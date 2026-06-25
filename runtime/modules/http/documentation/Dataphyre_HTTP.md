# Dataphyre HTTP

## Status

`http` is an optional first-party framework module. It provides lightweight
request, response, and response-emission primitives used by newer Dataphyre
framework layers.

## Runtime Shape

The module has a `Framework/` layer and no kernel entrypoint:

```text
runtime/modules/http/Framework/Request.php
runtime/modules/http/Framework/UploadedFile.php
runtime/modules/http/Framework/Response.php
runtime/modules/http/Framework/ResponseEmitter.php
runtime/modules/http/Framework/ActionArguments.php
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
$effective = $request->effective_method();
$path = $request->path();
$url = $request->fullUrl();
$ip = $request->ip();
$route = $request->routeName();
$token = $request->header('Authorization');
$page = $request->query('page', 1);
$email = $request->input('user.email');
$payload = $request->input();
$all = $request->all();
$safe = $request->only('name', 'email');
$published = $request->boolean('published');
$avatar = $request->file('avatar');
```

Request data is split into query, body, cookies, server values, headers, route
parameters, uploaded files, and arbitrary attributes.
`all()`, `only()`, `except()`, `has()`, and `filled()` operate on query values,
body values, and uploaded files. Body values override query values with the
same key. `query()`, `input()`, `cookie()`, `file()`, `only()`, `has()`, and
`filled()` support dot paths such as `user.email`. Typed readers include
`boolean()`, `integer()`, and `float()`.
URL and client helpers include `scheme()`, `host()`, `root()`, `url()`,
`fullUrl()`, `ip()`, and `userAgent()`. Forwarded host, proto, and IP headers
are honored when present.
Route helpers include `route()`, `routeName()`, and `routeIs()`; MVC dispatchers
populate route metadata before middleware and actions run.

`method()` returns the original HTTP method. `effective_method()` honors
POST-only method overrides from `_method` body/query values or the
`X-HTTP-Method-Override` header for `PUT`, `PATCH`, and `DELETE`.

Uploaded files are normalized into `Dataphyre\Http\UploadedFile` instances.
Use `file('avatar')`, `files()`, and `hasFile('avatar')`; nested PHP upload
arrays are flattened with dot paths such as `photos.0`. Empty upload fields are
ignored.

```php
if($request->hasFile('avatar')){
	$request->file('avatar')->moveTo(ROOTPATH['app'].'uploads/avatar.jpg');
}
```

## Action Arguments

`Dataphyre\Http\ActionArguments::resolve()` maps a callable signature to a
request, route parameters, defaults, nullable values, and optional typed values.
Higher-level modules can use it to keep controller/action invocation consistent
without owning their own reflection resolver.

## Response

`Dataphyre\Http\Response` represents a status, headers, and body.

```php
use Dataphyre\Http\Response;

return Response::json(['ok' => true]);
return Response::created(['id' => 123], '/orders/123');
return Response::html('<h1>Ready</h1>');
return Response::noContent();
return Response::stream($stream, 200, ['Content-Type' => 'application/pdf']);
return Response::file(ROOTPATH['app'].'reports/invoice.pdf');
return Response::download(ROOTPATH['app'].'exports/orders.csv', 'orders.csv');
$response = Response::normalize(['ok' => true]);
$html = Response::normalize('<h1>Ready</h1>', 'html');
$withDefaults = $response->withHeaders(['X-App' => 'default']);
$cached = $response->cacheFor(300)->withEtag('orders-v1');
$private = $response->privateCacheFor(60);
$fresh = $response->withLastModified(time());
$conditional = $fresh->withConditionalHeaders($request);
$noCache = $response->noCache();
$withCookie = $response->withCookie('theme', 'dark', 60);
$withoutCookie = $response->withoutCookie('theme');
```

`normalize()` converts common handler return values into `Response` instances:
arrays and `JsonSerializable` values become JSON, `null` becomes `204 No
Content`, and other values become string responses. Pass `'html'` as the second
argument when string-like values should receive an HTML content type.

`withHeaders()` returns a cloned response with additional headers. By default,
incoming headers are treated as defaults and existing response headers win.
`withHeader()`, `cacheFor()`, `privateCacheFor()`, `noCache()`, `withEtag()`,
and `withLastModified()` provide common response metadata helpers.
`isNotModified()`, `notModified()`, and `withConditionalHeaders()` support
ETag and Last-Modified conditional responses.
`withCookie()` and `withoutCookie()` append `Set-Cookie` headers; multiple
cookies are preserved and emitted as repeated header lines.
`stream()` emits a readable PHP stream without materializing the body in memory.
Streamed responses keep their caller-provided headers and bypass Flightdeck
debugbar body injection because the body is not buffered.
`file()` and `download()` read a local file into a response with content type,
content length, and inline or attachment content disposition headers.

## ResponseEmitter

`Dataphyre\Http\ResponseEmitter::emit()` accepts a `Response`, array,
`JsonSerializable`, string, `null`, or scalar-like value and sends the
corresponding HTTP response through `Response::normalize()`.

## Operational Notes

- JSON bodies are decoded from `php://input` only when `$_POST` is empty.
- Header names are normalized to lowercase underscore keys for lookup.
- This module intentionally avoids a large PSR-7 dependency surface.
