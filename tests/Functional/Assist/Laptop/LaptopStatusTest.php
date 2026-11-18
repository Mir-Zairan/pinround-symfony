<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist\Laptop;

use App\Assist\UI\Controller\LaptopController;
use App\Identity\Domain\User;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /ai/laptop/status through the real firewall, voters, session and AI policy, for Omar of Acme Foods. The clock is
 * fixed on 6 Nov 2026, before today: a clock ahead of the login would end the session as idle. The call stores nothing
 * by design, so every answer also proves that no AI usage was written for the organization.
 */
final class LaptopStatusTest extends WebTestCase
{
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers;
    use StoresAiSettings;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string STATUS_PATH = '/ai/laptop/status';
    private const string READY_QWEN = '{"ready": true, "model": "qwen3:4b"}';

    private KernelBrowser $client;

    private OrganizationId $acmeFoods;

    private User $omar;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->acmeFoods = $this->createOrganization('Acme Foods');
        $this->omar = $this->createUserWithoutOrganization('Omar Haddad');
        $this->addMembership($this->omar, $this->acmeFoods);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function laptopStatuses(): iterable
    {
        yield 'laptop only, ready with qwen3' => ['laptop', self::READY_QWEN];
        yield 'laptop first, ready with phi4' => ['laptop_first', '{"ready": true, "model": "phi4-mini:latest"}'];
        yield 'laptop only, not ready' => ['laptop', '{"ready": false, "model": null}'];
        yield 'a model name of exactly 64 characters' => ['laptop', '{"ready": true, "model": "'.str_repeat('a', 64).'"}'];
        yield 'the keys in the other order' => ['laptop', '{"model": "gemma3:1b", "ready": true}'];
    }

