<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\IssueApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Infrastructure\Doctrine\DoctrineApiTokens;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The token services as the container builds them, with API_TOKEN_PEPPER from .env.test: a wrong env name or a
 * missing alias would pass every test that builds them by hand.
 */
final class ApiTokenWiringTest extends KernelTestCase
{
    private const string ENV_TEST_PEPPER = 'change-me-test-only-api-token-pepper-32';
    private const string PLAIN_TOKEN = 'pr_wired-but-not-a-real-token';

    #[Test]
    public function theWiredHashingIsTheHmacOfTheTokenKeyedByThePepperOfEnvTest(): void
    {
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);

        self::assertSame(hash_hmac('sha256', self::PLAIN_TOKEN, self::ENV_TEST_PEPPER, true), $hashing->hash(self::PLAIN_TOKEN));
    }

    #[Test]
    public function theTokenRepositoryIsTheDoctrineOneAndTheIssueCommandIsBuilt(): void
    {
        $container = self::getContainer();

        self::assertInstanceOf(DoctrineApiTokens::class, $container->get('test.identity_api_tokens'));
        self::assertInstanceOf(IssueApiToken::class, $container->get('test.identity_issue_api_token'));
    }
}
