<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\RoundRefused;
use App\Review\Domain\Round;
use App\Review\UI\RoundCards;
use App\SharedKernel\Domain\OrganizationId;
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
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Test\Factories;

/**
 * The live actions of the owner's draft round on Acme Foods' ticket SHOP-1 (P05.T8, ADR-0017) through the real
 * firewall, voters and database: what each action stores, what it refuses with which reason, and who is answered 404.
 * Omar is the ticket's assignee and owns the draft; Lena leads the Shop team, Mia is a member who owns another draft on
 * the same ticket, Raj is a manager who only views the team, Sara is an admin, Ben belongs to Beta Bakery. The clock is
 * fixed on 6 Nov 2026, before today: a clock ahead of the login would end the session as idle.
 */
final class DraftRoundEditorTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use InteractsWithLiveComponents;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string FRAME = '/t/SHOP-1/rounds';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => 'ticket-rounds'];
    private const string OMAR_SHOT_TITLE = 'Omar first shot';
    private const string SHARED_AT = '2026-11-05 09:00:00+00';
    private const int LIVE_ACTION_QUERIES = 27;
    private const array STRANGERS = ['Lena', 'Mia', 'Sara', 'Raj', 'Ben'];

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $draft;
    private string $firstShot;
    private string $secondShot;
    private string $otherTicketId;
    private string $miasDraft;
    private string $miasShot;
    private string $otherTicketsDraft;
    /** The editor's props as the owner's page rendered them: signed, so only the round's voter guards the actions. */
    private string $props;
    private ?OrganizationId $betaBakery = null;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->addTeamRole($this->people['Raj'], $this->acmeFoods, $this->shop, 'viewer');
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
    public function aReadyPictureTheOwnerUploadedIsAddedAtTheEndOfTheDraft(): void
    {
        $this->seedWorld();
        $before = $this->stored();
        $mediaId = $this->addMedia('ready', 'Omar');

        $this->act('addScreenshot', ['mediaId' => $mediaId]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        $rows = $this->screenshotRowsOf($this->draft);
        self::assertCount(3, $rows);
        self::assertSame([$this->firstShot, $this->secondShot], [$rows[0]['id'], $rows[1]['id']]);
        self::assertSame(2, $rows[2]['position']);
        self::assertSame($before['screenshots'][$this->miasDraft], $this->screenshotRowsOf($this->miasDraft));
        self::assertSame($before['screenshots'][$this->otherTicketsDraft], $this->screenshotRowsOf($this->otherTicketsDraft));
        self::assertSame(2, $this->roundRow($this->ticketId, $this->draft)['version'], 'One accepted change adds one.');
    }

    #[Test]
    public function aPictureThatIsNotTheOwnersOrNotReadyIsRefusedWithItsReasonAndNothingIsStored(): void
    {
        $this->seedWorld();
        $betaMedia = $this->mediaOfBetaBakery();
        $cases = [
            'uploaded by Mia' => [$this->addMedia('ready', 'Mia'), RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU],
            'of another organization' => [$betaMedia, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU],
            'unknown' => [Uuid::v7()->toRfc4122(), RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU],
            'malformed' => ['not-a-uuid', RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU],
            'still processing' => [$this->addMedia('pending', 'Omar'), RoundRefused::MEDIA_STILL_PROCESSING],
            'rejected' => [$this->addMedia('rejected', 'Omar'), RoundRefused::MEDIA_REJECTED],
        ];
        $before = $this->stored();

        foreach ($cases as $name => [$mediaId, $reason]) {
            $this->act('addScreenshot', ['mediaId' => $mediaId]);

            self::assertResponseStatusCodeSame(200, $name);
            self::assertSame($reason, $this->refusalShown(), $name);
            self::assertSame($before, $this->stored(), $name.': nothing is stored.');
        }
    }

    #[Test]
    public function aDraftAlreadyHoldingTheMostScreenshotsRefusesAnotherAndStoresNothing(): void
    {
        $this->seedWorld();
        for ($position = 2; $position < Round::MAX_SCREENSHOTS; ++$position) {
            $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), $position, null, null);
        }
        $before = $this->stored();
        self::assertCount(Round::MAX_SCREENSHOTS, $before['screenshots'][$this->draft]);

        $this->act('addScreenshot', ['mediaId' => $this->addMedia('ready', 'Omar')]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(\sprintf('A round holds at most %d screenshots.', Round::MAX_SCREENSHOTS), $this->refusalShown());
        self::assertSame($before, $this->stored());
    }

    #[Test]
    public function removingAScreenshotTakesOnlyThatPictureWithItsPinsAndAnUnknownOrForeignOneIsRefused(): void
    {
        $this->seedWorld();
        $before = $this->stored();

        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid', $this->miasShot] as $screenshotId) {
            $this->act('removeScreenshot', ['screenshotId' => $screenshotId]);

            self::assertResponseStatusCodeSame(200, $screenshotId);
            self::assertSame(RoundRefused::SCREENSHOT_NOT_FOUND, $this->refusalShown(), $screenshotId);
            self::assertSame($before, $this->stored(), $screenshotId);
        }

        $this->act('removeScreenshot', ['screenshotId' => $this->firstShot]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        $after = $this->stored();
        self::assertSame([$this->secondShot], array_column($after['screenshots'][$this->draft], 'id'));
        self::assertSame([], $after['pins'][$this->draft], 'The pins go with their picture.');
        self::assertSame($before['screenshots'][$this->miasDraft], $after['screenshots'][$this->miasDraft]);
        self::assertSame($before['pins'][$this->miasDraft], $after['pins'][$this->miasDraft]);
        self::assertNotSame([], $after['pins'][$this->miasDraft]);
    }

    #[Test]
    public function titleAndSummaryAreSavedUpToTheirLimitsCountedInCharactersAndRefusedPastThem(): void
    {
        $this->seedWorld();
        $title = str_repeat('ü', 119).'😀';
        $summary = str_repeat('é', 599).'😀';
        self::assertGreaterThan(120, \strlen($title), 'The limit counts characters, not bytes.');

        $this->act('editScreenshotText', ['screenshotId' => $this->secondShot, 'title' => $title, 'summary' => $summary]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        $saved = $this->screenshotRowsOf($this->draft);
        self::assertSame([$title, $summary], [$saved[1]['title'], $saved[1]['summary']]);
        $before = $this->stored();

        $tooLong = [
            'title' => [$title.'x', $summary, 'A picture title holds at most 120 characters.'],
            'summary' => [$title, $summary.'x', 'A picture summary holds at most 600 characters.'],
        ];
        foreach ($tooLong as $name => [$longTitle, $longSummary, $reason]) {
            $this->act('editScreenshotText', ['screenshotId' => $this->secondShot, 'title' => $longTitle, 'summary' => $longSummary]);

            self::assertResponseStatusCodeSame(200, $name);
            self::assertSame($reason, $this->refusalShown(), $name);
            self::assertSame($before, $this->stored(), $name);
        }

        $this->act('editScreenshotText', ['screenshotId' => $this->secondShot, 'title' => '   ', 'summary' => '']);

        self::assertResponseStatusCodeSame(200);
        $cleared = $this->screenshotRowsOf($this->draft);
        self::assertNull($cleared[1]['title'], 'A blank title is none.');
        self::assertNull($cleared[1]['summary']);
        self::assertSame($before['screenshots'][$this->miasDraft], $this->screenshotRowsOf($this->miasDraft));
    }

    #[Test]
    public function aVerdictIsSetChangedAndClearedByAnEmptyOneAndAnUnknownOneIsRefusedWithTheStoredVerdictKept(): void
    {
        $this->seedWorld();

        $this->act('setVerdict', ['screenshotId' => $this->secondShot, 'verdict' => 'not_right']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['looks_right', 'not_right'], array_column($this->screenshotRowsOf($this->draft), 'verdict'));

        $this->act('setVerdict', ['screenshotId' => $this->firstShot, 'verdict' => '']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([null, 'not_right'], array_column($this->screenshotRowsOf($this->draft), 'verdict'));
        $before = $this->stored();

        $this->act('setVerdict', ['screenshotId' => $this->secondShot, 'verdict' => 'fine']);

        self::assertResponseStatusCodeSame(200, 'An unknown verdict is a refusal shown in the card, not a 400.');
        self::assertSame(RoundRefused::UNKNOWN_VERDICT, $this->refusalShown());
        self::assertSame($before, $this->stored());
        self::assertSame('looks_right', $this->screenshotRowsOf($this->miasDraft)[0]['verdict'], 'Mia\'s verdict is untouched.');
    }

    #[Test]
    public function pinsReplaceTheScreenshotsPinsInOrderAndTooManyOrTooLongANoteAreRefusedWithTheOldPinsKept(): void
    {
        $this->seedWorld();

        $this->act('setPins', ['screenshotId' => $this->firstShot, 'pins' => [
            ['x' => 5, 'y' => 6.5, 'note' => 'New A'],
            ['x' => 90.25, 'y' => 10, 'note' => str_repeat('ü', 999).'😀'],
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        $pins = $this->pinRowsOf($this->draft);
        self::assertSame([[1, '5.00', '6.50', 'New A'], [2, '90.25', '10.00', str_repeat('ü', 999).'😀']], array_map(static fn (array $pin): array => [$pin['number'], $pin['x_pct'], $pin['y_pct'], $pin['note']], $pins));
        $before = $this->stored();

        $refused = [
            'one past the limit' => [array_fill(0, 101, ['x' => 1, 'y' => 1, 'note' => '']), 'A picture holds at most 100 pins.'],
            'far past the limit' => [array_fill(0, 500, ['x' => 1, 'y' => 1, 'note' => '']), 'A picture holds at most 100 pins.'],
            'a note of 1001 characters' => [[['x' => 1, 'y' => 1, 'note' => str_repeat('ü', 1000).'😀']], 'A pin note holds at most 1000 characters.'],
            'outside the picture' => [[['x' => 100.5, 'y' => 1, 'note' => 'n']], 'A pin must sit inside the picture: x and y go from 0 to 100.'],
        ];
        foreach ($refused as $name => [$list, $reason]) {
            $this->act('setPins', ['screenshotId' => $this->firstShot, 'pins' => $list]);

            self::assertResponseStatusCodeSame(200, $name);
            self::assertSame($reason, $this->refusalShown(), $name);
            self::assertSame($before, $this->stored(), $name);
        }

        $this->act('setPins', ['screenshotId' => $this->firstShot, 'pins' => array_fill(0, 100, ['x' => 1, 'y' => 2, 'note' => 'n'])]);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        self::assertCount(100, $this->pinRowsOf($this->draft), 'Exactly the limit is accepted.');

        $this->act('setPins', ['screenshotId' => $this->firstShot, 'pins' => []]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->pinRowsOf($this->draft));
    }

    #[Test]
    public function aPinThatIsNotANumberedPlaceWithANoteIsABadRequestAndSavesNothing(): void
    {
        $this->seedWorld();
        $before = $this->stored();

        $malformed = [
            'x as text' => [['x' => '5', 'y' => 5, 'note' => 'n']],
            'no note' => [['x' => 5, 'y' => 5]],
            'note as number' => [['x' => 5, 'y' => 5, 'note' => 7]],
            'not a pin' => ['pin'],
        ];
        foreach ($malformed as $name => $list) {
            $this->act('setPins', ['screenshotId' => $this->firstShot, 'pins' => $list]);

            self::assertResponseStatusCodeSame(400, $name);
            self::assertSame($before, $this->stored(), $name);
        }
    }

    #[Test]
    public function everyActionIsNotFoundForAnyoneButTheOwnerEvenForAnotherDraftOwnerOnTheSameTicketAndChangesNothing(): void
    {
        $this->seedWorld();
        $ben = $this->otherOrganizationsMember();
        $before = $this->stored();
        self::assertNotSame([], $before['pins'][$this->draft]);
        $actions = [
            'addScreenshot' => ['mediaId' => $this->addMedia('ready', 'Omar')],
            'removeScreenshot' => ['screenshotId' => $this->firstShot],
            'editScreenshotText' => ['screenshotId' => $this->firstShot, 'title' => 'Hijacked', 'summary' => 'Hijacked'],
            'setVerdict' => ['screenshotId' => $this->firstShot, 'verdict' => 'not_right'],
            'setPins' => ['screenshotId' => $this->firstShot, 'pins' => []],
        ];

        foreach (self::STRANGERS as $person) {
            $this->logIn($this->client, 'Ben' === $person ? $ben : $this->people[$person]);
            foreach ($actions as $action => $args) {
                $this->postLiveAction($this->client, $this->props, $action, $args);

                self::assertResponseStatusCodeSame(404, $person.' '.$action);
                self::assertStringNotContainsString(self::OMAR_SHOT_TITLE, (string) $this->client->getResponse()->getContent(), $person.' '.$action);
                self::assertSame($before, $this->stored(), $person.' '.$action.': nothing changes.');
            }
        }
    }

    #[Test]
    public function aDraftDeletedAfterTheEditorWasRenderedIsNotFoundForItsOwnerAndStaysDeleted(): void
    {
        $this->seedWorld();
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE rvw_round SET deleted_at = TIMESTAMPTZ '2026-11-06 11:00:00+00', state = 'closed' WHERE id = ?",
            [$this->draft],
        ));
        $before = $this->stored();

        $this->act('setVerdict', ['screenshotId' => $this->firstShot, 'verdict' => 'not_right']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->stored());
    }

    #[Test]
    public function aRoundOfAnotherTicketOrAnUnknownOrMalformedRoundIdIsNotFoundForTheOwnerAndChangesNothing(): void
    {
        $this->seedWorld();
        $before = $this->stored();
        self::assertNotSame([], $before['screenshots'][$this->otherTicketsDraft], 'Omar owns the draft of the other ticket, so only the ticket check refuses it.');
        $cases = [
            'a draft of the owner on another ticket' => $this->otherTicketsDraft,
            'an unknown round' => Uuid::v7()->toRfc4122(),
            'a malformed id' => 'not-a-uuid',
        ];

        foreach ($cases as $name => $roundId) {
            $editor = $this->createLiveComponent('DraftRoundEditor', ['ticketKey' => 'SHOP-1', 'roundId' => $roundId], $this->client);
            // The guard runs on every live request, the first one that mounts the component with these props included.
            $this->client->catchExceptions(true);

            $response = $editor->response();

            self::assertSame(404, $response->getStatusCode(), $name);
            self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $response->getContent(), $name);
            self::assertSame($before, $this->stored(), $name.': nothing changes.');
        }
    }

    #[Test]
    public function aDraftSharedInAnotherTabRefusesEveryActionOfItsOwnerWithTheReasonAndChangesNothing(): void
    {
        $this->seedWorld();
        $unshared = $this->cardHeaderAndPicturesShown();
        self::assertSame('Draft round · only you can see this', $unshared['headline']);
        self::assertTrue($unshared['isPrivate']);
        self::assertSame([$this->firstShot, $this->secondShot], array_keys($unshared['pictures']));
        self::assertNotContains('', $unshared['pictures']);
        $this->shareInTheDatabase($this->draft, 1);
        $before = $this->stored();
        $actions = [
            'addScreenshot' => ['mediaId' => $this->addMedia('ready', 'Omar')],
            'removeScreenshot' => ['screenshotId' => $this->firstShot],
            'editScreenshotText' => ['screenshotId' => $this->firstShot, 'title' => 'Late', 'summary' => ''],
            'setVerdict' => ['screenshotId' => $this->firstShot, 'verdict' => 'not_right'],
            'setPins' => ['screenshotId' => $this->firstShot, 'pins' => []],
        ];

        foreach ($actions as $action => $args) {
            $this->act($action, $args);

            self::assertResponseStatusCodeSame(200, $action);
            self::assertSame('This draft round is shared, so it no longer changes.', $this->refusalShown(), $action);
            self::assertSame($before, $this->stored(), $action);
            $shared = $this->cardHeaderAndPicturesShown();
            self::assertSame('Round 1 · self-check by Omar', $shared['headline'], $action);
            self::assertFalse($shared['isPrivate'], $action.': a shared round is no longer private.');
            self::assertSame($unshared['pictures'], $shared['pictures'], $action);
        }
    }

    #[Test]
    public function aViewerWhoOwnsADraftSharedMeanwhileIsNotFoundBecauseTheRoundVoterDeniesEdit(): void
    {
        $this->seedWorld();
        $rajsDraft = $this->addRound($this->ticketId, null, 'draft', 'Raj', null);
        $rajsShot = $this->addScreenshot($rajsDraft, $this->addMedia('ready', 'Raj'), 0, 'Raj shot', null);
        $this->logIn($this->client, $this->people['Raj']);
        $rajsProps = $this->propsOf($rajsDraft);
        // Shared, Raj may still view it as a viewer of the team, but not change it. Without the EDIT check the command would
        // answer its "shared" refusal, a 200, and not the 404.
        $this->shareInTheDatabase($rajsDraft, 1);
        $before = $this->stored();

        foreach (['setVerdict' => ['screenshotId' => $rajsShot, 'verdict' => 'not_right'], 'removeScreenshot' => ['screenshotId' => $rajsShot]] as $action => $args) {
            $this->postLiveAction($this->client, $rajsProps, $action, $args);

            self::assertResponseStatusCodeSame(404, $action);
            self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent(), $action);
            self::assertSame($before, $this->stored(), $action);
        }
    }

    #[Test]
    public function theResponseCardShowsTheDraftAsStoredAfterAnAcceptedAndAfterARefusedChange(): void
    {
        $this->seedWorld();
        self::assertSame(
            ['looks_right', null],
            array_column($this->screenshotRowsOf($this->draft), 'verdict'),
            'The first picture starts as looks right, the second with none.',
        );

        $this->act('setVerdict', ['screenshotId' => $this->secondShot, 'verdict' => 'not_right']);

        self::assertResponseStatusCodeSame(200);
        $accepted = $this->cardShown();
        self::assertSame([$this->firstShot, $this->secondShot], $accepted['ids']);
        self::assertSame([$this->firstShot => ['true', 'false'], $this->secondShot => ['false', 'true']], $accepted['pressed']);
        self::assertSame('1 of 2 look right', $accepted['status']);

        $this->act('setVerdict', ['screenshotId' => $this->secondShot, 'verdict' => 'fine']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(RoundRefused::UNKNOWN_VERDICT, $this->refusalShown());
        self::assertSame($accepted, $this->cardShown(), 'A refused change leaves the card as stored.');

        $this->act('setVerdict', ['screenshotId' => $this->secondShot, 'verdict' => 'looks_right']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('2 of 2 look right', $this->cardShown()['status']);
    }

    #[Test]
    public function theEditorOfADraftWithTheMostScreenshotsShowsEachOneWithItsControlsAndCountsAllOfThem(): void
    {
        $this->seedWorld();
        $shotIds = [$this->firstShot, $this->secondShot];
        for ($position = 2; $position < Round::MAX_SCREENSHOTS; ++$position) {
            $shotIds[] = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), $position, null, null);
        }
        self::assertGreaterThan(RoundCards::MAX_PICTURES_PER_CARD + 1, \count($shotIds));
        $this->props = $this->propsOf($this->draft);

        $firstRender = $this->client->request('GET', self::FRAME, [], [], self::TURBO_FRAME_HEADERS);
        $this->assertEveryShotHasItsControls($firstRender, $shotIds);

        $eleventh = $shotIds[RoundCards::MAX_PICTURES_PER_CARD];
        $this->act('setVerdict', ['screenshotId' => $eleventh, 'verdict' => 'looks_right']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('looks_right', $this->screenshotRowsOf($this->draft)[RoundCards::MAX_PICTURES_PER_CARD]['verdict']);
        $card = $this->cardShown();
        self::assertSame($shotIds, $card['ids']);
        self::assertSame(['true', 'false'], $card['pressed'][$eleventh]);
        self::assertSame(\sprintf('2 of %d look right', Round::MAX_SCREENSHOTS), $card['status']);
        $this->assertEveryShotHasItsControls(new Crawler((string) $this->client->getResponse()->getContent()), $shotIds);

        $this->act('removeScreenshot', ['screenshotId' => $shotIds[Round::MAX_SCREENSHOTS - 1]]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->refusalShown());
        self::assertCount(Round::MAX_SCREENSHOTS - 1, $this->cardShown()['ids']);
    }

    #[Test]
    public function aLiveActionReadsOnlyTheDraftNotTheWholeRoundsSection(): void
    {
        $this->seedWorld();
        $report = $this->addRound($this->ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addScreenshot($report, $this->addMedia('ready', 'Mia'), 0, 'Report shot', null);
        $this->addRound($this->ticketId, 1, 'checklist', 'Omar', self::SHARED_AT);
        $this->addRound($this->ticketId, 2, 'review', 'Lena', self::SHARED_AT);
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $this->act('setVerdict', ['screenshotId' => $this->secondShot, 'verdict' => 'not_right']);

        self::assertResponseStatusCodeSame(200);
        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }
        // Measured with two other rounds seeded: reading the whole section instead would add their queries, the names and the signing.
        self::assertSame(self::LIVE_ACTION_QUERIES, $queryCount);
    }

    private function seedWorld(): void
    {
        $this->ticketId = $this->addTicket('SHOP-1');
        $this->otherTicketId = $this->addTicket('SHOP-2', 'all', 2);
        $this->draft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $this->firstShot = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 0, self::OMAR_SHOT_TITLE, 'What the total shows', 'looks_right');
        $this->secondShot = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 1, null, null);
        $this->addPin($this->firstShot, 1, '10.00', '20.00', 'Omar note', 'Omar');
        $this->miasDraft = $this->addRound($this->ticketId, null, 'draft', 'Mia', null);
        $this->miasShot = $this->addScreenshot($this->miasDraft, $this->addMedia('ready', 'Mia'), 0, 'Mia shot', 'Mia summary', 'looks_right');
        $this->addPin($this->miasShot, 1, '30.00', '40.00', 'Mia note', 'Mia');
        $this->otherTicketsDraft = $this->addRound($this->otherTicketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($this->otherTicketsDraft, $this->addMedia('ready', 'Omar'), 0, 'Other ticket shot', null);

        $this->logIn($this->client, $this->people['Omar']);
        $this->props = $this->propsOf($this->draft);
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
     * The card of the last response, by data attributes and aria only.
     *
     * @return array{ids: list<string>, pressed: array<string, list<string>>, status: ?string}
     */
    private function cardShown(): array
    {
        $response = new Crawler((string) $this->client->getResponse()->getContent());
        $sections = $response->filter('section[data-screenshot-id]');
        self::assertGreaterThan(0, \count($sections), 'The response holds the draft\'s card.');
        $ids = [];
        $pressed = [];
        foreach ($sections as $section) {
            $shot = new Crawler($section);
            $id = (string) $shot->attr('data-screenshot-id');
            $ids[] = $id;
            $pressed[$id] = $shot->filter('[role="group"] button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('aria-pressed'));
        }
        $status = $response->filter('.round-card__header [data-draft-status]');

        return ['ids' => $ids, 'pressed' => $pressed, 'status' => 0 === \count($status) ? null : $status->text()];
    }

    /**
     * @param list<string> $shotIds
     */
    private function assertEveryShotHasItsControls(Crawler $page, array $shotIds): void
    {
        $sections = $page->filter('section[data-screenshot-id]');
        self::assertSame($shotIds, $sections->each(static fn (Crawler $section): ?string => $section->attr('data-screenshot-id')));
        foreach ($sections as $section) {
            $shot = new Crawler($section);
            $id = (string) $shot->attr('data-screenshot-id');
            self::assertCount(2, $shot->filter('[role="group"] button[aria-pressed]'), $id.' has both verdict toggles.');
            self::assertCount(1, $shot->filter('button[data-live-action-param="removeScreenshot"]'), $id.' has Remove.');
            $image = $shot->filter('img.screenshot__image');
            self::assertCount(1, $image, $id.' shows its picture.');
            self::assertNotSame('', (string) $image->attr('src'), $id.' has a signed image.');
        }
    }

    /**
     * The headline, the PRIVATE tag and the pictures of the card in the last response.
     *
     * @return array{headline: string, isPrivate: bool, pictures: array<string, string>} pictures: image src by screenshot id
     */
    private function cardHeaderAndPicturesShown(): array
    {
        $response = new Crawler((string) $this->client->getResponse()->getContent());
        $pictures = [];
        foreach ($response->filter('section[data-screenshot-id]') as $section) {
            $shot = new Crawler($section);
            $image = $shot->filter('img.screenshot__image');
            $pictures[(string) $shot->attr('data-screenshot-id')] = 0 === \count($image) ? '' : (string) $image->attr('src');
        }

        return [
            'headline' => $response->filter('.round-card__header .round-card__headline')->text(),
            'isPrivate' => 0 !== \count($response->filter('.round-card__header .round-card__private')),
            'pictures' => $pictures,
        ];
    }

    private function refusalShown(): ?string
    {
        $refusal = new Crawler((string) $this->client->getResponse()->getContent())->filter('.draft-round-editor__refusal');

        return 0 === \count($refusal) ? null : $refusal->text();
    }

    /**
     * What a wrong lookup or a stray write would show: every round of both tickets, and their screenshots and pins.
     *
     * @return array{rounds: list<array<string, mixed>>, screenshots: array<string, list<array<string, mixed>>>, pins: array<string, list<array<string, mixed>>>}
     */
    private function stored(): array
    {
        $rounds = [...$this->roundRowsOf($this->ticketId), ...$this->roundRowsOf($this->otherTicketId)];
        self::assertGreaterThanOrEqual(3, \count($rounds));
        $screenshots = [];
        $pins = [];
        foreach (array_column($rounds, 'id') as $roundId) {
            self::assertIsString($roundId);
            $screenshots[$roundId] = $this->screenshotRowsOf($roundId);
            $pins[$roundId] = $this->pinRowsOf($roundId);
        }

        return ['rounds' => $rounds, 'screenshots' => $screenshots, 'pins' => $pins];
    }

    /**
     * @return array<string, mixed>
     */
    private function roundRow(string $ticketId, string $roundId): array
    {
        foreach ($this->roundRowsOf($ticketId) as $row) {
            if ($roundId === $row['id']) {
                return $row;
            }
        }
        self::fail('No round '.$roundId.' on the ticket.');
    }

    private function shareInTheDatabase(string $roundId, int $number): void
    {
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE rvw_round SET number = ?, shared_at = TIMESTAMPTZ '2026-11-06 11:00:00+00' WHERE id = ?",
            [$number, $roundId],
        ));
    }

    /**
     * Before any request: an action that throws in its transaction leaves the test's entity manager closed.
     */
    private function otherOrganizationsMember(): User
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->betaBakery = $betaBakery;

        return $ben;
    }

    /**
     * A ready picture of another organization, uploaded by that organization's own member.
     */
    private function mediaOfBetaBakery(): string
    {
        $ben = $this->otherOrganizationsMember();
        $betaBakery = $this->betaBakery ?? throw new \LogicException('Beta Bakery was just created.');
        $mediaId = Uuid::v7()->toRfc4122();
        $variants = json_encode(['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp'], \JSON_THROW_ON_ERROR);
        $parameters = [$betaBakery->toString(), $mediaId, 'uploads/'.$mediaId, $ben->id()->toString(), hash('sha256', $mediaId), $variants];
        $this->committedAs($betaBakery, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, sha256, width, height, variants)
             VALUES (?, ?, ?, 'image/png', 2048, ?, '2026-11-05 08:00:00+00', 'ready', ?, 1280, 800, CAST(? AS jsonb))",
            $parameters,
        ));

        return $mediaId;
    }
}
