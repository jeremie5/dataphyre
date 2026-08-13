<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Datadoc;

/**
 * Immutable, bounded Markdown corpus discovered below one trusted project root.
 *
 * Datadoc owns this filesystem boundary so every documentation producer uses
 * the same confinement, collision, asset, determinism, and manifest contract.
 */
final class DocumentationCorpus implements \JsonSerializable, \Countable, \IteratorAggregate {

	private const MAX_SOURCES=256;
	private const MAX_PAGES=10000;
	private const MAX_PAGE_BYTES=2097152;
	private const MAX_MARKDOWN_BYTES=67108864;
	private const MAX_ASSETS=10000;
	private const MAX_ASSET_BYTES=16777216;
	private const MAX_TOTAL_ASSET_BYTES=134217728;
	private const MAX_EXCLUSIONS=256;

	/**
	 * @param array<string,string> $documents
	 * @param array<string,string> $contentAssets
	 * @param array<string,mixed> $manifest
	 */
	private function __construct(
		private readonly array $documents,
		private readonly array $contentAssets,
		private readonly array $manifest,
	){}

	/**
	 * Compose a manual corpus or discover Dataphyre's conventional workspace.
	 *
	 * Manual mode requires `$source` and uses it as the portal root. Workspace
	 * mode requires a null source, mounts `docs/` at `project`, discovers every
	 * documentation directory immediately below each runtime module, and
	 * generates the root index.
	 * Extra mounts are supported in both modes for producer-generated corpora.
	 *
	 * @param array<string,string> $mounts Destination prefix => source directory.
	 */
	public static function discover(
		string $root,
		?string $source=null,
		array $mounts=[],
		bool $workspace=false,
		string $title='Dataphyre Documentation',
		array $exclude=[],
	):self {
		$root=self::root($root);
		$title=self::title($title);
		if($workspace&&$source!==null){ throw new \InvalidArgumentException('Datadoc workspace discovery cannot be combined with a root source.'); }
		if(!$workspace&&$source===null){ throw new \InvalidArgumentException('Datadoc manual corpus discovery requires a root source.'); }

		if(!array_is_list($exclude)){ throw new \InvalidArgumentException('Datadoc corpus exclusions must be a list of relative paths.'); }
		$exclusions=[];
		$exclusionKeys=[];
		foreach($exclude as $path){
			if(!is_string($path)){ throw new \InvalidArgumentException('Datadoc corpus exclusions must be a list of relative paths.'); }
			if(count($exclusions)>=self::MAX_EXCLUSIONS){ throw new \LengthException('Datadoc corpus exclusion count exceeds its bound.'); }
			$path=self::relativePath(str_replace('\\','/',$path));
			$key=strtolower($path);
			if(isset($exclusionKeys[$key])){ throw new \InvalidArgumentException('Datadoc corpus exclusions must be unique without case ambiguity.'); }
			$exclusionKeys[$key]=true;
			$exclusions[]=$path;
		}
		sort($exclusions,SORT_STRING);

		$sources=[];
		$mountKeys=[];
		$append=static function(string $mount,string $directory,string $kind,string $name,bool $recursive=true) use (&$sources,&$mountKeys,$root,$exclusions):void {
			if($mount!==''){ $mount=self::mountPrefix($mount); }
			$key=strtolower($mount);
			if(isset($mountKeys[$key])){ throw new \InvalidArgumentException('Datadoc corpus mount prefixes must be unique without case ambiguity.'); }
			if(count($sources)>=self::MAX_SOURCES){ throw new \LengthException('Datadoc corpus source count exceeds its bound.'); }
			$mountKeys[$key]=true;
			$resolved=self::directory($root,$directory);
			$sourcePath=self::rootRelative($root,$resolved);
			$localExclusions=[];
			foreach($exclusions as $exclusion){
				if($sourcePath==='.'){$local=$exclusion;}
				elseif($exclusion===$sourcePath){$local='';}
				elseif(str_starts_with($exclusion,$sourcePath.'/')){$local=substr($exclusion,strlen($sourcePath)+1);}
				else{continue;}
				if($local===''){ throw new \InvalidArgumentException('Datadoc corpus exclusions cannot remove an entire source.'); }
				$localExclusions[]=$local;
			}
			$sources[]=['mount'=>$mount,'directory'=>$resolved,'path'=>$sourcePath,'kind'=>$kind,'name'=>$name,'recursive'=>$recursive,'exclusions'=>$localExclusions];
		};

		$workspaceModules=[];
		$workspaceProject=false;
		if($workspace){
			$projectDocs=$root.'/docs';
			if(is_link($projectDocs)){ throw new \InvalidArgumentException('Datadoc workspace project documentation must not be a symlink.'); }
			if(is_dir($projectDocs)){ $append('docs',$projectDocs,'workspace_project','project'); $workspaceProject=true; }
			$moduleRoot=$root.'/runtime/modules';
			if(is_link($moduleRoot)){ throw new \InvalidArgumentException('Datadoc workspace module root must not be a symlink.'); }
			if(is_dir($moduleRoot)){
				$moduleDirectories=[];
				foreach(new \FilesystemIterator($moduleRoot,\FilesystemIterator::SKIP_DOTS) as $entry){
					if($entry->isLink()){ throw new \InvalidArgumentException('Datadoc workspace module tree must not contain symlinks.'); }
					if($entry->isDir()){ $moduleDirectories[$entry->getFilename()]=$entry->getPathname(); }
				}
				ksort($moduleDirectories,SORT_STRING);
				$moduleKeys=[];
				foreach($moduleDirectories as $module=>$directory){
					$moduleKey=strtolower($module);
					if(isset($moduleKeys[$moduleKey])){ throw new \InvalidArgumentException('Datadoc workspace module names must be unique without case ambiguity.'); }
					$moduleKeys[$moduleKey]=true;
					$documentation=$directory.'/documentation';
					if(is_link($documentation)){ throw new \InvalidArgumentException('Datadoc workspace module documentation must not be a symlink.'); }
					if(!is_dir($documentation)){ continue; }
					$moduleMount=self::rootRelative($root,str_replace('\\','/',$documentation));
					self::mountPrefix($moduleMount);
					$append($moduleMount,$documentation,'workspace_module',$module);
					$workspaceModules[]=$module;
				}
			}
			foreach(['runtime','config','examples/minimal'] as $supportPath){
				$supportDirectory=$root.'/'.$supportPath;
				if(is_link($supportDirectory)){ throw new \InvalidArgumentException('Datadoc workspace support directories must not be symlinks.'); }
				if(is_dir($supportDirectory)){ $append($supportPath,$supportDirectory,'workspace_support',$supportPath,false); }
			}
			if($sources===[]){ throw new \InvalidArgumentException('Datadoc workspace contains no discoverable documentation sources.'); }
		}
		else{ $append('',(string)$source,'root','root'); }

		foreach($mounts as $mount=>$directory){
			if(!is_string($mount)||$mount===''||!is_string($directory)){ throw new \InvalidArgumentException('Datadoc corpus mounts must map non-empty prefixes to directories.'); }
			$append($mount,$directory,'mount',$mount);
		}
		usort($sources,static fn(array $left,array $right):int=>strcmp($left['mount'],$right['mount']));

		$documents=[];
		$contentAssets=[];
		$occupied=[];
		$sourceManifest=[];
		$totalMarkdownBytes=0;
		$totalAssetBytes=0;
		$totalIgnored=0;
		foreach($sources as $spec){
			$files=[];
			$filesystemPrefix=rtrim(str_replace('\\','/',$spec['directory']),'/').'/';
			if($spec['recursive']){
				$tree=new \RecursiveDirectoryIterator($spec['directory'],\FilesystemIterator::SKIP_DOTS);
				if($spec['exclusions']!==[]){
					$tree=new \RecursiveCallbackFilterIterator($tree,static function(\SplFileInfo $entry) use ($filesystemPrefix,$spec):bool {
						$path=str_replace('\\','/',$entry->getPathname());
						$local=substr($path,strlen($filesystemPrefix));
						foreach($spec['exclusions'] as $exclusion){
							if($local===$exclusion||str_starts_with($local,$exclusion.'/')){ if($entry->isLink()){ throw new \InvalidArgumentException('Datadoc corpus excluded paths must not be symlinks.'); } return false; }
						}
						return true;
					});
				}
				$iterator=new \RecursiveIteratorIterator($tree,\RecursiveIteratorIterator::SELF_FIRST);
			}
			else{ $iterator=new \FilesystemIterator($spec['directory'],\FilesystemIterator::SKIP_DOTS); }
			foreach($iterator as $entry){
				if($entry->isLink()){ throw new \InvalidArgumentException('Datadoc corpus sources must not contain symlinks.'); }
				if($entry->isDir()){ continue; }
				if(!$entry->isFile()){ throw new \InvalidArgumentException('Datadoc corpus source contains an unsupported filesystem entry.'); }
				$path=str_replace('\\','/',$entry->getPathname());
				if(!str_starts_with($path,$filesystemPrefix)){ throw new \RuntimeException('Datadoc corpus source traversal escaped its directory.'); }
				$local=substr($path,strlen($filesystemPrefix));
				$excluded=false;
				foreach($spec['exclusions'] as $exclusion){ if($local===$exclusion||str_starts_with($local,$exclusion.'/')){ $excluded=true; break; } }
				if($excluded){ continue; }
				$files[$local]=['path'=>$entry->getPathname(),'bytes'=>$entry->getSize()];
			}
			ksort($files,SORT_STRING);
			$pageCount=0;
			$assetCount=0;
			$ignoredCount=0;
			$entryPage=null;
			$entryRank=null;
			foreach($files as $local=>$file){
				$destination=($spec['mount']===''?'':$spec['mount'].'/').$local;
				$key=strtolower($destination);
				$isMarkdown=str_ends_with($key,'.md');
				$isAsset=preg_match('/\.(?:png|jpe?g|gif|webp|avif|ico)$/D',$key)===1;
				if(!$isMarkdown&&!$isAsset){ $ignoredCount++; $totalIgnored++; continue; }
				$destination=self::relativePath($destination);
				$key=strtolower($destination);
				if(isset($occupied[$key])){ throw new \InvalidArgumentException('Datadoc corpus sources contain a case-ambiguous or colliding destination path.'); }
				if(is_link($file['path'])){ throw new \InvalidArgumentException('Datadoc corpus sources must not contain symlinks.'); }
				$declaredBytes=$file['bytes'];
				if($isMarkdown){
					if($declaredBytes<1||$declaredBytes>self::MAX_PAGE_BYTES||$totalMarkdownBytes+$declaredBytes>self::MAX_MARKDOWN_BYTES||count($documents)>=self::MAX_PAGES){ throw new \LengthException('Datadoc corpus exceeds its bounded Markdown limits.'); }
					$contents=file_get_contents($file['path'],false,null,0,self::MAX_PAGE_BYTES+1);
					if(!is_string($contents)){ throw new \RuntimeException('Unable to read a Datadoc Markdown source.'); }
					$actualBytes=strlen($contents);
					if($actualBytes<1||$actualBytes>self::MAX_PAGE_BYTES||$totalMarkdownBytes+$actualBytes>self::MAX_MARKDOWN_BYTES){ throw new \LengthException('Datadoc corpus exceeds its bounded Markdown limits.'); }
					$documents[$destination]=$contents;
					$totalMarkdownBytes+=$actualBytes;
					$pageCount++;
					$localKey=strtolower($local);
					$rank=$localKey==='index.md'?0:($localKey==='readme.md'?1:2);
					$selection=sprintf('%d:%s',$rank,$local);
					if($entryRank===null||strcmp($selection,$entryRank)<0){ $entryRank=$selection; $entryPage=$destination; }
				}
				else{
					if($declaredBytes<1||$declaredBytes>self::MAX_ASSET_BYTES||$totalAssetBytes+$declaredBytes>self::MAX_TOTAL_ASSET_BYTES||count($contentAssets)>=self::MAX_ASSETS){ throw new \LengthException('Datadoc corpus exceeds its bounded content asset limits.'); }
					$contents=file_get_contents($file['path'],false,null,0,self::MAX_ASSET_BYTES+1);
					if(!is_string($contents)){ throw new \RuntimeException('Unable to read a Datadoc content asset.'); }
					$actualBytes=strlen($contents);
					if($actualBytes<1||$actualBytes>self::MAX_ASSET_BYTES||$totalAssetBytes+$actualBytes>self::MAX_TOTAL_ASSET_BYTES){ throw new \LengthException('Datadoc corpus exceeds its bounded content asset limits.'); }
					DocumentationPortal::contentAssetMime($destination,$contents);
					$contentAssets[$destination]=$contents;
					$totalAssetBytes+=$actualBytes;
					$assetCount++;
				}
				$occupied[$key]=$destination;
			}
			$sourceManifest[]=[
				'mount'=>$spec['mount']===''?'.':$spec['mount'],
				'kind'=>$spec['kind'],
				'name'=>$spec['name'],
				'path'=>$spec['path'],
				'recursive'=>$spec['recursive'],
				'excluded_paths'=>$spec['exclusions'],
				'entry_page'=>$entryPage,
				'page_count'=>$pageCount,
				'content_asset_count'=>$assetCount,
				'ignored_file_count'=>$ignoredCount,
			];
		}

		$generatedPages=0;
		if($workspace){
			$index=self::workspaceIndex($title,$sourceManifest,$contentAssets);
			if(strlen($index)>self::MAX_PAGE_BYTES||$totalMarkdownBytes+strlen($index)>self::MAX_MARKDOWN_BYTES||count($documents)>=self::MAX_PAGES){ throw new \LengthException('Datadoc workspace index exceeds the bounded Markdown limits.'); }
			$documents['index.md']=$index;
			$totalMarkdownBytes+=strlen($index);
			$generatedPages=1;
		}
		elseif(!array_key_exists('index.md',$documents)){ throw new \InvalidArgumentException('Datadoc manual corpus source must contain a root index.md file.'); }

		ksort($documents,SORT_STRING);
		ksort($contentAssets,SORT_STRING);
		$fileIdentity=[];
		foreach($documents as $path=>$contents){ $fileIdentity[]=['kind'=>'markdown','path'=>$path,'bytes'=>strlen($contents),'sha256'=>hash('sha256',$contents)]; }
		foreach($contentAssets as $path=>$contents){ $fileIdentity[]=['kind'=>'content_asset','path'=>$path,'bytes'=>strlen($contents),'sha256'=>hash('sha256',$contents)]; }
		usort($fileIdentity,static fn(array $left,array $right):int=>strcmp($left['path'],$right['path'])?:strcmp($left['kind'],$right['kind']));
		$fingerprint=hash('sha256',json_encode(['mode'=>$workspace?'workspace':'manual','sources'=>$sourceManifest,'files'=>$fileIdentity],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
		$manifest=[
			'type'=>'dataphyre_datadoc_markdown_corpus',
			'schema_version'=>2,
			'discovery_mode'=>$workspace?'workspace':'manual',
			'source_count'=>count($sources),
			'sources'=>$sourceManifest,
			'page_count'=>count($documents),
			'generated_page_count'=>$generatedPages,
			'markdown_bytes'=>$totalMarkdownBytes,
			'content_asset_count'=>count($contentAssets),
			'content_asset_bytes'=>$totalAssetBytes,
			'ignored_file_count'=>$totalIgnored,
			'excluded_paths'=>$exclusions,
			'corpus_fingerprint'=>$fingerprint,
			'limits'=>[
				'sources'=>self::MAX_SOURCES,'pages'=>self::MAX_PAGES,'page_bytes'=>self::MAX_PAGE_BYTES,'markdown_bytes'=>self::MAX_MARKDOWN_BYTES,
				'content_assets'=>self::MAX_ASSETS,'content_asset_bytes'=>self::MAX_ASSET_BYTES,'total_content_asset_bytes'=>self::MAX_TOTAL_ASSET_BYTES,'exclusions'=>self::MAX_EXCLUSIONS,
			],
			'security'=>['project_confined'=>true,'symlinks_rejected'=>true,'source_not_executed'=>true,'raster_assets_signature_validated'=>true,'case_ambiguous_destinations_rejected'=>true,'publication_self_ingestion_prevented'=>$exclusions!==[]],
		];
		if($workspace){ $manifest['workspace']=['project_documentation'=>$workspaceProject,'module_count'=>count($workspaceModules),'modules'=>$workspaceModules,'generated_index'=>true]; }
		return new self($documents,$contentAssets,$manifest);
	}

	/** @return array<string,string> */ public function documents():array { return $this->documents; }
	/** @return array<string,string> */ public function contentAssets():array { return $this->contentAssets; }
	/** @return array<string,mixed> */ public function manifest():array { return $this->manifest; }
	public function document(string $path):?string { return $this->documents[str_replace('\\','/',trim($path))]??null; }
	public function contentAsset(string $path):?string { return $this->contentAssets[str_replace('\\','/',trim($path))]??null; }
	public function count():int { return count($this->documents); }
	public function getIterator():\Traversable { return new \ArrayIterator($this->documents); }
	/** @return array<string,mixed> */ public function jsonSerialize():array { return $this->manifest; }

	private static function root(string $root):string {
		if($root===''||is_link($root)){ throw new \InvalidArgumentException('Datadoc corpus root must be an existing non-symlink directory.'); }
		$resolved=realpath($root);
		if($resolved===false||!is_dir($resolved)){ throw new \InvalidArgumentException('Datadoc corpus root must be an existing non-symlink directory.'); }
		$resolved=rtrim(str_replace('\\','/',$resolved),'/');
		return $resolved===''?'/':$resolved;
	}

	private static function directory(string $root,string $path):string {
		if($path===''){ throw new \InvalidArgumentException('Datadoc corpus source directory is required.'); }
		$normalized=str_replace('\\','/',$path);
		$absolute=str_starts_with($normalized,'/')||(strlen($normalized)>=3&&ctype_alpha($normalized[0])&&$normalized[1]===':'&&$normalized[2]==='/');
		$candidate=$absolute?$normalized:rtrim($root,'/').'/'.$normalized;
		if(is_link($candidate)){ throw new \InvalidArgumentException('Datadoc corpus source directories must not be symlinks.'); }
		$resolved=realpath($candidate);
		if($resolved===false||!is_dir($resolved)){ throw new \InvalidArgumentException('Datadoc corpus source directory does not exist.'); }
		$resolved=str_replace('\\','/',$resolved);
		$left=DIRECTORY_SEPARATOR==='\\'?strtolower($resolved):$resolved;
		$right=DIRECTORY_SEPARATOR==='\\'?strtolower($root):$root;
		if($left!==$right&&!str_starts_with($left,rtrim($right,'/').'/')){ throw new \InvalidArgumentException('Datadoc corpus source directory escaped the project root.'); }
		return $resolved;
	}

	private static function rootRelative(string $root,string $path):string {
		if($path===$root){ return '.'; }
		return substr($path,strlen(rtrim($root,'/'))+1);
	}

	private static function mountPrefix(string $prefix):string {
		if($prefix===''||$prefix!==trim($prefix)||strlen($prefix)>4096||preg_match('//u',$prefix)!==1||preg_match('/[\x00-\x1f\x7f]/',$prefix)===1||str_contains($prefix,'\\')||str_contains($prefix,'%')||str_contains($prefix,'?')||str_contains($prefix,'#')||str_contains($prefix,':')||str_starts_with($prefix,'/')){
			throw new \InvalidArgumentException('Datadoc corpus mount prefixes must be safe relative UTF-8 paths.');
		}
		foreach(explode('/',$prefix) as $segment){ if($segment===''||$segment==='.'||$segment==='..'||$segment!==trim($segment)||strlen($segment)>255){ throw new \InvalidArgumentException('Datadoc corpus mount prefixes must be safe relative UTF-8 paths.'); } }
		return $prefix;
	}

	private static function relativePath(string $path):string {
		if($path===''||$path!==trim($path)||strlen($path)>4096||preg_match('//u',$path)!==1||preg_match('/[\x00-\x1f\x7f]/',$path)===1||str_contains($path,'\\')||str_contains($path,'%')||str_contains($path,'?')||str_contains($path,'#')||str_contains($path,':')||str_starts_with($path,'/')){
			throw new \InvalidArgumentException('Datadoc corpus paths must be safe relative UTF-8 paths.');
		}
		foreach(explode('/',$path) as $segment){ if($segment===''||$segment==='.'||$segment==='..'||$segment!==trim($segment)||strlen($segment)>255){ throw new \InvalidArgumentException('Datadoc corpus paths must be safe relative UTF-8 paths.'); } }
		return $path;
	}

	private static function title(string $title):string {
		$title=trim((string)preg_replace('/[\x00-\x1f\x7f]+/',' ',$title));
		if($title===''||strlen($title)>160||preg_match('//u',$title)!==1){ throw new \InvalidArgumentException('Datadoc corpus title is invalid.'); }
		return $title;
	}

	/** @param list<array<string,mixed>> $sources @param array<string,string> $contentAssets */
	private static function workspaceIndex(string $title,array $sources,array $contentAssets):string {
		$sections=['workspace_project'=>[],'workspace_module'=>[],'mount'=>[]];
		foreach($sources as $source){
			if(!is_string($source['entry_page']??null)||$source['entry_page']===''){ continue; }
			$kind=(string)$source['kind'];
			if(!isset($sections[$kind])){ continue; }
			$label=$kind==='workspace_project'?'Project documentation':ucwords(str_replace(['-','_','/'],' ',(string)$source['name']));
			$label=str_replace(['\\','[',']'],['\\\\','\\[','\\]'],$label);
			$target=implode('/',array_map('rawurlencode',explode('/',(string)$source['entry_page'])));
			$sections[$kind][]='- ['.$label.']('.$target.')';
		}
		$title=str_replace(['\\','#'],['\\\\','\\#'],$title);
		$markdown='# '.$title."\n\n";
		if(isset($contentAssets['runtime/logo.png'])){ $markdown.="![Dataphyre logo](runtime/logo.png)\n\n"; }
		$markdown.="Datadoc discovered this project documentation corpus without executing module code.\n";
		foreach([['workspace_project','Project'],['workspace_module','Modules'],['mount','Additional documentation']] as [$kind,$heading]){
			if($sections[$kind]===[]){ continue; }
			$markdown.="\n## ".$heading."\n\n".implode("\n",$sections[$kind])."\n";
		}
		return $markdown;
	}
}
