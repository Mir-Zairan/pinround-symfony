<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A person's private notebooks (ADR-0046): pinned screenshots to ask their agent about, and the scenarios the agent
 * wrote for the extension to play. Every method acts for the request's logged-in person, its owner. Each read or write
 * of one notebook asks NotebookVoter for NotebookPermission on it (create asks it before the notebook exists); forOwner
 * lists only that person's rows of the tenant and asks no voter. Another person's notebook, another organization's,
 * and an unknown or malformed id are not found alike, and nothing changes. Every method runs in the caller's open
 * transaction whose tenant is the person's organization; a write is one read and one save that writes only over the
 * version it read, and counts as a use of the notebook.
 */
interface Notebooks
{
    public const int MAX_SCREENSHOTS = 10;
    public const int MAX_SCENARIOS = 20;

    /** The notebooks one page of forOwner holds at most. */
    public const int NOTEBOOKS_PER_PAGE = 20;

    /**
     * The upload target kind of a picture for a notebook; its id is the notebook's id. The picture goes through Media's
     * presigned upload and processing as every screenshot does, with the purpose screenshot.
     */
    public const string UPLOAD_TARGET_KIND = 'notebook';

    /**
     * Saves a new, empty notebook of the request's person.
     *
     * @return string the notebook's uuid
     *
     * @throws NotebookRefused a title rule (blank, over 120 characters, unsavable text); NOT_YOURS_TO_MAKE when
     *                         NotebookVoter denies EDIT on the new notebook; nothing is saved. Membership is not checked
     *                         here: as for report drafts, the request's tenant is always one of the person's organizations
     *                         (EnterChosenOrganization), so a non-member never gets this far
     * @throws \LogicException when nobody is logged in, no transaction is open or no tenant is set
     */
    public function create(string $title, \DateTimeImmutable $at): string;

    /**
     * Adds one picture at the end of the notebook. The picture is a Media object the person uploaded, still processing
     * or ready, as an agent adds it right after its upload.
     *
     * @return int the pictures the notebook holds after the add, as saved over the version read
     *
     * @throws NotebookRefused NOT_FOUND; at most MAX_SCREENSHOTS pictures, each once, and the picture's own rules
     *                         (title, summary, pins); a RoundRefused MEDIA_ text for a picture that is not the person's
     *                         upload or was rejected; NOTEBOOK_CHANGED when another request saved it meanwhile; nothing
     *                         is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function addScreenshot(string $notebookId, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): int;

    /**
     * @throws NotebookRefused NOT_FOUND; a title rule; NOTEBOOK_CHANGED; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function rename(string $notebookId, string $title, \DateTimeImmutable $at): void;

    /**
     * Removes the notebook. Its pictures stay in Media, where a report, a round or another notebook may use them too,
     * until retention removes them.
     *
     * @throws NotebookRefused NOT_FOUND; NOTEBOOK_CHANGED when another request saved it meanwhile; nothing is removed
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function delete(string $notebookId): void;

    /**
     * One page of the person's notebooks, at most NOTEBOOKS_PER_PAGE, last used first (then id, highest first); one
     * query on the owner index.
     *
     * @param NotebookCursor|null $after null for the first page; else the page starts right after that place
     *
     * @throws \LogicException when nobody is logged in, no transaction is open or no tenant is set
     */
    public function forOwner(?NotebookCursor $after = null): NotebooksPage;

    /**
     * One notebook of the person, or null for a malformed or unknown id, another person's and another organization's
     * notebook, and with nobody logged in, alike. Reading it is no use: its last use time stays.
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function get(string $notebookId): ?NotebookView;

    /**
     * Replaces all of the notebook's scenarios with $decodedScenarios, or none of them: one broken rule refuses the
     * whole list and the old scenarios stay.
     *
     * @param mixed $decodedScenarios the scenarios as json_decode($json) gives them, objects as stdClass, never with
     *                                $associative true; a list of at most MAX_SCENARIOS scenarios in scenario.v1.json's
     *                                format, an empty list for none
     *
     * @throws NotebookRefused NOT_FOUND; the first scenario rule broken ("Scenario 2, step 5: …"), more than
     *                         MAX_SCENARIOS included; NOTEBOOK_CHANGED; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function replaceScenarios(string $notebookId, mixed $decodedScenarios, \DateTimeImmutable $at): void;
}
