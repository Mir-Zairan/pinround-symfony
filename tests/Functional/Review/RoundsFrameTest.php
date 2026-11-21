<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\UI\Checklist\ChecklistCard;
use App\Review\UI\Component\DraftRoundEditor;
use App\Review\UI\Component\RoundsSection;
use App\Review\UI\Controller\ChecklistController;
use App\Review\UI\RoundCards;
use App\Review\UI\RoundsController;
use App\Review\UI\RoundsSectionView;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\UI\TicketShowController;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Rounds section's lazy frame of Acme Foods' ticket SHOP-1, GET /t/SHOP-1/rounds, through the real firewall,
 * voters, Media signing and database. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end the session as idle.
 * Mia reported the ticket and Lena leads the Shop team, Omar is its member; Raj is a manager in no team.
 */
final class RoundsFrameTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string SHARED_AT = '2026-11-05 09:00:00+00';
    /**
     * A warm frame for a ticket with no round, the request's transaction included: the session's user and password
     * change, the organizations, the tenant, the viewer's roles and teams twice, the settings, the ticket, the names, the
     * ticket's facts for RoundPermission DRAFT_CREATE ("Start a draft round"), the visibility check and the rounds.
     */
    private const int EMPTY_FRAME_QUERIES = 16;
    /** The checklist's items, then the ticket's facts, which ChecklistPermission MARK_FIXED and CHECK are both decided on. */
    private const int CHECKLIST_QUERIES = 2;
    private const string ROUNDS_PAGE = '/t/SHOP-1/rounds';
    private const string ROUNDS = 'turbo-frame#ticket-rounds';
    /** What Turbo sends when it loads the Rounds section's frame. */
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => RoundsSection::FRAME_ID];
    private const string REPORT_TITLE = 'Checkout <b>total</b>';
    private const string REPORT_SUMMARY = 'The total shows €0 after a coupon.';
    private const string LENA_DRAFT_TITLE = 'Lena private self-check';
    private const string TEAM_ONLY_TITLE = 'Team-only price rules';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $stepId = $this->stepId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aTicketWithNoRoundsShowsTheEmptyState(): void
    {
        $this->addTicket('SHOP-1');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(RoundsSection::EMPTY, $page->filter(self::ROUNDS.' .empty-state')->text());
        self::assertCount(0, $page->filter(self::ROUNDS.' .round-card'));
    }

    #[Test]
    public function roundZeroShowsItsReporterItsPinCountAndEachScreenshotsTextAboveItsPictureWithPinsAndNoWayToAddOne(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $readyMedia = $this->addMedia('ready');
        $screenshotId = $this->addScreenshot($roundId, $readyMedia, 0, self::REPORT_TITLE, self::REPORT_SUMMARY);
        $this->addPin($screenshotId, 1, '12.35', '7.10', 'The total is off', 'Mia');
        $this->addPin($screenshotId, 2, '80.00', '50.00', 'Pay button is hidden', 'Lena');
        $this->addPin($screenshotId, 3, '50.00', '50.00', 'An AI guess nobody wanted', 'Mia', 'ai', 'dismissed');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $card = $page->filter(self::ROUNDS.' .round-card');
        self::assertCount(1, $card);
        self::assertSame('Round 0 · Report · by Mia · 2 pins', $card->filter('.round-card__headline')->text());
        self::assertSame(self::REPORT_TITLE, $card->filter('.screenshot__title')->text());
        self::assertSame(self::REPORT_SUMMARY, $card->filter('.screenshot__summary')->text());
        self::assertStringNotContainsString('<b>total</b>', (string) $this->client->getResponse()->getContent(), 'A title is text, never markup.');
        $order = $card->filter('figure.screenshot')->children()->each(static fn (Crawler $child): string => (string) $child->attr('class'));
        self::assertSame(['screenshot__text', 'screenshot__frame'], $order, 'The text comes above the picture.');
        self::assertStringContainsString('/media/'.$readyMedia.'/view.webp', (string) $card->filter('img.screenshot__image')->attr('src'));
        self::assertSame(
            ['Pin 1: The total is off', 'Pin 2: Pay button is hidden'],
            $card->filter('button.pin')->each(static fn (Crawler $pin): string => (string) $pin->attr('aria-label')),
        );
        self::assertSame(['The total is off', 'Pay button is hidden'], $card->filter('.pin-list__item .pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        self::assertCount(0, $card->filter('button[data-pin-add], textarea, [data-pin-remove], form'));
        self::assertCount(0, $card->filter('.round-card__open'));
        self::assertStringNotContainsString('An AI guess nobody wanted', (string) $this->client->getResponse()->getContent());
    }

    /**
     * P14.T7: review.css caps Round 0's picture by the thumbnail hook, so five rounds fit one screen; the owner's draft
     * editor keeps its pictures full width, as pins are placed on them.
     */
    #[Test]
    public function roundZerosPictureAndPinListCarryTheThumbnailHookAndTheOwnersDraftPictureDoesNot(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $reportShot = $this->addScreenshot($report, $this->addMedia('ready'), 0, 'Report shot', null);
        $this->addPin($reportShot, 1, '12.35', '7.10', 'The total is off', 'Mia');
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($draft, $this->addMedia('ready'), 0, 'Draft shot', null);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $thumbnails = $page->filter(self::ROUNDS.' article#round-'.$report.' .round-card__screenshot--thumbnail');
        self::assertCount(1, $thumbnails);
        self::assertSame(['Report shot'], $thumbnails->filter('figure.screenshot .screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(['The total is off'], $thumbnails->filter('.pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        $draftCard = $page->filter(self::ROUNDS.' article#round-'.$draft);
        self::assertCount(1, $draftCard->filter('figure.screenshot'), 'The draft shows its picture.');
        self::assertCount(0, $draftCard->filter('.round-card__screenshot--thumbnail'));
        self::assertCount(1, $page->filter(self::ROUNDS.' .round-card__screenshot--thumbnail'));
    }

    #[Test]
    public function aPictureStillProcessingARefusedOneAndOneMediaDoesNotKnowEachShowTheirOwnPlaceholder(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addScreenshot($roundId, $this->addMedia('pending'), 0, 'Pending one', null);
        $this->addScreenshot($roundId, $this->addMedia('rejected'), 1, 'Refused one', null);
        $this->addScreenshot($roundId, Uuid::v7()->toRfc4122(), 2, 'Unknown one', null);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $shots = $page->filter(self::ROUNDS.' figure.screenshot');
        self::assertCount(3, $shots);
        self::assertCount(0, $shots->filter('img'));
        self::assertSame(
            ['Processing…', 'We could not process this image. Try again.', 'We could not process this image. Try again.'],
            $shots->each(static fn (Crawler $shot): string => $shot->filter('.screenshot__placeholder')->text()),
        );
        self::assertSame('Round 0 · Report · by Mia · 0 pins', $page->filter(self::ROUNDS.' .round-card__headline')->text());
    }

    /**
     * The checklist card drops the prototype's "· by owner" and "Open round": its rows are the round, so it has no page.
     */
    #[Test]
    public function aReviewRoundIsARowWithAnOpenRoundLinkAndTheChecklistShowsItsPinsAndNeitherShowsAPicture(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $reportShot = $this->addScreenshot($report, $this->addMedia('ready'), 0, 'Cart', null);
        $checklist = $this->addRound($ticketId, 1, 'checklist', 'Omar', self::SHARED_AT);
        $review = $this->addRound($ticketId, 2, 'review', 'Lena', self::SHARED_AT);
        $this->addCheckItem($checklist, $this->addPin($reportShot, 2, '20.00', '20.00', '', 'Mia'), 'fixed');
        $openItem = $this->addCheckItem($checklist, $this->addPin($reportShot, 1, '10.00', '10.00', 'Total is <b>wrong</b>', 'Mia'), 'open');
        $this->addCheckItem($checklist, $this->addPin($reportShot, 3, '30.00', '30.00', 'Coupon field cut', 'Mia'), 'ok');
        $this->addScreenshot($review, $this->addMedia('ready'), 0, 'Hidden in a row', null);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['Round 0 · Report · by Mia · 3 pins', 'Round 1 · Checklist from the report', 'Round 2 · review by Lena · open'],
            $page->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()),
        );
        self::assertSame(['Open round'], $page->filter(self::ROUNDS.' a.round-card__open')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame(['/t/SHOP-1/rounds/2'], $page->filter(self::ROUNDS.' a.round-card__open')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame(['_top'], $page->filter(self::ROUNDS.' a.round-card__open')->each(static fn (Crawler $link): string => (string) $link->attr('data-turbo-frame')), 'The round page leaves the frame.');
        self::assertSame(['round-'.$review], $page->filter(self::ROUNDS.' article.round-card--row')->each(static fn (Crawler $row): string => (string) $row->attr('id')));
        $card = $page->filter(self::ROUNDS.' article#round-'.$checklist);
        self::assertSame('round-'.$checklist.'-heading', $card->attr('aria-labelledby'));
        self::assertSame('1 of 3 checked OK', $card->filter('.round-card__status')->text());
        self::assertSame(ChecklistCard::DESCRIPTION, $card->filter('.round-card__description')->text());
        self::assertSame(
            [['1', 'Total is <b>wrong</b>', 'Open'], ['2', '(no note)', 'Fixed · to check'], ['3', 'Coupon field cut', 'Checked OK']],
            $card->filter('.checklist__row')->each(static fn (Crawler $row): array => [$row->filter('.checklist__number')->text(), $row->filter('.checklist__note')->text(), $row->filter('.check-state')->text()]),
        );
        self::assertSame(
            ['check-state--open', 'check-state--fixed', 'check-state--ok'],
            $card->filter('.check-state')->each(static fn (Crawler $chip): string => (string) preg_replace('/^.*(check-state--[a-z-]+).*$/', '$1', (string) $chip->attr('class'))),
        );
        self::assertSame(['Mark fixed'], $card->filter('form button')->each(static fn (Crawler $button): string => $button->text()), 'The assignee marks the open pin fixed and checks none.');
        self::assertSame('/t/SHOP-1/checklist/'.$openItem.'/fixed', $card->filter('form')->attr('action'));
        self::assertSame('_top', $card->filter('form')->attr('data-turbo-frame'));
        self::assertSame(ChecklistController::SAVE_FAILED, $card->filter('form')->attr('data-failed-toast'), 'assets/app.js reloads with this toast when the answer is no stream.');
        self::assertCount(0, $page->filter(self::ROUNDS.' [autofocus]'), 'Only the stream of a change moves the focus.');
        self::assertCount(1, $page->filter(self::ROUNDS.' article#round-'.$review.' a.round-card__open'));
        self::assertCount(1, $page->filter(self::ROUNDS.' figure'), "Only the report's picture.");
        self::assertStringNotContainsString('Hidden in a row', (string) $this->client->getResponse()->getContent());
    }

    /**
     * P14.T7: Round 0 and Round 1 stay cards; every later round is one line with "Open round", in number order whatever
     * order they were made in. Omar is the assignee, so his shared draft is a self-check and Mia's is findings.
     */
    #[Test]
    public function laterRoundsAreOneLineRowsInNumberOrderAfterTheReportAndChecklistCards(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $review = $this->addRound($ticketId, 4, 'review', 'Lena', self::SHARED_AT);
        $this->closeRound($review);
        $findings = $this->addRound($ticketId, 3, 'draft', 'Mia', self::SHARED_AT);
        $this->addCheckItem($findings, $this->addPin($this->addScreenshot($findings, $this->addMedia('ready'), 0, 'Findings shot', null), 1, '10.00', '10.00', 'A finding', 'Mia'), 'open');
        $selfCheck = $this->addRound($ticketId, 2, 'draft', 'Omar', self::SHARED_AT);
        $this->addScreenshot($selfCheck, $this->addMedia('ready'), 1, 'Self-check shot two', null, 'looks_right');
        $this->addScreenshot($selfCheck, $this->addMedia('ready'), 0, 'Self-check shot one', null);
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $reportShot = $this->addScreenshot($report, $this->addMedia('ready'), 0, 'Cart', null);
        $checklist = $this->addRound($ticketId, 1, 'checklist', 'Omar', self::SHARED_AT);
        $this->addCheckItem($checklist, $this->addPin($reportShot, 1, '10.00', '10.00', 'Total is wrong', 'Mia'), 'open');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['round-'.$report, 'round-'.$checklist], $page->filter(self::ROUNDS.' article.round-card:not(.round-card--row)')->each(static fn (Crawler $card): string => (string) $card->attr('id')));
        $rows = $page->filter(self::ROUNDS.' article.round-card--row');
        self::assertSame(
            [
                ['round-'.$selfCheck, 'Round 2 · self-check by Omar · open · 2 screenshots', '/t/SHOP-1/rounds/2', '_top'],
                ['round-'.$findings, 'Round 3 · findings by Mia · open · 1 screenshot', '/t/SHOP-1/rounds/3', '_top'],
                ['round-'.$review, 'Round 4 · review by Lena · closed', '/t/SHOP-1/rounds/4', '_top'],
            ],
            $rows->each(static fn (Crawler $row): array => [
                (string) $row->attr('id'),
                $row->filter('.round-card__headline')->text(),
                (string) $row->filter('a.round-card__open')->attr('href'),
                (string) $row->filter('a.round-card__open')->attr('data-turbo-frame'),
            ]),
        );
        self::assertSame(['Open round', 'Open round', 'Open round'], $rows->filter('a.round-card__open')->each(static fn (Crawler $link): string => $link->text()));
        self::assertCount(0, $rows->filter('figure, img'));
        foreach ([$selfCheck, $review] as $oneLineRound) {
            self::assertCount(0, $page->filter(self::ROUNDS.' article#round-'.$oneLineRound)->filter('.round-card__status, .round-card__description, .checklist'), 'A row without items is one line.');
        }
        self::assertCount(1, $page->filter(self::ROUNDS.' article#round-'.$findings.' .checklist'), 'The findings keep their items to mark fixed.');
        self::assertCount(1, $page->filter(self::ROUNDS.' figure'), "Only the report's picture.");
        $content = (string) $this->client->getResponse()->getContent();
        foreach (['Self-check shot one', 'Self-check shot two'] as $rowShotTitle) {
            self::assertStringNotContainsString($rowShotTitle, $content);
        }
    }

    /**
     * Omar's unshared draft is his card alone: Lena (lead), Mia (reporter) and Sara (admin) see the shared rows and no
     * trace of it, not even its id.
     */
    #[Test]
    public function omarsPrivateDraftShowsToOmarAndToNoOneElse(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_AT);
        $omarsDraft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($omarsDraft, $this->addMedia('ready'), 0, 'Omar private shot', null);

        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['Round 0 · Report · by Mia · 0 pins', 'Round 1 · review by Lena · open', 'Draft round · only you can see this'],
            $page->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()),
        );
        self::assertCount(1, $page->filter(self::ROUNDS.' article#round-'.$omarsDraft.'.round-card--private'));

        foreach (['Lena', 'Mia', 'Sara'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
            self::assertResponseStatusCodeSame(200, $person);
            self::assertSame(
                ['Round 0 · Report · by Mia · 0 pins', 'Round 1 · review by Lena · open'],
                $page->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()),
                $person,
            );
            $content = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString($omarsDraft, $content, $person);
            self::assertStringNotContainsString('Omar private shot', $content, $person);
            self::assertStringNotContainsString('only you can see this', $content, $person);
        }
    }

    #[Test]
    public function aViewerWhoMayChangeNoPinSeesTheChecklistWithNoForm(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $reportShot = $this->addScreenshot($this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT), $this->addMedia('ready'), 0, 'Cart', null);
        $checklist = $this->addRound($ticketId, 1, 'checklist', 'Omar', self::SHARED_AT);
        $this->addCheckItem($checklist, $this->addPin($reportShot, 1, '10.00', '10.00', 'A note', 'Mia'), 'open');
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $card = $page->filter(self::ROUNDS.' article#round-'.$checklist);
        self::assertSame(['Open'], $card->filter('.check-state')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertCount(0, $card->filter('form, button, input'));
    }

    /**
     * No command makes a checklist round without items, so one is broken data: the frame fails rather than show it as a
     * round with nothing to check.
     */
    #[Test]
    public function aChecklistRoundWithNoItemsIsAnErrorNotAnEmptyCard(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'checklist', 'Omar', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->catchExceptions(false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/\\AChecklist round [0-9a-f-]{36} has no items\.\z/');
        $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
    }

    #[Test]
    public function aSharedDraftShowsToEveryoneAndTheViewersOwnUnsharedDraftIsPrivateWhileAnotherPersonsIsAbsent(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $shared = $this->addRound($ticketId, 3, 'draft', 'Omar', self::SHARED_AT);
        $this->addScreenshot($shared, $this->addMedia('ready'), 0, 'Shared self-check', null, 'looks_right');
        $this->addScreenshot($shared, $this->addMedia('ready'), 1, 'Second look', null, 'not_right');
        $omarDraft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($omarDraft, $this->addMedia('ready'), 0, 'Omar own draft shot', null);
        $lenaDraft = $this->addRound($ticketId, null, 'draft', 'Lena', null);
        $this->addScreenshot($lenaDraft, $this->addMedia('ready'), 0, self::LENA_DRAFT_TITLE, null);

        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $cards = $page->filter(self::ROUNDS.' .round-card');
        self::assertSame(
            ['Round 3 · self-check by Omar · open · 2 screenshots', 'Draft round · only you can see this'],
            $cards->filter('.round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()),
        );
        self::assertSame(['0 of 1 look right'], $cards->filter('.round-card__status')->each(static fn (Crawler $status): string => $status->text()), 'The shared round is a row with no status line.');
        self::assertSame(['PRIVATE'], $cards->filter('.round-card__private')->each(static fn (Crawler $tag): string => $tag->text()));
        self::assertCount(1, $page->filter(self::ROUNDS.' .round-card--private'));
        self::assertCount(1, $page->filter(self::ROUNDS.' .round-card--private .draft-round-editor'), 'The editor is in the private card only.');
        self::assertCount(1, $page->filter(self::ROUNDS.' .draft-round-editor'));
        self::assertCount(0, $page->filter(self::ROUNDS.' .checklist'), 'A self-check round has no items, so no checklist.');
        self::assertStringNotContainsString(self::LENA_DRAFT_TITLE, (string) $this->client->getResponse()->getContent());

        foreach (['Mia', 'Sara'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
            self::assertResponseStatusCodeSame(200);
            self::assertSame(['Round 3 · self-check by Omar · open · 2 screenshots'], $page->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()), $person);
            self::assertCount(0, $page->filter(self::ROUNDS.' .round-card--private, '.self::ROUNDS.' .draft-round-editor'), $person);
            self::assertStringNotContainsString('Omar own draft shot', (string) $this->client->getResponse()->getContent(), $person);
            self::assertStringNotContainsString(self::LENA_DRAFT_TITLE, (string) $this->client->getResponse()->getContent(), $person);
        }
    }

    #[Test]
    public function aTeamOnlyTicketsFrameShowsItsRoundsToTheTeamLeadAndAnswers404ForAManagerOutsideTheTeam(): void
    {
        $ticketId = $this->addTicket('SHOP-1', 'team');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Lena', self::SHARED_AT);
        $this->addScreenshot($roundId, $this->addMedia('ready'), 0, self::TEAM_ONLY_TITLE, null);

        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString(self::TEAM_ONLY_TITLE, (string) $this->client->getResponse()->getContent());

        $this->logIn($this->client, $this->people['Raj']);
        $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::TEAM_ONLY_TITLE, (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->client->getCrawler()->filter('.round-card, .screenshot, img.screenshot__image'));
    }

    #[Test]
    public function theCardsOfAViewerWhoMayNotSeeATeamOnlyTicketAreTheEmptyStateWithNoRoundText(): void
    {
        $ticketId = $this->addTicket('SHOP-1', 'team');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Lena', self::SHARED_AT);
        $this->addScreenshot($roundId, $this->addMedia('ready'), 0, self::TEAM_ONLY_TITLE, null);
        $this->addPin($this->addScreenshot($roundId, $this->addMedia('ready'), 1, null, null), 1, '10.00', '10.00', 'Hidden note', 'Lena');
        $roundCards = self::getContainer()->get(RoundCards::class);
        $transactions = new TenantTransactions(self::getContainer()->get(EntityManagerInterface::class), self::getContainer()->get(TenantContext::class));
        $sees = TicketVisibility::for($this->acmeFoods, $this->people['Lena']->id(), [$this->shop], false, null);
        $doesNotSee = TicketVisibility::for($this->acmeFoods, $this->people['Raj']->id(), [], false, null);
        $assigneeId = $this->people['Omar']->id();

        $seen = $transactions->rolledBackAs($this->acmeFoods->toString(), static fn (): RoundsSectionView => $roundCards->forTicket(new TicketId($ticketId), $sees, $assigneeId, new \DateTimeImmutable(self::NOW)));
        $unseen = $transactions->rolledBackAs($this->acmeFoods->toString(), static fn (): RoundsSectionView => $roundCards->forTicket(new TicketId($ticketId), $doesNotSee, $assigneeId, new \DateTimeImmutable(self::NOW)));

        self::assertCount(1, $seen->cards, 'The same rounds are there for a viewer who may see the ticket.');
        self::assertSame([], $unseen->cards);
        self::assertSame(0, $unseen->olderRoundsNotShown);
        self::assertStringNotContainsString(self::TEAM_ONLY_TITLE, serialize($unseen));
    }

    #[Test]
    public function aKeptAiPinKeepsItsAiMarkOnThePictureAndInTheList(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $screenshotId = $this->addScreenshot($roundId, $this->addMedia('ready'), 0, null, null);
        $this->addPin($screenshotId, 1, '10.00', '10.00', 'A human pin', 'Mia');
        $this->addPin($screenshotId, 2, '20.00', '20.00', 'A kept AI pin', 'Mia', 'ai', 'kept');
        $this->addPin($screenshotId, 3, '30.00', '30.00', 'An AI pin waiting', 'Mia', 'ai', 'suggested');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2', '3'], $page->filter(self::ROUNDS.' .pin--ai')->each(static fn (Crawler $pin): string => (string) $pin->attr('data-pin-number')));
        self::assertSame(['2', '3'], $page->filter(self::ROUNDS.' .pin-list__item--ai')->each(static fn (Crawler $item): string => (string) $item->attr('data-pin-number')));
        self::assertCount(3, $page->filter(self::ROUNDS.' .pin-list__item'));
        self::assertSame('Round 0 · Report · by Mia · 3 pins', $page->filter(self::ROUNDS.' .round-card__headline')->text());
    }

    #[Test]
    public function aRoundOfSomeoneWhoIsNoMemberIsByAFormerMember(): void
    {
        $this->people['Noor'] = $this->createUserWithoutOrganization('Noor');
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Noor', self::SHARED_AT);
        $this->addRound($ticketId, 1, 'review', 'Noor', self::SHARED_AT);
        $this->addRound($ticketId, 2, 'draft', 'Noor', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['Round 0 · Report · by a former member · 0 pins', 'Round 1 · review by a former member · open', 'Round 2 · findings by a former member · open · no screenshots'],
            $page->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()),
        );
        self::assertStringNotContainsString('Noor', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function onlyThePrivateDraftHasADescriptionAndRowsAndCardsAreTheSharedCardComponent(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'draft', 'Lena', self::SHARED_AT);
        $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addRound($ticketId, 2, 'review', 'Lena', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['Check your own work before you pass it on. Screenshots, pins, notes and a verdict per screenshot. Nobody else sees this until you share it.'],
            $page->filter(self::ROUNDS.' .round-card__description')->each(static fn (Crawler $description): string => $description->text()),
            'A row is one line, with no description.',
        );
        self::assertCount(3, $page->filter(self::ROUNDS.' article.card.round-card'));
    }

    #[Test]
    public function aDraftWithNoScreenshotsShowsNoStatusAndSaysItIsEmpty(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, $page->filter(self::ROUNDS.' .round-card'));
        self::assertCount(0, $page->filter(self::ROUNDS.' .round-card__status'));
        self::assertStringNotContainsString('0 of 0', (string) $this->client->getResponse()->getContent());
        self::assertSame([DraftRoundEditor::EMPTY_NOTE], $page->filter(self::ROUNDS.' .round-card__empty')->each(static fn (Crawler $note): string => $note->text()), 'The report has none.');
    }

    #[Test]
    public function aDraftsScreenshotsAreNumberedWithAVerdictTagThatSaysItInWordsAndTheReportsAreNot(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addScreenshot($report, $this->addMedia('ready'), 0, 'Report shot', null, 'looks_right');
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($draft, $this->addMedia('ready'), 2, 'Third', null, 'not_right');
        $this->addScreenshot($draft, $this->addMedia('ready'), 0, 'First', null);
        $this->addScreenshot($draft, $this->addMedia('ready'), 1, 'Second', null, 'looks_right');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $labels = $page->filter(self::ROUNDS.' .round-card__screenshot-label');
        self::assertCount(3, $labels, 'Only the draft numbers its screenshots.');
        self::assertSame(
            ['Screenshot 1 No verdict yet', 'Screenshot 2 Looks right', 'Screenshot 3 Not right yet'],
            $labels->each(static fn (Crawler $label): string => $label->text()),
        );
        self::assertSame(
            ['verdict-tag--none', 'verdict-tag--looks-right', 'verdict-tag--not-right'],
            $labels->filter('.tag')->each(static fn (Crawler $tag): string => array_values(array_filter(explode(' ', (string) $tag->attr('class')), static fn (string $class): bool => str_starts_with($class, 'verdict-tag--')))[0]),
        );
        self::assertCount(0, $page->filter(self::ROUNDS.' .round-card')->eq(0)->filter('.tag'), 'The report card is first.');
    }

    #[Test]
    public function screenshotTitlesAreHeadingsOneLevelBelowTheCardHeading(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addScreenshot($report, $this->addMedia('ready'), 0, 'Report shot', null);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Round 0 · Report · by Mia · 0 pins'], $page->filter(self::ROUNDS.' h3')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertSame(['Report shot'], $page->filter(self::ROUNDS.' h4.screenshot__title')->each(static fn (Crawler $heading): string => $heading->text()));
    }

    #[Test]
    public function aPinWithABlankNoteReadsNoNoteInTheList(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $screenshotId = $this->addScreenshot($report, $this->addMedia('ready'), 0, null, null);
        $this->addPin($screenshotId, 1, '10.00', '10.00', '', 'Mia');
        $this->addPin($screenshotId, 2, '30.00', '30.00', 'A real note', 'Mia');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['(no note)', 'A real note'], $page->filter(self::ROUNDS.' .pin-list__item .pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
    }

    #[Test]
    public function theRoundsControllerKeepsTheTicketRouteAndTheNotFoundTextOfTheTicketPage(): void
    {
        self::assertSame(self::constantOf(TicketShowController::class, 'ROUTE'), self::constantOf(RoundsController::class, 'TICKET_ROUTE'));
        self::assertSame(self::constantOf(TicketShowController::class, 'NOT_FOUND'), self::constantOf(RoundsController::class, 'NOT_FOUND'));
        self::assertSame('/t/SHOP-1', self::getContainer()->get('router')->generate(RoundsController::TICKET_ROUTE, ['key' => 'SHOP-1']));
    }

    /**
     * The section's own reads do not grow with the rounds: one report with one picture and pin and one findings round
     * with one item, then the same ticket with those, 5 more rounds by different people with 3 pictures each, three of
     * them findings rounds with an item per pin, and the viewer's draft read the same number of queries. The findings
     * round of the first read is there because a shared draft adds the items query and the ticket's facts, once.
     */
    #[Test]
    public function theSectionReadsTheSameNumberOfQueriesWhateverTheNumberOfRounds(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addPin($this->addScreenshot($report, $this->addMedia('ready'), 0, 'First', null), 1, '10.00', '10.00', 'A note', 'Mia');
        $findings = $this->addRound($ticketId, 6, 'draft', 'Mia', self::SHARED_AT);
        $this->addCheckItem($findings, $this->addPin($this->addScreenshot($findings, $this->addMedia('ready'), 0, null, null), 1, '10.00', '10.00', 'A finding', 'Mia'), 'open');
        $this->logIn($this->client, $this->people['Omar']);
        $this->queryCountOf(self::ROUNDS_PAGE);
        $twoRoundsQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);
        self::assertCount(1, $this->client->getCrawler()->filter(self::ROUNDS.' .checklist'));

        foreach (['Lena', 'Omar', 'Raj', 'Sara', 'Mia'] as $number => $owner) {
            $isDraft = 0 === $number % 2;
            $round = $this->addRound($ticketId, $number + 1, $isDraft ? 'draft' : 'review', $owner, self::SHARED_AT);
            foreach ([0, 1, 2] as $position) {
                $screenshot = $this->addScreenshot($round, $this->addMedia('ready'), $position, null, null);
                $pinId = $this->addPin($screenshot, 1, '10.00', '10.00', 'A note', $owner);
                if ($isDraft) {
                    $this->addCheckItem($round, $pinId, 'open');
                }
            }
        }
        $own = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($own, $this->addMedia('pending'), 0, null, null);
        $manyRoundsQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);

        self::assertCount(8, $this->client->getCrawler()->filter(self::ROUNDS.' .round-card'));
        self::assertCount(6, $this->client->getCrawler()->filter(self::ROUNDS.' article.round-card--row'));
        self::assertCount(2, $this->client->getCrawler()->filter(self::ROUNDS.' figure.screenshot'), "The report's and the own draft's: rows show none.");
        self::assertCount(4, $this->client->getCrawler()->filter(self::ROUNDS.' .checklist'));
        self::assertCount(10, $this->client->getCrawler()->filter(self::ROUNDS.' .checklist__row button'), 'Omar may mark each finding fixed.');
        self::assertSame($twoRoundsQueryCount, $manyRoundsQueryCount);
    }

    #[Test]
    public function pastTheCapTheNewestTwentyNumberedRoundsShowWithTheCountOfOlderOnesLeftOut(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        for ($number = 1; $number <= 22; ++$number) {
            $this->addRound($ticketId, ($number * 7) % 22 + 1, 'review', 'Lena', self::SHARED_AT);
        }
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $headlines = $page->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text());
        self::assertCount(21, $headlines);
        self::assertSame('Round 0 · Report · by Mia · 0 pins', $headlines[0]);
        self::assertSame('Round 3 · review by Lena · open', $headlines[1], 'Rounds 1 and 2 are the oldest two of 22.');
        self::assertSame('Round 22 · review by Lena · open', $headlines[20]);
        self::assertSame('2 older rounds are not shown.', $page->filter(self::ROUNDS.' .rounds__older')->text());
    }

    #[Test]
    public function oneRoundPastTheCapIsNamedInTheSingular(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        for ($number = 1; $number <= 21; ++$number) {
            $this->addRound($ticketId, $number, 'review', 'Lena', self::SHARED_AT);
        }
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertSame('1 older round is not shown.', $page->filter(self::ROUNDS.' .rounds__older')->text());
    }

    #[Test]
    public function aTicketWithinTheCapShowsNoNoteAboutOlderRounds(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        for ($number = 1; $number <= 20; ++$number) {
            $this->addRound($ticketId, $number, 'review', 'Lena', self::SHARED_AT);
        }
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertCount(20, $page->filter(self::ROUNDS.' .round-card'));
        self::assertCount(0, $page->filter(self::ROUNDS.' .rounds__older'));
    }

    #[Test]
    public function aViewerWhoMayNotSeeATeamOnlyTicketGetsNotFoundWithNoRoundText(): void
    {
        $ticketId = $this->addTicket('SHOP-1', 'team');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Lena', self::SHARED_AT);
        $this->addScreenshot($roundId, $this->addMedia('ready'), 0, self::TEAM_ONLY_TITLE, null);
        $this->logIn($this->client, $this->people['Raj']);

        foreach ([[], self::TURBO_FRAME_HEADERS] as $headers) {
            $this->client->request('GET', self::ROUNDS_PAGE, [], [], $headers);

            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString(RoundsController::NOT_FOUND, (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString(self::TEAM_ONLY_TITLE, (string) $this->client->getResponse()->getContent());
            self::assertCount(0, $this->client->getCrawler()->filter('.round-card, .screenshot, img'));
        }
    }

    #[Test]
    public function someoneOfAnotherOrganizationGetsNotFoundForATicketOfAcmeFoods(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addScreenshot($roundId, $this->addMedia('ready'), 0, self::TEAM_ONLY_TITLE, null);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);

        $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::TEAM_ONLY_TITLE, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogIn(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);

        $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(302);
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string}> a key, as in the URL, of no ticket
     */
    public static function keysOfNoTicket(): iterable
    {
        yield 'a number no ticket has' => ['SHOP-99'];
        yield 'a malformed key' => ['not-a-key'];
        yield 'the key in lower case' => ['shop-1'];
        yield 'a trailing newline' => ['SHOP-1%0A'];
        yield 'a number past the integer range' => ['SHOP-99999999999999999999'];
    }

    #[Test]
    #[DataProvider('keysOfNoTicket')]
    public function aKeyOfNoTicketIsNotFound(string $key): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', '/t/'.$key.'/rounds', [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->client->getCrawler()->filter('.round-card'));
    }

    #[Test]
    public function withoutTheFrameHeaderTheRoundsComeAsAPageOfTheirOwnWithABackLinkAndTheFrameAsTheOnlyFrame(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Rounds', $page->filter('h1')->text());
        self::assertSame('/t/SHOP-1', $page->filter('a.ticket__back')->attr('href'));
        self::assertSame(['Round 0 · Report · by Mia · 0 pins'], $page->filter('.round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()));
        self::assertSame('Turbo-Frame', $this->client->getResponse()->headers->get('Vary'));
    }

    #[Test]
    public function aCardShowsAtMostTenPicturesLazilyAndSaysHowManyMoreAreNotShown(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $shared = $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_AT);
        $own = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        foreach (range(0, RoundCards::MAX_PICTURES_PER_CARD + 1) as $position) {
            $this->addScreenshot($report, $this->addMedia('ready'), $position, 'Report shot '.$position, null);
        }
        foreach (range(0, RoundCards::MAX_PICTURES_PER_CARD) as $position) {
            $this->addScreenshot($shared, $this->addMedia('ready'), $position, 'Shared shot '.$position, null);
        }
        foreach (range(0, RoundCards::MAX_PICTURES_PER_CARD - 1) as $position) {
            $this->addScreenshot($own, $this->addMedia('ready'), $position, 'Own shot '.$position, null);
        }
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $cards = $page->filter(self::ROUNDS.' .round-card');
        self::assertCount(3, $cards);
        self::assertSame([10, 0, 10], $cards->each(static fn (Crawler $card): int => $card->filter('img.screenshot__image')->count()), 'The shared round is a row.');
        self::assertSame(
            ['2 more screenshots are not shown.'],
            $cards->filter('.round-card__more')->each(static fn (Crawler $note): string => $note->text()),
            'A card that shows every picture has no note.',
        );
        self::assertSame(['lazy'], array_unique($cards->filter('img.screenshot__image')->each(static fn (Crawler $image): ?string => $image->attr('loading'))));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Report shot 9', $content);
        self::assertStringNotContainsString('Report shot 10', $content);
        self::assertStringNotContainsString('Report shot 11', $content);
        self::assertStringNotContainsString('Shared shot 0', $content);
        self::assertStringContainsString('Own shot 9', $content);
        self::assertSame('Round 0 · Report · by Mia · 0 pins', $cards->eq(0)->filter('.round-card__headline')->text());
        self::assertSame('Round 1 · self-check by Omar · open · 11 screenshots', $cards->eq(1)->filter('.round-card__headline')->text(), 'The row counts every screenshot.');
    }

    /**
     * A round with a picture and a pin adds five queries to a frame with no round: its screenshots, their pins, the
     * owners' membership and names, and the signing. Whatever the number of rounds, the count stays that
     * (theSectionReadsTheSameNumberOfQueriesWhateverTheNumberOfRounds).
     */
    #[Test]
    public function aFrameWithARoundReadsFiveMoreQueriesThanOneWithNone(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->logIn($this->client, $this->people['Omar']);
        $this->queryCountOf(self::ROUNDS_PAGE);
        $noRoundQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);

        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addPin($this->addScreenshot($report, $this->addMedia('ready'), 0, 'First', null), 1, '10.00', '10.00', 'A note', 'Mia');
        $oneRoundQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);

        self::assertSame(self::EMPTY_FRAME_QUERIES, $noRoundQueryCount);
        self::assertSame(self::EMPTY_FRAME_QUERIES + 5, $oneRoundQueryCount, 'The rounds, screenshots, pins, the owners\' names and the signing.');
    }

    /**
     * The checklist card adds a pinned number of queries, the same for one item and five: its items in one, and
     * the ticket's facts in one, which ChecklistPermission MARK_FIXED and CHECK are both decided on.
     */
    #[Test]
    public function aChecklistAddsTheSameQueriesForOneItemAndForFive(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $reportShot = $this->addScreenshot($this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT), $this->addMedia('ready'), 0, 'Cart', null);
        $pinIds = array_map(fn (int $number): string => $this->addPin($reportShot, $number, '10.00', '10.00', 'Note '.$number, 'Mia'), [1, 2, 3, 4, 5]);
        $this->logIn($this->client, $this->people['Omar']);
        $this->queryCountOf(self::ROUNDS_PAGE);
        $noChecklistQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);

        $checklist = $this->addRound($ticketId, 1, 'checklist', 'Omar', self::SHARED_AT);
        $this->addCheckItem($checklist, $pinIds[0], 'open');
        $oneItemQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);
        self::assertCount(1, $this->client->getCrawler()->filter(self::ROUNDS.' .checklist__row'));

        foreach (\array_slice($pinIds, 1) as $pinId) {
            $this->addCheckItem($checklist, $pinId, 'open');
        }
        $fiveItemsQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);

        self::assertCount(5, $this->client->getCrawler()->filter(self::ROUNDS.' .checklist__row'));
        self::assertCount(5, $this->client->getCrawler()->filter(self::ROUNDS.' .checklist__row button'), 'Each row shows Omar its Mark fixed.');
        self::assertSame($noChecklistQueryCount + self::CHECKLIST_QUERIES, $oneItemQueryCount);
        self::assertSame($oneItemQueryCount, $fiveItemsQueryCount);
    }

    /**
     * @param class-string $class
     */
    private static function constantOf(string $class, string $name): mixed
    {
        return new \ReflectionClassConstant($class, $name)->getValue();
    }

    private function queryCountOf(string $uri): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', $uri, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);

        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }
}
