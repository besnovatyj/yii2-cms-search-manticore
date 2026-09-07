<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore;

use Besnovatyj\Search\contracts\EngineCapabilities;
use Besnovatyj\Search\contracts\IndexableDocument;
use Besnovatyj\Search\contracts\SearchEngineInterface;
use Besnovatyj\Search\contracts\SearchHit;
use Besnovatyj\Search\contracts\SearchQuery;
use Besnovatyj\Search\contracts\SearchResult;
use Besnovatyj\Search\services\SearchSettings;
use RuntimeException;
use Throwable;
use Yii;
use yii\helpers\Html;

/**
 * Ядро сквозного поиска на Manticore Search.
 *
 * Зачем оно, когда есть ядро на TNTSearch, которому не нужен ни демон, ни порт. Ради морфологии.
 * Стеммер отсекает окончания и на этом останавливается: «ботинки» он приведёт к «ботинк», а
 * «людям» так и оставит «люд» — «человек» по такому запросу не найдётся никогда. Manticore несёт
 * настоящий лемматизатор русского: словарь, который приводит слово к словарной форме независимо
 * от того, как далеко она от написанного. Плюс поиск с опечатками силами самого демона и
 * распознавание запроса, набранного в другой раскладке («ghbdtn» → «привет»), — на сайте, куда
 * приходят с телефона, это заметнее любого ранжирования.
 *
 * Чем за это платят: демон должен быть запущен и должен пережить перезагрузку сервера. Это
 * единственное отличие в эксплуатации — ни отдельной базы, ни репликации, ни JVM.
 *
 * Как устроен индекс. Одна таблица реального времени: документы кладутся и заменяются на ходу.
 * Полная переиндексация не собирает индекс заново рядом с рабочим, как это приходится делать
 * ядру на TNTSearch, а обновляет строки на месте по стабильным идентификаторам каталога и в
 * конце удаляет те, которых текущий прогон не подтвердил. Поиск при этом ни на мгновение не
 * остаётся без индекса, а прерванная пересборка не оставляет за собой мусора: худшее, что
 * случится, — часть документов будет с прошлого прогона, до следующей пересборки.
 *
 * Разговор с демоном идёт по протоколу MySQL обычным драйвером PHP — см. {@see ManticoreConnection}.
 */
final class ManticoreSearchEngine implements SearchEngineInterface
{
    /** Веса полей при ранжировании: совпадение в заголовке весомее совпадения в теле текста. */
    private const int TITLE_WEIGHT = 10;

    private const int KEYWORDS_WEIGHT = 5;

    private const int CONTENT_WEIGHT = 1;

    /** Сколько документов уходит в демон одним запросом. */
    private const int BATCH_ROWS = 100;

    /** ...и сколько байт текста — что бы ни случилось раньше, чтобы не упереться в размер пакета. */
    private const int BATCH_BYTES = 1000000;

    /**
     * Метки подсветки.
     *
     * Демон возвращает фрагмент текста документа как есть, и вставить в него готовые теги `<mark>`
     * нельзя: тогда пришлось бы отдавать в шаблон неэкранированный текст документа. Поэтому демон
     * расставляет метки, которые не значат в HTML ничего, фрагмент экранируется целиком, и только
     * потом метки превращаются в теги.
     */
    private const string MARK_OPEN = '[[bescms-mark]]';

    private const string MARK_CLOSE = '[[/bescms-mark]]';

    /** Метка текущей пересборки; null — пересборка не начата. */
    private ?int $stamp = null;

    /** @var list<IndexableDocument> накопленные, но ещё не отправленные документы */
    private array $pending = [];

    private int $pendingBytes = 0;

    /**
     * Демон отказался от поиска с опечатками (не установлен Manticore Buddy).
     * Проверяется один раз за запрос: повторять заведомо неудачную попытку незачем.
     */
    private bool $fuzzyRejected = false;

    public function __construct(
        private readonly ManticoreConnection $connection,
        private readonly ManticoreIndexSchema $schema,
        private readonly MatchEscaper $escaper,
        private readonly ManticoreSettings $engineSettings,
        private readonly SearchSettings $settings,
    ) {
    }

    public function capabilities(): EngineCapabilities
    {
        return new EngineCapabilities(
            fuzzy: $this->engineSettings->fuzzyDistance() > 0,
            lemmatization: true,
            highlight: true,
            suggestion: $this->engineSettings->suggestions(),
            facets: true,
            sortByDate: true,
            incremental: true,
            // Предела не заявляем: демон рассчитан на объёмы, до которых сайту на этой CMS ещё расти.
            comfortableSize: null,
        );
    }

