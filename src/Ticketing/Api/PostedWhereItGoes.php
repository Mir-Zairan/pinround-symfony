<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * Report a problem's "Where it goes" as a form posted it, nothing checked yet (report-a-problem.md, Where it goes): the
 * team, the person "Give it to" names, the project, the type and the priority. Report a problem and a report draft's
 * page post it under the same field names. Ids are in lowercase.
 */
final readonly class PostedWhereItGoes
{
    public const string TEAM_FIELD = 'team_id';
    public const string PROJECT_FIELD = 'project_id';
    public const string ASSIGNEE_FIELD = 'assignee_id';
    public const string TYPE_FIELD = 'type';
    public const string PRIORITY_FIELD = 'priority';
    /** The team id of "Not sure". */
    public const string NO_TEAM = 'none';
    public const string DEFAULT_TYPE = 'bug';
    public const string DEFAULT_PRIORITY = 'normal';

    /**
     * @param string $teamId     a team's uuid, or NO_TEAM for "Not sure"
     * @param string $assigneeId a person's uuid, or empty for "The team (<lead> decides)"
     */
    public function __construct(
        public string $teamId = '',
        public string $projectId = '',
        public string $assigneeId = '',
        public string $type = self::DEFAULT_TYPE,
        public string $priority = self::DEFAULT_PRIORITY,
    ) {
    }

    public function isNotSure(): bool
    {
        return self::NO_TEAM === $this->teamId;
    }
}
