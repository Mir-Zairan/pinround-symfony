<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\RoundRefused;
use App\Review\Domain\Round;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tries of a screenshot in the owner's draft round (P14.T6 step 2, ADR-0048) through the real firewall, voters and
 * database. Omar's draft of SHOP-1 holds the first screenshot's try 1 (position 0), a second screenshot (position 1)
 * and the first one's try 2 (position 2), so neither position order nor a count of rows is the order and count of the
 * screenshots. Try 1 has a person's pin and a dismissed AI pin; try 2 a pin of its own. Mia owns another draft of the
 * same ticket with a pin. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end the
 * session as idle.
 */
final class DraftRoundEditorTriesTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string FRAME = '/t/SHOP-1/rounds';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => 'ticket-rounds'];
    private const string FIRST_TRY_NOTE = 'The total is off';
    private const string LATEST_TRY_NOTE = 'The badge overlaps';
    private const string DISMISSED_NOTE = 'An AI guess nobody wanted';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $draft;
    private string $firstTry;
    private string $otherShot;
    private string $latestTry;
    private string $firstTryPin;
    private string $latestTryPin;
    private string $miasDraft;
    private string $miasPin;
    /** The editor's props as the owner's page rendered them. */
    private string $props;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople('member');
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
    public function aRetakeShowsAsTryTwoOfTheSameScreenshotWithASwitchAndTheFirstTryKeepsItsPins(): void
    {
        $this->seedWorld();

        $this->client->request('GET', self::FRAME, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->latestTry, $this->otherShot], $this->sectionIdsShown(), 'One section per screenshot, its latest try first.');
        self::assertSame('1 of 2 look right', $this->statusShown(), 'Counted per screenshot by its latest try: try 1 is not right, the other has no verdict.');
        $latest = $this->sectionOf($this->latestTry);
        self::assertSame('Screenshot 1', $latest->filter('.round-card__screenshot-label > span')->first()->text());
        self::assertSame('Try 2 of 2', $latest->filter('[data-try-label]')->text());
        self::assertSame(
            [['Try 1', 'false', $this->firstTry], ['Try 2', 'true', $this->latestTry]],
            $latest->filter('[data-try-switch] button')->each(static fn (Crawler $chip): array => [$chip->text(), (string) $chip->attr('aria-pressed'), (string) $chip->attr('data-live-screenshot-id-param')]),
        );
        self::assertStringContainsString(self::LATEST_TRY_NOTE, $latest->text());
        self::assertStringNotContainsString(self::FIRST_TRY_NOTE, $latest->text(), 'The try-1 pin stays on try 1.');
        self::assertSame(['true', 'false'], $latest->filter('button[data-live-action-param="setVerdict"]')->each(static fn (Crawler $chip): string => (string) $chip->attr('aria-pressed')), 'The latest try keeps its editor with its own verdict.');
        self::assertCount(1, $latest->filter('.pin-placer'));
        self::assertSame('Remove this try', trim($latest->filter('.draft-shot__remove')->text()));
        $other = $this->sectionOf($this->otherShot);
        self::assertSame('Screenshot 2', $other->filter('.round-card__screenshot-label > span')->first()->text());
        self::assertCount(0, $other->filter('[data-try-label]'), 'A screenshot no one retook has no try label.');
        self::assertCount(0, $other->filter('[data-try-switch]'));
        self::assertSame('Remove screenshot', trim($other->filter('.draft-shot__remove')->text()));
    }

    #[Test]
    public function switchingToTheFirstTryShowsItReadOnlyWithItsOwnPinsToMarkAndSwitchingBackShowsTheLatest(): void
    {
        $this->seedWorld();
        $before = $this->stored();

        $this->act('showTry', ['screenshotId' => $this->firstTry]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        self::assertSame([$this->firstTry, $this->otherShot], $this->sectionIdsShown());
        $first = $this->sectionOf($this->firstTry);
        self::assertSame('Try 1 of 2', $first->filter('[data-try-label]')->text());
        self::assertSame(['true', 'false'], $first->filter('[data-try-switch] button')->each(static fn (Crawler $chip): string => (string) $chip->attr('aria-pressed')));
        self::assertSame(
            [[$this->firstTryPin, self::FIRST_TRY_NOTE, 'Not checked yet', ['Fixed on try 2', 'Still wrong']]],
            $this->pinRowsShown($first),
            'Only the person\'s pin of try 1; the dismissed AI pin is left out.',
        );
        self::assertStringNotContainsString(self::LATEST_TRY_NOTE, $first->text());
        self::assertStringNotContainsString(self::DISMISSED_NOTE, $first->text());
        self::assertCount(0, $first->filter('button[data-live-action-param="setVerdict"]'), 'An earlier try is read-only.');
        self::assertCount(0, $first->filter('.pin-placer'));
        $picture = $first->filter('img.screenshot__image');
        self::assertCount(1, $picture);
        self::assertNotSame('', (string) $picture->attr('src'), 'The earlier try\'s picture is signed with the card.');
        self::assertSame([$this->firstTry], $first->filter('.draft-shot__remove')->each(static fn (Crawler $button): string => (string) $button->attr('data-live-screenshot-id-param')));
        self::assertSame('Remove this try', trim($first->filter('.draft-shot__remove')->text()));
        self::assertSame([$this->firstTry], $this->shownTryIdsOfResponse());
        self::assertSame($before, $this->stored(), 'Switching saves nothing.');

        $this->props = $this->propsOfResponse();
        $this->act('showTry', ['screenshotId' => $this->latestTry]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->latestTry, $this->otherShot], $this->sectionIdsShown());
        self::assertSame([], $this->shownTryIdsOfResponse(), 'The latest try clears the chain\'s entry.');
    }

    #[Test]
    public function aSecondSwitchInTheSameChainReplacesTheFirstSoTheChainNamesOneTry(): void
    {
        $this->seedWorld();
        $secondTry = $this->addRetake($this->draft, $this->firstTry, 3);
        $this->props = $this->propsOf($this->draft);

        $this->act('showTry', ['screenshotId' => $this->firstTry]);
        $this->props = $this->propsOfResponse();
        $this->act('showTry', ['screenshotId' => $this->latestTry]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->latestTry], $this->shownTryIdsOfResponse());
        $shown = $this->sectionOf($this->latestTry);
        self::assertSame('Try 2 of 3', $shown->filter('[data-try-label]')->text(), 'Try 2 is now an earlier try.');
        self::assertSame(
            [['Try 1', 'false', $this->firstTry], ['Try 2', 'true', $this->latestTry], ['Try 3', 'false', $secondTry]],
            $shown->filter('[data-try-switch] button')->each(static fn (Crawler $chip): array => [$chip->text(), (string) $chip->attr('aria-pressed'), (string) $chip->attr('data-live-screenshot-id-param')]),
        );
        self::assertSame([[$this->latestTryPin, self::LATEST_TRY_NOTE, 'Not checked yet', ['Fixed on try 3', 'Still wrong']]], $this->pinRowsShown($shown));
    }

    #[Test]
    public function anUnknownOrForeignTryIsRefusedWithItsReasonAndTheShownTriesStayAsTheyWere(): void
    {
        $this->seedWorld();
        $this->act('showTry', ['screenshotId' => $this->firstTry]);
        $this->props = $this->propsOfResponse();
        $miasShot = $this->screenshotRowsOf($this->miasDraft)[0]['id'];
        self::assertIsString($miasShot);
        $before = $this->stored();

        foreach (['unknown' => Uuid::v7()->toRfc4122(), 'malformed' => 'not-a-uuid', 'of Mia\'s draft' => $miasShot] as $name => $screenshotId) {
            $this->act('showTry', ['screenshotId' => $screenshotId]);

            self::assertResponseStatusCodeSame(200, $name);
            self::assertSame(RoundRefused::SCREENSHOT_NOT_FOUND, $this->refusalShown(), $name);
            self::assertSame([$this->firstTry], $this->shownTryIdsOfResponse(), $name);
            self::assertSame($before, $this->stored(), $name);
        }
    }

    #[Test]
    public function marksOfAPinOnAnEarlierTryAreSavedChangedAndClearedByPressingThemAgain(): void
    {
        $this->seedWorld();
        $this->act('showTry', ['screenshotId' => $this->firstTry]);
        $this->props = $this->propsOfResponse();
        $before = $this->stored();
        $steps = [
            'fixed' => ['fixed', 'fixed', 'Fixed on try 2', ['true', 'false'], ['', 'still_wrong']],
            'still wrong' => ['still_wrong', 'still_wrong', 'Still wrong', ['false', 'true'], ['fixed', '']],
            'cleared' => ['', null, 'Not checked yet', ['false', 'false'], ['fixed', 'still_wrong']],
        ];
        $version = $this->draftVersion();

        foreach ($steps as $name => [$verdict, $stored, $tag, $pressed, $nextVerdicts]) {
            $this->act('setPinVerdict', ['pinId' => $this->firstTryPin, 'verdict' => $verdict]);

            self::assertResponseStatusCodeSame(200, $name);
            self::assertNull($this->refusalShown(), $name);
            self::assertSame($stored, $this->pinVerdictsOf()[$this->firstTryPin], $name);
            $expected = $before['pins'];
            $expected[$this->firstTryPin] = $stored;
            self::assertSame($expected, $this->pinVerdictsOf(), $name.': only that pin changes.');
            self::assertSame(++$version, $this->draftVersion(), $name.': one accepted change adds one.');
            $row = $this->sectionOf($this->firstTry)->filter('li[data-pin-id="'.$this->firstTryPin.'"]');
            self::assertSame($tag, $row->filter('.verdict-tag')->text(), $name);
            self::assertSame($pressed, $row->filter('button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('aria-pressed')), $name);
            self::assertSame($nextVerdicts, $row->filter('button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('data-live-verdict-param')), $name.': a pressed chip takes its mark back.');
        }
    }

    #[Test]
    public function anUnknownMarkAPinOnTheLatestTryAndAnUnknownOrForeignPinAreRefusedWithTheirReasonAndNothingIsSaved(): void
    {
        $this->seedWorld();
        $this->setPinVerdict($this->firstTry, 1, 'still_wrong');
        $before = $this->stored();
        $cases = [
            'an unknown mark' => [$this->firstTryPin, 'fine', RoundRefused::UNKNOWN_PIN_VERDICT],
            'a pin on the latest try' => [$this->latestTryPin, 'fixed', Round::PIN_ON_LATEST_TRY],
            'an unknown pin' => [Uuid::v7()->toRfc4122(), 'fixed', RoundRefused::PIN_NOT_FOUND],
            'a malformed pin id' => ['not-a-uuid', 'fixed', RoundRefused::PIN_NOT_FOUND],
            'a pin of Mia\'s draft' => [$this->miasPin, 'fixed', RoundRefused::PIN_NOT_FOUND],
        ];

        foreach ($cases as $name => [$pinId, $verdict, $reason]) {
            $this->act('setPinVerdict', ['pinId' => $pinId, 'verdict' => $verdict]);

            self::assertResponseStatusCodeSame(200, $name);
            self::assertSame($reason, $this->refusalShown(), $name);
            self::assertSame($before, $this->stored(), $name.': nothing is saved.');
        }
        self::assertSame('still_wrong', $before['pins'][$this->firstTryPin], 'The kept mark is one the refusals could have changed.');
    }

    #[Test]
    public function anyoneButTheOwnerIsNotFoundForTheTryActionsAndChangesNothing(): void
    {
        $this->seedWorld();
        $before = $this->stored();
        $actions = [
            'showTry' => ['screenshotId' => $this->firstTry],
            'setPinVerdict' => ['pinId' => $this->firstTryPin, 'verdict' => 'fixed'],
        ];

        foreach (['Lena', 'Mia'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            foreach ($actions as $action => $args) {
                $this->postLiveAction($this->client, $this->props, $action, $args);

                self::assertResponseStatusCodeSame(404, $person.' '.$action);
                self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent(), $person.' '.$action);
                self::assertStringNotContainsString(self::FIRST_TRY_NOTE, (string) $this->client->getResponse()->getContent(), $person.' '.$action);
                self::assertSame($before, $this->stored(), $person.' '.$action.': nothing changes.');
            }
        }
    }

    #[Test]
    public function removingTheShownEarlierTryLeavesTheLatestAsTheOnlyTryAndForgetsTheSwitch(): void
    {
        $this->seedWorld();
        $this->act('showTry', ['screenshotId' => $this->firstTry]);
        $this->props = $this->propsOfResponse();

        $this->act('removeScreenshot', ['screenshotId' => $this->firstTry]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        $stored = $this->stored();
        self::assertSame([[$this->otherShot, 1, null], [$this->latestTry, 2, null]], $stored['screenshots'][$this->draft], 'Try 2 is now the first try of its chain.');
        self::assertArrayNotHasKey($this->firstTryPin, $stored['pins'], 'The pins go with their try.');
        self::assertSame([], $this->shownTryIdsOfResponse());
        self::assertSame([$this->otherShot, $this->latestTry], $this->sectionIdsShown(), 'Its chain now starts at position 2, after the other screenshot.');
        self::assertSame('Screenshot 2', $this->sectionOf($this->latestTry)->filter('.round-card__screenshot-label > span')->first()->text());
        self::assertCount(0, $this->sectionOf($this->latestTry)->filter('[data-try-label]'));
        self::assertCount(0, $this->sectionOf($this->latestTry)->filter('[data-try-switch]'));
    }

    #[Test]
    public function aShownTryThatBecomesTheLatestLeavesTheSwitchSoALaterRetakeOpensOnTheNewLatest(): void
    {
        $this->seedWorld();
        $this->act('showTry', ['screenshotId' => $this->firstTry]);
        $this->props = $this->propsOfResponse();

        $this->act('removeScreenshot', ['screenshotId' => $this->latestTry]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        self::assertSame([], $this->shownTryIdsOfResponse(), 'Try 1 is the latest now, so no switch names it.');
        self::assertSame([$this->firstTry, $this->otherShot], $this->sectionIdsShown());
        self::assertCount(1, $this->sectionOf($this->firstTry)->filter('.pin-placer'), 'The latest try has its editor again.');

        $newTry = $this->addRetake($this->draft, $this->firstTry, 3);
        $this->props = $this->propsOfResponse();
        $this->act('setVerdict', ['screenshotId' => $this->otherShot, 'verdict' => 'looks_right']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$newTry, $this->otherShot], $this->sectionIdsShown());
        self::assertSame('Try 2 of 2', $this->sectionOf($newTry)->filter('[data-try-label]')->text());
    }

    #[Test]
    public function anEarlierTryWithoutPinsSaysSoAndOffersNoMarks(): void
    {
        $this->seedWorld();
        $retakeOfOther = $this->addRetake($this->draft, $this->otherShot, 3);
        $this->props = $this->propsOf($this->draft);

        $this->act('showTry', ['screenshotId' => $this->otherShot]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        self::assertSame([$this->latestTry, $this->otherShot], $this->sectionIdsShown());
        $other = $this->sectionOf($this->otherShot);
        self::assertSame('Screenshot 2', $other->filter('.round-card__screenshot-label > span')->first()->text());
        self::assertSame('Try 1 of 2', $other->filter('[data-try-label]')->text());
        self::assertSame('This try has no pins.', $other->filter('.round-card__empty')->text());
        self::assertCount(0, $other->filter('.round-panel__pins'));
        self::assertCount(0, $other->filter('button[data-live-action-param="setPinVerdict"]'));
        self::assertSame([$this->otherShot, $retakeOfOther], $other->filter('[data-try-switch] button')->each(static fn (Crawler $chip): string => (string) $chip->attr('data-live-screenshot-id-param')));
    }

    #[Test]
    public function aDraftSharedInAnotherTabShowsOnlyTheLatestTriesAsTheTeamSeesThemAndHasNoEarlierTryToShow(): void
    {
        $this->seedWorld();
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE rvw_round SET number = 1, shared_at = TIMESTAMPTZ '2026-11-06 11:00:00+00' WHERE id = ?",
            [$this->draft],
        ));
        $before = $this->stored();

        $this->act('showTry', ['screenshotId' => $this->firstTry]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(RoundRefused::SCREENSHOT_NOT_FOUND, $this->refusalShown());
        self::assertSame([$this->otherShot, $this->latestTry], $this->sectionIdsShown(), 'By position, as the shared round lists them.');
        self::assertSame(
            ['Screenshot 2', 'Screenshot 3'],
            $this->sections()->each(static fn (Crawler $section): string => $section->filter('.round-card__screenshot-label > span')->first()->text()),
            'Numbered by stored position, as the findings checklist numbers its pins.',
        );
        self::assertSame('1 of 2 look right', $this->statusShown());
        $response = new Crawler((string) $this->client->getResponse()->getContent());
        self::assertCount(0, $response->filter('[data-try-label]'));
        self::assertCount(0, $response->filter('[data-try-switch]'));
        self::assertSame($before, $this->stored());
    }

    private function seedWorld(): void
    {
        $this->ticketId = $this->addTicket('SHOP-1');
        $this->draft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $this->firstTry = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 0, null, null, 'not_right');
        $this->otherShot = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 1, null, null);
        $this->latestTry = $this->addRetake($this->draft, $this->firstTry, 2, 'looks_right');
        $this->firstTryPin = $this->addPin($this->firstTry, 1, '10.00', '20.00', self::FIRST_TRY_NOTE, 'Omar');
        $this->addPin($this->firstTry, 2, '50.00', '50.00', self::DISMISSED_NOTE, 'Omar', 'ai', 'dismissed');
        $this->latestTryPin = $this->addPin($this->latestTry, 1, '30.00', '40.00', self::LATEST_TRY_NOTE, 'Omar');
        $this->miasDraft = $this->addRound($this->ticketId, null, 'draft', 'Mia', null);
        $miasShot = $this->addScreenshot($this->miasDraft, $this->addMedia('ready', 'Mia'), 0, 'Mia shot', null);
        $this->miasPin = $this->addPin($miasShot, 1, '70.00', '70.00', 'Mia note', 'Mia');

        $this->logIn($this->client, $this->people['Omar']);
        $this->props = $this->propsOf($this->draft);
    }

    /**
     * A later try of $firstTryId's chain, as the retake commands store it: pointing at the chain's first try.
     */
    private function addRetake(string $roundId, string $firstTryId, int $position, ?string $verdict = null): string
    {
        $screenshotId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $screenshotId, $roundId, $this->addMedia('ready', 'Omar'), $position, $verdict, $firstTryId];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, verdict, retake_of, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '2026-11-05 08:30:00+00')",
            $parameters,
        ));

        return $screenshotId;
    }

    private function propsOf(string $roundId): string
    {
        $frame = $this->client->request('GET', self::FRAME, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $editor = $frame->filter('#round-'.$roundId.' .draft-round-editor');
        self::assertCount(1, $editor);
        $props = (string) $editor->attr('data-live-props-value');
        self::assertNotSame('', $props);

        return $props;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function act(string $action, array $args): void
    {
        $this->postLiveAction($this->client, $this->props, $action, $args);
    }

    /**
     * The props the last response rendered, so the next action carries the try the owner switched to.
     */
    private function propsOfResponse(): string
    {
        self::assertResponseStatusCodeSame(200);
        $editor = new Crawler((string) $this->client->getResponse()->getContent())->filter('.draft-round-editor');
        self::assertCount(1, $editor);
        $props = (string) $editor->attr('data-live-props-value');
        self::assertNotSame('', $props);

        return $props;
    }

    /**
     * @return list<mixed>
     */
    private function shownTryIdsOfResponse(): array
    {
        $props = json_decode($this->propsOfResponse(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertIsArray($props['shownTryIds'] ?? null);

        return array_values($props['shownTryIds']);
    }

    /**
     * @return list<string> the screenshot ids of the sections in the last response, in order
     */
    private function sectionIdsShown(): array
    {
        $sections = $this->sections();
        self::assertGreaterThan(0, \count($sections), 'The response holds the draft\'s card.');

        return $sections->each(static fn (Crawler $section): string => (string) $section->attr('data-screenshot-id'));
    }

    private function sectionOf(string $screenshotId): Crawler
    {
        $section = $this->sections()->filter('section[data-screenshot-id="'.$screenshotId.'"]');
        self::assertCount(1, $section, 'One section shows '.$screenshotId);

        return $section;
    }

    private function sections(): Crawler
    {
        return new Crawler((string) $this->client->getResponse()->getContent())->filter('section[data-screenshot-id]');
    }

    /**
     * @return list<array{string, string, string, list<string>}> each row's pin id, note, mark and chip texts
     */
    private function pinRowsShown(Crawler $section): array
    {
        $rows = $section->filter('.round-panel__pins li[data-pin-id]');
        self::assertGreaterThan(0, \count($rows));

        return $rows->each(static fn (Crawler $row): array => [
            (string) $row->attr('data-pin-id'),
            $row->filter('.round-panel__note')->text(),
            $row->filter('.verdict-tag')->text(),
            $row->filter('button[aria-pressed]')->each(static fn (Crawler $chip): string => $chip->text()),
        ]);
    }

    private function statusShown(): ?string
    {
        $status = new Crawler((string) $this->client->getResponse()->getContent())->filter('.round-card__header [data-draft-status]');

        return 0 === \count($status) ? null : $status->text();
    }

    private function refusalShown(): ?string
    {
        $refusal = new Crawler((string) $this->client->getResponse()->getContent())->filter('.draft-round-editor__refusal');

        return 0 === \count($refusal) ? null : $refusal->text();
    }

    /**
     * What a wrong lookup or a stray write would show: the ticket's rounds, the screenshots of both drafts with their
     * chain, and every pin's mark.
     *
     * @return array{rounds: list<array<string, mixed>>, screenshots: array<string, list<array{mixed, mixed, mixed}>>, pins: array<mixed>}
     */
    private function stored(): array
    {
        $rounds = $this->roundRowsOf($this->ticketId);
        self::assertCount(2, $rounds);
        $screenshots = [];
        foreach ([$this->draft, $this->miasDraft] as $roundId) {
            $screenshots[$roundId] = $this->screenshotChainOf($roundId);
        }

        return ['rounds' => $rounds, 'screenshots' => $screenshots, 'pins' => $this->pinVerdictsOf()];
    }

    /**
     * @return list<array{mixed, mixed, mixed}> id, position and retake_of of each screenshot, by position
     */
    private function screenshotChainOf(string $roundId): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundId, &$rows): int {
            $rows = $migrator->fetchAllNumeric('SELECT id, position, retake_of FROM rvw_screenshot WHERE round_id = ? ORDER BY position', [$roundId]);

            return \count($rows);
        });

        return array_map(static fn (array $row): array => [$row[0], $row[1], $row[2]], $rows);
    }

    /**
     * @return array<mixed> each pin's verdict by pin id, of both drafts
     */
    private function pinVerdictsOf(): array
    {
        $verdicts = [];
        $roundIds = [$this->draft, $this->miasDraft];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundIds, &$verdicts): int {
            $verdicts = $migrator->fetchAllKeyValue(
                'SELECT p.id, p.verdict FROM rvw_pin p JOIN rvw_screenshot s ON s.organization_id = p.organization_id AND s.id = p.screenshot_id WHERE s.round_id IN (?, ?) ORDER BY p.id',
                $roundIds,
            );

            return \count($verdicts);
        });
        self::assertNotSame([], $verdicts);

        return $verdicts;
    }

    private function draftVersion(): int
    {
        foreach ($this->roundRowsOf($this->ticketId) as $row) {
            if ($this->draft === $row['id']) {
                self::assertIsInt($row['version']);

                return $row['version'];
            }
        }
        self::fail('No draft round on the ticket.');
    }
}
