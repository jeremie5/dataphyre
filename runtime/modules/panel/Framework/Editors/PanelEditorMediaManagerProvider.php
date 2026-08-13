<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** First-party editor provider pack backed by PanelMediaManager. */
final class PanelEditorMediaManagerProvider {
	/**
	 * @param array{scope?:callable,authorize?:callable,accepted?:array|string,max_bytes?:int,prefix?:string,chunk_size?:int,ttl?:int,delivery_ttl?:int,variants?:array,id_generator?:callable,csrf_required?:bool,csrf_field?:string,csrf_header?:string,deletes?:bool} $options
	 */
	public static function make(PanelMediaManager $manager,string $endpoint,string $scopeSecret,array $options=[]): PanelEditorCallbackAssetProvider {
		if(strlen($scopeSecret)<32){ throw new \InvalidArgumentException('The editor media provider scope key must contain at least 32 bytes.'); }
		$endpoint=PanelEditorAsset::normalizeEndpoint($endpoint);
		$prefix=self::prefix((string)($options['prefix']??'editor-assets'));
		$chunkSize=max(1024,min(5242880,(int)($options['chunk_size']??1048576)));
		$ttl=max(60,min(604800,(int)($options['ttl']??86400)));
		$deliveryTtl=max(60,min(3600,(int)($options['delivery_ttl']??900)));
		$variants=is_array($options['variants']??null)?$options['variants']:[];
		$scopeResolver=is_callable($options['scope']??null)?\Closure::fromCallable($options['scope']):null;
		$authorizer=is_callable($options['authorize']??null)?\Closure::fromCallable($options['authorize']):null;
		$idGenerator=is_callable($options['id_generator']??null)?\Closure::fromCallable($options['id_generator']):null;

		$provider=PanelEditorCallbackAssetProvider::make('panel_media',$endpoint)
			->providerType('panel_media_manager')->browserDriver('http')
			->accept($options['accepted']??['image/*'])
			->maxBytes(max(1,(int)($options['max_bytes']??10485760)))
			->uploads(true)->deletes(($options['deletes']??false)===true)
			->csrf(($options['csrf_required']??true)!==false,(string)($options['csrf_field']??'csrf'),(string)($options['csrf_header']??'X-CSRF-Token'))
			->browseUsing(static function(array $query,PanelEditorContext $context)use($manager,$endpoint,$scopeResolver,$scopeSecret):PanelEditorAssetPage{
				$scope=self::scope($scopeResolver,$context,$scopeSecret); if($scope===''){return new PanelEditorAssetPage();}
				return self::browse($manager,$endpoint,$scope,$query,$scopeSecret);
			})
			->findUsing(static function(string $id,PanelEditorContext $context)use($manager,$endpoint,$scopeResolver,$scopeSecret):?PanelEditorAsset{
				$scope=self::scope($scopeResolver,$context,$scopeSecret); return $scope===''?null:self::item($manager,$endpoint,$scope,$id);
			})
			->storeUsing(static function(array $upload,PanelEditorContext $context)use($manager,$endpoint,$scopeResolver,$scopeSecret,$prefix,$chunkSize,$ttl,$variants,$idGenerator):PanelEditorAssetResult{
				$scope=self::scope($scopeResolver,$context,$scopeSecret); return $scope===''?PanelEditorAssetResult::failure('operation_denied','The editor asset operation is not available.',403):self::store($manager,$endpoint,$scope,$upload,$context,$prefix,$chunkSize,$ttl,$variants,$idGenerator);
			})
			->deleteUsing(static function(string $id,PanelEditorContext $context)use($manager,$scopeResolver,$scopeSecret):bool{
				$scope=self::scope($scopeResolver,$context,$scopeSecret); return $scope!==''&&self::owned($manager->item($id),$scope)&&$manager->delete($id);
			})
			->deliverUsing(static function(string $id,PanelEditorAsset $asset,PanelEditorContext $context)use($manager,$scopeResolver,$scopeSecret,$deliveryTtl):PanelEditorAssetResult{
				$scope=self::scope($scopeResolver,$context,$scopeSecret); if($scope===''||!self::owned($manager->item($id),$scope)){return PanelEditorAssetResult::failure('asset_not_found','The editor asset was not found.',404);}
				$delivery=$manager->issue($id,$deliveryTtl,'inline',$scope);
				return PanelEditorAssetResult::success('delivery_ready','Editor asset delivery is ready.',$asset,null,['url'=>(string)($delivery['url']??''),'expires_at'=>$delivery['expires_at']??null]);
			})
			->normalizeUsing(static function(string $url,PanelEditorContext $context)use($manager,$endpoint,$scopeResolver,$scopeSecret):?string{
				$id=self::idFromUrl($endpoint,$url); if($id===null){return null;} $scope=self::scope($scopeResolver,$context,$scopeSecret); return $scope!==''&&self::owned($manager->item($id),$scope)?self::url($endpoint,$id):null;
			});
		if($scopeResolver!==null&&$authorizer!==null){
			$provider=$provider->authorizeUsing(static function(string $operation,PanelEditorContext $context)use($scopeResolver,$authorizer,$scopeSecret):bool{
				$scope=self::scope($scopeResolver,$context,$scopeSecret,true); if($scope===''){return false;}
				try{return PanelUtilityResolver::evaluate($authorizer,['operation'=>$operation,'context'=>$context,'scope'=>$scope],['operation','context','scope'])===true;}catch(\Throwable){return false;}
			});
		}
		return $provider;
	}

