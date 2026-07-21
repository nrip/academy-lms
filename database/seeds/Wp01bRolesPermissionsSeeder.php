<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Idempotent roles/permissions seeder (catalogue also applied in WP-01B-1 migration).
 * Local bootstrap admin is CLI-only — this seeder does not create users.
 */
final class Wp01bRolesPermissionsSeeder extends AbstractSeed
{
    public function run(): void
    {
        // Catalogue is owned by migration 20260720000003 for deterministic CI/test migrate.
        // This seeder is a no-op re-entry point for ops documentation compatibility.
    }
}
