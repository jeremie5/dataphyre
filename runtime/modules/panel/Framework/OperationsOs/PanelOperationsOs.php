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
 * Cohesive, deny-by-default composition root for Panel's governed operations OS.
 *
 * The runtime binds domain compilation, durable work, policy, AI operators,
 * semantics, lineage, process intelligence, compliance, fleet federation,
 * releases, local-first replicas, marketplace review, and Studio branching to
 * one trust root without serializing that root into any public manifest.
 */
final class PanelOperationsOs implements \JsonSerializable {
	/** @var array<string,PanelDomainCompilation> */ private array $compilations=[];
	/** @var array<string,array<string,PanelDomainCompilation>> */ private array $compilationHistory=[];
	/** @var array<string,string> */ private readonly array $domainKeys;
	/** @var array<string,string> */ private readonly array $syncKeys;
	private readonly string $domainKeyId;
	private readonly string $syncKeyId;

	public function __construct(
		private readonly PanelDomainCompiler $domainCompiler,
		private readonly PanelWorkGraph $workGraph,
		private readonly PanelPolicyControlPlane $policy,
		private readonly PanelOperatorRuntime $operator,
		private readonly PanelSemanticCatalog $semantics,
		private readonly PanelLineageGraph $lineage,
		private readonly PanelProcessIntelligence $processIntelligence,
		private readonly PanelCounterfactualLab $counterfactuals,
		private readonly PanelComplianceLedger $compliance,
		private readonly PanelFederationControlPlane $federation,
		private readonly PanelReleaseControlPlane $releases,
		private readonly PanelMarketplaceGovernance $marketplace,
		private readonly PanelStudioBranchManager $studioBranches,
		array $domainKeys,
		string $domainKeyId,
		array $syncKeys,
		string $syncKeyId,
		private readonly ?\Closure $clock=null,
		private readonly ?PanelDomainActivationRuntime $activationRuntime=null,
		private readonly ?PanelCommandFabric $commandFabric=null,
		private readonly ?PanelClosedLoopIntelligence $closedLoopIntelligence=null,
		private readonly ?PanelReleaseExecutionEngine $releaseExecutionEngine=null,
		private readonly ?PanelFederationGateway $federationGateway=null,
		private readonly ?PanelComplianceAutomation $complianceAutomation=null,
		private readonly ?PanelPackageMarketplaceTrustNetwork $marketplaceTrustNetwork=null,
	){
		$this->domainKeys=self::keys($domainKeys,'domain compilation');
		$this->syncKeys=self::keys($syncKeys,'local sync');
		$this->domainKeyId=PanelOperationsGuard::name($domainKeyId,'domain compilation key id');
		$this->syncKeyId=PanelOperationsGuard::name($syncKeyId,'local sync key id');
		if(!isset($this->domainKeys[$this->domainKeyId])||!isset($this->syncKeys[$this->syncKeyId])){
			throw new \InvalidArgumentException('Operations OS current trust keys are not present in their keyrings.');
		}
	}

