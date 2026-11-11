<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Ticketing\UI\CollapsedSteps;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CollapsedStepsTest extends TestCase
{
    #[Test]
    public function aSessionWithNothingStoredReadsAsEmpty(): void
    {
        self::assertSame([], CollapsedSteps::idsIn($this->session()));
    }

    #[Test]
    public function aNonListSessionValueReadsAsEmpty(): void
    {
        $session = $this->session();
        $session->set('ticketing.list.collapsed_steps', 'not-a-list');

        self::assertSame([], CollapsedSteps::idsIn($session));
    }

    #[Test]
    public function onlyStringIdsAreRead(): void
    {
        $session = $this->session();
        $session->set('ticketing.list.collapsed_steps', ['a', 7, null, 'b']);

        self::assertSame(['a', 'b'], CollapsedSteps::idsIn($session));
    }

    #[Test]
    public function collapsingKeepsOnlyTheNewestTwoHundredIds(): void
    {
        $session = $this->session();
        for ($i = 1; $i <= CollapsedSteps::MAX + 1; ++$i) {
            CollapsedSteps::set($session, 'step-'.$i, true);
        }

        $ids = CollapsedSteps::idsIn($session);

        self::assertCount(200, $ids);
        self::assertSame('step-2', $ids[0]);
        self::assertSame('step-201', $ids[199]);
        self::assertNotContains('step-1', $ids);
    }

    #[Test]
    public function collapsingAnIdAgainMovesItToTheEndWithoutADuplicate(): void
    {
        $session = $this->session();
        CollapsedSteps::set($session, 'a', true);
        CollapsedSteps::set($session, 'b', true);
        CollapsedSteps::set($session, 'a', true);

        self::assertSame(['b', 'a'], CollapsedSteps::idsIn($session));
    }

    #[Test]
    public function expandingRemovesOnlyThatId(): void
    {
        $session = $this->session();
        CollapsedSteps::set($session, 'a', true);
        CollapsedSteps::set($session, 'b', true);
        CollapsedSteps::set($session, 'c', true);
        CollapsedSteps::set($session, 'b', false);

        self::assertSame(['a', 'c'], CollapsedSteps::idsIn($session));
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
