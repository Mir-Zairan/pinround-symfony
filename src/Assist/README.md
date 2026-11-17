# Assist

AI help that only suggests: summaries, explanations, intake suggestions and prompt packages for laptop AI. A person always clicks to apply. It owns the tables `ast_explanation`, `ast_suggestion`, `ast_embedding`, `ast_usage` and `ast_local_package`. Its Api is `AiPolicy`, `Summaries`, `Explanations`, `IntakeAssist` and `LocalAi`. It may use the Api of Ticketing, Review and Organization.

## Built (phase 08, PR 1)

- **Api:** `AiPolicy` with `PolicyView` (the organization's AI policy for a person, with the one line that says why not); `Summaries` with `SummaryRequestView` and `SummaryRequestRefused` ("Summarize with AI": `request` queues a `GenerateSummary` job, `latestRequestOf` reads a queued or failed request for the page that polls).
- **Domain:** `AiFeature` (`summary`, `explain`, `intake`).
- **Application:** `AiGateway`, the one door to server AI, implemented by `Gateway\GuardedAiGateway` (policy through `Gateway\AiTaskAdmission` and `Policy\PolicyEvaluator`, the ticket through the `Gateway\AskedTicketQueries` port, limit, provider, answer check, metering); an intake task carries an `AiDraft` instead of a ticket, which the admission keeps from cloud AI when Ticketing's `DraftReportChecks` finds security words, and sends with the open duplicate candidates the reporter may see (Ticketing's `TicketVisibilities` and `IntakeCandidateQueries`; team-only ones only with the admin's opt-in), whose keys alone survive the answer check; the ports `AiProvider`, `PromptRenderer` (with `PromptVersion`), `AiAnswerCheck` (rejecting with `AiAnswerRejected`), `Metering` and `AiCallLimit` (30 calls an hour per person); `Summaries\QueuedSummaries` with the ports `SummaryRequests` and `SummaryJobQueue` and the messages `GenerateSummary` and `EndSummaryRequest`.
- **Infrastructure:** `Platform` (`SymfonyAiProvider`, the only Symfony AI user, and `FakeProvider` for tests); `Prompt` (`PromptBuilder`, `DelimitedTicketData`, `UntrustedText`, `OutputValidator` and the templates `summary.v1` and `intake.v1` with its schema); `Doctrine\DbalMetering` and the `ast_usage` partitions (`Scheduler\AddUsagePartitions`, `Scheduler\DropExpiredUsagePartitions`, `Health\NextUsagePartitionExists`); `RateLimit\SymfonyAiCallLimit`; `Messenger` (`GenerateSummaryHandler` on `async_ai`, `EndSummaryRequestHandler`, `MessengerSummaryJobQueue`); `Valkey\ValkeySummaryRequests`, the request state on the state Valkey. An answered summary is stored by Ticketing through `TicketSummaryCommands::storeAiSummary`.
- **UI:** `LocalAiChip` (who gets the header's "Local AI" chip and its sheet, and the `connect-src` to Ollama on those pages only) and `Controller\LaptopController` (`POST /ai/laptop/status`, 204, stores nothing).
- **Tables:** `ast_usage` only.

## Built (phase 08, PR 2)

- **Intake path ("Check with AI", backend only):** the Api `IntakeAssist` with `DraftReport`, `IntakeCheckId`, `IntakeCheckView`, `IntakeSuggestion` and `IntakeCheckRefused`. `Application\IntakeAssistService` refuses an over-cap draft, a non-member, what `AiTaskAdmission` refuses and a person with no call left before anything is kept, then takes a UUIDv7 of now as the check's id (`Intake\IntakeCheckIds`, `Uid\UuidV7IntakeCheckIds`) and opens the check on the state Valkey with its draft (`Intake\IntakeRequests`, `Valkey\ValkeyIntakeRequests`, an hour while queued) and queues `Intake\GenerateIntakeSuggestion` on `async_ai` (`Intake\IntakeJobQueue`, `Messenger\MessengerIntakeJobQueue`). `Messenger\GenerateIntakeSuggestionHandler` marks the check and takes its draft before the one gateway call, stores the checked answer in `ast_suggestion` (`Application\Suggestions`, `Doctrine\DbalSuggestions`), and ends the check after its commit (`Intake\EndIntakeRequest`, `Messenger\EndIntakeRequestHandler`); `Messenger\FailIntakeRequestOfAbandonedJob` fails a check Messenger gave up on. Only the person who asked reads the check (`checkOf`).
- **Tables:** `ast_suggestion` (`Version20261118100000`).

## Planned

`Explanations` and `LocalAi` in the Api; the ticket page's summary UI, the "Check with AI" page and controller, the laptop prompt package and result intake (phase 08's PR 2); the 7-day purge of `ast_suggestion`; explanations, embeddings, budgets; the tables `ast_explanation`, `ast_embedding` and `ast_local_package`. See the [phase 08 hand-off notes](../../docs/05-plan/phase-08-ai-assist.md#hand-off-notes).

See the [module catalogue](../../docs/02-architecture/modules.md#assist-ast_) and [ai-architecture.md](../../docs/02-architecture/ai-architecture.md).