	/** @param array<string,mixed> $config */
	public static function fromConfig(string $root,array $config):self {
		$root=rtrim(trim($root),'\\/');
		if($root===''||str_contains($root,"\0")||is_link($root)){
			throw new \InvalidArgumentException('Operations OS state root is invalid.');
		}
		if(!is_dir($root)&&!@mkdir($root,0770,true)&&!is_dir($root)){
			throw new \RuntimeException('Unable to create the Operations OS state root.');
		}
		$master=$config['master_key']??null;
		if(!is_string($master)||strlen($master)<32){
			throw new \InvalidArgumentException('Operations OS requires an explicit master key of at least 32 bytes.');
		}
		$keyId=PanelOperationsGuard::name((string)($config['key_id']??'primary'),'Operations OS key id');
		$keyring=static fn(string $purpose):array=>[$keyId=>hash_hmac('sha256','dataphyre.panel.operations-os.'.$purpose,$master,true)];
		$clock=is_callable($config['clock']??null)?\Closure::fromCallable($config['clock']):null;

		$policyKeys=self::configuredKeys($config,'policy',$keyring('policy'));
		$domainKeys=self::configuredKeys($config,'domain',$keyring('domain'));
		$releaseKeys=self::configuredKeys($config,'release',$keyring('release'));
		$complianceKeys=self::configuredKeys($config,'compliance',$keyring('compliance'));
		$federationKeys=self::configuredKeys($config,'federation',$keyring('federation'));
		$syncKeys=self::configuredKeys($config,'sync',$keyring('sync'));
		$approvalKeys=self::configuredKeys($config,'approval',$keyring('approval'));
		$activationKeys=self::configuredKeys($config,'activation',$keyring('activation'));
		$fabricKeys=self::configuredKeys($config,'fabric',$keyring('fabric'));
		$intelligenceKeys=self::configuredKeys($config,'intelligence',$keyring('intelligence'));
		$policyKeyId=self::currentKey($config,'policy',$keyId,$policyKeys);
		$domainKeyId=self::currentKey($config,'domain',$keyId,$domainKeys);
		$releaseKeyId=self::currentKey($config,'release',$keyId,$releaseKeys);
		$complianceKeyId=self::currentKey($config,'compliance',$keyId,$complianceKeys);
		$syncKeyId=self::currentKey($config,'sync',$keyId,$syncKeys);
		$approvalKeyId=self::currentKey($config,'approval',$keyId,$approvalKeys);
		$activationKeyId=self::currentKey($config,'activation',$keyId,$activationKeys);
		$fabricKeyId=self::currentKey($config,'fabric',$keyId,$fabricKeys);
		$intelligenceKeyId=self::currentKey($config,'intelligence',$keyId,$intelligenceKeys);
		$federationKeyId=self::currentKey($config,'federation',$keyId,$federationKeys);

		$policy=$config['policy']??new PanelPolicyControlPlane($policyKeys,true);
		if(!$policy instanceof PanelPolicyControlPlane){throw new \InvalidArgumentException('Operations OS policy must be a PanelPolicyControlPlane.');}
		$bundles=$config['policy_bundles']??[];
		if(!is_array($bundles)){throw new \InvalidArgumentException('Operations OS policy_bundles must be a list.');}
		foreach($bundles as$bundle){
			if(is_array($bundle)){$bundle=PanelPolicyBundle::from($bundle);}
			if(!$bundle instanceof PanelPolicyBundle){throw new \InvalidArgumentException('Operations OS policy bundles must be manifests or PanelPolicyBundle values.');}
			if(!$bundle->signed()){$bundle=$bundle->sign($policyKeyId,$policyKeys[$policyKeyId]);}
			$policy->register($bundle);
		}

		$authorize=self::policyAuthorizer($policy);
		$compliance=new PanelComplianceLedger($root.'/compliance',$complianceKeys,$complianceKeyId,$authorize,$clock,(int)($config['compliance_retention']??10000));
		$complianceFrameworks=$config['compliance_framework_catalog']??PanelComplianceFrameworkCatalog::firstParty();if(!$complianceFrameworks instanceof PanelComplianceFrameworkCatalog){throw new \InvalidArgumentException('Operations OS compliance_framework_catalog must be a PanelComplianceFrameworkCatalog.');}
		$frameworkPacks=$config['compliance_framework_packs']??[];if(!is_array($frameworkPacks)||($frameworkPacks!==[]&&!array_is_list($frameworkPacks))){throw new \InvalidArgumentException('Operations OS compliance_framework_packs must be a list.');}foreach($frameworkPacks as$pack){if(!$pack instanceof PanelComplianceFrameworkPack){throw new \InvalidArgumentException('Operations OS compliance framework packs must be PanelComplianceFrameworkPack values.');}$complianceFrameworks->register($pack);}
		$complianceCollectors=$config['compliance_collector_registry']??new PanelComplianceCollectorRegistry();if(!$complianceCollectors instanceof PanelComplianceCollectorRegistry){throw new \InvalidArgumentException('Operations OS compliance_collector_registry must be a PanelComplianceCollectorRegistry.');}
		$collectorEntries=$config['compliance_collectors']??[];if(!is_array($collectorEntries)||($collectorEntries!==[]&&!array_is_list($collectorEntries))){throw new \InvalidArgumentException('Operations OS compliance_collectors must be a list.');}foreach($collectorEntries as$entry){if($entry instanceof PanelComplianceCollector){$complianceCollectors->register($entry);continue;}if(!is_array($entry)||!($entry['collector']??null)instanceof PanelComplianceCollector){throw new \InvalidArgumentException('Operations OS compliance collector entries require a typed collector.');}$complianceCollectors->register($entry['collector'],(string)($entry['contributor']??'host'),(int)($entry['priority']??0),($entry['replace']??false)===true);}
		$complianceAutomation=$config['compliance_automation']??new PanelComplianceAutomation($compliance,$complianceCollectors,$complianceFrameworks,is_callable($config['compliance_clock']??null)?$config['compliance_clock']:$clock,is_array($config['compliance_collection_limits']??null)?$config['compliance_collection_limits']:[]);if(!$complianceAutomation instanceof PanelComplianceAutomation){throw new \InvalidArgumentException('Operations OS compliance_automation must be a PanelComplianceAutomation.');}if($complianceAutomation->ledger()!==$compliance||$complianceAutomation->collectors()!==$complianceCollectors||$complianceAutomation->frameworks()!==$complianceFrameworks){throw new \InvalidArgumentException('Operations OS compliance automation must share the configured ledger, collector registry, and framework catalog.');}
		$workStore=$config['work_store']??new PanelFilesystemWorkGraphStore($root.'/work',(int)($config['work_snapshot_retention']??2048));
		if(!$workStore instanceof PanelWorkGraphStore){throw new \InvalidArgumentException('Operations OS work_store must implement PanelWorkGraphStore.');}
		$work=new PanelWorkGraph($workStore,$authorize,$clock,(int)($config['work_event_retention']??50000),(int)($config['work_receipt_retention']??10000));

		$router=$config['operator_router']??new PanelOperatorRouter();
		if(!$router instanceof PanelOperatorRouter){throw new \InvalidArgumentException('Operations OS operator_router must be a PanelOperatorRouter.');}
		$models=$config['operator_models']??[];
		if(!is_array($models)){throw new \InvalidArgumentException('Operations OS operator_models must be a list.');}
		foreach($models as$entry){
			if(!is_array($entry)||!(($entry['adapter']??null) instanceof PanelOperatorModelAdapter)){throw new \InvalidArgumentException('Operations OS operator model entries require a model and adapter.');}
			$model=$entry['model']??null;
			if(is_array($model)){$model=PanelOperatorModel::from((string)($model['id']??''),$model);}
			if(!$model instanceof PanelOperatorModel){throw new \InvalidArgumentException('Operations OS operator model entries require PanelOperatorModel values.');}
			$router->register($model,$entry['adapter'],($entry['replace']??false)===true);
		}
		$tools=is_array($config['operator_tools']??null)?$config['operator_tools']:[];
		$evaluators=is_array($config['operator_evaluators']??null)?$config['operator_evaluators']:[];
		$operator=new PanelOperatorRuntime($router,$policy,$tools,$evaluators,is_callable($config['operator_executor']??null)?$config['operator_executor']:null,$approvalKeys);

		$semantics=new PanelSemanticCatalog();
		$metrics=$config['metrics']??[];
		if(!is_array($metrics)){throw new \InvalidArgumentException('Operations OS metrics must be an object-like map.');}
		foreach($metrics as$id=>$definition){if(!is_array($definition)){throw new \InvalidArgumentException('Operations OS metric definitions must be maps.');}$semantics->register(PanelSemanticMetric::from((string)$id,$definition));}
		$simulator=is_callable($config['simulator']??null)?$config['simulator']:static fn(array $baseline,array $intervention,string $seed,int $run):array=>array_replace_recursive($baseline,$intervention);
		$lineage=new PanelLineageGraph();

		$workflowEngine=$config['workflow_engine']??new WorkflowEngine(new FilesystemWorkflowStore($root.'/domain-workflows'));
		if(!$workflowEngine instanceof WorkflowEngine){throw new \InvalidArgumentException('Operations OS workflow_engine must be a WorkflowEngine.');}
		$automationExecutor=$config['automation_executor']??null;
		if($automationExecutor!==null&&!$automationExecutor instanceof AutomationExecutor){throw new \InvalidArgumentException('Operations OS automation_executor must be an AutomationExecutor.');}
		$automationRegistry=$config['automation_registry']??($automationExecutor?->registry()??new AutomationRegistry());
		if(!$automationRegistry instanceof AutomationRegistry){throw new \InvalidArgumentException('Operations OS automation_registry must be an AutomationRegistry.');}
		if($automationExecutor===null){$automationExecutor=new AutomationExecutor($automationRegistry,new FilesystemAutomationStore($root.'/domain-automation'));}
		if($automationExecutor->registry()!==$automationRegistry){throw new \InvalidArgumentException('Operations OS automation registry and executor must share one registry.');}
		$agentCatalog=$config['agent_tool_catalog']??new PanelAgentToolCatalog();if(!$agentCatalog instanceof PanelAgentToolCatalog){throw new \InvalidArgumentException('Operations OS agent_tool_catalog must be a PanelAgentToolCatalog.');}
		$operationRunner=$config['operation_runner']??null;
		if($operationRunner===null){$operationStore=new PanelFilesystemOperationStore($root.'/fabric-operations');$operationHandlers=new PanelOperationHandlerRegistry();$operationRunner=new PanelSynchronousOperationRunner($operationStore,$operationHandlers,new PanelLocalOperationQueue($operationStore));}
		if(!$operationRunner instanceof PanelOperationRunner){throw new \InvalidArgumentException('Operations OS operation_runner must implement PanelOperationRunner.');}
		$fabric=$config['command_fabric']??null;
		if($fabric===null){
			$fabricRegistry=$config['fabric_registry']??new PanelCommandRegistry((string)($config['fabric_conflict_policy']??'deny'));if(!$fabricRegistry instanceof PanelCommandRegistry){throw new \InvalidArgumentException('Operations OS fabric_registry must be a PanelCommandRegistry.');}
			$fabricStore=$config['fabric_store']??new PanelFilesystemCommandFabricStore($root.'/fabric',(int)($config['fabric_snapshot_retention']??4096));if(!$fabricStore instanceof PanelCommandFabricStore){throw new \InvalidArgumentException('Operations OS fabric_store must implement PanelCommandFabricStore.');}
			$fabricCodec=$config['fabric_payload_codec']??new PanelEncryptedCommandPayloadCodec($keyring('fabric-payload')[$keyId]);if(!$fabricCodec instanceof PanelCommandPayloadCodec){throw new \InvalidArgumentException('Operations OS fabric_payload_codec must implement PanelCommandPayloadCodec.');}
			$fabricClock=is_callable($config['fabric_clock']??null)?$config['fabric_clock']:$clock;
			$fabricObligations=$config['fabric_obligation_verifier']??new PanelAttestedCommandObligationVerifier($intelligenceKeys,$fabricClock);if(!$fabricObligations instanceof PanelCommandObligationVerifier){throw new \InvalidArgumentException('Operations OS fabric_obligation_verifier must implement PanelCommandObligationVerifier.');}
			$fabricWorker=$config['fabric_subscriber_worker']??null;if($fabricWorker!==null&&!is_string($fabricWorker)){throw new \InvalidArgumentException('Operations OS fabric_subscriber_worker must be a string.');}
			$fabricLeaseTtl=$config['fabric_subscriber_lease_ttl_seconds']??60;if(!is_int($fabricLeaseTtl)){throw new \InvalidArgumentException('Operations OS fabric_subscriber_lease_ttl_seconds must be an integer.');}
			$fabric=new PanelCommandFabric($fabricRegistry,$fabricStore,$policy,$fabricCodec,$fabricKeys,$fabricKeyId,$fabricObligations,$fabricClock,$fabricWorker,$fabricLeaseTtl);
		}
		if(!$fabric instanceof PanelCommandFabric){throw new \InvalidArgumentException('Operations OS command_fabric must be a PanelCommandFabric.');}
		if($fabric->policy()!==$policy){throw new \InvalidArgumentException('Operations OS command fabric and runtime must share one policy control plane.');}
		$iam=$config['iam_manager']??null;if($iam!==null&&!$iam instanceof PanelIamManager){throw new \InvalidArgumentException('Operations OS iam_manager must be a PanelIamManager.');}
		$agentRuntime=$config['agent_runtime']??null;if($agentRuntime!==null&&!$agentRuntime instanceof PanelAgentRuntime){throw new \InvalidArgumentException('Operations OS agent_runtime must be a PanelAgentRuntime.');}
		$tenantRegistry=$config['tenant_registry']??null;if($tenantRegistry!==null&&!$tenantRegistry instanceof PanelTenantRegistry){throw new \InvalidArgumentException('Operations OS tenant_registry must be a PanelTenantRegistry.');}
		$tenantRequest=$config['tenant_request_resolver']??null;if($tenantRequest!==null&&!$tenantRequest instanceof PanelTenantFabricRequestResolver&&!is_callable($tenantRequest)){throw new \InvalidArgumentException('Operations OS tenant_request_resolver must implement PanelTenantFabricRequestResolver or be callable.');}
		$registerNative=($config['register_fabric_native_handlers']??true)!==false;
		if($registerNative){
			$fabric->registry()->register('workflow.*',new PanelWorkflowFabricHandler($workflowEngine),'operations_os.workflows',100);
			$fabric->registry()->register('automation.*',new PanelAutomationFabricHandler($automationExecutor),'operations_os.automation',100);
			$fabric->registry()->register('operation.*',new PanelOperationFabricHandler($operationRunner),'operations_os.operations',100);
			if($iam instanceof PanelIamManager){$fabric->registry()->register('iam.*',new PanelIamFabricHandler($iam),'operations_os.iam',100);}
			if($agentRuntime instanceof PanelAgentRuntime){$fabric->registry()->register('agent.*',new PanelAgentFabricHandler($agentRuntime),'operations_os.agents',100);}
			if($tenantRegistry instanceof PanelTenantRegistry){$fabric->registry()->register('tenant.*',new PanelTenantFabricHandler($tenantRegistry,$tenantRequest),'operations_os.tenancy',100);}
		}
		$domainDelegate=$config['domain_command_executor']??null;if($domainDelegate!==null&&!$domainDelegate instanceof PanelDomainCommandExecutor){throw new \InvalidArgumentException('Operations OS domain_command_executor must implement PanelDomainCommandExecutor.');}
		$commandExecutor=$domainDelegate;
		if($domainDelegate instanceof PanelDomainCommandExecutor&&$registerNative){
			if($domainDelegate instanceof PanelDomainFabricCommandExecutor){throw new \InvalidArgumentException('Operations OS domain_command_executor must be the host delegate, not a fabric adapter.');}
			$fabric->registry()->register('domain.*',new PanelDelegatingDomainFabricHandler($domainDelegate),'operations_os.domains',100);
			$commandExecutor=new PanelDomainFabricCommandExecutor($fabric);
		}
		$fabricHandlers=$config['fabric_handlers']??[];if(!is_array($fabricHandlers)){throw new \InvalidArgumentException('Operations OS fabric_handlers must be a map or list.');}
		foreach($fabricHandlers as$pattern=>$definition){
			if(is_int($pattern)){if(!is_array($definition)||!is_string($definition['pattern']??null)||!isset($definition['handler'])){throw new \InvalidArgumentException('Operations OS fabric handler entries require pattern and handler.');}$pattern=$definition['pattern'];$handler=$definition['handler'];$contributor=(string)($definition['contributor']??'host');$priority=(int)($definition['priority']??0);}
			else{$handler=is_array($definition)&&array_key_exists('handler',$definition)?$definition['handler']:$definition;$contributor=is_array($definition)?(string)($definition['contributor']??'host'):'host';$priority=is_array($definition)?(int)($definition['priority']??0):0;}
			if(!$handler instanceof PanelCommandHandler&&!is_callable($handler)){throw new \InvalidArgumentException('Operations OS fabric handlers must be callable or PanelCommandHandler instances.');}
			$fabric->registry()->register((string)$pattern,$handler,$contributor,$priority);
		}
		$fabricSubscribers=$config['fabric_subscribers']??[];if(!is_array($fabricSubscribers)){throw new \InvalidArgumentException('Operations OS fabric_subscribers must be a list.');}
		foreach($fabricSubscribers as$subscriber){if(!is_array($subscriber)||!is_string($subscriber['name']??null)||!isset($subscriber['patterns'])||!is_callable($subscriber['subscriber']??null)){throw new \InvalidArgumentException('Operations OS fabric subscribers require name, patterns, and subscriber.');}$fabric->subscribe($subscriber['name'],is_array($subscriber['patterns'])?$subscriber['patterns']:(string)$subscriber['patterns'],$subscriber['subscriber']);}
		$realtimePublisher=$config['realtime_publisher']??null;if($realtimePublisher!==null&&!$realtimePublisher instanceof PanelRealtimePublisher){throw new \InvalidArgumentException('Operations OS realtime_publisher must implement PanelRealtimePublisher.');}if($realtimePublisher instanceof PanelRealtimePublisher){$fabric->subscribe('operations_os.realtime','*',new PanelRealtimeFabricProjector($realtimePublisher,(string)($config['realtime_panel']??'panel')));}
		$notificationAdapter=$config['notification_adapter']??null;if($notificationAdapter!==null&&!$notificationAdapter instanceof PanelNotificationAdapter){throw new \InvalidArgumentException('Operations OS notification_adapter must implement PanelNotificationAdapter.');}if($notificationAdapter instanceof PanelNotificationAdapter){$fabric->subscribe('operations_os.notifications','*',new PanelNotificationFabricProjector($notificationAdapter));}
		$complianceMappings=$config['fabric_compliance_mappings']??[];if(!is_array($complianceMappings)){throw new \InvalidArgumentException('Operations OS fabric_compliance_mappings must be a map.');}if($complianceMappings!==[]){$fabric->subscribe('operations_os.compliance',array_keys($complianceMappings),new PanelComplianceFabricProjector($compliance,$complianceMappings));}
		$intelligence=$config['closed_loop_intelligence']??null;
		if($intelligence===null){$intelligenceCodec=$config['intelligence_payload_codec']??new PanelEncryptedCommandPayloadCodec($keyring('intelligence-payload')[$keyId]);if(!$intelligenceCodec instanceof PanelCommandPayloadCodec){throw new \InvalidArgumentException('Operations OS intelligence_payload_codec must implement PanelCommandPayloadCodec.');}$thresholds=$config['intelligence_approval_thresholds']??[];if(!is_array($thresholds)){throw new \InvalidArgumentException('Operations OS intelligence_approval_thresholds must be a map.');}$intelligence=new PanelClosedLoopIntelligence($root.'/intelligence',$fabric,$policy,$intelligenceCodec,$intelligenceKeys,$intelligenceKeyId,$thresholds,is_callable($config['intelligence_clock']??null)?$config['intelligence_clock']:$clock,(int)($config['intelligence_snapshot_retention']??2048),(int)($config['intelligence_approval_ttl_seconds']??3600),(int)($config['intelligence_dispatch_stale_seconds']??300),(int)($config['intelligence_maximum_entries']??10000));}
		if(!$intelligence instanceof PanelClosedLoopIntelligence){throw new \InvalidArgumentException('Operations OS closed_loop_intelligence must be a PanelClosedLoopIntelligence.');}if($intelligence->fabric()!==$fabric||$intelligence->policy()!==$policy){throw new \InvalidArgumentException('Operations OS closed-loop intelligence must share the command fabric and policy control plane.');}
		$commandContext=$config['domain_command_context_resolver']??($commandExecutor!==null?new PanelRequestDomainCommandContextResolver():null);if($commandContext!==null&&!$commandContext instanceof PanelDomainCommandContextResolver){throw new \InvalidArgumentException('Operations OS domain_command_context_resolver must implement PanelDomainCommandContextResolver.');}
		$migrationExecutor=$config['domain_migration_executor']??null;if($migrationExecutor!==null&&!$migrationExecutor instanceof PanelDomainMigrationExecutor){throw new \InvalidArgumentException('Operations OS domain_migration_executor must implement PanelDomainMigrationExecutor.');}
		$materializer=new PanelDomainMaterializer($policy,$commandExecutor,$commandContext);
		$host=new PanelDomainRuntimeHost($workflowEngine,$automationRegistry,$automationExecutor,$policy,$agentCatalog,$semantics,$lineage,$policyKeys,$policyKeyId);
			$activationStore=$config['domain_activation_store']??new PanelFilesystemDomainActivationStore($root.'/domains',(int)($config['domain_activation_snapshot_retention']??2048));if(!$activationStore instanceof PanelDomainActivationStore){throw new \InvalidArgumentException('Operations OS domain_activation_store must implement PanelDomainActivationStore.');}
			$activation=new PanelDomainActivationRuntime(new PanelDomainCompiler(),$materializer,$host,$activationStore,$domainKeys,$activationKeys,$activationKeyId,$approvalKeys,$approvalKeyId,$migrationExecutor,$clock,is_callable($config['domain_nonce_factory']??null)?$config['domain_nonce_factory']:null,(int)($config['domain_plan_ttl_seconds']??900));
			$releases=$config['release_control_plane']??new PanelReleaseControlPlane($root.'/releases',$releaseKeys,$policy,$clock,(int)($config['release_snapshot_retention']??2048));if(!$releases instanceof PanelReleaseControlPlane){throw new \InvalidArgumentException('Operations OS release_control_plane must be a PanelReleaseControlPlane.');}if($releases->policy()!==$policy){throw new \InvalidArgumentException('Operations OS release control plane and runtime must share one policy control plane.');}
			$releaseAdapter=$config['release_deployment_adapter']??null;if($releaseAdapter!==null&&!$releaseAdapter instanceof PanelReleaseDeploymentAdapter){throw new \InvalidArgumentException('Operations OS release_deployment_adapter must implement PanelReleaseDeploymentAdapter.');}
				$releaseExecution=$config['release_execution_engine']??new PanelReleaseExecutionEngine($root.'/release-execution',$releases,$policy,$releaseAdapter,$releaseKeys,$releaseKeyId,is_callable($config['release_execution_clock']??null)?$config['release_execution_clock']:$clock,(int)($config['release_execution_lease_seconds']??120),(int)($config['release_execution_maximum_entries']??10000),(int)($config['release_execution_snapshot_retention']??2048));if(!$releaseExecution instanceof PanelReleaseExecutionEngine){throw new \InvalidArgumentException('Operations OS release_execution_engine must be a PanelReleaseExecutionEngine.');}if($releaseExecution->controlPlane()!==$releases||$releaseExecution->policy()!==$policy){throw new \InvalidArgumentException('Operations OS release execution must share the release and policy control planes.');}
				$federation=$config['federation_control_plane']??new PanelFederationControlPlane($federationKeys,$clock,$root.'/federation',$federationKeyId,(int)($config['federation_snapshot_retention']??2048));if(!$federation instanceof PanelFederationControlPlane){throw new \InvalidArgumentException('Operations OS federation_control_plane must be a PanelFederationControlPlane.');}
				$federationTransport=$config['federation_transport']??null;if($federationTransport!==null&&!$federationTransport instanceof PanelFederationTransport){throw new \InvalidArgumentException('Operations OS federation_transport must implement PanelFederationTransport.');}$federationCodec=$config['federation_payload_codec']??new PanelEncryptedCommandPayloadCodec($keyring('federation-payload')[$keyId]);if(!$federationCodec instanceof PanelCommandPayloadCodec){throw new \InvalidArgumentException('Operations OS federation_payload_codec must implement PanelCommandPayloadCodec.');}
				$federationNodeId=PanelOperationsGuard::name((string)($config['federation_node_id']??'local'),'Operations OS federation node id');$federationGateway=$config['federation_gateway']??new PanelFederationGateway($root.'/federation-gateway',$federationNodeId,$federation,$policy,$federationCodec,$federationKeys,$federationKeyId,$federationTransport,is_callable($config['federation_clock']??null)?$config['federation_clock']:$clock,(int)($config['federation_message_ttl_seconds']??300),(int)($config['federation_maximum_entries']??10000),(int)($config['federation_gateway_snapshot_retention']??2048));if(!$federationGateway instanceof PanelFederationGateway){throw new \InvalidArgumentException('Operations OS federation_gateway must be a PanelFederationGateway.');}if($federationGateway->controlPlane()!==$federation||$federationGateway->policy()!==$policy){throw new \InvalidArgumentException('Operations OS federation gateway must share the federation and policy control planes.');}

			$marketplaceTrust=$config['marketplace_trust_network']??null;
			if($marketplaceTrust===null&&array_key_exists('marketplace_transparency_verifier',$config)){
				$marketplaceVerifier=$config['marketplace_transparency_verifier'];if(!$marketplaceVerifier instanceof PanelPackageTransparencyVerifier){throw new \InvalidArgumentException('Operations OS marketplace_transparency_verifier must be a PanelPackageTransparencyVerifier.');}
				$marketplaceTrust=new PanelPackageMarketplaceTrustNetwork($root.'/marketplace-trust',$marketplaceVerifier,is_callable($config['marketplace_clock']??null)?$config['marketplace_clock']:$clock,(int)($config['marketplace_checkpoint_max_age_seconds']??86400),(int)($config['marketplace_snapshot_retention']??2048),(int)($config['marketplace_maximum_events']??100000));
			}
			if($marketplaceTrust!==null&&!$marketplaceTrust instanceof PanelPackageMarketplaceTrustNetwork){throw new \InvalidArgumentException('Operations OS marketplace_trust_network must be a PanelPackageMarketplaceTrustNetwork.');}
			$marketplaceRevocations=$config['marketplace_revocation_registry']??$marketplaceTrust?->revocations();if($marketplaceRevocations!==null&&!$marketplaceRevocations instanceof PanelPackageRevocationRegistry){throw new \InvalidArgumentException('Operations OS marketplace_revocation_registry must be a PanelPackageRevocationRegistry.');}
			$marketplacePublishers=$config['marketplace_publisher_trust_registry']??$marketplaceTrust?->publishers();if($marketplacePublishers!==null&&!$marketplacePublishers instanceof PanelPackagePublisherTrustRegistry){throw new \InvalidArgumentException('Operations OS marketplace_publisher_trust_registry must be a PanelPackagePublisherTrustRegistry.');}
			if($marketplaceTrust instanceof PanelPackageMarketplaceTrustNetwork&&(($marketplaceRevocations instanceof PanelPackageRevocationRegistry&&$marketplaceRevocations->network()!==$marketplaceTrust)||($marketplacePublishers instanceof PanelPackagePublisherTrustRegistry&&$marketplacePublishers->network()!==$marketplaceTrust))){throw new \InvalidArgumentException('Operations OS marketplace registries must share the configured trust network.');}
			$marketplace=$config['marketplace_governance']??new PanelMarketplaceGovernance($policy,(int)($config['marketplace_required_approvals']??2),is_array($config['marketplace_critical_permissions']??null)?$config['marketplace_critical_permissions']:['filesystem.*','process.*','network.unrestricted','secrets.read'],$clock,$marketplaceRevocations,$marketplacePublishers,is_array($config['marketplace_allowed_publisher_statuses']??null)?$config['marketplace_allowed_publisher_statuses']:['observed']);
			if(!$marketplace instanceof PanelMarketplaceGovernance){throw new \InvalidArgumentException('Operations OS marketplace_governance must be a PanelMarketplaceGovernance.');}
			if($marketplaceTrust instanceof PanelPackageMarketplaceTrustNetwork&&(!($marketplace->revocationRegistry() instanceof PanelPackageRevocationRegistry)||!($marketplace->publisherTrustRegistry() instanceof PanelPackagePublisherTrustRegistry)||$marketplace->revocationRegistry()?->network()!==$marketplaceTrust||$marketplace->publisherTrustRegistry()?->network()!==$marketplaceTrust)){throw new \InvalidArgumentException('Operations OS marketplace governance must share both registries from the configured trust network.');}

			$os=new self(
			new PanelDomainCompiler(),
			$work,
			$policy,
			$operator,
			$semantics,
			$lineage,
			new PanelProcessIntelligence(),
			new PanelCounterfactualLab($simulator,(int)($config['counterfactual_max_runs']??1000)),
			$compliance,
				$federation,
				$releases,
			$marketplace,
			new PanelStudioBranchManager($authorize,$clock,(int)($config['studio_required_approvals']??1)),
			$domainKeys,
			$domainKeyId,
			$syncKeys,
			$syncKeyId,
				$clock,
				$activation,
					$fabric,
					$intelligence,
						$releaseExecution,
						$federationGateway,
						$complianceAutomation,
						$marketplaceTrust,
				);
			if($registerNative&&($config['register_operations_os_control_handler']??true)!==false){
				$routes=$fabric->registry()->jsonSerialize()['routes']??[];
				if(!is_array($routes)||!isset($routes['operations_os.*'])){
					$fabric->registry()->register('operations_os.*',new PanelOperationsOsFabricHandler($os),'operations_os.control',200);
				}
			}
			foreach($activation->activeCompilations()as$compilation){$os->rememberCompilation($compilation);}
		$desired=$config['federation_desired_state']??[];
		if(!is_array($desired)){throw new \InvalidArgumentException('Operations OS federation desired state must be a map.');}
		if($desired!==[]){$os->federation()->desired($desired);}
		$domains=$config['domains']??[];
		if(!is_array($domains)){throw new \InvalidArgumentException('Operations OS domains must be a list.');}
		$activateDomains=($config['activate_domains']??false)===true;
		foreach($domains as$manifest){if(!is_array($manifest)&&!$manifest instanceof PanelDomainManifest){throw new \InvalidArgumentException('Operations OS domains must be manifests.');}if($activateDomains){$domain=$manifest instanceof PanelDomainManifest?$manifest:PanelDomainManifest::from($manifest);$compiled=$os->compileTrusted($domain);$plan=$activation->preview($compiled);if($plan->approvalCount()>0){throw new \LogicException('Configured domain upgrade requires an explicitly approved activation plan.');}$activation->activate($compiled,'system:config','config:'.$compiled->domainId().':'.$compiled->digest(),$plan);$os->rememberCompilation($compiled);}else{$os->installDomain($manifest);}}
		return$os;
	}

