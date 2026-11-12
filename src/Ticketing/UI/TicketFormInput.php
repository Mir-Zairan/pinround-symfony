<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use Symfony\Component\HttpFoundation\Request;

/**
 * What the new ticket form holds, as sent, so a refused form shows it again; nothing here is checked yet. The ids are
 * in lowercase, as TeamProjectChoice and UserId hold them; an empty assigneeId is no one.
 */
final readonly class TicketFormInput
{
    public function __construct(
        public string $projectId = '',
        public string $title = '',
        public string $description = '',
        public string $type = NewTicketController::DEFAULT_TYPE,
        public string $priority = NewTicketController::DEFAULT_PRIORITY,
        public string $assigneeId = '',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            strtolower($request->request->getString(NewTicketController::PROJECT_FIELD)),
            $request->request->getString(NewTicketController::TITLE_FIELD),
            $request->request->getString(NewTicketController::DESCRIPTION_FIELD),
            $request->request->getString(NewTicketController::TYPE_FIELD),
            $request->request->getString(NewTicketController::PRIORITY_FIELD),
            strtolower(trim($request->request->getString(NewTicketController::ASSIGNEE_FIELD))),
        );
    }
}
