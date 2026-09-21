<?php

declare(strict_types=1);

namespace Linkado\Laravel\Tests\Support;

use InvalidArgumentException;

final class DatabaseConfiguration
{
    /** @return array<string, mixed> */
    public static function externalOrSqlite(): array
    {
        return match ($driver = self::environment('LINKADO_TEST_DB_DRIVER', 'sqlite')) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => self::environment('LINKADO_TEST_DB_HOST', '127.0.0.1'),
                'port' => self::environment('LINKADO_TEST_DB_PORT', '3306'),
                'database' => self::environment('LINKADO_TEST_DB_DATABASE', 'linkado_test'),
                'username' => self::environment('LINKADO_TEST_DB_USERNAME', 'root'),
                'password' => self::environment('LINKADO_TEST_DB_PASSWORD', 'password'),
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => self::environment('LINKADO_TEST_DB_HOST', '127.0.0.1'),
                'port' => self::environment('LINKADO_TEST_DB_PORT', '5432'),
                'database' => self::environment('LINKADO_TEST_DB_DATABASE', 'linkado_test'),
                'username' => self::environment('LINKADO_TEST_DB_USERNAME', 'postgres'),
                'password' => self::environment('LINKADO_TEST_DB_PASSWORD', 'password'),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            default => throw new InvalidArgumentException("Unsupported Linkado test database driver [{$driver}]."),
        };
    }

    private static function environment(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) ? $value : $default;
    }
}
