<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** HTTP-neutral controller for first-party platform pages and mutations. */
final class PanelPlatformController {
	private $authorizer=null;
	private $csrfValidator=null;
	private $securityContextResolver=null;
	private $securityPolicyResolver=null;
	private ?PanelSecurityAuditTrail $securityAudit=null;
	private bool $securityBoundaryEnabled=false;
	private bool $developmentErrorDetails=false;
	public function authorize(?callable $authorizer): self { $clone=clone $this; $clone->authorizer=$authorizer; return $clone; }
	public function csrf(?callable $validator): self { $clone=clone $this; $clone->csrfValidator=$validator; return $clone; }
	/** Explicitly enables sanitized exception detail for local development responses. */
	public function developmentErrors(bool $enabled=true):self{$clone=clone$this;$clone->developmentErrorDetails=$enabled;return$clone;}
	/** Configures the explainable policy/context boundary used when no custom authorizer is supplied. */
	public function securityBoundary(?PanelSecurityAuditTrail $audit=null,?callable $contextResolver=null,?callable $policyResolver=null):self{$clone=clone $this;$clone->securityBoundaryEnabled=true;$clone->securityAudit=$audit;$clone->securityContextResolver=$contextResolver;$clone->securityPolicyResolver=$policyResolver;return$clone;}
	public function securityBoundaryEnabled():bool{return$this->securityBoundaryEnabled;}
	public function securityAudit():?PanelSecurityAuditTrail{return$this->securityAudit;}

	public function operationsOs(PanelOperationsConsole $console,array $options=[],PanelRequest|array|null $request=null):PanelPageResult {
		$request=self::readRequest($request??($options['request']??null));$context=$this->securityContext($request);$tenant=$request->tenantKey()??$context?->tenantId();
		$denial=$this->readGuard('operations_os.console.view',$request,['tenant_id'=>$tenant],$options);if($denial!==null){return$denial;}
		try{$snapshot=$console->snapshot($tenant,self::operationsConsoleSnapshotOptions($request,$options));return PanelPlatformTemplate::operationsOs($snapshot,$options);}
		catch(\Throwable$exception){return$this->errorResponse($request,'invalid_operations_os_console_request',422,'The Operations OS console request is invalid.',$exception,['domain'=>'operations_os','tenant_id'=>$tenant]);}
	}

	public function operateOperationsOs(PanelOperationsConsole $console,PanelRequest|array $request,array $options=[]):PanelPageResult {
		$request=$request instanceof PanelRequest?$request:PanelRequest::fromArray($request);
		if(strtoupper($request->method())!=='POST'){return$this->errorResponse($request,'method_not_allowed',405,'This endpoint requires POST.',null,[],['Allow'=>'POST']);}
		$operation=strtolower(trim((string)$request->input('operation','')));$allowed=['dispatch','recover_stale','drain_subscriber','intelligence_signal','intelligence_propose','intelligence_approve','intelligence_reject','intelligence_dispatch','intelligence_feedback','intelligence_recover'];
		if(!in_array($operation,$allowed,true)){return$this->errorResponse($request,'invalid_operations_os_request',422,'A valid Operations OS console operation is required.');}
		$context=$this->securityContext($request);$tenant=$request->tenantKey()??$context?->tenantId();$subject=['operation'=>$operation,'tenant_id'=>$tenant,'global_maintenance'=>in_array($operation,['recover_stale','drain_subscriber','intelligence_recover'],true),'command'=>$request->input('command'),'proposal_id'=>$request->input('proposal_id'),'subscriber'=>$request->input('subscriber')];
		$denial=$this->guard('operations_os.console.'.$operation,$request,$subject,$options);if($denial!==null){return$denial;}
		try{
			if($operation==='recover_stale'){
				$result=$console->recoverStale((int)$request->input('stale_after_seconds',300),(int)$request->input('limit',25));
				return PanelPageResult::json(['ok'=>true,'status'=>'committed','operation'=>$operation,'result'=>$result]);
			}
			if($operation==='drain_subscriber'){
				$name=trim((string)$request->input('subscriber',''));if($name===''){throw new \InvalidArgumentException('A subscriber is required.');}
				$result=$console->drainSubscriber($name,(int)$request->input('limit',100));
				return PanelPageResult::json(['ok'=>($result['ok']??false)===true,'status'=>($result['ok']??false)===true?'committed':'retry_required','operation'=>$operation,'result'=>$result],($result['ok']??false)===true?200:409);
			}
			if(!$context instanceof PanelSecurityContext){return$this->errorResponse($request,'unauthenticated',401,'An authenticated security context is required.');}
			if(str_starts_with($operation,'intelligence_')){
				$requestedTenant=trim((string)$request->input('tenant_id',$tenant??''));if($requestedTenant===''||($tenant!==null&&!hash_equals($tenant,$requestedTenant))||($context->tenantId()!==null&&!hash_equals($context->tenantId(),$requestedTenant))){return$this->errorResponse($request,'tenant_mismatch',403,'The intelligence tenant does not match the authenticated request boundary.');}$policyRequest=self::intelligencePolicyRequest($context,$requestedTenant,$request);$proposalId=trim((string)$request->input('proposal_id',$request->input('id','')));
				$result=match($operation){
					'intelligence_signal'=>$console->observe((string)$request->input('kind',''),$requestedTenant,(string)$request->input('source',''),(string)$request->input('subject_type',''),(string)$request->input('subject_id',''),(string)$request->input('summary',''),(string)$request->input('severity','medium'),(int)$request->input('confidence_basis_points',0),$policyRequest,self::arrayInput($request,'evidence'),self::optionalString($request->input('observed_at')),(int)$request->input('ttl_seconds',900)),
					'intelligence_propose'=>$console->propose((string)$request->input('signal_id',''),(string)$request->input('command',''),(string)$request->input('ability',''),self::arrayInput($request,'input'),(string)$request->input('risk','medium'),(string)$request->input('reason',''),$policyRequest,(string)$request->input('idempotency_key',''),(int)$request->input('requested_approvals',0),self::optionalInt($request->input('expected_revision'))),
					'intelligence_approve'=>$console->approveProposal($proposalId,$policyRequest,self::optionalInt($request->input('ttl_seconds'))),
					'intelligence_reject'=>$console->rejectProposal($proposalId,(string)$request->input('reason',''),$policyRequest),
					'intelligence_dispatch'=>$console->dispatchProposal($proposalId,$policyRequest,self::booleanInput($request->input('confirmed',false)),self::optionalInt($request->input('expected_revision')),self::optionalInt($request->input('stale_after_seconds'))),
					'intelligence_feedback'=>$console->recordFeedback($proposalId,(string)$request->input('outcome','unknown'),(int)$request->input('effectiveness_basis_points',0),self::arrayInput($request,'evidence'),$policyRequest,(string)$request->input('idempotency_key','')),
					'intelligence_recover'=>$console->recoverIntelligence($policyRequest,self::optionalInt($request->input('stale_after_seconds')),(int)$request->input('limit',25)),
				};
				if($result instanceof PanelCommandReceipt){$payload=$console->receiptSummary($result);return PanelPageResult::json(['ok'=>$result->ok(),'status'=>$result->status(),'operation'=>$operation,'receipt'=>$payload],$result->ok()?200:($result->status()==='denied'?403:422));}
				$payload=$result instanceof \JsonSerializable?$result->jsonSerialize():$result;return PanelPageResult::json(['ok'=>true,'status'=>'committed','operation'=>$operation,'result'=>$payload]);
			}
			$commandName=trim((string)$request->input('command',''));$ability=trim((string)$request->input('ability',''));$idempotency=trim((string)$request->input('idempotency_key',''));
			$requestedTenant=trim((string)$request->input('tenant_id',$tenant??''));if($tenant!==null&&$requestedTenant!==''&&!hash_equals($tenant,$requestedTenant)){return$this->errorResponse($request,'tenant_mismatch',403,'The command tenant does not match the request tenant.');}$commandTenant=$tenant??$requestedTenant;
			if($context->tenantId()!==null&&!hash_equals($context->tenantId(),$commandTenant)){return$this->errorResponse($request,'tenant_mismatch',403,'The command tenant does not match the authenticated tenant boundary.');}
			if($commandName===''||$ability===''||$idempotency===''||$commandTenant===''){throw new \InvalidArgumentException('Command, ability, tenant, and idempotency key are required.');}
			if(!self::allowedConsoleCommand($commandName,$options['allowed_commands']??null)){return$this->errorResponse($request,'command_not_allowed',403,'The command is not enabled for this console.');}
			$correlation=self::optionalString($request->input('correlation_id',$request->header('x-correlation-id')));$causation=self::optionalString($request->input('causation_id'));
			$command=new PanelCommandEnvelope(
				$commandName,$ability,$commandTenant,$context->actorId(),$idempotency,self::arrayInput($request,'input'),
				strtolower(trim((string)$request->input('risk','medium'))),$context->roles(),$context->permissions(),$correlation,$causation,
				self::optionalInt($request->input('expected_revision')),['source'=>'operations_os.console','mfa_level'=>$context->mfaLevel(),'confirmed'=>self::booleanInput($request->input('confirmed',false)),'dry_run'=>self::booleanInput($request->input('dry_run',false))],
			);
			$receipt=$console->dispatch($command);$payload=$console->receiptSummary($receipt);$status=$receipt->ok()?200:($receipt->status()==='denied'?403:422);
			return PanelPageResult::json(['ok'=>$receipt->ok(),'status'=>$receipt->status(),'operation'=>$operation,'receipt'=>$payload],$status);
		}catch(\OutOfBoundsException$exception){return$this->errorResponse($request,'not_found',404,'The Operations OS target was not found.',$exception,['domain'=>'operations_os','operation'=>$operation]);}
		catch(\LogicException$exception){return$this->errorResponse($request,'conflict',409,'The Operations OS operation conflicted with current state.',$exception,['domain'=>'operations_os','operation'=>$operation]);}
		catch(\Throwable$exception){return$this->errorResponse($request,'invalid_operations_os_request',422,'The Operations OS request could not be completed.',$exception,['domain'=>'operations_os','operation'=>$operation]);}
	}

