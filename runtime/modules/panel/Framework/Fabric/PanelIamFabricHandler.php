<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Routes privileged IAM commands through the existing tenant-scoped IAM manager. */
final class PanelIamFabricHandler implements PanelCommandHandler,\JsonSerializable {
	public function __construct(private readonly PanelIamManager $iam){}

	public function handle(PanelCommandEnvelope $command):PanelCommandOutcome {
		$input=$command->input();
		$operation=match($command->command()){
			'iam.principal.create'=>'principal.create',
			'iam.service.create'=>'service.create',
			'iam.membership.grant'=>'membership.grant',
			'iam.membership.revoke'=>'membership.revoke',
			'iam.membership.suspend'=>'membership.suspend',
			'iam.membership.restore'=>'membership.restore',
			'iam.service.rotate_credential'=>'service.rotate_credential',
			default=>throw new PanelCommandExecutionException('iam_command_unknown','The IAM command is not supported.'),
		};
		$defaultType=str_starts_with($operation,'principal.')?'principal':(str_starts_with($operation,'service.')?'service':null);
		$subjectType=$defaultType??$this->requiredSubjectType($input);
		if(isset($input['subject_type'])&&$this->requiredSubjectType($input)!==$subjectType){
			throw new PanelCommandExecutionException('iam_input_invalid','The IAM subject type does not match the command.');
		}
		$subjectId=$this->requiredIdentifier($input,'subject_id');
		$mutation=$this->mutation($command,$input,$operation,$subjectType,$subjectId);

		try{
			$receipt=match($operation){
				'principal.create'=>$this->iam->createPrincipal($mutation,$this->principal($command,$input,$subjectId)),
				'service.create'=>$this->iam->createServiceAccount($mutation,$this->service($command,$input,$subjectId)),
				'membership.grant'=>$this->iam->grant(
					$mutation,$input['roles']??[],$input['permissions']??[],
					$this->only($this->map($input['options']??[],'IAM grant options'),['expires_at','metadata'],'IAM grant options'),
				),
				'membership.revoke'=>$this->iam->revoke($mutation),
				'membership.suspend'=>$this->iam->suspend($mutation),
				'membership.restore'=>$this->iam->restore($mutation),
				'service.rotate_credential'=>$this->iam->rotateServiceCredential($mutation,$this->map($input['credential_metadata']??null,'IAM credential metadata')),
			};
		}catch(PanelIamAuthorizationException $error){
			throw new PanelCommandExecutionException('iam_authorization_denied','The IAM operation is not authorized.',$error);
		}catch(PanelIamConflict $error){
			throw new PanelCommandExecutionException('iam_conflict','The IAM operation conflicts with current state.',$error);
		}catch(\OutOfBoundsException $error){
			throw new PanelCommandExecutionException('iam_subject_not_found','The IAM subject does not exist.',$error);
		}catch(\InvalidArgumentException|\LengthException|\UnexpectedValueException $error){
			throw new PanelCommandExecutionException('iam_input_invalid','The IAM command input is invalid.',$error);
		}

		$event=new PanelEventDraft(
			'iam.'.$receipt->operation().'.completed','iam_subject',$receipt->subjectType().':'.$receipt->subjectId(),
			[
				'receipt_id'=>$receipt->id(),'operation'=>$receipt->operation(),'tenant_id'=>$receipt->tenantId(),
				'subject_type'=>$receipt->subjectType(),'subject_id'=>$receipt->subjectId(),'revision'=>$receipt->revision(),
				'status'=>$receipt->status(),'audit_hash'=>$receipt->auditHash(),'native_replayed'=>$receipt->replayed(),
			],
			['source'=>'iam_manager'],
		);
		return PanelCommandOutcome::make($receipt,[$event],[
			'native_runtime'=>'iam_manager','native_replayed'=>$receipt->replayed(),'native_audit_chain'=>true,
		]);
	}

	public function jsonSerialize():array{return [
		'type'=>'panel_iam_fabric_handler','version'=>1,
		'commands'=>['iam.principal.create','iam.service.create','iam.membership.grant','iam.membership.revoke','iam.membership.suspend','iam.membership.restore','iam.service.rotate_credential'],
		'native_authorization'=>true,'native_idempotency'=>true,'native_audit_chain'=>true,'optimistic_concurrency'=>true,
	];}

