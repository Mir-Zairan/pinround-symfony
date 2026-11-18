<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist\Laptop;

use App\Assist\Api\LocalModelName;
use App\Assist\Infrastructure\Prompt\OutputValidator;
use App\Assist\UI\Controller\LaptopController;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /ai/laptop/result through the real firewall, voters, session, AI policy, database and audit log, outside the
 * request transaction (SeedsLaptopAiTickets). Each test first has Omar ask for a package of SHOP-1 through POST
 * /ai/laptop/package. A refusal of LocalAi leaves the package as it was and writes one local_ai.result_refused entry
 * without the text (ADR-0042); a refusal before LocalAi writes neither.
 */
final class LaptopResultTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsLaptopAiTickets;
    use StoresAiSettings;

    private const string NOT_FOUND = 'This laptop AI request does not exist.';
    private const string GONE = 'This laptop AI request was used or ran out of time. Ask again.';
    /** A byte order mark, a CRLF and trailing spaces, which the answer check removes. */
    private const string RESULT = "\u{FEFF}Pay does nothing on the last step.\r\nThe order is never sent.  ";
    private const string CHECKED_RESULT = "Pay does nothing on the last step.\nThe order is never sent.";

    private string $omarsPackageId;

    /** @var list<array<string, mixed>> Omar's package as stored before the result */
    private array $unusedRows;

    protected function setUp(): void
    {
        $this->seedLaptopAiTickets();
        $this->omarsPackageId = $this->packageIdOf('Omar', $this->shopTicketId);
        $this->unusedRows = $this->packageRowsOf($this->acmeFoods);
        self::assertCount(1, $this->unusedRows);
    }

    protected function tearDown(): void
    {
        $this->removeLaptopAiRows();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function omarPostsTheResultOfHisPackageAndItIsKeptWithItsLabel(): void
    {
        $this->clock->modify('+2 minutes');

        $this->postResult(self::resultBody($this->omarsPackageId, self::RESULT), $this->laptopToken());

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [
                'packageId' => $this->omarsPackageId,
                'label' => 'posted by Omar using local model qwen3:4b (unverified)',
                'text' => self::CHECKED_RESULT,
                'model' => self::MODEL,
                'postedAt' => '2026-11-12T12:02:20+00:00',
            ],
            $this->answer(),
        );
        self::assertSame(
            [[...$this->unusedRows[0], 'used_at' => '2026-11-12 12:02:20', 'result_text' => self::CHECKED_RESULT, 'model' => self::MODEL]],
            $this->packageRowsOf($this->acmeFoods),
        );
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
    }

    /** The answer is user content: markup is kept as plain text, for the page to escape. */
    #[Test]
    public function aResultWithMarkupIsKeptVerbatim(): void
    {
        $this->postResult(self::resultBody($this->omarsPackageId, 'Pay <b>does nothing</b> on the last step.'), $this->laptopToken());

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Pay <b>does nothing</b> on the last step.', $this->packageRowsOf($this->acmeFoods)[0]['result_text'] ?? null);
    }

    #[Test]
    public function miaWhoPostsOmarsPackageIdIsToldItDoesNotExistAndTheRefusalIsAudited(): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $this->postResult(self::resultBody($this->omarsPackageId), $this->laptopToken());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => self::NOT_FOUND], $this->answer());
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([$this->refusalEntry('Mia', 'package_not_found')], $this->auditRowsOf($this->acmeFoods));
    }

    /** In his own tenant Raj passes the opening check; Acme Foods' package is not found there. */
    #[Test]
    public function rajOfBetaBakeryWhoPostsOmarsPackageIdIsToldItDoesNotExistInHisOrganization(): void
    {
        $this->logIn($this->client, $this->people['Raj']);

        $this->postResult(self::resultBody($this->omarsPackageId), $this->laptopToken());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => self::NOT_FOUND], $this->answer());
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
        self::assertSame([$this->refusalEntry('Raj', 'package_not_found')], $this->auditRowsOf($this->betaBakery));
    }

    #[Test]
    public function aSecondResultForThePackageIsGoneAndTheFirstIsKept(): void
    {
        $token = $this->laptopToken();
        $this->postResult(self::resultBody($this->omarsPackageId, self::RESULT), $token);
        self::assertResponseStatusCodeSame(201);
        $keptRows = $this->packageRowsOf($this->acmeFoods);

        $this->postResult(self::resultBody($this->omarsPackageId, 'Another answer.'), $token);

        self::assertResponseStatusCodeSame(410);
        self::assertSame(['message' => self::GONE], $this->answer());
        self::assertSame($keptRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame(self::CHECKED_RESULT, $keptRows[0]['result_text'] ?? null);
        self::assertSame([$this->refusalEntry('Omar', 'package_gone')], $this->auditRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aResultOneSecondAfterThePackageExpiredIsGoneAndNothingIsStored(): void
    {
        $this->clock->modify('+5 minutes +1 second');

        $this->postResult(self::resultBody($this->omarsPackageId), $this->laptopToken());

        self::assertResponseStatusCodeSame(410);
        self::assertSame(['message' => self::GONE], $this->answer());
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([$this->refusalEntry('Omar', 'package_gone')], $this->auditRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aResultForAnUnknownPackageIsNotFoundAndAudited(): void
    {
        $unknownPackageId = Uuid::v7()->toRfc4122();

        $this->postResult(self::resultBody($unknownPackageId), $this->laptopToken());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => self::NOT_FOUND], $this->answer());
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([[$this->idOf('Omar'), 'local_ai.result_refused', $unknownPackageId, ['model' => self::MODEL, 'reason' => 'package_not_found']]], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * @return iterable<string, array{string, string, string, string|null}> text, model, the reason, the model the audit
     *                                                                      entry keeps
     */
    public static function rejectedResults(): iterable
    {
        yield 'markup in the model name' => ['Pay does nothing.', 'qwen3:4b<script>', LocalModelName::RULE, null];
        yield 'an uppercase model name' => ['Pay does nothing.', 'Qwen3:4b', LocalModelName::RULE, null];
        yield 'a model name with a CRLF' => ['Pay does nothing.', "qwen3:4b\r\n", LocalModelName::RULE, null];
        yield 'a blank answer' => [" \r\n\u{FEFF} ", self::MODEL, 'The AI answer is blank.', self::MODEL];
        yield 'over the summary\'s words' => [str_repeat('word ', 61), self::MODEL, 'The AI answer to summary.v1 has more than 60 words.', self::MODEL];
    }

    #[Test]
    #[DataProvider('rejectedResults')]
    public function aResultThatFailsTheCheckIsRejectedWithItsReasonAndThePackageStaysOpen(string $text, string $model, string $reason, ?string $auditedModel): void
    {
        $this->postResult(self::resultBody($this->omarsPackageId, $text, $model), $this->laptopToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => $reason], $this->answer());
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([[$this->idOf('Omar'), 'local_ai.result_refused', $this->omarsPackageId, ['model' => $auditedModel, 'reason' => 'result_rejected']]], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * @return iterable<string, array{array<string, bool|string>, string, string}>
     */
    public static function policiesChangedAfterThePackage(): iterable
    {
        yield 'AI switched off' => [['on' => false, 'where' => 'laptop'], 'ai_off', 'Your admin turned AI help off.'];
        yield 'AI moved to the cloud' => [['on' => true, 'where' => 'cloud'], 'cloud_only', 'Your admin runs AI help in the company cloud, not on laptops.'];
        yield 'AI for leads only, Omar leads no team' => [['on' => true, 'who' => 'leads_only', 'where' => 'laptop'], 'leads_only', 'Your admin allows AI help for leads only.'];
    }

    /**
     * @param array<string, bool|string> $ai
     */
    #[Test]
    #[DataProvider('policiesChangedAfterThePackage')]
    public function aResultThePolicyNoLongerAllowsIsForbiddenWithItsSentenceAndThePackageStaysOpen(array $ai, string $code, string $sentence): void
    {
        $this->storeAiSettings($this->acmeFoods, $ai);

        $this->postResult(self::resultBody($this->omarsPackageId), $this->laptopToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['message' => $sentence], $this->answer());
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([$this->refusalEntry('Omar', $code)], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPackageIds(): iterable
    {
        yield 'not a UUID' => ['package-1'];
        yield 'empty' => [''];
        yield 'a version 4 UUID' => ['0d6f2a3e-7b1c-4f7e-9a2b-3c4d5e6f7a8b'];
        yield 'a CRLF after a UUIDv7' => ["019a7c00-0000-7000-8000-000000000001\r\n"];
        yield 'a line feed after a UUIDv7' => ["019a7c00-0000-7000-8000-000000000001\n"];
        yield 'a byte order mark before a UUIDv7' => ["\u{FEFF}019a7c00-0000-7000-8000-000000000001"];
        yield 'multibyte digits' => ['019a7c00-0000-7000-8000-00000000000١'];
    }

    /** Before the service: no package is looked up, so nothing is audited either. */
    #[Test]
    #[DataProvider('malformedPackageIds')]
    public function aMalformedPackageIdIsNotFoundAndNotAudited(string $packageId): void
    {
        $this->postResult(self::resultBody($packageId), $this->laptopToken());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => self::NOT_FOUND], $this->answer());
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesThatAreNoResult(): iterable
    {
        $packageId = '019a7c00-0000-7000-8000-000000000001';
        yield 'no text' => ['{"packageId": "'.$packageId.'", "model": "qwen3:4b"}'];
        yield 'no model' => ['{"packageId": "'.$packageId.'", "text": "Pay does nothing."}'];
        yield 'no package id' => ['{"model": "qwen3:4b", "text": "Pay does nothing."}'];
        yield 'an extra key' => ['{"packageId": "'.$packageId.'", "model": "qwen3:4b", "text": "Pay does nothing.", "label": "x"}'];
        yield 'a text that is a list' => ['{"packageId": "'.$packageId.'", "model": "qwen3:4b", "text": ["Pay does nothing."]}'];
        yield 'a model that is null' => ['{"packageId": "'.$packageId.'", "model": null, "text": "Pay does nothing."}'];
        yield 'a package id that is a number' => ['{"packageId": 7, "model": "qwen3:4b", "text": "Pay does nothing."}'];
        yield 'not JSON' => ['packageId='.$packageId];
        yield 'invalid UTF-8' => ["{\"packageId\": \"{$packageId}\", \"model\": \"qwen3:4b\", \"text\": \"\xC3\x28\"}"];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('bodiesThatAreNoResult')]
    public function aBodyThatIsNoResultIsRefusedAndNotAudited(string $body): void
    {
        $this->postResult($body, $this->laptopToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => LaptopController::NOT_A_RESULT], $this->answer());
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aMissingOrWrongTokenAnswersAsAnExpiredPageAndNothingIsAudited(): void
    {
        foreach (['not-the-token', ''] as $token) {
            $this->postResult(self::resultBody($this->omarsPackageId), $token);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['message' => LaptopController::FORM_EXPIRED], $this->answer());
        }
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aBodyOneByteOverTheCapIsRefusedBeforeThePackageIsLookedUpAndExactlyTheCapIsRead(): void
    {
        $token = $this->laptopToken();

        $this->postResult(str_pad(self::resultBody($this->omarsPackageId), LaptopController::MAX_RESULT_BODY_BYTES + 1), $token);

        self::assertResponseStatusCodeSame(413);
        self::assertSame(['message' => LaptopController::RESULT_TOO_LARGE], $this->answer());
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));

        $this->postResult(str_pad(self::resultBody($this->omarsPackageId), LaptopController::MAX_RESULT_BODY_BYTES), $token);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * The cap must read any answer the check could keep: 20,000 bytes, each escaped by JSON into six. This one is
     * then refused by the check, not by the cap.
     */
    #[Test]
    public function theLargestAnswerTheCheckReadsFitsUnderTheCapEvenWhenEveryByteIsEscaped(): void
    {
        $body = self::resultBody($this->omarsPackageId, str_repeat("\u{0001}", OutputValidator::MAX_ANSWER_BYTES));
        self::assertGreaterThan(6 * OutputValidator::MAX_ANSWER_BYTES, \strlen($body));

        $this->postResult($body, $this->laptopToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => 'The AI answer is blank.'], $this->answer());
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInIsSentToTheLoginPageAndNothingIsAudited(): void
    {
        $this->client->getCookieJar()->clear();

        $this->postResult(self::resultBody($this->omarsPackageId), 'no-token');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('https://localhost/login');
        self::assertSame($this->unusedRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * @return array{string, string, string, array<string, mixed>}
     */
    private function refusalEntry(string $person, string $reason): array
    {
        return [$this->idOf($person), 'local_ai.result_refused', $this->omarsPackageId, ['model' => self::MODEL, 'reason' => $reason]];
    }
}
