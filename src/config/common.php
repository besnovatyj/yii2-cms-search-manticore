<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\SearchManticore\Module;

/**
 * Yii2-конфиг модуля для движка yiisoft/config (группа `common` — общий для всех приложений).
 *
 * Только регистрация модуля: ни URL-правил, ни компонентов ядро не добавляет. Соединение с
 * демоном оно создаёт само по своим настройкам — отдельный компонент приложения означал бы,
 * что адрес демона нужно держать в двух местах.
 *
 * Группа `common`, а не `app-frontend`: искать умеет и фронт, и консоль (переиндексация), и
 * админка (страница состояния индекса).
 */
return [
    'modules' => [
        Module::moduleId() => array_merge(
            ['class' => Module::class],
            Module::moduleConfig(),
            ['version' => Module::moduleVersion()],
        ),
    ],
];
