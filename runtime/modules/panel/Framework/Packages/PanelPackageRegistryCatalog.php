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
 * Searchable, locator-free read model for a verified package registry index.
 */
final class PanelPackageRegistryCatalog implements \JsonSerializable {
	/** @var list<array<string,mixed>> */
	private array $packages;

	/** @param list<array<string,mixed>> $packages */
	public function __construct(
		private readonly string $registry,
		private readonly int $sequence,
		private readonly string $indexDigest,
		array $packages
	) {
		if(Resource::normalizeName($registry)!==$registry || $registry==='' || $sequence<0 || preg_match('/^[a-f0-9]{64}$/D', $indexDigest)!==1
			|| ($sequence===0 && $packages!==[])){
			throw new \InvalidArgumentException('Package registry catalog identity is invalid.');
		}
		$normalized=[];$seen=[];
		foreach($packages as $package){
			if(!is_array($package)){throw new \InvalidArgumentException('Package registry catalog rows must be maps.');}
			$id=Resource::normalizeName((string)($package['id'] ?? ''));
			$version=(string)($package['version'] ?? '');
			if($id==='' || $id!==($package['id'] ?? null) || !PanelPackageManifest::validVersion($version) || isset($seen[$id.'@'.strtolower($version)])){
				throw new \InvalidArgumentException('Package registry catalog contains an invalid or duplicate identity.');
			}
			$seen[$id.'@'.strtolower($version)]=true;
			$artifact=is_array($package['artifact'] ?? null) ? $package['artifact'] : [];
			$digest=(string)($artifact['sha256'] ?? '');
			$contentType=$artifact['content_type']??null;
			if(array_diff(array_keys($artifact), ['locator','sha256','bytes','content_type'])!==[]
				|| preg_match('/^[a-f0-9]{64}$/D', $digest)!==1 || !is_int($artifact['bytes'] ?? null) || $artifact['bytes']<1
				|| !is_string($contentType) || trim($contentType)==='' || strlen($contentType)>128 || preg_match('/[\x00-\x1F\x7F]/', $contentType)===1){
				throw new \InvalidArgumentException('Package registry catalog artifact descriptor is invalid.');
			}
			$artifact=['sha256'=>$digest,'bytes'=>$artifact['bytes'],'content_type'=>$contentType];
			foreach(['yanked','revoked','publisher_blocked']as$flag){
				if(array_key_exists($flag,$package)&&!is_bool($package[$flag])){throw new \InvalidArgumentException('Package registry catalog availability flags must be boolean.');}
			}
			$row=[
				'id'=>$id,'version'=>$version,'status'=>(string)($package['status'] ?? ''),
				'publisher'=>(string)($package['publisher'] ?? ''),
				'key_id'=>(string)($package['key_id'] ?? ''),
				'dependencies'=>$this->constraints($package['dependencies'] ?? [], 'dependencies'),
				'requirements'=>$this->requirements($package['requirements'] ?? []),
				'yanked'=>($package['yanked'] ?? false)===true,
				'revoked'=>($package['revoked'] ?? false)===true,
				'publisher_blocked'=>($package['publisher_blocked'] ?? false)===true,
				'artifact'=>$artifact,
				'listing'=>$this->listing($package['listing'] ?? []),
			];
			$normalized[]=$row;
		}
		usort($normalized, self::packageOrder(...));
		$this->packages=$normalized;
	}

	public static function empty(string $registry): self {
		$normalized=Resource::normalizeName($registry);
		if($normalized===''||$normalized!==$registry){throw new \InvalidArgumentException('Package registry catalog identity is invalid.');}
		return new self($registry, 0, hash('sha256', 'panel-package-registry-empty|'.$registry), []);
	}

	public static function fromPublication(PanelPackageRegistryPublication $publication): self {
		$index=$publication->index();
		return new self($publication->registry(), $publication->sequence(), $publication->digest(), $index['packages']);
	}