	/** @param array<string,mixed> $input */
	private function mutation(PanelCommandEnvelope $command,array $input,string $operation,string $subjectType,string $subjectId):PanelIamMutation {
		$reason=$input['reason']??null;
		if(!is_string($reason)||trim($reason)===''){throw new PanelCommandExecutionException('iam_input_invalid','IAM commands require an explicit reason.');}
		$options=[];
		foreach(['requester_id','approver_id']as$key){if(isset($input[$key])){if(!is_string($input[$key])&&!is_int($input[$key])){throw new PanelCommandExecutionException('iam_input_invalid','IAM provenance is invalid.');}$options[$key]=$input[$key];}}
		try{return PanelIamMutation::make($operation,$command->tenantId(),$subjectType,$subjectId,$command->actorId(),$reason,$command->idempotencyKey(),$command->expectedRevision(),$options);}
		catch(\Throwable $error){throw new PanelCommandExecutionException('iam_input_invalid','The IAM mutation envelope is invalid.',$error);}
	}

	/** @param array<string,mixed> $input */
	private function principal(PanelCommandEnvelope $command,array $input,string $subjectId):PanelIamPrincipal {
		$payload=$this->only($this->map($input['principal']??null,'IAM principal'),['id','display_name','email','status','revision','metadata','created_at','updated_at'],'IAM principal');
		if(isset($payload['id'])&&!hash_equals($subjectId,(string)$payload['id'])){throw new PanelCommandExecutionException('iam_input_invalid','The IAM principal id does not match the subject id.');}
		$payload['id']=$subjectId;$payload['revision']=$payload['revision']??0;$payload['status']=$payload['status']??'active';$payload['metadata']=$payload['metadata']??[];
		$payload['created_at']=$payload['created_at']??$command->createdAt();$payload['updated_at']=$payload['updated_at']??$payload['created_at'];
		try{return PanelIamPrincipal::restore($payload);}
		catch(\Throwable $error){throw new PanelCommandExecutionException('iam_input_invalid','The IAM principal definition is invalid.',$error);}
	}

	/** @param array<string,mixed> $input */
	private function service(PanelCommandEnvelope $command,array $input,string $subjectId):PanelIamServiceAccount {
		$payload=$this->only($this->map($input['service']??null,'IAM service account'),['id','display_name','status','revision','metadata','credential_metadata','created_at','updated_at'],'IAM service account');
		if(isset($payload['id'])&&!hash_equals($subjectId,(string)$payload['id'])){throw new PanelCommandExecutionException('iam_input_invalid','The IAM service-account id does not match the subject id.');}
		$payload['id']=$subjectId;$payload['revision']=$payload['revision']??0;$payload['status']=$payload['status']??'active';$payload['metadata']=$payload['metadata']??[];$payload['credential_metadata']=$payload['credential_metadata']??[];
		$payload['created_at']=$payload['created_at']??$command->createdAt();$payload['updated_at']=$payload['updated_at']??$payload['created_at'];
		try{return PanelIamServiceAccount::restore($payload);}
		catch(\Throwable $error){throw new PanelCommandExecutionException('iam_input_invalid','The IAM service-account definition is invalid.',$error);}
	}

	/** @param array<string,mixed> $input */
	private function requiredIdentifier(array $input,string $key):string {
		$value=$input[$key]??null;if(!is_string($value)&&!is_int($value)){throw new PanelCommandExecutionException('iam_input_invalid',"IAM {$key} is required.");}
		try{return PanelOperationsGuard::identifier($value,"IAM {$key}");}catch(\Throwable $error){throw new PanelCommandExecutionException('iam_input_invalid',"IAM {$key} is invalid.",$error);}
	}

	/** @param array<string,mixed> $input */
	private function requiredSubjectType(array $input):string {
		$value=$input['subject_type']??null;if(!is_string($value)){throw new PanelCommandExecutionException('iam_input_invalid','IAM subject_type is required.');}
		try{return PanelIamGuard::subjectType($value);}catch(\Throwable $error){throw new PanelCommandExecutionException('iam_input_invalid','IAM subject_type is invalid.',$error);}
	}

	/** @return array<string,mixed> */
	private function map(mixed $value,string $label):array {
		if(!is_array($value)||($value!==[]&&array_is_list($value))){throw new PanelCommandExecutionException('iam_input_invalid',$label.' must be an object.');}
		try{return PanelOperationsGuard::canonical($value);}catch(\Throwable $error){throw new PanelCommandExecutionException('iam_input_invalid',$label.' is invalid.',$error);}
	}

	/** @param array<string,mixed> $value @param list<string> $allowed @return array<string,mixed> */
	private function only(array $value,array $allowed,string $label):array {
		$unknown=array_diff(array_keys($value),$allowed);if($unknown!==[]){throw new PanelCommandExecutionException('iam_input_invalid',$label.' contains unsupported fields.');}return$value;
	}
}
