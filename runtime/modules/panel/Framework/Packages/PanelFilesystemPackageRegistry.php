<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Crash-safe standalone registry operator and local package transport.
 *
 * Artifacts and indexes are immutable content-addressed objects. Publication
 * writes every object before atomically moving the signed index pointer, so an
 * interrupted commit can leave only harmless unreferenced objects.
 */
final class PanelFilesystemPackageRegistry implements PanelPackageTransport, \JsonSerializable {
	private readonly string $root;
	private readonly string $registry;
	private readonly string $publisher;
	private readonly PanelAtomicSnapshotStore $state;
	private readonly int $rootDevice;
	private readonly int $rootInode;

	public function __construct(string $root, string $registry, string $publisher, int $retention=256) {
		$this->registry=$this->canonicalName($registry, 'registry');
		$this->publisher=$this->canonicalName($publisher, 'publisher');
		$root=rtrim(trim($root), "\\/");
		if($root==='' || str_contains($root, "\0") || is_link($root)){
			throw new \InvalidArgumentException('Package registry root must be a non-symlink filesystem directory.');
		}
		if(!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)){
			throw new \RuntimeException('Package registry root could not be created.');
		}
		$real=realpath($root);$stat=lstat($root);
		if($real===false || !is_array($stat) || is_link($root) || !is_writable($real)){
			throw new \RuntimeException('Package registry root could not be resolved safely.');
		}
		$this->root=rtrim($real, DIRECTORY_SEPARATOR);
		$this->rootDevice=(int)$stat['dev'];$this->rootInode=(int)$stat['ino'];
		@chmod($this->root, 0770);
		foreach([$this->root.DIRECTORY_SEPARATOR.'objects', $this->root.DIRECTORY_SEPARATOR.'objects'.DIRECTORY_SEPARATOR.'sha256', $this->root.DIRECTORY_SEPARATOR.'.registry-state'] as $directory){
			if(is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) || is_link($directory)){
				throw new \RuntimeException('Package registry storage directory could not be created safely.');
			}
		}
		$initial=[
			'schema'=>'panel_filesystem_package_registry','version'=>1,
			'registry'=>$this->registry,'publisher'=>$this->publisher,
			'sequence'=>0,'publication_count'=>0,'index'=>null,'packages'=>[],
		];
		$this->state=new PanelAtomicSnapshotStore($this->root.DIRECTORY_SEPARATOR.'.registry-state', 'panel.package-registry.v1', $initial, max(8, $retention));
		$this->assertState($this->state->payload());
	}

	public static function make(string $root, string $registry, string $publisher, int $retention=256): self {
		return new self($root, $registry, $publisher, $retention);
	}

	public function indexLocator(): string {return 'panel-registry://'.$this->registry.'/index';}

	public function artifactLocator(string $digest): string {
		$digest=$this->digest($digest);
		if($digest===''){throw new \InvalidArgumentException('Package registry artifact digest is invalid.');}
		return 'panel-registry://'.$this->registry.'/objects/sha256/'.$digest;
	}

	/** @return \Closure(array<string,mixed>):string */
	public function locatorFactory(): \Closure {
		return fn(array $artifact): string=>$this->artifactLocator((string)($artifact['sha256'] ?? ''));
	}

	/**
	 * Atomically advances the registry's signed index pointer.
	 *
	 * @return array<string,mixed> Safe commit receipt.
	 */
	public function commit(PanelPackageRegistryPublication $publication): array {
		$this->assertRoot();
		if($publication->registry()!==$this->registry || $publication->publisher()!==$this->publisher){
			throw new \LogicException('Registry publication identity does not match this operator.');
		}
		$current=$this->state->payload();$this->assertState($current);
		$currentDigest=is_array($current['index']) ? (string)$current['index']['sha256'] : '';
		if($publication->sequence()<$current['sequence']
			|| ($publication->sequence()===$current['sequence'] && ($currentDigest==='' || !hash_equals($currentDigest, $publication->digest())))){
			throw new \LogicException('Registry publication sequence conflicts with trusted operator state.');
		}
		foreach($publication->artifacts() as $digest=>$artifact){
			if(!hash_equals($this->artifactLocator($digest), (string)$artifact['locator'])){
				throw new \LogicException('Registry publication contains an artifact locator owned by another transport.');
			}
			$this->writeObject($digest, (string)$artifact['body']);
		}
		$this->writeObject($publication->digest(), $publication->body());
		$index=$publication->index();
		$packages=[];
		foreach($index['packages'] as $package){
			$safe=$package;
			if(is_array($safe['artifact'] ?? null)){unset($safe['artifact']['locator']);}
			$packages[]=$safe;
		}
		$result=$this->state->transaction(function(array &$state) use ($publication, $packages): array {
			$this->assertState($state);
			$current=(int)$state['sequence'];
			$currentDigest=is_array($state['index']) ? (string)($state['index']['sha256'] ?? '') : '';
			if($publication->sequence()<$current){
				throw new \LogicException('Registry publication sequence would roll trusted state backward.');
			}
			if($publication->sequence()===$current){
				if($currentDigest!=='' && hash_equals($currentDigest, $publication->digest())){
					return ['replayed'=>true,'previous_digest'=>$currentDigest];
				}
				throw new \LogicException('Registry publication reuses the current sequence with different signed bytes.');
			}
			$previous=$currentDigest;
			$state['sequence']=$publication->sequence();
			$state['publication_count']++;
			$state['index']=[
				'sha256'=>$publication->digest(),'bytes'=>$publication->bytes(),
				'content_type'=>PanelPackageRegistryIndex::CONTENT_TYPE,
				'locator'=>$this->indexLocator(),
				'previous_sha256'=>$previous!=='' ? $previous : null,
			];
			$state['packages']=$packages;
			return ['replayed'=>false,'previous_digest'=>$previous];
		}, 'package_registry.published', [
			'registry'=>$this->registry,'sequence'=>$publication->sequence(),
			'index_sha256'=>$publication->digest(),'package_count'=>count($packages),
		]);
		$receipt=$result['result'];
		return [
			'type'=>'panel_package_registry_commit_receipt','ok'=>true,
			'registry'=>$this->registry,'publisher'=>$this->publisher,
			'sequence'=>$publication->sequence(),'index_digest'=>$publication->digest(),
			'package_count'=>count($packages),'replayed'=>$receipt['replayed'],
			'previous_digest'=>$receipt['previous_digest'],
			'cursor'=>(int)$result['snapshot']['sequence'],
		];
	}

	/**
	 * Implements the existing transport contract for the current index and every
	 * immutable content-addressed package object retained on disk.
	 *
	 * @return array<string,mixed>
	 */
	public function fetch(string $locator, array $request=[]): array {
		try{
			$this->assertRoot();
			$target=$this->target($locator);
			if($target===null){return $this->miss(404);}
			$state=$this->state->payload();$this->assertState($state);
			if($target['kind']==='index'){
				if(!is_array($state['index'])){return $this->miss(404);}
				if(isset($request['minimum_sequence']) && (!is_int($request['minimum_sequence']) || $state['sequence']<$request['minimum_sequence'])){return $this->miss(409);}
				$digest=(string)$state['index']['sha256'];$contentType=PanelPackageRegistryIndex::CONTENT_TYPE;
			}
			else{
				$digest=$target['digest'];$contentType=PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE;
				$known=false;
				foreach($state['packages'] as $package){
					if(is_array($package) && is_array($package['artifact'] ?? null) && hash_equals((string)($package['artifact']['sha256'] ?? ''), $digest)){$known=true;break;}
				}
				if(!$known){return $this->miss(404);}
			}
			$body=$this->readObject($digest);
			if($body===null){return $this->miss(503);}
			$bytes=strlen($body);
			if(isset($request['max_bytes']) && (!is_int($request['max_bytes']) || $request['max_bytes']<1 || $bytes>$request['max_bytes'])){return $this->miss(413);}
			if(isset($request['content_type']) && (!is_string($request['content_type']) || strtolower(trim($request['content_type']))!==$contentType)){return $this->miss(406);}
			if(isset($request['sha256']) && (!is_string($request['sha256']) || $this->digest($request['sha256'])!==$digest)){return $this->miss(412);}
			return [
				'ok'=>true,'status'=>200,'body'=>$body,'bytes'=>$bytes,'sha256'=>$digest,
				'content_type'=>$contentType,'content_encoding'=>'identity',
			];
		}
		catch(\Throwable){return $this->miss(503);}
	}

	public function catalog(): PanelPackageRegistryCatalog {
		$state=$this->state->payload();$this->assertState($state);
		if($state['sequence']<1 || !is_array($state['index'])){return PanelPackageRegistryCatalog::empty($this->registry);}
		return new PanelPackageRegistryCatalog($this->registry, $state['sequence'], (string)$state['index']['sha256'], $state['packages']);
	}

	public function indexBody(): ?string {
		$state=$this->state->payload();$this->assertState($state);
		return is_array($state['index']) ? $this->readObject((string)$state['index']['sha256']) : null;
	}

	/** @return array<string,mixed> */
	public function changesSince(int $cursor=0, int $limit=100): array {
		return $this->state->changesSince($cursor, $limit);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		$state=$this->state->payload();$this->assertState($state);
		return [
			'type'=>'panel_filesystem_package_registry',
			'registry'=>$this->registry,'publisher'=>$this->publisher,
			'sequence'=>$state['sequence'],'publication_count'=>$state['publication_count'],
			'package_version_count'=>count($state['packages']),
			'index'=>is_array($state['index']) ? [
				'sha256'=>$state['index']['sha256'],'bytes'=>$state['index']['bytes'],
				'content_type'=>$state['index']['content_type'],
				'previous_sha256'=>$state['index']['previous_sha256'],
			] : null,
			'root_digest'=>hash('sha256', $this->root),
			'root_serialized'=>false,
			'capabilities'=>[
				'atomic_index_pointer'=>true,'immutable_content_addressed_objects'=>true,
				'rollback_protection'=>true,'same_sequence_equivocation_protection'=>true,
				'local_transport'=>true,'change_feed'=>true,'search_catalog'=>true,
				'symlink_rejection'=>true,'digest_verification_on_read'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {return $this->toArray();}

	private function writeObject(string $digest, string $body): void {
		$digest=$this->digest($digest);
		if($digest==='' || $body==='' || !hash_equals($digest, hash('sha256', $body))){throw new \InvalidArgumentException('Package registry object digest does not match its bytes.');}
		$this->assertRoot();
		$directory=$this->objectDirectory($digest);
		if(is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) || is_link($directory)){
			throw new \RuntimeException('Package registry object directory is unsafe.');
		}
		$lock=$this->objectLock();
		try{
			$path=$this->objectPath($digest);
			if(is_file($path)){
				if(is_link($path) || !hash_equals($digest, hash_file('sha256', $path) ?: '')){throw new \UnexpectedValueException('Package registry immutable object was corrupted.');}
				return;
			}
			$temp=$directory.DIRECTORY_SEPARATOR.'.'.$digest.'.'.bin2hex(random_bytes(8)).'.tmp';
			$handle=@fopen($temp, 'xb');
			if(!is_resource($handle)){throw new \RuntimeException('Package registry object staging file could not be created.');}
			try{
				$offset=0;$length=strlen($body);
				while($offset<$length){$written=fwrite($handle, substr($body, $offset));if($written===false || $written===0){throw new \RuntimeException('Package registry object could not be written completely.');}$offset+=$written;}
				if(!fflush($handle)){throw new \RuntimeException('Package registry object could not be flushed.');}
				if(function_exists('fsync')){@fsync($handle);}
			}
			finally{@fclose($handle);}
			if(!hash_equals($digest, hash_file('sha256', $temp) ?: '') || !@rename($temp, $path)){
				@unlink($temp);throw new \RuntimeException('Package registry object could not be committed atomically.');
			}
			@chmod($path, 0640);
		}
		finally{@flock($lock, LOCK_UN);@fclose($lock);}
	}

	private function readObject(string $digest): ?string {
		$digest=$this->digest($digest);
		if($digest===''){return null;}
		$this->assertRoot();
		$directory=$this->objectDirectory($digest);$path=$this->objectPath($digest);
		if(is_link($directory) || !is_dir($directory) || is_link($path) || !is_file($path)){return null;}
		$size=@filesize($path);
		if(!is_int($size) || $size<1 || $size>1073741824){return null;}
		$body=@file_get_contents($path);
		return is_string($body) && strlen($body)===$size && hash_equals($digest, hash('sha256', $body)) ? $body : null;
	}

	/** @return resource */
	private function objectLock() {
		$path=$this->root.DIRECTORY_SEPARATOR.'.objects.lock';
		if(is_link($path)){throw new \RuntimeException('Package registry object lock is unsafe.');}
		$handle=@fopen($path, 'c+b');
		if(!is_resource($handle) || is_link($path) || !flock($handle, LOCK_EX)){
			if(is_resource($handle)){@fclose($handle);}
			throw new \RuntimeException('Package registry object lock could not be acquired.');
		}
		return $handle;
	}

	private function objectDirectory(string $digest): string {
		return $this->root.DIRECTORY_SEPARATOR.'objects'.DIRECTORY_SEPARATOR.'sha256'.DIRECTORY_SEPARATOR.substr($digest, 0, 2);
	}

	private function objectPath(string $digest): string {return $this->objectDirectory($digest).DIRECTORY_SEPARATOR.$digest.'.object';}

	/** @return array{kind:string,digest?:string}|null */
	private function target(string $locator): ?array {
		if($locator===$this->indexLocator()){return ['kind'=>'index'];}
		$prefix='panel-registry://'.$this->registry.'/objects/sha256/';
		if(!str_starts_with($locator, $prefix)){return null;}
		$digest=substr($locator, strlen($prefix));
		return $this->digest($digest)!=='' && $locator===$prefix.$digest ? ['kind'=>'artifact','digest'=>$digest] : null;
	}

	/** @return array<string,mixed> */
	private function miss(int $status): array {return ['ok'=>false,'status'=>$status,'body'=>'','content_type'=>'application/octet-stream'];}

	private function assertRoot(): void {
		$stat=lstat($this->root);$real=realpath($this->root);
		if(!is_array($stat) || $real===false || $real!==$this->root || is_link($this->root)
			|| (int)$stat['dev']!==$this->rootDevice || (int)$stat['ino']!==$this->rootInode){
			throw new \RuntimeException('Package registry root identity changed after initialization.');
		}
		foreach([$this->root.DIRECTORY_SEPARATOR.'objects', $this->root.DIRECTORY_SEPARATOR.'objects'.DIRECTORY_SEPARATOR.'sha256', $this->root.DIRECTORY_SEPARATOR.'.registry-state'] as $directory){
			if(!is_dir($directory) || is_link($directory)){throw new \RuntimeException('Package registry storage directory is unsafe.');}
		}
	}

	/** @param array<string,mixed> $state */
	private function assertState(array $state): void {
		if(array_keys($state)!==['schema','version','registry','publisher','sequence','publication_count','index','packages']
			|| $state['schema']!=='panel_filesystem_package_registry' || $state['version']!==1
			|| $state['registry']!==$this->registry || $state['publisher']!==$this->publisher
			|| !is_int($state['sequence']) || $state['sequence']<0
			|| !is_int($state['publication_count']) || $state['publication_count']<0
			|| !is_array($state['packages']) || !array_is_list($state['packages'])){
			throw new \UnexpectedValueException('Package registry persisted state is invalid.');
		}
		if($state['sequence']===0){
			if($state['index']!==null || $state['packages']!==[]){throw new \UnexpectedValueException('Unpublished package registry state is inconsistent.');}
			return;
		}
		$index=$state['index'];
		if(!is_array($index) || array_keys($index)!==['sha256','bytes','content_type','locator','previous_sha256']
			|| $this->digest((string)$index['sha256'])!==$index['sha256']
			|| !is_int($index['bytes']) || $index['bytes']<1
			|| $index['content_type']!==PanelPackageRegistryIndex::CONTENT_TYPE
			|| $index['locator']!==$this->indexLocator()
			|| ($index['previous_sha256']!==null && $this->digest((string)$index['previous_sha256'])!==$index['previous_sha256'])
			|| $state['packages']===[]){
			throw new \UnexpectedValueException('Published package registry state is inconsistent.');
		}
	}

	private function canonicalName(string $value, string $label): string {
		$normalized=Resource::normalizeName($value);
		if($normalized==='' || $normalized!==$value || strlen($value)>128){throw new \InvalidArgumentException(ucfirst($label).' must be canonical.');}
		return $normalized;
	}

	private function digest(string $digest): string {
		$digest=strtolower(trim($digest));if(str_starts_with($digest, 'sha256:')){$digest=substr($digest, 7);}
		return preg_match('/^[a-f0-9]{64}$/D', $digest)===1 ? $digest : '';
	}
}
