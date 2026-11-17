<?php

declare(strict_types=1);

namespace App\Assist\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Laptop AI (ai-architecture.md, Laptop mode protocol): the server hands the browser a prompt package for Ollama on the
 * laptop and takes the laptop's result back as user-provided content. No provider is called, so no usage row is
 * written and no call counts against the person's AI limit. A result never becomes the ticket's current summary by
 * itself. Both methods read and write under row-level security with $organizationId as the tenant.
 */
interface LocalAi
{
    /** How long a package may take its result. */
    public const int PACKAGE_LIFETIME_SECONDS = 300;

    /** Unused, unexpired packages one person may hold in an organization at once. */
    public const int MAX_OPEN_PACKAGES_PER_PERSON = 10;

    /** Packages one person may be given in an organization in an hour, used and expired ones included. */
    public const int MAX_PACKAGES_PER_PERSON_PER_HOUR = 30;

    /**
     * Decides the organization's AI policy for the person and ticket as the laptop route (laptop or laptop first, AI
     * on, leads only, title only; a team-only ticket may go to the laptop), builds the prompt from the template the
     * server path uses, and stores a single-use package that expires in PACKAGE_LIFETIME_SECONDS. Runs in the caller's
     * open transaction, whose tenant is $organizationId.
     *
     * @throws LocalPackageRefused when the person is no member or may not see the ticket (the same cannot_see_ticket
     *                             for an unknown ticket and another organization's), the policy says no, the person
     *                             holds MAX_OPEN_PACKAGES_PER_PERSON open packages, or was given
     *                             MAX_PACKAGES_PER_PERSON_PER_HOUR in the last hour; nothing is stored then
     */
    public function package(LocalAiPurpose $purpose, TicketId $ticketId, UserId $requesterId, OrganizationId $organizationId): PromptPackage;

    /**
     * Finds the package through resolve_laptop_package, reads the poster's roles again and asks the policy for the
     * package's ticket as package() did, checks the model name and the text as any AI answer is checked
     * (AiAnswerCheck, the feature's size limits, plain text) and capped like a comment, and keeps the text on the
     * package in the one UPDATE that uses it, so of two results for one package exactly one is kept.
     *
     * Runs in a transaction of its own (Transactions), so the caller must have none open and must have set the tenant
     * to $organizationId. A refusal is recorded in the audit log of $organizationId (ADR-0019, ADR-0026) with its code
     * and the model name, never the text, and that transaction is committed before the refusal is thrown.
     *
     * @param string $model the model name the browser reports, LocalModelName's rule
     * @param string $text  the laptop's answer
     *
     * @throws LocalResultRefused when the package is not the poster's in $organizationId, the poster is no longer a
     *                            member or may no longer see the ticket (package_not_found), the package was used or
     *                            expired (package_gone), the policy no longer gives the poster laptop AI (ai_off,
     *                            leads_only, cloud_only), or the model name or the text fails the check
     *                            (result_rejected); the package stays as it was then
     * @throws \LogicException    when a transaction is open or no tenant is set; nothing is read or written
     */
    public function accept(LocalPackageId $packageId, string $model, string $text, UserId $posterId, OrganizationId $organizationId): LocalResult;
}
