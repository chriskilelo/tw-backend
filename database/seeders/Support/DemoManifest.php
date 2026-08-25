<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\File;

/**
 * Tracks every row DemoDataSeeder inserts (by table name + primary key) so
 * `php artisan demo-data:clear` can remove exactly what was seeded, without
 * touching QA fixture accounts, kpi_definitions, or any other pre-existing
 * configuration data. Written once to storage/app/demo-data/manifest.json
 * at the end of a successful seed run.
 */
final class DemoManifest
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $ids = [];

    /**
     * @var array<int, array{mission_id: string, previous_active_attache_user_id: ?string}>
     */
    private array $missionLinkResets = [];

    public static function path(): string
    {
        return storage_path('app/demo-data/manifest.json');
    }

    public function add(string $table, string $id): void
    {
        $this->ids[$table][] = $id;
    }

    /**
     * @param  array<int, string>  $ids
     */
    public function addMany(string $table, array $ids): void
    {
        foreach ($ids as $id) {
            $this->add($table, $id);
        }
    }

    public function recordMissionLinkReset(string $missionId, ?string $previousActiveAttacheUserId): void
    {
        $this->missionLinkResets[] = [
            'mission_id' => $missionId,
            'previous_active_attache_user_id' => $previousActiveAttacheUserId,
        ];
    }

    public function save(): void
    {
        File::ensureDirectoryExists(dirname(self::path()));

        File::put(self::path(), json_encode([
            'seeded_at' => now()->toIso8601String(),
            'tables' => $this->ids,
            'mission_link_resets' => $this->missionLinkResets,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @return array{seeded_at: string, tables: array<string, array<int, string>>, mission_link_resets: array<int, array{mission_id: string, previous_active_attache_user_id: ?string}>}|null
     */
    public static function load(): ?array
    {
        if (! File::exists(self::path())) {
            return null;
        }

        return json_decode(File::get(self::path()), true);
    }

    public static function delete(): void
    {
        File::delete(self::path());
    }
}
