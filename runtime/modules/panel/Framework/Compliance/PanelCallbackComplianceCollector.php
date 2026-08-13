<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Explicit callback adapter for host evidence collectors. */
final class PanelCallbackComplianceCollector implements PanelComplianceCollector, \JsonSerializable {
	private readonly \Closure $callback;
	private readonly string $id;
	private readonly string $version;
	/** @var array<string,mixed> */ private readonly array $capabilities;
	/** @var array<string,mixed> */ private readonly array $metadata;
	private readonly string $fingerprint;

	/** @param array<string,mixed> $capabilities @param array<string,mixed> $metadata */
	public function __construct(string $id,string $version,callable $callback,array $capabilities=[],array $metadata=[]){
		$this->id=PanelOperationsGuard::name($id,'compliance collector id');
		$this->version=self::version($version);
		$this->callback=\Closure::fromCallable($callback);
		$this->capabilities=PanelOperationsGuard::safeMetadata($capabilities,128);
		$this->metadata=PanelOperationsGuard::safeMetadata($metadata,128);
		$this->fingerprint=PanelOperationsGuard::digest([
			'type'=>'panel_compliance_collector','version'=>1,'id'=>$this->id,
			'implementation_version'=>$this->version,'capabilities'=>$this->capabilities,'metadata'=>$this->metadata,
		]);
	}

	public function id():string{return$this->id;}
	public function fingerprint():string{return$this->fingerprint;}
	/** @return array<string,mixed> */ public function capabilities():array{return$this->capabilities;}
	public function collect(PanelComplianceCollectionContext $context):PanelComplianceObservation {
		$result=($this->callback)($context,$this);
		if(!$result instanceof PanelComplianceObservation){throw new \UnexpectedValueException('Compliance collectors must return PanelComplianceObservation values.');}
		return$result;
	}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp([
		'type'=>'panel_compliance_collector_manifest','version'=>1,'id'=>$this->id,
		'implementation_version'=>$this->version,'fingerprint'=>$this->fingerprint,
		'capabilities'=>$this->capabilities,'metadata'=>$this->metadata,'credentials_serialized'=>false,
	]);}

	private static function version(string $version):string {
		$version=trim($version);
		if($version===''||strlen($version)>64||preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D',$version)!==1){throw new \InvalidArgumentException('Compliance collector version is invalid.');}
		return$version;
	}
}
