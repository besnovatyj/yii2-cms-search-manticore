<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchManticore\settings;

/**
 * Настройки ядра Manticore — готовые значения, ничего не вычисляющие.
 *
 * Собираются {@see ManticoreSettingsFactory} из двух источников по природе значения:
 * реквизиты подключения приезжают с окружением (секреты/переменные), свойства индекса —
 * из настроек в админке. Здесь они уже сведены вместе и разобраны.
 */
final readonly class ManticoreSettings
{
    /**
     * @param string $host          Адрес демона.
     * @param int    $port          Порт SQL-интерфейса демона.
     * @param string $username      Учётная запись; пустая строка — подключаться анонимно.
     * @param string $password      Пароль учётной записи.
     * @param string $table         Имя таблицы индекса, заданное вручную; пустая строка — ядро
     *                              вычислит его по имени базы проекта, чтобы несколько сайтов
     *                              могли делить один демон.
     * @param string $morphology    Набор морфологических обработчиков для индекса; пустая строка —
     *                              без морфологии. Вариант «по языку сайта» уже разрешён фабрикой.
     * @param bool   $suggestions   Собирать ли словарь подстрок, без которого не работают подсказки.
     * @param int    $minInfixLen   Длина минимальной подстроки в словаре; 0 — словарь не собирается.
     * @param int    $fuzzyDistance Допуск опечаток в буквах; 0 — искать без опечаток.
     * @param string $layouts       Раскладки клавиатуры для запроса, набранного не в той раскладке;
     *                              пустая строка — не распознавать.
     * @param int    $maxMatches    Сколько совпадений демон держит в памяти на один запрос.
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public string $table,
        public string $morphology,
        public bool $suggestions,
        public int $minInfixLen,
        public int $fuzzyDistance,
        public string $layouts,
        public int $maxMatches,
    ) {
    }
}
