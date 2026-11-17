<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist\Laptop;

use App\Assist\UI\Controller\LaptopController;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Fixtures\Security\ExpectedSecurityHeaders;
use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The header's Local AI chip and setup sheet on the home page, and the page's Content-Security-Policy, for Omar of Acme
 * Foods under each AI policy. Only a page with the chip may let the browser reach Ollama on the laptop; every other
 * page keeps exactly the headers SecurityHeadersTest pins.
 */
final class LocalAiChipTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use StoresAiSettings;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string OLLAMA_SOURCE = ' http://localhost:11434';
    private const string STORAGE_ORIGIN = 'http://localhost:9000';
    private const string MERCURE_ORIGIN = 'https://localhost';
    private const string AI_SETTINGS_QUERY = "/^SELECT CASE WHEN jsonb_typeof\\(data->'ai'\\)/";

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
    public static function laptopPlaces(): iterable
    {
        yield 'laptop only' => ['laptop', 'Until it runs, AI features stay hidden for you.'];
        yield 'laptop first, then cloud' => ['laptop_first', 'Until it runs, pinround uses the company cloud AI.'];
    }

    #[Test]
    #[DataProvider('laptopPlaces')]
    public function aLaptopOrganizationShowsTheChipNotCheckedAndItsSheetAndLetsThePageReachOllama(string $where, string $fallbackSentence): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi($where));
        $this->logIn($this->client, $this->omar);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Local AI: not checked'], self::shownLines($page->filter('.site-header .local-ai__chip')));
        self::assertSame('dialog', $page->filter('.local-ai__chip')->attr('aria-haspopup'));
        self::assertSame('not-checked', $page->filter('.local-ai')->attr('data-state'));
        self::assertSame('AI on your laptop', $page->filter('#local-ai-sheet-title')->text());
        self::assertSame('local-ai-sheet-title', $page->filter('dialog#local-ai-sheet')->attr('aria-labelledby'));
        self::assertSame('Your company runs pinround’s AI on each laptop, for free. '.$fallbackSentence, $page->filter('.local-ai__sub')->text());
        self::assertSame(['Not checked yet.'], self::shownLines($page->filter('.local-ai__status')));
        self::assertSame([
            'Install Ollama from ollama.com. It runs in the background.',
            'Get the model once, in a terminal: ollama pull qwen3:4b (about 2.5 GB).',
            'Let pinround talk to it: set OLLAMA_ORIGINS to your pinround address and restart Ollama. Your IT team can do this for everyone.',
            'Press “Check again”. Your browser may ask if this page may talk to apps on your computer. Say yes.',
        ], $page->filter('.local-ai__steps li')->each(static fn (Crawler $step): string => $step->text()));
        self::assertSame(['Close', 'Check again'], $page->filter('#local-ai-sheet .dialog__actions button')->each(static fn (Crawler $button): string => $button->text()));

        $controller = $page->filter('.local-ai');
        self::assertSame('http://localhost:11434/api/tags', $controller->attr('data-local-ai-tags-url-value'));
        self::assertSame('/ai/laptop/status', $controller->attr('data-local-ai-status-url-value'));
        self::assertSame(LaptopController::CSRF_HEADER, $controller->attr('data-local-ai-csrf-header-value'));
        self::assertNotSame('', (string) $controller->attr('data-local-ai-csrf-token-value'));

        $headers = ExpectedSecurityHeaders::presentIn($this->client->getResponse());
        $expectedHeaders = ExpectedSecurityHeaders::withoutStrictTransportSecurity($this->nonceOf($page), self::STORAGE_ORIGIN, self::MERCURE_ORIGIN);
        $expectedHeaders['content-security-policy'] = str_replace(
            'connect-src \'self\' '.self::STORAGE_ORIGIN.' '.self::MERCURE_ORIGIN.';',
            'connect-src \'self\' '.self::STORAGE_ORIGIN.' '.self::MERCURE_ORIGIN.self::OLLAMA_SOURCE.';',
            $expectedHeaders['content-security-policy'],
        );
        self::assertSame($expectedHeaders, $headers);
    }

    /**
     * The stored AI settings and Omar's organization roles.
     *
     * @return iterable<string, array{array<string, bool|string>|null, string}>
     */
    public static function pagesWithoutTheChip(): iterable
    {
        yield 'no AI settings at all' => [null, '{}'];
        yield 'AI runs in the cloud' => [['on' => true, 'where' => 'cloud'], '{}'];
        yield 'AI is off, though set to laptops' => [['on' => false, 'where' => 'laptop'], '{}'];
        yield 'AI for leads only, and Omar leads no team' => [['on' => true, 'who' => 'leads_only', 'where' => 'laptop'], '{}'];
        yield 'Omar is an admin with no team or manager role' => [self::laptopAi(), '{admin}'];
    }

    /**
     * @param array<string, bool|string>|null $ai
     */
    #[Test]
    #[DataProvider('pagesWithoutTheChip')]
    public function withoutLaptopAiForOmarThePageHasNoChipAndKeepsTheCspExactly(?array $ai, string $orgRoles): void
    {
        if (null !== $ai) {
            $this->storeAiSettings($this->acmeFoods, $ai);
        }
        $this->setOrgRoles($this->omar, $this->acmeFoods, $orgRoles);
        $this->logIn($this->client, $this->omar);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('.site-header'));
        self::assertCount(0, $page->filter('.local-ai'));
        self::assertCount(0, $page->filter('#local-ai-sheet'));
        self::assertSame(
            ExpectedSecurityHeaders::withoutStrictTransportSecurity($this->nonceOf($page), self::STORAGE_ORIGIN, self::MERCURE_ORIGIN),
            ExpectedSecurityHeaders::presentIn($this->client->getResponse()),
        );
    }

    #[Test]
    public function theLoginPageHasNoChipAndThePlainCsp(): void
    {
        $page = $this->client->request('GET', '/login');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.local-ai'));
        self::assertStringNotContainsString('11434', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function aLaptopPageReadsTheAiSettingsOnce(): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());
        $this->logIn($this->client, $this->omar);
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        $aiSettingsReads = preg_grep(self::AI_SETTINGS_QUERY, $this->queriesOf($debugData->getData()));
        self::assertIsArray($aiSettingsReads);
        self::assertCount(1, $aiSettingsReads);
    }

    #[Test]
    public function anAdminOnlyPageNeverReadsTheAiSettings(): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());
        $this->setOrgRoles($this->omar, $this->acmeFoods, '{admin}');
        $this->logIn($this->client, $this->omar);
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        $sql = $this->queriesOf($debugData->getData());
        self::assertNotSame([], $sql);
        self::assertSame([], preg_grep(self::AI_SETTINGS_QUERY, $sql));
    }

    /**
     * @param array<mixed> $debugData doctrine.debug_data_holder's queries by connection
     *
     * @return list<string> the SQL of every query
     */
    private function queriesOf(array $debugData): array
    {
        $sql = [];
        foreach ($debugData as $queriesOfOneConnection) {
            foreach (\is_array($queriesOfOneConnection) ? $queriesOfOneConnection : [] as $query) {
                $sql[] = \is_array($query) && \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            }
        }

        return $sql;
    }

    /**
     * @return list<string> the texts of the state lines the page shows, not the hidden ones
     */
    private static function shownLines(Crawler $holder): array
    {
        self::assertCount(1, $holder);

        return array_values(array_filter(
            $holder->filter('[data-states]')->each(static fn (Crawler $line): ?string => null === $line->attr('hidden') ? $line->text() : null),
            static fn (?string $line): bool => null !== $line,
        ));
    }

    private function nonceOf(Crawler $page): string
    {
        $nonce = (string) $page->filter('meta[name="csp-nonce"]')->attr('content');
        self::assertNotSame('', $nonce);

        return $nonce;
    }
}
