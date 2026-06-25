# Install Plugins

Dataphyre loads install-level plugin hooks from this directory. The hook files
are application-specific and are ignored for public export:

- `plugins/pre_init/*.php`
- `plugins/post_init/*.php`
- `plugins/mcp/*.json`

Keep reusable extension behavior in runtime modules. Use these hook directories
for local boot glue, deployment-specific wiring, or private integrations.

Application agents should treat install plugins as an app-owned extension layer,
not as permission to patch Dataphyre internals for one application. Prefer
configuration, dialbacks, callbacks, plugins, MCP metadata, application-owned adapters,
or reusable module contracts before runtime-internal edits.

MCP plugin JSON files can declare app-local or internal-only module metadata for
local developer tooling. Applications may create their own private
`plugins/mcp/*.json` declarations for local agents, but shared Dataphyre releases
must not ship those private declarations as framework behavior. Public exports
omit them, so redacted or private modules can remain discoverable to internal
agents without becoming part of the public module index. Release checks require
locally present redacted modules to have declarations with `release: redacted`
and a non-empty `visibility`.
