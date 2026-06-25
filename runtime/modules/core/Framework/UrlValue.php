<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable parsed representation of a URL or URL-like runtime string.
 *
 * `UrlValue` keeps the original input available through `raw()` and `__toString()`
 * while exposing parsed components for configuration, diagnostics, routing, and
 * service calls. It delegates parsing to PHP's `parse_url()` and preserves
 * relative paths by falling back to path-only data when parsing fails. It does
 * not validate outbound destinations, enforce allow-lists, or make a URL safe
 * for redirects or server-side fetches.
 *
 * Instances are value objects: every mutator returns a new instance and never
 * changes the parsed snapshot held by the current object.
 */
final class UrlValue implements \JsonSerializable {

	private readonly array $parts;
	private readonly array $query;
	private readonly string $base;
	private ?array $arrayPayload=null;

	/**
	 * Creates a parsed URL value from an untrusted or already-normalized string.
	 *
	 * The constructor does not validate that the input is an absolute URL. Partial
	 * URLs, protocol-relative URLs, relative paths, and opaque strings are accepted
	 * because Dataphyre uses this object for both external endpoints and internal
	 * route fragments. Query parameters are parsed with `parse_str()`, so repeated
	 * keys and bracket notation follow PHP's standard array semantics.
	 *
	 * @param string $raw Original URL string to preserve for serialization and string casts.
	 */
	public function __construct(
		private readonly string $raw
	){
		$parts=parse_url($raw);
		$this->parts=is_array($parts) ? $parts : ['path'=>$raw];
		$query=[];
		if(isset($this->parts['query']) && is_string($this->parts['query']) && $this->parts['query']!==''){
			parse_str($this->parts['query'], $query);
		}
		$this->query=$query;
		$baseParts=$this->parts;
		unset($baseParts['query'], $baseParts['fragment']);
		$this->base=static::build($baseParts);
	}

	/**
	 * Creates a URL value from a string factory call.
	 *
	 * This named constructor keeps call sites expressive when converting config,
	 * request, or service endpoint strings into an immutable URL value.
	 *
	 * @param string $url URL, relative path, or URL-like string to parse.
	 * @return self Parsed immutable URL value.
	 */
	public static function fromString(string $url): self {
		return new self($url);
	}

	/**
	 * Returns the exact input string captured by the constructor.
	 *
	 * @return string Original URL string without reconstruction or normalization.
	 */
	public function raw(): string {
		return $this->raw;
	}

	/**
	 * Returns the parsed URL scheme.
	 *
	 * The scheme is returned without `://` and without case normalization, matching
	 * the component value produced by `parse_url()`.
	 *
	 * @return ?string Scheme such as `https`, or null when the input is relative or malformed for that component.
	 */
	public function scheme(): ?string {
		return $this->stringPart('scheme');
	}

	/**
	 * Returns the parsed URL host.
	 *
	 * Host values are surfaced as parsed and are not IDNA-normalized, lowercased,
	 * or validated. Callers that enforce outbound network policy should normalize
	 * and validate the host before connecting.
	 *
	 * @return ?string Host component, or null for relative URLs and path-only values.
	 */
	public function host(): ?string {
		return $this->stringPart('host');
	}

	/**
	 * Returns the parsed port number.
	 *
	 *
	 */
	public function port(): ?int {
		return isset($this->parts['port']) ? (int)$this->parts['port'] : null;
	}

	/**
	 * Returns the parsed user-info username.
	 *
	 *
	 */
	public function user(): ?string {
		return $this->stringPart('user');
	}

	/**
	 * Returns the parsed user-info password.
	 *
	 *
	 */
	public function pass(): ?string {
		return $this->stringPart('pass');
	}

	/**
	 * Returns the parsed path component.
	 *
	 * Path-only and parse-fallback inputs are represented as the path. Missing
	 * paths return an empty string so path consumers can avoid null handling.
	 *
	 * @return string Path component or an empty string when no path exists.
	 */
	public function path(): string {
		return $this->stringPart('path') ?? '';
	}

	/**
	 * Returns the parsed fragment without the leading hash marker.
	 *
	 *
	 */
	public function fragment(): ?string {
		return $this->stringPart('fragment');
	}

