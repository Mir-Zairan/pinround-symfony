<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * How a metered AI call ended; the value is what ast_usage.status stores.
 */
enum AiCallStatus: string
{
    /** The provider answered and the answer was used. */
    case Answered = 'answered';
    /** The provider answered, but the answer was refused, for example by the output validator (AiAnswerRejected). */
    case Rejected = 'rejected';
    /** The provider was asked but gave no usable answer: an error, a timeout or a refusal of its own. */
    case Failed = 'failed';
}
