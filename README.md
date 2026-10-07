# Storm ApiOps

The framework's own introspection surface over HTTP — HTTP twins of the `storm:*` console
commands (streams/events, projections, sagas, the aggregate as fold/version/history), riding the
same API Platform bridge as `chronhub/storm-api`, under the ops zone (`/_storm/*`).

## Why a sibling package, not part of the bridge

The Api module is a strict deptrac leaf (`Api: [Contracts, Story]`): it sees the world through
the two buses only, which is its whole guarantee — the bridge cannot bypass the app's handlers.
Introspection is the opposite by nature: it reads the packages' internals through their own
services (the same `ProjectionStore` / `SagaInspectionGateway` the console commands render).
Widening the leaf would kill the guarantee, so the surface lives here: a top-consumer that
depends on what it inspects, with nothing depending on it.

Splitting also makes the exposure a conscious opt-in: requiring this package means the team
accepts what the surface carries — hydrated event payloads (PII) and destructive mutation verbs —
behind the app's ops firewall and Bureau-provided identity.

## Reset before the first run

`POST /_storm/projections/{name}/reset` can initialize and clear the output of a registered
projection that has never run. When no checkpoint exists before or after the reset, the response
is `204 No Content`; reset does not create a checkpoint. A reset of existing state returns the
fresh projection resource with status 200. Unknown projection names still return 404, and live
worker leases still refuse reset with 409. Identity and operator permission are checked first.

## Stream names and category filters

`GET /_storm/streams/{stream}/events` reads the exact named stream. A bare name such as
`account` reads only events stored in `account`, not those in `account-123`. An absent bare
stream returns an empty window even when qualified streams exist in that category.

The directory query `GET /_storm/streams?category=ACCOUNT` folds the category to lowercase
and lists both the bare stream and its qualified streams. Qualifiers remain case-sensitive:
`account-ABC` and `account-abc` identify different streams. Resume with the exact stream name
returned by the directory in `after`; event windows instead resume by global position.

## Wiring

```php
// bundles.php — next to the bridge, never instead of it
Storm\Api\StormApiBundle::class => ['all' => true],
Storm\ApiOps\StormApiOpsBundle::class => ['all' => true],
```

The app keeps the ops zone behind its firewall (for example a key-based authenticator granting
`ROLE_OPS` / `ROLE_ADMIN`). **Mind the mount prefix**: the resources
declare `/_storm/*`, but the bridge mounts them under `/api`, so the real path is
`/api/_storm/*` — a pattern that forgets the prefix matches nothing and protects nothing. The
mutation verbs (projection pause/resume/stop/retry/reset, saga cancel/redrive/pause/resume,
the type-level freeze on `/saga-types/{workflowType}/pause|resume`, and the irreversible
crypto-shred on `/privacy/{subject}/forget`) ride POST. Keep method-scoped firewall rules
as a perimeter and wire the package permission policy below; the framework hard-codes no role:

```yaml
access_control:
    # the health surface belongs to a sibling package, imported flat for an orchestrator's probes:
    # access_control keeps the FIRST matching rule, so its tighter lines stand ABOVE, or the
    # broader ops pattern below absorbs the probe path and the orchestrator is refused
    - { path: ^/_storm/health$, roles: PUBLIC_ACCESS, ips: [10.0.0.0/8] }
    - { path: ^/_storm/health$, roles: ROLE_NO_ACCESS }
    - { path: ^/(api/)?_storm, methods: [POST], roles: ROLE_ADMIN }
    - { path: ^/(api/)?_storm, roles: ROLE_OPS }
```

The two `^/(api/)?_storm` lines are deliberately broader than this package's surface: StormBundle's
own flat-imported controllers, `/_storm/health` and `/_storm/metrics`, fall under them too. That is
the safe default for anything this README does not name, and it is why any sibling surface with its
own trust level declares its rules first.

The package also requires an explicit application policy for every gated read and mutation:

```yaml
services:
    Storm\ApiOps\OpsAuthorization: '@App\Security\OperatorPermissions'
```

Implement `OpsAuthorization::canRead(Actor $actor, string $action, string $subject): bool` and
`canMutate(...)` using your authenticated principal and permission system. The action and subject
allow policies finer than a role; neither is a credential. Storm requires a Bureau identity first,
then invokes the matching policy on each call. An absent policy or a `false` decision yields HTTP
403 through `OperatorPermissionRefused`, before accessing the underlying data or applying a mutation.
Identity and permission backend failures propagate; they never grant access. Role names, tenant
scope and the match between the bound actor and the security principal belong to the application.

