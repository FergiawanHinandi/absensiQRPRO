<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed roles and permissions once after the test database is refreshed.
     */
    protected function afterRefreshingDatabase()
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }
}
