<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

/** @param array<string,mixed> $input @param array<string,mixed> $query */
function dp_panel_renderer_bulk_request(string $method='POST',array $input=[],array $query=[],string $operation='bulk'): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>$method,
		'resource'=>'bulk-records',
		'operation'=>$operation,
		'input'=>$input,
		'query'=>$query,
		'user'=>['id'=>7,'name'=>'Coverage Operator'],
	]);
}

/** @return list<array{id:string,name:string,status:string}> */
function dp_panel_renderer_bulk_records(): array {
	return [
		['id'=>'ok','name'=>'Successful','status'=>'draft'],
		['id'=>'failed','name'=>'Failed','status'=>'draft'],
		['id'=>'thrown','name'=>'Thrown','status'=>'draft'],
		['id'=>'denied','name'=>'Denied','status'=>'draft'],
	];
}

/** @param list<array{id:string,name:string,status:string}>|null $records */
function dp_panel_renderer_bulk_resource(?array $records=null): Resource {
	$records ??=dp_panel_renderer_bulk_records();
	return Resource::make('bulk-records')
		->label('Bulk Records')
		->pluralLabel('Bulk Records')
		->queryUsing(static fn(PanelRequest $request): array=>$records)
		->recordKeyUsing('id')
		->authorize(static fn(string $ability,mixed $record): bool=>(string)($record['id'] ?? '')!=='denied');
}

/** @return array{unavailable:Resource,available:Resource} */
function dp_panel_renderer_bulk_duplicate_resources(): array {
	$base=dp_panel_renderer_bulk_resource();
	return [
		'unavailable'=>$base,
		'available'=>$base->duplicateUsing(static function(array $record): array {
			return match($record['id']){
				'ok'=>['duplicated'=>true,'new_id'=>'copy-ok'],
				'failed'=>['success'=>false],
				'thrown'=>throw new RuntimeException('duplicate failed'),
				default=>['ok'=>true],
			};
		}),
	];
}

test('panel renderer bulk duplicate covers guards and per-record outcomes',static function(Context $t): void {
	$resources=dp_panel_renderer_bulk_duplicate_resources();
	$selection=['selected'=>['ok','failed','thrown','denied'],'return_to'=>'/panel/bulk-records?view=all'];

	$t->same(404,PanelRenderer::bulkDuplicateResult($resources['unavailable'],dp_panel_renderer_bulk_request('POST',$selection))->status());
	$t->same(405,PanelRenderer::bulkDuplicateResult($resources['available'],dp_panel_renderer_bulk_request('GET',$selection))->status());
	$t->same(422,PanelRenderer::bulkDuplicateResult($resources['available'],dp_panel_renderer_bulk_request('POST',['selected'=>[]]))->status());

	$result=PanelRenderer::bulkDuplicateResult($resources['available'],dp_panel_renderer_bulk_request('post',$selection));
	$t->same(303,$result->status());
	$t->same('bulk_duplicate',$result->data()['kind']);
	$t->same(['ok'],$result->data()['duplicated']);
	$t->same(['failed','thrown'],$result->data()['failed']);
	$t->same(['denied'],$result->data()['denied']);
	$t->same(['duplicated'=>true,'new_id'=>'copy-ok'],$result->data()['results']['ok']);
	$t->same(['success'=>false],$result->data()['results']['failed']);
	$t->same(false,array_key_exists('thrown',$result->data()['results']));
	$t->same(3,count($result->notifications()));
	$t->same('/panel/bulk-records?view=all',$result->headers()['Location']);
});

test('panel renderer bulk restore covers guards and per-record outcomes',static function(Context $t): void {
	$base=dp_panel_renderer_bulk_resource();
	$resource=$base->restoreUsing(static function(array $record): array {
		return match($record['id']){
			'ok'=>['restored'=>true],
			'failed'=>['ok'=>false],
			'thrown'=>throw new RuntimeException('restore failed'),
			default=>['success'=>true],
		};
	});
	$selection=['selected'=>['ok','failed','thrown','denied']];

	$t->same(404,PanelRenderer::bulkRestoreResult($base,dp_panel_renderer_bulk_request('POST',$selection))->status());
	$t->same(405,PanelRenderer::bulkRestoreResult($resource,dp_panel_renderer_bulk_request('PATCH',$selection))->status());
	$t->same(422,PanelRenderer::bulkRestoreResult($resource,dp_panel_renderer_bulk_request('POST',['selected'=>'']))->status());

	$result=PanelRenderer::bulkRestoreResult($resource,dp_panel_renderer_bulk_request('POST',$selection));
	$t->same(303,$result->status());
	$t->same('bulk_restore',$result->data()['kind']);
	$t->same(['ok'],$result->data()['restored']);
	$t->same(['failed','thrown'],$result->data()['failed']);
	$t->same(['denied'],$result->data()['denied']);
	$t->same(3,count($result->notifications()));
});

