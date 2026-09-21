<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Linkado\Laravel\Exceptions\ActiveTransactionRequired;
use Linkado\Laravel\Support\LinkadoConfiguration;

final readonly class RequiresActiveTransaction
{
    public function __construct(
        private DatabaseManager $database,
        private LinkadoConfiguration $configuration,
    ) {}

    public function ensure(): Connection
    {
        $connection = $this->database->connection($this->configuration->connection());

        if ($connection->transactionLevel() < 1) {
            throw new ActiveTransactionRequired('An active transaction is required on the configured Linkado database connection.');
        }

        return $connection;
    }
}
