# Dataphyre Managed PHP Web Pool

Status: implemented. Promotion remains fail-closed unless the exact candidate
image passes every focused gate in this document, including the private warm
traffic, concurrency, and scheduler-cadence probe.

## Decision

Managed web traffic must use one rootless, tenant-local, persistent PHP-FPM
master with a fixed worker pool. Static files must be served by the fixed
Dataphyre HTTP gateway without entering PHP. The scheduler remains a separate
one-shot `php-cgi` execution boundary. Realtime remains its separate persistent
framework process.

The former fresh-`php-cgi` web path is deleted and is not retained behind a
mode, compatibility switch, application manifest, or user-selectable process
command. There is one managed web topology.

This is an application-neutral runtime primitive. Applications do not select
the executable, pool size, listener, timeout, socket, configuration file,
bootstrap, or recycle policy.

## Why this cannot be a `php-cgi -b` patch

Descriptor `198` contains one canonical, process-bound envelope. The current
CGI child consumes it in request-local PHP state, acknowledges it once, and
closes it. A persistent CGI process would lose that PHP state at request
shutdown while its process environment remains mutable by the previous tenant
request. Reusing the first request's projected environment, publishing a secret
file, passing values in `envp`, or reopening descriptor `198` would all violate
the existing boundary.

PHP-FPM supplies the required Zend request startup/shutdown boundary, worker
replacement, and shared OPcache. The framework-owned native extension must
consume the envelope in the rootless FPM master during module startup, before
the master forks workers. Its private persistent memory is then inherited by
workers. Every request is rehydrated from that immutable snapshot and every
request shutdown restores the fixed baseline. Tenant PHP never owns the
transport descriptor or a reusable environment source.

## Fixed process topology

PID 1 owns exactly four direct children:

1. `web-http-gateway`: UID/GID `10001`, no capabilities, `NoNewPrivs`, listens
   on `127.0.0.1:8083`, serves immutable public files, and forwards dynamic
   requests over the fixed Unix FastCGI socket.
2. `web`: UID/GID `10001`, no capabilities, `NoNewPrivs`, rootless PHP-FPM
   master, listens only on `/run/dataphyre/web/php-fpm.sock`, and owns a fixed
   static pool of eight workers.
3. `scheduler`: the root gateway with only `CAP_KILL`, `CAP_SETUID`, and
   `CAP_SETGID`; every accepted signed registration, callback, or no-op is still
   executed by one fresh UID/GID `10001` `php-cgi` child. `CAP_KILL` exists only
   so the gateway can terminate that privilege-dropped child's complete owned
   process group.
4. `realtime`: the existing UID/GID `10001`, capability-free persistent
   realtime process.

The web gateway, FPM master, and scheduler gateway each start in their own
session. Their process-group ID equals their direct-child PID. Every scheduler
CGI starts in a separate owned session so success, failure, timeout, and outer
gateway interruption all terminate its complete group before the handler exits.
PID 1 sends `SIGTERM` to a stored proven group even if its leader has already
died, waits five seconds, sends `SIGKILL` to any remaining group, and reaps all
adopted descendants. An unexpected gateway, FPM master, scheduler gateway, or
realtime exit fails the runtime generation; PID 1 does not silently construct a
replacement generation with reused secrets.

FPM alone replaces a crashed request worker. `pm.max_requests=500` provides
bounded recycling without changing release identity. A master crash is a
generation crash.

## One-use native envelope contract

Extend `runtime/native/environment_fd/dataphyre_environment_fd.c`; do not add a
second extension or a userland broker.

Add the system-only INI setting
`dataphyre_environment_fd.managed_pool_role`. Its only accepted non-empty value
is `web`. It is fixed on the `php-fpm` command line and contains no tenant data.
When it is empty, the extension retains its current one-shot behavior exactly.

When it is `web`, module startup must:

1. Require SAPI `fpm-fcgi`, UID/GID `10001`, supplementary groups `[10001]`,
   an empty effective capability set, and `NoNewPrivs`.
2. Read the bounded frame from descriptor `198` with a five-second monotonic
   deadline and close the native descriptor on every path.
3. Decode JSON through the PHP JSON extension and require the existing canonical
   envelope key order and byte-for-byte canonical re-encoding.
