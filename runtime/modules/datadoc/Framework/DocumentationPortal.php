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
 * Builds a dependency-free static documentation portal from a normalized
 * Markdown corpus. Product and module publishers adapt their own release
 * types at this boundary; Datadoc owns rendering, search, navigation, assets,
 * security policy, and version metadata.
 */
final class DocumentationPortal implements \JsonSerializable {
	private const OPTION_NAMES=[
		'language',
		'direction',
		'ui_copy',
		'default_theme',
		'version_links',
		'canonical_base_url',
		'repository_url',
		'maximum_search_text_bytes',
	];
	private const MAX_PAGES=10000;
	private const MAX_RESERVED_PATHS=50000;
	private const MAX_MARKDOWN_BYTES=2097152;
	private const MAX_TOTAL_MARKDOWN_BYTES=67108864;
	private const MAX_CONTENT_ASSETS=10000;
	private const MAX_CONTENT_ASSET_BYTES=16777216;
	private const MAX_TOTAL_CONTENT_ASSET_BYTES=134217728;

	private function __construct(){}

	public static function make():self { return new self(); }

	/**
	 * @param array<string,string> $documents Relative Markdown path to UTF-8 source.
	 * @param list<string> $reservedPaths Existing release paths that generated files must not replace.
	 * @param array<string,mixed> $options
	 * @param array<string,string> $contentAssets Relative raster-image path to binary contents.
	 */
	public function build(string $version,string $title,array $documents,array $reservedPaths=[],array $options=[],array $contentAssets=[]):DocumentationPortalBuild {
		foreach(array_keys($options) as $name){
			if(!is_string($name)||!in_array($name,self::OPTION_NAMES,true)){ throw new \InvalidArgumentException('Documentation portal options contain an unsupported name.'); }
		}
		$version=self::canonicalVersion($version);
		$title=self::boundedText($title,'Documentation portal title',160);
		$language=self::language($options['language']??'en');
		$direction=self::direction($options['direction']??'ltr');
		$uiCopy=self::uiCopy($options['ui_copy']??[]);
		$theme=self::theme($options['default_theme']??'system');
		$canonicalBase=self::canonicalBase($options['canonical_base_url']??null);
		$repositoryUrl=self::externalUrl($options['repository_url']??null,'Documentation portal repository URL',true);
		$maximumSearchBytes=self::boundedInteger($options['maximum_search_text_bytes']??12000,512,50000,'Documentation portal search text bound');
		$versions=self::versions($options['version_links']??[],$version);

		$original=[];
		if(!array_is_list($reservedPaths)||count($reservedPaths)>self::MAX_RESERVED_PATHS){ throw new \InvalidArgumentException('Documentation portal reserved paths must be a bounded list.'); }
		foreach($reservedPaths as $reservedPath){
			if(!is_string($reservedPath)){ throw new \InvalidArgumentException('Documentation portal reserved paths must contain strings.'); }
			$relative=self::relativePath($reservedPath);
			$key=strtolower($relative);
			if(isset($original[$key])){ throw new \InvalidArgumentException('Documentation portal reserved paths must be unique without case ambiguity.'); }
			$original[$key]=$relative;
		}

		$assetFiles=[];
		$assetPaths=[];
		$assetManifest=[];
		$totalContentAssetBytes=0;
		if(count($contentAssets)>self::MAX_CONTENT_ASSETS){ throw new \LengthException('Documentation portal content asset count exceeds its bound.'); }
		foreach($contentAssets as $source=>$contents){
			if(!is_string($source)||!is_string($contents)){ throw new \InvalidArgumentException('Documentation portal content assets must be a relative-path to binary string map.'); }
			$relative=self::relativePath($source);
			$key=strtolower($relative);
			if(isset($original[$key])){ throw new \RuntimeException('Documentation portal content asset path collides with a release artifact.'); }
			$bytes=strlen($contents);
			$totalContentAssetBytes+=$bytes;
			if($bytes<1||$bytes>self::MAX_CONTENT_ASSET_BYTES||$totalContentAssetBytes>self::MAX_TOTAL_CONTENT_ASSET_BYTES){ throw new \LengthException('Documentation portal content asset input exceeds its byte bound.'); }
			$mediaType=self::contentAssetMime($relative,$contents);
			$original[$key]=$relative;
			$assetFiles[$relative]=$contents;
			$assetPaths[$key]=$relative;
			$assetManifest[]=['path'=>$relative,'media_type'=>$mediaType,'bytes'=>$bytes,'sha256'=>hash('sha256',$contents)];
		}
		ksort($assetFiles,SORT_STRING);
		ksort($assetPaths,SORT_STRING);
		usort($assetManifest,static fn(array $left,array $right):int=>$left['path']<=>$right['path']);

		$pages=[];
		$documentManifest=[];
		$totalMarkdownBytes=0;
		if(count($documents)>self::MAX_PAGES){ throw new \LengthException('Documentation portal page count exceeds its bound.'); }
		foreach($documents as $source=>$contents){
			if(!is_string($source)||!is_string($contents)){ throw new \InvalidArgumentException('Documentation portal documents must be a relative-path to Markdown map.'); }
			$relative=self::relativePath($source);
			if(!str_ends_with(strtolower($relative),'.md')){ throw new \InvalidArgumentException('Documentation portal documents must use Markdown paths.'); }
			$sourceKey=strtolower($relative);
			if(isset($original[$sourceKey])){ throw new \RuntimeException('Documentation portal source path collides with a reserved release artifact.'); }
			$original[$sourceKey]=$relative;
			$bytes=strlen($contents);
			$totalMarkdownBytes+=$bytes;
			if($bytes<1||$bytes>self::MAX_MARKDOWN_BYTES||$totalMarkdownBytes>self::MAX_TOTAL_MARKDOWN_BYTES){ throw new \LengthException('Documentation portal Markdown input exceeds its byte bound.'); }
			$htmlPath=substr($relative,0,-3).'.html';
			$key=strtolower($htmlPath);
			if(isset($original[$key])||isset($pages[$key])){ throw new \RuntimeException('Documentation portal output path collides with a release artifact.'); }
			$rendered=$this->renderMarkdown($contents,$relative,$assetPaths);
			$documentManifest[]=['path'=>$relative,'bytes'=>$bytes,'sha256'=>hash('sha256',$contents)];
			$pages[$key]=[
				'source'=>$relative,
				'path'=>$htmlPath,
				'title'=>$rendered['title'],
				'section'=>self::section($relative),
				'body'=>$rendered['html'],
				'toc'=>$rendered['toc'],
				'text'=>self::byteCut($rendered['text'],$maximumSearchBytes),
			];
		}
		if(!isset($pages['index.html'])){ throw new \RuntimeException('Documentation portal requires the release root Markdown index.'); }
		ksort($pages,SORT_STRING);
		usort($documentManifest,static fn(array $left,array $right):int=>$left['path']<=>$right['path']);
		$navigation=$this->navigationModel($pages,$uiCopy);

		$css=self::css();
		$javascript=self::javascript($uiCopy);
		$favicon=self::favicon();
		$searchEntries=[];
		foreach($pages as $page){
			$searchEntries[]=[
				'id'=>substr(hash('sha256',$version."\n".$page['path']),0,24),
				'title'=>$page['title'],
				'section'=>$page['section'],
				'path'=>$page['path'],
				'headings'=>array_values(array_map(static fn(array $heading):string=>$heading['text'],$page['toc'])),
				'text'=>$page['text'],
			];
		}
		$searchIndex=[
			'type'=>'dataphyre_datadoc_search_index',
			'schema_version'=>1,
			'documentation_version'=>$version,
			'entry_count'=>count($searchEntries),
			'entries'=>$searchEntries,
		];
		$versionsDocument=[
			'type'=>'dataphyre_datadoc_versions',
			'schema_version'=>1,
			'current'=>$version,
			'versions'=>array_values(array_map(static fn(array $item):array=>[
				'version'=>$item['version'],
				'url'=>$item['url']===''?'index.html':$item['url'],
				'current'=>$item['version']===$version,
			],$versions)),
		];
		$portalManifest=[
			'type'=>'dataphyre_datadoc_static_portal',
			'schema_version'=>1,
			'documentation_version'=>$version,
			'title'=>$title,
			'language'=>$language,
			'direction'=>$direction,
			'default_theme'=>$theme,
			'page_count'=>count($pages),
			'search_entry_count'=>count($searchEntries),
			'content_asset_count'=>count($assetManifest),
			'content_asset_bytes'=>$totalContentAssetBytes,
			'content_assets'=>$assetManifest,
			'maximum_search_text_bytes'=>$maximumSearchBytes,
			'version_count'=>count($versions),
			'versions'=>$versionsDocument['versions'],
			'canonical_base_url'=>$canonicalBase,
			'repository_url'=>$repositoryUrl,
			'ui_copy'=>[
				'key_count'=>count($uiCopy),
				'sha256'=>hash('sha256',self::json($uiCopy)),
			],
			'assets'=>[
				'css'=>['path'=>'assets/portal.css','bytes'=>strlen($css),'sha256'=>hash('sha256',$css)],
				'javascript'=>['path'=>'assets/portal.js','bytes'=>strlen($javascript),'sha256'=>hash('sha256',$javascript)],
				'favicon'=>['path'=>'assets/favicon.svg','bytes'=>strlen($favicon),'sha256'=>hash('sha256',$favicon)],
			],
			'navigation'=>$navigation,
			'capabilities'=>[
				'static_html'=>true,
				'progressive_enhancement'=>true,
				'local_search'=>true,
				'localized_shell'=>true,
				'text_direction'=>true,
				'local_content_assets'=>true,
				'version_switching'=>true,
				'deep_links'=>true,
				'code_copy'=>true,
				'responsive_navigation'=>true,
				'light_dark_system_themes'=>true,
				'reduced_motion'=>true,
				'forced_colors'=>true,
				'print_styles'=>true,
				'external_runtime_dependencies'=>false,
				'external_asset_requests'=>false,
				'inline_scripts'=>false,
				'dynamic_html_injection'=>false,
				'content_security_policy'=>true,
				'no_referrer_policy'=>true,
			],
			'configuration_fingerprint'=>hash('sha256',self::json([
				'language'=>$language,
				'direction'=>$direction,
				'ui_copy_sha256'=>hash('sha256',self::json($uiCopy)),
				'default_theme'=>$theme,
				'versions'=>$versionsDocument['versions'],
				'canonical_base_url'=>$canonicalBase,
				'repository_url'=>$repositoryUrl,
				'maximum_search_text_bytes'=>$maximumSearchBytes,
			])),
			'corpus_fingerprint'=>hash('sha256',self::json([
				'documents'=>$documentManifest,
				'content_assets'=>$assetManifest,
			])),
		];

		$generated=[
			'assets/favicon.svg'=>$favicon,
			'assets/portal.css'=>$css,
			'assets/portal.js'=>$javascript,
			'search-index.json'=>self::json($searchIndex),
			'versions.json'=>self::json($versionsDocument),
			'portal.json'=>self::json($portalManifest),
		];
		foreach($pages as $page){
			$generated[$page['path']]=$this->pageHtml($page,$title,$version,$language,$direction,$theme,$versions,$navigation,$canonicalBase,$repositoryUrl,$uiCopy);
		}
		$generated['404.html']=$this->notFoundHtml($title,$version,$language,$direction,$theme,$versions,$repositoryUrl,$uiCopy);
		if($canonicalBase!==null){ $generated['sitemap.xml']=$this->sitemap($canonicalBase,$pages); }
		ksort($generated,SORT_STRING);
		$generatedKeys=[];
		foreach($generated as $relative=>$contents){
			$relative=self::relativePath($relative);
			$key=strtolower($relative);
			if(isset($original[$key])){ throw new \RuntimeException('Documentation portal generated path collides with a release artifact.'); }
			if(isset($generatedKeys[$key])){ throw new \LogicException('Documentation portal generated paths became ambiguous.'); }
			$generatedKeys[$key]=true;
		}
		foreach($assetFiles as $relative=>$contents){ $generated[$relative]=$contents; }
		ksort($generated,SORT_STRING);
		return DocumentationPortalBuild::make($version,$generated,$portalManifest);
	}