	public static function fromIndex(PanelPackageRegistryIndex $index): self {
		if(!$index->ok()){throw new \InvalidArgumentException('Only verified registry indexes can become package catalogs.');}
		return new self($index->registry(), $index->sequence(), $index->envelopeDigest(), $index->entries());
	}

	/**
	 * Returns a deterministic discovery page with an index-bound opaque cursor.
	 *
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	public function search(string $query='', array $filters=[], ?string $cursor=null, int $limit=24): array {
		$filters=$this->filters($filters);
		$query=trim($query);
		if(strlen($query)>256){throw new \LengthException('Package registry search query exceeds its byte limit.');}
		$limit=max(1, min(100, $limit));
		$fingerprint=hash('sha256', self::canonicalJson(['query'=>$query,'filters'=>$filters]));
		$offset=$cursor===null || $cursor==='' ? 0 : $this->decodeCursor($cursor, $fingerprint);
		$matches=[];
		foreach($this->packages as $package){
			if(!$this->matchesFilters($package, $filters)){continue;}
			$score=$this->score($package, $query);
			if($query!=='' && $score===0){continue;}
			$package['_score']=$score;$matches[]=$package;
		}
		usort($matches, static function(array $left, array $right): int {
			$score=((int)$right['_score'])<=>((int)$left['_score']);
			return $score!==0 ? $score : self::packageOrder($left, $right);
		});
		if(!$filters['all_versions']){
			$latest=[];$collapsed=[];
			foreach($matches as $package){
				if(isset($latest[$package['id']])){continue;}
				$latest[$package['id']]=true;$collapsed[]=$package;
			}
			$matches=$collapsed;
		}
		$total=count($matches);
		$page=array_slice($matches, $offset, $limit);
		foreach($page as &$package){unset($package['_score']);}
		unset($package);
		$next=$offset+count($page)<$total ? $this->encodeCursor($offset+count($page), $fingerprint) : null;
		return [
			'type'=>'panel_package_registry_catalog_page',
			'registry'=>$this->registry,'sequence'=>$this->sequence,'published'=>$this->sequence>0,
			'index_digest'=>$this->sequence>0 ? $this->indexDigest : null,
			'query'=>$query,'filters'=>$filters,'limit'=>$limit,'total'=>$total,'count'=>count($page),
			'packages'=>$page,'next_cursor'=>$next,'facets'=>$this->facets($matches),
		];
	}

	/** @return list<array<string,mixed>> */
	public function versions(string $package, bool $includeUnavailable=false): array {
		$package=Resource::normalizeName($package);
		$versions=[];
		foreach($this->packages as $row){
			if($row['id']!==$package || (!$includeUnavailable && ($row['yanked'] || $row['revoked'] || $row['publisher_blocked']))){continue;}
			$versions[]=$row;
		}
		return $versions;
	}