4. Require transport role `web-pool`; exact contract, nonce, current PID,
   current Linux start-time ticks, ancestry through PID 1, sorted environment
   names, value bounds, managed role `web`, canonical project root, and the
   32-byte URL-safe managed bootstrap key.
5. Copy the application values, the complete clean process-environment
   baseline, canonical project root, and managed key into extension-owned
   persistent allocations. Zeroize the raw framed bytes and release the decoded
   zvals after validation.
6. Write the existing canonical acknowledgement for the FPM master PID and
   start-time ticks exactly once, then close descriptor `198`.

Add one request surface:

- `dataphyre_managed_pool_request_context(): array|false` returns the sealed
  environment and managed bootstrap context exactly once in each active FPM
  worker request. The fixed system `auto_prepend_file` consumes it before
  application code, projects the request superglobals, establishes the existing
  managed bootstrap provider, and zeroizes its encoded key copy. Every later
  call in that request returns `false`; it also returns `false` in the master,
  outside FPM, and outside an active managed request.

`RINIT` must reject execution in the master, require a direct FPM worker whose
parent is the attested master, re-attest UID/GID/groups/capabilities/
`NoNewPrivs`, restore the canonical working directory and umask, restore the
complete clean baseline environment, remove names introduced by the preceding
request, then project the immutable application values plus
`DATAPHYRE_RUNTIME_POOL=web` and `DATAPHYRE_RUNTIME_POOL_ROLE=web`.

`RSHUTDOWN` must restore the clean baseline, remove request-introduced names,
and restore working directory and umask. Zend request teardown owns userland
zvals; this contract does not claim it can zeroize arbitrary copies made by
tenant PHP. `MSHUTDOWN` must zeroize and free every extension-owned persistent
environment value and the managed key. An RINIT or RSHUTDOWN reset failure
terminates that worker instead of allowing it to serve another request with
ambiguous process state.
No function may return the raw envelope, reopen descriptor `198`, accept a file
path or descriptor number, replace the stored snapshot, or activate a second
tenant identity.

The extension version moves from `1.1.0` to `1.2.0`. Its build declares the JSON
module dependency explicitly. CLI, CGI, and FPM must load the same `.so`; the
managed startup path is dormant unless the fixed system INI role is present.

## Fixed web gateway

The former `application_runtime_cgi_gateway.php` is deleted. Its two retained
responsibilities now live in separate files:

- `application_runtime_scheduler_gateway.php` retains only the current signed
  scheduler claim, fresh `php-cgi` spawn, timeout, response receipt, and child
  reaping behavior. Delete its web branches and static-file branch.
- `application_runtime_web_gateway.php` retains the bounded HTTP parser and
  response writer, runs capability-free as UID/GID `10001`, and never receives
  the application environment or managed bootstrap key.

The web gateway accepts a maximum of eight connection handlers. Each request
has a five-second absolute header deadline, a 30-second absolute body deadline,
a 16 MiB decoded-body limit, and a 256 KiB memory spool before spilling to an
unnamed temporary stream. Strict `Transfer-Encoding: chunked` uploads are
decoded directly into that bounded spool; extensions, trailers, ambiguous
framing, invalid delimiters, and decoded overflow fail closed. The eight-handler
request-plus-dynamic-response spool ceiling is therefore 192 MiB, with at most
4 MiB resident in gateway body spools.

For `GET` and `HEAD`, a non-PHP regular file whose canonical path is beneath
`<project>/public/` is streamed directly under a 30-second client-write deadline.
The normalized decoded `/health` path is always dynamic: a public file, query,
or percent-encoded alias cannot shadow application health. Symlinks, dot-path
traversal, PHP extensions, non-regular files, ambiguous framing, duplicate
headers, and oversized input fail closed. Hashed assets receive immutable
caching; other files receive conservative caching.

`<project>/public/` is optional. When it is absent, the gateway skips static
resolution and forwards the request to the framework router. A path that exists
at that boundary but is a symlink, file, or non-canonical directory still fails
closed instead of being treated as an application route.

