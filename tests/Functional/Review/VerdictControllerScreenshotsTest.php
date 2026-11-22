<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\VerdictRefused;
use App\Review\Domain\ReviewedRound;
use App\Review\UI\Controller\RefusedWithToast;
use App\Review\UI\Controller\VerdictController;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
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
 * The verdict on each screenshot of a shared draft (ADR-0048, note of 2026-11-22) on Acme Foods' ticket SHOP-1, whose
 * round 1 is Omar's shared draft of two screenshots, the second retaken once, and round 2 another draft of his: "Your
 * verdict on …" with Pass, Needs fix or Question and a note for Mia, the Shop team's tester, and Lena, its lead; "Verdict
 * on …" to read for Omar, the assignee, Raj, a manager, and Sara, an admin. Through the real firewall, voters, CSRF and
 * database; the clock is fixed on 6 Nov 2026, before today.
 */
final class VerdictControllerScreenshotsTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string SHARED_TODAY = '2026-11-06 09:12:00+00';
    private const string SECOND_SCREENSHOT_PAGE = '/t/SHOP-1/rounds/1?screenshot=2';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $roundOne;
    private string $firstShot;
    private string $earlierTry;
    private string $retake;
    private string $otherDraftsShot;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople('member');
        $organizationId = $this->acmeFoods->toString();
        $miaId = $this->people['Mia']->id()->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_team_member SET job = 'tester' WHERE organization_id = ? AND user_id = ?",
            [$organizationId, $miaId],
        ));
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $stepId = $this->stepId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));

        $this->ticketId = $this->addTicket('SHOP-1');
        $this->roundOne = $this->addRound($this->ticketId, 1, 'draft', 'Omar', self::SHARED_TODAY);
        $this->firstShot = $this->addScreenshot($this->roundOne, $this->addMedia('ready'), 0, 'Cart page', null);
        $this->earlierTry = $this->addScreenshot($this->roundOne, $this->addMedia('ready'), 1, 'Checkout first try', null);
        $this->retake = $this->addScreenshot($this->roundOne, $this->addMedia('ready'), 2, 'Checkout', null);
        $this->markRetakeOf($this->retake, $this->earlierTry);
        $roundTwo = $this->addRound($this->ticketId, 2, 'draft', 'Omar', self::SHARED_TODAY);
        $this->otherDraftsShot = $this->addScreenshot($roundTwo, $this->addMedia('ready'), 0, 'Elsewhere', null);
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoMayGiveVerdicts(): iterable
    {
        yield 'the tester' => ['Mia'];
        yield 'the lead' => ['Lena'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayGiveVerdicts')]
    public function theTesterAndTheLeadGiveTheShownScreenshotAVerdictWithANote(string $person): void
    {
        $this->logIn($this->client, $this->people[$person]);
        $page = $this->client->request('GET', self::SECOND_SCREENSHOT_PAGE);

        self::assertResponseStatusCodeSame(200);
        $section = $page->filter('#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID);
        self::assertSame('Your verdict on Checkout', $section->filter('h2')->text());
        self::assertSame('No verdict yet.', $section->filter('.round-panel__empty')->text());
        $form = $section->filter('form');
        self::assertSame($this->verdictUrl($this->retake), $form->attr('action'), 'The latest try, the one shown, not the earlier one.');
        self::assertSame(['Pass', 'Needs fix', 'Question'], $form->filter('.round-panel__choice')->each(static fn (Crawler $chip): string => trim($chip->text())));
        self::assertSame('Note (optional)', $form->filter('label.round-panel__label')->text());
        self::assertSame('Then: Lena, team lead', $form->filter('.round-panel__then')->text());

        $this->client->request('POST', $this->verdictUrl($this->retake), [
            VerdictController::CSRF_FIELD => $this->verdictToken(),
            VerdictController::VERDICT_FIELD => 'needs_fix',
            VerdictController::NOTE_FIELD => "  The total still shows the old price.\r\n",
        ]);

        self::assertSame('Verdict saved. Lena can see it.', $this->toastAfterTheRedirect(self::SECOND_SCREENSHOT_PAGE.'#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID));
        $section = $this->client->getCrawler()->filter('#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID);
        self::assertSame(
            ['Needs fix', 'The total still shows the old price.', 'Saved by '.$person, 'needs_fix'],
            [
                $section->filter('.round-panel__verdict')->text(),
                $section->filter('.round-panel__note')->text(),
                $section->filter('.round-panel__author')->text(),
                $section->filter('input[type="radio"][checked]')->attr('value'),
            ],
        );
        self::assertSame('The total still shows the old price.', $section->filter('textarea')->text(), 'The form starts from the saved note.');
        self::assertSame([[$this->retake, 'needs_fix', 'The total still shows the old price.', $this->people[$person]->id()->toString()]], $this->storedVerdicts());

        $this->client->request('GET', '/t/SHOP-1/rounds/1?screenshot=1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('No verdict yet.', $this->client->getCrawler()->filter('#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID.' .round-panel__empty')->text(), 'Each screenshot has its own verdict.');
    }

    #[Test]
    public function aSecondVerdictTakesThePlaceOfTheFirstAndABlankNoteIsNone(): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $this->postVerdict($this->firstShot, 'question', 'Is the sum meant to include tax?');
        $this->logIn($this->client, $this->people['Lena']);

        $this->postVerdict($this->firstShot, 'pass', " \n ");

        self::assertSame('Verdict saved. Lena can see it.', $this->toastAfterTheRedirect('/t/SHOP-1/rounds/1?screenshot=1#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID));
        self::assertSame([[$this->firstShot, 'pass', null, $this->people['Lena']->id()->toString()]], $this->storedVerdicts());
        self::assertCount(0, $this->client->getCrawler()->filter('#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID.' .round-panel__note'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoMayNotGiveVerdicts(): iterable
    {
        yield 'the assignee' => ['Omar'];
        yield 'a manager' => ['Raj'];
        yield 'an admin' => ['Sara'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayNotGiveVerdicts')]
    public function everyoneElseReadsTheVerdictWithItsNoteAndIsRefusedWithTheReason(string $person): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $this->postVerdict($this->retake, 'needs_fix', 'The total still shows the old price.');
        $before = $this->storedVerdicts();
        $this->logIn($this->client, $this->people[$person]);

        $page = $this->client->request('GET', self::SECOND_SCREENSHOT_PAGE);

        self::assertResponseStatusCodeSame(200);
        $section = $page->filter('#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID);
        self::assertSame(
            ['Verdict on Checkout', 'Needs fix', 'The total still shows the old price.', 'Saved by Mia'],
            [$section->filter('h2')->text(), $section->filter('.round-panel__verdict')->text(), $section->filter('.round-panel__note')->text(), $section->filter('.round-panel__author')->text()],
        );
        self::assertCount(0, $section->filter('form, textarea, input'));

        $this->postVerdict($this->retake, 'pass', 'Looks right');

        self::assertSame(VerdictController::ONLY_TESTER_OR_LEAD, $this->toastAfterTheRedirect(self::SECOND_SCREENSHOT_PAGE.'#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID));
        self::assertSame($before, $this->storedVerdicts());
    }

    #[Test]
    public function aVerdictWithoutAChipOrWithANoteOverTheCapOrAnExpiredFormSavesNothing(): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $this->postVerdict($this->retake, '', 'A note');
        self::assertSame(VerdictRefused::PICK_A_VERDICT, $this->toastAfterTheRedirect(self::SECOND_SCREENSHOT_PAGE.'#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID));
        $this->postVerdict($this->retake, 'pass', str_repeat('é', VerdictController::NOTE_MAX_LENGTH + 1));
        self::assertSame('A note holds at most 1,000 characters.', $this->toastAfterTheRedirect(self::SECOND_SCREENSHOT_PAGE.'#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID));
        $this->client->request('POST', $this->verdictUrl($this->retake), [VerdictController::CSRF_FIELD => $this->csrfTokenFor($this->client, 'another_form'), VerdictController::VERDICT_FIELD => 'pass']);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastAfterTheRedirect(self::SECOND_SCREENSHOT_PAGE.'#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID));

        self::assertSame([], $this->storedVerdicts());
    }

    #[Test]
    public function aClosedDraftTakesNoVerdictAndShowsNoForm(): void
    {
        $this->closeRound($this->roundOne);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::SECOND_SCREENSHOT_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Verdict on Checkout', $page->filter('#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID.' h2')->text());
        self::assertCount(0, $page->filter('#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID.' form'));

        $this->postVerdict($this->retake, 'pass', null);

        self::assertSame(ReviewedRound::CLOSED, $this->toastAfterTheRedirect(self::SECOND_SCREENSHOT_PAGE.'#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID));
        self::assertSame([], $this->storedVerdicts());
    }

    #[Test]
    public function aScreenshotTheRoundPageDoesNotShowIsNotFoundAndNothingIsSaved(): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $notShown = [
            'a malformed id' => 'not-a-uuid',
            'an unknown id' => Uuid::v7()->toRfc4122(),
            'an earlier try a retake replaced' => $this->earlierTry,
            "another round's screenshot" => $this->otherDraftsShot,
        ];

        foreach ($notShown as $case => $screenshotId) {
            $this->postVerdict($screenshotId, 'pass', null);
            self::assertResponseStatusCodeSame(404, $case);
        }
        $this->client->request('POST', '/t/SHOP-1/rounds/9/screenshots/'.$this->retake.'/verdict', [VerdictController::CSRF_FIELD => $this->verdictToken(), VerdictController::VERDICT_FIELD => 'pass']);
        self::assertResponseStatusCodeSame(404, 'a round the ticket has not');

        self::assertSame([], $this->storedVerdicts());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogInAndSavesNothing(): void
    {
        $this->client->request('POST', $this->verdictUrl($this->retake), [VerdictController::CSRF_FIELD => 'any', VerdictController::VERDICT_FIELD => 'pass']);

        self::assertResponseRedirects('/login', 302);
        self::assertSame([], $this->storedVerdicts());
    }

    #[Test]
    public function theReportsPageShowsNoScreenshotVerdict(): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $report = $this->addRound($this->ticketId, 0, 'report', 'Lena', '2026-11-05 09:00:00+00');
        $this->addScreenshot($report, $this->addMedia('ready'), 0, 'Reported', null);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/0');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID));
        self::assertCount(1, $this->client->request('GET', '/t/SHOP-1/rounds/1')->filter('#'.VerdictController::SCREENSHOT_VERDICT_SECTION_ID), 'The shared draft beside it has one.');
    }

    private function verdictUrl(string $screenshotId): string
    {
        return '/t/SHOP-1/rounds/1/screenshots/'.$screenshotId.'/verdict';
    }

    private function verdictToken(): string
    {
        return $this->csrfTokenFor($this->client, VerdictController::csrfIdOf('SHOP-1'));
    }

    private function postVerdict(string $screenshotId, string $verdict, ?string $note): void
    {
        $fields = [VerdictController::CSRF_FIELD => $this->verdictToken(), VerdictController::VERDICT_FIELD => $verdict];
        if (null !== $note) {
            $fields[VerdictController::NOTE_FIELD] = $note;
        }
        $this->client->request('POST', $this->verdictUrl($screenshotId), $fields);
    }

    private function toastAfterTheRedirect(string $location): string
    {
        self::assertResponseStatusCodeSame(303);
        self::assertSame($location, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $toast = $page->filter('.toast-region [data-toast-message-value]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed}> each stored verdict's screenshot, verdict, note and who saved it
     */
    private function storedVerdicts(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                'SELECT screenshot_id, verdict, note, decided_by FROM rvw_screenshot_verdict WHERE organization_id = ? ORDER BY decided_at, screenshot_id',
                [$organizationId],
            );

            return 0;
        });

        return array_map(static fn (array $row): array => [$row[0], $row[1], $row[2], $row[3]], $rows);
    }
}
