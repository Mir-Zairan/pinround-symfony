<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * The organization-wide settings an admin changes, one row per organization (data-model.md, org_settings). New
 * settings hold the defaults of the Admin screen, with the first admin as the triager of tickets with no team.
 */
final class OrgSettings implements TenantOwned
{
    /** The values of data.step_policy (workflow-engine.md); Organization\Api\StepPolicy has one case for each. */
    public const array STEP_POLICIES = ['locked', 'extend', 'team'];

    private readonly string $organizationId;
    // Doctrine's optimistic lock: the column's default 1 on insert, raised by every update.
    private int $version = 1;

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(OrganizationId $organizationId, UserId $firstAdmin, private readonly \DateTimeImmutable $createdAt)
    {
        $this->organizationId = $organizationId->toString();
        $this->data = [
            'ping_limit_hours' => 4,
            'auto_clear' => true,
            'quiet_hours' => false,
            'lead_check' => false,
            'triager_id' => $firstAdmin->toString(),
            'step_policy' => 'extend',
            'ai' => ['on' => true, 'who' => 'everyone', 'send' => 'title_and_text', 'sync' => true, 'where' => 'cloud', 'model' => 'qwen3:4b'],
            'retention' => '2y',
        ];
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function triagerId(): UserId
    {
        $triagerId = $this->data['triager_id'] ?? null;

        return \is_string($triagerId) ? new UserId($triagerId) : throw new \UnexpectedValueException('The settings hold no triager.');
    }

    /**
     * Changes data.step_policy only; every other setting stays as it is.
     *
     * @throws \InvalidArgumentException when $stepPolicy is not one of STEP_POLICIES
     */
    public function changeStepPolicy(string $stepPolicy): void
    {
        if (!\in_array($stepPolicy, self::STEP_POLICIES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a step policy.', $stepPolicy));
        }

        $this->data['step_policy'] = $stepPolicy;
    }

    /**
     * @return array<string, mixed> every setting by its data-model.md key
     */
    public function data(): array
    {
        return $this->data;
    }
}
