<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\SymfonySessionConfirmations;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SymfonySessionConfirmationsTest extends TestCase
{
    private const string LENA = '0195a1b2-0000-7000-8000-0000000000aa';
    private const string OMAR = '0195a1b2-0000-7000-8000-0000000000bb';

    #[Test]
    public function aNewSessionHasNeitherTime(): void
    {
        [$confirmations] = self::withSession();

        self::assertNull($confirmations->recentAuthAt(new UserId(self::LENA)));
        self::assertNull($confirmations->twoStepConfirmedAt(new UserId(self::LENA)));
    }

    #[Test]
    public function eachTimeIsStoredWithItsUserAsUnixSecondsUnderItsOwnKeyAndReadBack(): void
    {
        [$confirmations, $session] = self::withSession();
        $lena = new UserId(self::LENA);

        $confirmations->recordRecentAuth($lena, new \DateTimeImmutable('2027-03-15 11:01:15.75 Europe/Berlin'));

        self::assertSame(['identity.recent_auth_at' => ['user_id' => self::LENA, 'at' => 1_805_104_875]], $session->all());
        self::assertSame('2027-03-15T10:01:15+00:00', $confirmations->recentAuthAt($lena)?->format(\DATE_ATOM));
        self::assertNull($confirmations->twoStepConfirmedAt($lena));

        $confirmations->recordTwoStepConfirmation($lena, new \DateTimeImmutable('2027-03-15 10:02:00 UTC'));

        self::assertSame([
            'identity.recent_auth_at' => ['user_id' => self::LENA, 'at' => 1_805_104_875],
            'identity.two_step_confirmed_at' => ['user_id' => self::LENA, 'at' => 1_805_104_920],
        ], $session->all());
        self::assertSame('2027-03-15T10:02:00+00:00', $confirmations->twoStepConfirmedAt($lena)?->format(\DATE_ATOM));
        self::assertSame('2027-03-15T10:01:15+00:00', $confirmations->recentAuthAt($lena)?->format(\DATE_ATOM));
    }

    #[Test]
    public function aTimeRecordedForAnotherUserReadsAsNoTime(): void
    {
        [$confirmations] = self::withSession();
        $confirmations->recordRecentAuth(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));
        $confirmations->recordTwoStepConfirmation(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));

        self::assertNull($confirmations->recentAuthAt(new UserId(self::OMAR)));
        self::assertNull($confirmations->twoStepConfirmedAt(new UserId(self::OMAR)));
        self::assertNotNull($confirmations->twoStepConfirmedAt(new UserId(self::LENA)));
    }

    #[Test]
    public function aLaterRecordReplacesTheEarlierTimeAndUser(): void
    {
        [$confirmations] = self::withSession();
        $confirmations->recordTwoStepConfirmation(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:02:00 UTC'));

        $confirmations->recordTwoStepConfirmation(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-15 10:40:00 UTC'));

        self::assertSame('2027-03-15T10:40:00+00:00', $confirmations->twoStepConfirmedAt(new UserId(self::OMAR))?->format(\DATE_ATOM));
        self::assertNull($confirmations->twoStepConfirmedAt(new UserId(self::LENA)));
    }

    #[Test]
    public function forgettingRemovesBothTimesAndNothingElse(): void
    {
        [$confirmations, $session] = self::withSession();
        $session->set('organization.chosen_id', 'kept');
        $confirmations->recordRecentAuth(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));
        $confirmations->recordTwoStepConfirmation(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));

        $confirmations->forget();

        self::assertSame(['organization.chosen_id' => 'kept'], $session->all());
    }

    #[Test]
    public function recordingKeepsTheSessionIdAndItsCreationTime(): void
    {
        [$confirmations, $session] = self::withSession();
        $session->start();
        $idBefore = $session->getId();
        $createdBefore = $session->getMetadataBag()->getCreated();

        $confirmations->recordRecentAuth(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));
        $confirmations->recordTwoStepConfirmation(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));

        self::assertSame($idBefore, $session->getId());
        self::assertSame($createdBefore, $session->getMetadataBag()->getCreated());
    }

    #[Test]
    public function aValueInAnotherShapeReadsAsNoTime(): void
    {
        [$confirmations, $session] = self::withSession();
        $lena = new UserId(self::LENA);
        $session->set('identity.recent_auth_at', 1_805_104_875);
        $session->set('identity.two_step_confirmed_at', ['user_id' => self::LENA, 'at' => '1805104875']);

        self::assertNull($confirmations->recentAuthAt($lena));
        self::assertNull($confirmations->twoStepConfirmedAt($lena));

        $session->set('identity.two_step_confirmed_at', ['at' => 1_805_104_875]);
        self::assertNull($confirmations->twoStepConfirmedAt($lena));
    }

    /**
     * @return iterable<string, array{\Closure(SymfonySessionConfirmations): mixed}>
     */
    public static function methods(): iterable
    {
        yield 'recordRecentAuth' => [static function (SymfonySessionConfirmations $confirmations): void {
            $confirmations->recordRecentAuth(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));
        }];
        yield 'recordTwoStepConfirmation' => [static function (SymfonySessionConfirmations $confirmations): void {
            $confirmations->recordTwoStepConfirmation(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));
        }];
        yield 'recentAuthAt' => [static fn (SymfonySessionConfirmations $confirmations): mixed => $confirmations->recentAuthAt(new UserId(self::LENA))];
        yield 'twoStepConfirmedAt' => [static fn (SymfonySessionConfirmations $confirmations): mixed => $confirmations->twoStepConfirmedAt(new UserId(self::LENA))];
        yield 'forget' => [static function (SymfonySessionConfirmations $confirmations): void {
            $confirmations->forget();
        }];
    }

    #[Test]
    #[DataProvider('methods')]
    public function withoutARequestItRefuses(\Closure $call): void
    {
        $this->expectException(SessionNotFoundException::class);

        $call(new SymfonySessionConfirmations(new RequestStack()));
    }

    /**
     * @return array{SymfonySessionConfirmations, Session}
     */
    private static function withSession(): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack([$request]);

        return [new SymfonySessionConfirmations($requestStack), $session];
    }
}
