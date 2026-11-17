<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Tracing\SpanAttributes;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesTeams;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The phase criterion "a trace shows one span per DB query for GET /", through the real kernel (ADR-0029).
 */
final class DatabaseSpanTest extends WebTestCase
{
    use CreatesTeams;
    use Factories;
    use LogsInUsers;

    #[Test]
    public function aRealQueryInsideTheRequestGetsItsOwnSpanBetweenBeginAndCommit(): void
    {
        $client = self::createClient();
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        $client->request('GET', '/_test/tracing/query');

        self::assertResponseStatusCodeSame(200);
        $spans = ExportedSpans::of($exporter);
        $roots = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => !SpanContextValidator::isValidSpanId($span->getParentSpanId())));
        self::assertCount(1, $roots);
        $children = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => $span !== $roots[0]));
        self::assertSame(['db BEGIN', 'db SELECT', 'db COMMIT'], array_map(static fn (SpanDataInterface $span): string => $span->getName(), $children));
        foreach ($children as $child) {
            self::assertSame($roots[0]->getSpanId(), $child->getParentSpanId());
        }
    }

    #[Test]
    public function theManagerHomeRunsOneTeamQueryAndNoFocusQuery(): void
    {
        // As the work Home before its focus query (login, organizations, BEGIN, the roles reads), then
        // every team in one query (allSummariesOf), then the header's Local AI chip's AI settings (LocalAiChip).
        self::assertSame(
            ['db SELECT', 'db SELECT', 'db SELECT', 'db BEGIN', 'db SELECT', 'db SELECT', 'db SELECT', 'db SELECT', 'db SELECT', 'db COMMIT'],
            $this->spanNamesOfHomeFor('{manager}'),
        );
    }

    #[Test]
    public function theAdminHomeRunsOneTeamCountAndNoFocusQuery(): void
    {
        // As the work Home before its focus query, then the team count (countOf). An admin with no team or manager role
        // gets no Local AI chip, so the header reads no AI settings.
        self::assertSame(
            ['db SELECT', 'db SELECT', 'db SELECT', 'db BEGIN', 'db SELECT', 'db SELECT', 'db SELECT', 'db SELECT', 'db COMMIT'],
            $this->spanNamesOfHomeFor('{admin}'),
        );
    }

    /**
     * @return list<string> the names of the spans under the request's, in order
     */
    private function spanNamesOfHomeFor(string $orgRoles): array
    {
        $client = self::createClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $organizationId = new OrganizationId($this->createdOrganizationIds[0]);
        $this->setOrgRoles($user, $organizationId, $orgRoles);
        // Three teams, so a query per team would add spans.
        $lead = $this->createUserWithoutOrganization('Lena Berg');
        foreach (['Shop', 'Mobile', 'Support'] as $teamName) {
            $this->createTeam($organizationId, $teamName, $lead);
        }
        $this->logIn($client, $user);
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        $spans = ExportedSpans::of($exporter);
        $roots = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => !SpanContextValidator::isValidSpanId($span->getParentSpanId())));
        self::assertCount(1, $roots);

        return array_values(array_map(
            static fn (SpanDataInterface $span): string => $span->getName(),
            array_filter($spans, static fn (SpanDataInterface $span): bool => $span !== $roots[0]),
        ));
    }

    #[Test]
    public function theHomePageExportsOneChildSpanPerStatementItSendsUnderTheRequestSpan(): void
    {
        $client = self::createClient();
        $this->logIn($client, $this->createUserWithPassword('Lena Berg'));
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        $spans = ExportedSpans::of($exporter);
        $roots = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => !SpanContextValidator::isValidSpanId($span->getParentSpanId())));
        self::assertCount(1, $roots);
        $children = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => $span !== $roots[0]));
        // The user and their password, the user's organizations (resolve_user_orgs), then BEGIN with the tenant's set_config,
        // then the user's org roles and team roles for the header's menu (MainMenu), then the saved Home focus chip, then the
        // AI settings for the header's Local AI chip (LocalAiChip).
        self::assertSame(['db SELECT', 'db SELECT', 'db SELECT', 'db BEGIN', 'db SELECT', 'db SELECT', 'db SELECT', 'db SELECT', 'db SELECT', 'db COMMIT'], array_map(static fn (SpanDataInterface $span): string => $span->getName(), $children));
        foreach ($children as $child) {
            self::assertSame($roots[0]->getTraceId(), $child->getTraceId());
            self::assertSame($roots[0]->getSpanId(), $child->getParentSpanId());
            self::assertSame('postgresql', $child->getAttributes()->get(SpanAttributes::DB_SYSTEM_NAME));
            self::assertSame(substr($child->getName(), 3), $child->getAttributes()->get(SpanAttributes::DB_OPERATION_NAME));
            self::assertCount(2, $child->getAttributes());
        }
    }
}
