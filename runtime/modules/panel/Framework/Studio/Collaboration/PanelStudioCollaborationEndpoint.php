<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Framework-neutral Studio collaboration request boundary.
 *
 * The host retains route registration, authentication, CSRF configuration,
 * editor checkpoint persistence, and raw presence-token custody.
 */
final class PanelStudioCollaborationEndpoint implements \JsonSerializable {
	private $hostAuthorizer=null;
	private readonly int $maximumRequestBytes;
	private readonly int $maximumResponseBytes;
	private readonly int $deltaLimit;
	private readonly int $refreshedIntentTtl;

	/** @param array<string,mixed> $options */
	public function __construct(
		private readonly PanelStudioCollaborationIntentSigner $signer,
		array $options=[],
	){
		$allowed=['maximum_request_bytes','maximum_response_bytes','delta_limit','refreshed_intent_ttl_seconds'];
		foreach(array_keys($options) as $name){
			if(!is_string($name)||!in_array($name, $allowed, true)){
				throw new \InvalidArgumentException('Studio collaboration endpoint options contain an unsupported name.');
			}
		}
		$this->maximumRequestBytes=self::option($options,'maximum_request_bytes',131072,4096,1048576);
		$this->maximumResponseBytes=self::option($options,'maximum_response_bytes',524288,4096,4194304);
		$this->deltaLimit=self::option($options,'delta_limit',100,1,1000);
		$this->refreshedIntentTtl=self::option($options,'refreshed_intent_ttl_seconds',300,30,900);
	}

	/** @param callable(string,PanelStudioEditorSession,array<string,mixed>):bool|null $authorizer */
	public function authorizeHost(?callable $authorizer):self {
		$clone=clone $this;
		$clone->hostAuthorizer=$authorizer;
		return $clone;
	}

	/** @param array<string,mixed>|string $request */
	public function handle(
		PanelStudioEditorSession $session,
		PanelStudioEditorOptions $editorOptions,
		array|string $request,
		?string $trustedPresenceToken=null,
		string $correlationId='',
	):PanelStudioCollaborationEndpointResult {
		try{
			$input=$this->request($request);
			$action=$this->action($input['studio_collaboration_transport_action']??null);
			$ability=match($action){
				'delta'=>'delta',
				'mutate'=>'mutate',
				'presence_sync','presence_release'=>'presence',
				'typing'=>'typing',
			};
			$token=$input['studio_collaboration_intent']??null;
			if(!is_string($token)){throw $this->error('intent_invalid',401);}
			$verified=$this->signer->verify($token,$session,$ability);
			$this->authorize($action,$session,$input);
			$connector=$editorOptions->collaborationConnector();
			if(!$connector instanceof PanelStudioCollaborationConnector){
				throw $this->error('collaboration_unavailable',503,true);
			}

			$presenceDisposition='unchanged';
			$presenceToken=null;
			$changes=[];
			$reset=false;
			$changed=false;
			$snapshot=null;

			if($action==='delta'){
				$cursor=$this->cursor($input['studio_collaboration_cursor']??0);
				$currentCursor=$connector->manager()->cursor();
				$feed=$cursor>$currentCursor
					?['reset_required'=>true,'changes'=>[]]
					:$connector->manager()->changesSince($cursor,$this->deltaLimit);
				$reset=($feed['reset_required']??false)===true;
				foreach(is_array($feed['changes']??null)?$feed['changes']:[] as $change){
					if(!is_array($change)){continue;}
					$changes[]=[
						'cursor'=>(int)($change['cursor']??0),
						'type'=>(string)($change['type']??'collaboration.changed'),
						'occurred_at'=>(string)($change['occurred_at']??''),
					];
				}
				$changed=$reset||$changes!==[];
				$snapshot=$connector->snapshot($session);
			}elseif($action==='mutate'){
				$this->csrf($editorOptions,$input);
				if(!$session->synchronizeClientState($input,$editorOptions)){
					throw $this->error('client_state_invalid',422);
				}
				$result=$connector->handle($session,$input);
				$changed=$result->changed();
				$snapshot=$result->snapshot();
			}elseif($action==='presence_sync'){
				$this->csrf($editorOptions,$input);
				if($trustedPresenceToken===null){
					$lease=$connector->acquirePresence($session,60);
				}else{
					try{$lease=$connector->heartbeatPresence($session,$trustedPresenceToken,60);}
					catch(\UnexpectedValueException){$lease=$connector->acquirePresence($session,60);}
				}
				$presenceDisposition='replace';
				$presenceToken=$lease->leaseToken();
				$changed=true;
				$snapshot=$connector->snapshot($session);
			}elseif($action==='presence_release'){
				$this->csrf($editorOptions,$input);
				$changed=$trustedPresenceToken!==null&&$connector->releasePresence($session,$trustedPresenceToken);
				$presenceDisposition='clear';
				$snapshot=$connector->snapshot($session);
			}else{
				$this->csrf($editorOptions,$input);
				$thread=$input['studio_collaboration_thread_id']??null;
				if(!is_string($thread)){throw $this->error('request_invalid',422);}
				$typing=$this->boolean($input['studio_collaboration_typing']??null);
				$changed=$connector->setTyping($session,$thread,$typing,12);
				$snapshot=$connector->snapshot($session);
			}

			$refreshed=$this->signer->issue($session,$verified->abilities(),$this->refreshedIntentTtl);
			$fragment=$changed||$reset?PanelStudioEditorRenderer::renderCollaboration($snapshot,$editorOptions->editorId()):null;
			$body=[
				'type'=>'panel_studio_collaboration_transport_response',
				'version'=>1,
				'ok'=>true,
				'action'=>$action,
				'changed'=>$changed,
				'cursor'=>$snapshot->cursor(),
				'reset_required'=>$reset,
				'changes'=>$changes,
				'workspace'=>$snapshot->model(),
				'fragment'=>$fragment,
				'intent'=>$refreshed->browserModel(),
				'correlation_id'=>$this->correlation($correlationId),
			];
			return $this->result(200,$body,$presenceDisposition,$presenceToken);
		}catch(PanelStudioCollaborationTransportException $error){
			return $this->failure($error,$correlationId);
		}catch(PanelCollaborationPolicyException){
			return $this->failure($this->error('host_authorization_denied',403),$correlationId);
		}catch(\InvalidArgumentException|\LengthException|\OutOfBoundsException|\UnexpectedValueException){
			return $this->failure($this->error('request_invalid',422),$correlationId);
		}catch(\Throwable){
			return $this->failure($this->error('collaboration_failed',500,true),$correlationId);
		}
	}

