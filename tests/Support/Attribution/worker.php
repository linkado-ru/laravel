<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Linkado\Laravel\Actions\CapturePendingAttribution;
use Linkado\Laravel\Actions\ConsumePendingAttribution;
use Linkado\Laravel\Events\AttributionConsumed;
use Linkado\Laravel\Support\Database\RequiresActiveTransaction;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;

require __DIR__.'/../../../vendor/autoload.php';

/** @param array<string, mixed> $message */
function send(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
}

function barrier(string $stage): void
{
    send(['stage' => $stage]);
    $command = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);

    if (isset($command['now'])) {
        CarbonImmutable::setTestNow($command['now']);
    }
}

try {
    $options = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $container = new Container;
    $container->instance('config', new Repository);
    $container->instance('db.transactions', new DatabaseTransactionsManager);
    $events = new Dispatcher($container);
    $capsule = new Manager($container);
    $capsule->setEventDispatcher($events);
    $databaseConfiguration = DatabaseConfiguration::externalOrSqlite();
    $databaseConfiguration['prefix'] = $options['prefix'] ?? '';
    $capsule->addConnection($databaseConfiguration);
    $connection = $capsule->getConnection();

    if (($options['isolation'] ?? null) === 'read-committed') {
        $connection->statement($connection->getDriverName() === 'pgsql'
            ? 'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED'
            : 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    $sessionId = $connection->selectOne($connection->getDriverName() === 'pgsql'
        ? 'SELECT pg_backend_pid() AS id' : 'SELECT CONNECTION_ID() AS id')->id;
    send(['stage' => 'ready', 'session' => (int) $sessionId]);
    CarbonImmutable::setTestNow($options['now'] ?? '2026-09-21 10:00:00');
    $configuration = new LinkadoConfiguration(new Repository(['linkado' => [
        'connection' => 'default',
        'tracking' => ['ttl_seconds' => 120, 'visitor_cookie' => 'linkado_visitor'],
    ]]));
    $eventCount = 0;
    $events->listen(AttributionConsumed::class, function () use (&$eventCount): void {
        $eventCount++;
    });
    $beforeReached = false;
    $connection->beforeExecuting(function (string $sql) use ($options, &$beforeReached): void {
        $prefix = ($options['before'] ?? null) === 'insert' ? 'insert into' : 'select';

        if (! $beforeReached && isset($options['before']) && str_starts_with($sql, $prefix) && str_contains($sql, 'linkado_pending_attributions')) {
            $beforeReached = true;
            barrier('before');
        }
    });
    $held = false;
    $connection->listen(function (QueryExecuted $query) use ($options, &$held): void {
        if (isset($options['after_lock_now']) && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'for update')) {
            CarbonImmutable::setTestNow($options['after_lock_now']);
        }

        if (($options['hold'] ?? false) && ! $held && str_starts_with($query->sql, 'update') && str_contains($query->sql, 'linkado_pending_attributions')) {
            $held = true;
            barrier('holding');
        }
    });
    $snapshot = null;

    if ($options['operation'] === 'capture') {
        if ($options['outer'] ?? false) {
            $connection->beginTransaction();
        }
        (new CapturePendingAttribution($capsule->getDatabaseManager(), $configuration))->handle(
            $options['visitor'], $options['click'] ?? null, $options['slug'] ?? null,
        );

        if ($options['outer'] ?? false) {
            $connection->table('linkado_pending_attributions')->count();
            $connection->commit();
        }
    } else {
        $consume = new ConsumePendingAttribution(new RequiresActiveTransaction($capsule->getDatabaseManager(), $configuration), $configuration, $events);
        $connection->beginTransaction();
        $snapshot = $consume->handle(Request::create('/register', 'POST', cookies: ['linkado_visitor' => $options['visitor']]));

        if ($options['rollback'] ?? false) {
            $connection->rollBack();
        } else {
            $connection->commit();
        }
    }
    send(['stage' => 'done', 'snapshot' => $snapshot === null ? null : ['click' => $snapshot->clickId, 'slug' => $snapshot->referralSlug], 'events' => $eventCount]);
} catch (Throwable $exception) {
    // Never print connection errors, SQL bindings, or credentials from a worker.
    send(['stage' => 'error', 'class' => $exception::class, 'code' => (string) $exception->getCode()]);
    exit(1);
}
