<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * The eight steps every organization has and nobody can remove.
 */
enum CoreStep: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case Progress = 'progress';
    case Review = 'review';
    case Test = 'test';
    case Input = 'input';
    case Hold = 'hold';
    case Done = 'done';

    public function stepName(): string
    {
        return match ($this) {
            self::Backlog => 'Backlog',
            self::Todo => 'To do',
            self::Progress => 'In progress',
            self::Review => 'Code review',
            self::Test => 'Testing',
            self::Input => 'Needs input',
            self::Hold => 'On hold',
            self::Done => 'Done',
        };
    }

    public function group(): Group
    {
        return match ($this) {
            self::Backlog, self::Todo => Group::Todo,
            self::Progress => Group::Doing,
            self::Review, self::Test => Group::Check,
            self::Input, self::Hold => Group::Wait,
            self::Done => Group::Done,
        };
    }
}
