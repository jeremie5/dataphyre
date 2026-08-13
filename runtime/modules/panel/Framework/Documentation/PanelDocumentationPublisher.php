<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Builds deterministic, versioned Markdown and JSON documentation artifacts.
 *
 * Publication is pure planning. Files are only changed when callers explicitly
 * apply the returned plan, which delegates to Panel's confined transactional
 * scaffold writer.
 */
final class PanelDocumentationPublisher {

	private function __construct(private readonly PanelApiReferenceExtractor $extractor) {}

	public static function make(string|PanelApiReferenceExtractor $source): self {
		return new self(is_string($source) ? PanelApiReferenceExtractor::make($source) : $source);
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function build(
		string $version,
		?PanelDocumentationCatalog $catalog=null,
		?PanelCompatibilityMatrix $compatibility=null,
		array $options=[]
	): PanelDocumentationPublication {
		$version=self::version($version);
		$catalog??=PanelDocumentationCatalog::make();
		$compatibility??=PanelCompatibilityMatrix::make();
		$base=self::basePath((string)($options['base_path'] ?? 'docs/panel'));
		$title=self::text((string)($options['title'] ?? 'Dataphyre Panel')) ?: 'Dataphyre Panel';
		$paths=$options['source_paths'] ?? null;
		if($paths!==null && !is_array($paths)){ throw new \InvalidArgumentException('Documentation source_paths must be an array.'); }
		$symbols=$this->extractor->extract($paths);
		if($symbols===[]){ throw new \RuntimeException('Documentation publication found no public PHP types.'); }

		$catalogManifest=self::sanitizeCatalog(self::safe($catalog->manifest(['documentation_version'=>$version])));
		$compatibilityManifest=self::sanitizeCompatibility(self::safe($compatibility->manifest(['documentation_version'=>$version])));
		$prefix=$base.'/versions/'.$version;
		if(strlen($prefix)>120){ throw new \InvalidArgumentException('Documentation release prefix exceeds the portable path budget.'); }
		$files=[];
		$apiLinks=[];
		$slugs=[];
		$reserved=['api/index.md'=>true,'api/index.json'=>true,'cookbook/index.md'=>true,'cookbook/catalog.json'=>true,'packages/compatibility.md'=>true,'packages/compatibility.json'=>true,'index.md'=>true,'publication.json'=>true];
		foreach($symbols as $symbol){
			$slug=self::artifactPath($prefix,'api/types',implode('/',array_map(self::pathSegment(...),explode('\\',(string)$symbol['fqcn']))),(string)$symbol['fqcn']);
			$key=strtolower($slug);
			if(isset($slugs[$key]) || isset($reserved[$key])){
				throw new \RuntimeException('Documentation API path collision detected.');
			}
			$slugs[$key]=(string)$symbol['fqcn'];
			$files[$slug]=$this->apiPage($symbol,$title,$version);
			$apiLinks[]=['fqcn'=>$symbol['fqcn'],'kind'=>$symbol['kind'],'path'=>$slug,'summary'=>$symbol['summary']];
		}
		$files['api/index.md']=$this->apiIndex($apiLinks,$title,$version);
		$files['api/index.json']=self::json(['type'=>'panel_api_reference','version'=>$version,'symbols'=>$symbols]);

		$cookbookLinks=[];
		$cookbookSlugs=[];
		foreach((array)($catalogManifest['entries'] ?? []) as $entry){
			if(!is_array($entry)){ continue; }
			$id=self::pathSegment(Resource::normalizeName((string)($entry['id'] ?? '')) ?: 'entry');
			$slug=self::artifactPath($prefix,'cookbook/entries',$id,(string)($entry['id'] ?? $id));
			$key=strtolower($slug);
			if(isset($cookbookSlugs[$key]) || isset($files[$slug]) || isset($reserved[$key])){ throw new \RuntimeException('Documentation cookbook path collision detected.'); }
			$cookbookSlugs[$key]=true;
			$files[$slug]=$this->cookbookPage($entry,$title,$version);
			$cookbookLinks[]=['id'=>$id,'title'=>(string)($entry['title'] ?? $id),'status'=>(string)($entry['status'] ?? 'draft'),'path'=>$slug];
		}
		$files['cookbook/index.md']=$this->cookbookIndex($cookbookLinks,$title,$version);
		$files['cookbook/catalog.json']=self::json($catalogManifest);
		$files['packages/compatibility.md']=$this->compatibilityPage($compatibilityManifest,$title,$version);
		$files['packages/compatibility.json']=self::json($compatibilityManifest);
		$files['index.md']=$this->rootIndex($title,$version,count($symbols),count($cookbookLinks),$compatibilityManifest);

		ksort($files,SORT_STRING);
		$fileManifest=[];
		$artifacts=[];
		foreach($files as $relative=>$contents){
			$path=$prefix.'/'.$relative;
			$fileManifest[]=['path'=>$relative,'bytes'=>strlen($contents),'sha256'=>hash('sha256',$contents)];
			$artifacts[]=PanelScaffoldResult::make('documentation',pathinfo($relative,PATHINFO_FILENAME),'',$path,$contents,[
				'version'=>$version,'relative_path'=>$relative,'sha256'=>hash('sha256',$contents),
			]);
		}
		$manifest=self::safe([
			'type'=>'panel_documentation_release',
			'schema_version'=>1,
			'version'=>$version,
			'title'=>$title,
			'api_symbol_count'=>count($symbols),
			'api_member_count'=>array_sum(array_map(static fn(array $symbol): int=>count((array)($symbol['members'] ?? [])),$symbols)),
			'cookbook_entry_count'=>count($cookbookLinks),
			'package_count'=>(int)($compatibilityManifest['package_count'] ?? 0),
			'files'=>$fileManifest,
			'source_root_fingerprint'=>hash('sha256',implode("\n",array_map(static fn(array $symbol): string=>$symbol['source'].':'.$symbol['source_sha256'],$symbols))),
			'meta'=>is_array($options['meta'] ?? null) ? $options['meta'] : [],
		]);
		$manifestContents=self::json($manifest);
		$artifacts[]=PanelScaffoldResult::make('documentation','publication_manifest','',$prefix.'/publication.json',$manifestContents,[
			'version'=>$version,'relative_path'=>'publication.json','sha256'=>hash('sha256',$manifestContents),
		]);
		return PanelDocumentationPublication::make($version,$artifacts,$manifest);
	}

	/** @param array<string,mixed> $symbol */
	private function apiPage(array $symbol,string $title,string $version): string {
		$lines=['# '.self::markdown((string)$symbol['fqcn']),'',self::markdown($title).' '.$version.' API reference.',''];
		if((string)($symbol['summary'] ?? '')!==''){ $lines[]=self::markdown((string)$symbol['summary']); $lines[]=''; }
		if(($symbol['deprecated'] ?? false)===true){ $lines[]='> Deprecated type.'; $lines[]=''; }
		if(($symbol['internal'] ?? false)===true){ $lines[]='> Internal API; compatibility is not guaranteed.'; $lines[]=''; }
		array_push($lines,...self::codeBlock((string)$symbol['signature'],'php'));
		$lines[]='';
		$relationships=[
			'Attributes'=>(array)($symbol['attributes'] ?? []),
			'Extends'=>(array)($symbol['extends'] ?? []),
			'Implements'=>(array)($symbol['implements'] ?? []),
			'Uses traits'=>(array)($symbol['uses'] ?? []),
			'Trait adaptations'=>(array)($symbol['trait_adaptations'] ?? []),
		];
		if(array_filter($relationships,static fn(array $items): bool=>$items!==[])!==[]){
			$lines[]='## Declared relationships';
			$lines[]='';
			foreach($relationships as $label=>$items){
				if($items!==[]){ $lines[]='- '.self::markdown($label).': '.implode(', ',array_map(static fn(mixed $item): string=>self::inlineCode((string)$item),$items)); }
			}
			$lines[]='';
		}
		$lines[]='Source: '.self::inlineCode((string)$symbol['source'].':'.(int)$symbol['source_line']);
		$lines[]='';
		$members=(array)($symbol['members'] ?? []);
		$lines[]='## Declared public members';
		$lines[]='';
		if($members===[]){ $lines[]='This type declares no public members.'; }
		foreach($members as $member){
			if(!is_array($member)){ continue; }
			$lines[]='### '.self::markdown((string)$member['name']);
			$lines[]='';
			$summary=(string)($member['summary'] ?? '');
			if($summary!==''){ $lines[]=self::markdown($summary); $lines[]=''; }
			if(($member['deprecated'] ?? false)===true){ $lines[]='> Deprecated.'; $lines[]=''; }
			array_push($lines,...self::codeBlock((string)($member['signature'] ?? ''),'php'));
			$lines[]='';
		}
		return implode("\n",$lines)."\n";
	}

	/** @param list<array<string,mixed>> $links */
	private function apiIndex(array $links,string $title,string $version): string {
		$lines=['# '.self::markdown($title).' API reference','',self::markdown('Version '.$version),''];
		foreach($links as $link){
			$target=substr((string)$link['path'],strlen('api/'));
			$lines[]='- ['.self::inlineCode((string)$link['fqcn']).']('.self::url($target).') — '.self::markdown((string)$link['kind']);
		}
		return implode("\n",$lines)."\n";
	}

	/** @param array<string,mixed> $entry */
	private function cookbookPage(array $entry,string $title,string $version): string {
		$lines=['# '.self::markdown((string)($entry['title'] ?? $entry['id'] ?? 'Entry')),'',self::markdown($title).' '.$version.' cookbook.',''];
		$summary=(string)($entry['summary'] ?? '');
		if($summary!==''){ $lines[]=self::markdown($summary); $lines[]=''; }
		$lines[]='Status: '.self::inlineCode((string)($entry['status'] ?? 'draft'));
		$api=array_values(array_filter((array)($entry['api'] ?? []),'is_string'));
		if($api!==[]){
			$lines[]=''; $lines[]='## API'; $lines[]='';
			foreach($api as $reference){ $lines[]='- '.self::inlineCode($reference); }
		}
		$examples=(array)($entry['examples'] ?? []);
		if($examples!==[]){ $lines[]=''; $lines[]='## Examples'; $lines[]=''; }
		foreach($examples as $example){
			if(!is_array($example) || trim((string)($example['code'] ?? ''))===''){ continue; }
			$lines[]='### '.self::markdown((string)($example['title'] ?? 'Example'));
			$lines[]='';
			$language=Resource::normalizeName((string)($example['language'] ?? 'text')) ?: 'text';
			array_push($lines,...self::codeBlock((string)$example['code'],$language));
			$lines[]='';
		}
		$links=(array)($entry['links'] ?? []);
		if($links!==[]){ $lines[]='## Related'; $lines[]=''; }
		foreach($links as $link){
			if(!is_array($link)){ continue; }
			$target=self::safeTarget((string)($link['target'] ?? ''));
			if($target===''){ continue; }
			$lines[]='- ['.self::markdown((string)($link['label'] ?? $target)).']('.self::url($target).')';
		}
		return rtrim(implode("\n",$lines))."\n";
	}

	/** @param list<array<string,mixed>> $links */
	private function cookbookIndex(array $links,string $title,string $version): string {
		$lines=['# '.self::markdown($title).' cookbook','',self::markdown('Version '.$version),''];
		if($links===[]){ $lines[]='No cookbook entries are registered for this release.'; }
		foreach($links as $link){
			$target=substr((string)$link['path'],strlen('cookbook/'));
			$lines[]='- ['.self::markdown((string)$link['title']).']('.self::url($target).') — '.self::inlineCode((string)$link['status']);
		}
		return implode("\n",$lines)."\n";
	}

	/** @param array<string,mixed> $matrix */
	private function compatibilityPage(array $matrix,string $title,string $version): string {
		$lines=['# '.self::markdown($title).' package compatibility','',self::markdown('Version '.$version),'','| Package | Version | Compatible | Reasons |','| --- | --- | --- | --- |'];
		foreach((array)($matrix['packages'] ?? []) as $package){
			if(!is_array($package)){ continue; }
			$compat=(array)($package['compatibility'] ?? []);
			$reasons=array_map('strval',(array)($compat['reasons'] ?? []));
			$lines[]='| '.self::cell((string)($package['id'] ?? 'package')).' | '.self::cell((string)($package['version'] ?? '')).' | '.(($compat['ok'] ?? false)===true ? 'yes' : 'no').' | '.self::cell(implode('; ',$reasons)).' |';
		}
		if(($matrix['package_count'] ?? 0)===0){ $lines[]='| _No packages registered_ |  |  |  |'; }
		return implode("\n",$lines)."\n";
	}

	/** @param array<string,mixed> $matrix */
	private function rootIndex(string $title,string $version,int $symbols,int $entries,array $matrix): string {
		return '# '.self::markdown($title).' '.$version."\n\n"
			."This release contains {$symbols} declared public PHP types, {$entries} cookbook entries, and ".(int)($matrix['package_count'] ?? 0)." package compatibility records.\n\n"
			."- [API reference](api/index.md)\n"
			."- [Cookbook](cookbook/index.md)\n"
			."- [Package compatibility](packages/compatibility.md)\n";
	}

	private static function version(string $version): string {
		return PanelDocumentationPublication::canonicalVersion($version);
	}

	private static function basePath(string $path): string {
		$path=trim(str_replace('\\','/',$path));
		if($path==='' || str_starts_with($path,'/') || preg_match('/^[A-Za-z]:/D',$path)===1 || strlen($path)>512 || preg_match('/[\x00-\x1F\x7F<>:"|?*]/',$path)===1){ throw new \InvalidArgumentException('Documentation base path is invalid.'); }
		$path=rtrim($path,'/');
		foreach(explode('/',$path) as $segment){
			$device=strtoupper((string)strtok($segment,'.'));
			if($segment==='' || $segment==='.' || $segment==='..' || strlen($segment)>96 || preg_match('/^[. ]|[. ]$/',$segment)===1 || in_array($device,['CON','PRN','AUX','NUL'],true) || preg_match('/^(?:COM|LPT)[1-9]$/D',$device)===1){
				throw new \InvalidArgumentException('Documentation base path is invalid.');
			}
		}
		return $path;
	}

	private static function pathSegment(string $value): string {
		$value=trim($value);
		$lower=strtolower($value);
		$segment=trim((string)preg_replace('/[^a-z0-9._-]+/','-',$lower),'-.');
		if($segment===''){ $segment='symbol'; }
		if($segment!==$lower){ $segment.='-'.substr(hash('sha256',$value),0,8); }
		if(preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i',$segment)===1){ $segment='_'.$segment; }
		if(strlen($segment)>96){ $segment=rtrim(substr($segment,0,79),'-.').'-'.substr(hash('sha256',$value),0,16); }
		return $segment;
	}

	private static function artifactPath(string $prefix,string $directory,string $candidate,string $identity): string {
		$path=$directory.'/'.$candidate.'.md';
		if(strlen($prefix.'/'.$path)<=180){ return $path; }
		return $directory.'/_long/'.substr(hash('sha256',$identity),0,32).'.md';
	}

	private static function text(string $value): string { return trim(preg_replace('/[\x00-\x1F\x7F]+/',' ',$value) ?? ''); }
	private static function markdown(string $value): string {
		$value=str_replace(['<','>'],['&lt;','&gt;'],self::text($value));
		return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|>])/u','\\\\$1',$value) ?? '';
	}
	private static function cell(string $value): string { return str_replace(['|',"\r","\n"],['\\|',' ',' '],self::markdown($value)); }
	private static function url(string $value): string {
		$value=(string)preg_replace('/%(?![0-9A-Fa-f]{2})/','%25',$value);
		return (string)preg_replace_callback('/[^A-Za-z0-9%\-._~:\/?#\[\]@!$&\'\*+,;=]/',static fn(array $match): string=>implode('',array_map(static fn(string $byte): string=>sprintf('%%%02X',ord($byte)),str_split($match[0]))),$value);
	}

