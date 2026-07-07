# Dataphyre Development Artifacts

This directory is for Dataphyre contributors, release maintainers, and local MCP
validation. It is intentionally excluded from prepared public exports.

- `tools/` contains release checks, export preparation, PHP linting, MCP
  validation, release manifest verification, and benchmark runners.
- `benchmarks/` contains benchmark notes and local performance records.
- `CODING_STANDARD.md`, `QUALITY_GATES.md`, `PERFORMANCE.md`,
  `PUBLIC_EXPORT.md`, and `RELEASE_CHECKLIST.md` describe contributor workflow,
runtime quality gates, optimization proof, and release-maintainer checks.

`tools/release_check` also verifies that `docs/AGENTIC_ENTERPRISE.md`
continues to publish the extension boundary, MCP safety, performance discipline,
and enterprise adoption checklist used by agent-first release claims. It also
checks that MCP documentation continues to name the coverage, readiness, safety,
enterprise-audit, and aggregate verification surfaces agents rely on.

Do not treat files here as framework runtime API. Application behavior should be
implemented with Dataphyre config, dialbacks, callbacks, plugins, or reusable
runtime modules.
