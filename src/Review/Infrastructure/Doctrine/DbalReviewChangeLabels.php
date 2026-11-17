<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\ReviewChangeLabels;
use App\Ticketing\Api\ReviewedPin;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Reads rvw_check_item, rvw_pin and rvw_area through DBAL. Every statement names organization_id as well as row-level
 * security, so each one uses an index that starts with it.
 */
final readonly class DbalReviewChangeLabels implements ReviewChangeLabels
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function checkItemPinNumberOf(string $itemId): ?int
    {
        $organizationId = $this->requireTransactionAndTenant();
        if (!Uuid::isValid($itemId)) {
            return null;
        }
        $number = $this->connection->fetchOne(
            'SELECT pin.number FROM rvw_check_item AS item'
            .' JOIN rvw_pin AS pin ON pin.organization_id = item.organization_id AND pin.id = item.pin_id'
            .' WHERE item.organization_id = ? AND item.id = ?',
            [$organizationId, $itemId],
        );

        return false === $number ? null : self::integer($number);
    }

    public function reviewedPinOf(string $roundId, string $pinId): ?ReviewedPin
    {
        $organizationId = $this->requireTransactionAndTenant();
        if (!Uuid::isValid($roundId) || !Uuid::isValid($pinId)) {
            return null;
        }
        // Two areas of a round may compare against one "before" picture (DbalAreas::pinToCheckOf); the first by
        // position names the pin.
        $row = $this->connection->fetchAssociative(
            'SELECT pin.number, area.name FROM rvw_pin AS pin'
            .' JOIN rvw_area AS area ON area.organization_id = pin.organization_id AND area.before_screenshot_id = pin.screenshot_id'
            .' WHERE pin.organization_id = ? AND pin.id = ? AND area.round_id = ?'
            .' ORDER BY area.position LIMIT 1',
            [$organizationId, $pinId, $roundId],
        );
        if (false === $row) {
            return null;
        }

        return new ReviewedPin(self::integer($row['number']), self::text($row['name']));
    }

    public function areaNameOf(string $areaId): ?string
    {
        $organizationId = $this->requireTransactionAndTenant();
        if (!Uuid::isValid($areaId)) {
            return null;
        }
        $name = $this->connection->fetchOne('SELECT name FROM rvw_area WHERE organization_id = ? AND id = ?', [$organizationId, $areaId]);

        return false === $name ? null : self::text($name);
    }

    private function requireTransactionAndTenant(): string
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Review change labels are read in a transaction under the organization as tenant; none is open.');
        }

        return $this->tenantContext->require()->toString();
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('An area name from the database is not a string.');
    }

    private static function integer(mixed $value): int
    {
        return \is_int($value) ? $value : throw new \LogicException('A pin number from the database is not an integer.');
    }
}
