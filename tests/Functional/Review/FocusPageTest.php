<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
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
 * The full-screen review of Acme Foods' ticket SHOP-1, GET /t/SHOP-1/rounds/2/focus (P05.T10), through the real
 * firewall, voters, CSRF, Media signing and database, and as a browser without JavaScript uses it: the links and plain
 * forms alone. Round 2's "Per product" area checks round 1's pins by Mia, Lena and Omar; an AI suggestion nobody kept is
 * not one of them. Mia is the Shop team's tester and Lena its lead; Omar, a member, and Raj, a manager in no team, may
 * see the round but not give verdicts. The clock is fixed on 6 Nov 2026, before today.
 */
final class FocusPageTest extends WebTestCase
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
    private const string FOCUS_PAGE = '/t/SHOP-1/rounds/2/focus';
    private const string PRIVATE_TITLE = 'Lena private draft picture';
    /**
     * As the round page: the session's user and password change, the organizations, the tenant, the viewer's roles and
     * teams, the settings, the ticket, the names, the visibility checks, the round, its areas, the pins, the round and
     * verdict voters' ticket facts and the signing of the one picture shown.
     */
    private const int PAGE_QUERIES = 22;
    /**
     * VerdictControllerTest's ONE_VERDICT_QUERIES (38) without the second read of the round and its names for the round
     * page's tab and panel: the redirect reads the full-screen review again.
     */
    private const int FOCUS_VERDICT_QUERIES = 32;

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
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function itShowsOneAreaWithTheAfterPictureTheEarlierPinsAndTheFirstPinsBar(): void
    {
        $this->seedRoundTwo();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::FOCUS_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('dark', $page->filter('html')->attr('data-theme'), 'Always dark, whatever the theme saved.');
        self::assertCount(0, $page->filter('.site-header'));
        self::assertSame('SHOP-1 · Round 2 · Per product · section 1 of 2', $page->filter('h1.focus-review__line')->text());
        self::assertSame('/t/SHOP-1/rounds/2?area=0', $page->filter('a.focus-review__exit')->attr('href'));
        self::assertSame('Exit full screen', $page->filter('a.focus-review__exit')->text());
        self::assertCount(1, $page->filter('a.focus-review__exit svg[aria-hidden="true"]'), 'The collapse icon is decoration only.');
        self::assertSame(
            [['After', '/t/SHOP-1/rounds/2/focus?area=0&side=after', 'page'], ['Before', '/t/SHOP-1/rounds/2/focus?area=0&side=before', null]],
            $page->filter('.focus-review__sides a.seg')->each(static fn (Crawler $link): array => [$link->text(), $link->attr('href'), $link->attr('aria-current')]),
        );
        self::assertSame('After, round 2: Per product', $page->filter('img.screenshot__image')->attr('alt'));
        self::assertCount(0, $page->filter('.screenshot__text'), 'No title or summary above the picture, as in the prototype.');
        self::assertStringNotContainsString('After product', (string) $this->client->getResponse()->getContent());
        self::assertSame(
            [['Pin 1: Planted wrong', 'true'], ['Pin 2: Header cut', 'false'], ['Pin 3', 'false']],
            $page->filter('button.pin')->each(static fn (Crawler $pin): array => [(string) $pin->attr('aria-label'), (string) $pin->attr('aria-pressed')]),
            "Round 1's pins to check, not the after picture's own, with the first one selected.",
        );
        self::assertCount(1, $page->filter('button.pin.pin--selected'));
        self::assertSame('polite', $page->filter('.focus-bar')->attr('aria-live'));
        self::assertSame(
            [['Pin 1 of 3 · Mia', 'Planted wrong', false], ['Pin 2 of 3 · Lena', 'Header cut', true], ['Pin 3 of 3 · Omar', '(no note)', true]],
            $page->filter('.focus-bar__stop')->each(static fn (Crawler $stop): array => [$stop->filter('.focus-bar__label')->text(), $stop->filter('.focus-bar__note')->text(), null !== $stop->attr('hidden')]),
        );
        $first = $page->filter('.focus-bar__stop')->first();
        self::assertSame(['Previous pin', 'Next pin'], $first->filter('a.focus-bar__step')->each(static fn (Crawler $link): string => (string) $link->attr('aria-label')));
        self::assertSame(
            ['/t/SHOP-1/rounds/2/focus?area=0&side=after&pin=3', '/t/SHOP-1/rounds/2/focus?area=0&side=after&pin=2'],
            $first->filter('a.focus-bar__step')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
            'Previous wraps around to the last pin.',
        );
        self::assertSame(['Fixed', 'Still wrong'], $first->filter('form button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame('/t/SHOP-1/rounds/2/pins/'.$this->pins[1].'/verdict', $first->filter('form')->attr('action'));
        self::assertNull($first->filter('form')->attr('data-turbo'), 'A plain form, so its answer is the redirect back here.');
        self::assertSame('after', $first->filter('input[name="'.VerdictController::FOCUS_SIDE_FIELD.'"]')->attr('value'));
        self::assertSame(
            [['0', '1'], ['0', '2'], ['0', '3']],
            $page->filter('.focus-bar__stop')->each(static fn (Crawler $stop): array => [
                (string) $stop->filter('input[name="'.VerdictController::FOCUS_AREA_FIELD.'"]')->attr('value'),
                (string) $stop->filter('input[name="'.VerdictController::FOCUS_PIN_FIELD.'"]')->attr('value'),
            ]),
            'Each form posts where it was, for a refusal to come back to.',
        );
        self::assertCount(0, $page->filter('.focus-bar .tag'), 'No verdict tag in the bar, as in the prototype.');
        self::assertSame(['Fixed'], $page->filter('[autofocus]')->each(static fn (Crawler $button): string => $button->text()), 'Focus starts on the shown pin\'s first choice.');
        self::assertStringNotContainsString('Suggested guess', (string) $this->client->getResponse()->getContent(), 'An AI suggestion nobody kept is not walked.');
    }

    #[Test]
    public function withoutJavaScriptTheLinksWalkThePinsAndSwitchToTheBeforePicture(): void
    {
        $this->seedRoundTwo();
        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->client->request('GET', self::FOCUS_PAGE);

        $page = $this->client->click($page->filter('.focus-bar__stop:not([hidden])')->filter('a[aria-label="Next pin"]')->link());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Pin 2 of 3 · Lena', $page->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text());
        self::assertSame('Pin 2: Header cut', $page->filter('button.pin.pin--selected')->attr('aria-label'));

        $page = $this->client->click($page->filter('.focus-bar__stop:not([hidden])')->filter('a[aria-label="Next pin"]')->link());
        $page = $this->client->click($page->filter('.focus-bar__stop:not([hidden])')->filter('a[aria-label="Next pin"]')->link());
        self::assertSame('Pin 1 of 3 · Mia', $page->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text(), 'Next wraps around to the first pin.');

        $page = $this->client->click($page->filter('.focus-review__sides')->selectLink('Before')->link());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Before, round 1: Per product', $page->filter('img.screenshot__image')->attr('alt'));
        self::assertSame('page', $page->filter('.focus-review__sides')->selectLink('Before')->attr('aria-current'));
        self::assertSame(['Pin 1: Planted wrong', 'Pin 2: Header cut', 'Pin 3'], $page->filter('button.pin')->each(static fn (Crawler $pin): string => (string) $pin->attr('aria-label')));
        self::assertSame('Pin 1 of 3 · Mia', $page->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text(), 'Switching the picture starts at the first pin.');
        self::assertSame('/t/SHOP-1/rounds/2/focus?area=0&side=before&pin=2', $page->filter('.focus-bar__stop:not([hidden])')->filter('a[aria-label="Next pin"]')->attr('href'));
        self::assertCount(0, $page->filter('.focus-bar form, [autofocus]'), 'Omar may not give verdicts, so the bars are read-only.');
    }

    #[Test]
    public function aChoiceMadeHereIsSavedMovesToTheNextPinAndShowsOnTheRoundPage(): void
    {
        $this->seedRoundTwo();
        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', self::FOCUS_PAGE.'?side=before');

        $this->client->submit($page->filter('.focus-bar__stop:not([hidden])')->selectButton('Fixed')->form());

        self::assertSame('Pin 1 marked fixed.', $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2/focus?area=0&side=before&pin=2'));
        $page = $this->client->getCrawler();
        self::assertSame('Pin 2 of 3 · Lena', $page->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text());
        self::assertSame('Before, round 1: Per product', $page->filter('img.screenshot__image')->attr('alt'), 'The picture stays the one chosen.');
        self::assertSame(['Fixed'], $page->filter('[autofocus]')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame([1 => 'fixed', 2 => null, 3 => null, 4 => null], $this->pinVerdicts());

        $page = $this->client->request('GET', self::FOCUS_PAGE.'?pin=3');
        $this->client->submit($page->filter('.focus-bar__stop:not([hidden])')->selectButton('Still wrong')->form());
        self::assertSame('Pin 3 marked still wrong.', $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2/focus?area=0&side=after&pin=1'), 'A choice on the last pin moves to the first.');
        self::assertSame([1 => 'fixed', 2 => null, 3 => 'still_wrong', 4 => null], $this->pinVerdicts());

        $page = $this->client->getCrawler();
        $shown = $page->filter('.focus-bar__stop:not([hidden])');
        self::assertSame(['Undo'], $shown->filter('form button')->each(static fn (Crawler $button): string => $button->text()));
        $this->client->submit($shown->selectButton('Undo')->form());
        self::assertSame('Verdict on pin 1 undone.', $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2/focus?area=0&side=after&pin=1'), 'Undo stays on its pin.');
        $this->client->submit($this->client->getCrawler()->filter('.focus-bar__stop:not([hidden])')->selectButton('Fixed')->form());
        self::assertSame([1 => 'fixed', 2 => null, 3 => 'still_wrong', 4 => null], $this->pinVerdicts());

        $roundPage = $this->client->request('GET', '/t/SHOP-1/rounds/2');
        self::assertSame(
            ['1 Planted wrong Mia · tester Fixed Undo', '2 Header cut Lena · team lead Not checked yet Fixed Still wrong', '3 (no note) Omar Still wrong Undo'],
            $roundPage->filter('.round-panel__pin')->each(static fn (Crawler $row): string => (string) preg_replace('/\s+/', ' ', trim($row->text()))),
            'The same records as the round page.',
        );
    }

    #[Test]
    public function aRefusedChoiceComesBackToTheSameAreaAndPinWithItsToastAndSavesNothing(): void
    {
        $this->seedRoundTwo();
        $before = $this->pinVerdicts();

        $this->logIn($this->client, $this->people['Omar']);
        $this->postFocusVerdict($this->pins[2], 'before', '0', '2');
        self::assertSame(VerdictController::ONLY_TESTER_OR_LEAD, $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2/focus?area=0&side=before&pin=2'));
        self::assertSame('Pin 2 of 3 · Lena', $this->client->getCrawler()->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text());

        $this->logIn($this->client, $this->people['Mia']);
        $this->client->request('POST', '/t/SHOP-1/rounds/2/pins/'.$this->pins[3].'/verdict', [
            VerdictController::VERDICT_FIELD => 'fixed',
            VerdictController::FOCUS_SIDE_FIELD => 'after',
            VerdictController::FOCUS_AREA_FIELD => '0',
            VerdictController::FOCUS_PIN_FIELD => '3',
        ]);
        self::assertSame(VerdictController::FORM_EXPIRED, $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2/focus?area=0&side=after&pin=3'));
        self::assertSame('Pin 3 of 3 · Omar', $this->client->getCrawler()->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text());

        $this->client->request('POST', '/t/SHOP-1/rounds/2/pins/'.$this->pins[2].'/undo', [
            VerdictController::CSRF_FIELD => $this->csrfTokenFor($this->client, VerdictController::csrfIdOf('SHOP-1')),
            VerdictController::FOCUS_SIDE_FIELD => 'after',
            VerdictController::FOCUS_AREA_FIELD => '0',
            VerdictController::FOCUS_PIN_FIELD => '2',
        ]);
        self::assertSame('This pin has no verdict to undo.', $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2/focus?area=0&side=after&pin=2'));
        self::assertSame('Pin 2 of 3 · Lena', $this->client->getCrawler()->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text());

        self::assertSame($before, $this->pinVerdicts());
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function malformedFocusPositions(): iterable
    {
        yield 'words' => ['abc', 'two'];
        yield 'negative numbers' => ['-1', '-2'];
        yield 'too many digits' => ['1234567890', '1234567890'];
        yield 'decimals' => ['0.5', '2.0'];
        yield 'lists' => [['0'], ['2']];
        yield 'empty' => ['', ''];
    }

    #[Test]
    #[DataProvider('malformedFocusPositions')]
    public function aRefusalWithMalformedPositionsComesBackToTheFirstPin(mixed $area, mixed $pin): void
    {
        $this->seedRoundTwo();
        $before = $this->pinVerdicts();
        $this->logIn($this->client, $this->people['Omar']);

        $this->postFocusVerdict($this->pins[2], 'before', $area, $pin);

        self::assertSame(VerdictController::ONLY_TESTER_OR_LEAD, $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2/focus?side=before'));
        self::assertSame('Pin 1 of 3 · Mia', $this->client->getCrawler()->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text());
        self::assertSame($before, $this->pinVerdicts());
    }

    #[Test]
    public function aFormWithAnUnknownSideIsAnsweredByTheRoundPage(): void
    {
        $this->seedRoundTwo();
        $before = $this->pinVerdicts();
        $this->logIn($this->client, $this->people['Mia']);

        $this->postFocusVerdict($this->pins[2], 'sideways', '0', '2');

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1/rounds/2?area=0', $this->client->getResponse()->headers->get('Location'), 'An unknown side is no full-screen form: the round page answers.');
        $before[2] = 'fixed';
        self::assertSame($before, $this->pinVerdicts());
    }

    #[Test]
    public function aPinsPlaceInTheWalkIsNotItsRingNumber(): void
    {
        $this->seedRoundTwo();
        $this->pins[5] = $this->addPin($this->beforeShot, 5, '60.00', '60.00', 'Price hidden', 'Lena');
        $this->pins[6] = $this->addPin($this->beforeShot, 6, '70.00', '70.00', 'Button off', 'Omar');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::FOCUS_PAGE.'?pin=4');

        $shown = $page->filter('.focus-bar__stop:not([hidden])');
        self::assertSame('Pin 4 of 5 · Lena', $shown->filter('.focus-bar__label')->text(), 'Ring 4 is an AI suggestion nobody kept, so the fourth stop is ring 5.');
        self::assertSame('Price hidden', $shown->filter('.focus-bar__note')->text());
        self::assertSame('Pin 5: Price hidden', $page->filter('button.pin.pin--selected')->attr('aria-label'));
        self::assertSame('4', $shown->filter('input[name="'.VerdictController::FOCUS_PIN_FIELD.'"]')->attr('value'));

        $this->client->submit($shown->selectButton('Fixed')->form());

        self::assertSame('Pin 5 marked fixed.', $this->toastAfterTheRedirect('/t/SHOP-1/rounds/2/focus?area=0&side=after&pin=5'));
        self::assertSame([1 => null, 2 => null, 3 => null, 4 => null, 5 => 'fixed', 6 => null], $this->pinVerdicts());
        self::assertSame('Pin 5 of 5 · Omar', $this->client->getCrawler()->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text());
        self::assertSame('Pin 6: Button off', $this->client->getCrawler()->filter('button.pin.pin--selected')->attr('aria-label'));
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function viewersOfTheVerdictForms(): iterable
    {
        yield "the ticket's tester on an open round" => ['Mia', false, true];
        yield 'the team lead on an open round' => ['Lena', false, true];
        yield 'a team member' => ['Omar', false, false];
        yield 'a manager in no team on a ticket everyone sees' => ['Raj', false, false];
        yield "the ticket's tester on a closed round" => ['Mia', true, false];
        yield 'the team lead on a closed round' => ['Lena', true, false];
    }

    #[Test]
    #[DataProvider('viewersOfTheVerdictForms')]
    public function onlyTheTesterOrTheLeadOnAnOpenRoundSeesTheVerdictForms(string $viewer, bool $isRoundClosed, bool $hasForms): void
    {
        $this->seedRoundTwo();
        if ($isRoundClosed) {
            $organizationId = $this->acmeFoods->toString();
            $roundId = $this->roundTwo;
            $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "UPDATE rvw_round SET state = 'closed' WHERE organization_id = ? AND id = ?",
                [$organizationId, $roundId],
            ));
        }
        $this->logIn($this->client, $this->people[$viewer]);

        $page = $this->client->request('GET', self::FOCUS_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(3, $page->filter('.focus-bar__stop'), 'Everyone who may see the round walks its pins.');
        self::assertCount($hasForms ? 3 : 0, $page->filter('.focus-bar form'));
        self::assertCount($hasForms ? 1 : 0, $page->filter('[autofocus]'));
    }

    #[Test]
    public function aChoiceMadeHereReadsAPinnedNumberOfQueriesBelowTheRoundPagesVerdict(): void
    {
        $this->seedRoundTwo();
        $this->logIn($this->client, $this->people['Lena']);
        $token = $this->csrfTokenFor($this->client, VerdictController::csrfIdOf('SHOP-1'));
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $this->client->request('POST', '/t/SHOP-1/rounds/2/pins/'.$this->pins[1].'/verdict', [
            VerdictController::CSRF_FIELD => $token,
            VerdictController::VERDICT_FIELD => 'fixed',
            VerdictController::FOCUS_SIDE_FIELD => 'after',
            VerdictController::FOCUS_AREA_FIELD => '0',
            VerdictController::FOCUS_PIN_FIELD => '1',
        ]);

        self::assertResponseStatusCodeSame(303);
        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }
        self::assertSame(self::FOCUS_VERDICT_QUERIES, $queryCount);
    }

    #[Test]
    public function anAreaWithNoEarlierPinsSaysSoAndMalformedParametersFallBack(): void
    {
        $this->seedRoundTwo();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::FOCUS_PAGE.'?area=1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-1 · Round 2 · Totals · section 2 of 2', $page->filter('h1.focus-review__line')->text());
        self::assertSame('There are no earlier pins to check here.', $page->filter('.focus-review__none')->text());
        self::assertCount(0, $page->filter('.focus-bar, button.pin'));

        foreach (['pin=99', 'pin=0', 'pin=abc', 'pin[]=2', 'side=sideways', 'side[]=before', 'area=99', 'area[]=1'] as $query) {
            $page = $this->client->request('GET', self::FOCUS_PAGE.'?'.$query);

            self::assertResponseStatusCodeSame(200, $query);
            self::assertSame('SHOP-1 · Round 2 · Per product · section 1 of 2', $page->filter('h1.focus-review__line')->text(), $query);
            self::assertSame('After, round 2: Per product', $page->filter('img.screenshot__image')->attr('alt'), $query);
            self::assertSame('Pin 1 of 3 · Mia', $page->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text(), $query);
        }
    }

    #[Test]
    public function aRoundWithNoScreenAreasSaysSo(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'review', 'Omar', self::SHARED_EARLIER);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1/focus');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-1 · Round 1', $page->filter('h1.focus-review__line')->text());
        self::assertSame('This round has no screen areas.', $page->filter('.empty-state')->text());
        self::assertSame('/t/SHOP-1/rounds/1', $page->filter('a.focus-review__exit')->attr('href'));
        self::assertCount(0, $page->filter('.focus-review__sides, .focus-bar'));
    }

    #[Test]
    public function theRoundPagesFullScreenButtonOpensItOnTheSameArea(): void
    {
        $this->seedRoundTwo();
        $this->logIn($this->client, $this->people['Omar']);

        $roundPage = $this->client->request('GET', '/t/SHOP-1/rounds/2?area=1');
        $fullScreen = $roundPage->filter('a.round-page__full-screen');
        self::assertSame('Full screen', $fullScreen->text());
        $page = $this->client->click($fullScreen->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-1 · Round 2 · Totals · section 2 of 2', $page->filter('h1.focus-review__line')->text());
        self::assertSame('/t/SHOP-1/rounds/2?area=1', $page->filter('a.focus-review__exit')->attr('href'), 'Exit goes back to that area.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesOfNoRound(): iterable
    {
        yield 'an unknown number' => ['/t/SHOP-1/rounds/9/focus'];
        yield 'a malformed number' => ['/t/SHOP-1/rounds/abc/focus'];
        yield 'a number too large for the database' => ['/t/SHOP-1/rounds/99999999999999999999/focus'];
        yield 'an unknown ticket' => ['/t/SHOP-77/rounds/2/focus'];
        yield 'a malformed key' => ['/t/shop%20one/rounds/2/focus'];
    }

    #[Test]
    #[DataProvider('addressesOfNoRound')]
    public function anUnknownOrMalformedKeyOrNumberAnswers404AndLeaksNothing(string $uri): void
    {
        $this->seedRoundTwo();
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('GET', $uri);

        self::assertResponseStatusCodeSame(404);
        self::assertNothingOfTheRoundIn((string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function someoneElsesUnsharedDraftHasNoFullScreenForAnyNumber(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $draft = $this->addRound($ticketId, null, 'draft', 'Lena', null);
        $this->addScreenshot($draft, $this->addMedia('ready'), 0, self::PRIVATE_TITLE, null);
        $this->logIn($this->client, $this->people['Omar']);

        foreach ([0, 1, 2] as $number) {
            $this->client->request('GET', '/t/SHOP-1/rounds/'.$number.'/focus');

            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString(self::PRIVATE_TITLE, (string) $this->client->getResponse()->getContent());
        }
    }

    #[Test]
    public function aViewerWithoutTicketViewGets404ForATeamOnlyTicketsRound(): void
    {
        $this->seedRoundTwo('team');

        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('GET', self::FOCUS_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->logIn($this->client, $this->people['Raj']);
        $this->client->request('GET', self::FOCUS_PAGE);

        self::assertResponseStatusCodeSame(404);
        self::assertNothingOfTheRoundIn((string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function someoneOfAnotherOrganizationGets404(): void
    {
        $this->seedRoundTwo();
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);

        $this->client->request('GET', self::FOCUS_PAGE);

        self::assertResponseStatusCodeSame(404);
        self::assertNothingOfTheRoundIn((string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogIn(): void
    {
        $this->seedRoundTwo();

        $this->client->request('GET', self::FOCUS_PAGE);

        self::assertResponseRedirects();
        self::assertNothingOfTheRoundIn((string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function thePageReadsAPinnedNumberOfQueriesWhateverTheNumberOfPins(): void
    {
        $this->seedRoundTwo();
        $this->logIn($this->client, $this->people['Mia']);
        $this->queryCountOf(self::FOCUS_PAGE.'?area=1');
        $noPinsQueryCount = $this->queryCountOf(self::FOCUS_PAGE.'?area=1');
        $threePinsQueryCount = $this->queryCountOf(self::FOCUS_PAGE.'?side=before&pin=2');
        self::assertCount(3, $this->client->getCrawler()->filter('.focus-bar__stop'));
        $this->addPin($this->beforeShot, 5, '60.00', '60.00', 'Price hidden', 'Lena');
        $this->addPin($this->beforeShot, 6, '70.00', '70.00', 'Button off', 'Omar');
        $fivePinsQueryCount = $this->queryCountOf(self::FOCUS_PAGE.'?side=before&pin=2');

        self::assertCount(5, $this->client->getCrawler()->filter('.focus-bar__stop'));
        self::assertSame($noPinsQueryCount, $threePinsQueryCount);
        self::assertSame($threePinsQueryCount, $fivePinsQueryCount);
        self::assertSame(self::PAGE_QUERIES, $fivePinsQueryCount);
    }

    private static function assertNothingOfTheRoundIn(string $content): void
    {
        foreach (['Before product', 'After product', 'Per product', 'Planted wrong', 'Header cut', 'Totals'] as $leak) {
            self::assertStringNotContainsString($leak, $content);
        }
    }

    private function queryCountOf(string $uri): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(200);

        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    /**
     * Fixed on the pin, as a full-screen form posts it, with a valid form token.
     */
    private function postFocusVerdict(string $pinId, string $side, mixed $areaPosition, mixed $pinPosition): void
    {
        $this->client->request('POST', '/t/SHOP-1/rounds/2/pins/'.$pinId.'/verdict', [
            VerdictController::CSRF_FIELD => $this->csrfTokenFor($this->client, VerdictController::csrfIdOf('SHOP-1')),
            VerdictController::VERDICT_FIELD => 'fixed',
            VerdictController::FOCUS_SIDE_FIELD => $side,
            VerdictController::FOCUS_AREA_FIELD => $areaPosition,
            VerdictController::FOCUS_PIN_FIELD => $pinPosition,
        ]);
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
     * Round 1 shot "Per product" with Mia's, Lena's and Omar's pins (Omar's with no note) and an AI suggestion nobody
     * kept, and "Totals" with none; round 2 has both areas, before round 1's pictures, and its own pin on "Per product".
     */
    private function seedRoundTwo(string $visibility = 'all'): void
    {
        $this->ticketId = $this->addTicket('SHOP-1', $visibility);
        $roundOne = $this->addRound($this->ticketId, 1, 'review', 'Omar', self::SHARED_EARLIER);
        $this->beforeShot = $this->addScreenshot($roundOne, $this->addMedia('ready'), 0, 'Before product', null);
        $this->pins[1] = $this->addPin($this->beforeShot, 1, '10.00', '10.00', 'Planted wrong', 'Mia');
        $this->pins[2] = $this->addPin($this->beforeShot, 2, '20.00', '30.00', 'Header cut', 'Lena');
        $this->pins[3] = $this->addPin($this->beforeShot, 3, '30.00', '30.00', '', 'Omar');
        $this->addPin($this->beforeShot, 4, '50.00', '50.00', 'Suggested guess', 'Mia', 'ai', 'suggested');
        $beforeTotals = $this->addScreenshot($roundOne, $this->addMedia('ready'), 1, 'Before totals', null);

        $this->roundTwo = $this->addRound($this->ticketId, 2, 'review', 'Omar', self::SHARED_TODAY);
        $afterProduct = $this->addScreenshot($this->roundTwo, $this->addMedia('ready'), 0, 'After product', null);
        $this->addPin($afterProduct, 1, '40.00', '40.00', 'Still cut', 'Mia');
        $afterTotals = $this->addScreenshot($this->roundTwo, $this->addMedia('ready'), 1, 'After totals', null);
        $this->addArea($this->roundTwo, 'Per product', 0, $this->beforeShot, $afterProduct, null, null);
        $this->addArea($this->roundTwo, 'Totals', 1, $beforeTotals, $afterTotals, null, null);
    }
}
