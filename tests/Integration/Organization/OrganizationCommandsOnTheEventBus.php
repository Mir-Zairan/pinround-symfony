<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Identity\Infrastructure\Doctrine\DbalUserQueries;
use App\Organization\Application\AddMember;
use App\Organization\Application\CreateOrganization;
use App\Organization\Application\CreateTeam;
use App\Organization\Application\InviteMember;
use App\Organization\Application\SetOrgRoles;
use App\Organization\Application\SetTeamRole;
use App\Organization\Domain\Job;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\TeamRole;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\Organization\Infrastructure\Doctrine\DoctrineInvitations;
use App\Organization\Infrastructure\Doctrine\DoctrineMemberships;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizations;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\Organization\Infrastructure\Doctrine\DoctrineTeams;
use App\Organization\Infrastructure\Messenger\MessengerOrganizationEvents;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Cache\AfterCommitTagInvalidation;
use App\Tests\Unit\Organization\Application\RecordingInvitedPersonRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The Organization commands wired as the app wires them, publishing on the real event.bus. Nothing in src calls them
 * yet, so the container has removed them; this builds them from the same parts. CreateOrganization gets no setup
 * steps: the tests that use this clean up with OrganizationRows, which leaves wf_ rows behind, and none of them reads
 * a step or a rule set.
 */
final readonly class OrganizationCommandsOnTheEventBus
{
    public const string SARA = '0197a3c0-0000-7000-8000-000000000756';
    public const string LENA = '0197a3c0-0000-7000-8000-000000000752';
    public const string OMAR = '0197a3c0-0000-7000-8000-000000000753';

    public function __construct(private EntityManagerInterface $entityManager, private MessageBusInterface $eventBus)
    {
    }

    /**
     * Creates the organization with Sara as first admin (by the system), adds Lena as admin and Omar as plain member,
     * creates the team Shop led by Lena, adds Omar to it and gives Lena Manager too, all as Sara after the first step:
     * three MemberJoined, one TeamCreated, one TeamRoleChanged and one OrgRolesChanged, in that order. Call it in a
     * transaction begun under $organizationId.
     */
    public function setUpShop(string $organizationId, string $slug, \DateTimeImmutable $now): void
    {
        $events = new MessengerOrganizationEvents($this->eventBus, $this->entityManager->getConnection(), self::teamPeopleCache());
        $memberships = new DoctrineMemberships($this->entityManager);
        $teams = new DoctrineTeams($this->entityManager);
        $organization = new OrganizationId($organizationId);
        $sara = new UserId(self::SARA);

        new CreateOrganization(new DoctrineOrganizations($this->entityManager), $memberships, new DoctrineOrgSettingsStore($this->entityManager), $events, [])(
            $organization,
            'Acme Foods',
            $slug,
            $sara,
            null,
            $now,
        );
        $addMember = new AddMember($memberships, $events);
        $addMember($organization, new UserId(self::LENA), new OrgRoles(OrgRole::Admin), $sara, $now);
        $addMember($organization, new UserId(self::OMAR), new OrgRoles(), $sara, $now);
        $teamId = new CreateTeam($teams, $memberships, $events)($organization, 'Shop', new UserId(self::LENA), Job::Developer, $sara, $now);
        new SetTeamRole($teams, $memberships, $events)($teamId, new UserId(self::OMAR), TeamRole::Member, Job::Developer, $sara, $now);
        new SetOrgRoles($memberships, $events)($organization, new UserId(self::LENA), new OrgRoles(OrgRole::Admin, OrgRole::Manager), $sara, $now);
    }

    /**
     * Sara invites the email with no roles and no team: one MemberInvited. Call it in a transaction begun under
     * $organizationId.
     *
     * @return string the invitation id
     */
    public function invite(string $organizationId, string $email, \DateTimeImmutable $now): string
    {
        $connection = $this->entityManager->getConnection();
        $inviteMember = new InviteMember(
            new DoctrineInvitations($this->entityManager),
            new DoctrineMemberships($this->entityManager),
            new DoctrineTeams($this->entityManager),
            new DbalUserQueries($connection),
            new RecordingInvitedPersonRegistration(),
            new MessengerOrganizationEvents($this->eventBus, $connection, self::teamPeopleCache()),
        );

        return $inviteMember(new OrganizationId($organizationId), $email, new OrgRoles(), null, null, new UserId(self::SARA), $now)->toString();
    }

    /**
     * A cache of its own: these commands are not read back through the team people cache.
     */
    private static function teamPeopleCache(): TeamPeopleCache
    {
        return new TeamPeopleCache(new TagAwareAdapter(new ArrayAdapter()), new AfterCommitTagInvalidation(new NullLogger()));
    }
}
