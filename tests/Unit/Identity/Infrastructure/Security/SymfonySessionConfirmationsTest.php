<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\SymfonySessionConfirmations;
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
    #[Test]
    public function aNewSessionHasNeitherTime(): void
    {
        [$confirmations] = self::withSession();

        self::assertNull($confirmations->recentAuthAt());
        self::assertNull($confirmations->twoStepConfirmedAt());
    }

    #[Test]
    public function eachTimeIsStoredAsUnixSecondsUnderItsOwnKeyAndReadBack(): void
    {
        [$confirmations, $session] = self::withSession();

        $confirmations->recordRecentAuth(new \DateTimeImmutable('2027-03-15 11:01:15.75 Europe/Berlin'));

        self::assertSame(['identity.recent_auth_at' => 1_805_104_875], $session->all());
        self::assertSame('2027-03-15T10:01:15+00:00', $confirmations->recentAuthAt()?->format(\DATE_ATOM));
        self::assertNull($confirmations->twoStepConfirmedAt());

        $confirmations->recordTwoStepConfirmation(new \DateTimeImmutable('2027-03-15 10:02:00 UTC'));

        self::assertSame(['identity.recent_auth_at' => 1_805_104_875, 'identity.two_step_confirmed_at' => 1_805_104_920], $session->all());
        self::assertSame('2027-03-15T10:02:00+00:00', $confirmations->twoStepConfirmedAt()?->format(\DATE_ATOM));
        self::assertSame('2027-03-15T10:01:15+00:00', $confirmations->recentAuthAt()?->format(\DATE_ATOM));
    }

    #[Test]
    public function aLaterRecordReplacesTheEarlierTime(): void
    {
        [$confirmations] = self::withSession();
        $confirmations->recordTwoStepConfirmation(new \DateTimeImmutable('2027-03-15 10:02:00 UTC'));

        $confirmations->recordTwoStepConfirmation(new \DateTimeImmutable('2027-03-15 10:40:00 UTC'));

        self::assertSame('2027-03-15T10:40:00+00:00', $confirmations->twoStepConfirmedAt()?->format(\DATE_ATOM));
    }

    #[Test]
    public function recordingKeepsTheSessionIdAndItsCreationTime(): void
    {
        [$confirmations, $session] = self::withSession();
        $session->start();
        $idBefore = $session->getId();
        $createdBefore = $session->getMetadataBag()->getCreated();

        $confirmations->recordRecentAuth(new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));
        $confirmations->recordTwoStepConfirmation(new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));

        self::assertSame($idBefore, $session->getId());
        self::assertSame($createdBefore, $session->getMetadataBag()->getCreated());
    }

    #[Test]
    public function aValueThatIsNotUnixSecondsReadsAsNoTime(): void
    {
        [$confirmations, $session] = self::withSession();
        $session->set('identity.recent_auth_at', '1805104875');
        $session->set('identity.two_step_confirmed_at', new \DateTimeImmutable('2027-03-15 10:02:00 UTC'));

        self::assertNull($confirmations->recentAuthAt());
        self::assertNull($confirmations->twoStepConfirmedAt());
    }

    /**
     * @return iterable<string, array{\Closure(SymfonySessionConfirmations): mixed}>
     */
    public static function methods(): iterable
    {
        yield 'recordRecentAuth' => [static function (SymfonySessionConfirmations $confirmations): void {
            $confirmations->recordRecentAuth(new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));
        }];
        yield 'recordTwoStepConfirmation' => [static function (SymfonySessionConfirmations $confirmations): void {
            $confirmations->recordTwoStepConfirmation(new \DateTimeImmutable('2027-03-15 10:01:15 UTC'));
        }];
        yield 'recentAuthAt' => [static fn (SymfonySessionConfirmations $confirmations): mixed => $confirmations->recentAuthAt()];
        yield 'twoStepConfirmedAt' => [static fn (SymfonySessionConfirmations $confirmations): mixed => $confirmations->twoStepConfirmedAt()];
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
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return [new SymfonySessionConfirmations($requestStack), $session];
    }
}
