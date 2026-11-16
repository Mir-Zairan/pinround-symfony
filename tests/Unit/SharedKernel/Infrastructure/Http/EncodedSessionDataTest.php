<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Http;

use App\SharedKernel\Infrastructure\Http\EncodedSessionData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncodedSessionDataTest extends TestCase
{
    /** Text that looks like the encoding's own bytes, with CRLF, a BOM and multibyte letters. */
    private const string TRICKY_TEXT = "\u{FEFF}a|b\";}_sf2_flashes|s:1:\"x\";\r\nÜnïcødé 漢字";

    #[Test]
    public function readsEveryAttributeAsItsRawSerializedValue(): void
    {
        $object = new \stdClass();
        $object->note = self::TRICKY_TEXT;
        $attributes = [
            '_security_main' => serialize($object),
            '_csrf/https-logout' => 'token',
            'tricky' => self::TRICKY_TEXT,
            'nested' => ['a' => [1, 2.5, true, null], 'b' => \INF, 'c' => -0.0, 'd' => 1e25],
            'object' => $object,
            7 => 'numeric name',
        ];

        $session = EncodedSessionData::decode(self::encoded($attributes, ['toast' => [self::TRICKY_TEXT]]));

        self::assertNotNull($session);
        self::assertSame(array_map(serialize(...), $attributes), $session->attributes());
        self::assertFalse($session->hasReferences());
    }

    #[Test]
    public function addsAttributesAndKeepsEveryOtherByte(): void
    {
        $flashes = ['toast' => [self::TRICKY_TEXT]];
        $encoded = self::encoded(['_csrf/https-logout' => 'token', 'tricky' => self::TRICKY_TEXT], $flashes);
        $session = EncodedSessionData::decode($encoded);
        self::assertNotNull($session);

        $added = $session->withAttributesAdded(['_csrf/https-review_draft_round:SHOP-1' => serialize('draft-token')]);

        self::assertSame(
            self::encoded(['_csrf/https-logout' => 'token', 'tricky' => self::TRICKY_TEXT, '_csrf/https-review_draft_round:SHOP-1' => 'draft-token'], $flashes),
            $added,
        );
        self::assertSame(
            ['_csrf/https-logout' => serialize('token'), 'tricky' => serialize(self::TRICKY_TEXT), '_csrf/https-review_draft_round:SHOP-1' => serialize('draft-token')],
            EncodedSessionData::decode($added)?->attributes(),
        );
    }

    #[Test]
    public function addsToAnEmptyAttributesArrayThatIsNotFirst(): void
    {
        $encoded = '_sf2_meta|'.serialize(['u' => 1]).'_sf2_attributes|a:0:{}';
        $session = EncodedSessionData::decode($encoded);
        self::assertNotNull($session);

        self::assertSame(
            '_sf2_meta|'.serialize(['u' => 1]).'_sf2_attributes|'.serialize(['_csrf/https-logout' => 'token']),
            $session->withAttributesAdded(['_csrf/https-logout' => serialize('token')]),
        );
    }

    #[Test]
    public function refusesToAddAnAttributeTheSessionHas(): void
    {
        $session = EncodedSessionData::decode(self::encoded(['_csrf/https-logout' => 'token']));
        self::assertNotNull($session);

        $this->expectExceptionObject(new \LogicException('The session already has that attribute.'));

        $session->withAttributesAdded(['_csrf/https-logout' => serialize('other')]);
    }

    #[Test]
    public function refusesToAddToASessionWithoutAttributes(): void
    {
        $session = EncodedSessionData::decode('_sf2_meta|'.serialize(['u' => 1]));
        self::assertNotNull($session);
        self::assertNull($session->attributes());

        $this->expectExceptionObject(new \LogicException('The session has no attributes to add to.'));

        $session->withAttributesAdded(['_csrf/https-logout' => serialize('token')]);
    }

    #[Test]
    public function anEmptySessionHasNoAttributes(): void
    {
        self::assertNull(EncodedSessionData::decode('')?->attributes());
        self::assertNotNull(EncodedSessionData::decode(''));
    }

    #[Test]
    public function notesAReferenceToAnObjectOrAVariable(): void
    {
        $object = new \stdClass();
        $shared = 'shared';
        $byReference = ['a' => &$shared, 'b' => &$shared];

        $objectReference = EncodedSessionData::decode('_sf2_attributes|'.serialize(['a' => 'x']).'_sf2_flashes|'.serialize([$object, $object]));
        $variableReference = EncodedSessionData::decode('_sf2_attributes|'.serialize($byReference));

        self::assertTrue($objectReference?->hasReferences());
        self::assertTrue($variableReference?->hasReferences());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableSessions(): iterable
    {
        $attributes = serialize(['_csrf/https-logout' => 'token']);
        yield 'no name' => ['|'.$attributes];
        yield 'no bar' => ['_sf2_attributes'];
        yield 'cut short' => ['_sf2_attributes|'.substr($attributes, 0, -1)];
        yield 'a string longer than its length' => ['_sf2_attributes|a:1:{s:4:"name";s:2:"abc";}'];
        yield 'a string shorter than its length' => ['_sf2_attributes|a:1:{s:4:"name";s:4:"abc";}'];
        yield 'fewer entries than counted' => ['_sf2_attributes|a:2:{s:4:"name";s:3:"abc";}'];
        yield 'more entries than counted' => ['_sf2_attributes|a:1:{s:4:"name";s:3:"abc";s:4:"more";N;}'];
        yield 'bytes after the last value' => ['_sf2_attributes|'.$attributes.'garbage'];
        yield 'a Serializable object' => ['_sf2_attributes|a:1:{s:1:"o";C:11:"ArrayObject":4:{x:i:0}}'];
        yield 'an enum' => ['_sf2_attributes|a:1:{s:1:"e";E:7:"Foo:Bar";}'];
        yield 'an array key that is no string or number' => ['_sf2_attributes|a:1:{N;s:1:"x";}'];
        yield 'a malformed null' => ['_sf2_attributes|a:1:{s:1:"x";N}'];
        yield 'a malformed object' => ['_sf2_attributes|a:1:{s:1:"x";O:8:stdClass:0:{}}'];
        yield 'a malformed array' => ['_sf2_attributes|a:x:{}'];
        yield 'a malformed number' => ['_sf2_attributes|a:1:{s:1:"x";i:;}'];
        yield 'too deep' => ['_sf2_attributes|'.str_repeat('a:1:{i:0;', 70).'N;'.str_repeat('}', 70)];
        yield 'a malformed flash after good attributes' => ['_sf2_attributes|'.$attributes.'_sf2_flashes|a:1:{'];
    }

    #[Test]
    #[DataProvider('unreadableSessions')]
    public function aSessionNotWhollyInTheEncodingIsUnreadable(string $encoded): void
    {
        self::assertNull(EncodedSessionData::decode($encoded));
    }

    /**
     * @param array<int|string, mixed>    $attributes
     * @param array<string, list<string>> $flashes
     */
    private static function encoded(array $attributes, array $flashes = []): string
    {
        return '_sf2_attributes|'.serialize($attributes).'_sf2_flashes|'.serialize($flashes).'_sf2_meta|'.serialize(['u' => 1, 'c' => 1, 'l' => 0]);
    }
}
