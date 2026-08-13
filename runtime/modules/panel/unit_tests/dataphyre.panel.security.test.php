<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelPageTemplate;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelResponseEmitter;
use Dataphyre\Panel\PanelStorageUploadEndpoint;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelThemePreview;
use Dataphyre\Panel\PanelUploadController;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/**
 * Invokes one private static Panel boundary helper for focused unit coverage.
 *
 * @param class-string $class Class declaring the helper.
 * @param string $method Private static method name.
 * @param mixed ...$arguments Invocation arguments.
 */
test('panel redirects and emitted headers reject response splitting', static function(Context $t): void {
	$redirect=PanelPageResult::redirect("/admin/orders\r\nX-Injected: yes");

	$t->same('/admin/orders', $redirect->redirectTo());
	$t->same('/admin/orders', $redirect->headers()['Location'] ?? null);
	$t->same(false, str_contains($redirect->content(), 'X-Injected'));
	$t->same(['safe', '3'], $t->nonPublic(PanelResponseEmitter::class)->invoke('headerValues', ['safe', "bad\r\nInjected: yes", 3, null]));
	$t->same('', $t->nonPublic(PanelResponseEmitter::class)->invoke('headerName', "X-Test\r\nInjected"));
	$unsafe=PanelPageResult::redirect('javascript:alert(1)');
	$t->same('#', $unsafe->redirectTo());
	$t->notContains('javascript:', $unsafe->content());
})->tag('panel', 'security', 'http')->maxMillis(1000);

test('structured page templates reject scriptable URL schemes', static function(Context $t): void {
	$html=(string)PanelPageTemplate::make([
		['type'=>'hero', 'title'=>'Boundary', 'actions'=>[
			['label'=>'Unsafe', 'url'=>'javascript:alert(1)'],
			['label'=>'Safe', 'url'=>'/admin/orders'],
		]],
		['type'=>'form', 'title'=>'Form', 'action'=>'data:text/html,unsafe', 'actions'=>[['label'=>'Submit']]],
		['type'=>'realtime_client', 'client'=>'unsafe', 'script'=>'javascript:alert(2)'],
		['type'=>'realtime_client', 'client'=>'safe', 'script'=>'/assets/realtime.js', 'script_only'=>true],
	]);

	$t->notContains('javascript:', $html);
	$t->notContains('data:text/html', $html);
	$t->contains('href="/admin/orders"', $html);
	$t->contains('src="/assets/realtime.js"', $html);
})->tag('panel', 'security', 'template')->maxMillis(1000);

test('theme assets and shared links enforce URL and attribute allow lists', static function(Context $t): void {
	$theme=PanelTheme::make('boundary')
		->token('radius', '12px')
		->token('attack', 'red;</style><script>alert(10)</script>')
		->brandLogo('/assets/brand.svg')
		->darkModeBrandLogo('javascript:alert(13)')
		->brandLogoHeight('24px;width:100vw')
		->favicon('data:image/svg+xml,unsafe')
		->stylesheet('/assets/safe.css', 'safe', ['media'=>'screen', 'onload'=>'alert(3)'])
		->stylesheet('javascript:alert(4)', 'unsafe');
	$html=(string)$t->nonPublic(PanelRenderer::class)->invoke('themeCssAssets', $theme);
	$brand=(string)$t->nonPublic(PanelRenderer::class)->invoke('brandHtml', $theme);
	$variables=$theme->styleVariables();

	$t->contains('href="/assets/safe.css"', $html);
	$t->contains('media="screen"', $html);
	$t->notContains('onload=', $html);
	$t->notContains('javascript:', $html);
	$t->contains('src="/assets/brand.svg"', $brand);
	$t->notContains('javascript:', $brand);
	$t->notContains('style=', $brand);
	$t->same('', $t->nonPublic(PanelRenderer::class)->invoke('safeWidgetUrl', (string)$theme->faviconUrl()));
	$t->same('', $t->nonPublic(PanelRenderer::class)->invoke('safeWidgetUrl', '//evil.example/path'));
	$t->same('', PanelContext::run(['asset_url_builder'=>static fn(string $asset): string => 'javascript:'.$asset], static fn(): string => PanelRenderer::assetUrl('panel.css')));
	$t->same('24px', $t->nonPublic(PanelRenderer::class)->invoke('safeLogoHeight', '24PX'));
	$t->contains('--dp-radius:12px;', $variables);
	$t->notContains('</style>', $variables);
	$t->notContains('--dp-attack:', $variables);

	$preview=PanelThemePreview::render([
		'name'=>'unsafe_preview',
		'colors'=>['primary'=>['key'=>['base'=>'red;position:fixed']]],
		'modes'=>['light'=>['samples'=>['surface'=>['background'=>'red;</style><script>alert(11)</script>']]]],
		'contrast'=>['light'=>[['status'=>'pass" onmouseover="alert(12)', 'background'=>'surface', 'text'=>'text']]],
	]);
	$t->notContains('</style><script>', $preview);
	$t->notContains('onmouseover', $preview);
	$t->contains('dp-theme-preview-status-unknown', $preview);
})->tag('panel', 'security', 'theme')->maxMillis(1000);

