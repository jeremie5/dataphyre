# Dataphyre HTTP

## Status

`http` is an optional first-party framework module. It provides lightweight
request, response, and response-emission primitives used by newer Dataphyre
framework layers.

## Runtime Shape

The module has a `Framework/` layer and no kernel entrypoint:

```text
runtime/modules/http/Framework/Request.php
runtime/modules/http/Framework/Response.php
runtime/modules/http/Framework/ResponseEmitter.php
```

Load it through the framework module loader when an application needs the
classes.

## Request

`Dataphyre\Http\Request` can capture the active PHP request or create synthetic
requests for tests and dispatchers.

```php
use Dataphyre\Http\Request;

$request = Request::capture($route_parameters);

$method = $request->method();
$path = $request->path();
$token = $request->header('Authorization');
$page = $request->query('page', 1);
$payload = $request->input();
```

Request data is split into query, body, cookies, server values, headers, route
parameters, and arbitrary attributes.

## Response

`Dataphyre\Http\Response` represents a status, headers, and body.

```php
use Dataphyre\Http\Response;

return Response::json(['ok' => true]);
return Response::html('<h1>Ready</h1>');
return Response::no_content();
```

## ResponseEmitter

`Dataphyre\Http\ResponseEmitter::emit()` accepts a `Response`, array,
`JsonSerializable`, string, `null`, or scalar-like value and sends the
corresponding HTTP response.

## Operational Notes

- JSON bodies are decoded from `php://input` only when `$_POST` is empty.
- Header names are normalized to lowercase underscore keys for lookup.
- This module intentionally avoids a large PSR-7 dependency surface.
