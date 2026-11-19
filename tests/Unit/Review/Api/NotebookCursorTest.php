<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Api;

use App\Review\Api\NotebookCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotebookCursorTest extends TestCase
{
    private const string NOTEBOOK = '0197a3c0-0000-7000-8000-0000000f9a01';
    private const string LAST_USED_AT = '2027-03-15 11:20:30.123456+01:00';

    #[Test]
    public function itKeepsALowercaseUuidV7AndTheLastUseToTheMicrosecond(): void
    {
        $cursor = new NotebookCursor(new \DateTimeImmutable(self::LAST_USED_AT), self::NOTEBOOK);

        self::assertSame([self::NOTEBOOK, '2027-03-15T11:20:30.123456+01:00'], [$cursor->notebookId, $cursor->lastUsedAt->format('Y-m-d\TH:i:s.uP')]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsOfNoCursor(): iterable
    {
        yield 'in capitals' => [strtoupper(self::NOTEBOOK)];
        yield 'a version 4 uuid' => ['0197a3c0-0000-4000-8000-0000000f9a01'];
        yield 'the id with a newline after it' => [self::NOTEBOOK."\n"];
    }

    #[Test]
    #[DataProvider('idsOfNoCursor')]
    public function anIdThatIsNotACanonicalLowercaseUuidV7IsRefused(string $notebookId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A notebook cursor\'s notebook id must be a canonical lowercase UUIDv7.');

        new NotebookCursor(new \DateTimeImmutable(self::LAST_USED_AT), $notebookId);
    }
}