	private static function inlineCode(string $value): string {
		$value=self::text($value);
		preg_match_all('/`+/',$value,$matches);
		$longest=0;
		foreach((array)($matches[0] ?? []) as $run){ $longest=max($longest,strlen((string)$run)); }
		$fence=str_repeat('`',max(1,$longest+1));
		return $fence.' '.$value.' '.$fence;
	}

	private static function safeTarget(string $target): string {
		for($pass=0;$pass<8;$pass++){
			$decoded=html_entity_decode($target,ENT_QUOTES|ENT_HTML5,'UTF-8');
			if($decoded===$target){ break; }
			$target=$decoded;
		}
		if(preg_match('/&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);/i',$target)===1 || preg_match('/[\x00-\x1F\x7F]/',$target)===1){ return ''; }
		$target=trim($target);
		if($target==='' || str_starts_with($target,'//') || str_contains($target,'\\')){ return ''; }
		$probe=$target;
		for($pass=0;$pass<8;$pass++){
			$decoded=rawurldecode($probe);
			if($decoded===$probe){ break; }
			$probe=$decoded;
		}
		if(rawurldecode($probe)!==$probe || preg_match('/[\x00-\x1F\x7F]/',$probe)===1 || str_starts_with($probe,'//') || str_contains($probe,'\\')){ return ''; }
		foreach([$target,$probe] as $candidate){
			$parts=parse_url($candidate);
			if($parts===false || isset($parts['user']) || isset($parts['pass'])){ return ''; }
			$scheme=strtolower((string)($parts['scheme'] ?? ''));
			if($scheme!=='' && !in_array($scheme,['http','https'],true)){ return ''; }
			if(in_array($scheme,['http','https'],true) && trim((string)($parts['host'] ?? ''))===''){ return ''; }
		}
		return $target;
	}