	/**
	 * Returns the parsed query parameters.
	 * Values follow `parse_str()` semantics, so repeated or bracketed keys may
	 * produce nested arrays.
	 *
	 * @return array<string,mixed> Parsed query parameters keyed by top-level name.
	 */
	public function query(): array {
		return $this->query;
	}

	/**
	 * Reports whether the parsed query contains a specific top-level key.
	 *
	 * Empty or whitespace-only keys are always false. The lookup uses
	 * `array_key_exists()` so keys with null values are still considered present.
	 *
	 * @param string $key Query key to check after trimming whitespace.
	 * @return bool True when the parsed query contains the requested key.
	 */
	public function hasQuery(string $key): bool {
		$key=trim($key);
		return $key!=='' && array_key_exists($key, $this->query);
	}

	/**
	 * Returns a parsed query value with a caller-supplied fallback.
	 *
	 * Values may be strings, arrays, or null depending on how PHP parsed the query
	 * string. Empty keys are treated as absent.
	 *
	 * @param string $key Query key to read after trimming whitespace.
	 * @param mixed $default Value returned when the key is empty or absent.
	 * @return mixed parsed query value, including arrays and null, or the caller default for empty/absent keys.
	 */
	public function queryValue(string $key, mixed $default=null): mixed {
		$key=trim($key);
		return $key!=='' && array_key_exists($key, $this->query) ? $this->query[$key] : $default;
	}

	/**
	 * Reports whether the value names an absolute or protocol-relative target.
	 *
	 * A scheme or a host is enough to make the value absolute for Dataphyre's URL
	 * composition needs. Protocol-relative URLs therefore count as absolute even
	 * when no scheme is present.
	 *
	 * @return bool True when a scheme or host component exists.
	 */
	public function isAbsolute(): bool {
		return $this->scheme()!==null || $this->host()!==null;
	}

	/**
	 * Reports whether the parsed scheme is HTTPS.
	 *
	 *
	 */
	public function isSecure(): bool {
		return strtolower((string)$this->scheme())==='https';
	}

	/**
	 * Returns the URL reconstructed without query string or fragment.
	 *
	 * The base is rebuilt from parsed components, so it may normalize missing
	 * slashes between host and path even though `raw()` continues to preserve the
	 * original input.
	 *
	 * @return string Reconstructed URL containing scheme, authority, and path only.
	 */
	public function base(): string {
		return $this->base;
	}

	/**
	 * Returns a new URL value after merging or replacing query parameters.
	 *
	 * Query manipulation is delegated to `Url::withQuery()` so fluent URL values
	 * follow the same encoding and removal rules as the procedural core helper.
	 *
	 * @param array|null $value Query values to add or merge; null leaves additions empty.
	 * @param array|null|bool $remove Removal rule forwarded to `Url::withQuery()`.
	 * @return self New URL value containing the updated query string.
	 */
	public function withQuery(array|null $value=null, array|null|bool $remove=false): self {
		return new self(Url::withQuery($this->raw, $value, $remove));
	}

	/**
	 * Returns a new URL value after removing query parameters.
	 *
	 * Passing `true` removes the whole query string. Passing an array removes only
	 * the named keys according to the core URL helper's rules.
	 *
	 * @param array|null|bool $remove Removal rule forwarded to `Url::withQuery()`.
	 * @return self New URL value with the requested query parameters removed.
	 */
	public function withoutQuery(array|null|bool $remove=true): self {
		return $this->withQuery(null, $remove);
	}

	/**
	 * Returns a new URL value with a replacement path component.
	 *
	 * Existing scheme, host, user-info, port, query, and fragment components are
	 * preserved. The builder adds the missing slash between host and path when an
	 * absolute URL receives a relative-looking path.
	 *
	 * @param string $path Replacement path component.
	 * @return self New URL value with the requested path.
	 */
	public function withPath(string $path): self {
		$parts=$this->parts;
		$parts['path']=$path;
		return new self(static::build($parts));
	}

	/**
	 * Returns a new URL value with a replacement fragment.
	 *
	 * Null and blank fragments remove the fragment component. Non-empty fragments
	 * are trimmed of leading and trailing hash characters before reconstruction.
	 *
	 * @param ?string $fragment Replacement fragment, with or without leading `#`.
	 * @return self New URL value with the requested fragment state.
	 */
	public function withFragment(?string $fragment): self {
		$parts=$this->parts;
		if($fragment===null || trim($fragment)===''){
			unset($parts['fragment']);
		}else{
			$parts['fragment']=trim($fragment, '#');
		}
		return new self(static::build($parts));
	}

