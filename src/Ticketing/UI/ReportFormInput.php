<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use Symfony\Component\HttpFoundation\Request;

/**
 * What the Report a problem form holds, as sent, so a refused form shows it again; nothing here is checked yet. Ids are
 * in lowercase; a team of NO_TEAM is "Not sure" and an empty assigneeId is "The team decides".
 */
final readonly class ReportFormInput
{
    public const string NO_TEAM = 'none';

    /**
     * @param array{media_id: mixed, title: mixed, summary: mixed, pins: mixed} $report the screenshot part in the shape
     *                                                                                  NewTicketReport takes, unchecked
     */
    public function __construct(
        public string $teamId = '',
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
        // Read raw for the screenshot part, as TicketFormInput does: NewTicketReport refuses a wrong shape with a message.
        $posted = $request->request->all();

        return new self(
            strtolower($request->request->getString(ReportController::TEAM_FIELD)),
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

    public function isNotSure(): bool
    {
        return self::NO_TEAM === $this->teamId;
    }

    /**
     * The pin notes as posted, in the order of their number, for the check reasons; anything that is not a note counts
     * as an empty one, as NewTicketReport refuses a wrong shape anyway.
     *
     * @return list<string>
     */
    public function pinNotes(): array
    {
        if ('' === $this->report['media_id'] || !\is_array($this->report['pins'])) {
            return [];
        }
        $pins = $this->report['pins'];
        ksort($pins);

        return array_values(array_map(static fn (mixed $pin): string => \is_array($pin) && \is_string($pin['note'] ?? null) ? $pin['note'] : '', $pins));
    }

    /**
     * The radio groups, of "type" and "priority", that hold a value other than their default: the person's own picks,
     * which Assist's check does not change.
     *
     * @return list<string>
     */
    public function personPickedFields(): array
    {
        return new TicketFormInput(type: $this->type, priority: $this->priority)->personPickedFields();
    }
}