Every other request is translated to FastCGI records and sent only to
`/run/dataphyre/web/php-fpm.sock`. The gateway fixes `SCRIPT_FILENAME` to the
framework-owned `application_runtime_router.php`; caller headers cannot replace
FastCGI parameters. FastCGI execution has one 300-second absolute deadline,
64 KiB response-header and stderr bounds, and an 8 MiB dynamic response spool.
The response normalizer removes every fixed and `Connection`-nominated
hop-by-hop field, rejects control characters, emits representation length for
`HEAD`, and emits no payload or synthesized length for `1xx`, `204`, and `304`.
Protocol errors, a dead worker, oversize output, or a timeout return `502`/`504`
without exposing FastCGI stderr.

The gateway may fork a cheap capability-free protocol child per accepted HTTP
connection. It must never fork or exec tenant PHP. This preserves bounded
concurrency without reintroducing application cold starts.

## Fixed FPM configuration

Add `application_runtime_php_fpm.conf` as immutable framework source. The
effective settings are fixed:

```ini
[global]
daemonize = no
error_log = /var/log/dataphyre/php-fpm-error.log
log_level = warning
process_control_timeout = 5s

[dataphyre-web]
listen = /run/dataphyre/web/php-fpm.sock
listen.mode = 0600
pm = static
pm.max_children = 8
pm.max_requests = 500
request_terminate_timeout = 300s
request_terminate_timeout_track_finished = yes
clear_env = yes
catch_workers_output = yes
decorate_workers_output = no
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[expose_php] = off
php_admin_value[user_ini.filename] =
php_admin_value[auto_prepend_file] = /opt/dataphyre/runtime/modules/core/kernel/application_runtime_fpm_environment.php
php_admin_value[auto_append_file] =
php_admin_value[opcache.validate_timestamps] = 0
php_admin_value[opcache.file_update_protection] = 0
```

The exact image must provide `/usr/local/sbin/php-fpm` from the same PHP build
as `PHP_BINARY`: PHP version, PHP API, Zend module API, thread-safety mode,
extension directory, loaded extension hashes, and FPM binary build ID must
match. Copying an arbitrary FPM binary into an otherwise different PHP image is
not a release contract.

PID 1 creates `/run/dataphyre/web` as one non-symlink directory owned by
UID/GID `10001`, mode `0700`, removes only a stale socket at the exact fixed
path after verifying its type and ownership, and otherwise fails closed.

## Bootstrap changes

`application_runtime_fpm_environment.php` must consume the one-use native
request context and populate the private fixed-router global.
`application_runtime_router.php` must prefer that FPM value for web requests
and must never call `consumeInherited()` in FPM. Scheduler requests keep their
current inherited-envelope branch.

`runtime/bootstrap.php` must prefer the native non-secret attestation when it is
present and otherwise preserve the current one-shot/realtime PHP attestation.
`helper_functions.php::dpvks()` must prefer the native managed-pool key provider
only for a validated native web attestation. Source-local writes remain
suppressed for both paths.

Ordinary HTTP bootstrap must not register or persist scheduler definitions.
Web and realtime use `DATAPHYRE_SCHEDULER_ACTIVATION_MODE=record_only`; the
separate signed scheduler registration remains the only persistence owner.

## Supervisor and status changes

`application_runtime_supervisor.php` must:

- require an exact executable `/usr/local/sbin/php-fpm` and the native extension
  version `1.2.0` before consuming the root envelope;
- spawn the rootless FPM master directly through the process broker with role
  `web-pool` and the one-use secret envelope;
- spawn the capability-free HTTP gateway with an empty `web-http-gateway`
  envelope;
- spawn the scheduler gateway in its own session with the current
  `scheduler-gateway` secret envelope and one-shot child behavior;
- wait for the exact Unix socket, eight direct FPM workers, and identity checks
  before exposing runtime readiness;
- fail the generation if any direct child exits or if the worker inventory does
  not recover within five seconds;
- terminate complete process groups and reap all descendants.

The private runtime status contract is `dataphyre.application_runtime.v6`. Its
`web` field is one composite fixed-runtime attestation containing HTTP
gateway PID/identity, FPM master PID/identity, eight worker PIDs/identities,
socket path hash, native envelope generation hash, execution model
`persistent-php-fpm`, and fixed recycle policy. Scheduler remains
`one-request-per-process-cgi`; realtime remains `single-exec-realtime`. No
application setting or release manifest field is added.

