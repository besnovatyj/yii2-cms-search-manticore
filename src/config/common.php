<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\SearchManticore\engine\ManticoreConnection;
use Besnovatyj\SearchManticore\engine\ManticoreIndexSchema;
use Besnovatyj\SearchManticore\engine\ManticoreSearchEngine;
use Besnovatyj\SearchManticore\engine\MatchEscaper;
use Besnovatyj\SearchManticore\Module;
use Besnovatyj\SearchManticore\settings\ManticoreSettings;
use Besnovatyj\SearchManticore\settings\ManticoreSettingsFactory;
use yii\di\Container;

/**
 * Yii2-конфиг модуля для движка yiisoft/config (группа `common` — общий для всех приложений).
 *
 * Объявляется через `extra.config-plugin`, собирается modman в merge-plan и мёржится в рантайме.
 * Регистрация модуля и DI-проводка — здесь, и только здесь.
 *
 * Почему не `config/container.php` и не Bootstrap-класс. Первый выполняется при инициализации
 * модуля, а к модулю ядра никто не обращается — маршрутов у него нет, движок создаёт фасад через
 * контейнер. Второй тоже не нужен: секция `container.singletons` применяется в `preInit`, то есть
 * раньше любой возможной точки обращения, и при этом лениво — пока поиск не понадобился, ничего
 * не создаётся. Bootstrap ради одной регистрации был бы третьим способом сделать то же самое.
 *
 * Отдельного компонента приложения ядро не заводит: соединение с демоном оно создаёт само по своим
 * настройкам, а компонент означал бы, что адрес демона нужно держать в двух местах.
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
    'container' => [
        'singletons' => [
            /**
             * Настройки ядра — один объект на запрос.
             *
             * Замыкание — единственное место пакета, знающее о `Yii::$app`: значения из админки
             * приезжают в объект модуля, а язык приложения нужен для вариантов «по языку сайта»
             * (морфология и раскладки). Ленивость обязательна — на момент сборки конфига ни того,
             * ни другого ещё нет.
             */
            ManticoreSettings::class => static fn (Container $c): ManticoreSettings => $c
                ->get(ManticoreSettingsFactory::class)
                ->create(
                    (array)(Yii::$app->getModule(Module::MODULE_ID)?->params ?? []),
                    (string)Yii::$app->language,
                ),

            /**
             * Соединение — обязательно синглтон: служебная сводка о запросе (`SHOW META`, из неё
             * берётся число найденного) читается тем же линком, которым выполнен сам запрос,
             * поэтому движок и описание таблицы должны получить один и тот же объект.
             */
            ManticoreConnection::class => ManticoreConnection::class,

            ManticoreSettingsFactory::class => ManticoreSettingsFactory::class,
            ManticoreIndexSchema::class => ManticoreIndexSchema::class,
            MatchEscaper::class => MatchEscaper::class,
            ManticoreSearchEngine::class => ManticoreSearchEngine::class,
        ],
    ],
];
