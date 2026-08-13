<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Route-neutral, fail-closed JSON/form endpoint for editor asset providers. */
final class PanelEditorAssetEndpoint implements \JsonSerializable {
	private ?\Closure $requestVerifier;
	public function __construct(private readonly PanelEditorAssetProvider $provider,?callable $requestVerifier=null) { $this->requestVerifier=$requestVerifier===null?null:\Closure::fromCallable($requestVerifier); }

	/** @param string|array<string,mixed> $payload @param array<string,mixed> $files @param array<string,mixed> $trustedRequest @return array{status:int,headers:array<string,string>,body:array<string,mixed>} */
	public function handle(string|array $payload,array $files,PanelEditorContext $context,array $trustedRequest=[]): array {
		$headers=['Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'same-origin'];
		$operation='';
		try{
			$payload=$this->payload($payload);
			$operation=PanelEditorManifest::name((string)($payload['operation']??''));
			if(!in_array($operation,['browse','asset','upload','delete','delivery'],true)){return $this->response(PanelEditorAssetResult::failure('request_invalid','The editor asset request is invalid.',400),$headers,$operation);}
			if(!$this->provider->ready()){return $this->response(PanelEditorAssetResult::failure('provider_unavailable','The editor asset provider is unavailable.',503),$headers,$operation);}
			if(!$this->verified($operation,$payload,$files,$context,$trustedRequest)){return $this->response(PanelEditorAssetResult::failure('request_denied','The editor asset request is not available.',403),$headers,$operation);}
			$result=match($operation){
				'browse'=>PanelEditorAssetResult::success('assets_ready','Editor assets are ready.',null,$this->provider->browse(is_array($payload['query']??null)?$payload['query']:[],$context)),
				'asset'=>$this->assetResult((string)($payload['id']??''),$context),
				'upload'=>$this->provider->storeAsset($this->upload($files),$context),
				'delete'=>$this->provider->deleteAsset((string)($payload['id']??''),$context),
				'delivery'=>$this->provider->delivery((string)($payload['id']??''),$context),
			};
			return $this->response($result,$headers,$operation);
		}
		catch(\InvalidArgumentException){return $this->response(PanelEditorAssetResult::failure('request_invalid','The editor asset request is invalid.',400),$headers,$operation);}
		catch(\Throwable){return $this->response(PanelEditorAssetResult::failure('internal_error','The editor asset request failed.',500),$headers,$operation);}
	}

	public function manifest(): array { return ['type'=>'panel_editor_asset_endpoint','schema_version'=>1,'provider'=>$this->provider->manifest(),'request_verifier'=>$this->requestVerifier!==null,'route_installed'=>false,'host_boundaries'=>['route','authentication','origin','csrf','rate_limit','trusted_context'],'public_errors'=>'stable_non_diagnostic']; }
	public function jsonSerialize(): array { return $this->manifest(); }

	private function payload(string|array $payload): array {
		if(is_string($payload)){
			if($payload===''||strlen($payload)>1048576){throw new \InvalidArgumentException('Invalid editor asset request payload.');}
			try{$payload=json_decode($payload,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \InvalidArgumentException('Invalid editor asset request payload.');}
		}
		if(!is_array($payload)||array_is_list($payload)){throw new \InvalidArgumentException('Invalid editor asset request payload.');}
		try{$encoded=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \InvalidArgumentException('Invalid editor asset request payload.');}
		if(strlen($encoded)>1048576){throw new \InvalidArgumentException('Invalid editor asset request payload.');}
		return $payload;
	}
	private function verified(string $operation,array $payload,array $files,PanelEditorContext $context,array $trustedRequest): bool { if($this->requestVerifier===null){return false;} try{return PanelUtilityResolver::evaluate($this->requestVerifier,['operation'=>$operation,'payload'=>$payload,'files'=>$files,'context'=>$context,'trusted_request'=>$trustedRequest,'provider'=>$this->provider],['operation','payload','files','context','trusted_request','provider'])===true;}catch(\Throwable){return false;} }
	private function upload(array $files): array { $upload=$files['file']??null; if(!is_array($upload)){foreach($files as $candidate){if(is_array($candidate)){$upload=$candidate;break;}}} if(!is_array($upload)){throw new \InvalidArgumentException('An editor asset upload is required.');} return $upload; }
	private function assetResult(string $id,PanelEditorContext $context): PanelEditorAssetResult { $asset=$this->provider->findAsset($id,$context); return $asset===null?PanelEditorAssetResult::failure('asset_not_found','The editor asset was not found.',404):PanelEditorAssetResult::success('asset_ready','Editor asset is ready.',$asset); }
	private function response(PanelEditorAssetResult $result,array $headers,string $operation): array { $status=$result->status(); PanelTrace::record('editor.asset',['provider'=>$this->provider->name(),'operation'=>$operation,'ok'=>$result->ok(),'code'=>$result->code(),'status'=>$status]); return ['status'=>$status,'headers'=>$headers,'body'=>$result->toArray()]; }
}
