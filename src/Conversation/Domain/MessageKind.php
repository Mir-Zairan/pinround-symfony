<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

/**
 * A comment says something on the ticket; a question asks one person, who gets the turn until it is answered.
 */
enum MessageKind: string
{
    case Comment = 'comment';
    case Question = 'question';
}
