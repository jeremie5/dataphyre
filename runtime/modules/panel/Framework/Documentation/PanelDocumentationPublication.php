<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, internally consistent, version-directory-atomic documentation plan. */
final class PanelDocumentationPublication implements \JsonSerializable, \Countable, \IteratorAggregate {

	/** @param list<PanelScaffoldResult> $artifacts @param array<string,mixed> $manifest */
	private function __construct(
		private readonly string $version,
		private readonly string $releasePrefix,
		private readonly array $artifacts,
		private readonly array $manifest
	){}

	/** Canonical lowercase SemVer used by both manifests and filesystem paths. */
	public static function canonicalVersion(string $version): string {
		$version=trim($version);
		if(str_starts_with(strtolower($version),'v')){ $version=substr($version,1); }
		if($version==='' || strlen($version)>64 || strtolower($version)!==$version){
			throw new \InvalidArgumentException('Documentation version must be canonical lowercase SemVer of at most 64 bytes.');
		}
		$identifier='(?:0|[1-9][0-9]*)';
		$pre='(?:0|[1-9][0-9]*|[0-9]*[a-z-][0-9a-z-]*)';
		if(preg_match('/^'.$identifier.'\\.'.$identifier.'\\.'.$identifier.'(?:-'.$pre.'(?:\\.'.$pre.')*)?(?:\\+[0-9a-z-]+(?:\\.[0-9a-z-]+)*)?$/D',$version)!==1){
			throw new \InvalidArgumentException('Documentation version must be canonical lowercase SemVer of at most 64 bytes.');
		}
		return $version;
	}

	/** @param list<PanelScaffoldResult> $artifacts @param array<string,mixed> $manifest */
	public static function make(string $version,array $artifacts,array $manifest): self {
		$version=self::canonicalVersion($version);
		if($artifacts===[]){ throw new \InvalidArgumentException('Documentation publications require artifacts.'); }
		if(($manifest['type'] ?? null)!=='panel_documentation_release' || ($manifest['schema_version'] ?? null)!==1 || ($manifest['version'] ?? null)!==$version){
			throw new \InvalidArgumentException('Documentation publication manifest identity is inconsistent.');
		}
		$declared=[];
		foreach((array)($manifest['files'] ?? []) as $file){
			if(!is_array($file) || !is_string($file['path'] ?? null) || !is_int($file['bytes'] ?? null) || !is_string($file['sha256'] ?? null)){
				throw new \InvalidArgumentException('Documentation publication file manifest is malformed.');
			}
			$relative=self::relativePath($file['path']);
			$key=strtolower($relative);
			if(isset($declared[$key]) || $file['bytes']<0 || preg_match('/^[a-f0-9]{64}$/D',$file['sha256'])!==1){
				throw new \InvalidArgumentException('Documentation publication file manifest is malformed.');
			}
			$declared[$key]=['path'=>$relative,'bytes'=>$file['bytes'],'sha256'=>$file['sha256']];
		}

		$paths=[];
		$prefix=null;
		$publicationArtifact=null;
		$semanticArtifacts=[];
		foreach($artifacts as $artifact){
			if(!$artifact instanceof PanelScaffoldResult){ throw new \InvalidArgumentException('Documentation publications accept scaffold artifacts only.'); }
			$path=self::relativePath($artifact->path());
			$key=strtolower($path);
			if(isset($paths[$key])){ throw new \InvalidArgumentException('Documentation publication paths must be unique.'); }
			$paths[$key]=true;
			$metadata=$artifact->metadata();
			if(!is_string($metadata['relative_path'] ?? null)){
				throw new \InvalidArgumentException('Documentation artifact metadata is inconsistent.');
			}
			$relative=self::relativePath($metadata['relative_path']);
			$suffix='/'.$relative;
			if(!str_ends_with($path,$suffix)){
				throw new \InvalidArgumentException('Documentation artifact metadata is inconsistent.');
			}
			$current=substr($path,0,-strlen($suffix));
			$releaseSuffix='versions/'.$version;
			if($current!==$releaseSuffix && !str_ends_with($current,'/'.$releaseSuffix)){
				throw new \InvalidArgumentException('Documentation artifacts must share their versioned release directory.');
			}
			if($prefix===null){ $prefix=$current; }
			elseif($prefix!==$current){ throw new \InvalidArgumentException('Documentation artifacts must share their versioned release directory.'); }
			if(($metadata['version'] ?? null)!==$version || $metadata['relative_path']!==$relative || ($metadata['sha256'] ?? null)!==hash('sha256',$artifact->contents())){
				throw new \InvalidArgumentException('Documentation artifact metadata is inconsistent.');
			}
			if($relative==='publication.json'){
				$publicationArtifact=$artifact;
				continue;
			}
			if(in_array($relative,['api/index.json','cookbook/catalog.json','packages/compatibility.json','index.md'],true)){ $semanticArtifacts[$relative]=$artifact; }
			$declaredKey=strtolower($relative);
			$entry=$declared[$declaredKey] ?? null;
			if(!is_array($entry) || $entry['path']!==$relative || $entry['bytes']!==strlen($artifact->contents()) || !hash_equals($entry['sha256'],hash('sha256',$artifact->contents()))){
				throw new \InvalidArgumentException('Documentation artifact does not match its file manifest.');
			}
			unset($declared[$declaredKey]);
		}
		if($prefix===null || $publicationArtifact===null || $declared!==[]){
			throw new \InvalidArgumentException('Documentation publication artifact set is incomplete.');
		}
		try { $decoded=json_decode($publicationArtifact->contents(),true,128,JSON_THROW_ON_ERROR); }
		catch(\Throwable $exception){ throw new \InvalidArgumentException('Documentation publication manifest artifact is invalid JSON.',0,$exception); }
		if(!is_array($decoded) || $decoded!==$manifest){
			throw new \InvalidArgumentException('Documentation publication manifest artifact does not match its plan.');
		}
		self::assertSemanticConsistency($version,$manifest,$semanticArtifacts);
		return new self($version,$prefix,array_values($artifacts),$manifest);
	}

