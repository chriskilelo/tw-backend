<?php

namespace Database\Seeders\Support;

/**
 * Deterministic pseudo-randomness shared by every Demo*Seeder, so the same
 * mission/quarter/attribute always produces the same number/decision on a
 * re-run (idempotent content after a demo-data:clear + reseed cycle),
 * without needing to persist a separate random-seed store.
 */
final class Determinism
{
    public static function seeded(string $key, int $min, int $max): int
    {
        return $min + (crc32($key) % ($max - $min + 1));
    }

    public static function chance(string $key, int $percentTrue): bool
    {
        return self::seeded($key.'-chance', 0, 99) < $percentTrue;
    }

    /**
     * @param  array<int, T>  $items
     * @return T
     *
     * @template T
     */
    public static function pick(string $key, array $items): mixed
    {
        $items = array_values($items);

        return $items[self::seeded($key.'-pick', 0, count($items) - 1)];
    }
}
