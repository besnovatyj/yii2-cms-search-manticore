<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore;

use Yii;

/**
 * Таблица индекса в демоне: имя, описание полей и её приведение к нужному виду.
 *
 * Таблица одна и работает в реальном времени (RT): документы в неё кладутся и заменяются на ходу,
 * без пересборки. Поэтому здесь нет ни двух слотов, ни атомарной подмены, как в ядре на TNTSearch:
 * идентификаторы документов каталога стабильны, `REPLACE INTO` обновляет запись на месте, и во
 * время полной переиндексации поиск продолжает работать по тем же строкам, просто часть из них
 * уже обновлена. Ломать и собирать индекс заново было бы не осторожностью, а лишним риском.
 *
 * Имя таблицы, если оно не задано в настройках, получает суффикс по имени базы проекта: один
 * демон нередко обслуживает несколько сайтов на сервере, и индексы не должны наступать друг другу
 * на ноги. Ничего настраивать для этого не нужно — суффикс вычисляется сам.
 *
 * Морфология берётся из настроек модуля и «запекается» в таблицу: сменить её без полной
 * переиндексации нельзя, потому что в индексе лежат уже разобранные формы слов. Поэтому
 * {@see ensureCurrent()} сверяет настройки таблицы с нужными и, если они разошлись, пересоздаёт
 * таблицу — но делает это только в начале полной пересборки, когда следом всё равно приедут все
 * документы.
 */
final class ManticoreIndexSchema
{
    /** Префикс имени таблицы, когда оно вычисляется само; полное имя — префикс плюс суффикс сайта. */
    private const string TABLE_PREFIX = 'bescms_search_';

    private ?string $table = null;

    public function __construct(
        private readonly ManticoreConnection $connection,
        private readonly ManticoreSettings $settings,
    ) {
    }

    /**
     * Имя таблицы индекса для текущего сайта.
     */
    public function table(): string
    {
        if ($this->table !== null) {
            return $this->table;
        }

        $configured = $this->settings->table();

        return $this->table = $configured === ''
            ? self::TABLE_PREFIX . $this->siteSuffix()
            : $configured;
    }

    /**
     * Существует ли таблица индекса. Заодно это и проверка живости демона.
     */
    public function exists(): bool
    {
        $rows = $this->connection->get()->createCommand('SHOW TABLES')->queryAll();

        foreach ($rows as $row) {
            if (in_array($this->table(), array_map('strval', array_values($row)), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Создать таблицу, если её нет. Существующую не трогает.
     */
    public function ensure(): void
    {
        if (!$this->exists()) {
            $this->create();
        }
    }

    /**
     * Привести таблицу к текущим настройкам морфологии, пересоздав её при расхождении.
     *
     * Вызывается только из начала полной пересборки: пересоздание опустошает индекс, и оправдано
     * оно лишь тем, что документы тут же будут залиты заново.
     */
    public function ensureCurrent(): void
    {
        if (!$this->exists()) {
            $this->create();

            return;
        }

        if ($this->matchesSettings()) {
            return;
        }

        Yii::warning(
            "Настройки таблицы «{$this->table()}» разошлись с настройками сайта — таблица пересоздаётся.",
            'search/manticore',
        );

        $this->drop();
        $this->create();
    }

    /**
     * Удалить таблицу индекса.
     */
    public function drop(): void
    {
        $this->connection->get()->createCommand('DROP TABLE IF EXISTS ' . $this->table())->execute();
    }

    /**
     * Создать таблицу индекса.
     *
     * Поля и атрибуты различаются по назначению: `title`, `content`, `keywords` — полнотекстовые
     * поля (по ним ищут и из них берут подсвеченный фрагмент), остальное — атрибуты, по которым
     * фильтруют, группируют и сортируют. Столбец `id` объявлять не нужно, он есть у любой таблицы.
     *
     * Имя `doc_type` вместо напрашивающегося `type` выбрано намеренно: `type` — служебное слово в
     * синтаксисе создания таблиц Manticore.
     */
    private function create(): void
    {
        $options = [];

        if ($this->settings->morphology() !== '') {
            $options[] = sprintf("morphology='%s'", $this->settings->morphology());
        }

        // Словарь подстрок нужен только подсказкам; выключенный, он не объявляется вовсе —
        // значение по умолчанию и есть «не собирать».
        if ($this->settings->minInfixLen() > 0) {
            $options[] = sprintf("min_infix_len='%d'", $this->settings->minInfixLen());
        }

        $sql = sprintf(
            'CREATE TABLE %s ('
            . 'title text, '
            . 'content text, '
            . 'keywords text, '
            . 'doc_type string, '
            . 'published timestamp, '
            . 'boost float, '
            . 'stamp timestamp'
            . ') %s',
            $this->table(),
            implode(' ', $options),
        );

        $this->connection->get()->createCommand(rtrim($sql))->execute();
    }

    /**
     * Совпадают ли настройки существующей таблицы с нужными.
     *
     * Сравниваются именно значения, а не текст: демон волен переписать перечисление обработчиков
     * по-своему, а выключенную настройку не показать вовсе. Поэтому отсутствие настройки в ответе
     * читается как «выключена» — иначе таблица пересоздавалась бы на каждой пересборке.
     */
    private function matchesSettings(): bool
    {
        $rows = $this->connection->get()->createCommand('SHOW CREATE TABLE ' . $this->table())->queryAll();
        $created = '';

        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_string($value) && stripos($value, 'create table') !== false) {
                    $created = $value;
                }
            }
        }

        if ($created === '') {
            return false;
        }

        $words = static fn (string $value): string => str_replace(' ', '', mb_strtolower($value));

        return $words($this->option($created, 'morphology')) === $words($this->settings->morphology())
            && (int)$this->option($created, 'min_infix_len') === $this->settings->minInfixLen();
    }

    /**
     * Значение настройки таблицы из текста `SHOW CREATE TABLE`; пустая строка — настройки нет.
     */
    private function option(string $created, string $name): string
    {
        return preg_match("/{$name}\\s*=\\s*'([^']*)'/i", $created, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * Суффикс имени таблицы — имя базы проекта, приведённое к безопасному идентификатору.
     *
     * Берётся из DSN подключения к базе: это самое стабильное имя, которое есть у сайта (id
     * приложения в advanced-шаблоне у фронта, админки и консоли разные, а индекс у них общий).
     */
    private function siteSuffix(): string
    {
        $database = '';

        if (preg_match('/dbname=([^;]+)/i', Yii::$app->db->dsn, $matches) === 1) {
            $database = $matches[1];
        }

        $suffix = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', $database));
        $suffix = trim($suffix, '_');

        return $suffix === '' ? 'default' : $suffix;
    }
}
