<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/**
 * Deterministic, source-tree-only browser showroom for Panel's CI contracts.
 *
 * Run with PHP's built-in server and point the browser runners at /panel:
 *
 *   php -S 127.0.0.1:8098 runtime/modules/panel/testing/fixtures/panel_browser_showroom.php
 *
 * The fixture deliberately exercises public Panel APIs. It owns no production
 * routes or storage and keeps mutable records in the browser session only.
 */

namespace {
	if(PHP_SAPI!=='cli-server'){
		fwrite(STDERR, "panel_browser_showroom.php is a PHP built-in-server router.\n");
		exit(64);
	}
	set_time_limit(30);
	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY', [
			'enabled'=>['core'=>true, 'panel'=>true],
			'disabled'=>[],
			'core_implicit'=>true,
		]);
	}
	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			if(!defined($constant)){define($constant, $defaults);}
		}
	}
}

namespace dataphyre {
	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(mixed ...$arguments): void {\tracelog(...$arguments);}
	}
	if(!function_exists(__NAMESPACE__.'\\dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			\dp_define_module_config($module, $constant, $defaults);
		}
	}
}

namespace {
	use Dataphyre\Panel\Panel;
	use Dataphyre\Panel\PanelArrayDataSource;
	use Dataphyre\Panel\PanelCollaborationManager;
	use Dataphyre\Panel\PanelContext;
	use Dataphyre\Panel\PanelDataQuery;
	use Dataphyre\Panel\PanelDataSourceRegistry;
	use Dataphyre\Panel\PanelDataSurfaceContext;
	use Dataphyre\Panel\PanelDataSurfaceDefinition;
	use Dataphyre\Panel\PanelDataSurfaceEndpoint;
	use Dataphyre\Panel\PanelDataSurfaceIntentSigner;
	use Dataphyre\Panel\PanelDataSurfaceProjection;
	use Dataphyre\Panel\PanelDataSurfaceRange;
	use Dataphyre\Panel\PanelDataSurfaceRegistry;
	use Dataphyre\Panel\PanelDataSurfaceWindowRequest;
	use Dataphyre\Panel\PanelFilesystemCollaborationStore;
	use Dataphyre\Panel\PanelFilesystemStudioStore;
	use Dataphyre\Panel\PanelHost;
	use Dataphyre\Panel\PanelInMemoryStudioStore;
	use Dataphyre\Panel\PanelInstance;
	use Dataphyre\Panel\PanelPlatform;
	use Dataphyre\Panel\PanelPlatformAssets;
	use Dataphyre\Panel\PanelPlatformTemplate;
	use Dataphyre\Panel\PanelRequest;
	use Dataphyre\Panel\PanelRenderer;
	use Dataphyre\Panel\PanelRoute;
	use Dataphyre\Panel\PanelRouteParser;
	use Dataphyre\Panel\PanelStudioDefinition;
	use Dataphyre\Panel\PanelStudioDocument;
	use Dataphyre\Panel\PanelStudioEditor;
	use Dataphyre\Panel\PanelStudioEditorCommand;
	use Dataphyre\Panel\PanelStudioEditorOptions;
	use Dataphyre\Panel\PanelStudioArrayIdentityConnector;
	use Dataphyre\Panel\PanelStudioCollaborationConnector;
	use Dataphyre\Panel\PanelStudioCollaborationEndpoint;
	use Dataphyre\Panel\PanelStudioCollaborationIntentSigner;
	use Dataphyre\Panel\PanelStudioCollaborationTransport;
	use Dataphyre\Panel\PanelStudioManager;
	use Dataphyre\Panel\PanelStudioPolicy;
	use Dataphyre\Panel\PanelStudioPreviewSigner;
	use Dataphyre\Panel\PanelStudioVisualDataset;
	use Dataphyre\Panel\PanelStudioVisualRuntime;
	use Dataphyre\Panel\PanelWidgetInteractionContext;
	use Dataphyre\Panel\PanelWidgetInteractionDefinition;
	use Dataphyre\Panel\PanelWidgetInteractionException;
	use Dataphyre\Panel\PanelWidgetInteractionRequest;
	use Dataphyre\Panel\PanelWidgetInteractionResult;
	use Dataphyre\Panel\PanelWidgetInteractionState;
	use Dataphyre\Panel\PanelWidgetRuntimeAdapter;
	use Dataphyre\Panel\Resource;

	error_reporting(E_ALL);
	ini_set('display_errors', '1');
	$modulesRoot=dirname(__DIR__, 3);
	require_once $modulesRoot.'/core/kernel/autoloader.php';
	\dataphyre\autoloader::register($modulesRoot);
	\dataphyre\autoloader::register_framework_modules(['panel']);
	require_once $modulesRoot.'/panel/Framework/Bootstrap.php';

	function dp_panel_browser_context(string $prefix): array {
		$assetBuilder=static function(string $asset) use ($prefix): string {
			$asset=basename(str_replace('\\', '/', trim($asset)));
			$version=\Dataphyre\Panel\PanelRenderer::assetVersion($asset);
			return $prefix.'?dp_panel_asset='.rawurlencode($asset).'&v='.rawurlencode((string)$version);
		};
		return [
			'panel_mount_prefix'=>$prefix,
			'url_builder'=>PanelRoute::urlBuilder($prefix),
			'asset_url_builder'=>$assetBuilder,
			'upload_url_builder'=>static fn(): string=>$prefix.'?dp_panel_upload=1',
		];
	}

	function dp_panel_browser_emit_asset(string $modulesRoot): void {
		$path=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
		$kernel=null;
		$binding=null;
		if(isset($_GET['dp_panel_asset']) && is_scalar($_GET['dp_panel_asset'])){
			$kernel=$modulesRoot.'/panel/kernel/assets.php';
			$binding=basename(str_replace('\\', '/', (string)$_GET['dp_panel_asset']));
		}
		elseif(isset($_GET['dp_panel_upload'])){
			$kernel=$modulesRoot.'/panel/kernel/upload.php';
		}
		elseif(preg_match('#^/panel/assets/[^/]+$#', $path)===1){
			$kernel=$modulesRoot.'/panel/kernel/assets.php';
			$binding=basename($path);
		}
		elseif(
			is_scalar($_GET['resource'] ?? null)
			&& Resource::normalizeName((string)$_GET['resource'])==='assets'
			&& is_scalar($_GET['operation'] ?? null)
		){
			$kernel=$modulesRoot.'/panel/kernel/assets.php';
			$binding=basename(str_replace('\\', '/', (string)$_GET['operation']));
		}
		if($kernel===null){return;}
		if(!class_exists('\\dataphyre\\routing', false)){
			require_once __DIR__.'/panel_browser_showroom_routing_stub.php';
		}
		if(is_string($binding) && $binding!==''){
			\dataphyre\routing::$bindings['asset']=$binding;
			$_GET['asset']=$binding;
			$_REQUEST['asset']=$binding;
		}
		require $kernel;
		exit;
	}

