<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable tenant-scoped Studio document identity and safe metadata. */
final class PanelStudioDocument implements \JsonSerializable {
	private readonly array $meta;
	public function __construct(private readonly string $tenantId,private readonly string $id,private readonly string $title,array $meta=[]){
		self::scope($tenantId,'tenant');self::scope($id,'document');
		if(trim($title)===''||strlen($title)>160||preg_match('//u',$title)!==1||preg_match('/<\/?[a-z!][^>]*>/i',$title)===1||PanelSensitiveDataSanitizer::sanitize($title,['max_string_bytes'=>160])!==$title){throw new \InvalidArgumentException('Studio document titles must be safe non-empty UTF-8 strings of at most 160 bytes.');}
		$clean=PanelSensitiveDataSanitizer::sanitize($meta,['max_depth'=>5,'max_items'=>50,'max_string_bytes'=>512]);
		if(!is_array($clean)){throw new \InvalidArgumentException('Studio document metadata must be JSON-compatible.');}
		$this->meta=$clean;
	}
	public static function make(string $tenantId,string $id,string $title,array $meta=[]):self{return new self($tenantId,$id,$title,$meta);}
	public function tenantId():string{return$this->tenantId;}
	public function id():string{return$this->id;}
	public function title():string{return$this->title;}
	public function meta():array{return$this->meta;}
	public function jsonSerialize():array{return['type'=>'panel_studio_document','version'=>1,'tenant_id'=>$this->tenantId,'id'=>$this->id,'title'=>$this->title,'meta'=>$this->meta];}
	public static function scope(string $value,string $label='scope'):string{if(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/',$value)!==1||str_contains($value,'..')){throw new \InvalidArgumentException("Studio {$label} identifiers must be safe scoped identifiers.");}return$value;}
}
