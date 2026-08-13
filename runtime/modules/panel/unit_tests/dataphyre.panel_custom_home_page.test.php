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
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

test('Panel mounts an authorized registered custom page at the surface root', static function(Context $t): void {
	$surface=Panel::make('custom_home_'.bin2hex(random_bytes(3)))
		->homePage('operator_overview')
		->config(['live_updates'=>true,'live_update_interval_ms'=>5000]);
	$surface->registerPage(PanelPage::make('operator_overview')->label('Operator overview')->content('<p>Domain workspace</p>'));

	$result=$surface->dispatch(PanelRequest::fromArray(['method'=>'GET','user'=>['id'=>7]]));
	$t->same(200, $result->status());
	$t->same('custom_page', $result->data()['kind'] ?? null);
	$t->contains('Domain workspace', $result->content());
	$t->contains('data-dp-panel-live-interval="5000"', $result->content());
})->tag('panel','home-page','unit');

test('Panel custom home fails closed through page authorization and falls back when missing', static function(Context $t): void {
	$denied=Panel::make('custom_home_denied_'.bin2hex(random_bytes(3)))->homePage(PanelPage::make('denied_home'));
	$denied->registerPage(PanelPage::make('denied_home')->authorize(static fn(): bool=>false));
	$t->same(403, $denied->dispatch(PanelRequest::fromArray(['method'=>'GET','user'=>['id'=>7]]))->status());

	$fallback=Panel::make('custom_home_missing_'.bin2hex(random_bytes(3)))->homePage('not_registered');
	$t->same('dashboard', $fallback->dispatch(PanelRequest::fromArray(['method'=>'GET']))->data()['kind'] ?? null);
	$t->same(null, Dataphyre\Panel\PanelContext::run(['home_page'=>''], static fn()=>Dataphyre\Panel\PanelConfig::homePage()));
})->tag('panel','home-page','unit');
