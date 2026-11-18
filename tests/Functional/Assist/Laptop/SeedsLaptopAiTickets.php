<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist\Laptop;

use App\Assist\UI\Controller\LaptopController;
use App\Identity\Domain\User;
use App\Organization\Api\OrganizationPermission;
use App\Organization\UI\Voter\OrganizationVoter;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Acme Foods and Beta Bakery for the laptop AI routes, both running AI on laptops, committed through LogsInUsers'
 * committedAs. Lena leads the Shop and Mobile teams, Omar is a member of Shop and Mia of Mobile; Sara is Acme Foods'
 * admin. Raj is a member of Beta Bakery only. SHOP-1 is open to everyone in Acme Foods, SHOP-2 to the Shop team only;
 * Lena reported both. The using test also uses CreatesAcmeFoodsTeams, CreatesTeams, LogsInUsers (tearDown renamed to
 * removeUsersAndOrganizations), IssuesCsrfTokens and StoresAiSettings, and calls removeLaptopAiRows in its tearDown.
 */
trait SeedsLaptopAiTickets
{
    private const string NOW = '2026-11-12 12:00:20 UTC';
    private const string PACKAGE_PATH = '/ai/laptop/package';
    private const string RESULT_PATH = '/ai/laptop/result';
    private const string MODEL = 'qwen3:4b';
    private const string TICKET_TEXT = 'The pay button does nothing on the last step of the checkout.';

    private KernelBrowser $client;

    private MockClock $clock;

    private OrganizationId $betaBakery;

    /** @var array<string, User> by first name */
    private array $people = [];

    private string $shopTicketId;

    private string $teamOnlyTicketId;

    private function seedLaptopAiTickets(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $this->clock);

