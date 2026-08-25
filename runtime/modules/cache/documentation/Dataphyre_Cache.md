# Dataphyre Cache

## Status

`cache` is an optional first-party module. It exposes a small key/value cache
facade without making cache infrastructure part of application availability.

## Runtime Shape

The module entrypoint is:

```text
runtime/modules/cache/kernel/cache.main.php
```

Loading the module exposes `\dataphyre\cache`.

## Backends and Availability

The facade lazily prefers the PHP `Memcached` extension. The endpoint resolves
in this order:

1. `DATAPHYRE_CACHE_MEMCACHED_HOST` and
   `DATAPHYRE_CACHE_MEMCACHED_PORT` environment variables.
2. The platform-compatible `MEMCACHED_HOST` and `MEMCACHED_PORT` variables.
3. `config/cache.php`, loaded as `DP_CACHE_CFG`, using the shape shown in
   `config/cache.example.php`. Top-level `host` and `port` remain accepted for
   older host bootstraps.
4. `127.0.0.1:11211`.

Environment variables override host configuration so container service bindings
can remain deployment-owned. Hostnames are bounded and reject whitespace or
control characters; ports must be between 1 and 65535. Invalid settings fall
back to the corresponding loopback default without being written to traces.
The client must return a healthy server version of at least `1.4.0` before it is
selected. The adapter does not accept or serialize cache credentials.

If the extension is missing, the service is unreachable, the health check
fails, or a later cache operation fails, the facade switches to request-local
memory for the rest of that request. This fallback is intentionally fail-open:
an optional cache outage does not call `core::unavailable()` and does not turn
an application request into a 503 response.

Request-local entries are not shared between workers or requests and must not
be used as durable state. A warning is emitted through Tracelog when available.
Call `cache::isShared()` before using the facade for cross-worker coordination
or a policy named `shared_cache`; it performs lazy backend selection and returns
`true` only while a healthy Memcached client is selected.

## API

```php
\dataphyre\cache::isShared();
\dataphyre\cache::get($key);
\dataphyre\cache::set($key, $value, $expiration = 0);
\dataphyre\cache::delete($key);
\dataphyre\cache::flush();
\dataphyre\cache::increment($key, $offset = 1);
\dataphyre\cache::incrementShared($key, $offset = 1, $expiration = 0);
\dataphyre\cache::decrement($key, $offset = 1);
```

- `isShared()` health-checks on first use and returns `true` only for Memcached.
- `get()` returns `null` for a missing or expired key.
- `set()`, `delete()`, and `flush()` return `true` when the requested state is
  established in either backend.
- `increment()` creates a missing counter from zero.
- `incrementShared()` atomically creates or increments an expiring counter only
  while Memcached is healthy. It returns `false` instead of using request-local
  memory, making it suitable for cross-worker policy such as MVC throttling.
  Concurrent creators use `add()` so every successful caller is counted once;
  the first creator establishes the expiration.
- `decrement()` creates a missing counter at zero and never returns a negative
  value.
- Keys that are empty, exceed Memcached's 250-byte limit, or contain forbidden
  whitespace/control bytes are replaced by a deterministic SHA-256 namespaced
  key. Ordinary keys retain their existing spelling.
- Expirations up to 30 days are relative seconds. Larger values are interpreted
  as absolute Unix timestamps, matching Memcached. This includes the absolute
  timestamps produced by SQL cache lifespans such as `strtotime('2 minutes')`.

Memcached's native serializer is used so scalar counters remain compatible with
its atomic increment and decrement operations.

## Fixed cross-process release probe

Cloud verifies shared cache without inline PHP or environment files through the
fixed one-shot operation `dataphyre_shared_cache_probe`. Its image-owned target
is `runtime/modules/cache/kernel/shared_cache_probe.php`; PID 1 accepts only
`DATAPHYRE_ONE_SHOT_CACHE_PHASE=detect|write|read-delete` and one 64-character
lowercase hexadecimal `DATAPHYRE_ONE_SHOT_CACHE_CHALLENGE`. The child accepts
only the corresponding `--phase` and `--challenge` arguments. It derives a
namespaced key and opaque value from the application, environment, release, and
challenge; no caller can provide a key, value, host, port, TTL, path, script, or
command.

`detect` requires the Memcached PHP extension at version 3.4.0 or newer and
reports whether a non-loopback shared endpoint is declared by the platform
cache environment aliases. It performs no network operation and is safe under
`--network none`. This fixed probe deliberately does not bootstrap application
configuration: the hosting control plane must project its deployment-owned Memcached endpoint as
`DATAPHYRE_CACHE_MEMCACHED_HOST` and optionally
`DATAPHYRE_CACHE_MEMCACHED_PORT` through the root-only environment envelope.

Cloud then runs `write` and `read-delete` in two separate network-enabled
one-shot processes with the same challenge. `write` stores the internally
derived value for exactly 120 seconds and verifies the facade remains shared.
`read-delete` matches that value, deletes it, verifies the subsequent miss, and
checks shared state after every operation. A one-process set/get is not proof:
the fail-open request-local backend can satisfy both. Only the separate writer
and reader prove process sharing. Each phase emits one canonical JSON line no
larger than 4 KiB; success exits `0`, typed misuse exits `64`, unavailable or
failed shared proof exits `69`, and invalid fixed runtime identity exits `78`.
