<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Опции модуля настроек `yii2-cms-config` для ядра поиска на Manticore.
 *
 * Пути указывают в `modules.SearchManticore.params.*` — оттуда их читает
 * {@see \Besnovatyj\SearchManticore\ManticoreSettings}.
 *
 * Настройки делятся на две группы, и путать их не стоит. Адрес и учётная запись — свойства
 * сервера: их выставляют один раз при установке. Морфология, подсказки и допуск опечаток —
 * свойства индекса: первые две «запекаются» в него при индексации, поэтому после их изменения
 * нужна полная пересборка (`php yii Search/index/rebuild`). Пересобрать индекс сама настройка
 * не пытается: на боевом сайте это осознанное действие администратора.
 */
return [
    'search_manticore_host' => [
        'path'        => 'modules.SearchManticore.params.host',
        'label'       => '[Поиск: Manticore] Адрес демона',
        'description' => 'Имя хоста или IP. В docker-сборке — имя сервиса, на обычном сервере — 127.0.0.1',
        'category'    => 'Search',
        'rules'       => [
            ['required'],
            ['string', 'max' => 255],
        ],
        'inputOptions' => [
            'type' => 'text',
        ],
    ],

    'search_manticore_port' => [
        'path'        => 'modules.SearchManticore.params.port',
        'label'       => '[Поиск: Manticore] Порт демона',
        'description' => 'SQL-интерфейс демона, по умолчанию 9306',
        'category'    => 'Search',
        'rules'       => [
            ['required'],
            ['integer', 'min' => 1, 'max' => 65535],
        ],
        'inputOptions' => [
            'type' => 'number',
        ],
    ],

    'search_manticore_username' => [
        'path'        => 'modules.SearchManticore.params.username',
        'label'       => '[Поиск: Manticore] Учётная запись',
        'description' => 'Пусто — подключаться анонимно (авторизация в демоне выключена)',
        'category'    => 'Search',
        'rules'       => [
            ['string', 'max' => 128],
        ],
        'inputOptions' => [
            'type' => 'text',
        ],
    ],

    'search_manticore_password_secret' => [
        'path'        => 'modules.SearchManticore.params.passwordSecret',
        'label'       => '[Поиск: Manticore] Имя секрета с паролем',
        'description' => 'Сам пароль здесь не хранится: он читается из /run/secrets/<имя> или из одноимённой переменной окружения',
        'category'    => 'Search',
        'rules'       => [
            ['match', 'pattern' => '/^[A-Za-z0-9_.-]*$/'],
            ['string', 'max' => 128],
        ],
        'inputOptions' => [
            'type' => 'text',
        ],
    ],

    'search_manticore_table' => [
        'path'        => 'modules.SearchManticore.params.table',
        'label'       => '[Поиск: Manticore] Имя таблицы индекса',
        'description' => 'Пусто — имя составляется из имени базы проекта, чтобы несколько сайтов могли делить один демон',
        'category'    => 'Search',
        'rules'       => [
            ['match', 'pattern' => '/^[A-Za-z_][A-Za-z0-9_]*$|^$/'],
            ['string', 'max' => 64],
        ],
        'inputOptions' => [
            'type' => 'text',
        ],
    ],

    'search_manticore_morphology' => [
        'path'        => 'modules.SearchManticore.params.morphology',
        'label'       => '[Поиск: Manticore] Морфология',
        'description' => 'После смены нужна полная переиндексация',
        'category'    => 'Search',
        'rules'       => [
            ['required'],
            ['in', 'range' => ['auto', 'lemmatize_ru_all, stem_en', 'lemmatize_ru, stem_en', 'stem_ru, stem_en', 'stem_en', 'none']],
        ],
        'inputOptions' => [
            'type'  => 'dropdown',
            'items' => [
                'auto' => 'По языку сайта (рекомендуется)',
                'lemmatize_ru_all, stem_en' => 'Лемматизатор русского, все разборы омонимов',
                'lemmatize_ru, stem_en' => 'Лемматизатор русского, один разбор (индекс меньше)',
                'stem_ru, stem_en' => 'Стеммер русского (без словаря, только окончания)',
                'stem_en' => 'Только английский стеммер',
                'none' => 'Без морфологии (поиск точных словоформ)',
            ],
        ],
    ],

    'search_manticore_suggestions' => [
        'path'        => 'modules.SearchManticore.params.suggestions',
        'label'       => '[Поиск: Manticore] Подсказки «возможно, вы имели в виду»',
        'description' => 'Требуют словаря подстрок: индекс вырастает в несколько раз. После смены нужна переиндексация',
        'category'    => 'Search',
        'rules'       => [
            ['boolean'],
        ],
        'inputOptions' => [
            'type' => 'checkbox',
        ],
    ],

    'search_manticore_fuzzy_distance' => [
        'path'        => 'modules.SearchManticore.params.fuzzyDistance',
        'label'       => '[Поиск: Manticore] Допуск опечаток',
        'description' => 'Сколько букв в слове может не совпасть. 0 — искать без опечаток',
        'category'    => 'Search',
        'rules'       => [
            ['required'],
            ['integer', 'min' => 0, 'max' => 2],
        ],
        'inputOptions' => [
            'type' => 'number',
        ],
    ],

    'search_manticore_layouts' => [
        'path'        => 'modules.SearchManticore.params.layouts',
        'label'       => '[Поиск: Manticore] Раскладки клавиатуры',
        'description' => 'Распознавание запроса, набранного не в той раскладке: «ghbdtn» → «привет»',
        'category'    => 'Search',
        'rules'       => [
            ['match', 'pattern' => '/^(auto|[a-z]{2}(,[a-z]{2})*)?$/'],
            ['string', 'max' => 64],
        ],
        'inputOptions' => [
            'type' => 'text',
        ],
    ],

    'search_manticore_max_matches' => [
        'path'        => 'modules.SearchManticore.params.maxMatches',
        'label'       => '[Поиск: Manticore] Окно совпадений',
        'description' => 'Сколько совпадений демон держит в памяти на запрос: глубина листания и точность счётчиков',
        'category'    => 'Search',
        'rules'       => [
            ['required'],
            ['integer', 'min' => 100, 'max' => 100000],
        ],
        'inputOptions' => [
            'type' => 'number',
        ],
    ],
];