	public function operations(PanelOperationStore $store,array $options=[],PanelRequest|array|null $request=null): PanelPageResult { $request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('operations.view',$request,['criteria'=>$options['criteria']??[]],$options);return$denial??PanelPlatformTemplate::operations($store->all(is_array($options['criteria']??null)?$options['criteria']:[],(int)($options['limit']??100),(int)($options['offset']??0)),$options); }
	public function operate(PanelOperationControl $control,PanelRequest|array $request,array $options=[]): PanelPageResult {
		$request=$request instanceof PanelRequest?$request:PanelRequest::fromArray($request); $method=strtoupper($request->method());
		if($method!=='POST'){return $this->errorResponse($request,'method_not_allowed',405,'This endpoint requires POST.',null,[],['Allow'=>'POST']);}
		$id=trim((string)$request->input('id',''));$operation=strtolower(trim((string)$request->input('operation','')));$denial=$this->guard('operations.'.$operation,$request,['id'=>$id,'operation'=>$operation],$options);if($denial!==null){return $denial;}
		if($id===''||!in_array($operation,['pause','resume','cancel','retry'],true)){return $this->errorResponse($request,'invalid_operation_request',422,'A valid operation id and command are required.');}
		try{$record=match($operation){'pause'=>$control->pause($id),'resume'=>$control->resume($id),'cancel'=>$control->cancel($id),'retry'=>$control->retry($id,(int)$request->input('delay_seconds',0))};return PanelPageResult::json(['ok'=>true,'status'=>'accepted','operation'=>$record->jsonSerialize()]);}catch(\OutOfBoundsException $exception){return $this->errorResponse($request,'not_found',404,'The operation was not found.',$exception,['domain'=>'operations','operation'=>$operation,'id'=>$id]);}catch(\Throwable $exception){return $this->errorResponse($request,'conflict',409,'The operation could not be completed because its state changed.',$exception,['domain'=>'operations','operation'=>$operation,'id'=>$id]);}
	}

