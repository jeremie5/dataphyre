<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel {
	require_once __DIR__.'/panel_test_probes.php';

	if(!function_exists(__NAMESPACE__.'\class_exists')){
		function class_exists(string $class,bool $autoload=true): bool {
			if(ltrim($class,'\\')==='DOMDocument' && !\Dataphyre\Panel\TestFixtures\AccessibilityEnvironment::domAvailable()){
				return false;
			}
			return \class_exists($class,$autoload);
		}
	}
}

namespace {
	use Dataphyre\Panel\PanelAccessibilityAudit;
	use Dataphyre\Panel\PanelPageResult;
	use Dataphyre\Panel\TestFixtures\AccessibilityEnvironment;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['panel']);

	/** @return list<string> */
	function dp_panel_accessibility_audit_rules(PanelAccessibilityAudit $audit,?string $severity=null): array {
		return array_values(array_map(
			static fn(array $issue): string=>(string)($issue['rule'] ?? ''),
			$audit->issues($severity)
		));
	}

	test('panel accessibility audit accepts a fully named DOM surface and reports metrics',static function(Context $t): void {
		$html=<<<'HTML'
<!doctype html>
<html lang="en">
<head>
	<style>@media (prefers-reduced-motion: reduce){*{animation:none}}</style>
</head>
<body>
	<div id=""></div>
	<span id="button-label">Open menu</span>
	<span id="dialog-title">Confirm order</span>
	<span id="description">Supporting description</span>
	<div id="controls">Controlled content</div>
	<div id="owned">Owned content</div>
	<div id="active">Active option</div>
	<button type="button">Save</button>
	<button type="button" aria-label="Close"></button>
	<button type="button" aria-labelledby="button-label"></button>
	<a href="/orders">Orders</a>
	<a href="/help" title="Help"></a>
	<img src="/preview.png" alt="Order preview">
	<img src="/decoration.png" aria-hidden="true">
	<input type="hidden" name="token" value="x">
	<input name="search" placeholder="Search orders">
	<label for="email">Email</label><input id="email" name="email">
	<label>Notes <span><textarea name="notes"></textarea></span></label>
	<select name="status" aria-label="Status"><option>Open</option></select>
	<section role="dialog" aria-modal="true" aria-labelledby="dialog-title"></section>
	<div aria-live="polite">Saved</div>
	<div aria-describedby="description" aria-controls="controls" aria-owns="owned" aria-activedescendant="active"></div>
	<div aria-controls=""></div>
</body>
</html>
HTML;
		$audit=PanelAccessibilityAudit::from(PanelPageResult::html($html),['surface'=>'orders']);
		$t->isTrue($audit->passed());
		$t->same(100,$audit->score());
		$t->same([],$audit->issues());
		$t->same(0,$audit->issueCount());
		$metrics=$audit->metrics();
		$t->same(3,$metrics['buttons']);
		$t->same(2,$metrics['links']);
		$t->same(2,$metrics['images']);
		$t->same(4,$metrics['inputs']);
		$t->same(1,$metrics['dialogs']);
		$t->same(1,$metrics['live_regions']);
		$t->same(0,$metrics['duplicate_ids']);
		$t->same(1,$metrics['reduced_motion_hooks']);
		$t->isTrue($metrics['aria_references']>=6);
		$report=$audit->toArray();
		$t->same('panel_accessibility_audit',$report['type']);
		$t->same(['surface'=>'orders'],$report['meta']);
		$t->same($report,$audit->jsonSerialize());
	})->tag('panel','accessibility-audit','coverage')->group('framework-coverage');

	test('panel accessibility audit reports every DOM rule and clamps severe scores',static function(Context $t): void {
		$html=<<<'HTML'
<!doctype html>
<html><body>
	<div id="duplicate"></div><span id="duplicate"></span>
	<button type="button"><span aria-hidden="true"></span></button>
	<a href="/empty"><svg aria-hidden="true"></svg></a>
	<img src="/missing-alt.png">
	<input name="unlabelled">
	<div role="alertdialog"></div>
	<div aria-live="loud"
		aria-labelledby="missing-label"
		aria-describedby="missing-description"
		aria-controls=""
		aria-owns="missing-owner"
		aria-activedescendant="missing-active"></div>
</body></html>
HTML;
		$audit=PanelAccessibilityAudit::from($html,['case'=>'broken']);
		$t->isFalse($audit->passed());
		$t->same(0,$audit->score());
		$t->isTrue($audit->issueCount('error')>=8);
		$t->isTrue($audit->issueCount('warning')>=4);
		$errors=dp_panel_accessibility_audit_rules($audit,'error');
		foreach(['duplicate_id','button_name','image_alt','input_label','aria_reference'] as $rule){
			$t->isTrue(in_array($rule,$errors,true),$rule);
		}
		$warnings=dp_panel_accessibility_audit_rules($audit,'warning');
		foreach(['link_name','dialog_modal','dialog_label','aria_live_value','reduced_motion'] as $rule){
			$t->isTrue(in_array($rule,$warnings,true),$rule);
		}
		$t->same(1,$audit->metrics()['duplicate_ids']);
		$t->isTrue($audit->metrics()['aria_references']>=4);
		$t->same('broken',$audit->toArray()['meta']['case']);

		$noLive=PanelAccessibilityAudit::from('<main>Static content</main><style>prefers-reduced-motion</style>');
		$t->isTrue(in_array('live_region',dp_panel_accessibility_audit_rules($noLive,'warning'),true));

		$t->nonPublic($audit)->invoke('issue','fatal','normalized_severity','Normalized warning');
		$t->nonPublic($audit)->invoke('issue','info','diagnostic','Informational issue',['source'=>'test']);
		$t->same('warning',$audit->issues()[array_key_last($audit->issues())-1]['severity']);
		$t->same('info',$audit->issues()[array_key_last($audit->issues())]['severity']);
	})->tag('panel','accessibility-audit','coverage')->group('framework-coverage');

	test('panel accessibility audit resolves every accessible name and form label source',static function(Context $t): void {
		$dom=new DOMDocument();
		$dom->loadHTML(<<<'HTML'
<!doctype html><html><body>
	<span id="known">Known label</span>
	<button id="text">Text name</button>
	<button id="aria" aria-label="ARIA name"></button>
	<button id="title" title="Title name"></button>
	<button id="alt" alt="Alternative name"></button>
	<button id="labelled" aria-labelledby="missing known"></button>
	<button id="unknown" aria-labelledby="missing"></button>
	<input id="named" aria-label="Named control">
	<input id="placeholder" placeholder="Placeholder control">
	<label for="for-control">For label</label><input id="for-control">
	<label><span><input id="wrapped"></span></label>
	<input id="unlabelled">
</body></html>
HTML);
		$xpath=new DOMXPath($dom);
		$ids=['known'=>1];
		foreach(['text','aria','title','alt','labelled'] as $id){
			$node=$dom->getElementById($id);
			$t->isTrue($node instanceof DOMElement);
			$t->isTrue($t->nonPublic(
				PanelAccessibilityAudit::from('<div aria-live="off"></div><style>prefers-reduced-motion</style>')
			)->invoke('hasAccessibleName',$node,$ids),$id);
		}
		$audit=PanelAccessibilityAudit::from('<div aria-live="off"></div><style>prefers-reduced-motion</style>');
		$t->isFalse($t->nonPublic($audit)->invoke('hasAccessibleName',$dom->getElementById('unknown'),$ids,));
		foreach(['named','placeholder','for-control','wrapped'] as $id){
			$t->isTrue($t->nonPublic($audit)->invoke('inputHasLabel',$dom->getElementById($id),$xpath,$ids,),$id);
		}
		$t->isFalse($t->nonPublic($audit)->invoke('inputHasLabel',$dom->getElementById('unlabelled'),$xpath,$ids,));
		$sample=$t->nonPublic($audit)->invoke('nodeSample',$dom->getElementById('text'));
		$t->contains('<button id="text">Text name</button>',$sample);
		$t->same('',$t->nonPublic($audit)->invoke('nodeSample',new DOMElement('div')));
	})->tag('panel','accessibility-audit','coverage')->group('framework-coverage');

	test('panel accessibility audit uses conservative fallback checks without DOM',static function(Context $t): void {
		AccessibilityEnvironment::reset($t)->withoutDom();
		$html=<<<'HTML'
<style>prefers-reduced-motion</style>
<div aria-live="polite"></div>
<button></button>
<button><span>Named button</span></button>
<button aria-label="Named"></button>
<button title="Titled"></button>
<img src="/missing.png">
<img src="/empty-alt.png" alt="">
<img src="/decorative.png" aria-hidden='true'>
HTML;
		$audit=PanelAccessibilityAudit::from($html);
		$t->isFalse($audit->passed());
		$t->same(4,$audit->metrics()['buttons']);
		$t->same(3,$audit->metrics()['images']);
		$t->same(1,$audit->metrics()['reduced_motion_hooks']);
		$t->same(2,$audit->issueCount('error'));
		$t->same(['button_name','image_alt'],dp_panel_accessibility_audit_rules($audit,'error'));
	})->tag('panel','accessibility-audit','coverage')->group('framework-coverage');
}
