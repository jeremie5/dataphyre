# Dataphyre Cache

## Status

`cache` is an optional first-party module. It provides a thin Memcached-backed
cache facade for Dataphyre runtime code.

## Runtime Shape

The module entrypoint is:

```text
runtime/modules/cache/kernel/cache.main.php
```

When the module is loaded, it exposes `\dataphyre\cache`.

## Requirements

- PHP `Memcached` extension
- A Memcached server reachable at `localhost:11211`

The current implementation validates that Memcached responds and that the server
version is at least `1.4.0`.

## API

```php
\dataphyre\cache::get($key);
\dataphyre\cache::set($key, $value, $expiration = 0);
\dataphyre\cache::delete($key);
\dataphyre\cache::flush();
\dataphyre\cache::increment($key, $offset = 1);
\dataphyre\cache::decrement($key, $offset = 1);
```

Values passed through `set()` are serialized before storage and unserialized by
`get()`. A missing key returns `null`.

## Operational Notes

- The Memcached connection is opened lazily on first cache operation.
- Connection or version failures call `core::unavailable(...)`.
- Cache operations are traced through `tracelog()`.
- The module currently assumes the default local Memcached host and port. Public
  configuration for multiple servers is a release-hardening item.