	public function domainCompiler():PanelDomainCompiler{return$this->domainCompiler;}
	public function workGraph():PanelWorkGraph{return$this->workGraph;}
	public function policy():PanelPolicyControlPlane{return$this->policy;}
	public function operator():PanelOperatorRuntime{return$this->operator;}
	public function semantics():PanelSemanticCatalog{return$this->semantics;}
	public function lineage():PanelLineageGraph{return$this->lineage;}
	public function processIntelligence():PanelProcessIntelligence{return$this->processIntelligence;}
	public function counterfactuals():PanelCounterfactualLab{return$this->counterfactuals;}
	public function compliance():PanelComplianceLedger{return$this->compliance;}
	public function complianceAutomation():PanelComplianceAutomation {if(!$this->complianceAutomation instanceof PanelComplianceAutomation){throw new \LogicException('Operations OS compliance automation is not configured.');}return$this->complianceAutomation;}
	public function federation():PanelFederationControlPlane{return$this->federation;}
	public function releases():PanelReleaseControlPlane{return$this->releases;}
	public function marketplace():PanelMarketplaceGovernance{return$this->marketplace;}
	public function hasMarketplaceTrustNetwork():bool{return$this->marketplaceTrustNetwork instanceof PanelPackageMarketplaceTrustNetwork;}
	public function marketplaceTrustNetwork():PanelPackageMarketplaceTrustNetwork{if(!$this->marketplaceTrustNetwork instanceof PanelPackageMarketplaceTrustNetwork){throw new \LogicException('Operations OS marketplace trust network is not configured.');}return$this->marketplaceTrustNetwork;}
	public function studioBranches():PanelStudioBranchManager{return$this->studioBranches;}
	public function activation():PanelDomainActivationRuntime {if(!$this->activationRuntime instanceof PanelDomainActivationRuntime){throw new \LogicException('Operations OS domain activation is not configured.');}return$this->activationRuntime;}
	public function commandFabric():PanelCommandFabric {if(!$this->commandFabric instanceof PanelCommandFabric){throw new \LogicException('Operations OS command fabric is not configured.');}return$this->commandFabric;}
	public function intelligence():PanelClosedLoopIntelligence {if(!$this->closedLoopIntelligence instanceof PanelClosedLoopIntelligence){throw new \LogicException('Operations OS closed-loop intelligence is not configured.');}return$this->closedLoopIntelligence;}
	public function releaseExecution():PanelReleaseExecutionEngine {if(!$this->releaseExecutionEngine instanceof PanelReleaseExecutionEngine){throw new \LogicException('Operations OS release execution is not configured.');}return$this->releaseExecutionEngine;}
	public function federationGateway():PanelFederationGateway {if(!$this->federationGateway instanceof PanelFederationGateway){throw new \LogicException('Operations OS federation gateway is not configured.');}return$this->federationGateway;}

