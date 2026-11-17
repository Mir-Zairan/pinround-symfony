<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\Intake;

use App\Ticketing\Api\DraftReportChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DraftReportChecks as another module gets it from the container: the word list decides, wherever in the draft the
 * word is.
 */
final class DraftReportChecksTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function sensitiveDrafts(): iterable
    {
        yield 'a security word in the title' => ['Password shown on the cart page', 'The cart total looks off.', ['The total is here']];
        yield 'a security word in the detail' => ['Cart total is wrong', 'Another tenant\'s orders show in my cart.', ['The total is here']];
        yield 'a security word in the second pin note' => ['Cart total is wrong', 'The cart total looks off.', ['The total is here', 'This leaks a token']];
        yield 'a text that is not valid UTF-8' => ['Cart total is wrong', "The cart total looks off \xC3\x28.", []];
    }

    /**
     * @param list<string> $pinNotes
     */
    #[Test]
    #[DataProvider('sensitiveDrafts')]
    public function aDraftWithASecurityWordAnywhereIsSecuritySensitive(string $title, string $detail, array $pinNotes): void
    {
        self::assertTrue($this->draftReportChecks()->isSecuritySensitive($title, $detail, $pinNotes));
    }

    #[Test]
    public function aCleanDraftIsNotSecuritySensitive(): void
    {
        self::assertFalse($this->draftReportChecks()->isSecuritySensitive(
            'Cart total is wrong',
            'The cart total looks off after a coupon.',
            ['The total is here', 'The coupon field'],
        ));
    }

    private function draftReportChecks(): DraftReportChecks
    {
        $draftReportChecks = self::getContainer()->get('test.ticketing_draft_report_checks');
        \assert($draftReportChecks instanceof DraftReportChecks);

        return $draftReportChecks;
    }
}
