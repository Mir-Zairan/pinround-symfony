<?php

declare(strict_types=1);

namespace App\Web\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Web\Domain\HomeFocus;
use App\Web\Domain\HomeFocusPreferences;
use Doctrine\DBAL\Connection;

/**
 * HomeFocusPreferences on web_home_focus under row-level security. The organization is always the tenant's, never a
 * value from the request.
 */
final readonly class DbalHomeFocusPreferences implements HomeFocusPreferences
{
    private const string FOCUS_OF = 'SELECT focus FROM web_home_focus WHERE organization_id = ? AND user_id = ?';

    private const string REMEMBER = 'INSERT INTO web_home_focus (organization_id, user_id, focus, updated_at) VALUES (?, ?, ?, ?)'
        .' ON CONFLICT (organization_id, user_id) DO UPDATE SET focus = EXCLUDED.focus, updated_at = EXCLUDED.updated_at';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function focusOf(UserId $userId): ?HomeFocus
    {
        $organizationId = $this->tenantContext->get();
        if (null === $organizationId) {
            return null;
        }

        $focus = $this->connection->fetchOne(self::FOCUS_OF, [$organizationId->toString(), $userId->toString()]);
        if (false === $focus) {
            return null;
        }

        return (\is_string($focus) ? HomeFocus::tryFrom($focus) : null) ?? throw new \LogicException('web_home_focus holds an unknown focus.');
    }

    public function remember(UserId $userId, HomeFocus $focus, \DateTimeImmutable $at): void
    {
        $organizationId = $this->tenantContext->require();
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would refuse the row anyway.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A Home focus is saved in the request transaction; none is open.');
        }

        $this->connection->executeStatement(
            self::REMEMBER,
            [$organizationId->toString(), $userId->toString(), $focus->value, $at->format('Y-m-d H:i:sP')],
        );
    }
}
