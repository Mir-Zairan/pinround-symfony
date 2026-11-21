<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\UI\DraftRoundController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * A retake made through the product's own path (P14.T6, ADR-0048): Omar posts `retake_of` to REST
 * `POST /api/v1/tickets/SHOP-1/draft/screenshots`, then reads his draft on the ticket page and shares it. His draft
 * holds try 1 of the first screenshot with a pin and a second screenshot with a pin; Mia owns another draft of the
 * same ticket with a pin, which nothing here may show. A second shared round compares its screenshots with the round
 * before it (Before / After). The clock is fixed on 10 Nov 2026, a day aud_event has a
 * partition for and not today.
 */
final class DraftScreenshotRetakeTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const string FRAME = '/t/SHOP-1/rounds';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => 'ticket-rounds'];
    private const string FIRST_TRY_TITLE = 'Cart before the fix';
    private const string FIRST_TRY_NOTE = 'The total is off';
    private const string RETAKE_TITLE = 'Cart after the fix';
    private const string RETAKE_NOTE = 'The badge still overlaps';
    private const string OTHER_TITLE = 'Checkout page';
    private const string OTHER_NOTE = 'The fee is missing';
    private const string MIAS_NOTE = 'Mia note';
    private const string NEXT_FIRST_TRY_TITLE = 'Cart in round two';
    private const string NEXT_FIRST_TRY_NOTE = 'The badge is gone';
    private const string NEXT_RETAKE_TITLE = 'Cart fixed in round two';
    private const string NEXT_RETAKE_NOTE = 'The total is right now';
    private const string NEW_SCREEN_TITLE = 'Delivery fee page';
    private const string NEW_SCREEN_NOTE = 'The fee shows twice';
    private const string CAPTION = '.round-compare__caption';
    private const string BEFORE_SIDE = '.round-compare__side--before';
    private const string AFTER_SIDE = '.round-compare__side--after';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $draft;
    private string $firstTry;
    private string $firstTryPin;
    private string $otherShot;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $this->stepId];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            $parameters,
        ));
        $this->ticketId = $this->addTicket('SHOP-1');
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['aud_event', 'med_upload_intent'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeApiTokens();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aRetakeAddedWithRetakeOfShowsAsTryTwoOfTheSameScreenshotAndTheFirstTryKeepsItsPins(): void
    {
        $this->seedDrafts();

        $retake = $this->retakeFirstTryThroughTheApi();

        self::assertSame([[$this->firstTry, 0, null], [$this->otherShot, 1, null], [$retake, 2, $this->firstTry]], $this->screenshotChainOf($this->draft));
        self::assertSame(
            [[$this->firstTry, self::FIRST_TRY_NOTE], [$this->otherShot, self::OTHER_NOTE], [$retake, self::RETAKE_NOTE]],
            array_map(static fn (array $pin): array => [$pin['screenshot_id'], $pin['note']], $this->pinRowsOf($this->draft)),
            'The try-1 pin is still stored on try 1; the retake has only its own.',
        );

        $this->logIn($this->client, $this->people['Omar']);
        $frame = $this->client->request('GET', self::FRAME, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$retake, $this->otherShot], $this->sectionIdsOf($frame), 'One section per screenshot: the retake stands in for its chain, before the other screenshot.');
        $latest = $this->sectionOf($frame, $retake);
        self::assertSame('Screenshot 1', $latest->filter('.round-card__screenshot-label > span')->first()->text(), 'The retake is the same screenshot, not a third one.');
        self::assertSame('Try 2 of 2', $latest->filter('[data-try-label]')->text());
        self::assertSame(
            [['Try 1', 'false', $this->firstTry], ['Try 2', 'true', $retake]],
            $latest->filter('[data-try-switch] button')->each(static fn (Crawler $chip): array => [$chip->text(), (string) $chip->attr('aria-pressed'), (string) $chip->attr('data-live-screenshot-id-param')]),
        );
        self::assertStringContainsString(self::RETAKE_NOTE, $latest->text());
        self::assertStringNotContainsString(self::FIRST_TRY_NOTE, $latest->text(), 'The try-1 pin does not move to try 2.');
        self::assertCount(0, $this->sectionOf($frame, $this->otherShot)->filter('[data-try-label]'), 'The screenshot nobody retook has no tries.');
        self::assertStringNotContainsString(self::MIAS_NOTE, (string) $this->client->getResponse()->getContent());

        $editor = $frame->filter('#round-'.$this->draft.' .draft-round-editor');
        self::assertCount(1, $editor);
        $this->postLiveAction($this->client, (string) $editor->attr('data-live-props-value'), 'showTry', ['screenshotId' => $this->firstTry]);

        self::assertResponseStatusCodeSame(200);
        $shown = new Crawler((string) $this->client->getResponse()->getContent());
        $first = $this->sectionOf($shown, $this->firstTry);
        self::assertSame('Try 1 of 2', $first->filter('[data-try-label]')->text());
        $pinRows = $first->filter('.round-panel__pins li[data-pin-id]');
        self::assertSame(
            [[$this->firstTryPin, self::FIRST_TRY_NOTE]],
            $pinRows->each(static fn (Crawler $row): array => [(string) $row->attr('data-pin-id'), $row->filter('.round-panel__note')->text()]),
            'Try 1 still shows its own pin, and only it.',
        );
    }

    /**
     * Shared, the round Mia opens has one screenshot per chain, the latest try: the retake and the other screenshot,
     * each with its own pin, and nothing of try 1.
     */
    #[Test]
    public function theSharedRoundShowsOneScreenshotPerChainTheLatestTry(): void
    {
        $this->seedDrafts();
        $retake = $this->retakeFirstTryThroughTheApi();
        $this->shareOmarsDraft();

        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('1 of 2', $page->filter('.screenshot-pager__position')->text());
        self::assertSame(
            ['Screenshot 1 1 pin', 'Screenshot 2 1 pin'],
            $page->filter('nav.screenshot-strip a.screenshot-thumb')->each(static fn (Crawler $thumb): string => (string) preg_replace('/\s+/', ' ', trim($thumb->text()))),
        );
        self::assertSame([self::RETAKE_TITLE], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::RETAKE_NOTE, $content);
        self::assertStringNotContainsString(self::FIRST_TRY_TITLE, $content);
        self::assertStringNotContainsString(self::FIRST_TRY_NOTE, $content);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1?screenshot=2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::OTHER_TITLE], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertStringNotContainsString(self::FIRST_TRY_TITLE, (string) $this->client->getResponse()->getContent());
        self::assertSame([[$this->firstTry, 0, null], [$this->otherShot, 1, null], [$retake, 2, $this->firstTry]], $this->screenshotChainOf($this->draft), 'Share keeps every try with the owner.');
    }

    /**
     * "Before = the earlier After" (ADR-0048): Round 1 is Omar's draft with screenshot A retaken once, shared and then
     * closed; Round 2 is his next draft with a new A' (Screenshot 1), retaken after he added the new screen B
     * (Screenshot 2). Mia sees A' compared with Round 1's latest try of A, never its first try nor A''s; B is new, so
     * it stands alone, and Round 1 has no round before it. Someone of another organization gets 404.
     */
    #[Test]
    public function theNextSharedRoundComparesEachScreenshotWithTheSameNumberedLatestTryOfTheRoundBefore(): void
    {
        $this->draft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $this->firstTry = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 0, self::FIRST_TRY_TITLE, null);
        $this->addPin($this->firstTry, 1, '10.00', '20.00', self::FIRST_TRY_NOTE, 'Omar');
        $this->addScreenshot($this->addRound($this->ticketId, null, 'draft', 'Mia', null), $this->addMedia('ready', 'Mia'), 0, 'Mia shot', null);
        $this->retakeThroughTheApi($this->draft, $this->firstTry, self::RETAKE_TITLE, self::RETAKE_NOTE);
        $this->shareDraft($this->draft);
        $roundOne = $this->draft;
        $this->closeRound($roundOne);

        $roundTwo = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $nextFirstTry = $this->addScreenshot($roundTwo, $this->addMedia('ready', 'Omar'), 0, self::NEXT_FIRST_TRY_TITLE, null);
        $this->addPin($nextFirstTry, 1, '15.00', '25.00', self::NEXT_FIRST_TRY_NOTE, 'Omar');
        $newScreen = $this->addScreenshot($roundTwo, $this->addMedia('ready', 'Omar'), 1, self::NEW_SCREEN_TITLE, null);
        $this->addPin($newScreen, 1, '55.00', '65.00', self::NEW_SCREEN_NOTE, 'Omar');
        $this->retakeThroughTheApi($roundTwo, $nextFirstTry, self::NEXT_RETAKE_TITLE, self::NEXT_RETAKE_NOTE);
        $this->shareDraft($roundTwo);
        self::assertSame(
            [[$roundOne, 1, 'closed'], [$roundTwo, 2, 'open']],
            array_values(array_map(
                static fn (array $round): array => [$round['id'], $round['number'], $round['state']],
                array_filter($this->roundRowsOf($this->ticketId), static fn (array $round): bool => null !== $round['number']),
            )),
        );

        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', '/t/SHOP-1/rounds/2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Before · round 1', 'After · round 2'], $page->filter(self::CAPTION)->each(static fn (Crawler $caption): string => $caption->text()));
        $before = $page->filter(self::BEFORE_SIDE);
        self::assertSame([self::RETAKE_TITLE], $before->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()), 'Before is Round 1\'s latest try of the chain.');
        self::assertStringContainsString(self::RETAKE_NOTE, $before->text());
        $after = $page->filter(self::AFTER_SIDE);
        self::assertSame([self::NEXT_RETAKE_TITLE], $after->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertStringContainsString(self::NEXT_RETAKE_NOTE, $after->text());
        self::assertStringNotContainsString(self::RETAKE_NOTE, $after->text());
        self::assertCount(1, $page->filter('.round-compare-control'), 'A screenshot with a Before offers the compare control.');
        $content = (string) $this->client->getResponse()->getContent();
        foreach ([self::FIRST_TRY_TITLE, self::FIRST_TRY_NOTE, self::NEXT_FIRST_TRY_TITLE, self::NEXT_FIRST_TRY_NOTE, 'Mia shot'] as $hidden) {
            self::assertStringNotContainsString($hidden, $content);
        }

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/2?screenshot=2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $page->filter(self::CAPTION)->each(static fn (Crawler $caption): string => $caption->text()), 'A screenshot new in this round has no Before.');
        self::assertCount(0, $page->filter(self::BEFORE_SIDE));
        self::assertCount(0, $page->filter('.round-compare-control'));
        self::assertSame([self::NEW_SCREEN_TITLE], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertStringNotContainsString(self::RETAKE_TITLE, (string) $this->client->getResponse()->getContent());

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $page->filter(self::CAPTION)->each(static fn (Crawler $caption): string => $caption->text()), 'Round 1 has no round before it.');
        self::assertCount(0, $page->filter(self::BEFORE_SIDE));
        self::assertSame([self::RETAKE_TITLE], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));

        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);
        $refused = $this->client->request('GET', '/t/SHOP-1/rounds/2');

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $refused->filter(self::CAPTION));
        $content = (string) $this->client->getResponse()->getContent();
        foreach ([self::RETAKE_TITLE, self::NEXT_RETAKE_TITLE, self::RETAKE_NOTE] as $hidden) {
            self::assertStringNotContainsString($hidden, $content);
        }
    }

    private function seedDrafts(): void
    {
        $this->draft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $this->firstTry = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 0, self::FIRST_TRY_TITLE, null);
        $this->firstTryPin = $this->addPin($this->firstTry, 1, '10.00', '20.00', self::FIRST_TRY_NOTE, 'Omar');
        $this->otherShot = $this->addScreenshot($this->draft, $this->addMedia('ready', 'Omar'), 1, self::OTHER_TITLE, null);
        $this->addPin($this->otherShot, 1, '50.00', '60.00', self::OTHER_NOTE, 'Omar');
        $miasShot = $this->addScreenshot($this->addRound($this->ticketId, null, 'draft', 'Mia', null), $this->addMedia('ready', 'Mia'), 0, 'Mia shot', null);
        $this->addPin($miasShot, 1, '70.00', '70.00', self::MIAS_NOTE, 'Mia');
    }

    /**
     * @return string the new try's id
     */
    private function retakeFirstTryThroughTheApi(): string
    {
        return $this->retakeThroughTheApi($this->draft, $this->firstTry, self::RETAKE_TITLE, self::RETAKE_NOTE);
    }

    /**
     * Omar's REST retake of $retakenId in his draft $draftId, with one pin.
     *
     * @return string the new try's id
     */
    private function retakeThroughTheApi(string $draftId, string $retakenId, string $title, string $pinNote): string
    {
        $uploadId = $this->addReadyUpload('Omar', $draftId);
        [, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);

        $this->client->request('POST', '/api/v1/tickets/SHOP-1/draft/screenshots', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'upload_id' => $uploadId,
            'title' => $title,
            'pins' => [['x_pct' => 30, 'y_pct' => 40, 'note' => $pinNote]],
            'retake_of' => $retakenId,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $retake = $answer['screenshot_id'] ?? null;
        self::assertIsString($retake);

        return $retake;
    }

    private function shareOmarsDraft(): void
    {
        $this->shareDraft($this->draft);
    }

    /**
     * Omar shares his draft $draftId with "Share now".
     */
    private function shareDraft(string $draftId): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request(
            'POST',
            '/t/SHOP-1/rounds/'.$draftId.'/share',
            [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))],
        );
        self::assertResponseStatusCodeSame(303);
    }

    /**
     * An upload Omar began for his draft and completed, with the ready picture processing made under the intent's id,
     * as Media stores them.
     *
     * @return string the intent's id, which is the picture's
     */
    private function addReadyUpload(string $person, string $roundId): string
    {
        $intentId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $uploader = $this->people[$person]->id()->toString();
        $storageKey = \sprintf('uploads/%s/%s', $organizationId, $intentId);
        $variants = json_encode(['view' => 'media/'.$intentId.'/view.webp', 'thumb' => 'media/'.$intentId.'/thumb.webp'], \JSON_THROW_ON_ERROR);
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $intentId, $uploader, $roundId, $storageKey, $variants): int|string {
            $migrator->executeStatement(
                "INSERT INTO med_upload_intent (organization_id, id, user_id, purpose, target_kind, target_id, max_bytes, mime_allowed, storage_key, expires_at, used_at)
                 VALUES (?, ?, ?, 'screenshot', 'round', ?, 245760, '{image/png}', ?, TIMESTAMPTZ '2026-11-11 09:00:00+00', TIMESTAMPTZ '2026-11-10 09:00:05+00')",
                [$organizationId, $intentId, $uploader, $roundId, $storageKey],
            );

            return $migrator->executeStatement(
                "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
                 VALUES (?, ?, ?, 'image/png', 2048, ?, TIMESTAMPTZ '2026-11-10 09:00:05+00', 'ready', NULL, ?, 1280, 800, CAST(? AS jsonb))",
                [$organizationId, $intentId, $storageKey, $uploader, hash('sha256', $intentId), $variants],
            );
        });

        return $intentId;
    }

    /**
     * @return list<string> the screenshot ids of the sections, in order
     */
    private function sectionIdsOf(Crawler $page): array
    {
        $sections = $page->filter('section[data-screenshot-id]');
        self::assertGreaterThan(0, \count($sections), 'The response holds the draft\'s card.');

        return $sections->each(static fn (Crawler $section): string => (string) $section->attr('data-screenshot-id'));
    }

    private function sectionOf(Crawler $page, string $screenshotId): Crawler
    {
        $section = $page->filter('section[data-screenshot-id="'.$screenshotId.'"]');
        self::assertCount(1, $section, 'One section shows '.$screenshotId);

        return $section;
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
}
