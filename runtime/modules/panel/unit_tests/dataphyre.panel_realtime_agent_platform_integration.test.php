<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\InMemoryPanelAgentWorkflowStore;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelAgentIntentSigner;
use Dataphyre\Panel\PanelAgentPlan;
use Dataphyre\Panel\PanelAgentPolicyDecision;
use Dataphyre\Panel\PanelAgentPolicyEngine;
use Dataphyre\Panel\PanelAgentPolicyResolver;
use Dataphyre\Panel\PanelAgentRequestContext;
use Dataphyre\Panel\PanelAgentRuntime;
use Dataphyre\Panel\PanelAgentTool;
use Dataphyre\Panel\PanelAgentToolCatalog;
use Dataphyre\Panel\PanelAgentToolExecutionRequest;
use Dataphyre\Panel\PanelAgentToolExecutionResult;
use Dataphyre\Panel\PanelAgentToolExecutor;
use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelInMemoryRealtimeBroker;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManifest;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelRealtimeEndpoint;
use Dataphyre\Panel\PanelRealtimeIntentSigner;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel realtime and agent workflow platform integration')
	->contract('panel.realtime-agent-platform-integration',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel','framework:platform','framework:realtime','framework:agents')
	->through('typed-services','surface-scope','manifest-redaction','plugin-rollback','facade')
	->tag('panel','platform','realtime','agents')
	->group('panel-platform-contract');

final class DpPanelPlatformAgentPolicy implements PanelAgentPolicyResolver {
	public function decide(PanelAgentRequestContext $context,PanelAgentTool $tool,array $arguments):PanelAgentPolicyDecision{return PanelAgentPolicyDecision::allow('Fixture policy allowed this tool.');}
	public function approve(PanelAgentRequestContext $approver,PanelAgentPlan $plan):PanelAgentPolicyDecision{return PanelAgentPolicyDecision::allow('Fixture policy allowed approval.');}
	public function fingerprint():string{return hash('sha256','dp-panel-platform-agent-policy-v1');}
}

final class DpPanelPlatformAgentExecutor implements PanelAgentToolExecutor {
	public function execute(PanelAgentToolExecutionRequest $request):PanelAgentToolExecutionResult{return PanelAgentToolExecutionResult::success(['ok'=>true]);}
}

final class DpPanelPassiveManifestProbe implements JsonSerializable {
	public int $calls=0;
	public function jsonSerialize():array{$this->calls++;return['secret_token'=>'MUST-NOT-RUN'];}
}

/** @return array{platform:PanelPlatform,broker:PanelInMemoryRealtimeBroker,realtime_signer:PanelRealtimeIntentSigner,realtime:PanelRealtimeEndpoint,catalog:PanelAgentToolCatalog,policy:PanelAgentPolicyEngine,agent_signer:PanelAgentIntentSigner,store:InMemoryPanelAgentWorkflowStore,runtime:PanelAgentRuntime} */
function dp_panel_realtime_agent_platform():array{
	$broker=new PanelInMemoryRealtimeBroker();
	$realtime_signer=new PanelRealtimeIntentSigner(['current'=>str_repeat('R',32)],'current');
	$realtime=(new PanelRealtimeEndpoint($broker,$realtime_signer))->authorizeHost(static fn():bool=>true);
	$catalog=new PanelAgentToolCatalog();
	$policy=new PanelAgentPolicyEngine(new DpPanelPlatformAgentPolicy());
	$agent_signer=new PanelAgentIntentSigner(['current'=>str_repeat('A',32)],'current');
	$store=new InMemoryPanelAgentWorkflowStore();
	$runtime=new PanelAgentRuntime($catalog,$policy,$agent_signer,$store);
	$platform=PanelPlatform::make()
		->register('realtime.broker',$broker)
		->register('realtime.signer',$realtime_signer)
		->register('realtime.endpoint',$realtime)
		->register('agents.catalog',$catalog)
		->register('agents.policy',$policy)
		->register('agents.signer',$agent_signer)
		->register('agents.store',$store)
		->register('agents.runtime',$runtime);
	return compact('platform','broker','realtime_signer','realtime','catalog','policy','agent_signer','store','runtime');
}

test('unresolved factories remain fail closed and public manifests never resolve them',static function(Context $t):void{
	$realtimeCalls=0;$agentCalls=0;
	$platform=PanelPlatform::make()
		->register('realtime.broker',new PanelInMemoryRealtimeBroker())
		->register('realtime.signer',new PanelRealtimeIntentSigner(['current'=>str_repeat('R',32)],'current'))
		->factory('realtime.endpoint',static function()use(&$realtimeCalls):PanelRealtimeEndpoint{$realtimeCalls++;throw new RuntimeException('must not resolve');})
		->register('agents.catalog',new PanelAgentToolCatalog())
		->register('agents.policy',new PanelAgentPolicyEngine())
		->register('agents.signer',new PanelAgentIntentSigner(['current'=>str_repeat('A',32)],'current'))
		->register('agents.store',new InMemoryPanelAgentWorkflowStore())
		->factory('agents.runtime',static function()use(&$agentCalls):PanelAgentRuntime{$agentCalls++;throw new RuntimeException('must not resolve');});
	$panel=PanelInstance::make('lazy-integrations')->usePlatform($platform);
	$t->isFalse($panel->hasRealtime());$t->isFalse($panel->hasAgentWorkflows());
	$t->throws(static fn():PanelRealtimeEndpoint=>$panel->realtime(),LogicException::class);
	$t->throws(static fn():PanelAgentRuntime=>$panel->agentRuntime(),LogicException::class);
	$t->same('unresolved_factory',$panel->realtimeManifest()['attachment']['services']['endpoint']['state']);
	$t->same('unresolved_factory',$panel->agentWorkflowManifest()['attachment']['services']['runtime']['state']);
	$t->same(0,$realtimeCalls);$t->same(0,$agentCalls);
	$t->isFalse($platform->manifest()->ready('realtime'));$t->isFalse($platform->manifest()->ready('agent_workflows'));
})->tag('panel','realtime','agents','factory','fail-closed')->group('framework-coverage');

test('typed services are instance scoped discoverable and exposed through bounded manifests',static function(Context $t):void{
	$fixture=dp_panel_realtime_agent_platform();$platform=$fixture['platform'];$panel=PanelInstance::make('operations')->usePlatform($platform);
	$t->isTrue($panel->hasRealtime());$t->isTrue($panel->hasAgentWorkflows());
	$t->same($fixture['broker'],$platform->realtimeBroker());$t->same($fixture['realtime_signer'],$platform->realtimeSigner());$t->same($fixture['realtime'],$platform->realtime());$t->same($fixture['realtime'],$panel->realtime());
	$t->same($fixture['catalog'],$platform->agentTools());$t->same($fixture['policy'],$platform->agentPolicy());$t->same($fixture['agent_signer'],$platform->agentSigner());$t->same($fixture['store'],$platform->agentStore());$t->same($fixture['runtime'],$platform->agents());$t->same($fixture['runtime'],$panel->agentRuntime());
	$t->same($platform,$platform->registerAgentTool(new PanelAgentTool('host.inspect','1.0.0','Host inspection.','host.inspect'),new DpPanelPlatformAgentExecutor(),'host'));
	$t->isTrue($platform->manifest()->ready('realtime'));$t->isTrue($platform->manifest()->ready('agent_workflows'));
	$realtime=$panel->realtimeManifest();$agents=$panel->agentWorkflowManifest();
	$t->isTrue($realtime['attachment']['configured']);$t->isTrue($agents['attachment']['configured']);
	$t->isFalse($realtime['integration']['routes_registered']);$t->isFalse($agents['integration']['routes_registered']);
	$t->isFalse($agents['integration']['model_client_registered']);$t->isTrue($agents['integration']['host_authorization_required']);
	$public=json_encode([$realtime,$agents,$panel->platformManifest()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	$t->notContains(str_repeat('R',32),$public);$t->notContains(str_repeat('A',32),$public);$t->notContains('routes_registered":true',$public);
	$scoped=$t->nonPublic($panel)->invoke('within',static fn():array=>['realtime'=>PanelConfig::realtime(),'agents'=>PanelConfig::agentRuntime(),'has_realtime'=>PanelConfig::hasRealtime(),'has_agents'=>PanelConfig::hasAgentWorkflows()]);
	$t->same($fixture['realtime'],$scoped['realtime']);$t->same($fixture['runtime'],$scoped['agents']);$t->isTrue($scoped['has_realtime']);$t->isTrue($scoped['has_agents']);
	$t->isFalse(PanelConfig::hasRealtime());$t->isFalse(PanelConfig::hasAgentWorkflows());
	$t->throws(static fn():PanelRealtimeEndpoint=>PanelConfig::realtime(),LogicException::class);$t->throws(static fn():PanelAgentRuntime=>PanelConfig::agentRuntime(),LogicException::class);
})->tag('panel','realtime','agents','scope','manifest','redaction')->group('framework-coverage');

test('split brain endpoint and runtime graphs fail readiness without resolving adapters',static function(Context $t):void{
	$registeredBroker=new PanelInMemoryRealtimeBroker();$registeredRealtimeSigner=new PanelRealtimeIntentSigner(['current'=>str_repeat('R',32)],'current');
	$endpoint=new PanelRealtimeEndpoint(new PanelInMemoryRealtimeBroker(),new PanelRealtimeIntentSigner(['current'=>str_repeat('S',32)],'current'));
	$registeredCatalog=new PanelAgentToolCatalog();$registeredPolicy=new PanelAgentPolicyEngine(new DpPanelPlatformAgentPolicy());$registeredAgentSigner=new PanelAgentIntentSigner(['current'=>str_repeat('A',32)],'current');$registeredStore=new InMemoryPanelAgentWorkflowStore();
	$runtime=new PanelAgentRuntime(new PanelAgentToolCatalog(),new PanelAgentPolicyEngine(),new PanelAgentIntentSigner(['current'=>str_repeat('B',32)],'current'),new InMemoryPanelAgentWorkflowStore());
	$platform=PanelPlatform::make()
		->register('realtime.broker',$registeredBroker)->register('realtime.signer',$registeredRealtimeSigner)->register('realtime.endpoint',$endpoint)
		->register('agents.catalog',$registeredCatalog)->register('agents.policy',$registeredPolicy)->register('agents.signer',$registeredAgentSigner)->register('agents.store',$registeredStore)->register('agents.runtime',$runtime);
	$panel=PanelInstance::make('split-brain-integrations')->usePlatform($platform);
	$t->isFalse($panel->hasRealtime());$t->isFalse($panel->hasAgentWorkflows());
	$t->throws(static fn():PanelRealtimeEndpoint=>$panel->realtime(),LogicException::class);$t->throws(static fn():PanelAgentRuntime=>$panel->agentRuntime(),LogicException::class);
	$realtime=$panel->realtimeManifest();$agents=$panel->agentWorkflowManifest();
	$t->isFalse($realtime['attachment']['configured']);$t->isFalse($agents['attachment']['configured']);
	$t->isTrue($realtime['attachment']['cohesion']['evaluated']);$t->isFalse($realtime['attachment']['cohesion']['valid']);
	$t->same(['realtime.endpoint.broker','realtime.endpoint.signer'],$realtime['attachment']['cohesion']['mismatches']);
	$t->isTrue($agents['attachment']['cohesion']['evaluated']);$t->isFalse($agents['attachment']['cohesion']['valid']);
	$t->same(['agents.runtime.catalog','agents.runtime.policy','agents.runtime.signer','agents.runtime.store'],$agents['attachment']['cohesion']['mismatches']);
	$t->isNull($realtime['endpoint']);$t->isNull($agents['runtime']);
	$manifest=$platform->manifest();$t->isFalse($manifest->ready('realtime'));$t->isFalse($manifest->ready('agent_workflows'));
	$t->same($realtime['attachment']['cohesion'],$manifest->domain('realtime')['cohesion']);$t->same($agents['attachment']['cohesion'],$manifest->domain('agent_workflows')['cohesion']);
	$t->isTrue($manifest->jsonSerialize()['security']['readiness_requires_cohesive_graphs']);
})->tag('panel','realtime','agents','cohesion','split-brain','fail-closed')->group('framework-coverage');

test('agent tool contributions participate in plugin rollback and provenance',static function(Context $t):void{
	$fixture=dp_panel_realtime_agent_platform();$panel=PanelInstance::make('agent-plugins')->usePlatform($fixture['platform']);
	$tool=new PanelAgentTool('orders.inspect','1.0.0','Inspect one order.','orders.inspect');$executor=new DpPanelPlatformAgentExecutor();
	$failure=new class($tool,$executor) implements PanelPlugin {
		public function __construct(private PanelAgentTool $tool,private PanelAgentToolExecutor $executor){}
		public function id():string{return'agent-tool-failure';}
		public function register(PanelInstance $panel):void{$panel->registerAgentTool($this->tool,$this->executor);throw new RuntimeException('registration failed');}
		public function boot(PanelInstance $panel):void{}
	};
	$t->throws(static fn():PanelInstance=>$panel->plugin($failure),RuntimeException::class);$t->isFalse($fixture['catalog']->has('orders.inspect',true));
	$success=new class($tool,$executor) implements PanelPlugin {
		public function __construct(private PanelAgentTool $tool,private PanelAgentToolExecutor $executor){}
		public function id():string{return'agent-tool-success';}
		public function register(PanelInstance $panel):void{$panel->registerAgentTool($this->tool,$this->executor,25);}
		public function boot(PanelInstance $panel):void{}
	};
	$panel->plugin($success);$t->isTrue($fixture['catalog']->has('orders.inspect'));$t->same('agent-tool-success',$fixture['catalog']->contributor('orders.inspect'));
	$t->same('agent-tool-success',$panel->agentWorkflowManifest()['runtime']['catalog']['tools'][0]['provenance']['contributor']??null);
})->tag('panel','agents','plugin','rollback','provenance')->group('framework-coverage');

test('default facade and complete panel manifest retain truthful opt-in attachment state',static function(Context $t):void{
	Panel::flush();
	try{
		$fixture=dp_panel_realtime_agent_platform();$surface=Panel::usePlatform($fixture['platform']);
		$t->isTrue(Panel::hasRealtime());$t->isTrue(Panel::hasAgentWorkflows());$t->same($fixture['realtime'],Panel::realtime());$t->same($fixture['runtime'],Panel::agentRuntime());
		$t->same($surface,Panel::registerAgentTool(new PanelAgentTool('orders.list','1.0.0','List orders.','orders.list'),new DpPanelPlatformAgentExecutor()));
		$t->isTrue(Panel::realtimeManifest()['attachment']['configured']);$t->isTrue(Panel::agentWorkflowManifest()['attachment']['configured']);
		$manifest=Panel::panelManifest();$t->isTrue($manifest['capabilities']['realtime']['configured']);$t->isTrue($manifest['capabilities']['agent_workflows']['configured']);
		$t->isFalse($manifest['capabilities']['realtime']['routes_registered']);$t->isFalse($manifest['capabilities']['agent_workflows']['routes_registered']);
		$t->same('panel_realtime_integration',$manifest['realtime']['type']);$t->same('panel_agent_workflow_integration',$manifest['agent_workflows']['type']);
	}finally{Panel::flush();}
})->tag('panel','realtime','agents','facade','manifest')->group('framework-coverage');

test('offline integration manifests are copied passively and recursively redacted',static function(Context $t):void{
	$probe=new DpPanelPassiveManifestProbe();
	$manifest=PanelManifest::from([
		'name'=>'offline-integrations',
		'platform'=>['type'=>'panel_platform_manifest','version'=>1,'adapter'=>$probe,'signing_key'=>'secret-platform'],
		'realtime'=>['type'=>'panel_realtime_integration','version'=>1,'endpoint'=>['adapter'=>$probe,'authorization_token'=>'secret-one'],'attachment'=>['configured'=>true]],
		'agent_workflows'=>['type'=>'panel_agent_workflow_integration','version'=>1,'runtime'=>['adapter'=>$probe,'apiKey'=>'secret-two'],'attachment'=>['configured'=>true]],
	])->toArray();
	$t->same(0,$probe->calls);$t->same('omitted',$manifest['platform']['adapter']['serialization']);$t->same('omitted',$manifest['realtime']['endpoint']['adapter']['serialization']);$t->same('omitted',$manifest['agent_workflows']['runtime']['adapter']['serialization']);
	$public=json_encode($manifest,JSON_THROW_ON_ERROR);$t->notContains('MUST-NOT-RUN',$public);$t->notContains('secret-platform',$public);$t->notContains('secret-one',$public);$t->notContains('secret-two',$public);
	$passive=$t->nonPublic(PanelManifest::class);$resource=fopen('php://memory','rb');$t->same('resource',$passive->invoke('passiveManifestSnapshot',$resource)['type']);fclose($resource);
	$t->same('INF',$passive->invoke('passiveManifestSnapshot',INF));$deep='leaf';for($index=0;$index<34;$index++){$deep=[$deep];}$t->contains('truncated',json_encode($passive->invoke('passiveManifestSnapshot',$deep),JSON_THROW_ON_ERROR));
	$wide=array_fill(0,5001,true);$wideSnapshot=$passive->invoke('passiveManifestSnapshot',$wide);$t->isTrue($wideSnapshot['__truncated_items__']);
})->tag('panel','realtime','agents','manifest','passive','redaction')->group('framework-coverage');