Migration: existing applications that only wire `IdentityProvider` must add this policy before
upgrading. The `describe` endpoint retains its compiled-wiring exception and must be protected by
the application firewall; it never reads stored data. The two dev-only anonymous opt-ins below
bypass both identity and permission checks independently. Each bypass and each explicit refusal
emits a best-effort audit record; logging is not a guarantee of durable delivery.

Beneath the firewall, the package carries its own defenses:

- **anonymous mutations are refused** (403): a destructive verb requires a Bureau-bound actor —
  the audit trail names who acted, and an anonymous mutation would blank that line. Dev/demo
  environments without an identity substrate opt out in so many words:

  ```yaml
  storm_api_ops:
      allow_anonymous_mutations: true # dev only — the default refuses
  ```

- **anonymous reads are refused too** (403), on their own knob: the reads serve hydrated event
  payloads, and the one misconfiguration above — a firewall pattern that forgets the mount —
  must fail as loud on GET as it does on POST, never drain the store silently. `describe` stays
  open either way, serving compiled wiring and never a row. Same dev/demo opt-out shape:

  ```yaml
  storm_api_ops:
      allow_anonymous_reads: true # dev only — the default refuses
  ```

- **the surface is absent from the API docs**: every ops resource declares `openapi: false`, so
  an app whose `/api/docs` stays public does not advertise the shape of its cancel, redrive and
  crypto-shred endpoints. Discovery belongs to `describe`, behind the same zone.

- **every ops response leaves `no-store, private`**, whatever cache policy the app declared
  globally: raw payloads, snapshots and saga forensics never land in a shared cache.

Aggregate state reads refuse streams carrying a declared personal event with HTTP 422.
The privacy probe runs before replay and again after the response state is materialized,
including historical reads. A personal append committed during replay therefore cannot leave
as a successful state response. A failed probe fails the read; neither case emits a success
audit record. This relies on the configured personal-event aliases and the retained event history.

The following served reads emit a best-effort audit record:

- The stream event feed.
- Correlation events.
- The aggregate state.

These records use the same audit channel as mutations. Emission does not guarantee delivery or
retention; those properties belong to the application logging stack.

Mutations are recorded in the audit log (`storm_api_ops mutation` records: action, subject,
outcome, and the Bureau-resolved actor when the app aliases an `IdentityProvider`) — a
BEST-EFFORT structured record by contract: routing, retention and durability belong to the
app's logging stack, the same doctrine as the alerts engine, and a logging outage never blocks
or fails the mutation itself. A dropped record is dispatched once as an in-process
`OpsAuditDegraded` event naming only its failed stage, `identity` or `sink`; it reaches an alert
only through a listener the app registers, and it is neither persisted nor a metric. The durable trail for the riskiest verb already lives in the
event store: a saga cancel carries its operator `reason` on `SagaCancelled`.

The events view is the HYDRATED CURRENT one: aliases resolved, upcasters applied, payloads
rendered from the current event shape (the stored header bag rides along untouched). A broken
alias or upcast chain surfaces as a 500, never a silently empty page; a forensic raw-row view
is deliberately not part of this surface today.

One endpoint answers a different question than all the others: `GET /api/_storm/describe` is
the HTTP twin of `storm:describe` — **what is wired**, never where it is at. It serves the exact
document the console renders (same `StormDescriptor`, one assembly, two channels), filtered with
`?section=` under the console's `--section` contract; an unknown section is refused loud, listing
the valid ones. By the descriptor's contract it touches no store and no broker, so it answers on
a deployment whose database is down — the one ops read that stays alive when everything else
here 500s. Being a pure read it carries no actor gate and writes no audit record; the ops-zone
access_control line covers it like every other GET.

## Manifest discipline

`require` lists exactly the module's real source edges, enforced by test
(`PackageManifestsTest`): a top-consumer's manifest grows with what it actually inspects, never
ahead of it. The deptrac line is the allowed ceiling; the manifest is the reality.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Experimental 0.x: this package changes without deprecation cycles — no backward-compatibility
promise and no legacy layer. Pin an exact 0.x tag or commit for reproducibility; pinning fixes
history, not a stable API. Schema changes are resets, not migrations, and a reset destroys data,
so it stays on disposable environments.*