test('asset response metadata supports the Panel fallback response shape', static function(Context $t): void {
	$result=new PanelPageResult('body', 206, ['Content-Type'=>'text/plain; charset=UTF-8']);
	$headers=$t->nonPublic(\Dataphyre\Panel\PanelAssetController::class)->invoke('responseHeaders', $result);

	$t->same('text/plain; charset=UTF-8', $headers['Content-Type'] ?? null);
})->tag('panel', 'security', 'http', 'assets')->maxMillis(1000);

test('renderer action surfaces reject executable links and forged attributes', static function(Context $t): void {
	$button=(string)$t->nonPublic(PanelRenderer::class)->invoke('inputButtonHtml', 'append', [
		'label'=>'Unsafe',
		'url'=>'javascript:alert(5)',
		'attributes'=>['data-safe'=>'yes', 'data-x" onmouseover="'=>'alert(6)'],
	]);
	$notification=(string)$t->nonPublic(PanelRenderer::class)->invoke('notificationsHtml', [[
		'message'=>'Unsafe notification', 'action_label'=>'Open', 'action_url'=>'javascript:alert(7)',
	]]);
	$group=(string)$t->nonPublic(PanelRenderer::class)->invoke('groupActionsHtml', [[
		'label'=>'Unsafe group', 'url'=>'data:text/html,unsafe', 'target'=>'popup" onload="alert(8)',
	]]);
	$empty=(string)$t->nonPublic(PanelRenderer::class)->invoke('tableEmptyStateHtml', [
		'heading'=>'Empty', 'action_label'=>'Unsafe', 'action_url'=>'javascript:alert(9)',
	]);
	$uploader=(string)$t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderControl', 'attachment', [
		'meta'=>['upload_endpoint'=>'javascript:alert(14)', 'upload_delete_endpoint'=>'data:text/html,unsafe'],
	], null, '', '', '', '', false, '', []);

	$t->notContains('javascript:', $button.$notification.$empty);
	$t->notContains('data:text/html', $group);
	$t->notContains('javascript:', $uploader);
	$t->notContains('data:text/html', $uploader);
	$t->notContains('onmouseover', $button);
	$t->contains('data-safe="yes"', $button);
})->tag('panel', 'security', 'renderer')->maxMillis(1000);

test('action outcomes preserve local redirects and reject external destinations', static function(Context $t): void {
	$unsafe=$t->nonPublic(PanelRenderer::class)->invoke('outcome', ['redirect'=>'https://evil.example/phish'], 'Done');
	$local=PanelConfig::url();
	$safe=$t->nonPublic(PanelRenderer::class)->invoke('outcome', ['redirect'=>$local], 'Done');

	$t->same(null, $unsafe['redirect'] ?? null);
	$t->same($local, $safe['redirect'] ?? null);
})->tag('panel', 'security', 'redirect')->maxMillis(1000);

