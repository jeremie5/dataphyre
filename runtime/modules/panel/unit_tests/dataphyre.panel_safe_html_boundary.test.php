<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\Infolist;
use Dataphyre\Panel\InfolistEntry;
use Dataphyre\Panel\PanelEditorSanitizationPolicy;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSafeHtml;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

/** @param array<string,mixed> $headers */
function dp_panel_safe_html_request(string $operation='show', array $headers=[]): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>'GET',
		'resource'=>'safe_html_records',
		'operation'=>$operation,
		'record'=>'1',
		'headers'=>$headers,
		'user'=>['id'=>901,'name'=>'Safe HTML Boundary'],
	]);
}

/** Assert that a rendered surface contains no executable attacker markup. */
function dp_panel_assert_non_executable_html(Context $t, string $html): void {
	$dangerousElements=str_contains(strtolower($html), '<!doctype html>')
		? 'iframe|object|embed|svg|math|template'
		: 'script|style|iframe|object|embed|svg|math|template';
	$t->same(0, preg_match('/<\s*\/?\s*(?:'.$dangerousElements.')\b/i', $html));
	$t->same(0, preg_match('/\s+on[a-z0-9_-]+\s*=/i', $html));
	$t->same(0, preg_match('/\s(?:href|src|action|formaction|xlink:href|srcdoc)\s*=\s*(["\'])?\s*(?:javascript|vbscript)\s*:/i', $html));
	$t->same(false, str_contains(strtolower($html), 'alert(1)'));
}

function dp_panel_safe_html_show_surface(Context $t, string $payload): string {
	$field=InfolistEntry::make('payload')->html()->toArray();
	return (string)$t->nonPublic(PanelRenderer::class)->invoke('showEntryHtml', [
		'name'=>'payload',
		'label'=>'Payload',
		'display'=>$payload,
		'raw'=>$payload,
		'meta'=>$field['meta'] ?? [],
		'field'=>$field,
	]);
}

function dp_panel_safe_html_infolist_surface(string $payload): string {
	$resource=Resource::make('safe_html_records')
		->recordKeyUsing('id')
		->recordTitleUsing('name')
		->infolist(Infolist::make([
			InfolistEntry::make('payload')->label('Payload')->html(),
		]));
	return PanelRenderer::show(
		$resource,
		dp_panel_safe_html_request(),
		['id'=>'1','name'=>'Boundary record','payload'=>$payload],
	)->content();
}

function dp_panel_safe_html_modal_surface(string|PanelSafeHtml $payload): string {
	$field=Field::make('preview', 'display_only')->label('Preview')->htmlContent($payload);
	$action=Action::make('preview')
		->label('Preview')
		->modal('Preview payload', 'Security boundary', 'md')
		->field($field)
		->handle(static fn(): string=>'No mutation');
	$resource=Resource::make('safe_html_records')
		->recordKeyUsing('id')
		->recordTitleUsing('name')
		->actions([$action]);
	return PanelRenderer::actionResult(
		$resource,
		dp_panel_safe_html_request('action', ['x-requested-with'=>'DataphyrePanelModal']),
		'preview',
		['id'=>'1','name'=>'Boundary record'],
	)->content();
}

dataset('panel adversarial rich html payloads', [
	'script element'=>['<script>alert(1)</script><strong>kept-script</strong>', '<strong>kept-script</strong>'],
	'unquoted event handler'=>['<img src=x onerror=alert(1)><em>kept-event</em>', '<em>kept-event</em>'],
	'encoded javascript URL'=>['<a href="&#x6a;avascript:alert(1)">kept-link</a>', '>kept-link</a>'],
	'malformed script nesting'=>['<<script>alert(1)//<</script><p>kept-malformed</p>', '<p>kept-malformed</p>'],
	'svg namespace payload'=>['<svg><a xlink:href="javascript:alert(1)"><text>bad</text></a></svg><b>kept-svg</b>', '<b>kept-svg</b>'],
	'mathml namespace payload'=>['<math><mtext onclick="alert(1)">bad</mtext></math><u>kept-math</u>', '<u>kept-math</u>'],
]);

test('html true sanitizes adversarial strings across show infolist and modal surfaces', static function(Context $t, string $payload, string $preserved): void {
	foreach([
		'show'=>dp_panel_safe_html_show_surface($t, $payload),
		'infolist'=>dp_panel_safe_html_infolist_surface($payload),
		'modal'=>dp_panel_safe_html_modal_surface($payload),
	] as $html){
		dp_panel_assert_non_executable_html($t, $html);
		$t->contains($preserved, $html);
	}
})->with('panel adversarial rich html payloads')->tag('panel','renderer','security','xss','safe-html')->group('framework-coverage');