	public function relations(PanelRelationWorkspace $workspace,array $options=[],PanelRequest|array|null $request=null): PanelPageResult { $request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('relations.view',$request,['workspace'=>$workspace::class],$options);return$denial??PanelPlatformTemplate::relations($workspace,$options); }
	public function workflows(iterable $workflows,array $options=[],PanelRequest|array|null $request=null):PanelPageResult{$request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('workflows.view',$request,[],$options);return$denial??PanelPlatformTemplate::workflows($workflows,$options);}
	public function automation(AutomationRegistry $registry,iterable $receipts=[],array $options=[],PanelRequest|array|null $request=null):PanelPageResult{$request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('automation.view',$request,[],$options);return$denial??PanelPlatformTemplate::automation($registry,$receipts,$options);}
	public function relate(PanelRelationWorkspace $workspace,PanelRequest|array $request,array $options=[]): PanelPageResult {
		$request=$request instanceof PanelRequest?$request:PanelRequest::fromArray($request);if(strtoupper($request->method())!=='POST'){return $this->errorResponse($request,'method_not_allowed',405,'This endpoint requires POST.',null,[],['Allow'=>'POST']);}
		$operation=strtolower(trim((string)$request->input('operation','')));$keys=$request->input('related_ids',$request->input('related_id',$request->input('id',[])));$keys=is_array($keys)?$keys:[$keys];$values=$request->input('pivot',[]);$values=is_array($values)?$values:[];
		$denial=$this->guard('relations.'.$operation,$request,['operation'=>$operation,'keys'=>$keys],$options);if($denial!==null){return $denial;}
		try{$command=PanelRelationWorkspaceCommand::make($operation,$keys,$values,['expected_version'=>$request->input('version'),'idempotency_key'=>(string)$request->input('idempotency_key',''),'actor'=>self::actor($request)]);$result=$workspace->execute($command);$status=$result->ok()?200:match($result->status()){'conflict'=>409,'denied'=>403,'failed'=>422,default=>400};return PanelPageResult::json($result->jsonSerialize(),$status);}catch(\Throwable $exception){return $this->errorResponse($request,'invalid_relation_request',422,'The relation request is invalid.',$exception,['domain'=>'relations','operation'=>$operation]);}
	}

	public function workflow(WorkflowEngine $engine,PanelRequest|array $request,array $options=[]):PanelPageResult{
		$request=$request instanceof PanelRequest?$request:PanelRequest::fromArray($request);if(strtoupper($request->method())!=='POST'){return $this->errorResponse($request,'method_not_allowed',405,'This endpoint requires POST.',null,[],['Allow'=>'POST']);}
		$definition=trim((string)$request->input('definition',''));$id=trim((string)$request->input('id',''));$operation=strtolower(trim((string)$request->input('operation','transition')));$actorData=$request->user();if(is_object($actorData)){ $actorData=['id'=>self::actor($request),'roles'=>(array)($actorData->roles??[]),'permissions'=>(array)($actorData->permissions??[])]; }elseif(!is_array($actorData)){ $actorData=(string)($actorData??''); }
		$denial=$this->guard('workflows.'.$operation,$request,['definition'=>$definition,'id'=>$id],$options);if($denial!==null){return $denial;}if($definition===''||$id===''||self::actor($request)===null){return $this->errorResponse($request,'invalid_workflow_request',422,'Definition, workflow id, and actor are required.');}
		$data=$request->input('data',[]);$data=is_array($data)?$data:[];$version=$request->input('version');$version=$version===null?null:(int)$version;$idempotency=trim((string)$request->input('idempotency_key',''))?:null;
		try{$result=match($operation){
			'start'=>$engine->start($definition,$id,$data,$actorData,$idempotency,is_array($request->input('metadata',[]))?$request->input('metadata',[]):[]),
			'approve'=>$engine->approve($definition,$id,$actorData,(string)$request->input('comment',''),$version,$idempotency),
			'reject'=>$engine->reject($definition,$id,$actorData,(string)$request->input('comment',''),$version,$idempotency),
			'draft'=>$engine->saveDraft($definition,$id,$data,$actorData,$version,$idempotency),
			'assign'=>$engine->assign($definition,$id,($assigned=trim((string)$request->input('assigned_to','')))!==''?$assigned:null,$request->input('roles',[]),$actorData,$version,$idempotency),
			'rollback'=>$engine->rollback($definition,$id,$actorData,($event=trim((string)$request->input('event_id','')))!==''?$event:null,(string)$request->input('reason',''),$version,$idempotency),
			'check_sla'=>$engine->checkSla($definition,$id,$actorData),
			default=>$engine->transition($definition,$id,(string)$request->input('transition',''),$data,$actorData,$version,$idempotency),
		};return PanelPageResult::json($result->jsonSerialize(),$result->ok()?200:match($result->code()){'not_found'=>404,'conflict'=>409,'denied','forbidden'=>403,default=>422});}catch(\Throwable $exception){return $this->errorResponse($request,'invalid_workflow_request',422,'The workflow request could not be completed.',$exception,['domain'=>'workflows','operation'=>$operation,'definition'=>$definition,'id'=>$id]);}
	}

	public function automate(AutomationExecutor $executor,string $action,PanelRequest|array $request,array $options=[]):PanelPageResult{
		$request=$request instanceof PanelRequest?$request:PanelRequest::fromArray($request);if(strtoupper($request->method())!=='POST'){return $this->errorResponse($request,'method_not_allowed',405,'This endpoint requires POST.',null,[],['Allow'=>'POST']);}$denial=$this->guard('automation.'.$action,$request,['action'=>$action],$options);if($denial!==null){return $denial;}$actor=self::actor($request);if($actor===null){return $this->errorResponse($request,'invalid_automation_request',422,'An actor is required.');}
		$user=$request->user();$actorData=is_array($user)?['id'=>$actor,'roles'=>is_array($user['roles']??null)?$user['roles']:[],'permissions'=>is_array($user['permissions']??null)?$user['permissions']:[]]:$actor;$input=$request->input('data',$request->input('input',[]));$input=is_array($input)?$input:[];$execution=new AutomationExecutionRequest($input,$actorData,($key=trim((string)$request->input('idempotency_key','')))!==''?$key:null,$request->input('dry_run',false)===true||$request->input('dry_run')==='1',$request->input('confirmed',false)===true||$request->input('confirmed')==='1',($phrase=trim((string)$request->input('confirmation_phrase','')))!==''?$phrase:null,is_array($request->input('context',[]))?$request->input('context',[]):[]);
		try{$result=strtolower((string)$request->input('operation','execute'))==='rollback'?$executor->rollback((string)$request->input('receipt_id',''),$execution):($execution->dryRun()?$executor->plan($action,$execution):$executor->execute($action,$execution));$payload=$result->jsonSerialize();return PanelPageResult::json($payload,($payload['ok']??false)?200:match($payload['code']??''){'not_found'=>404,'denied','confirmation_required'=>403,'conflict'=>409,default=>422});}catch(\Throwable $exception){return $this->errorResponse($request,'invalid_automation_request',422,'The automation request could not be completed.',$exception,['domain'=>'automation','action'=>$action]);}
	}

