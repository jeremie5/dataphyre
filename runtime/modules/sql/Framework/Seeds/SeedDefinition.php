<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Seeds;

use Closure;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable description of one versioned, application-owned database seed.
 *
 * A definition owns behavior but not execution state. SeedManager invokes the
 * callbacks and a SeedLedger records successful applications. File-loaded
 * definitions automatically receive a checksum derived from their source file.
 */
final class SeedDefinition implements JsonSerializable {
	private const MAXIMUM_CONTENT_SOURCES=64;
	private const MAXIMUM_CONTENT_SOURCE_FILE_BYTES=8388608;
	private const MAXIMUM_CONTENT_SOURCE_FILES=4096;
	private const MAXIMUM_CONTENT_SOURCE_AGGREGATE_BYTES=67108864;

	private Closure $up;
	private ?Closure $down;
	private ?Closure $preflight;
	private bool $explicit_checksum;

	/**
	 * @param callable(SeedContext):mixed $up Idempotent seed callback.
	 * @param callable(SeedContext):mixed|null $down Optional explicit rollback callback.
	 * @param list<string> $dependencies Seed ids or exact `id@version` keys.
	 */
	public function __construct(
		private string $id,
		private int $version,
		callable $up,
		?callable $down=null,
		private string $description='',
		private array $dependencies=[],
		private string $checksum='',
		private ?string $source=null,
		private array $profiles=['default'],
		private array $content_sources=[],
		?callable $preflight=null,
		private array $accepted_checksums=[],
	) {
		$this->id=self::normalizeReference($id, false);
		if($version<1){
			throw new InvalidArgumentException('Seed version must be a positive integer.');
		}
		$this->description=trim($description);
		$this->dependencies=self::normalizeDependencies($dependencies);
		$this->profiles=self::normalizeProfiles($profiles);
		$this->content_sources=self::normalizeContentSources($content_sources);
		$this->accepted_checksums=self::normalizeAcceptedChecksums($accepted_checksums);
		$this->explicit_checksum=trim($checksum)!=='';
		$this->checksum=self::normalizeChecksum($checksum, $this->id, $version, $this->description, $this->dependencies);
		$this->source=$source!==null && trim($source)!=='' ? str_replace('\\', '/', trim($source)) : null;
		$this->up=Closure::fromCallable($up);
		$this->down=$down!==null ? Closure::fromCallable($down) : null;
		$this->preflight=$preflight!==null ? Closure::fromCallable($preflight) : null;
		if(in_array($this->key(), $this->dependencies, true)){
			throw new InvalidArgumentException('A seed cannot depend on itself.');
		}
	}

	/** @param array<string,mixed> $definition */
	public static function fromArray(array $definition): self {
		$up=$definition['up'] ?? $definition['apply'] ?? null;
		if(!is_callable($up)){
			throw new InvalidArgumentException('Seed array definitions require a callable up/apply value.');
		}
		$down=$definition['down'] ?? $definition['rollback'] ?? null;
		if($down!==null && !is_callable($down)){
			throw new InvalidArgumentException('Seed rollback/down must be callable when provided.');
		}
		$preflight=$definition['preflight'] ?? $definition['check'] ?? null;
		if($preflight!==null && !is_callable($preflight)){
			throw new InvalidArgumentException('Seed preflight/check must be callable when provided.');
		}
		return new self(
			(string)($definition['id'] ?? ''),
			(int)($definition['version'] ?? 1),
			$up,
			$down,
			(string)($definition['description'] ?? ''),
			is_array($definition['dependencies'] ?? null) ? array_values($definition['dependencies']) : [],
			(string)($definition['checksum'] ?? ''),
			isset($definition['source']) ? (string)$definition['source'] : null,
			is_array($definition['profiles'] ?? null) ? array_values($definition['profiles']) : ['default'],
			is_array($definition['content_sources'] ?? null) ? array_values($definition['content_sources']) : [],
			$preflight,
			is_array($definition['accepted_checksums'] ?? null) ? array_values($definition['accepted_checksums']) : [],
		);
	}

