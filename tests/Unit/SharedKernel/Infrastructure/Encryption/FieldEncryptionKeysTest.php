<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Encryption;

use App\SharedKernel\Infrastructure\Encryption\FieldEncryptionKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldEncryptionKeysTest extends TestCase
{
    private const string KEY_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    private const string KEY_B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBA=';

    #[Test]
    public function itReadsEveryKeyAndTheCurrentId(): void
    {
        $keys = FieldEncryptionKeys::fromConfiguration('2026-10:'.self::KEY_A.', 2026-11:'.self::KEY_B, '2026-11');

        self::assertSame('2026-11', $keys->currentKeyId);
        self::assertSame(base64_decode(self::KEY_B, true), $keys->currentKey());
        self::assertSame(base64_decode(self::KEY_A, true), $keys->keyFor('2026-10'));
        self::assertSame(base64_decode(self::KEY_B, true), $keys->keyFor('2026-11'));
    }

    #[Test]
    public function anIdOfThirtyTwoLettersDigitsDashesAndUnderscoresIsAccepted(): void
    {
        $longestId = 'Key_2026-11'.str_repeat('x', 21);

        $keys = FieldEncryptionKeys::fromConfiguration($longestId.':'.self::KEY_A, $longestId);

        self::assertSame($longestId, $keys->currentKeyId);
    }

    #[Test]
    public function anIdThatIsNotInTheRingHasNoKey(): void
    {
        $keys = FieldEncryptionKeys::fromConfiguration('2026-11:'.self::KEY_B, '2026-11');

        self::assertNull($keys->keyFor('2026-10'));
        self::assertNull($keys->keyFor(''));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedConfiguration(): iterable
    {
        yield 'no keys' => ['', 'a', 'FIELD_ENCRYPTION_KEYS is empty'];
        yield 'only commas' => [' , ', 'a', 'FIELD_ENCRYPTION_KEYS entry 1 is not <id>:<base64 key>'];
        yield 'entry without a colon' => [self::KEY_A, 'a', 'FIELD_ENCRYPTION_KEYS entry 1 is not <id>:<base64 key>'];
        yield 'empty id' => [':'.self::KEY_A, 'a', 'FIELD_ENCRYPTION_KEYS entry 1 has a key id that is not 1 to 32 letters, digits, "-" or "_"'];
        yield 'id with a space' => ['a b:'.self::KEY_A, 'a b', 'FIELD_ENCRYPTION_KEYS entry 1 has a key id that is not 1 to 32 letters, digits, "-" or "_"'];
        yield 'id with a trailing newline' => ["a\n:".self::KEY_A, 'a', 'FIELD_ENCRYPTION_KEYS entry 1 has a key id that is not 1 to 32 letters, digits, "-" or "_"'];
        yield 'id longer than 32' => [str_repeat('a', 33).':'.self::KEY_A, 'a', 'FIELD_ENCRYPTION_KEYS entry 1 has a key id that is not 1 to 32 letters, digits, "-" or "_"'];
        yield 'key not base64' => ['a:not base64!', 'a', 'The key "a" in FIELD_ENCRYPTION_KEYS is not base64'];
        yield 'key of 16 bytes' => ['a:'.base64_encode(str_repeat('k', 16)), 'a', 'The key "a" in FIELD_ENCRYPTION_KEYS is 16 bytes; it must be 32'];
        yield 'key of 33 bytes' => ['a:'.base64_encode(str_repeat('k', 33)), 'a', 'The key "a" in FIELD_ENCRYPTION_KEYS is 33 bytes; it must be 32'];
        yield 'second entry broken' => ['a:'.self::KEY_A.',b:'.base64_encode('short'), 'a', 'The key "b" in FIELD_ENCRYPTION_KEYS is 5 bytes; it must be 32'];
        yield 'id twice' => ['a:'.self::KEY_A.',a:'.self::KEY_B, 'a', 'The key id "a" appears twice in FIELD_ENCRYPTION_KEYS'];
        yield 'no current id' => ['a:'.self::KEY_A, '', 'FIELD_ENCRYPTION_CURRENT_KEY_ID is empty'];
        yield 'current id not in the list' => ['a:'.self::KEY_A.',b:'.self::KEY_B, 'c', 'FIELD_ENCRYPTION_CURRENT_KEY_ID "c" is not one of the ids in FIELD_ENCRYPTION_KEYS (a, b)'];
        yield 'current id pasted with its key' => ['a:'.self::KEY_A, 'a:'.self::KEY_A, 'FIELD_ENCRYPTION_CURRENT_KEY_ID "?" is not one of the ids in FIELD_ENCRYPTION_KEYS (a)'];
    }

    #[Test]
    #[DataProvider('refusedConfiguration')]
    public function itRefusesABrokenConfigurationWithAClearMessage(string $keyList, string $currentKeyId, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        FieldEncryptionKeys::fromConfiguration($keyList, $currentKeyId);
    }

    #[Test]
    public function aRefusalNeverEchoesTheKeyMaterial(): void
    {
        $tooShortKey = base64_encode('sixteen-byte-key');

        try {
            FieldEncryptionKeys::fromConfiguration('a:'.$tooShortKey, 'a');
            self::fail('A 16-byte key was accepted.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringNotContainsString($tooShortKey, $refusal->getMessage());
            self::assertStringNotContainsString('sixteen-byte-key', $refusal->getMessage());
        }

        try {
            FieldEncryptionKeys::fromConfiguration(self::KEY_A, 'a');
            self::fail('An entry without an id was accepted.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringNotContainsString(self::KEY_A, $refusal->getMessage());
        }
    }
}