	function dp_panel_browser_normalize_path(): void {
		if(isset($_GET['resource']) && is_scalar($_GET['resource']) && trim((string)$_GET['resource'])!==''){return;}
		$path=trim(rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '')), '/');
		if($path==='panel' || !str_starts_with($path, 'panel/')){return;}
		$segments=array_values(array_filter(explode('/', substr($path, 6)), static fn(string $segment): bool=>$segment!==''));
		if($segments===[]){return;}
		foreach(PanelRouteParser::infer($segments) as $key=>$value){
			if($value===null || $value===''){continue;}
			if(in_array($key, ['resource', 'operation', 'record', 'action', 'relation'], true)){
				$_GET[$key]=$value;
				$_REQUEST[$key]=$value;
			}
			elseif(!isset($_GET[$key])){
				$_GET[$key]=$value;
				$_REQUEST[$key]=$value;
			}
		}
	}

	function dp_panel_browser_normalize_theme(): void {
		$theme=is_scalar($_GET['theme'] ?? null) ? Resource::normalizeName((string)$_GET['theme']) : '';
		if(in_array($theme, ['light', 'dark', 'system'], true)){
			$_GET['mode']=$_GET['mode'] ?? $theme;
			$_REQUEST['mode']=$_REQUEST['mode'] ?? $theme;
			unset($_GET['theme'], $_REQUEST['theme']);
		}
	}

	function dp_panel_browser_url(string $path='', array $query=[]): string {
		$url='/panel'.($path!=='' ? '/'.ltrim($path, '/') : '');
		return $query===[] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	function dp_panel_browser_e(mixed $value): string {
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** @return array<string,mixed> */
	function dp_panel_browser_studio_runtime(): array {
		$root=sys_get_temp_dir().'/dataphyre-panel-browser-showroom/'.hash('sha256', session_id());
		$tick=0;
		$clock=static function() use (&$tick): string {$tick++;return sprintf('2026-07-20T12:00:%02d+00:00', $tick%60);};
		$previewSigner=new PanelStudioPreviewSigner(['browser-v1'=>str_repeat('K', 32)], 'browser-v1', static fn(): int=>time(), static fn(): string=>'browserstudioeditorpreview0001');
		$manager=new PanelStudioManager(new PanelFilesystemStudioStore($root.'/studio'), PanelStudioPolicy::trustedMaintenance(['browser-fixture']), null, $previewSigner, 0, $clock);
		$document=PanelStudioDocument::make('browser-tenant', 'orders-studio-browser', 'Orders workspace editor');
		$initial=PanelStudioDefinition::from([
			'kind'=>'page','key'=>'orders','properties'=>['label'=>'Orders workspace','description'=>'Compose a trusted order-management surface.'],'children'=>[
				['kind'=>'form','key'=>'order_form','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
					['kind'=>'form_section','key'=>'identity','properties'=>['label'=>'Identity','columns'=>2],'children'=>[
						['kind'=>'field','key'=>'name','properties'=>['label'=>'Customer name','required'=>true],'children'=>[]],
						['kind'=>'field','key'=>'email','properties'=>['label'=>'Email address','type'=>'email','required'=>true],'children'=>[]],
						['kind'=>'field','key'=>'market','properties'=>['label'=>'Market','type'=>'select','options'=>['ca'=>'Canada','us'=>'United States','eu'=>'European Union']],'children'=>[]],
					]],
					['kind'=>'form_section','key'=>'fulfilment','properties'=>['label'=>'Fulfilment','columns'=>2],'children'=>[
						['kind'=>'field','key'=>'channel','properties'=>['label'=>'Channel','type'=>'select','options'=>['retail'=>'Retail','wholesale'=>'Wholesale']],'children'=>[]],
						['kind'=>'field','key'=>'status','properties'=>['label'=>'Status','type'=>'select','options'=>['review'=>'Review','packing'=>'Packing']],'children'=>[]],
					]],
				]],
				['kind'=>'table','key'=>'orders_table','properties'=>['density'=>'compact'],'children'=>[
					['kind'=>'column','key'=>'id','properties'=>['label'=>'Order ID','sortable'=>true],'children'=>[]],
					['kind'=>'column','key'=>'customer','properties'=>['label'=>'Customer','sortable'=>true],'children'=>[]],
				]],
			],
		]);
		$checkpoint=$_SESSION['dp_panel_studio_checkpoint'] ?? null;
		$session=is_array($checkpoint)
			?PanelStudioEditor::resume($manager, $document, 'browser-fixture', $checkpoint)
			:PanelStudioEditor::open($manager, $document, 'browser-fixture', $initial);
		if($session->baseRevision()===0){
			$session->save('studio-editor-browser-save');
			$session->apply(PanelStudioEditorCommand::select('orders/order_form/identity/email'));
		}
		$collaboration=new PanelStudioCollaborationConnector(
			new PanelCollaborationManager(
				new PanelFilesystemCollaborationStore($root.'/collaboration'),
				static fn(string $operation,?string $actor): bool=>$actor==='browser-fixture',
			),
			new PanelStudioArrayIdentityConnector([
				['id'=>'browser-fixture','display_name'=>'Avery Designer','status'=>'active','source'=>'showroom'],
				['id'=>'mina','display_name'=>'Mina Reviewer','status'=>'active','source'=>'showroom'],
				['id'=>'noah','display_name'=>'Noah Observer','status'=>'suspended','source'=>'showroom'],
			], 'browser-tenant')
		);
		if($collaboration->snapshot($session)->threads()===[]){
			$thread=$collaboration->handle($session, [
				'studio_collaboration_operation'=>'create_thread',
				'studio_collaboration_title'=>'Confirm the fulfilment handoff',
			]);
			$threadId=(string)$thread->resourceId();
			$collaboration->handle($session, [
				'studio_collaboration_operation'=>'comment:'.$threadId,
				'studio_collaboration_comments'=>[$threadId=>'The order surface is ready for a focused review.'],
			]);
			$collaboration->handle($session, ['studio_collaboration_operation'=>'assign','studio_collaboration_assignee'=>'mina']);
			$collaboration->handle($session, ['studio_collaboration_operation'=>'watch']);
		}
		$intentSigner=new PanelStudioCollaborationIntentSigner(
			['browser-live-v1'=>str_repeat('L', 48)],
			'browser-live-v1',
		);
		$transport=new PanelStudioCollaborationTransport(
			'/panel?dp_panel_studio_collaboration=1',
			$intentSigner->issue($session),
			[
				'visible_poll_milliseconds'=>500,
				'hidden_poll_milliseconds'=>1500,
				'maximum_backoff_milliseconds'=>5000,
				'request_timeout_milliseconds'=>5000,
				'presence_heartbeat_milliseconds'=>5000,
				'typing_idle_milliseconds'=>500,
			],
		);
		$options=PanelStudioEditorOptions::make([
			'action_url'=>'/studio/edit','preview_url'=>'/studio/preview','csrf_name'=>'_token','csrf_token'=>str_repeat('C', 32),
			'theme'=>'dark','direction'=>'ltr','title'=>'Panel Studio','editor_id'=>'orders-studio-browser','inline_assets'=>true,'zoom'=>'100','reflow'=>'desktop',
			'collaboration_connector'=>$collaboration,
			'collaboration_transport'=>$transport,
		]);
		$_SESSION['dp_panel_studio_checkpoint']=PanelStudioEditor::checkpoint($session);
		return compact('session','collaboration','intentSigner','options');
	}

	/** Renders the public route-free Studio Editor against deterministic trusted schemas. */
	function dp_panel_browser_studio_editor_html(): string {
		$runtime=dp_panel_browser_studio_runtime();
		return PanelStudioEditor::render($runtime['session'], $runtime['options']);
	}

	/** Emits the signed route-neutral Studio collaboration transport. */
	function dp_panel_browser_emit_studio_collaboration(): bool {
		if(!isset($_GET['dp_panel_studio_collaboration'])){return false;}
		$runtime=dp_panel_browser_studio_runtime();
		$endpoint=(new PanelStudioCollaborationEndpoint($runtime['intentSigner']))->authorizeHost(
			static fn(string $action,\Dataphyre\Panel\PanelStudioEditorSession $session,array $context): bool=>
				$session->principalId()==='browser-fixture'&&in_array($action,['delta','mutate','presence_sync','presence_release','typing'],true),
		);
		$presence=$_SESSION['dp_panel_studio_presence_token'] ?? null;
		$result=$endpoint->handle(
			$runtime['session'],
			$runtime['options'],
			$_POST,
			is_string($presence)?$presence:null,
			'studio-browser-'.substr(hash('sha256', session_id()."\0".microtime(true)),0,20),
		);
		if($result->presenceDisposition()==='replace'){
			$_SESSION['dp_panel_studio_presence_token']=$result->trustedPresenceToken();
		}elseif($result->presenceDisposition()==='clear'){
			unset($_SESSION['dp_panel_studio_presence_token']);
		}
		$_SESSION['dp_panel_studio_checkpoint']=PanelStudioEditor::checkpoint($runtime['session']);
		http_response_code($result->status());
		foreach($result->headers() as $name=>$value){header($name.': '.$value);}
		$content=$result->content();
		header('Content-Length: '.strlen($content));
		echo $content;
		return true;
	}

	/** Emits a standalone Studio document from the same server used by the unified browser registry. */
	function dp_panel_browser_emit_studio_editor(): bool {
		if(!isset($_GET['dp_panel_studio_editor'])){return false;}
		$html='<!doctype html><html lang="en" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Panel Studio Browser Lab</title><style>html{background:#07101e}body{margin:0;min-width:0;padding:16px;background:transparent;font-family:Inter,ui-sans-serif,system-ui,sans-serif}@media(max-width:520px){body{padding:0}}</style></head><body>'.dp_panel_browser_studio_editor_html().'</body></html>';
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store');
		header('Content-Length: '.strlen($html));
		echo $html;
		return true;
	}

	/**
	 * Emits a dense deterministic Operations OS document for visual and
	 * responsive browser certification. The payload mirrors the production
	 * redacted read model and deliberately contains every table/control shape.
	 */
	function dp_panel_browser_emit_operations_console(): bool {
		if(!isset($_GET['dp_panel_operations_console'])){return false;}
		$snapshot=[
			'tenant_id'=>'browser-tenant',
			'generated_at'=>'2026-07-16T16:00:00Z',
			'attention'=>[
				['severity'=>'critical','code'=>'domain_drift','message'=>'One active domain differs from its signed compilation.','section'=>'domains'],
				['severity'=>'critical','code'=>'work_overdue','message'=>'One work item has crossed its governed SLA.','section'=>'work'],
				['severity'=>'warning','code'=>'commands_executing','message'=>'Two commands are executing or awaiting recovery.','section'=>'fabric'],
				['severity'=>'info','code'=>'release_paused','message'=>'The canary release ring is paused.','section'=>'fleet'],
			],
			'status'=>['available'=>true,'command_fabric_sequence'=>427,'compliance_chain_verified'=>true],
			'work'=>[
				'available'=>true,'scoped'=>true,'tenant_id'=>'browser-tenant','sla'=>['total'=>7,'overdue'=>1,'unassigned'=>1],
				'queue'=>[
					['id'=>'case_release_042','title'=>'Approve regulated release EU-42','state'=>'waiting_approval','priority'=>95,'queue'=>'release_governance','assignee'=>'Mina','due_at'=>'2026-07-16T17:00:00Z'],
					['id'=>'incident_fabric_017','title'=>'Reconcile subscriber cursor lag','state'=>'in_progress','priority'=>82,'queue'=>'platform_integrity','assignee'=>'Noah','due_at'=>'2026-07-16T18:15:00Z'],
					['id'=>'review_package_103','title'=>'Review marketplace package controls','state'=>'open','priority'=>61,'queue'=>'marketplace_review','assignee'=>null,'due_at'=>'2026-07-17T12:00:00Z'],
				],
			],
			'fabric'=>[
				'available'=>true,'integrity'=>['ok'=>true],'sequence'=>427,'commands'=>63,'receipts'=>63,'events'=>112,'executing'=>2,
				'journal'=>[
					['id'=>'command_4ab190cb63f9c7e1','command'=>'operations_os.release.pause','tenant_id'=>'browser-tenant','risk'=>'high','status'=>'succeeded','attempts'=>1,'updated_at'=>'2026-07-16T15:58:20Z'],
					['id'=>'command_daa7693b56c0ac8f','command'=>'workflow.approval.request','tenant_id'=>'browser-tenant','risk'=>'medium','status'=>'executing','attempts'=>2,'updated_at'=>'2026-07-16T15:57:08Z'],
					['id'=>'command_0c083a599b430928','command'=>'domain.activate','tenant_id'=>'browser-tenant','risk'=>'critical','status'=>'failed','attempts'=>3,'updated_at'=>'2026-07-16T15:51:45Z'],
				],
				'event_stream'=>[
					['sequence'=>427,'id'=>'event_427','event_type'=>'operations_os.release.paused','aggregate_type'=>'release_ring','aggregate_id'=>'canary','tenant_id'=>'browser-tenant','occurred_at'=>'2026-07-16T15:58:20Z'],
					['sequence'=>426,'id'=>'event_426','event_type'=>'workflow.approval.requested','aggregate_type'=>'release','aggregate_id'=>'EU-42','tenant_id'=>'browser-tenant','occurred_at'=>'2026-07-16T15:57:08Z'],
					['sequence'=>425,'id'=>'event_425','event_type'=>'work.assignment.changed','aggregate_type'=>'case','aggregate_id'=>'incident_fabric_017','tenant_id'=>'browser-tenant','occurred_at'=>'2026-07-16T15:54:02Z'],
				],
				'subscribers'=>[
					['name'=>'search_projection','patterns'=>['domain.*','work.*','workflow.*'],'cursor'=>427],
					['name'=>'compliance_projection','patterns'=>['*'],'cursor'=>424],
				],
			],
			'domains'=>[
				'available'=>true,'active_count'=>3,'drifted_count'=>1,
				'items'=>[
					['id'=>'commerce','version'=>'3.8.1','active'=>true,'drifted'=>false,'trusted'=>true,'history_depth'=>12,'drift_channels'=>[]],
					['id'=>'fulfilment','version'=>'2.4.0','active'=>true,'drifted'=>true,'trusted'=>true,'history_depth'=>9,'drift_channels'=>['workflow_projection','policy_binding']],
					['id'=>'support','version'=>'1.9.3','active'=>true,'drifted'=>false,'trusted'=>true,'history_depth'=>6,'drift_channels'=>[]],
				],
			],
			'policy'=>['available'=>true,'revision'=>48,'bundle_count'=>7,'default_deny'=>true,'deny_overrides'=>true,'kill_switches'=>['catalog.destructive.*']],
			'intelligence'=>[
				'available'=>true,
				'operator'=>['model_count'=>2,'tool_count'=>18,'evaluator_count'=>5,'executor_configured'=>true,'models'=>[
					['id'=>'reasoner-primary','provider'=>'OpenAI','model'=>'gpt-5.6','health'=>'healthy','regions'=>['ca-central','us-east'],'classifications'=>['internal','restricted']],
					['id'=>'local-fallback','provider'=>'Dataphyre','model'=>'operator-small','health'=>'degraded','regions'=>['ca-central'],'classifications'=>['public','internal']],
				]],
				'semantics'=>['metric_count'=>34],'lineage'=>['node_count'=>188,'edge_count'=>471],
			],
			'compliance'=>['available'=>true,'verified'=>true,'control_count'=>29,'evidence_count'=>816,'active_hold_count'=>2,'sequence'=>1194],
			'fleet'=>[
				'available'=>true,
				'federation'=>['node_count'=>2,'drift_count'=>0,'nodes'=>[
					['id'=>'example-ca-01','environment'=>'production','region'=>'ca-central','capability_count'=>24,'expires_at'=>'2026-07-17T16:00:00Z'],
					['id'=>'example-eu-01','environment'=>'production','region'=>'eu-west','capability_count'=>22,'expires_at'=>'2026-07-17T15:45:00Z'],
				]],
				'releases'=>['deployment_count'=>14,'rings'=>[
					['name'=>'canary','traffic_basis_points'=>500,'health_gate_count'=>6,'paused'=>true],
					['name'=>'progressive','traffic_basis_points'=>2500,'health_gate_count'=>8,'paused'=>false],
					['name'=>'stable','traffic_basis_points'=>7000,'health_gate_count'=>10,'paused'=>false],
				]],
				'marketplace'=>['reviews'=>[
					['package_id'=>'dataphyre/warehouse-bridge','package_version'=>'4.2.0','status'=>'review','risk_score'=>28,'finding_count'=>2,'approval_count'=>1,'required_approvals'=>2],
					['package_id'=>'example_publisher/operator-toolkit','package_version'=>'1.7.4','status'=>'approved','risk_score'=>6,'finding_count'=>0,'approval_count'=>2,'required_approvals'=>2],
				]],
			],
		];
		$result=PanelPlatformTemplate::operationsOs($snapshot,[
			'action_url'=>'/panel?dp_panel_operations_console=1','control_tenant'=>'browser-tenant','eyebrow'=>'DATAPHYRE CONTROL PLANE',
			'csrf_name'=>'_token','csrf_token'=>str_repeat('C',32),
		]);
		$mode=Resource::normalizeName((string)($_GET['mode']??'dark'));
		$light=$mode==='light';
		$tokens=$light
			?'--dp-text:#162033;--dp-text-muted:#637083;--dp-accent:#155eef;--dp-border:#d6dce5;--dp-border-strong:#aab4c3;--dp-surface:#fff;--dp-surface-raised:#f4f6f9;--dp-control-bg:#fff;--dp-danger:#c4243d;--dp-warning:#9a5c00;--dp-success:#14763a;'
			:'--dp-text:#e8edf6;--dp-text-muted:#9eabc0;--dp-accent:#69a1ff;--dp-border:#2b3850;--dp-border-strong:#455470;--dp-surface:#101827;--dp-surface-raised:#172238;--dp-control-bg:#0b1220;--dp-danger:#ff6579;--dp-warning:#ffb74d;--dp-success:#54d98c;';
		$html='<!doctype html><html lang="en" dir="ltr" style="'.$tokens.'color-scheme:'.($light?'light':'dark').';background:'.($light?'#f6f8fb':'#08101d').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Panel Operations OS Browser Lab</title><style>'.PanelPlatformAssets::stylesheet().'html{min-inline-size:0}body{box-sizing:border-box;max-inline-size:112rem;margin:0 auto;padding:clamp(.75rem,2vw,2rem);background:transparent;color:var(--dp-text);font-family:Inter,ui-sans-serif,system-ui,sans-serif}*,*:before,*:after{box-sizing:border-box}@media(max-width:30rem){body{padding:.5rem}}</style></head><body>'.$result->content().'</body></html>';
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store');
		header('Content-Length: '.strlen($html));
		echo $html;
		return true;
	}

	/** Renders the trusted Studio visual adapter through the selected Panel instance. */
	function dp_panel_browser_emit_studio_visual(PanelInstance $panel): bool {
		if(!isset($_GET['dp_panel_studio_visual'])){return false;}
		$mode=Resource::normalizeName((string)($_GET['dp_panel_studio_visual_mode'] ?? 'dark'));
		$_COOKIE['dataphyre_panel_theme_mode']=in_array($mode, ['light','dark','system'], true) ? $mode : 'dark';
		$store=new PanelInMemoryStudioStore();
		$runtime=new PanelStudioVisualRuntime();
		$manager=new PanelStudioManager(
			$store,
			PanelStudioPolicy::trustedMaintenance(['browser-fixture']),
			visualRuntime:$runtime,
		);
		$platform=PanelPlatform::make([
			'studio.store'=>$store,
			'studio.compiler'=>$manager->compiler(),
			'studio.registry'=>$manager->registry(),
			'studio.materializer'=>$manager->materializer(),
			'studio.manager'=>$manager,
			'studio.visual_runtime'=>$runtime,
		]);
		$panel->usePlatform($platform, true);
		$document=PanelStudioDocument::make('browser-tenant', 'operations-studio-visual', 'Operations visual preview');
		$definition=PanelStudioDefinition::from([
			'kind'=>'page','key'=>'operations','properties'=>['label'=>'Operations studio','description'=>'Actual trusted Panel surfaces.','layout'=>'masonry'],'children'=>[
				['kind'=>'form','key'=>'order_form','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
					['kind'=>'form_section','key'=>'identity','properties'=>['label'=>'Identity','columns'=>2],'children'=>[
						['kind'=>'field','key'=>'customer','properties'=>['label'=>'Customer','required'=>true],'children'=>[]],
						['kind'=>'field','key'=>'email','properties'=>['label'=>'Email','type'=>'email','required'=>true],'children'=>[]],
						['kind'=>'field','key'=>'market','properties'=>['label'=>'Market','type'=>'select','options'=>['ca'=>'Canada','us'=>'United States','eu'=>'European Union']],'children'=>[]],
					]],
				]],
				['kind'=>'table','key'=>'orders_table','properties'=>['per_page'=>10,'density'=>'compact'],'children'=>[
					['kind'=>'column','key'=>'title','properties'=>['label'=>'Order','searchable'=>true],'children'=>[]],
					['kind'=>'column','key'=>'total','properties'=>['label'=>'Total','type'=>'money','currency'=>'CAD'],'children'=>[]],
					['kind'=>'table_views','key'=>'views','properties'=>['layout'=>'masonry'],'children'=>[
						['kind'=>'table_view','key'=>'active','properties'=>['label'=>'Active','default'=>true],'children'=>[]],
					]],
				]],
				['kind'=>'infolist','key'=>'order_summary','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
					['kind'=>'infolist_entry','key'=>'title','properties'=>['label'=>'Order'],'children'=>[]],
					['kind'=>'infolist_entry','key'=>'state','properties'=>['label'=>'Status','type'=>'badge'],'children'=>[]],
				]],
				['kind'=>'board','key'=>'fulfilment','properties'=>['label'=>'Fulfilment','status_field'=>'state','layout'=>'masonry','card_layout'=>'brick'],'children'=>[
					['kind'=>'board_column','key'=>'queued','properties'=>['label'=>'Queued','status'=>'Queued','accepts_moves'=>false],'children'=>[]],
					['kind'=>'board_column','key'=>'done','properties'=>['label'=>'Done','status'=>'Done','tone'=>'success','from'=>['Queued'],'transition'=>'complete'],'children'=>[]],
				]],
				['kind'=>'widget_grid','key'=>'metrics','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
					['kind'=>'widget','key'=>'volume','properties'=>['label'=>'Volume','value'=>42,'tone'=>'primary'],'children'=>[]],
					['kind'=>'widget','key'=>'trend','properties'=>['label'=>'Trend','type'=>'chart','chart_type'=>'bar','labels'=>['A','B','C'],'data'=>[2,5,3]],'children'=>[]],
				]],
				['kind'=>'navigation','key'=>'workspace_navigation','properties'=>['label'=>'Workspace'],'children'=>[
					['kind'=>'navigation_item','key'=>'orders','properties'=>['label'=>'Orders','url'=>'/orders','badge'=>12],'children'=>[]],
					['kind'=>'navigation_item','key'=>'settings','properties'=>['label'=>'Settings','url'=>'/settings'],'children'=>[]],
				]],
			],
		]);
		$session=PanelStudioEditor::open($manager, $document, 'browser-fixture', $definition);
		$session->apply(PanelStudioEditorCommand::select('operations/order_form/identity/email'));
		$dataset=new PanelStudioVisualDataset([
			['id'=>1,'title'=>'SO-260714-001','customer'=>'Avery Stone','email'=>'avery@example.test','market'=>'ca','state'=>'Queued','total'=>148.25,'password'=>'browser-secret'],
			['id'=>2,'title'=>'SO-260714-002','customer'=>'Maya Chen','email'=>'maya@example.test','market'=>'eu','state'=>'Done','total'=>92.75,'api_token'=>'browser-token'],
		]);
		$preview=$panel->renderStudioVisualPreview($session, $dataset, PanelRequest::fromArray([
			'method'=>'GET','resource'=>'operations','operation'=>'page','tenant'=>'browser-tenant','query'=>['density'=>'compact','view'=>'active'],
		]));
		$response=$preview->response(isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string)$_SERVER['HTTP_IF_NONE_MATCH'] : null);
		http_response_code($response->status());
		foreach($response->headers() as$name=>$value){header($name.': '.$value);}
		header('Content-Length: '.strlen($response->content()));
		echo$response->content();
		return true;
	}

	function dp_panel_browser_seed(): void {
		if(isset($_SESSION['dp_panel_browser']) && is_array($_SESSION['dp_panel_browser'])){return;}
		$customers=['Maya Chen','Noel Dupuis','Nora Silva','Avery Stone','Iris Patel','Clara Roy'];
		$owners=['Mina','Noah','Owen','Ari'];
		$statuses=['review','paid','packing','shipped'];
		$risks=['critical','high','medium','low'];
		$markets=['CA','US','EU'];
		$orders=[];
		for($id=1;$id<=16;$id++){
			$status=$statuses[($id-1)%count($statuses)];
			if($id===4){$status='review';}
			$orders[]=[
				'id'=>$id,
				'number'=>'SO-260505-'.str_pad((string)$id, 4, '0', STR_PAD_LEFT),
				'customer'=>$customers[($id-1)%count($customers)],
				'email'=>'buyer'.$id.'@example.test',
				'market'=>$markets[($id-1)%count($markets)],
				'channel'=>$id%2===0 ? 'wholesale' : 'marketplace',
				'status'=>$status,
				'risk'=>$risks[($id-1)%count($risks)],
				'total'=>round(22.27+$id*20.64, 2),
				'margin'=>round(8.5+$id*1.3, 1),
				'owner'=>$owners[($id-1)%count($owners)],
				'sla_minutes'=>$id%5===0 ? -15 : 30+$id*12,
				'internal_note'=>$id%3===0 ? 'Manual risk review requested.' : '',
				'customer_note'=>$id%4===0 ? 'Please confirm the delivery window.' : '',
				'submitted_at'=>'2026-05-05 '.str_pad((string)(8+($id%10)), 2, '0', STR_PAD_LEFT).':15:00',
				'updated_at'=>'2026-05-05 '.str_pad((string)(9+($id%10)), 2, '0', STR_PAD_LEFT).':30:00',
				'items'=>$id===4 ? [] : [
					['position'=>1,'sku'=>'SKU-'.$id.'-A','title'=>'Panel product '.$id,'quantity'=>1+$id%3,'price'=>12.5+$id,'supplier'=>'North Supply','supplier_note'=>'','line_total'=>(1+$id%3)*(12.5+$id)],
					['position'=>2,'sku'=>'SKU-'.$id.'-B','title'=>'Companion item '.$id,'quantity'=>1,'price'=>8.75+$id,'supplier'=>'Maple Works','supplier_note'=>'Packed together','line_total'=>8.75+$id],
				],
			];
		}
		$_SESSION['dp_panel_browser']=['orders'=>$orders];
	}

	/** @return array<int,array<string,mixed>> */
	function dp_panel_browser_orders(): array {
		return is_array($_SESSION['dp_panel_browser']['orders'] ?? null) ? $_SESSION['dp_panel_browser']['orders'] : [];
	}

	function dp_panel_browser_patch(int|string $id, array $patch): void {
		foreach($_SESSION['dp_panel_browser']['orders'] as $index=>$order){
			if((string)($order['id'] ?? '')!==(string)$id){continue;}
			$_SESSION['dp_panel_browser']['orders'][$index]=array_replace($order, $patch);
			return;
		}
	}

	/**
	 * Session-backed browser-fixture adapter for a real multi-request island.
	 *
	 * Panel's production in-memory conformance adapter is intentionally process
	 * local. The showroom crosses HTTP request boundaries, so this fixture keeps
	 * only its tiny counter state in the existing browser-test session while all
	 * dispatch, scope binding, rendering, and client behavior use public APIs.
	 */
	final class PanelBrowserSessionWidgetRuntimeAdapter implements PanelWidgetRuntimeAdapter {
		private const ENDPOINT='/panel?dp_panel_widget_runtime=1';
		private const SNAPSHOT_KEY='browser-showroom-widget-snapshot-fixture-key-v1';

		public function name(): string {return 'browser_fixture';}
		public function contractVersion(): int {return 1;}

		public function handle(PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult {
			if($definition->component()!=='counter'){
				return $this->failure($request, 'widget_component_unavailable', 'Interactive updates are unavailable.', 404);
			}
			$sessions=&$this->sessions();
			$island=$request->islandId();
			$session=$sessions[$island] ?? null;
			if($request->operation()==='mount'){
				$replayed=is_array($session) && ($session['mounted'] ?? false)===true;
				if(!$replayed){
					$session=['value'=>40,'version'=>1,'mounted'=>true,'snapshot'=>$this->snapshot($island)];
					$sessions[$island]=$session;
				}
				return $this->success($request, $context, $session, $replayed);
			}
			if(!is_array($session) || ($session['mounted'] ?? false)!==true || !is_string($request->snapshot()) || !hash_equals((string)($session['snapshot'] ?? ''), $request->snapshot())){
				return $this->failure($request, 'widget_session_invalid', 'The widget session is no longer valid.', 409);
			}
			if(in_array($request->operation(), ['action','refresh','unmount'], true) && $request->expectedVersion()!==(int)$session['version']){
				return $this->failure($request, 'widget_version_conflict', 'The widget changed in another request. Refresh and try again.', 409);
			}
			if($request->operation()==='action'){
				if($request->action()!=='increment' || !$definition->allows('increment')){
					return $this->failure($request, 'widget_action_unavailable', 'This widget action is not available.', 404);
				}
				$session['value']=(int)$session['value']+1;
				$session['version']++;
			}
			elseif($request->operation()==='refresh'){
				$session['value']=(int)$session['value']+10;
				$session['version']++;
			}
			elseif($request->operation()==='unmount'){
				$session['version']++;
				$session['mounted']=false;
			}
			$sessions[$island]=$session;
			return $this->success($request, $context, $session);
		}

		public function manifest(): array {
			return [
				'type'=>'panel_widget_runtime_adapter',
				'name'=>$this->name(),
				'contract_version'=>$this->contractVersion(),
				'components'=>['counter'=>['increment']],
				'capabilities'=>['fixture_session_state'=>true,'durable'=>false,'multi_process'=>false],
			];
		}

		public function reset(): void {unset($_SESSION['dp_panel_browser']['widget_runtime']);}

		/** @return array<string,array<string,mixed>> */
		private function &sessions(): array {
			if(!is_array($_SESSION['dp_panel_browser']['widget_runtime'] ?? null)){$_SESSION['dp_panel_browser']['widget_runtime']=[];}
			return $_SESSION['dp_panel_browser']['widget_runtime'];
		}

		/** @param array<string,mixed> $session */
		private function success(PanelWidgetInteractionRequest $request, PanelWidgetInteractionContext $context, array $session, bool $replayed=false): PanelWidgetInteractionResult {
			$state=($session['mounted'] ?? false)===true
				? PanelWidgetInteractionState::ready(['value'=>(int)$session['value']], (int)$session['version'], $replayed ? 'Widget state restored.' : 'Widget updated.')
				: PanelWidgetInteractionState::make('unmounted', (int)$session['version'], [], null, 'Widget session ended.');
			return PanelWidgetInteractionResult::success($this->name(), $request->islandId(), $state, self::ENDPOINT, (string)$session['snapshot'], $context->bindingTag(), $replayed);
		}

		private function failure(PanelWidgetInteractionRequest $request, string $code, string $message, int $status): PanelWidgetInteractionResult {
			return PanelWidgetInteractionResult::failure($this->name(), $request->islandId(), new PanelWidgetInteractionException($code, $message, $status));
		}

		private function snapshot(string $island): string {return 'fixture.'.hash_hmac('sha256', $island, self::SNAPSHOT_KEY);}
	}

	function dp_panel_browser_widget_definition(): PanelWidgetInteractionDefinition {
		return PanelWidgetInteractionDefinition::make('browser_fixture', 'counter')
			->action('increment', 'Increment')
			->refresh('manual')
			->retryLimit(0);
	}

	/** Emits the same-origin public Widget result envelope used by the browser runtime. */
	function dp_panel_browser_emit_widget_runtime(PanelInstance $panel): bool {
		if(!isset($_GET['dp_panel_widget_runtime'])){return false;}
		$payload=[];
		$island='dpwi-browser-invalid';
		try{
			$decoded=json_decode((string)file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
			if(!is_array($decoded) || array_is_list($decoded)){throw new InvalidArgumentException('Widget request must be an object.');}
			$payload=$decoded;
			if(is_string($decoded['island_id'] ?? null) && preg_match('/^[a-z][a-z0-9_.-]{0,95}$/', $decoded['island_id'])===1){$island=$decoded['island_id'];}
			$request=PanelWidgetInteractionRequest::fromArray($payload);
			$panelRequest=PanelRequest::fromArray(['method'=>'POST','input'=>$payload,'user'=>['id'=>'browser-operator']]);
			$context=$panel->widgetRuntime()->context($panel, $panelRequest, 'dashboard');
			$result=$panel->widgetRuntime()->dispatch(dp_panel_browser_widget_definition(), $context, $request);
		}
		catch(Throwable){
			$result=PanelWidgetInteractionResult::failure('browser_fixture', $island, new PanelWidgetInteractionException('widget_request_invalid', 'The widget request is invalid.', 400));
		}
		$body=json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		$serialized=$result->jsonSerialize();
		if(($serialized['state']['status'] ?? null)==='unmounted'){
			$terminalSealed=array_key_exists('endpoint', $serialized) && $serialized['endpoint']===null
				&& array_key_exists('snapshot', $serialized) && $serialized['snapshot']===null
				&& array_key_exists('binding_tag', $serialized) && $serialized['binding_tag']===null
				&& ($serialized['retryable'] ?? null)===false
				&& array_key_exists('error_code', $serialized['state']) && $serialized['state']['error_code']===null
				&& ($serialized['state']['data'] ?? null)===[];
			header('X-Dataphyre-Panel-Widget-Terminal: '.($terminalSealed ? 'sealed' : 'invalid'));
		}
		http_response_code($result->httpStatus());
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('Content-Length: '.strlen($body));
		echo $body;
		return true;
	}

	/** @return array{registry:PanelDataSurfaceRegistry,definition:PanelDataSurfaceDefinition,context:PanelDataSurfaceContext} */
	function dp_panel_browser_data_surface_fixture(): array {
		$rows=array_map(static fn(array $order): array=>[
			'id'=>(int)$order['id'],
			'tenant_id'=>'browser-tenant',
			'number'=>(string)$order['number'],
			'customer'=>(string)$order['customer'],
			'status'=>(string)$order['status'],
			'total'=>(float)$order['total'],
		], dp_panel_browser_orders());
		$sources=(new PanelDataSourceRegistry())->register('browser_orders', new PanelArrayDataSource($rows, ['name'=>'browser_orders']));
		$signer=new PanelDataSurfaceIntentSigner(['browser-v1'=>'browser-showroom-data-surface-signing-key-v1'], 'browser-v1');
		$projection=PanelDataSurfaceProjection::make(
			['id','number','customer','status','total'],
			'id',
			['title'=>'number','description'=>'customer','badge'=>'status','meta'=>'total'],
			['id'=>'ID','number'=>'Order','customer'=>'Customer','status'=>'Status','total'=>'Total']
		);
		$definition=PanelDataSurfaceDefinition::make(
			'browser_orders_surface',
			'orders',
			'browser_orders',
			'table',
			$projection,
			PanelDataSurfaceRange::make(0, 5, 0, 1),
			null,
			[
				'title'=>'Live order window',
				'description'=>'Signed, bounded Data Surface records backed by the browser session.',
				'empty_message'=>'No browser orders are available.',
				'endpoint'=>'/panel?dp_panel_data_surface=1',
				'estimated_item_size'=>52,
				'virtualize'=>true,
			]
		);
		$context=PanelDataSurfaceContext::fromTrusted('browser_showroom', [
			'tenant_id'=>'browser-tenant',
			'principal_id'=>'browser-operator',
			'correlation_id'=>'browser-data-surface',
		]);
		$registry=(new PanelDataSurfaceRegistry(
			$sources,
			$signer,
			static fn(array $envelope, PanelDataSurfaceContext $candidate): bool=>$candidate->principal()==='browser-operator' && $candidate->tenant()==='browser-tenant'
		))->register($definition);
		return ['registry'=>$registry,'definition'=>$definition,'context'=>$context];
	}

	/** Renders a real signed first window through the public Data Surface APIs. */
	function dp_panel_browser_data_surface_html(): string {
		$fixture=dp_panel_browser_data_surface_fixture();
		$intent=$fixture['registry']->issue('browser_orders_surface', $fixture['context'], PanelDataQuery::make()->sort('id'));
		$window=$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]), $fixture['context']);
		return PanelRenderer::dataSurface($fixture['definition'], $window, $intent, ['id'=>'dp-data-surface-browser-orders']);
	}

	/** Emits the framework-neutral signed Data Surface window response. */
	function dp_panel_browser_emit_data_surface(): bool {
		if(!isset($_GET['dp_panel_data_surface'])){return false;}
		$fixture=dp_panel_browser_data_surface_fixture();
		$response=(new PanelDataSurfaceEndpoint($fixture['registry']))->handle(
			(string)file_get_contents('php://input'),
			'browser_showroom',
			['tenant_id'=>'browser-tenant','principal_id'=>'browser-operator','correlation_id'=>'browser-data-surface']
		);
		$body=json_encode($response['body'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		http_response_code($response['status']);
		foreach($response['headers'] as $name=>$value){header($name.': '.$value);}
		header('Content-Length: '.strlen($body));
		echo $body;
		return true;
	}

	function dp_panel_browser_order_items(array $order): array {
		return is_array($order['items'] ?? null) ? $order['items'] : [];
	}

	function dp_panel_browser_panel(): PanelInstance {
		$preset=Resource::normalizeName((string)($_GET['panel_theme'] ?? $_GET['preset'] ?? $_COOKIE['dataphyre_panel_theme_preset'] ?? 'flat_minima'));
		$preset=in_array($preset, ['flat_minima','glass','brutalist'], true) ? $preset : 'flat_minima';
		$panel=Panel::make('browser_showroom', [
			'panel_label'=>'Dataphyre Browser Lab',
			'home_label'=>'Command Center',
			'navigation_layout'=>'sidebar',
			'navigation_mode'=>'floating',
			'mobile_navigation_mode'=>'drawer',
			'mobile_sidebar_layout'=>'single',
			'asset_mode'=>'physical',
			'table_density_controls'=>true,
			'resource_imports'=>true,
			'resource_exports'=>true,
			'theme_selector'=>true,
			'theme_selector_parameter'=>'panel_theme',
			'theme_selector_presets'=>[
				'flat_minima'=>'Flat Minima',
				'glass'=>'Glass',
				'brutalist'=>'Brutalist',
			],
			'widgets_presentation'=>['display'=>'masonry','masonry'=>'rows','fit'=>'fill','columns'=>['base'=>2,'lg'=>3],'min_width'=>210],
			'widget_runtime_binding_keys'=>['browser-v1'=>'browser-showroom-widget-binding-fixture-key-v1'],
			'widget_runtime_current_key'=>'browser-v1',
			'theme'=>[
				'name'=>'browser_'.$preset,
				'presets'=>[$preset],
				'brand'=>['name'=>'Dataphyre Browser Lab','tagline'=>'Framework regression surface'],
				'colors'=>['primary'=>'#2563eb','success'=>'#16a34a','warning'=>'#d97706','danger'=>'#dc2626','info'=>'#0891b2'],
			],
		]);
		$panel->authorize(static fn(): bool=>true);
		$panel->registerWidgetRuntimeAdapter(new PanelBrowserSessionWidgetRuntimeAdapter());
		// Deterministic fixture-only key: browser regressions exercise the complete
		// signed return contract without exposing a deployable application secret.
		$panel->navigationIntentKey('browser-showroom-navigation-fixture-key-v1', 'browser-v1', [
			'surface'=>'browser_showroom',
			'unsigned_migration'=>'same_panel',
			'ttl'=>1200,
		]);
		$panel->navigationFeatures(['search'=>true,'recent'=>true,'pinning'=>true,'collapse'=>true,'collapse_exclusive'=>true]);
		$panel->registerCommands([
			$panel->command('open_orders')->label('Open orders')->group('Workspace')->url(dp_panel_browser_url('orders'))->keywords(['orders','table']),
			$panel->command('open_showcase')->label('Open feature showcase')->group('Workspace')->url(dp_panel_browser_url('', ['resource'=>'feature_showcase']))->keywords(['showcase','features']),
		]);
		$workspace=$panel->nav('workspace_folder')->label('Workspace')->icon('layout-dashboard')->group('Overview')->description('Browser contract pages.')->folderOnly();
		$commerce=$panel->nav('commerce_folder')->label('Commerce')->icon('shopping-bag')->group('Operations')->description('Order and seller workflows.')->folderOnly();
		$panel->registerNavigationItems([$workspace,$commerce]);
		$panel->registerWidgets(dp_panel_browser_widgets($panel, 'home'));
		$panel->registerPage(dp_panel_browser_showcase($panel));
		$panel->registerPage(dp_panel_browser_state_lab($panel));
		$panel->registerPage($panel->page('data_surface_lab')
			->label('Data Surface Lab')
			->icon('rows-3')
			->group('Overview')
			->navigationParent('workspace_folder')
			->navigationDescription('Signed, virtualized, progressively enhanced record windows.')
			->content(static fn(): string=>dp_panel_browser_data_surface_html()));
		$panel->register(dp_panel_browser_orders_resource($panel));
		$panel->register(dp_panel_browser_sellers_resource($panel));
		return $panel;
	}

	function dp_panel_browser_widgets(PanelInstance $panel, string $prefix): array {
		$definitions=[
			['queue','Queue records',16,'Deterministic records rendered by the table.','primary','list'],
			['paths','Reactive paths',4,'Modal, fragment, navigation, and native document paths.','success','workflow'],
			['controls','Control families',9,'Forms, choices, uploads, filters, and table controls.','info','sliders'],
			['themes','Theme presets',3,'Flat Minima, Glass, and Brutalist.','warning','palette'],
			['routes','Route surfaces',12,'Index, board, create, edit, show, import, and labs.','primary','route'],
			['contracts','Browser contracts',50,'Executable interaction scenarios in the source tree.','success','check-circle'],
		];
		$widgets=[];
		foreach($definitions as [$name,$label,$value,$description,$tone,$icon]){
			$widgets[]=$panel->widget($prefix.'_'.$name)->label($label)->value($value)->description($description)->tone($tone)->icon($icon);
		}
		if($prefix==='home'){
			$widgets[]=$panel->widget('home_live_counter')->label('Live counter')->value(40)->description('A session-backed browser fixture for the complete interactive Widget lifecycle.')->tone('primary')->icon('activity')->interactive(dp_panel_browser_widget_definition());
		}
		return $widgets;
	}

	function dp_panel_browser_showcase(PanelInstance $panel): mixed {
		$body='';
		for($index=1;$index<=18;$index++){
			$body.='<article class="dp-panel-card"><h2>Primitive '.($index).'</h2><p>Generated layout, focus, contrast, and responsive behavior stay observable without application-owned dependencies.</p></article>';
		}
		return $panel->page('feature_showcase')
			->label('Feature Showcase')
			->icon('sparkles')
			->group('Overview')
			->navigationParent('workspace_folder')
			->navigationDescription('Real Panel primitives under browser automation.')
			->content('<section class="dp-panel-grid">'.$body.'</section>')
			->actions([
				$panel->action('refresh_showcase')->label('Refresh showcase')->icon('refresh-cw')->tone('primary')->handle(static fn(): array=>['message'=>'Showcase refreshed.']),
				$panel->action('workflow_modal')->label('Workflow modal')->icon('workflow')->tone('info')->slideOver('Workflow modal','Generated modal fields.')->fields([$panel->field('surface')->required(),$panel->field('note','textarea')])->handle(static fn(): array=>['message'=>'Workflow saved.']),
				$panel->action('schema_playground')->label('Schema playground')->icon('blocks')->tone('primary')->slideOver('Schema playground','Generated schema controls.')->fields([$panel->field('name')->required(),$panel->field('kind','select')->options(['resource'=>'Resource','page'=>'Page'])])->handle(static fn(): array=>['message'=>'Schema saved.']),
				$panel->action('toast_stack')->label('Toast stack')->icon('bell')->tone('warning')->withoutRefresh()->handle(static fn(): array=>['message'=>'Notification emitted.']),
				$panel->action('lifecycle_probe')->label('Lifecycle probe')->icon('activity')->tone('success')->slideOver('Lifecycle probe','Validation and effects.')->fields([$panel->field('stage','select')->options(['validate'=>'Validate','effects'=>'Effects'])->required()])->handle(static fn(): array=>['message'=>'Lifecycle complete.']),
				$panel->action('trace_probe')->label('Trace probe')->icon('scan-search')->tone('info')->withoutRefresh()->handle(static fn(): array=>['message'=>'Trace recorded.']),
				$panel->action('manifest_snapshot')->label('Manifest snapshot')->icon('file-json')->tone('primary')->infoModal('<p>Resources, pages, widgets, commands, and themes use the current source tree.</p>','Manifest snapshot','Current fixture manifest.'),
			])
			->masonryToolbar(true, ['columns'=>['base'=>1,'md'=>3,'xl'=>5],'min_width'=>150,'gap'=>'compact'])
			->widgets(dp_panel_browser_widgets($panel, 'showcase'))
			->masonryWidgets(true, ['columns'=>['sm'=>2,'lg'=>3],'min_width'=>210]);
	}

	function dp_panel_browser_state_lab(PanelInstance $panel): mixed {
		return $panel->page('state_lab')
			->label('State Lab')
			->icon('flask-conical')
			->group('Overview')
			->navigationParent('workspace_folder')
			->content(static function(PanelRequest $request): string {
				$fixture=Resource::normalizeName((string)$request->query('fixture', 'loading_error'));
				$copy=match($fixture){
					'validation_disabled'=>'Required, invalid, readonly, and disabled controls remain distinguishable.',
					'dense_long'=>'Long labels and dense content reflow without clipping or horizontal scrolling.',
					'relation_upload'=>'Relation and upload states share normalized controls and clear status copy.',
					'modal'=>'Modal layers preserve focus, scroll, history, and viewport bounds.',
					default=>'Loading, empty, success, warning, and error states retain stable geometry.',
				};
				$cards='';
				for($index=1;$index<=10;$index++){
					$cards.='<article class="dp-panel-card"><h2>'.dp_panel_browser_e(ucwords(str_replace('_',' ',$fixture))).' '.$index.'</h2><p>'.dp_panel_browser_e($copy).'</p></article>';
				}
				return '<section class="dp-panel-grid">'.$cards.'</section>';
			});
	}

	function dp_panel_browser_orders_resource(PanelInstance $panel): Resource {
		$status=['review'=>'Review','paid'=>'Paid','packing'=>'Packing','shipped'=>'Shipped','cancelled'=>'Cancelled'];
		$risk=['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'];
		$owners=['Mina'=>'Mina','Noah'=>'Noah','Owen'=>'Owen','Ari'=>'Ari'];
		return $panel->resource('orders')
			->label('Order')
			->pluralLabel('Orders')
			->icon('shopping-bag')
			->group('Commerce')
			->navigationParent('commerce_folder')
			->navigationDescription('Deterministic order workflows and table states.')
			->queryUsing(static fn(): array=>dp_panel_browser_orders())
			->recordKeyUsing('id')
			->recordTitleUsing('number')
			->recordSubtitleUsing(static fn(array $order): string=>$order['customer'].' - CAD '.number_format((float)$order['total'], 2))
			->globalSearch()
			->globalSearchColumns(['number','customer','email','owner'])
			->policy(['viewAny'=>true,'view'=>true,'create'=>true,'update'=>true,'delete'=>true,'bulkUpdate'=>true,'export'=>true,'import'=>true])
			->saveUsing(static function(array $data, mixed $record, string $mode): array {
				if(is_array($record) && isset($record['id'])){dp_panel_browser_patch($record['id'], $data);}
				return ['message'=>$mode==='store' ? 'Order created.' : 'Order updated.'];
			})
			->transitionUsing(static function(array $transition, array $record): array {
				dp_panel_browser_patch($record['id'], ['status'=>$transition['to'],'updated_at'=>'2026-05-05 18:00:00']);
				return ['message'=>'Order moved to '.$transition['label'].'.'];
			})
			->duplicateUsing(static fn(array $record): array=>['message'=>'Order '.$record['number'].' duplicated.'])
			->deleteUsing(static fn(array $record): array=>['deleted'=>true,'message'=>'Order '.$record['number'].' cancelled.'])
			->statusField('status')
			->statusWidgets()
			->statusTransitions([
				['name'=>'approve','label'=>'Approve','from'=>['review'],'to'=>'paid','tone'=>'success','confirmation'=>'Approve this order for fulfillment?'],
				['name'=>'pack','label'=>'Pack','from'=>['paid'],'to'=>'packing','tone'=>'primary'],
				['name'=>'ship','label'=>'Ship','from'=>['packing'],'to'=>'shipped','tone'=>'success'],
				['name'=>'cancel','label'=>'Cancel','from'=>['review','paid','packing'],'to'=>'cancelled','tone'=>'danger','confirmation'=>'Cancel this order?'],
			])
			->rowAttributes(static fn(array $record): array=>['data-order-status'=>$record['status'],'data-order-risk'=>$record['risk']])
			->rowClick('show')
			->schema($panel->schema([
				$panel->schemaTab('Order', [
					$panel->field('customer')->required()->placeholder('Customer name')->columnSpan(['default'=>'full','md'=>3,'xl'=>4]),
					...dp_panel_browser_order_context_fields($panel),
					$panel->field('email','email')->required()->email()->prependLabel('@')->copyButton()->appendButton('Trim','trim',['icon'=>'trim'])->columnSpan(['default'=>'full','md'=>3,'xl'=>4]),
					$panel->field('market','radio')->options(['CA'=>'Canada','US'=>'United States','EU'=>'European Union'])->required()->masonryOptions(true,['columns'=>['base'=>2],'min_width'=>150,'gap'=>'compact'])->columnSpan(['md'=>3,'xl'=>4]),
					$panel->field('channel','select')->options(['marketplace'=>'Marketplace','wholesale'=>'Wholesale','support'=>'Support assisted'])->required()->columnSpan(['md'=>2]),
					$panel->field('status','select')->options($status)->required()->setButton('Review','review','append',['icon'=>'rv','title'=>'Set to review'])->columnSpan(['md'=>2,'xl'=>3]),
					$panel->field('risk','select')->options($risk)->required()->columnSpan(['md'=>2]),
					$panel->field('total','number')->required()->currency('CAD',2)->columnSpan(['md'=>2]),
					$panel->field('owner','select')->options($owners)->required()->columnSpan(['md'=>2,'xl'=>3]),
				]),
				$panel->schemaTab('Fulfillment', [
					$panel->field('sla_minutes','number')->label('SLA minutes')->required(),
					$panel->field('priority_handling','checkbox')->label('Priority handling'),
					$panel->field('receipt','file')->label('Receipt or label')->acceptedTypes(['text/csv','application/pdf','image/*']),
					$panel->field('internal_note','textarea')->label('Internal note')->columnSpan('full'),
					...dp_panel_browser_order_handoff_fields($panel),
				]),
			])->columns(['default'=>1,'md'=>6,'xl'=>12]))
			->infolist($panel->infolist()->section('Summary', [
				$panel->textEntry('number')->label('Order')->copyable(),
				$panel->textEntry('customer')->label('Customer'),
				$panel->badgeEntry('status',$status),
				$panel->badgeEntry('risk',$risk),
				$panel->textEntry('owner')->label('Owner'),
			])->columns(['default'=>1,'md'=>3]))
			->columns([
				$panel->column('number')->label('Order')->searchable()->sortable()->linkTo(static fn(array $record): string=>dp_panel_browser_url('orders/'.$record['id'])),
				$panel->column('customer')->searchable()->sortable(),
				$panel->column('market')->badge(['CA'=>'success','US'=>'primary','EU'=>'info']),
				$panel->column('status')->badge(['review'=>'warning','paid'=>'primary','packing'=>'info','shipped'=>'success','cancelled'=>'danger'])->sortable(),
				$panel->column('risk')->badge(['low'=>'success','medium'=>'warning','high'=>'danger','critical'=>'danger'])->sortable(),
				$panel->column('total')->money('CAD')->align('right')->sortable(),
				$panel->column('owner')->searchable()->sortable(),
				$panel->column('sla_minutes')->label('SLA')->format(static fn(mixed $value): string=>((int)$value<0?'Late ':'').abs((int)$value).'m')->sortable(),
				$panel->column('submitted_at')->datetime('M j, H:i')->hiddenByDefault(),
			])
			->views([
				$panel->view('active')->label('Active')->default()->filter(static fn(array $order): bool=>!in_array($order['status'],['shipped','cancelled'],true))->visibleColumns(['number','customer','status','risk','total','owner','sla_minutes'])->density('compact'),
				$panel->view('review')->label('Risk review')->tone('danger')->filterValue('status','review'),
				$panel->view('premium')->label('Premium')->tone('success')->range('total',250,null),
				$panel->view('recent_shipments')->label('Recent shipments')->tone('success')->filterValue('status','shipped'),
			])
			->masonryViews(true,['columns'=>['base'=>2,'md'=>4,'xl'=>6],'min_width'=>138,'gap'=>'compact'])
			->filters([
				$panel->filter('status','select')->options($status)->indicator('Status'),
				$panel->filter('risk','select')->options($risk)->indicator('Risk'),
				$panel->filter('market','select')->options(['CA'=>'Canada','US'=>'United States','EU'=>'European Union'])->indicator('Market'),
			])
			->tableGroups([
				$panel->tableGroup('status')->label('Status')->default()->collapsible(),
				$panel->tableGroup('risk')->label('Risk')->collapsible(),
				$panel->tableGroup('market')->label('Market')->collapsible(),
				$panel->tableGroup('owner')->label('Owner')->collapsible(),
			])
			->emptyState('No orders have entered the workspace.','Create the first deterministic order.','Create order',dp_panel_browser_url('orders/create'),'shopping-bag')
			->filteredEmptyState('No orders match this operating slice.','Clear filters or return to the active queue.','Reset table view',dp_panel_browser_url('orders'),'filter-x')
			->summaries([$panel->summary('orders')->count(),$panel->summary('revenue')->sum('total')->money('CAD')])
			->perPage(12)
			->perPageOptions([8,12,25,50])
			->defaultSort('submitted_at','desc')
			->bulkFields([$panel->field('owner','select')->options($owners)->required(),$panel->field('internal_note','textarea')->label('Reason')->required()])
			->bulkUpdateUsing(static function(array $data, array $records): array {
				foreach($records as $record){dp_panel_browser_patch($record['id'], $data);}
				return ['message'=>count($records).' selected records updated.'];
			})
			->recordActionLimit(0)
			->recordActionPlacement('edit','primary')
			->actions(dp_panel_browser_order_actions($panel, $owners, $risk))
			->relation($panel->relation('items')
				->label('Line items')
				->parentTitleUsing(static fn(array $order): string=>(string)$order['number'])
				->description(static fn(array $order): string=>'Products, suppliers, and values attached to '.$order['number'].'.')
				->emptyState('No line items on this order.','Newly imported or manually repaired orders can exist before item rows arrive.')
				->queryUsing(static fn(array $order): array=>dp_panel_browser_order_items($order))
				->columns([
					$panel->column('position')->label('#')->align('right'),
					$panel->column('sku')->searchable(),
					$panel->column('title')->searchable(),
					$panel->column('quantity')->align('right'),
					$panel->column('price')->money('CAD')->align('right'),
					$panel->column('supplier')->searchable(),
					$panel->column('supplier_note')->label('Pivot note')->searchable(),
				])
				->summaries([$panel->summary('items')->sum('quantity'),$panel->summary('value')->sum('line_total')->money('CAD')])
			);
	}

	function dp_panel_browser_order_actions(PanelInstance $panel, array $owners, array $risk): array {
		return [
			$panel->action('assign')->label('Assign')->icon('user-check')->tone('primary')->large()->recordPrimary()->slideOver('Assign owner','Move ownership without leaving the table.')->modalBack()->fields([$panel->field('owner','select')->options($owners)->required(),$panel->field('internal_note','textarea')->label('Reason')->required()])->handle(static function(array $record,array $data): array {dp_panel_browser_patch($record['id'],$data);return ['message'=>'Order assigned.'];}),
			$panel->action('risk_review')->label('Risk review')->icon('shield-alert')->tone('danger')->recordOverflow()->authorize(static fn(mixed $record=null): bool=>is_array($record))->slideOver('Record risk review','Capture the reason for the risk change.')->modalBack()->fields([$panel->field('risk','select')->options($risk)->required(),$panel->field('internal_note','textarea')->label('Reason')->required()])->handle(static function(array $record,array $data): array {dp_panel_browser_patch($record['id'],$data);return ['message'=>'Risk saved.'];}),
			$panel->action('snapshot')->label('Snapshot')->icon('eye')->tone('neutral')->outlined()->authorize(static fn(mixed $record=null): bool=>is_array($record))->slideOver('Order snapshot','Read-only order context.')->modalBack()->modalContent(static fn(array $record): array=>['Order'=>$record['number'],'Customer'=>$record['customer'],'Status'=>ucfirst($record['status']),'Owner'=>$record['owner']]),
			$panel->action('capture_payment')->label('Capture payment')->icon('credit-card')->tone('success')->outlined()->authorize(static fn(mixed $record=null): bool=>is_array($record))->confirmation('Capture payment for this order?')->handle(static fn(): array=>['message'=>'Payment captured.']),
			$panel->actionGroup('ops_more')->label('Ops')->icon('more-horizontal')->tone('primary')->outlined()->compact()->recordOverflow()->dropdownWidth('lg')->alignEnd()->actions([
				$panel->actionGroupSection('Communication','Customer-facing actions'),
				$panel->action('message_customer')->label('Message customer')->icon('send')->tone('info')->slideOver('Send customer update','Queue a message while keeping order context.')->modalBack()->fields([$panel->field('subject')->required(),$panel->field('body','textarea')->required()])->handle(static fn(): array=>['message'=>'Message queued.']),
				$panel->action('copy_order_reference')->label('Copy reference')->icon('copy')->tone('neutral')->handle(static fn(): array=>['message'=>'Reference copied.']),
			]),
		];
	}

	function dp_panel_browser_order_context_fields(PanelInstance $panel): array {
		$fields=[];
		foreach([
			'account_reference'=>'Account reference',
			'purchase_order'=>'Purchase order',
			'customer_segment'=>'Customer segment',
			'delivery_contact'=>'Delivery contact',
			'address_line'=>'Address line',
			'city'=>'City',
			'postal_code'=>'Postal code',
			'country'=>'Country',
			'warehouse_reference'=>'Warehouse reference',
			'carrier_reference'=>'Carrier reference',
			'campaign_reference'=>'Campaign reference',
			'operator_reference'=>'Operator reference',
		] as $name=>$label){
			$fields[]=$panel->field($name)->label($label)->placeholder($label)->columnSpan(['md'=>2]);
		}
		return $fields;
	}

	function dp_panel_browser_order_handoff_fields(PanelInstance $panel): array {
		$fields=[];
		for($index=1;$index<=12;$index++){
			$fields[]=$panel->field('handoff_note_'.$index, 'textarea')->label('Handoff note '.$index)->rows(3)->placeholder('Deterministic long-form workflow context.')->columnSpan('full');
		}
		return $fields;
	}

	function dp_panel_browser_sellers_resource(PanelInstance $panel): Resource {
		return $panel->resource('sellers')
			->label('Seller')->pluralLabel('Sellers')->icon('store')->group('Commerce')->navigationParent('commerce_folder')
			->queryUsing(static fn(): array=>[['id'=>1,'name'=>'Maple Miniatures','category'=>'Toys','tier'=>'anchor','owner'=>'Mina','health'=>82,'open_tickets'=>2,'verification'=>'verified','risk_note'=>'']])
			->recordKeyUsing('id')->recordTitleUsing('name')->policy(['viewAny'=>true,'view'=>true,'create'=>true,'update'=>true])
			->fields([
				$panel->field('name')->required()->section('Profile'),
				$panel->field('category')->required()->section('Profile'),
				$panel->field('tier','select')->options(['anchor'=>'Anchor','growth'=>'Growth','probation'=>'Probation'])->required()->section('Profile'),
				$panel->field('owner','select')->options(['Mina'=>'Mina','Noah'=>'Noah'])->required()->section('Profile'),
				$panel->field('seller_logo','file')->label('Seller logo')->acceptedTypes(['image/*'])->section('Profile'),
				$panel->field('health','number')->required()->section('Risk'),
				$panel->field('open_tickets','number')->required()->section('Risk'),
				$panel->field('verification','select')->options(['verified'=>'Verified','review'=>'Review'])->required()->section('Risk'),
				$panel->field('risk_note','textarea')->columnSpan('full')->section('Risk'),
			])
			->formColumns(3)
			->columns([$panel->column('name')->searchable(),$panel->column('category'),$panel->column('tier')->badge(),$panel->column('health')]);
	}

	// Execute only after fixture classes and functions are declared. Unlike
	// functions, class declarations are not available before execution reaches
	// them, and the Widget adapter is intentionally defined in this source file.
	dp_panel_browser_emit_asset($modulesRoot);
	if(session_status()!==PHP_SESSION_ACTIVE){
		session_name('DP_PANEL_BROWSER_SHOWROOM');
		session_start();
	}
	if(dp_panel_browser_emit_studio_collaboration()){exit;}
	if(dp_panel_browser_emit_studio_editor()){exit;}
	if(dp_panel_browser_emit_operations_console()){exit;}
	dp_panel_browser_normalize_path();
	dp_panel_browser_normalize_theme();
	dp_panel_browser_seed();
	if(dp_panel_browser_emit_data_surface()){exit;}
	$panel=dp_panel_browser_panel();
	if(dp_panel_browser_emit_studio_visual($panel)){exit;}
	if(dp_panel_browser_emit_widget_runtime($panel)){exit;}
	PanelContext::run(
		dp_panel_browser_context('/panel'),
		static fn(): mixed=>PanelHost::surface($panel, [
			'id'=>'browser-operator',
			'name'=>'Browser Operator',
			'permissions'=>['*'],
		])->emit()
	);
}
