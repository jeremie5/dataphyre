<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

use Dataphyre\Datadoc\DocumentationPortal;

/**
 * Adapts verified Panel documentation releases to Datadoc's universal static
 * portal engine while preserving Panel's immutable publication API.
 */
final class PanelDocumentationPortal implements \JsonSerializable {
	private function __construct(){}

	public static function make():self { return new self(); }

	/** @param array<string,mixed> $options */
	public function decorate(PanelDocumentationPublication $publication,array $options=[]):PanelDocumentationPublication {
		$engine=self::engine();
		$release=$publication->manifest();
		if(isset($release['portal'])){ throw new \InvalidArgumentException('Documentation publication already contains a static portal.'); }
		$title=$release['title']??null;
		if(!is_string($title)){ throw new \LogicException('Verified Panel documentation publication title became invalid.'); }

		$documents=[];
		$contentAssets=[];
		$reserved=[];
		$artifacts=[];
		$producerArtifacts=[];
		foreach($publication->artifacts() as $artifact){
			$relative=$artifact->metadata()['relative_path']??null;
			if(!is_string($relative)){ throw new \LogicException('Verified Panel documentation artifact metadata became invalid.'); }
			if($relative==='publication.json'){ continue; }
			$artifacts[]=$artifact;
			$producerArtifacts[strtolower($relative)]=['path'=>$relative,'contents'=>$artifact->contents()];
			if(str_ends_with(strtolower($relative),'.md')){ $documents[$relative]=$artifact->contents(); }
			elseif(preg_match('/\.(?:png|jpe?g|gif|webp|avif|ico)$/i',$relative)===1){
				DocumentationPortal::contentAssetMime($relative,$artifact->contents());
				$contentAssets[$relative]=$artifact->contents();
			}
			else{ $reserved[]=$relative; }
		}

		$build=$engine->build($publication->version(),$title,$documents,$reserved,$options,$contentAssets);
		$fileManifest=[];
		foreach((array)($release['files']??[]) as $file){ if(is_array($file)){ $fileManifest[]=$file; } }
		foreach($build->files() as $relative=>$contents){
			if(isset($producerArtifacts[strtolower($relative)])){ continue; }
			$digest=hash('sha256',$contents);
			$fileManifest[]=['path'=>$relative,'bytes'=>strlen($contents),'sha256'=>$digest];
			$artifacts[]=PanelScaffoldResult::make('documentation_portal',pathinfo($relative,PATHINFO_FILENAME),'',$publication->releasePrefix().'/'.$relative,$contents,[
				'version'=>$publication->version(),
				'relative_path'=>$relative,
				'sha256'=>$digest,
				'portal'=>true,
				'portal_engine'=>'datadoc',
			]);
		}
		usort($fileManifest,static fn(array $left,array $right):int=>(string)($left['path']??'')<=>(string)($right['path']??''));
		$release['files']=$fileManifest;
		$release['portal']=$build->manifest();
		$release=self::canonical($release);
		$publicationContents=self::json($release);
		$artifacts[]=PanelScaffoldResult::make('documentation','publication_manifest','',$publication->releasePrefix().'/publication.json',$publicationContents,[
			'version'=>$publication->version(),
			'relative_path'=>'publication.json',
			'sha256'=>hash('sha256',$publicationContents),
		]);
		usort($artifacts,static fn(PanelScaffoldResult $left,PanelScaffoldResult $right):int=>$left->path()<=>$right->path());
		return PanelDocumentationPublication::make($publication->version(),$artifacts,$release);
	}

	/** @return array<string,mixed> */
	public function manifest():array {
		return [
			'type'=>'panel_documentation_portal_adapter',
			'version'=>1,
			'owner_module'=>'panel',
			'engine_module'=>'datadoc',
			'input'=>'verified_panel_documentation_publication',
			'output'=>'immutable_panel_documentation_release',
			'default_enabled'=>false,
			'producer_content_assets'=>true,
			'engine'=>self::engine()->manifest(),
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array { return $this->manifest(); }

	private static function engine():DocumentationPortal {
		if(!class_exists(DocumentationPortal::class,false)&&class_exists('dataphyre\\autoloader',false)){ \dataphyre\autoloader::register_framework_modules(['datadoc']); }
		if(!class_exists(DocumentationPortal::class)){ throw new \LogicException('Panel static documentation portals require the Datadoc Framework module.'); }
		return DocumentationPortal::make();
	}

	private static function json(array $value):string {
		try { return json_encode(self::canonical($value),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n"; }
		catch(\JsonException $error){ throw new \RuntimeException('Panel documentation publication data could not be encoded.',0,$error); }
	}

	/** @param array<mixed> $value @return array<mixed> */
	private static function canonical(array $value):array {
		foreach($value as $key=>$item){ if(is_array($item)){ $value[$key]=self::canonical($item); } }
		if(!array_is_list($value)){ ksort($value,SORT_STRING); }
		return $value;
	}
}
