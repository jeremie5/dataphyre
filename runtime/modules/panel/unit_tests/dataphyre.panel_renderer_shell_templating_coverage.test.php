<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!class_exists(__NAMESPACE__.'\\templating',false)){
		final class templating {
			/** @return array<string,mixed> */
			public static function plan_string(string $template,string $templateName='inline.tpl'): array { return []; }
			/** @param array<string,mixed> $data @param array<string,mixed> $themeValues @param array<string,mixed> $slots */
			public static function render_string(string $template,array $data=[],array $themeValues=[],array $slots=[],string $templateName='inline.tpl'): string { return $template; }
			/** @return array<string,mixed> */
			public static function asset_manifest_string(string $template,string $templateName='inline.tpl'): array { return []; }
		}
	}
}

namespace {
	use Dataphyre\Panel\PanelContext;
	use Dataphyre\Panel\PanelPageResult;
	use Dataphyre\Panel\PanelRenderer;
	use Dataphyre\Panel\PanelTheme;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['panel','templating']);

	test('panel renderer shell passes complete pages through framework templating when the kernel is active',static function(Context $t): void {
		$result=PanelContext::run([
			'theme'=>PanelTheme::make('templated-shell'),
			'navigation_layout'=>'none',
		],static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
			'page','Templated','<p>Body</p>',['kind'=>'custom','navigation_state'=>[]],200,[]
		));
		$t->same(200,$result->status());
		$t->contains('<!doctype html>',$result->content());
		$t->contains('Templated',$result->content());
	})->tag('panel','renderer','shell','coverage')->group('framework-coverage');
}
