<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain\Intake;

use App\Ticketing\Domain\Intake\CheckReasons;
use App\Ticketing\Domain\Intake\DraftReport;
use App\Ticketing\Domain\Intake\WordLists;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckReasonsTest extends TestCase
{
    #[Test]
    public function theSecurityListIsPinnedToItsVersion(): void
    {
        self::assertSame(
            [
                'version' => 2,
                'security' => [
                    'secur\p{L}*', 'insecur\p{L}*', 'unsecur\p{L}*', 'cybersecur\p{L}*',
                    'leak\p{L}*',
                    'password\p{L}*',
                    'unauth\p{L}*',
                    'hack', 'hacks', 'hacked', 'hacker', 'hackers', 'hacking',
                    'privacy',
                    'gdpr',
                    'token', 'tokens',
                    'permission\p{L}*',
                    'other\s+tenants?', 'another\s+tenant',
                ],
            ],
            [
                'version' => new \ReflectionClassConstant(WordLists::class, 'VERSION')->getValue(),
                'security' => new \ReflectionClassConstant(WordLists::class, 'SECURITY')->getValue(),
            ],
        );
    }

    /**
     * One title per list entry, matched by that entry alone.
     *
     * @return array<string, array{string}>
     */
    public static function titlePerSecurityEntry(): array
    {
        return [
            'secur\p{L}*' => ['The upload page is not secure'],
            'insecur\p{L}*' => ['Insecure cookie on the checkout'],
            'unsecur\p{L}*' => ['The admin endpoint is unsecured'],
            'cybersecur\p{L}*' => ['Cybersecurity audit found an open port'],
            'leak\p{L}*' => ['Memory leakage on the search page'],
            'password\p{L}*' => ['Passwordless sign-in link expires too soon'],
            'unauth\p{L}*' => ['Unauthorized users see the export button'],
            'hack' => ['Someone tried to hack the shop'],
            'hacks' => ['The theme hacks the menu colours'],
            'hacked' => ['My account was hacked yesterday'],
            'hacker' => ['A hacker posted spam in the comments'],
            'hackers' => ['Hackers flood the contact form'],
            'hacking' => ['Hacking attempts show in the log'],
            'privacy' => ['Privacy page shows the wrong address'],
            'gdpr' => ['GDPR export misses the orders'],
            'token' => ['Reset token is shown in the URL'],
            'tokens' => ['Old tokens still work after sign-out'],
            'permission\p{L}*' => ['Permissions page lists deleted roles'],
            'other\s+tenants?' => ['I can see invoices of other tenants'],
            'another\s+tenant' => ['Orders from another tenant show up'],
        ];
    }

    #[Test]
    public function everySecurityEntryHasATitle(): void
    {
        self::assertSame(WordLists::SECURITY, array_keys(self::titlePerSecurityEntry()));
    }

    #[Test]
    #[DataProvider('titlePerSecurityEntry')]
    public function eachSecurityEntryMakesADraftSensitive(string $title): void
    {
        self::assertTrue(new CheckReasons()->isSecuritySensitive(new DraftReport($title, '', [])));
    }

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
        yield 'not secure' => [new DraftReport('Payment form is not secure', '', [])];
        yield 'insecure' => [new DraftReport('Insecure link in the footer', '', [])];
        yield 'leakage' => [new DraftReport('Data leakage in the export', '', [])];
        yield 'a word in brackets' => [new DraftReport('Field label is wrong (password)', '', [])];
        yield 'a word between multibyte words' => [new DraftReport('Größe des Tokens ändern', '', [])];
        yield 'two words split by CRLF' => [new DraftReport('Wrong data', "I can see data of another\r\ntenant here.", [])];
        yield 'a word before CRLF' => [new DraftReport('Wrong data', "Shows the token\r\nOn every page", [])];
        yield 'a word at the end with no final newline' => [new DraftReport('Wrong data', "Steps:\r\n1. Open the token", [])];
        yield 'a BOM before the word' => [new DraftReport("\u{FEFF}gdpr request", '', [])];
        yield 'a zero-width space inside the word' => [new DraftReport("pass\u{200B}word shown on screen", '', [])];
        yield 'a soft hyphen inside the word' => [new DraftReport("pri\u{00AD}vacy page", '', [])];
        yield 'a combining grapheme joiner inside the word' => [new DraftReport("to\u{034F}ken in the URL", '', [])];
        yield 'a variation selector inside the word' => [new DraftReport("ha\u{FE0F}ck attempt", '', [])];
        yield 'a supplementary variation selector inside the word' => [new DraftReport("gd\u{E0100}pr export", '', [])];
        yield 'a Hangul choseong filler inside the word' => [new DraftReport("hack\u{115F}ed account", '', [])];
        yield 'a Hangul jungseong filler inside the word' => [new DraftReport("to\u{1160}ken in the URL", '', [])];
        yield 'a Hangul filler inside the word' => [new DraftReport("pass\u{3164}word on screen", '', [])];
        yield 'a half-width Hangul filler inside the word' => [new DraftReport("hack\u{FFA0}ed account", '', [])];
        yield 'full-width upper case' => [new DraftReport('ＰＡＳＳＷＯＲＤ shown on screen', '', [])];
        yield 'full-width lower case' => [new DraftReport('ｔｏｋｅｎ in the URL', '', [])];
        yield 'mathematical bold letters' => [new DraftReport("\u{1D420}\u{1D41D}\u{1D429}\u{1D42B} export", '', [])];
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
        yield 'a letter before the word' => [new DraftReport('Untoken list', '', [])];
        yield 'a letter with an accent before the word' => [new DraftReport('Überhack theme', '', [])];
        yield 'a digit after the word' => [new DraftReport('token2 field', '', [])];
        yield 'a digit before the word' => [new DraftReport('2token field', '', [])];
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