    /**
     * Модуль ядра включён, демон отвечает и таблица индекса на месте.
     */
    public function isAvailable(): bool
    {
        if (!$this->engineSettings->installed()) {
            return false;
        }

        try {
            return $this->schema->exists();
        } catch (Throwable $e) {
            Yii::warning(
                'Демон Manticore недоступен (' . $this->connection->dsn() . '): ' . $e->getMessage(),
                'search/manticore',
            );

            return false;
        }
    }

    public function query(SearchQuery $searchQuery): SearchResult
    {
        $match = $this->escaper->escape($searchQuery->text);
        $window = $searchQuery->offset + $searchQuery->limit;
        $maxMatches = max($this->engineSettings->maxMatches(), $window);

        $select = ['id', 'WEIGHT() * IF(boost > 0, boost, 1) AS w'];

        if ($searchQuery->highlight) {
            $select[] = $this->highlightExpression();
        }

        $conditions = ['MATCH(:match)'];
        $params = [':match' => $match];
        $placeholders = [];

        foreach (array_values($searchQuery->types) as $index => $type) {
            $placeholders[] = ":type{$index}";
            $params[":type{$index}"] = $type;
        }

        if ($placeholders !== []) {
            $conditions[] = 'doc_type IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d, %d OPTION max_matches=%d, field_weights=(title=%d, keywords=%d, content=%d)',
            implode(', ', $select),
            $this->schema->table(),
            implode(' AND ', $conditions),
            $searchQuery->sort === SearchQuery::SORT_DATE ? 'published DESC, w DESC' : 'w DESC, published DESC',
            max(0, $searchQuery->offset),
            max(1, $searchQuery->limit),
            $maxMatches,
            self::TITLE_WEIGHT,
            self::KEYWORDS_WEIGHT,
            self::CONTENT_WEIGHT,
        );

        $rows = $this->select($sql . $this->fuzzyOption(), $params, $sql);

        $hits = [];
        foreach ($rows as $row) {
            $snippet = isset($row['hl']) ? (string)$row['hl'] : '';

            $hits[] = new SearchHit(
                documentId: (int)$row['id'],
                score: (float)($row['w'] ?? 0),
                highlight: $snippet === '' ? null : $this->decorate($snippet),
            );
        }

        // Порядок важен: SHOW META рассказывает о последнем запросе соединения, поэтому читается
        // сразу после основного и до всего остального.
        $total = $this->totalFound($searchQuery->offset + count($hits));

        return new SearchResult(
            hits: $hits,
            total: $total,
            facets: $searchQuery->withFacets ? $this->facets($match, $maxMatches) : [],
            suggestion: $total === 0 && $searchQuery->offset === 0 && $this->engineSettings->suggestions()
                ? $this->suggest($searchQuery->text)
                : null,
        );
    }

    /**
     * Подсветить совпадения в произвольном тексте.
     *
     * Запасной путь: при поиске подсвеченные фрагменты приходят прямо в выдаче, и фасад сюда не
     * заходит. Метод остаётся для тех случаев, когда фрагмент собран не из индекса — например,
     * из анонса каталога.
     */
    public function highlight(string $text, string $query): string
    {
        if ($text === '' || $query === '') {
            return Html::encode($text);
        }

        $sql = sprintf(
            "CALL SNIPPETS(:text, '%s', :query, '%s' AS before_match, '%s' AS after_match, "
            . '%d AS limit, 1 AS query_mode, 0 AS allow_empty)',
            $this->schema->table(),
            self::MARK_OPEN,
            self::MARK_CLOSE,
            max(mb_strlen($text) * 2, $this->settings->snippetLength()),
        );

        try {
            $rows = $this->connection->get()->createCommand($sql, [
                ':text' => $text,
                ':query' => $this->escaper->escape($query),
            ])->queryAll();
        } catch (Throwable $e) {
            Yii::warning('Не удалось подсветить фрагмент: ' . $e->getMessage(), 'search/manticore');

            return Html::encode($text);
        }

        $snippet = (string)(array_values($rows[0] ?? [])[0] ?? '');

        return $snippet === '' ? Html::encode($text) : $this->decorate($snippet);
    }

    /**
     * Начать полную переиндексацию.
     *
     * Таблица не пересоздаётся — она приводится к текущим настройкам и наполняется поверх
     * имеющегося содержимого. Метка прогона позволяет в конце отличить обновлённые документы от
     * оставшихся с прошлого раза.
     */
    public function beginRebuild(): void
    {
        $this->schema->ensureCurrent();

        $this->stamp = time();
        $this->pending = [];
        $this->pendingBytes = 0;
    }