	public function installDomain(PanelDomainManifest|array $manifest):PanelDomainCompilation {
		$manifest=$manifest instanceof PanelDomainManifest?$manifest:PanelDomainManifest::from($manifest);
		$compilation=$this->domainCompiler->compile($manifest)->sign($this->domainKeyId,$this->domainKeys[$this->domainKeyId]);
		$known=$this->compilationHistory[$manifest->id()][$manifest->version()]??null;
		if($known instanceof PanelDomainCompilation&&!hash_equals($known->sourceFingerprint(),$compilation->sourceFingerprint())){throw new \LogicException('Published Operations OS domain versions are immutable.');}
		if($known instanceof PanelDomainCompilation){$compilation=$known;}
		$active=$this->compilations[$manifest->id()]??null;
		if($active instanceof PanelDomainCompilation&&hash_equals($active->digest(),$compilation->digest())){return$active;}
		$source=$compilation->artifact('source');
		if(!is_array($source)){throw new \UnexpectedValueException('Compiled domain source artifact is invalid.');}
		$metrics=[];
		foreach($source['metrics']??[]as$id=>$definition){
			if(!is_array($definition)){throw new \UnexpectedValueException('Compiled domain metric artifact is invalid.');}
			$metrics[$manifest->id().'.'.$id]=PanelSemanticMetric::from($manifest->id().'.'.$id,$definition);
		}
		$lineage=PanelLineageGraph::fromCompilation($compilation);
		$previous=$this->compilations[$manifest->id()]??null;
		if($previous instanceof PanelDomainCompilation){$previousSource=$previous->artifact('source');if(is_array($previousSource)){foreach(array_keys(is_array($previousSource['metrics']??null)?$previousSource['metrics']:[])as$id){$this->semantics->remove($manifest->id().'.'.$id);}}}
		foreach($metrics as$metric){$this->semantics->register($metric,true);}
		$this->lineage->forgetPrefix($manifest->id().':');
		$this->absorbLineage($manifest->id(),$lineage);
		$this->compilations[$manifest->id()]=$compilation;
		$this->compilationHistory[$manifest->id()][$manifest->version()]=$compilation;
		uksort($this->compilationHistory[$manifest->id()],static fn(string $left,string $right):int=>version_compare($left,$right));
		ksort($this->compilations,SORT_STRING);
		return$compilation;
	}

