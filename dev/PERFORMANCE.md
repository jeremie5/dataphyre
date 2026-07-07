# Hot-Path Code Contract

Dataphyre should not chase micro-optimizations for their own sake. This contract
applies when a Dataphyre framework change adds or modifies shared code that runs
in production hot paths. It is not a benchmark requirement for applications that
use Dataphyre. Shared hot-path code should be lean, reusable, and backed by
proof when performance is part of the reason for the shape of the implementation.

## Production Code

- Add or change production hot-path code only when the behavior is shared across
  modules, framework objects, adapters, or application installs.
- Prefer universal concepts: immutable view caches, normalized value reuse,
  precomputed maps, typed contracts, and explicit module capabilities.
- Avoid feature flags, special cases, or application-specific branches whose only
  purpose is to win one benchmark or one application's workload.
- Keep the public/runtime API smaller after the change unless a new abstraction
  clearly replaces repeated logic across modules.
- Do not add a slower generic layer to a hot path unless the enterprise-grade
  benefit is explicit and verified against the path it affects.

## Proof

If performance-sensitive production code is added or reshaped, record evidence
for the affected hot path:

- Add or reuse a focused scenario in `dev/tools/public/benchmark_hot_paths.php`.
- Record baseline, candidate, restore/control when practical, and final
  confirmation in local maintainer benchmark notes.
- Include `opcache` and `opcache-jit` matrix runs for accepted hot-path changes
  when the scenario is CPU-bound or class-loading-sensitive.
- Run targeted tests or release checks that prove behavior did not change.

Rejected candidates are useful. Keep benchmark notes when a tempting change is
restored because it regresses p50, p95, average, memory, or maintainability.

## Non-Hot-Path Changes

Docs, release tooling, benchmark harnesses, MCP/developer checks, and cold-path
runtime code do not need micro-benchmark proof by default. They still need normal
verification such as release checks, link checks, JSON parsing, targeted tests, or
linting.

## Agent Boundary

Agents should not modify Dataphyre internals to make one application work. Use
configuration, dialbacks, callbacks, plugins, or reusable modules first. Runtime
edits belong in Dataphyre only when the behavior is framework-level, broadly
applicable, and verified.
