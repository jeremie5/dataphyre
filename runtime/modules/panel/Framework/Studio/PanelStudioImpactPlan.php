<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Deterministic structural promotion impact derived from existing manifest diffs. */
final class PanelStudioImpactPlan implements \JsonSerializable {
	private readonly PanelManifestDiff $diff;
	private readonly array $impacts;
	public function __construct(array|\JsonSerializable $before,array|\JsonSerializable $after){$this->diff=PanelManifestDiff::between(self::safe($before),self::safe($after));$impacts=[];foreach($this->diff->changes()as$change){$path=(string)$change['path'];$breaking=$change['type']==='removed'||($change['type']==='changed'&&preg_match('/(?:^|\.)(?:kind|key|type|required)(?:\.|$)/',$path)===1);$impacts[]=['path'=>$path,'change'=>$change['type'],'classification'=>$breaking?'breaking':($change['type']==='added'?'additive':'review'),'requires_approval'=>$breaking];}usort($impacts,static fn(array $a,array $b):int=>[$a['path'],$a['change']]<=>[$b['path'],$b['change']]);$this->impacts=$impacts;}
	public function changed():bool{return$this->diff->changed();}
	public function diff():PanelManifestDiff{return$this->diff;}
	public function impacts():array{return$this->impacts;}
	public function breaking():bool{return array_filter($this->impacts,static fn(array $impact):bool=>$impact['classification']==='breaking')!==[];}
	public function jsonSerialize():array{return['type'=>'panel_studio_impact_plan','version'=>1,'changed'=>$this->changed(),'breaking'=>$this->breaking(),'summary'=>$this->diff->summary(),'impacts'=>$this->impacts,'diff'=>$this->diff->jsonSerialize()];}
	private static function safe(array|\JsonSerializable $value):array{$value=$value instanceof \JsonSerializable?$value->jsonSerialize():$value;if(!is_array($value)){throw new \InvalidArgumentException('Studio impact plans require array manifests.');}$safe=PanelSensitiveDataSanitizer::sanitize($value,['max_depth'=>16,'max_items'=>1000,'max_string_bytes'=>4096]);return is_array($safe)?$safe:[];}
}
