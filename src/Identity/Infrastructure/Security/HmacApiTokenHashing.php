<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\ApiTokenHashing;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * HMAC-SHA256 of the token, keyed by the pepper API_TOKEN_PEPPER: the SHA-256 with a server-side pepper of ADR-0021,
 * in the keyed form that needs no care about how the token and the pepper are joined. Changing the pepper makes every
 * token stop working. A missing variable fails when the container builds this service; an empty or short one here.
 */
final readonly class HmacApiTokenHashing implements ApiTokenHashing
{
    public const int PEPPER_MIN_BYTES = 32;

    /**
     * @throws \InvalidArgumentException when the pepper is shorter than PEPPER_MIN_BYTES, empty included
     */
    public function __construct(
        #[\SensitiveParameter]
        #[Autowire(env: 'API_TOKEN_PEPPER')]
        private string $pepper,
    ) {
        if (\strlen($pepper) < self::PEPPER_MIN_BYTES) {
            throw new \InvalidArgumentException(\sprintf('API_TOKEN_PEPPER must be at least %d bytes; set it from the secret store.', self::PEPPER_MIN_BYTES));
        }
    }

    public function hash(#[\SensitiveParameter] string $plainToken): string
    {
        return hash_hmac('sha256', $plainToken, $this->pepper, true);
    }
}
