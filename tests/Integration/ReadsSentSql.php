<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * The SQL Doctrine's debug middleware recorded on every connection of the test kernel, for tests that pin the queries a
 * read or a write sends. Reset the holder before the measured work; it keeps what earlier work sent.
 */
trait ReadsSentSql
{
    /**
     * @return list<string> the SQL of each recorded statement, connection by connection, in the order sent
     */
    private static function sqlOf(DebugDataHolder $debugData): array
    {
        $sql = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            foreach ($queriesOfOneConnection as $query) {
                self::assertIsArray($query);
                self::assertIsString($query['sql']);
                $sql[] = $query['sql'];
            }
        }

        return $sql;
    }

    private static function queryCountOf(DebugDataHolder $debugData): int
    {
        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    private function debugData(): DebugDataHolder
    {
        return self::getContainer()->get('doctrine.debug_data_holder');
    }
}
