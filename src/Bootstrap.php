<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore;

use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\di\Container;
use Yii;

/**
 * Проводка зависимостей ядра в контейнер приложения.
 *
 * Обычные модули объявляют такое в своём `config/container.php`, который выполняется при
 * инициализации модуля. Здесь так нельзя: ядро создаёт не маршрутизатор, а фасад поиска —
 * `EngineResolver` вызывает `Yii::createObject()` для класса движка, и к этому моменту модуль
 * ядра может быть ни разу не тронут. Поэтому единственное, чего контейнер не соберёт сам,
 * регистрируется глобально, пока модуль включён; выключенный модуль этот bootstrap не получает.
 *
 * Собрать сам контейнер не может только {@see ManticoreSettings}: её конструктор принимает массив
 * настроек, а не класс. Остальное (соединение, описание таблицы, экранирование, сам движок)
 * разрешается по тайп-хинтам конструкторов.
 *
 * Синглтон — не ради экономии: настройки читаются один раз, и все части ядра в пределах запроса
 * обязаны видеть одни и те же адрес, таблицу и морфологию.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * @param Application $app
     */
    public function bootstrap($app): void
    {
        /** @var Container $container */
        $container = Yii::$container;

        $container->setSingleton(
            ManticoreSettings::class,
            static fn (): ManticoreSettings => ManticoreSettings::current(),
        );
    }
}
