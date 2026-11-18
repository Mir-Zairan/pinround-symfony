<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * No laptop AI prompt package was issued: the person is no member or may not see the ticket, the organization's AI
 * policy gives them no laptop AI, or they already hold as many open packages, or were given as many in the last hour, as the
 * caps allow. Nothing was stored.
 * The message is the one line the person is shown.
 */
final class LocalPackageRefused extends \DomainException
{
    /** The code of a refusal because the person may not see the ticket, or is no member; a page answers it with 404. */
    public const string CANNOT_SEE_TICKET = 'cannot_see_ticket';

    /** The code of a refusal because the person holds as many unused, unexpired packages as the cap allows. */
    public const string TOO_MANY_OPEN_PACKAGES = 'too_many_open_packages';

    /** The code of a refusal because the person was given as many packages in the last hour as the cap allows. */
    public const string TOO_MANY_PACKAGES = 'too_many_packages';

    /**
     * @param string $refusalCode a PolicyView refusal code (ai_off, leads_only, cannot_see_ticket, cloud_only,
     *                            admin_only_member) or one of this class's codes
     * @param string $sentence    the line shown where the button is
     */
    public function __construct(public readonly string $refusalCode, string $sentence)
    {
        parent::__construct($sentence);
    }

    public static function tooManyOpenPackages(int $maxOpenPackages): self
    {
        return new self(self::TOO_MANY_OPEN_PACKAGES, \sprintf('You have %d laptop AI requests open. Wait a few minutes and try again.', $maxOpenPackages));
    }

    public static function tooManyPackages(int $maxPackagesPerHour): self
    {
        return new self(self::TOO_MANY_PACKAGES, \sprintf('You asked for %d laptop AI requests in the last hour. Try again later.', $maxPackagesPerHour));
    }
}
