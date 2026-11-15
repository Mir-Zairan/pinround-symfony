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
    /**
     * @param array{media_id: mixed, title: mixed, summary: mixed, pins: mixed} $report the screenshot part in the shape
     *                                                                                  NewTicketReport takes, its values
     *                                                                                  as posted, unchecked
     */
    public function __construct(
        public string $projectId = '',
        public string $title = '',
        public string $description = '',
        public string $type = NewTicketController::DEFAULT_TYPE,
        public string $priority = NewTicketController::DEFAULT_PRIORITY,
        public string $assigneeId = '',
        public array $report = ['media_id' => '', 'title' => '', 'summary' => '', 'pins' => []],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        // Read raw rather than with getString or all($key), which answer 400 to an array or a string where the other
        // is expected: NewTicketReport refuses such a value with a message the form shows.
        $posted = $request->request->all();

        return new self(
            strtolower($request->request->getString(NewTicketController::PROJECT_FIELD)),
            $request->request->getString(NewTicketController::TITLE_FIELD),
            $request->request->getString(NewTicketController::DESCRIPTION_FIELD),
            $request->request->getString(NewTicketController::TYPE_FIELD),
            $request->request->getString(NewTicketController::PRIORITY_FIELD),
            strtolower(trim($request->request->getString(NewTicketController::ASSIGNEE_FIELD))),
            [
                'media_id' => $posted[NewTicketController::SCREENSHOT_MEDIA_FIELD] ?? '',
                'title' => $posted[NewTicketController::SCREENSHOT_TITLE_FIELD] ?? '',
                'summary' => $posted[NewTicketController::SCREENSHOT_SUMMARY_FIELD] ?? '',
                'pins' => $posted[NewTicketController::PINS_FIELD] ?? [],
            ],
        );
    }
}
