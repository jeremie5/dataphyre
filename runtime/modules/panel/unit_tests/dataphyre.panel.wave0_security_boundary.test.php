<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelArrayRelationAdapter;
use Dataphyre\Panel\PanelErrorEnvelope;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelRelationWorkspace;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSensitiveDataSanitizer;
use Dataphyre\Panel\PanelTrace;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

test('sensitive data sanitizer is recursive key aware string aware and bounded',static function(Context $t):void{
	$serializable=new class implements JsonSerializable{public function jsonSerialize():mixed{return['clientSecret'=>'serialized-secret','safe'=>'visible'];}};
	$broken=new class implements JsonSerializable{public function jsonSerialize():mixed{throw new RuntimeException('password=serialization-secret');}};
	$resource=fopen('php://temp','r+');
	$payload=[
		'password'=>'password-secret',
		'nested'=>['apiToken'=>'api-secret','safe'=>'visible','code'=>'diagnostic-code'],
		'request'=>['input'=>['code'=>'123456'],'query'=>['csrf_token'=>'csrf-secret']],
		'message'=>'Authorization: Bearer bearer-secret password=message-secret passphrase="two word secret"',
		'jwt'=>'eyJabcdefghijk.abcdefghijkl.abcdefghijkl',
		'private'=>'-----BEGIN PRIVATE'.' KEY-----\nprivate-secret',
		'serializable'=>$serializable,
		'broken'=>$broken,
		'resource'=>$resource,
		'not_finite'=>INF,
		'object'=>new stdClass(),
	];
	$clean=PanelSensitiveDataSanitizer::sanitize($payload,['max_string_bytes'=>80]);
	fclose($resource);
	$json=json_encode($clean,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	foreach(['password-secret','api-secret','123456','csrf-secret','bearer-secret','message-secret','two word secret','private-secret','serialized-secret']as$secret){$t->notContains($secret,$json);}
	$t->contains('visible',$json);
	$t->contains('diagnostic-code',$json);
	$t->contains(PanelSensitiveDataSanitizer::REDACTED,$json);
	$t->same(['type'=>'object','class'=>$broken::class,'serialization'=>'failed'],$clean['broken']);
	$t->same('stream',$clean['resource']['resource_type']);
	$t->same('INF',$clean['not_finite']);
	$t->same(stdClass::class,$clean['object']['class']);
	$t->isTrue(PanelSensitiveDataSanitizer::isSensitiveKey('clientSecret'));
	$t->isFalse(PanelSensitiveDataSanitizer::isSensitiveKey('code',['diagnostic']));
	$t->isTrue(PanelSensitiveDataSanitizer::isSensitiveKey('code',['request','input']));
	$bounded=PanelSensitiveDataSanitizer::sanitize(['items'=>range(1,8),'long'=>str_repeat('x',40),'deep'=>['a'=>['b'=>['c'=>'end']]]],['max_items'=>3,'max_string_bytes'=>16,'max_depth'=>2]);
	$t->same(5,$bounded['items']['__truncated_items__']);
	$t->same(19,strlen($bounded['long']));
	$t->same('depth',$bounded['deep']['a']['b']['truncated']);
})->tag('panel','security','redaction','wave-0')->maxMillis(1000);

test('panel trace strips secrets from requests exceptions spans and diagnostics',static function(Context $t):void{
	PanelTrace::flush();
	$request=PanelRequest::fromArray([
		'method'=>'POST','resource'=>'orders','operation'=>'action',
		'query'=>['api_key'=>'query-secret','safe'=>'kept'],
		'input'=>['password'=>'input-secret','code'=>'654321','nested'=>['authorization'=>'Bearer nested-secret']],
	]);
	$span=PanelTrace::begin('secure request',['request'=>$request,'message'=>'Bearer span-secret']);
	PanelTrace::fail($span,new RuntimeException('password=exception-secret'),['diagnostic'=>['code'=>'provider_error','safe'=>'visible']]);
	$events=PanelTrace::events();
	$json=json_encode($events,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	foreach(['query-secret','input-secret','654321','nested-secret','span-secret','exception-secret']as$secret){$t->notContains($secret,$json);}
	$t->contains('kept',$json);
	$t->contains('provider_error',$json);
	$t->contains(PanelSensitiveDataSanitizer::REDACTED,$json);
	$t->same(0,count(PanelTrace::summary()['active_spans']));
	PanelTrace::flush();
})->tag('panel','security','trace','redaction','wave-0')->maxMillis(1000);

test('public error envelopes are stable correlated and production safe',static function(Context $t):void{
	$exception=new RuntimeException('password=exception-secret Bearer bearer-secret');
	$production=PanelErrorEnvelope::response('Operation Conflict',409,'The operation could not be completed.',$exception,false,['api_token'=>'context-secret'],'request-42',['X-Correlation-ID'=>'unsafe-override','Allow'=>'POST']);
	$payload=json_decode($production->content(),true,512,JSON_THROW_ON_ERROR);
	$t->same(409,$production->status());
	$t->same('request-42',$production->headers()['X-Correlation-ID']);
	$t->same('no-store',$production->headers()['Cache-Control']);
	$t->same('POST',$production->headers()['Allow']);
	$t->same('operation_conflict',$payload['status']);
	$t->same('request-42',$payload['error']['correlation_id']);
	$t->isFalse(isset($payload['error']['detail']));
	$t->notContains('exception-secret',$production->content());
	$t->notContains('context-secret',$production->content());

	$development=PanelErrorEnvelope::response('',700,'',$exception,true,['api_token'=>'context-secret','safe'=>'visible'],' unsafe id ');
	$developmentPayload=json_decode($development->content(),true,512,JSON_THROW_ON_ERROR);
	$t->same(500,$development->status());
	$t->same('internal_error',$developmentPayload['status']);
	$t->isTrue(preg_match('/^[a-f0-9]{32}$/',$developmentPayload['correlation_id'])===1);
	$t->same(RuntimeException::class,$developmentPayload['error']['detail']['exception']['class']);
	$t->contains(PanelSensitiveDataSanitizer::REDACTED,$developmentPayload['error']['detail']['exception']['message']);
	$t->notContains('exception-secret',$development->content());
	$t->notContains('context-secret',$development->content());
	$t->contains('visible',$development->content());

	$withoutException=PanelErrorEnvelope::response('invalid',422,'Invalid request.',null,true);
	$t->same(null,json_decode($withoutException->content(),true,512,JSON_THROW_ON_ERROR)['error']['detail']['exception']);
})->tag('panel','security','http','error-envelope','wave-0')->maxMillis(1000);

test('platform controller uses safe error envelopes and only exposes sanitized development detail',static function(Context $t):void{
	$workspace=PanelRelationWorkspace::make('items','order-1',new PanelArrayRelationAdapter());
	$request=['method'=>'POST','headers'=>['X-Correlation-ID'=>'platform-request-7'],'input'=>['operation'=>'unsupported','related_id'=>'record-1','password'=>'request-secret'],'user'=>['id'=>'operator']];
	$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn():bool=>true);
	$method=$controller->relate($workspace,['method'=>'GET','headers'=>['X-Correlation-ID'=>'method-request-1']]);
	$methodPayload=json_decode($method->content(),true,512,JSON_THROW_ON_ERROR);
	$t->same(405,$method->status());
	$t->same('method_not_allowed',$methodPayload['error']['code']);
	$t->same('POST',$method->headers()['Allow']);
	$csrf=(new PanelPlatformController())->relate($workspace,['method'=>'POST','input'=>['operation'=>'attach','related_id'=>'record-1']]);
	$t->same(419,$csrf->status());
	$t->same('csrf_failed',json_decode($csrf->content(),true,512,JSON_THROW_ON_ERROR)['error']['code']);
	$production=$controller->relate($workspace,$request);
	$payload=json_decode($production->content(),true,512,JSON_THROW_ON_ERROR);
	$t->same(422,$production->status());
	$t->same('platform-request-7',$production->headers()['X-Correlation-ID']);
	$t->same('invalid_relation_request',$payload['error']['code']);
	$t->isFalse(isset($payload['error']['detail']));
	$t->notContains('request-secret',$production->content());
	$development=$controller->developmentErrors()->relate($workspace,$request);
	$developmentPayload=json_decode($development->content(),true,512,JSON_THROW_ON_ERROR);
	$t->isTrue(isset($developmentPayload['error']['detail']));
	$t->notContains('request-secret',$development->content());
})->tag('panel','security','platform-controller','error-envelope','wave-0')->maxMillis(1000);

test('csv exports neutralize formulas but preserve numbers dates and structured json',static function(Context $t):void{
	$resource=Resource::make('security-export')->columns([
		Column::make('danger')->label('=DANGEROUS HEADER'),
		Column::make('spaced'),Column::make('minus_formula'),Column::make('negative'),Column::make('positive'),Column::make('currency'),Column::make('date'),Column::make('json'),
	]);
	$request=PanelRequest::fromArray(['method'=>'GET','resource'=>'security-export']);
	$result=PanelRenderer::exportCsv($resource,$request,[[
		'danger'=>'=HYPERLINK("https://example.invalid")',
		'spaced'=>" \t@SUM(1,2)",
		'minus_formula'=>'-WEBSERVICE("https://example.invalid")',
		'negative'=>'-42.50','positive'=>'+1200','currency'=>'-$1,234.50','date'=>'-2026-07-13','json'=>['formula'=>'=1+1'],
	]],1,true);
	$lines=preg_split('/\r?\n/',trim($result->content()));
	$headers=str_getcsv((string)$lines[0],',','"','');
	$row=str_getcsv((string)$lines[1],',','"','');
	$t->same("'=DANGEROUS HEADER",$headers[0]);
	$t->same("'=HYPERLINK(\"https://example.invalid\")",$row[0]);
	$t->same("' \t@SUM(1,2)",$row[1]);
	$t->same("'-WEBSERVICE(\"https://example.invalid\")",$row[2]);
	$t->same('-42.50',$row[3]);
	$t->same('+1200',$row[4]);
	$t->same('-$1,234.50',$row[5]);
	$t->same('-2026-07-13',$row[6]);
	$t->same('{"formula":"=1+1"}',$row[7]);

	$json=PanelRenderer::exportCsv($resource,PanelRequest::fromArray(['method'=>'GET','resource'=>'security-export','query'=>['format'=>'json']]),[['danger'=>'=1+1']],1,true);
	$t->contains('"danger": "=1+1"',$json->content());
	$t->notContains("'=1+1",$json->content());

	$templateResource=Resource::make('security-import')->importUsing(static fn(array$rows):array=>$rows)->fields([
		Field::make('formula')->label('@DANGEROUS HEADER')->default('=WEBSERVICE("https://example.invalid")'),
		Field::make('amount','number')->default('-42.50'),
	]);
	$template=PanelRenderer::importTemplateCsv($templateResource,$request);
	$templateLines=preg_split('/\r?\n/',trim($template->content()));
	$templateHeaders=str_getcsv((string)$templateLines[0],',','"','');
	$templateRow=str_getcsv((string)$templateLines[1],',','"','');
	$t->same("'@DANGEROUS HEADER",$templateHeaders[0]);
	$t->same("'=WEBSERVICE(\"https://example.invalid\")",$templateRow[0]);
	$t->same('-42.50',$templateRow[1]);

	$t->same("'\u{200B}=CMD()",$t->nonPublic(PanelRenderer::class)->invoke('spreadsheetSafeCsvValue',"\u{200B}=CMD()"));
	$t->same("'=CMD()",$t->nonPublic(PanelRenderer::class)->invoke('spreadsheetSafeCsvValue','=CMD()'));
	$t->same('', $t->nonPublic(PanelRenderer::class)->invoke('spreadsheetSafeCsvValue',''));
})->tag('panel','security','csv','formula-injection','wave-0')->maxMillis(2000);
