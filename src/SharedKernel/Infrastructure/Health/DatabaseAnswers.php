<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Health;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * PostgreSQL answers `SELECT 1` as the default connection's role, on a short-lived connection with the default
 * connection's settings and a 1 s connect timeout. The app's own connection keeps pdo_pgsql's 30 s default and is
 * left untouched, so a probe never changes its state.
 *
 * Runs first: the checks that query the database run only after it answered here, so a database that does not
 * answer costs the readiness route about a second, not 30.
 */
#[AsTaggedItem(priority: 100)]
final readonly class DatabaseAnswers implements ReadinessCheck
{
    private const int CONNECT_TIMEOUT_SECONDS = 1;

    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function verify(): void
    {
        $params = $this->connection->getParams();
        $driverOptions = $params['driverOptions'] ?? [];
        // `+` and not a spread: the PDO attribute keys are integers, which a spread would renumber.
        $params['driverOptions'] = [\PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS] + (\is_array($driverOptions) ? $driverOptions : []);
        $probe = new Connection($params, $this->connection->getDriver(), $this->connection->getConfiguration());

        try {
            $probe->executeQuery('SELECT 1');
        } finally {
            $probe->close();
        }
    }
}
