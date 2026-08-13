<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelRoute;
use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelStorageUploadEndpoint;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelThemeAsset;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return mixed */
function dp_panel_route_theme_path(array $data, string $path): mixed {
	$value=$data;
	foreach(explode('.', $path) as $segment){
		if(!is_array($value) || !array_key_exists($segment, $value)){
			return null;
		}
		$value=$value[$segment];
	}
	return $value;
}

dataset('panel route url contracts', [
	'home'=>['/panel', '', [], '/panel'],
	'normalizes bare prefix'=>['admin', '', [], '/admin'],
	'normalizes trailing prefix slash'=>['/admin/', '', [], '/admin'],
	'root home'=>['/', '', [], '/'],
	'root resource'=>['/', 'orders', [], '/orders'],
	'resource'=>['/panel', 'orders', [], '/panel/orders'],
	'drops duplicate resource'=>['/panel', 'orders', ['resource'=>'orders', 'page'=>2], '/panel/orders?page=2'],
	'keeps different resource'=>['/panel', 'orders', ['resource'=>'users'], '/panel/orders?resource=users'],
	'drops empty query values'=>['/panel', 'orders', ['q'=>'', 'page'=>null, 'active'=>false], '/panel/orders'],
	'filters empty nested query'=>['/panel', 'orders', ['filter'=>['q'=>'', 'status'=>'open']], '/panel/orders?filter%5Bstatus%5D=open'],
	'encodes query values'=>['/panel', 'orders', ['q'=>'ice cream'], '/panel/orders?q=ice%20cream'],
	'encodes record segment'=>['/panel', 'orders/show/A B', [], '/panel/orders/A%20B'],
	'preserves encoded slash as segment data'=>['/panel', 'orders/show/A%2FB', [], '/panel/orders/A%2FB'],
	'legacy show'=>['/panel', 'orders/show/42', [], '/panel/orders/42'],
	'legacy edit'=>['/panel', 'orders/edit/42', [], '/panel/orders/42/edit'],
	'legacy update'=>['/panel', 'orders/update/42', [], '/panel/orders/42/update'],
	'legacy delete'=>['/panel', 'orders/delete/42', [], '/panel/orders/42/delete'],
	'legacy force delete'=>['/panel', 'orders/force_delete/42', [], '/panel/orders/42/force_delete'],
	'legacy restore'=>['/panel', 'orders/restore/42', [], '/panel/orders/42/restore'],
	'legacy duplicate'=>['/panel', 'orders/duplicate/42', [], '/panel/orders/42/duplicate'],
	'legacy inline update'=>['/panel', 'orders/inline_update/42', [], '/panel/orders/42/inline_update'],
	'legacy transition'=>['/panel', 'orders/transition/42', [], '/panel/orders/42/transition'],
	'legacy action'=>['/panel', 'orders/action/review/42', [], '/panel/orders/42/action/review'],
	'legacy relation'=>['/panel', 'orders/relation/42/items', [], '/panel/orders/42/relation/items'],
	'canonical action drops identity'=>['/panel', 'orders/42/action/review', ['resource'=>'orders', 'record'=>'42', 'operation'=>'action', 'action'=>'review', 'tab'=>'audit'], '/panel/orders/42/action/review?tab=audit'],
	'canonical relation drops identity'=>['/panel', 'orders/42/relation/items', ['resource'=>'orders', 'record'=>'42', 'operation'=>'relation', 'relation'=>'items', 'page'=>3], '/panel/orders/42/relation/items?page=3'],
	'index drops operation'=>['/panel', 'orders', ['operation'=>'index', 'tenant'=>'ca'], '/panel/orders?tenant=ca'],
	'board drops operation'=>['/panel', 'orders/board', ['operation'=>'board', 'tenant'=>'ca'], '/panel/orders/board?tenant=ca'],
	'unknown route preserved'=>['/panel', 'reports/monthly/2026', [], '/panel/reports/monthly/2026'],
]);