    /**
     * @param iterable<IndexableDocument> $documents
     */
    public function addDocuments(iterable $documents): void
    {
        if ($this->stamp === null) {
            throw new RuntimeException('Сборка индекса не начата: вызовите beginRebuild().');
        }

        foreach ($documents as $document) {
            $this->pending[] = $document;
            $this->pendingBytes += strlen($document->title) + strlen($document->text) + strlen($document->keywords);

            if (count($this->pending) >= self::BATCH_ROWS || $this->pendingBytes >= self::BATCH_BYTES) {
                $this->flush();
            }
        }

        $this->flush();
    }

    /**
     * Завершить переиндексацию: убрать документы, которых этот прогон не подтвердил.
     *
     * Здесь исчезают из поиска удалённые записи, снятые с публикации материалы и документы
     * отключённых источников.
     */
    public function commitRebuild(): void
    {
        if ($this->stamp === null) {
            throw new RuntimeException('Сборка индекса не начата: вызовите beginRebuild().');
        }

        $this->flush();

        $this->connection->get()
            ->createCommand(sprintf('DELETE FROM %s WHERE stamp < %d', $this->schema->table(), $this->stamp))
            ->execute();

        $this->stamp = null;
    }

    /**
     * Прервать переиндексацию.
     *
     * Откатывать нечего и незачем: индекс всё это время оставался рабочим, просто часть документов
     * успела обновиться. Неподтверждённые строки остаются на месте — это лучше, чем удалить их и
     * оставить сайт с половиной поиска.
     */
    public function cancelRebuild(): void
    {
        if ($this->stamp === null) {
            return;
        }

        Yii::warning(
            'Переиндексация прервана: индекс остался рабочим, но часть документов не обновлена.',
            'search/manticore',
        );

        $this->stamp = null;
        $this->pending = [];
        $this->pendingBytes = 0;
    }

    public function indexDocument(IndexableDocument $document): void
    {
        $this->schema->ensure();

        // Идёт пересборка — документ должен получить её метку, иначе завершение пересборки сочтёт
        // его неподтверждённым и удалит.
        $this->write([$document], $this->stamp ?? time());
    }

    public function removeDocument(int $documentId): void
    {
        $this->connection->get()
            ->createCommand(sprintf('DELETE FROM %s WHERE id = %d', $this->schema->table(), $documentId))
            ->execute();
    }

    /**
     * Отправить накопленные документы в демон.
     */
    private function flush(): void
    {
        if ($this->pending === [] || $this->stamp === null) {
            return;
        }

        $this->write($this->pending, $this->stamp);

        $this->pending = [];
        $this->pendingBytes = 0;
    }

    /**
     * Записать пачку документов с указанной меткой прогона.
     *
     * Числа подставляются в запрос напрямую, строки — параметрами. Так сделано намеренно: драйвер
     * оформляет любое подставляемое значение как строку в кавычках, а демон ждёт в числовых
     * столбцах именно число.
     *
     * @param list<IndexableDocument> $documents
     */
    private function write(array $documents, int $stamp): void
    {
        if ($documents === []) {
            return;
        }

        $rows = [];
        $params = [];

        foreach (array_values($documents) as $index => $document) {
            $rows[] = sprintf(
                '(%d, :title%d, :content%d, :keywords%d, :type%d, %d, %s, %d)',
                $document->documentId,
                $index,
                $index,
                $index,
                $index,
                $document->date ?? 0,
                sprintf('%.4F', $document->boost > 0 ? $document->boost : 1.0),
                $stamp,
            );

            $params[":title{$index}"] = $document->title;
            $params[":content{$index}"] = $document->text;
            $params[":keywords{$index}"] = $document->keywords;
            $params[":type{$index}"] = $document->type;
        }

        $sql = sprintf(
            'REPLACE INTO %s (id, title, content, keywords, doc_type, published, boost, stamp) VALUES %s',
            $this->schema->table(),
            implode(', ', $rows),
        );

        $this->connection->get()->createCommand($sql, $params)->execute();
    }

    /**
     * Выражение подсветки для списка полей выдачи.
     */
    private function highlightExpression(): string
    {
        return sprintf(
            "HIGHLIGHT({before_match='%s', after_match='%s', limit=%d, around=8, allow_empty=0}, 'title, content') AS hl",
            self::MARK_OPEN,
            self::MARK_CLOSE,
            $this->settings->snippetLength(),
        );
    }

    /**
     * Хвост запроса, включающий поиск с опечатками и разбор чужой раскладки.
     *
     * Пустая строка, если опечатки выключены в настройках или демон уже отказал: этот режим
     * выполняет Manticore Buddy — он идёт в комплекте с демоном, но в урезанной сборке его
     * может не быть.
     */
    private function fuzzyOption(): string
    {
        $distance = $this->engineSettings->fuzzyDistance();

        if (!$this->settings->fuzzy() || $distance === 0 || $this->fuzzyRejected) {
            return '';
        }

        return sprintf(", fuzzy=1, distance=%d, layouts='%s'", $distance, $this->engineSettings->layouts());
    }

