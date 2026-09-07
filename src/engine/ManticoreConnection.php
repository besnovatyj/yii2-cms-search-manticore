<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore\engine;

use Besnovatyj\SearchManticore\settings\ManticoreSettings;
use PDO;
use yii\db\Connection;

/**
 * Соединение с демоном Manticore.
 *
 * Manticore говорит по протоколу MySQL (порт 9306), поэтому отдельного клиента, HTTP-обвязки и
 * новых расширений PHP не нужно: подходит тот же `pdo_mysql`, которым приложение работает с базой.
 * Официальный HTTP-клиент сюда не тянется намеренно — он добавил бы зависимость и второй язык
 * запросов ради того, что уже умеет драйвер в сборке.
 *
 * Адрес и учётная запись берутся из настроек ядра, а не из компонента приложения: они приезжают
 * вместе с окружением (секреты/переменные), как реквизиты базы, и не должны существовать в двух
 * местах сразу.
 *
 * Отличия от обычного подключения к MySQL, каждое из которых обязательно:
 *
 *  * `charset` не задаётся — иначе Yii отправит `SET NAMES` в демон, которому эта команда не нужна;
 *  * `emulatePrepare` включён — Manticore поддерживает подготовленные выражения не полностью,
 *    поэтому подстановку значений делает драйвер на стороне PHP, а демон получает готовый SQL;
 *  * кэш схемы выключен — таблиц в понимании Yii здесь нет, метаданные читать неоткуда;
 *  * задан таймаут соединения: упавший демон не должен превращать страницу поиска в зависший запрос.
 */
final class ManticoreConnection
{
    /** Сколько секунд ждать соединения, прежде чем считать демон недоступным. */
    private const int CONNECT_TIMEOUT = 3;

    /**
     * Линк к демону — один на весь запрос.
     *
     * Это не экономия: служебная сводка о запросе (`SHOW META`, из неё берётся число найденного)
     * живёт в соединении, и прочитать её можно только тем же линком, которым был выполнен сам
     * запрос. Единственность обеспечивает контейнер — класс объявлен синглтоном в
     * `config/common.php`, поэтому и ядро, и описание таблицы получают один и тот же объект.
     * Своей статики для этого не нужно: она пережила бы и запрос, и смену настроек, а в консоли
     * и воркере очереди — вообще всё время жизни процесса.
     */
    private ?Connection $connection = null;

    public function __construct(private readonly ManticoreSettings $settings)
    {
    }

    /**
     * Подключение к демону — одно на запрос.
     *
     * Объект соединения создаётся лениво и сам по себе ещё ничего не открывает: PDO подключается
     * при первом запросе. Поэтому вызов метода безопасен и на страницах, где поиска не будет.
     */
    public function get(): Connection
    {
        return $this->connection ??= new Connection([
            'dsn' => $this->dsn(),
            'username' => $this->settings->username,
            'password' => $this->settings->password,
            // Ни charset, ни enableSchemaCache здесь не «настройки по вкусу» — см. описание класса.
            'charset' => null,
            'emulatePrepare' => true,
            'enableSchemaCache' => false,
            'attributes' => [
                PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
            ],
        ]);
    }

    /** Адрес демона — для страницы состояния индекса и для сообщений об ошибках. */
    public function dsn(): string
    {
        return sprintf('mysql:host=%s;port=%d', $this->settings->host, $this->settings->port);
    }
}
