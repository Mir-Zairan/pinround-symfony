<?php

declare(strict_types=1);

namespace App\Review\Application\Notebook;

use App\Review\Api\NotebookView;

/**
 * The voter decision UseCaseNotebooks asks before it shows or changes a notebook (ADR-0013), so a caller that forgot to
 * ask cannot reach one; Review's Application may not use Symfony's security itself.
 */
interface NotebookAccess
{
    /**
     * Whether the request's logged-in person holds $attribute on the notebook; false with nobody logged in.
     *
     * @param string $attribute one of NotebookPermission's constants
     */
    public function isGranted(string $attribute, NotebookView $notebook): bool;
}
