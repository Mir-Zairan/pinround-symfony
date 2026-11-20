<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\PeopleCountQueries;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

final readonly class DbalPeopleCountQueries implements PeopleCountQueries
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function peopleCountOf(OrganizationId $organizationId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT (SELECT count(*) FROM org_membership WHERE organization_id = CAST(:organization AS uuid))'
            .' + (SELECT count(*) FROM org_invitation WHERE organization_id = CAST(:organization AS uuid)'
            .' AND accepted_at IS NULL AND expires_at > CAST(:now AS timestamptz))',
            ['organization' => $organizationId->toString(), 'now' => $this->clock->now()->format(self::TIMESTAMP_FORMAT)],
        );

        return \is_int($count) ? $count : throw new \LogicException('The people count from the database is not a number.');
    }
}
