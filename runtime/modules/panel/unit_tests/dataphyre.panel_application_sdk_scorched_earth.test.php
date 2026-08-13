<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelSdkCompatibilityReport;
use Dataphyre\Panel\PanelSdkContract;
use Dataphyre\Panel\PanelDeveloperToolkit;
use Dataphyre\Panel\PanelSdkGenerator;
use Dataphyre\Panel\PanelSdkOperation;
use Dataphyre\Panel\PanelSdkProtocolCatalog;
use Dataphyre\Panel\PanelSdkSchema;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_application_sdk_contract(string $version='1.0.0',?PanelSdkOperation $replacement=null):PanelSdkContract {
	$body=PanelSdkSchema::object(['name'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>80])],['name']);
	$response=PanelSdkSchema::object(['id'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>80]),'name'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>80])],['id','name']);
	$operation=$replacement??PanelSdkOperation::post('echo_value','/api/items/{id}',$response,['body'=>$body,'query'=>PanelSdkSchema::object(),'errors'=>['default'=>PanelSdkProtocolCatalog::errorEnvelope()],'scopes'=>['items.write'],'summary'=>'Echo one item','idempotent'=>true]);
	return PanelSdkContract::make('acme-panel',$version,['label'=>'Acme Panel','description'=>'Generated application contract','bindings'=>['source_epoch'=>str_repeat('a',64)],'metadata'=>['owner'=>'platform']])->withOperation($operation)->withEvent('item.changed',$response)->withArtifact('studio.item',$body);
}

