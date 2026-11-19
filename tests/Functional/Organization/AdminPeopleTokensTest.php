<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Audit\Application\RecordIdentityAuditEntry;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\User;
use App\Organization\UI\Admin\PeopleController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Identity\TurnsOnTwoStepLogin;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
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
 * The API tokens of Admin › People: an Admin sees every person's live tokens and revokes any of them (P09.T1), through
 * the real firewall, code login, outbox and audit worker over HTTPS. The clock is fixed on 16 Nov 2026, inside the
 * months aud_event has partitions for and before the day this was written: a clock ahead of the wall clock would end
 * the session, whose idle check reads the session's own time.
 */
final class AdminPeopleTokensTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use IssuesCsrfTokens;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RunsTheWorkerUntilIdle;
    use TurnsOnTwoStepLogin;

    private const string PEOPLE_PAGE = '/admin/people';
    private const string REVOKE = '/admin/people/tokens/%s/revoke';
    private const string REVOKE_CSRF_ID = 'organization_admin_people_token_revoke';
    private const string NOW = '2026-11-16 14:00:00 UTC';

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
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordIdentityAuditEntry::HANDLER_NAME]);
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Lena's token expires a day after Omar's, so the list order is not the issue order. A revoked and an expired token
     * of Omar's are left out.
     */
    #[Test]
    public function anAdminSeesEveryPersonsLiveTokensSoonestToExpireFirst(): void
    {
        [$sara, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        [$lenasToken, $lenasPlainToken] = $this->issueApiToken($lena, $acmeFoods, '2099-03-16 10:00:00 UTC');
        [$omarsToken, $omarsPlainToken] = $this->issueApiToken($omar, $acmeFoods);
        [$omarsRevokedToken] = $this->issueApiToken($omar, $acmeFoods, '2099-03-15 09:00:00 UTC');
        $this->revokeApiToken($omarsRevokedToken, $acmeFoods);
        $this->issueApiToken($omar, $acmeFoods, '2026-11-01 10:00:00 UTC', 1);
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('API tokens', $page->filter('main #people-tokens-title')->text());
        self::assertSame([$omarsToken, $lenasToken], $this->tokenIdsOn($page));
        self::assertSame(['Omar Haddad', 'Lena Berg'], $page->filter('main .people__token-row .people__token-owner')->each(static fn (Crawler $owner): string => $owner->text()));
        self::assertStringContainsString('Claude Code · pr_', $page->filter('main .people__token-row .people__sub')->first()->text());
        self::assertStringContainsString('Works until 13 Jun 2099', $page->filter('main .people__token-row .people__sub')->first()->text());
        self::assertSame(
            [
                \sprintf('Revoke Omar Haddad’s token Claude Code, %s…', self::displayPrefixOf($omarsPlainToken)),
                \sprintf('Revoke Lena Berg’s token Claude Code, %s…', self::displayPrefixOf($lenasPlainToken)),
            ],
            $page->filter('main .people__token-row form button[type="submit"]')->each(static fn (Crawler $revoke): ?string => $revoke->attr('aria-label')),
        );
        self::assertCount(0, $page->filter('main .people__tokens .empty-state'));
    }

    #[Test]
    public function withNoLiveTokensTheSectionSaysSo(): void
    {
        [$sara] = $this->acmeFoodsWithSaraOmarAndLena();
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('No API tokens work now.', $page->filter('main .people__tokens .empty-state')->text());
        self::assertCount(0, $page->filter('main .people__token-row'));
    }

    /**
     * 21 tokens; the 20th and 21st expire in the same second, so only the id in the cursor tells the second page where
     * to start. Issued in id order, so the 21st has the higher id.
     */
    #[Test]
    public function theTokensShowTwentyAtATimeWithShowMoreForTheNext(): void
    {
        [$sara, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        $soonestFirst = [];
        for ($day = 1; $day <= 19; ++$day) {
            [$soonestFirst[]] = $this->issueApiToken(0 === $day % 2 ? $omar : $lena, $acmeFoods, \sprintf('2099-01-%02d 10:00:00 UTC', $day));
        }
        [$soonestFirst[]] = $this->issueApiToken($omar, $acmeFoods, '2099-01-20 10:00:00 UTC');
        [$twentyFirst] = $this->issueApiToken($lena, $acmeFoods, '2099-01-20 10:00:00 UTC');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($soonestFirst, $this->tokenIdsOn($page));
        $showMore = $page->filter('main .people__tokens a.btn')->reduce(static fn (Crawler $link): bool => 'Show more' === $link->text());
        self::assertCount(1, $showMore);

        $page = $this->client->click($showMore->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$twentyFirst], $this->tokenIdsOn($page));
        self::assertCount(0, $page->filter('main .people__tokens a'));
    }

    /**
     * 21 invites older than the invites cursor and 21 tokens expiring after the tokens cursor, so both lists have a
     * next page; each Show more names its own list's next page and the page the other list is on.
     */
    #[Test]
    public function eachShowMoreKeepsThePageTheOtherListIsOn(): void
    {
        [$sara, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        for ($minute = 1; $minute <= 21; ++$minute) {
            $this->storeOpenInvitation($acmeFoods, \sprintf('mia+%d@acme.test', $minute), \sprintf('2026-11-16 13:%02d:00 UTC', $minute), \sprintf('0198f000-0000-7000-8000-0000000001%02d', $minute));
        }
        $soonestFirst = [];
        for ($day = 1; $day <= 21; ++$day) {
            [$soonestFirst[]] = $this->issueApiToken(0 === $day % 2 ? $omar : $lena, $acmeFoods, \sprintf('2099-01-%02d 10:00:00 UTC', $day));
        }
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE, [
            PeopleController::AFTER_INVITED_AT => '2026-11-16T13:59:00.000000+00:00',
            PeopleController::AFTER_ID => '0198f000-0000-7000-8000-0000000001ff',
            PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-01-01T00:00:00+00:00',
            PeopleController::TOKENS_AFTER_ID => '0198f000-0000-7000-8000-0000000002ff',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(PeopleController::PAGE_SIZE, $page->filter('main .people__pending .people__row'));
        self::assertCount(PeopleController::PAGE_SIZE, $page->filter('main .people__token-row'));
        self::assertSame(
            '/admin/people?tokens_after_expires_at=2099-01-01T00:00:00%2B00:00&tokens_after_id=0198f000-0000-7000-8000-0000000002ff'
            .'&after_invited_at=2026-11-16T13:02:00.000000%2B00:00&after_id=0198f000-0000-7000-8000-000000000102',
            $this->showMoreHrefOf($page, 'people__pending'),
        );
        self::assertSame(
            '/admin/people?after_invited_at=2026-11-16T13:59:00.000000%2B00:00&after_id=0198f000-0000-7000-8000-0000000001ff'
            .'&tokens_after_expires_at=2099-04-20T10:00:00%2B00:00&tokens_after_id='.$soonestFirst[19],
            $this->showMoreHrefOf($page, 'people__tokens'),
        );
    }

    /**
     * The admin is on a later page of both lists; the revoke brings them back to those pages, not to the first.
     */
    #[Test]
    public function aRevokeGoesBackToThePagesBothListsWereOn(): void
    {
        [$sara, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        [$omarsToken] = $this->issueApiToken($omar, $acmeFoods);
        [$lenasToken] = $this->issueApiToken($lena, $acmeFoods);
        $this->logInWithACode($this->client, $this->clock, $sara);
        $page = $this->client->request('GET', self::PEOPLE_PAGE, [
            PeopleController::AFTER_INVITED_AT => '2026-11-16T13:59:00.250000+00:00',
            PeopleController::AFTER_ID => '0198f000-0000-7000-8000-0000000001ff',
            PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-01-01T00:00:00+00:00',
            PeopleController::TOKENS_AFTER_ID => '0198f000-0000-7000-8000-0000000002ff',
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$omarsToken, $lenasToken], $this->tokenIdsOn($page));
        $revokedAt = $this->clock->now()->format('Y-m-d H:i:s');

        $this->client->submit($page->filter(\sprintf('main li[data-token-id="%s"] form', $omarsToken))->form());

        self::assertResponseRedirects(
            '/admin/people?after_invited_at=2026-11-16T13:59:00.250000%2B00:00&after_id=0198f000-0000-7000-8000-0000000001ff'
            .'&tokens_after_expires_at=2099-01-01T00:00:00%2B00:00&tokens_after_id=0198f000-0000-7000-8000-0000000002ff',
            302,
        );
        self::assertSame(['last_used_at' => null, 'revoked_at' => $revokedAt], $this->usageOf($omarsToken, $acmeFoods));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$lenasToken], $this->tokenIdsOn($page));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function notAPageInTheRevokeForm(): iterable
    {
        yield 'the token time only' => [[PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-01-01T00:00:00+00:00']];
        yield 'an invite id that is no uuid' => [[PeopleController::AFTER_INVITED_AT => '2026-11-16T13:59:00.000000+00:00', PeopleController::AFTER_ID => 'not-a-uuid']];
    }

    /**
     * @param array<string, string> $pages
     */
    #[Test]
    #[DataProvider('notAPageInTheRevokeForm')]
    public function aRevokeWithAPartOrBadPageCursorIsRefusedAndTheTokenStaysLive(array $pages): void
    {
        [$sara, $acmeFoods, $omar] = $this->acmeFoodsWithSaraOmarAndLena();
        [$omarsToken] = $this->issueApiToken($omar, $acmeFoods);
        $this->logInWithACode($this->client, $this->clock, $sara);

        $this->client->request('POST', \sprintf(self::REVOKE, $omarsToken), ['_csrf_token' => $this->csrfTokenFor($this->client, self::REVOKE_CSRF_ID), ...$pages]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($omarsToken, $acmeFoods));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function notAPageOfTokens(): iterable
    {
        $at = '2099-06-13T10:00:00+00:00';
        $id = '0198f000-0000-7000-8000-000000000001';
        yield 'the time only' => [[PeopleController::TOKENS_AFTER_EXPIRES_AT => $at]];
        yield 'the id only' => [[PeopleController::TOKENS_AFTER_ID => $id]];
        yield 'an id that is no uuid' => [[PeopleController::TOKENS_AFTER_EXPIRES_AT => $at, PeopleController::TOKENS_AFTER_ID => 'not-a-uuid']];
        yield 'a day the month does not have' => [[PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-06-31T10:00:00+00:00', PeopleController::TOKENS_AFTER_ID => $id]];
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('notAPageOfTokens')]
    public function aPartOrBadTokenPageCursorIsRefused(array $query): void
    {
        [$sara] = $this->acmeFoodsWithSaraOmarAndLena();
        $this->logInWithACode($this->client, $this->clock, $sara);

        $this->client->request('GET', self::PEOPLE_PAGE, $query);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function anAdminRevokesOmarsTokenOnceAndOneAuditRowNamesHerAsActor(): void
    {
        [$sara, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        [$omarsToken] = $this->issueApiToken($omar, $acmeFoods);
        [$lenasToken] = $this->issueApiToken($lena, $acmeFoods);
        $this->logInWithACode($this->client, $this->clock, $sara);
        $page = $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);
        $revokeOmars = $page->filter(\sprintf('main li[data-token-id="%s"] form', $omarsToken))->form();
        $this->forgetEarlierEvents();
        $this->clock->sleep(60);
        $revokedAt = $this->clock->now();

        $this->client->submit($revokeOmars);

        self::assertResponseRedirects(self::PEOPLE_PAGE, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(PeopleController::TOKEN_REVOKED, $page->filter('.toast-region .toast[data-controller="toast"]')->attr('data-toast-message-value'));
        self::assertSame([$lenasToken], $this->tokenIdsOn($page));
        self::assertSame(['last_used_at' => null, 'revoked_at' => $revokedAt->format('Y-m-d H:i:s')], $this->usageOf($omarsToken, $acmeFoods));
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($lenasToken, $acmeFoods));

        // A second click, as from a page opened before the first.
        $this->clock->sleep(60);
        $this->client->submit($revokeOmars);

        self::assertResponseRedirects(self::PEOPLE_PAGE, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.toast-region .toast, noscript'));
        self::assertSame(['last_used_at' => null, 'revoked_at' => $revokedAt->format('Y-m-d H:i:s')], $this->usageOf($omarsToken, $acmeFoods));
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        self::assertSame(
            [['user', $sara->id()->toString(), 'api_token.revoked', 'api_token', $omarsToken, $revokedAt->format('Y-m-d H:i:s')]],
            $this->auditRowsOf($acmeFoods),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rolesBelowAdmin(): iterable
    {
        yield 'manager' => ['{manager}'];
        yield 'member' => ['{}'];
    }

    /**
     * With a fresh code and a valid CSRF token, so only the role refuses.
     */
    #[Test]
    #[DataProvider('rolesBelowAdmin')]
    public function aPersonBelowAdminIsRefusedTheRevokeAndTheTokenStaysLive(string $orgRoles): void
    {
        [, $acmeFoods, $omar, $lena] = $this->acmeFoodsWithSaraOmarAndLena();
        $this->setOrgRoles($lena, $acmeFoods, $orgRoles);
        [$omarsToken] = $this->issueApiToken($omar, $acmeFoods);
        $this->logInWithACode($this->client, $this->clock, $lena);
        $this->forgetEarlierEvents();

        $this->client->request('POST', \sprintf(self::REVOKE, $omarsToken), ['_csrf_token' => $this->csrfTokenFor($this->client, self::REVOKE_CSRF_ID)]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($omarsToken, $acmeFoods));
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    /**
     * /admin is in the browser firewall, which reads no Bearer token, so the request is not logged in.
     */
    #[Test]
    public function anAdminsOwnTokenAsBearerIsSentToTheLoginPageAndRevokesNothing(): void
    {
        [$sara, $acmeFoods] = $this->acmeFoodsWithSaraOmarAndLena();
        [$sarasToken, $sarasPlainToken] = $this->issueApiToken($sara, $acmeFoods);

        $this->client->request('POST', \sprintf(self::REVOKE, $sarasToken), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$sarasPlainToken]);

        self::assertResponseRedirects('https://localhost/login', 302);
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($sarasToken, $acmeFoods));
    }

    #[Test]
    public function anotherOrganizationsTokenIsNotFoundAndStaysLive(): void
    {
        [$sara, $acmeFoods] = $this->acmeFoodsWithSaraOmarAndLena();
        $betaBakery = $this->createOrganization('Beta Bakery');
        $mia = $this->createUserWithoutOrganization('Mia Lund');
        $this->addMembership($mia, $betaBakery);
        [$miasToken] = $this->issueApiToken($mia, $betaBakery);
        $this->logInWithACode($this->client, $this->clock, $sara);
        $this->forgetEarlierEvents();

        $this->client->request('POST', \sprintf(self::REVOKE, $miasToken), ['_csrf_token' => $this->csrfTokenFor($this->client, self::REVOKE_CSRF_ID)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($miasToken, $betaBakery));
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        self::assertSame([], $this->auditRowsOf($acmeFoods));
        self::assertSame([], $this->auditRowsOf($betaBakery));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsOfNoToken(): iterable
    {
        yield 'malformed' => ["not-a-token-id'"];
        yield 'unknown' => ['0198f000-0000-7000-8000-0000000000ff'];
    }

    #[Test]
    #[DataProvider('idsOfNoToken')]
    public function anIdOfNoTokenIsNotFoundAndRevokesNothing(string $tokenId): void
    {
        [$sara, $acmeFoods, $omar] = $this->acmeFoodsWithSaraOmarAndLena();
        [$omarsToken] = $this->issueApiToken($omar, $acmeFoods);
        $this->logInWithACode($this->client, $this->clock, $sara);

        $this->client->request('POST', \sprintf(self::REVOKE, rawurlencode($tokenId)), ['_csrf_token' => $this->csrfTokenFor($this->client, self::REVOKE_CSRF_ID)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($omarsToken, $acmeFoods));
    }

    #[Test]
    public function aRevokeWithAWrongCsrfTokenIsRefusedAndTheTokenStaysLive(): void
    {
        [$sara, $acmeFoods, $omar] = $this->acmeFoodsWithSaraOmarAndLena();
        [$omarsToken] = $this->issueApiToken($omar, $acmeFoods);
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('POST', \sprintf(self::REVOKE, $omarsToken), [
            '_csrf_token' => 'not-the-token',
            PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-01-01T00:00:00+00:00',
            PeopleController::TOKENS_AFTER_ID => '0198f000-0000-7000-8000-0000000002ff',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(PeopleController::FORM_EXPIRED, $page->filter('main [role="alert"]')->text());
        self::assertSame([$omarsToken], $this->tokenIdsOn($page));
        self::assertSame(
            [
                PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-01-01T00:00:00+00:00',
                PeopleController::TOKENS_AFTER_ID => '0198f000-0000-7000-8000-0000000002ff',
            ],
            array_diff_key($page->filter(\sprintf('main li[data-token-id="%s"] form', $omarsToken))->form()->getValues(), ['_csrf_token' => true]),
            'the page shown again keeps the page of tokens the form named',
        );
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($omarsToken, $acmeFoods));
    }

    /**
     * @return array{User, OrganizationId, User, User} Sara, the Admin, and Omar and Lena, members, of Acme Foods
     */
    private function acmeFoodsWithSaraOmarAndLena(): array
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $this->addMembership($omar, $acmeFoods);
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $acmeFoods);

        return [$sara, $acmeFoods, $omar, $lena];
    }

    /**
     * @return list<string>
     */
    private function tokenIdsOn(Crawler $page): array
    {
        return $page->filter('main .people__token-row')->each(static fn (Crawler $row): string => (string) $row->attr('data-token-id'));
    }

    /**
     * The href of the one Show more in the section with $sectionClass.
     */
    private function showMoreHrefOf(Crawler $page, string $sectionClass): ?string
    {
        $showMore = $page->filter(\sprintf('main .%s a.btn', $sectionClass))->reduce(static fn (Crawler $link): bool => 'Show more' === $link->text());
        self::assertCount(1, $showMore);

        return $showMore->attr('href');
    }

    /**
     * The token as the page shows it: "pr_" and the first characters of the random part.
     */
    private static function displayPrefixOf(string $plainToken): string
    {
        return substr($plainToken, 0, \strlen(ApiToken::PLAIN_PREFIX) + ApiToken::DISPLAY_PREFIX_LENGTH);
    }

    /**
     * An invitation not accepted, made at $createdAt and open for 72 hours from then.
     */
    private function storeOpenInvitation(OrganizationId $organizationId, string $email, string $createdAt, string $id): void
    {
        $madeAt = new \DateTimeImmutable($createdAt);
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO org_invitation (organization_id, id, email, org_roles, invited_by, created_at, expires_at)'
            ." VALUES (?, ?, ?, '{}', ?, ?, ?)",
            [$organizationId->toString(), $id, $email, Uuid::v7()->toRfc4122(), $madeAt->format(\DATE_ATOM), $madeAt->modify('+72 hours')->format(\DATE_ATOM)],
        ));
    }

    /**
     * Drops the events of the logins, so the outbox holds only what the requests after it publish.
     */
    private function forgetEarlierEvents(): void
    {
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    /**
     * @return list<list<mixed>> actor kind, actor, action, target type, target, time
     */
    private function auditRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT actor_kind, actor_user_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM aud_event WHERE organization_id = ? ORDER BY at, id",
                [$organizationId->toString()],
            );

            return 0;
        });

        return $rows;
    }
}
