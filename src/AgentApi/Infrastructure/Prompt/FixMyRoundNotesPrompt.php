<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Prompt;

use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Api\CurrentApiToken;
use App\Ticketing\Api\TicketKeyQueries;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;

/**
 * The MCP prompt fix_my_round_notes (P14.T12, FR-MCP-16): how a client reads get_round_notes on one ticket, fixes pin by
 * pin, saves each retake with its context and the try it answers as it goes, and uploads the retakes as tries
 * (retake_of) only when the person asks. Its text is the versioned file VERSION names, beside this class, with the
 * ticket key filled in; a change of wording is a new file and a new VERSION.
 *
 * It renders only for a ticket the caller may read get_round_notes on: a token with the read scope, and a key the
 * token's organization has a ticket with that TicketPermission VIEW grants. Anything else is refused before the file is
 * read, so the answer never tells whether a ticket the caller may not see exists. Holds no state (ADR-0008).
 */
final readonly class FixMyRoundNotesPrompt implements AgentPrompt
{
    public const string NAME = 'fix_my_round_notes';
    public const string VERSION = 'fix_my_round_notes.v1';
    public const string KEY_ARGUMENT = 'key';
    /** get_round_notes' key pattern, anchored at the true end, so "SHOP-1\n" is malformed too. */
    public const string KEY_FORMAT = '/\A[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,9}\z/';
    public const string MALFORMED_KEY = '"key" must be a ticket key, for example SHOP-12.';
    /** The scope of get_round_notes, which the prompt has the client call first. */
    public const string REQUIRED_SCOPE = 'read';
    public const string DESCRIPTION = 'How to fix what was marked on a ticket\'s screenshots: read get_round_notes, fix'
        .' pin by pin, save each retake with its title, summary and pins as you go, and, when you ask, upload each as the'
        .' next try of its screenshot. You mark each pin fixed or still wrong in pinround. Needs a token with the read'
        .' scope.';

    /**
     * @param string $promptDirectory where the versioned files are: this class's own folder; a test may name another
     */
    public function __construct(
        private CurrentApiToken $currentApiToken,
        private TicketKeyQueries $ticketKeyQueries,
        private TicketVisibilityCheck $ticketVisibilityCheck,
        private string $promptDirectory = __DIR__,
    ) {
    }

    public function definition(): Prompt
    {
        return new Prompt(
            name: self::NAME,
            title: 'Fix my round notes',
            description: self::DESCRIPTION,
            arguments: [new PromptArgument(self::KEY_ARGUMENT, 'The ticket key, for example SHOP-12.', true)],
        );
    }

    /**
     * Reads the ticket in two queries, under the token's organization.
     *
     * @param array<string, string> $arguments "key", as GatedPromptGetHandler checked it is there and is text
     *
     * @throws ToolRefusal       missing scope for a token without the read scope; invalid input for a malformed key,
     *                           before any query; not found for a key the organization has no ticket with, or one
     *                           TicketPermission VIEW denies
     * @throws \RuntimeException when the file cannot be read or lacks the key's placeholder
     * @throws \LogicException   when the request has no token, which the api_token firewall never lets through
     */
    public function text(array $arguments): string
    {
        $access = $this->currentApiToken->access() ?? throw new \LogicException('fix_my_round_notes was read without a personal access token.');
        if (!$access->hasScope(self::REQUIRED_SCOPE)) {
            throw ToolRefusal::missingScope(self::REQUIRED_SCOPE);
        }
        $key = $arguments[self::KEY_ARGUMENT] ?? '';
        if (1 !== preg_match(self::KEY_FORMAT, $key)) {
            throw ToolRefusal::invalidPromptArguments(self::MALFORMED_KEY);
        }
        $ticketId = $this->ticketKeyQueries->idOf($key) ?? throw ToolRefusal::notFound();
        $this->ticketVisibilityCheck->refuseUnlessVisible($ticketId);

        return PromptTemplate::fill(VersionedPromptFile::read($this->promptDirectory, self::VERSION), [self::KEY_ARGUMENT => $key]);
    }
}
