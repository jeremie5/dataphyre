# Extension Points

Dataphyre application behavior should extend through application code, config,
callbacks, dialbacks, plugins, MCP metadata, or application-owned adapters before
changing framework internals.

## Dialback Naming

Dialbacks are exact string contracts. Preserve existing event names when
maintaining an integration. Kernel/runtime dialbacks use:

```php
CALL_<MODULE>_<ACTION>
```

New Framework-owned dialbacks use:

```php
CALL_<MODULE>_FRAMEWORK_<SURFACE_OR_CONCEPT>_<ACTION>
```

Framework bridge code may still register or fire an existing kernel dialback
when it is intentionally adapting legacy runtime behavior. Do not reuse a
kernel event name for a new Framework-only lifecycle hook.

Use all caps for the full event name. The first segment after `CALL_` should be
the owning module or subsystem. For example:

```php
\dataphyre\core::register_dialback('CALL_APP_EXAMPLE', static function(string $value): string {
	return strtoupper($value);
});

$result=\dataphyre\core::dialback('CALL_APP_EXAMPLE', 'hello');
```

Do not use dialbacks as hidden application rewrites. They are narrow, named
extension points for policy, diagnostics, integration, or replacement behavior
where a module explicitly exposes the hook.

## Tracelog Usage

Use the global `tracelog()` helper for Dataphyre runtime instrumentation:

```php
tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Message', $S='info');
tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
```

Use `function_call_with_test` only when the function is suitable for dynamic
unit-test discovery. Do not trace secrets, tokens, credentials, cookies, private
keys, full request bodies, or tenant-private payloads. For functions with
`#[SensitiveParameter]`, use `function_call` and omit `$A` rather than passing
raw `func_get_args()`.

## Dialback Families

The current source tree exposes these dialback families:

- Access: `CALL_ACCESS_*`
- AceIt Engine: `CALL_ACEIT_ENGINE_*`
- API: `CALL_API_*`
- Async: `CALL_ASYNC_*`
- Core: `CALL_CORE_*`
- Currency: `CALL_CURRENCY_*`
- Firewall: `CALL_FIREWALL_*`
- Mailer: `CALL_MAILER_*`
- Panel: `CALL_PANEL_*`
- Permission: `CALL_PERMISSION_*`
- PostgreSQL SQL driver: `CALL_POSTGRESQL_*`
- Sanitation: `CALL_SANITATION_*`
- Scheduling: `CALL_SCHEDULING_*`
- SQL: `CALL_SQL_*`
- Storage: `CALL_STORAGE_*`
- Stripe: `CALL_STRIPE_*`
- Supercookie: `CALL_SUPERCOOKIE_*`
- Time Machine: `CALL_TIME_MACHINE_*`
- Tracelog: `CALL_TRACELOG_*`
- Vestra Fabric: `CALL_VESTRA_*`

## Notable Hook Shapes

- `CALL_ACCESS_<OPERATION>_AUTH_TYPE` is built dynamically for auth-type
  delegation. The operation segment is uppercased by the Access module.
- `CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_LOGIN`,
  `CALL_ACCESS_FRAMEWORK_AUTH_AFTER_LOGIN`,
  `CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_LOGIN_USING_ID`,
  `CALL_ACCESS_FRAMEWORK_AUTH_AFTER_LOGIN_USING_ID`,
  `CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_ATTEMPT`,
  `CALL_ACCESS_FRAMEWORK_AUTH_AFTER_ATTEMPT`,
  `CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_LOGOUT`, and
  `CALL_ACCESS_FRAMEWORK_AUTH_AFTER_LOGOUT` expose guard-level login, attempt,
  and logout policy hooks. Payloads include guard, remember flag, credential-key
  names, identifier type, user type, and success state; they never include raw
  credentials or identifiers. Returning a boolean replaces the operation result.
- `CALL_ACCESS_FRAMEWORK_OAUTH_BEFORE_RESOLVE_LOCAL_USER`,
  `CALL_ACCESS_FRAMEWORK_OAUTH_AFTER_RESOLVE_LOCAL_USER`,
  `CALL_ACCESS_FRAMEWORK_OAUTH_BEFORE_LOGIN`, and
  `CALL_ACCESS_FRAMEWORK_OAUTH_AFTER_LOGIN` expose OAuth-to-local-user trust
  handoffs. Payloads include provider, guard, remember flag, user type, resolved
  state, and success state. Resolve hooks may return a replacement local user;
  login hooks may return a boolean operation result.
