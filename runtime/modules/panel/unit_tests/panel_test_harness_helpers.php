<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelAssetController;
use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelHost;
use Dataphyre\Panel\PanelLocalization;
use Dataphyre\Panel\PanelNotification;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelPageTemplate;
use Dataphyre\Panel\PanelRegressionSuite;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelResponseEmitter;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRoute;
use Dataphyre\Panel\PanelRouteController;
use Dataphyre\Panel\PanelStorageUploadEndpoint;
use Dataphyre\Panel\PanelTestHarness;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelTrace;
use Dataphyre\Panel\PanelUploadController;
use Dataphyre\Test\Context as TestContext;

/** @return array<string,int> Canonical limits shared by PHP and Node asset gates. */
function dp_panel_asset_budgets(): array {
	static $budgets=null;
	if(is_array($budgets)){
		return $budgets;
	}
	$path=dirname(__DIR__).'/testing/panel_asset_budgets.json';
	$decoded=json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	if(!is_array($decoded)){
		throw new RuntimeException('Panel asset budget manifest must decode to an object.');
	}
	return $budgets=array_map('intval', $decoded);
}

if(!function_exists('dp_panel_unit_test_bootstrap')){
	function dp_panel_unit_test_bootstrap(): void {
		if(!defined('DATAPHYRE_MODULE_POLICY')){
			define('DATAPHYRE_MODULE_POLICY', [
				'enabled'=>[
					'core'=>true,
					'datadoc'=>true,
					'http'=>true,
					'mvc'=>true,
					'panel'=>true,
					'permission'=>true,
					'routing'=>true,
				],
				'disabled'=>[],
				'core_implicit'=>true,
			]);
		}
		$modules_root=dirname(__DIR__, 2);
		if(!class_exists(TestContext::class, false)){
			require_once $modules_root.'/testing/tooling/bootstrap.php';
		}
		$autoloader=$modules_root.'/core/kernel/autoloader.php';
		if(is_file($autoloader)){
			require_once $autoloader;
			if(class_exists('dataphyre\\autoloader', false)){
				\dataphyre\autoloader::register($modules_root);
				\dataphyre\autoloader::register_framework_modules(['datadoc', 'panel', 'permission']);
			}
		}
	}
}

function dp_panel_test_harness_context(): TestContext {
	static $context=null;
	dp_panel_unit_test_bootstrap();
	return $context ??= new TestContext('panel-json-harness', '', __FILE__, 'panel');
}

function dp_panel_test_harness_result_assertions(): bool {
	dp_panel_unit_test_bootstrap();

	$result=PanelPageResult::html(
		'<main><h1>Orders</h1><button aria-label="Refresh orders"></button><div aria-live="polite">Ready</div><style>@media (prefers-reduced-motion: reduce){*{transition:none}}</style></main>',
		200,
		['stats'=>['orders'=>3]],
		[['message'=>'Orders refreshed', 'type'=>'success']]
	);

	PanelTestHarness::assertOk($result);
	PanelTestHarness::assertSee($result, 'Orders');
	PanelTestHarness::assertDontSee($result, 'Customers');
	PanelTestHarness::assertData($result, 'stats.orders', 3);
	PanelTestHarness::assertNotification($result, 'refreshed', 'success');
	PanelTestHarness::assertAccessible($result);

	return true;
}

function dp_panel_test_harness_redirect_assertion(): bool {
	dp_panel_unit_test_bootstrap();

	PanelTestHarness::assertRedirect(PanelPageResult::redirect('/admin/orders'), '/admin/orders');

	return true;
}

