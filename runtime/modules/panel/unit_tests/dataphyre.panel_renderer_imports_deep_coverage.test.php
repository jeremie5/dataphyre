<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/panel_test_probes.php';

use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TestFixtures\RendererStreamScenario;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

if(!function_exists('Dataphyre\\Panel\\fopen')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Panel;
function fopen(string $filename,string $mode,bool $useIncludePath=false,mixed $context=null): mixed {
	if(\Dataphyre\Panel\TestFixtures\RendererStreamScenario::openShouldFail()){
		return false;
	}
	return $context===null
		? \fopen($filename,$mode,$useIncludePath)
		: \fopen($filename,$mode,$useIncludePath,$context);
}
PHP);
}

/** @param array<string,mixed> $input @param array<string,mixed> $query @param array<string,mixed> $files */
function dp_panel_renderer_imports_request(string $method='GET',array $input=[],array $query=[],array $files=[]): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>$method,
		'resource'=>'imports',
		'operation'=>'index',
		'input'=>$input,
		'query'=>$query,
		'files'=>$files,
		'user'=>['id'=>7],
	]);
}

/** @param callable|null $importHandler @param callable|null $authorizer */
function dp_panel_renderer_imports_resource(?callable $importHandler=null,?callable $authorizer=null,array $records=[]): Resource {
	$records=$records!==[] ? $records : [
		['id'=>'1','name'=>'Alice','email'=>'alice@example.com','status'=>'draft'],
		['id'=>'2','name'=>'Bob','email'=>'bob@example.com','status'=>'draft'],
	];
	$resource=Resource::make('imports')
		->label('Import Records')
		->pluralLabel('Import Records')
		->fields([
			Field::make('name')->label('Name')->required(),
			Field::make('email','email')->label('Email'),
			Field::make('ignored')->label('Ignored')->readonly(),
		])
		->columns([
			Column::make('id')->label('Identifier'),
			Column::make('name')->label('Name'),
			Column::make('email')->label('Email'),
		])
		->queryUsing(static fn(PanelRequest $request): array=>$records)
		->recordKeyUsing('id');
	if($importHandler!==null){
		$resource=$resource->importUsing($importHandler);
	}
	if($authorizer!==null){
		$resource=$resource->authorize($authorizer);
	}
	return $resource;
}

function dp_panel_renderer_imports_transition_resource(?callable $handler=null,?callable $authorizer=null,array $records=[]): Resource {
	$records=$records!==[] ? $records : [
		['id'=>'1','name'=>'One','status'=>'draft'],
		['id'=>'2','name'=>'Two','status'=>'draft'],
	];
	$resource=Resource::make('imports')
		->label('Import Records')
		->columns([Column::make('id'),Column::make('name'),Column::make('status')])
		->queryUsing(static fn(PanelRequest $request): array=>$records)
		->recordKeyUsing('id')
		->statusTransition([
			'name'=>'publish',
			'label'=>'Publish',
			'from'=>['draft'],
			'to'=>'published',
			'tone'=>'success',
		]);
	$resource=$resource->transitionUsing($handler ?? static fn(array $transition,mixed $record): array=>[
		'transitioned'=>true,
		'record'=>$record,
	]);
	if($authorizer!==null){
		$resource=$resource->authorize($authorizer);
	}
	return $resource;
}

test('panel renderer imports exports csv and json across feature and stream outcomes',static function(Context $t): void {
	$stream=RendererStreamScenario::reset($t);
	$resource=dp_panel_renderer_imports_resource(static fn(array $rows): array=>['imported'=>$rows]);
	$request=dp_panel_renderer_imports_request('GET');

	$disabled=PanelContext::run(['resource_exports'=>false],static fn()=>PanelRenderer::exportCsv($resource,$request));
	$t->same(403,$disabled->status());

	$json=PanelRenderer::exportCsv($resource,dp_panel_renderer_imports_request('GET',[],['format'=>'json']),[
		['id'=>1,'name'=>'Alice','email'=>'alice@example.com'],
	]);
	$t->same('application/json; charset=utf-8',$json->headers()['Content-Type']);
	$t->contains('"Alice"',$json->content());

	$csv=PanelRenderer::exportCsv($resource,$request,[
		['id'=>2,'name'=>'Bob','email'=>'bob@example.com'],
	]);
	$t->same('text/csv; charset=utf-8',$csv->headers()['Content-Type']);
	$t->contains('Identifier,Name,Email',$csv->content());
	$t->contains('2,Bob,bob@example.com',$csv->content());

	$stream->failOpens();
	$failed=PanelRenderer::exportCsv($resource,$request,[],0,true);
	$stream->failOpens(false);
	$t->same(500,$failed->status());
	$t->same('export',$failed->data()['kind']);
});

