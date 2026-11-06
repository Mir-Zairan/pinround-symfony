<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

enum Group: string
{
    case ToDo = 'todo';
    case Doing = 'doing';
    case Checking = 'checking';
    case Waiting = 'waiting';
    case Done = 'done';
}
