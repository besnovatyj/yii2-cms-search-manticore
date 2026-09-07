<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore;

use Besnovatyj\Contracts\module\DeclaresModule;
use Besnovatyj\Contracts\module\ProvidesBootstrap;
use Besnovatyj\Contracts\module\ProvidesDependencies;
use Besnovatyj\Contracts\module\ProvidesOptions;
use Besnovatyj\Kernel\module\CmsModule;

/**
 * Ядро сквозного поиска на Manticore Search.
 *
 * Модулем, а не просто пакетом, оформлено ради двух вещей, которых у пакета быть не может:
 * его можно включать и выключать менеджером модулей, и у него есть собственные настройки в
 * админке. Настраивать здесь действительно есть что: адрес демона и учётная запись зависят от
 * сервера, а морфология, подсказки и допуск опечаток — от сайта. Адаптеры редактора остаются
 * пакетами именно потому, что настраивать у них нечего, кроме выбора самого адаптера.
 *
 * Ни контроллёров, ни пунктов меню, ни таблиц в базе проекта модуль не добавляет: весь его
 * видимый интерфейс — раздел настроек и страница состояния индекса, которую рисует фасад.
 * Выключенный модуль означает «этого ядра в системе нет»: фасад увидит недоступное ядро и
 * останется на своём.
 */
class Module extends CmsModule implements DeclaresModule, ProvidesBootstrap, ProvidesDependencies, ProvidesOptions
{
    public const bool EDITABLE = true;

    public const string VERSION = '1.0.0';

    public const string MODULE_ID = 'SearchManticore';

    public static function moduleId(): string
    {
        return self::MODULE_ID;
    }

    public static function moduleVersion(): string
    {
        return self::VERSION;
    }

    public static function isEditable(): bool
    {
        return self::EDITABLE;
    }

    public static function moduleConfig(): array
    {
        return require __DIR__ . '/config/config.php';
    }

    public static function options(): array
    {
        return require __DIR__ . '/config/options.php';
    }

    /**
     * Ядро вызывает не маршрутизатор, а фасад поиска, поэтому проводка зависимостей нужна
     * глобально, а не при инициализации модуля — см. {@see Bootstrap}.
     */
    public static function bootstrapClasses(): array
    {
        return [Bootstrap::class];
    }

    /**
     * Без фасада ядро бессмысленно: он владеет каталогом документов, переиндексацией и выдачей.
     */
    public static function dependencies(): array
    {
        return [
            'modules' => ['Search'],
            'php_extensions' => ['pdo_mysql', 'mbstring'],
            'php_version' => '>=8.4',
        ];
    }
}
