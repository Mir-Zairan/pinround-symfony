<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * What a personal access token may do on top of what its user may do (ADR-0021, ADR-0039). A closed set: the
 * idn_api_token_scopes_check constraint holds the same values.
 */
enum ApiTokenScope: string
{
    case Read = 'read';
    case RoundsWrite = 'rounds:write';
    case CommentsWrite = 'comments:write';
    case TicketsMove = 'tickets:move';
    case ReportsWrite = 'reports:write';
    case NotebooksWrite = 'notebooks:write';

    /**
     * The scopes a token made for Claude Code starts with; reports:write (ADR-0039) and notebooks:write (ADR-0046) are
     * ticked by the user only.
     *
     * @return list<self>
     */
    public static function claudeCodeDefaults(): array
    {
        return [self::Read, self::RoundsWrite];
    }

    /**
     * @param list<string> $values scope names as a form or a stored row carries them
     *
     * @return list<self> in the order given
     *
     * @throws UnknownApiTokenScope when a value is not one of the cases
     */
    public static function fromValues(array $values): array
    {
        return array_map(
            static fn (string $value): self => self::tryFrom($value) ?? throw new UnknownApiTokenScope('A token scope must be one of: '.implode(', ', array_column(self::cases(), 'value')).'.'),
            $values,
        );
    }
}
