<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Doctrine;

use App\Assist\Api\LocalAiPurpose;
use App\Assist\Api\LocalModelName;
use App\Assist\Api\LocalPackageId;
use App\Assist\Application\LocalAi\IssuedLocalPackage;
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

    private const string COUNT_OPEN = 'SELECT count(*) FROM ast_local_package'
        .' WHERE organization_id = ? AND user_id = ? AND used_at IS NULL AND expires_at > ?';

    private const string INSERT = 'INSERT INTO ast_local_package (organization_id, id, user_id, ticket_id, purpose, prompt_version, created_at, expires_at)'
        .' VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

    private const string RESOLVE = 'SELECT organization_id, user_id FROM resolve_laptop_package(?::uuid)';

    private const string SELECT_OF_USER = 'SELECT purpose, prompt_version, (used_at IS NULL AND expires_at > ?) AS is_open'
        .' FROM ast_local_package WHERE organization_id = ? AND id = ? AND user_id = ?';

    private const string POST = 'UPDATE ast_local_package SET used_at = ?, result_text = ?, model = ?'
        .' WHERE organization_id = ? AND id = ? AND user_id = ? AND used_at IS NULL AND expires_at > ?';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection, private TenantContext $tenantContext, private ClockInterface $clock)
    {
    }

    public function issue(LocalPackageId $packageId, OrganizationId $organizationId, UserId $requesterId, TicketId $ticketId, LocalAiPurpose $purpose, PromptVersion $promptVersion, int $lifetimeSeconds, int $maxOpenPackages): ?\DateTimeImmutable
    {
        $this->requireTenant($organizationId);
        $now = $this->clock->now();
        $this->connection->executeStatement(self::LOCK, [\sprintf('ast_local_package:open:%s:%s', $organizationId->toString(), $requesterId->toString())]);
        $openPackages = $this->connection->fetchOne(self::COUNT_OPEN, [$organizationId->toString(), $requesterId->toString(), $now->format(self::TIMESTAMP_FORMAT)]);
        if (!\is_int($openPackages)) {
            throw new \UnexpectedValueException('The count of open laptop packages is not a number.');
        }
        if ($openPackages >= $maxOpenPackages) {
            return null;
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
        if (null === $purpose || !\is_string($row['prompt_version']) || !\is_bool($row['is_open'])) {
            throw new \UnexpectedValueException('A stored laptop package has a purpose or prompt version issue() does not write.');
        }
        try {
            $promptVersion = PromptVersion::fromName($row['prompt_version']);
        } catch (UnknownPromptTemplate $unreadable) {
            throw new \UnexpectedValueException('A stored laptop package has a prompt version issue() does not write.', 0, $unreadable);
        }

        return new IssuedLocalPackage($purpose, $promptVersion, $row['is_open']);
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
