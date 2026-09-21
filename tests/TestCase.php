<?php

declare(strict_types=1);

namespace Linkado\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
use Linkado\Laravel\LinkadoServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    private string $publishingRoot;

    protected function defineEnvironment($app): void
    {
        // Each test application owns its throttle counters, including parallel workers.
        $app['config']->set('cache.default', 'array');

        $this->publishingRoot = sys_get_temp_dir().'/linkado-publish-'.bin2hex(random_bytes(12));
        $files = new Filesystem;

        foreach (['config', 'database/migrations', 'lang'] as $directory) {
            $files->ensureDirectoryExists($this->publishingRoot.'/'.$directory);
        }

        $app->useConfigPath($this->publishingRoot.'/config');
        $app->useDatabasePath($this->publishingRoot.'/database');
        $app->useLangPath($this->publishingRoot.'/lang');
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory($this->publishingRoot);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            LinkadoServiceProvider::class,
        ];
    }
}