	/** @return array<string,mixed> */
	public function manifest():array {
		return [
			'type'=>'datadoc_documentation_portal_engine',
			'version'=>1,
			'input'=>'normalized_markdown_corpus',
			'output'=>'self_contained_static_portal_build',
			'owner_module'=>'datadoc',
			'default_enabled'=>false,
			'network_access'=>false,
			'source_execution'=>false,
			'raw_html_trusted'=>false,
			'external_dependencies'=>false,
			'max_pages'=>self::MAX_PAGES,
			'max_reserved_paths'=>self::MAX_RESERVED_PATHS,
			'max_markdown_bytes'=>self::MAX_MARKDOWN_BYTES,
			'max_total_markdown_bytes'=>self::MAX_TOTAL_MARKDOWN_BYTES,
			'max_content_assets'=>self::MAX_CONTENT_ASSETS,
			'max_content_asset_bytes'=>self::MAX_CONTENT_ASSET_BYTES,
			'max_total_content_asset_bytes'=>self::MAX_TOTAL_CONTENT_ASSET_BYTES,
			'content_asset_media_types'=>['image/png','image/jpeg','image/gif','image/webp','image/avif','image/vnd.microsoft.icon'],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array { return $this->manifest(); }

	/**
	 * @param array<string,string> $contentAssets Case-folded path to canonical path.
	 * @return array{title:string,html:string,toc:list<array{level:int,id:string,text:string}>,text:string}
	 */
	private function renderMarkdown(string $markdown,string $source,array $contentAssets=[]):array {
		if(preg_match('//u',$markdown)!==1){ throw new \InvalidArgumentException('Documentation portal Markdown must be valid UTF-8.'); }
		$markdown=str_replace(["\r\n","\r"],"\n",$markdown);
		$lines=explode("\n",$markdown);
		$html=[];
		$toc=[];
		$ids=[];
		$title='';
		$count=count($lines);
		for($index=0;$index<$count;){
			$line=$lines[$index];
			if(trim($line)===''){ $index++; continue; }
			if(preg_match('/^(`{3,})([a-zA-Z0-9_-]*)\s*$/D',$line,$match)===1){
				$fence=$match[1];
				$language=strtolower($match[2]);
				$code=[];
				$closed=false;
				for($index++;$index<$count;$index++){
					if(preg_match('/^'.preg_quote($fence,'/').'\s*$/D',$lines[$index])===1){ $closed=true; $index++; break; }
					$code[]=$lines[$index];
				}
				if(!$closed){ throw new \InvalidArgumentException('Documentation portal Markdown contains an unterminated code fence.'); }
				$class=$language!==''?' class="language-'.self::html($language).'"':'';
				$html[]='<div class="dp-doc-code"><pre tabindex="0"><code'.$class.'>'.self::html(implode("\n",$code)).'</code></pre></div>';
				continue;
			}
			if(preg_match('/^(#{1,6})\s+(.+)$/D',$line,$match)===1){
				$level=strlen($match[1]);
				$text=$this->plainInline($match[2],$source,$contentAssets);
				$id=self::headingId($text,$ids);
				if($level===1&&$title===''){ $title=$text; }
				if($level===2||$level===3){ $toc[]=['level'=>$level,'id'=>$id,'text'=>$text]; }
				$html[]='<h'.$level.' id="'.self::html($id).'">'.$this->inline($match[2],true,$source,$contentAssets).'<a class="dp-doc-anchor" href="#'.self::html($id).'" aria-label="Link to '.self::html($text).'">#</a></h'.$level.'>';
				$index++;
				continue;
			}
			if(str_starts_with($line,'> ')){
				$quote=[];
				while($index<$count&&str_starts_with($lines[$index],'> ')){ $quote[]=substr($lines[$index],2); $index++; }
				$html[]='<blockquote><p>'.$this->inline(implode(' ',$quote),true,$source,$contentAssets).'</p></blockquote>';
				continue;
			}
			if(preg_match('/^-\s+(.+)$/D',$line)===1){
				$items=[];
				while($index<$count&&preg_match('/^-\s+(.+)$/D',$lines[$index],$match)===1){ $items[]='<li>'.$this->inline($match[1],true,$source,$contentAssets).'</li>'; $index++; }
				$html[]='<ul>'.implode('',$items).'</ul>';
				continue;
			}
			if($index+1<$count&&self::looksLikeTableRow($line)&&self::looksLikeTableDivider($lines[$index+1])){
				$headers=self::tableCells($line);
				$index+=2;
				$rows=[];
				while($index<$count&&self::looksLikeTableRow($lines[$index])){ $rows[]=self::tableCells($lines[$index]); $index++; }
				$head='<tr>'.implode('',array_map(fn(string $cell):string=>'<th scope="col">'.$this->inline($cell,true,$source,$contentAssets).'</th>',$headers)).'</tr>';
				$body='';
				foreach($rows as $cells){
					$normalized=array_pad(array_slice($cells,0,count($headers)),count($headers),'');
					$body.='<tr>'.implode('',array_map(fn(string $cell):string=>'<td>'.$this->inline($cell,true,$source,$contentAssets).'</td>',$normalized)).'</tr>';
				}
				$html[]='<div class="dp-doc-table" tabindex="0"><table><thead>'.$head.'</thead><tbody>'.$body.'</tbody></table></div>';
				continue;
			}
			$paragraph=[];
			while($index<$count&&trim($lines[$index])!==''&&!self::blockStarts($lines,$index)){ $paragraph[]=trim($lines[$index]); $index++; }
			if($paragraph===[]){ $paragraph[]=$line; $index++; }
			$html[]='<p>'.$this->inline(implode(' ',$paragraph),true,$source,$contentAssets).'</p>';
		}
		if($title===''){ $title=self::fallbackTitle($source); }
		$body=implode("\n",$html);
		$searchBody=(string)preg_replace_callback('/<img\b[^>]*\balt="([^"]*)"[^>]*>/i',static fn(array $match):string=>' '.html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8').' ',$body);
		$plain=html_entity_decode(strip_tags($searchBody),ENT_QUOTES|ENT_HTML5,'UTF-8');
		$text=trim((string)preg_replace('/\s+/u',' ',strip_tags($plain)));
		return ['title'=>$title,'html'=>$body,'toc'=>$toc,'text'=>$text];
	}

	/** @param array<string,string> $contentAssets */
	private function inline(string $text,bool $links=true,string $source='',array $contentAssets=[]):string {
		$text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
		$output='';
		$buffer='';
		$length=strlen($text);
		$flush=static function() use (&$output,&$buffer):void { if($buffer!==''){ $output.=self::html($buffer); $buffer=''; } };
		for($index=0;$index<$length;){
			$character=$text[$index];
			if($character==='\\'&&$index+1<$length){ $buffer.=$text[$index+1]; $index+=2; continue; }
			if($character==='`'){
				$run=strspn($text,'`',$index);
				$fence=str_repeat('`',$run);
				$closing=strpos($text,$fence,$index+$run);
				if($closing!==false){
					$flush();
					$code=substr($text,$index+$run,$closing-$index-$run);
					if(strlen($code)>=2&&$code[0]===' '&&str_ends_with($code,' ')){ $code=substr($code,1,-1); }
					$output.='<code>'.self::html($code).'</code>';
					$index=$closing+$run;
					continue;
				}
			}
			if($links&&$character==='!'&&isset($text[$index+1])&&$text[$index+1]==='['){
				$labelEnd=self::unescapedPosition($text,']',$index+2);
				if($labelEnd!==null&&isset($text[$labelEnd+1])&&$text[$labelEnd+1]==='('){
					$targetEnd=self::linkTargetEnd($text,$labelEnd+2);
					if($targetEnd!==null){
						$alt=self::imageAlt(substr($text,$index+2,$labelEnd-$index-2));
						$definition=self::contentAssetDefinition(substr($text,$labelEnd+2,$targetEnd-$labelEnd-2));
						$target=$definition===null?null:self::contentAssetReference($definition['target'],$source,$contentAssets);
						$title=$definition!==null&&$definition['title']!==null?' title="'.self::html($definition['title']).'"':'';
						$flush();
						$output.=$target===null?self::html($alt):'<img class="dp-doc-image" src="'.self::html($target).'" alt="'.self::html($alt).'"'.$title.' loading="lazy" decoding="async">';
						$index=$targetEnd+1;
						continue;
					}
				}
			}
			if($links&&$character==='['){
				$labelEnd=self::unescapedPosition($text,']',$index+1);
				if($labelEnd!==null&&isset($text[$labelEnd+1])&&$text[$labelEnd+1]==='('){
					$targetEnd=self::linkTargetEnd($text,$labelEnd+2);
					if($targetEnd!==null){
						$target=self::href(substr($text,$labelEnd+2,$targetEnd-$labelEnd-2));
						$flush();
						$label=$this->inline(substr($text,$index+1,$labelEnd-$index-1),false,$source,$contentAssets);
						if($target!==''){
							$external=preg_match('/^https?:\/\//i',$target)===1;
							$attributes=$external?' target="_blank" rel="noopener noreferrer"':'';
							$output.='<a href="'.self::html(self::markdownHref($target)).'"'.$attributes.'>'.$label.'</a>';
						}
						else{ $output.=$label; }
						$index=$targetEnd+1;
						continue;
					}
				}
			}
			$buffer.=$character;
			$index++;
		}
		$flush();
		return $output;
	}

	/**
	 * @param list<array{version:string,url:string}> $versions
	 * @param list<array{id:string,label:string,path:string}> $navigationItems
	 */
	private function pageHtml(array $page,string $siteTitle,string $version,string $language,string $direction,string $theme,array $versions,array $navigationItems,?string $canonicalBase,?string $repositoryUrl,array $copy):string {
		$root=self::rootPath($page['path']);
		$canonical=$canonicalBase!==null?"\n\t".'<link rel="canonical" href="'.self::html($canonicalBase.$page['path']).'">':'';
		$description=self::description($page['text']);
		$navigation=$this->navigation($page['section'],$root,$navigationItems,$copy);
		$toc=$this->toc($page['toc'],$copy);
		$versionOptions=$this->versionOptions($versions,$version,$root);
		$repository=$repositoryUrl!==null?'<a class="dp-doc-repository" href="'.self::html($repositoryUrl).'" target="_blank" rel="noopener noreferrer">'.self::html($copy['repository']).'</a>':'';
		return '<!doctype html>
<html lang="'.self::html($language).'" dir="'.self::html($direction).'" data-theme="'.self::html($theme).'">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="light dark">
	<meta name="referrer" content="no-referrer">
	<meta http-equiv="Content-Security-Policy" content="default-src &#039;self&#039;; script-src &#039;self&#039;; style-src &#039;self&#039;; connect-src &#039;self&#039;; img-src &#039;self&#039;; object-src &#039;none&#039;; base-uri &#039;none&#039;; form-action &#039;none&#039;">
	<meta name="description" content="'.self::html($description).'">
	<title>'.self::html($page['title'].' | '.$siteTitle).'</title>'.$canonical.'
	<link rel="icon" href="'.self::html($root).'assets/favicon.svg" type="image/svg+xml">
	<link rel="stylesheet" href="'.self::html($root).'assets/portal.css">
	<script src="'.self::html($root).'assets/portal.js" defer></script>
</head>
<body data-root="'.self::html($root).'" data-search-index="'.self::html($root).'search-index.json">
	<a class="dp-doc-skip" href="#main-content">'.self::html($copy['skip_to_content']).'</a>
	<header class="dp-doc-header">
		<a class="dp-doc-brand" href="'.self::html($root).'index.html">'.self::html($siteTitle).'</a>
		<div class="dp-doc-header-actions">
			<button class="dp-doc-search-trigger" type="button" data-search-open aria-haspopup="dialog">'.self::html($copy['search']).' <kbd>Ctrl K</kbd></button>
			<label class="dp-doc-version"><span>'.self::html($copy['version']).'</span><select data-version-select>'.$versionOptions.'</select></label>
			<button class="dp-doc-theme" type="button" data-theme-toggle aria-label="'.self::html($copy['change_color_theme']).'">'.self::html($copy['theme']).'</button>
			'.$repository.'
			<button class="dp-doc-menu" type="button" data-nav-toggle aria-controls="portal-navigation" aria-expanded="false">'.self::html($copy['menu']).'</button>
		</div>
	</header>
	<button class="dp-doc-nav-backdrop" type="button" data-nav-backdrop aria-label="'.self::html($copy['close_navigation']).'" hidden></button>
	<div class="dp-doc-layout">
		<aside class="dp-doc-sidebar" id="portal-navigation" data-navigation aria-label="'.self::html($copy['documentation']).'">'.$navigation.'</aside>
		<main class="dp-doc-main" id="main-content" tabindex="-1">
			<article class="dp-doc-article">'.$page['body'].'</article>
			<footer class="dp-doc-footer">'.self::html(self::copy($copy,'generated_release',['version'=>$version])).'</footer>
		</main>
		'.$toc.'
	</div>
	<dialog class="dp-doc-search" data-search-dialog aria-labelledby="portal-search-title">
		<form method="dialog" class="dp-doc-search-head"><h2 id="portal-search-title">'.self::html($copy['search_documentation']).'</h2><button value="close" aria-label="'.self::html($copy['close_search']).'">'.self::html($copy['close']).'</button></form>
		<label class="dp-doc-search-field"><span>'.self::html($copy['search_terms']).'</span><input type="search" data-search-input autocomplete="off" maxlength="120" placeholder="'.self::html($copy['search_placeholder']).'"></label>
		<p class="dp-doc-search-status" data-search-status role="status">'.self::html($copy['type_two_characters']).'</p>
		<ol class="dp-doc-search-results" data-search-results></ol>
	</dialog>
	<p class="dp-doc-live" data-live-region role="status" aria-live="polite"></p>
	<noscript><p class="dp-doc-noscript">'.self::html($copy['javascript_required']).'</p></noscript>
</body>
</html>
';
	}

	/** @param list<array{version:string,url:string}> $versions */
	private function notFoundHtml(string $title,string $version,string $language,string $direction,string $theme,array $versions,?string $repositoryUrl,array $copy):string {
		$options=$this->versionOptions($versions,$version,'');
		$repository=$repositoryUrl!==null?'<a href="'.self::html($repositoryUrl).'" target="_blank" rel="noopener noreferrer">'.self::html($copy['repository']).'</a>':'';
		return '<!doctype html>
<html lang="'.self::html($language).'" dir="'.self::html($direction).'" data-theme="'.self::html($theme).'">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="referrer" content="no-referrer"><meta http-equiv="Content-Security-Policy" content="default-src &#039;self&#039;; script-src &#039;self&#039;; style-src &#039;self&#039;; connect-src &#039;self&#039;; img-src &#039;self&#039;; object-src &#039;none&#039;; base-uri &#039;none&#039;; form-action &#039;none&#039;"><meta name="robots" content="noindex"><title>'.self::html($copy['page_not_found']).' | '.self::html($title).'</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/portal.css"><script src="assets/portal.js" defer></script></head>
<body data-root="" data-search-index="search-index.json"><main class="dp-doc-missing" id="main-content"><p>'.self::html($copy['documentation']).'</p><h1>'.self::html($copy['page_not_found']).'</h1><p>'.self::html($copy['missing_page']).'</p><div><a class="dp-doc-primary" href="index.html">'.self::html($copy['open_documentation']).'</a>'.$repository.'</div><label class="dp-doc-version"><span>'.self::html($copy['version']).'</span><select data-version-select>'.$options.'</select></label></main><p class="dp-doc-live" data-live-region role="status" aria-live="polite"></p></body>
</html>
';
	}

	/**
	 * @param array<string,array<string,mixed>> $pages
	 * @return list<array{id:string,label:string,path:string}>
	 */
	private function navigationModel(array $pages,array $copy):array {
		$items=[];
		foreach($pages as $page){
			$id=(string)$page['section'];
			$preferred=$id==='overview'?'index.html':$id.'/index.html';
			if(!isset($items[$id])||$page['path']===$preferred){
				$label=match($id){
					'overview'=>$copy['overview'],
					'api'=>$copy['api_reference'],
					default=>(string)$page['title'],
				};
				$items[$id]=['id'=>$id,'label'=>$label,'path'=>(string)$page['path']];
			}
		}
		uasort($items,static function(array $left,array $right):int {
			$rank=static fn(string $id):int=>match($id){ 'overview'=>0,'api'=>1,default=>2 };
			return $rank($left['id'])<=>$rank($right['id'])?:$left['id']<=>$right['id'];
		});
		return array_values($items);
	}

	/** @param list<array{id:string,label:string,path:string}> $items */
	private function navigation(string $section,string $root,array $items,array $copy):string {
		$html='<nav><p class="dp-doc-nav-title">'.self::html($copy['documentation']).'</p><ul>';
		foreach($items as $item){ $current=$section===$item['id']?' aria-current="page"':''; $html.='<li><a href="'.self::html($root.$item['path']).'"'.$current.'>'.self::html($item['label']).'</a></li>'; }
		return $html.'</ul></nav>';
	}

	/** @param list<array{level:int,id:string,text:string}> $headings */
	private function toc(array $headings,array $copy):string {
		if($headings===[]){ return ''; }
		$html='<aside class="dp-doc-toc" aria-label="'.self::html($copy['on_this_page']).'"><p>'.self::html($copy['on_this_page']).'</p><ol>';
		foreach($headings as $heading){ $class=$heading['level']===3?' class="is-child"':''; $html.='<li'.$class.'><a href="#'.self::html($heading['id']).'">'.self::html($heading['text']).'</a></li>'; }
		return $html.'</ol></aside>';
	}

	/** @param list<array{version:string,url:string}> $versions */
	private function versionOptions(array $versions,string $current,string $root):string {
		$options='';
		foreach($versions as $item){
			$url=$item['url']===''?$root.'index.html':$item['url'];
			if($item['url']!==''&&!str_starts_with($url,'/')&&preg_match('/^https:\/\//i',$url)!==1){ $url=$root.$url; }
			$selected=$item['version']===$current?' selected':'';
			$options.='<option value="'.self::html($url).'"'.$selected.'>'.self::html($item['version']).'</option>';
		}
		return $options;
	}

	/** @param array<string,array<string,mixed>> $pages */
	private function sitemap(string $canonicalBase,array $pages):string {
		$urls='';
		foreach($pages as $page){ $urls.="\n  ".'<url><loc>'.self::xml($canonicalBase.$page['path']).'</loc></url>'; }
		return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls."\n".'</urlset>'."\n";
	}

	/** Canonical lowercase SemVer used by Datadoc portal metadata and version links. */
	public static function canonicalVersion(string $version):string {
		$version=trim($version);
		if(str_starts_with(strtolower($version),'v')){ $version=substr($version,1); }
		if($version===''||strlen($version)>64||strtolower($version)!==$version){ throw new \InvalidArgumentException('Documentation version must be canonical lowercase SemVer of at most 64 bytes.'); }
		$identifier='(?:0|[1-9][0-9]*)';
		$pre='(?:0|[1-9][0-9]*|[0-9]*[a-z-][0-9a-z-]*)';
		if(preg_match('/^'.$identifier.'\\.'.$identifier.'\\.'.$identifier.'(?:-'.$pre.'(?:\\.'.$pre.')*)?(?:\\+[0-9a-z-]+(?:\\.[0-9a-z-]+)*)?$/D',$version)!==1){
			throw new \InvalidArgumentException('Documentation version must be canonical lowercase SemVer of at most 64 bytes.');
		}
		return $version;
	}

	/**
	 * Validates a publishable inert content asset and returns its authoritative
	 * media type. Datadoc intentionally excludes SVG, HTML, PDF, scripts, fonts,
	 * and archives from this boundary even when a browser could display them.
	 */
	public static function contentAssetMime(string $path,string $contents):string {
		$path=self::relativePath($path);
		$bytes=strlen($contents);
		if($bytes<1||$bytes>self::MAX_CONTENT_ASSET_BYTES){ throw new \LengthException('Documentation portal content asset exceeds its byte bound.'); }
		$extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
		$mime=match($extension){
			'png'=>$bytes>=24&&str_starts_with($contents,"\x89PNG\r\n\x1a\n")&&substr($contents,12,4)==='IHDR'?'image/png':null,
			'jpg','jpeg'=>$bytes>=4&&str_starts_with($contents,"\xff\xd8\xff")&&str_ends_with($contents,"\xff\xd9")?'image/jpeg':null,
			'gif'=>$bytes>=10&&(str_starts_with($contents,'GIF87a')||str_starts_with($contents,'GIF89a'))?'image/gif':null,
			'webp'=>$bytes>=16&&str_starts_with($contents,'RIFF')&&substr($contents,8,4)==='WEBP'?'image/webp':null,
			'avif'=>$bytes>=16&&substr($contents,4,4)==='ftyp'&&preg_match('/(?:avif|avis)/',substr($contents,8,56))===1?'image/avif':null,
			'ico'=>$bytes>=6&&substr($contents,0,4)==="\x00\x00\x01\x00"&&unpack('vcount',substr($contents,4,2))['count']>0?'image/vnd.microsoft.icon':null,
			default=>null,
		};
		if($mime===null){ throw new \InvalidArgumentException('Documentation portal content assets must be signature-valid supported raster images.'); }
		return $mime;
	}

	private static function relativePath(string $path):string {
		if($path===''||$path!==trim($path)||strlen($path)>4096||preg_match('//u',$path)!==1||preg_match('/[\\x00-\\x1f\\x7f]/',$path)===1||str_contains($path,'\\')||str_contains($path,'%')||str_contains($path,'?')||str_contains($path,'#')||str_contains($path,':')||str_starts_with($path,'/')){
			throw new \InvalidArgumentException('Documentation portal paths must be safe relative UTF-8 paths.');
		}
		foreach(explode('/',$path) as $segment){
			if($segment===''||$segment==='.'||$segment==='..'||$segment!==trim($segment)||strlen($segment)>255){ throw new \InvalidArgumentException('Documentation portal paths must be safe relative UTF-8 paths.'); }
		}
		return $path;
	}

	/** @param mixed $value @return list<array{version:string,url:string}> */
	private static function versions(mixed $value,string $current):array {
		if(!is_array($value)||($value!==[]&&array_is_list($value))||count($value)>64){ throw new \InvalidArgumentException('Documentation portal version_links must be a bounded version-to-URL map.'); }
		$versions=[];
		foreach($value as $version=>$url){
			if(!is_string($version)||!is_string($url)){ throw new \InvalidArgumentException('Documentation portal version links are malformed.'); }
			$version=self::canonicalVersion($version);
			$url=trim($url);
			if($url===''&&$version!==$current){ throw new \InvalidArgumentException('Only the current documentation version may use an implicit local URL.'); }
			if($url!==''){ $url=self::versionUrl($url); }
			$versions[$version]=$url;
		}
		$versions[$current]=$versions[$current]??'';
		uksort($versions,static fn(string $left,string $right):int=>version_compare($right,$left));
		return array_map(static fn(string $version,string $url):array=>['version'=>$version,'url'=>$url],array_keys($versions),array_values($versions));
	}

	private static function versionUrl(string $url):string {
		$url=trim($url);
		if($url===''||strlen($url)>2048||preg_match('/[\x00-\x20\x7F]/',$url)===1||str_contains($url,'\\')||str_starts_with($url,'//')){ throw new \InvalidArgumentException('Documentation portal version URL is unsafe.'); }
		$probe=$url;
		for($pass=0;$pass<8;$pass++){ $decoded=rawurldecode(html_entity_decode($probe,ENT_QUOTES|ENT_HTML5,'UTF-8')); if($decoded===$probe){ break; } $probe=$decoded; }
		if(rawurldecode(html_entity_decode($probe,ENT_QUOTES|ENT_HTML5,'UTF-8'))!==$probe||preg_match('/[\x00-\x20\x7F]/',$probe)===1||str_contains($probe,'\\')||str_starts_with($probe,'//')){ throw new \InvalidArgumentException('Documentation portal version URL is unsafe.'); }
		if(str_starts_with($probe,'/')||preg_match('/^https:\/\//i',$probe)===1){ return self::publicUrl($url,'Documentation portal version URL'); }
		$parts=parse_url($probe);
		if($parts===false||isset($parts['scheme'])||isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])){ throw new \InvalidArgumentException('Documentation portal version URL is unsafe.'); }
		$path=(string)($parts['path']??'');
		if($path===''||str_starts_with($path,'./')){ throw new \InvalidArgumentException('Documentation portal version URL is unsafe.'); }
		$segments=explode('/',$path);
		$ascents=0;
		$seenName=false;
		foreach($segments as $segment){
			if($segment===''){ throw new \InvalidArgumentException('Documentation portal version URL is unsafe.'); }
			if($segment==='..'){
				if($seenName||++$ascents>16){ throw new \InvalidArgumentException('Documentation portal version URL is unsafe.'); }
				continue;
			}
			if($segment==='.'||strlen($segment)>255){ throw new \InvalidArgumentException('Documentation portal version URL is unsafe.'); }
			$seenName=true;
		}
		if(!$seenName){ throw new \InvalidArgumentException('Documentation portal version URL is unsafe.'); }
		return $url;
	}

	private static function canonicalBase(mixed $value):?string {
		if($value===null||$value===''){ return null; }
		$url=self::externalUrl($value,'Documentation portal canonical base URL',true);
		if($url===null){ return null; }
		$parts=parse_url($url);
		if($parts===false||isset($parts['query'])||isset($parts['fragment'])){ throw new \InvalidArgumentException('Documentation portal canonical base URL must not contain a query or fragment.'); }
		return rtrim($url,'/').'/';
	}

	private static function externalUrl(mixed $value,string $label,bool $nullable=false):?string {
		if($value===null||$value===''){ if($nullable){ return null; } throw new \InvalidArgumentException($label.' is required.'); }
		if(!is_string($value)){ throw new \InvalidArgumentException($label.' must be a string.'); }
		$url=self::publicUrl($value,$label);
		if($url===''||preg_match('/^https:\/\//i',$url)!==1){ throw new \InvalidArgumentException($label.' must be an absolute HTTPS URL.'); }
		return $url;
	}

	private static function publicUrl(string $url,string $label):string {
		$url=trim($url);
		if($url===''||strlen($url)>2048||preg_match('/[\x00-\x20\x7F]/',$url)===1||str_contains($url,'\\')||str_starts_with($url,'//')){ throw new \InvalidArgumentException($label.' is unsafe.'); }
		$probe=$url;
		for($pass=0;$pass<8;$pass++){ $decoded=rawurldecode(html_entity_decode($probe,ENT_QUOTES|ENT_HTML5,'UTF-8')); if($decoded===$probe){ break; } $probe=$decoded; }
		if(rawurldecode(html_entity_decode($probe,ENT_QUOTES|ENT_HTML5,'UTF-8'))!==$probe||preg_match('/[\x00-\x20\x7F]/',$probe)===1||str_contains($probe,'\\')||str_starts_with($probe,'//')){ throw new \InvalidArgumentException($label.' is unsafe.'); }
		if(str_starts_with($probe,'/')){
			foreach(explode('/',trim($probe,'/')) as $segment){ if($segment==='.'||$segment==='..'){ throw new \InvalidArgumentException($label.' is unsafe.'); } }
			return $url;
		}
		$parts=parse_url($probe);
		if($parts===false||strtolower((string)($parts['scheme']??''))!=='https'||trim((string)($parts['host']??''))===''||isset($parts['user'])||isset($parts['pass'])){ throw new \InvalidArgumentException($label.' is unsafe.'); }
		return $url;
	}

	private static function href(string $target):string {
		$target=trim(self::unescapeMarkdown($target));
		if($target===''||strlen($target)>2048||preg_match('/[\x00-\x1F\x7F]/',$target)===1||str_contains($target,'\\')||str_starts_with($target,'//')){ return ''; }
		$probe=$target;
		for($pass=0;$pass<8;$pass++){ $decoded=rawurldecode(html_entity_decode($probe,ENT_QUOTES|ENT_HTML5,'UTF-8')); if($decoded===$probe){ break; } $probe=$decoded; }
		if(rawurldecode(html_entity_decode($probe,ENT_QUOTES|ENT_HTML5,'UTF-8'))!==$probe||preg_match('/[\x00-\x1F\x7F]/',$probe)===1||str_contains($probe,'\\')||str_starts_with($probe,'//')){ return ''; }
		$parts=parse_url($probe);
		if($parts===false||isset($parts['user'])||isset($parts['pass'])){ return ''; }
		$scheme=strtolower((string)($parts['scheme']??''));
		if($scheme!==''&&!in_array($scheme,['http','https'],true)){ return ''; }
		if($scheme!==''&&trim((string)($parts['host']??''))===''){ return ''; }
		return $target;
	}

	private static function markdownHref(string $target):string {
		if(preg_match('/^https?:\/\//i',$target)===1){ return $target; }
		$offset=strcspn($target,'?#');
		$path=substr($target,0,$offset);
		$suffix=substr($target,$offset);
		if(str_ends_with(strtolower($path),'.md')){ $path=substr($path,0,-3).'.html'; }
		return $path.$suffix;
	}

	private static function imageAlt(string $value):string {
		$value=trim((string)preg_replace('/[\x00-\x1F\x7F]+/',' ',strip_tags(self::unescapeMarkdown(html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8')))));
		if(strlen($value)>512||preg_match('//u',$value)!==1){ throw new \InvalidArgumentException('Documentation portal image alternative text is invalid.'); }
		return $value;
	}

	/** @return array{target:string,title:?string}|null */
	private static function contentAssetDefinition(string $value):?array {
		$value=trim(self::unescapeMarkdown(html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8')));
		if($value===''){ return null; }
		if($value[0]==='<'){
			$end=strpos($value,'>');
			if($end===false){ return null; }
			$target=substr($value,1,$end-1);
			$remainder=trim(substr($value,$end+1));
		}
		else{
			$end=strcspn($value," \t\r\n");
			$target=substr($value,0,$end);
			$remainder=trim(substr($value,$end));
		}
		$title=null;
		if($remainder!==''){
			$quote=$remainder[0];
			if(strlen($remainder)<2||!in_array($quote,['"',"'"],true)||!str_ends_with($remainder,$quote)){ return null; }
			$title=self::imageAlt(substr($remainder,1,-1));
		}
		return $target===''?null:['target'=>$target,'title'=>$title];
	}

	/** @param array<string,string> $contentAssets */
	private static function contentAssetReference(string $target,string $source,array $contentAssets):?string {
		$target=trim(self::unescapeMarkdown(html_entity_decode($target,ENT_QUOTES|ENT_HTML5,'UTF-8')));
		if($target===''||strlen($target)>2048||preg_match('//u',$target)!==1||preg_match('/[\x00-\x1F\x7F]/',$target)===1||str_contains($target,'\\')||str_contains($target,'%')||str_contains($target,'?')||str_contains($target,'#')||str_starts_with($target,'/')){ return null; }
		$parts=parse_url($target);
		if($parts===false||isset($parts['scheme'])||isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])){ return null; }
		$sourceDirectory=dirname($source);
		$segments=$sourceDirectory==='.'?[]:explode('/',$sourceDirectory);
		foreach(explode('/',$target) as $segment){
			if($segment===''||$segment!==trim($segment)){ return null; }
			if($segment==='.'){ continue; }
			if($segment==='..'){ if($segments===[]){ return null; } array_pop($segments); continue; }
			$segments[]=$segment;
		}
		if($segments===[]){ return null; }
		try { $resolved=self::relativePath(implode('/',$segments)); }
		catch(\InvalidArgumentException){ return null; }
		$key=strtolower($resolved);
		if(!isset($contentAssets[$key])){ throw new \RuntimeException('Documentation portal Markdown references an unknown local content asset.'); }
		return self::relativeContentAssetUrl($source,$contentAssets[$key]);
	}

	private static function relativeContentAssetUrl(string $source,string $target):string {
		$from=dirname($source);
		$from=$from==='.'?[]:explode('/',$from);
		$to=explode('/',$target);
		while($from!==[]&&$to!==[]&&$from[0]===$to[0]){ array_shift($from); array_shift($to); }
		$segments=[...array_fill(0,count($from),'..'),...array_map('rawurlencode',$to)];
		return implode('/',$segments);
	}

	private static function language(mixed $value):string {
		if(!is_string($value)){ throw new \InvalidArgumentException('Documentation portal language must be a string.'); }
		$value=trim($value);
		if(strlen($value)>35||preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,3}$/D',$value)!==1){ throw new \InvalidArgumentException('Documentation portal language is invalid.'); }
		return $value;
	}

	private static function direction(mixed $value):string {
		if(!is_string($value)||!in_array($value,['ltr','rtl'],true)){ throw new \InvalidArgumentException('Documentation portal direction must be ltr or rtl.'); }
		return $value;
	}

	/** @return array<string,string> */
	private static function uiCopy(mixed $value):array {
		$defaults=[
			'skip_to_content'=>'Skip to content',
			'repository'=>'Repository',
			'search'=>'Search',
			'version'=>'Version',
			'change_color_theme'=>'Change color theme',
			'theme'=>'Theme',
			'menu'=>'Menu',
			'close_navigation'=>'Close navigation',
			'documentation'=>'Documentation',
			'generated_release'=>'Generated from the verified {version} documentation release.',
			'search_documentation'=>'Search documentation',
			'close_search'=>'Close search',
			'close'=>'Close',
			'search_terms'=>'Search terms',
			'search_placeholder'=>'Type a class, method, or guide',
			'type_two_characters'=>'Type at least two characters.',
			'javascript_required'=>'Search, theme switching, and mobile navigation need JavaScript. Every documentation page remains readable without it.',
			'page_not_found'=>'Page not found',
			'missing_page'=>'The requested page is not part of this immutable release.',
			'open_documentation'=>'Open documentation',
			'on_this_page'=>'On this page',
			'overview'=>'Overview',
			'api_reference'=>'API reference',
			'color_theme_status'=>'Color theme: {theme}. Activate to change.',
			'color_theme_changed'=>'Color theme changed to {theme}.',
			'search_result_one'=>'{count} result.',
			'search_result_many'=>'{count} results.',
			'no_matching_documentation'=>'No matching documentation.',
			'searching'=>'Searching.',
			'search_index_unavailable'=>'Search index could not be loaded.',
			'copy'=>'Copy',
			'copied'=>'Copied',
			'code_copied'=>'Code copied to clipboard.',
			'code_copy_failed'=>'Code could not be copied.',
		];
		if($value===[]||$value===null){ return $defaults; }
		if(!is_array($value)||array_is_list($value)
			|| array_diff(array_keys($defaults),array_keys($value))!==[]
			|| array_diff(array_keys($value),array_keys($defaults))!==[]){
			throw new \InvalidArgumentException('Documentation portal UI copy must provide exactly every supported key.');
		}
		$copy=[];
		foreach($defaults as $key=>$fallback){
			$copy[$key]=self::boundedText($value[$key]??null,'Documentation portal UI copy '.$key,600);
			preg_match_all('/\{[a-z_]+\}/',$fallback,$expected);
			preg_match_all('/\{[a-z_]+\}/',$copy[$key],$actual);
			if(($expected[0]??[])!==($actual[0]??[])){
				throw new \InvalidArgumentException('Documentation portal UI copy placeholders are inconsistent for '.$key.'.');
			}
		}
		return $copy;
	}

	/** @param array<string,string> $copy @param array<string,string|int> $parameters */
	private static function copy(array $copy,string $key,array $parameters=[]):string {
		$value=$copy[$key]??'';
		foreach($parameters as $name=>$replacement){ $value=str_replace('{'.$name.'}',(string)$replacement,$value); }
		return $value;
	}

	private static function theme(mixed $value):string {
		if(!is_string($value)||!in_array($value,['system','light','dark'],true)){ throw new \InvalidArgumentException('Documentation portal default theme must be system, light, or dark.'); }
		return $value;
	}

	private static function boundedInteger(mixed $value,int $minimum,int $maximum,string $label):int {
		if(!is_int($value)||$value<$minimum||$value>$maximum){ throw new \InvalidArgumentException($label.' is outside its supported bound.'); }
		return $value;
	}

	private static function boundedText(mixed $value,string $label,int $maximum):string {
		if(!is_string($value)){ throw new \InvalidArgumentException($label.' must be a string.'); }
		$value=trim((string)preg_replace('/[\x00-\x1F\x7F]+/',' ',$value));
		if($value===''||strlen($value)>$maximum||preg_match('//u',$value)!==1){ throw new \InvalidArgumentException($label.' is invalid.'); }
		return $value;
	}

	/** @param array<string,int> $seen */
	private static function headingId(string $text,array &$seen):string {
		$base=trim((string)preg_replace('/[^a-z0-9]+/','-',strtolower($text)),'-');
		if($base===''){ $base='section'; }
		$seen[$base]=($seen[$base]??0)+1;
		return $seen[$base]===1?$base:$base.'-'.$seen[$base];
	}

	/** @param array<string,string> $contentAssets */
	private function plainInline(string $text,string $source='',array $contentAssets=[]):string {
		$text=strip_tags($text);
		return trim(html_entity_decode(strip_tags($this->inline($text,true,$source,$contentAssets)),ENT_QUOTES|ENT_HTML5,'UTF-8'));
	}

	private static function unescapeMarkdown(string $text):string {
		return (string)preg_replace_callback('/\\\\(.)/s',static fn(array $match):string=>str_contains('\\`*_{}[]()#+-.!|>',$match[1])?$match[1]:$match[0],$text);
	}

	private static function unescapedPosition(string $text,string $needle,int $offset):?int {
		while(($position=strpos($text,$needle,$offset))!==false){
			$slashes=0;
			for($probe=$position-1;$probe>=0&&$text[$probe]==='\\';$probe--){ $slashes++; }
			if($slashes%2===0){ return $position; }
			$offset=$position+1;
		}
		return null;
	}

	private static function linkTargetEnd(string $text,int $offset):?int {
		$depth=0;
		$quote=null;
		$angle=false;
		for($index=$offset,$length=strlen($text);$index<$length;$index++){
			if($text[$index]==='\\'){ $index++; continue; }
			if($angle){ if($text[$index]==='>'){ $angle=false; } continue; }
			if($quote!==null){ if($text[$index]===$quote){ $quote=null; } continue; }
			if($text[$index]==='<'&&$index===$offset){ $angle=true; continue; }
			if($text[$index]==='"'||$text[$index]==="'"){ $quote=$text[$index]; continue; }
			if($text[$index]==='('){ if(++$depth>16){ return null; } continue; }
			if($text[$index]!==')'){ continue; }
			if($depth===0){ return $index; }
			$depth--;
		}
		return null;
	}

	/** @param list<string> $lines */
	private static function blockStarts(array $lines,int $index):bool {
		$line=$lines[$index]??'';
		if(preg_match('/^(?:#{1,6}\s+|`{3,}|>\s+|-\s+)/',$line)===1){ return true; }
		return isset($lines[$index+1])&&self::looksLikeTableRow($line)&&self::looksLikeTableDivider($lines[$index+1]);
	}

	private static function looksLikeTableRow(string $line):bool { return str_starts_with(trim($line),'|')&&str_ends_with(trim($line),'|'); }
	private static function looksLikeTableDivider(string $line):bool { return preg_match('/^\s*\|(?:\s*:?-{3,}:?\s*\|)+\s*$/D',$line)===1; }

	/** @return list<string> */
	private static function tableCells(string $line):array {
		$line=trim($line);
		$line=substr($line,1,-1);
		$cells=[];
		$buffer='';
		$escaped=false;
		for($index=0,$length=strlen($line);$index<$length;$index++){
			$character=$line[$index];
			if($escaped){ $buffer.='\\'.$character; $escaped=false; continue; }
			if($character==='\\'){ $escaped=true; continue; }
			if($character==='|'){ $cells[]=trim($buffer); $buffer=''; continue; }
			$buffer.=$character;
		}
		if($escaped){ $buffer.='\\'; }
		$cells[]=trim($buffer);
		return $cells;
	}

	private static function fallbackTitle(string $source):string {
		$name=pathinfo($source,PATHINFO_FILENAME);
		$name=str_replace(['-','_'],' ',$name);
		$name=trim((string)preg_replace('/\s+/',' ',$name));
		return $name===''?'Documentation':ucwords($name);
	}

	private static function section(string $path):string {
		$separator=strpos($path,'/');
		return $separator===false?'overview':strtolower(substr($path,0,$separator));
	}

	private static function rootPath(string $path):string {
		$directory=dirname($path);
		return $directory==='.'?'':str_repeat('../',substr_count(str_replace('\\','/',$directory),'/')+1);
	}

	private static function description(string $text):string {
		$text=trim((string)preg_replace('/\s+/u',' ',$text));
		return self::byteCut($text,180);
	}

	private static function byteCut(string $value,int $maximum):string {
		if(strlen($value)<=$maximum){ return $value; }
		$value=substr($value,0,$maximum);
		while($value!==''&&preg_match('//u',$value)!==1){ $value=substr($value,0,-1); }
		return rtrim($value);
	}

	private static function html(string $value):string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8'); }
	private static function xml(string $value):string { return htmlspecialchars($value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

	private static function json(array $value):string {
		try{return json_encode(self::canonical($value),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n";}
		catch(\JsonException $error){ throw new \RuntimeException('Documentation portal data could not be encoded.',0,$error); }
	}

	/** @param array<mixed> $value @return array<mixed> */
	private static function canonical(array $value):array {
		foreach($value as $key=>$item){ if(is_array($item)){ $value[$key]=self::canonical($item); } }
		if(!array_is_list($value)){ ksort($value,SORT_STRING); }
		return $value;
	}

	private static function favicon():string {
		return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="Dataphyre documentation">
<rect width="64" height="64" rx="14" fill="#1457c9"/>
<path fill="#fff" fill-rule="evenodd" d="M16 14h13c13 0 21 7 21 18s-8 18-21 18H16V14Zm11 9v18h3c7 0 11-3 11-9s-4-9-11-9h-3Z"/>
<circle cx="49" cy="15" r="4" fill="#8ab2ff"/>
</svg>
SVG;
	}

	private static function css():string {
		return <<<'CSS'
:root{color-scheme:light dark;--doc-bg:#f5f7fa;--doc-surface:#fbfcfe;--doc-surface-strong:#edf1f6;--doc-text:#172033;--doc-muted:#59677b;--doc-border:#d6dde7;--doc-accent:#1457c9;--doc-accent-soft:#e3ecfc;--doc-code:#eef2f7;--doc-radius:10px;--doc-header:64px;--doc-shadow:0 16px 44px rgb(31 47 72/.12);font-family:ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-synthesis:none;text-rendering:optimizeLegibility}
:root[data-theme=dark]{--doc-bg:#0d121a;--doc-surface:#121925;--doc-surface-strong:#1b2533;--doc-text:#edf2f8;--doc-muted:#aab5c5;--doc-border:#2a3748;--doc-accent:#8ab2ff;--doc-accent-soft:#172b4c;--doc-code:#101722;--doc-shadow:0 18px 50px rgb(3 7 14/.42)}
@media(prefers-color-scheme:dark){:root[data-theme=system]{--doc-bg:#0d121a;--doc-surface:#121925;--doc-surface-strong:#1b2533;--doc-text:#edf2f8;--doc-muted:#aab5c5;--doc-border:#2a3748;--doc-accent:#8ab2ff;--doc-accent-soft:#172b4c;--doc-code:#101722;--doc-shadow:0 18px 50px rgb(3 7 14/.42)}}
*{box-sizing:border-box}html{background:var(--doc-bg);scroll-padding-top:calc(var(--doc-header) + 24px)}body{margin:0;background:var(--doc-bg);color:var(--doc-text);font-size:15px;line-height:1.65}a{color:var(--doc-accent);text-underline-offset:3px}button,input,select{font:inherit}button,select,input{color:inherit}.dp-doc-skip{position:fixed;z-index:50;left:16px;top:10px;transform:translateY(-160%);padding:10px 14px;border-radius:var(--doc-radius);background:var(--doc-text);color:var(--doc-bg)}.dp-doc-skip:focus{transform:none}.dp-doc-header{position:sticky;z-index:30;top:0;display:flex;align-items:center;justify-content:space-between;min-height:var(--doc-header);padding:0 clamp(16px,3vw,44px);border-bottom:1px solid var(--doc-border);background:var(--doc-bg);background:color-mix(in srgb,var(--doc-bg) 92%,transparent);backdrop-filter:blur(16px)}.dp-doc-brand{color:var(--doc-text);font-weight:760;font-size:16px;text-decoration:none;letter-spacing:-.02em;white-space:nowrap}.dp-doc-header-actions{display:flex;align-items:center;gap:8px}.dp-doc-header button,.dp-doc-header select,.dp-doc-repository{min-height:42px;border:1px solid var(--doc-border);border-radius:var(--doc-radius);background:var(--doc-surface);padding:8px 12px;text-decoration:none}.dp-doc-header button:hover,.dp-doc-header select:hover,.dp-doc-repository:hover{border-color:var(--doc-accent)}.dp-doc-search-trigger{min-width:174px;text-align:left;display:flex;align-items:center;justify-content:space-between;gap:20px}.dp-doc-search-trigger kbd{color:var(--doc-muted);font-size:11px}.dp-doc-version{display:flex;align-items:center;gap:7px;color:var(--doc-muted);font-size:12px}.dp-doc-version select{font-size:13px}.dp-doc-menu{display:none}.dp-doc-layout{display:grid;grid-template-columns:minmax(190px,248px) minmax(0,780px) minmax(160px,220px);gap:clamp(24px,4vw,64px);max-width:1440px;margin:0 auto;padding:42px clamp(18px,3.5vw,52px) 90px}.dp-doc-sidebar,.dp-doc-toc{align-self:start;position:sticky;top:calc(var(--doc-header) + 28px);max-height:calc(100vh - var(--doc-header) - 56px);max-height:calc(100dvh - var(--doc-header) - 56px);overflow:auto}.dp-doc-nav-title,.dp-doc-toc>p{margin:0 0 12px;color:var(--doc-muted);font-size:12px;font-weight:700}.dp-doc-sidebar ul,.dp-doc-toc ol{list-style:none;margin:0;padding:0}.dp-doc-sidebar li+li{margin-top:4px}.dp-doc-sidebar a,.dp-doc-toc a{display:block;border-radius:8px;color:var(--doc-muted);padding:8px 10px;text-decoration:none}.dp-doc-sidebar a:hover,.dp-doc-toc a:hover{color:var(--doc-text);background:var(--doc-surface-strong)}.dp-doc-sidebar a[aria-current=page]{color:var(--doc-accent);background:var(--doc-accent-soft);font-weight:700}.dp-doc-toc a{font-size:12px;padding:5px 8px}.dp-doc-toc .is-child a{padding-left:20px}.dp-doc-main{min-width:0}.dp-doc-article{max-width:78ch;overflow-wrap:anywhere}.dp-doc-article h1,.dp-doc-article h2,.dp-doc-article h3,.dp-doc-article h4,.dp-doc-article h5,.dp-doc-article h6{position:relative;color:var(--doc-text);line-height:1.2;letter-spacing:-.025em}.dp-doc-article h1{margin:2px 0 22px;font-size:clamp(2rem,4vw,3.2rem);font-weight:790}.dp-doc-article h2{margin:56px 0 16px;font-size:clamp(1.45rem,2.4vw,2rem)}.dp-doc-article h3{margin:38px 0 12px;font-size:1.25rem}.dp-doc-article h4{margin:30px 0 10px;font-size:1.05rem}.dp-doc-anchor{margin-left:8px;color:var(--doc-muted);font-weight:500;text-decoration:none;opacity:0}.dp-doc-article :is(h1,h2,h3,h4,h5,h6):hover .dp-doc-anchor,.dp-doc-anchor:focus{opacity:1}.dp-doc-article p{margin:0 0 18px;color:var(--doc-muted)}.dp-doc-image{display:block;width:auto;max-width:100%;height:auto;margin:24px 0;border:1px solid var(--doc-border);border-radius:var(--doc-radius);background:var(--doc-surface)}.dp-doc-article li{margin:7px 0}.dp-doc-article ul{padding-left:22px;margin:0 0 22px}.dp-doc-article blockquote{margin:26px 0;padding:3px 0 3px 20px;border-left:3px solid var(--doc-accent)}.dp-doc-article blockquote p{margin:0;color:var(--doc-text)}.dp-doc-article :not(pre)>code{border:1px solid var(--doc-border);border-radius:6px;background:var(--doc-code);padding:2px 6px;color:var(--doc-text);font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.88em}.dp-doc-code{position:relative;margin:24px 0}.dp-doc-code pre{max-width:100%;overflow:auto;margin:0;border:1px solid var(--doc-border);border-radius:var(--doc-radius);background:var(--doc-code);padding:20px;line-height:1.55}.dp-doc-code code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px}.dp-doc-copy{position:absolute;right:9px;top:9px;min-height:36px;border:1px solid var(--doc-border);border-radius:8px;background:var(--doc-surface);padding:6px 10px}.dp-doc-table{max-width:100%;overflow:auto;margin:24px 0;border:1px solid var(--doc-border);border-radius:var(--doc-radius)}.dp-doc-table table{width:100%;border-collapse:collapse;background:var(--doc-surface);font-size:13px}.dp-doc-table th,.dp-doc-table td{min-width:120px;padding:12px 14px;border-bottom:1px solid var(--doc-border);text-align:left;vertical-align:top}.dp-doc-table tr:last-child td{border-bottom:0}.dp-doc-table th{background:var(--doc-surface-strong);font-weight:720}.dp-doc-footer{margin-top:70px;padding-top:20px;border-top:1px solid var(--doc-border);color:var(--doc-muted);font-size:12px}.dp-doc-search{width:min(720px,calc(100vw - 24px));max-height:min(760px,calc(100vh - 24px));max-height:min(760px,calc(100dvh - 24px));border:1px solid var(--doc-border);border-radius:var(--doc-radius);background:var(--doc-surface);color:var(--doc-text);padding:0;box-shadow:var(--doc-shadow)}.dp-doc-search::backdrop{background:rgb(16 24 38/.56)}.dp-doc-search-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid var(--doc-border)}.dp-doc-search-head h2{margin:0;font-size:18px}.dp-doc-search-head button{min-height:40px;border:1px solid var(--doc-border);border-radius:8px;background:var(--doc-surface-strong);padding:7px 11px}.dp-doc-search-field{display:block;padding:18px 20px 8px}.dp-doc-search-field span{display:block;margin-bottom:7px;color:var(--doc-muted);font-size:12px;font-weight:700}.dp-doc-search-field input{width:100%;min-height:48px;border:1px solid var(--doc-border);border-radius:var(--doc-radius);background:var(--doc-bg);padding:11px 13px}.dp-doc-search-status{margin:0;padding:10px 20px;color:var(--doc-muted);font-size:13px}.dp-doc-search-results{list-style:none;margin:0;padding:0 10px 18px}.dp-doc-search-results a{display:block;border-radius:var(--doc-radius);padding:12px;color:var(--doc-text);text-decoration:none}.dp-doc-search-results a:hover,.dp-doc-search-results a:focus{background:var(--doc-surface-strong)}.dp-doc-search-results strong{display:block}.dp-doc-search-results span{display:block;color:var(--doc-muted);font-size:12px}.dp-doc-live{position:fixed;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}.dp-doc-noscript{position:fixed;left:16px;right:16px;bottom:16px;z-index:40;margin:0;border:1px solid var(--doc-border);border-radius:var(--doc-radius);background:var(--doc-surface);padding:12px}.dp-doc-nav-backdrop{position:fixed;z-index:31;inset:var(--doc-header) 0 0;border:0;background:rgb(16 24 38/.48)}.dp-doc-missing{display:grid;align-content:center;min-height:100vh;min-height:100dvh;max-width:720px;margin:auto;padding:32px}.dp-doc-missing>p:first-child{color:var(--doc-muted);font-weight:700}.dp-doc-missing h1{font-size:clamp(2.2rem,7vw,4.6rem);line-height:1;margin:0 0 18px}.dp-doc-missing div{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0}.dp-doc-missing a{min-height:44px;border:1px solid var(--doc-border);border-radius:var(--doc-radius);padding:10px 14px;text-decoration:none}.dp-doc-missing .dp-doc-primary{background:var(--doc-accent);color:var(--doc-bg);border-color:var(--doc-accent)}
:focus-visible{outline:3px solid var(--doc-accent);outline-offset:3px}
@media(max-width:1080px){.dp-doc-layout{grid-template-columns:220px minmax(0,1fr)}.dp-doc-toc{display:none}}
[dir=rtl] .dp-doc-skip{left:auto;right:16px}[dir=rtl] .dp-doc-search-trigger,[dir=rtl] .dp-doc-table th,[dir=rtl] .dp-doc-table td{text-align:right}[dir=rtl] .dp-doc-anchor{margin-left:0;margin-right:8px}[dir=rtl] .dp-doc-toc .is-child a{padding-left:8px;padding-right:20px}
@media(max-width:760px){.dp-doc-header{padding:0 12px}.dp-doc-header-actions{gap:5px}.dp-doc-search-trigger{min-width:44px;width:44px;overflow:hidden;white-space:nowrap}.dp-doc-search-trigger kbd,.dp-doc-version>span,.dp-doc-theme,.dp-doc-repository{display:none}.dp-doc-menu{display:block}.dp-doc-layout{display:block;padding:28px 16px 70px}.dp-doc-sidebar{position:fixed;z-index:32;top:var(--doc-header);bottom:0;left:0;width:min(320px,88vw);max-height:none;transform:translateX(-104%);border-right:1px solid var(--doc-border);background:var(--doc-surface);padding:24px 16px;box-shadow:var(--doc-shadow)}[dir=rtl] .dp-doc-sidebar{left:auto;right:0;transform:translateX(104%);border-right:0;border-left:1px solid var(--doc-border)}.dp-doc-sidebar.is-open{transform:none}.dp-doc-article h1{font-size:clamp(1.9rem,10vw,2.8rem);overflow-wrap:anywhere}.dp-doc-article h2{margin-top:44px}.dp-doc-header select{max-width:98px}.dp-doc-footer{margin-top:52px}}
@media(max-width:760px){.dp-doc-header{gap:8px;padding-inline:10px}.dp-doc-brand{flex:0 1 30vw;max-width:30vw;min-width:0;overflow:hidden;text-overflow:ellipsis}.dp-doc-header-actions{flex:1 1 auto;min-width:0;justify-content:flex-end}.dp-doc-version{min-width:0}.dp-doc-version select,.dp-doc-header select{width:72px;max-width:72px;min-width:0;padding-inline:6px}.dp-doc-search-trigger{flex:0 0 44px;justify-content:center;gap:0;font-size:0}.dp-doc-search-trigger::after{content:'⌕';font-size:21px;line-height:1}.dp-doc-menu{white-space:nowrap;padding-inline:9px}}
@media(prefers-reduced-motion:no-preference){.dp-doc-sidebar,.dp-doc-anchor{transition:transform .18s ease,opacity .18s ease}.dp-doc-header button:active,.dp-doc-header a:active,.dp-doc-copy:active{transform:translateY(1px)}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important;animation:none!important}}
@media(forced-colors:active){.dp-doc-header,.dp-doc-table,.dp-doc-code pre,.dp-doc-search,.dp-doc-sidebar,.dp-doc-image{border-color:CanvasText}.dp-doc-sidebar a[aria-current=page]{outline:2px solid Highlight}.dp-doc-nav-backdrop{background:CanvasText}.dp-doc-primary{forced-color-adjust:none}}
@media print{.dp-doc-header,.dp-doc-sidebar,.dp-doc-toc,.dp-doc-search,.dp-doc-footer,.dp-doc-anchor,.dp-doc-copy,.dp-doc-noscript{display:none!important}.dp-doc-layout{display:block;max-width:none;padding:0}.dp-doc-article{max-width:none}.dp-doc-code pre,.dp-doc-table,.dp-doc-image{break-inside:avoid}}
CSS;
	}

	/** @param array<string,string> $copy */
	private static function javascript(array $copy):string {
		$javascript=<<<'JS'
(()=>{"use strict";const doc=document;const root=doc.documentElement;const body=doc.body;const live=doc.querySelector("[data-live-region]");const announce=(message)=>{if(live)live.textContent=message;};const safeStorage={get(key){try{return localStorage.getItem(key);}catch{return null;}},set(key,value){try{localStorage.setItem(key,value);}catch{}}};const allowedThemes=["system","light","dark"];const applyTheme=(theme)=>{const selected=allowedThemes.includes(theme)?theme:"system";root.dataset.theme=selected;safeStorage.set("dataphyre-datadoc-theme",selected);const button=doc.querySelector("[data-theme-toggle]");if(button)button.setAttribute("aria-label",`Color theme: ${selected}. Activate to change.`);};applyTheme(safeStorage.get("dataphyre-datadoc-theme")||root.dataset.theme||"system");doc.querySelector("[data-theme-toggle]")?.addEventListener("click",()=>{const current=allowedThemes.indexOf(root.dataset.theme||"system");applyTheme(allowedThemes[(current+1)%allowedThemes.length]);announce(`Color theme changed to ${root.dataset.theme}.`);});doc.querySelectorAll("[data-version-select]").forEach((select)=>select.addEventListener("change",()=>{const target=select.value;if(target&&(/^(?:https:\/\/|\/|(?:\.\.\/)*[a-z0-9])/i.test(target)))location.assign(target);}));const navigation=doc.querySelector("[data-navigation]");const navToggle=doc.querySelector("[data-nav-toggle]");const backdrop=doc.querySelector("[data-nav-backdrop]");const setNavigation=(open)=>{if(!navigation||!navToggle||!backdrop)return;navigation.classList.toggle("is-open",open);navToggle.setAttribute("aria-expanded",String(open));backdrop.hidden=!open;if(open)navigation.querySelector("a")?.focus();};navToggle?.addEventListener("click",()=>setNavigation(navToggle.getAttribute("aria-expanded")!=="true"));backdrop?.addEventListener("click",()=>setNavigation(false));navigation?.addEventListener("click",(event)=>{if(event.target instanceof HTMLAnchorElement)setNavigation(false);});const dialog=doc.querySelector("[data-search-dialog]");const input=doc.querySelector("[data-search-input]");const status=doc.querySelector("[data-search-status]");const results=doc.querySelector("[data-search-results]");let restoreFocus=null;let searchPromise=null;const openSearch=()=>{if(!(dialog instanceof HTMLDialogElement)||!(input instanceof HTMLInputElement))return;restoreFocus=doc.activeElement;if(!dialog.open)dialog.showModal();input.focus();input.select();};const closeSearch=()=>{if(dialog instanceof HTMLDialogElement&&dialog.open)dialog.close();if(restoreFocus instanceof HTMLElement)restoreFocus.focus();};doc.querySelectorAll("[data-search-open]").forEach((button)=>button.addEventListener("click",openSearch));dialog?.addEventListener("close",()=>{if(restoreFocus instanceof HTMLElement)restoreFocus.focus();});doc.addEventListener("keydown",(event)=>{const target=event.target;const editing=target instanceof HTMLInputElement||target instanceof HTMLTextAreaElement||target instanceof HTMLSelectElement||target?.isContentEditable;if((event.key==="k"&&(event.ctrlKey||event.metaKey))||(event.key==="/"&&!editing)){event.preventDefault();openSearch();}if(event.key==="Escape"&&navigation?.classList.contains("is-open"))setNavigation(false);});const loadSearch=()=>{if(searchPromise)return searchPromise;const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),8000);searchPromise=fetch(body.dataset.searchIndex||"search-index.json",{credentials:"same-origin",cache:"force-cache",headers:{Accept:"application/json"},signal:controller.signal}).then((response)=>{if(!response.ok)throw new Error("search unavailable");return response.json();}).then((payload)=>{if(!payload||payload.type!=="dataphyre_datadoc_search_index"||!Array.isArray(payload.entries)||payload.entries.length>10000)throw new Error("search invalid");return payload.entries.filter((entry)=>entry&&typeof entry.title==="string"&&typeof entry.path==="string"&&typeof entry.text==="string");}).finally(()=>clearTimeout(timeout));return searchPromise;};const renderResults=(entries,query)=>{if(!results||!status)return;results.replaceChildren();const terms=query.toLocaleLowerCase().split(/\s+/u).filter(Boolean).slice(0,8);const ranked=entries.map((entry)=>{const title=entry.title.toLocaleLowerCase();const text=entry.text.toLocaleLowerCase();const headings=Array.isArray(entry.headings)?entry.headings.join(" ").toLocaleLowerCase():"";if(!terms.every((term)=>title.includes(term)||headings.includes(term)||text.includes(term)))return null;let score=0;for(const term of terms){if(title.startsWith(term))score+=8;else if(title.includes(term))score+=5;if(headings.includes(term))score+=3;if(text.includes(term))score+=1;}return{entry,score};}).filter(Boolean).sort((left,right)=>right.score-left.score||left.entry.title.localeCompare(right.entry.title)).slice(0,20);for(const item of ranked){const li=doc.createElement("li");const anchor=doc.createElement("a");const heading=doc.createElement("strong");const meta=doc.createElement("span");anchor.href=(body.dataset.root||"")+item.entry.path;heading.textContent=item.entry.title;meta.textContent=item.entry.section||"Documentation";anchor.append(heading,meta);li.append(anchor);results.append(li);}status.textContent=ranked.length?`${ranked.length} result${ranked.length===1?"":"s"}.`:"No matching documentation.";};let searchTimer=0;input?.addEventListener("input",()=>{clearTimeout(searchTimer);const query=input.value.trim().slice(0,120);if(query.length<2){results?.replaceChildren();if(status)status.textContent="Type at least two characters.";return;}if(status)status.textContent="Searching.";searchTimer=setTimeout(()=>loadSearch().then((entries)=>renderResults(entries,query)).catch(()=>{if(status)status.textContent="Search index could not be loaded.";}),90);});doc.querySelectorAll(".dp-doc-code").forEach((wrapper)=>{const code=wrapper.querySelector("code");if(!code)return;const button=doc.createElement("button");button.type="button";button.className="dp-doc-copy";button.textContent="Copy";button.addEventListener("click",async()=>{try{await navigator.clipboard.writeText(code.textContent||"");button.textContent="Copied";announce("Code copied to clipboard.");setTimeout(()=>{button.textContent="Copy";},1600);}catch{announce("Code could not be copied.");}});wrapper.append(button);});if(location.hash){const id=decodeURIComponent(location.hash.slice(1));doc.getElementById(id)?.scrollIntoView({block:"start"});}dialog?.addEventListener("click",(event)=>{if(event.target===dialog)closeSearch();});})();
JS;
		$copyJson=(string)json_encode($copy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
		$javascript=str_replace('const doc=document;','const copy='.$copyJson.';const format=(value,parameters={})=>Object.entries(parameters).reduce((text,[name,replacement])=>text.split(`{${name}}`).join(String(replacement)),value);const doc=document;',$javascript);
		$needles=['button.setAttribute("aria-label",`Color theme: ${selected}. Activate to change.`)','announce(`Color theme changed to ${root.dataset.theme}.`)','item.entry.section||"Documentation"','status.textContent=ranked.length?`${ranked.length} result${ranked.length===1?"":"s"}.`:"No matching documentation."','status.textContent="Type at least two characters."','status.textContent="Searching."','status.textContent="Search index could not be loaded."','button.textContent="Copy"','button.textContent="Copied"','announce("Code copied to clipboard.")','announce("Code could not be copied.")'];
		$localized=['button.setAttribute("aria-label",format(copy.color_theme_status,{theme:selected}))','announce(format(copy.color_theme_changed,{theme:root.dataset.theme}))','item.entry.section||copy.documentation','status.textContent=ranked.length?format(ranked.length===1?copy.search_result_one:copy.search_result_many,{count:ranked.length}):copy.no_matching_documentation','status.textContent=copy.type_two_characters','status.textContent=copy.searching','status.textContent=copy.search_index_unavailable','button.textContent=copy.copy','button.textContent=copy.copied','announce(copy.code_copied)','announce(copy.code_copy_failed)'];
		$javascript=str_replace($needles,$localized,$javascript);
		$javascript=str_replace('backdrop?.addEventListener("click",()=>setNavigation(false));','backdrop?.addEventListener("click",()=>{setNavigation(false);navToggle?.focus();});',$javascript);
		return str_replace('if(event.key==="Escape"&&navigation?.classList.contains("is-open"))setNavigation(false);','if(event.key==="Escape"&&navigation?.classList.contains("is-open")){setNavigation(false);navToggle?.focus();}',$javascript);
	}
}