	/** @return list<string> */
	private static function codeBlock(string $code,string $language): array {
		preg_match_all('/`+/',$code,$matches);
		$longest=0;
		foreach((array)($matches[0] ?? []) as $run){ $longest=max($longest,strlen((string)$run)); }
		$fence=str_repeat('`',max(3,$longest+1));
		$language=Resource::normalizeName($language) ?: 'text';
		return [$fence.$language,$code,$fence];
	}

	/** @param array<string,mixed> $manifest @return array<string,mixed> */
	private static function sanitizeCatalog(array $manifest): array {
		$entries=[];
		foreach((array)($manifest['entries'] ?? []) as $entry){
			if(!is_array($entry)){ continue; }
			$links=[];
			foreach((array)($entry['links'] ?? []) as $link){
				if(!is_array($link)){ continue; }
				$target=self::safeTarget((string)($link['target'] ?? ''));
				if($target===''){ continue; }
				$links[]=['label'=>self::text((string)($link['label'] ?? $target)) ?: $target,'target'=>$target];
			}
			$entry['links']=$links;
			$entries[]=$entry;
		}
		usort($entries,static fn(array $left,array $right): int=>[
			(string)($left['category'] ?? ''),(string)($left['title'] ?? ''),(string)($left['id'] ?? ''),
		]<=>[
			(string)($right['category'] ?? ''),(string)($right['title'] ?? ''),(string)($right['id'] ?? ''),
		]);
		$manifest['entries']=$entries;
		$manifest['link_count']=array_sum(array_map(static fn(array $entry): int=>count((array)($entry['links'] ?? [])),$entries));
		return self::canonical($manifest);
	}