    /**
     * Выполнить запрос, при необходимости повторив его без поиска с опечатками.
     *
     * @param array<string, mixed> $params
     * @param string|null          $plain запрос без режима опечаток; null — повторять нечем
     * @return array<int, array<string, mixed>>
     */
    private function select(string $sql, array $params, ?string $plain = null): array
    {
        try {
            return $this->connection->get()->createCommand($sql, $params)->queryAll();
        } catch (Throwable $e) {
            if ($plain === null || $plain === $sql || $this->fuzzyRejected) {
                throw $e;
            }

            $this->fuzzyRejected = true;

            Yii::warning(
                'Демон не принял поиск с опечатками (нужен Manticore Buddy), запрос повторён без него: '
                . $e->getMessage(),
                'search/manticore',
            );

            return $this->connection->get()->createCommand($plain, $params)->queryAll();
        }
    }

    /**
     * Сколько всего документов совпало с запросом.
     *
     * Значение берётся из служебной сводки о последнем запросе. Оно ограничено окном совпадений
     * из настроек: до этого числа счётчик точен, дальше — «не меньше».
     */
    private function totalFound(int $fallback): int
    {
        try {
            $rows = $this->connection->get()->createCommand('SHOW META')->queryAll();
        } catch (Throwable $e) {
            Yii::warning('Не удалось прочитать сводку запроса: ' . $e->getMessage(), 'search/manticore');

            return $fallback;
        }

        foreach ($rows as $row) {
            $values = array_values($row);

            if (($values[0] ?? null) === 'total_found') {
                return (int)($values[1] ?? 0);
            }
        }

        return $fallback;
    }

    /**
     * Распределение совпадений по разделам — цифры на вкладках выдачи.
     *
     * Считается отдельным запросом, без фильтра по разделам: вкладка «Новости» должна показывать
     * своё число и тогда, когда посетитель смотрит вкладку «Услуги». Группировка вместо `FACET`
     * выбрана намеренно — это обычный одиночный запрос, совместимый с режимом опечаток.
     *
     * @return array<string, int>
     */
    private function facets(string $match, int $maxMatches): array
    {
        $sql = sprintf(
            'SELECT doc_type, COUNT(*) AS cnt FROM %s WHERE MATCH(:match)'
            . ' GROUP BY doc_type ORDER BY cnt DESC LIMIT 100 OPTION max_matches=%d',
            $this->schema->table(),
            $maxMatches,
        );

        try {
            $rows = $this->select($sql . $this->fuzzyOption(), [':match' => $match], $sql);
        } catch (Throwable $e) {
            Yii::warning('Не удалось посчитать разделы выдачи: ' . $e->getMessage(), 'search/manticore');

            return [];
        }

        $facets = [];

        foreach ($rows as $row) {
            $type = (string)($row['doc_type'] ?? '');

            if ($type !== '') {
                $facets[$type] = (int)($row['cnt'] ?? 0);
            }
        }

        return $facets;
    }

    /**
     * «Возможно, вы имели в виду»: исправление последнего слова запроса по словарю индекса.
     *
     * Исправляется именно последнее слово — то, которое посетитель дописывал последним и в котором
     * чаще всего опечатка. Подсказка предлагается только тогда, когда не нашлось ничего: иначе она
     * спорила бы с непустой выдачей.
     */
    private function suggest(string $text): ?string
    {
        $sql = sprintf(
            "CALL QSUGGEST(:text, '%s', 1 AS limit, 1 AS sentence, 0 AS result_stats)",
            $this->schema->table(),
        );

        try {
            $rows = $this->connection->get()->createCommand($sql, [':text' => $text])->queryAll();
        } catch (Throwable $e) {
            Yii::warning('Не удалось получить подсказку по запросу: ' . $e->getMessage(), 'search/manticore');

            return null;
        }

        $suggestion = trim((string)(array_values($rows[0] ?? [])[0] ?? ''));

        if ($suggestion === '' || mb_strtolower($suggestion) === mb_strtolower(trim($text))) {
            return null;
        }

        return $suggestion;
    }

    /**
     * Превратить метки демона в теги подсветки, оставив остальной текст экранированным.
     */
    private function decorate(string $snippet): string
    {
        return str_replace(
            [self::MARK_OPEN, self::MARK_CLOSE],
            ['<mark>', '</mark>'],
            Html::encode($snippet),
        );
    }
}
