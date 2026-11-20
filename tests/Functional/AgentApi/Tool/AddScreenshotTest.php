<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\McpRequestGuard;
use App\AgentApi\Infrastructure\Mcp\Tool\AddScreenshot;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\Media\Application\ProcessUpload;
use App\Review\Domain\ScreenshotTriesFull;
use App\Review\Infrastructure\Doctrine\DbalRoundQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Review\SeedsTicketRounds;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Integration\Media\Persistence\MediaRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * add_screenshot through POST /mcp end to end (P09.T5 step 3): the api_token firewall, the request guard, the gate,
 * Media's completion with its target authorizer and outbox, the voter and commands of the draft round editor, and the
 * audit row, on Acme Foods' ticket SHOP-1. Omar is its assignee; his unshared draft holds one picture already, and he
 * has a shared draft there too. Mia, a member of Shop, has an unshared draft of her own. Each upload is an intent its
 * person began for one of those rounds, seeded as Media stores it; a used one has the media object its completion made,
 * which takes the intent's id, in the state processing left it. A retake_of names a try of Omar's first picture, seeded
 * as Review stores tries, or a screenshot of another round or organization. The clock is fixed on 10 Nov 2026, a day
 * aud_event has a partition for and not today.
 */
final class AddScreenshotTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsRetakeTargets;
    use SeedsTicketRounds;

    private const string MCP = '/mcp';
    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const string PROTOCOL_VERSION = '2025-06-18';
    /** JSON-RPC's "Invalid params". */
    private const int INVALID_PARAMS = -32602;
    private const string SHARED_AT = '2026-11-05 09:00:00+00';
    private const string USED_AT = '2026-11-10 09:00:05+00';
    private const string TITLE = 'Case B: a new delivery date';
    private const string SUMMARY = 'The total now leaves out the old delivery fee.';
    private const string PIN_NOTE = 'The total without the fee';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $omarsDraft;
    private string $omarsFirstShot;
    private string $omarsSharedDraft;
    private string $miasDraft;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = $this->addTodoStep($this->acmeFoods);

        $this->ticketId = $this->addTicket('SHOP-1');
        $this->addScreenshot($this->addRound($this->ticketId, 0, 'report', 'Mia', self::SHARED_AT), $this->addMedia('ready'), 0, 'Cart', null);
        $this->omarsSharedDraft = $this->addRound($this->ticketId, 1, 'draft', 'Omar', self::SHARED_AT);
        $this->omarsDraft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $this->omarsFirstShot = $this->addScreenshot($this->omarsDraft, $this->addMedia('ready', 'Omar'), 0, 'Before', null);
        $this->miasDraft = $this->addRound($this->ticketId, null, 'draft', 'Mia', null);
    }

    protected function tearDown(): void
    {
        // MediaRows switches the tenant, which the switch guard refuses while the last request's tenant and entities linger.
        self::getContainer()->get(TenantContext::class)->reset();
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeApiTokens();
        $this->removeSeededRounds();
        new MediaRows($this->migrator(), self::getContainer()->get(TenantContext::class))->removeAllOf(...$this->createdOrganizationIds);
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theOwnersReadyPictureGoesIntoHisDraftWithItsTitleSummaryAndPinsAndOneOkRowThatHoldsNoContent(): void
    {
        $uploadId = $this->addUsedUpload('Omar', $this->omarsDraft, 'ready');
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);

        $this->callAddScreenshot($token, [
            'key' => 'SHOP-1',
            'upload_id' => $uploadId,
            'title' => '  '.self::TITLE.' ',
            'summary' => self::SUMMARY,
            'pins' => [['x_pct' => 12.5, 'y_pct' => 40, 'note' => self::PIN_NOTE], ['x_pct' => 0, 'y_pct' => 100, 'note' => 'The fee']],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $screenshots = $this->screenshotsOf($this->omarsDraft);
        self::assertCount(2, $screenshots);
        $newShot = $screenshots[1]['id'];
        self::assertIsString($newShot);
        self::assertSame(['screenshot_id' => $newShot, 'round_id' => $this->omarsDraft, 'status' => AddScreenshot::ADDED, 'pin_count' => 2], $this->responseValueAt('result', 'structuredContent'));
        self::assertSame([
            ['id' => $this->omarsFirstShot, 'media_id' => $screenshots[0]['media_id'], 'position' => 0, 'title' => 'Before', 'summary' => null],
            ['id' => $newShot, 'media_id' => $uploadId, 'position' => 1, 'title' => self::TITLE, 'summary' => self::SUMMARY],
        ], $screenshots);
        self::assertSame([
            ['screenshot_id' => $newShot, 'number' => 1, 'x_pct' => '12.50', 'y_pct' => '40.00', 'note' => self::PIN_NOTE],
            ['screenshot_id' => $newShot, 'number' => 2, 'x_pct' => '0.00', 'y_pct' => '100.00', 'note' => 'The fee'],
        ], $this->pinRowsOf($this->omarsDraft));
        self::assertSame([], $this->screenshotsOf($this->miasDraft));
        self::assertSame([$this->toolRow('Omar', $tokenId, 'round', $this->omarsDraft, 'ok')], $this->toolRowsOf($this->acmeFoods));
        $this->assertNoAuditRowHolds([self::TITLE, self::SUMMARY, self::PIN_NOTE, $token]);
    }

    /**
     * The first call completes the upload and finds the picture pending; that answer is no error, so the completion
     * and its processing job stay. Once the worker has made the picture ready, the same call adds it.
     */
    #[Test]
    public function aPictureStillProcessingIsAnsweredSoWithItsCompletionKeptAndIsAddedOnceItIsReady(): void
    {
        $uploadId = $this->addOpenUpload('Omar', $this->omarsDraft);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $arguments = ['key' => 'SHOP-1', 'upload_id' => $uploadId, 'title' => self::TITLE];

        $sessionId = $this->callAddScreenshot($token, $arguments);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame(['upload_id' => $uploadId, 'status' => AddScreenshot::PROCESSING, 'message' => AddScreenshot::STILL_PROCESSING], $this->responseValueAt('result', 'structuredContent'));
        self::assertSame([['id' => $uploadId, 'used_at' => '2026-11-10 09:00:20']], $this->intentsOf($this->acmeFoods));
        self::assertSame([[$uploadId, 'pending', $this->people['Omar']->id()->toString()]], $this->picturesOf($uploadId));
        self::assertEquals([new ProcessUpload($this->acmeFoods->toString(), $uploadId)], $this->queuedMessages());
        self::assertCount(1, $this->screenshotsOf($this->omarsDraft));

        $this->finishProcessing($uploadId);
        $this->callTool($token, $sessionId, $arguments);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(AddScreenshot::ADDED, $this->responseValueAt('result', 'structuredContent', 'status'));
        self::assertSame([$uploadId, self::TITLE], [$this->screenshotsOf($this->omarsDraft)[1]['media_id'] ?? null, $this->screenshotsOf($this->omarsDraft)[1]['title'] ?? null]);
        self::assertCount(1, $this->queuedMessages(), 'the second call completed nothing again');
        $okRow = $this->toolRow('Omar', $tokenId, 'round', $this->omarsDraft, 'ok');
        self::assertSame([$okRow, $okRow], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * A polyglot or oversized file is rejected by the processing worker (P05.T2, its media security tests); the
     * tool sees that outcome on the picture.
     */
    #[Test]
    public function aRejectedPictureIsAPlainErrorAuditedAsRefusedAndAddsNothing(): void
    {
        $uploadId = $this->addUsedUpload('Omar', $this->omarsDraft, 'rejected');
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $uploadId, 'title' => self::TITLE]);

        $this->assertRefused(AddScreenshot::PICTURE_REJECTED);
        self::assertSame($before, $this->stored());
        self::assertSame([$this->toolRow('Omar', $tokenId, 'round', $this->omarsDraft, 'refused')], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function theSameUploadTwiceAddsOneScreenshotAndTheSecondCallSaysItWasAddedBefore(): void
    {
        $uploadId = $this->addUsedUpload('Omar', $this->omarsDraft, 'ready');
        [, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);

        $sessionId = $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $uploadId, 'title' => self::TITLE, 'pins' => [['x_pct' => 1, 'y_pct' => 2, 'note' => self::PIN_NOTE]]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(AddScreenshot::ADDED, $this->responseValueAt('result', 'structuredContent', 'status'));
        $afterFirst = $this->stored();

        $this->callTool($token, $sessionId, ['key' => 'SHOP-1', 'upload_id' => strtoupper($uploadId), 'title' => 'Another title']);

        self::assertResponseStatusCodeSame(200);
        $newShot = $afterFirst['screenshots'][$this->omarsDraft][1]['id'] ?? null;
        self::assertIsString($newShot);
        self::assertSame(['screenshot_id' => $newShot, 'round_id' => $this->omarsDraft, 'status' => AddScreenshot::ADDED_BEFORE, 'pin_count' => 1], $this->responseValueAt('result', 'structuredContent'));
        self::assertSame($afterFirst, $this->stored());
    }

    #[Test]
    public function anotherPersonsUploadIsNotFoundAndNothingIsWritten(): void
    {
        $miasUpload = $this->addUsedUpload('Mia', $this->miasDraft, 'ready');
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $miasUpload]);

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame($before, $this->stored());
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'not_found')], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function theOwnersSharedDraftIsReadOnlyAndNothingIsWrittenOrCompleted(): void
    {
        $uploadId = $this->addOpenUpload('Omar', $this->omarsSharedDraft);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $uploadId]);

        $this->assertRefused(ToolRefusal::READ_ONLY);
        self::assertSame($before, $this->stored());
        self::assertSame([['id' => $uploadId, 'used_at' => null]], $this->intentsOf($this->acmeFoods));
        self::assertSame([$this->toolRow('Omar', $tokenId, 'round', $this->omarsSharedDraft, 'read_only')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * SHOP-1 moves to Mobile, for that team only, and to Lena: Omar is in no role of Mobile and no longer involved, so
     * he may not see it. RoundPermission EDIT still grants him his own unshared draft without reading the ticket, so
     * add_screenshot asks TicketPermission VIEW itself and answers as for an unknown key, before the upload is completed.
     */
    #[Test]
    public function theOwnersDraftOnATicketTheyMayNoLongerSeeIsNotFoundAndNothingIsWrittenOrCompleted(): void
    {
        $uploadId = $this->addOpenUpload('Omar', $this->omarsDraft);
        $parameters = [$this->mobile->toString(), $this->people['Lena']->id()->toString(), $this->acmeFoods->toString(), $this->ticketId];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET team_id = ?, visibility = 'team', assignee_id = ? WHERE organization_id = ? AND id = ?",
            $parameters,
        ));
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $uploadId, 'title' => self::TITLE]);

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame($before, $this->stored());
        self::assertSame([['id' => $uploadId, 'used_at' => null]], $this->intentsOf($this->acmeFoods));
        self::assertSame([], $this->picturesOf($uploadId));
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'not_found')], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aTokenWithoutRoundsWriteGetsMissingScopeAndNothingIsWritten(): void
    {
        $uploadId = $this->addOpenUpload('Omar', $this->omarsDraft);
        [$tokenId, $token] = $this->issueReadOnlyToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $uploadId]);

        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'rounds:write'));
        self::assertSame($before, $this->stored());
        self::assertSame([['id' => $uploadId, 'used_at' => null]], $this->intentsOf($this->acmeFoods));
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'missing_scope')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Omar works in Beta Bakery too, which has a SHOP-1 of its own, so the key is found there and the upload is looked
     * up; Acme Foods' upload is not one of Beta Bakery's.
     */
    #[Test]
    public function anUploadOfAnotherOrganizationIsNotFoundAndNothingIsWritten(): void
    {
        $uploadId = $this->addOpenUpload('Omar', $this->omarsDraft);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Omar'], $betaBakery);
        $this->addBetaBakerysShop1($betaBakery);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $betaBakery);
        $before = $this->stored();

        $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $uploadId]);

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame($before, $this->stored());
        self::assertSame([['id' => $uploadId, 'used_at' => null]], $this->intentsOf($this->acmeFoods));
        self::assertSame([], $this->toolRowsOf($this->acmeFoods));
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'not_found')], $this->toolRowsOf($betaBakery));
    }

    /**
     * Omar retakes his second try, not the first, in capitals, as an id is matched in any case: the new try joins the
     * chain of its first try, so the chain stays one and the new try is its only latest one, as the team's read of a
     * shared round (latestTrySql) finds it. The earlier tries keep their pins.
     */
    #[Test]
    public function aRetakeOfAnyTryJoinsTheChainOfItsFirstTryWithItsOwnPinsAndTheEarlierTriesKeepTheirs(): void
    {
        $this->addPin($this->omarsFirstShot, 1, '10.00', '20.00', 'The old fee', 'Omar');
        $secondTry = $this->addRetake($this->omarsDraft, $this->omarsFirstShot, 1, 'Omar');
        $uploadId = $this->addUsedUpload('Omar', $this->omarsDraft, 'ready');
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);

        $this->callAddScreenshot($token, [
            'key' => 'SHOP-1',
            'upload_id' => $uploadId,
            'title' => self::TITLE,
            'summary' => self::SUMMARY,
            'pins' => [['x_pct' => 12.5, 'y_pct' => 40, 'note' => self::PIN_NOTE]],
            'retake_of' => strtoupper($secondTry),
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $tries = $this->triesOf($this->omarsDraft);
        self::assertCount(3, $tries);
        $newTry = $tries[2][0];
        self::assertIsString($newTry);
        self::assertSame(['screenshot_id' => $newTry, 'round_id' => $this->omarsDraft, 'status' => AddScreenshot::ADDED, 'pin_count' => 1], $this->responseValueAt('result', 'structuredContent'));
        self::assertSame([[$this->omarsFirstShot, 0, null], [$secondTry, 1, $this->omarsFirstShot], [$newTry, 2, $this->omarsFirstShot]], $tries);
        self::assertSame([$newTry], $this->latestTriesOf($this->omarsDraft));
        self::assertSame([
            ['screenshot_id' => $this->omarsFirstShot, 'number' => 1, 'x_pct' => '10.00', 'y_pct' => '20.00', 'note' => 'The old fee'],
            ['screenshot_id' => $newTry, 'number' => 1, 'x_pct' => '12.50', 'y_pct' => '40.00', 'note' => self::PIN_NOTE],
        ], $this->pinRowsOf($this->omarsDraft));
        self::assertSame([$this->toolRow('Omar', $tokenId, 'round', $this->omarsDraft, 'ok')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * @return iterable<string, array{'mias draft'|'shared round'|'beta bakery'|'unknown'|'malformed'|'trailing newline'}>
     */
    public static function retakesOfNoScreenshotOfTheDraft(): iterable
    {
        yield 'a screenshot of Mia\'s draft' => ['mias draft'];
        yield 'a screenshot of Omar\'s shared round' => ['shared round'];
        yield 'a screenshot of Omar\'s draft in Beta Bakery' => ['beta bakery'];
        yield 'an unknown id' => ['unknown'];
        yield 'a malformed id' => ['malformed'];
        yield 'the first try\'s id with a trailing newline' => ['trailing newline'];
    }

    /**
     * Review looks the retaken screenshot up in the draft the voter granted, so another person's draft, another round
     * and another organization answer as an unknown id does, after the picture was found ready; the gate's savepoint
     * takes everything back.
     *
     * @param 'mias draft'|'shared round'|'beta bakery'|'unknown'|'malformed'|'trailing newline' $retaken
     */
    #[Test]
    #[DataProvider('retakesOfNoScreenshotOfTheDraft')]
    public function aRetakeOfNoScreenshotOfTheOwnersDraftIsNotFoundAndNothingIsWritten(string $retaken): void
    {
        $retakeOf = match ($retaken) {
            'mias draft' => $this->addScreenshot($this->miasDraft, $this->addMedia('ready', 'Mia'), 0, 'Hers', null),
            'shared round' => $this->addScreenshot($this->omarsSharedDraft, $this->addMedia('ready', 'Omar'), 0, 'Shared', null),
            'beta bakery' => $this->addOtherOrganizationsDraftScreenshot($this->createOrganization('Beta Bakery'), 'Omar'),
            'unknown' => Uuid::v7()->toRfc4122(),
            'malformed' => 'not-a-screenshot-id',
            'trailing newline' => $this->omarsFirstShot."\n",
        };
        $uploadId = $this->addUsedUpload('Omar', $this->omarsDraft, 'ready');
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();
        $triesBefore = $this->triesOf($this->omarsDraft);

        $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $uploadId, 'title' => self::TITLE, 'summary' => self::SUMMARY, 'retake_of' => $retakeOf]);

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame($before, $this->stored());
        self::assertSame([[$this->omarsFirstShot, 0, null]], $triesBefore);
        self::assertSame($triesBefore, $this->triesOf($this->omarsDraft));
        self::assertSame([$this->toolRow('Omar', $tokenId, 'round', $this->omarsDraft, 'not_found')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Four tries are seeded; retaking the middle one adds the fifth, and a sixth, retaking the first, is Review's plain
     * refusal with the cap's reason.
     */
    #[Test]
    public function theFifthTryIsAddedAndASixthIsRefusedWithTheCapsReasonAndNothingIsWritten(): void
    {
        $secondTry = $this->addRetake($this->omarsDraft, $this->omarsFirstShot, 1, 'Omar');
        $thirdTry = $this->addRetake($this->omarsDraft, $this->omarsFirstShot, 2, 'Omar');
        $fourthTry = $this->addRetake($this->omarsDraft, $this->omarsFirstShot, 3, 'Omar');
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $arguments = ['key' => 'SHOP-1', 'title' => self::TITLE, 'summary' => self::SUMMARY];

        $sessionId = $this->callAddScreenshot($token, [...$arguments, 'upload_id' => $this->addUsedUpload('Omar', $this->omarsDraft, 'ready'), 'retake_of' => $thirdTry]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(AddScreenshot::ADDED, $this->responseValueAt('result', 'structuredContent', 'status'));
        $fifthTry = $this->responseValueAt('result', 'structuredContent', 'screenshot_id');
        self::assertIsString($fifthTry);
        $chain = [[$this->omarsFirstShot, 0, null], [$secondTry, 1, $this->omarsFirstShot], [$thirdTry, 2, $this->omarsFirstShot], [$fourthTry, 3, $this->omarsFirstShot], [$fifthTry, 4, $this->omarsFirstShot]];
        self::assertSame($chain, $this->triesOf($this->omarsDraft));
        $sixthUpload = $this->addUsedUpload('Omar', $this->omarsDraft, 'ready');
        $before = $this->stored();

        $this->callTool($token, $sessionId, [...$arguments, 'upload_id' => $sixthUpload, 'retake_of' => $this->omarsFirstShot]);

        $this->assertRefused(ScreenshotTriesFull::REASON);
        self::assertSame($before, $this->stored());
        self::assertSame($chain, $this->triesOf($this->omarsDraft));
        // Both calls share the frozen clock's time, so their rows tie on `at` and their order is not defined.
        self::assertEqualsCanonicalizing(
            [$this->toolRow('Omar', $tokenId, 'round', $this->omarsDraft, 'ok'), $this->toolRow('Omar', $tokenId, 'round', $this->omarsDraft, 'refused')],
            $this->toolRowsOf($this->acmeFoods),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function inputPastTheCaps(): iterable
    {
        yield 'a title of 121 characters' => [['title' => str_repeat('é', 121)]];
        yield 'a summary of 601 characters' => [['summary' => str_repeat('s', 601)]];
        yield 'a pin outside the picture' => [['pins' => [['x_pct' => 100.5, 'y_pct' => 10, 'note' => self::PIN_NOTE]]]];
        yield 'a pin note of 1,001 characters' => [['pins' => [['x_pct' => 10, 'y_pct' => 10, 'note' => str_repeat('n', 1001)]]]];
        yield '101 pins' => [['pins' => array_fill(0, 101, ['x_pct' => 10, 'y_pct' => 10, 'note' => self::PIN_NOTE])]];
    }

    /**
     * @param array<string, mixed> $pastTheCap
     */
    #[Test]
    #[DataProvider('inputPastTheCaps')]
    public function inputPastACapOfTheUiIsInvalidAndNothingIsWrittenOrCompleted(array $pastTheCap): void
    {
        $uploadId = $this->addOpenUpload('Omar', $this->omarsDraft);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $uploadId, ...$pastTheCap]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::INVALID_PARAMS, $this->responseValueAt('error', 'code'));
        self::assertSame($before, $this->stored());
        self::assertSame([['id' => $uploadId, 'used_at' => null]], $this->intentsOf($this->acmeFoods));
        self::assertSame([], $this->queuedMessages());
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'invalid_input')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * A picture sent inside the call, as base64 or otherwise, makes the body too big: the guard refuses it before the
     * firewall, so neither the tool nor the gate runs and no row is written.
     */
    #[Test]
    public function aToolCallBodyOverOneMegabyteIsRefusedBeforeTheToolRuns(): void
    {
        $uploadId = $this->addOpenUpload('Omar', $this->omarsDraft);
        [, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callAddScreenshot($token, ['key' => 'SHOP-1', 'upload_id' => $uploadId, 'summary' => str_repeat('A', McpRequestGuard::MAX_BODY_BYTES)]);

        self::assertResponseStatusCodeSame(413);
        self::assertSame(['error' => McpRequestGuard::BODY_TOO_LARGE], $this->responseValueAt());
        self::assertSame($before, $this->stored());
        self::assertSame([['id' => $uploadId, 'used_at' => null]], $this->intentsOf($this->acmeFoods));
        self::assertSame([], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * @return array{string, string} the token's id and its plain text
     */
    private function issueReadOnlyToken(User $user, OrganizationId $organizationId): array
    {
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $issued = ApiToken::issue($this->apiTokens()->nextIdentity(), $organizationId, $user->id(), 'Claude Code', [ApiTokenScope::Read], $hashing, new \DateTimeImmutable(self::LIVE_TOKEN_ISSUED_AT), ApiToken::DEFAULT_LIFETIME_DAYS);
        $this->inTokenTransactionAs($organizationId, fn () => $this->apiTokens()->add($issued->token()));

        return [$issued->token()->id(), $issued->reveal()];
    }

    private function addTodoStep(OrganizationId $organizationId): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId->toString(), $stepId],
        ));

        return $stepId;
    }

    /**
     * Beta Bakery's own SHOP-1, which Omar reported and is assigned, with an unshared draft of his.
     */
    private function addBetaBakerysShop1(OrganizationId $betaBakery): void
    {
        $projectId = $this->addProject(null, 'SHOP', 'Shop', $betaBakery);
        $ticketId = Uuid::v7()->toRfc4122();
        $omar = $this->people['Omar']->id()->toString();
        $parameters = [$betaBakery->toString(), $ticketId, $projectId, $this->addTodoStep($betaBakery), $omar, $omar];
        $this->committedAs($betaBakery, static function (Connection $migrator) use ($parameters, $betaBakery, $ticketId, $omar): int|string {
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, NULL, 1, 'SHOP-1', 'Bread prices are rounded', '', 'bug', 'major', 2, ?, ?, ?, 'all', 3, '{}', '{}',
                     TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                $parameters,
            );

            return $migrator->executeStatement(
                "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at)
                 VALUES (?, ?, ?, NULL, 'draft', ?, NULL, 'open', 1, TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                [$betaBakery->toString(), Uuid::v7()->toRfc4122(), $ticketId, $omar],
            );
        });
    }

    /**
     * An upload $person began for $roundId a minute before the fixed time, as Media stores it, still unused.
     *
     * @return string the intent's id
     */
    private function addOpenUpload(string $person, string $roundId): string
    {
        return $this->addIntent($person, $roundId, null);
    }

    /**
     * An upload $person began for $roundId and completed, with the media object its completion made under the
     * intent's id, in the state processing left it.
     *
     * @param 'ready'|'pending'|'rejected' $status
     *
     * @return string the intent's id, which is the picture's
     */
    private function addUsedUpload(string $person, string $roundId, string $status): string
    {
        $intentId = $this->addIntent($person, $roundId, self::USED_AT);
        $parameters = [
            $this->acmeFoods->toString(), $intentId, \sprintf('uploads/%s/%s', $this->acmeFoods->toString(), $intentId), $this->people[$person]->id()->toString(), $status,
            'rejected' === $status ? 'The file is not a picture.' : null,
            ...('ready' === $status ? self::processedPictureOf($intentId) : [null, null, null, null]),
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
             VALUES (?, ?, ?, 'image/png', 2048, ?, TIMESTAMPTZ '2026-11-10 09:00:05+00', ?, ?, ?, ?, ?, CAST(? AS jsonb))",
            $parameters,
        ));

        return $intentId;
    }

    private function addIntent(string $person, string $roundId, ?string $usedAt): string
    {
        $intentId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $intentId, $this->people[$person]->id()->toString(), $roundId, \sprintf('uploads/%s/%s', $this->acmeFoods->toString(), $intentId), $usedAt];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_upload_intent (organization_id, id, user_id, purpose, target_kind, target_id, max_bytes, mime_allowed, storage_key, expires_at, used_at)
             VALUES (?, ?, ?, 'screenshot', 'round', ?, 245760, '{image/png}', ?, TIMESTAMPTZ '2026-11-11 09:00:00+00', CAST(? AS TIMESTAMPTZ))",
            $parameters,
        ));

        return $intentId;
    }

    /**
     * Marks the pending picture ready as the processing worker does once it has checked and re-encoded it.
     */
    private function finishProcessing(string $mediaId): void
    {
        $parameters = [...self::processedPictureOf($mediaId), $mediaId];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE med_object SET status = 'ready', sha256 = ?, width = ?, height = ?, variants = CAST(? AS jsonb) WHERE id = ?",
            $parameters,
        ));
    }

    /**
     * @return array{string, int, int, string} the sha256, width, height and variants (JSON) of a processed picture
     */
    private static function processedPictureOf(string $mediaId): array
    {
        return [hash('sha256', $mediaId), 1280, 800, json_encode(['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp'], \JSON_THROW_ON_ERROR)];
    }

    /**
     * Starts and confirms a session as Claude Code does, then calls add_screenshot in it.
     *
     * @param array<string, mixed> $arguments
     *
     * @return string the session's id
     */
    private function callAddScreenshot(string $token, array $arguments): string
    {
        $this->postJsonRpc($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0']],
        ]);
        self::assertResponseStatusCodeSame(200);
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        $this->callTool($token, $sessionId, $arguments);

        return $sessionId;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callTool(string $token, string $sessionId, array $arguments): void
    {
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => AddScreenshot::NAME, 'arguments' => $arguments]], $sessionId);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function postJsonRpc(string $token, array $message, ?string $sessionId = null): void
    {
        $sessionHeaders = null === $sessionId ? [] : ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION];
        $this->client->request('POST', self::MCP, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ...$sessionHeaders,
        ], content: json_encode($message, \JSON_THROW_ON_ERROR));
    }

    private function assertRefused(string $message): void
    {
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => $message]], $this->responseValueAt('result', 'content'));
        self::assertNull($this->responseValueAt('result', 'structuredContent'));
    }

    /**
     * Every round of SHOP-1, the screenshots and pins of each draft, the media objects and the queued jobs, as stored.
     *
     * @return array{rounds: list<array<string, mixed>>, screenshots: array<string, list<array<string, mixed>>>, pins: list<array<string, mixed>>, media: list<array<string, mixed>>, queued: int}
     */
    private function stored(): array
    {
        $rounds = $this->roundRowsOf($this->ticketId);
        self::assertCount(4, $rounds);
        $screenshots = [];
        foreach ([$this->omarsDraft, $this->omarsSharedDraft, $this->miasDraft] as $roundId) {
            $screenshots[$roundId] = $this->screenshotsOf($roundId);
        }
        self::assertNotSame([], $screenshots[$this->omarsDraft]);

        return [
            'rounds' => $rounds,
            'screenshots' => $screenshots,
            'pins' => $this->pinRowsOf($this->omarsDraft),
            'media' => $this->rowsOf($this->acmeFoods, 'SELECT id, status, uploaded_by FROM med_object WHERE organization_id = ? ORDER BY id'),
            'queued' => \count($this->queuedMessages()),
        ];
    }

    /**
     * @return list<array<string, mixed>> the round's screenshots with their pictures, by position
     */
    private function screenshotsOf(string $roundId): array
    {
        return $this->rowsOf($this->acmeFoods, 'SELECT id, media_id, position, title, summary FROM rvw_screenshot WHERE organization_id = ? AND round_id = ? ORDER BY position', [$roundId]);
    }

    /**
     * @return list<list<mixed>> the id, position and retake_of of each screenshot of the round, by position
     */
    private function triesOf(string $roundId): array
    {
        return array_map(array_values(...), $this->rowsOf($this->acmeFoods, 'SELECT id, position, retake_of FROM rvw_screenshot WHERE organization_id = ? AND round_id = ? ORDER BY position', [$roundId]));
    }

    /**
     * @return list<mixed> the ids of the round's screenshots that are the latest try of their chain, by position
     */
    private function latestTriesOf(string $roundId): array
    {
        return array_column($this->rowsOf(
            $this->acmeFoods,
            'SELECT screenshot.id FROM rvw_screenshot AS screenshot WHERE screenshot.organization_id = ? AND screenshot.round_id = ? AND '.DbalRoundQueries::latestTrySql('screenshot').' ORDER BY screenshot.position',
            [$roundId],
        ), 'id');
    }

    /**
     * @return list<array<string, mixed>> the organization's upload intents with when each was used, in UTC
     */
    private function intentsOf(OrganizationId $organizationId): array
    {
        return $this->rowsOf($organizationId, "SELECT id, to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS used_at FROM med_upload_intent WHERE organization_id = ? ORDER BY id");
    }

    /**
     * @return list<list<mixed>> the media object's id, status and uploader
     */
    private function picturesOf(string $mediaId): array
    {
        return array_map(array_values(...), $this->rowsOf($this->acmeFoods, 'SELECT id, status, uploaded_by FROM med_object WHERE organization_id = ? AND id = ?', [$mediaId]));
    }

    /**
     * @return list<object> the message of each outbox row that names Acme Foods, oldest first
     */
    private function queuedMessages(): array
    {
        $serializer = new PhpSerializer();

        return array_map(static function (array $row) use ($serializer): object {
            self::assertIsString($row['body']);

            return $serializer->decode(['body' => $row['body']])->getMessage();
        }, $this->rowsOf($this->acmeFoods, "SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0 ORDER BY id"));
    }

    /**
     * @param string       $sql        with the organization id as its first parameter
     * @param list<string> $parameters the ones after it
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(OrganizationId $organizationId, string $sql, array $parameters = []): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, $sql, $parameters, &$rows): int {
            $rows = $migrator->fetchAllAssociative($sql, [$organizationId->toString(), ...$parameters]);

            return 0;
        });

        return $rows;
    }

    /**
     * @return array{string, string, string, string, string, string|null, string, array<string, string>}
     */
    private function toolRow(string $person, string $tokenId, string $targetType, ?string $targetId, string $outcome): array
    {
        return ['token', $this->people[$person]->id()->toString(), $tokenId, ToolCallAuditor::ACTION, $targetType, $targetId, '2026-11-10 09:00:20', ['outcome' => $outcome, 'tool' => AddScreenshot::NAME]];
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed, mixed, array<mixed>}>
     */
    private function toolRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT actor_kind, actor_user_id, actor_token_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), data::text FROM aud_event WHERE organization_id = ? AND action = ? ORDER BY at, id",
                [$organizationId->toString(), ToolCallAuditor::ACTION],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            self::assertIsString($row[7]);
            $data = json_decode($row[7], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            ksort($data);

            return [$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $data];
        }, $rows);
    }

    /**
     * Every column of every Acme Foods audit row, as text, holds none of $texts.
     *
     * @param list<string> $texts
     */
    private function assertNoAuditRowHolds(array $texts): void
    {
        $rowTexts = [];
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rowTexts): int {
            $rowTexts = $migrator->fetchFirstColumn('SELECT e::text FROM aud_event e WHERE organization_id = ?', [$organizationId]);

            return 0;
        });
        self::assertNotSame([], $rowTexts);
        foreach ($rowTexts as $rowText) {
            self::assertIsString($rowText);
            foreach ($texts as $text) {
                self::assertStringNotContainsString($text, $rowText);
            }
        }
    }

    /**
     * @return mixed null when the last key is missing; fails when a key before it does not hold an array
     */
    private function responseValueAt(string ...$path): mixed
    {
        $value = json_decode($this->client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($path as $key) {
            self::assertIsArray($value, \sprintf('no %s in the response', implode('.', $path)));
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