	/**
	 * Returns a copy associated with a source file and its content checksum.
	 *
	 * Content sources are immutable regular files beneath the caller-selected
	 * application root. The optional inventory is shared by SeedFileLoader so a
	 * whole discovery operation has one count/byte budget and fingerprints a
	 * repeated source only once.
	 *
	 * @param array<string,array{bytes:int,sha256:string}>|null $content_inventory
	 */
	public function withSource(
		string $source,
		string $source_checksum,
		?string $content_root=null,
		?array &$content_inventory=null,
		?int &$content_bytes=null,
	): self {
		$source_checksum=strtolower(trim($source_checksum));
		if(preg_match('/^[a-f0-9]{64}$/', $source_checksum)!==1){
			throw new InvalidArgumentException('Seed source checksum must be a SHA-256 hexadecimal digest.');
		}
		$resolved_source=realpath($source);
		if(!is_string($resolved_source) || is_link($source) || !is_file($resolved_source)){
			throw new InvalidArgumentException('Seed source must be a regular non-symbolic file.');
		}
		$requested_root=$content_root ?? dirname($resolved_source);
		$root=realpath($requested_root);
		if(!is_string($root) || is_link($requested_root) || !is_dir($root)){
			throw new InvalidArgumentException('Seed content root must be a regular non-symbolic directory.');
		}
		$root=self::normalizeAbsolutePath($root);
		$resolved_source=self::normalizeAbsolutePath($resolved_source);
		if(!self::isWithinRoot($resolved_source,$root)){
			throw new InvalidArgumentException('Seed definition escaped its content root.');
		}
		if($content_inventory===null) $content_inventory=[];
		if($content_bytes===null) $content_bytes=0;
		$content_fingerprints=[];
		foreach($this->content_sources as $content_source){
			$candidate=self::lexicalAbsolutePath($content_source,dirname($resolved_source));
			if(!self::isWithinRoot($candidate,$root) || self::containsSymbolicLink($candidate,$root)){
				throw new InvalidArgumentException('Seed content source escaped its content root: '.$content_source);
			}
			$resolved=realpath($candidate);
			if(!is_string($resolved) || is_link($candidate) || !is_file($resolved) || !is_readable($resolved)){
				throw new InvalidArgumentException('Seed content source does not exist: '.$content_source);
			}
			$resolved=self::normalizeAbsolutePath($resolved);
			if(!hash_equals($candidate,$resolved) || !self::isWithinRoot($resolved,$root)){
				throw new InvalidArgumentException('Seed content source must resolve without indirection: '.$content_source);
			}
			if(!isset($content_inventory[$resolved])){
				$bytes=filesize($resolved);
				if(!is_int($bytes) || $bytes<0 || $bytes>self::MAXIMUM_CONTENT_SOURCE_FILE_BYTES){
					throw new InvalidArgumentException('Seed content source exceeded its byte bound: '.$content_source);
				}
				if(count($content_inventory)>=self::MAXIMUM_CONTENT_SOURCE_FILES
					|| $content_bytes+$bytes>self::MAXIMUM_CONTENT_SOURCE_AGGREGATE_BYTES){
					throw new InvalidArgumentException('Seed content source inventory exceeded its bound.');
				}
				$fingerprint=hash_file('sha256',$resolved);
				clearstatcache(true,$resolved);
				$bytes_after=filesize($resolved);
				$fingerprint_after=hash_file('sha256',$resolved);
				if(!is_string($fingerprint) || !is_string($fingerprint_after)
					|| !is_int($bytes_after) || $bytes_after!==$bytes
					|| !hash_equals($fingerprint,$fingerprint_after)){
					throw new InvalidArgumentException('Seed content source changed while it was fingerprinted: '.$content_source);
				}
				$content_inventory[$resolved]=['bytes'=>$bytes,'sha256'=>strtolower($fingerprint)];
				$content_bytes+=$bytes;
			}
			$content_fingerprints[]=$content_inventory[$resolved]['sha256'];
		}
		$checksum=hash('sha256', implode("\0", [
			'dataphyre-seed-v1',
			$this->key(),
			$source_checksum,
			$this->checksum,
			...$content_fingerprints,
		]));
		return new self(
			$this->id,
			$this->version,
			$this->up,
			$this->down,
			$this->description,
			$this->dependencies,
			$checksum,
			$source,
			$this->profiles,
			$this->content_sources,
			$this->preflight,
			$this->accepted_checksums,
		);
	}

