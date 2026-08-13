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
\dataphyre\cache::decrement($key, $offset = 1);
```

- `isShared()` health-checks on first use and returns `true` only for Memcached.
- `get()` returns `null` for a missing or expired key.
- `set()`, `delete()`, and `flush()` return `true` when the requested state is
  established in either backend.
- `increment()` creates a missing counter from zero.
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