	public function notifications(PanelNotificationInbox|array $inbox,array $options=[],PanelRequest|array|null $request=null): PanelPageResult { $request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('notifications.view',$request,['recipient'=>$options['recipient']??null],$options);return$denial??PanelPlatformTemplate::notifications($inbox,$options); }
	public function notify(PanelNotificationInbox $inbox,PanelRequest|array $request,array $options=[]): PanelPageResult {
		$request=$request instanceof PanelRequest?$request:PanelRequest::fromArray($request);if(strtoupper($request->method())!=='POST'){return $this->errorResponse($request,'method_not_allowed',405,'This endpoint requires POST.',null,[],['Allow'=>'POST']);}
		$id=trim((string)$request->input('id',''));$operation=strtolower(trim((string)$request->input('operation','')));$denial=$this->guard('notifications.'.$operation,$request,['id'=>$id],$options);if($denial!==null){return $denial;}
		if($id===''||!in_array($operation,['read','unread','dismiss','restore'],true)){return $this->errorResponse($request,'invalid_notification_request',422,'A valid notification id and operation are required.');}
		$changed=match($operation){'read'=>$inbox->markRead($id),'unread'=>$inbox->markUnread($id),'dismiss'=>$inbox->dismiss($id),'restore'=>$inbox->restore($id)};return PanelPageResult::json(['ok'=>$changed,'status'=>$changed?'committed':'not_found','counts'=>$inbox->counts()],$changed?200:404);
	}

