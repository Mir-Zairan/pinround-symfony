<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * A question was not asked, answered or withdrawn: the ticket already waits for an answer, the person asked is not on
 * the ticket, or the question is not open or not the actor's to answer or withdraw. Nothing was saved. The message is
 * the reason to show the person.
 */
final class QuestionRefused extends \DomainException
{
}
