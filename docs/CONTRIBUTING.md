# Contributing to Dataphyre

This package contains runtime docs, examples, package metadata, and release
attestation for Dataphyre.

Most MCP users are application agents building applications with Dataphyre, not
Dataphyre framework contributors. Application work should stay in the consuming
application unless the change is truly reusable framework behavior.

Application teams building on Dataphyre should not patch framework internals to
make one application work. Use configuration, dialbacks, callbacks, plugins,
application-owned adapters, or reusable runtime modules first, then verify the
affected application or module with focused app-owned checks.

Framework contributions, release checks, MCP publication validation, and
Dataphyre hot-path benchmark evidence are project workflows. Open issues and
pull requests against the source repository referenced by the package metadata.

By contributing, you agree that your contributions are licensed under the same
license as Dataphyre.