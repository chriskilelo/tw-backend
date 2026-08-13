<?php

namespace Database\Seeders;

use App\Services\PermissionCatalogueService;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the permission catalogue (CLAUDE.md Section 4, Rule 2 / BR-020).
     */
    public function run(PermissionCatalogueService $permissionCatalogueService): void
    {
        $permissionCatalogueService->seed();
    }
}