	public function previewDomainActivation(PanelDomainManifest|PanelDomainCompilation|array $domain,string $operation='activate'):PanelDomainActivationPlan {
		return$this->activation()->preview($this->trustedCompilation($domain),$operation);
	}

	/** @param list<PanelDomainActivationApproval> $approvals */
	public function activateDomain(PanelDomainManifest|PanelDomainCompilation|array $domain,string|int $actorId,string $idempotencyKey,?PanelDomainActivationPlan $plan=null,array $approvals=[],?int $expectedRevision=null):PanelDomainActivationReceipt {
		$compilation=$this->trustedCompilation($domain);$receipt=$this->activation()->activate($compilation,$actorId,$idempotencyKey,$plan,$approvals,$expectedRevision);$this->rememberCompilation($compilation);return$receipt;
	}

	public function approveDomainActivation(PanelDomainActivationPlan $plan,string|int $actorId,int $ttlSeconds=300):PanelDomainActivationApproval {return$this->activation()->issueApproval($plan,$actorId,$ttlSeconds);}

	/** @param list<PanelDomainActivationApproval> $approvals */
	public function rollbackDomain(string $domainId,string $version,string|int $actorId,string $idempotencyKey,?PanelDomainActivationPlan $plan=null,array $approvals=[],?int $expectedRevision=null):PanelDomainActivationReceipt {$receipt=$this->activation()->rollback($domainId,$version,$actorId,$idempotencyKey,$plan,$approvals,$expectedRevision);$compilation=$this->activation()->activeCompilation($domainId);if($compilation instanceof PanelDomainCompilation){$this->rememberCompilation($compilation);}return$receipt;}

