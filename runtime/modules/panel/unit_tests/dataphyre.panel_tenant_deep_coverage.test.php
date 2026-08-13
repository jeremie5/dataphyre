<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTenant;
use Dataphyre\Panel\PanelTrace;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel tenant imports static definitions and fluent builders stay immutable',static function(Context $t): void {
	$tenant=PanelTenant::fromArray([
		'name'=>'north-america','label'=>' North America ','description'=>' Main region ','icon'=>' globe ','url'=>' /tenant/na ',
		'sort'=>'5','badge'=>12,'badge_tone'=>'SUCCESS','current'=>1,'hidden'=>true,'meta'=>['region'=>'na'],
	]);
	$array=$tenant->toArray();
	$t->same('north-america',$tenant->name());
	$t->same('North America',$array['label']);
	$t->same('Main region',$array['description']);
	$t->same('globe',$array['icon']);
	$t->same('/tenant/na',$array['url']);
	$t->same(5,$array['sort']);
	$t->same(12,$array['badge']);
	$t->same('success',$array['badge_tone']);
	$t->isTrue($array['current']);
	$t->isTrue($array['hidden']);
	$t->same(['region'=>'na'],$array['meta']);
	$t->same($array,$tenant->definition());

	$base=PanelTenant::make('south_east');
	$built=$base->label(' Built ')->description(' ')->icon(' ')->url('/built')->badge('hot')->badgeTone('invalid')->current(false)
		->sort(-2)->hide(false)->meta(['one'=>1])->meta(['two'=>2]);
	$t->same('South East',$base->toArray()['label']);
	$t->same('Built',$built->toArray()['label']);
	$t->same(null,$built->toArray()['description']);
	$t->same(null,$built->toArray()['icon']);
	$t->same('neutral',$built->toArray()['badge_tone']);
	$t->same(['one'=>1,'two'=>2],$built->toArray()['meta']);
	$t->same('',PanelTenant::make('...')->name());
	$unsafe=PanelTenant::make('safe')->url('javascript:alert(1)')->badge([])->meta(['api_token'=>'private']);
	$t->same(null,$unsafe->toArray()['url']);
	$t->same(null,$unsafe->toArray()['badge']);
	$t->same('[redacted]',$unsafe->toArray()['meta']['api_token']);
	$t->same(null,PanelTenant::make('credentials')->url('https://user:pass@example.com')->toArray()['url']);
	$t->same(null,PanelTenant::make('network')->url('//evil.example/path')->toArray()['url']);
})->tag('panel','tenant','coverage')->group('framework-coverage');

test('panel tenant evaluates lazy url current badge and visibility resolvers',static function(Context $t): void {
	$request=PanelRequest::fromArray(['method'=>'GET','query'=>['tenant'=>'alpha']]);
	$manager=new PanelManager();
	$tenant=PanelTenant::make('alpha')
		->url(static fn(PanelRequest $request,PanelTenant $tenant,PanelManager $manager): string=>'/tenant/'.$tenant->name())
		->badge(static fn(PanelRequest $request,PanelTenant $tenant,PanelManager $manager): int=>7)
		->current(static fn(PanelRequest $request): bool=>$request->query('tenant')==='alpha')
		->visibleUsing(static fn(PanelRequest $request,PanelTenant $tenant,PanelManager $manager): bool=>$tenant->name()==='alpha');
	$t->isTrue($tenant->isVisible($request,$manager));
	$array=$tenant->toArray($request,$manager);
	$t->same('/tenant/alpha',$array['url']);
	$t->same(7,$array['badge']);
	$t->isTrue($array['current']);
	$t->isTrue($array['visible_lazy']);
	$t->isTrue($array['url_lazy']);
	$t->isTrue($array['current_lazy']);
	$t->isTrue($array['badge_lazy']);
	$t->isFalse(PanelTenant::make('hidden')->hide()->isVisible($request,$manager));
	$t->isTrue(PanelTenant::make('plain')->isVisible($request,$manager));
	$t->isFalse(PanelTenant::make('broken')->visibleUsing(static function(): bool { throw new RuntimeException('visibility failed'); })->isVisible($request,$manager));
})->tag('panel','tenant','coverage')->group('framework-coverage');

test('panel tenant resolver failures fail closed and static values clear lazy state',static function(Context $t): void {
	$request=PanelRequest::fromArray(['method'=>'GET']);
	$manager=new PanelManager();
	PanelTrace::flush();
	$broken=PanelTenant::make('broken')
		->url(static function(): string { throw new RuntimeException('url failed'); })
		->current(static function(): bool { throw new RuntimeException('current failed'); })
		->badge(static function(): int { throw new RuntimeException('badge failed'); });
	$array=$broken->toArray($request,$manager);
	$t->same(null,$array['url']);
	$t->isFalse($array['current']);
	$t->same(null,$array['badge']);
	$trace=json_encode(PanelTrace::events(),JSON_THROW_ON_ERROR);
	$t->isFalse(str_contains($trace,'url failed'));
	$t->isFalse(str_contains($trace,'current failed'));
	$t->isFalse(str_contains($trace,'badge failed'));
	$t->isFalse(str_contains($trace,'"tenant":"broken"'));
	$t->contains(hash('sha256','broken'),$trace);
	$lazyUnsafe=PanelTenant::make('lazy-unsafe')->url(static fn(): string=>'javascript:alert(1)')->badge(static fn(): array=>['unsafe']);
	$t->same(null,$lazyUnsafe->toArray($request,$manager)['url']);
	$t->same(null,$lazyUnsafe->toArray($request,$manager)['badge']);
	$static=$broken->url(' /static ')->current(true)->badge('static');
	$array=$static->toArray($request,$manager);
	$t->same('/static',$array['url']);
	$t->isTrue($array['current']);
	$t->same('static',$array['badge']);
	$t->isFalse($array['url_lazy']);
	$t->isFalse($array['current_lazy']);
	$t->isFalse($array['badge_lazy']);

	$minimal=PanelTenant::fromArray(['name'=>'minimal','label'=>42,'description'=>42,'icon'=>42,'url'=>42,'sort'=>null,'badge_tone'=>42,'meta'=>'bad']);
	$t->same('Minimal',$minimal->toArray()['label']);
})->tag('panel','tenant','coverage')->group('framework-coverage');
