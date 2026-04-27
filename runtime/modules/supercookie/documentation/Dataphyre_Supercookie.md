# Dataphyre Supercookie

## Status

`supercookie` is an optional first-party module. It stores multiple logical
values inside one secure JSON cookie.

## Runtime Shape

The module entrypoint is:

```text
runtime/modules/supercookie/kernel/supercookie.main.php
```

When loaded, it exposes `\dataphyre\supercookie`.

## API

```php
\dataphyre\supercookie::set('name', $value);
\dataphyre\supercookie::get('name');
\dataphyre\supercookie::del('name');
```

The default cookie name is `__Secure-DATA`, derived from
`\dataphyre\supercookie::$cookie_name`.

## Behavior

- Values are encoded into a JSON object stored in the cookie.
- Cookies are set for 30 days.
- Cookies are set with `secure=true` and `httponly=true`.
- The cookie domain is derived from `$_SERVER['HTTP_HOST']`.
- Name validation rejects characters that are not allowed in cookie names.
- `CALL_SUPERCOOKIE_SET`, `CALL_SUPERCOOKIE_GET`, and `CALL_SUPERCOOKIE_DEL`
  dialbacks can override default behavior.

## Operational Notes

- `set()` and `del()` must run before output is sent.
- The module stores client-visible JSON values. Do not store secrets unless the
  application adds encryption through dialbacks or another layer.
