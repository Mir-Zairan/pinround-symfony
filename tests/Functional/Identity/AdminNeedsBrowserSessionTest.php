<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Tests\Functional\Organization\LogsInAdmins;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * An admin's personal access token never opens admin work (ADR-0012, ADR-0021, P09.T1): on /mcp every ADMIN_*
 * attribute, ORG_ADMIN and ORG_INVITE are denied with AdminNeedsBrowserSessionVoter's reason, and the admin pages,
 * behind the main firewall, never read the token at all. The same admin with a browser session and a fresh code opens them. The client keeps its
 * kernel; the clock is fixed before the wall clock, by which the session store dates a browser session.
 */
final class AdminNeedsBrowserSessionTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use TurnsOnTwoStepLogin;

    private const string BROWSER_SESSION_NEEDED = 'Admin work needs a browser session, not a personal access token.';

    /** Each admin page and admin action, as method and path. */
    private const array ADMIN_ROUTES = [
        ['GET', '/admin/people'],
        ['POST', '/admin/people/invite'],
        ['POST', '/admin/people/tokens/0197a3c0-0000-7000-8000-00000000a7e1/revoke'],
        ['GET', '/admin/ai'],
        ['POST', '/admin/ai'],
        ['POST', '/admin/ai/provider-key'],
        ['GET', '/_test/admin-area'],
    ];

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock('2026-11-10 09:00:00 UTC');
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function anAdminsTokenIsDeniedEveryAdminRouteThatHerBrowserSessionOpens(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        [, $sarasToken] = $this->issueApiToken($sara, $acmeFoods);
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$sarasToken];

        $this->client->request('GET', '/mcp/_test/admin-decisions', server: $bearer);

        self::assertResponseStatusCodeSame(200);
        $denied = ['isGranted' => false, 'message' => 'Access Denied. '.self::BROWSER_SESSION_NEEDED];
        self::assertSame([
            AdminPermission::AREA => $denied,
            AdminPermission::AI => $denied,
            OrganizationPermission::ADMIN => $denied,
            OrganizationPermission::INVITE => $denied,
        ], $this->responseJson());

        $this->client->request('GET', '/mcp/_test/admin-area', server: $bearer);
        self::assertResponseStatusCodeSame(403);

        foreach (self::ADMIN_ROUTES as [$method, $path]) {
            $this->client->request($method, $path, server: $bearer);

            self::assertResponseRedirects('https://localhost/login', 302, $method.' '.$path);
        }

        $this->logInWithACode($this->client, $this->clock, $sara);
        foreach (self::ADMIN_ROUTES as [$method, $path]) {
            if ('GET' === $method) {
                $this->client->request($method, $path);

                self::assertResponseStatusCodeSame(200, $method.' '.$path);
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private function responseJson(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }
}
