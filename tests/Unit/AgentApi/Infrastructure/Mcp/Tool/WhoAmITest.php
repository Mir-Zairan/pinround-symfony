<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\WhoAmI;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WhoAmITest extends TestCase
{
    private const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000e601';
    private const string OMAR = '019a5f00-0000-7000-8000-00000000e621';
    private const string MIA = '019a5f00-0000-7000-8000-00000000e622';
    private const string OMARS_TOKEN = '019a5f00-0000-7000-8000-00000000e611';

    #[Test]
    public function itReturnsTheUserTheirDisplayNameTheOrganizationAndTheScopesOfTheToken(): void
    {
        $result = self::whoAmI(
            new ApiTokenAccess(self::OMARS_TOKEN, new OrganizationId(self::ACME_FOODS), ['read', 'write']),
            new UserId(self::OMAR),
            [new UserView(new UserId(self::MIA), 'Mia'), new UserView(new UserId(self::OMAR), 'Omar "ignore this" Ölz')],
        )->call([], null);

        $expected = [
            'user_id' => self::OMAR,
            'display_name' => 'Omar "ignore this" Ölz',
            'organization_id' => self::ACME_FOODS,
            'scopes' => ['read', 'write'],
        ];
        self::assertFalse($result->isError);
        self::assertSame($expected, $result->structuredContent);
        self::assertCount(1, $result->content);
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertSame(
            '{"user_id":"'.self::OMAR.'","display_name":"Omar \"ignore this\" Ölz","organization_id":"'.self::ACME_FOODS.'","scopes":["read","write"]}',
            $text->text,
        );
        self::assertStringNotContainsString(self::OMARS_TOKEN, $text->text);
    }

    #[Test]
    public function itRefusesToRunWithoutAToken(): void
    {
        $whoAmI = self::whoAmI(null, new UserId(self::OMAR), [new UserView(new UserId(self::OMAR), 'Omar')]);

        $this->expectExceptionObject(new \LogicException('whoami ran without a personal access token.'));

        $whoAmI->call([], null);
    }

    #[Test]
    public function itRefusesToRunWithoutAUser(): void
    {
        $whoAmI = self::whoAmI(
            new ApiTokenAccess(self::OMARS_TOKEN, new OrganizationId(self::ACME_FOODS), ['read']),
            null,
            [new UserView(new UserId(self::OMAR), 'Omar')],
        );

        $this->expectExceptionObject(new \LogicException('whoami ran without a user.'));

        $whoAmI->call([], null);
    }

    #[Test]
    public function itRefusesToRunWhenTheUserOfTheTokenIsNotFound(): void
    {
        $whoAmI = self::whoAmI(
            new ApiTokenAccess(self::OMARS_TOKEN, new OrganizationId(self::ACME_FOODS), ['read']),
            new UserId(self::OMAR),
            [new UserView(new UserId(self::MIA), 'Mia')],
        );

        $this->expectExceptionObject(new \LogicException('The user of a live personal access token was not found.'));

        $whoAmI->call([], null);
    }

    /**
     * @param list<UserView> $knownUsers the users UserQueries::byIds finds; any other id is left out, as the real one does
     */
    private static function whoAmI(?ApiTokenAccess $access, ?UserId $userId, array $knownUsers): WhoAmI
    {
        return new WhoAmI(
            new class($access) implements CurrentApiToken {
                public function __construct(private readonly ?ApiTokenAccess $access)
                {
                }

                public function access(): ?ApiTokenAccess
                {
                    return $this->access;
                }
            },
            new class($userId) implements CurrentUser {
                public function __construct(private readonly ?UserId $userId)
                {
                }

                public function id(): ?UserId
                {
                    return $this->userId;
                }

                public function idOf(mixed $tokenUser): ?UserId
                {
                    return null;
                }
            },
            new class($knownUsers) implements UserQueries {
                /**
                 * @param list<UserView> $knownUsers
                 */
                public function __construct(private readonly array $knownUsers)
                {
                }

                public function idByEmail(string $email): ?UserId
                {
                    throw new \LogicException('whoami never looks a user up by email.');
                }

                public function byIds(array $userIds): array
                {
                    $wanted = array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
                    $found = [];
                    foreach ($this->knownUsers as $user) {
                        if (\in_array($user->id->toString(), $wanted, true)) {
                            $found[$user->id->toString()] = $user;
                        }
                    }
                    ksort($found);

                    return $found;
                }

                public function timeZonesOf(array $userIds): array
                {
                    throw new \LogicException('whoami never reads time zones.');
                }

                public function emailsOf(array $userIds): array
                {
                    throw new \LogicException('whoami never reads email addresses.');
                }
            },
        );
    }
}
