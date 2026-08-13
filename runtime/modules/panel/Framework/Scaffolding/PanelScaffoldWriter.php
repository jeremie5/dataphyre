<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Persists generated artifacts as one root-confined, preflighted transaction.
 *
 * The legacy `PanelScaffoldResult::write()` method remains available for small
 * callers. Production tooling should use this writer: it rejects traversal and
 * symlink escapes, detects duplicate destinations, stages every artifact before
 * publication, detects stale targets, and rolls back an incomplete commit.
 */
final class PanelScaffoldWriter {

	private function __construct(private readonly string $root){}

	public static function make(string $root): self {
		$root=trim($root);
		if($root==='' || !is_dir($root)){
			throw new \InvalidArgumentException('Panel scaffold workspace root must be an existing directory.');
		}
		if(is_link($root)){
			throw new \InvalidArgumentException('Panel scaffold workspace root cannot be a symbolic link.');
		}
		$resolved=realpath($root);
		if($resolved===false){
			throw new \RuntimeException('Unable to resolve the Panel scaffold workspace root.');
		}
		return new self(rtrim($resolved, '/\\'));
	}

	public function root(): string {
		return $this->root;
	}

	/**
	 * Discovers the namespace mapped to a project-relative source directory.
	 *
	 * @return array{namespace:string,base_path:string,source:string}
	 */
	public static function discoverNamespace(string $root, string $directory='app/Panel'): array {
		$writer=self::make($root);
		$relative=self::relativePath($directory);
		if($relative===''){
			throw new \InvalidArgumentException('Panel scaffold namespace directory cannot be empty.');
		}
		$composer=$writer->root.DIRECTORY_SEPARATOR.'composer.json';
		if(is_file($composer)){
			$contents=file_get_contents($composer);
			if($contents===false){
				throw new \RuntimeException('Unable to read composer.json for Panel namespace discovery.');
			}
			try{
				$decoded=json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
			}
			catch(\JsonException $exception){
				throw new \InvalidArgumentException('composer.json is invalid JSON.', 0, $exception);
			}
			$matches=[];
			foreach(['autoload'=>'composer','autoload-dev'=>'composer-dev'] as $section=>$source){
				$mappings=is_array($decoded[$section]['psr-4'] ?? null) ? $decoded[$section]['psr-4'] : [];
				foreach($mappings as $prefix=>$paths){
					foreach(is_array($paths) ? $paths : [$paths] as $path){
						$base=self::relativePath((string)$path);
						if($base!=='' && ($relative===$base || str_starts_with($relative.'/', $base.'/'))){
							$matches[]=['prefix'=>trim((string)$prefix, '\\'), 'base'=>$base, 'source'=>$source];
						}
					}
				}
			}
			usort($matches, static fn(array $left,array $right): int=>strlen($right['base'])<=>strlen($left['base']));
			if(isset($matches[0])){
				$suffix=trim(substr($relative, strlen($matches[0]['base'])), '/');
				$namespace=trim($matches[0]['prefix'].($suffix!=='' ? '\\'.self::namespaceFromPath($suffix) : ''), '\\');
				self::assertNamespace($namespace);
				return ['namespace'=>$namespace, 'base_path'=>$relative, 'source'=>$matches[0]['source']];
			}
		}
		return ['namespace'=>self::namespaceFromPath($relative), 'base_path'=>$relative, 'source'=>'convention'];
	}

