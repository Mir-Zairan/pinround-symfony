<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Infrastructure\Doctrine\DbalRounds;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRounds::openSharedRoundsOf on the real tables as pinround_app, under row-level security: which rounds it reads
 * (ADR-0048) and the two locks that keep two shares on one ticket from both passing its check. Each test commits its
 * rows and removes them through the migrator. Every time is a fixed one in April 2027.
 */
final class DbalRoundsOpenSharedRoundsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000fd401';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000fd402';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000fd403';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000fd404';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000fd405';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000fd411';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000fd412';
    /** No shared round yet: Lena and Omar each hold an unshared draft with a picture, ready to share. */
    private const string QUIET_TICKET = '0197a3c0-0000-7000-8000-0000000fd413';

    private const string REPORT = '0197a3c0-0000-7000-8000-0000000fd421';
    private const string CHECKLIST = '0197a3c0-0000-7000-8000-0000000fd422';
    private const string CLOSED_REVIEW = '0197a3c0-0000-7000-8000-0000000fd423';
    private const string OPEN_REVIEW = '0197a3c0-0000-7000-8000-0000000fd424';
    private const string CLOSED_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000fd425';
    private const string OPEN_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000fd426';
    private const string UNSHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000fd427';
    private const string OTHER_TICKET_OPEN_REVIEW = '0197a3c0-0000-7000-8000-0000000fd428';
    private const string OTHER_ORGANIZATION_OPEN_REVIEW = '0197a3c0-0000-7000-8000-0000000fd429';
    private const string LENAS_QUIET_DRAFT = '0197a3c0-0000-7000-8000-0000000fd42a';
    private const string OMARS_QUIET_DRAFT = '0197a3c0-0000-7000-8000-0000000fd42b';

    private const string REPORT_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000fd431';
    private const string CLOSED_SHARED_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000fd432';
    private const string OPEN_SHARED_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000fd433';
    private const string UNSHARED_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000fd434';
    private const string LENAS_QUIET_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000fd435';
    private const string OMARS_QUIET_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000fd436';

    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000fd441';

    private const string STARTED_AT = '2027-04-06 09:00:00+00:00';
    private const string SHARED_AT = '2027-04-07 10:00:00+00:00';
    private const string SAVED_AT = '2027-04-07 10:05:00+00:00';

    private ?EntityManagerInterface $otherSession = null;

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->seedRounds();
    }

    protected function tearDown(): void
    {
        $this->otherSession?->getConnection()->close();
        $this->otherSession = null;
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    /**
     * The open shared draft (number 5) is stored before the open review round (number 3), so a read that kept the
     * stored order would come back the wrong way round.
     */
    #[Test]
    public function theOpenReviewRoundAndOpenSharedDraftAreReadByNumberAndNoOtherRoundIs(): void
    {
        $found = $this->committed(self::ACME_FOODS, fn (): array => $this->rounds()->openSharedRoundsOf(new TicketId(self::TICKET)));

        self::assertNotSame([], $found);
        foreach ($found as $round) {
            self::assertTrue($round->isOpenSharedRound(), $round->id()->toString());
            self::assertSame(self::TICKET, $round->ticketId()->toString());
            self::assertSame(self::ACME_FOODS, $round->organizationId()->toString());
        }
        self::assertSame(
            [[self::OPEN_REVIEW, 3, RoundKind::Review], [self::OPEN_SHARED_DRAFT, 5, RoundKind::Draft]],
            array_map(static fn (Round $round): array => [$round->id()->toString(), $round->number(), $round->kind()], $found),
        );
        self::assertSame([self::OPEN_SHARED_SCREENSHOT], array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $found[1]->screenshots()));
    }

    #[Test]
    public function anotherOrganizationReadsOnlyItsOwnRoundOnTheSameTicketId(): void
    {
        $found = $this->committed(self::OTHER_ORGANIZATION, fn (): array => $this->rounds()->openSharedRoundsOf(new TicketId(self::TICKET)));

        self::assertSame([self::OTHER_ORGANIZATION_OPEN_REVIEW], array_map(static fn (Round $round): string => $round->id()->toString(), $found));
    }

    #[Test]
    public function aTicketWithOnlyUnsharedDraftsHasNoOpenSharedRound(): void
    {
        self::assertSame([], $this->committed(self::ACME_FOODS, fn (): array => $this->rounds()->openSharedRoundsOf(new TicketId(self::QUIET_TICKET))));
        self::assertSame(
            [self::OTHER_TICKET_OPEN_REVIEW],
            array_map(static fn (Round $round): string => $round->id()->toString(), $this->committed(self::ACME_FOODS, fn (): array => $this->rounds()->openSharedRoundsOf(new TicketId(self::OTHER_TICKET)))),
        );
    }

    #[Test]
    public function readingOpenSharedRoundsRefusesWithoutAnOpenTransaction(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->rounds()->openSharedRoundsOf(new TicketId(self::TICKET));
            self::fail('openSharedRoundsOf ran without a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Rounds are read and saved in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }
    }

    /**
     * Lena and Omar share their drafts on a ticket with no open shared round at once. Lena's request checks, shares and
     * saves, and holds its transaction; Omar's check waits at the ticket's number lock (lock_timeout ends the wait
     * here, as PHP cannot wait in two places). Lena's draft is no open shared round in Omar's snapshot, so only the
     * number lock, not a row lock, can make him wait. Once Lena commits, Omar's check finds her round and he does not
     * share: exactly one share lands.
     */
    #[Test]
    public function aSecondCheckWaitsForTheFirstShareAndThenSeesItsRound(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $tenantContext);
        $this->otherSession = $secondSession->entityManager;
        $otherRounds = new DbalRounds($secondSession->entityManager->getConnection(), $tenantContext);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));

        $this->connection()->beginTransaction();
        try {
            $openBeforeLenaShares = $this->rounds()->openSharedRoundsOf(new TicketId(self::QUIET_TICKET));
            $lenasDraft = $this->rounds()->get(new RoundId(self::LENAS_QUIET_DRAFT)) ?? throw new \LogicException('Lena\'s draft is missing.');
            $lenasDraft->share(new UserId(self::LENA), $this->rounds()->nextFreeNumberOf($lenasDraft->ticketId()), new \DateTimeImmutable(self::SHARED_AT));
            $this->rounds()->save($lenasDraft, new \DateTimeImmutable(self::SAVED_AT));

            $omarWaited = $secondSession->sqlStateOfFailedAttempt(static function () use ($otherRounds): void {
                $otherRounds->openSharedRoundsOf(new TicketId(self::QUIET_TICKET));
            });
            $this->connection()->commit();
        } finally {
            // Releases the locks if anything above threw, so tearDown's DELETE does not wait on them.
            if ($this->connection()->isTransactionActive()) {
                $this->connection()->rollBack();
            }
        }

        $secondConnection = $secondSession->entityManager->getConnection();
        $secondConnection->beginTransaction();
        try {
            $openWhenOmarShares = $otherRounds->openSharedRoundsOf(new TicketId(self::QUIET_TICKET));
            $omarShared = false;
            if ([] === $openWhenOmarShares) {
                $omarsDraft = $otherRounds->get(new RoundId(self::OMARS_QUIET_DRAFT)) ?? throw new \LogicException('Omar\'s draft is missing.');
                $omarsDraft->share(new UserId(self::OMAR), $otherRounds->nextFreeNumberOf($omarsDraft->ticketId()), new \DateTimeImmutable(self::SHARED_AT));
                $otherRounds->save($omarsDraft, new \DateTimeImmutable(self::SAVED_AT));
                $omarShared = true;
            }
            $secondConnection->commit();
        } finally {
            if ($secondConnection->isTransactionActive()) {
                $secondConnection->rollBack();
            }
        }
        $tenantContext->clear();

        self::assertSame([], $openBeforeLenaShares);
        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $omarWaited, 'The second check did not wait for the first share\'s number lock.');
        self::assertSame([self::LENAS_QUIET_DRAFT], array_map(static fn (Round $round): string => $round->id()->toString(), $openWhenOmarShares));
        self::assertFalse($omarShared, 'Both shares passed the check.');
        self::assertSame(
            [[self::LENAS_QUIET_DRAFT, 1], [self::OMARS_QUIET_DRAFT, null]],
            $this->committed(self::ACME_FOODS, fn (): array => array_map(
                static fn (array $row): array => [$row['id'], $row['number']],
                $this->connection()->fetchAllAssociative('SELECT id, number FROM rvw_round WHERE ticket_id = ? ORDER BY id', [self::QUIET_TICKET]),
            )),
        );
    }

    /**
     * A verdict locks the round row and takes no number lock. The check must still wait for it, or a share could pass
     * while the round it would have to see is about to change; only the row lock (FOR UPDATE) makes it wait.
     */
    #[Test]
    public function theCheckWaitsForARowLockOnAnOpenRoundWithoutTheNumberLock(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $tenantContext);
        $this->otherSession = $secondSession->entityManager;
        $otherRounds = new DbalRounds($secondSession->entityManager->getConnection(), $tenantContext);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));

        $this->connection()->beginTransaction();
        try {
            $lockedRows = $this->connection()->executeStatement('UPDATE rvw_round SET version = version WHERE organization_id = ? AND id = ?', [self::ACME_FOODS, self::OPEN_REVIEW]);
            $checkWaited = $secondSession->sqlStateOfFailedAttempt(static function () use ($otherRounds): void {
                $otherRounds->openSharedRoundsOf(new TicketId(self::TICKET));
            });
        } finally {
            $this->connection()->rollBack();
            $tenantContext->clear();
        }

        self::assertSame(1, $lockedRows);
        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $checkWaited, 'The check read the open rounds without locking them.');
    }

    private function seedRounds(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $ticket = new TicketId(self::TICKET);
        $lena = new UserId(self::LENA);
        $sharedAt = new \DateTimeImmutable(self::SHARED_AT);

        // Out of number order: the open shared draft 5 before the open review round 3.
        $openSharedDraft = $this->draftWithScreenshot(self::OPEN_SHARED_DRAFT, self::ACME_FOODS, self::TICKET, self::MIA, self::OPEN_SHARED_SCREENSHOT);
        $openSharedDraft->share(new UserId(self::MIA), 5, $sharedAt);
        $this->addCommitted($openSharedDraft);
        $this->addCommitted(self::reviewRound(self::OPEN_REVIEW, self::ACME_FOODS, self::TICKET, 3, RoundState::Open));

        $this->addCommitted(Round::report(new RoundId(self::REPORT), $acmeFoods, $ticket, $lena, [
            Screenshot::of(new ScreenshotId(self::REPORT_SCREENSHOT), self::MEDIA, 0, null, null, []),
        ], new \DateTimeImmutable(self::STARTED_AT)));
        $this->addCommitted(Round::restored(new RoundId(self::CHECKLIST), $acmeFoods, $ticket, RoundKind::Checklist, $lena, 1, $sharedAt, RoundState::Open, 1, [], new \DateTimeImmutable(self::STARTED_AT), null, null, null));
        $this->addCommitted(self::reviewRound(self::CLOSED_REVIEW, self::ACME_FOODS, self::TICKET, 2, RoundState::Closed));
        $closedSharedDraft = $this->draftWithScreenshot(self::CLOSED_SHARED_DRAFT, self::ACME_FOODS, self::TICKET, self::OMAR, self::CLOSED_SHARED_SCREENSHOT);
        $closedSharedDraft->share(new UserId(self::OMAR), 4, $sharedAt);
        $closedSharedDraft->close();
        $this->addCommitted($closedSharedDraft);
        $this->addCommitted($this->draftWithScreenshot(self::UNSHARED_DRAFT, self::ACME_FOODS, self::TICKET, self::LENA, self::UNSHARED_SCREENSHOT));

        $this->addCommitted(self::reviewRound(self::OTHER_TICKET_OPEN_REVIEW, self::ACME_FOODS, self::OTHER_TICKET, 1, RoundState::Open));
        $this->addCommitted(self::reviewRound(self::OTHER_ORGANIZATION_OPEN_REVIEW, self::OTHER_ORGANIZATION, self::TICKET, 1, RoundState::Open));

        $this->addCommitted($this->draftWithScreenshot(self::LENAS_QUIET_DRAFT, self::ACME_FOODS, self::QUIET_TICKET, self::LENA, self::LENAS_QUIET_SCREENSHOT));
        $this->addCommitted($this->draftWithScreenshot(self::OMARS_QUIET_DRAFT, self::ACME_FOODS, self::QUIET_TICKET, self::OMAR, self::OMARS_QUIET_SCREENSHOT));
    }

    private function draftWithScreenshot(string $roundId, string $organizationId, string $ticketId, string $ownerId, string $screenshotId): Round
    {
        $draft = Round::draft(new RoundId($roundId), new OrganizationId($organizationId), new TicketId($ticketId), new UserId($ownerId), new \DateTimeImmutable(self::STARTED_AT));
        $draft->addScreenshot(new UserId($ownerId), new ScreenshotId($screenshotId), self::MEDIA);

        return $draft;
    }

    private static function reviewRound(string $roundId, string $organizationId, string $ticketId, int $number, RoundState $state): Round
    {
        return Round::restored(
            new RoundId($roundId),
            new OrganizationId($organizationId),
            new TicketId($ticketId),
            RoundKind::Review,
            new UserId(self::OMAR),
            $number,
            new \DateTimeImmutable(self::SHARED_AT),
            $state,
            1,
            [],
            new \DateTimeImmutable(self::STARTED_AT),
            null,
            null,
            null,
        );
    }

    private function addCommitted(Round $round): void
    {
        $this->committed($round->organizationId()->toString(), function () use ($round): void {
            $this->rounds()->add($round);
        });
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function committed(string $organizationId, \Closure $work): mixed
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class))->committedAs($organizationId, $work);
    }

    private function rounds(): DbalRounds
    {
        return new DbalRounds($this->connection(), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