	private static function browse(PanelMediaManager $manager,string $endpoint,string $scope,array $query,string $secret): PanelEditorAssetPage {
		$search=self::queryText($query['search']??'',128); $mime=self::queryText($query['mime']??'',96); $kind=PanelEditorManifest::name((string)($query['kind']??'')); $limit=max(1,min(50,(int)($query['limit']??24)));
		$queryDigest=hash('sha256',json_encode([$search,$mime,$kind],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
		$offset=self::cursorOffset(isset($query['cursor'])?(string)$query['cursor']:'',$scope,$queryDigest,$secret);
		$items=[];
		foreach($manager->items('ready') as $item){
			if(!self::owned($item,$scope)){continue;}
			$asset=self::asset($endpoint,$item); if($asset===null){continue;}
			if($search!==''&&!str_contains(strtolower($asset->name()),strtolower($search))){continue;}
			if($mime!==''&&!self::mimeMatches($asset->mime(),$mime)){continue;}
			if($kind!==''&&$asset->kind()!==$kind){continue;}
			$items[]=$asset;
		}
		usort($items,static fn(PanelEditorAsset $left,PanelEditorAsset $right):int=>strcmp($right->id(),$left->id()));
		$total=count($items); $page=array_slice($items,$offset,$limit); $next=$offset+$limit<$total?self::cursor($offset+$limit,$scope,$queryDigest,$secret):null;
		return new PanelEditorAssetPage($page,$next,$next!==null,$total,['provider'=>'panel_media_manager']);
	}
	private static function store(PanelMediaManager $manager,string $endpoint,string $scope,array $upload,PanelEditorContext $context,string $prefix,int $chunkSize,int $ttl,array $variants,?\Closure $idGenerator): PanelEditorAssetResult {
		$tmp=is_string($upload['tmp_name']??null)?trim((string)$upload['tmp_name']):'';
		if($tmp===''||!is_file($tmp)||!is_readable($tmp)){return PanelEditorAssetResult::failure('upload_invalid','The editor upload temporary file is unavailable.',422);}
		$size=filesize($tmp); if(!is_int($size)||$size<1){return PanelEditorAssetResult::failure('upload_invalid','The editor upload must contain at least one byte.',422);}
		$name=basename(str_replace('\\','/',(string)($upload['name']??'asset'))); $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION)); $extension=preg_match('/^[a-z0-9]{1,16}$/D',$extension)===1?$extension:'bin';
		$token=self::uploadToken($idGenerator,$upload,$context); $slug=self::slug(pathinfo($name,PATHINFO_FILENAME)); $path=$prefix.'/'.$scope.'/'.($context->field()!==''?$context->field():'editor').'/'.$token.'-'.$slug.'.'.$extension;
		$checksum=hash_file('sha256',$tmp); if(!is_string($checksum)){return PanelEditorAssetResult::failure('upload_failed','The editor asset upload failed.',422);}
		$session=null;
		try{
			$session=$manager->startUpload($path,$size,['chunk_size'=>$chunkSize,'checksum'=>$checksum,'metadata'=>['editor_scope'=>$scope],'ttl'=>$ttl]);
			$handle=fopen($tmp,'rb'); if(!is_resource($handle)){throw new \RuntimeException('Unable to open the editor upload.');}
			try{
				$index=0;$offset=0;
				while($offset<$size){$length=min($chunkSize,$size-$offset);$contents='';while(strlen($contents)<$length&&!feof($handle)){$part=fread($handle,$length-strlen($contents));if($part===false){throw new \RuntimeException('Unable to read the editor upload.');}$contents.=$part;}if(strlen($contents)!==$length){throw new \RuntimeException('The editor upload ended early.');}$manager->receiveChunk((string)$session['id'],$index,$contents,hash('sha256',$contents),$offset);$offset+=$length;$index++;}
			}
			finally{fclose($handle);}
			$completion=$manager->completeUpload((string)$session['id'],$variants,['name'=>$name,'editor'=>['scope_tag'=>$scope,'field'=>$context->field(),'mode'=>$context->mode()]],false);
			$item=is_array($completion['item']??null)?$completion['item']:[]; $asset=self::asset($endpoint,$item);
			if(($completion['ok']??false)!==true||$asset===null){return PanelEditorAssetResult::failure('processing_rejected','The editor asset did not pass media processing.',422,['media_id'=>$item['id']??null]);}
			return PanelEditorAssetResult::success('uploaded','Editor asset uploaded.',$asset);
		}
		catch(\Throwable){
			if(is_array($session)&&isset($session['id'])){try{$manager->cancelUpload((string)$session['id']);}catch(\Throwable){}}
			return PanelEditorAssetResult::failure('upload_failed','The editor asset upload failed.',422);
		}
	}
	private static function item(PanelMediaManager $manager,string $endpoint,string $scope,string $id): ?PanelEditorAsset { $item=$manager->item($id); return self::owned($item,$scope)?self::asset($endpoint,$item):null; }
	private static function asset(string $endpoint,array $item): ?PanelEditorAsset {
		try{
			$metadata=is_array($item['metadata']??null)?$item['metadata']:[]; $source=is_array($item['source']??null)?$item['source']:[];
			return new PanelEditorAsset((string)($item['id']??''),(string)($item['name']??basename((string)($item['path']??'asset'))),self::url($endpoint,(string)($item['id']??'')),(string)($metadata['mime']??$source['mime']??'application/octet-stream'),(int)($metadata['size']??$source['size']??0),'',isset($metadata['width'])?(int)$metadata['width']:null,isset($metadata['height'])?(int)$metadata['height']:null,(string)($metadata['context']['editor']['alt']??''),(string)($item['status']??'ready'),['created_at'=>$item['created_at']??null,'updated_at'=>$item['updated_at']??null,'variant_names'=>array_keys(is_array($item['variants']??null)?$item['variants']:[])]);
		}catch(\Throwable){return null;}
	}
	private static function owned(?array $item,string $scope): bool { return is_array($item)&&is_array($item['metadata']['context']['editor']??null)&&isset($item['metadata']['context']['editor']['scope_tag'])&&hash_equals($scope,(string)$item['metadata']['context']['editor']['scope_tag']); }
	private static function scope(?\Closure $resolver,PanelEditorContext $context,string $secret,bool $raw=false): string {
		if($resolver===null){return '';}
		try{$value=PanelUtilityResolver::evaluate($resolver,['context'=>$context,'record'=>$context->record(),'request'=>$context->request(),'values'=>$context->values()],['context','record','request','values']);}catch(\Throwable){return '';}
		if(!is_scalar($value)){return '';}$value=trim((string)$value);if($value===''||strlen($value)>512||preg_match('/[\x00-\x1f\x7f]/',$value)===1){return '';}
		return $raw?$value:self::encode(hash_hmac('sha256',"panel-editor-assets-scope-v1\0".$value,$secret,true));
	}
	private static function url(string $endpoint,string $id): string { return $endpoint.'/'.rawurlencode(PanelEditorAsset::normalizeId($id)); }
	private static function idFromUrl(string $endpoint,string $url): ?string { if(!str_starts_with($url,$endpoint.'/')){return null;} $candidate=substr($url,strlen($endpoint)+1); if($candidate===''||str_contains($candidate,'/')||str_contains($candidate,'?')||str_contains($candidate,'#')){return null;} $id=rawurldecode($candidate); try{return rawurlencode($id)===$candidate||$id===$candidate?PanelEditorAsset::normalizeId($id):null;}catch(\Throwable){return null;} }
	private static function cursor(int $offset,string $scope,string $query,string $secret): string { $payload=self::encode(json_encode(['v'=>1,'o'=>$offset,'s'=>$scope,'q'=>$query],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); return $payload.'.'.self::encode(hash_hmac('sha256',$payload,$secret,true)); }
	private static function cursorOffset(string $cursor,string $scope,string $query,string $secret): int { if($cursor===''){return 0;} $parts=explode('.',$cursor); if(count($parts)!==2){return PHP_INT_MAX;} [$payload,$signature]=$parts; $provided=self::decode($signature); if($provided===null||!hash_equals(hash_hmac('sha256',$payload,$secret,true),$provided)){return PHP_INT_MAX;} $json=self::decode($payload); if($json===null){return PHP_INT_MAX;} try{$claims=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){return PHP_INT_MAX;} return is_array($claims)&&($claims['v']??null)===1&&is_int($claims['o']??null)&&$claims['o']>=0&&isset($claims['s'],$claims['q'])&&hash_equals($scope,(string)$claims['s'])&&hash_equals($query,(string)$claims['q'])?$claims['o']:PHP_INT_MAX; }
	private static function uploadToken(?\Closure $generator,array $upload,PanelEditorContext $context): string { if($generator!==null){try{$value=PanelUtilityResolver::evaluate($generator,['upload'=>$upload,'context'=>$context],['upload','context']);if(is_scalar($value)){$value=trim((string)$value);if(preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,63}$/D',$value)===1){return $value;}}}catch(\Throwable){}} return bin2hex(random_bytes(12)); }
	private static function prefix(string $prefix): string { $prefix=trim(str_replace('\\','/',$prefix),'/ '); if($prefix===''||strlen($prefix)>160||preg_match('~(?:^|/)\.\.?(?:/|$)~',$prefix)===1||preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D',$prefix)!==1||str_starts_with($prefix,'.panel')){throw new \InvalidArgumentException('The editor media storage prefix is unsafe.');} return $prefix; }
	private static function slug(string $value): string { $value=strtolower(trim($value));$value=trim(preg_replace('/[^a-z0-9]+/','-',$value)??'','-');return substr($value!==''?$value:'asset',0,64); }
	private static function queryText(mixed $value,int $limit): string { if(!is_scalar($value)){return '';} $value=trim((string)$value); if(strlen($value)>$limit){$value=substr($value,0,$limit);} return preg_match('/[\x00-\x1f\x7f]/',$value)===1?'':$value; }
	private static function mimeMatches(string $mime,string $filter): bool { $filter=strtolower($filter); return str_ends_with($filter,'/*')?str_starts_with($mime,substr($filter,0,-1)):$mime===$filter; }
	private static function encode(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
	private static function decode(string $value): ?string { if($value===''||preg_match('/^[A-Za-z0-9_-]+$/D',$value)!==1){return null;} $padding=(4-strlen($value)%4)%4;$decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$padding),true);return is_string($decoded)?$decoded:null; }
}
