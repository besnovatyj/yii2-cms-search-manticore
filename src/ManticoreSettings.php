<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore;

use Besnovatyj\Helpers\SecretReader;
use Yii;

/**
 * Типизированное чтение настроек ядра.
 *
 * Модуль настроек `yii2-cms-config` умеет писать только в `modules.<Id>.params.*` и только
 * скаляры, поэтому разбор строковых значений («auto», список раскладок) собран здесь, а
 * остальной код работает с готовыми значениями.
 *
 * Пароль в настройках не хранится: там лежит только имя секрета. Само значение читается
 * {@see SecretReader} — из файла `/run/secrets/<имя>`, а если файла нет, из одноимённой
 * переменной окружения. Так пароль не попадает ни в базу, ни в дамп, ни в резервную копию
 * настроек.
 */
final class ManticoreSettings
{
    /**
     * @param array<string, mixed> $params `params` модуля ядра
     * @param bool                 $installed включён ли модуль ядра в системе
     */
    public function __construct(
        private readonly array $params,
        private readonly bool $installed,
    ) {
    }

    /**
     * Настройки активного модуля ядра. Модуль выключен — дефолты пакета и признак «не установлено».
     */
    public static function current(): self
    {
        $module = Yii::$app->hasModule(Module::MODULE_ID) ? Yii::$app->getModule(Module::MODULE_ID) : null;

        return $module instanceof Module
            ? new self($module->params, true)
            : new self([], false);
    }

    /**
     * Включён ли модуль ядра.
     *
     * Выключенный менеджером модуль означает «этого ядра в системе нет»: класс движка остаётся в
     * автозагрузке, потому что пакет никуда не делся, но настроек у него нет и обслуживать поиск
     * он не должен.
     */
    public function installed(): bool
    {
        return $this->installed;
    }

    public function host(): string
    {
        $host = trim((string)($this->params['host'] ?? ''));

        return $host === '' ? '127.0.0.1' : $host;
    }

    public function port(): int
    {
        $port = (int)($this->params['port'] ?? 0);

        return $port > 0 ? $port : 9306;
    }

    public function username(): string
    {
        return trim((string)($this->params['username'] ?? ''));
    }

    /** Пароль учётной записи; пустая строка — подключение без авторизации. */
    public function password(): string
    {
        $secret = trim((string)($this->params['passwordSecret'] ?? ''));

        return $secret === '' ? '' : SecretReader::get($secret);
    }

    /** Имя таблицы индекса, заданное вручную; пустая строка — вычислять по имени базы. */
    public function table(): string
    {
        return trim((string)($this->params['table'] ?? ''));
    }

    /**
     * Набор морфологических обработчиков для индекса.
     *
     * `auto` разворачивается по языку приложения: для русского — лемматизатор со всеми разборами
     * омонимов (словарная форма, а не отсечение окончаний), для прочих языков — английский
     * стеммер. Обработка слова прекращается на первом обработчике, который его изменил, поэтому
     * пара «лемматизатор русского + английский стеммер» обслуживает двуязычный текст.
     */
    public function morphology(): string
    {
        $value = trim((string)($this->params['morphology'] ?? 'auto'));

        if ($value === 'none') {
            return '';
        }

        if ($value !== '' && $value !== 'auto') {
            return $value;
        }

        return $this->isRussian() ? 'lemmatize_ru_all, stem_en' : 'stem_en';
    }

    /** Собирать ли словарь подстрок, без которого не работают подсказки. */
    public function suggestions(): bool
    {
        return (bool)($this->params['suggestions'] ?? true);
    }

    /**
     * Длина минимальной подстроки в словаре индекса; 0 — словарь подстрок не собирается.
     */
    public function minInfixLen(): int
    {
        return $this->suggestions() ? 3 : 0;
    }

    /** Допуск опечаток в буквах; 0 — искать без опечаток. */
    public function fuzzyDistance(): int
    {
        $distance = (int)($this->params['fuzzyDistance'] ?? 2);

        return max(0, min(2, $distance));
    }

    /**
     * Раскладки клавиатуры для распознавания запроса, набранного не в той раскладке.
     * Пустая строка — не распознавать.
     */
    public function layouts(): string
    {
        $value = trim((string)($this->params['layouts'] ?? 'auto'));

        if ($value !== 'auto') {
            return $value;
        }

        return $this->isRussian() ? 'ru,us' : 'us';
    }

    /** Сколько совпадений демон держит в памяти на один запрос. */
    public function maxMatches(): int
    {
        $value = (int)($this->params['maxMatches'] ?? 5000);

        return max(100, $value);
    }

    private function isRussian(): bool
    {
        return str_starts_with(Yii::$app->language, 'ru');
    }
}
