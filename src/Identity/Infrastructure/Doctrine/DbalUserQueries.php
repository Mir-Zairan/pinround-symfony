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

    public function timeZonesOf(array $userIds): array
    {
        if (\count($userIds) > self::MAX_TIME_ZONE_USER_IDS) {
            throw new \InvalidArgumentException(\sprintf('Ask for the time zones of at most %d people at once.', self::MAX_TIME_ZONE_USER_IDS));
        }
        if ([] === $userIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, time_zone FROM idn_user WHERE id = ANY(?::uuid[]) ORDER BY id',
            ['{'.implode(',', array_map(static fn (UserId $userId): string => $userId->toString(), $userIds)).'}'],
        );
        $zones = [];
        foreach ($rows as $row) {
            if (!\is_string($row['id']) || !\is_string($row['time_zone'])) {
                throw new \LogicException('A user id or time zone from the database is not a string.');
            }
            $zones[$row['id']] = self::timeZoneOrUtc($row['time_zone']);
        }

        return $zones;
    }

    public function emailsOf(array $userIds): array
    {
        if (\count($userIds) > self::MAX_EMAIL_USER_IDS) {
            throw new \InvalidArgumentException(\sprintf('Ask for the email addresses of at most %d people at once.', self::MAX_EMAIL_USER_IDS));
        }
        if ([] === $userIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, email FROM idn_user WHERE id = ANY(?::uuid[]) AND status = 'active' ORDER BY id",
            ['{'.implode(',', array_map(static fn (UserId $userId): string => $userId->toString(), $userIds)).'}'],
        );
        $emails = [];
        foreach ($rows as $row) {
            if (!\is_string($row['id']) || !\is_string($row['email'])) {
                throw new \LogicException('A user id or email from the database is not a string.');
            }
            $emails[$row['id']] = $row['email'];
        }

        return $emails;
    }

    /**
     * User only stores zones PHP knows, but a zone can leave PHP's list in a later tzdata release.
     */
    private static function timeZoneOrUtc(string $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone($name);
        } catch (\DateInvalidTimeZoneException) {
            return new \DateTimeZone('UTC');
        }
    }
}
