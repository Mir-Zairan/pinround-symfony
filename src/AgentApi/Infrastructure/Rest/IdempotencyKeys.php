<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Rest;

use App\Identity\Api\ApiTokenAccess;
use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The Idempotency-Key answers of POST /api/v1 in api_idempotency_key, per token, under row-level security. reserve()
 * inserts the key before the request runs; a second request with the same key waits on that row until the first
 * commits (or rolls back, and then takes the key itself), so the same request never runs twice. Holds no state
 * (ADR-0008). Each method runs in the request transaction.
 */
final readonly class IdempotencyKeys
{
    /** 1 to 100 characters a header carries plainly; \z, as $ would also accept a trailing newline. */
    public const string KEY_PATTERN = '/\A[A-Za-z0-9_.:-]{1,100}\z/';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    /**
     * @param string $requestHash the raw SHA-256 of the method, path and body
     *
     * @return StoredAnswer|null null when this request took the key and must run; the first request's answer when it
     *                           ran already, or STORED_FOR_ANOTHER_REQUEST's mismatch when that request was another one
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function reserve(ApiTokenAccess $access, string $key, string $requestHash, \DateTimeImmutable $at): ?StoredAnswer
    {
        $organizationId = $this->requireTenant();
        $taken = $this->connection->executeStatement(
            'INSERT INTO api_idempotency_key (organization_id, token_id, key, request_hash, created_at)'
            .' VALUES (:organization, :token, :key, :hash, :at) ON CONFLICT (organization_id, token_id, key) DO NOTHING',
            ['organization' => $organizationId, 'token' => $access->tokenId, 'key' => $key, 'hash' => $requestHash, 'at' => $at->format(\DATE_ATOM)],
            ['hash' => ParameterType::BINARY],
        );
        if (1 === $taken) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT request_hash, status, response FROM api_idempotency_key WHERE organization_id = :organization AND token_id = :token AND key = :key',
            ['organization' => $organizationId, 'token' => $access->tokenId, 'key' => $key],
        );
        if (false === $row) {
            throw new \LogicException('An idempotency key that conflicted on insert is not found.');
        }
        $storedHash = \is_resource($row['request_hash']) ? stream_get_contents($row['request_hash']) : $row['request_hash'];
        if (!\is_string($storedHash) || !hash_equals($storedHash, $requestHash)) {
            return StoredAnswer::forAnotherRequest();
        }
        if (!\is_int($row['status']) || !\is_string($row['response'])) {
            throw new \LogicException('A committed idempotency key has no answer.');
        }

        return new StoredAnswer($row['status'], $row['response']);
    }

    /**
     * @param string $body the response's JSON text as sent, kept byte for byte
     *
     * @throws \LogicException when the key was not reserved by this request
     */
    public function answer(ApiTokenAccess $access, string $key, int $status, string $body): void
    {
        $answered = $this->connection->executeStatement(
            'UPDATE api_idempotency_key SET status = :status, response = :response'
            .' WHERE organization_id = :organization AND token_id = :token AND key = :key AND status IS NULL',
            [
                'status' => $status,
                'response' => $body,
                'organization' => $this->requireTenant(),
                'token' => $access->tokenId,
                'key' => $key,
            ],
        );
        if (1 !== $answered) {
            throw new \LogicException('The idempotency key to answer was not reserved by this request.');
        }
    }

    private function requireTenant(): string
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Idempotency keys are kept in the request transaction; none is open.');
        }

        return $this->tenantContext->require()->toString();
    }
}
