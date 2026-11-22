<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\ChecklistRefused;
use App\Review\UI\Controller\ChecklistController;
use App\Review\UI\Controller\RefusedWithToast;
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
 * The checklist card's Mark fixed, OK and Not fixed on Acme Foods' ticket SHOP-1 (P05.T6), through the real firewall,
 * voters, CSRF and database. Omar, a member, is the ticket's assignee; Mia is the Shop team's tester and the ticket
 * stores no tester, so she checks it; Lena leads the team, Raj is a manager and Sara an admin. The checklist's pins
 * 1 to 5 are open, fixed, OK, not fixed and fixed. The clock is fixed on 6 Nov 2026, before today.
 */
final class ChecklistControllerTest extends WebTestCase
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
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    private const array STATES_AS_SEEDED = [1 => 'open', 2 => 'fixed', 3 => 'ok', 4 => 'not_fixed', 5 => 'fixed'];

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $checklistId;

    /** @var array<int, string> the checklist's items, by pin number */
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
        $reportShot = $this->addScreenshot($this->addRound($this->ticketId, 0, 'report', 'Mia', self::SHARED_AT), $this->addMedia('ready'), 0, 'Cart', null);
        $this->checklistId = $this->addRound($this->ticketId, null, 'checklist', 'Mia', self::SHARED_AT);
        foreach (self::STATES_AS_SEEDED as $number => $state) {
            $this->items[$number] = $this->addCheckItem($this->checklistId, $this->addPin($reportShot, $number, '10.00', '10.00', 'Note '.$number, 'Mia'), $state);
        }
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theAssigneeMarksPinsFixedWithFocusOnTheNextMarkFixedThenOnTheHeading(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postAsTurbo($this->itemUrl(1, 'fixed'));

        self::assertSame('Pin 1 marked fixed. A tester checks it.', $this->toastOfTheStream(200));
        $card = $this->streamedCard();
        self::assertSame(['Fixed · to check', 'Fixed · to check', 'Checked OK', 'Not fixed', 'Fixed · to check'], $this->chipsOf($card));
        self::assertSame('1 of 5 checked OK', $card->filter('.round-card__status')->text());
        self::assertSame(['checklist-fixed-'.$this->items[4]], $this->autofocusedIdsOf($card), 'Focus goes to the next Mark fixed.');
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [1 => 'fixed']), $this->storedStates());

        $this->postAsTurbo($this->itemUrl(4, 'fixed'));

        self::assertSame('Pin 4 marked fixed. A tester checks it.', $this->toastOfTheStream(200));
        $card = $this->streamedCard();
        self::assertCount(0, $card->filter('form'));
        self::assertSame(['round-'.$this->checklistId.'-heading'], $this->autofocusedIdsOf($card), 'With no button left, focus goes to the heading.');
        self::assertSame('-1', $card->filter('h3')->attr('tabindex'));
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [1 => 'fixed', 4 => 'fixed']), $this->storedStates());
    }

    #[Test]
    public function aPlainFormComesBackToTheRoundsWithAToastThatNamesTheStoredTester(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $miaId = $this->people['Mia']->id()->toString();
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET tester_id = ? WHERE organization_id = ? AND id = ?',
            [$miaId, $organizationId, $ticketId],
        ));
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->itemUrl(4, 'fixed'), [ChecklistController::CSRF_FIELD => $this->checklistToken()]);

        self::assertSame('Pin 4 marked fixed. Mia checks it.', $this->toastAfterTheRedirect());
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [4 => 'fixed']), $this->storedStates());
    }

    /**
     * Round 0's page lists the report's pictures with each pin's check state and the tester's buttons, as the checklist
     * has no round page of its own (ADR-0048, note of 2026-11-22); a plain form from it comes back to it, a refused one
     * too, and one from the full-screen review to its pin.
     */
    #[Test]
    public function theRoundPageShowsEachPinsCheckAndItsFormsComeBackToIt(): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/0');

        self::assertResponseStatusCodeSame(200);
        $rows = $page->filter('.round-pin[data-pin-list="after"]');
        self::assertSame(['Open', 'Fixed · to check', 'Checked OK', 'Not fixed', 'Fixed · to check'], $rows->filter('.check-state')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertSame(
            [[], ['OK', 'Not fixed'], ['Undo'], ['Undo'], ['OK', 'Not fixed']],
            $rows->each(static fn (Crawler $row): array => $row->filter('button')->each(static fn (Crawler $button): string => $button->text())),
        );
        $okForm = $rows->eq(4)->filter('form')->first();
        self::assertSame($this->itemUrl(5, 'ok'), $okForm->attr('action'));
        self::assertSame(['0', '1'], [
            $okForm->filter('input[name="'.ChecklistController::RETURN_ROUND_FIELD.'"]')->attr('value'),
            $okForm->filter('input[name="'.ChecklistController::RETURN_SCREENSHOT_FIELD.'"]')->attr('value'),
        ]);
        $back = [ChecklistController::RETURN_ROUND_FIELD => '0', ChecklistController::RETURN_SCREENSHOT_FIELD => '1'];

        $this->client->request('POST', $this->itemUrl(5, 'ok'), [ChecklistController::CSRF_FIELD => $this->checklistToken()] + $back);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1/rounds/0?screenshot=1#round-check-'.$this->items[5], $this->client->getResponse()->headers->get('Location'), 'Back to the changed pin\'s row.');
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Pin 5 checked OK.', $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        $changedRow = $page->filter('#round-check-'.$this->items[5]);
        self::assertSame(['5', '-1', 'fragment-focus'], [$changedRow->attr('data-pin-number'), $changedRow->attr('tabindex'), $changedRow->attr('data-controller')], 'The row the fragment names takes the focus.');
        self::assertSame('Checked OK', $changedRow->filter('.check-state')->text());
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [5 => 'ok']), $this->storedStates());

        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', $this->itemUrl(2, 'ok'), [ChecklistController::CSRF_FIELD => $this->checklistToken()] + $back);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1/rounds/0?screenshot=1#round-check-'.$this->items[2], $this->client->getResponse()->headers->get('Location'), 'A refusal comes back to the round page too.');
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [5 => 'ok']), $this->storedStates());
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(ChecklistController::ONLY_THE_TESTER, $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));

        $this->client->request('POST', $this->itemUrl(1, 'fixed'), [
            ChecklistController::CSRF_FIELD => $this->checklistToken(),
            ChecklistController::RETURN_SIDE_FIELD => 'after',
            ChecklistController::RETURN_PIN_FIELD => '1',
        ] + $back);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1/rounds/0/focus?screenshot=1&side=after&pin=1', $this->client->getResponse()->headers->get('Location'));
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [1 => 'fixed', 5 => 'ok']), $this->storedStates());

        $this->client->request('POST', $this->itemUrl(4, 'fixed'), [ChecklistController::CSRF_FIELD => $this->checklistToken(), ChecklistController::RETURN_ROUND_FIELD => '1/../../x']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1#ticket-rounds', $this->client->getResponse()->headers->get('Location'), 'A malformed round number goes back to the ticket.');
    }

    #[Test]
    public function theFullScreenBarOfAPinThatIsACheckItemShowsItsStateAndTheViewersButtons(): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/0/focus?screenshot=1&pin=2');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('.focus-review__picture.screenshot--markers'), 'Markers, whose notes are in the bar only.');
        $bars = $page->filter('.focus-bar__stop');
        self::assertSame(['Open', 'Fixed · to check', 'Checked OK', 'Not fixed', 'Fixed · to check'], $bars->filter('.check-state')->each(static fn (Crawler $chip): string => $chip->text()));
        $secondBar = $bars->eq(1);
        self::assertNull($secondBar->attr('hidden'));
        self::assertSame(['OK', 'Not fixed'], $secondBar->filter('form button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame($this->itemUrl(2, 'ok'), $secondBar->filter('form')->first()->attr('action'));
        self::assertSame(['0', '1', 'after', '2'], [
            $secondBar->filter('input[name="'.ChecklistController::RETURN_ROUND_FIELD.'"]')->first()->attr('value'),
            $secondBar->filter('input[name="'.ChecklistController::RETURN_SCREENSHOT_FIELD.'"]')->first()->attr('value'),
            $secondBar->filter('input[name="'.ChecklistController::RETURN_SIDE_FIELD.'"]')->first()->attr('value'),
            $secondBar->filter('input[name="'.ChecklistController::RETURN_PIN_FIELD.'"]')->first()->attr('value'),
        ]);
        self::assertCount(0, $bars->eq(0)->filter('form'), 'An open pin is the assignee\'s to mark fixed, not the tester\'s.');
        self::assertSame(['OK'], $bars->filter('[autofocus]')->each(static fn (Crawler $control): string => $control->text()), 'Only the shown bar\'s first check button takes the focus, not pin 5\'s.');
        self::assertCount(1, $secondBar->filter('button[autofocus]'));

        $this->client->request('POST', $this->itemUrl(2, 'ok'), [
            ChecklistController::CSRF_FIELD => $this->checklistToken(),
            ChecklistController::RETURN_ROUND_FIELD => '0',
            ChecklistController::RETURN_SCREENSHOT_FIELD => '1',
            ChecklistController::RETURN_SIDE_FIELD => 'after',
            ChecklistController::RETURN_PIN_FIELD => '2',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1/rounds/0/focus?screenshot=1&side=after&pin=2', $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $secondBar = $page->filter('.focus-bar__stop')->eq(1);
        self::assertSame('Checked OK', $secondBar->filter('.check-state')->text());
        self::assertSame(['Undo'], $secondBar->filter('form button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame($this->itemUrl(2, 'undo-check'), $secondBar->filter('form')->attr('action'));
        self::assertSame(['Undo'], $page->filter('.focus-bar [autofocus]')->each(static fn (Crawler $control): string => $control->text()), 'The check\'s Undo takes the focus, so a slip is one key away.');

        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', '/t/SHOP-1/rounds/0/focus?screenshot=1&pin=2');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.focus-bar form'), 'The lead checks nothing.');
        self::assertSame(['focus-bar__note'], $page->filter('.focus-bar [autofocus]')->each(static fn (Crawler $control): string => (string) $control->attr('class')), 'With no button, the shown bar\'s note takes the focus.');
    }

    #[Test]
    public function theTesterChecksAPinOkAndSendsAnotherBack(): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', '/t/SHOP-1/rounds', [], [], ['HTTP_TURBO_FRAME' => 'ticket-rounds']);
        self::assertSame(
            [['OK', 'Not fixed'], ['Undo'], ['Undo'], ['OK', 'Not fixed']],
            $page->filter('.checklist__row')->reduce(static fn (Crawler $row): bool => $row->filter('button')->count() > 0)
                ->each(static fn (Crawler $row): array => $row->filter('button')->each(static fn (Crawler $button): string => $button->text())),
        );

        $this->postAsTurbo($this->itemUrl(5, 'ok'));

        self::assertSame('Pin 5 checked OK.', $this->toastOfTheStream(200));
        $card = $this->streamedCard();
        self::assertSame('2 of 5 checked OK', $card->filter('.round-card__status')->text());
        self::assertSame(['checklist-ok-'.$this->items[2]], $this->autofocusedIdsOf($card), 'No later row has a button, so focus goes to the first one.');
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [5 => 'ok']), $this->storedStates());

        $this->postAsTurbo($this->itemUrl(2, 'not-fixed'));

        self::assertSame('Pin 2 sent back.', $this->toastOfTheStream(200));
        $card = $this->streamedCard();
        self::assertSame(['Open', 'Not fixed', 'Checked OK', 'Not fixed', 'Checked OK'], $this->chipsOf($card));
        self::assertSame(['round-'.$this->checklistId.'-heading'], $this->autofocusedIdsOf($card));
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [2 => 'not_fixed', 5 => 'ok']), $this->storedStates());
    }

    /**
     * The tester pressed OK and Not fixed by mistake: Undo on the round page puts pin 3 back to check, with its OK and
     * Not fixed again, and Undo on the ticket's card does the same for pin 4.
     */
    #[Test]
    public function theTesterUndoesAChecksSlipAndThePinIsBackToCheck(): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', '/t/SHOP-1/rounds/0');
        self::assertResponseStatusCodeSame(200);
        $undoForm = $page->filter('#round-check-'.$this->items[3].' form');
        self::assertSame([$this->itemUrl(3, 'undo-check')], $undoForm->each(static fn (Crawler $form): string => (string) $form->attr('action')));
        self::assertSame('Undo', $undoForm->filter('button')->text());

        $this->client->request('POST', $this->itemUrl(3, 'undo-check'), [
            ChecklistController::CSRF_FIELD => $this->checklistToken(),
            ChecklistController::RETURN_ROUND_FIELD => '0',
            ChecklistController::RETURN_SCREENSHOT_FIELD => '1',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1/rounds/0?screenshot=1#round-check-'.$this->items[3], $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Check on pin 3 undone.', $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        $row = $page->filter('#round-check-'.$this->items[3]);
        self::assertSame('Fixed · to check', $row->filter('.check-state')->text());
        self::assertSame(['OK', 'Not fixed'], $row->filter('button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [3 => 'fixed']), $this->storedStates());

        $this->postAsTurbo($this->itemUrl(4, 'undo-check'));

        self::assertSame('Check on pin 4 undone.', $this->toastOfTheStream(200));
        $card = $this->streamedCard();
        self::assertSame(['Open', 'Fixed · to check', 'Fixed · to check', 'Fixed · to check', 'Fixed · to check'], $this->chipsOf($card));
        self::assertSame('0 of 5 checked OK', $card->filter('.round-card__status')->text());
        self::assertSame(['checklist-ok-'.$this->items[4]], $this->autofocusedIdsOf($card), 'The undone pin\'s OK takes the focus.');
        self::assertSame(array_replace(self::STATES_AS_SEEDED, [3 => 'fixed', 4 => 'fixed']), $this->storedStates());
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
    public function someoneWhoIsNeitherAssigneeNorTesterSeesNoButtonAndIsRefusedWithTheReason(string $person): void
    {
        $this->logIn($this->client, $this->people[$person]);
        $page = $this->client->request('GET', '/t/SHOP-1/rounds', [], [], ['HTTP_TURBO_FRAME' => 'ticket-rounds']);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(5, $page->filter('.checklist__row'));
        self::assertCount(0, $page->filter('.checklist form, .checklist button'));

        $this->postAsTurbo($this->itemUrl(1, 'fixed'));
        self::assertSame(ChecklistController::ONLY_THE_ASSIGNEE, $this->toastOfTheStream(403));
        $this->postAsTurbo($this->itemUrl(2, 'ok'));
        self::assertSame(ChecklistController::ONLY_THE_TESTER, $this->toastOfTheStream(403));
        $this->postAsTurbo($this->itemUrl(2, 'not-fixed'));
        self::assertSame(ChecklistController::ONLY_THE_TESTER, $this->toastOfTheStream(403));
        $this->postAsTurbo($this->itemUrl(3, 'undo-check'));
        self::assertSame(ChecklistController::ONLY_THE_TESTER_UNDOES, $this->toastOfTheStream(403));
        $this->client->request('POST', $this->itemUrl(2, 'ok'), [ChecklistController::CSRF_FIELD => $this->checklistToken()]);
        self::assertSame(ChecklistController::ONLY_THE_TESTER, $this->toastAfterTheRedirect());

        self::assertSame(self::STATES_AS_SEEDED, $this->storedStates());
    }

    #[Test]
    public function theAssigneeMayNotCheckAndTheTesterMayNotMarkFixed(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $this->postAsTurbo($this->itemUrl(2, 'ok'));
        self::assertSame(ChecklistController::ONLY_THE_TESTER, $this->toastOfTheStream(403));
        $this->postAsTurbo($this->itemUrl(4, 'undo-check'));
        self::assertSame(ChecklistController::ONLY_THE_TESTER_UNDOES, $this->toastOfTheStream(403));

        $this->logIn($this->client, $this->people['Mia']);
        $this->postAsTurbo($this->itemUrl(1, 'fixed'));
        self::assertSame(ChecklistController::ONLY_THE_ASSIGNEE, $this->toastOfTheStream(403));

        self::assertSame(self::STATES_AS_SEEDED, $this->storedStates());
    }

    #[Test]
    public function aChangeTheItemsStateDoesNotAllowIsRefusedWithTheReason(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $this->postAsTurbo($this->itemUrl(3, 'fixed'));
        self::assertSame('This check is confirmed OK, so it no longer changes.', $this->toastOfTheStream(422));
        $this->postAsTurbo($this->itemUrl(2, 'fixed'));
        self::assertSame('This check is marked fixed already.', $this->toastOfTheStream(422));

        $this->logIn($this->client, $this->people['Mia']);
        $this->postAsTurbo($this->itemUrl(1, 'ok'));
        self::assertSame('Only a check marked fixed can be confirmed OK.', $this->toastOfTheStream(422));
        $this->postAsTurbo($this->itemUrl(4, 'not-fixed'));
        self::assertSame('Only a check marked fixed can be sent back as not fixed.', $this->toastOfTheStream(422));
        $this->postAsTurbo($this->itemUrl(1, 'undo-check'));
        self::assertSame('Only a check confirmed OK or sent back as not fixed can be undone.', $this->toastOfTheStream(422));
        $this->postAsTurbo($this->itemUrl(2, 'undo-check'));
        self::assertSame('Only a check confirmed OK or sent back as not fixed can be undone.', $this->toastOfTheStream(422));

        self::assertSame(self::STATES_AS_SEEDED, $this->storedStates());
    }

    #[Test]
    public function aFormWithoutAValidTokenChangesNothing(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->itemUrl(1, 'fixed'), [], [], self::TURBO_FORM_HEADERS);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastOfTheStream(422));
        $this->client->request('POST', $this->itemUrl(1, 'fixed'), [ChecklistController::CSRF_FIELD => $this->csrfTokenFor($this->client, ChecklistController::csrfIdOf('SHOP-2'))], [], self::TURBO_FORM_HEADERS);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastOfTheStream(422));
        $this->logIn($this->client, $this->people['Mia']);
        $this->client->request('POST', $this->itemUrl(3, 'undo-check'), [], [], self::TURBO_FORM_HEADERS);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastOfTheStream(422));

        self::assertSame(self::STATES_AS_SEEDED, $this->storedStates());
    }

    #[Test]
    public function aMalformedOrUnknownItemOrAnItemOfAnotherTicketIsNotFound(): void
    {
        $otherTicket = $this->addTicket('SHOP-2', number: 2);
        $otherShot = $this->addScreenshot($this->addRound($otherTicket, 0, 'report', 'Mia', self::SHARED_AT), $this->addMedia('ready'), 0, 'Cart', null);
        $otherItem = $this->addCheckItem($this->addRound($otherTicket, null, 'checklist', 'Mia', self::SHARED_AT), $this->addPin($otherShot, 1, '10.00', '10.00', 'Elsewhere', 'Mia'), 'open');
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->checklistToken();
        $notItemsOfTheTicket = [
            'a malformed id' => '/t/SHOP-1/checklist/not-a-uuid/fixed',
            'an unknown id' => '/t/SHOP-1/checklist/'.Uuid::v7()->toRfc4122().'/fixed',
            "another ticket's item" => '/t/SHOP-1/checklist/'.$otherItem.'/fixed',
            "another ticket's item to undo" => '/t/SHOP-1/checklist/'.$otherItem.'/undo-check',
            'a ticket that does not exist' => '/t/SHOP-77/checklist/'.$this->items[1].'/fixed',
        ];

        foreach ($notItemsOfTheTicket as $case => $url) {
            $this->client->request('POST', $url, [ChecklistController::CSRF_FIELD => $token], [], self::TURBO_FORM_HEADERS);
            self::assertResponseStatusCodeSame(404, $case);
        }
        $this->client->request('POST', '/t/SHOP-1/checklist/'.$otherItem.'/fixed', [ChecklistController::CSRF_FIELD => $token]);
        self::assertStringContainsString(ChecklistRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());

        self::assertSame(self::STATES_AS_SEEDED, $this->storedStates());
        self::assertSame([1 => 'open'], $this->storedStates($otherTicket));
    }

    #[Test]
    public function someoneOfAnotherOrganizationGetsNotFoundAndChangesNothing(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);

        $this->client->request('POST', $this->itemUrl(1, 'fixed'), [ChecklistController::CSRF_FIELD => $this->checklistToken()], [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(RoundsController::NOT_FOUND, (string) $this->client->getResponse()->getContent(), "The ticket page's 404, which does not tell the ticket exists.");
        self::assertSame(self::STATES_AS_SEEDED, $this->storedStates());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogInAndChangesNothing(): void
    {
        foreach ([$this->itemUrl(1, 'fixed'), $this->itemUrl(2, 'ok'), $this->itemUrl(5, 'not-fixed'), $this->itemUrl(3, 'undo-check')] as $url) {
            $this->client->request('POST', $url, [ChecklistController::CSRF_FIELD => 'any']);

            self::assertResponseRedirects('/login', 302, $url);
        }
        self::assertSame(self::STATES_AS_SEEDED, $this->storedStates());
    }

    /**
     * @param 'fixed'|'ok'|'not-fixed'|'undo-check' $action
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
     * The checklist card of the last answer's stream, which replaces the one on the page.
     */
    private function streamedCard(): Crawler
    {
        self::assertResponseStatusCodeSame(200);
        $content = (string) $this->client->getResponse()->getContent();
        $matched = preg_match('#<turbo-stream action="replace" target="checklist-'.$this->checklistId.'"><template>(.*?)</template></turbo-stream>#s', $content, $card);
        self::assertSame(1, $matched);
        $crawler = new Crawler($card[1]);
        self::assertSame('checklist-'.$this->checklistId, $crawler->filter('div.checklist')->attr('id'));

        return $crawler;
    }

    /**
     * @return list<string>
     */
    private function chipsOf(Crawler $card): array
    {
        return $card->filter('.check-state')->each(static fn (Crawler $chip): string => $chip->text());
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
     * @return array<int, string> the checklist items' states, by pin number
     */
    private function storedStates(?string $ticketId = null): array
    {
        $states = [];
        $ticketId ??= $this->ticketId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($ticketId, &$states): int {
            /** @var list<array{number: int, state: string}> $rows */
            $rows = $migrator->fetchAllAssociative(
                'SELECT pin.number, item.state FROM rvw_check_item AS item
                 JOIN rvw_round AS round ON round.id = item.round_id
                 JOIN rvw_pin AS pin ON pin.id = item.pin_id
                 WHERE round.ticket_id = ? ORDER BY pin.number',
                [$ticketId],
            );
            foreach ($rows as $row) {
                $states[$row['number']] = $row['state'];
            }

            return 0;
        });
        self::assertNotEmpty($states);

        return $states;
    }
}
