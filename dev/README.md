# Dataphyre Contributor Workspace

This directory is for Dataphyre contributors working from a Git worktree.
Files here support validation and review; they are not framework runtime API and
applications should not depend on them.

- `tools/public/` contains tracked contributor tools for PHP linting, MCP
  validation, trace/dialback review, and hot-path benchmark runs.
- `CODING_STANDARD.md`, `QUALITY_GATES.md`, `PERFORMANCE.md`,
  `PUBLIC_EXPORT.md`, and `RELEASE_CHECKLIST.md` describe contributor workflow,
  runtime quality gates, optimization proof, and release review.

Do not treat files here as framework runtime API. Application behavior should be
implemented with Dataphyre config, dialbacks, callbacks, plugins, or reusable
runtime modules.