test('upload routing rejects traversal tokens and metadata drift', static function(Context $t): void {
	$storagePath=$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('storagePath', '../{filename}', 'report.pdf', 'upload123', 'document', 'default');
	$safePath=$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('storagePath', 'panel_uploads/{field}/{filename}', 'report.pdf', 'upload123', 'document', 'default');
	$manifest=[
		'upload_id'=>'upload123', 'filename'=>'report.pdf', 'size'=>42, 'mime'=>'application/pdf',
		'chunks'=>2, 'disk'=>'local', 'path'=>$safePath, 'visibility'=>'private',
	];

	$t->same('', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('token', '..'));
	$t->same('', $storagePath);
	$t->same(true, is_string($safePath) && str_starts_with($safePath, 'panel_uploads/document/'));
	$t->same(true, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('manifestMatches', $manifest, $manifest));
	$t->same(false, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('manifestMatches', $manifest, array_replace($manifest, ['disk'=>'private'])));
	$t->same('text/plain', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('mimeType', 'Text/Plain; charset=UTF-8'));
	$t->same('application/octet-stream', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('mimeType', "text/plain\r\nX-Test: yes"));
	$t->same(true, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('deletePathAllowed', 'panel_uploads/2026/report.pdf'));
	$t->same(false, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('deletePathAllowed', 'private/customer-export.csv'));

	$tmp=$t->tempFile('12345678','dp-panel-upload-test');
	$oversizedChunk=PanelStorageUploadEndpoint::handle([
		'upload_id'=>'uploadsize1', 'filename'=>'report.txt', 'size'=>'4', 'chunks'=>'1', 'chunk_index'=>'0',
	], ['file'=>['name'=>'report.txt', 'type'=>'text/plain', 'tmp_name'=>$tmp, 'error'=>UPLOAD_ERR_OK, 'size'=>8]]);
	$t->same('Upload chunk exceeds the declared upload size.', $oversizedChunk['error'] ?? null);

	$pendingTmp=$t->tempFile('1234','dp-panel-upload-lock');
	$pendingId='lock'.bin2hex(random_bytes(6));
	$directory=(string)$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('chunkDirectory', $pendingId);
	$t->cleanup(static fn()=>$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('cleanup',$directory));
	$pending=PanelStorageUploadEndpoint::handle([
		'upload_id'=>$pendingId, 'filename'=>'report.txt', 'size'=>'8', 'chunks'=>'2', 'chunk_index'=>'0',
	], ['file'=>['name'=>'report.txt', 'type'=>'text/plain', 'tmp_name'=>$pendingTmp, 'error'=>UPLOAD_ERR_OK, 'size'=>4]]);
	$t->same(true, ($pending['pending'] ?? false)===true);
	$lock=fopen($directory.'/.upload.lock', 'c+b');
	$t->same(true, is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB));
	if(is_resource($lock)){
		flock($lock, LOCK_UN);
		fclose($lock);
	}
})->tag('panel', 'security', 'upload')->maxMillis(1000);

test('upload mutations require a valid form-scoped CSRF token', static function(Context $t): void {
	$modulesRoot=dirname(__DIR__, 2);
	foreach([
		'/core/kernel/core_functions.php',
		'/core/Framework/CsrfToken.php',
		'/core/Framework/Csrf.php',
		'/http/Framework/UploadedFile.php',
		'/http/Framework/Request.php',
		'/http/Framework/Response.php',
	] as $file){
		$path=$modulesRoot.$file;
		if(is_file($path)){
			require_once $path;
		}
	}
	if(!class_exists(\Dataphyre\Csrf::class)){
		throw new RuntimeException('Dataphyre CSRF support is unavailable to the Panel test.');
	}
	$session=$t->global('_SESSION');
	if(!is_array($session->value())){
		$session->replace([]);
	}
	$invalid=PanelUploadController::handle(\Dataphyre\Http\Request::create('POST', '/panel/upload', [], []));
	$t->same(419, $invalid->status ?? null);

	$token=\Dataphyre\Csrf::value('dp_panel_upload');
	$valid=PanelUploadController::handle(\Dataphyre\Http\Request::create('POST', '/panel/upload', [], ['csrf'=>$token]));
	$t->same(422, $valid->status ?? null);
})->tag('panel', 'security', 'csrf', 'upload')->maxMillis(1000);
