<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->files = app(Filesystem::class);
    $this->publishedConfigPath = config_path('linkado.php');
    $this->publishedLanguagePath = app()->langPath('vendor/linkado');
    $this->migrationPattern = database_path('migrations/*_create_linkado_*_table.php');
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
        ->and($publishedMigrations)->toHaveCount(3)
        ->and(collect($publishedMigrations)->map(fn (string $path): string => basename($path))->implode("\n"))
        ->toContain('create_linkado_outbox_events_table.php')
        ->toContain('create_linkado_outbox_attempts_table.php')
        ->toContain('create_linkado_pending_attributions_table.php');
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
