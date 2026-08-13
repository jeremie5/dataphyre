<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelOperatorApproval;
use Dataphyre\Panel\PanelOperatorEvaluation;
use Dataphyre\Panel\PanelOperatorModel;
use Dataphyre\Panel\PanelOperatorModelAdapter;
use Dataphyre\Panel\PanelOperatorProposal;
use Dataphyre\Panel\PanelOperatorRouter;
use Dataphyre\Panel\PanelOperatorRun;
use Dataphyre\Panel\PanelOperatorRuntime;
use Dataphyre\Panel\PanelOperatorTask;
use Dataphyre\Panel\PanelPolicyBundle;
use Dataphyre\Panel\PanelPolicyControlPlane;
use Dataphyre\Panel\PanelPolicyDecision;
use Dataphyre\Panel\PanelPolicyRequest;
use Dataphyre\Panel\PanelPolicyRule;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @param array<string,mixed> $obligations */
function dp_panel_os_policy(array $obligations=[]):array{return[
	'id'=>'operator_policy','version'=>'1.0.0','rules'=>[
		'allow_operator'=>['effect'=>'allow','abilities'=>['operator.*','release.*','marketplace.*'],'priority'=>10,'when'=>['all'=>[
			['any'=>[['path'=>'actor.roles','op'=>'contains','value'=>'operator'],['path'=>'context.roles','op'=>'contains','value'=>'operator']]],
			['path'=>'risk','op'=>'not_in','value'=>['critical']],
		]],'obligations'=>$obligations,'reason'=>'Approved operator policy.'],
	],
];}

/** @param array<string,mixed> $overrides */
function dp_panel_os_task(array $overrides=[]):PanelOperatorTask{return PanelOperatorTask::from($overrides+[
	'id'=>'task:1','actor_id'=>'Actor:1','tenant_id'=>'tenant:1','instruction'=>'Review the order and prepare a reversible status update.',
	'risk'=>'high','classification'=>'internal','residency'=>'ca','allowed_tools'=>['orders.review'],'required_capabilities'=>['text','tools'],
	'max_output_tokens'=>256,'max_cost_micros'=>10000,'dry_run'=>true,'context'=>['roles'=>['operator']],
]);}

function dp_panel_os_model(string $id='model_a',string $health='ready',int $inputCost=1000):PanelOperatorModel{return new PanelOperatorModel($id,'openai','governed-model',['tools','text'],['ca','global'],['internal','public'],8192,1024,$inputCost,2000,10,$health);}

function dp_panel_os_adapter(array|Throwable $result):PanelOperatorModelAdapter{return new class($result)implements PanelOperatorModelAdapter{
	public function __construct(private array|Throwable $result){}
	public function propose(PanelOperatorTask $task,PanelOperatorModel $model,array $toolManifest):PanelOperatorProposal|array{if($this->result instanceof Throwable){throw$this->result;}return$this->result;}
};}

