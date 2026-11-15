<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * The ticket already waits for the answer to another question; a ticket waits on one question at a time (FR-CNV-08).
 */
final class QuestionAlreadyOpen extends \DomainException
{
}
