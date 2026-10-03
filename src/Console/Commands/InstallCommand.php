<?php

declare(strict_types=1);

namespace Linkado\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Linkado\Laravel\Support\LinkadoConfiguration;

class InstallCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'linkado:install
        {--force : Overwrite existing published Linkado resources}';

    /**
     * The command description.
     */
    protected $description = 'Publish Linkado package resources.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $arguments = [
            '--tag' => 'linkado',
        ];

        if ($this->option('force')) {
            $arguments['--force'] = true;
        }

        $this->components->info('Publishing Linkado resources.');

        if ($this->call('vendor:publish', $arguments) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->components->info('Linkado resources published.');

        foreach (app(LinkadoConfiguration::class)->configurationIssues()['errors'] as $issue) {
            $this->line($issue['code'].': '.$issue['setting']);
        }

        return self::SUCCESS;
    }
}
