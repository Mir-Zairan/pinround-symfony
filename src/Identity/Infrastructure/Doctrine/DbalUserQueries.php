<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalUserQueries implements UserQueries
{
    public function __construct(private Connection $connection)
    {
    }

    public function idByEmail(string $email): ?UserId
    {
        try {
            $address = new Email($email);
        } catch (InvalidEmail) {
            return null;
        }
        $id = $this->connection->fetchOne('SELECT id FROM idn_user WHERE email = ?', [$address->toString()]);

        return \is_string($id) ? new UserId($id) : null;
    }

    public function byIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        // One array literal parameter keeps the SQL text the same for any number of ids; a UserId is always a uuid, so
        // it needs no quoting inside the literal.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name FROM idn_user WHERE id = ANY(?::uuid[]) ORDER BY id',
            ['{'.implode(',', array_map(static fn (UserId $userId): string => $userId->toString(), $userIds)).'}'],
        );
        $users = [];
        foreach ($rows as $row) {
            if (!\is_string($row['id']) || !\is_string($row['name'])) {
                throw new \LogicException('A user id or name from the database is not a string.');
            }
            $users[$row['id']] = new UserView(new UserId($row['id']), $row['name']);
        }

        return $users;
    }
}
