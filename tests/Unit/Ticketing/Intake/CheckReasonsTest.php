<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Intake;

use App\Ticketing\Domain\Intake\CheckReasons;
use App\Ticketing\Domain\Intake\DraftReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckReasonsTest extends TestCase
{
    /**
     * @return iterable<string, array{DraftReport}>
     */
    public static function sensitiveDrafts(): iterable
    {
        yield 'a word in the title' => [new DraftReport('Password reset link never arrives', '', [])];
        yield 'a word in the detail' => [new DraftReport('Login page is slow', 'I think there is a leak of customer emails.', [])];
        yield 'a word in a pin note' => [new DraftReport('Footer overlaps', 'See the picture.', ['Fine', 'This shows the API token in clear text'])];
        yield 'upper case' => [new DraftReport('SECURITY review needed', '', [])];
        yield 'a prefix entry' => [new DraftReport('Unauthenticated users can open the admin page', '', [])];
        yield 'two words split by CRLF' => [new DraftReport('Wrong data', "I can see data of another\r\ntenant here.", [])];
        yield 'a BOM before the word' => [new DraftReport("\u{FEFF}gdpr request", '', [])];
        yield 'a zero-width space inside the word' => [new DraftReport("pass\u{200B}word shown on screen", '', [])];
        yield 'text that is not UTF-8' => [new DraftReport("Checkout \xC3\x28 broken", '', [])];
    }

    #[Test]
    #[DataProvider('sensitiveDrafts')]
    public function aDraftWithASecurityWordIsSensitive(DraftReport $draft): void
    {
        self::assertTrue(new CheckReasons()->isSecuritySensitive($draft));
    }

    /**
     * @return iterable<string, array{DraftReport}>
     */
    public static function plainDrafts(): iterable
    {
        yield 'no word at all' => [new DraftReport('Checkout button is hidden', "Steps:\r\n1. Open the cart", ['The button should be here'])];
        yield 'a German near miss' => [new DraftReport('Passwört Feld ist leer', '', [])];
        yield 'a word inside a longer word' => [new DraftReport('Tokenizer splits names wrongly', 'The hackathon page is down.', [])];
        yield 'a letter with an accent after the word' => [new DraftReport('hackö theme colours', '', [])];
        yield 'tenant without other or another' => [new DraftReport('Tenant name is cut off', '', [])];
        yield 'no pins and an empty detail' => [new DraftReport('Logo is blurry', '', [])];
    }

    #[Test]
    #[DataProvider('plainDrafts')]
    public function aDraftWithoutASecurityWordIsNotSensitive(DraftReport $draft): void
    {
        self::assertFalse(new CheckReasons()->isSecuritySensitive($draft));
    }
}
