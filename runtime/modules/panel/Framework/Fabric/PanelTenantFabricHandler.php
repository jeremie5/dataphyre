<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Routes explicit tenant lifecycle commands into one manager-owned tenant registry. */
final class PanelTenantFabricHandler implements PanelCommandHandler,\JsonSerializable {
	private readonly ?\Closure $requestResolver;

	public function __construct(private readonly PanelTenantRegistry $tenants,PanelTenantFabricRequestResolver|callable|null $requestResolver=null){
		$this->requestResolver=$requestResolver===null?null:($requestResolver instanceof PanelTenantFabricRequestResolver?\Closure::fromCallable([$requestResolver,'resolve']):\Closure::fromCallable($requestResolver));
	}

	public function handle(PanelCommandEnvelope $command):PanelCommandOutcome {
		$input=$command->input();
		try{
			return match($command->command()){
				'tenant.register'=>$this->register($input),
				'tenant.onboard'=>$this->onboard($command,$input),
				'tenant.switch'=>$this->switch($command,$input),
				default=>throw new PanelCommandExecutionException('tenant_command_unknown','The tenant command is not supported.'),
			};
		}catch(PanelCommandExecutionException $error){throw$error;}
		catch(\InvalidArgumentException|\LengthException|\UnexpectedValueException $error){throw new PanelCommandExecutionException('tenant_input_invalid','The tenant command input is invalid.',$error);}
		catch(\Throwable $error){throw new PanelCommandExecutionException('tenant_operation_failed','The tenant operation failed.',$error);}
	}

	public function jsonSerialize():array{return [
		'type'=>'panel_tenant_fabric_handler','version'=>1,'commands'=>['tenant.register','tenant.onboard','tenant.switch'],
		'request_resolver'=>$this->requestResolver!==null,'implicit_request_capture'=>false,'native_onboarding_idempotency'=>true,'native_idempotency_scope'=>'process',
		'host_effects'=>'at_least_once','registry'=>$this->tenants->describe(),
	];}

	/** @param array<string,mixed> $input */
	private function register(array $input):PanelCommandOutcome {
		$tenant=$this->tenant($input);$registered=$this->tenants->register($tenant);
		return PanelCommandOutcome::make($registered->definition(),[
			new PanelEventDraft('tenant.registered','tenant',$registered->name(),['tenant'=>$registered->definition()],['source'=>'tenant_registry']),
		],['native_runtime'=>'tenant_registry','host_effects'=>false]);
	}

	/** @param array<string,mixed> $input */
	private function onboard(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$tenant=$this->tenant($input);$result=$this->tenants->onboard($tenant,$this->request($command),$command->idempotencyKey());
		if(!$result->ok()){throw new PanelCommandExecutionException($this->errorCode($result->code()),'Tenant onboarding did not complete successfully.');}
		return PanelCommandOutcome::make($result,[
			new PanelEventDraft('tenant.onboarded','tenant',$tenant->name(),[
				'tenant'=>$tenant->name(),'completed'=>$result->completed(),'rolled_back'=>$result->rolledBack(),'native_replayed'=>$result->replayed(),
			],['source'=>'tenant_registry']),
		],['native_runtime'=>'tenant_registry','native_replayed'=>$result->replayed(),'host_effects'=>'at_least_once']);
	}

	/** @param array<string,mixed> $input */
	private function switch(PanelCommandEnvelope $command,array $input):PanelCommandOutcome {
		$target=$input['tenant']??null;if(!is_string($target)||trim($target)===''){throw new PanelCommandExecutionException('tenant_input_invalid','Tenant switch target is required.');}
		$result=$this->tenants->switch($target,$this->request($command));
		if(!$result->ok()){throw new PanelCommandExecutionException($this->errorCode($result->code()),'Tenant switching did not complete successfully.');}
		return PanelCommandOutcome::make($result,[
			new PanelEventDraft('tenant.switched','tenant',$result->current()->tenantKey()??$target,[
				'previous'=>$result->previous()->tenantKey(),'current'=>$result->current()->tenantKey(),'persisted'=>$result->persisted(),
			],['source'=>'tenant_registry']),
		],['native_runtime'=>'tenant_registry','host_effects'=>'at_least_once']);
	}

	/** @param array<string,mixed> $input */
	private function tenant(array $input):PanelTenant {
		$definition=$input['tenant']??null;if(!is_array($definition)||($definition!==[]&&array_is_list($definition))){throw new PanelCommandExecutionException('tenant_input_invalid','Tenant definition is required.');}
		try{
			$definition=PanelOperationsGuard::canonical($definition);
			$name=$definition['name']??null;
			if(!is_string($name)&&!is_int($name)){throw new \InvalidArgumentException('Tenant name is required.');}
			$definition['name']=PanelOperationsGuard::name($name,'tenant name');
			return PanelTenant::fromArray($definition);
		}
		catch(\Throwable $error){throw new PanelCommandExecutionException('tenant_input_invalid','The tenant definition is invalid.',$error);}
	}

	private function request(PanelCommandEnvelope $command):PanelRequest {
		if(!$this->requestResolver instanceof \Closure){throw new PanelCommandExecutionException('tenant_request_unavailable','Tenant onboarding and switching require a host request resolver.');}
		try{$request=($this->requestResolver)($command);}catch(\Throwable $error){throw new PanelCommandExecutionException('tenant_request_unavailable','The tenant request context could not be resolved.',$error);}
		if(!$request instanceof PanelRequest){throw new PanelCommandExecutionException('tenant_request_unavailable','The tenant request resolver returned an invalid request.');}return$request;
	}

	private function errorCode(string $code):string{return substr('tenant_'.preg_replace('/[^a-z0-9_.-]+/','_',strtolower($code)),0,96);}
}
