<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization\Admin;

use App\Identity\Domain\User;
use App\Organization\Application\ChangeAiSettings;
use App\Organization\Domain\OrgSettings;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\Organization\UI\Admin\AiSettingsController;
use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Identity\TurnsOnTwoStepLogin;
use App\Tests\Functional\Organization\LogsInAdmins;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Admin › AI tab, /admin/ai, through the real firewall, login form, code page, outbox and audit worker over HTTPS.
 * The client keeps its kernel, so the clock set in the container is the one every request reads; it is fixed on
 * 16 Nov 2026, inside the months aud_event has partitions for and before the day this was written. A clock ahead of the
 * wall clock would end the session: its idle check reads the session's own time.
 */
final class AdminAiTest extends WebTestCase
{
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RunsTheWorkerUntilIdle;
    use TurnsOnTwoStepLogin;

    private const string AI_PAGE = '/admin/ai';
    private const string PROVIDER_KEY_PAGE = '/admin/ai/provider-key';
    private const string CONFIRM_FOR_THE_AI_PAGE = '/me/confirm?return=/admin/ai';
    private const string NOW = '2026-11-16 09:00:00 UTC';
    private const string RAW_PROVIDER_KEY = 'sk-ant-test-4f2b9c0e7d1a5b3c';
    // The CSRF ids AiSettingsController checks, as the tab's forms send them.
    private const string CHANGE_CSRF_ID = 'organization_admin_ai_change';
    private const string PROVIDER_KEY_CSRF_ID = 'organization_admin_ai_provider_key';

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]));
        }
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function anAdminWithARecentCodeSeesEachSettingAsStoredAndNoSavedKey(): void
    {
        $this->adminLoggedInWithACode(['provider' => 'anthropic', 'where' => 'laptop_first']);

        $page = $this->client->request('GET', self::AI_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('AI', $page->filter('main h1')->text());
        self::assertSame(['Everyone', 'Title and ticket text', 'Laptop first, cloud when it is off', 'Anthropic (Claude)'], $page->filter('main button.chip[aria-pressed="true"]')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertSame(['on' => 'true', 'sync' => 'true', 'restricted_to_cloud' => 'false'], $this->switchesOn($page));
        self::assertSame('Free when Ollama runs. Otherwise pinround uses the cloud AI, so nobody is left without AI.', $page->filter('main .admin-ai__where-note')->text());
        self::assertSame('No key saved. pinround uses its own key.', $page->filter('main #admin-ai-key-status')->text());
        self::assertSame('', $page->filter('main input[name="provider_key"]')->attr('value'));
        self::assertCount(0, $page->filter('main [role="alert"]'));
    }

    /**
     * Each change is its own request; choosing what is stored already writes nothing and publishes nothing.
     */
    #[Test]
    public function anAdminChangesEachSettingAtOnceAndEachChangeIsAuditedAsSettingsChanged(): void
    {
        [$sara, $acmeFoods] = $this->adminLoggedInWithACode();
        $this->client->request('GET', self::AI_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->forgetEarlierEvents();

        $this->choose('on', 'off');
        $this->choose('who', 'leads_only');
        $this->choose('send', 'title_only');
        $this->choose('sync', 'off');
        $this->choose('where', 'laptop');
        $this->choose('restricted_to_cloud', 'on');
        $this->choose('provider', 'ollama');
        $this->choose('who', 'leads_only');

        $page = $this->client->getCrawler();
        self::assertSame(['Leads only', 'Title only', 'Each person’s laptop (Ollama)', 'Ollama server'], $page->filter('main button.chip[aria-pressed="true"]')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertSame(['on' => 'false', 'sync' => 'false', 'restricted_to_cloud' => 'true'], $this->switchesOn($page));
        // assertEquals: jsonb keeps the keys, not their order.
        self::assertEquals(
            ['on' => false, 'who' => 'leads_only', 'send' => 'title_only', 'sync' => false, 'where' => 'laptop', 'model' => 'qwen3:4b', 'restricted_to_cloud' => true, 'provider' => 'ollama'],
            $this->storedSettingsOf($acmeFoods)['ai'],
        );
        self::assertSame(8, $this->storedSettingsOf($acmeFoods)['version'], 'One save for each change, none for choosing what is stored.');

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        $at = $this->clock->now()->format('Y-m-d H:i:s');
        $byUserOnOrganization = ['user', $sara->id()->toString(), 'settings.changed', 'organization', $acmeFoods->toString(), $at];
        self::assertEqualsCanonicalizing(
            [
                [...$byUserOnOrganization, ['setting' => 'ai.on', 'from' => true, 'to' => false]],
                [...$byUserOnOrganization, ['setting' => 'ai.who', 'from' => 'everyone', 'to' => 'leads_only']],
                [...$byUserOnOrganization, ['setting' => 'ai.send', 'from' => 'title_and_text', 'to' => 'title_only']],
                [...$byUserOnOrganization, ['setting' => 'ai.sync', 'from' => true, 'to' => false]],
                [...$byUserOnOrganization, ['setting' => 'ai.where', 'from' => 'cloud', 'to' => 'laptop']],
                [...$byUserOnOrganization, ['setting' => 'ai.restricted_to_cloud', 'from' => null, 'to' => true]],
                [...$byUserOnOrganization, ['setting' => 'ai.provider', 'from' => null, 'to' => 'ollama']],
            ],
            $this->auditRowsOf($acmeFoods),
        );
    }

    /**
     * The key is pasted with a line break after it. It is stored encrypted under the current key's id, and it is in
     * no page, no stored setting and no audit row as it was typed.
     */
    #[Test]
    public function anAdminWithAFreshCodeSavesAProviderKeyThatIsStoredEncryptedAndShownOnlyAsSaved(): void
    {
        [$sara, $acmeFoods] = $this->adminLoggedInWithACode();
        $this->client->request('GET', self::AI_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->forgetEarlierEvents();

        $this->client->submitForm('Save key', ['provider_key' => self::RAW_PROVIDER_KEY."\r\n"]);

        self::assertResponseRedirects(self::AI_PAGE, 302);
        self::assertStringNotContainsString(self::RAW_PROVIDER_KEY, (string) $this->client->getResponse()->getContent());
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(AiSettingsController::PROVIDER_KEY_SAVED, $page->filter('.toast-region .toast')->attr('data-toast-message-value'));
        self::assertSame('A key is saved. A new key replaces it.', $page->filter('main #admin-ai-key-status')->text());
        self::assertSame('', $page->filter('main input[name="provider_key"]')->attr('value'));
        self::assertStringNotContainsString(self::RAW_PROVIDER_KEY, (string) $this->client->getResponse()->getContent());

        $stored = $this->storedSettingsOf($acmeFoods);
        self::assertStringNotContainsString(self::RAW_PROVIDER_KEY, $stored['json']);
        $ai = $stored['ai'];
        self::assertIsString($ai['secret_enc']);
        $encrypted = base64_decode($ai['secret_enc'], true);
        self::assertIsString($encrypted);
        self::assertSame(self::RAW_PROVIDER_KEY, self::getContainer()->get(FieldEncryption::class)->decrypt($encrypted));
        self::assertIsString($ai['key_id']);
        self::assertSame(substr($encrypted, 1, \ord($encrypted[0])), $ai['key_id']);

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        self::assertSame(
            [['user', $sara->id()->toString(), 'settings.changed', 'organization', $acmeFoods->toString(), $this->clock->now()->format('Y-m-d H:i:s'), ['setting' => 'ai.provider_key', 'from' => null, 'to' => 'changed']]],
            $this->auditRowsOf($acmeFoods),
        );
    }

    /**
     * The code is 11 minutes old: enough for the admin pages (an hour) and their settings, not for the key (10 minutes,
     * RequiresRecentAuth). After "Confirm it's you" the same key is saved.
     */
    #[Test]
    public function anAdminWhoseCodeIsOverTenMinutesOldIsSentToConfirmItsYouBeforeTheKeyIsSaved(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->storeNewSettings($acmeFoods, $sara);
        $seed = $this->logInWithACode($this->client, $this->clock, $sara);
        $this->clock->sleep(11 * 60);
        $this->client->request('GET', self::AI_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->forgetEarlierEvents();
        $before = $this->storedSettingsOf($acmeFoods);

        $this->client->submitForm('Save key', ['provider_key' => self::RAW_PROVIDER_KEY]);

        self::assertResponseRedirects(self::CONFIRM_FOR_THE_AI_PAGE, 302);
        self::assertSame($before, $this->storedSettingsOf($acmeFoods));
        self::assertSame(0, $this->outboxCount());

        $this->client->request('GET', self::AI_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->choose('where', 'laptop');
        self::assertSame('laptop', $this->storedSettingsOf($acmeFoods)['ai']['where'] ?? null);

        $this->client->request('GET', self::CONFIRM_FOR_THE_AI_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);
        self::assertResponseRedirects(self::AI_PAGE, 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Save key', ['provider_key' => self::RAW_PROVIDER_KEY]);
        self::assertResponseRedirects(self::AI_PAGE, 302);
        self::assertIsString($this->storedSettingsOf($acmeFoods)['ai']['secret_enc'] ?? null);
    }

    /**
     * Over an hour after the last code, the admin pages ask for the code again (ADMIN_AREA) before anything is shown or
     * stored, even with valid form tokens.
     */
    #[Test]
    public function anAdminWhoseCodeIsOverAnHourOldIsSentToConfirmItsYouAndNothingIsStored(): void
    {
        [, $acmeFoods] = $this->adminLoggedInWithACode();
        $this->clock->sleep(60 * 60 + 1);
        $this->forgetEarlierEvents();
        $before = $this->storedSettingsOf($acmeFoods);

        $this->client->request('GET', self::AI_PAGE);
        self::assertResponseRedirects(self::CONFIRM_FOR_THE_AI_PAGE, 302);

        $this->client->request('POST', self::AI_PAGE, ['setting' => 'on', 'choice' => 'off', '_csrf_token' => $this->csrfTokenFor($this->client, self::CHANGE_CSRF_ID)]);
        self::assertSentToConfirmItsYou($this->client);

        $this->client->request('POST', self::PROVIDER_KEY_PAGE, ['provider_key' => self::RAW_PROVIDER_KEY, '_csrf_token' => $this->csrfTokenFor($this->client, self::PROVIDER_KEY_CSRF_ID)]);
        self::assertSentToConfirmItsYou($this->client);

        self::assertSame($before, $this->storedSettingsOf($acmeFoods));
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * @return iterable<string, array{string, string, 'lead'|'member'|'viewer'|null}>
     */
    public static function peopleBelowAdmin(): iterable
    {
        yield 'Raj, a manager' => ['Raj Patel', '{manager}', null];
        yield 'Lena, a lead' => ['Lena Berg', '{}', 'lead'];
        yield 'Omar, a member' => ['Omar Haddad', '{}', 'member'];
        yield 'Mia, a viewer' => ['Mia Chen', '{}', 'viewer'];
    }

    /**
     * With a fresh code and valid form tokens, so only the role refuses: without the access check the change and the
     * key would be stored.
     *
     * @param 'lead'|'member'|'viewer'|null $teamRole
     */
    #[Test]
    #[DataProvider('peopleBelowAdmin')]
    public function aPersonBelowAdminIsRefusedThePageTheChangesAndTheKeyAndNothingIsStored(string $name, string $orgRoles, ?string $teamRole): void
    {
        [$person, $acmeFoods] = $this->memberWithOrgRoles($name, $orgRoles);
        if (null !== $teamRole) {
            $this->giveTeamRole($person, $acmeFoods, $teamRole);
        }
        $this->storeNewSettings($acmeFoods, $person);
        $this->logInWithACode($this->client, $this->clock, $person);
        $this->forgetEarlierEvents();
        $before = $this->storedSettingsOf($acmeFoods);

        $page = $this->client->request('GET', self::AI_PAGE);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $page->filter('main form'));

        $this->client->request('POST', self::AI_PAGE, ['setting' => 'on', 'choice' => 'off', '_csrf_token' => $this->csrfTokenFor($this->client, self::CHANGE_CSRF_ID)]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', self::PROVIDER_KEY_PAGE, ['provider_key' => self::RAW_PROVIDER_KEY, '_csrf_token' => $this->csrfTokenFor($this->client, self::PROVIDER_KEY_CSRF_ID)]);
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString(self::RAW_PROVIDER_KEY, (string) $this->client->getResponse()->getContent());

        self::assertSame($before, $this->storedSettingsOf($acmeFoods));
        self::assertSame(0, $this->outboxCount());
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', self::AI_PAGE);
        self::assertResponseRedirects('/login', 302);

        $this->client->request('POST', self::AI_PAGE, ['setting' => 'on', 'choice' => 'off', '_csrf_token' => 'any']);
        self::assertResponseRedirects('/login', 302);

        $this->client->request('POST', self::PROVIDER_KEY_PAGE, ['provider_key' => self::RAW_PROVIDER_KEY, '_csrf_token' => 'any']);
        self::assertResponseRedirects('/login', 302);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function choicesTheTabDoesNotOffer(): iterable
    {
        yield 'an unknown setting' => [['setting' => 'model', 'choice' => 'phi4-mini']];
        yield 'the encrypted key' => [['setting' => 'secret_enc', 'choice' => 'on']];
        yield 'an unknown choice' => [['setting' => 'where', 'choice' => 'moon']];
        yield 'the stored value instead of the choice' => [['setting' => 'on', 'choice' => 'false']];
        yield 'no setting' => [['choice' => 'off']];
    }

    /**
     * @param array<string, string> $fields
     */
    #[Test]
    #[DataProvider('choicesTheTabDoesNotOffer')]
    public function aChoiceTheTabDoesNotOfferShowsTheReasonAndChangesNothing(array $fields): void
    {
        [, $acmeFoods] = $this->adminLoggedInWithACode();
        $this->forgetEarlierEvents();
        $before = $this->storedSettingsOf($acmeFoods);

        $page = $this->client->request('POST', self::AI_PAGE, [...$fields, '_csrf_token' => $this->csrfTokenFor($this->client, self::CHANGE_CSRF_ID)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ChangeAiSettings::UNKNOWN_CHOICE, $page->filter('main [role="alert"]')->text());
        self::assertSame($before, $this->storedSettingsOf($acmeFoods));
        self::assertSame(0, $this->outboxCount());
    }

    #[Test]
    public function aSettingSentAsAListIsRefusedAsABadRequestAndChangesNothing(): void
    {
        [, $acmeFoods] = $this->adminLoggedInWithACode();
        $before = $this->storedSettingsOf($acmeFoods);

        $this->client->request('POST', self::AI_PAGE, ['setting' => ['on'], 'choice' => 'off', '_csrf_token' => $this->csrfTokenFor($this->client, self::CHANGE_CSRF_ID)]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame($before, $this->storedSettingsOf($acmeFoods));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valuesThatAreNoKey(): iterable
    {
        yield 'empty' => ['', ChangeAiSettings::PROVIDER_KEY_MISSING];
        yield 'one character over the cap' => [str_repeat('k', ChangeAiSettings::PROVIDER_KEY_MAX_LENGTH + 1), ChangeAiSettings::PROVIDER_KEY_TOO_LONG];
        yield 'a space inside' => ['sk-ant-test 4f2b9c0e7d1a', ChangeAiSettings::PROVIDER_KEY_NOT_PRINTABLE];
        yield 'markup' => ['<b>sk-ant-test</b>', ChangeAiSettings::PROVIDER_KEY_NOT_PRINTABLE];
    }

    #[Test]
    #[DataProvider('valuesThatAreNoKey')]
    public function aValueThatIsNoKeyShowsTheReasonStoresNothingAndIsNotShownAgain(string $typed, string $reason): void
    {
        [, $acmeFoods] = $this->adminLoggedInWithACode();
        $this->client->request('GET', self::AI_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->forgetEarlierEvents();
        $before = $this->storedSettingsOf($acmeFoods);

        $page = $this->client->submitForm('Save key', ['provider_key' => $typed]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($reason, $page->filter('main [role="alert"]')->text());
        self::assertSame('', $page->filter('main input[name="provider_key"]')->attr('value'));
        if ('' !== $typed) {
            self::assertStringNotContainsString($typed, (string) $this->client->getResponse()->getContent());
        }
        self::assertSame('No key saved. pinround uses its own key.', $page->filter('main #admin-ai-key-status')->text());
        self::assertSame($before, $this->storedSettingsOf($acmeFoods));
        self::assertSame(0, $this->outboxCount());
    }

    #[Test]
    public function aChangeWithAWrongCsrfTokenIsRefusedAndChangesNothing(): void
    {
        [, $acmeFoods] = $this->adminLoggedInWithACode();
        $this->client->request('GET', self::AI_PAGE);
        self::assertResponseStatusCodeSame(200);
        $before = $this->storedSettingsOf($acmeFoods);

        $page = $this->client->request('POST', self::AI_PAGE, ['setting' => 'on', 'choice' => 'off', '_csrf_token' => 'not-the-token']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(AiSettingsController::FORM_EXPIRED, $page->filter('main [role="alert"]')->text());

        $page = $this->client->request('POST', self::PROVIDER_KEY_PAGE, ['provider_key' => self::RAW_PROVIDER_KEY, '_csrf_token' => $this->csrfTokenFor($this->client, self::CHANGE_CSRF_ID)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(AiSettingsController::FORM_EXPIRED, $page->filter('main [role="alert"]')->text());

        self::assertSame($before, $this->storedSettingsOf($acmeFoods));
    }

    /**
     * Another request saves "where" between this request's read of the settings and its write. This request's write
     * then fails the version check: it is rolled back, the page shows the other save and says so, and nothing of this
     * request is stored or published.
     */
    #[Test]
    public function aChangeThatLostTheRaceToAnotherSaveIsRefusedAndKeepsTheOtherSave(): void
    {
        $store = new OrgSettingsStoreWithAnotherSave(new DoctrineOrgSettingsStore(self::getContainer()->get('doctrine.orm.entity_manager')));
        // Set before the first request builds ChangeAiSettings, which keeps the store it is given.
        self::getContainer()->set(DoctrineOrgSettingsStore::class, $store);
        [, $acmeFoods] = $this->adminLoggedInWithACode();
        $this->client->request('GET', self::AI_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->forgetEarlierEvents();
        $store->anotherSaveAfterNextRead = fn () => $this->duringTheRequest(fn () => $this->committedAs($acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_settings SET data = jsonb_set(data, '{ai,where}', '\"laptop\"'), version = version + 1 WHERE organization_id = ?",
            [$acmeFoods->toString()],
        )));

        $page = $this->client->request('POST', self::AI_PAGE, ['setting' => 'on', 'choice' => 'off', '_csrf_token' => $this->csrfTokenFor($this->client, self::CHANGE_CSRF_ID)]);

        self::assertResponseStatusCodeSame(409);
        self::assertNull($store->anotherSaveAfterNextRead, 'The other save never ran.');
        self::assertSame(AiSettingsController::CHANGED_MEANWHILE, $page->filter('main [role="alert"]')->text());
        self::assertSame(['on' => 'true', 'sync' => 'true', 'restricted_to_cloud' => 'false'], $this->switchesOn($page));
        self::assertContains('Each person’s laptop (Ollama)', $page->filter('main button.chip[aria-pressed="true"]')->each(static fn (Crawler $chip): string => $chip->text()));
        $stored = $this->storedSettingsOf($acmeFoods);
        self::assertSame([true, 'laptop', 2], [$stored['ai']['on'] ?? null, $stored['ai']['where'] ?? null, $stored['version']]);
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * Sara, the Admin of a new Acme Foods with new settings, logged in with a code at the clock's time.
     *
     * @param array<string, bool|string> $aiChanges data.ai keys to store in place of the defaults
     *
     * @return array{User, OrganizationId}
     */
    private function adminLoggedInWithACode(array $aiChanges = []): array
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->storeNewSettings($acmeFoods, $sara, $aiChanges);
        $this->logInWithACode($this->client, $this->clock, $sara);

        return [$sara, $acmeFoods];
    }

    /**
     * The settings a new organization gets (OrgSettings), with $aiChanges in data.ai.
     *
     * @param array<string, bool|string> $aiChanges
     */
    private function storeNewSettings(OrganizationId $organizationId, User $firstAdmin, array $aiChanges = []): void
    {
        $data = new OrgSettings($organizationId, $firstAdmin->id(), new \DateTimeImmutable('2027-03-01 08:00:00 UTC'))->data();
        $ai = $data['ai'];
        \assert(\is_array($ai));
        $data['ai'] = [...$ai, ...$aiChanges];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, ?, '2027-03-01 08:00:00+00')",
            [$organizationId->toString(), json_encode($data, \JSON_THROW_ON_ERROR)],
        ));
    }

    /**
     * Clicks the chip of $choice, or the switch that turns $setting to $choice, on the page the client shows.
     */
    private function choose(string $setting, string $choice): void
    {
        $settingForm = $this->client->getCrawler()->filter('main form')->reduce(static fn (Crawler $form): bool => $setting === $form->filter('input[name="setting"]')->attr('value'));
        self::assertCount(1, $settingForm, \sprintf('The page has no form for "%s".', $setting));
        $chip = $settingForm->filter(\sprintf('button[name="choice"][value="%s"]', $choice));
        if (\count($chip) > 0) {
            $this->client->submit($chip->form());
        } else {
            self::assertSame($choice, $settingForm->filter('input[name="choice"]')->attr('value'), \sprintf('The switch of "%s" does not turn it %s.', $setting, $choice));
            $this->client->submit($settingForm->filter('button[role="switch"]')->form());
        }

        self::assertResponseRedirects(self::AI_PAGE, 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return array<string, string|null> each switch's setting and its aria-checked
     */
    private function switchesOn(Crawler $page): array
    {
        $switches = [];
        $page->filter('main form')->each(static function (Crawler $form) use (&$switches): void {
            $switch = $form->filter('button[role="switch"]');
            if (\count($switch) > 0) {
                $switches[(string) $form->filter('input[name="setting"]')->attr('value')] = $switch->attr('aria-checked');
            }
        });

        return $switches;
    }

    /**
     * @param 'lead'|'member'|'viewer' $teamRole
     */
    private function giveTeamRole(User $person, OrganizationId $organizationId, string $teamRole): void
    {
        if ('lead' === $teamRole) {
            $this->createTeam($organizationId, 'Shop', $person);

            return;
        }
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $organizationId);
        $this->addTeamRole($person, $organizationId, $this->createTeam($organizationId, 'Shop', $lena), $teamRole);
    }

    private static function assertSentToConfirmItsYou(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('/me/confirm?return=', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * Drops the events of the logins, so the outbox holds only what the requests after it publish.
     */
    private function forgetEarlierEvents(): void
    {
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    private function outboxCount(): int
    {
        $count = $this->migrator()->fetchOne("SELECT count(*)::int FROM messenger_outbox WHERE queue_name = 'outbox'");
        self::assertIsInt($count);

        return $count;
    }

    /**
     * Runs $work in the middle of a request and gives the request its organization back: committedAs leaves none.
     */
    private function duringTheRequest(\Closure $work): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $requestOrganization = $tenantContext->get();
        try {
            $work();
        } finally {
            if (null !== $requestOrganization) {
                $tenantContext->reset();
                $tenantContext->set($requestOrganization);
            }
        }
    }

    /**
     * @return array{ai: array<array-key, mixed>, json: string, version: int} data.ai, all of data as stored, and the version
     */
    private function storedSettingsOf(OrganizationId $organizationId): array
    {
        $stored = null;
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$stored): int {
            $stored = $migrator->fetchAssociative('SELECT data::text AS data, version FROM org_settings WHERE organization_id = ?', [$organizationId->toString()]);

            return 0;
        });
        self::assertIsArray($stored);
        self::assertIsString($stored['data']);
        self::assertIsInt($stored['version']);
        $data = json_decode($stored['data'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['ai'] ?? null);

        return ['ai' => $data['ai'], 'json' => $stored['data'], 'version' => $stored['version']];
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed, array<mixed>}> actor kind, actor, action, target type, target, time, data
     */
    private function auditRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT actor_kind, actor_user_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), data::text FROM aud_event WHERE organization_id = ?",
                [$organizationId->toString()],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            self::assertIsString($row[6]);
            $data = json_decode($row[6], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertStringNotContainsString(self::RAW_PROVIDER_KEY, $row[6]);

            return [$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $data];
        }, $rows);
    }
}
