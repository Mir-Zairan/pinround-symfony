<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Workflow\Application\Transition\QuestionOpenPause;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuestionOpenPauseTest extends TestCase
{
    #[Test]
    public function whileAQuestionIsOpenTheTicketIsPausedUntilTheAskedPersonAnswers(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, asked: GuardFixtures::MIA);

        $note = new QuestionOpenPause()->noteFor($ticket);

        self::assertSame('Paused until Mia answers the question below.', $note?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function withNoQuestionOpenNothingIsPaused(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);

        self::assertNull(new QuestionOpenPause()->noteFor($ticket));
    }
}
