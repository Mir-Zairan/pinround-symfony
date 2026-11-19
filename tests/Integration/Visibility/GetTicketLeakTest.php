<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\AgentApi\Infrastructure\Mcp\Tool\GetTicket;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The MCP tool get_ticket, as ToolCallGate runs it: with the viewer's personal access token in the token storage,
 * targetOf() finds the ticket by key, the voter decides on the target it names, and call() reads the page. A viewer gets
 * a cast ticket exactly when they may see it; otherwise the answer is the "not found" an unknown key gets. call() alone,
 * with the voter's decision ignored, never returns a ticket the viewer may not see either, as it reads the page under
 * the viewer's own visibility predicate.
 *
 * Beta Bakery has its own ticket with the team-only ticket's key and its own title, so its admin, Ben, gets that one
 * and never the marker.
 */
final class GetTicketLeakTest extends VisibilityLeakCast
{
    /** The step the cast's no-team ticket is in, which the cast itself does not add; Beta Bakery's ticket is in it too. */
    private const string TICKET_ROWS_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000f7f3';
    private const string BETA_BAKERY_TITLE = 'Beta Bakery own ticket';
    /** The title TicketRows gives the no-team ticket. */
    private const string NO_TEAM_TITLE = 'Checkout button is hidden';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            self::asTenantMigrator($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Triage', 'todo', NULL, 'company', 0)",
                [$organizationId, self::TICKET_ROWS_STEP],
            ));
        }
        $teamOnlyKey = self::keyOf(self::teamOnlyTicketId());
        $teamOnlyNumber = (int) substr($teamOnlyKey, (int) strpos($teamOnlyKey, '-') + 1);
        new TicketRows(self::migrator(), self::tenantContext())->addOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_PROJECT, self::VIEWERS['Ben'][0], $teamOnlyNumber);
        self::asTenantMigrator(self::BETA_BAKERY, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET title = ? WHERE id = ?',
            [self::BETA_BAKERY_TITLE, self::BETA_BAKERY_TICKET],
        ));
        self::ensureKernelShutdown();
    }

    /**
     * Ben asks the key in his own organization, where it names Beta Bakery's ticket.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function theTeamOnlyTicketIsReturnedOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $teamOnlyKey = self::keyOf(self::teamOnlyTicketId());
        $isOfBetaBakery = self::BETA_BAKERY === self::VIEWERS[$viewer][1];

        $answer = self::gatedAnswerAs($viewer, $teamOnlyKey);

        $expectedTitle = match (true) {
            $seesTeamOnly => self::LEAK_MARKER,
            $isOfBetaBakery => self::BETA_BAKERY_TITLE,
            default => null,
        };
        self::assertSame(
            null === $expectedTitle ? ToolRefusal::NOT_FOUND : [$teamOnlyKey, UntrustedText::wrap($expectedTitle)],
            \is_array($answer) ? [$answer['key'] ?? null, $answer['title'] ?? null] : $answer,
            \sprintf('%s gets the wrong answer for the team-only ticket.', $viewer),
        );
    }

    /**
     * Beta Bakery has no ticket with the no-team ticket's key.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function theNoTeamTicketIsReturnedOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $noTeamKey = self::keyOf(self::NO_TEAM_TICKET);

        $answer = self::gatedAnswerAs($viewer, $noTeamKey);

        self::assertSame(
            $seesNoTeamTicket ? [$noTeamKey, UntrustedText::wrap(self::NO_TEAM_TITLE)] : ToolRefusal::NOT_FOUND,
            \is_array($answer) ? [$answer['key'] ?? null, $answer['title'] ?? null] : $answer,
            \sprintf('%s gets the wrong answer for the no-team ticket.', $viewer),
        );
    }

    /**
     * Ben reads another organization, where the key names his own ticket, so he is left out here.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function acmeFoodsViewers(): iterable
    {
        foreach (self::viewers() as $case => $viewer) {
            if (self::ACME_FOODS === self::VIEWERS[$viewer[0]][1]) {
                yield $case => $viewer;
            }
        }
    }

    /**
     * The voter on targetOf()'s target and call() each refuse on their own: with the voter's decision ignored, call()
     * still reads the page under the viewer's own predicate, so a viewer who may not see the ticket gets "not found"
     * from it; a viewer who may see it gets it, so the refusal is not the only answer.
     */
    #[Test]
    #[DataProvider('acmeFoodsViewers')]
    public function theVoterAndCallEachRefuseTheTeamOnlyTicketOnTheirOwn(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $arguments = ['key' => self::keyOf(self::teamOnlyTicketId())];

        [$isGranted, $answer] = self::asViewerWithApiToken($viewer, static function (GetTicket $getTicket) use ($arguments): array {
            $target = $getTicket->targetOf($arguments);
            $isGranted = self::getContainer()->get('security.authorization_checker')->isGranted($target->voterAttribute, $target->subject);
            try {
                return [$isGranted, self::structuredContentOf($getTicket->call($arguments, $target)->structuredContent)];
            } catch (ToolRefusal $refusal) {
                return [$isGranted, $refusal->getMessage()];
            }
        });

        self::assertSame(
            [$seesTeamOnly, $seesTeamOnly ? UntrustedText::wrap(self::LEAK_MARKER) : ToolRefusal::NOT_FOUND],
            [$isGranted, \is_array($answer) ? ($answer['title'] ?? null) : $answer],
            \sprintf('%s gets the wrong voter decision or answer from call() alone.', $viewer),
        );
    }

    /**
     * targetOf(), the voter on its target, then call(), as ToolCallGate runs them.
     *
     * @return array<mixed>|string the answer's structured content, or the refusal's message
     */
    private static function gatedAnswerAs(string $viewer, string $key): array|string
    {
        return self::asViewerWithApiToken($viewer, static function (GetTicket $getTicket) use ($key): array|string {
            $arguments = ['key' => $key];
            try {
                $target = $getTicket->targetOf($arguments);
                if (!self::getContainer()->get('security.authorization_checker')->isGranted($target->voterAttribute, $target->subject)) {
                    return ToolRefusal::NOT_FOUND;
                }

                return self::structuredContentOf($getTicket->call($arguments, $target)->structuredContent);
            } catch (ToolRefusal $refusal) {
                return $refusal->getMessage();
            }
        });
    }

    /**
     * Runs the work in the viewer's rolled-back transaction with a read token of their organization in the token
     * storage, as the api_token firewall leaves it; the storage is emptied after.
     *
     * @template T
     *
     * @param \Closure(GetTicket): T $work
     *
     * @return T
     */
    private static function asViewerWithApiToken(string $viewer, \Closure $work): mixed
    {
        return self::asViewer($viewer, static function (TokenInterface $loginToken) use ($viewer, $work): mixed {
            $securityUser = $loginToken->getUser();
            \assert($securityUser instanceof SecurityUser);
            $access = new ApiTokenAccess('0197a3c0-0000-7000-8000-00000000f7a1', new OrganizationId(self::VIEWERS[$viewer][1]), ['read'], new \DateTimeImmutable('2027-04-15 10:00:00 UTC'));
            $tokenStorage = self::getContainer()->get('security.token_storage');
            $tokenStorage->setToken(new ApiTokenAuthentication($securityUser, $access, null));
            try {
                return $work(self::getContainer()->get(GetTicket::class));
            } finally {
                $tokenStorage->setToken(null);
            }
        });
    }

    /**
     * @return array<mixed>
     */
    private static function structuredContentOf(mixed $structuredContent): array
    {
        self::assertIsArray($structuredContent);

        return $structuredContent;
    }

    /**
     * Read under Acme Foods as tenant, as row-level security binds the migrator too.
     */
    private static function keyOf(string $ticketId): string
    {
        $key = null;
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($ticketId, &$key): void {
            $key = $migrator->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [$ticketId]);
        });
        \assert(\is_string($key));

        return $key;
    }
}
