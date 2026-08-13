<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable tenant storage namespace with traversal and link containment checks. */
final class PanelTenantStorageScope implements \JsonSerializable {

	/** @param list<string> $namespace */
	private function __construct(private readonly string $tenant, private readonly array $namespace=[]){ }

	/** @param string|list<string> $namespace */
	public static function make(string $tenant, string|array $namespace=[]): self {
		$tenantSegments=self::segments([$tenant], false);
		if(count($tenantSegments)!==1){ throw new \InvalidArgumentException('Tenant storage scopes require one safe tenant segment.'); }
		return new self(strtolower($tenantSegments[0]), self::segments($namespace));
	}

	public function tenant(): string { return $this->tenant; }
	/** @return list<string> */
	public function namespaceSegments(): array { return $this->namespace; }

	public function relativeRoot(): string {
		return implode('/', ['tenants', $this->tenant, ...$this->namespace]);
	}

	public function namespaceKey(): string {
		return implode(':', [$this->tenant, ...$this->namespace]);
	}

	/**
	 * Resolves the tenant namespace beneath an existing base and tenant root.
	 * Existing symlinks/junctions are dereferenced and must remain contained.
	 */
	public function filesystemRoot(string $baseRoot): string {
		[$baseReal,$tenantReal]=$this->filesystemRoots($baseRoot);
		unset($baseReal);
		return self::walkContained($tenantReal, $tenantReal, $this->namespace);
	}

	/** Resolves a safe relative path beneath this tenant's real root. */
	public function resolvePath(string $baseRoot, string|array $relative=[]): string {
		[, $tenantReal]=$this->filesystemRoots($baseRoot);
		$scope=self::walkContained($tenantReal, $tenantReal, $this->namespace);
		return self::walkContained($tenantReal, $scope, self::segments($relative));
	}

	/** Returns true only for an existing path whose real path stays in this tenant root. */
	public function containsPath(string $baseRoot, string $path): bool {
		try{
			$scopeRoot=$this->filesystemRoot($baseRoot);
			$real=realpath($path);
			return is_string($real) && self::contained($scopeRoot, $real);
		}
		catch(\Throwable){ return false; }
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'tenant'=>$this->tenant,
			'namespace'=>$this->namespace,
			'namespace_key'=>$this->namespaceKey(),
			'relative_root'=>$this->relativeRoot(),
			'filesystem_containment'=>'realpath_required',
			'link_aware'=>true,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return array{string,string} */
	private function filesystemRoots(string $baseRoot): array {
		$baseReal=realpath($baseRoot);
		if(!is_string($baseReal) || !is_dir($baseReal)){ throw new \InvalidArgumentException('Tenant storage base root must be an existing directory.'); }
		$tenantCandidate=$baseReal.DIRECTORY_SEPARATOR.'tenants'.DIRECTORY_SEPARATOR.$this->tenant;
		$tenantReal=realpath($tenantCandidate);
		if(!is_string($tenantReal) || !is_dir($tenantReal)){ throw new \RuntimeException('Tenant storage root does not exist.'); }
		self::assertContained($baseReal, $tenantReal);
		if(!self::samePath($tenantCandidate, $tenantReal)){
			throw new \RuntimeException('Tenant storage root may not alias another filesystem location.');
		}
		return [$baseReal,$tenantReal];
	}

	/** @param list<string> $segments */
	private static function walkContained(string $rootReal, string $start, array $segments): string {
		$current=$start;
		self::assertContained($rootReal, $current);
		foreach($segments as $segment){
			$candidate=rtrim($current, '\\/').DIRECTORY_SEPARATOR.$segment;
			if(file_exists($candidate) || is_link($candidate)){
				$resolved=realpath($candidate);
				if(!is_string($resolved)){ throw new \RuntimeException('Tenant storage path contains a broken link.'); }
				self::assertContained($rootReal, $resolved);
				$current=$resolved;
				continue;
			}
			$current=$candidate;
		}
		return $current;
	}

	private static function assertContained(string $root, string $path): void {
		if(!self::contained($root, $path)){ throw new \RuntimeException('Tenant storage path escapes its tenant root.'); }
	}

	private static function contained(string $root, string $path): bool {
		$root=self::normalizedPath($root);
		$path=self::normalizedPath($path);
		return $path===$root || str_starts_with($path, $root.'/');
	}

	private static function samePath(string $left, string $right): bool {
		return self::normalizedPath($left)===self::normalizedPath($right);
	}

	private static function normalizedPath(string $path): string {
		$path=str_replace('\\', '/', rtrim($path, '\\/'));
		if(PanelFilesystemPath::usesWindowsSemantics($path)){
			$path=strtolower($path);
		}
		return $path;
	}

	/** @param string|list<string> $value @return list<string> */
	private static function segments(string|array $value, bool $split=true): array {
		$values=is_array($value) ? $value : ($value==='' ? [] : ($split ? preg_split('~[\\\\/]~', $value) : [$value]));
		if(!is_array($values)){ throw new \InvalidArgumentException('Tenant storage namespace is invalid.'); }
		$segments=[];
		foreach($values as $segment){
			if(!is_string($segment) && !is_int($segment)){ throw new \InvalidArgumentException('Tenant storage segments must be strings.'); }
			$segment=(string)$segment;
			$decoded=$segment;
			for($pass=0;$pass<3;$pass++){
				$next=rawurldecode($decoded);
				if($next===$decoded){ break; }
				$decoded=$next;
			}
			if($segment==='' || $decoded!==$segment || trim($segment)!==$segment || preg_match('/[\x00-\x1F\x7F]/', $segment)===1){
				throw new \InvalidArgumentException('Tenant storage segments may not be blank, encoded, padded, or contain controls.');
			}
			$lower=strtolower($segment);
			if(in_array($lower, ['.', '..', '.git', '.svn', '$recycle.bin', 'system volume information'], true)
				|| str_ends_with($segment, '.')
				|| preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?$/i', $segment)===1){
				throw new \InvalidArgumentException('Tenant storage segment is reserved.');
			}
			if(strlen($segment)>100 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $segment)!==1){
				throw new \InvalidArgumentException('Tenant storage segment contains unsupported characters.');
			}
			$segments[]=$segment;
		}
		return $segments;
	}
}
