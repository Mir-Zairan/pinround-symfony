<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist\Laptop;

use App\Assist\UI\Controller\LaptopController;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The limiter ai_laptop_per_user on POST /ai/laptop/package and /ai/laptop/result together, on the state Valkey of the
 * tests (SeedsLaptopAiTickets). Each test has an organization no other run has counted, and the clock is frozen 20
 * seconds into a minute, so a refused request waits 40 seconds. Requests with a malformed id spend the limit without
 * storing or auditing anything, so a refusal of the limiter is the only thing that could stop a later request.
 */
final class LaptopRoutesRequestLimitTest extends WebTestCase
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

    private const int LIMIT = 30;
    private const string MALFORMED_ID = 'not-a-uuid';

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
    public function thirtyRequestsOfBothRoutesPassAndTheThirtyFirstIsRefusedWithAWaitStoringAndAuditingNothing(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->laptopToken();
        $this->spendRequests($token, self::LIMIT);

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $token);
        $this->assertTooManyRequests();
        $this->postResult(self::resultBody(Uuid::v7()->toRfc4122()), $token);
        $this->assertTooManyRequests();

        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
    }

    #[Test]
    public function omarMayAskAgainWhenTheNextMinuteOfTheClockBegins(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->laptopToken();
        $this->spendRequests($token, self::LIMIT);
        $this->clock->modify('+39 seconds');
        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $token);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Retry-After', '1');

        $this->clock->modify('+1 second');
        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $token);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->packageRowsOf($this->acmeFoods));
    }

    #[Test]
    public function omarAtTheLimitDoesNotHoldBackLena(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $this->spendRequests($this->laptopToken(), self::LIMIT);

        $this->logIn($this->client, $this->people['Lena']);
        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $this->laptopToken());

        self::assertResponseStatusCodeSame(200);
    }

    /** After the token check, so a page of another site cannot spend a member's requests by posting in their session. */
    #[Test]
    public function requestsWithAWrongFormTokenSpendNoneOfOmarsRequests(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->laptopToken();
        for ($request = 1; $request <= self::LIMIT + 1; ++$request) {
            $this->postPackageRequest(self::packageRequest($this->shopTicketId), 'not-the-token');
            self::assertResponseStatusCodeSame(422);
        }

        $this->postPackageRequest(self::packageRequest($this->shopTicketId), $token);

        self::assertResponseStatusCodeSame(200);
    }

    /** Fails closed: a counter that cannot be read or written never lets a request through unlimited. */
    #[Test]
    public function bothRoutesRefuseWhenTheCounterStorageFailsAndStoreOrAuditNothing(): void
    {
        self::getContainer()->set(ValkeyLimiterStorage::class, new class implements StorageInterface {
            public function save(LimiterStateInterface $limiterState): void
            {
                throw new \RedisException('Connection refused');
            }

            public function fetch(string $limiterStateId): ?LimiterStateInterface
            {
                throw new \RedisException('Connection refused');
            }

            public function delete(string $limiterStateId): void
            {
                throw new \RedisException('Connection refused');
            }
        });
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->laptopToken();
        $this->client->catchExceptions(false);

        foreach ([[self::PACKAGE_PATH, self::packageRequest($this->shopTicketId)], [self::RESULT_PATH, self::resultBody(Uuid::v7()->toRfc4122())]] as [$path, $body]) {
            try {
                $this->postJson($path, $body, $token);
                self::fail(\sprintf('%s answered %d.', $path, $this->client->getResponse()->getStatusCode()));
            } catch (\RedisException $failure) {
                self::assertSame('Connection refused', $failure->getMessage());
            }
        }

        self::assertSame([], $this->packageRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * Half on each route, each answered 404 before LocalAi as a malformed id.
     */
    private function spendRequests(string $token, int $requests): void
    {
        for ($request = 1; $request <= $requests; ++$request) {
            if (0 === $request % 2) {
                $this->postPackageRequest(self::packageRequest(self::MALFORMED_ID), $token);
            } else {
                $this->postResult(self::resultBody(self::MALFORMED_ID), $token);
            }
            self::assertResponseStatusCodeSame(404);
        }
    }

    private function assertTooManyRequests(): void
    {
        self::assertResponseStatusCodeSame(429);
        self::assertSame(['message' => 'You sent too many laptop AI requests. Wait a minute and try again.'], $this->answer());
        self::assertResponseHeaderSame('Retry-After', '40');
    }
}
