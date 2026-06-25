<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Http;

/**
 * HTTP response value used by Dataphyre request dispatchers.
 *
 * A response carries status, headers, and body content until the outer HTTP
 * layer emits it. Helper methods create common response variants and return
 * cloned instances for header, cache, conditional request, and cookie changes.
 */
final class Response {

	/** @var int HTTP status code emitted with the response. */
	public int $status;
	/** @var array<string, string|array<int, string>> Response headers, including multi-value Set-Cookie headers. */
	public array $headers;
	/** @var string Response body bytes already materialized in memory. */
	public string $body;
	/** @var resource|null Response body stream emitted without materializing the body. */
	public mixed $stream=null;
	/** @var array<string, callable> Runtime response macros keyed by method name. */
	private static array $macros=[];
	private ?string $normalizedEtagPayload=null;

	/**
	 * Captures response body, status, and headers.
	 *
	 * The constructor stores body bytes as provided. Header normalization is left
	 * to factories and helper methods so low-level callers can preserve exact
	 * header names and values when needed.
	 *
	 * @param string $body Response body bytes.
	 * @param int $status HTTP status code.
	 * @param array<string, string|array<int, string>> $headers Response header map.
	 */
	public function __construct(string $body='', int $status=200, array $headers=[]){
		$this->body=$body;
		$this->status=$status;
		$this->headers=$headers;
	}

	/**
	 * Creates a plain response with caller-provided body, status, and headers.
	 *
	 * @param string $body Response body bytes.
	 * @param int $status HTTP status code.
	 * @param array<string, string|array<int, string>> $headers Response header map.
	 * @return self Plain response value.
	 */
	public static function make(string $body='', int $status=200, array $headers=[]): self {
		return new self($body, $status, $headers);
	}

	/**
	 * Creates a streamed response from a readable PHP stream resource.
	 *
	 * Streamed responses leave the body empty and are emitted chunk by chunk by
	 * ResponseEmitter. They are intended for large files, encrypted storage reads,
	 * and other response bodies that should not be fully buffered in memory.
	 *
	 * @param mixed $stream Readable stream resource.
	 * @param int $status HTTP status code.
	 * @param array<string, string|array<int, string>> $headers Response header map.
	 * @return self Streamed response value.
	 *
	 * @throws \InvalidArgumentException When the value is not a stream resource.
	 */
	public static function stream(mixed $stream, int $status=200, array $headers=[]): self {
		if(!is_resource($stream)){
			throw new \InvalidArgumentException('Response stream must be a readable resource.');
		}
		$response=new self('', $status, $headers);
		$response->stream=$stream;
		return $response;
	}

	/**
	 * Indicates whether this response emits a stream instead of a materialized body.
	 *
	 * @return bool True when a stream resource is attached.
	 */
	public function isStreamed(): bool {
		return is_resource($this->stream);
	}