        $this->acmeFoods = $this->createOrganization('Acme Foods');
        foreach (['Lena' => '{}', 'Omar' => '{}', 'Mia' => '{}', 'Sara' => '{admin}'] as $name => $orgRoles) {
            $this->people[$name] = $this->createUserWithoutOrganization($name);
            $this->addMembership($this->people[$name], $this->acmeFoods);
            $this->setOrgRoles($this->people[$name], $this->acmeFoods, $orgRoles);
        }
        $this->shop = $this->createTeam($this->acmeFoods, 'Shop', $this->people['Lena']);
        $this->mobile = $this->createTeam($this->acmeFoods, 'Mobile', $this->people['Lena']);
        $this->addTeamRole($this->people['Omar'], $this->acmeFoods, $this->shop, 'member');
        $this->addTeamRole($this->people['Mia'], $this->acmeFoods, $this->mobile, 'member');
        $this->betaBakery = $this->createOrganization('Beta Bakery');
        $this->people['Raj'] = $this->createUserWithoutOrganization('Raj');
        $this->addMembership($this->people['Raj'], $this->betaBakery);
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());
        $this->storeAiSettings($this->betaBakery, self::laptopAi());

        $shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $stepId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));
        $this->shopTicketId = $this->addShopTicket($shopProject, $stepId, 1, 'all');
        $this->teamOnlyTicketId = $this->addShopTicket($shopProject, $stepId, 2, 'team');
    }

    /**
     * Through the migrator, as the app may not delete packages, audit entries, tickets, steps or projects.
     */
    private function removeLaptopAiRows(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ast_local_package', 'aud_event', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
    }

    /**
     * @param 'all'|'team' $visibility
     *
     * @return string the ticket's id
     */
    private function addShopTicket(string $shopProject, string $stepId, int $number, string $visibility): string
    {
        $ticketId = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $ticketId, $shopProject, $this->shop->toString(), $number, 'SHOP-'.$number,
            self::TICKET_TEXT, $stepId, $this->people['Lena']->id()->toString(), $visibility,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Checkout fails', ?, 'bug', 'major', 2, ?, ?, ?, 1, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));

        return $ticketId;
    }

    /**
     * @param list<array{string, string}> $packages each package's id and the time it was used, all for SHOP-1
     */
    private function seedUsedPackagesOf(string $person, array $packages): void
    {
        $organizationId = $this->acmeFoods->toString();
        $userId = $this->people[$person]->id()->toString();
        $ticketId = $this->shopTicketId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $userId, $ticketId, $packages): int {
            foreach ($packages as [$packageId, $usedAt]) {
                $migrator->executeStatement(
                    "INSERT INTO ast_local_package (organization_id, id, user_id, ticket_id, purpose, prompt_version, created_at, expires_at, used_at, result_text, model)
                     VALUES (?, ?, ?, ?, 'summary', 'summary.v1', CAST(? AS timestamptz), CAST(? AS timestamptz) + interval '5 minutes', CAST(? AS timestamptz), 'An earlier answer.', 'qwen3:4b')",
                    [$organizationId, $packageId, $userId, $ticketId, $usedAt, $usedAt, $usedAt],
                );
            }

            return \count($packages);
        });
    }

    private function postPackageRequest(string $body, string $token): void
    {
        $this->postJson(self::PACKAGE_PATH, $body, $token);
    }

    private function postResult(string $body, string $token): void
    {
        $this->postJson(self::RESULT_PATH, $body, $token);
    }

    private function postJson(string $path, string $body, string $token): void
    {
        $this->client->request('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: $body);
    }

    private static function packageRequest(string $ticketId, string $feature = 'summary'): string
    {
        return json_encode(['feature' => $feature, 'ticketId' => $ticketId], \JSON_THROW_ON_ERROR);
    }

    private static function resultBody(string $packageId, string $text = 'Pay does nothing on the last step.', string $model = self::MODEL): string
    {
        return json_encode(['packageId' => $packageId, 'model' => $model, 'text' => $text], \JSON_THROW_ON_ERROR);
    }

    /**
     * Logs $person in, asks for a summary package of $ticketId and answers its id.
     */
    private function packageIdOf(string $person, string $ticketId): string
    {
        $this->logIn($this->client, $this->people[$person]);
        $this->postPackageRequest(self::packageRequest($ticketId), $this->laptopToken());
        self::assertResponseStatusCodeSame(200);
        $packageId = $this->answer()['packageId'] ?? null;
        self::assertIsString($packageId);

        return $packageId;
    }

    /**
     * The organization voter as it answers for a membership removed after EnterChosenOrganization let the request in,
     * which no test request can time: it denies VIEW, ADMIN and INVITE on every organization, the attributes the real
     * voter decides, and stays out of every other vote, as the real one does. A membership removed before the
     * request never reaches the controller, as EnterChosenOrganization sends the person to the picker. The client's
     * kernel is booted again first, keeping its session, as the voter is built by the first request and cannot be
     * replaced after it.
     */
    private function replaceOrganizationVoterWithOneThatFindsNoMembership(): void
    {
        $kernel = $this->client->getKernel();
        $kernel->shutdown();
        $kernel->boot();
        self::getContainer()->set('clock', $this->clock);
        self::getContainer()->set(OrganizationVoter::class, new class extends Voter {
            protected function supports(string $attribute, mixed $subject): bool
            {
                return $subject instanceof OrganizationId && \in_array(
                    $attribute,
                    [OrganizationPermission::VIEW, OrganizationPermission::ADMIN, OrganizationPermission::INVITE],
                    true,
                );
            }

            protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
            {
                return false;
            }
        });
    }

    private function laptopToken(): string
    {
        return $this->csrfTokenFor($this->client, LaptopController::CSRF_ID);
    }

    /**
     * @return array<mixed>
     */
    private function answer(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 4, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        return $answer;
    }

    /**
     * @return list<array<string, mixed>> the packages stored in the organization, by id: id, user_id, ticket_id,
     *                                    expires_at and used_at (UTC), result_text, model
     */
    private function packageRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use (&$rows): int {
            $rows = $migrator->fetchAllAssociative(
                "SELECT id, user_id, ticket_id,
                        to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at,
                        to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS used_at,
                        result_text, model
                 FROM ast_local_package ORDER BY id",
            );

            return \count($rows);
        });

        return $rows;
    }

    /**
     * @return list<array{string|null, string, string|null, array<string, mixed>}> the organization's audit entries,
     *                                                                             oldest first: actor, action,
     *                                                                             target, data
     */
    private function auditRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use (&$rows): int {
            $rows = $migrator->fetchAllNumeric('SELECT actor_user_id, action, target_id, data::text FROM aud_event ORDER BY at, id');

            return \count($rows);
        });

        return array_map(static function (array $row): array {
            [$actorUserId, $action, $targetId, $data] = $row;
            \assert((null === $actorUserId || \is_string($actorUserId)) && \is_string($action) && (null === $targetId || \is_string($targetId)) && \is_string($data));
            /** @var array<string, mixed> $decoded a JSON object, as DbalAuditLog writes */
            $decoded = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);

            return [$actorUserId, $action, $targetId, $decoded];
        }, $rows);
    }

    private function idOf(string $person): string
    {
        return $this->people[$person]->id()->toString();
    }
}