    #[Test]
    #[DataProvider('laptopStatuses')]
    public function omarInALaptopOrganizationReportsWhatHisBrowserFound(string $where, string $status): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi($where));
        $this->logIn($this->client, $this->omar);

        $this->postStatus($status, $this->statusToken());

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->usageRowCount());
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInIsSentToTheLoginPage(): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());

        $this->postStatus(self::READY_QWEN, 'no-token');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('https://localhost/login');
        self::assertSame(0, $this->usageRowCount());
    }

    /**
     * The stored AI settings, Omar's organization roles, and the line that says why he gets no local AI.
     *
     * @return iterable<string, array{array<string, bool|string>, string, string}>
     */
    public static function refusedPeople(): iterable
    {
        yield 'AI runs in the cloud' => [['on' => true, 'where' => 'cloud'], '{}', 'Your admin runs AI help in the company cloud, not on laptops.'];
        yield 'AI is off, though set to laptops' => [['on' => false, 'where' => 'laptop'], '{}', 'Your admin turned AI help off.'];
        yield 'AI for leads only, and Omar leads no team' => [['on' => true, 'who' => 'leads_only', 'where' => 'laptop'], '{}', 'Your admin allows AI help for leads only.'];
        yield 'Omar is an admin with no team or manager role' => [self::laptopAi(), '{admin}', 'Local AI is for people in a team and for managers.'];
        yield 'an unknown place where the AI runs' => [['on' => true, 'where' => 'everywhere'], '{}', 'Your admin turned AI help off.'];
    }

    /**
     * @param array<string, bool|string> $ai
     */
    #[Test]
    #[DataProvider('refusedPeople')]
    public function omarIsRefusedWithTheReasonWhenHeGetsNoLocalAi(array $ai, string $orgRoles, string $reason): void
    {
        $this->storeAiSettings($this->acmeFoods, $ai);
        $this->setOrgRoles($this->omar, $this->acmeFoods, $orgRoles);
        $this->logIn($this->client, $this->omar);

        $this->postStatus(self::READY_QWEN, $this->statusToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['message' => $reason], $this->answer());
        self::assertSame(0, $this->usageRowCount());
    }

    #[Test]
    public function anAdminWhoIsAlsoAManagerMayReport(): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());
        $this->setOrgRoles($this->omar, $this->acmeFoods, '{admin,manager}');
        $this->logIn($this->client, $this->omar);

        $this->postStatus(self::READY_QWEN, $this->statusToken());

        self::assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function aMissingOrWrongTokenAnswersAsAnExpiredPage(): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());
        $this->logIn($this->client, $this->omar);
        $this->statusToken();

        $this->postStatus(self::READY_QWEN, 'not-the-token');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => LaptopController::FORM_EXPIRED], $this->answer());

        $this->postStatus(self::READY_QWEN, '');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => LaptopController::FORM_EXPIRED], $this->answer());
        self::assertSame(0, $this->usageRowCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesThatAreNoStatus(): iterable
    {
        yield 'markup in the model name' => ['{"ready": true, "model": "qwen3:4b<script>"}'];
        yield 'an uppercase model name' => ['{"ready": true, "model": "Qwen3:4b"}'];
        yield 'a model name of 65 characters' => ['{"ready": true, "model": "'.str_repeat('a', 65).'"}'];
        yield 'an empty model name' => ['{"ready": true, "model": ""}'];
        yield 'a model name with a final newline' => ['{"ready": true, "model": "qwen3:4b\n"}'];
        yield 'a model name with a space' => ['{"ready": true, "model": "qwen3 4b"}'];
        yield 'a multibyte model name' => ['{"ready": true, "model": "qwén3"}'];
        yield 'a ready laptop without a model' => ['{"ready": true, "model": null}'];
        yield 'a laptop not ready, with a model' => ['{"ready": false, "model": "qwen3:4b"}'];
        yield 'ready as a string' => ['{"ready": "true", "model": "qwen3:4b"}'];
        yield 'ready as a number' => ['{"ready": 1, "model": "qwen3:4b"}'];
        yield 'no model key' => ['{"ready": false}'];
        yield 'no ready key' => ['{"model": "qwen3:4b"}'];
        yield 'an extra key' => ['{"ready": true, "model": "qwen3:4b", "note": "x"}'];
        yield 'a model that is an object' => ['{"ready": true, "model": {"name": "qwen3:4b"}}'];
        yield 'a list' => ['[true, "qwen3:4b"]'];
        yield 'not JSON' => ['ready=true&model=qwen3:4b'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('bodiesThatAreNoStatus')]
    public function aBodyThatIsNoLaptopStatusIsRefused(string $body): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());
        $this->logIn($this->client, $this->omar);

        $this->postStatus($body, $this->statusToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => LaptopController::NOT_A_STATUS], $this->answer());
        self::assertSame(0, $this->usageRowCount());
    }

    #[Test]
    public function aBodyOfExactly1KbIsReadAndOneByteMoreIsRefused(): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());
        $this->logIn($this->client, $this->omar);
        $token = $this->statusToken();

        $this->postStatus(str_pad(self::READY_QWEN, LaptopController::MAX_BODY_BYTES), $token);
        self::assertResponseStatusCodeSame(204);

        $this->postStatus(str_pad(self::READY_QWEN, LaptopController::MAX_BODY_BYTES + 1), $token);
        self::assertResponseStatusCodeSame(413);
        self::assertSame(['message' => LaptopController::BODY_TOO_LARGE], $this->answer());
    }

    #[Test]
    public function getIsNotAllowed(): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());
        $this->logIn($this->client, $this->omar);

        $this->client->request('GET', self::STATUS_PATH);

        self::assertResponseStatusCodeSame(405);
    }

    private function postStatus(string $body, string $token): void
    {
        $this->client->request('POST', self::STATUS_PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: $body);
    }

    private function statusToken(): string
    {
        return $this->csrfTokenFor($this->client, LaptopController::CSRF_ID);
    }

    /**
     * @return array<mixed>
     */
    private function answer(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 4, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        return $answer;
    }

    private function usageRowCount(): int
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        $tenantContext->set($this->acmeFoods);
        try {
            $count = $this->migrator()->transactional(static fn (Connection $migrator): mixed => $migrator->fetchOne('SELECT count(*) FROM ast_usage'));
            self::assertIsInt($count);

            return $count;
        } finally {
            $tenantContext->reset();
        }
    }
}
