<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Report drafts (ADR-0039, ADR-0040): a report a person's agent or extension prepares, which only that person sees,
 * edits and sends. Sending makes the ticket and its Round 0; nothing else does, and no token path calls send. Every
 * method runs in the caller's open transaction whose tenant is $organizationId, or the request's organization.
 */
interface ReportDrafts
{
    public const int MAX_SCREENSHOTS = 10;

    /** The drafts one page of forOwner holds at most. */
    public const int DRAFTS_PER_PAGE = 20;

    /** The upload target kind of a picture for a report draft that is not made yet; its id is the project key. */
    public const string NEW_REPORT_UPLOAD_TARGET_KIND = 'new_report';

    /** The detail of a report draft made from a draft round, with the round's ticket key. */
    public const string FOUND_WHILE_TESTING = 'Found while testing %s';

    /**
     * Saves a new draft owned by $owner for the project with $projectKey.
     *
     * @return string the draft's uuid
     *
     * @throws ReportDraftRefused when no project has that key or no team owns it (PROJECT_NOT_FOUND, PROJECT_HAS_NO_TEAM),
     *                            the content breaks a rule (see ReportDraftRefused), or a picture is not one $owner
     *                            uploaded or was rejected (RoundRefused's MEDIA_ texts); nothing is saved
     * @throws \LogicException    when no transaction is open, or no tenant is set or it is not $organizationId
     */
    public function create(OrganizationId $organizationId, UserId $owner, string $projectKey, ReportDraftContent $content, \DateTimeImmutable $at): string;

    /**
     * "Make it a new ticket" (ADR-0044): deletes $owner's unshared draft round and saves its pictures, with their titles,
     * summaries and pins in the same order, as a new report draft of $owner for the project of the round's ticket. Its
     * detail is "Found while testing SHOP-42" with the ticket's key; its title is the first picture's title, or that
     * line when no picture has one. The report draft points to the pictures the round had; nothing is uploaded again.
     * Makes no ticket: only Send does. On a refusal the caller must roll back its transaction, which restores the round.
     *
     * @return string the new report draft's uuid
     *
     * @throws RoundRefused       NOT_FOUND for an id that is not $owner's undeleted draft; DRAFT_CHANGED when another
     *                            request saved the round meanwhile; or when the draft is shared
     * @throws ReportDraftRefused TOO_MANY_SCREENSHOTS for a draft of more than MAX_SCREENSHOTS pictures; otherwise as
     *                            create, PROJECT_NOT_FOUND for a ticket whose project key is no project's any more
     * @throws \LogicException    when no transaction is open or no tenant is set
     */
    public function createFromDraftRound(string $roundId, UserId $owner, \DateTimeImmutable $at): string;

    /**
     * One page of the owner's drafts, at most DRAFTS_PER_PAGE, newest change first (updated_at, then id, highest
     * first); one query on the owner index, reading only the columns a ReportDraftSummary carries.
     *
     * @param ReportDraftCursor|null $after null for the first page; else the page starts right after that place
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function forOwner(UserId $owner, ?ReportDraftCursor $after = null): ReportDraftsPage;

    /**
     * One draft of $owner, or null for a malformed or unknown id and for another person's draft alike.
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function ofOwner(string $draftId, UserId $owner): ?ReportDraftView;

    /**
     * Replaces the draft's content when its version is still $expectedVersion; the version then goes up by one.
     *
     * @throws ReportDraftRefused NOT_FOUND for a draft that is not $owner's; DRAFT_CHANGED when another request saved it
     *                            since $expectedVersion; otherwise as create; nothing is saved
     * @throws \LogicException    when no transaction is open or no tenant is set
     */
    public function update(string $draftId, UserId $owner, int $expectedVersion, ReportDraftContent $content, \DateTimeImmutable $at): void;

    /**
     * Adds one picture at the end of the draft, or does nothing when the picture is in it already.
     *
     * @throws ReportDraftRefused NOT_FOUND for a draft that is not $owner's; TOO_MANY_SCREENSHOTS when it is full;
     *                            otherwise as create for the picture; nothing is saved
     * @throws \LogicException    when no transaction is open or no tenant is set
     */
    public function addScreenshot(string $draftId, UserId $owner, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): void;

    /**
     * Removes the draft and forgets its pictures' places; the pictures stay in Media until retention removes them.
     *
     * @throws ReportDraftRefused NOT_FOUND for a draft that is not $owner's; nothing is removed
     * @throws \LogicException    when no transaction is open or no tenant is set
     */
    public function delete(string $draftId, UserId $owner): void;

    /**
     * Makes the ticket from the draft, as $owner reports it at $at, with its pictures as Round 0, then removes the
     * draft, all in the caller's transaction: on a refusal the caller rolls back and the draft stays. Only the person's
     * own click in the app may call it, never a token path (ADR-0039).
     *
     * @return string the new ticket's key, for example SHOP-12
     *
     * @throws ReportDraftRefused NOT_FOUND for a draft that is not $owner's; DRAFT_CHANGED when its version is not
     *                            $expectedVersion; PROJECT_NOT_FOUND or PROJECT_HAS_NO_TEAM; a RoundRefused MEDIA_ text
     *                            for a picture still processing or rejected
     * @throws \LogicException    when no transaction is open or no tenant is set
     */
    public function send(string $draftId, UserId $owner, int $expectedVersion, \DateTimeImmutable $at): string;
}