	/** @return array<string,mixed>|null */
	public function latest(string $package, bool $includeUnavailable=false): ?array {
		return $this->versions($package, $includeUnavailable)[0] ?? null;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		$available=0;$yanked=0;
		foreach($this->packages as $package){
			if($package['yanked']){$yanked++;}
			elseif(!$package['revoked'] && !$package['publisher_blocked']){$available++;}
		}
		return [
			'type'=>'panel_package_registry_catalog',
			'registry'=>$this->registry,'sequence'=>$this->sequence,'published'=>$this->sequence>0,
			'index_digest'=>$this->sequence>0 ? $this->indexDigest : null,
			'package_version_count'=>count($this->packages),
			'package_count'=>count(array_unique(array_column($this->packages, 'id'))),
			'available_version_count'=>$available,'yanked_version_count'=>$yanked,
			'locators_serialized'=>false,
			'capabilities'=>[
				'full_text_discovery'=>true,'facets'=>true,'capability_filtering'=>true,
				'version_history'=>true,'yank_awareness'=>true,'cursor_pagination'=>true,
				'index_bound_cursors'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {return $this->toArray();}

	/** @return array<string,mixed> */
	private function filters(array $filters): array {
		if(array_diff(array_keys($filters), ['status','type','publisher','tag','category','capability','include_yanked','include_revoked','include_blocked','all_versions'])!==[]){
			throw new \InvalidArgumentException('Package registry search filters contain unsupported fields.');
		}
		$result=[
			'status'=>$this->names($filters['status'] ?? []),
			'type'=>$this->names($filters['type'] ?? []),
			'publisher'=>$this->names($filters['publisher'] ?? []),
			'tag'=>$this->names($filters['tag'] ?? []),
			'category'=>$this->names($filters['category'] ?? []),
			'capability'=>$this->names($filters['capability'] ?? []),
			'include_yanked'=>($filters['include_yanked'] ?? false)===true,
			'include_revoked'=>($filters['include_revoked'] ?? false)===true,
			'include_blocked'=>($filters['include_blocked'] ?? false)===true,
			'all_versions'=>($filters['all_versions'] ?? false)===true,
		];
		return $result;
	}

	/** @return list<string> */
	private function names(mixed $values): array {
		if(is_string($values)){$values=[$values];}
		if(!is_array($values) || !array_is_list($values) || count($values)>64){throw new \InvalidArgumentException('Package registry search filter values are malformed.');}
		$result=[];
		foreach($values as $value){
			if(!is_string($value)){throw new \InvalidArgumentException('Package registry search filter values must be strings.');}
			$name=Resource::normalizeName($value);
			if($name==='' || $name!==$value){throw new \InvalidArgumentException('Package registry search filter values must be canonical.');}
			$result[$name]=true;
		}
		$names=array_keys($result);sort($names, SORT_STRING);return $names;
	}

	private function matchesFilters(array $package, array $filters): bool {
		if(!$filters['include_yanked'] && $package['yanked']){return false;}
		if(!$filters['include_revoked'] && $package['revoked']){return false;}
		if(!$filters['include_blocked'] && $package['publisher_blocked']){return false;}
		$listing=$package['listing'];
		foreach(['status'=>'status','publisher'=>'publisher'] as $filter=>$field){
			if($filters[$filter]!==[] && !in_array($package[$field], $filters[$filter], true)){return false;}
		}
		if($filters['type']!==[] && !in_array((string)($listing['type'] ?? ''), $filters['type'], true)){return false;}
		foreach(['tag'=>'tags','category'=>'categories','capability'=>'provides'] as $filter=>$field){
			if($filters[$filter]!==[] && array_intersect($filters[$filter], (array)($listing[$field] ?? []))!==$filters[$filter]){return false;}
		}
		return true;
	}

	/** @return array<string,string> */
	private function constraints(mixed $values, string $label): array {
		if(!is_array($values) || ($values!==[] && array_is_list($values)) || count($values)>512){
			throw new \InvalidArgumentException("Package registry catalog {$label} are malformed.");
		}
		$result=[];
		foreach($values as $name=>$constraint){
			if(!is_string($name) || Resource::normalizeName($name)!==$name || $name==='' || !is_string($constraint)){
				throw new \InvalidArgumentException("Package registry catalog {$label} are malformed.");
			}
			$constraint=trim($constraint);
			if($constraint==='' || strlen($constraint)>128 || preg_match('/[\x00-\x1F\x7F]/', $constraint)===1){
				throw new \InvalidArgumentException("Package registry catalog {$label} are malformed.");
			}
			$result[$name]=$constraint;
		}
		ksort($result,SORT_STRING);return$result;
	}

	/** @return array<string,mixed> */
	private function requirements(mixed $requirements): array {
		if(!is_array($requirements) || ($requirements!==[] && array_is_list($requirements))){
			throw new \InvalidArgumentException('Package registry catalog requirements are malformed.');
		}
		$result=[];
		foreach(['php','panel','reactor']as$name){
			if(!array_key_exists($name,$requirements)){continue;}
			$value=$requirements[$name];
			if($value===null){$result[$name]=null;continue;}
			if(!is_string($value) || trim($value)==='' || strlen($value)>128 || preg_match('/[\x00-\x1F\x7F]/',$value)===1){
				throw new \InvalidArgumentException('Package registry catalog requirements are malformed.');
			}
			$result[$name]=trim($value);
		}
		foreach(['modules','themes']as$name){
			if(array_key_exists($name,$requirements)){$result[$name]=$this->constraints($requirements[$name],$name);}
		}
		return$result;
	}

	/** @return array<string,mixed> */
	private function listing(mixed $listing): array {
		if($listing===null||$listing===[]){return[];}
		if(!is_array($listing)||array_is_list($listing)){throw new \InvalidArgumentException('Package registry catalog listing is malformed.');}
		$result=[];
		foreach(['label'=>180,'description'=>4000,'license'=>128]as$field=>$maximum){
			if(!array_key_exists($field,$listing)){continue;}
			$value=$listing[$field];
			if(!is_string($value) || trim($value)==='' || strlen(trim($value))>$maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===1){
				throw new \InvalidArgumentException('Package registry catalog listing is malformed.');
			}
			$result[$field]=trim($value);
		}
		if(array_key_exists('type',$listing)){
			$type=is_string($listing['type'])?Resource::normalizeName($listing['type']):'';
			if($type===''||$type!==$listing['type']){throw new \InvalidArgumentException('Package registry catalog listing is malformed.');}
			$result['type']=$type;
		}
		foreach(['tags'=>64,'categories'=>32,'provides'=>256]as$field=>$limit){
			if(!array_key_exists($field,$listing)){continue;}
			$values=$listing[$field];
			if(!is_array($values)||!array_is_list($values)||count($values)>$limit){throw new \InvalidArgumentException('Package registry catalog listing is malformed.');}
			$names=[];
			foreach($values as$value){
				if(!is_string($value)){throw new \InvalidArgumentException('Package registry catalog listing is malformed.');}
				$name=Resource::normalizeName($value);
				if($name===''||$name!==$value){throw new \InvalidArgumentException('Package registry catalog listing is malformed.');}
				$names[$name]=true;
			}
			$result[$field]=array_keys($names);sort($result[$field],SORT_STRING);
		}
		if(array_key_exists('links',$listing)){
			$links=$listing['links'];
			if(!is_array($links)||!array_is_list($links)||count($links)>32){throw new \InvalidArgumentException('Package registry catalog listing is malformed.');}
			$result['links']=[];
			foreach($links as$link){
				if(!is_array($link)||!is_string($link['label']??null)||!is_string($link['target']??null)){throw new \InvalidArgumentException('Package registry catalog listing is malformed.');}
				$label=trim($link['label']);$target=trim($link['target']);$parts=parse_url($target);
				if($label===''||strlen($label)>180||preg_match('/[\x00-\x1F\x7F]/',$label)===1
					||$target===''||strlen($target)>2048||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
					||trim((string)($parts['host']??''))===''||isset($parts['user'])||isset($parts['pass'])||str_contains($target,"\0")){
					throw new \InvalidArgumentException('Package registry catalog listing is malformed.');
				}
				$result['links'][]=['label'=>$label,'target'=>$target];
			}
		}
		return$result;
	}

	private function score(array $package, string $query): int {
		if($query===''){return 0;}
		$query=strtolower($query);
		$listing=$package['listing'];
		$id=strtolower($package['id']);
		$label=strtolower((string)($listing['label'] ?? ''));
		$description=strtolower((string)($listing['description'] ?? ''));
		$score=0;
		if($id===$query){$score+=1000;}
		elseif(str_starts_with($id, $query)){$score+=600;}
		elseif(str_contains($id, $query)){$score+=350;}
		if($label===$query){$score+=700;}
		elseif(str_starts_with($label, $query)){$score+=450;}
		elseif(str_contains($label, $query)){$score+=250;}
		if(str_contains($description, $query)){$score+=80;}
		foreach(['tags'=>120,'categories'=>100,'provides'=>140] as $field=>$weight){
			foreach((array)($listing[$field] ?? []) as $value){
				$value=strtolower((string)$value);
				if($value===$query){$score+=$weight*2;}
				elseif(str_contains($value, $query)){$score+=$weight;}
			}
		}
		return $score;
	}

	/** @param list<array<string,mixed>> $packages @return array<string,array<string,int>> */
	private function facets(array $packages): array {
		$facets=['status'=>[],'type'=>[],'publisher'=>[],'tags'=>[],'categories'=>[],'provides'=>[]];
		foreach($packages as $package){
			$listing=$package['listing'];
			foreach(['status'=>$package['status'],'type'=>$listing['type'] ?? null,'publisher'=>$package['publisher']] as $facet=>$value){
				if(is_string($value) && $value!==''){$facets[$facet][$value]=($facets[$facet][$value] ?? 0)+1;}
			}
			foreach(['tags','categories','provides'] as $facet){
				foreach((array)($listing[$facet] ?? []) as $value){$facets[$facet][$value]=($facets[$facet][$value] ?? 0)+1;}
			}
		}
		foreach($facets as &$values){ksort($values, SORT_STRING);}unset($values);
		return $facets;
	}

	private function encodeCursor(int $offset, string $fingerprint): string {
		$payload=['index'=>$this->indexDigest,'query'=>$fingerprint,'offset'=>$offset];
		$payload['checksum']=hash('sha256', self::canonicalJson($payload));
		return rtrim(strtr(base64_encode(self::canonicalJson($payload)), '+/', '-_'), '=');
	}

	private function decodeCursor(string $cursor, string $fingerprint): int {
		if(strlen($cursor)>1024 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)!==1){throw new \InvalidArgumentException('Package registry cursor is malformed.');}
		$encoded=strtr($cursor, '-_', '+/');
		$encoded.=str_repeat('=', (4-strlen($encoded)%4)%4);
		$raw=base64_decode($encoded, true);
		try{$payload=is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;}
		catch(\Throwable){$payload=null;}
		if(!is_array($payload) || array_keys($payload)!==['checksum','index','offset','query']
			|| !is_string($payload['checksum']) || !is_string($payload['index']) || !is_string($payload['query']) || !is_int($payload['offset']) || $payload['offset']<0){
			throw new \InvalidArgumentException('Package registry cursor is malformed.');
		}
		$checksum=$payload['checksum'];unset($payload['checksum']);
		if(!hash_equals($checksum, hash('sha256', self::canonicalJson($payload)))
			|| !hash_equals($this->indexDigest, $payload['index'])
			|| !hash_equals($fingerprint, $payload['query'])){
			throw new \LogicException('Package registry cursor does not belong to this catalog query.');
		}
		return $payload['offset'];
	}

	private static function packageOrder(array $left, array $right): int {
		$id=strcmp((string)$left['id'], (string)$right['id']);
		return $id!==0 ? $id : -PanelPackageManifest::compareVersions((string)$left['version'], (string)$right['version']);
	}

	private static function canonicalJson(mixed $value): string {
		return json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	private static function canonical(mixed $value): mixed {
		if(is_array($value)){
			if(!array_is_list($value)){ksort($value, SORT_STRING);}
			foreach($value as $key=>$item){$value[$key]=self::canonical($item);}
			return $value;
		}
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){return $value;}
		if(is_float($value) && is_finite($value)){return $value;}
		throw new \InvalidArgumentException('Package registry catalog values must be JSON-compatible.');
	}
}
