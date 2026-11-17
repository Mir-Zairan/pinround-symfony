<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeSuggestion;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The AI suggestions kept for the person who asked (ast_suggestion), today the intake ones. Only the suggestion and its label are stored, never the draft or the prompt. Both methods work under row-level security, so
 * they need an open transaction whose tenant is $organizationId.
 */
interface Suggestions
{
    /**
     * @throws \LogicException          when no transaction is open or $organizationId is not its tenant, before any write
     * @throws \Doctrine\DBAL\Exception when the row cannot be written, for one with the same id too
     */
    public function addIntake(IntakeCheckId $checkId, OrganizationId $organizationId, UserId $requesterId, IntakeSuggestion $suggestion, \DateTimeImmutable $createdAt): void;

    /**
     * @return IntakeSuggestion|null null when the organization has no intake suggestion with this id that $requesterId
     *                               asked for
     *
     * @throws \LogicException           when no transaction is open or $organizationId is not its tenant, before any read
     * @throws \UnexpectedValueException when the stored result is not one addIntake() wrote
     */
    public function intakeOf(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?IntakeSuggestion;
}