	public function media(array|\JsonSerializable $library,array $options=[],PanelRequest|array|null $request=null): PanelPageResult { $request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('media.view',$request,[],$options);return$denial??PanelPlatformTemplate::media($library,$options); }
	public function packages(PanelPackageRegistryCatalog $catalog,array $options=[],PanelRequest|array|null $request=null):PanelPageResult{
		$request=self::readRequest($request??($options['request']??null));
		$denial=$this->readGuard('packages.view',$request,['registry'=>$catalog->toArray()['registry']??null],$options);if($denial!==null){return$denial;}
		try{
			$configuredFilters=is_array($options['filters']??null)?$options['filters']:[];
			$filters=[];
			foreach(['status','type','publisher','tag','category','capability']as$name){
				$filters[$name]=self::packageFilterValues($request->query($name,$configuredFilters[$name]??[]));
			}
			foreach(['include_yanked','include_revoked','include_blocked','all_versions']as$name){
				$filters[$name]=self::packageQueryBoolean($request->query($name,$configuredFilters[$name]??false));
			}
			$query=self::packageQueryString($request->query('q',$options['query']??''),'q',256);
			$cursor=self::packageQueryString($request->query('cursor',$options['cursor']??''),'cursor',1024);
			$limit=self::packageQueryLimit($request->query('limit',$options['limit']??24));
			$page=$catalog->search($query,$filters,$cursor!==''?$cursor:null,$limit);
			return PanelPlatformTemplate::packages($catalog,$page,$options);
		}catch(\Throwable$exception){
			return$this->errorResponse($request,'invalid_package_catalog_request',422,'The package catalog request is invalid.',$exception,['domain'=>'packages']);
		}
	}
	public function preferences(PanelWorkspacePreferences $workspace,array $options=[],PanelRequest|array|null $request=null):PanelPageResult{$request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('preferences.view',$request,['profile'=>$workspace->manifest()['profile']??'default'],$options);return$denial??PanelPlatformTemplate::preferences($workspace,$options);}
	public function prefer(PanelWorkspacePreferences $workspace,PanelRequest|array $request,array $options=[]):PanelPageResult{
		$request=$request instanceof PanelRequest?$request:PanelRequest::fromArray($request);if(strtoupper($request->method())!=='POST'){return $this->errorResponse($request,'method_not_allowed',405,'This endpoint requires POST.',null,[],['Allow'=>'POST']);}
		$operation=strtolower(trim((string)$request->input('operation','')));$allowed=['appearance','save_table_view','delete_table_view','touch_recent','pin','unpin','notifications','device_overrides','import'];$denial=$this->guard('preferences.'.$operation,$request,['operation'=>$operation,'profile'=>$workspace->manifest()['profile']??'default'],$options);if($denial!==null){return$denial;}
		if(!in_array($operation,$allowed,true)){return $this->errorResponse($request,'invalid_preference_request',422,'A valid preference operation is required.');}
		$expected=self::optionalInt($request->input('expected_revision'));$strategy=(string)$request->input('strategy','reject');
		try{
			$result=match($operation){
				'appearance'=>$workspace->appearance((string)$request->input('theme','default'),(string)$request->input('density','normal'),(string)$request->input('locale','en'),(string)$request->input('direction','auto'),$expected,$strategy),
				'save_table_view'=>$workspace->saveTableView((string)$request->input('resource',''),(string)$request->input('name','default'),self::arrayInput($request,'configuration'),$expected,$strategy),
				'delete_table_view'=>$workspace->deleteTableView((string)$request->input('resource',''),(string)$request->input('name','default'),$expected,$strategy),
				'touch_recent'=>$workspace->touchRecent((string)$request->input('type','resource'),(string)$request->input('id',''),self::arrayInput($request,'meta'),(int)$request->input('limit',30)),
				'pin'=>$workspace->pin((string)$request->input('type','resource'),(string)$request->input('id',''),self::arrayInput($request,'meta')),
				'unpin'=>$workspace->unpin((string)$request->input('type','resource'),(string)$request->input('id','')),
				'notifications'=>$workspace->notifications(self::arrayInput($request,'preferences'),$expected,$strategy),
				'device_overrides'=>$workspace->deviceOverrides((string)$request->input('device',''),self::arrayInput($request,'overrides'),$expected,$strategy),
				'import'=>$workspace->import(self::arrayInput($request,'payload'),$strategy),
			};
			$payload=$result instanceof \JsonSerializable?$result->jsonSerialize():array_map(static fn(mixed$item):mixed=>$item instanceof \JsonSerializable?$item->jsonSerialize():$item,(array)$result);
			return PanelPageResult::json(['ok'=>true,'status'=>'committed','preferences'=>$payload]);
		}catch(PanelPreferenceConflictException$exception){return PanelPageResult::json(['ok'=>false,'status'=>'conflict','conflict'=>$exception->jsonSerialize()],409);}catch(\Throwable$exception){return $this->errorResponse($request,'invalid_preference_request',422,'The preference request could not be completed.',$exception,['domain'=>'preferences','operation'=>$operation]);}
	}
	public function collaboration(PanelCollaborationManager $manager,array $options=[],PanelRequest|array|null $request=null):PanelPageResult{$request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('collaboration.view',$request,[],$options);return$denial??PanelPlatformTemplate::collaboration($manager,$options);}
	public function collaborate(PanelCollaborationManager $manager,PanelRequest|array $request,array $options=[]):PanelPageResult{
		$request=$request instanceof PanelRequest?$request:PanelRequest::fromArray($request);if(strtoupper($request->method())!=='POST'){return $this->errorResponse($request,'method_not_allowed',405,'This endpoint requires POST.',null,[],['Allow'=>'POST']);}
		$operation=strtolower(trim((string)$request->input('operation','')));$allowed=['create_thread','set_thread_status','comment','assign','unassign','watch','unwatch','subscribe','unsubscribe','acquire_presence','heartbeat_presence','release_presence','typing','cleanup_expired'];$denial=$this->guard('collaboration.'.$operation,$request,['operation'=>$operation],$options);if($denial!==null){return$denial;}$actor=self::actor($request);if($actor===null){return $this->errorResponse($request,'invalid_collaboration_request',422,'An actor is required.');}if(!in_array($operation,$allowed,true)){return $this->errorResponse($request,'invalid_collaboration_request',422,'A valid collaboration operation is required.');}
		$subjectType=(string)$request->input('subject_type','');$subjectId=(string)$request->input('subject_id','');
		try{
			$result=match($operation){
				'create_thread'=>$manager->createThread($actor,$subjectType,$subjectId,(string)$request->input('title',''),self::arrayInput($request,'meta'),self::optionalString($request->input('id'))),
				'set_thread_status'=>$manager->setThreadStatus((string)$request->input('thread_id',$request->input('id','')),(string)$request->input('status','open'),$actor),
				'comment'=>$manager->comment((string)$request->input('thread_id',''),$actor,(string)$request->input('body',''),self::listInput($request,'mentions'),self::arrayInput($request,'meta')),
				'assign'=>$manager->assign($subjectType,$subjectId,(string)$request->input('assignee',''),$actor,self::arrayInput($request,'meta')),
				'unassign'=>$manager->unassign($subjectType,$subjectId,$actor),
				'watch'=>$manager->watch($subjectType,$subjectId,(string)$request->input('user_id',$actor),self::arrayInput($request,'meta')),
				'unwatch'=>$manager->unwatch($subjectType,$subjectId,(string)$request->input('user_id',$actor)),
				'subscribe'=>$manager->subscribe((string)$request->input('topic',''),(string)$request->input('user_id',$actor),self::listInput($request,'channels'),(string)$request->input('mode','immediate')),
				'unsubscribe'=>$manager->unsubscribe((string)$request->input('topic',''),(string)$request->input('user_id',$actor)),
				'acquire_presence'=>$manager->acquirePresence((string)$request->input('scope',''),(string)$request->input('user_id',$actor),(int)$request->input('ttl_seconds',60),self::arrayInput($request,'meta')),
				'heartbeat_presence'=>$manager->heartbeatPresence((string)$request->input('scope',''),(string)$request->input('user_id',$actor),(string)$request->input('lease_token',''),(int)$request->input('ttl_seconds',60)),
				'release_presence'=>$manager->releasePresence((string)$request->input('scope',''),(string)$request->input('user_id',$actor),(string)$request->input('lease_token','')),
				'typing'=>$manager->typing((string)$request->input('thread_id',''),(string)$request->input('user_id',$actor),self::booleanInput($request->input('typing',true)),(int)$request->input('ttl_seconds',12)),
				'cleanup_expired'=>$manager->cleanupExpired(self::optionalInt($request->input('at'))),
			};
			return PanelPageResult::json(['ok'=>true,'status'=>'committed','result'=>$result instanceof \JsonSerializable?$result->jsonSerialize():$result,'cursor'=>$manager->cursor()]);
		}catch(PanelCollaborationPolicyException$exception){return $this->errorResponse($request,'denied',403,'The collaboration operation is not authorized.',$exception,['domain'=>'collaboration','operation'=>$operation]);}catch(\UnexpectedValueException$exception){return $this->errorResponse($request,'conflict',409,'The collaboration state changed before the request completed.',$exception,['domain'=>'collaboration','operation'=>$operation]);}catch(\Throwable$exception){return $this->errorResponse($request,'invalid_collaboration_request',422,'The collaboration request could not be completed.',$exception,['domain'=>'collaboration','operation'=>$operation]);}
	}
	public function security(array $reports,array $options=[],PanelRequest|array|null $request=null): PanelPageResult { $request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('security.view',$request,['report_count'=>count($reports)],$options);return$denial??PanelPlatformTemplate::security($reports,$options); }
	public function authentication(PanelAuthenticationManager $manager,string|int $userId,array $options=[],PanelRequest|array|null $request=null):PanelPageResult{
		$request=self::readRequest($request??($options['request']??null));$target=trim((string)$userId);$subject=['user_id'=>$target,'target'=>$target,'owner'=>$target,'id'=>null];
		$denial=$this->readGuard('authentication.view',$request,$subject,$options);if($denial!==null){return$denial;}
		$access=$this->authenticationAccess($request,$target,$subject,'read.authorization');if($access instanceof PanelPageResult){return$access;}
		return PanelPlatformTemplate::authentication($manager,$target,$options,$access);
	}
	public function authenticate(PanelAuthenticationManager $manager,PanelRequest|array $request,array $options=[]):PanelPageResult{
		$request=$request instanceof PanelRequest?$request:PanelRequest::fromArray($request);if(strtoupper($request->method())!=='POST'){return $this->errorResponse($request,'method_not_allowed',405,'This endpoint requires POST.',null,[],['Allow'=>'POST']);}
		$requestedUser=$request->input('user_id',null);if($requestedUser===null){$requestedUser=$options['user_id']??self::actor($request);if(is_callable($requestedUser)){try{$requestedUser=$requestedUser($request);}catch(\Throwable){$requestedUser=null;}}}$userId=is_scalar($requestedUser)?trim((string)$requestedUser):'';$operation=strtolower(trim((string)$request->input('operation','')));
		$id=trim((string)$request->input('id',''));$subjectId=match($operation){'begin_challenge'=>trim((string)$request->input('session_id','')),'revoke_all_sessions'=>trim((string)$request->input('except','')),default=>$id};$resource=match($operation){'confirm_totp','disable_totp'=>'factor','begin_challenge','revoke_all_sessions'=>'session','verify_challenge','cancel_challenge'=>'challenge','revoke_device'=>'device','revoke_session'=>'session',default=>null};$supported=['provision_totp','confirm_totp','verify_totp','use_recovery','disable_totp','begin_challenge','verify_challenge','cancel_challenge','revoke_device','revoke_all_devices','revoke_session','revoke_all_sessions'];$owner=$resource!==null&&$subjectId!==''?$manager->ownerOf($resource,$subjectId):(in_array($operation,$supported,true)?$userId:null);$subject=['user_id'=>$userId,'operation'=>$operation,'target'=>$userId,'owner'=>$owner,'id'=>$subjectId!==''?$subjectId:null];
		$denial=$this->guard('authentication.'.$operation,$request,$subject,$options);if($denial!==null){return$denial;}$access=$this->authenticationAccess($request,$userId,$subject,'mutation.authorization');if($access instanceof PanelPageResult){return$access;}if($userId===''||$operation===''){return $this->errorResponse($request,'invalid_authentication_request',422,'A user and authentication operation are required.');}$auth=$manager->scoped($access);$scopedOptions=array_replace($options,['user_id'=>$userId]);
		try{return match($operation){
			'provision_totp'=>PanelPlatformTemplate::authenticationEnrollment($auth->provisionTotp((string)$request->input('label','Authenticator'),['issuer'=>(string)$request->input('issuer','Dataphyre Panel'),'account'=>(string)$request->input('account',$userId),'recovery_codes'=>(int)$request->input('recovery_codes',10)]),$scopedOptions),
			'confirm_totp'=>self::authenticationDecision($auth->confirmTotp((string)$request->input('id',''),(string)$request->input('code',''),time())),
			'verify_totp'=>self::authenticationDecision($auth->verifyTotp((string)$request->input('code',''),time())),
			'use_recovery'=>self::authenticationDecision($auth->useRecoveryCode((string)$request->input('code',''))),
			'disable_totp'=>PanelPageResult::json(['ok'=>$auth->disableTotp((string)$request->input('id','')),'status'=>'committed']),
			'begin_challenge'=>PanelPageResult::json(['ok'=>true,'challenge'=>$auth->beginChallenge((string)$request->input('purpose','Sensitive operation'),(string)$request->input('method','totp'),['recipient'=>$request->input('recipient'),'session_id'=>$request->input('session_id'),'required_level'=>(int)$request->input('required_level',2)])->jsonSerialize()]),
			'verify_challenge'=>self::authenticationDecision($auth->verifyChallenge((string)$request->input('id',''),(string)$request->input('code',''))),
			'cancel_challenge'=>PanelPageResult::json(['ok'=>$auth->cancelChallenge((string)$request->input('id','')),'status'=>'committed']),
			'revoke_device'=>PanelPageResult::json(['ok'=>$auth->revokeDevice((string)$request->input('id','')),'status'=>'committed']),
			'revoke_all_devices'=>PanelPageResult::json(['ok'=>true,'status'=>'committed','revoked'=>$auth->revokeAllDevices()]),
			'revoke_session'=>PanelPageResult::json(['ok'=>$auth->revokeSession((string)$request->input('id','')),'status'=>'committed']),
			'revoke_all_sessions'=>PanelPageResult::json(['ok'=>true,'status'=>'committed','revoked'=>$auth->revokeAllSessions(($except=trim((string)$request->input('except','')))!==''?$except:null)]),
			default=>$this->errorResponse($request,'invalid_authentication_request',422,'The authentication operation is not supported.'),
		};}catch(\Throwable $exception){return $this->errorResponse($request,'invalid_authentication_request',422,'The authentication request could not be completed.',$exception,['domain'=>'authentication','operation'=>$operation,'user_id'=>$userId]);}
	}
	public function developer(PanelManifestInspector $inspection,?PanelManifestDiff $diff=null,?PanelQualityMatrix $matrix=null,array $options=[],PanelRequest|array|null $request=null): PanelPageResult { $request=self::readRequest($request??($options['request']??null));$denial=$this->readGuard('development.view',$request,[],$options);return$denial??PanelPlatformTemplate::developer($inspection,$diff,$matrix,$options); }