test('panel route URLs canonicalize legacy paths encoding and query identity', static function(Context $t, string $prefix, string $target, array $query, string $expected): void {
	$t->same($expected, PanelRoute::url($prefix, $target, $query));
})->with('panel route url contracts')->tag('panel', 'route', 'url')->maxMillis(1000);

dataset('panel route endpoint contracts', [
	'asset basename'=>['asset', '/admin', '../theme.css', '/admin/assets/theme.css'],
	'asset windows basename'=>['asset', '/admin', '..\\theme.css', '/admin/assets/theme.css'],
	'root asset'=>['asset', '/', 'panel.css', '/assets/panel.css'],
	'upload'=>['upload', '/admin', '', '/admin/upload'],
	'root upload'=>['upload', '/', '', '/upload'],
	'builder home'=>['builder', '/admin', '', '/admin'],
	'builder record'=>['builder', '/admin', 'orders/show/9', '/admin/orders/9'],
]);

test('panel route endpoint helpers remain traversal safe at every mount prefix', static function(Context $t, string $kind, string $prefix, string $target, string $expectedPath): void {
	$url=match($kind){
		'asset'=>PanelRoute::assetUrl($prefix, $target),
		'upload'=>PanelRoute::uploadUrl($prefix),
		'builder'=>(PanelRoute::urlBuilder($prefix))($target),
	};
	$t->same($expectedPath, (string)(parse_url($url, PHP_URL_PATH) ?? ''));
})->with('panel route endpoint contracts')->tag('panel', 'route', 'endpoint')->maxMillis(1000);

dataset('panel route manifest contracts', [
	'type'=>['type', 'panel_route_manifest'],
	'prefix'=>['prefix', '/admin'],
	'surface'=>['surface', 'ops'],
	'page name'=>['route_names.page', 'backoffice'],
	'catch all name'=>['route_names.catch_all', 'backoffice.catch_all'],
	'assets name'=>['route_names.assets', 'backoffice.assets'],
	'upload name'=>['route_names.upload', 'backoffice.upload'],
	'page route'=>['routes.page', '/admin'],
	'catch all route'=>['routes.catch_all', '/admin/{...panel_segments}'],
	'asset route'=>['routes.assets', '/admin/assets/{asset}'],
	'upload route'=>['routes.upload', '/admin/upload'],
	'home URL'=>['urls.home', '/admin'],
	'resource URL'=>['urls.example_resource', '/admin/orders'],
	'record URL'=>['urls.example_record', '/admin/orders/42'],
	'upload URL'=>['urls.upload', '/admin/upload'],
	'legacy upload'=>['legacy.upload', '/dataphyre/panel/upload'],
]);

test('panel route manifests expose stable controller and endpoint metadata', static function(Context $t, string $path, mixed $expected): void {
	$manifest=PanelRoute::manifest('/admin', 'ops', ['name'=>'backoffice']);
	$t->same($expected, dp_panel_route_theme_path($manifest, $path));
})->with('panel route manifest contracts')->tag('panel', 'route', 'manifest')->maxMillis(1000);

test('mobile navigation defaults follow the shell layout and explicit host configuration', static function(Context $t): void {
	$t->same('chips', PanelConfig::mobileNavigationMode());
	$t->same('drawer', PanelConfig::mobileNavigationMode('drawer'));
	$t->same('chips', PanelConfig::mobileNavigationMode('unsupported'));
	$t->same('none', PanelContext::run(['mobile_navigation_mode'=>'disabled'], static fn(): string => PanelConfig::mobileNavigationMode('drawer')));
	$t->same('drawer', PanelContext::run(['mobile_navigation_mode'=>'hamburger'], static fn(): string => PanelConfig::mobileNavigationMode('chips')));
})->tag('panel', 'navigation', 'responsive', 'regression')->maxMillis(1000);