	/**
	 * Returns a diagnostic array describing the parsed URL value.
	 *
	 * The array intentionally excludes the parsed password component so logs,
	 * examples, and JSON serialization avoid exposing user-info secrets by
	 * default. `raw()` still preserves the full input for callers that explicitly
	 * need the original string.
	 *
	 * @return array{raw:string,scheme:?string,host:?string,port:?int,user:?string,path:string,query:array,fragment:?string,is_absolute:bool,is_secure:bool,base:string} Parsed URL diagnostics without password data.
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		$scheme=$this->stringPart('scheme');
		$host=$this->stringPart('host');
		$path=$this->stringPart('path') ?? '';
		return $this->arrayPayload=[
			'raw'=>$this->raw,
			'scheme'=>$scheme,
			'host'=>$host,
			'port'=>$this->port(),
			'user'=>$this->stringPart('user'),
			'path'=>$path,
			'query'=>$this->query,
			'fragment'=>$this->stringPart('fragment'),
			'is_absolute'=>$scheme!==null || $host!==null,
			'is_secure'=>strtolower((string)$scheme)==='https',
			'base'=>$this->base(),
		];
	}

	/**
	 * Serializes the parsed URL summary for JSON output.
	 *
	 * JSON consumers receive the same redacted diagnostic shape as `toArray()`.
	 *
	 * @return array{raw:string,scheme:?string,host:?string,port:?int,user:?string,path:string,query:array,fragment:?string,is_absolute:bool,is_secure:bool,base:string} Parsed URL diagnostics without password data.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Returns the original URL string for casts and interpolation.
	 *
	 * @return string Original input string.
	 */
	public function __toString(): string {
		return $this->raw;
	}

	/**
	 * Reads a parsed string component when it exists and is non-empty.
	 *
	 * `parse_url()` may return integers for ports and omit components entirely.
	 * This helper keeps string-facing accessors consistent by returning null for
	 * missing, empty, or non-string parts.
	 *
	 * @param string $key Component key from the parsed URL part map.
	 * @return ?string Non-empty string component or null.
	 */
	private function stringPart(string $key): ?string {
		$value=$this->parts[$key] ?? null;
		return is_string($value) && $value!=='' ? $value : null;
	}

	/**
	 * Reconstructs a URL string from parsed component parts.
	 *
	 * The builder preserves user-info, host, port, path, query, and fragment
	 * fields without percent-encoding changes. It adds authority separators and a
	 * host/path slash when needed so updated value objects remain usable after a
	 * component-level mutation.
	 *
	 * @param array<string,mixed> $parts Parsed URL component map.
	 * @return string Reconstructed URL or path-like string.
	 */
	private static function build(array $parts): string {
		$url='';
		$scheme=isset($parts['scheme']) ? trim((string)$parts['scheme']) : '';
		$host=isset($parts['host']) ? trim((string)$parts['host']) : '';
		$user=isset($parts['user']) ? (string)$parts['user'] : '';
		$pass=isset($parts['pass']) ? (string)$parts['pass'] : '';
		$port=isset($parts['port']) ? (int)$parts['port'] : null;
		$path=isset($parts['path']) ? (string)$parts['path'] : '';
		$query=isset($parts['query']) ? (string)$parts['query'] : '';
		$fragment=isset($parts['fragment']) ? trim((string)$parts['fragment'], '#') : '';

		if($scheme!==''){
			$url.=$scheme.'://';
		}elseif($host!==''){
			$url.='//';
		}

		if($host!==''){
			if($user!==''){
				$url.=$user;
				if($pass!==''){
					$url.=':'.$pass;
				}
				$url.='@';
			}
			$url.=$host;
			if($port!==null){
				$url.=':'.$port;
			}
		}

		if($path!==''){
			if($host!=='' && $path[0]!=='/'){
				$url.='/'.$path;
			}else{
				$url.=$path;
			}
		}

		if($query!==''){
			$url.='?'.$query;
		}

		if($fragment!==''){
			$url.='#'.$fragment;
		}

		return $url;
	}
}