	/** @param array<string,mixed> $context @param array<string,string> $headers */
	private function errorResponse(PanelRequest $request,string $code,int $status,string $message,?\Throwable $exception=null,array $context=[],array $headers=[]):PanelPageResult{
		$result=PanelErrorEnvelope::response($code,$status,$message,$exception,$this->developmentErrorDetails,$context,(string)$request->header('x-correlation-id',''),$headers);
		PanelTrace::record('platform.error',[
			'code'=>$code,
			'http_status'=>$status,
			'correlation_id'=>$result->data()['correlation_id']??null,
			'exception'=>$exception,
			'request'=>$request,
			'context'=>$context,
		]);
		return$result;
	}
	private function readGuard(string $ability,PanelRequest $request,array $subject,array $options):?PanelPageResult{
		if(!in_array(strtoupper($request->method()),['GET','HEAD'],true)){return$this->errorResponse($request,'method_not_allowed',405,'This endpoint requires GET or HEAD.',null,['ability'=>$ability],['Allow'=>'GET, HEAD']);}
		$context=$this->securityContext($request);$decision=$this->authorizationDecision($ability,$request,$subject,$context);
		if(!$this->audit('read.authorization',$context,$ability,$subject,$decision->allowed(),['decision'=>['allowed'=>$decision->allowed(),'ability'=>$ability,'reason_count'=>count($decision->reasons()),'requirements'=>$decision->requirements()]])){return$this->errorResponse($request,'security_audit_failed',503,'The security decision could not be recorded.',null,['ability'=>$ability]);}
		if($context===null){return$this->errorResponse($request,'unauthenticated',401,'An authenticated security context is required.',null,['ability'=>$ability]);}
		return$decision->allowed()?null:$this->errorResponse($request,'denied',403,'Operation is not authorized.',null,['ability'=>$ability,'decision_reasons'=>$decision->reasons()]);
	}

