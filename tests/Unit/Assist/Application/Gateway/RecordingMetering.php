<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\Assist\Application\AiCallUsage;
use App\Assist\Application\Metering;
use App\Assist\Domain\AiFeature;
use App\Organization\Api\ActorContext;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Keeps each usage row instead of writing it, and refuses a caller of another organization than the tenant, as
 * DbalMetering does.
 */
final class RecordingMetering implements Metering
{
    /** @var list<array{usage: AiCallUsage, feature: AiFeature, caller: ActorContext}> */
    public array $rows = [];

    public function __construct(private readonly OrganizationId $tenant)
    {
    }

    public function record(AiCallUsage $usage, AiFeature $feature, ActorContext $caller): void
    {
        if (!$caller->organizationId()->equals($this->tenant)) {
            throw new \LogicException('An AI call is metered in the organization of the transaction\'s tenant; the caller acted in another one.');
        }
        $this->rows[] = ['usage' => $usage, 'feature' => $feature, 'caller' => $caller];
    }
}
