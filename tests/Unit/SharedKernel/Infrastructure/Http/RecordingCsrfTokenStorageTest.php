<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Http;

use App\SharedKernel\Infrastructure\Http\RecordingCsrfTokenStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

final class RecordingCsrfTokenStorageTest extends TestCase
{
    #[Test]
    public function noTokenIsStoredBeforeAnythingIsSet(): void
    {
        self::assertFalse($this->recording()->hasStoredToken());
    }

    #[Test]
    public function settingATokenStoresItAndNotesIt(): void
    {
        $storage = $this->recording();

        $storage->setToken('logout', 'abc');

        self::assertTrue($storage->hasStoredToken());
        self::assertSame('abc', $storage->getToken('logout'));
    }

    #[Test]
    public function readingCheckingRemovingAndClearingNeverNoteAStoredToken(): void
    {
        $inner = $this->inMemoryStorage();
        $inner->setToken('logout', 'abc');
        $inner->setToken('move', 'def');
        $storage = new RecordingCsrfTokenStorage($inner);

        self::assertSame('abc', $storage->getToken('logout'));
        self::assertTrue($storage->hasToken('logout'));
        self::assertSame('abc', $storage->removeToken('logout'));
        self::assertFalse($storage->hasToken('logout'));
        $storage->clear();
        self::assertFalse($storage->hasToken('move'));

        self::assertFalse($storage->hasStoredToken());
    }

    #[Test]
    public function resetForgetsTheStoredTokenButKeepsTheToken(): void
    {
        $storage = $this->recording();
        $storage->setToken('logout', 'abc');

        $storage->reset();

        self::assertFalse($storage->hasStoredToken());
        self::assertSame('abc', $storage->getToken('logout'));
    }

    #[Test]
    public function removingATokenNotesItsIdOnly(): void
    {
        $storage = $this->recording();
        $storage->setToken('logout', 'abc');
        $storage->setToken('move', 'def');

        $storage->removeToken('logout');

        self::assertTrue($storage->hasRemovedToken('logout'));
        self::assertFalse($storage->hasRemovedToken('move'));
        self::assertFalse($storage->hasCleared());
        self::assertSame('def', $storage->getToken('move'));
    }

    #[Test]
    public function clearingNotesThatEveryTokenWent(): void
    {
        $storage = $this->recording();
        $storage->setToken('logout', 'abc');

        $storage->clear();

        self::assertTrue($storage->hasCleared());
        self::assertFalse($storage->hasToken('logout'));
    }

    #[Test]
    public function resetForgetsTheRemovedAndClearedTokens(): void
    {
        $storage = $this->recording();
        $storage->setToken('logout', 'abc');
        $storage->removeToken('logout');
        $storage->clear();

        $storage->reset();

        self::assertFalse($storage->hasRemovedToken('logout'));
        self::assertFalse($storage->hasCleared());
    }

    private function recording(): RecordingCsrfTokenStorage
    {
        return new RecordingCsrfTokenStorage($this->inMemoryStorage());
    }

    private function inMemoryStorage(): ClearableTokenStorageInterface
    {
        return new class implements ClearableTokenStorageInterface {
            /** @var array<string, string> */
            private array $tokens = [];

            public function getToken(string $tokenId): string
            {
                return $this->tokens[$tokenId] ?? throw new \OutOfBoundsException('No token '.$tokenId);
            }

            public function setToken(string $tokenId, #[\SensitiveParameter] string $token): void
            {
                $this->tokens[$tokenId] = $token;
            }

            public function removeToken(string $tokenId): ?string
            {
                $token = $this->tokens[$tokenId] ?? null;
                unset($this->tokens[$tokenId]);

                return $token;
            }

            public function hasToken(string $tokenId): bool
            {
                return isset($this->tokens[$tokenId]);
            }

            public function clear(): void
            {
                $this->tokens = [];
            }
        };
    }
}