	/** @param list<PanelDomainActivationApproval> $approvals */
	public function deactivateDomain(string $domainId,string|int $actorId,string $idempotencyKey,?PanelDomainActivationPlan $plan=null,array $approvals=[],?int $expectedRevision=null):PanelDomainActivationReceipt {$receipt=$this->activation()->deactivate($domainId,$actorId,$idempotencyKey,$plan,$approvals,$expectedRevision);unset($this->compilations[PanelOperationsGuard::name($domainId,'domain id')]);return$receipt;}

	/** @param list<PanelDomainActivationApproval> $approvals */
	public function reconcileDomain(string $domainId,string|int $actorId,string $idempotencyKey,?PanelDomainActivationPlan $plan=null,array $approvals=[],?int $expectedRevision=null):PanelDomainActivationReceipt {return$this->activation()->reconcile($domainId,$actorId,$idempotencyKey,$plan,$approvals,$expectedRevision);}

	public function attachManager(PanelManager $manager):self {$this->activation()->attachManager($manager);return$this;}

	public function compilation(string $domainId):PanelDomainCompilation {
		$domainId=PanelOperationsGuard::name($domainId,'domain id');
		if(!isset($this->compilations[$domainId])){throw new \OutOfBoundsException('Operations OS domain is not installed.');}
		return$this->compilations[$domainId];
	}
	public function compilationAt(string $domainId,string $version):PanelDomainCompilation {$domainId=PanelOperationsGuard::name($domainId,'domain id');$version=trim($version);if($version===''||strlen($version)>64){throw new \InvalidArgumentException('Domain compilation version is invalid.');}$compilation=$this->compilationHistory[$domainId][$version]??null;if(!$compilation instanceof PanelDomainCompilation){throw new \OutOfBoundsException('Operations OS domain version is not installed.');}return$compilation;}
	/** @return list<PanelDomainCompilation> */public function compilationHistory(string $domainId):array {$domainId=PanelOperationsGuard::name($domainId,'domain id');if(!isset($this->compilationHistory[$domainId])){throw new \OutOfBoundsException('Operations OS domain is not installed.');}return array_values($this->compilationHistory[$domainId]);}

