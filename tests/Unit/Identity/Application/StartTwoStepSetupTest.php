<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\StartTwoStepSetup;
use App\Identity\Domain\TwoStepLogin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StartTwoStepSetupTest extends TestCase
{
    use TwoStepSetupFixtures;

    #[Test]
    public function aUserWithoutTwoStepLoginGetsANewSeedStoredEncryptedAndStillOff(): void
    {
        $user = self::lena();
        $twoStepLogins = new InMemoryTwoStepLogins();

        $this->start($twoStepLogins, new RecordingUsers($user), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        $twoStepLogin = $twoStepLogins->byUserId($user->id());
        self::assertNotNull($twoStepLogin);
        self::assertFalse($twoStepLogin->isOn());
        $seed = self::fieldEncryption()->decrypt($twoStepLogin->encryptedSeed());
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $seed);
        self::assertStringNotContainsString($seed, $twoStepLogin->encryptedSeed());
        self::assertSame('2027-03-15T10:00:00+00:00', $twoStepLogin->createdAt()->format(\DATE_ATOM));
        self::assertSame([$user->id()->toString()], $twoStepLogins->userIdsReadForChange);
    }

    #[Test]
    public function startingAgainBeforeTheConfirmationReplacesTheSeed(): void
    {
        $user = self::lena();
        $twoStepLogins = new InMemoryTwoStepLogins();
        $this->start($twoStepLogins, new RecordingUsers($user), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $firstSeed = $this->seedOf($twoStepLogins->byUserId($user->id()));

        $this->start($twoStepLogins, new RecordingUsers($user), new \DateTimeImmutable('2027-03-15 10:05:00 UTC'));

        $twoStepLogin = $twoStepLogins->byUserId($user->id());
        self::assertNotNull($twoStepLogin);
        self::assertNotSame($firstSeed, $this->seedOf($twoStepLogin));
        self::assertFalse($twoStepLogin->isOn());
        self::assertSame('2027-03-15T10:05:00+00:00', $twoStepLogin->createdAt()->format(\DATE_ATOM));
        self::assertCount(2, $twoStepLogins->saved);
    }

    #[Test]
    public function startingOnceItIsOnChangesNothing(): void
    {
        $user = self::lena();
        $twoStepLogins = new InMemoryTwoStepLogins();
        $twoStepLogin = new TwoStepLogin($user, self::fieldEncryption()->encrypt('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $twoStepLogin->confirm(58_000_000, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), ['$argon2id$first']);
        $twoStepLogins->save($twoStepLogin);
        $encryptedSeedBefore = $twoStepLogin->encryptedSeed();

        $this->start($twoStepLogins, new RecordingUsers($user), new \DateTimeImmutable('2027-03-15 10:05:00 UTC'));

        self::assertSame($encryptedSeedBefore, $twoStepLogins->byUserId($user->id())?->encryptedSeed());
        self::assertCount(1, $twoStepLogins->saved);
        self::assertSame([$user->id()->toString()], $twoStepLogins->userIdsReadForChange);
    }

    #[Test]
    public function anUnknownUserIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        $this->start(new InMemoryTwoStepLogins(), new RecordingUsers(null), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
    }

    private function start(InMemoryTwoStepLogins $twoStepLogins, RecordingUsers $users, \DateTimeImmutable $now): void
    {
        new StartTwoStepSetup($twoStepLogins, $users, self::fieldEncryption(), self::oneTimeCodes())(self::lena()->id(), $now);
    }

    private function seedOf(?TwoStepLogin $twoStepLogin): string
    {
        self::assertNotNull($twoStepLogin);

        return self::fieldEncryption()->decrypt($twoStepLogin->encryptedSeed());
    }
}