	/** @param array<string,mixed> $manifest @return array<string,mixed> */
	private static function sanitizeCompatibility(array $manifest): array {
		$packages=array_values(array_filter((array)($manifest['packages'] ?? []),'is_array'));
		$decorated=array_map(static fn(array $package): array=>[
			'key'=>[(string)($package['id'] ?? ''),(string)($package['version'] ?? ''),hash('sha256',self::json($package))],
			'package'=>$package,
		],$packages);
		usort($decorated,static fn(array $left,array $right): int=>$left['key']<=>$right['key']);
		$packages=array_map(static fn(array $entry): array=>$entry['package'],$decorated);
		$manifest['packages']=$packages;
		return self::canonical($manifest);
	}

	private static function json(array $value): string {
		try { return json_encode(self::canonical($value),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n"; }
		catch(\JsonException $exception){ throw new \RuntimeException('Documentation data is not JSON serializable.',0,$exception); }
	}

	private static function safe(mixed $value,int $depth=0,?string $key=null): mixed {
		if($depth>10){ throw new \InvalidArgumentException('Documentation data exceeded the nesting limit.'); }
		if($key!==null && self::sensitiveKey($key)){ return '[redacted]'; }
		if(is_string($value) && strlen($value)>100000){ throw new \InvalidArgumentException('Documentation strings cannot exceed 100000 bytes.'); }
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){ return $value; }
		if(is_float($value)){
			if(!is_finite($value)){ throw new \InvalidArgumentException('Documentation numbers must be finite.'); }
			return $value;
		}
		if(!is_array($value)){ throw new \InvalidArgumentException('Documentation data must be JSON-safe.'); }
		if(count($value)>10000){ throw new \InvalidArgumentException('Documentation data exceeded the item limit.'); }
		$result=[];
		$seen=[];
		foreach($value as $itemKey=>$item){
			$normalizedKey=is_int($itemKey) ? $itemKey : self::text((string)$itemKey);
			if($normalizedKey===''){ throw new \InvalidArgumentException('Documentation data keys cannot be blank.'); }
			$collision=(is_int($normalizedKey) ? 'i:' : 's:').(string)$normalizedKey;
			if(isset($seen[$collision])){ throw new \InvalidArgumentException('Documentation data contains colliding normalized keys.'); }
			$seen[$collision]=true;
			$result[$normalizedKey]=self::safe($item,$depth+1,is_string($normalizedKey) ? $normalizedKey : null);
		}
		return self::canonical($result);
	}

	private static function sensitiveKey(string $key): bool {
		$key=strtolower((string)preg_replace('/[^a-z0-9]+/i','',$key));
		foreach(['secret','password','passwd','authorization','cookie','credential','privatekey','apikey','csrf','token','bearer','seed'] as $sensitive){
			if(str_contains($key,$sensitive)){ return true; }
		}
		return false;
	}

	private static function canonical(array $value): array {
		foreach($value as $key=>$item){ if(is_array($item)){ $value[$key]=self::canonical($item); } }
		if(!array_is_list($value)){ ksort($value,SORT_STRING); }
		return $value;
	}
}