test('panel renderer imports bulk export enforces request authorization selection and formats',static function(Context $t): void {
	$resource=dp_panel_renderer_imports_resource(static fn(array $rows): array=>['imported'=>$rows]);
	$post=dp_panel_renderer_imports_request('POST',['selected'=>['1','2']]);

	$disabled=PanelContext::run(['resource_exports'=>false],static fn()=>PanelRenderer::bulkExportCsv($resource,$post));
	$t->same(403,$disabled->status());
	$t->same(405,PanelRenderer::bulkExportCsv($resource,dp_panel_renderer_imports_request('GET'))->status());

	$denied=dp_panel_renderer_imports_resource(static fn(array $rows): array=>['imported'=>$rows],static fn(): bool=>false);
	$t->same(403,PanelRenderer::bulkExportCsv($denied,$post)->status());
	$t->same(422,PanelRenderer::bulkExportCsv($resource,dp_panel_renderer_imports_request('POST',['selected'=>[]]))->status());

	$json=PanelRenderer::bulkExportCsv($resource,dp_panel_renderer_imports_request('POST',['selected'=>'1'],['format'=>'json']));
	$t->same('bulk_export',$json->data()['kind']);
	$t->same(1,$json->data()['selected_count']);
	$t->contains('"Alice"',$json->content());

	$csv=PanelRenderer::bulkExportCsv($resource,$post);
	$t->same('bulk_export',$csv->data()['kind']);
	$t->same(2,$csv->data()['selected_count']);
	$t->contains('Bob',$csv->content());
});

test('panel renderer imports form and template cover unavailable messages columns and stream failure',static function(Context $t): void {
	$stream=RendererStreamScenario::reset($t);
	$request=dp_panel_renderer_imports_request('GET');
	$resource=dp_panel_renderer_imports_resource(static fn(array $rows): array=>['imported'=>$rows]);
	$withoutFields=Resource::make('pass-through')->importUsing(static fn(array $rows): array=>$rows);

	$disabled=PanelContext::run(['resource_imports'=>false],static fn()=>PanelRenderer::importForm($resource,$request));
	$t->same(404,$disabled->status());
	$t->same(404,PanelRenderer::importForm(Resource::make('none'),$request)->status());

	$passThrough=PanelRenderer::importForm($withoutFields,$request,'',201);
	$t->same(201,$passThrough->status());
	$t->contains('pass-through',$passThrough->content());
	$withMessage=PanelRenderer::importForm($resource,$request,'Choose a clean CSV.',422);
	$t->same(422,$withMessage->status());
	$t->contains('Choose a clean CSV.',$withMessage->content());
	$t->contains('Name (name)',$withMessage->content());

	$templateDisabled=PanelContext::run(['resource_imports'=>false],static fn()=>PanelRenderer::importTemplateCsv($resource,$request));
	$t->same(404,$templateDisabled->status());
	$denied=dp_panel_renderer_imports_resource(static fn(array $rows): array=>$rows,static fn(): bool=>false);
	$t->same(403,PanelRenderer::importTemplateCsv($denied,$request)->status());

	$template=PanelRenderer::importTemplateCsv($resource,$request);
	$t->same('import_template',$template->data()['kind']);
	$t->contains('Name,Email',$template->content());
	$t->contains('person@example.com',$template->content());

	$stream->failOpens();
	$failed=PanelRenderer::importTemplateCsv($resource,$request);
	$stream->failOpens(false);
	$t->same(500,$failed->status());
	$t->same('import_template',$failed->data()['kind']);
});

