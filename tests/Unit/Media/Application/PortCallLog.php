<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

/**
 * The calls several in-memory ports received, in one order across them, so a test can tell which came first.
 */
final class PortCallLog
{
    /** @var list<string> */
    private array $calls = [];

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