	public function id(): string { return $this->id; }
	public function version(): int { return $this->version; }
	public function key(): string { return $this->id.'@'.$this->version; }
	public function description(): string { return $this->description; }
	/** @return list<string> */
	public function dependencies(): array { return $this->dependencies; }
	public function checksum(): string { return $this->checksum; }
	public function acceptsChecksum(string $checksum): bool {
		$checksum=strtolower(trim($checksum));
		return hash_equals($this->checksum, $checksum) || in_array($checksum, $this->accepted_checksums, true);
	}
	public function source(): ?string { return $this->source; }
	/** @return list<string> */
	public function profiles(): array { return $this->profiles; }
	/** @return list<string> */
	public function contentSources(): array { return $this->content_sources; }
	public function hasContentFingerprint(): bool { return $this->explicit_checksum; }
	public function hasRollback(): bool { return $this->down!==null; }
	public function hasPreflight(): bool { return $this->preflight!==null; }
	/** @param array<string,true> $profiles */
	public function supportsProfiles(array $profiles): bool {
		foreach($this->profiles as $profile){
			if(isset($profiles[$profile])) return true;
		}
		return false;
	}
	public function matches(string $selector): bool {
		$selector=trim($selector);
		return $selector===$this->id || $selector===$this->key();
	}

	public function apply(SeedContext $context): mixed {
		return ($this->up)($context);
	}

	public function preflight(SeedContext $context): mixed {
		return $this->preflight!==null ? ($this->preflight)($context) : null;
	}

