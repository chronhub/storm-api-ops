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
crypto-shred on `/privacy/{subject}/forget`) ride POST — one method-scoped line is the whole
authorization gesture, the framework hard-codes no role:

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

The payload-bearing reads — the event feed and the aggregate state — also write an audit line
when served, so a drained store is never invisible in the module's own channel.

Mutations are recorded in the audit log (`storm_api_ops mutation` records: action, subject,
outcome, and the Bureau-resolved actor when the app aliases an `IdentityProvider`) — a
BEST-EFFORT structured record by contract: routing, retention and durability belong to the
app's logging stack, the same doctrine as the alerts engine, and a logging outage never blocks
or fails the mutation itself. The durable trail for the riskiest verb already lives in the
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

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
