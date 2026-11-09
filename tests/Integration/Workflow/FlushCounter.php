<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;

/**
 * Counts the flushes of an entity manager while some work runs.
 */
final class FlushCounter
{
    private int $flushes = 0;

    public static function countDuring(EntityManagerInterface $entityManager, \Closure $work): int
    {
        $counter = new self();
        $entityManager->getEventManager()->addEventListener([Events::postFlush], $counter);
        try {
            $work();
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::postFlush], $counter);
        }

        return $counter->flushes;
    }

    public function postFlush(): void
    {
        ++$this->flushes;
    }
}
