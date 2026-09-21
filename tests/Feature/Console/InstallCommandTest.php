<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->files = app(Filesystem::class);
    $this->publishedConfigPath = config_path('linkado.php');
    $this->publishedLanguagePath = app()->langPath('vendor/linkado');
    $this->migrationPattern = database_path('migrations/*_linkado_*_table.php');
});

it('publishes Linkado resources safely when run more than once', function (): void {
    $this->artisan('linkado:install')
        ->expectsOutputToContain('Linkado resources published.')
        ->assertSuccessful();

    $this->artisan('linkado:install')
        ->assertSuccessful();

    $publishedMigrations = glob($this->migrationPattern) ?: [];

    expect($this->files->exists($this->publishedConfigPath))->toBeTrue()
        ->and($this->files->exists($this->publishedLanguagePath.'/en/messages.php'))->toBeTrue()
        ->and($publishedMigrations)->toHaveCount(4)
        ->and(collect($publishedMigrations)->map(fn (string $path): string => basename($path))->implode("\n"))
        ->toContain('create_linkado_outbox_events_table.php')
        ->toContain('create_linkado_outbox_attempts_table.php')
        ->toContain('create_linkado_pending_attributions_table.php')
        ->toContain('2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php');
});

it('does not overwrite an existing Linkado configuration without force', function (): void {
    $hostConfiguration = "<?php\n\nreturn ['host' => true];\n";

    $this->files->put($this->publishedConfigPath, $hostConfiguration);

    $this->artisan('linkado:install')
        ->assertSuccessful();

    expect($this->files->get($this->publishedConfigPath))->toBe($hostConfiguration);
});

it('overwrites an existing Linkado configuration when forced', function (): void {
    $this->files->put($this->publishedConfigPath, "<?php\n\nreturn ['host' => true];\n");

    $this->artisan('linkado:install', ['--force' => true])
        ->assertSuccessful();

    expect($this->files->get($this->publishedConfigPath))->toContain("'mode' => env('LINKADO_MODE', 'off'),");
});

it('preserves published migrations across later installation and publication', function (string $command, array $arguments): void {
    $this->travelTo(now()->startOfDay());
    $this->artisan('linkado:install')->assertSuccessful();

    $original = glob($this->migrationPattern) ?: [];
    expect($original)->toHaveCount(4);
    $this->files->append($original[0], "\n// Host migration customization.\n");
    $contents = array_map(fn (string $path): string => $this->files->get($path), $original);

    $this->travel(1)->days();
    $this->artisan($command, $arguments)->assertSuccessful();

    expect(glob($this->migrationPattern))->toBe($original)
        ->and(array_map(fn (string $path): string => $this->files->get($path), $original))->toBe($contents);
})->with([
    'install' => ['linkado:install', []],
    'all resources tag' => ['vendor:publish', ['--tag' => 'linkado']],
    'migration tag' => ['vendor:publish', ['--tag' => 'linkado-migrations']],
]);

it('can migrate before and after later installation without recreating tables', function (): void {
    config()->set('database.connections.install_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'install_test');
    config()->set('linkado.connection', 'install_test');
    DB::purge('install_test');

    $this->travelTo(now()->startOfDay());
    $this->artisan('linkado:install')->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $this->travel(1)->days();
    $this->artisan('linkado:install')->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(glob($this->migrationPattern))->toHaveCount(4)
        ->and(DB::table('migrations')->count())->toBe(4)
        ->and(Schema::hasTable('linkado_outbox_events'))->toBeTrue()
        ->and(Schema::hasTable('linkado_outbox_attempts'))->toBeTrue()
        ->and(Schema::hasTable('linkado_pending_attributions'))->toBeTrue()
        ->and(Schema::hasColumn('linkado_pending_attributions', 'identity_hash'))->toBeTrue();
});
