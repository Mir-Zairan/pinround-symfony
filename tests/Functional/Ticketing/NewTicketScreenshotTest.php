<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Application\ReportPictures;
use App\Review\UI\Component\RoundsSection;
use App\Review\UI\NewTicket\ScreenshotReportOnNewTicket;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use App\Ticketing\UI\NewTicketController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The screenshot part of the new ticket form at /tickets/new, saved as the ticket's Round 0 through the real firewall,
 * voters, Review and database. Mia, a viewer of Shop, reports; Acme Foods already has APP-1 in Mobile's project with a
 * report of its own, so a round count that ignores the organization or the ticket tells. The clock is fixed on
 * 6 Nov 2026, before today: a clock ahead of the login would end the session as idle.
 */
final class NewTicketScreenshotTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string NEW_TICKET = '/tickets/new';
    private const string TICKET_TITLE = 'Coupon total is wrong';
    private const string TICKET_DESCRIPTION = 'The cart shows €0 after a coupon.';
    /** What Turbo sends when it loads the Rounds section's frame. */
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => RoundsSection::FRAME_ID];

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $backlogStepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $clock);
        new AddTicketEventPartitions(self::getContainer()->get('doctrine.dbal.migrator_connection'), $clock)();
        $this->people = $this->createAcmeFoodsPeople();
        $this->backlogStepId = $this->addCoreSteps();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->addTicketWithReport($this->addProject($this->mobile, 'APP', 'Mobile app'), 'APP-1');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['rvw_round', 'med_object', 'tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aTicketWithAScreenshotAndTwoPinsGetsRoundZeroByTheReporterWithThePinsInTheirPostedOrder(): void
    {
        $mediaId = $this->addMedia($this->people['Mia'], 'ready');
        $this->addMedia($this->people['Mia'], 'ready');
        $this->logIn($this->client, $this->people['Mia']);

        $this->postTicket([
            NewTicketController::SCREENSHOT_MEDIA_FIELD => $mediaId,
            NewTicketController::SCREENSHOT_TITLE_FIELD => ' Cart after a coupon ',
            NewTicketController::SCREENSHOT_SUMMARY_FIELD => "The total drops to zero.\r\nIt should not.",
            NewTicketController::PINS_FIELD => [
                5 => ['x' => '80.00', 'y' => '50.00', 'note' => 'Pay button is hidden'],
                2 => ['x' => '12.35', 'y' => '7.10', 'note' => 'The total is off'],
            ],
        ]);

        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame(['APP-1', 'SHOP-1'], $this->committedTicketKeys());
        self::assertSame([
            ['key' => 'APP-1', 'number' => 0, 'kind' => 'report', 'owner_id' => $this->people['Omar']->id()->toString(), 'created_at' => '2026-11-05 08:00'],
            ['key' => 'SHOP-1', 'number' => 0, 'kind' => 'report', 'owner_id' => $this->people['Mia']->id()->toString(), 'created_at' => '2026-11-06 12:00'],
        ], $this->committedRounds());
        self::assertSame(
            [['media_id' => $mediaId, 'title' => 'Cart after a coupon', 'summary' => "The total drops to zero.\nIt should not."]],
            $this->committedScreenshotsOf('SHOP-1'),
        );
        self::assertSame([
            ['number' => 1, 'x_pct' => '12.35', 'y_pct' => '7.10', 'note' => 'The total is off'],
            ['number' => 2, 'x_pct' => '80.00', 'y_pct' => '50.00', 'note' => 'Pay button is hidden'],
        ], $this->committedPinsOf('SHOP-1'));

        $frame = $this->client->request('GET', '/t/SHOP-1/rounds', [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Round 0 · Report · by Mia · 2 pins'], $frame->filter('.round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()));
        self::assertSame(['The total is off', 'Pay button is hidden'], $frame->filter('.pin-list__item .pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function postsWithoutAScreenshot(): iterable
    {
        yield 'no screenshot field at all' => [[]];
        yield 'blank fields and no pins' => [[
            NewTicketController::SCREENSHOT_MEDIA_FIELD => '',
            NewTicketController::SCREENSHOT_TITLE_FIELD => "  \r\n",
            NewTicketController::SCREENSHOT_SUMMARY_FIELD => '',
        ]];
    }

    /**
     * @param array<string, mixed> $screenshotFields
     */
    #[Test]
    #[DataProvider('postsWithoutAScreenshot')]
    public function aTicketWithoutAScreenshotIsMadeWithNoRound(array $screenshotFields): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $this->postTicket($screenshotFields);

        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame(['APP-1', 'SHOP-1'], $this->committedTicketKeys());
        self::assertSame(['APP-1'], array_column($this->committedRounds(), 'key'));
        $frame = $this->client->request('GET', '/t/SHOP-1/rounds', [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $frame->filter('.round-card'));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}> whose picture is posted ("none" for none, or
     *                                                                       "<uploader> <status>[ in Beta Bakery]"), the
     *                                                                       other screenshot fields and the message
     */
    public static function refusedScreenshots(): iterable
    {
        $pin = ['x' => '10.00', 'y' => '20.00', 'note' => 'Here'];
        yield 'a picture another person uploaded' => ['Omar ready', [], RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a picture still processing' => ['Mia pending', [], RoundRefused::MEDIA_STILL_PROCESSING];
        yield 'a rejected picture' => ['Mia rejected', [], RoundRefused::MEDIA_REJECTED];
        yield 'a picture of another organization the reporter uploaded' => ['Mia ready in Beta Bakery', [], RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a media id that is no uuid' => ['none', [NewTicketController::SCREENSHOT_MEDIA_FIELD => 'not-a-uuid'], RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a title of 121 characters' => ['Mia ready', [NewTicketController::SCREENSHOT_TITLE_FIELD => str_repeat('é', 121)], 'A picture title holds at most 120 characters.'];
        yield 'a summary of 601 characters' => ['Mia ready', [NewTicketController::SCREENSHOT_SUMMARY_FIELD => str_repeat('é', 601)], 'A picture summary holds at most 600 characters.'];
        yield 'a pin note of 1,001 characters' => ['Mia ready', [NewTicketController::PINS_FIELD => [1 => [...$pin, 'note' => str_repeat('é', 1001)]]], 'A pin note holds at most 1000 characters.'];
        yield 'one pin more than a picture holds' => ['Mia ready', [NewTicketController::PINS_FIELD => array_fill(1, RoundCommands::MAX_PINS_PER_SCREENSHOT + 1, $pin)], ScreenshotReportOnNewTicket::TOO_MANY_PINS];
        yield 'a pin x with an exponent' => ['Mia ready', [NewTicketController::PINS_FIELD => [1 => [...$pin, 'x' => '1e1']]], ScreenshotReportOnNewTicket::PIN_SPOT_UNREADABLE];
        yield 'a pin with no y' => ['Mia ready', [NewTicketController::PINS_FIELD => [1 => ['x' => '10.00']]], ScreenshotReportOnNewTicket::PIN_SPOT_UNREADABLE];
        yield 'a pin key that is not a number' => ['Mia ready', [NewTicketController::PINS_FIELD => ['first' => $pin]], ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'pins posted as text' => ['Mia ready', [NewTicketController::PINS_FIELD => 'none'], ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a media id posted as a list' => ['none', [NewTicketController::SCREENSHOT_MEDIA_FIELD => ['a', 'b']], ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a title posted as a list' => ['Mia ready', [NewTicketController::SCREENSHOT_TITLE_FIELD => ['x']], ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a summary that is not UTF-8' => ['Mia ready', [NewTicketController::SCREENSHOT_SUMMARY_FIELD => "\xC3\x28"], ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a title and pins with no picture' => ['none', [NewTicketController::SCREENSHOT_TITLE_FIELD => 'Cart', NewTicketController::PINS_FIELD => [1 => $pin]], ScreenshotReportOnNewTicket::CONTENT_WITHOUT_SCREENSHOT];
    }

    /**
     * @param array<string, mixed> $screenshotFields
     */
    #[Test]
    #[DataProvider('refusedScreenshots')]
    public function aRefusedScreenshotShowsItsMessageKeepsTheTicketTextAndMakesNoTicketNoRoundAndUsesUpNoNumber(string $picture, array $screenshotFields, string $message): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $mediaFields = 'none' === $picture ? [] : [NewTicketController::SCREENSHOT_MEDIA_FIELD => $this->addPictureAsDescribed($picture)];

        $page = $this->postTicket([...$mediaFields, ...$screenshotFields]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($message, $page->filter('#screenshot-error')->text());
        self::assertSame(self::TICKET_TITLE, $page->filter('input[name="title"]')->attr('value'));
        self::assertSame(self::TICKET_DESCRIPTION, $page->filter('textarea[name="description"]')->text());
        self::assertCount(0, $page->filter('.profile__error:not(#screenshot-error)'), 'Only the screenshot part is refused.');
        self::assertSame(['APP-1'], $this->committedTicketKeys());
        self::assertSame(['APP-1'], array_column($this->committedRounds(), 'key'));

        $this->postTicket();
        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame(['APP-1', 'SHOP-1'], $this->committedTicketKeys());
    }

    #[Test]
    public function aRefusedReportWithTheReportersOwnReadyPictureComesBackWithThePictureItsPinsAndTheSummary(): void
    {
        $mediaId = $this->addMedia($this->people['Mia'], 'ready');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->postTicket([
            NewTicketController::SCREENSHOT_MEDIA_FIELD => $mediaId,
            NewTicketController::SCREENSHOT_TITLE_FIELD => str_repeat('é', 121),
            NewTicketController::SCREENSHOT_SUMMARY_FIELD => 'The total drops to zero.',
            NewTicketController::PINS_FIELD => [
                5 => ['x' => '80.00', 'y' => '50.00', 'note' => 'Pay button is hidden'],
                2 => ['x' => '12.35', 'y' => '7.10', 'note' => 'The total is off'],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('A picture title holds at most 120 characters.', $page->filter('#screenshot-error')->text());
        self::assertSame($mediaId, $page->filter('input[name="'.NewTicketController::SCREENSHOT_MEDIA_FIELD.'"]')->attr('value'));
        $markers = $page->filter('#screenshot-frame .pin');
        self::assertSame(['2', '5'], $markers->each(static fn (Crawler $marker): string => (string) $marker->attr('data-pin-number')));
        self::assertSame(['12.35', '80.00'], $markers->each(static fn (Crawler $marker): string => (string) $marker->attr('data-x-percent')));
        self::assertSame(['The total is off', 'Pay button is hidden'], $page->filter('textarea.pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        self::assertSame('The total drops to zero.', $page->filter('textarea[name="'.NewTicketController::SCREENSHOT_SUMMARY_FIELD.'"]')->text());
        self::assertSame(str_repeat('é', 121), $page->filter('input[name="'.NewTicketController::SCREENSHOT_TITLE_FIELD.'"]')->attr('value'));
        self::assertSame(['APP-1'], $this->committedTicketKeys());
    }

    /**
     * @return iterable<string, array{string}> whose picture is posted, as addPictureAsDescribed reads it
     */
    public static function picturesNotTheReportersOwn(): iterable
    {
        yield 'a picture another person uploaded' => ['Omar ready'];
        yield 'a picture of another organization the reporter uploaded' => ['Mia ready in Beta Bakery'];
    }

    #[Test]
    #[DataProvider('picturesNotTheReportersOwn')]
    public function aRefusedPictureThatIsNotTheReportersOwnComesBackWithoutItsIdOrPinsButWithTheSummary(string $picture): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->postTicket([
            NewTicketController::SCREENSHOT_MEDIA_FIELD => $this->addPictureAsDescribed($picture),
            NewTicketController::SCREENSHOT_SUMMARY_FIELD => 'The total drops to zero.',
            NewTicketController::PINS_FIELD => [1 => ['x' => '12.35', 'y' => '7.10', 'note' => 'The total is off']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU, $page->filter('#screenshot-error')->text());
        self::assertSame('', $page->filter('input[name="'.NewTicketController::SCREENSHOT_MEDIA_FIELD.'"]')->attr('value'));
        self::assertCount(0, $page->filter('.pin'));
        self::assertCount(0, $page->filter('textarea.pin-list__note'));
        self::assertSame('The total drops to zero.', $page->filter('textarea[name="'.NewTicketController::SCREENSHOT_SUMMARY_FIELD.'"]')->text());
        self::assertSame(['APP-1'], $this->committedTicketKeys());
    }

    #[Test]
    public function aReportRefusedOnlyOnceTheTicketIsMadeRollsTheTicketAndItsNumberBack(): void
    {
        $container = self::getContainer();
        $report = new NewTicketReportRefusingOnlyOnAttach(new ScreenshotReportOnNewTicket(
            $container->get(RoundCommands::class),
            $container->get(ReportPictures::class),
            $container->get(TenantContext::class),
        ));
        $container->set(ScreenshotReportOnNewTicket::class, $report);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->postTicket([
            NewTicketController::SCREENSHOT_MEDIA_FIELD => $this->addMedia($this->people['Omar'], 'ready'),
            NewTicketController::PINS_FIELD => [1 => ['x' => '12.35', 'y' => '7.10', 'note' => 'The total is off']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $report->attachRefusalCount, 'The refusal came from attach, after the ticket was made.');
        self::assertSame(RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU, $page->filter('#screenshot-error')->text());
        self::assertSame(self::TICKET_TITLE, $page->filter('input[name="title"]')->attr('value'));
        self::assertSame(['APP-1'], $this->committedTicketKeys());
        self::assertSame(['APP-1'], array_column($this->committedRounds(), 'key'));

        $this->postTicket();
        self::assertResponseRedirects('/t/SHOP-1', 302);
        self::assertSame(['APP-1', 'SHOP-1'], $this->committedTicketKeys());
        self::assertSame(['APP-1'], array_column($this->committedRounds(), 'key'));
    }

    #[Test]
    public function aPictureStillProcessingIsRefusedWithTheOtherFieldErrorsBeforeTheTicketIsMade(): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->postTicket([
            NewTicketController::TITLE_FIELD => '   ',
            NewTicketController::SCREENSHOT_MEDIA_FIELD => $this->addMedia($this->people['Mia'], 'pending'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(NewTicketController::TITLE_MISSING, $page->filter('#ticket-title-error')->text());
        self::assertSame(RoundRefused::MEDIA_STILL_PROCESSING, $page->filter('#screenshot-error')->text());
        self::assertSame(['APP-1'], $this->committedTicketKeys());
        self::assertSame(['APP-1'], array_column($this->committedRounds(), 'key'));
    }

    #[Test]
    public function aPersonOfNoTeamWhoPostsAScreenshotIsDeniedAndNothingIsSaved(): void
    {
        $mediaId = $this->addMedia($this->people['Raj'], 'ready');
        $this->logIn($this->client, $this->people['Raj']);

        $this->client->request('POST', self::NEW_TICKET, [
            ...$this->ticketFields($this->csrfTokenFor($this->client, NewTicketController::CSRF_ID)),
            NewTicketController::SCREENSHOT_MEDIA_FIELD => $mediaId,
            NewTicketController::PINS_FIELD => [1 => ['x' => '10.00', 'y' => '20.00', 'note' => 'Here']],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['APP-1'], $this->committedTicketKeys());
        self::assertSame(['APP-1'], array_column($this->committedRounds(), 'key'));
    }

    /**
     * Posts the form with the CSRF token of the page it renders, the Shop project, Bug, Normal, the ticket's title and
     * description, unless $fields says else, with the screenshot fields of $fields.
     *
     * @param array<string, mixed> $fields
     */
    private function postTicket(array $fields = []): Crawler
    {
        $form = $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        $csrfToken = (string) $form->filter('form[action="'.self::NEW_TICKET.'"] input[name="'.NewTicketController::CSRF_FIELD.'"]')->attr('value');

        return $this->client->request('POST', self::NEW_TICKET, [...$this->ticketFields($csrfToken), ...$fields]);
    }

    /**
     * @return array<string, string>
     */
    private function ticketFields(string $csrfToken): array
    {
        return [
            NewTicketController::CSRF_FIELD => $csrfToken,
            NewTicketController::PROJECT_FIELD => $this->shopProject,
            NewTicketController::TITLE_FIELD => self::TICKET_TITLE,
            NewTicketController::DESCRIPTION_FIELD => self::TICKET_DESCRIPTION,
            NewTicketController::TYPE_FIELD => 'bug',
            NewTicketController::PRIORITY_FIELD => 'normal',
        ];
    }

    /**
     * After the login: a person of two organizations works in the one they choose, here Acme Foods.
     *
     * @param string $picture "<uploader> <status>", with " in Beta Bakery" for a picture of an organization of its own
     *                        the uploader also belongs to
     *
     * @return string the media id
     */
    private function addPictureAsDescribed(string $picture): string
    {
        [$uploader, $status] = explode(' ', $picture, 3);
        if (!\in_array($status, ['ready', 'pending', 'rejected'], true)) {
            self::fail('No picture status '.$status);
        }
        if (!str_ends_with($picture, ' in Beta Bakery')) {
            return $this->addMedia($this->people[$uploader], $status);
        }
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people[$uploader], $betaBakery);
        $picker = $this->client->request('GET', '/org/choose');
        self::assertResponseStatusCodeSame(200);
        $choice = $picker->filter(\sprintf('input[name="organization_id"][value="%s"]', $this->acmeFoods->toString()))->closest('form')?->form();
        self::assertNotNull($choice);
        $this->client->submit($choice);
        self::assertResponseRedirects('/', 302);

        return $this->addMedia($this->people[$uploader], $status, $betaBakery);
    }

    /**
     * Backlog and To do as core steps of Acme Foods, which CreateTicket needs.
     *
     * @return string Backlog's id
     */
    private function addCoreSteps(): string
    {
        $organizationId = $this->acmeFoods->toString();
        $backlogStepId = Uuid::v7()->toRfc4122();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $backlogStepId): int {
            foreach ([[$backlogStepId, 'Backlog', 'backlog', 0], [Uuid::v7()->toRfc4122(), 'To do', 'todo', 1]] as [$stepId, $name, $coreCode, $position]) {
                $migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, 'todo', ?, 'core', ?)",
                    [$organizationId, $stepId, $name, $coreCode, $position],
                );
            }

            return 0;
        });

        return $backlogStepId;
    }

    /**
     * A ticket of Mobile reported by Omar, with its Round 0 of one picture and one pin.
     */
    private function addTicketWithReport(string $projectId, string $key): void
    {
        $organizationId = $this->acmeFoods->toString();
        $ticketId = Uuid::v7()->toRfc4122();
        $roundId = Uuid::v7()->toRfc4122();
        $screenshotId = Uuid::v7()->toRfc4122();
        $omarId = $this->people['Omar']->id()->toString();
        $mediaId = $this->addMedia($this->people['Omar'], 'ready');
        $mobileId = $this->mobile->toString();
        $stepId = $this->backlogStepId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $ticketId, $roundId, $screenshotId, $omarId, $mediaId, $projectId, $mobileId, $key, $stepId): int|string {
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, 'App crashes on start', '', 'bug', 'major', 2, ?, ?, NULL, 'all', 1, '{}', '{}',
                     TIMESTAMPTZ '2026-11-05 08:00:00+00', TIMESTAMPTZ '2026-11-05 08:00:00+00')",
                [$organizationId, $ticketId, $projectId, $mobileId, $key, $stepId, $omarId],
            );
            $migrator->executeStatement(
                "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at)
                 VALUES (?, ?, ?, 0, 'report', ?, '2026-11-05 08:00:00+00', 'open', 1, '2026-11-05 08:00:00+00')",
                [$organizationId, $roundId, $ticketId, $omarId],
            );
            $migrator->executeStatement(
                "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, title, created_at)
                 VALUES (?, ?, ?, ?, 0, 'Start screen', '2026-11-05 08:00:00+00')",
                [$organizationId, $screenshotId, $roundId, $mediaId],
            );

            return $migrator->executeStatement(
                "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, source, author_id, created_at)
                 VALUES (?, ?, ?, 1, 50, 50, 'Blank here', 'human', ?, '2026-11-05 08:00:00+00')",
                [$organizationId, Uuid::v7()->toRfc4122(), $screenshotId, $omarId],
            );
        });
    }

    /**
     * @param 'ready'|'pending'|'rejected' $status
     *
     * @return string the media id
     */
    private function addMedia(User $uploader, string $status, ?OrganizationId $organizationId = null): string
    {
        $organizationId ??= $this->acmeFoods;
        $mediaId = Uuid::v7()->toRfc4122();
        $parameters = [
            $organizationId->toString(), $mediaId, 'uploads/'.$mediaId, $uploader->id()->toString(), $status,
            'rejected' === $status ? 'The file is not a picture.' : null,
            'ready' === $status ? hash('sha256', $mediaId) : null,
            'ready' === $status ? 1280 : null,
            'ready' === $status ? 800 : null,
            'ready' === $status ? json_encode(['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp'], \JSON_THROW_ON_ERROR) : null,
        ];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
             VALUES (?, ?, ?, 'image/png', 2048, ?, '2026-11-05 08:00:00+00', ?, ?, ?, ?, ?, CAST(? AS jsonb))",
            $parameters,
        ));

        return $mediaId;
    }

    /**
     * @return list<string>
     */
    private function committedTicketKeys(): array
    {
        $keys = [];
        foreach ($this->committedRowsOf('SELECT key FROM tkt_ticket WHERE organization_id = ? ORDER BY key') as ['key' => $key]) {
            self::assertIsString($key);
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @return list<array<string, mixed>> Acme Foods' rounds by their ticket's key, created_at in UTC
     */
    private function committedRounds(): array
    {
        return $this->committedRowsOf(
            "SELECT t.key, r.number, r.kind, r.owner_id::text AS owner_id, to_char(r.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS created_at"
            .' FROM rvw_round r JOIN tkt_ticket t ON t.organization_id = r.organization_id AND t.id = r.ticket_id'
            .' WHERE r.organization_id = ? ORDER BY t.key, r.number',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function committedScreenshotsOf(string $key): array
    {
        return $this->committedRowsOf(
            'SELECT s.media_id::text AS media_id, s.title, s.summary FROM rvw_screenshot s'
            .' JOIN rvw_round r ON r.organization_id = s.organization_id AND r.id = s.round_id'
            .' JOIN tkt_ticket t ON t.organization_id = r.organization_id AND t.id = r.ticket_id'
            .' WHERE s.organization_id = ? AND t.key = ? ORDER BY s.position',
            [$key],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function committedPinsOf(string $key): array
    {
        return $this->committedRowsOf(
            'SELECT p.number, p.x_pct::text AS x_pct, p.y_pct::text AS y_pct, p.note FROM rvw_pin p'
            .' JOIN rvw_screenshot s ON s.organization_id = p.organization_id AND s.id = p.screenshot_id'
            .' JOIN rvw_round r ON r.organization_id = s.organization_id AND r.id = s.round_id'
            .' JOIN tkt_ticket t ON t.organization_id = r.organization_id AND t.id = r.ticket_id'
            .' WHERE p.organization_id = ? AND t.key = ? ORDER BY p.number',
            [$key],
        );
    }

    /**
     * @param list<string> $parameters after Acme Foods' id, which the query takes first
     *
     * @return list<array<string, mixed>>
     */
    private function committedRowsOf(string $sql, array $parameters = []): array
    {
        $rows = [];
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($sql, $organizationId, $parameters, &$rows): int {
            $rows = $migrator->fetchAllAssociative($sql, [$organizationId, ...$parameters]);

            return 0;
        });

        return $rows;
    }
}
