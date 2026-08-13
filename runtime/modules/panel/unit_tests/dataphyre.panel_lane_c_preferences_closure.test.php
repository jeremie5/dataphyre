<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelFilesystemPreferenceStore;
use Dataphyre\Panel\PanelInMemoryPreferenceStore;
use Dataphyre\Panel\PanelPreferenceConflictException;
use Dataphyre\Panel\PanelPreferenceStateEngine;
use Dataphyre\Panel\PanelPreferenceStore;
use Dataphyre\Panel\PanelWorkspacePreferenceProfile;
use Dataphyre\Panel\PanelWorkspacePreferences;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

final class DpPanelAlwaysConflictingPreferenceStore implements PanelPreferenceStore {
	private PanelWorkspacePreferenceProfile $profile;
	public function __construct(){ $this->profile=new PanelWorkspacePreferenceProfile('operator'); }
	public function load(string $userId,string $profile='default'): ?PanelWorkspacePreferenceProfile { return $this->profile; }
	public function save(PanelWorkspacePreferenceProfile $profile,?int $expectedRevision=null,string $strategy='reject'): PanelWorkspacePreferenceProfile {
		throw new PanelPreferenceConflictException($profile->userId(),$profile->name(),$expectedRevision,$this->profile->revision(),['revision']);
	}
	public function delete(string $userId,string $profile='default',?int $expectedRevision=null): bool { return false; }
	public function profiles(string $userId): array { return [$this->profile]; }
	public function history(string $userId,string $profile='default',int $limit=100): array { return [$this->profile]; }
	public function export(string $userId,?string $profile=null): array { return []; }
	public function import(array $payload,string $strategy='merge'): array { return []; }
	public function cursor(): int { return 0; }
	public function changesSince(int $cursor=0,int $limit=100): array { return []; }
	public function manifest(array $meta=[]): array { return ['type'=>'always_conflicting']; }
}

test('preference state handles pruned history imports overlays and serializable values', static function(Context $t): void {
	$state=PanelPreferenceStateEngine::initialState();
	$first=PanelPreferenceStateEngine::save($state,new PanelWorkspacePreferenceProfile('operator','default',['layout'=>['density'=>'normal']]),0,'reject',2);
	$second=PanelPreferenceStateEngine::save($state,$first->with(['layout'=>['density'=>'compact']]),1,'reject',2);
	PanelPreferenceStateEngine::save($state,$second->with(['layout'=>['density'=>'roomy']]),2,'reject',2);
	$stale=$first->with(['layout'=>['theme'=>'glass']]);
	$historyConflict=$t->throws(static fn()=>PanelPreferenceStateEngine::save($state,$stale,0,'merge',2),PanelPreferenceConflictException::class);
	$t->same(0,$historyConflict->expectedRevision());

	$t->throws(static fn()=>PanelPreferenceStateEngine::import($state,[]),InvalidArgumentException::class);
	$wrongUser=['type'=>'panel_workspace_preferences_export','version'=>1,'user_id'=>'operator','profiles'=>[
		(new PanelWorkspacePreferenceProfile('other'))->toArray(),
	]];
	$t->throws(static fn()=>PanelPreferenceStateEngine::import($state,$wrongUser),InvalidArgumentException::class);
	$existing=['type'=>'panel_workspace_preferences_export','version'=>1,'user_id'=>'operator','profiles'=>[
		(new PanelWorkspacePreferenceProfile('operator','default',['layout'=>['theme'=>'flat']]))->toArray(),
	]];
	$t->throws(static fn()=>PanelPreferenceStateEngine::import($state,$existing,'reject'),PanelPreferenceConflictException::class);
	$merged=PanelPreferenceStateEngine::import($state,$existing,'merge');
	$t->same('flat',$merged[0]->settings()['layout']['theme']);
	$t->same('roomy',$merged[0]->settings()['layout']['density']);

	$jsonValue=new class implements JsonSerializable { public function jsonSerialize(): array { return ['safe'=>'yes','token'=>'remove']; } };
	$stringValue=new class implements Stringable { public function __toString(): string { return 'string-value'; } };
	$t->same(['safe'=>'yes'],PanelPreferenceStateEngine::sanitize($jsonValue));
	$t->same('string-value',PanelPreferenceStateEngine::sanitize($stringValue));
	$t->same(null,PanelPreferenceStateEngine::sanitize(new stdClass()));
})->tag('panel','preferences','state','coverage')->group('panel-lane-c');

