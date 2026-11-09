<?php

declare(strict_types=1);

namespace App\Identity\Api;

final class RegistrationRefused extends \DomainException
{
    /**
     * @param non-empty-list<RegistrationRefusal> $refusals in form order: email, name, password
     */
    public function __construct(public readonly array $refusals, ?\Throwable $previous = null)
    {
        parent::__construct('No account was created for this person.', 0, $previous);
    }
}
