<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Domain\GeneralNote;
use App\Review\Domain\ReviewedRound;
use App\Review\UI\Controller\GeneralNoteController;
use App\Review\UI\Controller\RefusedWithToast;
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
 * "General notes on this round" (ADR-0048, note of 2026-11-22) on Acme Foods' ticket SHOP-1, whose round 1 is Omar's
 * shared draft of two screenshots and round 2 another draft of his. Everyone who may see the ticket reads the notes and
 * adds one while the round is open: Mia, the Shop team's tester, Omar, the assignee, Raj, a manager, Sara, an admin.
 * Through the real firewall, voters, CSRF and database; the clock is fixed on 6 Nov 2026, before today.
 */
final class GeneralNoteControllerTest extends WebTestCase
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
    private const string ROUND_PAGE = '/t/SHOP-1/rounds/1';
    private const string NOTES_URL = '/t/SHOP-1/rounds/1/notes';
    private const string SECTION = '#'.GeneralNoteController::SECTION_ID;

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $roundOne;
    private string $roundTwo;

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

        $ticketId = $this->addTicket('SHOP-1');
        $this->roundOne = $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_TODAY);
        $this->addScreenshot($this->roundOne, $this->addMedia('ready'), 0, 'Cart page', null);
        $this->addScreenshot($this->roundOne, $this->addMedia('ready'), 1, 'Checkout', null);
        $this->roundTwo = $this->addRound($ticketId, 2, 'draft', 'Omar', self::SHARED_TODAY);
        $this->addScreenshot($this->roundTwo, $this->addMedia('ready'), 0, 'Elsewhere', null);
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
    public static function peopleWhoMaySeeTheTicket(): iterable
    {
        yield 'the tester' => ['Mia'];
        yield 'the assignee' => ['Omar'];
        yield 'the lead' => ['Lena'];
        yield 'a manager' => ['Raj'];
        yield 'an admin' => ['Sara'];
    }

    #[Test]
    #[DataProvider('peopleWhoMaySeeTheTicket')]
    public function anyoneWhoMaySeeTheTicketAddsANoteWithAnOptionalVerdict(string $person): void
    {
        $this->logIn($this->client, $this->people[$person]);
        $page = $this->client->request('GET', self::ROUND_PAGE.'?screenshot=2');

        self::assertResponseStatusCodeSame(200);
        $section = $page->filter(self::SECTION);
        self::assertSame('General notes on this round', $section->filter('h2')->text());
        self::assertSame('No general notes yet.', $section->filter('.round-panel__empty')->text());
        $form = $section->filter('form');
        self::assertSame(self::NOTES_URL, $form->attr('action'));
        self::assertSame(['Pass', 'Needs fix', 'Question'], $form->filter('.round-panel__choice')->each(static fn (Crawler $chip): string => trim($chip->text())));
        self::assertCount(0, $form->filter('input[type="radio"][checked]'), 'No chip is chosen at first.');
        self::assertSame('Add a note for the whole round', $form->filter('label.round-panel__label')->text());
        self::assertSame("Something the screenshots don't show, or a change for the whole round", $form->filter('textarea')->attr('placeholder'));
        self::assertSame('Add note', trim($form->filter('button[type="submit"]')->text()));

        $this->postNote('needs_fix', "  The footer is gone on every page.\r\n", '2');

        self::assertSame(GeneralNoteController::NOTE_ADDED, $this->toastAfterTheRedirect(self::ROUND_PAGE.'?screenshot=2'.self::SECTION));
        self::assertSame(
            [[$person, 'Needs fix', 'now', 'The footer is gone on every page.']],
            $this->listedNotes($this->client->getCrawler()),
        );
        self::assertSame([[$this->roundOne, $this->people[$person]->id()->toString(), 'needs_fix', 'The footer is gone on every page.']], $this->storedNotes());
    }

    #[Test]
    public function everyoneReadsTheNotesNewestFirstAndANoteWithoutAVerdictHasNoChip(): void
    {
        // Out of order, and two at the same time, which the newer id puts first.
        $this->addNote($this->roundOne, 'Mia', 'question', 'Is the banner meant to stay?', '2026-11-06 10:00:00+00', '0197a3c0-0000-7000-8000-00000000b002');
        $this->addNote($this->roundOne, 'Lena', null, 'Fonts look off on every screen.', '2026-11-06 11:30:00+00', '0197a3c0-0000-7000-8000-00000000b001');
        $this->addNote($this->roundOne, 'Raj', 'pass', 'Fine by me.', '2026-11-06 11:30:00+00', '0197a3c0-0000-7000-8000-00000000b003');
        $this->addNote($this->roundTwo, 'Mia', 'pass', 'A note on round 2.', '2026-11-06 11:59:00+00', '0197a3c0-0000-7000-8000-00000000b004');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                ['Raj', 'Pass', '30 min ago', 'Fine by me.'],
                ['Lena', null, '30 min ago', 'Fonts look off on every screen.'],
                ['Mia', 'Question', '2 h ago', 'Is the banner meant to stay?'],
            ],
            $this->listedNotes($page),
        );
        self::assertCount(0, $page->filter(self::SECTION.' .round-panel__empty'));
    }

    #[Test]
    public function aRefusedNoteSavesNothingAndSaysWhy(): void
    {
        $this->addNote($this->roundOne, 'Mia', null, 'Already here.', '2026-11-06 10:00:00+00', '0197a3c0-0000-7000-8000-00000000b001');
        $before = $this->storedNotes();
        $this->logIn($this->client, $this->people['Mia']);

        $this->postNote('pass', " \r\n ", null);
        self::assertSame(GeneralNote::NOTE_MISSING, $this->toastAfterTheRedirect(self::ROUND_PAGE.self::SECTION));
        $this->postNote('pass', str_repeat('é', GeneralNote::NOTE_MAX_LENGTH + 1), null);
        self::assertSame(GeneralNote::NOTE_TOO_LONG, $this->toastAfterTheRedirect(self::ROUND_PAGE.self::SECTION));
        $this->postNote('fixed', 'A note', null);
        self::assertSame(GeneralNote::UNKNOWN_VERDICT, $this->toastAfterTheRedirect(self::ROUND_PAGE.self::SECTION));
        $this->client->request('POST', self::NOTES_URL, [GeneralNoteController::CSRF_FIELD => $this->csrfTokenFor($this->client, 'another_form'), GeneralNoteController::NOTE_FIELD => 'A note']);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastAfterTheRedirect(self::ROUND_PAGE.self::SECTION));

        self::assertSame($before, $this->storedNotes());
    }

    #[Test]
    public function aTurboFormIsRefusedWithA422ToastSoTheTypedNoteStays(): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::NOTES_URL, [
            GeneralNoteController::CSRF_FIELD => $this->noteToken(),
            GeneralNoteController::NOTE_FIELD => '',
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(GeneralNote::NOTE_MISSING, (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->storedNotes());
    }

    #[Test]
    public function aClosedRoundKeepsItsNotesReadOnly(): void
    {
        $this->addNote($this->roundOne, 'Mia', 'needs_fix', 'The footer is gone.', '2026-11-06 10:00:00+00', '0197a3c0-0000-7000-8000-00000000b001');
        $this->closeRound($this->roundOne);
        $before = $this->storedNotes();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([['Mia', 'Needs fix', '2 h ago', 'The footer is gone.']], $this->listedNotes($page));
        self::assertCount(0, $page->filter(self::SECTION.' form, '.self::SECTION.' textarea'));

        $this->postNote(null, 'One more', null);

        self::assertSame(ReviewedRound::CLOSED_FOR_GENERAL_NOTES, $this->toastAfterTheRedirect(self::ROUND_PAGE.self::SECTION));
        self::assertSame($before, $this->storedNotes());
    }

    #[Test]
    public function someoneOfAnotherOrganizationGets404AndSavesNothing(): void
    {
        $this->addNote($this->roundOne, 'Mia', null, 'Only for Acme Foods.', '2026-11-06 10:00:00+00', '0197a3c0-0000-7000-8000-00000000b001');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $before = $this->storedNotes();
        $this->logIn($this->client, $ben);

        $this->client->request('GET', self::ROUND_PAGE);
        self::assertResponseStatusCodeSame(404);
        // Not the note's text: the debug error page lists the test's own seeding queries with their values.
        self::assertCount(0, $this->client->getCrawler()->filter(self::SECTION));
        $this->client->request('POST', self::NOTES_URL, [GeneralNoteController::CSRF_FIELD => $this->noteToken(), GeneralNoteController::NOTE_FIELD => 'Hello']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, $this->storedNotes());
    }

    #[Test]
    public function aRoundTheTicketHasNotIsNotFoundAndAnAnonymousVisitorIsSentToLogIn(): void
    {
        $this->client->request('POST', self::NOTES_URL, [GeneralNoteController::CSRF_FIELD => 'any', GeneralNoteController::NOTE_FIELD => 'Hello']);
        self::assertResponseRedirects('/login', 302);

        $this->logIn($this->client, $this->people['Mia']);
        $this->client->request('POST', '/t/SHOP-1/rounds/9/notes', [GeneralNoteController::CSRF_FIELD => $this->noteToken(), GeneralNoteController::NOTE_FIELD => 'Hello']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/t/SHOP-1/rounds/x1/notes', [GeneralNoteController::CSRF_FIELD => $this->noteToken(), GeneralNoteController::NOTE_FIELD => 'Hello']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame([], $this->storedNotes());
    }

    private function noteToken(): string
    {
        return $this->csrfTokenFor($this->client, GeneralNoteController::csrfIdOf('SHOP-1'));
    }

    private function postNote(?string $verdict, string $note, ?string $screenshot): void
    {
        $fields = [GeneralNoteController::CSRF_FIELD => $this->noteToken(), GeneralNoteController::NOTE_FIELD => $note];
        if (null !== $verdict) {
            $fields[GeneralNoteController::VERDICT_FIELD] = $verdict;
        }
        if (null !== $screenshot) {
            $fields[GeneralNoteController::RETURN_SCREENSHOT_FIELD] = $screenshot;
        }
        $this->client->request('POST', self::NOTES_URL, $fields);
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
     * @return list<array{string, string|null, string, string}> each listed note's author, verdict chip, time and text
     */
    private function listedNotes(Crawler $page): array
    {
        $items = $page->filter(self::SECTION.' .round-general-notes__item');
        self::assertGreaterThan(0, $items->count(), 'The section lists no note.');

        return $items->each(static fn (Crawler $item): array => [
            $item->filter('.round-general-notes__author')->text(),
            $item->filter('.round-general-notes__verdict')->count() > 0 ? $item->filter('.round-general-notes__verdict')->text() : null,
            $item->filter('.round-general-notes__when')->text(),
            $item->filter('.round-general-notes__note')->text(),
        ]);
    }

    private function addNote(string $roundId, string $author, ?string $verdict, string $note, string $createdAt, string $id): void
    {
        $organizationId = $this->acmeFoods->toString();
        $authorId = $this->people[$author]->id()->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO rvw_round_note (organization_id, id, round_id, author_id, verdict, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$organizationId, $id, $roundId, $authorId, $verdict, $note, $createdAt],
        ));
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed}> each stored note's round, author, verdict and text, oldest first
     */
    private function storedNotes(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                'SELECT round_id, author_id, verdict, note FROM rvw_round_note WHERE organization_id = ? ORDER BY created_at, id',
                [$organizationId],
            );

            return 0;
        });

        return array_map(static fn (array $row): array => [$row[0], $row[1], $row[2], $row[3]], $rows);
    }
}
