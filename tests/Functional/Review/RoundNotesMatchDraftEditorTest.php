<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\PinNote;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundNotes;
use App\Review\Api\RoundQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * P05.T8: "roundNotes returns exactly what the editor shows". Omar's draft of SHOP-1 is rendered by the real Rounds
 * frame (the DraftRoundEditor card), then read through RoundQueries::roundNotes, and the two are compared field by
 * field: the screenshots and their order, title, summary, verdict and each pin's number, x, y and note. Around the draft are
 * the rows neither may show: Mia's draft of the same ticket, Omar's shared round, and a screenshot Omar removed through
 * the editor's own action. The clock is fixed on 6 Nov 2026, before today.
 */
final class RoundNotesMatchDraftEditorTest extends WebTestCase
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

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $draft;
    private string $miasShot;
    private string $sharedShot;

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
    public function roundNotesHoldTheScreenshotsTextsVerdictsAndPinsWithPositionsInTheOrderTheEditorShowsThem(): void
    {
        $removedShot = $this->seedWorld();
        $this->logIn($this->client, $this->people['Omar']);
        $this->removeInTheEditor($removedShot);

        $shown = $this->shownByTheEditor();
        $notes = $this->notesOf('Omar');

        self::assertNotNull($notes);
        self::assertSame($this->draft, $notes->roundId);
        self::assertCount(3, $shown, 'Four pictures were seeded and one removed.');
        $fromNotes = [];
        foreach ($notes->screenshots as $screenshot) {
            $fromNotes[$screenshot->screenshotId] = [
                'title' => $screenshot->title,
                'summary' => $screenshot->summary,
                'verdict' => $screenshot->verdict,
                'pins' => array_map(static fn (PinNote $pin): array => ['number' => $pin->number, 'x' => $pin->xPercent, 'y' => $pin->yPercent, 'note' => $pin->note], $screenshot->pins),
            ];
        }
        $fromEditor = array_map(static fn (array $picture): array => [
            'title' => $picture['title'],
            'summary' => $picture['summary'],
            'verdict' => $picture['verdict'],
            'pins' => array_map(static fn (int $number, array $pin): array => ['number' => $number, 'x' => (float) $pin['x'], 'y' => (float) $pin['y'], 'note' => $pin['note']], array_keys($picture['pins']), $picture['pins']),
        ], $shown);

        self::assertSame($fromEditor, $fromNotes, 'The same pictures in the same order with the same texts, verdicts and pins (number, x, y, note).');
        self::assertNotContains($removedShot, array_keys($fromNotes));
        self::assertSame(
            [['number' => 1, 'x' => 10.0, 'y' => 20.0, 'note' => 'Button hidden'], ['number' => 2, 'x' => 55.5, 'y' => 60.25, 'note' => 'Wrong colour'], ['number' => 3, 'x' => 90.0, 'y' => 5.0, 'note' => 'Prix affiché en €']],
            $fromNotes[array_key_first($fromNotes)]['pins'],
            'The first picture\'s pins were inserted as 3, 1, 2 and come back by number with their positions.',
        );
    }

    #[Test]
    public function anotherPersonsDraftASharedRoundAndARemovedPictureAreInNeitherTheEditorNorTheNotes(): void
    {
        $removedShot = $this->seedWorld();
        $this->logIn($this->client, $this->people['Omar']);
        $this->removeInTheEditor($removedShot);

        $editorIds = array_keys($this->shownByTheEditor());
        $notes = $this->notesOf('Omar');

        self::assertNotNull($notes);
        $noteIds = array_map(static fn ($screenshot): string => $screenshot->screenshotId, $notes->screenshots);
        self::assertNotSame([], $noteIds);
        foreach ([$this->miasShot, $this->sharedShot, $removedShot] as $foreign) {
            self::assertNotContains($foreign, $editorIds);
            self::assertNotContains($foreign, $noteIds);
        }
        $miasNotes = $this->notesOf('Mia');
        self::assertNotNull($miasNotes);
        self::assertSame([$this->miasShot], array_map(static fn ($screenshot): string => $screenshot->screenshotId, $miasNotes->screenshots));
    }

    /**
     * @return string the id of the removed picture, which sits between the first and the second of the others
     */
    private function seedWorld(): string
    {
        $this->ticketId = $this->addTicket('SHOP-1');
        $this->draft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        // Inserted in the order 2, 0, 3, 1 so neither insertion order nor id order is the position order.
        $third = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 2, null, 'Only a summary, with an é and an € sign', 'looks_right');
        $first = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 0, 'Omar first', 'The total is off', 'not_right');
        $untouched = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 3, null, null);
        $removed = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 1, 'Omar removed', 'Goes away', 'not_right');
        $this->addPinsOf($first, [[3, '90.00', '5.00', 'Prix affiché en €'], [1, '10.00', '20.00', 'Button hidden'], [2, '55.50', '60.25', 'Wrong colour']]);
        $this->addPinsOf($third, [[1, '30.00', '40.00', 'Fine here']]);
        $this->addPinsOf($removed, [[1, '1.00', '1.00', 'Removed pin']]);
        self::assertNotSame('', $untouched);

        $miasDraft = $this->addRound($this->ticketId, null, 'draft', 'Mia', null);
        $this->miasShot = $this->addScreenshot($miasDraft, $this->addMedia('ready', 'Mia'), 0, 'Mia shot', 'Mia summary', 'looks_right');
        $this->addPinsOf($this->miasShot, [[1, '70.00', '70.00', 'Mia note']]);
        $shared = $this->addRound($this->ticketId, 1, 'draft', 'Omar', '2026-11-05 09:00:00+00');
        $this->sharedShot = $this->addScreenshot($shared, $this->addMedia('ready', 'Omar'), 0, 'Shared shot', null, 'looks_right');
        $this->addPinsOf($this->sharedShot, [[1, '80.00', '80.00', 'Shared note']]);

        return $removed;
    }

    /**
     * @param list<array{int, string, string, string}> $pins number, x, y and note, in the order they are inserted
     */
    private function addPinsOf(string $screenshotId, array $pins): void
    {
        foreach ($pins as [$number, $x, $y, $note]) {
            $this->addPin($screenshotId, $number, $x, $y, $note, 'Omar');
        }
    }

    private function removeInTheEditor(string $screenshotId): void
    {
        $frame = $this->client->request('GET', self::FRAME, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $editor = $frame->filter('#round-'.$this->draft.' .draft-round-editor');
        self::assertCount(1, $editor);

        $this->postLiveAction($this->client, (string) $editor->attr('data-live-props-value'), 'removeScreenshot', ['screenshotId' => $screenshotId]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, new Crawler((string) $this->client->getResponse()->getContent())->filter('.draft-round-editor__refusal'));
    }

    /**
     * What the owner's page shows of the draft, from the rendered HTML: by screenshot id in the order shown.
     *
     * @return array<string, array{title: ?string, summary: ?string, verdict: ?string, pins: array<int, array{x: string, y: string, note: string}>}>
     */
    private function shownByTheEditor(): array
    {
        $frame = $this->client->request('GET', self::FRAME, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $editor = $frame->filter('#round-'.$this->draft.' .draft-round-editor');
        self::assertCount(1, $editor);

        $shown = [];
        foreach ($editor->filter('section[data-screenshot-id]') as $section) {
            $shot = new Crawler($section);
            $pressed = $shot->filter('[role="group"] button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('aria-pressed'));
            self::assertCount(2, $pressed);
            $verdict = match ($pressed) {
                ['true', 'false'] => RoundCommands::VERDICT_LOOKS_RIGHT,
                ['false', 'true'] => RoundCommands::VERDICT_NOT_RIGHT_YET,
                ['false', 'false'] => null,
                default => self::fail('Both verdict toggles are pressed.'),
            };
            $coordinates = [];
            foreach ($shot->filter('.pin-layer .pin[data-pin-number]') as $pin) {
                $pinCrawler = new Crawler($pin);
                $coordinates[(int) $pinCrawler->attr('data-pin-number')] = [(string) $pinCrawler->attr('data-x-percent'), (string) $pinCrawler->attr('data-y-percent')];
            }
            $pins = [];
            foreach ($shot->filter('.pin-list__item') as $item) {
                $itemCrawler = new Crawler($item);
                $number = (int) $itemCrawler->attr('data-pin-number');
                self::assertArrayHasKey($number, $coordinates);
                $pins[$number] = ['x' => $coordinates[$number][0], 'y' => $coordinates[$number][1], 'note' => $itemCrawler->filter('textarea')->text('', false)];
            }
            $title = (string) $shot->filter('[data-field="title"]')->attr('value');
            $summary = $shot->filter('[data-field="summary"]')->text('', false);
            $shown[(string) $shot->attr('data-screenshot-id')] = [
                'title' => '' === $title ? null : $title,
                'summary' => '' === $summary ? null : $summary,
                'verdict' => $verdict,
                'pins' => $pins,
            ];
        }
        self::assertNotSame([], $shown);

        return $shown;
    }

    private function notesOf(string $owner): ?RoundNotes
    {
        $container = self::getContainer();
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $tenantContext = $container->get(TenantContext::class);
        $roundQueries = $container->get('test.review_round_queries');
        \assert($roundQueries instanceof RoundQueries);

        return new TenantTransactions($entityManager, $tenantContext)->rolledBackAs(
            $this->acmeFoods->toString(),
            fn (): ?RoundNotes => $roundQueries->roundNotes(new TicketId($this->ticketId), new UserId($this->people[$owner]->id()->toString())),
        );
    }
}
