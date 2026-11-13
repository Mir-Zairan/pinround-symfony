<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\Organization\Api\TeamPermission;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\UI\RailTeamChoice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class RailTeamChoiceTest extends TestCase
{
    private const string ORGANIZATION = '0190a000-0000-7000-8000-000000000001';
    private const string OMAR = '0190a000-0000-7000-8000-00000000000b';
    private const string SHOP_TEAM = '0190a000-0000-7000-8000-0000000000a1';
    private const string APP_TEAM = '0190a000-0000-7000-8000-0000000000a2';
    private const string WAREHOUSE_TEAM = '0190a000-0000-7000-8000-0000000000a3';

    #[Test]
    public function theProjectsTeamWinsWhenTheViewerMaySeeIt(): void
    {
        $choice = self::choiceSeeing([self::SHOP_TEAM, self::APP_TEAM]);

        $teamId = $choice->teamOf(self::omarIn([self::APP_TEAM]), self::projectOf(self::SHOP_TEAM), new TeamId(self::APP_TEAM));

        self::assertSame(self::SHOP_TEAM, $teamId?->toString());
    }

    #[Test]
    public function thePickedTeamDecidesWhenTheProjectsTeamIsNotShownToTheViewer(): void
    {
        $choice = self::choiceSeeing([self::APP_TEAM]);

        $teamId = $choice->teamOf(self::omarIn([self::APP_TEAM, self::WAREHOUSE_TEAM]), self::projectOf(self::SHOP_TEAM), new TeamId(self::WAREHOUSE_TEAM));

        self::assertSame(self::WAREHOUSE_TEAM, $teamId?->toString());
    }

    #[Test]
    public function thePickedTeamDecidesForAProjectNoTeamOwns(): void
    {
        $choice = self::choiceSeeing([self::APP_TEAM, self::WAREHOUSE_TEAM]);

        $teamId = $choice->teamOf(self::omarIn([self::APP_TEAM, self::WAREHOUSE_TEAM]), self::projectOf(null), new TeamId(self::WAREHOUSE_TEAM));

        self::assertSame(self::WAREHOUSE_TEAM, $teamId?->toString());
    }

    #[Test]
    public function allProjectsWithNoPickGetTheViewersFirstTeamById(): void
    {
        $choice = self::choiceSeeing([self::APP_TEAM, self::WAREHOUSE_TEAM]);

        $teamId = $choice->teamOf(self::omarIn([self::WAREHOUSE_TEAM, self::APP_TEAM]), null, null);

        self::assertSame(self::APP_TEAM, $teamId?->toString());
    }

    #[Test]
    public function aViewerInNoTeamWhomTheProjectsTeamIsNotShownToGetsNoTeam(): void
    {
        $choice = self::choiceSeeing([]);

        self::assertNull($choice->teamOf(self::omarIn([]), self::projectOf(self::SHOP_TEAM), null));
        self::assertNull($choice->teamOf(self::omarIn([]), null, null));
    }

    /**
     * @param list<string> $visibleTeamIds the teams TeamPermission::VIEW grants; every other attribute or subject is denied
     */
    private static function choiceSeeing(array $visibleTeamIds): RailTeamChoice
    {
        return new RailTeamChoice(new class($visibleTeamIds) implements AuthorizationCheckerInterface {
            /** @param list<string> $visibleTeamIds */
            public function __construct(private readonly array $visibleTeamIds)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return TeamPermission::VIEW === $attribute
                    && $subject instanceof TeamId
                    && \in_array($subject->toString(), $this->visibleTeamIds, true);
            }
        });
    }

    /**
     * @param list<string> $teamIds teams Omar is a member of, in any order
     */
    private static function omarIn(array $teamIds): ActorContext
    {
        return new ActorContext(
            new UserId(self::OMAR),
            new OrganizationId(self::ORGANIZATION),
            new Roles(false, false, [], array_map(static fn (string $teamId): TeamId => new TeamId($teamId), $teamIds), []),
        );
    }

    private static function projectOf(?string $teamId): ProjectChoice
    {
        return new ProjectChoice('0190a000-0000-7000-8000-0000000000b1', 'SHOP', 'Shop', null === $teamId ? null : new TeamId($teamId));
    }
}
