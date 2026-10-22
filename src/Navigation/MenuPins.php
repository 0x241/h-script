<?php

declare(strict_types=1);

namespace HScript\Navigation;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use RuntimeException;

/** Account preferences, always intersected with the current menu permissions. */
final class MenuPins
{
    public static function get(Connection $db, int $owner, string $scope, array $allowed, array $legacy = []): array
    {
        $value = $db->fetch1($db->select('AddInfo', 'aMenuPins', 'auID=?d', [$owner]));
        $data = self::decode($value);
        if (!array_key_exists($scope, $data) && $legacy !== [])
            return self::change($db, $owner, $scope, $allowed, 'import', '', $legacy);
        return self::normalize($data[$scope] ?? [], $allowed);
    }

    public static function change(Connection $db, int $owner, string $scope, array $allowed, string $action, string $item, array $legacy = []): array
    {
        if ($owner < 1 || !in_array($scope, ['cabinet', 'admin'], true)) throw new RuntimeException('Invalid menu owner');
        return TransactionScope::run($db->link, static function () use ($db, $owner, $scope, $allowed, $action, $item, $legacy): array {
            $row = $db->fetch1Row($db->query('SELECT aMenuPins FROM AddInfo WHERE auID=?d FOR UPDATE', [$owner]));
            if (!$row) throw new RuntimeException('Account preferences unavailable');
            $data = self::decode($row['aMenuPins']);
            $pins = self::normalize($data[$scope] ?? $legacy, $allowed);
            if ($action === 'pin' && in_array($item, $allowed, true)) {
                $pins = array_values(array_diff($pins, [$item]));
                array_unshift($pins, $item);
            } elseif ($action === 'unpin') $pins = array_values(array_diff($pins, [$item]));
            $data[$scope] = array_slice($pins, 0, 100);
            $db->update('AddInfo', ['aMenuPins' => json_encode($data, JSON_THROW_ON_ERROR)], '', 'auID=?d', [$owner]);
            return $data[$scope];
        });
    }

    private static function decode(mixed $value): array
    {
        if (!is_string($value) || strlen($value) > 32768) return [];
        $decoded = json_decode($value, true, 4);
        return is_array($decoded) ? array_intersect_key($decoded, ['cabinet' => true, 'admin' => true]) : [];
    }

    private static function normalize(mixed $pins, array $allowed): array
    {
        if (!is_array($pins)) return [];
        return array_slice(array_values(array_unique(array_filter($pins,
            static fn($item): bool => is_string($item) && strlen($item) <= 120 && in_array($item, $allowed, true)))), 0, 100);
    }
}
