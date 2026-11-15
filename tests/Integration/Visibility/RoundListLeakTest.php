<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Review\Api\RoundPinView;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Infrastructure\Doctrine\DbalRoundQueries;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Rounds section's data, RoundQueries::forTicket, read as a page would: under the viewer's own organization as
 * tenant. Each cast ticket has a shared Round 0 with a screenshot and a pin, which a viewer gets exactly when they may
 * see the ticket.
 */
final class RoundListLeakTest extends VisibilityLeakCast
{
    private const string TEAM_ONLY_REPORT = '0197a3c0-0000-7000-8000-000000005b01';
    private const string NO_TEAM_REPORT = '0197a3c0-0000-7000-8000-000000005b02';
    private const string TEAM_ONLY_SCREENSHOT = '0197a3c0-0000-7000-8000-000000005b11';
    private const string NO_TEAM_SCREENSHOT = '0197a3c0-0000-7000-8000-000000005b12';
    private const string TEAM_ONLY_PIN = '0197a3c0-0000-7000-8000-000000005b21';
    private const string NO_TEAM_PIN = '0197a3c0-0000-7000-8000-000000005b22';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000005b31';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $rounds = [
            [self::TEAM_ONLY_REPORT, self::teamOnlyTicketId(), self::VIEWERS['Mia'][0], self::TEAM_ONLY_SCREENSHOT, self::TEAM_ONLY_PIN],
            [self::NO_TEAM_REPORT, self::NO_TEAM_TICKET, self::NO_TEAM_REPORTER, self::NO_TEAM_SCREENSHOT, self::NO_TEAM_PIN],
        ];
        self::transactions()->committedAs(self::ACME_FOODS, static function () use ($rounds): void {
            foreach ($rounds as [$roundId, $ticketId, $reporterId, $screenshotId, $pinId]) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at) VALUES (?, ?, ?, 0, 'report', ?, '2027-03-15 10:00:00+00', 'open', 1, '2027-03-15 10:00:00+00')",
                    [self::ACME_FOODS, $roundId, $ticketId, $reporterId],
                );
                self::connection()->executeStatement(
                    "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, created_at) VALUES (?, ?, ?, ?, 0, '2027-03-15 10:00:00+00')",
                    [self::ACME_FOODS, $screenshotId, $roundId, self::MEDIA],
                );
                self::connection()->executeStatement(
                    "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, source, author_id, created_at) VALUES (?, ?, ?, 1, 40, 60, ?, 'human', ?, '2027-03-15 10:00:00+00')",
                    [self::ACME_FOODS, $pinId, $screenshotId, self::LEAK_MARKER, $reporterId],
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

    #[Test]
    #[DataProvider('viewers')]
    public function theRoundsOfATeamOnlyTicketReachOnlyAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        self::assertSame(
            $seesTeamOnly ? [[self::TEAM_ONLY_REPORT, [[self::TEAM_ONLY_SCREENSHOT, [self::TEAM_ONLY_PIN]]]]] : [],
            self::roundsAs($viewer, self::teamOnlyTicketId()),
            \sprintf('%s gets the wrong rounds of the team-only ticket.', $viewer),
        );
        self::assertSame(
            $seesNoTeamTicket ? [[self::NO_TEAM_REPORT, [[self::NO_TEAM_SCREENSHOT, [self::NO_TEAM_PIN]]]]] : [],
            self::roundsAs($viewer, self::NO_TEAM_TICKET),
            \sprintf('%s gets the wrong rounds of the team-only ticket with no team.', $viewer),
        );
    }

    /**
     * Written out by viewer, so a change to the provider cannot hide one: the team's lead gets the round, and a member
     * outside the team, a manager with no team role and another organization's admin get none.
     */
    #[Test]
    public function aRoundOfATeamOnlyTicketIsInvisibleOutsideTheTeamAndToAnotherOrganization(): void
    {
        self::assertSame([self::TEAM_ONLY_REPORT], array_column(self::roundsAs('Lena', self::teamOnlyTicketId()), 0));
        self::assertSame([], self::roundsAs('Noor', self::teamOnlyTicketId()));
        self::assertSame([], self::roundsAs('Raj', self::teamOnlyTicketId()));
        self::assertSame([], self::roundsAs('Ben', self::teamOnlyTicketId()));
    }

    /**
     * Each round the viewer gets with its screenshots and their pins, by id.
     *
     * @return list<array{string, list<array{string, list<string>}>}>
     */
    private static function roundsAs(string $viewer, string $ticketId): array
    {
        return self::asViewer($viewer, static function () use ($viewer, $ticketId): array {
            $rounds = self::roundQueries()->forTicket(new TicketId($ticketId), new UserId(self::VIEWERS[$viewer][0]));

            return array_map(static fn (RoundView $round): array => [$round->id, array_map(
                static fn (RoundScreenshotView $screenshot): array => [$screenshot->id, array_map(static fn (RoundPinView $pin): string => $pin->id, $screenshot->pins)],
                $round->screenshots,
            )], $rounds);
        });
    }

    /**
     * Built with the services the container wires it with: no page reads rounds yet, so the compiled test container
     * drops the private service.
     */
    private static function roundQueries(): DbalRoundQueries
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);
        $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
        \assert($ticketQueries instanceof TicketQueries);

        return new DbalRoundQueries(self::connection(), self::tenantContext(), $ticketVisibilities, $ticketQueries);
    }

    private static function roundRows(): RoundRows
    {
        return new RoundRows(self::migrator(), self::tenantContext());
    }
}
