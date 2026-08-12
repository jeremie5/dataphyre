# Runtime capability contracts

Dataphyre is a framework, so applications must depend on documented framework
capabilities rather than on another application's release checks. This document
defines the small, application-neutral runtime surfaces used by modular
installations and by framework verification.

## Compatibility rule

An installation with no explicit module allow-list retains legacy discovery
semantics. A non-empty allow-list opts into strict module selection, and explicit
denies always win. This keeps existing applications compatible while allowing a
new application to constrain its runtime deliberately.

## Why these surfaces belong in the framework

| Capability | Framework responsibility | Application responsibility |
| --- | --- | --- |
| Table metadata | Expose a read-only normalized column-definition snapshot. | Choose tables and decide how to present or validate them. |
| API route metadata | Carry route API metadata and bridge API dispatch through the MVC runtime. | Declare routes, authorization, and response policy. |
| Account-isolated Stripe client | Offer an injected, per-account client boundary with local readiness and webhook verification. | Supply credentials, choose billing policy, and handle domain events. |
| Secure scheduler claims | Validate internal provenance, one-time claims, and atomic running locks. | Register tasks and provide purpose-specific policy. |
| Module policy | Resolve an application flight-sheet module allow/deny policy before loading modules. | Select modules for the installation. |

None of these contracts names a product, tenant, catalog, receipt, or business
workflow. They are useful to any Dataphyre application and remain optional until
an installation enables the corresponding module or integration.

Consumers must probe the documented methods and fail closed when a required
capability is absent. Framework releases preserve the existing public facade and
add these capability methods without requiring applications to adopt a specific
framework consumer. Applications must not copy framework contract checks from a
different application; the framework owns its capability documentation and tests.

The account-isolated Stripe client never stores a secret in process-global
configuration. The scheduler contract rejects public traffic, binds claims to a
purpose, prevents replay, and uses an atomic lock for concurrent workers.

## Verification

The runtime capability contract test exercises method presence and behavior for
the SQL, MVC/API, Stripe, and scheduler surfaces. The test uses injected fixtures
and does not contact Stripe, mutate application databases, or require an
application repository. Release tooling may consume the same documented
capability list, but the framework remains the source of truth.