test('panel renderer imports validates previews and executes all result summary notices',static function(Context $t): void {
	$request=dp_panel_renderer_imports_request('POST');
	$t->same(404,PanelRenderer::importResult(Resource::make('none'),$request)->status());

	$denied=dp_panel_renderer_imports_resource(static fn(array $rows): array=>$rows,static fn(): bool=>false);
	$t->same(403,PanelRenderer::importResult($denied,dp_panel_renderer_imports_request('POST',['csv_data'=>"name\nAlice"]))->status());

	$resource=dp_panel_renderer_imports_resource(static fn(array $rows): array=>['imported'=>$rows]);
	$t->same(422,PanelRenderer::importResult($resource,$request)->status());
	$t->same(422,PanelRenderer::importResult($resource,dp_panel_renderer_imports_request('POST',['csv_data'=>"\n\n"]))->status());
	$t->same(422,PanelRenderer::importResult($resource,dp_panel_renderer_imports_request('POST',['csv_data'=>"name,email"]))->status());

	$preview=PanelRenderer::importResult($resource,dp_panel_renderer_imports_request('POST',[
		'csv_data'=>"name,email,unknown\nAlice,alice@example.com,x",
		'import_map'=>[2=>'__skip'],
		'has_header'=>'1',
	]));
	$t->same('import_preview',$preview->data()['kind']);
	$t->same(1,$preview->data()['row_count']);
	$t->contains('Skipped columns',$preview->content());

	$invalid=PanelRenderer::importResult($resource,dp_panel_renderer_imports_request('POST',[
		'csv_data'=>"name,email\n,not-an-email",
		'has_header'=>'1',
		'__panel_import_confirm'=>'1',
	]));
	$t->same(422,$invalid->status());
	$t->same(1,$invalid->data()['invalid_count']);

	$partial=dp_panel_renderer_imports_resource(static fn(array $rows): array=>[
		'imported'=>[0],
		'failed'=>[1],
		'message'=>'Partial import',
	]);
	$partialResult=PanelRenderer::importResult($partial,dp_panel_renderer_imports_request('POST',[
		'csv_data'=>"name,email\nAlice,alice@example.com\nBob,bob@example.com",
		'has_header'=>'1',
		'__panel_import_confirm'=>'1',
		'return_to'=>'/imports?view=all',
	]));
	$t->same(303,$partialResult->status());
	$t->same(1,$partialResult->data()['imported_count']);
	$t->same(1,$partialResult->data()['failed_count']);
	$t->same('/imports?view=all',$partialResult->headers()['Location']);

	$none=dp_panel_renderer_imports_resource(static fn(array $rows): array=>[
		'imported'=>0,
		'failed'=>0,
		'redirect'=>'/custom-import-result',
	]);
	$noneResult=PanelRenderer::importResult($none,dp_panel_renderer_imports_request('POST',[
		'csv_data'=>"name\nAlice",
		'__panel_import_confirm'=>'1',
	]));
	$t->same(0,$noneResult->data()['imported_count']);
	$t->same(0,$noneResult->data()['failed_count']);
	$t->same('/custom-import-result',$noneResult->headers()['Location']);
});

test('panel renderer imports preview renders explicit fallback and clean states',static function(Context $t): void {
	$resource=dp_panel_renderer_imports_resource(static fn(array $rows): array=>$rows);
	$request=dp_panel_renderer_imports_request('POST',['delimiter'=>';','has_header'=>'0']);
	$parsed=[
		'rows'=>[['name'=>'Alice','email'=>'alice@example.com']],
		'headers'=>['Name','Ignored'],
		'mapped_headers'=>['name',null],
		'skipped_columns'=>['Ignored'],
	];
	$invalid=$t->nonPublic(PanelRenderer::class)->invoke('importPreview',$resource,$request,"Name;Ignored\nAlice;x",$parsed,
		['invalid_count'=>1,'row_errors'=>[0=>['email'=>['Invalid email']]]],null,409,);
	$t->same(409,$invalid->status());
	$t->contains('1 row need attention',$invalid->content());
	$t->contains('Skipped columns: Ignored',$invalid->content());
	$t->contains('disabled',$invalid->content());

	$clean=$t->nonPublic(PanelRenderer::class)->invoke('importPreview',$resource,$request,'name,Alice',['rows'=>[['name'=>'Alice']]],
		['invalid_count'=>0,'row_errors'=>[]],'Ready to import.',202,);
	$t->same(202,$clean->status());
	$t->contains('Ready to import.',$clean->content());
});

