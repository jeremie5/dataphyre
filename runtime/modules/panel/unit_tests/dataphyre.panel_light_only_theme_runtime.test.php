<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('light-only Panel themes cannot be overridden by a stored browser mode', static function(Context $t): void {
	$surface=Panel::make('light_only_'.bin2hex(random_bytes(3)))
		->homePage('home')
		->useTheme([
			'name'=>'light_only',
			'preset'=>'flat_minima',
			'dark_mode'=>false,
			'default_mode'=>'light',
			'mode_toggle'=>false,
		]);
	$surface->registerPage(PanelPage::make('home')->content('<p>Light-only surface</p>'));

	$html=$surface->dispatch(PanelRequest::fromArray(['method'=>'GET']))->content();
	$t->contains('data-dp-theme-mode="light"', $html);
	$t->contains('data-dp-theme-dark-mode="disabled"', $html);
	$t->notContains('data-dp-theme-mode-choice=', $html);

	$javascript=(string)(PanelRenderer::assetContent('panel.js', ['shell'])['content'] ?? '');
	$t->contains('dpPanelThemeModeLocked', $javascript);
	$t->contains('dataset.dpThemeDarkMode==="disabled"', $javascript);
	$t->contains('if(locked){mode="light";}', $javascript);
})->tag('panel','theme','light-only','browser-runtime','unit');
