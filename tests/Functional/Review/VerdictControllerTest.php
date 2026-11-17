<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\VerdictRefused;
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
 * The verdicts of the review round page's side panel on Acme Foods' ticket SHOP-1, round 2 (P05.T7): Fixed, Still
 * wrong and Undo on round 1's pins, and Save verdict on a screen area, through the real firewall, voters, CSRF and
 * database. Mia is the Shop team's tester and Lena its lead, who may give verdicts; Omar, a member and the ticket's
 * assignee, Raj, a manager, and Sara, an admin, may see the round but not give them. The clock is fixed on
 * 6 Nov 2026, before today.
 */
final class VerdictControllerTest extends WebTestCase
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
    private const string SHARED_EARLIER = '2026-11-05 09:00:00+00';
    private const string SHARED_TODAY = '2026-11-06 09:12:00+00';
    private const string ROUND_PAGE = '/t/SHOP-1/rounds/2';
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    /**
     * The session's user, the transaction and its tenant; the actor's roles and teams, the settings, the ticket, its
     * steps, team and names and its visibility check, the round, its areas and pins; the round and verdict voters'
     * ticket facts; the command's lock, pin read, conditional update and outbox event; then the visibility check, the
     * round, its areas and pins again for the answer, and the commit.
     */
    private const int ONE_VERDICT_QUERIES = 38;

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $beforeShot;
    private string $roundTwo;

    /** @var array<int, string> round 1's pins to check on "Per product", by number */
    private array $pins = [];
    private string $aiSuggestion;
    private string $afterPin;
    private string $perProduct;
    private string $totals;
    private string $otherRoundsArea;

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
        $this->seedRoundTwoOfTwoAreas();
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
    public function theTesterAndTheLeadMarkAPinFixedUndoItAndMarkItStillWrong(string $person): void
    {
        $this->logIn($this->client, $this->people[$person]);
        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertCount(0, $page->filter('[autofocus]'), 'Only the stream of a verdict moves the focus.');
        $firstPin = $page->filter('.round-panel__pin')->first();
        self::assertSame(['Fixed', 'Still wrong'], $firstPin->filter('form button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame('/t/SHOP-1/rounds/2/pins/'.$this->pins[1].'/verdict', $firstPin->filter('form')->attr('action'));
        self::assertSame('true', $firstPin->filter('form')->attr('data-turbo'));
        self::assertSame(
            ['Mia · tester', 'Lena · team lead', 'Omar'],
            $page->filter('.round-panel__pin .round-panel__author')->each(static fn (Crawler $line): string => $line->text()),
        );

        $this->postAsTurbo($this->pinUrl(1), [VerdictController::VERDICT_FIELD => 'fixed']);

        self::assertSame('Pin 1 marked fixed.', $this->toastOfTheStream(200));
        $stream = $this->streamedPanel();
        self::assertSame('Fixed', $stream->filter('.round-panel__pin')->first()->filter('.tag')->text());
        self::assertSame(['Undo'], $stream->filter('.round-panel__pin')->first()->filter('form button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame(['round-panel-undo-'.$this->pins[1]], $this->autofocusedIdsOf($stream), 'Focus goes to the Undo that replaced the button clicked.');
        self::assertSame([1 => 'fixed', 2 => null, 3 => null, 4 => null], $this->pinVerdicts());

        $this->postAsTurbo('/t/SHOP-1/rounds/2/pins/'.strtoupper($this->pins[1]).'/undo', []);

        self::assertSame('Verdict on pin 1 undone.', $this->toastOfTheStream(200));
        self::assertSame(['round-panel-fixed-'.$this->pins[1]], $this->autofocusedIdsOf($this->streamedPanel()), 'Focus goes back to Fixed.');
        self::assertSame([1 => null, 2 => null, 3 => null, 4 => null], $this->pinVerdicts());

        $this->client->request('POST', $this->pinUrl(1), [VerdictController::CSRF_FIELD => $this->verdictToken(), VerdictController::VERDICT_FIELD => 'still_wrong']);

        self::assertSame('Pin 1 marked still wrong.', $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2?area=0'));
        self::assertSame([1 => 'still_wrong', 2 => null, 3 => null, 4 => null], $this->pinVerdicts());
    }

    #[Test]
    #[DataProvider('peopleWhoMayGiveVerdicts')]
    public function theTesterAndTheLeadSaveAnAreaVerdictWithANote(string $person): void
    {
        $this->logIn($this->client, $this->people[$person]);
        $page = $this->client->request('GET', self::ROUND_PAGE.'?area=1');
        $form = $page->filter('form.round-panel__verdict-form');
        self::assertSame('Your verdict on Totals', $page->filter('#round-panel-verdict')->text());
        self::assertSame(['Pass', 'Needs fix', 'Question'], $form->filter('label.round-panel__choice')->each(static fn (Crawler $chip): string => trim($chip->text())));
        self::assertSame('What should Lena know?', $form->filter('textarea')->attr('placeholder'));
        self::assertSame('Then: Lena, team lead', $form->filter('.round-panel__then')->text());
        self::assertSame('Save verdict', $form->filter('button[type=submit]')->text());

        $this->postAsTurbo($this->areaUrl($this->totals), [VerdictController::VERDICT_FIELD => 'needs_fix', VerdictController::NOTE_FIELD => '  Sum is off by a cent  ']);

        self::assertSame('Verdict saved. Lena can see it.', $this->toastOfTheStream(200));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="round-area-tabs">', $content);
        $tabs = new Crawler($content);
        self::assertSame(
            ['Per product 3 pins', 'Totals Needs fix'],
            $tabs->filter('template')->first()->filter('a.area-tab')->each(static fn (Crawler $tab): string => (string) preg_replace('/\s+/', ' ', trim($tab->text()))),
        );
        $panel = $this->streamedPanel();
        self::assertSame('Needs fix', $panel->filter('.round-panel__verdict .tag')->text());
        self::assertSame('Saved by '.$person, $panel->filter('.round-panel__author')->text());
        self::assertSame('needs_fix', $panel->filter('input[type=radio][checked]')->attr('value'));
        self::assertSame(['round-panel-save'], $this->autofocusedIdsOf($panel));
        self::assertSame(
            [$this->perProduct => [null, null, null], $this->totals => ['needs_fix', 'Sum is off by a cent', $this->people[$person]->id()->toString()]],
            $this->areaVerdicts(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoMayNotGiveVerdicts(): iterable
    {
        yield 'the assignee, a member' => ['Omar'];
        yield 'a manager' => ['Raj'];
        yield 'an admin' => ['Sara'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayNotGiveVerdicts')]
    public function someoneWhoMayNotGiveVerdictsSeesThePanelReadOnlyAndIsRefusedWithNothingSaved(string $person): void
    {
        $this->setPinVerdict($this->beforeShot, 2, 'fixed');
        $before = $this->storedState();
        $this->logIn($this->client, $this->people[$person]);

        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.round-panel form, .round-panel button, .round-panel__next'));
        self::assertSame('Verdict on Per product', $page->filter('#round-panel-verdict')->text());

        $this->postAsTurbo($this->pinUrl(1), [VerdictController::VERDICT_FIELD => 'fixed']);
        self::assertSame(VerdictController::ONLY_TESTER_OR_LEAD, $this->toastOfTheStream(403));
        $this->postAsTurbo('/t/SHOP-1/rounds/2/pins/'.$this->pins[2].'/undo', []);
        self::assertSame(VerdictController::ONLY_TESTER_OR_LEAD, $this->toastOfTheStream(403));
        $this->postAsTurbo($this->areaUrl($this->perProduct), [VerdictController::VERDICT_FIELD => 'pass']);
        self::assertSame(VerdictController::ONLY_TESTER_OR_LEAD, $this->toastOfTheStream(403));
        $this->client->request('POST', $this->areaUrl($this->perProduct), [VerdictController::CSRF_FIELD => $this->verdictToken(), VerdictController::VERDICT_FIELD => 'pass']);
        self::assertSame(VerdictController::ONLY_TESTER_OR_LEAD, $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2'));

        self::assertSame($before, $this->storedState());
    }

    #[Test]
    public function aSaveWithoutAVerdictOrWithAnUnknownOneGetsThePrototypesToastAndSavesNothing(): void
    {
        $before = $this->storedState();
        $this->logIn($this->client, $this->people['Mia']);

        $this->postAsTurbo($this->areaUrl($this->perProduct), [VerdictController::NOTE_FIELD => 'Looks fine']);
        self::assertSame('Pick Pass, Needs fix or Question first.', $this->toastOfTheStream(422));
        $this->postAsTurbo($this->areaUrl($this->perProduct), [VerdictController::VERDICT_FIELD => 'maybe']);
        self::assertSame('Pick Pass, Needs fix or Question first.', $this->toastOfTheStream(422));
        $this->postAsTurbo($this->pinUrl(1), [VerdictController::VERDICT_FIELD => 'maybe']);
        self::assertSame(VerdictRefused::UNKNOWN_PIN_VERDICT, $this->toastOfTheStream(422));
        $this->postAsTurbo('/t/SHOP-1/rounds/2/pins/'.$this->pins[1].'/undo', []);
        self::assertSame('This pin has no verdict to undo.', $this->toastOfTheStream(422));

        self::assertSame($before, $this->storedState());
    }

    #[Test]
    public function aFormWithoutAValidTokenSavesNothing(): void
    {
        $before = $this->storedState();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->pinUrl(1), [VerdictController::VERDICT_FIELD => 'fixed'], [], self::TURBO_FORM_HEADERS);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastOfTheStream(422));
        $this->client->request('POST', $this->areaUrl($this->perProduct), [VerdictController::CSRF_FIELD => $this->csrfTokenFor($this->client, 'another_form'), VerdictController::VERDICT_FIELD => 'pass'], [], self::TURBO_FORM_HEADERS);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastOfTheStream(422));

        self::assertSame($before, $this->storedState());
    }

    #[Test]
    public function aMalformedOrUnknownPinOrAreaIdIsNotFoundAndChangesNothing(): void
    {
        $before = $this->storedState();
        $this->logIn($this->client, $this->people['Mia']);
        $token = $this->verdictToken();
        $notPinsToCheck = [
            'a malformed id' => 'not-a-uuid',
            'an unknown id' => Uuid::v7()->toRfc4122(),
            'an AI suggestion nobody kept' => $this->aiSuggestion,
            "a pin on this round's own picture" => $this->afterPin,
        ];
        $notAreasOfTheRound = ['a malformed id' => 'not-a-uuid', 'an unknown id' => Uuid::v7()->toRfc4122(), "another round's area" => $this->otherRoundsArea];

        foreach ($notPinsToCheck as $case => $pinId) {
            foreach (['/verdict', '/undo'] as $action) {
                $this->client->request('POST', '/t/SHOP-1/rounds/2/pins/'.$pinId.$action, [VerdictController::CSRF_FIELD => $token, VerdictController::VERDICT_FIELD => 'fixed']);
                self::assertResponseStatusCodeSame(404, $case.$action);
                self::assertStringContainsString(VerdictRefused::PIN_NOT_FOUND, (string) $this->client->getResponse()->getContent());
            }
        }
        foreach ($notAreasOfTheRound as $case => $areaId) {
            $this->client->request('POST', $this->areaUrl($areaId), [VerdictController::CSRF_FIELD => $token, VerdictController::VERDICT_FIELD => 'pass']);
            self::assertResponseStatusCodeSame(404, $case);
            self::assertStringContainsString(VerdictRefused::AREA_NOT_FOUND, (string) $this->client->getResponse()->getContent());
        }
        foreach (['/t/SHOP-1/rounds/9/areas/'.$this->perProduct.'/verdict', '/t/SHOP-77/rounds/2/areas/'.$this->perProduct.'/verdict'] as $url) {
            $this->client->request('POST', $url, [VerdictController::CSRF_FIELD => $token, VerdictController::VERDICT_FIELD => 'pass']);
            self::assertResponseStatusCodeSame(404, $url);
        }

        self::assertSame($before, $this->storedState());
    }

    #[Test]
    public function aTeamOnlyTicketsRoundIsNotFoundForSomeoneWhoMayNotSeeIt(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET visibility = 'team' WHERE organization_id = ? AND id = ?",
            [$organizationId, $ticketId],
        ));
        $before = $this->storedState();
        $this->logIn($this->client, $this->people['Raj']);

        $this->client->request('POST', $this->areaUrl($this->perProduct), [VerdictController::CSRF_FIELD => $this->verdictToken(), VerdictController::VERDICT_FIELD => 'pass'], [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(VerdictController::ONLY_TESTER_OR_LEAD, (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->storedState());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogInAndSavesNothing(): void
    {
        $before = $this->storedState();

        $this->client->request('POST', $this->areaUrl($this->perProduct), [VerdictController::VERDICT_FIELD => 'pass']);

        self::assertResponseRedirects('/login', 302);
        self::assertSame($before, $this->storedState());
    }

    #[Test]
    public function noVerdictChangesTheTicketsStepOrVersion(): void
    {
        $ticketBefore = $this->ticketRow();
        $this->logIn($this->client, $this->people['Mia']);

        $this->postAsTurbo($this->pinUrl(1), [VerdictController::VERDICT_FIELD => 'fixed']);
        $this->postAsTurbo($this->pinUrl(2), [VerdictController::VERDICT_FIELD => 'still_wrong']);
        $this->postAsTurbo('/t/SHOP-1/rounds/2/pins/'.$this->pins[2].'/undo', []);
        $this->postAsTurbo($this->areaUrl($this->perProduct), [VerdictController::VERDICT_FIELD => 'pass']);
        $this->postAsTurbo($this->areaUrl($this->totals), [VerdictController::VERDICT_FIELD => 'pass']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->perProduct => ['pass', null, $this->people['Mia']->id()->toString()], $this->totals => ['pass', null, $this->people['Mia']->id()->toString()]], $this->areaVerdicts());
        self::assertSame($ticketBefore, $this->ticketRow());
    }

    #[Test]
    public function theMatchingVerbIsOfferedOnlyOnceEveryAreaHasAVerdictAndNeverMovesTheTicket(): void
    {
        $ticketBefore = $this->ticketRow();
        $this->logIn($this->client, $this->people['Mia']);

        $this->postAsTurbo($this->areaUrl($this->perProduct), [VerdictController::VERDICT_FIELD => 'pass']);
        self::assertCount(0, $this->streamedPanel()->filter('.round-panel__next'), 'Totals has no verdict yet.');

        $this->postAsTurbo($this->areaUrl($this->totals), [VerdictController::VERDICT_FIELD => 'pass']);
        $offer = $this->streamedPanel()->filter('.round-panel__next');
        self::assertSame('Every area passed.', $offer->filter('.round-panel__next-sentence')->text());
        self::assertSame('Pass it on SHOP-1', $offer->filter('a')->text());
        self::assertSame('/t/SHOP-1#ticket-next-step', $offer->filter('a')->attr('href'));
        $ticketPage = $this->client->request('GET', '/t/SHOP-1');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $ticketPage->filter('#ticket-next-step'), 'The link lands on the ticket\'s Your next step box.');

        $this->postAsTurbo($this->areaUrl($this->totals), [VerdictController::VERDICT_FIELD => 'needs_fix']);
        $offer = $this->streamedPanel()->filter('.round-panel__next');
        self::assertSame('At least one area needs a fix.', $offer->filter('.round-panel__next-sentence')->text());
        self::assertSame('Needs changes on SHOP-1', $offer->filter('a')->text());
        self::assertSame('/t/SHOP-1#ticket-next-step', $offer->filter('a')->attr('href'));

        $this->postAsTurbo($this->areaUrl($this->perProduct), [VerdictController::VERDICT_FIELD => 'question']);
        $offer = $this->streamedPanel()->filter('.round-panel__next');
        self::assertSame('An area has a question for Lena.', $offer->filter('.round-panel__next-sentence')->text());
        self::assertSame('Ask Lena', $offer->filter('a')->text());
        $leadId = $this->people['Lena']->id()->toString();
        self::assertSame('/t/SHOP-1/thread?ask='.$leadId.'#thread-composer-SHOP-1', $offer->filter('a')->attr('href'));
        $thread = $this->client->request('GET', '/t/SHOP-1/thread?ask='.$leadId);
        self::assertResponseStatusCodeSame(200);
        $composer = $thread->filter('form#thread-composer-SHOP-1');
        self::assertCount(1, $composer, 'The link lands on the question composer.');
        self::assertStringContainsString('thread-composer', (string) $thread->filter('.thread__content')->attr('data-controller'));
        self::assertNull($composer->filter('[data-thread-composer-target="askChip"]')->attr('disabled'), 'Mia may ask, so thread-composer can start the question.');
        self::assertSame(['Lena'], $composer->filter('[data-thread-composer-target="person"][data-user-id="'.$leadId.'"]')->each(static fn (Crawler $person): string => trim($person->text())), 'The ask parameter names a person the composer offers.');

        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertSame('Ask Lena', $page->filter('.round-panel__next a')->text(), 'The page offers it too.');
        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertCount(0, $page->filter('.round-panel__next'), 'Only someone who may give verdicts is offered the verb.');
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertCount(1, $page->filter('form.round-panel__verdict-form'), 'The lead may still give verdicts.');
        self::assertCount(0, $page->filter('.round-panel__next'), 'The lead is not offered a question to themself.');
        $this->postAsTurbo($this->areaUrl($this->totals), [VerdictController::VERDICT_FIELD => 'pass']);
        self::assertCount(0, $this->streamedPanel()->filter('.round-panel__next'), 'Nor after a verdict of their own.');
        self::assertSame($ticketBefore, $this->ticketRow());
    }

    #[Test]
    public function aClosedRoundShowsNoVerdictFormsAndRefusesAVerdict(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $roundId = $this->roundTwo;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE rvw_round SET state = 'closed' WHERE organization_id = ? AND id = ?",
            [$organizationId, $roundId],
        ));
        $before = $this->storedState();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.round-panel form'));

        $this->postAsTurbo($this->areaUrl($this->perProduct), [VerdictController::VERDICT_FIELD => 'pass']);
        self::assertSame('This round is closed, so its verdicts no longer change.', $this->toastOfTheStream(422));
        $this->postAsTurbo($this->pinUrl(1), [VerdictController::VERDICT_FIELD => 'fixed']);
        self::assertSame('This round is closed, so its verdicts no longer change.', $this->toastOfTheStream(422));
        self::assertSame($before, $this->storedState());
    }

    #[Test]
    public function aPlainFormWithoutAVerdictComesBackToTheAreaWithThePrototypesToastAndSavesNothing(): void
    {
        $before = $this->storedState();
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', $this->areaUrl($this->totals), [VerdictController::CSRF_FIELD => $this->verdictToken(), VerdictController::NOTE_FIELD => 'Looks fine']);

        self::assertSame('Pick Pass, Needs fix or Question first.', $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2?area=1'));
        self::assertSame($before, $this->storedState());
    }

    /**
     * A ticket no team owns has no team lead to see a verdict, so the page shows no forms, and VerdictVoter refuses a
     * posted one before VerdictController would name the lead in its toast.
     */
    #[Test]
    public function aTicketWithNoTeamLeadShowsNoVerdictFormsAndRefusesAPostedVerdict(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $ticketId = $this->ticketId;
        // Mia stays the ticket's own tester, so only the missing team (and its lead) can be why she is refused.
        $miaId = $this->people['Mia']->id()->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET team_id = NULL, tester_id = ? WHERE organization_id = ? AND id = ?',
            [$miaId, $organizationId, $ticketId],
        ));
        $before = $this->storedState();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Verdict on Per product', $page->filter('#round-panel-verdict')->text());
        self::assertCount(0, $page->filter('.round-panel form, .round-panel__next'));

        $this->postAsTurbo($this->areaUrl($this->perProduct), [VerdictController::VERDICT_FIELD => 'pass']);
        self::assertSame(VerdictController::ONLY_TESTER_OR_LEAD, $this->toastOfTheStream(403));
        $this->postAsTurbo($this->pinUrl(1), [VerdictController::VERDICT_FIELD => 'fixed']);
        self::assertSame(VerdictController::ONLY_TESTER_OR_LEAD, $this->toastOfTheStream(403));
        self::assertSame($before, $this->storedState());
    }

    #[Test]
    public function oneVerdictReadsAPinnedNumberOfQueries(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $token = $this->verdictToken();
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $this->client->request('POST', $this->pinUrl(1), [VerdictController::CSRF_FIELD => $token, VerdictController::VERDICT_FIELD => 'fixed'], [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }
        self::assertSame(self::ONE_VERDICT_QUERIES, $queryCount);
    }

    /**
     * Round 1 shot "Per product" with Mia's, Lena's and Omar's pins and an AI suggestion nobody kept, and "Totals" with
     * none; round 2 has both areas, before round 1's pictures, with no verdict yet; round 3 has an area of its own.
     */
    private function seedRoundTwoOfTwoAreas(): void
    {
        $this->ticketId = $this->addTicket('SHOP-1');
        $roundOne = $this->addRound($this->ticketId, 1, 'review', 'Omar', self::SHARED_EARLIER);
        $this->beforeShot = $this->addScreenshot($roundOne, $this->addMedia('ready'), 0, 'Before product', null);
        $this->pins[1] = $this->addPin($this->beforeShot, 1, '10.00', '10.00', 'Planted wrong', 'Mia');
        $this->pins[2] = $this->addPin($this->beforeShot, 2, '20.00', '30.00', 'Header cut', 'Lena');
        $this->pins[3] = $this->addPin($this->beforeShot, 3, '30.00', '30.00', 'Footer gap', 'Omar');
        $this->aiSuggestion = $this->addPin($this->beforeShot, 4, '50.00', '50.00', 'Suggested guess', 'Mia', 'ai', 'suggested');
        $beforeTotals = $this->addScreenshot($roundOne, $this->addMedia('ready'), 1, 'Before totals', null);

        $this->roundTwo = $this->addRound($this->ticketId, 2, 'review', 'Omar', self::SHARED_TODAY);
        $afterProduct = $this->addScreenshot($this->roundTwo, $this->addMedia('ready'), 0, 'After product', null);
        $this->afterPin = $this->addPin($afterProduct, 1, '40.00', '40.00', 'Still cut', 'Mia');
        $afterTotals = $this->addScreenshot($this->roundTwo, $this->addMedia('ready'), 1, 'After totals', null);
        $this->perProduct = $this->addArea($this->roundTwo, 'Per product', 0, $this->beforeShot, $afterProduct, null, null);
        $this->totals = $this->addArea($this->roundTwo, 'Totals', 1, $beforeTotals, $afterTotals, null, null);

        $roundThree = $this->addRound($this->ticketId, 3, 'review', 'Omar', self::SHARED_TODAY);
        $laterShot = $this->addScreenshot($roundThree, $this->addMedia('ready'), 0, 'Later product', null);
        $this->otherRoundsArea = $this->addArea($roundThree, 'Per product', 0, $afterProduct, $laterShot, null, null);
    }

    private function pinUrl(int $number): string
    {
        return '/t/SHOP-1/rounds/2/pins/'.$this->pins[$number].'/verdict';
    }

    private function areaUrl(string $areaId): string
    {
        return '/t/SHOP-1/rounds/2/areas/'.$areaId.'/verdict';
    }

    private function verdictToken(): string
    {
        return $this->csrfTokenFor($this->client, VerdictController::csrfIdOf('SHOP-1'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function postAsTurbo(string $url, array $fields): void
    {
        $this->client->request('POST', $url, [VerdictController::CSRF_FIELD => $this->verdictToken(), ...$fields], [], self::TURBO_FORM_HEADERS);
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
     * The side panel of the last answer's stream.
     */
    private function streamedPanel(): Crawler
    {
        self::assertResponseStatusCodeSame(200);
        $content = (string) $this->client->getResponse()->getContent();
        $matched = preg_match('#<turbo-stream action="replace" target="round-panel"><template>(.*?)</template></turbo-stream>#s', $content, $panel);
        self::assertSame(1, $matched);

        return new Crawler($panel[1]);
    }

    /**
     * @return list<string> the ids of the controls with autofocus, which Turbo focuses after the stream
     */
    private function autofocusedIdsOf(Crawler $panel): array
    {
        return $panel->filter('[autofocus]')->each(static fn (Crawler $control): string => (string) $control->attr('id'));
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
     * @return array<int, string|null> round 1's pin verdicts on "Per product", by number
     */
    private function pinVerdicts(): array
    {
        $verdicts = [];
        $screenshotId = $this->beforeShot;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($screenshotId, &$verdicts): int {
            /** @var list<array{number: int, verdict: string|null}> $rows */
            $rows = $migrator->fetchAllAssociative('SELECT number, verdict FROM rvw_pin WHERE screenshot_id = ? ORDER BY number', [$screenshotId]);
            foreach ($rows as $row) {
                $verdicts[$row['number']] = $row['verdict'];
            }

            return 0;
        });
        self::assertNotEmpty($verdicts);

        return $verdicts;
    }

    /**
     * @return array<string, array{string|null, string|null, string|null}> round 2's areas' verdict, note and decider, by id
     */
    private function areaVerdicts(): array
    {
        $verdicts = [];
        $roundId = $this->roundTwo;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundId, &$verdicts): int {
            /** @var list<array{id: string, verdict: string|null, note: string|null, decided_by: string|null}> $rows */
            $rows = $migrator->fetchAllAssociative('SELECT id, verdict, note, decided_by FROM rvw_area WHERE round_id = ? ORDER BY position', [$roundId]);
            foreach ($rows as $row) {
                $verdicts[$row['id']] = [$row['verdict'], $row['note'], $row['decided_by']];
            }

            return 0;
        });
        self::assertNotEmpty($verdicts);

        return $verdicts;
    }

    /**
     * @return array<string, mixed> the ticket's step and version as stored
     */
    private function ticketRow(): array
    {
        $row = [];
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($ticketId, &$row): int {
            $row = $migrator->fetchAssociative('SELECT step_id, version, updated_at FROM tkt_ticket WHERE id = ?', [$ticketId]);
            $row = false === $row ? [] : $row;

            return 0;
        });
        self::assertNotSame([], $row);

        return $row;
    }

    /**
     * @return array{pins: array<int, string|null>, areas: array<string, array{string|null, string|null, string|null}>, ticket: array<string, mixed>}
     */
    private function storedState(): array
    {
        return ['pins' => $this->pinVerdicts(), 'areas' => $this->areaVerdicts(), 'ticket' => $this->ticketRow()];
    }
}
