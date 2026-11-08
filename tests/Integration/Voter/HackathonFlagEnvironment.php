<?php

declare(strict_types=1);

namespace App\Tests\Integration\Voter;

/**
 * Sets APP_HACKATHON_SKIP_ADMIN_MFA for the kernels booted after it, until restore() puts the old value back. A kernel
 * booted before keeps the value it read: the container caches each env var once read. The container looks in $_ENV,
 * then $_SERVER, then getenv(), and Dotenv put the empty default of .env in the first two, so all three are set.
 */
final readonly class HackathonFlagEnvironment
{
    public const string NAME = 'APP_HACKATHON_SKIP_ADMIN_MFA';

    private function __construct(private ?string $envBefore, private ?string $serverBefore, private string|false $processBefore)
    {
    }

    /**
     * @param string|null $value the flag's text, such as '1'; null removes it
     */
    public static function set(?string $value): self
    {
        $before = new self(self::stringOrNull($_ENV[self::NAME] ?? null), self::stringOrNull($_SERVER[self::NAME] ?? null), getenv(self::NAME));
        self::write($value, $value, null === $value ? false : $value);

        return $before;
    }

    public function restore(): void
    {
        self::write($this->envBefore, $this->serverBefore, $this->processBefore);
    }

    private static function write(?string $env, ?string $server, string|false $process): void
    {
        if (null === $env) {
            unset($_ENV[self::NAME]);
        } else {
            $_ENV[self::NAME] = $env;
        }
        if (null === $server) {
            unset($_SERVER[self::NAME]);
        } else {
            $_SERVER[self::NAME] = $server;
        }
        putenv(false === $process ? self::NAME : self::NAME.'='.$process);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
