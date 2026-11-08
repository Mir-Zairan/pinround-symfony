<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\OtphpOneTimeCodes;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class OtphpOneTimeCodesTest extends TestCase
{
    // RFC 6238 appendix B's SHA-1 seed, "12345678901234567890", in base32.
    private const string RFC_SEED = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    // 2009-02-13 23:31:30 UTC, step 41152263: RFC 6238 gives 89005924, so 6 digits are 005924.
    private const int RFC_TIME = 1234567890;
    private const int RFC_STEP = 41152263;

    #[Test]
    public function theCodeOfTheRfcTestVectorMatchesItsStep(): void
    {
        self::assertSame(self::RFC_STEP, $this->codes()->matchingStep(self::RFC_SEED, '005924', self::at(self::RFC_TIME)));
    }

    #[Test]
    public function theCodesOfTheCurrentStepAndOneStepEitherSideMatchAndTwoStepsAwayDoNot(): void
    {
        $codes = $this->codes();
        $seed = self::RFC_SEED;
        // Mid-step, so a step either side is exactly 30 seconds away.
        $now = self::at(1_800_000_015);
        $step = 60_000_000;

        foreach ([-1, 0, 1] as $offset) {
            $code = self::codeAt($seed, $step + $offset);
            self::assertSame($step + $offset, $codes->matchingStep($seed, $code, $now), 'offset '.$offset);
        }
        foreach ([-2, 2] as $offset) {
            $code = self::codeAt($seed, $step + $offset);
            self::assertNotContains($code, [self::codeAt($seed, $step - 1), self::codeAt($seed, $step), self::codeAt($seed, $step + 1)], 'the fixed seed repeats a code');
            self::assertNull($codes->matchingStep($seed, $code, $now), 'offset '.$offset);
        }
    }

    #[Test]
    public function aCodeOfAnotherSeedOrNotSixDigitsDoesNotMatch(): void
    {
        $codes = $this->codes();
        $now = self::at(self::RFC_TIME);

        self::assertNull($codes->matchingStep('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', '005924', $now));
        self::assertNull($codes->matchingStep(self::RFC_SEED, '05924', $now));
        self::assertNull($codes->matchingStep(self::RFC_SEED, '', $now));
    }

    #[Test]
    public function aNewSeedIsThirtyTwoBase32CharactersAndDiffersEachTime(): void
    {
        $codes = $this->codes();

        $seed = $codes->newSeed();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $seed);
        self::assertNotSame($seed, $codes->newSeed());
    }

    #[Test]
    public function theProvisioningUriNamesPinroundAndTheEmailAndCarriesTheSeed(): void
    {
        $uri = $this->codes()->provisioningUri(self::RFC_SEED, 'lena@acme-foods.example');

        self::assertSame('otpauth', parse_url($uri, \PHP_URL_SCHEME));
        self::assertSame('totp', parse_url($uri, \PHP_URL_HOST));
        self::assertSame('/pinround:lena@acme-foods.example', rawurldecode((string) parse_url($uri, \PHP_URL_PATH)), 'label');
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
        self::assertSame(self::RFC_SEED, $query['secret'] ?? null);
        self::assertSame('pinround', $query['issuer'] ?? null);
        // The defaults every app assumes; a period, digits or algorithm other than these would have to be named.
        self::assertArrayNotHasKey('period', $query);
    }

    #[Test]
    public function inTheFirstStepAfterTheEpochTheStepBeforeIsNotAskedFor(): void
    {
        self::assertSame(0, $this->codes()->matchingStep(self::RFC_SEED, self::codeAt(self::RFC_SEED, 0), self::at(10)));
    }

    #[Test]
    public function anEmptySeedOrAccountNameIsRefused(): void
    {
        foreach ([
            'empty seed for a code' => fn (): mixed => $this->codes()->matchingStep('', '123456', self::at(self::RFC_TIME)),
            'empty seed for a link' => fn (): mixed => $this->codes()->provisioningUri('', 'lena@acme-foods.example'),
            'empty account name' => fn (): mixed => $this->codes()->provisioningUri(self::RFC_SEED, ''),
        ] as $case => $call) {
            try {
                $call();
                self::fail('Accepted: '.$case);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function codes(): OtphpOneTimeCodes
    {
        // A clock far from the times the tests ask about, so a code computed from the clock instead fails.
        return new OtphpOneTimeCodes(new MockClock('2001-01-01 00:00:00 UTC'));
    }

    private static function codeAt(string $seed, int $step): string
    {
        \assert('' !== $seed && $step >= 0);

        return TOTP::createFromSecret($seed, new MockClock())->at($step * 30);
    }

    private static function at(int $unixSeconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$unixSeconds);
    }
}
