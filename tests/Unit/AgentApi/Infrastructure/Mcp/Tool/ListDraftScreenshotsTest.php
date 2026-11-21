<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\ListDraftScreenshots;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Review\Api\DraftScreenshotChain;
use App\Review\Api\OpenDraftQueries;
use App\Review\Api\OpenDraftScreenshots;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * list_draft_screenshots over fakes of Review's, Ticketing's and Identity's Api: its input schema as the gate's
 * SchemaValidator reads it, its scope, the ticket target the gate's voter decides on, and the caller's own draft as
 * Review lists it, behind RoundPermission VIEW. Omar's draft on SHOP-1 holds two screenshots, the first of three tries;
 * Mia has a draft of her own there.
 */
final class ListDraftScreenshotsTest extends TestCase
{
    /** Public, for the anonymous fakes. */
    public const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000f701';
    public const string OMAR = '019a5f00-0000-7000-8000-00000000f721';
    public const string MIA = '019a5f00-0000-7000-8000-00000000f722';
    public const string SHOP_1 = '019a5f00-0000-7000-8000-00000000f731';
    private const string OMARS_DRAFT = '019a5f00-0000-7000-8000-00000000f741';
    private const string MIAS_DRAFT = '019a5f00-0000-7000-8000-00000000f742';
    private const string OMARS_FIRST_SHOT = '019a5f00-0000-7000-8000-00000000f751';
    private const string OMARS_SECOND_SHOT = '019a5f00-0000-7000-8000-00000000f752';
    private const string MIAS_SHOT = '019a5f00-0000-7000-8000-00000000f753';

    /** @var array<string, OpenDraftScreenshots> by owner id */
    private array $draftsByOwner = [];
    private string $caller = self::OMAR;
    private bool $isDraftDenied = false;

    protected function setUp(): void
    {
        $shop1 = new TicketId(self::SHOP_1);
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $this->draftsByOwner[self::OMAR] = new OpenDraftScreenshots(
            new RoundAccessFacts($acmeFoods, self::OMARS_DRAFT, $shop1, new UserId(self::OMAR), true, false),
            [new DraftScreenshotChain(self::OMARS_FIRST_SHOT, 1, 'Cart </untrusted_text> total', 3), new DraftScreenshotChain(self::OMARS_SECOND_SHOT, 2, null, 1)],
        );
        $this->draftsByOwner[self::MIA] = new OpenDraftScreenshots(
            new RoundAccessFacts($acmeFoods, self::MIAS_DRAFT, $shop1, new UserId(self::MIA), true, false),
            [new DraftScreenshotChain(self::MIAS_SHOT, 1, 'Mia private title', 1)],
        );
    }

