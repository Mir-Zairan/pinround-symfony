# ADR-0029: Observability with OpenTelemetry and structured logs that carry ids, not content

We can see how fast and how healthy pinround is without reading anyone's tickets.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene: "Home is slow today".
- [Decision drivers](#decision-drivers): find problems fast, privately.
- [Considered options](#considered-options): logs only, vendor APM agent, OpenTelemetry.
- [Decision outcome](#decision-outcome): traces, metrics, logs, health endpoints, SLOs and alerts.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): checks.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [More information](#more-information): links and when to revisit.

## Status and people

- Status: Accepted · Superseded in part by [ADR-0034](0034-allow-list-log-redaction.md): only the log field name `org_id`, now `organization_id`. The rest stands.
- Date: 2026-11-06
- Deciders: Zairan Mir (lead)

## Context and problem statement

On Tuesday morning Lena says Home takes five seconds. Is it a slow SQL query, a full queue of AI jobs, Valkey, or the object storage? Without traces we guess. With careless logging we might "fix" it by logging ticket bodies, which breaks ADR-0027.

## Decision drivers

- Find the slow part of a request in minutes.
- See queue health (age and failures) for images, AI, Jira and email.
- Measure the latency budgets from the non-functional requirements as SLOs.
- No content in telemetry. Vendor-neutral, so self-hosted orgs can use free tools.

## Considered options

1. **Logs only.**
2. **A vendor APM agent** (one SaaS product).
3. **OpenTelemetry for traces and metrics + Monolog JSON logs (chosen).**

## Decision outcome

Chosen option: **3**.

- **Traces:** OpenTelemetry PHP SDK with auto-instrumentation where it is stable (HTTP kernel, Doctrine/PDO, HTTP client, Messenger). Check the package names and FrankenPHP worker-mode compatibility at implementation time. Manual spans for AI gateway calls, media processing, Jira sync and the turn read-model update.
  - Allowed span attributes: route name, org id, user id (opaque UUIDs), ticket id, counts, sizes, model name, token counts.
  - Never allowed: titles, descriptions, comments, prompts or answers.
  - Sampling: 10% in production, 100% for errors and for requests slower than the budget (tail-based in the collector, if available).
- **Metrics:** request duration histograms per route, Messenger queue depth and oldest message age per transport, failed messages, AI calls and failures per provider, ping rate-limit hits, DB pool usage, Mercure publish latency.
- **Logs:** Monolog JSON to stdout, with `trace_id`, `span_id`, `request_id`, `org_id` and `user_id`. Redaction processor (ADR-0027). Level `info` in production.
- **Export:** OTLP to a collector. SaaS: any OTLP backend. Self-host: the Docker Compose profile `observability` with OpenTelemetry Collector + Grafana stack (optional).
- **Health:** `/health/live` (process up) and `/health/ready` (DB, Valkey and storage reachable, migrations current, next audit partition exists). These are not public: allowed from internal networks or with a token.
- **SLOs and alerts:**
  - Home p95 ≤ 250 ms, ticket page p95 ≤ 250 ms, list p95 ≤ 200 ms, availability 99.9%.
  - Alerts: error rate > 2% for 5 min, any queue's oldest message > 5 min, failed messages > 20/hour, DB connections > 80%, error-budget burn rate.

### Consequences

- Good: problems are found by data, not guesses, and telemetry is safe to share with vendors.
- Good: free and portable.
- Bad: OpenTelemetry PHP and worker mode need care (flush per request, no leaking spans between requests).
- Neutral: some dashboards are needed for each new module (part of each phase's Definition of done).

## Confirmation

- Test: a request in worker mode produces exactly one root span, and a second request does not reuse its context.
- Test: span attributes are checked against an allow-list in an integration test with an in-memory exporter.
- Staging dashboard shows all budgets before launch (phase 12 checklist).

## Pros and cons of the options

### 1. Logs only

- Good, because it is simple.
- Bad, because there are no timings across components and no queue view.

### 2. Vendor APM agent

- Good, because it gives fast insight.
- Bad, because of lock-in, cost, possible content capture by default, and self-hosted orgs cannot use it.

### 3. OpenTelemetry

- Good, because it is open, portable and fully under our control.
- Bad, because there is more setup.

## More information

- Related: [ADR-0008](0008-frankenphp-runtime.md), [ADR-0009](0009-async-messaging-and-outbox.md), [ADR-0027](0027-secrets-and-encryption.md), [ADR-0030](0030-deployment-model.md).
- Architecture: [operations.md](../02-architecture/operations.md), [performance-and-scale.md](../02-architecture/performance-and-scale.md).
- Note 2026-11-07 (P00.T8): the `observability` profile today runs only Jaeger, a dev-only local viewer (in-memory, loopback ports only). The self-host Collector + Grafana parts of the profile are still to come; tail sampling (100% of errors and slow requests) lands with the collector, and the app change is env only. The spans are written by hand, which fits "auto-instrumentation where stable": the auto-instrumentation C extension was not used because its global context storage conflicts with worker mode and the no-static-state rule ([ADR-0008](0008-frankenphp-runtime.md)).
- Revisit when telemetry cost exceeds 5% of hosting cost, or when sampling hides real problems.