	public function signer():PanelStudioCollaborationIntentSigner{return $this->signer;}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return [
			'type'=>'panel_studio_collaboration_endpoint',
			'version'=>1,
			'authorization_configured'=>$this->hostAuthorizer!==null,
			'signer'=>$this->signer,
			'maximum_request_bytes'=>$this->maximumRequestBytes,
			'maximum_response_bytes'=>$this->maximumResponseBytes,
			'delta_limit'=>$this->deltaLimit,
			'refreshed_intent_ttl_seconds'=>$this->refreshedIntentTtl,
			'host_route_required'=>true,
			'host_session_persistence_required'=>true,
			'host_presence_token_custody'=>true,
			'mutations_retried'=>false,
			'capabilities'=>[
				'signed_delta'=>true,
				'signed_mutation'=>true,
				'signed_presence'=>true,
				'signed_typing'=>true,
				'csrf_on_state_changes'=>true,
				'bounded_responses'=>true,
				'rotating_intents'=>true,
				'secret_safe_manifest'=>true,
			],
		];
	}

	/** @param array<string,mixed>|string $request @return array<string,mixed> */
	private function request(array|string $request):array {
		if(is_string($request)){
			if(strlen($request)>$this->maximumRequestBytes){throw $this->error('request_too_large',413);}
			try{$request=json_decode($request,true,64,JSON_THROW_ON_ERROR);}
			catch(\Throwable){throw $this->error('request_invalid',422);}
		}
		if(array_is_list($request)||count($request)>256){
			throw $this->error('request_invalid',422);
		}
		try{$encoded=json_encode($request,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
		catch(\Throwable){throw $this->error('request_invalid',422);}
		$bytes=strlen($encoded);
		if($bytes>$this->maximumRequestBytes){throw $this->error('request_too_large',413);}
		return $request;
	}

	private function action(mixed $value):string {
		if(!is_string($value)||!in_array($value,['delta','mutate','presence_sync','presence_release','typing'],true)){
			throw $this->error('request_invalid',422);
		}
		return $value;
	}

	/** @param array<string,mixed> $input */
	private function authorize(string $action,PanelStudioEditorSession $session,array $input):void {
		if($this->hostAuthorizer===null){throw $this->error('host_authorization_required',403);}
		$context=[
			'cursor'=>$input['studio_collaboration_cursor']??null,
			'operation'=>$input['studio_collaboration_operation']??null,
			'thread_id'=>$input['studio_collaboration_thread_id']??null,
			'typing'=>$input['studio_collaboration_typing']??null,
		];
		try{$allowed=($this->hostAuthorizer)($action,$session,PanelCollaborationStateEngine::sanitize($context));}
		catch(\Throwable $error){throw $this->error('host_authorization_unavailable',503,true,$error);}
		if($allowed!==true){throw $this->error('host_authorization_denied',403);}
	}

	/** @param array<string,mixed> $input */
	private function csrf(PanelStudioEditorOptions $options,array $input):void {
		if(!$options->verifyCsrf($input)){throw $this->error('csrf_invalid',419);}
	}

	private function cursor(mixed $value):int {
		if(is_int($value)&&$value>=0){return $value;}
		if(is_string($value)&&preg_match('/^(0|[1-9][0-9]*)$/D', $value)===1&&strlen($value)<=strlen((string)PHP_INT_MAX)){
			$cursor=(int)$value;
			if((string)$cursor===$value){return $cursor;}
		}
		throw $this->error('request_invalid',422);
	}

	private function boolean(mixed $value):bool {
		if(is_bool($value)){return $value;}
		if($value==='1'||$value==='true'){return true;}
		if($value==='0'||$value==='false'){return false;}
		throw $this->error('request_invalid',422);
	}

	/** @param array<string,mixed> $body */
	private function result(int $status,array $body,string $presenceDisposition='unchanged',?string $presenceToken=null):PanelStudioCollaborationEndpointResult {
		$content=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		if(strlen($content)>$this->maximumResponseBytes){
			throw $this->error('response_too_large',507);
		}
		return new PanelStudioCollaborationEndpointResult(
			$status,
			[
				'Content-Type'=>'application/json; charset=utf-8',
				'Cache-Control'=>'no-store, private',
				'X-Content-Type-Options'=>'nosniff',
			],
			$body,
			$presenceDisposition,
			$presenceToken,
		);
	}

	private function failure(PanelStudioCollaborationTransportException $error,string $correlationId):PanelStudioCollaborationEndpointResult {
		$body=[
			'type'=>'panel_studio_collaboration_transport_error',
			'version'=>1,
			'ok'=>false,
			'code'=>$error->publicCode(),
			'message'=>$this->message($error->publicCode()),
			'retryable'=>$error->retryable(),
			'correlation_id'=>$this->correlation($correlationId),
		];
		return new PanelStudioCollaborationEndpointResult(
			$error->httpStatus(),
			[
				'Content-Type'=>'application/json; charset=utf-8',
				'Cache-Control'=>'no-store, private',
				'X-Content-Type-Options'=>'nosniff',
			],
			$body,
		);
	}

	private function error(string $code,int $status,bool $retryable=false,?\Throwable $previous=null):PanelStudioCollaborationTransportException {
		return new PanelStudioCollaborationTransportException($code,$status,$this->message($code),$retryable,$previous);
	}

	private function message(string $code):string {
		return [
			'intent_invalid'=>'Studio collaboration intent is invalid.',
			'intent_expired'=>'Studio collaboration intent has expired.',
			'request_invalid'=>'Studio collaboration request is invalid.',
			'request_too_large'=>'Studio collaboration request exceeds its byte bound.',
			'response_too_large'=>'Studio collaboration response exceeds its byte bound.',
			'csrf_invalid'=>'Studio collaboration CSRF verification failed.',
			'client_state_invalid'=>'Studio collaboration client state is invalid.',
			'host_authorization_required'=>'Studio collaboration host authorization is required.',
			'host_authorization_denied'=>'Studio collaboration request is not authorized.',
			'host_authorization_unavailable'=>'Studio collaboration host authorization is unavailable.',
			'collaboration_unavailable'=>'Studio collaboration is unavailable.',
			'collaboration_failed'=>'Studio collaboration request failed.',
		][$code]??'Studio collaboration request was rejected.';
	}

	private function correlation(string $value):?string {
		$value=trim($value);
		return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $value)===1?$value:null;
	}

	/** @param array<string,mixed> $options */
	private static function option(array $options,string $name,int $default,int $minimum,int $maximum):int {
		$value=$options[$name]??$default;
		if(!is_int($value)||$value<$minimum||$value>$maximum){
			throw new \InvalidArgumentException("Studio collaboration endpoint option '{$name}' is outside its supported bound.");
		}
		return $value;
	}
}
