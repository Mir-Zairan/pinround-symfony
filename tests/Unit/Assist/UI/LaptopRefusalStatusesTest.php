<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\UI;

use App\Assist\Api\LocalPackageRefused;
use App\Assist\Api\LocalResultRefused;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\UI\LaptopRefusalStatuses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every code LocalAi may refuse with, read from the refusal classes' constants and PolicyRefusal's cases, has the status
 * the tables below give, so a new code cannot reach a page unmapped. A code the policy only gives the cloud path is
 * forbidden on the laptop routes, as is a code nobody names.
 */
final class LaptopRefusalStatusesTest extends TestCase
{
    private const array PACKAGE_STATUSES = [
        'cannot_see_ticket' => 404,
        'too_many_open_packages' => 429,
        'too_many_packages' => 429,
        'ai_off' => 403,
        'leads_only' => 403,
        'cloud_only' => 403,
        'restricted_ticket_to_cloud' => 403,
        'laptop_not_ready' => 403,
        'security_sensitive_draft_to_cloud' => 403,
        'intake_not_on_laptop' => 403,
    ];

    private const array RESULT_STATUSES = [
        'package_not_found' => 404,
        'package_gone' => 410,
        'result_rejected' => 422,
        'ai_off' => 403,
        'leads_only' => 403,
        'cloud_only' => 403,
        'cannot_see_ticket' => 403,
        'restricted_ticket_to_cloud' => 403,
        'laptop_not_ready' => 403,
        'security_sensitive_draft_to_cloud' => 403,
        'intake_not_on_laptop' => 403,
    ];

    #[Test]
    public function everyPackageRefusalCodeHasItsStatus(): void
    {
        $codes = [...self::constantValuesOf(LocalPackageRefused::class), ...self::policyCodes()];
        self::assertEqualsCanonicalizing(array_keys(self::PACKAGE_STATUSES), array_values(array_unique($codes)));

        foreach (self::PACKAGE_STATUSES as $code => $status) {
            self::assertSame($status, LaptopRefusalStatuses::ofPackageRefusal(new LocalPackageRefused($code, 'A sentence.')), $code);
        }
    }

    #[Test]
    public function everyResultRefusalCodeHasItsStatus(): void
    {
        $codes = [...self::constantValuesOf(LocalResultRefused::class), ...self::policyCodes()];
        self::assertEqualsCanonicalizing(array_keys(self::RESULT_STATUSES), $codes);

        foreach (self::RESULT_STATUSES as $code => $status) {
            self::assertSame($status, LaptopRefusalStatuses::ofResultRefusal(self::resultRefusalOf($code)), $code);
        }
    }

    #[Test]
    public function aCodeNobodyNamesIsForbiddenOnBothRoutes(): void
    {
        self::assertSame(403, LaptopRefusalStatuses::ofPackageRefusal(new LocalPackageRefused('a_new_code', 'A sentence.')));
        self::assertSame(403, LaptopRefusalStatuses::ofResultRefusal(LocalResultRefused::notAllowed('a_new_code', 'A sentence.')));
    }

    private static function resultRefusalOf(string $code): LocalResultRefused
    {
        $refusal = match ($code) {
            LocalResultRefused::PACKAGE_NOT_FOUND => LocalResultRefused::packageNotFound(),
            LocalResultRefused::PACKAGE_GONE => LocalResultRefused::packageGone(),
            LocalResultRefused::RESULT_REJECTED => LocalResultRefused::resultRejected('The AI answer is blank.'),
            default => LocalResultRefused::notAllowed($code, 'A sentence.'),
        };
        self::assertSame($code, $refusal->refusalCode);

        return $refusal;
    }

    /**
     * @return list<string>
     */
    private static function policyCodes(): array
    {
        return array_map(static fn (PolicyRefusal $refusal): string => $refusal->value, PolicyRefusal::cases());
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function constantValuesOf(string $class): array
    {
        $values = [];
        foreach (new \ReflectionClass($class)->getConstants() as $value) {
            self::assertIsString($value);
            $values[] = $value;
        }

        return $values;
    }
}
