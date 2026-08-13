<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Scope-aware view of durable revocation events with fail-closed completeness. */
final class PanelPackageRevocationRegistry implements \JsonSerializable {
	public function __construct(private readonly PanelPackageMarketplaceTrustNetwork $network){}
	public function network():PanelPackageMarketplaceTrustNetwork{return$this->network;}
	/** @param array<string,mixed> $subject */
	public function decision(string $kind,array $subject,string|int|\DateTimeInterface|null $at=null):PanelPackageRevocationDecision{
		$kind=PanelOperationsGuard::name($kind,'revocation decision kind');if(!in_array($kind,['registry','publisher','key','package','version','artifact'],true)){throw new \InvalidArgumentException('Revocation decision kind is unsupported.');}
		$subject=$this->normalizeSubject($subject);$health=$this->network->health($at);$evaluatedAt=(string)$health['as_of'];$now=(new \DateTimeImmutable($evaluatedAt))->getTimestamp();$matches=[];
		foreach($this->network->revocationRecords()as$record){$revocation=$record['subject'];$effective=(new \DateTimeImmutable($revocation['effective_at']))->getTimestamp();$expires=isset($revocation['expires_at'])?(new \DateTimeImmutable($revocation['expires_at']))->getTimestamp():null;if($effective>$now||($expires!==null&&$expires<=$now)||!$this->matches($revocation,$subject)){continue;}$matches[]=['revocation_id'=>$revocation['revocation_id'],'scope'=>$revocation['scope'],'reason'=>$revocation['reason'],'effective_at'=>$revocation['effective_at'],'expires_at'=>$revocation['expires_at']??null,'log_id'=>$record['log_id'],'event_id'=>$record['event_id'],'event_digest'=>$record['event_digest']];}
		usort($matches,static fn(array $left,array $right):int=>strcmp($left['revocation_id'],$right['revocation_id']));
		return new PanelPackageRevocationDecision($kind,PanelOperationsGuard::digest($subject),(bool)$health['complete'],(bool)$health['stale'],$matches,$evaluatedAt);
	}
	/** @param array<string,mixed> $subject @param array<string,mixed> $context @return array<string,mixed> */public function __invoke(string $kind,array $subject,array $context=[]):array{return$this->decision($kind,$subject,$context['at']??null)->jsonSerialize();}
	/** @return array<string,mixed> */public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_package_revocation_registry_manifest','version'=>1,'network'=>$this->network->jsonSerialize(),'capabilities'=>['registry_scope'=>true,'publisher_scope'=>true,'key_scope'=>true,'package_scope'=>true,'version_scope'=>true,'artifact_scope'=>true,'temporary_revocations'=>true,'fail_closed_completeness'=>true]]);}
	/** @param array<string,mixed> $subject @return array<string,mixed> */private function normalizeSubject(array $subject):array{$allowed=['registry','publisher','key_id','package','version','artifact_sha256'];if($subject===[]||array_diff(array_keys($subject),$allowed)!==[]){throw new \InvalidArgumentException('Revocation lookup subject is empty or malformed.');}$result=[];foreach(['registry','publisher','package']as$field){if(isset($subject[$field])){$value=PanelOperationsGuard::name((string)$subject[$field],$field);if($value!==$subject[$field])throw new \InvalidArgumentException('Revocation lookup identifiers must be canonical.');$result[$field]=$value;}}if(isset($subject['key_id'])){$value=PanelOperationsGuard::identifier((string)$subject['key_id'],'key id',256);if($value!==$subject['key_id'])throw new \InvalidArgumentException('Revocation lookup key id must be canonical.');$result['key_id']=$value;}if(isset($subject['version'])){if(!is_string($subject['version'])||!PanelPackageManifest::validVersion($subject['version']))throw new \InvalidArgumentException('Revocation lookup version is invalid.');$result['version']=$subject['version'];}if(isset($subject['artifact_sha256'])){if(!is_string($subject['artifact_sha256'])||preg_match('/^[a-f0-9]{64}$/D',$subject['artifact_sha256'])!==1)throw new \InvalidArgumentException('Revocation lookup artifact digest is invalid.');$result['artifact_sha256']=$subject['artifact_sha256'];}return$result;}
	/** @param array<string,mixed> $revocation @param array<string,mixed> $subject */private function matches(array $revocation,array $subject):bool{$scope=(string)$revocation['scope'];$field=match($scope){'registry'=>'registry','publisher'=>'publisher','key'=>'key_id','package','version'=>'package','artifact'=>'artifact_sha256'};if(!isset($subject[$field])||!hash_equals((string)$revocation[$field],(string)$subject[$field]))return false;if($scope==='version'&&(!isset($subject['version'])||!hash_equals((string)$revocation['version'],(string)$subject['version'])))return false;foreach(['registry','publisher']as$context){if(isset($revocation[$context])&&(!isset($subject[$context])||!hash_equals((string)$revocation[$context],(string)$subject[$context])))return false;}return true;}
}
