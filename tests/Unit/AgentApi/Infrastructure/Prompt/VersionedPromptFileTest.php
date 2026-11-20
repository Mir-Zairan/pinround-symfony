<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Prompt;

use App\AgentApi\Infrastructure\Prompt\VersionedPromptFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VersionedPromptFileTest extends TestCase
{
    private ?string $promptDirectory = null;

    #[Test]
    public function aFileIsReadByteForByte(): void
    {
        $text = "\u{FEFF}# Ünïcode prompt\r\nA line without a final newline";
        file_put_contents($this->promptDirectory().'/some_prompt.v1.md', $text);

        self::assertSame($text, VersionedPromptFile::read($this->promptDirectory(), 'some_prompt.v1'));
    }

    #[Test]
    public function anEmptyFileIsRefusedRatherThanAnsweredEmpty(): void
    {
        file_put_contents($this->promptDirectory().'/some_prompt.v1.md', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('The prompt file some_prompt.v1.md is missing or empty.');

        VersionedPromptFile::read($this->promptDirectory(), 'some_prompt.v1');
    }

    protected function tearDown(): void
    {
        if (null === $this->promptDirectory) {
            return;
        }
        $promptFile = $this->promptDirectory.'/some_prompt.v1.md';
        if (is_file($promptFile)) {
            unlink($promptFile);
        }
        rmdir($this->promptDirectory);
    }

    private function promptDirectory(): string
    {
        if (null === $this->promptDirectory) {
            $this->promptDirectory = sys_get_temp_dir().'/versioned-prompt-file-'.bin2hex(random_bytes(6));
            mkdir($this->promptDirectory);
        }

        return $this->promptDirectory;
    }
}