	/**
	 * Registers a dynamic response macro.
	 *
	 * Macros can be called statically or on an instance. Instance macro closures
	 * are bound to the current response so they can compose clone-on-write helper
	 * methods.
	 *
	 * @param string $name Macro method name.
	 * @param callable $macro Macro implementation.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the macro name is blank.
	 */
	public static function macro(string $name, callable $macro): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Response macro name cannot be empty.');
		}
		self::$macros[$name]=$macro;
	}

	/**
	 * Indicates whether a response macro is registered.
	 *
	 * @param string $name Macro method name.
	 * @return bool True when a macro exists for the supplied name.
	 */
	public static function hasMacro(string $name): bool {
		return isset(self::$macros[$name]);
	}

	/**
	 * Clears all registered response macros.
	 *
	 * @return void
	 */
	public static function flushMacros(): void {
		self::$macros=[];
	}

	/**
	 * Dispatches a registered dynamic macro call.
	 *
	 * Throws when the requested macro has not been registered.
	 *
	 * @param string $name Macro method name.
	 * @param array<int, mixed> $arguments Macro arguments.
	 * @return mixed value produced by the registered static macro callable.
	 *
	 * @throws \BadMethodCallException When the macro is not registered.
	 */
	public static function __callStatic(string $name, array $arguments): mixed {
		if(isset(self::$macros[$name])===false){
			throw new \BadMethodCallException('Response macro is not registered: '.$name);
		}
		$macro=self::$macros[$name];
		if($macro instanceof \Closure){
			$macro=$macro->bindTo(null, self::class);
		}
		return $macro(...$arguments);
	}

	/**
	 * Dispatches a registered dynamic macro call.
	 *
	 * Throws when the requested macro has not been registered.
	 *
	 * @param string $name Macro method name.
	 * @param array<int, mixed> $arguments Macro arguments.
	 * @return mixed value produced by the registered macro after closure binding to this response.
	 *
	 * @throws \BadMethodCallException When the macro is not registered.
	 */
	public function __call(string $name, array $arguments): mixed {
		if(isset(self::$macros[$name])===false){
			throw new \BadMethodCallException('Response macro is not registered: '.$name);
		}
		$macro=self::$macros[$name];
		if($macro instanceof \Closure){
			$macro=$macro->bindTo($this, self::class);
		}
		return $macro(...$arguments);
	}

	/**
	 * Creates a JSON response from array or serializable data.
	 *
	 * JSON is encoded without escaping slashes or Unicode. Encoding failure falls
	 * back to an empty JSON object so dispatchers still receive a valid body.
	 *
	 * @param array<string|int, mixed>|\JsonSerializable $payload Response data to encode.
	 * @param int $status HTTP status code.
	 * @param array<string, string|array<int, string>> $headers Additional or overriding headers.
	 * @return self JSON response with content type set.
	 */
	public static function json(array|\JsonSerializable $payload, int $status=200, array $headers=[]): self {
		$headers=array_replace(['Content-Type'=>'application/json; charset=utf-8'], $headers);
		$encoded=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return new self($encoded===false ? '{}' : $encoded, $status, $headers);
	}

	/**
	 * Creates a 201 JSON response and optional Location header.
	 *
	 * @param array<string|int, mixed>|\JsonSerializable $payload Response data to encode.
	 * @param ?string $location Created resource location, when available.
	 * @param array<string, string|array<int, string>> $headers Additional or overriding headers.
	 * @return self JSON response with status 201.
	 */
	public static function created(array|\JsonSerializable $payload, ?string $location=null, array $headers=[]): self {
		if($location!==null && trim($location)!==''){
			$headers=array_replace(['Location'=>$location], $headers);
		}
		return self::json($payload, 201, $headers);
	}

	/**
	 * Creates an HTML response.
	 *
	 * @param string $html HTML body.
	 * @param int $status HTTP status code.
	 * @param array<string, string|array<int, string>> $headers Additional or overriding headers.
	 * @return self HTML response with content type set.
	 */
	public static function html(string $html, int $status=200, array $headers=[]): self {
		$headers=array_replace(['Content-Type'=>'text/html; charset=utf-8'], $headers);
		return new self($html, $status, $headers);
	}

	/**
	 * Creates an empty 204 No Content response.
	 *
	 * @return self Empty response with status 204.
	 */
	public static function noContent(): self {
		return new self('', 204, []);
	}

	/**
	 * Legacy snake_case alias for noContent().
	 *
	 * @return self Empty response with status 204.
	 */
	public static function no_content(): self {
		return self::noContent();
	}

	/**
	 * Creates an inline file response by reading the file into memory.
	 *
	 * @param string $path Filesystem path to a readable file.
	 * @param ?string $name Optional filename advertised in Content-Disposition.
	 * @param array<string, string|array<int, string>> $headers Additional or overriding headers.
	 * @return self Inline file response.
	 *
	 * @throws \InvalidArgumentException When the file is missing or unreadable.
	 * @throws \RuntimeException When file contents cannot be read.
	 */
	public static function file(string $path, ?string $name=null, array $headers=[]): self {
		return self::fileResponse($path, $name, false, $headers);
	}

	/**
	 * Creates an attachment file response by reading the file into memory.
	 *
	 * @param string $path Filesystem path to a readable file.
	 * @param ?string $name Optional download filename.
	 * @param array<string, string|array<int, string>> $headers Additional or overriding headers.
	 * @return self Download response with attachment disposition.
	 *
	 * @throws \InvalidArgumentException When the file is missing or unreadable.
	 * @throws \RuntimeException When file contents cannot be read.
	 */
	public static function download(string $path, ?string $name=null, array $headers=[]): self {
		return self::fileResponse($path, $name, true, $headers);
	}

	/**
	 * Returns a copy with additional response headers.
	 *
	 * When `$replace` is false, existing headers win over incoming values. When
	 * true, incoming values replace existing headers.
	 *
	 * @param array<string, string|array<int, string>> $headers Header map to merge.
	 * @param bool $replace True when incoming headers should override existing values.
	 * @return self Cloned response with merged headers.
	 */
	public function withHeaders(array $headers, bool $replace=false): self {
		$clone=clone $this;
		$clone->headers=$replace
			? array_replace($clone->headers, $headers)
			: array_replace($headers, $clone->headers);
		return $clone;
	}

	/**
	 * Returns a copy with one response header set.
	 *
	 *
	 * @param string $name Header name.
	 * @param string|array<int, string> $value Header value or multi-value header list.
	 * @return self Cloned response with the header set.
	 */
	public function withHeader(string $name, string|array $value): self {
		$clone=clone $this;
		$clone->headers[$name]=$value;
		if(strtolower($name)==='etag'){
			$clone->normalizedEtagPayload=null;
		}
		return $clone;
	}

	/**
	 * Returns a copy with headers that discourage caching.
	 *
	 * @return self Cloned response with no-cache headers.
	 */
	public function noCache(): self {
		return $this->withHeaders([
			'Cache-Control'=>'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'=>'no-cache',
			'Expires'=>'0',
		], true);
	}

	/**
	 * Returns a copy with a public or private max-age cache policy.
	 *
	 * Negative durations are clamped to zero.
	 *
	 * @param int $seconds Cache lifetime in seconds.
	 * @param bool $public True for public cache, false for private cache.
	 * @return self Cloned response with Cache-Control set.
	 */
	public function cacheFor(int $seconds, bool $public=true): self {
		$seconds=max(0, $seconds);
		return $this->withHeaders([
			'Cache-Control'=>($public ? 'public' : 'private').', max-age='.$seconds,
		], true);
	}

	/**
	 * Returns a copy with a private max-age cache policy.
	 *
	 *
	 * @param int $seconds Cache lifetime in seconds.
	 * @return self Cloned response with private Cache-Control set.
	 */
	public function privateCacheFor(int $seconds): self {
		return $this->cacheFor($seconds, false);
	}

	/**
	 * Returns a copy with an ETag header.
	 *
	 * Empty values leave the response unchanged. Quotes are normalized and weak
	 * validators receive the `W/` prefix.
	 *
	 * @param string $etag Entity tag value.
	 * @param bool $weak True to emit a weak validator.
	 * @return self Cloned response with ETag set, or current response for blank tags.
	 */
	public function withEtag(string $etag, bool $weak=false): self {
		$etag=trim($etag);
		if($etag===''){
			return $this;
		}
		$etag=trim($etag, '"');
		return $this->withHeader('ETag', ($weak ? 'W/' : '').'"'.$etag.'"');
	}

	/**
	 * Returns a copy with a Last-Modified header.
	 *
	 *
	 * @param int|\DateTimeInterface $modified Timestamp or date object.
	 * @return self Cloned response with Last-Modified set in GMT.
	 */
	public function withLastModified(int|\DateTimeInterface $modified): self {
		$timestamp=$modified instanceof \DateTimeInterface ? $modified->getTimestamp() : $modified;
		return $this->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $timestamp).' GMT');
	}

	/**
	 * Evaluates request validators against this response's ETag or Last-Modified.
	 *
	 * `If-None-Match` takes precedence when an ETag exists. Weak and strong ETags
	 * compare after normalizing their validator wrappers. Last-Modified falls
	 * back to `If-Modified-Since` timestamp comparison.
	 *
	 * @param Request $request Incoming request containing conditional headers.
	 * @return bool True when the request validators indicate the response is unchanged.
	 */
	public function isNotModified(Request $request): bool {
		$etag=$this->headerValue('ETag');
		$ifNoneMatch=(string)$request->header('If-None-Match', '');
		if($etag!==null && $ifNoneMatch!==''){
			$normalizedEtag=$this->normalizedEtagPayload ??= $this->normalizeEtag($etag);
			foreach(explode(',', $ifNoneMatch) as $candidate){
				$candidate=trim($candidate);
				if($candidate==='*' || $candidate===$etag || $this->normalizeEtag($candidate)===$normalizedEtag){
					return true;
				}
			}
		}
		$lastModified=$this->headerValue('Last-Modified');
		$ifModifiedSince=(string)$request->header('If-Modified-Since', '');
		if($lastModified!==null && $ifModifiedSince!==''){
			$modifiedAt=strtotime($lastModified);
			$requestedAt=strtotime($ifModifiedSince);
			return $modifiedAt!==false && $requestedAt!==false && $modifiedAt<=$requestedAt;
		}
		return false;
	}

	/**
	 * Returns a copy transformed into a 304 Not Modified response.
	 *
	 * Body and representation-specific headers are removed while cache validators
	 * and other safe headers are preserved.
	 *
	 * @return self Cloned response with status 304 and empty body.
	 */
	public function notModified(): self {
		$clone=clone $this;
		$clone->status=304;
		$clone->body='';
		unset($clone->headers['Content-Type'], $clone->headers['Content-Length'], $clone->headers['Content-Disposition']);
		return $clone;
	}

	/**
	 * Applies conditional request handling to this response.
	 *
	 *
	 * @param Request $request Incoming request containing conditional headers.
	 * @return self Not-modified response when validators match, otherwise the current response.
	 */
	public function withConditionalHeaders(Request $request): self {
		return $this->isNotModified($request) ? $this->notModified() : $this;
	}

	/**
	 * Returns a copy with a Set-Cookie header appended.
	 *
	 * A positive minute value creates Expires and Max-Age attributes. Zero leaves
	 * the cookie as a session cookie.
	 *
	 * @param string $name Cookie name.
	 * @param string $value Cookie value.
	 * @param int $minutes Lifetime in minutes; zero creates a session cookie.
	 * @param string $path Cookie path attribute.
	 * @param string $domain Cookie domain attribute.
	 * @param bool $secure True to add the Secure attribute.
	 * @param bool $httpOnly True to add the HttpOnly attribute.
	 * @param string $sameSite SameSite policy: Lax, Strict, or None.
	 * @return self Cloned response with Set-Cookie appended.
	 */
	public function withCookie(
		string $name,
		string $value,
		int $minutes=0,
		string $path='/',
		string $domain='',
		bool $secure=false,
		bool $httpOnly=true,
		string $sameSite='Lax'
	): self {
		$expires=$minutes>0 ? time()+($minutes*60) : 0;
		return $this->withCookieHeader(self::cookieHeader($name, $value, $expires, $path, $domain, $secure, $httpOnly, $sameSite));
	}

	/**
	 * Returns a copy with an expired Set-Cookie header for deletion.
	 *
	 *
	 * @param string $name Cookie name.
	 * @param string $path Cookie path attribute used for deletion.
	 * @param string $domain Cookie domain attribute used for deletion.
	 * @return self Cloned response with an expired Set-Cookie header.
	 */
	public function withoutCookie(string $name, string $path='/', string $domain=''): self {
		return $this->withCookieHeader(self::cookieHeader($name, '', time()-31536000, $path, $domain, false, true, 'Lax'));
	}

	/**
	 * Builds a Set-Cookie header value.
	 *
	 * Names and values are raw-url-encoded. Invalid cookie names are rejected.
	 * SameSite values are normalized to the supported browser policies.
	 *
	 * @param string $name Cookie name.
	 * @param string $value Cookie value.
	 * @param int $expires Unix expiration timestamp; zero omits expiration, negative deletes.
	 * @param string $path Cookie path attribute.
	 * @param string $domain Cookie domain attribute.
	 * @param bool $secure True to add the Secure attribute.
	 * @param bool $httpOnly True to add the HttpOnly attribute.
	 * @param string $sameSite SameSite policy.
	 * @return string Complete Set-Cookie header value.
	 *
	 * @throws \InvalidArgumentException When the cookie name is invalid.
	 */
	public static function cookieHeader(
		string $name,
		string $value,
		int $expires=0,
		string $path='/',
		string $domain='',
		bool $secure=false,
		bool $httpOnly=true,
		string $sameSite='Lax'
	): string {
		$name=trim($name);
		if($name==='' || preg_match('/[=,;\\s]/', $name)){
			throw new \InvalidArgumentException('Cookie name is invalid.');
		}
		$header=rawurlencode($name).'='.rawurlencode($value);
		if($expires>0){
			$header.='; Expires='.gmdate('D, d M Y H:i:s', $expires).' GMT';
			$header.='; Max-Age='.max(0, $expires-time());
		} elseif($expires<0){
			$header.='; Expires='.gmdate('D, d M Y H:i:s', $expires).' GMT';
			$header.='; Max-Age=0';
		}
		if($path!==''){
			$header.='; Path='.$path;
		}
		if($domain!==''){
			$header.='; Domain='.$domain;
		}
		if($secure){
			$header.='; Secure';
		}
		if($httpOnly){
			$header.='; HttpOnly';
		}
		$sameSite=ucfirst(strtolower(trim($sameSite)));
		if(in_array($sameSite, ['Lax', 'Strict', 'None'], true)){
			$header.='; SameSite='.$sameSite;
		}
		return $header;
	}

	/**
	 * Converts arbitrary controller output into a response value.
	 *
	 * Existing responses pass through unchanged. Arrays and JsonSerializable
	 * values become JSON, null becomes 204, and strings are treated as raw or HTML
	 * according to `$stringMode`.
	 *
	 * @param mixed $response Controller return value.
	 * @param string $stringMode Use `html` to wrap string output as HTML; any other value keeps raw text.
	 * @return self Normalized response value.
	 */
	public static function normalize(mixed $response, string $stringMode='raw'): self {
		if($response instanceof self){
			return $response;
		}
		if(is_array($response) || $response instanceof \JsonSerializable){
			return self::json($response);
		}
		if($response===null){
			return self::noContent();
		}
		if($stringMode==='html'){
			return self::html((string)$response);
		}
		return self::make((string)$response);
	}

	/**
	 * Returns a copy with one Set-Cookie header appended to the multi-value list.
	 *
	 * @param string $cookie Complete Set-Cookie header value.
	 * @return self Cloned response with Set-Cookie appended.
	 */
	private function withCookieHeader(string $cookie): self {
		$clone=clone $this;
		$current=$clone->headers['Set-Cookie'] ?? [];
		if($current===[]){
			$clone->headers['Set-Cookie']=[$cookie];
			return $clone;
		}
		$cookies=[];
		if(is_array($current)){
			foreach($current as $value){
				if(is_string($value) && $value!==''){
					$cookies[]=$value;
				}
			}
		}
		elseif(is_string($current) && $current!==''){
			$cookies[]=$current;
		}
		$cookies[]=$cookie;
		$clone->headers['Set-Cookie']=$cookies;
		return $clone;
	}

	/**
	 * Fetches a string header value by exact header name.
	 *
	 * @param string $name Header name.
	 * @return ?string Header value when stored as a string.
	 */
	private function headerValue(string $name): ?string {
		$value=$this->headers[$name] ?? null;
		return is_string($value) ? $value : null;
	}

	/**
	 * Normalizes an ETag value for conditional request comparison.
	 *
	 * @param string $etag Header value or candidate validator.
	 * @return string Validator without weak prefix or wrapping quotes.
	 */
	private function normalizeEtag(string $etag): string {
		$etag=trim($etag);
		if(str_starts_with($etag, 'W/')){
			$etag=substr($etag, 2);
		}
		return trim($etag, '"');
	}

	/**
	 * Creates a file-backed response by reading the file into the response body.
	 *
	 * @param string $path Filesystem path to a readable file.
	 * @param ?string $name Optional filename for content disposition.
	 * @param bool $attachment True for attachment disposition, false for inline.
	 * @param array<string, string|array<int, string>> $headers Additional or overriding headers.
	 * @return self File response.
	 *
	 * @throws \InvalidArgumentException When the file is missing or unreadable.
	 * @throws \RuntimeException When file contents cannot be read.
	 */
	private static function fileResponse(string $path, ?string $name, bool $attachment, array $headers): self {
		if(!is_file($path) || !is_readable($path)){
			throw new \InvalidArgumentException('Response file does not exist or is not readable: '.$path);
		}
		$filename=$name!==null && trim($name)!=='' ? trim($name) : basename($path);
		$body=file_get_contents($path);
		if($body===false){
			throw new \RuntimeException('Response file could not be read: '.$path);
		}
		$defaults=[
			'Content-Type'=>self::mimeType($path),
			'Content-Length'=>(string)filesize($path),
			'Content-Disposition'=>self::contentDisposition($attachment ? 'attachment' : 'inline', $filename),
		];
		return new self($body, 200, array_replace($defaults, $headers));
	}

	/**
	 * Resolves a file MIME type with a conservative extension fallback table.
	 *
	 * @param string $path Filesystem path being served.
	 * @return string MIME type suitable for Content-Type.
	 */
	private static function mimeType(string $path): string {
		if(function_exists('mime_content_type')){
			$mime=@mime_content_type($path);
			if(is_string($mime) && $mime!==''){
				return $mime;
			}
		}
		return match(strtolower((string)pathinfo($path, PATHINFO_EXTENSION))){
			'css'=>'text/css; charset=utf-8',
			'csv'=>'text/csv; charset=utf-8',
			'gif'=>'image/gif',
			'htm', 'html'=>'text/html; charset=utf-8',
			'jpg', 'jpeg'=>'image/jpeg',
			'js'=>'application/javascript; charset=utf-8',
			'json'=>'application/json; charset=utf-8',
			'pdf'=>'application/pdf',
			'png'=>'image/png',
			'svg'=>'image/svg+xml',
			'txt'=>'text/plain; charset=utf-8',
			'webp'=>'image/webp',
			default=>'application/octet-stream',
		};
	}

	/**
	 * Builds a Content-Disposition header with ASCII and RFC 5987 filenames.
	 *
	 * @param string $disposition Disposition type, usually inline or attachment.
	 * @param string $filename Filename advertised to the client.
	 * @return string Content-Disposition header value.
	 */
	private static function contentDisposition(string $disposition, string $filename): string {
		$ascii=preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'download';
		$ascii=str_replace(['\\', '"'], ['_', '\\"'], $ascii);
		return $disposition.'; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename);
	}
}
