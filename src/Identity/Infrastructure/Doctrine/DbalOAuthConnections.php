<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Api\OAuthConnection;
use App\Identity\Application\OAuthConnections;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * OAuthConnections on idn_oauth_refresh_token, naming the tenant's organization_id as well as row-level security does.
 * Each row of a list is a family's newest token, the only one neither traded nor revoked, found through
 * idn_oauth_refresh_token_user_id_live_idx; its family's other tokens give when it was connected and whether it was
 * renewed, through idn_oauth_refresh_token_family_id_idx. The client's name comes from idn_oauth_client, a global table.
 */
final readonly class DbalOAuthConnections implements OAuthConnections
{
    private const string SELECT_LIVE = 'SELECT live.family_id::text AS family_id, live.user_id::text AS user_id,'
        ." COALESCE(NULLIF(client.client_name, ''), live.client_id) AS client_name,"
        .' array_to_json(live.scopes)::text AS scopes,'
        ." to_char(family.connected_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS connected_at,"
        ." CASE WHEN family.is_renewed THEN to_char(live.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') END AS last_renewed_at"
        .' FROM idn_oauth_refresh_token live'
        .' CROSS JOIN LATERAL (SELECT min(f.created_at) AS connected_at, bool_or(f.used_at IS NOT NULL) AS is_renewed'
        .' FROM idn_oauth_refresh_token f WHERE f.organization_id = live.organization_id AND f.family_id = live.family_id) family'
        .' LEFT JOIN idn_oauth_client client ON client.client_id = live.client_id'
        .' WHERE live.organization_id = ? AND live.revoked_at IS NULL AND live.used_at IS NULL AND live.expires_at > ?';

    private const string NEWEST_FIRST = ' ORDER BY family.connected_at DESC, live.family_id DESC LIMIT ?';

    // The columns keep whole seconds, and PostgreSQL rounds a fraction; cutting it here keeps "now" from moving ahead.
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:sP';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function liveOf(UserId $userId, \DateTimeImmutable $at): array
    {
        $organizationId = $this->tenantContext->require()->toString();

        return $this->connectionsOf($this->connection->fetchAllAssociative(
            self::SELECT_LIVE.' AND live.user_id = ?'.self::NEWEST_FIRST,
            [$organizationId, $at->format(self::TIMESTAMP_FORMAT), $userId->toString(), self::MAX_LISTED],
            [3 => ParameterType::INTEGER],
        ));
    }

    public function livePage(\DateTimeImmutable $at, int $limit, ?\DateTimeImmutable $beforeConnectedAt = null, ?string $beforeFamilyId = null): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A page of connections holds at least one.');
        }
        if ((null === $beforeConnectedAt) !== (null === $beforeFamilyId) || (null !== $beforeFamilyId && !Uuid::isValid($beforeFamilyId))) {
            throw new \InvalidArgumentException('A page of connections starts after both the connection time and the id of a connection.');
        }
        $organizationId = $this->tenantContext->require()->toString();
        if (null === $beforeConnectedAt || null === $beforeFamilyId) {
            return $this->connectionsOf($this->connection->fetchAllAssociative(
                self::SELECT_LIVE.self::NEWEST_FIRST,
                [$organizationId, $at->format(self::TIMESTAMP_FORMAT), $limit],
                [2 => ParameterType::INTEGER],
            ));
        }

        return $this->connectionsOf($this->connection->fetchAllAssociative(
            self::SELECT_LIVE.' AND (family.connected_at, live.family_id) < (?::timestamptz, ?::uuid)'.self::NEWEST_FIRST,
            [$organizationId, $at->format(self::TIMESTAMP_FORMAT), $beforeConnectedAt->format(self::TIMESTAMP_FORMAT), strtolower($beforeFamilyId), $limit],
            [4 => ParameterType::INTEGER],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<OAuthConnection>
     */
    private function connectionsOf(array $rows): array
    {
        return array_map(static function (array $row): OAuthConnection {
            if (!\is_string($row['family_id']) || !\is_string($row['user_id']) || !\is_string($row['client_name']) || !\is_string($row['scopes']) || !\is_string($row['connected_at'])) {
                throw new \UnexpectedValueException('A connection row lacks a column.');
            }
            $scopes = json_decode($row['scopes'], true, 2, \JSON_THROW_ON_ERROR);
            if (!\is_array($scopes) || !array_is_list($scopes) || [] !== array_filter($scopes, static fn (mixed $scope): bool => !\is_string($scope))) {
                throw new \UnexpectedValueException('The scopes of a connection are not a list of names.');
            }
            /** @var list<string> $scopes */
            $lastRenewedAt = \is_string($row['last_renewed_at']) ? new \DateTimeImmutable($row['last_renewed_at'].'+00:00') : null;

            return new OAuthConnection(
                $row['family_id'],
                new UserId($row['user_id']),
                $row['client_name'],
                new \DateTimeImmutable($row['connected_at'].'+00:00'),
                $lastRenewedAt,
                $scopes,
            );
        }, $rows);
    }
}
