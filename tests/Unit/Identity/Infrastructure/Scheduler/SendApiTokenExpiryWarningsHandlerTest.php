<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Scheduler;

use App\Identity\Api\UserQueries;
use App\Identity\Infrastructure\Scheduler\SendApiTokenExpiryWarningsHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SendApiTokenExpiryWarningsHandlerTest extends TestCase
{
    /**
     * A full page can have as many owners as tokens, and UserQueries refuses more ids than its caps: the page would
     * throw after its claim and roll back on every retry.
     */
    #[Test]
    public function aFullPageHasNoMoreOwnersThanUserQueriesTakesForEmailsOrTimeZones(): void
    {
        self::assertLessThanOrEqual(UserQueries::MAX_EMAIL_USER_IDS, SendApiTokenExpiryWarningsHandler::MAX_TOKENS_PER_PAGE);
        self::assertLessThanOrEqual(UserQueries::MAX_TIME_ZONE_USER_IDS, SendApiTokenExpiryWarningsHandler::MAX_TOKENS_PER_PAGE);
    }
}
