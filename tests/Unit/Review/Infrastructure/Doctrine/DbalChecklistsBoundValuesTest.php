<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Infrastructure\Doctrine;

use App\Review\Domain\Round;
use App\Review\Domain\Screenshot;
use App\Review\Infrastructure\Doctrine\DbalChecklists;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DbalChecklists::add writes every item of a checklist in one INSERT, so the values it binds must stay under what
 * PostgreSQL's protocol allows for one statement; raising a cap in the domain fails here first.
 */
final class DbalChecklistsBoundValuesTest extends TestCase
{
    private const int POSTGRESQL_MAX_BOUND_VALUES = 65_535;

    #[Test]
    public function theLargestChecklistBindsFewerValuesThanOneStatementTakes(): void
    {
        $itemColumns = new \ReflectionClassConstant(DbalChecklists::class, 'ITEM_COLUMNS')->getValue();
        self::assertIsArray($itemColumns);
        self::assertNotEmpty($itemColumns);

        $largestChecklistBoundValues = Round::MAX_SCREENSHOTS * Screenshot::MAX_PINS * \count($itemColumns);

        self::assertLessThanOrEqual(self::POSTGRESQL_MAX_BOUND_VALUES, $largestChecklistBoundValues);
    }
}
