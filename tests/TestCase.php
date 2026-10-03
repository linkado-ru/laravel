<?php

declare(strict_types=1);

namespace Linkado\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Linkado\Laravel\LinkadoServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Saloon\Config;

abstract class TestCase extends Orchestra
{
    private string $publishingRoot;

    public function createApplication(): Application
    {
        $cache = sys_get_temp_dir().'/linkado-testing-config-'.getmypid().'-'.bin2hex(random_bytes(8)).'.php';
        $_ENV['APP_CONFIG_CACHE'] = $cache;
        $_SERVER['APP_CONFIG_CACHE'] = $cache;
        putenv('APP_CONFIG_CACHE='.$cache);

        return parent::createApplication();
    }

    protected function defineEnvironment($app): void
    {
        // Each test application owns its throttle counters, including parallel workers.
        $app['config']->set('cache.default', 'array');
        Config::preventStrayRequests();

        $this->publishingRoot = sys_get_temp_dir().'/linkado-publish-'.bin2hex(random_bytes(12));
        $files = new Filesystem;

        foreach (['config', 'database/migrations', 'lang'] as $directory) {
            $files->ensureDirectoryExists($this->publishingRoot.'/'.$directory);
        }

        $app->useConfigPath($this->publishingRoot.'/config');
        $app->useDatabasePath($this->publishingRoot.'/database');
        $app->useLangPath($this->publishingRoot.'/lang');
    }

    /** @return array<string, mixed> */
    protected function packageDefaults(): array
    {
        $values = [];
        $keys = ['LINKADO_MODE', 'LINKADO_URL', 'LINKADO_BASE_URL', 'LINKADO_TOKEN', 'LINKADO_PROGRAM_KEY',
            'LINKADO_SSO_ENABLED', 'LINKADO_TRACKING_ENABLED', 'LINKADO_CUSTOMER_EVENTS_ENABLED',
            'LINKADO_BILLING_EVENTS_ENABLED', 'LINKADO_REFUND_EVENTS_ENABLED'];

        foreach ($keys as $key) {
            $values[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            return require __DIR__.'/../config/linkado.php';
        } finally {
            foreach ($values as $key => [$environment, $server, $process]) {
                if ($environment !== null) {
                    $_ENV[$key] = $environment;
                }

                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                putenv($process === false ? $key : $key.'='.$process);
            }
        }
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