function dp_panel_upload_boundary_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	$files=['file'=>[
		'name'=>'report.txt',
		'type'=>'text/plain',
		'tmp_name'=>__FILE__,
		'error'=>UPLOAD_ERR_OK,
		'size'=>1,
	]];
	$unsafe_id=PanelStorageUploadEndpoint::handle([
		'upload_id'=>'..',
		'filename'=>'report.txt',
	], $files);
	$partial_count=PanelStorageUploadEndpoint::handle([
		'upload_id'=>'upload-123',
		'filename'=>'report.txt',
		'chunks'=>'2oops',
	], $files);
	$out_of_range=PanelStorageUploadEndpoint::handle([
		'upload_id'=>'upload-123',
		'filename'=>'report.txt',
		'chunks'=>2,
		'chunk_index'=>2,
	], $files);
	$upload_endpoint=dp_panel_test_harness_context()->nonPublic(PanelStorageUploadEndpoint::class);
	$traversal_path=$upload_endpoint->invoke('storagePath', '../escape/{filename}', 'report.txt', 'upload-123', 'document', 'default');
	$safe_path=$upload_endpoint->invoke('storagePath', 'panel_uploads/{field}/{filename}', 'report.txt', 'upload-123', 'document', 'default');
	$manifest=[
		'upload_id'=>'upload-123', 'filename'=>'report.txt', 'size'=>42, 'mime'=>'text/plain',
		'chunks'=>2, 'disk'=>'local', 'path'=>'panel_uploads/report.txt', 'visibility'=>'private',
	];

	return json_encode([
		'unsafe_id_error'=>$unsafe_id['error'] ?? null,
		'partial_count_error'=>$partial_count['error'] ?? null,
		'out_of_range_error'=>$out_of_range['error'] ?? null,
		'traversal_path_rejected'=>$traversal_path==='',
		'safe_path_is_relative'=>is_string($safe_path) && str_starts_with($safe_path, 'panel_uploads/document/') && !str_contains($safe_path, '..'),
		'same_manifest_accepted'=>$upload_endpoint->invoke('manifestMatches', $manifest, $manifest),
		'changed_route_rejected'=>!$upload_endpoint->invoke('manifestMatches', $manifest, array_replace($manifest, ['disk'=>'private'])),
		'changed_chunk_count_rejected'=>!$upload_endpoint->invoke('manifestMatches', $manifest, array_replace($manifest, ['chunks'=>1])),
		'mime_parameters_removed'=>$upload_endpoint->invoke('mimeType', 'Text/Plain; charset=UTF-8')==='text/plain',
		'multiline_mime_rejected'=>$upload_endpoint->invoke('mimeType', "text/plain\r\nX-Test: yes")==='application/octet-stream',
		'default_delete_prefix_allowed'=>$upload_endpoint->invoke('deletePathAllowed', 'panel_uploads/2026/report.pdf'),
		'unrelated_delete_prefix_rejected'=>!$upload_endpoint->invoke('deletePathAllowed', 'private/customer-export.csv'),
	], JSON_UNESCAPED_SLASHES);
}

function dp_panel_responsive_asset_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	$asset=PanelRenderer::assetContent('panel.css');
	$css=(string)($asset['content'] ?? '');

	return json_encode([
		'content_type'=>$asset['content_type'] ?? null,
		'uses_dynamic_viewport'=>str_contains($css, '100dvh'),
		'has_no_legacy_viewport_units'=>!str_contains($css, '100vh'),
		'hides_mobile_nav_scrollbar'=>str_contains($css, '.dp-panel-sidebar-nav::-webkit-scrollbar{display:none'),
		'guards_mobile_action_targets'=>str_contains($css, '@media(max-width:760px)') && str_contains($css, '.dp-panel :where(.dp-panel-button,.dp-panel-action,.dp-panel-filter-trigger,.dp-panel-row-link') && str_contains($css, 'min-height:var(--dp-vs-control-md)'),
		'enlarges_mobile_row_selectors'=>str_contains($css, '.dp-panel-checkbox input[type="checkbox"]') && str_contains($css, 'width:22px;height:22px;min-width:22px;min-height:22px'),
	], JSON_UNESCAPED_SLASHES);
}

function dp_panel_response_boundary_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	$redirect=PanelPageResult::redirect("/admin/orders\r\nX-Injected: yes");
	$unsafe_redirect=PanelPageResult::redirect('javascript:alert(1)');
	$response_emitter=dp_panel_test_harness_context()->nonPublic(PanelResponseEmitter::class);

	return json_encode([
		'redirect_to'=>$redirect->redirectTo(),
		'location'=>$redirect->headers()['Location'] ?? null,
		'content_has_injected_header'=>str_contains($redirect->content(), 'X-Injected'),
		'filtered_values'=>$response_emitter->invoke('headerValues', ['safe', "bad\r\nInjected: yes", 3, null]),
		'valid_name'=>$response_emitter->invoke('headerName', 'X-Panel-Trace'),
		'invalid_name_rejected'=>$response_emitter->invoke('headerName', "X-Test\r\nInjected")==='',
		'script_redirect_rejected'=>$unsafe_redirect->redirectTo()==='#' && !str_contains($unsafe_redirect->content(), 'javascript:'),
	], JSON_UNESCAPED_SLASHES);
}