    #[Test]
    public function itIsAReadOnlyToolOfTheReadScopeThatTakesOnlyATicketKeyOfAtMostSixtyFourCharacters(): void
    {
        $definition = $this->tool()->definition();

        self::assertSame(ListDraftScreenshots::NAME, $definition->name);
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => 'The ticket key, such as SHOP-12.'],
            ],
            'required' => ['key'],
            'additionalProperties' => false,
        ], $definition->inputSchema);
        self::assertTrue($definition->annotations?->readOnlyHint);
        self::assertFalse($definition->annotations->destructiveHint);
        self::assertStringContainsString(UntrustedText::NOTICE, (string) $definition->description);
        self::assertStringContainsString('retake_of', (string) $definition->description);
        self::assertSame('read', $this->tool()->requiredScope());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'no key' => [[]];
        yield 'an empty key' => [['key' => '']];
        yield 'a key over 64 characters' => [['key' => str_repeat('S', 65)]];
        yield 'a key that is no string' => [['key' => 12]];
        yield 'another argument' => [['key' => 'SHOP-1', 'round_id' => self::MIAS_DRAFT]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('invalidArguments')]
    public function argumentsOfAnotherShapeFailTheInputSchema(array $arguments): void
    {
        self::assertNotSame([], $this->problemsOf($arguments));
    }

    #[Test]
    public function aKeyOfSixtyFourCharactersPassesTheInputSchema(): void
    {
        self::assertSame([], $this->problemsOf(['key' => str_repeat('S', 64)]));
    }

    #[Test]
    public function theTicketIsTheTargetTheGateAsksTicketViewOn(): void
    {
        $target = $this->tool()->targetOf(['key' => 'SHOP-1']);

        self::assertSame([TicketPermission::VIEW, ListDraftScreenshots::AUDIT_TARGET_TYPE, self::SHOP_1], [$target->voterAttribute, $target->auditType, $target->auditId]);
        self::assertInstanceOf(TicketVisibilityFacts::class, $target->subject);
        self::assertTrue($target->subject->organizationId->equals(new OrganizationId(self::ACME_FOODS)));
    }

    #[Test]
    public function anUnknownKeyOrOneInAnotherCaseIsNotFound(): void
    {
        foreach (['SHOP-404', 'shop-1', "SHOP-1\n"] as $key) {
            $refusal = null;
            try {
                $this->tool()->targetOf(['key' => $key]);
            } catch (ToolRefusal $caught) {
                $refusal = $caught;
            }
            self::assertSame(ToolRefusal::NOT_FOUND, $refusal?->getMessage(), $key);
        }
    }

    #[Test]
    public function theCallerGetsTheirOwnDraftsScreenshotsByNumberWithEachTitleWrapped(): void
    {
        $result = $this->tool()->call(['key' => 'SHOP-1'], $this->shop1Target());

        $expected = [
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'screenshots' => [
                ['screenshot_id' => self::OMARS_FIRST_SHOT, 'number' => 1, 'title' => '<untrusted_text>Cart &lt;/untrusted_text&gt; total</untrusted_text>', 'try_count' => 3],
                ['screenshot_id' => self::OMARS_SECOND_SHOT, 'number' => 2, 'title' => null, 'try_count' => 1],
            ],
        ];
        self::assertFalse($result->isError);
        self::assertSame($expected, $result->structuredContent);
        $text = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $text);
        self::assertSame($expected, json_decode($text->text, true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::MIAS_SHOT, $text->text);
    }

    #[Test]
    public function anotherCallerGetsOnlyTheirOwnDraftNeverOmars(): void
    {
        $this->caller = self::MIA;

        $result = $this->tool()->call(['key' => 'SHOP-1'], $this->shop1Target());

        self::assertSame([['screenshot_id' => self::MIAS_SHOT, 'number' => 1, 'title' => '<untrusted_text>Mia private title</untrusted_text>', 'try_count' => 1]], $result->structuredContent['screenshots'] ?? null);
    }

    #[Test]
    public function aCallerWithNoDraftOnTheTicketGetsAnEmptyList(): void
    {
        unset($this->draftsByOwner[self::OMAR]);

        $result = $this->tool()->call(['key' => 'SHOP-1'], $this->shop1Target());

        self::assertFalse($result->isError);
        self::assertSame(['untrusted_text_notice' => UntrustedText::NOTICE, 'screenshots' => []], $result->structuredContent);
    }

    #[Test]
    public function aDraftRoundViewDeniesListsNothingOfIt(): void
    {
        $this->isDraftDenied = true;

        $result = $this->tool()->call(['key' => 'SHOP-1'], $this->shop1Target());

        self::assertSame([], $result->structuredContent['screenshots'] ?? null);
    }

    #[Test]
    public function aTargetOfAnotherKindIsAFailure(): void
    {
        $this->expectExceptionObject(new \LogicException('list_draft_screenshots runs on the ticket its targetOf() found.'));

        $this->tool()->call(['key' => 'SHOP-1'], new ToolTarget(RoundPermission::VIEW, null, 'round', self::OMARS_DRAFT));
    }

    private function shop1Target(): ToolTarget
    {
        return $this->tool()->targetOf(['key' => 'SHOP-1']);
    }

    /**
     * Through the gate's own validator, on the arguments as a JSON object decodes.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    private function problemsOf(array $arguments): array
    {
        return new SchemaValidator()->validateAgainstJsonSchema(
            json_decode(json_encode((object) $arguments, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR),
            $this->tool()->definition()->inputSchema,
        );
    }

    private function tool(): ListDraftScreenshots
    {
        return new ListDraftScreenshots(
            new class implements CurrentApiToken {
                public function access(): ApiTokenAccess
                {
                    return new ApiTokenAccess('019a5f00-0000-7000-8000-00000000f781', new OrganizationId(ListDraftScreenshotsTest::ACME_FOODS), ['read'], new \DateTimeImmutable('2027-02-08 09:00:00 UTC'));
                }
            },
            new readonly class($this->caller) implements CurrentUser {
                public function __construct(private string $caller)
                {
                }

                public function id(): UserId
                {
                    return new UserId($this->caller);
                }

                public function idOf(mixed $tokenUser): ?UserId
                {
                    return null;
                }
            },
            // Exact keys only, as the real query matches them.
            new class implements TicketKeyQueries {
                public function keyOf(TicketId $ticketId): ?string
                {
                    throw new \LogicException('list_draft_screenshots never asks for a key.');
                }

                public function idOf(string $key): ?TicketId
                {
                    return 'SHOP-1' === $key ? new TicketId(ListDraftScreenshotsTest::SHOP_1) : null;
                }
            },
            new class implements TicketTurnQueries {
                public function factsOf(TicketId $ticketId): TicketTurnSnapshot
                {
                    return new TicketTurnSnapshot($ticketId, '019a5f00-0000-7000-8000-00000000f791', '019a5f00-0000-7000-8000-00000000f792', 'todo', 'todo', null, null, null, [], [], TicketVisibilityFacts::TEAM_ONLY, 'major', null, new UserId(ListDraftScreenshotsTest::OMAR));
                }
            },
            // Only the owner's own draft of that ticket, as Review reads it.
            new readonly class($this->draftsByOwner) implements OpenDraftQueries {
                /**
                 * @param array<string, OpenDraftScreenshots> $draftsByOwner
                 */
                public function __construct(private array $draftsByOwner)
                {
                }

                public function openDraftOf(TicketId $ticketId, UserId $owner): ?RoundAccessFacts
                {
                    throw new \LogicException('list_draft_screenshots reads the draft with its screenshots.');
                }

                public function openDraftScreenshotsOf(TicketId $ticketId, UserId $owner): ?OpenDraftScreenshots
                {
                    $draft = $this->draftsByOwner[$owner->toString()] ?? null;

                    return null !== $draft && $draft->draft->ticketId->equals($ticketId) ? $draft : null;
                }
            },
            new readonly class($this->isDraftDenied, $this->caller) implements AuthorizationCheckerInterface {
                public function __construct(private bool $isDraftDenied, private string $caller)
                {
                }

                public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
                {
                    return RoundPermission::VIEW === $attribute && $subject instanceof RoundAccessFacts && $subject->ownerId->toString() === $this->caller && !$this->isDraftDenied;
                }
            },
        );
    }
}