dataset('panel theme fluent contracts', [
	'radius'=>['radius', ['12px'], 'tokens.radius', '12px'],
	'max width'=>['maxWidth', ['80rem'], 'tokens.max_width', '80rem'],
	'panel padding'=>['panelPadding', ['2rem'], 'tokens.panel_padding', '2rem'],
	'section padding'=>['sectionPadding', ['1.5rem'], 'tokens.section_padding', '1.5rem'],
	'control padding'=>['controlPadding', ['.5rem 1rem'], 'tokens.control_padding', '.5rem 1rem'],
	'input padding'=>['inputPadding', ['.75rem'], 'tokens.input_padding', '.75rem'],
	'table padding'=>['tableCellPadding', ['.5rem'], 'tokens.table_cell_padding', '.5rem'],
	'gap'=>['gap', ['1rem'], 'tokens.gap', '1rem'],
	'token normalization'=>['token', ['Heading Size', '2rem'], 'tokens.heading_size', '2rem'],
	'dark token'=>['darkToken', ['Surface Muted', '#111'], 'dark_tokens.surface_muted', '#111'],
	'dark surface'=>['darkSurface', ['#111', '#222'], 'dark_tokens.surface_muted', '#222'],
	'dark body'=>['darkBody', ['#050505'], 'dark_tokens.body_bg', '#050505'],
	'dark text'=>['darkText', ['#fff', '#ccc', '#999'], 'dark_tokens.text_subtle', '#999'],
	'asset root'=>['assetRoot', ['Icons', '/assets/icons'], 'asset_roots.icons', '/assets/icons'],
	'font family'=>['font', ['Inter', null, 'local'], 'font', 'Inter'],
	'font provider'=>['font', ['Inter', null, 'local'], 'font_provider', 'local'],
	'dark mode'=>['darkMode', [false], 'dark_mode', false],
	'default dark mode'=>['defaultMode', ['dark'], 'default_mode', 'dark'],
	'invalid mode fallback'=>['defaultMode', ['midnight'], 'default_mode', 'system'],
	'mode toggle'=>['modeToggle', [false], 'mode_toggle', false],
	'brand name'=>['brandName', ['Dataphyre'], 'brand.name', 'Dataphyre'],
	'brand logo'=>['brandLogo', ['/logo.svg'], 'brand.logo', '/logo.svg'],
	'dark logo'=>['darkModeBrandLogo', ['/logo-dark.svg'], 'brand.dark_logo', '/logo-dark.svg'],
	'logo height'=>['brandLogoHeight', ['28px'], 'brand.logo_height', '28px'],
	'favicon'=>['favicon', ['/favicon.ico'], 'favicon', '/favicon.ico'],
	'stylesheet href'=>['stylesheet', ['/theme.css', 'theme', ['media'=>'screen']], 'css_assets.0.href', '/theme.css'],
	'stylesheet media'=>['stylesheet', ['/theme.css', 'theme', ['media'=>'screen']], 'css_assets.0.attributes.media', 'screen'],
]);

test('panel themes serialize each visual token brand and mode mutation', static function(Context $t, string $method, array $arguments, string $path, mixed $expected): void {
	$theme=PanelTheme::make('contract');
	$theme->{$method}(...$arguments);
	$t->same($expected, dp_panel_route_theme_path($theme->toArray(), $path));
})->with('panel theme fluent contracts')->tag('panel', 'theme', 'manifest')->maxMillis(1000);

dataset('panel theme asset contracts', [
	'string name'=>['string', 'name', 'theme'],
	'string href'=>['string', 'href', '/theme.css'],
	'array url'=>['url', 'href', '/print.css'],
	'array path'=>['path', 'href', '/local.css'],
	'explicit name normalized'=>['named', 'name', 'brand_theme'],
	'allowed media'=>['attributes', 'attributes.media', 'print'],
	'allowed integrity'=>['attributes', 'attributes.integrity', 'sha384-test'],
	'allowed crossorigin'=>['attributes', 'attributes.crossorigin', 'anonymous'],
	'allowed referrer policy'=>['attributes', 'attributes.referrerpolicy', 'no-referrer'],
	'allowed fetch priority'=>['attributes', 'attributes.fetchpriority', 'high'],
	'allowed nonce'=>['attributes', 'attributes.nonce', 'abc'],
	'allowed title'=>['attributes', 'attributes.title', 'Theme'],
	'blocked onclick'=>['attributes', 'attributes.onclick', null],
	'blocked style'=>['attributes', 'attributes.style', null],
	'empty attribute removed'=>['empty_attribute', 'attributes.media', null],
]);

