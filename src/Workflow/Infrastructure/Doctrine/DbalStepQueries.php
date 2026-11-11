<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Doctrine;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\ListedStepView;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\StepId;
use App\Workflow\Domain\StepOrigin;
use Doctrine\DBAL\Connection;

final readonly class DbalStepQueries implements StepQueries
{
    public function __construct(
        private Connection $connection,
        private SettingsQueries $settingsQueries,
        private TenantContext $tenantContext,
    ) {
    }

    public function stepsFor(TeamId $teamId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, grp, position, origin, core_code FROM wf_step'
            .' WHERE archived_at IS NULL AND (team_id IS NULL OR team_id = ?)'
            .' ORDER BY '.self::orderSql('grp', Group::inListOrder()).', '.self::orderSql('origin', StepOrigin::cases()).', position, id',
            [$teamId->toString()],
        );

        return array_map(self::stepView(...), $rows);
    }

    public function moveTargetsFor(TeamId $teamId): array
    {
        return $this->moveTargetsUnder($teamId, $this->settingsQueries->stepPolicyOf($this->tenantContext->require()));
    }

    /**
     * moveTargetsFor with the step policy already read, so a caller that keys a cache on the policy stores what it keyed.
     *
     * @return list<StepView>
     */
    public function moveTargetsUnder(TeamId $teamId, StepPolicy $stepPolicy): array
    {
        $steps = $this->stepsFor($teamId);
        if (StepPolicy::Locked !== $stepPolicy) {
            return $steps;
        }

        return array_values(array_filter(
            $steps,
            static fn (StepView $step): bool => StepOrigin::Team->value !== $step->origin(),
        ));
    }

    public function listedSteps(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, grp, archived_at IS NOT NULL AS is_archived FROM wf_step WHERE organization_id = ?'
            .' ORDER BY '.self::orderSql('grp', Group::inListOrder()).', '.self::orderSql('origin', StepOrigin::cases()).', position, id',
            [$this->tenantContext->require()->toString()],
        );

        return array_map(static function (array $row): ListedStepView {
            $group = Group::from(self::text($row['grp'], 'group'));

            return new ListedStepView(
                self::text($row['id'], 'id'),
                self::text($row['name'], 'name'),
                $group->value,
                $group->label(),
                $group->listHint(),
                true === $row['is_archived'],
            );
        }, $rows);
    }

    public function groupOf(string $stepId): ?string
    {
        $groupCode = $this->connection->fetchOne('SELECT grp FROM wf_step WHERE id = ?', [new StepId($stepId)->toString()]);
        if (false === $groupCode) {
            return null;
        }

        return Group::from(self::text($groupCode, 'group'))->value;
    }

    /**
     * A CASE that gives each enum value its index in $inOrder, built from the enums so the SQL order cannot drift from
     * the order lists show (groups) or team.md names (core, company, team steps); the values are enum constants, never
     * input.
     *
     * @param list<Group>|list<StepOrigin> $inOrder
     */
    private static function orderSql(string $column, array $inOrder): string
    {
        $whens = array_map(
            static fn (Group|StepOrigin $value, int $index): string => \sprintf("WHEN '%s' THEN %d", $value->value, $index),
            $inOrder,
            array_keys($inOrder),
        );

        return \sprintf('CASE %s %s END', $column, implode(' ', $whens));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stepView(array $row): StepView
    {
        $group = Group::from(self::text($row['grp'], 'group'));
        $position = $row['position'];

        return new StepView(
            self::text($row['id'], 'id'),
            self::text($row['name'], 'name'),
            $group->value,
            $group->label(),
            \is_int($position) ? $position : throw new \LogicException('A step position from the database is not an integer.'),
            self::text($row['origin'], 'origin'),
            null === $row['core_code'] ? null : self::text($row['core_code'], 'core code'),
        );
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A step %s from the database is not a string.', $column));
    }
}