function dp_panel_template_url_boundary_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	$template=PanelPageTemplate::make([
		[
			'type'=>'hero',
			'title'=>'URL boundary',
			'actions'=>[
				['label'=>'Unsafe', 'url'=>'javascript:alert(1)'],
				['label'=>'Safe', 'url'=>'/admin/orders'],
			],
		],
		[
			'type'=>'form',
			'title'=>'Unsafe form',
			'action'=>'data:text/html,unsafe',
			'actions'=>[['label'=>'Submit']],
		],
		[
			'type'=>'realtime_client',
			'client'=>'unsafe',
			'script'=>'javascript:alert(2)',
		],
		[
			'type'=>'realtime_client',
			'client'=>'safe',
			'script'=>'/assets/realtime.js',
			'script_only'=>true,
		],
	]);
	$html=(string)$template;
	$renderer=dp_panel_test_harness_context()->nonPublic(PanelRenderer::class);
	$theme=PanelTheme::make('url_boundary')
		->stylesheet('/assets/safe.css', 'safe', ['media'=>'screen', 'onload'=>'alert(3)'])
		->stylesheet('javascript:alert(4)', 'unsafe');
	$theme_assets=(string)$renderer->invoke('themeCssAssets', $theme);
	$button_html=(string)$renderer->invoke('inputButtonHtml', 'append', [
		'label'=>'Unsafe button',
		'url'=>'javascript:alert(5)',
		'attributes'=>[
			'data-safe'=>'yes',
			'data-x" onmouseover="'=>'alert(6)',
		],
	]);
	$notification_html=(string)$renderer->invoke('notificationsHtml', [[
		'message'=>'Unsafe notification action',
		'action_label'=>'Open',
		'action_url'=>'javascript:alert(7)',
	]]);
	$group_action_html=(string)$renderer->invoke('groupActionsHtml', [[
		'label'=>'Unsafe group action',
		'url'=>'data:text/html,unsafe',
		'target'=>'popup" onload="alert(8)',
	]]);
	$empty_html=(string)$renderer->invoke('tableEmptyStateHtml', [
		'heading'=>'Empty',
		'action_label'=>'Unsafe empty action',
		'action_url'=>'javascript:alert(9)',
	]);
	$unsafe_outcome=$renderer->invoke('outcome', ['redirect'=>'https://evil.example/phish'], 'Done');
	$safe_outcome=$renderer->invoke('outcome', ['redirect'=>PanelConfig::url()], 'Done');

	return json_encode([
		'unsafe_link_replaced'=>!str_contains($html, 'javascript:alert') && str_contains($html, 'href="#"'),
		'unsafe_form_replaced'=>!str_contains($html, 'data:text/html') && str_contains($html, 'action="#"'),
		'unsafe_script_omitted'=>!str_contains($html, 'alert(2)'),
		'safe_link_preserved'=>str_contains($html, 'href="/admin/orders"'),
		'safe_script_preserved'=>str_contains($html, 'src="/assets/realtime.js"'),
		'protocol_relative_widget_rejected'=>$renderer->invoke('safeWidgetUrl', '//evil.example/path')==='',
		'theme_event_attribute_rejected'=>!str_contains($theme_assets, 'onload='),
		'theme_safe_attribute_preserved'=>str_contains($theme_assets, 'media="screen"'),
		'unsafe_theme_url_omitted'=>!str_contains($theme_assets, 'javascript:'),
		'unsafe_field_button_url_rejected'=>!str_contains($button_html, 'javascript:'),
		'unsafe_field_button_attribute_rejected'=>!str_contains($button_html, 'onmouseover'),
		'safe_field_button_attribute_preserved'=>str_contains($button_html, 'data-safe="yes"'),
		'unsafe_notification_action_rejected'=>!str_contains($notification_html, 'javascript:'),
		'unsafe_group_action_rejected'=>!str_contains($group_action_html, 'data:text/html'),
		'unsafe_empty_action_rejected'=>!str_contains($empty_html, 'javascript:'),
		'external_action_redirect_rejected'=>($unsafe_outcome['redirect'] ?? null)===null,
		'local_action_redirect_preserved'=>($safe_outcome['redirect'] ?? null)===PanelConfig::url(),
	], JSON_UNESCAPED_SLASHES);
}

