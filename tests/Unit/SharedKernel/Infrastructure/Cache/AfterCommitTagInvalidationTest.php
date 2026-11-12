<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Cache;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Cache\AfterCommitTagInvalidation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Two pools share one transaction, as cache.workflow and cache.team_people do; each keeps its own waiting tags.
 */
final class AfterCommitTagInvalidationTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002501';

    private TagAwareAdapter $workflowPool;

    private TagAwareAdapter $peoplePool;

    private AfterCommitTagInvalidation $invalidation;

    protected function setUp(): void
    {
        $this->workflowPool = new TagAwareAdapter(new ArrayAdapter());
        $this->peoplePool = new TagAwareAdapter(new ArrayAdapter());
        $this->invalidation = new AfterCommitTagInvalidation(new NullLogger());
        self::fill($this->workflowPool, 'entry', 'wf-tag');
        self::fill($this->peoplePool, 'entry', 'people-tag');
    }

    #[Test]
    public function aWaitingChangeOfOnePoolStopsCachingInThatPoolOnly(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        self::assertNull($this->invalidation->tenantToCacheFor($this->workflowPool), 'No transaction is open.');

        $this->invalidation->transactionBegan($acmeFoods);
        self::assertSame(self::ACME_FOODS, $this->invalidation->tenantToCacheFor($this->workflowPool)?->toString());

        $this->invalidation->invalidateAfterCommit($this->workflowPool, ['wf-tag']);

        self::assertNull($this->invalidation->tenantToCacheFor($this->workflowPool));
        self::assertSame(self::ACME_FOODS, $this->invalidation->tenantToCacheFor($this->peoplePool)?->toString());
    }

    #[Test]
    public function theCommitInvalidatesTheWaitingTagsOfEveryPoolAndNoOther(): void
    {
        self::fill($this->peoplePool, 'other entry', 'other-people-tag');
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->invalidation->invalidateAfterCommit($this->workflowPool, ['wf-tag']);
        $this->invalidation->invalidateAfterCommit($this->peoplePool, ['people-tag']);
        self::assertSame([true, true, true], $this->hasEntries());

        $this->invalidation->transactionCommitted();

        self::assertSame([false, false, true], $this->hasEntries());
        self::assertNull($this->invalidation->tenantToCacheFor($this->peoplePool), 'The transaction is over.');
    }

    #[Test]
    public function aRollbackAClosedConnectionAndAResetInvalidateNothing(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->invalidation->invalidateAfterCommit($this->workflowPool, ['wf-tag']);
        $this->invalidation->transactionEnded();
        $this->invalidation->transactionCommitted();

        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->invalidation->invalidateAfterCommit($this->peoplePool, ['people-tag']);
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->invalidation->transactionCommitted();

        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->invalidation->invalidateAfterCommit($this->workflowPool, ['wf-tag']);
        $this->invalidation->reset();
        self::assertNull($this->invalidation->tenantToCacheFor($this->peoplePool), 'The reset leaves no tenant behind.');
        $this->invalidation->transactionCommitted();

        self::assertSame([true, true, false], $this->hasEntries());
    }

    #[Test]
    public function withNoTransactionOpenAChangeIsInvalidatedAtOnce(): void
    {
        $this->invalidation->invalidateAfterCommit($this->peoplePool, ['people-tag']);

        self::assertSame([true, false, false], $this->hasEntries());
    }

    #[Test]
    public function aPoolThatFailsAtCommitIsLoggedAndTheOtherPoolsTagsAreStillInvalidated(): void
    {
        $messages = [];
        $logger = new class(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        }) extends AbstractLogger {
            /** @param \Closure(string): void $onLog */
            public function __construct(private readonly \Closure $onLog)
            {
            }

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                ($this->onLog)((string) $message);
            }
        };
        $invalidation = new AfterCommitTagInvalidation($logger);
        $invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $invalidation->invalidateAfterCommit(self::failingPool(), ['wf-tag']);
        $invalidation->invalidateAfterCommit($this->peoplePool, ['people-tag']);

        $invalidation->transactionCommitted();

        self::assertSame(['A cache kept entries of a committed change; its event handler drops them.'], $messages);
        self::assertSame([true, false, false], $this->hasEntries());
    }

    /**
     * @return array{bool, bool, bool} the workflow entry, the people entry, the other people entry
     */
    private function hasEntries(): array
    {
        return [$this->workflowPool->hasItem('entry'), $this->peoplePool->hasItem('entry'), $this->peoplePool->hasItem('other entry')];
    }

    private static function fill(TagAwareAdapter $pool, string $key, string $tag): void
    {
        $pool->get($key, static function (ItemInterface $item) use ($tag): string {
            $item->tag($tag);

            return 'cached';
        });
    }

    private static function failingPool(): TagAwareCacheInterface
    {
        return new class implements TagAwareCacheInterface {
            /** @param array<mixed>|null $metadata */
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                throw new \LogicException('Not read in this test.');
            }

            public function delete(string $key): bool
            {
                return false;
            }

            public function invalidateTags(array $tags): bool
            {
                return false;
            }
        };
    }
}
