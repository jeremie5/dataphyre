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

The facade lazily prefers the PHP `Memcached` extension connected to
`127.0.0.1:11211`. The client must return a healthy server version of at least
`1.4.0` before it is selected.

If the extension is missing, the service is unreachable, the health check
fails, or a later cache operation fails, the facade switches to request-local
memory for the rest of that request. This fallback is intentionally fail-open:
an optional cache outage does not call `core::unavailable()` and does not turn
an application request into a 503 response.

Request-local entries are not shared between workers or requests and must not
be used as durable state. A warning is emitted through Tracelog when available.

## API

```php
\dataphyre\cache::get($key);
\dataphyre\cache::set($key, $value, $expiration = 0);
\dataphyre\cache::delete($key);
\dataphyre\cache::flush();
\dataphyre\cache::increment($key, $offset = 1);
\dataphyre\cache::decrement($key, $offset = 1);
```

- `get()` returns `null` for a missing or expired key.
- `set()`, `delete()`, and `flush()` return `true` when the requested state is
  established in either backend.
- `increment()` creates a missing counter from zero.
- `decrement()` creates a missing counter at zero and never returns a negative
  value.
- Expirations up to 30 days are relative seconds. Larger values are interpreted
  as absolute Unix timestamps, matching Memcached.

Memcached's native serializer is used so scalar counters remain compatible with
its atomic increment and decrement operations.
