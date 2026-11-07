<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Identity\Domain\Email;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalUserQueries implements UserQueries
{
    public function __construct(private Connection $connection)
    {
    }

    public function idByEmail(string $email): ?UserId
    {
        $id = $this->connection->fetchOne('SELECT id FROM idn_user WHERE email = ?', [new Email($email)->toString()]);

        return \is_string($id) ? new UserId($id) : null;
    }
}