test('safe html is explicit fail closed and loses trust across serialization', static function(Context $t): void {
	$sanitized=PanelSafeHtml::sanitize('<p onclick="bad()">Safe <strong>rich text</strong></p><script>bad()</script>');
	$t->same('sanitized', $sanitized->source());
	$t->same(false, $sanitized->isTrusted());
	$t->same($sanitized->html(), (string)$sanitized);
	dp_panel_assert_non_executable_html($t, $sanitized->html());
	$t->contains('<strong>rich text</strong>', $sanitized->html());

	$policy=PanelEditorSanitizationPolicy::strict()->disallowElement('strong');
	$t->same('<p>policy text</p>', PanelSafeHtml::sanitize('<p><strong>policy text</strong></p>', $policy)->html());

	$trusted=PanelSafeHtml::trusted('<span class="dp-panel-framework-markup"><i aria-hidden="true">DP</i> Trusted</span>');
	$t->same('trusted', $trusted->source());
	$t->same(true, $trusted->isTrusted());
	$t->same($trusted->html(), $trusted->jsonSerialize());
	$t->same($trusted->html(), PanelSafeHtml::fromTrusted($trusted->html())->html());
	$t->same('"<span class=\"dp-panel-framework-markup\"><i aria-hidden=\"true\">DP<\/i> Trusted<\/span>"', json_encode($trusted));

	$roundTrip=json_decode((string)json_encode($trusted), true);
	$t->same(true, is_string($roundTrip));
	$t->same(false, str_contains(dp_panel_safe_html_show_surface($t, (string)$roundTrip), 'class="dp-panel-framework-markup"'));

	$invalid="\xC3\x28";
	$t->same('sanitized', PanelSafeHtml::sanitize($invalid)->source());
	$t->throws(static fn()=>PanelSafeHtml::trusted($invalid), \InvalidArgumentException::class);
})->tag('panel','renderer','security','safe-html','contract')->group('framework-coverage');

test('trusted framework markup remains intact only through explicit safe html APIs', static function(Context $t): void {
	$markup='<span class="dp-panel-framework-markup" data-dp-generated="1"><i aria-hidden="true">DP</i> Trusted</span>';
	$trusted=PanelSafeHtml::fromTrusted($markup);

	$field=Field::make('trusted_preview', 'display_only')->trustedHtmlContent($markup);
	$fieldMeta=$field->toArray();
	$t->instanceOf(PanelSafeHtml::class, $fieldMeta['meta']['display_content']);
	$t->contains('safe_html', $fieldMeta['component']['capabilities']);
	$t->contains('trusted_html', $fieldMeta['component']['capabilities']);
	$t->same(false, in_array('sanitized_html', $fieldMeta['component']['capabilities'], true));

	$legacy=Field::make('legacy_preview', 'display_only')->htmlContent($markup)->toArray();
	$t->same(true, is_string($legacy['meta']['display_content']));
	$t->contains('sanitized_html', $legacy['component']['capabilities']);
	$t->same(false, in_array('trusted_html', $legacy['component']['capabilities'], true));

	$direct=$t->nonPublic(PanelRenderer::class)->invoke(
		'showFieldHtml',
		Field::make('payload')->displayUsing(static fn(): PanelSafeHtml=>$trusted),
		Field::make('payload')->toArray(),
		'ignored',
	);
	$t->contains($markup, (string)$direct);

	$resource=Resource::make('safe_html_records')
		->recordKeyUsing('id')
		->recordTitleUsing('name')
		->infolist(Infolist::make([
			InfolistEntry::make('dynamic')->displayUsing(static fn(): PanelSafeHtml=>$trusted),
			InfolistEntry::make('fixed')->trustedHtml($markup),
		]));
	$infolist=PanelRenderer::show(
		$resource,
		dp_panel_safe_html_request(),
		['id'=>'1','name'=>'Boundary record','dynamic'=>'ignored','fixed'=>'ignored'],
	)->content();
	$t->same(2, substr_count($infolist, $markup));
	$t->contains($markup, dp_panel_safe_html_modal_surface($trusted));

	$prefixed=$t->nonPublic(PanelRenderer::class)->invoke(
		'entryValueHtml',
		$trusted,
		'ignored',
		['type'=>'text','meta'=>['prefix'=>'<img src=x onerror=bad()>','suffix'=>'<script>bad()</script>']],
	);
	$t->contains('&lt;img src=x onerror=bad()&gt;', (string)$prefixed);
	$t->contains('&lt;script&gt;bad()&lt;/script&gt;', (string)$prefixed);
	$t->same(0, preg_match('/<(?:img|script)\b/i', (string)$prefixed));
})->tag('panel','renderer','security','safe-html','trusted')->group('framework-coverage');