- `CALL_API_FRAMEWORK_LIFECYCLE_BEFORE_RUN` and
  `CALL_API_FRAMEWORK_LIFECYCLE_AFTER_RUN` wrap API before/after/error
  lifecycle phases. Payloads include phase, hook count, route summary, and extra
  value count; they do not include request bodies, headers, credentials, or
  response payloads. Returning a `Response` short-circuits or replaces the
  phase response.
- `CALL_API_FRAMEWORK_LIFECYCLE_BEFORE_INVOKE` and
  `CALL_API_FRAMEWORK_LIFECYCLE_AFTER_INVOKE` wrap individual configured API
  lifecycle hook calls with target metadata and result type only. Returning a
  `Response` replaces the hook result.
- `CALL_API_FRAMEWORK_AUTH_BEFORE_RESOLVER` and
  `CALL_API_FRAMEWORK_AUTH_AFTER_RESOLVER` wrap custom API auth resolvers.
  Payloads include scheme, route summary, credential type, scope count, runtime
  key names, authorization state, status, and result type; they never include
  raw credentials. Non-null dialback return values are normalized through the
  API authorization result contract.
- `CALL_VESTRA_RESOLVE_TENANT_CONTEXT` and `CALL_VESTRA_ISSUE_TENANT_TOKEN`
  let applications/plugins provide tenant-aware Vestra Fabric policy without
  modifying the Vestra module.
- `CALL_TRACELOG_SET_HANDLER` and `CALL_TRACELOG_ERROR_FOUND` are Tracelog
  backend hooks. Keep callbacks small and fail closed.
- `CALL_STORAGE_FRAMEWORK_LIFECYCLE_BEFORE_APPLY` and
  `CALL_STORAGE_FRAMEWORK_LIFECYCLE_AFTER_APPLY` wrap storage lifecycle
  application with a summary payload. Returning an array replaces the operation
  result; other return values are ignored.
- `CALL_STORAGE_FRAMEWORK_QUARANTINE_BEFORE_PURGE` and
  `CALL_STORAGE_FRAMEWORK_QUARANTINE_AFTER_PURGE` wrap quarantine purge
  operations with disk, prefix, option-key, status, and count summaries.
  Returning an array replaces the operation result.
- `CALL_STORAGE_FRAMEWORK_SYNC_BEFORE` and `CALL_STORAGE_FRAMEWORK_SYNC_AFTER`
  wrap cross-disk sync with source/target disks, prefix, dry-run/delete flags,
  compare mode, and result counts. Returning an array replaces the operation
  result.
- `CALL_PANEL_FRAMEWORK_PACKAGE_BEFORE_APPLY` and
  `CALL_PANEL_FRAMEWORK_PACKAGE_AFTER_APPLY` wrap panel package installation
  apply with package id, target root, dry-run mode, overwrite policy, blocked
  status, step counts, result counts, and duration. Returning an array or
  `PanelPackageApplyResult` replaces the operation result.
- `CALL_MAILER_FRAMEWORK_SEND_BEFORE`,
  `CALL_MAILER_FRAMEWORK_SEND_AFTER`,
  `CALL_MAILER_FRAMEWORK_SEND_BATCH_BEFORE`,
  `CALL_MAILER_FRAMEWORK_SEND_BATCH_AFTER`,
  `CALL_MAILER_FRAMEWORK_SEND_ASYNC_BEFORE`, and
  `CALL_MAILER_FRAMEWORK_SEND_ASYNC_AFTER` expose mail send, batch send, and
  async handoff policy hooks. Payloads include provider, queue/async mode,
  message type, option keys, result counts, provider/status, and message-id
  presence. Payloads must not include recipient lists, subjects, message bodies,
  provider credentials, or raw provider responses. Returning a `SendResult`
  replaces single-send results, returning an array replaces batch results, and
  async hooks may return a replacement async handle/result.

## Hygiene

Runtime dialback families must be listed above so agents can discover extension
points without reading every module. Dialback names use `CALL_<MODULE>_<ACTION>`.
Framework-owned names use
`CALL_<MODULE>_FRAMEWORK_<SURFACE_OR_CONCEPT>_<ACTION>`.
Use all caps after framework prefixes: `DATAPHYRE_VESTRA_*` is valid; mixed
case after `DATAPHYRE_` is not.
