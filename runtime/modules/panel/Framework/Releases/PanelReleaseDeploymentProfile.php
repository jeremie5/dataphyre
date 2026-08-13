<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable, credential-free deployment target and rollout policy. */
final class PanelReleaseDeploymentProfile implements \JsonSerializable {
	private const DRIVERS=['kubernetes','nomad','ecs','compose','filesystem'];
	private const STRATEGIES=['rolling','blue_green','canary'];
	private readonly string $driver;
	private readonly string $target;
	private readonly string $strategy;
	private readonly array $config;
	private readonly array $rollout;
	private readonly string $digest;

	public function __construct(string $driver,string $target,array $config,string $strategy='rolling',array $rollout=[]){
		$driver=PanelOperationsGuard::name($driver,'release deployment driver');if(!in_array($driver,self::DRIVERS,true))throw new \InvalidArgumentException('Release deployment driver is unsupported.');
		$strategy=PanelOperationsGuard::name($strategy,'release deployment strategy');if(!in_array($strategy,self::STRATEGIES,true))throw new \InvalidArgumentException('Release deployment strategy is unsupported.');
		$this->driver=$driver;$this->target=PanelOperationsGuard::identifier($target,'release deployment target');$this->strategy=$strategy;$this->config=$this->normalizeConfig($config);$this->rollout=$this->normalizeRollout($rollout);
		$this->digest=PanelOperationsGuard::digest(['driver'=>$this->driver,'target'=>$this->target,'strategy'=>$this->strategy,'config'=>$this->config,'rollout'=>$this->rollout]);
	}

	public static function kubernetes(string $target,array $config,string $strategy='rolling',array $rollout=[]):self{return new self('kubernetes',$target,$config,$strategy,$rollout);}
	public static function nomad(string $target,array $config,string $strategy='rolling',array $rollout=[]):self{return new self('nomad',$target,$config,$strategy,$rollout);}
	public static function ecs(string $target,array $config,string $strategy='rolling',array $rollout=[]):self{return new self('ecs',$target,$config,$strategy,$rollout);}
	public static function compose(string $target,array $config,string $strategy='blue_green',array $rollout=[]):self{return new self('compose',$target,$config,$strategy,$rollout);}
	public static function filesystem(string $target,array $config,string $strategy='blue_green',array $rollout=[]):self{return new self('filesystem',$target,$config,$strategy,$rollout);}
	public function driver():string{return$this->driver;}public function target():string{return$this->target;}public function strategy():string{return$this->strategy;}public function config():array{return$this->config;}public function rollout():array{return$this->rollout;}public function digest():string{return$this->digest;}

	public function action(string $phase):string{
		$phase=strtolower(trim($phase));if(!in_array($phase,['prepare','activate','verify','rollback'],true))throw new \InvalidArgumentException('Release deployment phase is invalid.');
		return match($this->driver){
			'kubernetes'=>['prepare'=>'stage_workload_revision','activate'=>'promote_workload_revision','verify'=>'verify_workload_rollout','rollback'=>'rollback_workload_revision'][$phase],
			'nomad'=>['prepare'=>'register_job_version','activate'=>'promote_job_version','verify'=>'verify_job_deployment','rollback'=>'rollback_job_version'][$phase],
			'ecs'=>['prepare'=>'register_task_revision','activate'=>'update_service_revision','verify'=>'verify_service_deployment','rollback'=>'rollback_service_revision'][$phase],
			'compose'=>['prepare'=>'prepare_service_release','activate'=>'promote_service_release','verify'=>'verify_service_release','rollback'=>'rollback_service_release'][$phase],
			'filesystem'=>['prepare'=>'stage_release_directory','activate'=>'switch_current_release','verify'=>'verify_current_release','rollback'=>'restore_previous_release'][$phase],
		};
	}

	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_release_deployment_profile','version'=>1,'digest'=>$this->digest,'driver'=>$this->driver,'target'=>$this->target,'strategy'=>$this->strategy,'config'=>$this->config,'rollout'=>$this->rollout,'credentials_exposed'=>false,'transport_endpoint_exposed'=>false]);}

	private function normalizeConfig(array $config):array{
		PanelOperationsGuard::object($config,'release deployment config',16);
		$schema=match($this->driver){
			'kubernetes'=>[['namespace','workload_kind','workload','container'],['cluster_ref']],
			'nomad'=>[['namespace','job','group','task'],['region']],
			'ecs'=>[['region','cluster','service','container'],[]],
			'compose'=>[['project','service'],['context_ref']],
			'filesystem'=>[['root_ref','current_link'],['health_ref']],
		};
		[$required,$optional]=$schema;$allowed=[...$required,...$optional];foreach($config as$key=>$value){if(!is_string($key)||!in_array($key,$allowed,true))throw new \InvalidArgumentException('Release deployment config contains an unsupported key.');if(!is_string($value))throw new \InvalidArgumentException('Release deployment config values must be identifiers.');$config[$key]=PanelOperationsGuard::identifier($value,'release deployment '.$key,160);}
		foreach($required as$key){if(!isset($config[$key]))throw new \InvalidArgumentException('Release deployment config is missing '.$key.'.');}
		if($this->driver==='kubernetes'&&!in_array(strtolower($config['workload_kind']),['deployment','statefulset','daemonset'],true))throw new \InvalidArgumentException('Kubernetes workload kind is unsupported.');
		ksort($config,SORT_STRING);return$config;
	}

	private function normalizeRollout(array $rollout):array{
		PanelOperationsGuard::object($rollout,'release rollout config',16);$defaults=['verify_timeout_seconds'=>300,'drain_timeout_seconds'=>120,'max_unavailable_percent'=>0,'max_surge_percent'=>25];
		if($this->strategy==='canary')$defaults['canary_percent']=10;$allowed=array_keys($defaults);
		foreach($rollout as$key=>$value){if(!is_string($key)||!in_array($key,$allowed,true)||!is_int($value))throw new \InvalidArgumentException('Release rollout config is invalid.');}
		$rollout=array_replace($defaults,$rollout);
		foreach(['verify_timeout_seconds','drain_timeout_seconds']as$key){if($rollout[$key]<10||$rollout[$key]>86400)throw new \InvalidArgumentException('Release rollout timeout is invalid.');}
		foreach(['max_unavailable_percent','max_surge_percent']as$key){if($rollout[$key]<0||$rollout[$key]>100)throw new \InvalidArgumentException('Release rollout percentage is invalid.');}
		if(isset($rollout['canary_percent'])&&($rollout['canary_percent']<1||$rollout['canary_percent']>50))throw new \InvalidArgumentException('Release canary percentage is invalid.');
		ksort($rollout,SORT_STRING);return$rollout;
	}
}
