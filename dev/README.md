# Dataphyre Development Artifacts

This directory is for Dataphyre contributors, local MCP validation, and runtime
quality records. Files here are development support, not framework runtime API.

- `tools/public/` contains tracked contributor tools such as PHP linting, MCP
  validation, trace/dialback review, and benchmark runners.
- `CODING_STANDARD.md`, `QUALITY_GATES.md`, `PERFORMANCE.md`,
  `PUBLIC_EXPORT.md`, and `RELEASE_CHECKLIST.md` describe contributor workflow,
runtime quality gates, optimization proof, and release-maintainer checks.

Do not treat files here as framework runtime API. Application behavior should be
implemented with Dataphyre config, dialbacks, callbacks, plugins, or reusable
runtime modules.
