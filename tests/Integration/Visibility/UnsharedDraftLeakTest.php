<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * A tester's unshared draft round (ADR-0044, P13.T1) on every read path of Review that can hold it: the Rounds section
 * (RoundQueries::forTicket), the draft editor's reads (draftOf, draftScreenshotOf), the notes Claude Code reads
 * (roundNotes, sharedRoundNotes) and RoundPermission VIEW and EDIT. The cast's team-only SHOP ticket is in Testing with
 * Omar as its assignee and Mia as its tester, whose turn it is; it has Mia's shared Round 0, Mia's unshared draft and
 * Omar's own unshared draft, each with a screenshot and a pin. Mia's draft reaches Mia alone: not Omar, the assignee,
 * not Lena, the lead, not Sara, an admin, and not another organization's admin.
 */
final class UnsharedDraftLeakTest extends VisibilityLeakCast
{
    private const string TESTING_STEP = '0197a3c0-0000-7000-8000-000000005c01';
    private const string REPORT = '0197a3c0-0000-7000-8000-000000005c11';
    private const string MIAS_DRAFT = '0197a3c0-0000-7000-8000-000000005c12';
    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-000000005c13';
    private const string REPORT_SCREENSHOT = '0197a3c0-0000-7000-8000-000000005c21';
    private const string MIAS_SCREENSHOT = '0197a3c0-0000-7000-8000-000000005c22';
    private const string OMARS_SCREENSHOT = '0197a3c0-0000-7000-8000-000000005c23';
    private const string REPORT_PIN = '0197a3c0-0000-7000-8000-000000005c31';
    private const string MIAS_PIN = '0197a3c0-0000-7000-8000-000000005c32';
    private const string OMARS_PIN = '0197a3c0-0000-7000-8000-000000005c33';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000005c41';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $ticketId = self::teamOnlyTicketId();
        $omarId = self::VIEWERS['Omar'][0];
        $miaId = self::VIEWERS['Mia'][0];
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($ticketId, $omarId, $miaId): void {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Testing', 'check', 'test', 'core', 5)",
                [self::ACME_FOODS, self::TESTING_STEP],
            );
            $migrator->executeStatement('UPDATE tkt_ticket SET step_id = ?, assignee_id = ?, tester_id = ? WHERE id = ?', [self::TESTING_STEP, $omarId, $miaId, $ticketId]);
            $migrator->executeStatement(
                "UPDATE ins_ticket_turn SET grp = 'check', kind = 'test', turn_user_id = ?, assignee_id = ?, tester_id = ? WHERE ticket_id = ?",
                [$miaId, $omarId, $miaId, $ticketId],
            );
        });
        $rounds = [
            [self::REPORT, 0, 'report', $miaId, '2027-03-15 10:00:00+00', self::REPORT_SCREENSHOT, self::REPORT_PIN],
            [self::MIAS_DRAFT, null, 'draft', $miaId, null, self::MIAS_SCREENSHOT, self::MIAS_PIN],
            [self::OMARS_DRAFT, null, 'draft', $omarId, null, self::OMARS_SCREENSHOT, self::OMARS_PIN],
        ];
        self::transactions()->committedAs(self::ACME_FOODS, static function () use ($rounds, $ticketId): void {
            foreach ($rounds as [$roundId, $number, $kind, $ownerId, $sharedAt, $screenshotId, $pinId]) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'open', 1, '2027-03-15 10:00:00+00')",
                    [self::ACME_FOODS, $roundId, $ticketId, $number, $kind, $ownerId, $sharedAt],
                );
                self::connection()->executeStatement(
                    "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, created_at) VALUES (?, ?, ?, ?, 0, '2027-03-15 10:00:00+00')",
                    [self::ACME_FOODS, $screenshotId, $roundId, self::MEDIA],
                );
                self::connection()->executeStatement(
                    "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, source, author_id, created_at) VALUES (?, ?, ?, 1, 40, 60, ?, 'human', ?, '2027-03-15 10:00:00+00')",
                    [self::ACME_FOODS, $pinId, $screenshotId, self::LEAK_MARKER, $ownerId],
                );
            }
        });
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDownAfterClass();
    }

    /**
     * Each viewer, whether they may see the ticket, and the own draft they have on it, if any. Omar sees the team-only
     * ticket here as its assignee, though he is in no team.
     *
     * @return iterable<string, array{string, bool, string|null}>
     */
    public static function viewersOfMiasDraft(): iterable
    {
        yield 'Mia, the tester whose draft it is' => ['Mia', true, self::MIAS_DRAFT];
        yield 'Omar, the assignee, with a draft of his own' => ['Omar', true, self::OMARS_DRAFT];
        yield 'Lena, the lead of the ticket\'s team' => ['Lena', true, null];
        yield 'Sara, an admin with no team role' => ['Sara', true, null];
        yield 'Raj, a manager with no team role' => ['Raj', false, null];
        yield 'Noor, an outsider with no link' => ['Noor', false, null];
        yield 'Ben, an admin of another organization' => ['Ben', false, null];
    }

    #[Test]
    #[DataProvider('viewersOfMiasDraft')]
    public function theRoundsSectionShowsTheSharedRoundToWhoMaySeeTheTicketAndADraftToItsOwnerAlone(string $viewer, bool $seesTicket, ?string $ownDraft): void
    {
        $expected = [];
        if ($seesTicket) {
            $expected[] = [self::REPORT, [[self::REPORT_SCREENSHOT, [self::REPORT_PIN]]]];
        }
        if (self::MIAS_DRAFT === $ownDraft) {
            $expected[] = [self::MIAS_DRAFT, [[self::MIAS_SCREENSHOT, [self::MIAS_PIN]]]];
        }
        if (self::OMARS_DRAFT === $ownDraft) {
            $expected[] = [self::OMARS_DRAFT, [[self::OMARS_SCREENSHOT, [self::OMARS_PIN]]]];
        }

        self::assertSame($expected, self::roundsAs($viewer), \sprintf('%s gets the wrong rounds.', $viewer));
    }

    #[Test]
    #[DataProvider('viewersOfMiasDraft')]
    public function theDraftEditorAndTheNotesReadMiasDraftForHerAlone(string $viewer, bool $seesTicket, ?string $ownDraft): void
    {
        $isMia = 'Mia' === $viewer;
        [$draft, $notes] = self::asViewer($viewer, static function () use ($viewer): array {
            $viewerId = new UserId(self::VIEWERS[$viewer][0]);

            return [
                self::roundQueries()->draftOf(self::MIAS_DRAFT, $viewerId)?->id,
                self::roundQueries()->roundNotes(new TicketId(self::teamOnlyTicketId()), $viewerId)?->roundId,
            ];
        });

        self::assertSame($isMia ? self::MIAS_DRAFT : null, $draft, \sprintf('%s reads Mia\'s draft through the editor.', $viewer));
        self::assertSame($ownDraft, $notes, \sprintf('%s gets notes of a draft not their own.', $viewer));
    }

    #[Test]
    #[DataProvider('viewersOfMiasDraft')]
    public function onlyMiaMayViewOrEditHerDraftWhileTheSharedRoundIsForWhoMaySeeTheTicket(string $viewer, bool $seesTicket, ?string $ownDraft): void
    {
        $isMia = 'Mia' === $viewer;
        $decided = self::asViewer($viewer, static function (TokenInterface $token): array {
            $draft = self::roundQueries()->accessFactsOf(self::MIAS_DRAFT);
            $draftOfTheScreenshot = self::roundQueries()->draftScreenshotOf(new TicketId(self::teamOnlyTicketId()), self::MIAS_SCREENSHOT);
            $report = self::roundQueries()->accessFactsOf(self::REPORT);

            return [
                'draft view' => self::isGranted($token, RoundPermission::VIEW, $draft),
                'draft edit' => self::isGranted($token, RoundPermission::EDIT, $draft),
                'draft of the screenshot view' => self::isGranted($token, RoundPermission::VIEW, $draftOfTheScreenshot),
                'draft of the screenshot edit' => self::isGranted($token, RoundPermission::EDIT, $draftOfTheScreenshot),
                'report view' => self::isGranted($token, RoundPermission::VIEW, $report),
            ];
        });

        self::assertSame([
            'draft view' => $isMia,
            'draft edit' => $isMia,
            'draft of the screenshot view' => $isMia,
            'draft of the screenshot edit' => $isMia,
            'report view' => $seesTicket,
        ], $decided, $viewer);
    }

    /**
     * Written out by viewer, so a change to the provider cannot hide one.
     */
    #[Test]
    public function miasUnsharedDraftIsInvisibleToTheAssigneeTheLeadTheAdminAndAnotherOrganization(): void
    {
        self::assertSame([self::REPORT, self::MIAS_DRAFT], array_column(self::roundsAs('Mia'), 0));
        self::assertSame([self::REPORT, self::OMARS_DRAFT], array_column(self::roundsAs('Omar'), 0));
        self::assertSame([self::REPORT], array_column(self::roundsAs('Lena'), 0));
        self::assertSame([self::REPORT], array_column(self::roundsAs('Sara'), 0));
        self::assertSame([], self::roundsAs('Ben'));
    }

    /**
     * Claude Code's shared notes are the newest shared round with pictures, never an unshared draft, though Mia's and
     * Omar's drafts are newer and hold pictures.
     */
    #[Test]
    public function theSharedNotesAreTheReportNeverAnUnsharedDraft(): void
    {
        $shared = self::asViewer('Lena', static fn (): ?string => self::roundQueries()->sharedRoundNotes(new TicketId(self::teamOnlyTicketId()))?->roundId);

        self::assertSame(self::REPORT, $shared);
    }

    /**
     * The rounds of the team-only ticket the viewer gets, each with its screenshots and their pins, by id.
     *
     * @return list<array{string, list<array{string, list<string>}>}>
     */
    private static function roundsAs(string $viewer): array
    {
        return self::asViewer($viewer, static function () use ($viewer): array {
            $rounds = self::roundQueries()->forTicket(new TicketId(self::teamOnlyTicketId()), new UserId(self::VIEWERS[$viewer][0]))->rounds;

            return array_map(static fn (RoundView $round): array => [$round->id, array_map(
                static fn (RoundScreenshotView $screenshot): array => [$screenshot->id, array_map(static fn (RoundPinView $pin): string => $pin->id, $screenshot->pins)],
                $round->screenshots,
            )], $rounds);
        });
    }

    /**
     * A round the tenant has not is refused, as the controllers answer it with 404.
     */
    private static function isGranted(TokenInterface $token, string $attribute, ?RoundAccessFacts $round): bool
    {
        return null !== $round && self::accessDecisionManager()->decide($token, [$attribute], $round);
    }

    private static function roundQueries(): RoundQueries
    {
        $roundQueries = self::getContainer()->get('test.review_round_queries');
        \assert($roundQueries instanceof RoundQueries);

        return $roundQueries;
    }

    private static function accessDecisionManager(): AccessDecisionManagerInterface
    {
        return self::getContainer()->get('security.access.decision_manager');
    }

    private static function roundRows(): RoundRows
    {
        return new RoundRows(self::migrator(), self::tenantContext());
    }
}
