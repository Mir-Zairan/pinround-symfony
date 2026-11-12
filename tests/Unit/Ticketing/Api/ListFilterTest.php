<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListFilterTest extends TestCase
{
    private const string PROJECT = '0197a3c0-0000-7000-8000-00000000c011';
    private const string ASSIGNEE = '0197a3c0-0000-7000-8000-0000000000a1';

    #[Test]
    public function byDefaultItShowsEveryProjectWithoutDone(): void
    {
        $filter = new ListFilter(self::visibility());

        self::assertSame([null, false, null], [$filter->projectId, $filter->showsDone, $filter->assigneeId]);
    }

    #[Test]
    public function itKeepsOneProjectAndDone(): void
    {
        $filter = new ListFilter(self::visibility(), self::PROJECT, true);

        self::assertSame([self::PROJECT, true], [$filter->projectId, $filter->showsDone]);
    }

    #[Test]
    public function aProjectIdThatIsNoUuidIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListFilter(self::visibility(), 'SHOP');
    }

    #[Test]
    public function itKeepsOneAssignee(): void
    {
        $filter = new ListFilter(self::visibility(), assigneeId: self::ASSIGNEE);

        self::assertSame(self::ASSIGNEE, $filter->assigneeId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function assigneeIdsThatAreNoCanonicalUuid(): iterable
    {
        yield 'a word' => ['Omar'];
        yield 'uppercase' => [strtoupper(self::ASSIGNEE)];
        yield 'with a newline after' => [self::ASSIGNEE."\n"];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('assigneeIdsThatAreNoCanonicalUuid')]
    public function anAssigneeIdThatIsNoCanonicalUuidIsRefused(string $assigneeId): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A list filter\'s assignee id must be a canonical lowercase uuid.'));
        new ListFilter(self::visibility(), assigneeId: $assigneeId);
    }

    #[Test]
    public function itKeepsASearchOfUpTo200CharactersMultibyteIncluded(): void
    {
        $longestSearch = str_repeat('é', ListFilter::SEARCH_TEXT_MAX_LENGTH);

        self::assertSame([null, 'shop-41', $longestSearch], [
            new ListFilter(self::visibility())->searchText,
            new ListFilter(self::visibility(), searchText: 'shop-41')->searchText,
            new ListFilter(self::visibility(), searchText: $longestSearch)->searchText,
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function searchTextsTheListPageNeverSends(): iterable
    {
        yield 'empty' => [''];
        yield 'untrimmed' => [' checkout'];
        yield 'a trailing newline' => ["checkout\n"];
        yield 'one character too long' => [str_repeat('é', ListFilter::SEARCH_TEXT_MAX_LENGTH + 1)];
        yield 'invalid UTF-8' => ["check\xC3out"];
        yield 'a NUL inside' => ["check\0out"];
    }

    #[Test]
    #[DataProvider('searchTextsTheListPageNeverSends')]
    public function aSearchTextTheListPageNeverSendsIsRefused(string $searchText): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A list filter\'s search text must be trimmed, valid UTF-8 without NUL, and 1 to 200 characters.'));
        new ListFilter(self::visibility(), searchText: $searchText);
    }

    private static function visibility(): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId('0197a3c0-0000-7000-8000-00000000c0a1'), new UserId('0197a3c0-0000-7000-8000-00000000c0b1'), [], false, null);
    }
}