	public function rollback(SeedContext $context): mixed {
		if($this->down===null){
			throw new \RuntimeException('Seed '.$this->key().' does not define a rollback callback.');
		}
		return ($this->down)($context);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'id'=>$this->id,
			'version'=>$this->version,
			'key'=>$this->key(),
			'description'=>$this->description,
			'dependencies'=>$this->dependencies,
			'profiles'=>$this->profiles,
			'checksum'=>$this->checksum,
			'accepted_checksums'=>$this->accepted_checksums,
			'preflight_available'=>$this->hasPreflight(),
			'content_sources'=>$this->content_sources,
			'rollback_available'=>$this->hasRollback(),
			'source'=>$this->source,
		];
	}

	private static function normalizeReference(string $reference, bool $allow_version): string {
		$reference=strtolower(trim($reference));
		$pattern=$allow_version
			? '/^[a-z][a-z0-9._:-]{0,190}(?:@[1-9][0-9]*)?$/'
			: '/^[a-z][a-z0-9._:-]{0,190}$/';
		if(preg_match($pattern, $reference)!==1){
			throw new InvalidArgumentException('Invalid seed id or dependency reference: '.$reference);
		}
		return $reference;
	}

	/** @param list<mixed> $dependencies @return list<string> */
	private static function normalizeDependencies(array $dependencies): array {
		$normalized=[];
		foreach($dependencies as $dependency){
			$dependency=self::normalizeReference((string)$dependency, true);
			$normalized[$dependency]=$dependency;
		}
		return array_values($normalized);
	}

	/** @param list<mixed> $profiles @return list<string> */
	private static function normalizeProfiles(array $profiles): array {
		$normalized=[];
		foreach($profiles as $profile){
			$profile=strtolower(trim((string)$profile));
			if(preg_match('/^[a-z][a-z0-9._:-]{0,63}$/', $profile)!==1){
				throw new InvalidArgumentException('Invalid seed profile: '.$profile);
			}
			$normalized[$profile]=$profile;
		}
		if($normalized===[]){
			throw new InvalidArgumentException('A seed requires at least one execution profile.');
		}
		return array_values($normalized);
	}

	/** @param list<mixed> $sources @return list<string> */
	private static function normalizeContentSources(array $sources): array {
		$normalized=[];
		foreach($sources as $source){
			$source=str_replace('\\', '/', trim((string)$source));
			$windows_absolute=strlen($source)>=3 && ctype_alpha($source[0] ?? '')
				&& $source[1]===':' && $source[2]==='/';
			if($source==='' || strlen($source)>4096 || preg_match('/[\x00-\x1f\x7f]/D',$source)===1){
				throw new InvalidArgumentException('Seed content source path is invalid.');
			}
			if($source[0]==='/' || $windows_absolute){
				throw new InvalidArgumentException('Seed content source paths must be relative.');
			}
			$normalized[$source]=$source;
			if(count($normalized)>self::MAXIMUM_CONTENT_SOURCES){
				throw new InvalidArgumentException('Seed content source list exceeded its bound.');
			}
		}
		return array_values($normalized);
	}

	/** @param list<mixed> $checksums @return list<string> */
	private static function normalizeAcceptedChecksums(array $checksums): array {
		$normalized=[];
		foreach($checksums as $checksum){
			$checksum=strtolower(trim((string)$checksum));
			if(preg_match('/^[a-f0-9]{64}$/', $checksum)!==1){
				throw new InvalidArgumentException('Accepted seed checksums must be SHA-256 hexadecimal digests.');
			}
			$normalized[$checksum]=$checksum;
		}
		return array_values($normalized);
	}

	private static function lexicalAbsolutePath(string $source,string $base): string {
		$path=self::normalizeAbsolutePath(rtrim($base,'/').'/'.$source);
		$prefix=str_starts_with($path,'/') ? '/' : '';
		$parts=[];
		foreach(explode('/',trim($path,'/')) as $part){
			if($part==='' || $part==='.') continue;
			if($part==='..'){
				if($parts===[]) throw new InvalidArgumentException('Seed content source path is invalid.');
				array_pop($parts);
				continue;
			}
			$parts[]=$part;
		}
		return $prefix.implode('/',$parts);
	}

	private static function normalizeAbsolutePath(string $path): string {
		$normalized=str_replace('\\','/',$path);
		return $normalized==='/' ? '/' : rtrim($normalized,'/');
	}

	private static function isWithinRoot(string $path,string $root): bool {
		return hash_equals($path,$root) || str_starts_with($path,rtrim($root,'/').'/');
	}

	private static function containsSymbolicLink(string $path,string $root): bool {
		if(!self::isWithinRoot($path,$root)) return true;
		$relative=ltrim(substr($path,strlen($root)),'/');
		$current=$root;
		foreach($relative==='' ? [] : explode('/',$relative) as $part){
			$current.='/'.$part;
			if(is_link($current)) return true;
		}
		return false;
	}

	/** @param list<string> $dependencies */
	private static function normalizeChecksum(string $checksum, string $id, int $version, string $description, array $dependencies): string {
		$checksum=strtolower(trim($checksum));
		if($checksum===''){
			return hash('sha256', $id."\0".$version."\0".$description."\0".implode("\0", $dependencies));
		}
		if(preg_match('/^[a-f0-9]{64}$/', $checksum)!==1){
			throw new InvalidArgumentException('Seed checksum must be a SHA-256 hexadecimal digest.');
		}
		return $checksum;
	}
}