## Implemented topology

The native extension consumes the master envelope, restores request
environment/cwd/umask, supplies one prepend-only request context, and fails a
worker closed on reset failure. PID 1 starts the rootless FPM master and HTTP
gateway in separate process groups, waits for the exact socket and eight direct
workers, and retains the existing scheduler and realtime isolation boundaries.
The fixed test and publishing images carry an ABI-matched FPM executable.

Focused exact-image tests cover same-worker adversarial mutation reset, the
real runtime router and framework bootstrap, `pm.max_requests` recycle,
descriptor closure, `/proc` metadata exclusion, worker crash and replacement,
static-file rejection boundaries, process-group cleanup, socket removal, and
one total monotonic five-second envelope deadline. SQL endpoint outage memory
remains request-local and Tracelog establishes an array session at shutdown.

## Release-blocking focused gates

The exact-image suite must prove all of the following:

1. The FPM master consumes and acknowledges descriptor `198` once before any
   request, the descriptor is closed in master and workers, and a second consume
   is impossible.
2. Secrets and the managed key are absent from argv, the initial `/proc` process
   environment, filesystem paths, gateway memory, and status output.
3. Eight workers share one master and one OPcache while consecutive requests
   receive the exact immutable application values.
4. On the same worker, request one mutates environment, cwd, umask, locale,
   timezone, INI values, globals, statics, handlers, and output buffers; request
   two observes the canonical baseline and none of request one's values.
5. A request-worker `SIGKILL` produces one failed request, a replacement worker
   with the same generation, and a subsequent successful request.
6. `pm.max_requests` recycles a worker without changing generation or losing
   the environment snapshot.
7. FPM master death fails the complete runtime; it does not fall back to CGI or
   reuse another generation's secrets.
8. Supervisor `SIGTERM` drains the gateway and FPM group, escalates after five
   seconds, leaves no descendants or socket, and never invokes tenant shutdown
   code as root.
9. Static files never start PHP and preserve exact bytes for `GET` and `HEAD`.
   `/health` remains dynamic even when `public/health` exists. Traversal,
   symlink, dot-path, PHP, malformed FastCGI, request amplification, slowloris,
   and oversized-response cases fail closed. An application with no `public/`
   tree reaches its dynamic routes, while an existing unsafe public-root node
   remains a `404` boundary.
10. Scheduler registration, callback, no-op, receipt, lock cleanup, replay
    rejection, budget timeout, and child reaping still use distinct fresh
    `php-cgi` PIDs. Input headers and bodies are independently limited to 4096
    bytes, transfer encoding is rejected, callback/no-op output is limited to
    64 KiB, and registration output is limited to the 512 KiB protocol
    transport plus 64 KiB.
11. Realtime registration and HTTP forwarding continue to work through the new
    HTTP gateway.
12. A full application warm dynamic latency probe and concurrent request probe
    run inside the exact image. The private release gate fails if warm p95
    exceeds the fixed platform budget or if scheduler cadence lag exceeds its
    definition budget; registration success alone is not cadence evidence.

Gate 12 is the private, argument-free
`application_runtime_release_probe.php`. It warms `GET /health` three times,
measures 20 requests with a fixed 750 ms p95 budget, requires eight concurrent
requests to complete within 3000 ms, and requires at least one successful
business-cadence cycle. A per-definition start, completion, or recurrence
deadline breach makes the cadence result fail. Empty, claimed-only, and partial
cycles preserve the prior measured result; only a later nonempty cycle that
observes every due definition within cadence can recover it. The probe has a
25-second internal deadline, a 30-second process budget, and a 2048-byte
canonical evidence bound. These are platform constants, not application
settings.

PID 1 assigns the immutable definition set deterministic absolute cadence phases
over a fixed one-minute horizon. First and recurring executions retain those
phases, preventing completion-time convergence from recreating callback waves.
The planner is runtime-owned and exposes no application or deployment control.

No source-only assertion, skipped FPM test, different PHP image, or self-hosted
sidecar is sufficient evidence for promotion.