test('in-memory preferences expose retained cursor resets profiles and serialization', static function(Context $t): void {
	$store=new PanelInMemoryPreferenceStore(8,2);
	$current=$store->save(new PanelWorkspacePreferenceProfile('operator','zeta'),0);
	$store->save(new PanelWorkspacePreferenceProfile('operator','alpha'),0);
	for($revision=0;$revision<10;$revision++){
		$current=$store->save($current->with(['revision_marker'=>$revision]),$current->revision());
	}
	$t->same(['alpha','zeta'],array_map(static fn(PanelWorkspacePreferenceProfile $profile): string=>$profile->name(),$store->profiles('operator')));
	$t->same($store->cursor(),$store->jsonSerialize()['cursor']);
	$reset=$store->changesSince(1,2000);
	$t->isTrue($reset['reset_required']);
	$t->same([],$reset['changes']);
	$t->same('panel_workspace_preferences_snapshot',$reset['snapshot']['type']);
})->tag('panel','preferences','memory','coverage')->group('panel-lane-c');

test('filesystem preference store imports exports lists deletes and serializes atomically', static function(Context $t): void {
	$source=new PanelInMemoryPreferenceStore();
	$source->save(new PanelWorkspacePreferenceProfile('operator','portable',['theme'=>'glass']),0);
	$payload=$source->export('operator');
	$store=new PanelFilesystemPreferenceStore($t->workspace('panel-lane-c-preferences')->directory('store'),8,3);
	$t->same(1,count($store->import($payload,'merge')));
	$t->same(['portable'],array_map(static fn(PanelWorkspacePreferenceProfile $profile): string=>$profile->name(),$store->profiles('operator')));
	$t->same(1,count($store->export('operator')['profiles']));
	$t->isTrue($store->delete('operator','portable',1));
	$t->same('filesystem_atomic_json',$store->jsonSerialize()['adapter']);
})->tag('panel','preferences','filesystem','coverage')->group('panel-lane-c');

test('workspace preferences delete views import payloads serialize and bound retry conflicts', static function(Context $t): void {
	$store=new PanelInMemoryPreferenceStore();
	$workspace=new PanelWorkspacePreferences($store,'operator');
	$workspace->saveTableView('orders','attention',['filters'=>['status'=>'attention']]);
	$workspace->deleteTableView('orders','attention');
	$t->same(null,$workspace->tableView('orders','attention'));
	$payload=(new PanelWorkspacePreferences($store,'operator'))->export();
	$t->same(1,count($workspace->import($payload,'merge')));
	$t->same('panel_workspace_preferences',$workspace->jsonSerialize()['type']);

	$conflicting=new PanelWorkspacePreferences(new DpPanelAlwaysConflictingPreferenceStore(),'operator');
	$exception=$t->throws(static fn()=>$conflicting->update(static function(array &$settings): void { $settings['theme']='glass'; },null,'retry'),PanelPreferenceConflictException::class);
	$t->same(0,$exception->expectedRevision());
})->tag('panel','preferences','workspace','coverage')->group('panel-lane-c');

test('preference profiles reject unsafe identifiers and discard non-json settings', static function(Context $t): void {
	$t->throws(static fn()=>new PanelWorkspacePreferenceProfile("bad\0actor"),InvalidArgumentException::class);
	$stream=fopen('php://temp','r+b');
	try{
		$profile=new PanelWorkspacePreferenceProfile('operator','default',['stream'=>$stream]);
		$t->same([],$profile->settings());
	}finally{
		fclose($stream);
	}
})->tag('panel','preferences','profile','coverage')->group('panel-lane-c');
