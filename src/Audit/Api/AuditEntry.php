<?php

declare(strict_types=1);

namespace App\Audit\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * One audited action. Action and target type are codes and the ids are UUIDs, so free text can only go into $data,
 * which holds ids and before/after values of settings: never ticket text, AI prompts or answers, tokens or secrets
 * (ADR-0026, ADR-0027).
 */
final readonly class AuditEntry
{
    // For example "role.changed" or "mcp.tool_called"; at most 63 characters; \z, because $ would also accept a trailing newline.
    private const string CODE = '/^(?=.{1,63}\z)[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/';
    private const string CANONICAL_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public OrganizationId $organizationId,
        public ActorKind $actorKind,
        public ?string $actorUserId,
        public ?string $actorTokenId,
        public string $action,
        public string $targetType,
        public ?string $targetId,
        public array $data,
        public ?string $ipHash,
        public ?string $userAgentHash,
    ) {
    }

    /**
     * @param array<string, mixed> $data          ids and setting values only
     * @param ?string              $ipHash        the HMAC of the client IP, raw bytes
     * @param ?string              $userAgentHash the HMAC of the user agent, raw bytes
     *
     * @throws \InvalidArgumentException when a code, an id or a hash is malformed
     */
    public static function byUser(
        OrganizationId $organizationId,
        string $userId,
        string $action,
        string $targetType,
        ?string $targetId,
        array $data = [],
        ?string $ipHash = null,
        ?string $userAgentHash = null,
    ): self {
        return self::build($organizationId, ActorKind::User, self::uuid($userId, 'user id'), null, $action, $targetType, $targetId, $data, $ipHash, $userAgentHash);
    }

    /**
     * A personal access token always acts for the user it belongs to, so both are kept.
     *
     * @param array<string, mixed> $data ids and setting values only
     *
     * @throws \InvalidArgumentException when a code, an id or a hash is malformed
     */
    public static function byToken(
        OrganizationId $organizationId,
        string $userId,
        string $tokenId,
        string $action,
        string $targetType,
        ?string $targetId,
        array $data = [],
        ?string $ipHash = null,
        ?string $userAgentHash = null,
    ): self {
        return self::build($organizationId, ActorKind::Token, self::uuid($userId, 'user id'), self::uuid($tokenId, 'token id'), $action, $targetType, $targetId, $data, $ipHash, $userAgentHash);
    }

    /**
     * @param array<string, mixed> $data ids and setting values only
     *
     * @throws \InvalidArgumentException when a code or an id is malformed
     */
    public static function bySystem(OrganizationId $organizationId, string $action, string $targetType, ?string $targetId, array $data = []): self
    {
        return self::build($organizationId, ActorKind::System, null, null, $action, $targetType, $targetId, $data, null, null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function build(
        OrganizationId $organizationId,
        ActorKind $actorKind,
        ?string $actorUserId,
        ?string $actorTokenId,
        string $action,
        string $targetType,
        ?string $targetId,
        array $data,
        ?string $ipHash,
        ?string $userAgentHash,
    ): self {
        return new self(
            $organizationId,
            $actorKind,
            $actorUserId,
            $actorTokenId,
            self::code($action, 'action'),
            self::code($targetType, 'target type'),
            null === $targetId ? null : self::uuid($targetId, 'target id'),
            $data,
            self::hash($ipHash, 'IP hash'),
            self::hash($userAgentHash, 'user agent hash'),
        );
    }

    private static function code(string $value, string $field): string
    {
        if (1 !== preg_match(self::CODE, $value)) {
            throw new \InvalidArgumentException(\sprintf('An audit %s must be a lowercase dotted code of at most 63 characters.', $field));
        }

        return $value;
    }

    private static function uuid(string $value, string $field): string
    {
        $lowercase = strtolower($value);
        if (1 !== preg_match(self::CANONICAL_UUID, $lowercase)) {
            throw new \InvalidArgumentException(\sprintf('An audit %s must be a canonical UUID.', $field));
        }

        return $lowercase;
    }

    private static function hash(?string $value, string $field): ?string
    {
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('An audit %s must not be empty; pass null when there is none.', $field));
        }

        return $value;
    }
}
