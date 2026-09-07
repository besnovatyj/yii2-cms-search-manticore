<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore;

use Besnovatyj\Contracts\module\DeclaresModule;
use Besnovatyj\Contracts\module\ProvidesDependencies;
use Besnovatyj\Contracts\module\ProvidesOptions;
use Besnovatyj\Kernel\module\CmsModule;
use Besnovatyj\Search\contracts\SearchEngineDescriptor;
use Besnovatyj\Search\contracts\SearchEngineProvider;
use Besnovatyj\SearchManticore\engine\ManticoreSearchEngine;

/**
 * Ядро сквозного поиска на Manticore Search.
 *
 * Модулем, а не просто пакетом, оформлено ради двух вещей, которых у пакета быть не может:
 * его можно включать и выключать менеджером модулей, и у него есть собственные настройки в
 * админке. Настраивать здесь действительно есть что: адрес демона и учётная запись зависят от
 * сервера, а морфология, подсказки и допуск опечаток — от сайта.
 *
 * Ни контроллёров, ни пунктов меню, ни таблиц в базе проекта модуль не добавляет: весь его
 * видимый интерфейс — раздел настроек и страница состояния индекса, которую рисует фасад.
 * Выключенный модуль означает «этого ядра в системе нет»: фасад перестаёт видеть его в реестре
 * и остаётся на своём.
 */
class Module extends CmsModule implements
    DeclaresModule,
    ProvidesDependencies,
    ProvidesOptions,
    SearchEngineProvider
{
    public const bool EDITABLE = true;
    public const string VERSION = '1.0.0';
    public const string MODULE_ID = 'SearchManticore';

    /** Ключ ядра в настройках и в метке «каким ядром собран индекс». */
    public const string ENGINE_KEY = 'manticore';

    public static function moduleId(): string { return self::MODULE_ID; }
    public static function moduleVersion(): string { return self::VERSION; }
    public static function isEditable(): bool { return self::EDITABLE; }
    public static function moduleConfig(): array { return require __DIR__ . '/config/config.php'; }
    public static function options(): array { return require __DIR__ . '/config/options.php'; }
    public static function dependencies(): array { return require __DIR__ . '/config/dependencies.php'; }

    /**
     * Ядро, которое этот модуль приносит фасаду. Реализация {@see SearchEngineProvider};
     * вызывается только модулем поиска, если он установлен.
     *
     * @return SearchEngineDescriptor[]
     */
    public function searchEngines(): array
    {
        return [
            new SearchEngineDescriptor(
                self::ENGINE_KEY,
                'Manticore Search (отдельный демон)',
                ManticoreSearchEngine::class,
            ),
        ];
    }
}
