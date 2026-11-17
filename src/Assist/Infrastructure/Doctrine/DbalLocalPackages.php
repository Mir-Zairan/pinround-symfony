<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Doctrine;

use App\Assist\Api\LocalAiPurpose;
use App\Assist\Api\LocalModelName;
use App\Assist\Api\LocalPackageId;
use App\Assist\Application\LocalAi\IssuedLocalPackage;
use App\Assist\Application\LocalAi\LocalPackageLimit;
use App\Assist\Application\LocalAi\LocalPackageOwner;
use App\Assist\Application\LocalAi\LocalPackages;
use App\Assist\Application\PromptVersion;
use App\Assist\Application\UnknownPromptTemplate;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * LocalPackages on ast_local_package. Names the tenant's organization_id as well as row-level security, and the
 * package's user, so nobody reads or uses another person's package. Times come from the application clock, never the
 * database's, so "now" is the same for the check and the write.
 */
final readonly class DbalLocalPackages implements LocalPackages
{
    // An advisory lock, not a row lock: a person with no open package has no row to lock.
    private const string LOCK = 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))';

    // A package created in the last hour expires after an hour ago (expires_at > created_at), and so does an open one,
    // so the bound on expires_at lets the (organization_id, user_id, expires_at) index find both counts' rows.
    private const string COUNT_OPEN_AND_LAST_HOUR = 'SELECT count(*) FILTER (WHERE used_at IS NULL AND expires_at > ?) AS open_packages,'
        .' count(*) FILTER (WHERE created_at > ?) AS packages_last_hour'
        .' FROM ast_local_package WHERE organization_id = ? AND user_id = ? AND expires_at > ?';

    private const string INSERT = 'INSERT INTO ast_local_package (organization_id, id, user_id, ticket_id, purpose, prompt_version, created_at, expires_at)'
        .' VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

    private const string RESOLVE = 'SELECT organization_id, user_id FROM resolve_laptop_package(?::uuid)';

    private const string SELECT_OF_USER = 'SELECT purpose, ticket_id, prompt_version, (used_at IS NULL AND expires_at > ?) AS is_open'
        .' FROM ast_local_package WHERE organization_id = ? AND id = ? AND user_id = ?';

    private const string POST = 'UPDATE ast_local_package SET used_at = ?, result_text = ?, model = ?'
        .' WHERE organization_id = ? AND id = ? AND user_id = ? AND used_at IS NULL AND expires_at > ?';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection, private TenantContext $tenantContext, private ClockInterface $clock)
    {
    }

    public function issue(LocalPackageId $packageId, OrganizationId $organizationId, UserId $requesterId, TicketId $ticketId, LocalAiPurpose $purpose, PromptVersion $promptVersion, int $lifetimeSeconds, int $maxOpenPackages, int $maxPackagesPerHour): \DateTimeImmutable|LocalPackageLimit
    {
        $this->requireTenant($organizationId);
        $now = $this->clock->now();
        $anHourAgo = $now->modify('-1 hour')->format(self::TIMESTAMP_FORMAT);
        $this->connection->executeStatement(self::LOCK, [\sprintf('ast_local_package:open:%s:%s', $organizationId->toString(), $requesterId->toString())]);
        $counts = $this->connection->fetchAssociative(self::COUNT_OPEN_AND_LAST_HOUR, [
            $now->format(self::TIMESTAMP_FORMAT),
            $anHourAgo,
            $organizationId->toString(),
            $requesterId->toString(),
            $anHourAgo,
        ]);
        if (false === $counts || !\is_int($counts['open_packages']) || !\is_int($counts['packages_last_hour'])) {
            throw new \UnexpectedValueException('The counts of laptop packages are not numbers.');
        }
        if ($counts['open_packages'] >= $maxOpenPackages) {
            return LocalPackageLimit::OpenPackages;
        }
        if ($counts['packages_last_hour'] >= $maxPackagesPerHour) {
            return LocalPackageLimit::PackagesPerHour;
        }

        $expiresAt = $now->modify(\sprintf('+%d seconds', $lifetimeSeconds));
        $this->connection->executeStatement(self::INSERT, [
            $organizationId->toString(),
            $packageId->toString(),
            $requesterId->toString(),
            $ticketId->toString(),
            $purpose->value,
            $promptVersion->name(),
            $now->format(self::TIMESTAMP_FORMAT),
            $expiresAt->format(self::TIMESTAMP_FORMAT),
        ]);

        return $expiresAt;
    }

    public function ownerOf(LocalPackageId $packageId): ?LocalPackageOwner
    {
        $row = $this->connection->fetchAssociative(self::RESOLVE, [$packageId->toString()]);
        if (false === $row) {
            return null;
        }
        if (!\is_string($row['organization_id']) || !\is_string($row['user_id'])) {
            throw new \UnexpectedValueException('resolve_laptop_package gave a row without its ids.');
        }

        return new LocalPackageOwner(new OrganizationId($row['organization_id']), new UserId($row['user_id']));
    }

    public function issuedOf(OrganizationId $organizationId, LocalPackageId $packageId, UserId $userId): ?IssuedLocalPackage
    {
        $this->requireTenant($organizationId);
        $row = $this->connection->fetchAssociative(self::SELECT_OF_USER, [
            $this->clock->now()->format(self::TIMESTAMP_FORMAT),
            $organizationId->toString(),
            $packageId->toString(),
            $userId->toString(),
        ]);
        if (false === $row) {
            return null;
        }
        $purpose = \is_string($row['purpose']) ? LocalAiPurpose::tryFrom($row['purpose']) : null;
        if (null === $purpose || !\is_string($row['ticket_id']) || !\is_string($row['prompt_version']) || !\is_bool($row['is_open'])) {
            throw new \UnexpectedValueException('A stored laptop package has a purpose, ticket or prompt version issue() does not write.');
        }
        try {
            $promptVersion = PromptVersion::fromName($row['prompt_version']);
        } catch (UnknownPromptTemplate $unreadable) {
            throw new \UnexpectedValueException('A stored laptop package has a prompt version issue() does not write.', 0, $unreadable);
        }

        return new IssuedLocalPackage($purpose, new TicketId($row['ticket_id']), $promptVersion, $row['is_open']);
    }

    public function post(OrganizationId $organizationId, LocalPackageId $packageId, UserId $posterId, string $text, LocalModelName $model): ?\DateTimeImmutable
    {
        $this->requireTenant($organizationId);
        $now = $this->clock->now();
        $nowText = $now->format(self::TIMESTAMP_FORMAT);
        $updated = $this->connection->executeStatement(self::POST, [
            $nowText,
            $text,
            $model->value,
            $organizationId->toString(),
            $packageId->toString(),
            $posterId->toString(),
            $nowText,
        ]);

        return 1 === $updated ? $now : null;
    }

    /**
     * @throws \LogicException when no transaction is open or $organizationId is not its tenant
     */
    private function requireTenant(OrganizationId $organizationId): void
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would hide every row.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Laptop packages are kept in a transaction under the organization as tenant; none is open.');
        }
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new \LogicException('Laptop packages are kept under their own organization as tenant.');
        }
    }
}
