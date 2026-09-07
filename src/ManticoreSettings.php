<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore;

use Besnovatyj\Helpers\SecretReader;
use Yii;

/**
 * Настройки ядра из двух источников — по природе значения, а не по вкусу.
 *
 * Реквизиты подключения (адрес, порт, учётная запись, пароль) — свойство сервера: их задаёт тот,
 * кто разворачивает окружение, и меняются они вместе с окружением, а не с сайтом. Поэтому они
 * читаются {@see SecretReader}: файл `/run/secrets/<имя>`, а если файла нет — одноимённая
 * переменная окружения. Ровно так же приложение получает реквизиты базы и адреса доменов, и
 * ровно поэтому переезд на другой сервер не требует захода в админку: значения приезжают вместе
 * с окружением. Пароль при этом не попадает ни в базу, ни в дамп, ни в резервную копию настроек.
 *
 * Свойства индекса (морфология, подсказки, допуск опечаток, раскладки, окно совпадений, имя
 * таблицы) — решение о том, как сайт ищет. Их и настраивают в админке через `yii2-cms-config`.
 * Модуль настроек умеет писать только в `modules.<Id>.params.*` и только скаляры, поэтому разбор
 * строковых значений («auto», список раскладок) собран здесь.
 */
final class ManticoreSettings
{
    /** Имена секретов подключения. Те же имена — у переменных окружения. */
    private const string SECRET_HOST = 'MANTICORE_HOST';

    private const string SECRET_PORT = 'MANTICORE_PORT';

    private const string SECRET_USER = 'MANTICORE_USER';

    private const string SECRET_PASSWORD = 'MANTICORE_PASSWORD';

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

    /** Адрес демона; по умолчанию — та же машина, как при обычной установке пакетом. */
    public function host(): string
    {
        return SecretReader::get(self::SECRET_HOST, '127.0.0.1');
    }

    /** Порт SQL-интерфейса демона. */
    public function port(): int
    {
        $port = (int)SecretReader::get(self::SECRET_PORT, '9306');

        return $port > 0 ? $port : 9306;
    }

    /** Учётная запись; пустая строка — подключаться анонимно (авторизация демона выключена). */
    public function username(): string
    {
        return SecretReader::get(self::SECRET_USER);
    }

    /** Пароль учётной записи. */
    public function password(): string
    {
        return SecretReader::get(self::SECRET_PASSWORD);
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
