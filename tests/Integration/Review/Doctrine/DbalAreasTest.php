<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Review\Domain\AiPinState;
use App\Review\Domain\Area;
use App\Review\Domain\AreaId;
use App\Review\Domain\AreaVerdict;
use App\Review\Domain\PinId;
use App\Review\Domain\PinSource;
use App\Review\Domain\PinToCheck;
use App\Review\Domain\PinVerdict;
use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\Review\Domain\ScreenshotId;
use App\Review\Infrastructure\Doctrine\DbalAreas;
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
 * DbalAreas on the real rvw_round, rvw_pin and rvw_area tables as pinround_app, under row-level security. Acme Foods
 * has Round 0 with two pictures and a review round comparing its first one; Beta Bakery has a round with the same
 * shape. Every time is a fixed one in March 2027; nothing reads the clock. Each write commits, so a read afterwards
 * proves what was stored.
 */
final class DbalAreasTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f7b01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f7b02';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f7b03';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f7b04';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f7b05';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f7b11';
    private const string REVIEW = '0197a3c0-0000-7000-8000-0000000f7b12';
    private const string BETA_ROUND = '0197a3c0-0000-7000-8000-0000000f7b13';
    private const string UNKNOWN_ROUND = '0197a3c0-0000-7000-8000-0000000f7b1f';
    private const string BEFORE = '0197a3c0-0000-7000-8000-0000000f7b21';
    private const string UNCOMPARED = '0197a3c0-0000-7000-8000-0000000f7b22';
    private const string AFTER = '0197a3c0-0000-7000-8000-0000000f7b23';
    private const string BETA_BEFORE = '0197a3c0-0000-7000-8000-0000000f7b24';
    private const string AREA = '0197a3c0-0000-7000-8000-0000000f7b31';
    private const string BETA_AREA = '0197a3c0-0000-7000-8000-0000000f7b32';
    private const string UNKNOWN_AREA = '0197a3c0-0000-7000-8000-0000000f7b3f';
    private const string HUMAN_PIN = '0197a3c0-0000-7000-8000-0000000f7b41';
    private const string KEPT_AI_PIN = '0197a3c0-0000-7000-8000-0000000f7b42';
    private const string UNCOMPARED_PIN = '0197a3c0-0000-7000-8000-0000000f7b43';
    private const string AFTER_PIN = '0197a3c0-0000-7000-8000-0000000f7b44';
    private const string BETA_PIN = '0197a3c0-0000-7000-8000-0000000f7b45';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f7b51';
    // An offset other than UTC, so a time written without its offset would read back an hour off.
    private const string DECIDED_AT = '2027-03-16 10:15:00+01:00';

    /** @var list<EntityManagerInterface> */
    private array $otherSessions = [];

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::round(self::ACME_FOODS, self::REPORT, ['kind' => 'report', 'number' => 0, 'state' => 'closed']),
            self::round(self::ACME_FOODS, self::REVIEW, ['kind' => 'review', 'number' => 2]),
            self::screenshot(self::ACME_FOODS, self::BEFORE, self::REPORT, 0),
            self::screenshot(self::ACME_FOODS, self::UNCOMPARED, self::REPORT, 1),
            self::screenshot(self::ACME_FOODS, self::AFTER, self::REVIEW, 0),
            self::pin(self::ACME_FOODS, self::HUMAN_PIN, self::BEFORE, 1, []),
            self::pin(self::ACME_FOODS, self::KEPT_AI_PIN, self::BEFORE, 2, ['source' => 'ai', 'ai_state' => 'kept', 'verdict' => 'still_wrong']),
            self::pin(self::ACME_FOODS, self::UNCOMPARED_PIN, self::UNCOMPARED, 1, []),
            self::pin(self::ACME_FOODS, self::AFTER_PIN, self::AFTER, 1, []),
            ['rvw_area', self::areaRow(self::ACME_FOODS, self::AREA, self::REVIEW, self::BEFORE, self::AFTER)],
        ]);
        $this->roundRows()->addAllOf(self::BETA_BAKERY, [
            self::round(self::BETA_BAKERY, self::BETA_ROUND, ['kind' => 'report', 'number' => 0]),
            self::screenshot(self::BETA_BAKERY, self::BETA_BEFORE, self::BETA_ROUND, 0),
            self::screenshot(self::BETA_BAKERY, self::AFTER, self::BETA_ROUND, 1),
            self::pin(self::BETA_BAKERY, self::BETA_PIN, self::BETA_BEFORE, 1, []),
            ['rvw_area', self::areaRow(self::BETA_BAKERY, self::BETA_AREA, self::BETA_ROUND, self::BETA_BEFORE, self::AFTER)],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->otherSessions as $otherSession) {
            $otherSession->getConnection()->close();
        }
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aRoundIsReadForItsVerdictsAndAnotherOrganizationsOrAnUnknownOneIsNone(): void
    {
        self::assertEquals(
            [
                new ReviewedRound(new RoundId(self::REVIEW), new TicketId(self::TICKET), RoundKind::Review, true, RoundState::Open, false),
                new ReviewedRound(new RoundId(self::REPORT), new TicketId(self::TICKET), RoundKind::Report, true, RoundState::Closed, false),
                null,
                null,
            ],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
                $this->areas()->lockRound(new RoundId(self::REVIEW)),
                $this->areas()->lockRound(new RoundId(self::REPORT)),
                $this->areas()->lockRound(new RoundId(self::BETA_ROUND)),
                $this->areas()->lockRound(new RoundId(self::UNKNOWN_ROUND)),
            ]),
        );
    }

    #[Test]
    public function anUnsharedDeletedDraftIsReadAsSuch(): void
    {
        $draft = '0197a3c0-0000-7000-8000-0000000f7b14';
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::round(self::ACME_FOODS, $draft, ['kind' => 'draft', 'number' => null, 'shared_at' => null, 'state' => 'closed', 'deleted_at' => '2027-03-16 09:00:00+00']),
        ]);

        self::assertEquals(
            new ReviewedRound(new RoundId($draft), new TicketId(self::TICKET), RoundKind::Draft, false, RoundState::Closed, true),
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?ReviewedRound => $this->areas()->lockRound(new RoundId($draft))),
        );
    }

    /**
     * The round stays locked until the transaction ends: a second session's lock of it waits, here until lock_timeout.
     */
    #[Test]
    public function aLockedRoundMakesASecondLockWait(): void
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->otherSessions[] = $secondSession->entityManager;
        $secondSessionAreas = new DbalAreas($secondSession->entityManager->getConnection(), $this->tenantContext());

        $sqlState = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($secondSession, $secondSessionAreas): string {
            $this->areas()->lockRound(new RoundId(self::REVIEW));

            return $secondSession->sqlStateOfFailedAttempt(static function () use ($secondSessionAreas): void {
                $secondSessionAreas->lockRound(new RoundId(self::REVIEW));
            });
        });

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $sqlState);
    }

    #[Test]
    public function anAreaIsReadInItsOwnRoundOnlyAndOfItsOwnOrganizationOnly(): void
    {
        self::assertEquals(
            [
                Area::restored(new AreaId(self::AREA), new RoundId(self::REVIEW), 'Per product', 0, new ScreenshotId(self::BEFORE), new ScreenshotId(self::AFTER), null, null, null, null),
                null,
                null,
                null,
            ],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
                $this->areas()->areaOf(new RoundId(self::REVIEW), new AreaId(self::AREA)),
                $this->areas()->areaOf(new RoundId(self::REPORT), new AreaId(self::AREA)),
                $this->areas()->areaOf(new RoundId(self::BETA_ROUND), new AreaId(self::BETA_AREA)),
                $this->areas()->areaOf(new RoundId(self::REVIEW), new AreaId(self::UNKNOWN_AREA)),
            ]),
        );
    }

    #[Test]
    public function aSavedVerdictIsReadBackWithItsNoteWhoDecidedAndWhenAndTouchesNoOtherOrganization(): void
    {
        $decided = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Area => $this->areas()->areaOf(new RoundId(self::REVIEW), new AreaId(self::AREA)))
            ?->decide(new UserId(self::MIA), AreaVerdict::NeedsFix, 'Prix affiché en €', new \DateTimeImmutable(self::DECIDED_AT));
        self::assertNotNull($decided);

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->areas()->saveVerdict($decided));

        $readBack = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Area => $this->areas()->areaOf(new RoundId(self::REVIEW), new AreaId(self::AREA)));
        self::assertNotNull($readBack);
        self::assertSame(
            [AreaVerdict::NeedsFix, 'Prix affiché en €', self::MIA, '2027-03-16 09:15:00'],
            [$readBack->verdict(), $readBack->note(), $readBack->decidedBy()?->toString(), $readBack->decidedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
        self::assertSame(
            [['verdict' => null, 'note' => null, 'decided_by' => null]],
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->connection()->fetchAllAssociative('SELECT verdict, note, decided_by FROM rvw_area')),
        );
    }

    #[Test]
    public function aVerdictOnAnAreaThatIsNotStoredIsALogicErrorAndWritesNothing(): void
    {
        $unknownArea = Area::restored(new AreaId(self::UNKNOWN_AREA), new RoundId(self::REVIEW), 'Per product', 1, null, new ScreenshotId(self::UNCOMPARED), null, null, null, null)
            ->decide(new UserId(self::MIA), AreaVerdict::Pass, null, new \DateTimeImmutable(self::DECIDED_AT));
        $areaOfAnotherRound = Area::restored(new AreaId(self::AREA), new RoundId(self::REPORT), 'Per product', 0, new ScreenshotId(self::BEFORE), new ScreenshotId(self::AFTER), null, null, null, null)
            ->decide(new UserId(self::MIA), AreaVerdict::Pass, null, new \DateTimeImmutable(self::DECIDED_AT));

        foreach ([[self::ACME_FOODS, $unknownArea], [self::ACME_FOODS, $areaOfAnotherRound], [self::BETA_BAKERY, $this->acmeArea()->decide(new UserId(self::MIA), AreaVerdict::Pass, null, new \DateTimeImmutable(self::DECIDED_AT))]] as [$organizationId, $area]) {
            $refusal = $this->transactions()->committedAs($organizationId, function () use ($area): ?\LogicException {
                try {
                    $this->areas()->saveVerdict($area);
                } catch (\LogicException $refused) {
                    return $refused;
                }

                return null;
            });
            self::assertSame(\sprintf('Area %s is not stored; a verdict is saved on an area read before.', $area->id()->toString()), $refusal?->getMessage());
        }

        self::assertSame([['verdict' => null]], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative('SELECT verdict FROM rvw_area')));
    }

    /**
     * Only the pins on an area's "before" picture are checked in its round; one on a picture no area compares, on the
     * round's own "after" picture or of another organization is none.
     */
    #[Test]
    public function aPinIsCheckedOnlyWhenAnAreaOfTheRoundComparesItsPicture(): void
    {
        self::assertEquals(
            [
                new PinToCheck(new PinId(self::HUMAN_PIN), PinSource::Human, null, null),
                new PinToCheck(new PinId(self::KEPT_AI_PIN), PinSource::Ai, AiPinState::Kept, PinVerdict::StillWrong),
                null,
                null,
                null,
                null,
            ],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
                $this->areas()->pinToCheckOf(new RoundId(self::REVIEW), new PinId(self::HUMAN_PIN)),
                $this->areas()->pinToCheckOf(new RoundId(self::REVIEW), new PinId(self::KEPT_AI_PIN)),
                $this->areas()->pinToCheckOf(new RoundId(self::REVIEW), new PinId(self::UNCOMPARED_PIN)),
                $this->areas()->pinToCheckOf(new RoundId(self::REVIEW), new PinId(self::AFTER_PIN)),
                $this->areas()->pinToCheckOf(new RoundId(self::REPORT), new PinId(self::HUMAN_PIN)),
                $this->areas()->pinToCheckOf(new RoundId(self::BETA_ROUND), new PinId(self::BETA_PIN)),
            ]),
        );
    }

    #[Test]
    public function twoAreasComparingOnePictureFindThePinOnce(): void
    {
        $secondAfter = '0197a3c0-0000-7000-8000-0000000f7b25';
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::screenshot(self::ACME_FOODS, $secondAfter, self::REVIEW, 1),
            ['rvw_area', [...self::areaRow(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000f7b33', self::REVIEW, self::BEFORE, $secondAfter), 'position' => 1]],
        ]);

        self::assertEquals(
            new PinToCheck(new PinId(self::HUMAN_PIN), PinSource::Human, null, null),
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?PinToCheck => $this->areas()->pinToCheckOf(new RoundId(self::REVIEW), new PinId(self::HUMAN_PIN))),
        );
    }

    #[Test]
    public function aPinVerdictIsWrittenOverTheVerdictReadAndUndoneTheSameWay(): void
    {
        $read = new PinToCheck(new PinId(self::HUMAN_PIN), PinSource::Human, null, null);
        $fixed = $read->judge(PinVerdict::Fixed);

        self::assertTrue($this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->areas()->savePinVerdict($read, $fixed)));
        self::assertSame(['fixed', 'still_wrong', null, null], $this->pinVerdicts());

        self::assertTrue($this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->areas()->savePinVerdict($fixed, $fixed->undo())));
        self::assertSame([null, 'still_wrong', null, null], $this->pinVerdicts());
    }

    /**
     * Another request wrote the verdict after this one read it: the write finds the verdict moved and leaves it.
     */
    #[Test]
    public function aPinVerdictReadBeforeAnotherWriteIsNotSaved(): void
    {
        $readWithNone = new PinToCheck(new PinId(self::KEPT_AI_PIN), PinSource::Ai, AiPinState::Kept, null);
        $readAsFixed = new PinToCheck(new PinId(self::KEPT_AI_PIN), PinSource::Ai, AiPinState::Kept, PinVerdict::Fixed);

        self::assertSame(
            [false, false],
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
                $this->areas()->savePinVerdict($readWithNone, $readWithNone->judge(PinVerdict::Fixed)),
                $this->areas()->savePinVerdict($readAsFixed, $readAsFixed->undo()),
            ]),
        );
        self::assertSame([null, 'still_wrong', null, null], $this->pinVerdicts());
    }

    #[Test]
    public function anotherOrganizationsPinIsNotWritten(): void
    {
        $read = new PinToCheck(new PinId(self::HUMAN_PIN), PinSource::Human, null, null);

        self::assertFalse($this->transactions()->committedAs(self::BETA_BAKERY, fn (): bool => $this->areas()->savePinVerdict($read, $read->judge(PinVerdict::Fixed))));
        self::assertSame([null, 'still_wrong', null, null], $this->pinVerdicts());
    }

    #[Test]
    public function aPinVerdictIsSavedOverTheSamePinOnly(): void
    {
        $read = new PinToCheck(new PinId(self::HUMAN_PIN), PinSource::Human, null, null);
        $otherPin = new PinToCheck(new PinId(self::UNCOMPARED_PIN), PinSource::Human, null, PinVerdict::Fixed);

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($read, $otherPin): ?\LogicException {
            try {
                $this->areas()->savePinVerdict($read, $otherPin);
            } catch (\LogicException $refused) {
                return $refused;
            }

            return null;
        });

        self::assertSame('A pin verdict is saved over the same pin it was read from.', $refusal?->getMessage());
        self::assertSame([null, 'still_wrong', null, null], $this->pinVerdicts());
    }

    #[Test]
    public function nothingIsReadOrWrittenOutsideATransaction(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $read = new PinToCheck(new PinId(self::HUMAN_PIN), PinSource::Human, null, null);
            foreach ([
                fn () => $this->areas()->lockRound(new RoundId(self::REVIEW)),
                fn () => $this->areas()->areaOf(new RoundId(self::REVIEW), new AreaId(self::AREA)),
                fn () => $this->areas()->saveVerdict($this->acmeArea()->decide(new UserId(self::MIA), AreaVerdict::Pass, null, new \DateTimeImmutable(self::DECIDED_AT))),
                fn () => $this->areas()->pinToCheckOf(new RoundId(self::REVIEW), new PinId(self::HUMAN_PIN)),
                fn () => $this->areas()->savePinVerdict($read, $read->judge(PinVerdict::Fixed)),
            ] as $call) {
                try {
                    $call();
                    self::fail('Areas ran outside a transaction.');
                } catch (\LogicException $refusal) {
                    self::assertSame('Areas and verdicts are read and saved in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
                }
            }
        } finally {
            $this->tenantContext()->clear();
        }

        self::assertSame([null, 'still_wrong', null, null], $this->pinVerdicts());
        self::assertSame([['verdict' => null]], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative('SELECT verdict FROM rvw_area')));
    }

    private function acmeArea(): Area
    {
        return Area::restored(new AreaId(self::AREA), new RoundId(self::REVIEW), 'Per product', 0, new ScreenshotId(self::BEFORE), new ScreenshotId(self::AFTER), null, null, null, null);
    }

    /**
     * @return list<mixed> the verdicts of Acme Foods' human, kept AI, uncompared and "after" pins, in that order
     */
    private function pinVerdicts(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT verdict FROM rvw_pin WHERE id IN (?, ?, ?, ?) ORDER BY id',
            [self::HUMAN_PIN, self::KEPT_AI_PIN, self::UNCOMPARED_PIN, self::AFTER_PIN],
        ));
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array{string, array<string, mixed>}
     */
    private static function round(string $organizationId, string $roundId, array $change): array
    {
        return ['rvw_round', [
            'organization_id' => $organizationId,
            'id' => $roundId,
            'ticket_id' => self::TICKET,
            'owner_id' => self::LENA,
            'shared_at' => '2027-03-15 10:00:00+00',
            'state' => 'open',
            'version' => 1,
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function screenshot(string $organizationId, string $screenshotId, string $roundId, int $position): array
    {
        return ['rvw_screenshot', [
            'organization_id' => $organizationId,
            'id' => $screenshotId,
            'round_id' => $roundId,
            'media_id' => self::MEDIA,
            'position' => $position,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array{string, array<string, mixed>}
     */
    private static function pin(string $organizationId, string $pinId, string $screenshotId, int $number, array $change): array
    {
        return ['rvw_pin', [
            'organization_id' => $organizationId,
            'id' => $pinId,
            'screenshot_id' => $screenshotId,
            'number' => $number,
            'x_pct' => '40.00',
            'y_pct' => '60.00',
            'note' => 'Looks off',
            'source' => 'human',
            'author_id' => self::LENA,
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function areaRow(string $organizationId, string $areaId, string $roundId, string $beforeScreenshotId, string $afterScreenshotId): array
    {
        return [
            'organization_id' => $organizationId,
            'id' => $areaId,
            'round_id' => $roundId,
            'name' => 'Per product',
            'position' => 0,
            'before_screenshot_id' => $beforeScreenshotId,
            'after_screenshot_id' => $afterScreenshotId,
            'created_at' => '2027-03-16 08:00:00+00',
        ];
    }

    private function areas(): DbalAreas
    {
        return new DbalAreas($this->connection(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