test('panel renderer bulk update covers capability method selection form validation and success',static function(Context $t): void {
	$base=dp_panel_renderer_bulk_resource();
	$resource=$base
		->bulkField(Field::make('status')->label('Status')->required())
		->bulkUpdateUsing(static fn(array $data,array $records): string=>'Updated '.count($records).' records to '.$data['status']);
	$selected=['selected'=>['ok','failed']];

	$t->same(404,PanelRenderer::bulkUpdateResult($base,dp_panel_renderer_bulk_request('POST',$selected))->status());
	$t->same(405,PanelRenderer::bulkUpdateResult($resource,dp_panel_renderer_bulk_request('GET',$selected))->status());
	$t->same(422,PanelRenderer::bulkUpdateResult($resource,dp_panel_renderer_bulk_request('POST',['selected'=>[]]))->status());

	$form=PanelRenderer::bulkUpdateResult($resource,dp_panel_renderer_bulk_request('POST',$selected));
	$t->same(200,$form->status());
	$t->same('bulk_update_form',$form->data()['kind']);
	$t->same(2,$form->data()['selected_count']);
	$t->contains('__panel_bulk_update_submit',$form->content());

	$invalid=PanelRenderer::bulkUpdateResult($resource,dp_panel_renderer_bulk_request('POST',$selected+[
		'__panel_bulk_update_submit'=>'1',
		'status'=>'',
	]));
	$t->same(422,$invalid->status());
	$t->same(false,$invalid->data()['form_state']['valid']);
	$t->same(true,array_key_exists('status',$invalid->data()['form_state']['errors']));

	$success=PanelRenderer::bulkUpdateResult($resource,dp_panel_renderer_bulk_request('POST',$selected+[
		'__panel_bulk_update_submit'=>'1',
		'status'=>'published',
	]));
	$t->same(303,$success->status());
	$t->same('bulk_update',$success->data()['kind']);
	$t->same(2,$success->data()['selected_count']);
	$t->same(['status'],$success->data()['input_keys']);
	$t->same('Updated 2 records to published',$success->data()['result']);
	$t->same(1,count($success->notifications()));
});

test('panel renderer bulk delete covers guards and per-record outcomes',static function(Context $t): void {
	$base=dp_panel_renderer_bulk_resource();
	$resource=$base->deleteUsing(static function(array $record): array {
		return match($record['id']){
			'ok'=>['deleted'=>true],
			'failed'=>['success'=>false],
			'thrown'=>throw new RuntimeException('delete failed'),
			default=>['ok'=>true],
		};
	});
	$selection=['selected'=>['ok','failed','thrown','denied']];

	$t->same(404,PanelRenderer::bulkDeleteResult($base,dp_panel_renderer_bulk_request('POST',$selection))->status());
	$t->same(405,PanelRenderer::bulkDeleteResult($resource,dp_panel_renderer_bulk_request('HEAD',$selection))->status());
	$t->same(422,PanelRenderer::bulkDeleteResult($resource,dp_panel_renderer_bulk_request('POST',['selected'=>[]]))->status());

	$result=PanelRenderer::bulkDeleteResult($resource,dp_panel_renderer_bulk_request('POST',$selection));
	$t->same(303,$result->status());
	$t->same('bulk_delete',$result->data()['kind']);
	$t->same(['ok'],$result->data()['deleted']);
	$t->same(['failed','thrown'],$result->data()['failed']);
	$t->same(['denied'],$result->data()['denied']);
	$t->same(3,count($result->notifications()));
});

test('panel renderer bulk force delete covers guards and per-record outcomes',static function(Context $t): void {
	$base=dp_panel_renderer_bulk_resource();
	$resource=$base->forceDeleteUsing(static function(array $record): array {
		return match($record['id']){
			'ok'=>['force_deleted'=>true,'purged'=>1],
			'failed'=>['deleted'=>false],
			'thrown'=>throw new RuntimeException('force delete failed'),
			default=>['success'=>true],
		};
	});
	$selection=['selected'=>['ok','failed','thrown','denied']];

	$t->same(404,PanelRenderer::bulkForceDeleteResult($base,dp_panel_renderer_bulk_request('POST',$selection))->status());
	$t->same(405,PanelRenderer::bulkForceDeleteResult($resource,dp_panel_renderer_bulk_request('OPTIONS',$selection))->status());
	$t->same(422,PanelRenderer::bulkForceDeleteResult($resource,dp_panel_renderer_bulk_request('POST',['selected'=>[]]))->status());

	$result=PanelRenderer::bulkForceDeleteResult($resource,dp_panel_renderer_bulk_request('POST',$selection));
	$t->same(303,$result->status());
	$t->same('bulk_force_delete',$result->data()['kind']);
	$t->same(['ok'],$result->data()['force_deleted']);
	$t->same(['failed','thrown'],$result->data()['failed']);
	$t->same(['denied'],$result->data()['denied']);
	$t->same(['force_deleted'=>true,'purged'=>1],$result->data()['results']['ok']);
	$t->same(['deleted'=>false],$result->data()['results']['failed']);
	$t->same(false,array_key_exists('thrown',$result->data()['results']));
	$t->same(3,count($result->notifications()));
});

test('panel renderer bulk forbidden includes nullable resource diagnostics',static function(Context $t): void {
	$request=dp_panel_renderer_bulk_request('GET');
	$withResource=PanelRenderer::forbidden(dp_panel_renderer_bulk_resource(),$request);
	$t->same(403,$withResource->status());
	$t->same('forbidden',$withResource->data()['kind']);
	$t->same('bulk-records',$withResource->data()['resource']['name']);

	$withoutResource=PanelRenderer::forbidden(null,$request);
	$t->same(403,$withoutResource->status());
	$t->same(null,$withoutResource->data()['resource']);
});
