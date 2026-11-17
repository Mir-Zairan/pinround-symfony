<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * "Check with AI" was refused before anything was queued: the draft report is too long, not UTF-8 or names no project
 * id, the person is no member, the organization's AI policy says no for this person or draft, or the person has used
 * up their AI calls for now. The message is the one line the person is shown.
 */
final class IntakeCheckRefused extends \DomainException
{
    /** The code of a refusal because the project id is not a canonical lowercase uuid; a page answers it with 404. */
    public const string UNKNOWN_PROJECT = 'unknown_project';

    /** The code of a refusal because a text of the draft or its number of pins is over its cap. */
    public const string DRAFT_TOO_LONG = 'draft_too_long';

    /** The code of a refusal because a text of the draft is not valid UTF-8. */
    public const string UNREADABLE_DRAFT = 'unreadable_draft';

    /** The code of a refusal because the person is no member of the organization. */
    public const string NOT_A_MEMBER = 'not_a_member';

    /** The code of a refusal because the person made as many AI calls as the limit allows for now. */
    public const string TOO_MANY_AI_REQUESTS = 'too_many_ai_requests';

    /**
     * @param string $refusalCode a PolicyView refusal code (ai_off, leads_only, laptop_not_ready,
     *                            security_sensitive_draft_to_cloud) or one of this class's codes
     * @param string $sentence    the line shown where the button is
     */
    public function __construct(public readonly string $refusalCode, string $sentence)
    {
        parent::__construct($sentence);
    }
}
