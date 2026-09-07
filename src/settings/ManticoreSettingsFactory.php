<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore\settings;

use Besnovatyj\Helpers\SecretReader;
use Besnovatyj\Search\settings\ParamReader;

/**
 * Сборка {@see ManticoreSettings} из двух источников — по природе значения, а не по вкусу.
 *
 * Реквизиты подключения (адрес, порт, учётная запись, пароль) — свойство сервера: их задаёт тот,
 * кто разворачивает окружение, и меняются они вместе с окружением, а не с сайтом. Поэтому они
 * читаются {@see SecretReader}: файл `/run/secrets/<имя>`, а если файла нет — одноимённая
 * переменная окружения. Ровно так же приложение получает реквизиты базы, и ровно поэтому переезд
 * на другой сервер не требует захода в админку. Пароль при этом не попадает ни в базу, ни в дамп,
 * ни в резервную копию настроек.
 *
 * Свойства индекса (морфология, подсказки, допуск опечаток, раскладки, окно совпадений, имя
 * таблицы) — решение о том, как сайт ищет; их настраивают в админке через `yii2-cms-config`.
 * Разбор строковых значений выполняет общий для всего поиска {@see ParamReader}.
 *
 * Язык приложения приходит аргументом: варианты «по языку сайта» разрешаются здесь, а знание о
 * том, откуда берётся язык, остаётся в composition root (`config/common.php`).
 */
final class ManticoreSettingsFactory
{
    /** Имена секретов подключения. Те же имена — у переменных окружения. */
    private const string SECRET_HOST = 'MANTICORE_HOST';

    private const string SECRET_PORT = 'MANTICORE_PORT';

    private const string SECRET_USER = 'MANTICORE_USER';

    private const string SECRET_PASSWORD = 'MANTICORE_PASSWORD';

    /** Значение настройки «по языку сайта». */
    private const string AUTO = 'auto';

    /** Длина минимальной подстроки в словаре индекса, когда подсказки включены. */
    private const int INFIX_LEN = 3;

    /**
     * @param array<string, mixed> $params   `params` модуля ядра
     * @param string               $language язык приложения (`Yii::$app->language`)
     */
    public function create(array $params, string $language): ManticoreSettings
    {
        $reader = new ParamReader($params);
        $isRussian = str_starts_with($language, 'ru');
        $suggestions = $reader->bool('suggestions', true);
        $port = (int)SecretReader::get(self::SECRET_PORT, '9306');

        return new ManticoreSettings(
            // По умолчанию — та же машина, как при обычной установке пакетом.
            host: SecretReader::get(self::SECRET_HOST, '127.0.0.1'),
            port: $port > 0 ? $port : 9306,
            username: SecretReader::get(self::SECRET_USER),
            password: SecretReader::get(self::SECRET_PASSWORD),
            table: $reader->string('table'),
            morphology: $this->morphology($reader->string('morphology', self::AUTO), $isRussian),
            suggestions: $suggestions,
            minInfixLen: $suggestions ? self::INFIX_LEN : 0,
            fuzzyDistance: $reader->int('fuzzyDistance', 2, min: 0, max: 2),
            layouts: $this->layouts($reader->string('layouts', self::AUTO), $isRussian),
            maxMatches: $reader->int('maxMatches', 5000, min: 100, max: 100000),
        );
    }

    /**
     * Набор морфологических обработчиков.
     *
     * `auto` разворачивается по языку приложения: для русского — лемматизатор со всеми разборами
     * омонимов (словарная форма, а не отсечение окончаний), для прочих языков — английский
     * стеммер. Обработка слова прекращается на первом обработчике, который его изменил, поэтому
     * пара «лемматизатор русского + английский стеммер» обслуживает двуязычный текст.
     */
    private function morphology(string $value, bool $isRussian): string
    {
        if ($value === 'none') {
            return '';
        }

        if ($value !== '' && $value !== self::AUTO) {
            return $value;
        }

        return $isRussian ? 'lemmatize_ru_all, stem_en' : 'stem_en';
    }

    /**
     * Раскладки клавиатуры для распознавания запроса, набранного не в той раскладке
     * («ghbdtn» → «привет»). Пустая строка — не распознавать.
     */
    private function layouts(string $value, bool $isRussian): string
    {
        if ($value !== self::AUTO) {
            return $value;
        }

        return $isRussian ? 'ru,us' : 'us';
    }
}