test('theme stylesheet assets normalize definitions and enforce link attributes', static function(Context $t, string $scenario, string $path, mixed $expected): void {
	$asset=match($scenario){
		'string'=>PanelThemeAsset::from('/theme.css'),
		'url'=>PanelThemeAsset::from(['url'=>'/print.css']),
		'path'=>PanelThemeAsset::from(['path'=>'/local.css']),
		'named'=>PanelThemeAsset::stylesheet('/theme.css', 'Brand Theme'),
		'attributes'=>PanelThemeAsset::stylesheet('/theme.css', null, [
			'media'=>'print', 'integrity'=>'sha384-test', 'crossorigin'=>'anonymous',
			'referrerpolicy'=>'no-referrer', 'fetchpriority'=>'high', 'nonce'=>'abc',
			'title'=>'Theme', 'onclick'=>'alert(1)', 'style'=>'display:none',
		]),
		'empty_attribute'=>PanelThemeAsset::stylesheet('/theme.css', null, ['media'=>'']),
	};
	$t->same(true, $asset instanceof PanelThemeAsset);
	$t->same($expected, dp_panel_route_theme_path($asset->toArray(), $path));
})->with('panel theme asset contracts')->tag('panel', 'theme', 'asset')->maxMillis(1000);

dataset('panel upload validation contracts', [
	'missing file'=>[[], [], 'Upload chunk is missing or invalid.'],
	'upload error'=>[[], ['file'=>['error'=>UPLOAD_ERR_PARTIAL]], 'Upload chunk is missing or invalid.'],
	'missing identity'=>[[], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload identity is missing.'],
	'unsafe identity'=>[['upload_id'=>'../bad'], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload identity is missing.'],
	'zero chunks'=>[['upload_id'=>'safe', 'chunks'=>0], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload chunk count is invalid.'],
	'too many chunks'=>[['upload_id'=>'safe', 'chunks'=>10001], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload chunk count is invalid.'],
	'nonnumeric chunks'=>[['upload_id'=>'safe', 'chunks'=>'many'], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload chunk count is invalid.'],
	'negative chunk index'=>[['upload_id'=>'safe', 'chunks'=>2, 'chunk_index'=>-1], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload chunk index is invalid.'],
	'out of range chunk'=>[['upload_id'=>'safe', 'chunks'=>2, 'chunk_index'=>2], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload chunk index is invalid.'],
	'negative size'=>[['upload_id'=>'safe', 'size'=>-1], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload size is invalid.'],
	'zero multipart size'=>[['upload_id'=>'safe', 'chunks'=>2, 'size'=>0], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload size is invalid.'],
	'traversal storage path'=>[['upload_id'=>'safe', 'size'=>1, 'storage_path'=>'../{filename}'], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt']], 'Upload storage path is invalid.'],
	'missing temporary file'=>[['upload_id'=>'safe', 'size'=>1], ['file'=>['error'=>UPLOAD_ERR_OK, 'name'=>'file.txt', 'tmp_name'=>'Z:/missing']], 'Temporary upload chunk is unavailable.'],
]);

test('storage uploads fail closed at each malformed request boundary', static function(Context $t, array $post, array $files, string $expected): void {
	$result=PanelStorageUploadEndpoint::handle($post, $files);
	$t->same(false, $result['ok'] ?? null);
	$t->same($expected, $result['error'] ?? null);
})->with('panel upload validation contracts')->tag('panel', 'upload', 'failure')->maxMillis(1000);
