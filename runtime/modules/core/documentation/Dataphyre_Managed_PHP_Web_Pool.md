# Dataphyre Managed PHP Web Pool

Status: the native envelope/request-reset and exact-image FPM prerequisites are
implemented and tested. The serving topology is deliberately not selectable
until the remaining gateway/supervisor gates below are implemented and green.

## Decision

Managed web traffic must use one rootless, tenant-local, persistent PHP-FPM
master with a fixed worker pool. Static files must be served by the fixed
Dataphyre HTTP gateway without entering PHP. The scheduler remains a separate
one-shot `php-cgi` execution boundary. Realtime remains its separate persistent
framework process.

The current fresh-`php-cgi` web path must be deleted, not retained behind a
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
3. `scheduler`: the existing root gateway with only `CAP_SETUID` and
   `CAP_SETGID`; every accepted signed registration, callback, or no-op is still
   executed by one fresh UID/GID `10001` `php-cgi` child.
4. `realtime`: the existing UID/GID `10001`, capability-free persistent
   realtime process.

The web gateway and FPM master each start in their own session. Their process
group ID equals their direct-child PID. PID 1 sends `SIGTERM` to the complete
group, waits five seconds, sends `SIGKILL` to any remaining group, and reaps all
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

Replace `application_runtime_cgi_gateway.php` with two files:

- `application_runtime_scheduler_gateway.php` retains only the current signed
  scheduler claim, fresh `php-cgi` spawn, timeout, response receipt, and child
  reaping behavior. Delete its web branches and static-file branch.
- `application_runtime_web_gateway.php` retains the bounded HTTP parser and
  response writer, runs capability-free as UID/GID `10001`, and never receives
  the application environment or managed bootstrap key.

The web gateway accepts the existing bounded methods, headers, request body, and
timeouts. For `GET` and `HEAD`, a non-PHP regular file whose canonical path is
beneath `<project>/public/` is streamed directly. Symlinks, dot-path traversal,
PHP extensions, non-regular files, ambiguous framing, duplicate headers, and
oversized input fail closed. Hashed assets receive immutable caching; other
files receive conservative caching.

Every other request is translated to FastCGI records and sent only to
`/run/dataphyre/web/php-fpm.sock`. The gateway fixes `SCRIPT_FILENAME` to the
framework-owned `application_runtime_router.php`; caller headers cannot replace
FastCGI parameters. It bounds FastCGI records, stdout, stderr, response headers,
body, and elapsed time with the existing HTTP limits. Protocol errors, a dead
worker, or a timeout return `502`/`504` without exposing FastCGI stderr.

The gateway may fork a cheap capability-free protocol child per accepted HTTP
connection. It must never fork or exec tenant PHP. This preserves bounded
concurrency without reintroducing application cold starts.

## Fixed FPM configuration

Add `application_runtime_php_fpm.conf` as immutable framework source. The
effective settings are fixed:

```ini
[global]
daemonize = no
error_log = /proc/self/fd/2
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
- spawn the scheduler gateway with the current `scheduler-gateway` secret
  envelope and one-shot child behavior;
- wait for the exact Unix socket, eight direct FPM workers, and identity checks
  before exposing runtime readiness;
- fail the generation if any direct child exits or if the worker inventory does
  not recover within five seconds;
- terminate complete process groups and reap all descendants.

The private runtime status contract advances once, from v4 to v5. Its existing
`web` field becomes one composite fixed-runtime attestation containing HTTP
gateway PID/identity, FPM master PID/identity, eight worker PIDs/identities,
socket path hash, native envelope generation hash, execution model
`persistent-php-fpm`, and fixed recycle policy. Scheduler remains
`one-request-per-process-cgi`; realtime remains `single-exec-realtime`. No
application setting or release manifest field is added.

## Implemented prerequisite slice

The current prerequisite changes are intentionally smaller than the final
topology: the native extension consumes the master envelope, restores request
environment/cwd/umask, supplies one prepend-only request context, and fails a
worker closed on reset failure. The fixed test image and Cloud PHP builder carry
an ABI-matched FPM executable, while their entrypoint remains the isolating
supervisor. Focused tests prove same-worker adversarial mutation reset,
including through the real runtime router and framework bootstrap, plus
`pm.max_requests` recycle, descriptor closure, `/proc` metadata exclusion,
master signal cleanup, and one total monotonic five-second envelope deadline.
SQL endpoint outage memory is request-local rather than browser-session state,
and Tracelog establishes an array session at shutdown.

This slice does not start FPM in Cloud and does not change traffic.

## Remaining topology patch set

Modify:

- `runtime/native/environment_fd/config.m4`
- `runtime/native/environment_fd/dataphyre_environment_fd.c`
- `runtime/modules/core/kernel/application_runtime_child_environment.php`
- `runtime/modules/core/kernel/application_runtime_process_broker.php`
- `runtime/modules/core/kernel/application_runtime_router.php`
- `runtime/modules/core/kernel/application_runtime_supervisor.php`
- `runtime/modules/core/kernel/application_runtime_status_probe.php`
- `runtime/modules/core/kernel/helper_functions.php`
- `runtime/bootstrap.php`
- `runtime/modules/core/documentation/Dataphyre_Core.md`

Add:

- `runtime/modules/core/kernel/application_runtime_web_gateway.php`
- `runtime/modules/core/kernel/application_runtime_scheduler_gateway.php`
- `runtime/modules/core/kernel/application_runtime_php_fpm.conf`

Extend the existing managed-pool test and fixtures with the complete gateway,
worker-crash, socket, concurrency, and supervisor-generation cases below.

Delete:

- `runtime/modules/core/kernel/application_runtime_cgi_gateway.php`
- every source/test assertion for web execution model
  `one-request-per-process-cgi`
- every web path that execs `/usr/local/bin/php-cgi`

Update focused contracts that currently name the deleted gateway:

- `dataphyre.core_application_runtime_secret_broker.test.php`
- `dataphyre.core_application_runtime_supervisor.test.php`
- `dataphyre.core_process_entrypoints_exact.test.php`
- `dataphyre.core_scheduler_internal_route.test.php`
- `dataphyre.core_application_runtime_scheduler_v4.test.php`
- `dataphyre.scheduling_exact.test.php`

The exact Dataphyre testing image and the publishing image must also contain the
matching FPM SAPI before this patch is selectable. That image work is outside
the runtime-core patch and must not be simulated by skipping the integration
suite.

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
   Traversal, symlink, dot-path, PHP, malformed FastCGI, and oversized-response
   cases fail closed.
10. Scheduler registration, callback, no-op, receipt, lock cleanup, replay
    rejection, budget timeout, and child reaping still use distinct fresh
    `php-cgi` PIDs.
11. Realtime registration and HTTP forwarding continue to work through the new
    HTTP gateway.
12. A full application warm dynamic latency probe and concurrent request probe
    run inside the exact image. Release preflight fails if warm p95 exceeds the
    fixed platform budget or if scheduler cadence lag exceeds its definition
    budget; registration success alone is not cadence evidence.

No source-only assertion, skipped FPM test, different PHP image, or self-hosted
sidecar is sufficient evidence for promotion.
