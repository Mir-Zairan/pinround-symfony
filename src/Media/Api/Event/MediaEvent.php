<?php

declare(strict_types=1);

namespace App\Media\Api\Event;

/**
 * An event of the Media module about one media object. It goes to the outbox in the transaction of the change and on
 * to async_notify (messenger.yaml), and is handled under its organization. Ids are canonical UUID strings, so a
 * consumer needs no Media class to read one.
 */
interface MediaEvent
{
    public string $organizationId { get; }

    public string $mediaId { get; }
}
