# Stability Policy

This policy defines what public users can rely on in Dataphyre releases. It is
intentionally conservative for the MIT re-release because Dataphyre is moving
from an embedded production tree into a public project shape.

## Versioning

Dataphyre follows semantic versioning for public releases:

- Major releases may remove or redesign public runtime contracts.
- Minor releases may add modules, config keys, framework classes, or public
  tooling while preserving documented behavior.
- Patch releases are compatible bug fixes, docs fixes, or release tooling
  improvements.

The current public release is tracked as `2.0.3` in [CHANGELOG.md](CHANGELOG.md).
Treat documented stable runtime contracts as the supported 2.x compatibility
surface.

## Stable Runtime Contracts

These surfaces are intended to remain stable across 2.x releases:

- `runtime/bootstrap.php` as the explicit runtime entrypoint.
- `flight_sheet.php` returning a top-level `bootstrap` array.
- `bootstrap.app`, `bootstrap.application_roots`, `bootstrap.is_production`,
  `bootstrap.prevent_keyless_direct_access`, `bootstrap.allow_app_override`, and
  `bootstrap.flightdeck` as install bootstrap keys.
- Application discovery through configured application roots and
  `<application>/app.php`.
- Application definition keys documented in [Architecture](ARCHITECTURE.md):
  `id`, `root_directory`, `rootpath_file`, `routes_file`,
  `compiled_routes_file`, `framework_bootstrap_file`, `legacy_bootstrap_file`,
  `autoload`, and `options`.
- Public templates: `flight_sheet.example.php`, `index.example.php`,
  `config/*.example.php`, and `examples/minimal/`.

## Module Stability

Module status is tracked in [MODULES.md](MODULES.md):

- `core` is part of the runtime contract.
- `optional` modules are supported, but their deeper method-level APIs are
  treated according to their module documentation.
- `adapter` modules are opt-in integrations and may change when their upstream
  services or bundled clients change.
- `legacy` modules are kept for compatibility and may be redesigned or removed
  in a future major release.
- `experimental` modules are present for visibility but are not a stability
  promise.

## Internal Surfaces

These are not public compatibility promises unless a module document explicitly
says otherwise:

- generated cache and log file formats;
- compiled route/cache artifacts;
- local plugin hook contents;
- install-local MCP plugin declarations under `plugins/mcp/*.json`;
- redacted runtime modules and private adapter modules outside the public
  module index;
- private helper functions not documented in module guides;
- module internals under `kernel/` that are only loaded as part of boot;
- third-party client internals bundled under vendored paths.

## Agent Compatibility Boundary

Application agents should treat stable runtime contracts, public templates,
module docs, config files, dialbacks, callbacks, plugins, MCP metadata,
application-owned adapters, and reusable module contracts as the normal extension
surface. Use focused application or module checks to verify application-owned
behavior.

Private `plugins/mcp/*.json` declarations are app-local or internal metadata for
agent/tool visibility. They are stable as an extension location, not as public
framework payload.

Do not treat internal surfaces, generated state, local plugin contents,
`dataphyre_mcp_verify_all`, Dataphyre hot-path benchmarks,
or runtime-internal edits as ordinary app-agent requirements.
Escalate to project evidence only for release-facing public
framework claims, corporate-ready or enterprise-readiness claims,
security/identity/access/session/credential/governance/tenant/privacy/
compliance/data-residency/retention/legal-hold/access-policy compatibility
claims, Dataphyre framework internals or reusable framework contributions, or
shared production hot-path changes.

## Deprecation Policy

For 1.x releases, public runtime contracts are deprecated before removal.
A deprecation includes:

- a changelog entry;
- migration guidance in the relevant doc;
- a replacement path when one exists;
- at least one minor release of overlap before removal, unless the change is a
  security fix.

Legacy and experimental modules may receive shorter migration windows, but their
status must remain visible in `MODULES.md` and release notes.

## Security Exceptions

Security fixes can change behavior in patch releases when preserving old
behavior would keep users exposed. When that happens, the release notes call out
the behavior change directly.



