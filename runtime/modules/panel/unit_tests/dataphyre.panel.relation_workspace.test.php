<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelArrayRelationAdapter;
use Dataphyre\Panel\PanelRelationWorkspace;
use Dataphyre\Panel\PanelRelationWorkspaceCommand;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_relation_workspace_fixture(): PanelRelationWorkspace {
	$adapter=new PanelArrayRelationAdapter([
		1=>['id'=>1, 'name'=>'Alpha'],
		2=>['id'=>2, 'name'=>'Beta'],
		3=>['id'=>3, 'name'=>'Gamma'],
	], ['order-1'=>[1=>['role'=>'primary']]]);
	return PanelRelationWorkspace::make('line_items', 'order-1', $adapter)
		->breadcrumbs([['label'=>'Orders', 'url'=>'/orders'], ['label'=>'Order 1', 'url'=>'/orders/1']]);
}

test('relation workspace supports bulk attach pivot editing ordering and deterministic manifests', static function(Context $t): void {
	$workspace=dp_relation_workspace_fixture();
	$attached=$workspace->execute(PanelRelationWorkspaceCommand::make('attach', [2, 3], [
		'2'=>['role'=>'secondary'], '3'=>['role'=>'observer'],
	], ['expected_version'=>0, 'idempotency_key'=>'attach:2:3', 'actor'=>'mina']));
	$t->same('committed', $attached->status());
	$t->same(2, $attached->version());
	$t->same(3, count($attached->records()));
	$pivot=$workspace->execute(PanelRelationWorkspaceCommand::make('update_pivot', 2, ['role'=>'owner'], ['expected_version'=>2, 'idempotency_key'=>'pivot:2']));
	$t->same('owner', $pivot->records()[1]['_pivot']['role']);
	$ordered=$workspace->execute(PanelRelationWorkspaceCommand::make('reorder', [3, 1, 2], [], ['expected_version'=>3, 'idempotency_key'=>'order:3:1:2']));
	$t->same(['3', '1', '2'], array_column($ordered->records(), 'id'));
	$manifest=$workspace->manifest();
	$t->same('relation_workspace', $manifest['type']);
	$t->isTrue($manifest['capabilities']['undo']);
	$t->same(2, count($manifest['breadcrumbs']));
})->tag('panel', 'relations', 'workspace', 'bulk')->maxMillis(1000);

test('relation workspace enforces optimistic concurrency authorization and idempotency', static function(Context $t): void {
	$workspace=dp_relation_workspace_fixture()->authorize(static fn(PanelRelationWorkspaceCommand $command): bool|array => $command->actor()==='admin' ? true : ['Admin required.']);
	$denied=$workspace->execute(PanelRelationWorkspaceCommand::make('attach', 2, [], ['actor'=>'viewer', 'idempotency_key'=>'denied']));
	$t->same('denied', $denied->status());
	$conflict=$workspace->execute(PanelRelationWorkspaceCommand::make('attach', 2, [], ['actor'=>'admin', 'expected_version'=>4, 'idempotency_key'=>'conflict']));
	$t->same('conflict', $conflict->status());
	$command=PanelRelationWorkspaceCommand::make('attach', 2, ['role'=>'member'], ['actor'=>'admin', 'expected_version'=>0, 'idempotency_key'=>'attach:2']);
	$t->same('committed', $workspace->execute($command)->status());
	$t->same('duplicate', $workspace->execute($command)->status());
	$t->same(2, count($workspace->records()));
})->tag('panel', 'relations', 'workspace', 'policies')->maxMillis(1000);

test('relation workspace rolls failed bulk operations back and produces usable undo receipts', static function(Context $t): void {
	$workspace=dp_relation_workspace_fixture();
	$failed=$workspace->execute(PanelRelationWorkspaceCommand::make('attach', [2, 99], [], ['idempotency_key'=>'bad-bulk']));
	$t->same('failed', $failed->status());
	$t->same(1, count($workspace->records()));
	$t->isTrue(($failed->jsonSerialize()['metadata']['rolled_back'] ?? false)===true);
	$attached=$workspace->execute(PanelRelationWorkspaceCommand::make('attach', 2, [], ['idempotency_key'=>'good-attach']));
	$t->same(2, count($workspace->records()));
	$undone=$workspace->undo($attached, 'undo-good-attach');
	$t->same('committed', $undone->status());
	$t->same(1, count($workspace->records()));
})->tag('panel', 'relations', 'workspace', 'rollback')->maxMillis(1000);