test('SDK schema grammar is closed bounded executable and deterministic',static function(Context $t):void {
	$schema=PanelSdkSchema::object([
		'id'=>PanelSdkSchema::string(['pattern'=>'^[a-z0-9-]+$','minLength'=>2,'maxLength'=>20]),
		'priority'=>PanelSdkSchema::integer(['minimum'=>1,'maximum'=>5]),
		'tags'=>PanelSdkSchema::arrayOf(PanelSdkSchema::string(['maxLength'=>12]),['maxItems'=>3,'uniqueItems'=>true]),
		'mode'=>PanelSdkSchema::enum(['manual','automatic']),
		'note'=>PanelSdkSchema::nullable(PanelSdkSchema::string(['maxLength'=>40])),
	],['id','priority','mode']);
	$valid=['id'=>'order-1','priority'=>3,'tags'=>['new','paid'],'mode'=>'manual','note'=>null];$t->isTrue($schema->accepts($valid));$t->same([],$schema->validate($valid));
	$errors=$schema->validate(['id'=>'!','priority'=>9,'tags'=>['same','same'],'mode'=>'unknown','extra'=>true]);$codes=array_column($errors,'code');foreach(['pattern','min_length','maximum','unique_items','enum_mismatch','additional_property']as$code){$t->isTrue(in_array($code,$codes,true),$code);}
	$copy=PanelSdkSchema::fromArray($schema->definition());$t->same($schema->fingerprint(),$copy->fingerprint());$t->same($schema->definition(),$copy->jsonSerialize());
	$t->isTrue(PanelSdkSchema::any('Any JSON value')->isAny());$t->isTrue(PanelSdkSchema::number(['minimum'=>0.5,'maximum'=>2.5])->accepts(1.5));$t->throws(static fn()=>PanelSdkSchema::string(['minLength'=>2])->assertValid('x','short value'),UnexpectedValueException::class);
	$formats=PanelSdkSchema::object(['date'=>PanelSdkSchema::string(['format'=>'date']),'instant'=>PanelSdkSchema::string(['format'=>'date-time'])],['date','instant']);$t->isTrue($formats->accepts(['date'=>'2026-07-16','instant'=>'2026-07-16T12:00:00Z']));$t->isFalse($formats->accepts(['date'=>'2026-02-30','instant'=>'not-an-instant']));
	$t->throws(static fn()=>PanelSdkSchema::fromArray(['type'=>'object','properties'=>['x'=>['type'=>'string']],'required'=>['missing']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSdkSchema::fromArray(['type'=>'string','script'=>'alert(1)']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSdkSchema::union([PanelSdkSchema::string(),PanelSdkSchema::string()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSdkSchema::enum(['one',1]),InvalidArgumentException::class);
})->tag('panel','sdk','schema','validation','scorched-earth')->isolation('case')->maxMillis(5000);

test('SDK contracts bind host routes protocols events and Studio artifacts without credentials',static function(Context $t):void {
	$contract=PanelSdkProtocolCatalog::firstParty('shopiro-ops','3.2.1',[
		'data_surface'=>'/api/panel/data-surfaces/{surface}','command'=>'/api/panel/commands','events'=>'/api/panel/events','studio_artifact'=>'/api/panel/studio/{document}',
	],['bindings'=>['platform'=>str_repeat('b',64)],'metadata'=>['environment'=>'public']]);
	$t->same(4,count($contract->operations()));$t->same(['data_surface_window','dispatch_command','get_studio_artifact','list_events'],array_keys($contract->operations()));$t->notNull($contract->operation('data_surface_window'));$t->same(['surface'],$contract->operation('data_surface_window')?->pathParameters());$t->same(['panel.data_surface.read'],$contract->operation('data_surface_window')?->scopes());$t->same(['panel.event'],array_keys($contract->events()));$t->same(['panel.studio.artifact'],array_keys($contract->artifacts()));
	$request=PanelSdkProtocolCatalog::dataSurfaceRequest();$t->isTrue($request->accepts(['intent'=>str_repeat('i',64)]));$t->isTrue($request->accepts(['intent'=>str_repeat('i',64),'interaction'=>['type'=>'cross_filter','values'=>['paid',3]]]));$t->isFalse($request->accepts(['intent'=>'x','interaction'=>null]));$t->isFalse($request->accepts(['intent'=>'x','interaction'=>['type'=>'cross_filter','values'=>array_fill(0,101,'x')]]));
	$command=PanelSdkProtocolCatalog::commandRequest()->definition();$t->isFalse(isset($command['properties']['tenant_id'],$command['properties']['actor_id'],$command['properties']['ability'],$command['properties']['evidence']));
	$serialized=$contract->jsonSerialize();$json=json_encode($serialized,JSON_THROW_ON_ERROR);$t->isFalse(str_contains(strtolower($json),'password'));$t->isFalse($serialized['security']['credentials_embedded']);$t->isTrue($serialized['security']['auth_owned_by_host']);$t->same($contract->fingerprint(),PanelSdkContract::fromArray($serialized)->fingerprint());
})->tag('panel','sdk','protocols','data-surface','fabric','studio','security','scorched-earth')->isolation('case')->maxMillis(8000);

test('SDK compatibility analysis is directional and enforces semantic version bumps',static function(Context $t):void {
	$before=dp_panel_application_sdk_contract();$base=$before->operation('echo_value');$t->notNull($base);
	$additiveOperation=PanelSdkOperation::post('echo_value','/api/items/{id}',$base->responseSchema(),['body'=>$base->bodySchema(),'query'=>PanelSdkSchema::object(['search'=>PanelSdkSchema::string(['maxLength'=>80])]),'errors'=>$base->errors(),'scopes'=>$base->scopes(),'summary'=>$base->summary(),'idempotent'=>true]);
	$additive=dp_panel_application_sdk_contract('1.1.0',$additiveOperation);$report=PanelSdkCompatibilityReport::between($before,$additive);$t->isFalse($report->breaking());$t->isTrue($report->additive());$t->same('minor',$report->requiredBump());$t->isTrue($report->versionCompliant());$t->same(1,$report->summary()['additive']);
	$breakingBody=PanelSdkSchema::object(['name'=>PanelSdkSchema::string(),'reason'=>PanelSdkSchema::string()],['name','reason']);$breakingOperation=PanelSdkOperation::post('echo_value','/api/items/{id}',$base->responseSchema(),['body'=>$breakingBody,'query'=>$base->querySchema(),'errors'=>$base->errors(),'scopes'=>$base->scopes(),'summary'=>$base->summary(),'idempotent'=>true]);
	$breaking=dp_panel_application_sdk_contract('2.0.0',$breakingOperation);$major=PanelSdkCompatibilityReport::between($before,$breaking);$t->isTrue($major->breaking());$t->same('major',$major->requiredBump());$t->isTrue($major->versionCompliant());$t->isTrue(in_array('required_property_added',array_column($major->changes('breaking'),'code'),true));
	$badVersion=PanelSdkCompatibilityReport::between($before,dp_panel_application_sdk_contract('1.1.0',$breakingOperation));$t->isFalse($badVersion->versionCompliant());$t->isFalse($badVersion->jsonSerialize()['compatible']);
	$numericBefore=PanelSdkContract::make('numeric-contract','1.0.0')->withEvent('score',PanelSdkSchema::number(['minimum'=>0,'maximum'=>100]));$numericAfter=PanelSdkContract::make('numeric-contract','2.0.0')->withEvent('score',PanelSdkSchema::number(['minimum'=>10,'maximum'=>90]));$numeric=PanelSdkCompatibilityReport::between($numericBefore,$numericAfter);$t->isTrue(in_array('minimum_changed',array_column($numeric->changes(),'code'),true));
	$enumBefore=PanelSdkContract::make('enum-contract','1.0.0')->withEvent('state',PanelSdkSchema::enum(['new','paid']));$enumAfter=PanelSdkContract::make('enum-contract','2.0.0')->withEvent('state',PanelSdkSchema::enum(['new','paid','closed']))->withEvent('audit',PanelSdkSchema::boolean());$enum=PanelSdkCompatibilityReport::between($enumBefore,$enumAfter);$t->isTrue(in_array('response_enum_widened',array_column($enum->changes(),'code'),true));$t->isTrue(in_array('member_added',array_column($enum->changes(),'code'),true));
	$t->nonPublic($enum)->invoke('compareSchema',['type'=>'boolean','extension'=>'old'],['type'=>'boolean','extension'=>'new'],'events.probe','response');$t->isTrue(in_array('schema_changed',array_column($enum->changes(),'code'),true));
})->tag('panel','sdk','compatibility','semver','scorched-earth')->isolation('case')->maxMillis(5000);

test('SDK compiler emits deterministic integrity-bound PHP and TypeScript packages',static function(Context $t):void {
	$contract=dp_panel_application_sdk_contract();$generator=new PanelSdkGenerator();$first=$generator->generate($contract,['php_namespace'=>'Generated\\AcmePanel','php_class'=>'OperationsClient','composer_package'=>'acme/panel-sdk','typescript_package'=>'@acme/panel-sdk']);$second=$generator->generate($contract,['php_namespace'=>'Generated\\AcmePanel','php_class'=>'OperationsClient','composer_package'=>'acme/panel-sdk','typescript_package'=>'@acme/panel-sdk']);
	$t->same($first->fingerprint(),$second->fingerprint());$t->same($first->files(),$second->files());$t->isTrue($first->verify());$t->same(['php','typescript'],$first->targets());$t->notNull($first->file('contract.json'));$t->notNull($first->file('sdk-manifest.json'));$t->contains('final class OperationsClient',(string)$first->file('php/src/OperationsClient.php'));$t->contains('public async echoValue',(string)$first->file('typescript/src/index.ts'));$t->contains('EventItemChanged',(string)$first->file('typescript/src/index.ts'));$t->contains('ArtifactStudioItem',(string)$first->file('typescript/src/index.ts'));$t->notContains('X-CSRF-Token',(string)$first->file('sdk-manifest.json'));
	$manifest=$first->jsonSerialize();$t->isTrue($manifest['verified']);$t->isFalse($manifest['contents_exposed']);$t->same(count($first->files()),count($manifest['files']));foreach($manifest['files']as$path=>$file){$t->same(hash('sha256',(string)$first->file($path)),$file['sha256'],$path);}
	$decoded=json_decode((string)$first->file('contract.json'),true,512,JSON_THROW_ON_ERROR);$t->same($contract->fingerprint(),PanelSdkContract::fromArray($decoded)->fingerprint());$t->isFalse($generator->jsonSerialize()['effects']['filesystem']);$t->isFalse($generator->jsonSerialize()['effects']['network']);
	$t->same('Panel',$t->nonPublic(PanelSdkGenerator::class)->invoke('studly','---'));$t->same(str_repeat('c',64),$contract->withBindings(['release'=>str_repeat('c',64)])->bindings()['release']);
	$t->instanceOf(PanelSdkGenerator::class,PanelDeveloperToolkit::sdkGenerator());$t->same($contract->fingerprint(),PanelDeveloperToolkit::sdkCompatibility($contract,$contract)->jsonSerialize()['before']['fingerprint']);$t->same(1,count(PanelDeveloperToolkit::sdkContract('toolkit-sdk','1.0.0',['events'=>'/api/events'])->operations()));
})->tag('panel','sdk','codegen','php','typescript','determinism','scorched-earth')->isolation('case')->maxMillis(10000);

test('generated PHP client validates requests responses paths headers and public errors',static function(Context $t):void {
	$package=(new PanelSdkGenerator())->generate(dp_panel_application_sdk_contract(),['targets'=>['php'],'php_namespace'=>'Generated\\RuntimeSdk','php_class'=>'OperationsClient','composer_package'=>'acme/runtime-sdk']);$root=$t->tempDirectory('panel-generated-php-sdk');
	foreach($package->files()as$path=>$contents){if(!str_starts_with($path,'php/src/'))continue;$target=$root.'/'.basename($path);file_put_contents($target,$contents);}
	require $root.'/PanelTransport.php';require $root.'/PanelTransportResponse.php';require $root.'/PanelSdkException.php';require $root.'/OperationsClient.php';
	$transport=new class implements \Generated\RuntimeSdk\PanelTransport {public array $requests=[];public bool $fail=false;public function send(string $method,string $url,array $headers,?string $jsonBody=null):\Generated\RuntimeSdk\PanelTransportResponse{$this->requests[]=compact('method','url','headers','jsonBody');if($this->fail){return new \Generated\RuntimeSdk\PanelTransportResponse(422,['ok'=>false,'status'=>'invalid','error'=>['code'=>'item_invalid','message'=>'Item is invalid.','correlation_id'=>'corr-7'],'errors'=>['Item is invalid.'],'correlation_id'=>'corr-7']);}return new \Generated\RuntimeSdk\PanelTransportResponse(200,['id'=>'42','name'=>'Ada','future_field'=>true],['X-Correlation-ID'=>'corr-ok']);}};
	$client=new \Generated\RuntimeSdk\OperationsClient($transport,'https://panel.example',['X-CSRF-Token'=>'safe']);$result=$client->echoValue('42',['name'=>'Ada']);$t->same('Ada',$result['name']);$t->same('POST',$transport->requests[0]['method']);$t->same('https://panel.example/api/items/42',$transport->requests[0]['url']);$t->same('safe',$transport->requests[0]['headers']['X-CSRF-Token']);$t->same(['name'=>'Ada'],json_decode($transport->requests[0]['jsonBody'],true,512,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>$client->echoValue('42',[]),\Generated\RuntimeSdk\PanelSdkException::class);
	$transport->fail=true;try{$client->echoValue('42',['name'=>'Ada']);$t->isTrue(false,'expected public error');}catch(\Generated\RuntimeSdk\PanelSdkException $error){$t->same('item_invalid',$error->publicCode);$t->same(422,$error->httpStatus);$t->same('corr-7',$error->correlationId);$t->same('Item is invalid.',$error->getMessage());}
	$t->throws(static fn()=>new \Generated\RuntimeSdk\OperationsClient($transport,'https://user:pass@panel.example'),InvalidArgumentException::class);
})->tag('panel','sdk','generated-client','php','runtime','security','scorched-earth')->isolation('case')->maxMillis(10000);

test('SDK boundaries reject route ambiguity credential metadata and tampered contracts',static function(Context $t):void {
	$response=PanelSdkSchema::object(['ok'=>PanelSdkSchema::boolean()],['ok']);
	$t->same('PUT',PanelSdkOperation::make('replace','PUT','/api/replace',$response)->method());$t->same('PATCH',PanelSdkOperation::patch('modify','/api/modify',$response)->method());
	$t->throws(static fn()=>PanelSdkOperation::get('external','https://evil.example/x',$response),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSdkOperation::get('body','/api/body',$response,['body'=>PanelSdkSchema::object()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSdkOperation::get('missing_path','/api/{id}',$response,['path'=>PanelSdkSchema::object()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSdkContract::make('unsafe','1.0.0',['metadata'=>['api_token'=>'secret']]),InvalidArgumentException::class);
	$first=PanelSdkOperation::get('one','/api/shared',$response);$second=PanelSdkOperation::get('two','/api/shared',$response);$contract=PanelSdkContract::make('duplicates','1.0.0')->withOperation($first);$t->throws(static fn()=>$contract->withOperation($second),LogicException::class);
	$t->throws(static fn()=>PanelSdkProtocolCatalog::firstParty('bad-routes','1.0.0',['unknown'=>'/api/x']),InvalidArgumentException::class);
	$payload=dp_panel_application_sdk_contract()->jsonSerialize();$payload['fingerprint']=str_repeat('0',64);$t->throws(static fn()=>PanelSdkContract::fromArray($payload),UnexpectedValueException::class);
	$t->throws(static fn()=>(new PanelSdkGenerator())->generate(dp_panel_application_sdk_contract(),['targets'=>['ruby']]),InvalidArgumentException::class);
})->tag('panel','sdk','security','adversarial','scorched-earth')->isolation('case')->maxMillis(5000);
