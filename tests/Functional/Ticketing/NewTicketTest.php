<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The new ticket form at /tickets/new on Acme Foods' team Shop through the real firewall, voters, session and database.
 * Test organizations have no core steps, so each test inserts Backlog and To do.
 */
final class NewTicketTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NEW_TICKET = '/tickets/new';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
    }

    protected function tearDown(): void
    {
        $this->removeCommittedTickets();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aMemberCreatesATicketAndLandsOnTheListWithAToast(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreSteps();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->logIn($this->client, $people['Omar']);

        $this->client->request('GET', self::NEW_TICKET);
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Make ticket', [
            'project_id' => $projectId,
            'title' => '  Checkout button does nothing ',
            'description' => 'Tapping Pay on the cart page shows no answer.',
            'type' => 'bug',
            'priority' => 'major',
            'assignee_id' => $people['Omar']->id()->toString(),
        ]);

        self::assertResponseRedirects('/_test/menu/tickets?project=SHOP', 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Ticket SHOP-1 made.', $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame([[
            'key' => 'SHOP-1',
            'title' => 'Checkout button does nothing',
            'type' => 'bug',
            'priority' => 'major',
            'assignee_id' => $people['Omar']->id()->toString(),
            'reporter_id' => $people['Omar']->id()->toString(),
        ]], $this->committedTickets());
    }

    /**
     * Backlog and To do as core steps of Acme Foods, which CreateTicket needs.
     */
    private function addCoreSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId): int {
            foreach ([['Backlog', 'backlog', 0], ['To do', 'todo', 1]] as [$name, $coreCode, $position]) {
                $migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, 'todo', ?, 'core', ?)",
                    [$organizationId, Uuid::v7()->toRfc4122(), $name, $coreCode, $position],
                );
            }

            return 0;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function committedTickets(): array
    {
        $tickets = [];
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$tickets): int {
            $tickets = $migrator->fetchAllAssociative(
                'SELECT key, title, type, priority, assignee_id, reporter_id FROM tkt_ticket WHERE organization_id = ? ORDER BY number',
                [$organizationId],
            );

            return 0;
        });

        return $tickets;
    }

    /**
     * Through the migrator, as the app may not delete tickets or steps; the ticket's turn row goes with it.
     */
    private function removeCommittedTickets(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM tkt_ticket WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
    }
}