test('unified policy normalizes requests evaluates portable conditions and denies by default',static function(Context $t):void{
	$request=new PanelPolicyRequest(' Actor:1 ','OPERATOR.PLAN','tenant:1','operator_task','task:1','HIGH',['Operator','operator'],['Orders.*'],['score'=>9,'market'=>'ca','tags'=>['vip'],'name'=>'Avery Stone','suffix'=>'Stone','present'=>true]);
	$t->same('Actor:1',$request->actorId());$t->same('operator.plan',$request->ability());$t->same('high',$request->risk());$t->same(['operator'],$request->roles());$t->same(['orders.*'],$request->permissions());$t->isTrue($request->can('orders.review'));$t->isTrue($request->hasRole('OPERATOR'));$t->same($request->fingerprint(),PanelPolicyRequest::from($request->jsonSerialize())->fingerprint());
	$t->throws(static fn()=>new PanelPolicyRequest('actor','bad ability'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelPolicyRequest('actor','operator.plan',null,'task',null),InvalidArgumentException::class);$t->throws(static fn()=>new PanelPolicyRequest('actor','operator.plan',null,null,null,'impossible'),InvalidArgumentException::class);

	$conditions=[
		['path'=>'context.score','op'=>'eq','value'=>9],['path'=>'context.score','op'=>'neq','value'=>8],['path'=>'context.market','op'=>'in','value'=>['ca','us']],['path'=>'context.market','op'=>'not_in','value'=>['eu']],
		['path'=>'context.tags','op'=>'contains','value'=>'vip'],['path'=>'context.tags','op'=>'not_contains','value'=>'blocked'],['path'=>'context.present','op'=>'exists','value'=>true],
		['path'=>'context.score','op'=>'gt','value'=>8],['path'=>'context.score','op'=>'gte','value'=>9],['path'=>'context.score','op'=>'lt','value'=>10],['path'=>'context.score','op'=>'lte','value'=>9],
		['path'=>'context.name','op'=>'starts_with','value'=>'Avery'],['path'=>'context.suffix','op'=>'ends_with','value'=>'Stone'],['path'=>'ability','op'=>'matches','value'=>'operator.*'],
	];
	foreach($conditions as$index=>$condition){$rule=new PanelPolicyRule('condition_'.$index,'ALLOW',['OPERATOR.*'],1,$condition,[],'Condition matched.');$t->isTrue($rule->matches($request));$t->same('allow',$rule->effect());$t->same(['operator.*'],$rule->abilities());$t->same($rule->fingerprint(),$rule->jsonSerialize()['fingerprint']);}
	$compound=new PanelPolicyRule('compound','allow',['operator.*'],5,['all'=>[['path'=>'risk','op'=>'eq','value'=>'high'],['any'=>[['path'=>'context.market','op'=>'eq','value'=>'us'],['not'=>['path'=>'context.market','op'=>'eq','value'=>'eu']]]]]],['mfa_level'=>2],'Compound matched.');$t->isTrue($compound->matches($request));$t->same(2,$compound->obligations()['mfa_level']);
	$t->throws(static fn()=>new PanelPolicyRule('bad','maybe',['*']),InvalidArgumentException::class);$t->throws(static fn()=>new PanelPolicyRule('bad','allow',[]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelPolicyRule('bad','allow',['*'],0,['path'=>'x','op'=>'unknown','value'=>1]),InvalidArgumentException::class);

	$plane=new PanelPolicyControlPlane([],false);$denied=$plane->evaluate($request);$t->isFalse($denied->allowed());$t->same(0,$denied->revision());$t->throws(static fn()=>$denied->assertAllowed(),LogicException::class);
	$allow=PanelPolicyBundle::from(dp_panel_os_policy(['mfa_level'=>1,'approval_count'=>1,'confirmation'=>true,'allowed_regions'=>['ca']]));$plane->register($allow);$decision=$plane->evaluate($request);$t->isTrue($decision->allowed());$t->same(1,$decision->approvalCount());$t->isTrue($decision->requiresApproval());$t->isTrue($decision->confirmationRequired());$t->same(1,$decision->mfaLevel());$t->same($decision,$decision->assertAllowed());$t->isTrue(count($decision->trace())>0);$t->same(['operator_policy:allow_operator'],$decision->matchedRules());
	$deny=PanelPolicyBundle::from(['id'=>'deny','version'=>'1.0.0','rules'=>['deny_critical'=>['effect'=>'deny','abilities'=>['operator.*'],'priority'=>100,'when'=>['path'=>'risk','op'=>'eq','value'=>'high'],'reason'=>'Risk denied.']]]);$plane->register($deny);$blocked=$plane->evaluate($request);$t->isFalse($blocked->allowed());$t->contains('deny',strtolower(implode(' ',$blocked->reasons())));
	$plane->engage('operator.*');$t->contains('kill_switch',$plane->evaluate($request)->matchedRules()[0]);$revision=$plane->revision();$plane->engage('operator.*');$t->same($revision,$plane->revision());$plane->release('operator.*');$plane->release('missing.*');$plane->remove('deny')->remove('missing');$t->isTrue($plane->evaluate($request)->allowed());
	$t->throws(static fn()=>(new PanelPolicyControlPlane([],false,true)),InvalidArgumentException::class);$t->throws(static fn()=>$plane->engage('bad pattern'),InvalidArgumentException::class);
})->tag('panel','operations-os','policy','conditions','default-deny')->isolation('case')->maxMillis(6000);

test('signed policy bundles and checkpoints are tamper evident and portable',static function(Context $t):void{
	$key=str_repeat('p',32);$bundle=PanelPolicyBundle::from(dp_panel_os_policy(['approval_count'=>2,'max_cost_micros'=>5000,'separation_of_duties'=>true]))->sign('primary',$key);
	$t->same('operator_policy',$bundle->id());$t->same('1.0.0',$bundle->version());$t->isTrue($bundle->signed());$t->same('primary',$bundle->keyId());$t->isTrue($bundle->verify(['primary'=>$key]));$t->isFalse($bundle->verify(['primary'=>str_repeat('x',32)]));$t->same($bundle->digest(),PanelPolicyBundle::hydrate($bundle->jsonSerialize())->digest());
	$plane=new PanelPolicyControlPlane(['primary'=>$key]);$plane->register($bundle)->engage('release.*');$checkpoint=$plane->checkpoint();$restored=(new PanelPolicyControlPlane(['primary'=>$key]))->restore($checkpoint);$t->same($plane->revision(),$restored->revision());$t->same($plane->jsonSerialize()['bundle_digests'],$restored->jsonSerialize()['bundle_digests']);
	$t->throws(static fn()=>$plane->register($bundle),LogicException::class);$plane->register($bundle,true);
	$t->throws(static fn()=>(new PanelPolicyControlPlane(['primary'=>str_repeat('x',32)]))->register($bundle),LogicException::class);
	$t->throws(static fn()=>PanelPolicyBundle::from(['id'=>'empty','version'=>'1.0.0','rules'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>$bundle->sign('primary','short'),InvalidArgumentException::class);
	$bad=$bundle->jsonSerialize();$bad['digest']=str_repeat('0',64);$t->throws(static fn()=>PanelPolicyBundle::hydrate($bad),UnexpectedValueException::class);
	$badCheckpoint=$checkpoint;$badCheckpoint['kill_switches']=['bad pattern'];$t->throws(static fn()=>(new PanelPolicyControlPlane(['primary'=>$key]))->restore($badCheckpoint),InvalidArgumentException::class);
	$badCheckpoint=$checkpoint;$badCheckpoint['revision']=-1;$t->throws(static fn()=>(new PanelPolicyControlPlane(['primary'=>$key]))->restore($badCheckpoint),UnexpectedValueException::class);
	$t->throws(static fn()=>new PanelPolicyDecision(true,'bad',[],[],[],[],0),InvalidArgumentException::class);
})->tag('panel','operations-os','policy','signatures','checkpoint')->isolation('case')->maxMillis(5000);

test('operator router honors model capability residency classification health and cost constraints',static function(Context $t):void{
	$task=dp_panel_os_task();$router=new PanelOperatorRouter();$cheap=dp_panel_os_model('cheap','degraded',0);$ready=dp_panel_os_model('ready','ready',1000000);$router->register($cheap,dp_panel_os_adapter([]))->register($ready,dp_panel_os_adapter([]));
	$t->same('ready',$router->route($task)->id());$t->same($ready,$router->model('ready'));$t->instanceOf(PanelOperatorModelAdapter::class,$router->adapter('ready'));$t->isTrue($ready->supports($task));$t->isTrue($ready->estimatedCost(1000,1000)>0);$t->same(2,$router->jsonSerialize()['adapter_count']);
	$t->same('cheap',$router->route($task,['allowed_models'=>['cheap']])->id());$t->throws(static fn()=>$router->route($task,['denied_models'=>['cheap','ready']]),LogicException::class);$t->throws(static fn()=>$router->route($task,['allowed_models'=>['ready'],'max_cost_micros'=>1]),LogicException::class);
	$router->remove('cheap');$t->throws(static fn()=>$router->model('cheap'),OutOfBoundsException::class);$t->throws(static fn()=>$router->adapter('cheap'),OutOfBoundsException::class);$router->remove('missing');
	$t->throws(static fn()=>$router->register($ready,dp_panel_os_adapter([])),LogicException::class);$router->register($ready,dp_panel_os_adapter([]),true);
	$t->throws(static fn()=>new PanelOperatorModel('bad','provider','model',[],['ca'],['internal'],8192,100,0,0),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelOperatorModel('bad','provider','model',['text'],['ca'],['internal'],100,100,0,0),InvalidArgumentException::class);
	$unavailable=dp_panel_os_model('down','unavailable');$t->isFalse($unavailable->supports($task));
	$t->same(['text','tools'],$ready->capabilities());$t->same(['ca','global'],$ready->regions());$t->same(['internal','public'],$ready->classifications());$t->same($ready->fingerprint(),$ready->jsonSerialize()['fingerprint']);
})->tag('panel','operations-os','operator','router','budgets')->isolation('case')->maxMillis(5000);

test('operator runtime plans evaluates approves confirms executes and sanitizes evidence',static function(Context $t):void{
	$key=str_repeat('p',32);$approvalKey=str_repeat('a',32);$policy=new PanelPolicyControlPlane(['primary'=>$key]);$policy->register(PanelPolicyBundle::from(dp_panel_os_policy(['approval_count'=>2,'confirmation'=>true,'separation_of_duties'=>true,'required_evaluators'=>['safety'],'max_cost_micros'=>10000]))->sign('primary',$key));
	$proposal=['summary'=>'Review the order.','steps'=>[['tool'=>'orders.review','arguments'=>['id'=>'SO:1','status'=>'review'],'rationale'=>'Move to governed review.']],'warnings'=>['Dry run only.'],'input_tokens'=>40,'output_tokens'=>20];
	$router=new PanelOperatorRouter();$model=dp_panel_os_model();$router->register($model,dp_panel_os_adapter($proposal));
	$runtime=new PanelOperatorRuntime($router,$policy,['orders.review'=>['description'=>'Review order.']],['safety'=>static fn():PanelOperatorEvaluation=>PanelOperatorEvaluation::pass('safety','Safe.', ['password'=>'hidden'])],static fn():array=>['ok'=>true,'token'=>'secret-token'],['primary'=>$approvalKey]);
	$task=dp_panel_os_task(['roles'=>['operator']]);$run=$runtime->plan($task);$t->same('awaiting_approval',$run->status());$t->isTrue($run->executable());$t->same($task,$run->task());$t->same($model,$run->model());$t->same('Review the order.',$run->proposal()?->summary());$t->same(4,count($run->evaluations()));$t->same($router,$runtime->router());$t->same($policy,$runtime->policy());
	$proposalObject=$run->proposal();$t->instanceOf(PanelOperatorProposal::class,$proposalObject);$t->same('orders.review',$proposalObject?->steps()[0]['tool']);$t->same(['Dry run only.'],$proposalObject?->warnings());$t->isTrue(($proposalObject?->estimatedCostMicros()??0)>=0);$t->same($proposalObject?->digest(),$proposalObject?->jsonSerialize()['digest']);
	$target=$run->approvalTarget();$self=PanelOperatorApproval::sign($target,'Actor:1','2026-07-16T12:00:00Z','primary',$approvalKey);$one=PanelOperatorApproval::sign($target,'Reviewer:1','2026-07-16T12:00:01Z','primary',$approvalKey);$two=PanelOperatorApproval::sign($target,'Reviewer:2','2026-07-16T12:00:02Z','primary',$approvalKey);
	$t->isTrue($one->verify(['primary'=>$approvalKey],$target));$t->isFalse($one->verify(['primary'=>$approvalKey],str_repeat('0',64)));$t->same('Reviewer:1',$one->approverId());$t->same('primary',$one->keyId());$t->same('2026-07-16T12:00:01.000000Z',$one->occurredAt());
	$t->same('Reviewer:1',$one->jsonSerialize()['approver_id']);
	$t->same('awaiting_approval',$runtime->execute($run,[$self,$one])->status());$approved=$runtime->execute($run,[$one,$two]);$t->same('awaiting_confirmation',$approved->status());$completed=$runtime->execute($run,[$one,$two],true);$t->same('completed',$completed->status());$t->isTrue($completed->output()['ok']);$t->same('[REDACTED]',$completed->output()['token']);$t->isTrue(is_string($completed->digest()));$t->same($completed->digest(),$completed->jsonSerialize()['digest']);
	$t->same('panel_operator_runtime_manifest',$runtime->jsonSerialize()['type']);$t->notContains('secret-token',json_encode($completed,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>$runtime->execute($completed),LogicException::class);
	$t->throws(static fn()=>PanelOperatorApproval::sign($target,'Reviewer','now','primary','short'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelOperatorRun($task,$run->decision(),'impossible'),InvalidArgumentException::class);
	$t->throws(static fn()=>(new PanelOperatorRun($task,$run->decision(),'denied'))->approvalTarget(),LogicException::class);
})->tag('panel','operations-os','operator','approval','execution')->isolation('case')->maxMillis(7000);

test('operator runtime fails closed at every model evaluator policy and execution boundary',static function(Context $t):void{
	$key=str_repeat('p',32);$allow=new PanelPolicyControlPlane(['primary'=>$key]);$allow->register(PanelPolicyBundle::from(dp_panel_os_policy())->sign('primary',$key));$task=dp_panel_os_task();
	$denied=(new PanelOperatorRuntime(new PanelOperatorRouter(),new PanelPolicyControlPlane(),[]))->plan($task);$t->same('denied',$denied->status());$t->isFalse($denied->executable());
	$routing=(new PanelOperatorRuntime(new PanelOperatorRouter(),$allow,[]))->plan($task);$t->same('routing_failed',$routing->status());$t->contains('failed closed',$routing->error()??'');
	$router=new PanelOperatorRouter();$router->register(dp_panel_os_model(),dp_panel_os_adapter(new RuntimeException('transport secret')));$modelFailed=(new PanelOperatorRuntime($router,$allow,['orders.review'=>[]]))->plan($task);$t->same('model_failed',$modelFailed->status());$t->notContains('transport secret',$modelFailed->error()??'');
	$emptyRouter=new PanelOperatorRouter();$emptyRouter->register(dp_panel_os_model(),dp_panel_os_adapter(['summary'=>'No work','steps'=>[]]));$empty=(new PanelOperatorRuntime($emptyRouter,$allow,['orders.review'=>[]]))->plan($task);$t->same('evaluation_failed',$empty->status());
	$evalRouter=new PanelOperatorRouter();$evalRouter->register(dp_panel_os_model(),dp_panel_os_adapter(['steps'=>[['tool'=>'orders.review','arguments'=>[]]]]));$failedEval=(new PanelOperatorRuntime($evalRouter,$allow,['orders.review'=>[]],['safety'=>static fn():bool=>false]))->plan($task);$t->same('evaluation_failed',$failedEval->status());
	$badEval=(new PanelOperatorRuntime($evalRouter,$allow,['orders.review'=>[]],['safety'=>static function():bool{throw new RuntimeException('down');}]))->plan($task);$t->same('evaluation_failed',$badEval->status());
	$unknown=(new PanelOperatorRuntime($evalRouter,$allow,[]))->plan($task);$t->same('model_failed',$unknown->status());
	$secretRouter=new PanelOperatorRouter();$secretRouter->register(dp_panel_os_model(),dp_panel_os_adapter(['steps'=>[['tool'=>'orders.review','arguments'=>['api_key'=>'nope']]]]));$t->same('model_failed',(new PanelOperatorRuntime($secretRouter,$allow,['orders.review'=>[]]))->plan($task)->status());
	$planned=(new PanelOperatorRuntime($evalRouter,$allow,['orders.review'=>[]]))->plan($task);$t->same('planned',$planned->status());
	$noExecutor=new PanelOperatorRuntime($evalRouter,$allow,['orders.review'=>[]]);$t->same('execution_failed',$noExecutor->execute($noExecutor->plan($task))->status());
	$throwing=new PanelOperatorRuntime($evalRouter,$allow,['orders.review'=>[]],[],static function():void{throw new RuntimeException('executor secret');});$failed=$throwing->execute($throwing->plan($task));$t->same('execution_failed',$failed->status());$t->notContains('executor secret',$failed->error()??'');
	$staleRun=$planned;$allow->register(PanelPolicyBundle::from(['id'=>'unrelated','version'=>'1.0.0','rules'=>['allow'=>['effect'=>'allow','abilities'=>['unrelated']]]])->sign('primary',$key));$t->contains('Policy changed',(new PanelOperatorRuntime($evalRouter,$allow,['orders.review'=>[]],[],static fn()=>[]))->execute($staleRun)->error()??'');
	$t->throws(static fn()=>new PanelOperatorProposal('bad','model','Summary',[],[],0,0,0),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelOperatorEvaluation('bad name',true,'Message'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelOperatorRuntime($evalRouter,$allow,['bad'=>'not-map']),InvalidArgumentException::class);
})->tag('panel','operations-os','operator','fail-closed','adversarial')->isolation('case')->maxMillis(7000);