	/**
	 * @param array<int,mixed> $artifacts
	 */
	public function apply(array $artifacts, string $policy='error', bool $dryRun=false): PanelScaffoldWriteResult {
		$policy=strtolower(trim($policy));
		if(!in_array($policy, ['error', 'skip', 'replace'], true)){
			throw new \InvalidArgumentException('Panel scaffold conflict policy must be error, skip, or replace.');
		}
		if($artifacts===[]){
			throw new \InvalidArgumentException('Panel scaffold transaction requires at least one artifact.');
		}
		$entries=[];
		$seen=[];
		foreach($artifacts as $artifact){
			if(!$artifact instanceof PanelScaffoldResult){
				throw new \InvalidArgumentException('Panel scaffold transactions accept only PanelScaffoldResult artifacts.');
			}
			$target=$this->target($artifact->path());
			$key=strtolower(str_replace('\\', '/', $target));
			if(isset($seen[$key])){
				throw new \LogicException('Panel scaffold artifacts resolve to the same target: '.$target);
			}
			$seen[$key]=true;
			$this->assertSafePath($target);
			if(is_dir($target)){
				throw new \RuntimeException('Panel scaffold target is a directory: '.$target);
			}
			$existingDigest=null;
			$operation='create';
			if(is_file($target)){
				$existingDigest=hash_file('sha256', $target);
				if(!is_string($existingDigest)){
					throw new \RuntimeException('Unable to hash existing Panel scaffold target: '.$target);
				}
				if(hash_equals($existingDigest, hash('sha256', $artifact->contents()))){
					$operation='identical';
				}
				elseif($policy==='error'){
					throw new \RuntimeException('Panel scaffold target already exists with different contents: '.$target);
				}
				else{
					$operation=$policy==='skip' ? 'skip' : 'replace';
				}
			}
			$entries[]=[
				'kind'=>$artifact->kind(),
				'name'=>$artifact->name(),
				'class'=>$artifact->class(),
				'path'=>$target,
				'operation'=>$operation,
				'bytes'=>strlen($artifact->contents()),
				'digest'=>hash('sha256', $artifact->contents()),
				'existing_digest'=>$existingDigest,
				'artifact'=>$artifact,
			];
		}
		$publicEntries=array_map([self::class, 'publicEntry'], $entries);
		$pending=array_values(array_filter($entries, static fn(array $entry): bool=>in_array($entry['operation'], ['create', 'replace'], true)));
		if($dryRun || $pending===[]){
			return new PanelScaffoldWriteResult($this->root, $policy, $dryRun, $publicEntries);
		}

		$transaction=$this->root.DIRECTORY_SEPARATOR.'.dataphyre-panel-scaffold-'.bin2hex(random_bytes(12));
		if(!mkdir($transaction, 0700) && !is_dir($transaction)){
			throw new \RuntimeException('Unable to create the Panel scaffold transaction directory.');
		}
		$committed=[];
		$createdDirectories=[];
		try{
			foreach($pending as $index=>$entry){
				$stage=$transaction.DIRECTORY_SEPARATOR.'artifact-'.$index;
				$written=file_put_contents($stage, $entry['artifact']->contents(), LOCK_EX);
				if($written!==$entry['bytes'] || !hash_equals($entry['digest'], (string)hash_file('sha256', $stage))){
					throw new \RuntimeException('Unable to stage Panel scaffold artifact: '.$entry['path']);
				}
				$pending[$index]['stage']=$stage;
			}
			foreach($pending as $index=>$entry){
				$this->assertSafePath($entry['path']);
				$this->assertUnchanged($entry);
				$this->ensureParent(dirname($entry['path']), $createdDirectories);
				$backup=null;
				$commitIndex=null;
				if($entry['operation']==='replace'){
					$backup=$transaction.DIRECTORY_SEPARATOR.'backup-'.$index;
					if(!rename($entry['path'], $backup)){
						throw new \RuntimeException('Unable to back up Panel scaffold target: '.$entry['path']);
					}
					$commitIndex=count($committed);
					$committed[]=['path'=>$entry['path'], 'backup'=>$backup, 'published'=>false];
				}
				if(!rename($entry['stage'], $entry['path'])){
					throw new \RuntimeException('Unable to publish Panel scaffold target: '.$entry['path']);
				}
				if($commitIndex!==null){
					$committed[$commitIndex]['published']=true;
				}
				else{
					$committed[]=['path'=>$entry['path'], 'backup'=>null, 'published'=>true];
				}
			}
		}
		catch(\Throwable $exception){
			$rollbackFailures=[];
			foreach(array_reverse($committed) as $commit){
				if(($commit['published'] ?? false)===true && (is_file($commit['path']) || is_link($commit['path'])) && !unlink($commit['path'])){
					$rollbackFailures[]='remove '.$commit['path'];
				}
				if(is_string($commit['backup']) && is_file($commit['backup']) && !rename($commit['backup'], $commit['path'])){
					$rollbackFailures[]='restore '.$commit['path'];
				}
			}
			foreach(array_reverse($createdDirectories) as $directory){
				if(is_dir($directory)){ @rmdir($directory); }
			}
			if($rollbackFailures===[]){
				self::removeTree($transaction);
				throw new \RuntimeException('Panel scaffold transaction failed and was rolled back: '.$exception->getMessage(), 0, $exception);
			}
			throw new \RuntimeException('Panel scaffold transaction failed; recovery artifacts were preserved at '.$transaction.' ('.implode(', ', $rollbackFailures).'): '.$exception->getMessage(), 0, $exception);
		}
		self::removeTree($transaction);
		return new PanelScaffoldWriteResult($this->root, $policy, false, $publicEntries);
	}