	public function verifyCompilation(PanelDomainCompilation $compilation):bool{return$compilation->verify($this->domainKeys);}
	public function diffDomains(string $fromDomainId,string $toDomainId):PanelDomainDiff{return$this->domainCompiler->diff($this->compilation($fromDomainId),$this->compilation($toDomainId));}
	public function diffDomainVersions(string $domainId,string $fromVersion,string $toVersion):PanelDomainDiff{return$this->domainCompiler->diff($this->compilationAt($domainId,$fromVersion),$this->compilationAt($domainId,$toVersion));}

	public function replica(string|int $actorId):PanelLocalReplica {
		$actorId=PanelOperationsGuard::identifier($actorId,'local replica actor');
		return new PanelLocalReplica($actorId,$this->syncKeys,$this->syncKeyId,self::policyAuthorizer($this->policy,$actorId),$this->clock);
	}

	/** @return array<string,mixed> */
	public function status():array {
		return PanelManifestContract::stamp([
			'type'=>'panel_operations_os_status_manifest',
			'version'=>1,
			'policy_revision'=>$this->policy->revision(),
			'operator_router_revision'=>$this->operator->router()->revision(),
			'semantic_revision'=>$this->semantics->revision(),
			'lineage_revision'=>$this->lineage->revision(),
			'federation_revision'=>$this->federation->revision(),
			'installed_domains'=>array_map(static fn(PanelDomainCompilation $compilation):string=>$compilation->digest(),$this->compilations),
			'domain_history_depth'=>array_map('count',$this->compilationHistory),
			'domain_activation_revision'=>$this->activationRuntime?->revision(),
			'command_fabric_revision'=>$this->commandFabric?->store()->payload()['revision'],
			'command_fabric_sequence'=>$this->commandFabric?->store()->payload()['sequence'],
			'intelligence_revision'=>$this->closedLoopIntelligence?->store()->payload()['revision'],
				'release_execution_revision'=>$this->releaseExecutionEngine?->store()->payload()['revision'],
				'federation_gateway_revision'=>$this->federationGateway?->store()->payload()['revision'],
			'compliance_chain_verified'=>$this->compliance->verify(),
			'compliance_automation_configured'=>$this->complianceAutomation!==null,
			'marketplace_trust_network_configured'=>$this->marketplaceTrustNetwork!==null,
			'generated_at'=>$this->now(),
		]);
	}

	public function jsonSerialize():array {
		return PanelManifestContract::stamp([
			'type'=>'panel_operations_os_manifest',
			'version'=>1,
			'status'=>$this->status(),
			'components'=>[
				'domain_compiler'=>$this->domainCompiler->jsonSerialize(),
				'domain_activation'=>$this->activationRuntime?->jsonSerialize(),
				'command_fabric'=>$this->commandFabric?->jsonSerialize(),
				'closed_loop_intelligence'=>$this->closedLoopIntelligence?->jsonSerialize(),
				'work_graph'=>$this->workGraph->jsonSerialize(),
				'policy'=>$this->policy->jsonSerialize(),
				'operator'=>$this->operator->jsonSerialize(),
				'semantics'=>$this->semantics->jsonSerialize(),
				'lineage'=>$this->lineage->jsonSerialize(),
				'process_intelligence'=>$this->processIntelligence->jsonSerialize(),
				'counterfactuals'=>$this->counterfactuals->jsonSerialize(),
				'compliance'=>$this->compliance->jsonSerialize(),
				'compliance_automation'=>$this->complianceAutomation?->jsonSerialize(),
					'federation'=>$this->federation->jsonSerialize(),
					'federation_gateway'=>$this->federationGateway?->jsonSerialize(),
				'releases'=>$this->releases->jsonSerialize(),
				'release_execution'=>$this->releaseExecutionEngine?->jsonSerialize(),
				'marketplace'=>$this->marketplace->jsonSerialize(),
				'marketplace_trust_network'=>$this->marketplaceTrustNetwork?->jsonSerialize(),
				'studio_branches'=>$this->studioBranches->jsonSerialize(),
			],
			'security'=>[
				'default_deny'=>true,
				'domain_separated_keys'=>true,
				'secrets_serialized'=>false,
				'signed_domain_compilations'=>true,
				'signed_domain_activation'=>$this->activationRuntime!==null,
				'signed_command_receipts'=>$this->commandFabric!==null,
				'encrypted_command_payloads'=>$this->commandFabric!==null,
				'signed_intelligence_state'=>$this->closedLoopIntelligence!==null,
				'encrypted_intelligence_evidence'=>$this->closedLoopIntelligence!==null,
				'signed_release_execution_state'=>$this->releaseExecutionEngine!==null,
					'release_adapter_results_redacted'=>$this->releaseExecutionEngine!==null,
					'encrypted_federation_payloads'=>$this->federationGateway!==null,
					'signed_federation_acknowledgements'=>$this->federationGateway!==null,
				'signed_local_sync'=>true,
			],
			'capabilities'=>[
				'domain_as_code'=>true,'universal_work_graph'=>true,'portable_policy'=>true,
				'governed_ai_operators'=>true,'semantic_layer'=>true,'semantic_query_pushdown'=>true,'deterministic_semantic_fallback'=>true,'field_lineage'=>true,
				'process_intelligence'=>true,'counterfactual_simulation'=>true,
				'continuous_compliance'=>true,'collector_driven_compliance'=>$this->complianceAutomation!==null,'framework_evidence_crosswalks'=>$this->complianceAutomation!==null,'signed_compliance_runs'=>$this->complianceAutomation!==null,'fleet_federation'=>true,'release_control'=>true,
				'local_first_sync'=>true,'browser_local_first_runtime'=>true,'encrypted_indexeddb_queue'=>true,'device_attested_sync'=>true,'durable_fenced_replay'=>true,'governed_marketplace'=>true,'marketplace_transparency'=>$this->marketplaceTrustNetwork!==null,'marketplace_revocation_propagation'=>$this->marketplaceTrustNetwork!==null,'publisher_evidence_profiles'=>$this->marketplaceTrustNetwork!==null,'studio_branching'=>true,
				'versioned_domain_history'=>true,'immutable_domain_versions'=>true,
				'transactional_domain_activation'=>$this->activationRuntime!==null,'domain_restart_recovery'=>$this->activationRuntime!==null,
				'unified_command_fabric'=>$this->commandFabric!==null,'signed_event_outbox'=>$this->commandFabric!==null,
				'closed_loop_intelligence'=>$this->closedLoopIntelligence!==null,'governed_recommendation_dispatch'=>$this->closedLoopIntelligence!==null,'outcome_effectiveness_feedback'=>$this->closedLoopIntelligence!==null,
					'durable_release_execution'=>$this->releaseExecutionEngine!==null,'release_execution_configured'=>$this->releaseExecutionEngine?->configured()??false,'fenced_release_recovery'=>$this->releaseExecutionEngine!==null,'automatic_release_rollback'=>$this->releaseExecutionEngine!==null,
					'durable_federation_transport'=>$this->federationGateway!==null,'federation_transport_configured'=>$this->federationGateway?->transportConfigured()??false,'encrypted_federation_outbox'=>$this->federationGateway!==null,'federation_exact_replay'=>$this->federationGateway!==null,
			],
		]);
	}

