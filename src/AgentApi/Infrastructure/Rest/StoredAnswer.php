<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Rest;

/**
 * The answer an Idempotency-Key already holds: the first request's status and JSON body, or a refusal when the key was
 * used for another request.
 */
final readonly class StoredAnswer
{
    public const string STORED_FOR_ANOTHER_REQUEST = 'This Idempotency-Key was used for another request. Use a new key.';

    /**
     * @param string $body the JSON text of the answer, as first sent
     */
    public function __construct(public int $status, public string $body, public bool $isReplay = true)
    {
    }

    public static function forAnotherRequest(): self
    {
        return new self(422, json_encode(['error' => self::STORED_FOR_ANOTHER_REQUEST], \JSON_THROW_ON_ERROR), false);
    }
}
