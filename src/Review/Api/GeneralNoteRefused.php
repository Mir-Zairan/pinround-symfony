<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A general note was not added. The message is the reason to show the person (422), except NOT_FOUND, which the
 * caller answers as a missing page (404).
 */
final class GeneralNoteRefused extends \DomainException
{
    /** A malformed or unknown round id, another organization's round, the checklist, or an unshared or deleted draft. */
    public const string NOT_FOUND = 'That round does not exist.';

    public function isNotFound(): bool
    {
        return self::NOT_FOUND === $this->getMessage();
    }
}
