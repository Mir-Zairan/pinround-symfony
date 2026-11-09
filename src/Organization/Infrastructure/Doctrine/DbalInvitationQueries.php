<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\InvitationQueries;
use App\Organization\Api\PendingInvitation;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

final readonly class DbalInvitationQueries implements InvitationQueries
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function pendingOf(OrganizationId $organizationId, int $limit, ?PendingInvitation $after = null): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('A page of pending invitations holds 1 to %d, not %d.', self::MAX_LIMIT, $limit));
        }

        $sql = 'SELECT id, email, created_at, expires_at FROM org_invitation'
            .' WHERE organization_id = ? AND accepted_at IS NULL AND expires_at > ?';
        $parameters = [$organizationId->toString(), $this->clock->now()->format(self::TIMESTAMP_FORMAT)];
        if (null !== $after) {
            $sql .= ' AND (created_at, id) < (?, ?::uuid)';
            $parameters[] = $after->invitedAt()->format(self::TIMESTAMP_FORMAT);
            $parameters[] = $after->id();
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';
        $parameters[] = $limit;

        $rows = $this->connection->fetchAllAssociative($sql, $parameters, [\count($parameters) - 1 => ParameterType::INTEGER]);

        return array_map(
            static fn (array $row): PendingInvitation => new PendingInvitation(
                self::text($row['id'], 'id'),
                self::text($row['email'], 'email'),
                new \DateTimeImmutable(self::text($row['created_at'], 'created_at')),
                new \DateTimeImmutable(self::text($row['expires_at'], 'expires_at')),
            ),
            $rows,
        );
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('An invitation %s from the database is not a string.', $column));
    }
}
