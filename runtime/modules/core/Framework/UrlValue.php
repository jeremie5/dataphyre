<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class UrlValue implements \JsonSerializable {

	private readonly array $parts;
	private readonly array $query;

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
	}

	public static function fromString(string $url): self {
		return new self($url);
	}

	public function raw(): string {
		return $this->raw;
	}

	public function scheme(): ?string {
		return $this->stringPart('scheme');
	}

	public function host(): ?string {
		return $this->stringPart('host');
	}

	public function port(): ?int {
		return isset($this->parts['port']) ? (int)$this->parts['port'] : null;
	}

	public function user(): ?string {
		return $this->stringPart('user');
	}

	public function pass(): ?string {
		return $this->stringPart('pass');
	}

	public function path(): string {
		return $this->stringPart('path') ?? '';
	}

	public function fragment(): ?string {
		return $this->stringPart('fragment');
	}

	public function query(): array {
		return $this->query;
	}

	public function hasQuery(string $key): bool {
		$key=trim($key);
		return $key!=='' && array_key_exists($key, $this->query);
	}

	public function queryValue(string $key, mixed $default=null): mixed {
		$key=trim($key);
		return $key!=='' && array_key_exists($key, $this->query) ? $this->query[$key] : $default;
	}

	public function isAbsolute(): bool {
		return $this->scheme()!==null || $this->host()!==null;
	}

	public function isSecure(): bool {
		return strtolower((string)$this->scheme())==='https';
	}

	public function base(): string {
		$parts=$this->parts;
		unset($parts['query'], $parts['fragment']);
		return static::build($parts);
	}

	public function withQuery(array|null $value=null, array|null|bool $remove=false): self {
		return new self(Url::withQuery($this->raw, $value, $remove));
	}

	public function withoutQuery(array|null|bool $remove=true): self {
		return $this->withQuery(null, $remove);
	}

	public function withPath(string $path): self {
		$parts=$this->parts;
		$parts['path']=$path;
		return new self(static::build($parts));
	}

	public function withFragment(?string $fragment): self {
		$parts=$this->parts;
		if($fragment===null || trim($fragment)===''){
			unset($parts['fragment']);
		}else{
			$parts['fragment']=trim($fragment, '#');
		}
		return new self(static::build($parts));
	}

	public function toArray(): array {
		return [
			'raw'=>$this->raw,
			'scheme'=>$this->scheme(),
			'host'=>$this->host(),
			'port'=>$this->port(),
			'user'=>$this->user(),
			'path'=>$this->path(),
			'query'=>$this->query,
			'fragment'=>$this->fragment(),
			'is_absolute'=>$this->isAbsolute(),
			'is_secure'=>$this->isSecure(),
			'base'=>$this->base(),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	public function __toString(): string {
		return $this->raw;
	}

	private function stringPart(string $key): ?string {
		$value=$this->parts[$key] ?? null;
		return is_string($value) && $value!=='' ? $value : null;
	}

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
