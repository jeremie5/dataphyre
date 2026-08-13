<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Field;
use Dataphyre\Panel\InfolistEntry;
use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\PanelActionState;
use Dataphyre\Panel\Action;
use Dataphyre\Panel\PanelCollectionPresentation;
use Dataphyre\Panel\PanelFormState;
use Dataphyre\Panel\PanelInfolistState;
use Dataphyre\Panel\PanelLifecycleResult;
use Dataphyre\Panel\PanelMediaLibrary;
use Dataphyre\Panel\PanelPackageApplyResult;
use Dataphyre\Panel\PanelPackageLock;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Panel\PanelPackageTrustReport;
use Dataphyre\Panel\PanelRegressionReport;
use Dataphyre\Panel\PanelRelationState;
use Dataphyre\Panel\PanelSurfaceState;
use Dataphyre\Panel\PanelTableState;
use Dataphyre\Panel\PluginManifest;
use Dataphyre\Panel\ThemeManifest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Panel diagnostic value-object contracts')
	->framework(['panel'], [
		'functions'=>['tracelog'],
		'files'=>['panel/kernel/panel.main.php'],
	])
	->tag('panel','panel-remaining-values-exact')
	->group('framework-coverage');

test('panel remaining value objects expose every diagnostic accessor and compaction branch',static function(Context $t): void {
	$entry=InfolistEntry::from(Field::make('status'))
		->optionsUsing(static fn(): array=>['active'=>'Active'])
		->displayUsing(static fn(mixed $value): mixed=>$value)
		->visibleUsing(static fn(): bool=>true)
		->columnStart(2)
		->rowSpan(3);
	$t->instanceOf(InfolistEntry::class,$entry->required());
	$t->same('status',$entry->name());
	$t->throws(static fn()=>$entry->missingEntryMethod(),BadMethodCallException::class);

	$form=new PanelFormState(['name'=>'Ada'],[0=>'ignored',''=>['ignored'],'name'=>['Required']],['dirty_fields'=>'invalid']);
	$t->isEmpty($form->dirtyFields());
	$t->isEmpty($form->withErrors([0=>'ignored'],false)->errors());
	$diagnostic=new PanelFormState(['name'=>'Ada'],['name'=>['Required']],['initial_values'=>['name'=>'Grace'],'raw_values'=>['name'=>' Ada '],'dehydrated_values'=>['name'=>'Ada'],'dirty_fields'=>['name'],'state_updates'=>['name'=>['visible'=>true]]]);
	$t->hasPathValues(['name'=>'name'],$diagnostic->fieldState('name'));

	$infolist=PanelInfolistState::make([['name'=>'status','visible'=>true],'invalid',['name'=>'secret','visible'=>false]],[],[],['record'=>1]);
	$t->hasAccessorValues(['meta'=>['record'=>1]],$infolist);
	$t->hasPathValues(['name'=>'status'],$infolist->entry('Status'));
	$t->isNull($infolist->entry('missing'));

	$relation=new PanelRelationState(['name'=>'line_items','label'=>'Line items'],[],new PanelTableState(),[],[],[],[],[],[['label'=>'Total','value'=>2]],['heading'=>'Empty']);
	$t->same('line_items',$relation->relation()['name']);
	$t->same('Line items',$relation->relationLabel());
	$t->same(1,count($relation->facts()));
	$t->same('Empty',$relation->emptyState()['heading']);

	$lifecycle=PanelLifecycleResult::halt('Stopped',[],422,['reason'=>'coverage']);
	$t->same(['reason'=>'coverage'],$lifecycle->payload());
	$known=new PanelActionState(['name'=>'known'],'action',null,[],$lifecycle);
	$t->same('Stopped',$known->jsonSerialize()['result']['message']);
	$object=new PanelActionState(['name'=>'object'],'action',null,[],new stdClass());
	$t->same('object',$object->jsonSerialize()['result']['type']);
	$long=new PanelActionState(['name'=>'long'],'action',null,[],str_repeat('x',801));
	$t->same(803,strlen($long->jsonSerialize()['result']));

	$surface=PanelSurfaceState::make('Coverage',200,['request'=>['path'=>'/panel'],'form_state'=>new stdClass()]);
	$t->same('/panel',$surface->request()['path']);
	$t->same(stdClass::class,$surface->jsonSerialize()['states']['form_state']['type']);

	$report=new PanelRegressionReport('coverage',[['status'=>'skipped']],12.3456);
	$t->hasAccessorValues(['hasSkipped'=>true,'durationMs'=>12.3456],$report);
	$t->contains('1 skipped',$report->summary());
	$t->hasConsistentSerialization($report);

	$trust=new PanelPackageTrustReport([['id'=>'one']],['trusted'=>1,'blocked'=>0],['mode'=>'strict'],['source'=>'coverage']);
	$t->same('one',$trust->packages()[0]['id']);
	$t->same('panel_package_trust_report',$trust->toArray()['type']);
	$t->hasConsistentSerialization($trust);

	$apply=PanelPackageApplyResult::make(['package'=>['id'=>'one'],'target_root'=>'/tmp/panel']);
	$t->hasPathValues(['id'=>'one'],$apply->package());
	$t->hasAccessorValues(['targetRoot'=>'/tmp/panel'],$apply);
	$t->hasConsistentSerialization($apply);
	$lock=new PanelPackageLock(['checksum'=>'sha256:coverage','packages'=>[['id'=>'one']]]);
	$t->hasAccessorValues(['checksum'=>'sha256:coverage'],$lock);
	$t->same(1,count($lock->packages()));

	$presentation=PanelCollectionPresentation::fromMeta(['presentation'=>['views'=>['display'=>'pill','columns'=>3]]],'views');
	$t->hasPathValues(['display'=>'segmented'],$presentation);
	$t->same('inline',PanelCollectionPresentation::normalize('row')['display']);
	$t->same([],PanelCollectionPresentation::normalize(['columns'=>new stdClass()])['columns']);

	$library=PanelMediaLibrary::make(['images'=>['accept'=>['image/png']]])->meta(['source'=>'coverage'])->meta('mode','test');
	$t->isTrue($library->has('images'));
	$item=$library->item('images',['name'=>'avatar.png','filename'=>'avatar.png','type'=>'image/png','mime'=>'image/png','size'=>10]);
	$t->contains('avatar-',$item->toArray()['filename']);
	$t->hasKey('ok',$library->validate('images',['name'=>'avatar.png','filename'=>'avatar.png','type'=>'image/png','mime'=>'image/png','size'=>10]));
	$t->same('test',$library->toArray()['metadata']['mode']);

	$template=PanelPackageTemplate::make('coverage-theme')->theme()->meta(['source'=>'coverage'])->meta('mode','test');
	$t->hasKey('src/CoverageThemeTheme.php',array_column($template->artifacts(),null,'path'));
	$t->same('panel_package_template',$template->toArray()['type']);

	$t->same('not_a_loadedplugin',PluginManifest::from('Not\\A\\LoadedPlugin',['enabled'=>true])->toArray()['id']);
	$t->same('coverage-theme',ThemeManifest::from('coverage-theme')->toArray()['name']);
	$t->same('theme_manifest',ThemeManifest::from(null)->toArray()['type']);

	$table=PageTable::fromArray(['name'=>'coverage','presentation'=>['views'=>'brick']]);
	$t->same('brick',$table->presentations()['views']['display']);
	$t->same($table,$table->collectionPresentation(' ', 'row'));
	$t->same('close',Action::make('coverage')->modalNavigation('unsupported')->toArray()['effects']['modal_navigation']);
	$t->same('fallback',\dataphyre\panel::config('coverage_missing','fallback'));
});
