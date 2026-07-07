# Runtime Quality Gates

Dataphyre changes should make the framework easier to trust in embedded,
multi-application, and agent-assisted environments without adding broad runtime
surface area.

Use these gates for framework-level changes:

## Reusable Concept

- The change belongs in Dataphyre only when it applies across modules,
  applications, adapters, or installs.
- Application-specific behavior should use config, dialbacks, callbacks, plugins,
  or an application-owned module.
- New abstractions should remove repeated framework logic or make an existing
  cross-module contract explicit.

## Inspectable Contract

- Public and framework-facing behavior should be named, documented, and stable
  enough for agents and maintainers to reason about.
- Module status, extension points, required services, and data boundaries should
  be discoverable from docs, manifests, diagnostics, or release checks.
- Hidden coupling is a release risk; prefer explicit capability, config, or
  dialback contracts.

## Provenance

- Runtime decisions should be traceable to their source: config file, tenant,
  module default, plugin, callback, request, or external service.
- Avoid hardcoded tenant, URL, path, credential, billing, or deployment
  assumptions in reusable runtime code.
- Prefer references and resolvers over copied environment-specific values.

## Verification

- Behavior changes need targeted tests, release checks, diagnostics, or a
  reproducible manual check.
- Production hot-path code follows `dev/PERFORMANCE.md`.
- Release-impacting changes must pass the package-boundary and release hygiene
  checks before publication.

## Small Surface

- Prefer adding one reusable contract over several module-local special cases.
- Prefer deleting legacy framework surface once compatibility has moved to an
  install, adapter, plugin, or external compatibility layer.
- Do not add runtime dependencies or long-lived state unless the ownership,
  lifecycle, and release impact are clear.