	private function guard(string $ability,PanelRequest $request,array $subject,array $options): ?PanelPageResult {
		$context=$this->securityContext($request);
		try{$csrfValid=$this->csrfValidator!==null&&($this->csrfValidator)($request,$ability)===true;}catch(\Throwable){$csrfValid=false;}
		if(!$csrfValid){$this->audit('mutation.csrf',$context,$ability,$subject,false,['reason'=>'csrf_failed']);return $this->errorResponse($request,'csrf_failed',419,'The request security token is missing or invalid.',null,['ability'=>$ability]);}
		$decision=$this->authorizationDecision($ability,$request,$subject,$context);
		if(!$this->audit('mutation.authorization',$context,$ability,$subject,$decision->allowed(),['decision'=>['allowed'=>$decision->allowed(),'ability'=>$ability,'reason_count'=>count($decision->reasons()),'requirements'=>$decision->requirements()]])){return $this->errorResponse($request,'security_audit_failed',503,'The security decision could not be recorded.',null,['ability'=>$ability]);}
		if($decision->allowed()){return null;}
		return $this->errorResponse($request,'denied',403,'Operation is not authorized.',null,['ability'=>$ability,'decision_reasons'=>$decision->reasons()]);
	}
	private function authorizationDecision(string $ability,PanelRequest $request,array $subject,?PanelSecurityContext $context):PanelSecurityDecision{
		try{
			if($context===null){return self::decision($ability,false,'An authenticated security context is required.');}
			if($request->tenantKey()!==null&&$context->tenantId()!==$request->tenantKey()){return self::decision($ability,false,'Tenant boundary does not match.');}
			if($this->authorizer!==null){return self::decision($ability,($this->authorizer)($ability,$request->user(),$subject,$request));}
			if(!$this->securityBoundaryEnabled){return self::decision($ability,false,'Authorization is not configured.');}
			$policy=$this->securityPolicyResolver!==null?($this->securityPolicyResolver)($ability,$context,$subject,$request):PanelSecurityPolicy::make($ability)->permissions($ability);
			if(!$policy instanceof PanelSecurityPolicy){return self::decision($ability,false,'Authorization policy is unavailable.');}
			return $policy->evaluate($context,$subject);
		}catch(\Throwable){return self::decision($ability,false,'Operation is not authorized.');}
	}
	/** Creates an owner-bound authentication scope and independently audits cross-user elevation. */
	private function authenticationAccess(PanelRequest $request,string $target,array $subject,string $auditType):PanelAuthenticationAccess|PanelPageResult{
		$context=$this->securityContext($request);if($context===null){return$this->errorResponse($request,'unauthenticated',401,'An authenticated security context is required.',null,['ability'=>PanelAuthenticationAccess::CROSS_USER_ABILITY]);}$actor=$context->actorId();
		try{if(hash_equals($actor,$target)){return PanelAuthenticationAccess::self($actor);}$ability=PanelAuthenticationAccess::CROSS_USER_ABILITY;$decision=$this->authorizationDecision($ability,$request,$subject,$context);if(!$this->audit($auditType,$context,$ability,$subject,$decision->allowed(),['scope'=>'cross_user','decision'=>['allowed'=>$decision->allowed(),'ability'=>$ability,'reason_count'=>count($decision->reasons()),'requirements'=>$decision->requirements()]])){return$this->errorResponse($request,'security_audit_failed',503,'The security decision could not be recorded.',null,['ability'=>$ability]);}if(!$decision->allowed()){return$this->errorResponse($request,'denied',403,'Operation is not authorized.',null,['ability'=>$ability,'decision_reasons'=>$decision->reasons()]);}return PanelAuthenticationAccess::elevated($actor,$target,$decision);}catch(\Throwable$exception){return$this->errorResponse($request,'invalid_authentication_request',422,'The authentication request could not be completed.',$exception,['domain'=>'authentication','target'=>$target]);}
	}
	private function securityContext(PanelRequest $request):?PanelSecurityContext{
		try{
			if($this->securityContextResolver!==null){$resolved=($this->securityContextResolver)($request->user(),$request);return self::context($resolved);}
			return self::context($request->user());
		}catch(\Throwable){return null;}
	}
	private function audit(string $type,?PanelSecurityContext $context,string $ability,array $subject,bool $allowed,array $metadata=[]):bool{
		if($this->securityAudit===null){return true;}
		try{$this->securityAudit->record($type,$context??['actor_id'=>null],['ability'=>$ability,'allowed'=>$allowed,'subject'=>$subject],$metadata);return true;}catch(\Throwable){return false;}
	}
	private static function context(mixed $value):?PanelSecurityContext{
		if($value instanceof PanelSecurityContext){return$value;}
		if(is_scalar($value)&&trim((string)$value)!==''){return PanelSecurityContext::make((string)$value);}
		if(is_object($value)){$value=$value instanceof \JsonSerializable?$value->jsonSerialize():get_object_vars($value);}
		if(!is_array($value)){return null;}$actor=$value['actor_id']??$value['id']??$value['user_id']??$value['key']??null;if(!is_scalar($actor)||trim((string)$actor)===''){return null;}$value['actor_id']=(string)$actor;return PanelSecurityContext::fromArray($value);
	}
	private static function decision(string $ability,mixed $result,?string $fallback=null):PanelSecurityDecision{
		if($result instanceof PanelSecurityDecision){return$result;}
		if($result===true){return new PanelSecurityDecision(true,$ability);}
		$reasons=is_array($result)?$result:[];if($reasons===[]){$reason=is_string($result)?trim($result):'';$reasons=[$reason!==''?$reason:($fallback??'Operation is not authorized.')];}
		return new PanelSecurityDecision(false,$ability,array_values(array_filter(array_map(static fn(mixed $reason):string=>trim((string)$reason),$reasons),static fn(string $reason):bool=>$reason!==''))?:['Operation is not authorized.']);
	}
	private static function actor(PanelRequest $request): ?string { $user=$request->user();if(is_scalar($user)){return trim((string)$user)?:null;}if(is_array($user)){foreach(['id','key','user_id']as$key){if(isset($user[$key])&&is_scalar($user[$key])){return(string)$user[$key];}}}if(is_object($user)){foreach(['id','getId','key']as$member){if(isset($user->{$member})&&is_scalar($user->{$member})){return(string)$user->{$member};}if(method_exists($user,$member)){return(string)$user->{$member}();}}}return null;}
	private static function readRequest(PanelRequest|array|null $request):PanelRequest{return$request instanceof PanelRequest?$request:PanelRequest::fromArray(is_array($request)?$request:['method'=>'GET']);}
	private static function authenticationDecision(PanelAuthenticationDecision $decision):PanelPageResult{return PanelPageResult::json($decision->jsonSerialize(),$decision->verified()?200:422);}
	private static function arrayInput(PanelRequest$request,string$key):array{$value=$request->input($key,[]);if(is_array($value)){return$value;}if(is_string($value)&&$value!==''){$decoded=json_decode($value,true);if(is_array($decoded)){return$decoded;}}return[];}
	private static function listInput(PanelRequest$request,string$key):array{$value=$request->input($key,[]);if(is_string($value)){$decoded=json_decode($value,true);$value=is_array($decoded)?$decoded:preg_split('/\s*,\s*/',$value,-1,PREG_SPLIT_NO_EMPTY);}return array_values(array_unique(array_filter(array_map(static fn(mixed$item):string=>trim((string)$item),is_array($value)?$value:[]),static fn(string$item):bool=>$item!=='')));}
	/** @param array<string,mixed> $options @return array<string,mixed> */
	private static function operationsConsoleSnapshotOptions(PanelRequest$request,array$options):array{
		$snapshot=is_array($options['snapshot']??null)?$options['snapshot']:[];$snapshot['limit']=(int)$request->query('limit',$snapshot['limit']??$options['limit']??PanelOperationsConsole::DEFAULT_LIMIT);$snapshot['event_cursor']=(int)$request->query('event_cursor',$snapshot['event_cursor']??0);$snapshot['work_cursor']=(int)$request->query('work_cursor',$snapshot['work_cursor']??0);
		$work=is_array($snapshot['work']??null)?$snapshot['work']:[];foreach(['queue','type','state','assignee','subject_type','subject_id','search']as$key){$value=$request->query($key,null);if(is_scalar($value)&&trim((string)$value)!==''){$work[$key]=(string)$value;}}
		foreach(['states','tags']as$key){$value=$request->query($key,null);if(is_array($value)){$work[$key]=array_values(array_map('strval',$value));}elseif(is_string($value)&&trim($value)!==''){$work[$key]=preg_split('/\s*,\s*/',$value,-1,PREG_SPLIT_NO_EMPTY)?:[];}}
		if($request->query('overdue',null)!==null){$work['overdue']=self::booleanInput($request->query('overdue'));}$snapshot['work']=$work;return$snapshot;
	}
	private static function intelligencePolicyRequest(PanelSecurityContext$context,string$tenantId,PanelRequest$request):PanelPolicyRequest{
		$risk=strtolower(trim((string)$request->input('risk','medium')));if(!in_array($risk,['low','medium','high','critical'],true)){$risk='medium';}
		return new PanelPolicyRequest($context->actorId(),'intelligence.console',$tenantId,null,null,$risk,$context->roles(),$context->permissions(),['source'=>'operations_os.console','mfa_level'=>$context->mfaLevel(),'trusted_session'=>$context->trustedSession(),'dry_run'=>self::booleanInput($request->input('dry_run',false)),'cost_micros'=>max(0,(int)$request->input('cost_micros',0))]);
	}
	private static function allowedConsoleCommand(string$command,mixed$patterns):bool{
		$patterns=is_array($patterns)&&$patterns!==[]?$patterns:['operations_os.*'];$command=strtolower(trim($command));
		foreach($patterns as$pattern){$pattern=strtolower(trim((string)$pattern));if($pattern==='*'||$pattern===$command||(str_ends_with($pattern,'.*')&&str_starts_with($command,substr($pattern,0,-1)))){return true;}}return false;
	}
	private static function optionalInt(mixed$value):?int{return$value===null||$value===''?null:(int)$value;}
	private static function optionalString(mixed$value):?string{$value=trim((string)($value??''));return$value!==''?$value:null;}
	private static function booleanInput(mixed$value):bool{return filter_var($value,FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE)??false;}
	/** @return list<string> */
	private static function packageFilterValues(mixed$value):array{
		if($value===null||$value===''){return[];}
		if(is_string($value)){$value=preg_split('/\s*,\s*/',$value,-1,PREG_SPLIT_NO_EMPTY)?:[];}
		if(!is_array($value)||!array_is_list($value)||count($value)>64){throw new \InvalidArgumentException('Package catalog filters must be bounded lists.');}
		$values=[];
		foreach($value as$item){
			if(!is_scalar($item)){throw new \InvalidArgumentException('Package catalog filter values must be scalar.');}
			$item=Resource::normalizeName((string)$item);
			if($item===''||strlen($item)>128){throw new \InvalidArgumentException('Package catalog filter values must be canonical names.');}
			$values[$item]=true;
		}
		$result=array_keys($values);sort($result,SORT_STRING);return$result;
	}
	private static function packageQueryBoolean(mixed$value):bool{
		if($value===null||$value===''||$value===false||$value===0||$value==='0'){return false;}
		$result=filter_var($value,FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE);
		if($result===null){throw new \InvalidArgumentException('Package catalog boolean filters are invalid.');}
		return$result;
	}
	private static function packageQueryString(mixed$value,string$name,int$maximum):string{
		if($value===null){return'';}
		if(!is_scalar($value)){throw new \InvalidArgumentException("Package catalog {$name} must be a scalar value.");}
		$value=trim((string)$value);
		if(strlen($value)>$maximum||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===1){throw new \LengthException("Package catalog {$name} is invalid.");}
		return$value;
	}
	private static function packageQueryLimit(mixed$value):int{
		if(is_int($value)){return max(1,min(100,$value));}
		if(!is_string($value)||preg_match('/^[0-9]{1,3}$/D',$value)!==1){throw new \InvalidArgumentException('Package catalog limit is invalid.');}
		return max(1,min(100,(int)$value));
	}
}