	/** @param array<string,mixed> $entry */
	private function assertUnchanged(array $entry): void {
		if($entry['operation']==='create'){
			if(file_exists($entry['path']) || is_link($entry['path'])){
				throw new \RuntimeException('Panel scaffold target appeared after preflight: '.$entry['path']);
			}
			return;
		}
		$current=is_file($entry['path']) ? hash_file('sha256', $entry['path']) : false;
		if(!is_string($current) || !hash_equals((string)$entry['existing_digest'], $current)){
			throw new \RuntimeException('Panel scaffold target changed after preflight: '.$entry['path']);
		}
	}

	private function target(string $path): string {
		$path=trim($path);
		if($path==='' || str_contains($path, "\0")){
			throw new \InvalidArgumentException('Panel scaffold target path cannot be empty or contain NUL bytes.');
		}
		$unix=str_replace('\\', '/', $path);
		$absolute=(bool)preg_match('/^[A-Za-z]:\//', $unix) || str_starts_with($unix, '/');
		if(!$absolute){
			$unix=str_replace('\\', '/', $this->root).'/'.$unix;
		}
		$prefix='/';
		if(preg_match('/^[A-Za-z]:\//', $unix)===1){
			$prefix=strtoupper(substr($unix, 0, 2));
			$unix=substr($unix, 2);
		}
		elseif(str_starts_with($unix, '//')){
			$prefix='//';
		}
		$parts=[];
		foreach(explode('/', $unix) as $part){
			if($part==='' || $part==='.'){ continue; }
			if($part==='..'){
				if($parts===[]){ throw new \InvalidArgumentException('Panel scaffold target escapes its workspace root.'); }
				array_pop($parts);
				continue;
			}
			self::assertSafeSegment($part, $prefix!=='/');
			$parts[]=$part;
		}
		$normalizedUnix=match($prefix){
			'//'=>'//'.implode('/', $parts),
			'/'=>'/'.implode('/', $parts),
			default=>$prefix.'/'.implode('/', $parts),
		};
		$normalized=DIRECTORY_SEPARATOR==='\\' ? str_replace('/', '\\', $normalizedUnix) : $normalizedUnix;
		if(!$this->withinRoot($normalized) || $normalized===$this->root){
			throw new \InvalidArgumentException('Panel scaffold target escapes its workspace root.');
		}
		return $normalized;
	}

	private function withinRoot(string $path): bool {
		$root=str_replace('\\', '/', $this->root);
		$path=str_replace('\\', '/', $path);
		if(PanelFilesystemPath::usesWindowsSemantics($root) || PanelFilesystemPath::usesWindowsSemantics($path)){
			$root=strtolower($root);
			$path=strtolower($path);
		}
		return str_starts_with($path.'/', $root.'/');
	}

