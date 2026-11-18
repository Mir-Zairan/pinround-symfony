<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Api;

use App\Assist\Api\PolicyView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyViewTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function halfRefusals(): iterable
    {
        yield 'a code without its sentence' => ['ai_off', null];
        yield 'a sentence without its code' => [null, 'Your admin turned AI help off.'];
    }

    #[Test]
    #[DataProvider('halfRefusals')]
    public function aRefusalNeedsBothItsCodeAndItsSentence(?string $code, ?string $sentence): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A refusal needs both its code and its sentence.');

        new PolicyView($code, $sentence, PolicyView::WHERE_CLOUD, false, false, false, null);
    }

    #[Test]
    public function anUnknownPlaceWhereTheAiRunsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A policy view needs a known place where the AI runs.');

        new PolicyView(null, null, 'moon', false, false, false, null);
    }

    #[Test]
    public function aWholeRefusalOrNoneAndEachKnownPlaceAreKeptAsGiven(): void
    {
        $refused = new PolicyView('leads_only', 'Your admin allows AI help for leads only.', PolicyView::WHERE_LAPTOP, true, false, true, null);
        self::assertSame(
            ['leads_only', 'Your admin allows AI help for leads only.', 'laptop', true, false, true, false, false],
            [$refused->refusalCode, $refused->refusalSentence, $refused->whereAiRuns, $refused->isTitleOnly, $refused->isSummaryOnSyncOn, $refused->isRestrictedToCloudAllowed, $refused->isAllowed(), $refused->isLaptopAllowed()],
        );

        $placesKept = [];
        foreach ([PolicyView::WHERE_CLOUD, PolicyView::WHERE_LAPTOP, PolicyView::WHERE_LAPTOP_FIRST] as $whereAiRuns) {
            $allowed = new PolicyView(null, null, $whereAiRuns, false, true, false, null);
            $placesKept[] = [$allowed->whereAiRuns, $allowed->isAllowed(), $allowed->isLaptopAllowed()];
        }
        self::assertSame([['cloud', true, false], ['laptop', true, true], ['laptop_first', true, true]], $placesKept);
    }
}