test('panel renderer imports single transition covers method record definition availability auth and success',static function(Context $t): void {
	$get=dp_panel_renderer_imports_request('GET',[],['transition'=>'publish']);
	$post=dp_panel_renderer_imports_request('POST',['transition'=>'publish','return_to'=>'/imports']);
	$t->same(404,PanelRenderer::transitionResult(Resource::make('none'),$post,['id'=>1])->status());

	$resource=dp_panel_renderer_imports_transition_resource();
	$t->same(405,PanelRenderer::transitionResult($resource,$get,['id'=>1,'status'=>'draft'])->status());
	$t->same(404,PanelRenderer::transitionResult($resource,$post,null)->status());
	$t->same(404,PanelRenderer::transitionResult($resource,dp_panel_renderer_imports_request('POST',['transition'=>'missing']),['id'=>1,'status'=>'draft'])->status());
	$t->same(409,PanelRenderer::transitionResult($resource,$post,['id'=>1,'status'=>'archived'])->status());

	$denied=dp_panel_renderer_imports_transition_resource(null,static fn(): bool=>false);
	$t->same(403,PanelRenderer::transitionResult($denied,$post,['id'=>1,'status'=>'draft'])->status());

	$success=PanelRenderer::transitionResult($resource,$post,['id'=>1,'status'=>'draft']);
	$t->same(303,$success->status());
	$t->same('transition',$success->data()['kind']);
	$t->same('publish',$success->data()['transition']['name']);
	$t->same('/imports',$success->headers()['Location']);
});

test('panel renderer imports bulk transition classifies unavailable denied failed thrown and changed records',static function(Context $t): void {
	$post=dp_panel_renderer_imports_request('POST',['transition'=>'publish','selected'=>['1','2','3','4','5'],'return_to'=>'/imports']);
	$t->same(404,PanelRenderer::bulkTransitionResult(Resource::make('none'),$post)->status());

	$records=[
		['id'=>'1','status'=>'draft'],
		['id'=>'2','status'=>'draft'],
		['id'=>'3','status'=>'draft'],
		['id'=>'4','status'=>'draft'],
		['id'=>'5','status'=>'archived'],
	];
	$handler=static function(array $transition,array $record): array|false {
		return match($record['id']){
			'1'=>['transitioned'=>true],
			'3'=>false,
			'4'=>throw new RuntimeException('transition failed'),
			default=>['transitioned'=>true],
		};
	};
	$authorizer=static fn(string $ability,mixed $record): bool=>(string)($record['id'] ?? '')!=='2';
	$resource=dp_panel_renderer_imports_transition_resource($handler,$authorizer,$records);

	$t->same(405,PanelRenderer::bulkTransitionResult($resource,dp_panel_renderer_imports_request('GET',[],['transition'=>'publish']))->status());
	$t->same(404,PanelRenderer::bulkTransitionResult($resource,dp_panel_renderer_imports_request('POST',['transition'=>'missing']))->status());
	$t->same(422,PanelRenderer::bulkTransitionResult($resource,dp_panel_renderer_imports_request('POST',['transition'=>'publish','selected'=>[]]))->status());

	$result=PanelRenderer::bulkTransitionResult($resource,$post);
	$t->same(303,$result->status());
	$t->same(['1'],$result->data()['transitioned']);
	$t->same(['5'],$result->data()['unavailable']);
	$t->same(['3','4'],$result->data()['failed']);
	$t->same(['2'],$result->data()['denied']);
	$t->same(4,count($result->notifications()));
});