	public function version(): string { return $this->version; }
	public function releasePrefix(): string { return $this->releasePrefix; }
	/** @return list<PanelScaffoldResult> */
	public function artifacts(): array { return $this->artifacts; }
	/** @return array<string,mixed> */
	public function manifest(): array { return $this->manifest; }
	public function count(): int { return count($this->artifacts); }
	public function getIterator(): \Traversable { return new \ArrayIterator($this->artifacts); }

	public function artifact(string $path): ?PanelScaffoldResult {
		$path=str_replace('\\','/',trim($path));
		foreach($this->artifacts as $artifact){
			if(str_replace('\\','/',$artifact->path())===$path){ return $artifact; }
		}
		return null;
	}

	/**
	 * Publishes a whole immutable version directory with one final rename.
	 *
	 * Existing identical releases are idempotent. A changed, partial, skipped,
	 * or replacement publication is rejected; publish a new SemVer instead.
	 */
	public function apply(string $root,string $policy='error',bool $dryRun=true): PanelScaffoldWriteResult {
		if($policy!=='error'){ throw new \InvalidArgumentException('Versioned documentation publications only support the immutable error policy.'); }
		$writer=PanelScaffoldWriter::make($root);
		$plan=$writer->apply($this->artifacts,'error',true);
		if($dryRun){ return $plan; }
		$root=$writer->root();
		$lockPath=$root.DIRECTORY_SEPARATOR.'.dataphyre-panel-docs.lock';
		if(is_link($lockPath)){ throw new \RuntimeException('Documentation publication lock path is unsafe.'); }
		$lock=@fopen($lockPath,'c+b');
		if(!is_resource($lock) || is_link($lockPath)){
			is_resource($lock) && fclose($lock);
			throw new \RuntimeException('Unable to open the documentation publication lock.');
		}
		if(!flock($lock,LOCK_EX|LOCK_NB)){ fclose($lock); throw new \RuntimeException('Documentation publication is busy.'); }
		try{
			$plan=$writer->apply($this->artifacts,'error',true);
			$target=self::join($root,$this->releasePrefix);
			$parent=dirname($target);
			if(file_exists($target) || is_link($target)){
				if($plan->changed()){ throw new \RuntimeException('Documentation release already exists but is incomplete or different. Publish a new version.'); }
				$this->verifyTree($target);
				self::ensureDirectory($root,$parent);
				self::exposeTree($target);
				$this->verifyTree($target);
				return new PanelScaffoldWriteResult($root,'error',false,$plan->entries());
			}
			foreach($plan->entries() as $entry){
				if(($entry['operation'] ?? null)!=='create'){ throw new \RuntimeException('Documentation release publication requires an entirely new version directory.'); }
			}

			$stage=$root.DIRECTORY_SEPARATOR.'.dataphyre-panel-docs-'.bin2hex(random_bytes(12));
			if(!@mkdir($stage,0700,false)){ throw new \RuntimeException('Unable to create documentation publication staging directory.'); }
			try{
				PanelScaffoldWriter::make($stage)->apply($this->artifacts,'error',false);
				$stageRelease=self::join($stage,$this->releasePrefix);
				$this->verifyTree($stageRelease);
				self::exposeTree($stageRelease);
				self::ensureDirectory($root,$parent);
				$fresh=$writer->apply($this->artifacts,'error',true);
				foreach($fresh->entries() as $entry){
					if(($entry['operation'] ?? null)!=='create'){ throw new \RuntimeException('Documentation release target changed during publication.'); }
				}
				self::assertContainedDirectory($root,$parent);
				$this->verifyTree($stageRelease);
				if(file_exists($target) || is_link($target) || !@rename($stageRelease,$target)){ throw new \RuntimeException('Unable to atomically publish documentation release directory.'); }
				$this->verifyTree($target);
			}
			finally{
				self::removeTree($stage);
			}
		}
		finally{
			flock($lock,LOCK_UN);
			fclose($lock);
		}
		return new PanelScaffoldWriteResult($root,'error',false,$plan->entries());
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_documentation_publication',
			'version'=>$this->version,
			'release_prefix'=>$this->releasePrefix,
			'artifact_count'=>count($this->artifacts),
			'artifacts'=>array_map(static fn(PanelScaffoldResult $artifact): array=>$artifact->jsonSerialize(),$this->artifacts),
			'manifest'=>$this->manifest,
		];
	}

	private function verifyTree(string $release): void {
		if(!is_dir($release) || is_link($release)){ throw new \RuntimeException('Documentation release directory is invalid.'); }
		$releaseReal=realpath($release);
		if($releaseReal===false || !self::samePath($releaseReal,$release)){
			throw new \RuntimeException('Documentation release directory is an unsafe filesystem alias.');
		}
		$expected=[];
		$directories=[];
		$prefix=$this->releasePrefix.'/';
		foreach($this->artifacts as $artifact){
			$path=self::relativePath($artifact->path());
			if(!str_starts_with($path,$prefix)){ throw new \LogicException('Documentation artifact escaped its release prefix.'); }
			$relative=substr($path,strlen($prefix));
			$key=strtolower($relative);
			$expected[$key]=['path'=>$relative,'bytes'=>strlen($artifact->contents()),'sha256'=>hash('sha256',$artifact->contents())];
			$directory=dirname(str_replace('/',DIRECTORY_SEPARATOR,$relative));
			while($directory!=='.'){
				$directories[strtolower(str_replace(DIRECTORY_SEPARATOR,'/',$directory))]=true;
				$directory=dirname($directory);
			}
		}
		$seen=[];
		$pending=[[$release,'']];
		while($pending!==[]){
			[$directory,$relativeDirectory]=array_pop($pending);
			foreach(new \FilesystemIterator($directory,\FilesystemIterator::SKIP_DOTS) as $entry){
				$path=$entry->getPathname();
				$name=$entry->getFilename();
				$relative=$relativeDirectory==='' ? $name : $relativeDirectory.'/'.$name;
				if($entry->isLink()){ throw new \RuntimeException('Documentation release trees cannot contain links.'); }
				if($entry->isDir()){
					$real=realpath($path);
					if($real===false || !self::samePath($real,$path) || !self::contained($releaseReal,$real) || !isset($directories[strtolower($relative)])){
						throw new \RuntimeException('Documentation release contains an unexpected or unsafe directory.');
					}
					$pending[]=[$path,$relative];
					continue;
				}
				if(!$entry->isFile()){ throw new \RuntimeException('Documentation release contains an unsupported filesystem entry.'); }
				$key=strtolower($relative);
				$wanted=$expected[$key] ?? null;
				if(!is_array($wanted) || $wanted['path']!==$relative || isset($seen[$key]) || $entry->getSize()!==$wanted['bytes'] || !hash_equals($wanted['sha256'],(string)hash_file('sha256',$path))){
					throw new \RuntimeException('Documentation release contains an unexpected or modified artifact.');
				}
				$seen[$key]=true;
			}
		}
		if(count($seen)!==count($expected)){ throw new \RuntimeException('Documentation release artifact set is incomplete.'); }
	}

	/** Makes only a fully verified static release readable by a separate web user. */
	private static function exposeTree(string $directory): void {
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
		foreach($iterator as $entry){
			if($entry->isLink() || (!$entry->isDir() && !$entry->isFile())){ throw new \RuntimeException('Documentation release contains an unsafe filesystem entry.'); }
			self::exposePath($entry->getPathname(),$entry->isDir() ? 0755 : 0644);
		}
		self::exposePath($directory,0755);
	}

	/** Creates and exposes only root-confined publication ancestors, never the caller's root. */
	private static function ensureDirectory(string $root,string $directory): void {
		$rootReal=realpath($root);
		if($rootReal===false){ throw new \RuntimeException('Documentation publication root could not be resolved.'); }
		$rootPath=rtrim(str_replace('\\','/',$rootReal),'/');
		$directoryPath=rtrim(str_replace('\\','/',$directory),'/');
		$left=DIRECTORY_SEPARATOR==='\\' ? strtolower($directoryPath) : $directoryPath;
		$right=DIRECTORY_SEPARATOR==='\\' ? strtolower($rootPath) : $rootPath;
		if($left!==$right && !str_starts_with($left,$right.'/')){ throw new \RuntimeException('Documentation release parent escaped the project root.'); }
		$relative=$left===$right ? '' : substr($directoryPath,strlen($rootPath)+1);
		$current=$rootReal;
		foreach($relative==='' ? [] : explode('/',$relative) as $segment){
			$current.=DIRECTORY_SEPARATOR.$segment;
			if(is_link($current) || (file_exists($current) && !is_dir($current))){ throw new \RuntimeException('Documentation release parent path is unsafe.'); }
			if(!is_dir($current) && !@mkdir($current,0755,false) && !is_dir($current)){ throw new \RuntimeException('Unable to create documentation release parent directory.'); }
			$real=realpath($current);
			if($real===false || !self::samePath($real,$current) || !self::contained($rootReal,$real)){ throw new \RuntimeException('Documentation release parent path is unsafe.'); }
			self::exposePath($current,0755);
		}
		self::assertContainedDirectory($rootReal,$directory);
	}

	private static function exposePath(string $path,int $mode): void {
		if(DIRECTORY_SEPARATOR==='\\'){ return; }
		clearstatcache(true,$path);
		$actual=fileperms($path);
		if(!is_int($actual)){ throw new \RuntimeException('Unable to inspect a verified documentation publication path.'); }
		if(($actual&0777)!==$mode && !@chmod($path,$mode)){ throw new \RuntimeException('Unable to expose a verified documentation publication path.'); }
		clearstatcache(true,$path);
		$actual=fileperms($path);
		if(!is_int($actual) || ($actual&0777)!==$mode){ throw new \RuntimeException('Verified documentation publication permissions are inconsistent.'); }
	}

	private static function assertContainedDirectory(string $root,string $directory): void {
		$rootReal=realpath($root);
		$directoryReal=realpath($directory);
		if($rootReal===false || $directoryReal===false){ throw new \RuntimeException('Documentation release directory could not be resolved.'); }
		$rootNormalized=str_replace('\\','/',$rootReal);
		$directoryNormalized=str_replace('\\','/',$directoryReal);
		$left=DIRECTORY_SEPARATOR==='\\' ? strtolower($directoryNormalized) : $directoryNormalized;
		$right=DIRECTORY_SEPARATOR==='\\' ? strtolower($rootNormalized) : $rootNormalized;
		if($left!==$right && !str_starts_with($left,$right.'/')){ throw new \RuntimeException('Documentation release directory escaped the project root.'); }
		$relative=ltrim(substr($directoryNormalized,strlen($rootNormalized)),'/');
		$current=$rootReal;
		foreach($relative==='' ? [] : explode('/',$relative) as $segment){
			$current.=DIRECTORY_SEPARATOR.$segment;
			$real=realpath($current);
			if(is_link($current) || !is_dir($current) || $real===false || !self::samePath($real,$current)){ throw new \RuntimeException('Documentation release directory contains an unsafe ancestor.'); }
		}
	}

	private static function removeTree(string $path): void {
		if(is_link($path) || is_file($path)){ @unlink($path); return; }
		if(!is_dir($path)){ return; }
		$root=realpath($path);
		if($root===false || !self::samePath($root,$path)){ @rmdir($path); return; }
		self::removeContainedTree($path,$root);
	}

	private static function removeContainedTree(string $path,string $root): void {
		foreach(new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $entry){
			$child=$entry->getPathname();
			if($entry->isLink() || $entry->isFile()){ @unlink($child); continue; }
			$real=$entry->isDir() ? realpath($child) : false;
			if($real===false || !self::samePath($real,$child) || !self::contained($root,$real)){
				@rmdir($child);
				@unlink($child);
				continue;
			}
			self::removeContainedTree($child,$root);
		}
		@rmdir($path);
	}

	private static function contained(string $root,string $path): bool {
		$root=self::comparisonPath($root);
		$path=self::comparisonPath($path);
		return $path===$root || str_starts_with($path,$root.'/');
	}

	private static function samePath(string $left,string $right): bool {
		return self::comparisonPath($left)===self::comparisonPath($right);
	}

	private static function comparisonPath(string $path): string {
		$path=rtrim(str_replace('\\','/',$path),'/');
		return DIRECTORY_SEPARATOR==='\\' ? strtolower($path) : $path;
	}

	/** @param array<string,mixed> $manifest @param array<string,PanelScaffoldResult> $artifacts */
	private static function assertSemanticConsistency(string $version,array $manifest,array $artifacts): void {
		foreach(['api/index.json','cookbook/catalog.json','packages/compatibility.json','index.md'] as $required){
			if(!isset($artifacts[$required])){ throw new \InvalidArgumentException('Documentation publication is missing a semantic index artifact.'); }
		}
		$decode=static function(PanelScaffoldResult $artifact,string $label): array {
			try { $value=json_decode($artifact->contents(),true,128,JSON_THROW_ON_ERROR); }
			catch(\Throwable $exception){ throw new \InvalidArgumentException('Documentation '.$label.' index is invalid JSON.',0,$exception); }
			if(!is_array($value)){ throw new \InvalidArgumentException('Documentation '.$label.' index must be a JSON object.'); }
			return $value;
		};
		$api=$decode($artifacts['api/index.json'],'API');
		$cookbook=$decode($artifacts['cookbook/catalog.json'],'cookbook');
		$packages=$decode($artifacts['packages/compatibility.json'],'package compatibility');
		if(!is_array($api['symbols'] ?? null) || !array_is_list($api['symbols'])){ throw new \InvalidArgumentException('Documentation API index symbols must be a JSON list.'); }
		if(!is_array($cookbook['entries'] ?? null) || !array_is_list($cookbook['entries'])){ throw new \InvalidArgumentException('Documentation cookbook entries must be a JSON list.'); }
		if(!is_array($packages['packages'] ?? null) || !array_is_list($packages['packages'])){ throw new \InvalidArgumentException('Documentation compatibility packages must be a JSON list.'); }
		$symbols=$api['symbols'];
		$entries=$cookbook['entries'];
		$packageRows=$packages['packages'];
		$fingerprintParts=[];
		$memberCount=0;
		foreach($symbols as $symbol){
			if(!is_array($symbol) || !is_string($symbol['source'] ?? null) || !is_string($symbol['source_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D',$symbol['source_sha256'])!==1 || !is_array($symbol['members'] ?? null) || !array_is_list($symbol['members'])){ throw new \InvalidArgumentException('Documentation API index symbol metadata is malformed.'); }
			$memberCount+=count($symbol['members']);
			$fingerprintParts[]=$symbol['source'].':'.$symbol['source_sha256'];
		}
		$categories=[];
		$statuses=[];
		$exampleCount=0;
		$apiReferenceCount=0;
		$linkCount=0;
		foreach($entries as $entry){
			if(!is_array($entry) || !is_string($entry['category'] ?? null) || !is_string($entry['status'] ?? null) || !is_array($entry['examples'] ?? null) || !array_is_list($entry['examples']) || !is_array($entry['api'] ?? null) || !array_is_list($entry['api']) || !is_array($entry['links'] ?? null) || !array_is_list($entry['links'])){ throw new \InvalidArgumentException('Documentation cookbook entry metadata is malformed.'); }
			$categories[$entry['category']]=($categories[$entry['category']] ?? 0)+1;
			$statuses[$entry['status']]=($statuses[$entry['status']] ?? 0)+1;
			$exampleCount+=count($entry['examples']);
			$apiReferenceCount+=count($entry['api']);
			$linkCount+=count($entry['links']);
		}
		ksort($categories);
		ksort($statuses);
		$compatibleCount=0;
		$provides=[];
		foreach($packageRows as $package){
			if(!is_array($package) || !is_array($package['compatibility'] ?? null) || !is_bool($package['compatibility']['ok'] ?? null) || !is_array($package['provides'] ?? null) || !array_is_list($package['provides'])){ throw new \InvalidArgumentException('Documentation package compatibility metadata is malformed.'); }
			if($package['compatibility']['ok']){ $compatibleCount++; }
			foreach($package['provides'] as $provide){
				if(!is_string($provide)){ throw new \InvalidArgumentException('Documentation package capability metadata is malformed.'); }
				$provides[$provide]=($provides[$provide] ?? 0)+1;
			}
		}
		ksort($provides);
		$expected=[
			'api_symbol_count'=>count($symbols),
			'api_member_count'=>$memberCount,
			'cookbook_entry_count'=>count($entries),
			'package_count'=>count($packageRows),
			'source_root_fingerprint'=>hash('sha256',implode("\n",$fingerprintParts)),
		];
		foreach($expected as $key=>$value){ if(($manifest[$key] ?? null)!==$value){ throw new \InvalidArgumentException('Documentation publication semantic counts or fingerprint are inconsistent.'); } }
		if(($api['type'] ?? null)!=='panel_api_reference' || ($api['version'] ?? null)!==$version
			|| ($cookbook['type'] ?? null)!=='panel_documentation_catalog' || (($cookbook['meta']['documentation_version'] ?? null)!==$version)
			|| ($cookbook['entry_count'] ?? null)!==count($entries) || ($cookbook['category_count'] ?? null)!==count($categories) || ($cookbook['status_count'] ?? null)!==count($statuses)
			|| ($cookbook['example_count'] ?? null)!==$exampleCount || ($cookbook['api_reference_count'] ?? null)!==$apiReferenceCount || ($cookbook['link_count'] ?? null)!==$linkCount
			|| ($cookbook['categories'] ?? null)!==$categories || ($cookbook['statuses'] ?? null)!==$statuses
			|| ($packages['type'] ?? null)!=='panel_compatibility_matrix' || (($packages['meta']['documentation_version'] ?? null)!==$version)
			|| ($packages['package_count'] ?? null)!==count($packageRows) || ($packages['compatible_count'] ?? null)!==$compatibleCount || ($packages['blocked_count'] ?? null)!==count($packageRows)-$compatibleCount
			|| ($packages['provides'] ?? null)!==$provides){
			throw new \InvalidArgumentException('Documentation semantic indexes do not identify the same release.');
		}
		$title=$manifest['title'] ?? null;
		if(!is_string($title) || $title==='' || !str_starts_with($artifacts['index.md']->contents(),'# '.self::markdown($title).' '.$version."\n")){
			throw new \InvalidArgumentException('Documentation release title is inconsistent with its root index.');
		}
	}

	private static function markdown(string $value): string {
		$value=trim(preg_replace('/[\x00-\x1F\x7F]+/',' ',$value) ?? '');
		$value=str_replace(['<','>'],['&lt;','&gt;'],$value);
		return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|>])/u','\\\\$1',$value) ?? '';
	}

	private static function join(string $root,string $relative): string {
		return rtrim($root,'\\/').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
	}

	private static function relativePath(string $path): string {
		if($path==='' || trim($path)!==$path || str_contains($path,'\\') || str_starts_with($path,'/') || preg_match('/^[A-Za-z]:/D',$path)===1 || preg_match('/[\x00-\x1F\x7F]/',$path)===1){
			throw new \InvalidArgumentException('Documentation publication paths must be canonical relative paths.');
		}
		$segments=explode('/',$path);
		foreach($segments as $segment){
			if($segment==='' || $segment==='.' || $segment==='..'){ throw new \InvalidArgumentException('Documentation publication paths must be canonical relative paths.'); }
		}
		return $path;
	}
}
