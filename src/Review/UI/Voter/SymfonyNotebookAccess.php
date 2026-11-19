<?php

declare(strict_types=1);

namespace App\Review\UI\Voter;

use App\Review\Api\NotebookView;
use App\Review\Application\Notebook\NotebookAccess;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * NotebookAccess through the app's access decision manager, so NotebookVoter decides with every other voter, on the
 * request's token; with none, Symfony decides for a NullToken and NotebookVoter denies.
 */
final readonly class SymfonyNotebookAccess implements NotebookAccess
{
    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    public function isGranted(string $attribute, NotebookView $notebook): bool
    {
        return $this->authorizationChecker->isGranted($attribute, $notebook);
    }
}
