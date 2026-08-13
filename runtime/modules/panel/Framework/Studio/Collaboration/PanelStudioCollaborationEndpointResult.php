<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Framework-neutral endpoint result with separately trusted host lease custody. */
final class PanelStudioCollaborationEndpointResult implements \JsonSerializable {
	/** @var array<string,string> */
	private readonly array $headers;

	/** @param array<string,string> $headers @param array<string,mixed> $body */
	public function __construct(
		private readonly int $status,
		array $headers,
		private readonly array $body,
		private readonly string $presenceDisposition='unchanged',
		private readonly ?string $presenceToken=null,
	){
		if($status<200||$status>599){throw new \InvalidArgumentException('Studio collaboration endpoint status is invalid.');}
		$clean=[];
		foreach($headers as $name=>$value){
			if(!is_string($name)||preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/D', $name)!==1||!is_string($value)||preg_match('/[\r\n\0]/', $value)===1){
				throw new \InvalidArgumentException('Studio collaboration endpoint headers are invalid.');
			}
			$clean[$name]=$value;
		}
		if(!in_array($presenceDisposition, ['unchanged','replace','clear'], true)){
			throw new \InvalidArgumentException('Studio collaboration presence disposition is invalid.');
		}
		if(($presenceDisposition==='replace')!==($presenceToken!==null)){
			throw new \InvalidArgumentException('Studio collaboration presence token disposition is inconsistent.');
		}
		$this->headers=$clean;
	}

	public function status():int{return $this->status;}
	/** @return array<string,string> */ public function headers():array{return $this->headers;}
	/** @return array<string,mixed> */ public function body():array{return $this->body;}
	public function content():string{return json_encode($this->body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
	public function presenceDisposition():string{return $this->presenceDisposition;}
	public function trustedPresenceToken():?string{return $this->presenceToken;}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return [
			'type'=>'panel_studio_collaboration_endpoint_result',
			'version'=>1,
			'status'=>$this->status,
			'headers'=>$this->headers,
			'content_bytes'=>strlen($this->content()),
			'presence_disposition'=>$this->presenceDisposition,
			'presence_token_serialized'=>false,
			'body_serialized'=>false,
		];
	}
}
