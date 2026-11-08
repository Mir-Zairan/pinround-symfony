<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\ShowTwoStepSetup;
use App\Identity\Application\TwoStepLoginState;
use App\Identity\Domain\TwoStepLogin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShowTwoStepSetupTest extends TestCase
{
    use TwoStepSetupFixtures;

    private const string SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXQ';

    #[Test]
    public function aUserWithoutTwoStepLoginSeesItOffWithoutAKey(): void
    {
        $show = $this->show(new InMemoryTwoStepLogins());

        $setup = $show(self::lena()->id());

        self::assertSame(TwoStepLoginState::Off, $setup->state);
        self::assertNull($setup->manualKey);
        self::assertNull($setup->provisioningUri);
        self::assertSame(TwoStepLoginState::Off, $show->stateOf(self::lena()->id()));
    }

    #[Test]
    public function whileItAwaitsACodeTheKeyIsShownInGroupsOfFourAndTheLinkHoldsTheSeedAndEmail(): void
    {
        $twoStepLogins = new InMemoryTwoStepLogins();
        $twoStepLogins->save(new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
        $show = $this->show($twoStepLogins);

        $setup = $show(self::lena()->id());

        self::assertSame(TwoStepLoginState::AwaitingCode, $setup->state);
        self::assertSame('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXQ', $setup->manualKey);
        self::assertNotNull($setup->provisioningUri);
        self::assertStringStartsWith('otpauth://totp/pinround%3Alena%40acme-foods.example?', $setup->provisioningUri);
        self::assertStringContainsString('secret='.self::SEED, $setup->provisioningUri);
        self::assertSame(TwoStepLoginState::AwaitingCode, $show->stateOf(self::lena()->id()));
        self::assertStringNotContainsString(self::SEED, print_r($setup, true));
    }

    #[Test]
    public function onceItIsOnNeitherTheKeyNorTheLinkIsShown(): void
    {
        $twoStepLogins = new InMemoryTwoStepLogins();
        $twoStepLogin = new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $twoStepLogin->confirm(58_000_000, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), ['$argon2id$first']);
        $twoStepLogins->save($twoStepLogin);
        $show = $this->show($twoStepLogins);

        $setup = $show(self::lena()->id());

        self::assertSame(TwoStepLoginState::On, $setup->state);
        self::assertNull($setup->manualKey);
        self::assertNull($setup->provisioningUri);
        self::assertSame(TwoStepLoginState::On, $show->stateOf(self::lena()->id()));
    }

    private function show(InMemoryTwoStepLogins $twoStepLogins): ShowTwoStepSetup
    {
        return new ShowTwoStepSetup($twoStepLogins, new RecordingUsers(self::lena()), self::fieldEncryption(), self::oneTimeCodes());
    }
}