	private function compileTrusted(PanelDomainManifest $manifest):PanelDomainCompilation {return$this->domainCompiler->compile($manifest)->sign($this->domainKeyId,$this->domainKeys[$this->domainKeyId]);}

	private function trustedCompilation(PanelDomainManifest|PanelDomainCompilation|array $domain):PanelDomainCompilation {
		if($domain instanceof PanelDomainCompilation){if(!$this->verifyCompilation($domain)){throw new \LogicException('Operations OS domain compilation is not trusted.');}return$domain;}
		return$this->compileTrusted($domain instanceof PanelDomainManifest?$domain:PanelDomainManifest::from($domain));
	}

	private function rememberCompilation(PanelDomainCompilation $compilation):void {
		$known=$this->compilationHistory[$compilation->domainId()][$compilation->domainVersion()]??null;if($known instanceof PanelDomainCompilation&&!hash_equals($known->sourceFingerprint(),$compilation->sourceFingerprint())){throw new \LogicException('Published Operations OS domain versions are immutable.');}
		$this->compilations[$compilation->domainId()]=$known??$compilation;$this->compilationHistory[$compilation->domainId()][$compilation->domainVersion()]=$known??$compilation;uksort($this->compilationHistory[$compilation->domainId()],static fn(string $left,string $right):int=>version_compare($left,$right));ksort($this->compilations,SORT_STRING);
	}

	private function absorbLineage(string $domainId,PanelLineageGraph $graph):void {
		$manifest=$graph->jsonSerialize();$prefix=$domainId.':';
		foreach($manifest['nodes']??[]as$node){if(!is_array($node)){continue;}$this->lineage->node($prefix.$node['id'],(string)$node['kind'],(string)$node['label'],is_array($node['metadata']??null)?$node['metadata']:[],true);}
		foreach($manifest['edges']??[]as$edge){if(!is_array($edge)){continue;}$this->lineage->edge($prefix.$edge['from'],$prefix.$edge['to'],(string)$edge['kind'],is_array($edge['metadata']??null)?$edge['metadata']:[],true);}
	}

	/** @return \Closure(string,array<string,mixed>,mixed):PanelPolicyDecision */
	private static function policyAuthorizer(PanelPolicyControlPlane $policy,string|int|null $fallbackActor=null):\Closure {
		return static function(string $ability,array $context,mixed $subject=null)use($policy,$fallbackActor):PanelPolicyDecision {
			$actor=$context['actor_id']??$context['actorId']??$fallbackActor??'system';
			$tenant=$context['tenant_id']??$context['tenantId']??null;
			return$policy->evaluate(new PanelPolicyRequest(
				PanelOperationsGuard::identifier(is_int($actor)?$actor:(string)$actor,'policy actor id'),
				$ability,
				$tenant!==null?PanelOperationsGuard::identifier((string)$tenant,'policy tenant id'):null,
				null,
				null,
				isset($context['risk'])?(string)$context['risk']:'medium',
				is_array($context['roles']??null)?$context['roles']:[],
				is_array($context['permissions']??null)?$context['permissions']:[],
				$context,
			));
		};
	}

	/** @param array<string,string> $keys @return array<string,string> */
	private static function keys(array $keys,string $label):array {
		if($keys===[]){throw new \InvalidArgumentException(ucfirst($label).' keyring cannot be empty.');}
		$normalized=[];foreach($keys as$id=>$key){$id=PanelOperationsGuard::name((string)$id,$label.' key id');if(!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException(ucfirst($label).' keys require at least 32 bytes.');}$normalized[$id]=$key;}ksort($normalized,SORT_STRING);return$normalized;
	}

	/** @param array<string,mixed> $config @param array<string,string> $fallback @return array<string,string> */
	private static function configuredKeys(array $config,string $name,array $fallback):array {
		$value=$config[$name.'_keys']??$fallback;
		if(!is_array($value)){throw new \InvalidArgumentException('Operations OS '.$name.'_keys must be a keyring.');}
		return self::keys($value,$name);
	}

	/** @param array<string,mixed> $config @param array<string,string> $keys */
	private static function currentKey(array $config,string $name,string $fallback,array $keys):string {
		$id=PanelOperationsGuard::name((string)($config[$name.'_key_id']??$fallback),$name.' key id');
		if(!isset($keys[$id])){throw new \InvalidArgumentException('Operations OS '.$name.' current key is not trusted.');}
		return$id;
	}

	private function now():string {
		$value=$this->clock!==null?($this->clock)():gmdate('c');
		if(!$value instanceof \DateTimeInterface&&!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Operations OS clock must return an instant.');}
		return PanelOperationsGuard::instant($value);
	}
}
