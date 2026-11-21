<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Process;

/**
 * For a test that holds a lock while changes in their own PHP processes queue up behind it, in a KernelTestCase.
 */
trait WaitsForWaitingChanges
{
    /**
     * Polls pg_locks, which every role may read, on a connection outside the held transaction, counting only sessions
     * of this database, as other test databases share the server. Fails when a change ends first, such as on a boot
     * error, or when they are not waiting within 30 seconds.
     *
     * @param array<string, Process> $changes
     */
    private function waitUntilWaiting(Connection $connection, int $expectedWaiting, array $changes): void
    {
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            foreach ($changes as $name => $change) {
                self::assertTrue($change->isRunning(), 'the '.$name.' ended before it waited: '.$change->getOutput().$change->getErrorOutput());
            }
            $waiting = $connection->fetchOne(
                'SELECT count(DISTINCT l.pid) FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid WHERE NOT l.granted AND a.datname = current_database()',
            );
            if ($expectedWaiting === $waiting) {
                return;
            }
            usleep(20_000);
        }
        self::fail(\sprintf('%d changes did not wait within 30 seconds.', $expectedWaiting));
    }
}