	private function assertSafePath(string $target): void {
		$relative=ltrim(substr($target, strlen($this->root)), '/\\');
		$current=$this->root;
		foreach(explode(DIRECTORY_SEPARATOR, $relative) as $index=>$part){
			$current.=DIRECTORY_SEPARATOR.$part;
			if(is_link($current)){
				throw new \RuntimeException('Panel scaffold paths cannot traverse symbolic links: '.$current);
			}
			if($index<count(explode(DIRECTORY_SEPARATOR, $relative))-1 && file_exists($current) && !is_dir($current)){
				throw new \RuntimeException('Panel scaffold parent path is not a directory: '.$current);
			}
			if(is_dir($current)){
				$real=realpath($current);
				if($real===false || !$this->withinRoot($real)){
					throw new \RuntimeException('Panel scaffold path resolves outside its workspace root: '.$current);
				}
			}
		}
	}

	/** @param array<int,string> $created */
	private function ensureParent(string $directory, array &$created): void {
		if(is_dir($directory)){ return; }
		$relative=ltrim(substr($directory, strlen($this->root)), '/\\');
		$current=$this->root;
		foreach(explode(DIRECTORY_SEPARATOR, $relative) as $part){
			if($part===''){ continue; }
			$current.=DIRECTORY_SEPARATOR.$part;
			$existed=is_dir($current);
			if(!$existed){
				$made=mkdir($current, 0775);
				if(!$made && !is_dir($current)){
					throw new \RuntimeException('Unable to create Panel scaffold directory: '.$current);
				}
				if($made){ $created[]=$current; }
			}
			$this->assertSafePath($current.DIRECTORY_SEPARATOR.'placeholder');
		}
	}

	private static function assertSafeSegment(string $segment, bool $windows=false): void {
		if(preg_match('/[\x00-\x1f]/', $segment)===1){
			throw new \InvalidArgumentException('Panel scaffold target contains control characters.');
		}
		if($windows && (preg_match('/[<>:"|?*]/', $segment)===1 || preg_match('/[. ]$/', $segment)===1)){
			throw new \InvalidArgumentException('Panel scaffold target contains Windows-invalid path characters.');
		}
		$device=strtoupper((string)strtok($segment, '.'));
		if($windows && (in_array($device, ['CON','PRN','AUX','NUL'], true) || preg_match('/^(COM|LPT)[1-9]$/', $device)===1)){
			throw new \InvalidArgumentException('Panel scaffold target uses a reserved Windows device name.');
		}
	}

	/** @return array<string,mixed> */
	private static function publicEntry(array $entry): array {
		unset($entry['artifact'], $entry['stage']);
		return $entry;
	}

	private static function relativePath(string $path): string {
		$path=str_replace('\\', '/', trim($path));
		$parts=[];
		foreach(explode('/', $path) as $part){
			if($part==='' || $part==='.'){ continue; }
			if($part==='..'){
				if($parts===[]){ throw new \InvalidArgumentException('Panel scaffold path cannot traverse outside the project.'); }
				array_pop($parts);
				continue;
			}
			$parts[]=$part;
		}
		return implode('/', $parts);
	}

	private static function namespaceFromPath(string $path): string {
		$segments=[];
		foreach(explode('/', trim(str_replace('\\', '/', $path), '/')) as $segment){
			$segment=preg_replace('/[^a-zA-Z0-9_]+/', ' ', $segment) ?? '';
			$segment=str_replace(' ', '', ucwords(trim($segment)));
			if($segment==='' || preg_match('/^[0-9]/', $segment)===1){ $segment='Generated'.$segment; }
			$segments[]=$segment;
		}
		$namespace=implode('\\', $segments);
		self::assertNamespace($namespace);
		return $namespace;
	}

	private static function assertNamespace(string $namespace): void {
		if($namespace===''){ throw new \InvalidArgumentException('Panel scaffold namespace cannot be empty.'); }
		foreach(explode('\\', $namespace) as $segment){
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)!==1){
				throw new \InvalidArgumentException('Composer PSR-4 namespace is not a safe PHP namespace.');
			}
		}
	}

	private static function removeTree(string $directory): void {
		if(!is_dir($directory)){ return; }
		foreach(new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry){
			$path=$entry->getPathname();
			if($entry->isDir() && !$entry->isLink()){
				self::removeTree($path);
			}
			else{
				@unlink($path);
			}
		}
		@rmdir($directory);
	}
}
