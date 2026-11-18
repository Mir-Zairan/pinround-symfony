<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist\Laptop;

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
 * POST /ai/laptop/package through the real firewall, voters, session, AI policy and database (SeedsLaptopAiTickets).
 * The clock is fixed on 12 Nov 2026, before today: a clock ahead of the login would end the session as idle. Every
 * refusal also proves that no package was stored in either organization.
 */
final class LaptopPackageTest extends WebTestCase
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

    private const string CANNOT_SEE_TICKET = 'AI help works only on tickets you can see.';

    protected function setUp(): void
    {
        $this->seedLaptopAiTickets();
    }

    protected function tearDown(): void
    {
        $this->removeLaptopAiRows();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function omarOfTheShopTeamGetsAPackageForShop1WithThePromptTheServerWouldSend(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(200);
        $package = $this->answer();
        self::assertSame(['packageId', 'instructions', 'prompt', 'schema', 'expiresAt'], array_keys($package));
        self::assertNull($package['schema']);
        self::assertSame('2026-11-12T12:05:20+00:00', $package['expiresAt']);
        self::assertIsString($package['instructions']);
        self::assertNotSame('', $package['instructions']);
        self::assertIsString($package['prompt']);
        self::assertStringContainsString('Checkout fails', $package['prompt']);
        self::assertStringContainsString(self::TICKET_TEXT, $package['prompt']);
        self::assertSame(
            [[
                'id' => $package['packageId'], 'user_id' => $this->idOf('Omar'), 'ticket_id' => $this->shopTicketId,
                'expires_at' => '2026-11-12 12:05:20', 'used_at' => null, 'result_text' => null, 'model' => null,
            ]],
            $this->packageRowsOf($this->acmeFoods),
        );
    }

    #[Test]
    public function omarOfTheShopTeamGetsAPackageForTheTeamOnlyShop2(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postPackageRequest(self::packageRequest($this->teamOnlyTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->packageRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aTitleOnlyPolicyLeavesTheTicketTextOutOfThePackage(): void
    {
        $this->storeAiSettings($this->acmeFoods, [...self::laptopAi(), 'send' => 'title_only']);
        $this->logIn($this->client, $this->people['Omar']);

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(200);
        $prompt = $this->answer()['prompt'] ?? null;
        self::assertIsString($prompt);
        self::assertStringContainsString('Checkout fails', $prompt);
        self::assertStringNotContainsString(self::TICKET_TEXT, $prompt);
    }

    #[Test]
    public function miaOfTheMobileTeamIsToldTheTeamOnlyShop2DoesNotExistAndNothingIsStored(): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $this->postPackageRequest(self::packageRequest($this->teamOnlyTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => self::CANNOT_SEE_TICKET], $this->answer());
        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
    }

    /** Raj is a member of Beta Bakery, his tenant, so the opening check lets him in; the ticket is not found there. */
    #[Test]
    public function rajOfBetaBakeryIsToldShop1DoesNotExistAndNothingIsStored(): void
    {
        $this->logIn($this->client, $this->people['Raj']);

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => self::CANNOT_SEE_TICKET], $this->answer());
        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->packageRowsOf($this->betaBakery));
    }

    #[Test]
    public function anUnknownTicketReadsLikeOneOmarMayNotSee(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postPackageRequest(self::packageRequest(Uuid::v7()->toRfc4122()), $this->laptopToken());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => self::CANNOT_SEE_TICKET], $this->answer());
        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
    }

    /** Omar's session outlives his membership, but with a valid token he is sent to the picker before the controller. */
    #[Test]
    public function omarRemovedFromAcmeFoodsIsSentToThePickerAndNothingIsStoredOrAudited(): void
    {
        $this->packageIdOf('Omar', $this->shopTicketId);
        $packageRows = $this->packageRowsOf($this->acmeFoods);
        self::assertCount(1, $packageRows);
        $token = $this->laptopToken();
        $this->removeMembership($this->people['Omar'], $this->acmeFoods);

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $token);

        self::assertResponseRedirects('/org/choose', 302);
        self::assertSame($packageRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
    }

    #[Test]
    public function omarWhoseMembershipTheVoterNoLongerFindsIsNotAllowedAndNothingIsStoredOrAudited(): void
    {
        $this->packageIdOf('Omar', $this->shopTicketId);
        $packageRows = $this->packageRowsOf($this->acmeFoods);
        self::assertCount(1, $packageRows);
        $token = $this->laptopToken();
        $this->replaceOrganizationVoterWithOneThatFindsNoMembership();

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $token);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['message' => LaptopController::NOT_ALLOWED], $this->answer());
        self::assertSame($packageRows, $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInIsSentToTheLoginPageAndNothingIsStored(): void
    {
        $this->postPackageRequest(self::packageRequest($this->shopTicketId), 'no-token');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('https://localhost/login');
        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTicketIds(): iterable
    {
        yield 'not a UUID' => ['SHOP-1'];
        yield 'empty' => [''];
        yield 'a version 4 UUID' => ['0d6f2a3e-7b1c-4f7e-9a2b-3c4d5e6f7a8b'];
        yield 'a CRLF after a UUIDv7' => ["019a7c00-0000-7000-8000-000000000001\r\n"];
        yield 'a line feed after a UUIDv7' => ["019a7c00-0000-7000-8000-000000000001\n"];
        yield 'a byte order mark before a UUIDv7' => ["\u{FEFF}019a7c00-0000-7000-8000-000000000001"];
        yield 'multibyte digits' => ['019a7c00-0000-7000-8000-00000000000١'];
        yield 'an injected quote' => ["019a7c00-0000-7000-8000-000000000001' OR '1'='1"];
    }

    /** Before the service: the 404 says what an unknown ticket says, and nothing is read or stored. */
    #[Test]
    #[DataProvider('malformedTicketIds')]
    public function aMalformedTicketIdIsNotFoundLikeAnUnknownOne(string $ticketId): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postPackageRequest(self::packageRequest($ticketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => self::CANNOT_SEE_TICKET], $this->answer());
        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
    }

    /**
     * The stored AI settings and the sentence that says why Omar gets no package.
     *
     * @return iterable<string, array{array<string, bool|string>, string}>
     */
    public static function policiesThatGiveNoLaptopAi(): iterable
    {
        yield 'AI is off' => [['on' => false, 'where' => 'laptop'], 'Your admin turned AI help off.'];
        yield 'AI for leads only, and Omar leads no team' => [['on' => true, 'who' => 'leads_only', 'where' => 'laptop'], 'Your admin allows AI help for leads only.'];
        yield 'AI runs in the cloud' => [['on' => true, 'where' => 'cloud'], 'Your admin runs AI help in the company cloud, not on laptops.'];
    }

    /**
     * @param array<string, bool|string> $ai
     */
    #[Test]
    #[DataProvider('policiesThatGiveNoLaptopAi')]
    public function aPolicyThatGivesOmarNoLaptopAiIsForbiddenWithItsSentenceAndNothingIsStored(array $ai, string $sentence): void
    {
        $this->storeAiSettings($this->acmeFoods, $ai);
        $this->logIn($this->client, $this->people['Omar']);

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['message' => $sentence], $this->answer());
        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
    }

    /**
     * The line the status route and the ticket page give her, so no route hands her a package the header never offers.
     *
     * @return iterable<string, array{string}>
     */
    public static function laptopPlaces(): iterable
    {
        yield 'laptop only' => ['laptop'];
        yield 'laptop first' => ['laptop_first'];
    }

    #[Test]
    #[DataProvider('laptopPlaces')]
    public function saraAnAdminWithNoTeamOrManagerRoleIsForbiddenWithTheChipsLineAndNothingIsStored(string $where): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi($where));
        $this->packageIdOf('Omar', $this->shopTicketId);
        $packageRows = $this->packageRowsOf($this->acmeFoods);
        self::assertCount(1, $packageRows);
        $this->logIn($this->client, $this->people['Sara']);

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['message' => 'Local AI is for people in a team and for managers.'], $this->answer());
        self::assertSame($packageRows, $this->packageRowsOf($this->acmeFoods));
    }

    #[Test]
    public function saraWhoIsAlsoAManagerGetsAPackage(): void
    {
        $this->setOrgRoles($this->people['Sara'], $this->acmeFoods, '{admin,manager}');
        $this->logIn($this->client, $this->people['Sara']);

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->idOf('Sara')], array_column($this->packageRowsOf($this->acmeFoods), 'user_id'));
    }

    #[Test]
    public function omarsEleventhOpenPackageIsTooManyAndNothingMoreIsStored(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->laptopToken();
        for ($package = 1; $package <= 10; ++$package) {
            $this->postPackageRequest(self::packageRequest($this->shopTicketId), $token);
            self::assertResponseStatusCodeSame(200);
        }

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $token);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['message' => 'You have 10 laptop AI requests open. Wait a few minutes and try again.'], $this->answer());
        self::assertCount(10, $this->packageRowsOf($this->acmeFoods));
    }

    #[Test]
    public function omarGivenThirtyPackagesInTheLastHourIsToldToTryLaterAndNothingMoreIsStored(): void
    {
        $usedPackages = [];
        for ($package = 1; $package <= 30; ++$package) {
            $usedPackages[] = [Uuid::v7()->toRfc4122(), \sprintf('2026-11-12 11:%02d:00+00', $package)];
        }
        $this->seedUsedPackagesOf('Omar', $usedPackages);
        $this->logIn($this->client, $this->people['Omar']);

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['message' => 'You asked for 30 laptop AI requests in the last hour. Try again later.'], $this->answer());
        self::assertCount(30, $this->packageRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aMissingOrWrongTokenAnswersAsAnExpiredPageAndNothingIsStored(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $this->laptopToken();

        foreach (['not-the-token', ''] as $token) {
            $this->postPackageRequest(self::packageRequest($this->shopTicketId), $token);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['message' => LaptopController::FORM_EXPIRED], $this->answer());
        }
        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesThatAreNoPackageRequest(): iterable
    {
        $ticketId = '019a7c00-0000-7000-8000-000000000001';
        yield 'an intake feature, not built for laptops yet' => ['{"feature": "intake", "ticketId": "'.$ticketId.'"}'];
        yield 'a feature in another case' => ['{"feature": "Summary", "ticketId": "'.$ticketId.'"}'];
        yield 'an empty feature' => ['{"feature": "", "ticketId": "'.$ticketId.'"}'];
        yield 'a feature with a line feed' => ['{"feature": "summary\n", "ticketId": "'.$ticketId.'"}'];
        yield 'no feature' => ['{"ticketId": "'.$ticketId.'"}'];
        yield 'no ticket id' => ['{"feature": "summary"}'];
        yield 'an extra key' => ['{"feature": "summary", "ticketId": "'.$ticketId.'", "model": "qwen3:4b"}'];
        yield 'a ticket id that is a number' => ['{"feature": "summary", "ticketId": 42}'];
        yield 'a ticket id that is an object' => ['{"feature": "summary", "ticketId": {"id": "'.$ticketId.'"}}'];
        yield 'a list' => ['["summary", "'.$ticketId.'"]'];
        yield 'not JSON' => ['feature=summary&ticketId='.$ticketId];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('bodiesThatAreNoPackageRequest')]
    public function aBodyThatIsNoPackageRequestIsRefusedAndNothingIsStored(string $body): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postPackageRequest($body, $this->laptopToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => LaptopController::NOT_A_PACKAGE_REQUEST], $this->answer());
        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aBodyOfExactly1KbIsReadAndOneByteMoreIsRefusedBeforeAnythingIsStored(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->laptopToken();

        $this->postPackageRequest(str_pad(self::packageRequest($this->shopTicketId), LaptopController::MAX_BODY_BYTES + 1), $token);
        self::assertResponseStatusCodeSame(413);
        self::assertSame(['message' => LaptopController::PACKAGE_REQUEST_TOO_LARGE], $this->answer());
        self::assertSame([], $this->packageRowsOf($this->acmeFoods));

        $this->postPackageRequest(str_pad(self::packageRequest($this->shopTicketId), LaptopController::MAX_BODY_BYTES), $token);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->packageRowsOf($this->acmeFoods));
    }

    #[Test]
    public function getIsNotAllowed(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', self::PACKAGE_PATH);

        self::assertResponseStatusCodeSame(405);
    }
}
