<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\LoginAttempts;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * LoginAttempts on idn_login_attempt. No entity maps the table, and pinround_app may only insert and read it.
 */
final readonly class DbalLoginAttempts implements LoginAttempts
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function record(string $emailHash, string $ipHash, bool $success): void
    {
        $at = $this->clock->now();
        // The id carries the same instant as at, so ordering by id and by at agree.
        $id = new UuidV7(UuidV7::generate($at));
        $this->connection->executeStatement(
            'INSERT INTO idn_login_attempt (id, email_hash, ip_hash, success, at) VALUES (?, ?, ?, ?, ?)',
            [$id->toRfc4122(), $emailHash, $ipHash, $success, $id->getDateTime()->format('Y-m-d H:i:s.uP')],
            [1 => ParameterType::BINARY, 2 => ParameterType::BINARY, 3 => ParameterType::BOOLEAN],
        );
    }
}
