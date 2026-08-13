<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Append-only hash-chained security decision and impersonation audit trail. */
final class PanelSecurityAuditTrail implements \JsonSerializable {
	private array $events=[];
	public function __construct(private readonly ?string $file=null,private readonly ?string $integrityKey=null) {
		if($integrityKey!==null&&strlen($integrityKey)<32){throw new \InvalidArgumentException('Security audit integrity keys must contain at least 32 bytes.');}
		if($file!==null && is_file($file)){ $this->events=$this->read(); }
	}
	public function record(string $type, PanelSecurityContext|array $context, PanelSecurityDecision|PanelImpersonationSession|array $subject, array $metadata=[]): array {
		$sanitize=static fn(mixed$value):mixed=>PanelSensitiveDataSanitizer::sanitize($value,['max_depth'=>16,'max_items'=>250,'max_string_bytes'=>4096]);
		$payload=['type'=>trim($type) ?: 'security.event', 'timestamp'=>gmdate('c'), 'context'=>$sanitize($context), 'subject'=>$sanitize($subject), 'metadata'=>$sanitize($metadata)];
		if($this->file===null){ return $this->append($this->events, $payload); }
		$this->directory();$handle=fopen($this->file.'.lock','c+b');if($handle===false||!flock($handle,LOCK_EX)){throw new \RuntimeException('Unable to lock security audit trail.');}@chmod($this->file.'.lock',0600);
		try{$events=is_file($this->file)?$this->read():[];if(!$this->verifyEvents($events)){throw new \RuntimeException('Security audit trail integrity check failed.');}$event=$this->append($events,$payload);$this->publish($events);$this->events=$events;return$event;}finally{flock($handle,LOCK_UN);fclose($handle);}
	}
	public function events(?string $type=null): array { $this->refresh();return $type===null ? $this->events : array_values(array_filter($this->events, static fn(array $event): bool => $event['type']===$type)); }
	public function verify(): bool { $this->refresh();return $this->verifyEvents($this->events); }
	public function integrityMode():string{return$this->integrityKey!==null?'hmac-sha256-v1':'checksum-sha256-v1';}
	public function tamperEvident():bool{return$this->integrityKey!==null;}
	public function jsonSerialize(): array { return ['type'=>'security_audit_trail', 'verified'=>$this->verify(), 'integrity_mode'=>$this->integrityMode(), 'tamper_evident'=>$this->tamperEvident(), 'count'=>count($this->events), 'events'=>$this->events]; }
	private function append(array &$events,array $payload):array{$previous=$events!==[]?(string)($events[array_key_last($events)]['hash']??''):str_repeat('0',64);$mode=$this->integrityMode();$event=['sequence'=>count($events)+1]+$payload+['previous_hash'=>$previous,'integrity'=>$mode];$event['hash']=$this->digest($event,$mode);$events[]=$event;return$event;}
	private function directory():void{$directory=dirname((string)$this->file);if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){throw new \RuntimeException('Unable to create security audit directory.');}@chmod($directory,0700);}
	private function publish(array $events):void{$temporary=$this->file.'.'.bin2hex(random_bytes(8)).'.tmp';$payload=json_encode($events,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$written=file_put_contents($temporary,$payload,LOCK_EX);if($written===false){@unlink($temporary);throw new \RuntimeException('Unable to publish security audit trail.');}@chmod($temporary,0600);if(!rename($temporary,(string)$this->file)){@unlink($temporary);throw new \RuntimeException('Unable to publish security audit trail.');}@chmod((string)$this->file,0600);}
	private function read():array{$contents=file_get_contents((string)$this->file);if($contents===false){throw new \RuntimeException('Unable to read security audit trail.');}try{$decoded=json_decode($contents,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $exception){throw new \RuntimeException('Security audit trail is not valid JSON.',0,$exception);}if(!is_array($decoded)||!array_is_list($decoded)||array_filter($decoded,static fn(mixed $event):bool=>!is_array($event))!==[]){throw new \RuntimeException('Security audit trail payload must be an event list.');}return$decoded;}
	private function refresh():void{if($this->file===null||!is_file($this->file)){return;}$this->directory();$handle=fopen($this->file.'.lock','c+b');if($handle===false||!flock($handle,LOCK_SH)){throw new \RuntimeException('Unable to lock security audit trail.');}@chmod($this->file.'.lock',0600);try{$this->events=$this->read();}finally{flock($handle,LOCK_UN);fclose($handle);}}
	private function verifyEvents(array $events):bool{$previous=str_repeat('0',64);foreach($events as$event){$hash=$event['hash']??'';unset($event['hash']);$mode=(string)($event['integrity']??'sha256-v1');try{$actual=$this->digest($event,$mode);}catch(\Throwable){return false;}if($actual===''||!is_string($hash)||strlen($hash)!==64||($event['previous_hash']??'')!==$previous||!hash_equals($hash,$actual)){return false;}$previous=$hash;}return true;}
	private function digest(array $event,string $mode):string{$json=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if($this->integrityKey!==null){return$mode==='hmac-sha256-v1'?hash_hmac('sha256',$json,$this->integrityKey):'';}return in_array($mode,['sha256-v1','checksum-sha256-v1'],true)?hash('sha256',$json):'';}
}
