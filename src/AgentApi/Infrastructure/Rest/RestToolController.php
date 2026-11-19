<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Rest;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\Tool\AddNotebookScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\AddReportScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\AddScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateNotebook;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateNotebookUpload;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateReport;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateReportUpload;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateUpload;
use App\AgentApi\Infrastructure\Mcp\Tool\ListMyTickets;
use App\AgentApi\Infrastructure\Mcp\ToolCallGate;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Api\CurrentApiToken;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST /api/v1 for the browser extension (P09.T7, FR-MCP-10): each endpoint runs the MCP tool of the same action
 * through ToolCallGate, so it has the same scope, input schema, voter, savepoint and audit row as the tool. A refusal is
 * a JSON {error, outcome} with 404 (not found), 403 (missing scope), 409 (read-only) or 422 (invalid input, refused). A
 * POST may carry an Idempotency-Key: the first answer is stored with the request's writes and replayed for the same
 * request, and the same key for another request is refused. Holds no state (ADR-0008).
 */
#[AsController]
final readonly class RestToolController
{
    public const string INVALID_BODY = 'The body must be a JSON object.';
    public const string INVALID_IDEMPOTENCY_KEY = 'An Idempotency-Key is 1 to 100 letters, digits, dots, colons, dashes or underscores.';
    public const string UPLOAD_NEEDS_ONE_TARGET = 'Name either "ticket" (your draft round on it) or "project" (a new report), not both.';
    public const string IDEMPOTENCY_HEADER = 'Idempotency-Key';
    public const string REPLAYED_HEADER = 'Idempotent-Replayed';

    /** @var array<string, AgentTool> */
    private array $toolsByName;

    /**
     * @param iterable<AgentTool> $tools
     */
    public function __construct(
        #[AutowireIterator(AgentTool::TAG)]
        iterable $tools,
        private ToolCallGate $toolCallGate,
        private IdempotencyKeys $idempotencyKeys,
        private CurrentApiToken $currentApiToken,
        private ClockInterface $clock,
    ) {
        $toolsByName = [];
        foreach ($tools as $tool) {
            $toolsByName[$tool->definition()->name] = $tool;
        }
        $this->toolsByName = $toolsByName;
    }

    /**
     * {ticket, mime, bytes, sha256} begins an upload into the caller's draft round (create_upload, rounds:write);
     * {project, mime, bytes, sha256} one for a new report (create_report_upload, reports:write).
     */
    #[Route('/api/v1/uploads', name: 'api_v1_uploads', methods: ['POST'])]
    public function uploads(Request $request): Response
    {
        return $this->idempotent($request, function (array $body): Response {
            $hasTicket = \array_key_exists('ticket', $body);
            if ($hasTicket === \array_key_exists('project', $body)) {
                return self::error(self::UPLOAD_NEEDS_ONE_TARGET, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($hasTicket) {
                $body['key'] = $body['ticket'];
                unset($body['ticket']);

                return $this->run(CreateUpload::NAME, $body, Response::HTTP_CREATED);
            }

            return $this->run(CreateReportUpload::NAME, $body, Response::HTTP_CREATED);
        });
    }

    /**
     * The same report draft as create_report (ADR-0039): never a ticket.
     */
    #[Route('/api/v1/reports', name: 'api_v1_reports', methods: ['POST'])]
    public function reports(Request $request): Response
    {
        return $this->idempotent($request, fn (array $body): Response => $this->run(CreateReport::NAME, $body, Response::HTTP_CREATED));
    }

    /**
     * One more picture at the end of the caller's own report draft (add_report_screenshot, reports:write), uploaded
     * through /api/v1/uploads with the draft's project, as for /api/v1/reports.
     */
    #[Route('/api/v1/reports/{id}/screenshots', name: 'api_v1_report_screenshots', methods: ['POST'])]
    public function reportScreenshots(string $id, Request $request): Response
    {
        return $this->idempotent($request, fn (array $body): Response => $this->run(AddReportScreenshot::NAME, [...$body, 'report' => $id], Response::HTTP_OK));
    }

    #[Route('/api/v1/tickets/{key}/draft/screenshots', name: 'api_v1_draft_screenshots', methods: ['POST'])]
    public function draftScreenshots(string $key, Request $request): Response
    {
        return $this->idempotent($request, fn (array $body): Response => $this->run(AddScreenshot::NAME, [...$body, 'key' => $key], Response::HTTP_OK));
    }

    /**
     * A new, empty notebook of the caller (create_notebook, notebooks:write).
     */
    #[Route('/api/v1/notebooks', name: 'api_v1_notebooks', methods: ['POST'])]
    public function notebooks(Request $request): Response
    {
        return $this->idempotent($request, fn (array $body): Response => $this->run(CreateNotebook::NAME, $body, Response::HTTP_CREATED));
    }

    /**
     * {mime, bytes, sha256} begins an upload for the caller's own notebook (create_notebook_upload, notebooks:write);
     * apart from /api/v1/uploads, whose body names a ticket or a project, as the notebook is in the path here.
     */
    #[Route('/api/v1/notebooks/{id}/uploads', name: 'api_v1_notebook_uploads', methods: ['POST'])]
    public function notebookUploads(string $id, Request $request): Response
    {
        return $this->idempotent($request, fn (array $body): Response => $this->run(CreateNotebookUpload::NAME, [...$body, 'notebook' => $id], Response::HTTP_CREATED));
    }

    #[Route('/api/v1/notebooks/{id}/screenshots', name: 'api_v1_notebook_screenshots', methods: ['POST'])]
    public function notebookScreenshots(string $id, Request $request): Response
    {
        return $this->idempotent($request, fn (array $body): Response => $this->run(AddNotebookScreenshot::NAME, [...$body, 'notebook' => $id], Response::HTTP_OK));
    }

    #[Route('/api/v1/me/tickets', name: 'api_v1_my_tickets', methods: ['GET'])]
    public function myTickets(Request $request): Response
    {
        $arguments = $request->query->has('limit') ? ['limit' => $request->query->getInt('limit')] : [];

        return $this->run(ListMyTickets::NAME, $arguments, Response::HTTP_OK);
    }

    /**
     * @param \Closure(array<string, mixed>): Response $handle
     */
    private function idempotent(Request $request, \Closure $handle): Response
    {
        $body = json_decode($request->getContent(), true, 32);
        if (!\is_array($body) || array_is_list($body) && [] !== $body) {
            return self::error(self::INVALID_BODY, Response::HTTP_BAD_REQUEST);
        }
        /** @var array<string, mixed> $body */
        $keys = $request->headers->all(self::IDEMPOTENCY_HEADER);
        if ([] === $keys) {
            return $handle($body);
        }
        $key = (string) $keys[0];
        if (1 !== \count($keys) || 1 !== preg_match(IdempotencyKeys::KEY_PATTERN, $key)) {
            return self::error(self::INVALID_IDEMPOTENCY_KEY, Response::HTTP_BAD_REQUEST);
        }
        $access = $this->currentApiToken->access() ?? throw new \LogicException('A request to /api/v1 passed the api_token firewall without a personal access token.');
        $requestHash = hash('sha256', $request->getMethod()."\n".$request->getPathInfo()."\n".$request->getContent(), true);
        $stored = $this->idempotencyKeys->reserve($access, $key, $requestHash, $this->clock->now());
        if (null !== $stored) {
            return JsonResponse::fromJsonString($stored->body, $stored->status, $stored->isReplay ? [self::REPLAYED_HEADER => 'true'] : []);
        }
        $response = $handle($body);
        $this->idempotencyKeys->answer($access, $key, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function run(string $toolName, array $arguments, int $successStatus): Response
    {
        $tool = $this->toolsByName[$toolName] ?? throw new \LogicException(\sprintf('No tool is named %s.', $toolName));
        try {
            $toolAnswer = $this->toolCallGate->call($tool, $arguments);
        } catch (ToolRefusal $refusal) {
            return new JsonResponse(['error' => $refusal->getMessage(), 'outcome' => $refusal->outcome->value], self::statusOf($refusal->outcome));
        }

        return new JsonResponse($toolAnswer->structuredContent ?? [], $successStatus);
    }

    private static function statusOf(ToolCallOutcome $outcome): int
    {
        return match ($outcome) {
            ToolCallOutcome::NotFound => Response::HTTP_NOT_FOUND,
            ToolCallOutcome::MissingScope => Response::HTTP_FORBIDDEN,
            ToolCallOutcome::ReadOnly => Response::HTTP_CONFLICT,
            ToolCallOutcome::InvalidInput, ToolCallOutcome::Refused => Response::HTTP_UNPROCESSABLE_ENTITY,
            ToolCallOutcome::Ok, ToolCallOutcome::Error => throw new \LogicException('A refusal has no ok or error outcome.'),
        };
    }

    private static function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
