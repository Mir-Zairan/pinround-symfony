<?php

declare(strict_types=1);

namespace App\Assist\Application\Intake;

/**
 * The person has IntakeRequests::MAX_OPEN_CHECKS_PER_PERSON checks open already, so no other was opened. Not a
 * \RuntimeException, so it is never taken for a state that cannot be reached.
 */
final class TooManyOpenIntakeChecks extends \DomainException
{
    public function __construct()
    {
        parent::__construct(\sprintf('At most %d intake checks may be open per person.', IntakeRequests::MAX_OPEN_CHECKS_PER_PERSON));
    }
}
