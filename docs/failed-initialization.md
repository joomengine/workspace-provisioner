# Failed initialization and physical stop evidence

A failed `create` retains its original operation/request ID, generation, reservations, image digests and initialization checkpoints. Failure is committed before the worker attempts physical cleanup. While that failed create is the latest generation, the worker revisits owned-VM stop cleanup at most once per ten seconds. Durable selection alternates due cleanup and ordinary work when both exist; cleanup is ordered oldest-due first, so neither queue starves the other across worker restarts or slow calls. The worker does not rerun initialization, delete the VM/data, open ingress or create a replacement generation. A worker restart resumes cleanup. The selected host must still match the saved inventory identity.

## Fresh status contract

Use `Application::status()` or the `status` CLI for fresh evidence. `Journal::status()` is a journal snapshot only. Operation output adds `generation`, `attempt` (incremented on an authorized retry), and status adds `workspace_generation`. For a failed create, fresh status adds `stop_observation`:

```json
{
  "version": 1,
  "operation_id": "the-original-create-operation-uuid",
  "generation": 1,
  "attempt": 0,
  "state": "stopped",
  "error": null,
  "observed_at": "2026-10-01T12:00:00+00:00"
}
```

The state is `stopped`, `absent`, `running`, or `unknown`; `observed_at` is UTC RFC3339. This describes a point-in-time owned-VM observation, not uptime, application health or a permanent guarantee. Ordinary operation failure and the internal historical `access_stop_confirmed` flag are not fresh physical evidence.

Consumers must require the same failed create, current matching workspace/operation generation, matching attempt, and a fresh `stopped` or `absent` response immediately before recording containment. They must reread on subsequent reconciliation, never reuse a stored response. Status uses executor exclusion and atomically rechecks the journal after physical observation. A concurrent retry or newer generation invalidates evidence. A busy worker, changed host, ownership conflict, malformed/unreachable API, transitional power state or unresolved physical operation remains `unknown`. Status never stops or starts infrastructure.

## Interrupted operations and recovery limits

Create and start requests persist a physical mutation intent **before** transport and the exact Incus operation ID after acknowledgement. Cleanup stops persist a separate intent with the same safeguards, so they cannot overwrite original create/start ambiguity. A worker interrupted with such an intent enters failed cleanup instead of automatically restarting initialization. Acknowledged operations can be settled by their recorded Incus ID; cleanup checks project-scoped operations and reobserves physical state. A lost acknowledgement of a cleanup stop remains unresolved even if a current snapshot is stopped: that delayed stop could otherwise execute after an initialization retry starts the VM.

An **unacknowledged create/start/cleanup-stop reply cannot be resolved by an empty operation list or an absent/stopped instance snapshot**: the request may still execute later. Such ambiguity remains `runtime_outcome_unconfirmed`. Retry and superseding lifecycle requests, including delete, are rejected. An unresolved cleanup-stop intent is never replaced by another stop request. Legacy failed creates without complete intent tracking have the same limitation. This version has no automatic override or operator-resolution command for that irreducible case. Keep data/reservations and investigate on the authorized host before designing a reviewed recovery. Do not edit state to manufacture a stop or reset the generation.

When cleanup has settled acknowledged physical work, an authorized `retry` of the original create continues its existing checkpoints. It clears cached stop evidence and increments the attempt without changing the request ID, generation, resources or data. Do not submit a separate suspend generation to a failed initialization if it must remain retryable. No customer eligibility, account state or payment logic belongs in the native provisioner.

The internal `Runtime::ensure` observer now receives a structured intent (`kind`, `state`, `operation`), and `Runtime::start` and `Runtime::suspend` accept the same optional observer. Custom runtime adapters must implement the new read-only `observe` method and must not manufacture confirmed state from successful calls alone. The CLI request schema is unchanged.

## Qualification

Synthetic tests cover durable cleanup, interrupted worker state, lost replies, request replay, unchanged checkpoints/data, running versus stopped versus absent, concurrent retry/new-generation invalidation, host/ownership refusal and strict unknown responses. These are not real session-termination or recovery results.

Before operational qualification, an authorized isolated Incus/KVM host must exercise failure during VM creation, start, guest setup, recipes and handover; kill the worker before and after persisted failure and transport acknowledgements; lose replies; observe actual VM power and active-session termination; verify no unrelated VM is touched; and compare retained Joomla files/databases after the original initialization retry. Explicitly record unresolved/unacknowledged cases and the reviewed operator-resolution procedure. No production deployment, host test or uptime claim is implied by passing hosted CI.
