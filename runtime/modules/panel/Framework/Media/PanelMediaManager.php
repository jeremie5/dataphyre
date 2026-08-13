<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cohesive resumable upload, processing, catalog, delivery, and cleanup service. */
final class PanelMediaManager implements \JsonSerializable {
	public function __construct(
		private readonly PanelMediaDisk $disk,
		private readonly PanelMediaProcessingPipeline $pipeline,
		private readonly PanelSnapshotStore $catalog,
		private readonly ?PanelSignedMediaDelivery $delivery=null,
		private readonly ?PanelMediaCleanupPolicy $cleanup=null
	){}
	public static function local(string $root,string $signingSecret,array $options=[]): self {
		$disk=new PanelLocalMediaDisk($root,(string)($options['disk_name']??'local'));
		$catalog=new PanelAtomicSnapshotStore(rtrim($root,'/\\').'/.panel-media-catalog','panel.media.catalog',['items'=>[],'uploads'=>[]],(int)($options['retention']??512));
		return self::forDisk($disk,$catalog,$signingSecret,$options);
	}
	public static function forDisk(PanelMediaDisk $disk,PanelSnapshotStore $catalog,?string $signingSecret=null,array $options=[]):self {
		$pipeline=new PanelMediaProcessingPipeline($disk,($options['fail_closed']??true)!==false,isset($options['quarantine_prefix'])?(string)$options['quarantine_prefix']:'.panel-quarantine');
		$delivery=is_string($signingSecret)&&$signingSecret!==''?new PanelSignedMediaDelivery($disk,$signingSecret,(string)($options['delivery_url']??'/panel/media/private'),(int)($options['maximum_ttl']??604800)):null;
		$cleanup=($options['cleanup']??true)!==false?new PanelMediaCleanupPolicy((int)($options['cleanup_grace']??604800),['.panel_uploads','.panel-quarantine','.panel-media-catalog'],(int)($options['maximum_deletes']??1000)):null;
		return new self($disk,$pipeline,$catalog,$delivery,$cleanup);
	}
	public function disk():PanelMediaDisk{return $this->disk;}
	public function catalog():PanelSnapshotStore{return $this->catalog;}
	public function scanner(PanelMediaScanner|callable $scanner,?string $name=null): self { $this->pipeline->scanner($scanner,$name);return $this; }
	public function transformer(PanelMediaTransformer|callable $transformer,?string $name=null): self { $this->pipeline->transformer($transformer,$name);return $this; }
	public function startUpload(string $path,int $totalSize,array $options=[]): array {
		$session=PanelResumableUploadSession::start($this->disk,$path,$totalSize,(int)($options['chunk_size']??5242880),isset($options['checksum'])?(string)$options['checksum']:null,is_array($options['metadata']??null)?$options['metadata']:[],isset($options['id'])?(string)$options['id']:null,(int)($options['ttl']??86400));
		$this->catalog->transaction(function(array &$payload)use($session):void{$payload['uploads'][$session->id()]=$session->manifest();},'media.upload.started',['upload_id'=>$session->id(),'path'=>$path]);
		return $session->manifest();
	}
	public function receiveChunk(string $sessionId,int $index,string $contents,?string $checksum=null,?int $offset=null): array {
		$session=PanelResumableUploadSession::resume($this->disk,$sessionId);$result=$session->receiveChunk($index,$contents,$checksum,$offset);
		$this->catalog->transaction(function(array &$payload)use($session):void{$payload['uploads'][$session->id()]=$session->manifest();},'media.upload.chunk',['upload_id'=>$sessionId,'index'=>$index]);return ['chunk'=>$result,'session'=>$session->status()];
	}
	public function completeUpload(string $sessionId,array $variants=[],array $context=[],bool $overwrite=false): array {
		$session=PanelResumableUploadSession::resume($this->disk,$sessionId);$assembled=$session->assemble($overwrite);$processed=$this->pipeline->process($session->targetPath(),$variants,$context);$id='media_'.substr(hash('sha256',(string)($processed['source']['checksum']??$this->disk->checksum($session->targetPath())).'|'.$session->targetPath()),0,24);
		$item=['id'=>$id,'name'=>(string)($context['name']??basename($session->targetPath())),'path'=>$session->targetPath(),'status'=>($processed['ok']??false)?'ready':'rejected','source'=>$processed['source']??$this->disk->descriptor($session->targetPath()),'variants'=>$processed['variants']??[],'metadata'=>$processed['metadata']??[],'processing'=>$processed,'created_at'=>gmdate('c'),'updated_at'=>gmdate('c')];
		$this->catalog->transaction(function(array &$payload)use($session,$id,$item):void{unset($payload['uploads'][$session->id()]);$payload['items'][$id]=$item;},'media.upload.completed',['upload_id'=>$sessionId,'media_id'=>$id,'status'=>$item['status']]);
		return ['type'=>'panel_media_completion','ok'=>($processed['ok']??false)===true,'upload'=>$assembled,'item'=>$item,'processing'=>$processed];
	}
	public function cancelUpload(string $sessionId): bool { $session=PanelResumableUploadSession::resume($this->disk,$sessionId);$cancelled=$session->cancel();$this->catalog->transaction(function(array &$payload)use($session):void{unset($payload['uploads'][$session->id()]);},'media.upload.cancelled',['upload_id'=>$sessionId]);return $cancelled; }
	public function items(?string $status=null): array { $items=array_values(is_array($this->catalog->payload()['items']??null)?$this->catalog->payload()['items']:[]);return $status===null?$items:array_values(array_filter($items,static fn(array $item):bool=>($item['status']??null)===$status)); }
	public function item(string $id): ?array { $item=$this->catalog->payload()['items'][$id]??null;return is_array($item)?$item:null; }
	public function issue(string $id,int $ttl=900,string $disposition='inline',?string $audience=null): array { if($this->delivery===null){throw new \LogicException('Private media delivery is not configured.');}$item=$this->item($id);if($item===null){throw new \OutOfBoundsException('Media item was not found.');}return $this->delivery->issue((string)$item['path'],$ttl,$disposition,(string)$item['name'],$audience,['media_id'=>$id]); }
	public function delete(string $id,bool $deleteVariants=true): bool { $item=$this->item($id);if($item===null){return false;}$paths=[(string)$item['path']];if($deleteVariants){foreach($item['variants']??[]as$variant){if(is_array($variant)&&isset($variant['path'])){$paths[]=(string)$variant['path'];}}}foreach(array_unique($paths)as$path){$this->disk->delete($path);}$this->catalog->transaction(function(array &$payload)use($id):void{unset($payload['items'][$id]);},'media.deleted',['media_id'=>$id]);return true; }
	public function cleanup(bool $dryRun=true,?int $now=null): array { if($this->cleanup===null){throw new \LogicException('Media cleanup is not configured.');}$referenced=[];foreach($this->items()as$item){$referenced[]=(string)$item['path'];foreach($item['variants']??[]as$variant){if(is_array($variant)&&isset($variant['path'])){$referenced[]=(string)$variant['path'];}}}return $this->cleanup->execute($this->disk,$referenced,'',$dryRun,$now); }
	public function changes(int $cursor=0,int $limit=100): array { return $this->catalog->changesSince($cursor,$limit); }
	public function manifest(): array { return ['type'=>'panel_media_manager','disk'=>$this->disk->manifest(),'pipeline'=>$this->pipeline->manifest(),'catalog'=>$this->catalog->manifest(),'delivery'=>$this->delivery?->manifest(),'cleanup'=>$this->cleanup?->manifest(),'items'=>$this->items(),'uploads'=>array_values($this->catalog->payload()['uploads']??[]),'capabilities'=>['resumable_uploads'=>true,'checksums'=>true,'scanning'=>true,'variants'=>true,'quarantine'=>true,'private_delivery'=>$this->delivery!==null,'cleanup'=>$this->cleanup!==null,'change_feed'=>true]]; }
	public function jsonSerialize(): array { return $this->manifest(); }
}