function dp_panel_permission_bridge_summary_json(): string {
	dp_panel_unit_test_bootstrap();
	if(class_exists('dataphyre\\core', false)){
		\dataphyre\core::load_framework_module('permission');
	}
	if(class_exists('\\Dataphyre\\Permission\\Permission')){
		\Dataphyre\Permission\Permission::flush();
	}

	$panel=Panel::make('secure_panel')->permissions(['allow_guest_pages'=>['login'], 'manifest_decisions'=>true]);
	$panel_resource=$panel->resource('orders')
		->label('Order')
		->queryUsing(static fn(): array => [['id'=>1, 'number'=>'SO-1']])
		->fields([['name'=>'number', 'label'=>'Number']])
		->columns([['name'=>'number', 'label'=>'Number']])
		->actions(['review'])
		->relations(['items']);
	$panel->register($panel_resource);
	$panel->registerPage(PanelPage::make('login')->label('Login')->content(static fn(): array => ['title'=>'Login', 'content'=>'Guest entry']));
	$panel->registerPage(PanelPage::make('reports')->label('Reports')->content(static fn(): array => ['title'=>'Reports', 'content'=>'Permission controlled']));

	$viewer=['permissions'=>['panel.orders.view_any']];
	$creator=['permissions'=>['panel.orders.view_any', 'panel.orders.create']];
	$relation_viewer=['permissions'=>['panel.orders.view_any', 'panel.orders.view', 'panel.orders.relation.items.view']];
	$page_viewer=['permissions'=>['panel.reports.view']];
	$super=['permissions'=>['panel.*']];

	$index=$panel->dispatch(['resource'=>'orders', 'operation'=>'index', 'user'=>$viewer]);
	$create_denied=$panel->dispatch(['resource'=>'orders', 'operation'=>'create', 'user'=>$viewer]);
	$create_allowed=$panel->dispatch(['resource'=>'orders', 'operation'=>'create', 'user'=>$creator]);
	$relation_denied=$panel->dispatch(['resource'=>'orders', 'operation'=>'relation', 'record'=>'1', 'relation'=>'items', 'user'=>$viewer]);
	$relation_allowed=$panel->dispatch(['resource'=>'orders', 'operation'=>'relation', 'record'=>'1', 'relation'=>'items', 'user'=>$relation_viewer]);
	$guest_page=$panel->dispatch(['resource'=>'login', 'operation'=>'view']);
	$page_denied=$panel->dispatch(['resource'=>'reports', 'operation'=>'view', 'user'=>$viewer]);
	$page_allowed=$panel->dispatch(['resource'=>'reports', 'operation'=>'view', 'user'=>$page_viewer]);
	$viewer_navigation=$panel->navigation(PanelRequest::fromArray(['user'=>$viewer]));
	$configured=Panel::make('configured_permission_panel', ['permission'=>['super_permission'=>'panel.*']]);
	$configured->register($configured->resource('products')->queryUsing(static fn(): array => []));
	$configured_index=$configured->dispatch(['resource'=>'products', 'operation'=>'index', 'user'=>$super]);
	$manifest=$panel->panelManifest(PanelRequest::fromArray(['resource'=>'orders', 'operation'=>'index', 'user'=>$viewer, 'tenant'=>'CA']));
	$permission_manifest=is_array($manifest['permission'] ?? null) ? $manifest['permission'] : [];
	$decision_snapshot=is_array($permission_manifest['decision_snapshot'] ?? null) ? $permission_manifest['decision_snapshot'] : [];
	$resource_permission=is_array($manifest['resources']['orders']['permission'] ?? null) ? $manifest['resources']['orders']['permission'] : [];
	$action_permission=is_array($manifest['resources']['orders']['actions']['review']['permission'] ?? null) ? $manifest['resources']['orders']['actions']['review']['permission'] : [];
	$action_availability=is_array($manifest['resources']['orders']['actions']['review']['availability'] ?? null) ? $manifest['resources']['orders']['actions']['review']['availability'] : [];
	$relation_permission=is_array($manifest['resources']['orders']['relations']['items']['permission'] ?? null) ? $manifest['resources']['orders']['relations']['items']['permission'] : [];
	$page_permission=is_array($manifest['pages']['reports']['permission'] ?? null) ? $manifest['pages']['reports']['permission'] : [];
	$login_permission=is_array($manifest['pages']['login']['permission'] ?? null) ? $manifest['pages']['login']['permission'] : [];
	$catalog=class_exists('\\Dataphyre\\Permission\\Permission')
		? \Dataphyre\Permission\Permission::panel_catalog($panel)
		: [];
	$permission_for=class_exists('\\Dataphyre\\Permission\\PermissionPanel')
		? \Dataphyre\Permission\PermissionPanel::permissionFor('index', $panel_resource, PanelRequest::fromArray(['resource'=>'orders', 'operation'=>'index']), [])
		: '';

	return json_encode([
		'index_status'=>$index->status(),
		'create_denied_status'=>$create_denied->status(),
		'create_allowed_status'=>$create_allowed->status(),
		'relation_denied_status'=>$relation_denied->status(),
		'relation_allowed_status'=>$relation_allowed->status(),
		'guest_page_status'=>$guest_page->status(),
		'page_denied_status'=>$page_denied->status(),
		'page_allowed_status'=>$page_allowed->status(),
		'navigation_has_login'=>in_array('login', array_column($viewer_navigation, 'name'), true),
		'navigation_has_reports'=>in_array('reports', array_column($viewer_navigation, 'name'), true),
		'configured_status'=>$configured_index->status(),
		'permission_for_index'=>$permission_for,
		'catalog_has_view_any'=>in_array('panel.orders.view_any', array_column($catalog, 'permission'), true),
		'catalog_has_action_context'=>count(array_filter($catalog, static fn(array $row): bool => ($row['resource'] ?? '')==='orders'))>0,
		'manifest_permission_enabled'=>$permission_manifest['enabled'] ?? null,
		'manifest_permission_catalog_count'=>$permission_manifest['catalog_count'] ?? null,
		'manifest_permission_example'=>$permission_manifest['examples']['update'] ?? '',
		'manifest_capability_permission_catalog'=>$manifest['capabilities']['permission']['catalog_count'] ?? null,
		'manifest_decisions_included'=>$decision_snapshot['included'] ?? null,
		'manifest_decisions_allowed'=>$decision_snapshot['counts']['allowed'] ?? null,
		'manifest_decisions_denied'=>$decision_snapshot['counts']['denied'] ?? null,
		'manifest_decisions_context_tenant'=>$decision_snapshot['context']['tenant'] ?? null,
		'resource_permission_update'=>$resource_permission['operations']['update'] ?? '',
		'resource_permission_total'=>$resource_permission['counts']['total'] ?? null,
		'action_permission'=>$action_permission['permission'] ?? '',
		'action_permission_authorized'=>$action_availability['authorized'] ?? null,
		'relation_permission_view'=>$relation_permission['operations']['view'] ?? '',
		'page_permission_view'=>$page_permission['operations']['view'] ?? '',
		'login_permission_guest'=>$login_permission['guest_allowed'] ?? null,
	], JSON_UNESCAPED_SLASHES);
}

