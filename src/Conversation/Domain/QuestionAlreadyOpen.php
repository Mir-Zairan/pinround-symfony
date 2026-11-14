<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

/**
 * The ticket has an open question already, so a second one was not added (cnv_message_ticket_id_open_question_key,
 * FR-CNV-08).
 */
final class QuestionAlreadyOpen extends \DomainException
{
}
