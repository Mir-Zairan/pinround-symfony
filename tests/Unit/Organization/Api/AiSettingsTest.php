<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Api;

use App\Organization\Api\AiSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiSettingsTest extends TestCase
{
    #[Test]
    public function anUnknownPlaceWhereTheAiRunsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('AI settings need a known place where the AI runs.');

        new AiSettings(true, false, false, false, 'moon', false, null, null);
    }

    #[Test]
    public function eachKnownPlaceAndEverySettingAreKeptAsGiven(): void
    {
        $kept = [];
        foreach (AiSettings::WHERE_VALUES as $whereAiRuns) {
            $settings = new AiSettings(true, true, false, true, $whereAiRuns, true, 'anthropic', 'claude-haiku-4-5');
            $kept[] = [
                $settings->isOn, $settings->isForLeadsOnly, $settings->isTitleOnly, $settings->isSummaryOnSyncOn, $settings->whereAiRuns,
                $settings->isRestrictedToCloudAllowed, $settings->provider, $settings->providerModel,
            ];
        }

        self::assertSame([
            [true, true, false, true, 'cloud', true, 'anthropic', 'claude-haiku-4-5'],
            [true, true, false, true, 'laptop', true, 'anthropic', 'claude-haiku-4-5'],
            [true, true, false, true, 'laptop_first', true, 'anthropic', 'claude-haiku-4-5'],
        ], $kept);
    }

    #[Test]
    public function offHasEverySettingAtItsDefault(): void
    {
        $off = AiSettings::off();

        self::assertSame(
            [false, false, false, false, 'cloud', false, null, null],
            [$off->isOn, $off->isForLeadsOnly, $off->isTitleOnly, $off->isSummaryOnSyncOn, $off->whereAiRuns, $off->isRestrictedToCloudAllowed, $off->provider, $off->providerModel],
        );
    }
}