function dp_panel_test_harness_navigation_summary(): string {
	dp_panel_unit_test_bootstrap();

	$harness=PanelTestHarness::make();
	$harness
		->registerPage(PanelPage::make('orders')->label('Orders')->group('Commerce')->url('/admin/orders')->sort(20)->navigationBadge(5)->navigationBadgeTone('success'))
		->registerNavigationItem(NavigationItem::make('settings')->label('Settings')->group('Admin')->url('/admin/settings')->sort(90)->hide())
		->registerCommand(PanelCommand::make('open_orders')->label('Open Orders')->group('Commerce')->url('/admin/orders')->keywords(['sales', 'orders'])->sort(10))
		->registerCommand(PanelCommand::make('open_settings')->label('Open Settings')->group('Admin')->url('/admin/settings')->keywords(['settings'])->sort(20));

	$navigation=$harness->manager()->navigationState($harness->request(['resource'=>'orders']));
	$commands=$harness->commandState('sales');
	$entry=$navigation->entry('orders') ?? [];
	$matched=$commands->matched()[0] ?? [];

	return json_encode([
		'entry_count'=>$navigation->meta()['entry_count'] ?? null,
		'group_count'=>count($navigation->groups()),
		'active_name'=>$navigation->active()['name'] ?? '',
		'orders_badge'=>$entry['badge'] ?? null,
		'hidden_settings_absent'=>$navigation->entry('settings')===null,
		'command_query'=>$commands->query(),
		'matched_count'=>count($commands->matched()),
		'matched_command'=>$matched['name'] ?? '',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_panel_page_result_download_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	$csv=PanelPageResult::csv("sku,total\nA-1,4\n", 'Orders May 2026.csv', ['rows'=>1]);
	$json=PanelPageResult::jsonDownload(['status'=>'ok', 'items'=>['A-1']], 'Orders/Export?.json');
	$api=PanelPageResult::json(['ok'=>true], 202, ['X-Panel-Test'=>'yes']);

	return json_encode([
		'csv_status'=>$csv->status(),
		'csv_content_type'=>$csv->headers()['Content-Type'] ?? '',
		'csv_disposition'=>$csv->headers()['Content-Disposition'] ?? '',
		'csv_rows'=>$csv->data()['rows'] ?? null,
		'json_filename'=>$json->headers()['Content-Disposition'] ?? '',
		'json_contains_pretty_items'=>str_contains($json->content(), "\n        \"A-1\"\n"),
		'api_status'=>$api->status(),
		'api_header'=>$api->headers()['X-Panel-Test'] ?? '',
		'api_content'=>$api->content(),
	], JSON_UNESCAPED_SLASHES);
}

function dp_panel_test_harness_command_visibility_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	$harness=PanelTestHarness::make();
	$harness
		->registerCommand(PanelCommand::make('visible_report')->label('Visible Report')->group('Reports')->description('Sales export')->keywords('sales export')->sort(30)->url(fn()=>'/admin/reports/sales'))
		->registerCommand(PanelCommand::make('hidden_report')->label('Hidden Report')->group('Reports')->visibleUsing(fn()=>false)->sort(10))
		->registerCommand(['name'=>'quick_help', 'label'=>'Quick Help', 'group'=>'Help', 'url'=>'/admin/help', 'sort'=>5, 'new_tab'=>true]);

	$all=$harness->commandState();
	$matched=$harness->commandState('sales');
	$first=$all->commands()[0] ?? [];
	$visible=$matched->matched()[0] ?? [];

	return json_encode([
		'command_count'=>$all->meta()['command_count'] ?? null,
		'registered_commands'=>$all->meta()['registered_commands'] ?? null,
		'group_count'=>$all->meta()['group_count'] ?? null,
		'first_command'=>$first['name'] ?? '',
		'first_new_tab'=>$first['new_tab'] ?? null,
		'match_count'=>$matched->meta()['match_count'] ?? null,
		'matched_command'=>$visible['name'] ?? '',
		'matched_url'=>$visible['url'] ?? '',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_panel_regression_suite_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	$suite=PanelRegressionSuite::make('Orders Smoke');
	$suite
		->meta(['module'=>'panel', 'deterministic'=>true])
		->check('ok result passes harness', function(PanelTestHarness $test): string {
			PanelTestHarness::assertOk(PanelPageResult::html('<main><h1>Orders</h1></main>'));
			return 'HTML response accepted.';
		}, ['surface'=>'result'])
		->check('array details are retained', function(): array {
			return ['message'=>'Details returned.', 'records'=>2];
		}, ['surface'=>'details'])
		->skip('external browser audit', 'External browser not needed for native unit test.', ['surface'=>'browser']);

	$pending=$suite->checks();
	$report=$suite->run(['build'=>'unit']);
	$rows=$report->results();

	return json_encode([
		'name'=>$report->name(),
		'count_before_run'=>count($pending),
		'pending_first_status'=>$pending[0]['status'] ?? '',
		'ok'=>$report->ok(),
		'total'=>$report->total(),
		'passed'=>$report->passed(),
		'failed'=>$report->failed(),
		'skipped'=>$report->skipped(),
		'first_message'=>$rows[0]['message'] ?? '',
		'second_records'=>$rows[1]['details']['records'] ?? null,
		'third_status'=>$rows[2]['status'] ?? '',
		'manifest_last_report_type'=>$suite->manifest()['last_report']['type'] ?? '',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_panel_localization_catalogue_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	$localization=PanelLocalization::make([
		'locale'=>'fr-CA',
		'fallback_locale'=>'en',
		'translations'=>[
			'en'=>[
				'actions'=>[
					'save'=>'Save :resource',
				],
				'panel.empty'=>'No records for {resource}.',
			],
			'fr'=>[
				'actions.save'=>'Enregistrer :resource',
			],
			'fr-CA'=>[
				'panel'=>[
					'title'=>'Tableau {{ name }}',
				],
			],
		],
	]);
	$localization->add('en', ['delete'=>'Delete {resource}'], 'actions');
	$scope=$localization->scope('actions');
	$manifest=$localization->jsonSerialize();

	return json_encode([
		'locale'=>$localization->locale(),
		'fallback'=>$localization->fallbackLocale(),
		'title'=>$localization->t('panel.title', ['name'=>'Dataphyre']),
		'scoped_save'=>$scope->t('save', ['resource'=>'orders']),
		'fallback_delete'=>$scope->t('delete', ['resource'=>'orders']),
		'default_copy'=>$localization->t('panel.missing', [], null, 'Missing copy'),
		'has_base_fallback'=>$localization->has('actions.save'),
		'manifest_type'=>$manifest['type'] ?? '',
		'manifest_locales'=>$manifest['counts']['locales'] ?? null,
		'manifest_keys'=>$manifest['counts']['keys'] ?? null,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_panel_notification_adapter_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	$adapter=Panel::notificationAdapter([], ['database', 'broadcast']);
	$inbox=Panel::notificationInboxUsing($adapter);
	$first=$inbox->add(
		PanelNotification::success('Order 1001 paid.', 'Payment received')->action('Open order', '/admin/orders/1001'),
		'user:1',
		['tenant'=>'north']
	);
	$second=$inbox->add([
		'type'=>'warning',
		'title'=>'Stock low',
		'message'=>'SKU A-1 is below threshold.',
		'recipient'=>'user:2',
		'channels'=>['database', 'mail'],
		'created_at'=>'2026-05-12T10:00:00+00:00',
	]);

	$inbox->markRead($first->id(), '2026-05-12T11:00:00+00:00');
	$inbox->dismiss($second->id(), '2026-05-12T12:00:00+00:00');
	$deliveries=$inbox->deliver($first, ['database', 'broadcast']);
	$manifest=$inbox->manifest(['surface'=>'unit']);
	$user_one_counts=$inbox->counts(false, 'user:1');

	return json_encode([
		'adapter'=>$manifest['adapter']['adapter'] ?? '',
		'durable'=>$manifest['adapter']['durable'] ?? null,
		'visible_total'=>$manifest['counts']['total'] ?? null,
		'all_total'=>$inbox->counts(true)['total'] ?? null,
		'user_one_unread'=>$user_one_counts['unread'] ?? null,
		'user_one_read'=>$user_one_counts['read'] ?? null,
		'dismissed'=>$inbox->counts(true)['dismissed'] ?? null,
		'delivery_count'=>count($deliveries),
		'delivery_channel'=>$deliveries[1]['channel'] ?? '',
		'action_url'=>$first->toArray()['action_url'] ?? '',
		'channels'=>$second->channels(),
		'read_at'=>$first->readAt(),
		'capability_delivery'=>$manifest['capabilities']['delivery_channels'] ?? false,
		'manifest_type'=>$manifest['type'] ?? '',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_panel_route_compatibility_summary_json(): string {
	dp_panel_unit_test_bootstrap();

	if(class_exists('dataphyre\\autoloader', false)){
		$modules_root=dirname(__DIR__, 2);
		\dataphyre\autoloader::register_framework_modules(['http', 'routing', 'mvc', 'panel']);
	}
	PanelTrace::flush();

	$builder=Panel::routeUrlBuilder('/admin');
	$url=$builder('orders/action/review/42', ['tenant'=>'CA']);
	$duplicate_resource_url=$builder('orders', ['resource'=>'orders', 'per_page'=>'12']);
	$path_style_edit_url=$builder('orders/edit/42', ['resource'=>'orders', 'operation'=>'edit', 'record'=>'42', 'density'=>'compact']);
	$route_round_trips=[];
	foreach([
		'index'=>['target'=>'orders', 'query'=>['resource'=>'orders', 'tenant'=>'CA']],
		'show'=>['target'=>'orders/show/42', 'query'=>['resource'=>'orders', 'operation'=>'show', 'record'=>'42', 'tenant'=>'CA']],
		'edit'=>['target'=>'orders/edit/42', 'query'=>['resource'=>'orders', 'operation'=>'edit', 'record'=>'42', 'tenant'=>'CA']],
		'board'=>['target'=>'orders/board', 'query'=>['resource'=>'orders', 'operation'=>'board', 'tenant'=>'CA']],
		'action'=>['target'=>'orders/action/review/42', 'query'=>['resource'=>'orders', 'operation'=>'action', 'record'=>'42', 'action'=>'review', 'tenant'=>'CA']],
		'relation'=>['target'=>'orders/relation/42/items', 'query'=>['resource'=>'orders', 'operation'=>'relation', 'record'=>'42', 'relation'=>'items', 'tenant'=>'CA']],
	] as $case=>$route_case){
		$case_url=$builder($route_case['target'], $route_case['query']);
		$case_path=(string)parse_url($case_url, PHP_URL_PATH);
		parse_str((string)parse_url($case_url, PHP_URL_QUERY), $case_query);
		$case_segments=array_values(array_filter(explode('/', trim(substr($case_path, strlen('/admin')), '/'))));
		$case_request=PanelRequest::fromHttpRequest(\Dataphyre\Http\Request::create('GET', $case_path, $case_query, [], [], [], [], ['panel_segments'=>$case_segments]));
		$route_round_trips[$case]=[
			'url'=>$case_url,
			'resource'=>$case_request->resourceName(),
			'operation'=>$case_request->operation(),
			'record'=>$case_request->recordKey(),
			'action'=>$case_request->actionName(),
			'relation'=>$case_request->relationName(),
		];
	}
	$path=(string)parse_url($url, PHP_URL_PATH);
	parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
	$segments=array_values(array_filter(explode('/', trim(substr($path, strlen('/admin')), '/'))));
	$request=\Dataphyre\Http\Request::create('GET', $path, $query, [], [], [], [], ['panel_segments'=>$segments]);
	$panel_request=\Dataphyre\Panel\PanelRequest::fromHttpRequest($request);
	$routes=Panel::routes('/admin', 'default', ['name'=>'panel']);
	$compiled=$routes[1]->compile();
	$route_manifest=Panel::routeManifest('/admin', 'default', ['name'=>'panel']);
	$mounted_routes=Panel::mountedRoutes('/admin', 'default', ['name'=>'panel']);
	$mounted_asset=$mounted_routes[0]->compile();
	$mounted_upload=$mounted_routes[1]->compile();
	$app=new \Dataphyre\Mvc\MvcApplication('panel_route_test', ['controllers'=>['namespace'=>'App\\Controllers']]);
	$mvc_routes=Panel::mvcRoutes($app->routes(), '/backoffice', 'default', ['name'=>'panel.mvc']);
	$mvc_mounted_routes=Panel::mvcMountedRoutes($app->routes(), '/admin', 'default', ['name'=>'panel.mounted']);
	$mvc_manifest=$app->routes()->compile();
	$route_asset_url=PanelContext::run([
		'asset_url_builder'=>static fn(string $asset): string => PanelRoute::assetUrl('/admin', $asset),
		'upload_url'=>PanelRoute::uploadUrl('/admin'),
	], static fn(): string => PanelRenderer::assetUrl('panel.css'));
	$route_upload_url=PanelContext::run([
		'upload_url'=>PanelRoute::uploadUrl('/admin'),
	], static fn(): string => PanelConfig::uploadUrl());
	$asset_response=PanelAssetController::response('panel.css', \Dataphyre\Http\Request::create('GET', '/admin/assets/panel.css'));
	$upload_response=PanelUploadController::handle(\Dataphyre\Http\Request::create('GET', '/admin/upload'));
	$panel_manifest=PanelContext::run([
		'panel_mount_prefix'=>'/admin',
	], static fn(): array => Panel::panelManifest(null, ['name'=>'default']));
	$controller_response=PanelRouteController::handle(\Dataphyre\Http\Request::create('GET', '/admin', [], [], [], [], [], [
		'panel_mount_prefix'=>'/admin',
		'panel_surface'=>'default',
	]));
	$controller_body=(string)($controller_response->body ?? '');
	$trace_summary=PanelTrace::summary();
	$response=PanelPageResult::html('ok', 202, ['route'=>'compat'])->toResponse();

	return json_encode([
		'url'=>$url,
		'duplicate_resource_url'=>$duplicate_resource_url,
		'path_style_edit_url'=>$path_style_edit_url,
		'route_round_trips'=>$route_round_trips,
		'resource'=>$panel_request->resourceName(),
		'record'=>$panel_request->recordKey(),
		'operation'=>$panel_request->operation(),
		'action'=>$panel_request->actionName(),
		'tenant'=>$panel_request->tenantKey(),
		'routing_count'=>count($routes),
		'routing_path'=>$compiled['path'] ?? '',
		'routing_surface'=>$compiled['defaults']['panel_surface'] ?? '',
		'routing_mount'=>$compiled['defaults']['panel_mount_prefix'] ?? '',
		'route_manifest_type'=>$route_manifest['type'] ?? '',
		'route_manifest_asset'=>$route_manifest['routes']['assets'] ?? '',
		'route_manifest_upload_url'=>$route_manifest['urls']['upload'] ?? '',
		'mounted_count'=>count($mounted_routes),
		'mounted_asset_path'=>$mounted_asset['path'] ?? '',
		'mounted_asset_handler'=>$mounted_asset['handler']['class'] ?? '',
		'mounted_upload_path'=>$mounted_upload['path'] ?? '',
		'mounted_upload_method'=>$mounted_upload['methods'][0] ?? '',
		'asset_url_path'=>(string)parse_url($route_asset_url, PHP_URL_PATH),
		'upload_url'=>$route_upload_url,
		'asset_response_status'=>$asset_response->status ?? null,
		'asset_response_type'=>$asset_response->headers['Content-Type'] ?? '',
		'upload_response_status'=>$upload_response->status ?? null,
		'panel_manifest_route_mounted'=>$panel_manifest['routes']['mounted'] ?? null,
		'panel_manifest_route_prefix'=>$panel_manifest['routes']['prefix'] ?? '',
		'controller_response_status'=>$controller_response->status ?? null,
		'controller_asset_mounted'=>str_contains($controller_body, '/admin/assets/panel.css'),
		'controller_script_mounted'=>str_contains($controller_body, '/admin/assets/panel.js'),
		'trace_asset_events'=>$trace_summary['events']['route_asset'] ?? $trace_summary['events']['route.asset'] ?? 0,
		'trace_upload_events'=>$trace_summary['events']['route_upload'] ?? $trace_summary['events']['route.upload'] ?? 0,
		'trace_dispatch_end_events'=>$trace_summary['events']['route_dispatch_end'] ?? $trace_summary['events']['route.dispatch.end'] ?? 0,
		'mvc_count'=>count($mvc_routes),
		'mvc_mounted_count'=>count($mvc_mounted_routes),
		'mvc_path'=>$mvc_manifest['routes'][1]['path'] ?? '',
		'mvc_handler'=>$mvc_manifest['routes'][1]['handler']['class'] ?? '',
		'response_class'=>get_class($response),
		'response_status'=>$response->status ?? null,
		'response_header'=>$response->headers['Content-Type'] ?? '',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_panel_host_http_fragment_summary_json(): string {
	dp_panel_unit_test_bootstrap();
	if(class_exists('dataphyre\\autoloader', false)){
		$modules_root=dirname(__DIR__, 2);
		\dataphyre\autoloader::register_framework_modules(['http', 'panel']);
	}

	$response=PanelHost::surface('default')->response(\Dataphyre\Http\Request::create(
		'GET',
		'/admin',
		['__panel_partial'=>'fragment'],
		[],
		[],
		[],
		['X-Requested-With'=>'DataphyrePanelFragment'],
		['panel_mount_prefix'=>'/admin', 'panel_surface'=>'default']
	));
	$payload=json_decode((string)($response->body ?? ''), true);

	return json_encode([
		'response_class'=>is_object($response) ? get_class($response) : gettype($response),
		'status'=>$response->status ?? null,
		'content_type'=>$response->headers['Content-Type'] ?? '',
		'has_html'=>is_array($payload) && is_string($payload['html'] ?? null),
		'has_main'=>is_array($payload) && is_string($payload['html'] ?? null) && str_contains($payload['html'], 'main class="dp-panel'),
		'payload_status'=>is_array($payload) ? ($payload['status'] ?? null) : null,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
