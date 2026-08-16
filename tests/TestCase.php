<?php

namespace HypathBel\ModelScribe\Tests;

use HypathBel\ModelScribe\ModelScribeServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            ModelScribeServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');

        // Register the package migration so RefreshDatabase can migrate it.
        \Orchestra\Testbench\load_migration_paths($app, __DIR__.'/../database/migrations');
    }
}
