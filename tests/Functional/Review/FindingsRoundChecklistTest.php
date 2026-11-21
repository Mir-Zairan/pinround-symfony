<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\UI\Checklist\ChecklistCard;
use App\Review\UI\Controller\ChecklistController;
use App\Review\UI\DraftRoundController;
use App\Review\UI\RoundsController;
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
 * A findings round's check items on Acme Foods' ticket SHOP-1 (ADR-0044, FR-RND-17, P13.T2), through the real
 * firewall, voters, CSRF and database. Omar, a member, is the ticket's assignee; Mia is the Shop team's tester and the
 * ticket's stored tester; Lena leads the team, Raj is a manager and Sara an admin. Mia shares her draft with two pins
 * through its Share form in each test, so the items are the ones the share made. The report's checklist round, with
 * one fixed item of its own, stays beside it and must never change. The clock is fixed on 6 Nov 2026, before today.
 */
final class FindingsRoundChecklistTest extends WebTestCase
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
    private const string SHARED_AT = '2026-11-05 09:00:00+00';
    private const string ROUNDS_PAGE = '/t/SHOP-1/rounds';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => 'ticket-rounds'];
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    private const string CUT_OFF_NOTE = 'Discount line is cut off';
    private const string DOUBLE_WARNING_NOTE = 'The warning shows twice';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $checklistId;
    private string $checklistItemId;
    private string $miasDraftId;

    /** @var array<int, string> the findings round's items, by pin number, once Mia shared it */
    private array $items = [];

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
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET tester_id = ? WHERE organization_id = ? AND id = ?',
            [$miaId, $organizationId, $ticketId],
        ));
        $reportShot = $this->addScreenshot($this->addRound($this->ticketId, 0, 'report', 'Mia', self::SHARED_AT), $this->addMedia('ready'), 0, 'Cart', null);
        $this->checklistId = $this->addRound($this->ticketId, 1, 'checklist', 'Mia', self::SHARED_AT);
        $this->checklistItemId = $this->addCheckItem($this->checklistId, $this->addPin($reportShot, 1, '10.00', '10.00', 'Total is wrong', 'Mia'), 'fixed');
        $this->miasDraftId = $this->addRound($this->ticketId, null, 'draft', 'Mia', null);
        $draftShot = $this->addScreenshot($this->miasDraftId, $this->addMedia('ready'), 0, 'Small screen', null, 'not_right');
        $this->addPin($draftShot, 2, '60.00', '40.00', self::DOUBLE_WARNING_NOTE, 'Mia');
        $this->addPin($draftShot, 1, '20.00', '80.00', self::CUT_OFF_NOTE, 'Mia');
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function miasSharedDraftReadsFindingsByMiaWithAnOpenItemPerPinThatOnlyOmarMayMarkFixed(): void
    {
        $this->shareMiasDraft();

        self::assertSame([1 => 'open', 2 => 'open'], $this->storedStates());
        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->roundsFrame();
        self::assertSame(
            ['Round 0 · Report · by Mia · 1 pin', 'Round 1 · Checklist from the report', 'Round 2 · findings by Mia · open · 1 screenshot', 'Checklist'],
            $page->filter('turbo-frame#ticket-rounds .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()),
        );
        $card = $page->filter('article#round-'.$this->miasDraftId);
        self::assertSame('round-'.$this->miasDraftId.'-heading', $card->attr('aria-labelledby'));
        self::assertSame('Round 2 · findings by Mia · open · 1 screenshot', $card->filter('h3#round-'.$this->miasDraftId.'-heading')->text());
        self::assertSame(['/t/SHOP-1/rounds/2'], $card->filter('a.round-card__open')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertCount(0, $card->filter('figure.screenshot'), 'The findings round is a row: its picture is on its own page, its items stay here.');
        $checklist = $card->filter('div#checklist-'.$this->miasDraftId.'.checklist');
        self::assertCount(1, $checklist);
        self::assertSame('0 of 2 checked OK', $checklist->filter('.round-card__status')->text());
        self::assertSame(ChecklistCard::FINDINGS_DESCRIPTION, $checklist->filter('.round-card__description')->text());
        self::assertSame(
            [['1', self::CUT_OFF_NOTE, 'Open', ['Mark fixed']], ['2', self::DOUBLE_WARNING_NOTE, 'Open', ['Mark fixed']]],
            $this->rowsOf($checklist),
        );
        self::assertSame('/t/SHOP-1/checklist/'.$this->items[1].'/fixed', $checklist->filter('form')->first()->attr('action'));
        self::assertSame([['1', 'Total is wrong', 'Fixed · to check', []]], $this->rowsOf($page->filter('div#checklist-'.$this->checklistId)), "The report's checklist keeps its own row.");

        $this->logIn($this->client, $this->people['Mia']);
        self::assertSame(
            [['1', self::CUT_OFF_NOTE, 'Open', []], ['2', self::DOUBLE_WARNING_NOTE, 'Open', []]],
            $this->rowsOf($this->roundsFrame()->filter('div#checklist-'.$this->miasDraftId)),
            'Mia checks a finding only once it is marked fixed.',
        );
    }

    #[Test]
    public function omarMarksFindingsFixedWithTheFindingsChecklistStreamedBackAndThenWithARedirect(): void
    {
        $this->shareMiasDraft();
        $this->logIn($this->client, $this->people['Omar']);

        $this->postAsTurbo($this->itemUrl(1, 'fixed'));

        self::assertSame('Pin 1 marked fixed. Mia checks it.', $this->toastOfTheStream(200));
        $streamed = $this->streamedFindings();
        self::assertSame([['1', self::CUT_OFF_NOTE, 'Fixed · to check', []], ['2', self::DOUBLE_WARNING_NOTE, 'Open', ['Mark fixed']]], $this->rowsOf($streamed));
        self::assertSame('0 of 2 checked OK', $streamed->filter('.round-card__status')->text());
        self::assertSame(['checklist-fixed-'.$this->items[2]], $this->autofocusedIdsOf($streamed), 'Focus goes to the next Mark fixed.');
        self::assertSame([1 => 'fixed', 2 => 'open'], $this->storedStates());

        $this->client->request('POST', $this->itemUrl(2, 'fixed'), [ChecklistController::CSRF_FIELD => $this->checklistToken()]);

        self::assertSame('Pin 2 marked fixed. Mia checks it.', $this->toastAfterTheRedirect());
        self::assertSame([1 => 'fixed', 2 => 'fixed'], $this->storedStates());
        self::assertSame('fixed', $this->checklistItemState(), "The report's checklist item is untouched.");
    }

    #[Test]
    public function omarMayNotCheckAFindingAndMiaMayNotMarkOneFixed(): void
    {
        $this->shareMiasDraft();
        $this->logIn($this->client, $this->people['Omar']);
        $this->postAsTurbo($this->itemUrl(1, 'fixed'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->postAsTurbo($this->itemUrl(1, 'ok'));
        self::assertSame(ChecklistController::ONLY_THE_TESTER, $this->toastOfTheStream(403));
        $this->postAsTurbo($this->itemUrl(1, 'not-fixed'));
        self::assertSame(ChecklistController::ONLY_THE_TESTER, $this->toastOfTheStream(403));
        self::assertSame([['1', self::CUT_OFF_NOTE, 'Fixed · to check', []], ['2', self::DOUBLE_WARNING_NOTE, 'Open', ['Mark fixed']]], $this->rowsOf($this->roundsFrame()->filter('div#checklist-'.$this->miasDraftId)));

        $this->logIn($this->client, $this->people['Mia']);
        $this->postAsTurbo($this->itemUrl(2, 'fixed'));
        self::assertSame(ChecklistController::ONLY_THE_ASSIGNEE, $this->toastOfTheStream(403));

        self::assertSame([1 => 'fixed', 2 => 'open'], $this->storedStates());
    }

    #[Test]
    public function miaChecksOneFindingOkAndSendsTheOtherBack(): void
    {
        $this->shareMiasDraft();
        $this->logIn($this->client, $this->people['Omar']);
        foreach ([1, 2] as $pinNumber) {
            $this->postAsTurbo($this->itemUrl($pinNumber, 'fixed'));
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
        }
        $this->logIn($this->client, $this->people['Mia']);
        self::assertSame(
            [['1', self::CUT_OFF_NOTE, 'Fixed · to check', ['OK', 'Not fixed']], ['2', self::DOUBLE_WARNING_NOTE, 'Fixed · to check', ['OK', 'Not fixed']]],
            $this->rowsOf($this->roundsFrame()->filter('div#checklist-'.$this->miasDraftId)),
        );

        $this->postAsTurbo($this->itemUrl(1, 'ok'));

        self::assertSame('Pin 1 checked OK.', $this->toastOfTheStream(200));
        $streamed = $this->streamedFindings();
        self::assertSame('1 of 2 checked OK', $streamed->filter('.round-card__status')->text());
        self::assertSame(['checklist-ok-'.$this->items[2]], $this->autofocusedIdsOf($streamed));

        $this->postAsTurbo($this->itemUrl(2, 'not-fixed'));

        self::assertSame('Pin 2 sent back.', $this->toastOfTheStream(200));
        $streamed = $this->streamedFindings();
        self::assertSame([['1', self::CUT_OFF_NOTE, 'Checked OK', []], ['2', self::DOUBLE_WARNING_NOTE, 'Not fixed', []]], $this->rowsOf($streamed));
        self::assertSame(['checklist-'.$this->miasDraftId.'-heading'], $this->autofocusedIdsOf($streamed), 'With no button left, focus goes to the checklist heading.');
        self::assertSame('-1', $streamed->filter('h4')->attr('tabindex'));
        self::assertSame([1 => 'ok', 2 => 'not_fixed'], $this->storedStates());
        self::assertSame('fixed', $this->checklistItemState());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWithNeitherRole(): iterable
    {
        yield 'the lead' => ['Lena'];
        yield 'a manager' => ['Raj'];
        yield 'an admin' => ['Sara'];
    }

    #[Test]
    #[DataProvider('peopleWithNeitherRole')]
    public function someoneWhoIsNeitherAssigneeNorTesterSeesTheFindingsWithNoButtonAndIsRefusedWithTheReason(string $person): void
    {
        $this->shareMiasDraft();
        $this->logIn($this->client, $this->people['Omar']);
        $this->postAsTurbo($this->itemUrl(2, 'fixed'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->logIn($this->client, $this->people[$person]);
        self::assertSame(
            [['1', self::CUT_OFF_NOTE, 'Open', []], ['2', self::DOUBLE_WARNING_NOTE, 'Fixed · to check', []]],
            $this->rowsOf($this->roundsFrame()->filter('div#checklist-'.$this->miasDraftId)),
        );

        $this->postAsTurbo($this->itemUrl(1, 'fixed'));
        self::assertSame(ChecklistController::ONLY_THE_ASSIGNEE, $this->toastOfTheStream(403));
        $this->postAsTurbo($this->itemUrl(2, 'ok'));
        self::assertSame(ChecklistController::ONLY_THE_TESTER, $this->toastOfTheStream(403));
        $this->postAsTurbo($this->itemUrl(2, 'not-fixed'));
        self::assertSame(ChecklistController::ONLY_THE_TESTER, $this->toastOfTheStream(403));

        self::assertSame([1 => 'open', 2 => 'fixed'], $this->storedStates());
    }

    #[Test]
    public function someoneOfAnotherOrganizationGetsNotFoundAndChangesNothing(): void
    {
        $this->shareMiasDraft();
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);

        foreach ([$this->itemUrl(1, 'fixed'), $this->itemUrl(1, 'ok'), $this->itemUrl(1, 'not-fixed')] as $url) {
            $this->client->request('POST', $url, [ChecklistController::CSRF_FIELD => $this->checklistToken()], [], self::TURBO_FORM_HEADERS);

            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString(RoundsController::NOT_FOUND, (string) $this->client->getResponse()->getContent(), $url);
        }
        self::assertSame([1 => 'open', 2 => 'open'], $this->storedStates());
    }

    /**
     * Pin numbers start at 1 on each picture, so a second picture with its own pin 1 gives two rows with badge 1: each
     * row's label names its picture, and each button is described by its own row's label and note.
     */
    #[Test]
    public function twoFindingsWithPinOneOnDifferentPicturesAreToldApartByTheirPicture(): void
    {
        $secondShot = $this->addScreenshot($this->miasDraftId, $this->addMedia('ready'), 1, null, null, 'not_right');
        $this->addPin($secondShot, 1, '50.00', '50.00', 'Badge overlaps the price', 'Mia');
        $this->shareThroughTheForm();
        $itemIds = $this->itemIdsInPictureAndPinOrder();
        self::assertCount(3, $itemIds, 'The share made no item per pin, so nothing below is proven.');

        $this->logIn($this->client, $this->people['Omar']);
        $rows = $this->roundsFrame()->filter('div#checklist-'.$this->miasDraftId.' .checklist__row');

        self::assertSame(
            [
                ['1', 'Screenshot 1 · Small screen · pin 1', self::CUT_OFF_NOTE],
                ['2', 'Screenshot 1 · Small screen · pin 2', self::DOUBLE_WARNING_NOTE],
                ['1', 'Screenshot 2 · pin 1', 'Badge overlaps the price'],
            ],
            $rows->each(static fn (Crawler $row): array => [$row->filter('.checklist__number')->text(), $row->filter('.checklist__pin')->text(), $row->filter('.checklist__note')->text()]),
        );
        foreach ($rows->each(static fn (Crawler $row): Crawler => $row) as $index => $row) {
            $pinId = 'checklist-'.$this->miasDraftId.'-pin-'.$itemIds[$index];
            self::assertSame($pinId, $row->filter('.checklist__pin')->attr('id'));
            $buttons = $row->filter('button');
            self::assertCount(1, $buttons, 'Omar may mark each finding fixed.');
            self::assertSame($pinId.' checklist-'.$this->miasDraftId.'-note-'.$itemIds[$index], $buttons->attr('aria-describedby'));
        }
    }

    /**
     * Mia shares her draft through its Share form, and the items it made are read by pin number.
     */
    private function shareMiasDraft(): void
    {
        $this->shareThroughTheForm();

        $roundId = $this->miasDraftId;
        $items = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundId, &$items): int {
            /** @var list<array{number: int, id: string}> $rows */
            $rows = $migrator->fetchAllAssociative(
                'SELECT pin.number, item.id FROM rvw_check_item AS item JOIN rvw_pin AS pin ON pin.id = item.pin_id WHERE item.round_id = ? ORDER BY pin.number',
                [$roundId],
            );
            foreach ($rows as $row) {
                $items[$row['number']] = $row['id'];
            }

            return 0;
        });
        self::assertSame([1, 2], array_keys($items), 'The share made no item per pin, so nothing below is proven.');
        $this->items = $items;
    }

    private function shareThroughTheForm(): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $this->client->request(
            'POST',
            '/t/SHOP-1/rounds/'.$this->miasDraftId.'/share',
            [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))],
        );
        self::assertSame('Shared with the team as round 2.', $this->toastAfterTheRedirect());
    }

    /**
     * @return list<string> the findings round's item ids by picture position and pin number, as the checklist lists them
     */
    private function itemIdsInPictureAndPinOrder(): array
    {
        $roundId = $this->miasDraftId;
        $itemIds = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundId, &$itemIds): int {
            /** @var list<string> $ids */
            $ids = $migrator->fetchFirstColumn(
                'SELECT item.id FROM rvw_check_item AS item JOIN rvw_pin AS pin ON pin.id = item.pin_id'
                .' JOIN rvw_screenshot AS screenshot ON screenshot.id = pin.screenshot_id WHERE item.round_id = ? ORDER BY screenshot.position, pin.number',
                [$roundId],
            );
            $itemIds = $ids;

            return 0;
        });

        return $itemIds;
    }

    private function roundsFrame(): Crawler
    {
        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);

        return $page;
    }

    /**
     * @return list<array{string, string, string, list<string>}> each row's pin number, note, state chip and buttons
     */
    private function rowsOf(Crawler $checklist): array
    {
        self::assertCount(1, $checklist);

        return $checklist->filter('.checklist__row')->each(static fn (Crawler $row): array => [
            $row->filter('.checklist__number')->text(),
            $row->filter('.checklist__note')->text(),
            $row->filter('.check-state')->text(),
            $row->filter('button')->each(static fn (Crawler $button): string => $button->text()),
        ]);
    }

    /**
     * @param 'fixed'|'ok'|'not-fixed' $action
     */
    private function itemUrl(int $pinNumber, string $action): string
    {
        return '/t/SHOP-1/checklist/'.$this->items[$pinNumber].'/'.$action;
    }

    private function checklistToken(): string
    {
        return $this->csrfTokenFor($this->client, ChecklistController::csrfIdOf('SHOP-1'));
    }

    private function postAsTurbo(string $url): void
    {
        $this->client->request('POST', $url, [ChecklistController::CSRF_FIELD => $this->checklistToken()], [], self::TURBO_FORM_HEADERS);
    }

    private function toastOfTheStream(int $status): string
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame('text/vnd.turbo-stream.html; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        $matched = preg_match('/data-toast-message-value="([^"]*)"/', (string) $this->client->getResponse()->getContent(), $toast);
        self::assertSame(1, $matched);

        return html_entity_decode($toast[1], \ENT_QUOTES | \ENT_HTML5);
    }

    /**
     * The findings checklist of the last answer's stream, which replaces the one in the findings round's card.
     */
    private function streamedFindings(): Crawler
    {
        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match_all('#<turbo-stream action="replace"#', $content), 'Only the changed checklist is replaced.');
        $matched = preg_match('#<turbo-stream action="replace" target="checklist-'.$this->miasDraftId.'"><template>(.*?)</template></turbo-stream>#s', $content, $card);
        self::assertSame(1, $matched);
        $crawler = new Crawler($card[1]);
        self::assertSame('checklist-'.$this->miasDraftId, $crawler->filter('div.checklist')->attr('id'));
        self::assertSame('Checklist', $crawler->filter('h4')->text());

        return $crawler;
    }

    /**
     * @return list<string> the ids of the elements with autofocus, which Turbo focuses after the stream
     */
    private function autofocusedIdsOf(Crawler $card): array
    {
        return $card->filter('[autofocus]')->each(static fn (Crawler $control): string => (string) $control->attr('id'));
    }

    private function toastAfterTheRedirect(): string
    {
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1#ticket-rounds', $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $toast = $page->filter('.toast-region [data-toast-message-value]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * @return array<int, string> the findings round's items' states, by pin number
     */
    private function storedStates(): array
    {
        $states = [];
        $roundId = $this->miasDraftId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundId, &$states): int {
            /** @var list<array{number: int, state: string}> $rows */
            $rows = $migrator->fetchAllAssociative(
                'SELECT pin.number, item.state FROM rvw_check_item AS item JOIN rvw_pin AS pin ON pin.id = item.pin_id WHERE item.round_id = ? ORDER BY pin.number',
                [$roundId],
            );
            foreach ($rows as $row) {
                $states[$row['number']] = $row['state'];
            }

            return 0;
        });
        self::assertNotEmpty($states);

        return $states;
    }

    private function checklistItemState(): string
    {
        $itemId = $this->checklistItemId;
        $state = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($itemId, &$state): int {
            $state = $migrator->fetchOne('SELECT state FROM rvw_check_item WHERE id = ?', [$itemId]);

            return 0;
        });
        self::assertIsString($state);

        return $state;
    }
}
