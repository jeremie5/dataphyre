<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Routes operation submit/run commands into the configured durable runner. */
final class PanelOperationFabricHandler implements PanelCommandHandler,\JsonSerializable {
	public function __construct(private readonly PanelOperationRunner $operations){}

	public function handle(PanelCommandEnvelope $command):PanelCommandOutcome {
		$input=$command->input();
		$options=$command->command()==='operation.submit'?$this->map($input['options']??[],'operation options'):[];
		$optionMetadata=$this->map($options['metadata']??[],'operation metadata');
		$record=match($command->command()){
			'operation.submit'=>$this->operations->submit(
				$this->requiredName($input,'type'),$this->optionalLabel($input,'name','operation'),$input['payload']??[],
				array_replace($options,[
					'id'=>'fabric_'.substr($command->fingerprint(),0,40),'idempotency_key'=>$command->idempotencyKey(),
					'metadata'=>array_replace($optionMetadata,[
						'tenant_id'=>$command->tenantId(),'actor_id'=>$command->actorId(),'correlation_id'=>$command->correlationId(),
					]),
				]),
			),
			'operation.run'=>$this->operations->run($this->requiredIdentifier($input,'id')),
			default=>throw new PanelCommandExecutionException('operation_command_unknown','The operation command is not supported.'),
		};
		$event=new PanelEventDraft(
			'operation.'.$record->status(),'operation',$record->id(),
			[
				'operation_id'=>$record->id(),'operation_type'=>$record->type(),'status'=>$record->status(),
				'revision'=>$record->revision(),'attempt'=>$record->attempt(),'terminal'=>$record->terminal(),'percent'=>$record->percent(),
			],
			['source'=>'operation_runner'],
		);
		return PanelCommandOutcome::make($record,[$event],[
			'native_runtime'=>'operation_runner','operation_status'=>$record->status(),'operation_terminal'=>$record->terminal(),
		]);
	}

	public function jsonSerialize():array{return ['type'=>'panel_operation_fabric_handler','version'=>1,'commands'=>['operation.submit','operation.run'],'deterministic_submit_ids'=>true,'native_idempotency'=>true];}

	/** @param array<string,mixed> $input */
	private function requiredName(array $input,string $key):string {
		$value=$input[$key]??null;if(!is_string($value)&&!is_int($value)){throw new PanelCommandExecutionException('operation_input_invalid',"Operation {$key} is required.");}
		try{return PanelOperationsGuard::name($value,"operation {$key}");}
		catch(\Throwable $error){throw new PanelCommandExecutionException('operation_input_invalid',"Operation {$key} is invalid.",$error);}
	}

	/** @param array<string,mixed> $input */
	private function requiredIdentifier(array $input,string $key):string {
		$value=$input[$key]??null;if(!is_string($value)&&!is_int($value)){throw new PanelCommandExecutionException('operation_input_invalid',"Operation {$key} is required.");}
		try{return PanelOperationsGuard::identifier($value,"operation {$key}");}
		catch(\Throwable $error){throw new PanelCommandExecutionException('operation_input_invalid',"Operation {$key} is invalid.",$error);}
	}

	/** @param array<string,mixed> $input */
	private function optionalLabel(array $input,string $key,string $default):string {
		$value=$input[$key]??$default;if(!is_string($value)){throw new PanelCommandExecutionException('operation_input_invalid',"Operation {$key} is invalid.");}
		try{return PanelOperationsGuard::label($value,"operation {$key}",200);}
		catch(\Throwable $error){throw new PanelCommandExecutionException('operation_input_invalid',"Operation {$key} is invalid.",$error);}
	}

	/** @return array<string,mixed> */
	private function map(mixed $value,string $label):array {
		if(!is_array($value)||($value!==[]&&array_is_list($value))){throw new PanelCommandExecutionException('operation_input_invalid',ucfirst($label).' must be an object.');}
		try{return PanelOperationsGuard::canonical($value);}
		catch(\Throwable $error){throw new PanelCommandExecutionException('operation_input_invalid',ucfirst($label).' is invalid.',$error);}
	}
}
