<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Encryption;

use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Infrastructure\Encryption\SodiumFieldEncryption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SodiumFieldEncryptionTest extends TestCase
{
    private const string KEY_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    private const string KEY_B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBA=';
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    #[Test]
    public function whatItEncryptsDecryptsToTheSamePlaintext(): void
    {
        $encryption = new SodiumFieldEncryption('a:'.self::KEY_A, 'a');

        $stored = $encryption->encrypt(self::SECRET);

        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertSame(self::SECRET, $encryption->decrypt($stored));
    }

    #[Test]
    public function anEmptyPlaintextSurvivesTheRoundTrip(): void
    {
        $encryption = new SodiumFieldEncryption('a:'.self::KEY_A, 'a');

        self::assertSame('', $encryption->decrypt($encryption->encrypt('')));
    }

    #[Test]
    public function twoEncryptionsOfTheSamePlaintextDiffer(): void
    {
        $encryption = new SodiumFieldEncryption('a:'.self::KEY_A, 'a');

        self::assertNotSame($encryption->encrypt(self::SECRET), $encryption->encrypt(self::SECRET));
    }

    #[Test]
    public function theStoredValueIsTheKeyIdThenTheNonceThenTheCiphertext(): void
    {
        $encryption = new SodiumFieldEncryption('2026-11:'.self::KEY_A, '2026-11');

        $stored = $encryption->encrypt(self::SECRET);

        self::assertSame(7, \ord($stored[0]));
        self::assertSame('2026-11', substr($stored, 1, 7));
        $nonce = substr($stored, 8, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($stored, 8 + \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        self::assertSame(\strlen(self::SECRET) + \SODIUM_CRYPTO_SECRETBOX_MACBYTES, \strlen($ciphertext));
        self::assertSame(self::SECRET, sodium_crypto_secretbox_open($ciphertext, $nonce, base64_decode(self::KEY_A, true)));
    }

    #[Test]
    public function aRingThatStillHoldsTheOldKeyDecryptsWhatTheOldKeyWrote(): void
    {
        $stored = new SodiumFieldEncryption('a:'.self::KEY_A, 'a')->encrypt(self::SECRET);

        $rotated = new SodiumFieldEncryption('a:'.self::KEY_A.',b:'.self::KEY_B, 'b');

        self::assertSame(self::SECRET, $rotated->decrypt($stored));
    }

    #[Test]
    public function newWritesAfterARotationCarryTheCurrentKeyId(): void
    {
        $rotated = new SodiumFieldEncryption('a:'.self::KEY_A.',b:'.self::KEY_B, 'b');

        $stored = $rotated->encrypt(self::SECRET);

        self::assertSame("\x01b", substr($stored, 0, 2));
        self::assertSame(self::SECRET, new SodiumFieldEncryption('b:'.self::KEY_B, 'b')->decrypt($stored));
    }

    #[Test]
    public function aRingWithoutTheKeyThatWroteTheValueRefusesIt(): void
    {
        $stored = new SodiumFieldEncryption('a:'.self::KEY_A, 'a')->encrypt(self::SECRET);

        $this->expectException(UndecryptableField::class);
        $this->expectExceptionMessage('No field encryption key has the id "a".');

        new SodiumFieldEncryption('b:'.self::KEY_B, 'b')->decrypt($stored);
    }

    #[Test]
    public function anUnknownKeyIdThatIsNotAValidIdIsNotEchoed(): void
    {
        $encryption = new SodiumFieldEncryption('a:'.self::KEY_A, 'a');
        $stored = "\x03\x00\xff\n".str_repeat("\x00", \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES);

        $this->expectException(UndecryptableField::class);
        $this->expectExceptionMessage('No field encryption key has the stored key id, which is not a valid id.');

        $encryption->decrypt($stored);
    }

    #[Test]
    public function aValueWrittenUnderAnotherKeyButRelabelledWithAKnownIdIsRefused(): void
    {
        $storedUnderB = new SodiumFieldEncryption('a:'.self::KEY_B, 'a')->encrypt(self::SECRET);

        $this->expectException(UndecryptableField::class);
        $this->expectExceptionMessage('The field encrypted with key "a" is altered or was written with another key.');

        new SodiumFieldEncryption('a:'.self::KEY_A, 'a')->decrypt($storedUnderB);
    }

    /**
     * @return iterable<string, array{\Closure(string): string, string}>
     */
    public static function alteredValues(): iterable
    {
        $altered = 'The field encrypted with key "a" is altered or was written with another key.';
        $tooShort = 'The stored field is too short to hold a key id, a nonce and a ciphertext.';

        yield 'one ciphertext byte flipped' => [static fn (string $stored): string => self::flipByteAt($stored, \strlen($stored) - 1), $altered];
        yield 'first ciphertext byte flipped' => [static fn (string $stored): string => self::flipByteAt($stored, 2 + \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $altered];
        yield 'one nonce byte flipped' => [static fn (string $stored): string => self::flipByteAt($stored, 2), $altered];
        yield 'last byte cut off' => [static fn (string $stored): string => substr($stored, 0, -1), $altered];
        yield 'a byte added' => [static fn (string $stored): string => $stored."\x00", $altered];
        yield 'cut to the key id and nonce' => [static fn (string $stored): string => substr($stored, 0, 2 + \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $tooShort];
        yield 'cut to one byte under the minimum' => [static fn (string $stored): string => substr($stored, 0, 1 + 1 + \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES - 1), $tooShort];
        yield 'cut to the key id length' => [static fn (string $stored): string => substr($stored, 0, 1), $tooShort];
        yield 'empty' => [static fn (string $stored): string => '', $tooShort];
        yield 'key id length of zero' => [static fn (string $stored): string => "\x00".substr($stored, 1), $tooShort];
        yield 'key id length past the end' => [static fn (string $stored): string => "\xff".substr($stored, 1), $tooShort];
    }

    /**
     * @param \Closure(string): string $alter
     */
    #[Test]
    #[DataProvider('alteredValues')]
    public function anAlteredOrCutValueIsRefused(\Closure $alter, string $message): void
    {
        $encryption = new SodiumFieldEncryption('a:'.self::KEY_A, 'a');
        $stored = $encryption->encrypt(self::SECRET);

        $this->expectException(UndecryptableField::class);
        $this->expectExceptionMessage($message);

        $encryption->decrypt($alter($stored));
    }

    #[Test]
    public function aMissingConfigurationFailsOnFirstUseNotWhenTheServiceIsBuilt(): void
    {
        $encryption = new SodiumFieldEncryption('', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FIELD_ENCRYPTION_KEYS is empty');

        $encryption->encrypt(self::SECRET);
    }

    #[Test]
    public function aMissingConfigurationAlsoFailsOnDecrypt(): void
    {
        $stored = new SodiumFieldEncryption('a:'.self::KEY_A, 'a')->encrypt(self::SECRET);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FIELD_ENCRYPTION_KEYS is empty');

        new SodiumFieldEncryption('', '')->decrypt($stored);
    }

    #[Test]
    public function aStackTraceOfAFailedEncryptShowsNeitherThePlaintextNorTheKeys(): void
    {
        $tooShortKey = base64_encode('sixteen-byte-key');
        // Production ini (CI's too) drops every argument from traces, which would make this pass without the attribute.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            new SodiumFieldEncryption('a:'.$tooShortKey, 'a')->encrypt(self::SECRET);
            self::fail('A 16-byte key was accepted.');
        } catch (\InvalidArgumentException $refusal) {
            $trace = $refusal->getTraceAsString();
            self::assertStringContainsString('SodiumFieldEncryption->encrypt(Object(SensitiveParameterValue))', $trace);
            self::assertStringContainsString('FieldEncryptionKeys::fromConfiguration(Object(SensitiveParameterValue), ', $trace);
            // Traces cut string arguments to 15 characters, so a shorter piece is what would leak.
            self::assertStringNotContainsString(substr(self::SECRET, 0, 10), $trace);
            self::assertStringNotContainsString(substr($tooShortKey, 0, 10), $trace);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    #[Test]
    public function aStackTraceOfAFailedDecryptShowsNotTheStoredValue(): void
    {
        $encryption = new SodiumFieldEncryption('a:'.self::KEY_A, 'a');
        $altered = self::flipByteAt($encryption->encrypt(self::SECRET), 2);
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            $encryption->decrypt($altered);
            self::fail('An altered value was decrypted.');
        } catch (UndecryptableField $refusal) {
            self::assertStringContainsString('SodiumFieldEncryption->decrypt(Object(SensitiveParameterValue))', $refusal->getTraceAsString());
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    private static function flipByteAt(string $stored, int $offset): string
    {
        $stored[$offset] = \chr(\ord($stored[$offset]) ^ 0x01);

        return $stored;
    }
}
