<?php

namespace App\Services\ExcelServices;

class CellUinCacheService
{
    private static $cache = [];
    private static $initialized = false;
    private static $userCache = [];
    private static $dateCache = [];

    public static function initialize()
    {
        if (!self::$initialized) {
            self::$cache = [];
            self::$userCache = [];
            self::$dateCache = [];
            self::$initialized = true;
        }
    }

    // Существующие методы для УИН
    public static function addCellUinMapping(string $sheetName, string $cellAddress, string $uin)
    {
        $key = self::generateKey($sheetName, $cellAddress);
        self::$cache[$key] = $uin;
    }

    public static function getUinFromCell(string $sheetName, string $cellAddress): ?string
    {
        $key = self::generateKey($sheetName, $cellAddress);
        return self::$cache[$key] ?? null;
    }

    // Новые методы для пользователя
    public static function addCellUserMapping(string $sheetName, string $cellAddress, string $user)
    {
        $key = self::generateKey($sheetName, $cellAddress);
        self::$userCache[$key] = $user;
    }

    public static function getUserFromCell(string $sheetName, string $cellAddress): ?string
    {
        $key = self::generateKey($sheetName, $cellAddress);
        return self::$userCache[$key] ?? null;
    }

    // Новые методы для даты
    public static function addCellDateMapping(string $sheetName, string $cellAddress, string $date)
    {
        $key = self::generateKey($sheetName, $cellAddress);
        self::$dateCache[$key] = $date;
    }

    public static function getDateFromCell(string $sheetName, string $cellAddress): ?string
    {
        $key = self::generateKey($sheetName, $cellAddress);
        return self::$dateCache[$key] ?? null;
    }

    public static function hasCell(string $sheetName, string $cellAddress): bool
    {
        $key = self::generateKey($sheetName, $cellAddress);
        return isset(self::$cache[$key]);
    }

    public static function clearCache()
    {
        self::$cache = [];
        self::$userCache = [];
        self::$dateCache = [];
        self::$initialized = false;
    }

    private static function generateKey(string $sheetName, string $cellAddress): string
    {
        return $sheetName . '!' . strtoupper($cellAddress);
    }

    public static function getCacheStats(): array
    {
        return [
            'total_uin_mappings' => count(self::$cache),
            'total_user_mappings' => count(self::$userCache),
            'total_date_mappings' => count(self::$dateCache),
            'uin_cache_keys' => array_keys(self::$cache),
            'user_cache_keys' => array_keys(self::$userCache),
            'date_cache_keys' => array_keys(self::$dateCache)
        ];
    }
}
