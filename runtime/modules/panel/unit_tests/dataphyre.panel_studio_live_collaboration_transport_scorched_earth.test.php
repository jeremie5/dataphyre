<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Panel\PanelInMemoryStudioStore;
use Dataphyre\Panel\PanelPlatformManifest;
use Dataphyre\Panel\PanelStudioArrayIdentityConnector;
use Dataphyre\Panel\PanelStudioCollaborationConnector;
use Dataphyre\Panel\PanelStudioCollaborationEndpoint;
use Dataphyre\Panel\PanelStudioCollaborationEndpointResult;
use Dataphyre\Panel\PanelStudioCollaborationIntent;
use Dataphyre\Panel\PanelStudioCollaborationIntentSigner;
use Dataphyre\Panel\PanelStudioCollaborationIntentVerification;
use Dataphyre\Panel\PanelStudioCollaborationTransport;
use Dataphyre\Panel\PanelStudioCollaborationTransportException;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioEditor;
use Dataphyre\Panel\PanelStudioEditorOptions;
use Dataphyre\Panel\PanelStudioEditorSession;
use Dataphyre\Panel\PanelStudioIdentityProfile;
use Dataphyre\Panel\PanelStudioManager;
use Dataphyre\Panel\PanelStudioPolicy;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_studio_transport_manager():PanelStudioManager {
	return new PanelStudioManager(
		new PanelInMemoryStudioStore(),
		PanelStudioPolicy::permit(static fn():bool=>true,'live_collaboration_test'),
		clock:static fn():string=>'2026-07-20T16:00:00+00:00',
	);
}

function dp_panel_studio_transport_definition():PanelStudioDefinition {
	return PanelStudioDefinition::from([
		'kind'=>'page',
		'key'=>'workspace',
		'properties'=>['label'=>'Live collaboration workspace'],
		'children'=>[],
	]);
}

/** @return array<string,mixed> */
function dp_panel_studio_transport_fixture(int &$now,int $eventRetention=512):array {
	$collaborationManager=new PanelCollaborationManager(
		new PanelInMemoryCollaborationStore($eventRetention),
		static fn(string $operation,?string $principal):bool=>$principal==='editor',
	);
	$connector=new PanelStudioCollaborationConnector(
		$collaborationManager,
		new PanelStudioArrayIdentityConnector([
			new PanelStudioIdentityProfile('editor','Editorial Operator'),
			new PanelStudioIdentityProfile('mina','Mina Reviewer'),
		],'tenant-live'),
		['threads'=>20,'comments_per_thread'=>20,'directory'=>20,'watchers'=>20,'presence'=>20,'typing_per_thread'=>20],
	);
	$session=PanelStudioEditor::open(
		dp_panel_studio_transport_manager(),
		PanelStudioDocument::make('tenant-live','orders-live','Live orders studio'),
		'editor',
		dp_panel_studio_transport_definition(),
	);
	$nonce=0;
	$signer=new PanelStudioCollaborationIntentSigner(
		[
			'previous'=>str_repeat('P',48),
			'current'=>str_repeat('K',48),
		],
		'current',
		static function()use(&$now):int{return$now;},
		static function()use(&$nonce):string{$nonce++;return str_pad(dechex($nonce),32,'0',STR_PAD_LEFT);},
		0,
	);
	$intent=$signer->issue($session);
	$transport=new PanelStudioCollaborationTransport('/panel/studio/collaboration',$intent,[
		'visible_poll_milliseconds'=>750,
		'hidden_poll_milliseconds'=>4000,
		'maximum_backoff_milliseconds'=>12000,
		'request_timeout_milliseconds'=>5000,
		'presence_heartbeat_milliseconds'=>10000,
		'typing_idle_milliseconds'=>900,
	]);
	$options=PanelStudioEditorOptions::make([
		'action_url'=>'/panel/studio',
		'csrf_token'=>str_repeat('C',32),
		'editor_id'=>'live-studio',
		'collaboration_connector'=>$connector,
		'collaboration_transport'=>$transport,
	]);
	$endpoint=(new PanelStudioCollaborationEndpoint($signer))->authorizeHost(
		static fn(string $action,PanelStudioEditorSession $requestSession,array $context):bool=>
			$requestSession->principalId()==='editor'&&in_array($action,['delta','mutate','presence_sync','presence_release','typing'],true),
	);
	return compact('collaborationManager','connector','session','signer','intent','transport','options','endpoint');
}

/** @param array<string,mixed> $fixture @param array<string,mixed> $replace @return array<string,mixed> */
function dp_panel_studio_transport_input(array $fixture,string $action,array $replace=[]):array {
	/** @var PanelStudioEditorSession $session */
	$session=$fixture['session'];
	/** @var PanelStudioCollaborationIntent $intent */
	$intent=$fixture['intent'];
	return array_replace([
		'studio_collaboration_transport_action'=>$action,
		'studio_collaboration_intent'=>$intent->token(),
		'_token'=>str_repeat('C',32),
		'studio_definition_json'=>json_encode($session->definition()->root(),JSON_THROW_ON_ERROR),
		'studio_base_revision'=>(string)$session->baseRevision(),
		'studio_base_hash'=>$session->baseHash(),
		'studio_selected_path'=>$session->selectedPath(),
	],$replace);
}

function dp_panel_studio_transport_code(PanelStudioCollaborationEndpointResult $result):string {
	$code=$result->body()['code']??'';
	return is_string($code)?$code:'';
}

function dp_panel_studio_transport_base64url_encode(string $value):string {
	return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
}

function dp_panel_studio_transport_base64url_decode(string $value):string {
	$padding=(4-strlen($value)%4)%4;
	$decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$padding),true);
	if(!is_string($decoded)){throw new RuntimeException('Invalid test collaboration token segment.');}
	return $decoded;
}

/** @param array<string,mixed>|string $header @param array<string,mixed>|string $payload */
function dp_panel_studio_transport_signed_token(array|string $header,array|string $payload,string $secret):string {
	$header=is_array($header)?json_encode($header,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):$header;
	$payload=is_array($payload)?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):$payload;
	$input=dp_panel_studio_transport_base64url_encode($header).'.'.dp_panel_studio_transport_base64url_encode($payload);
	return $input.'.'.dp_panel_studio_transport_base64url_encode(hash_hmac('sha256',$input,$secret,true));
}

test('Studio collaboration intents are rotating scope-bound short-lived and secret-safe',static function(Context $t):void {
	$now=1800000000;$fixture=dp_panel_studio_transport_fixture($now);$session=$fixture['session'];$signer=$fixture['signer'];$intent=$fixture['intent'];
	$t->same(['delta','mutate','presence','typing'],$intent->abilities());
	$t->same('current',$intent->keyId());$t->same($now,$intent->issuedAt());$t->same($now+300,$intent->expiresAt());
	$t->contains($intent->token(),json_encode($intent->browserModel(),JSON_THROW_ON_ERROR));
	$t->notContains($intent->token(),json_encode($intent,JSON_THROW_ON_ERROR));
	$verified=$signer->verify($intent->token(),$session,'mutate');
	$t->instanceOf(PanelStudioCollaborationIntentVerification::class,$verified);
	$t->isTrue($verified->allows('delta'));$t->isTrue($verified->allows('mutate'));$t->isFalse($verified->allows('other'));
	$t->same('current',$verified->keyId());$t->same(str_pad('1',32,'0',STR_PAD_LEFT),$verified->nonce());
	$t->notContains($verified->nonce(),json_encode($verified,JSON_THROW_ON_ERROR));

	$oldNonce=0;
	$oldSigner=new PanelStudioCollaborationIntentSigner(
		['previous'=>str_repeat('P',48)],
		'previous',
		static function()use(&$now):int{return$now;},
		static function()use(&$oldNonce):string{$oldNonce++;return str_pad(dechex($oldNonce),32,'0',STR_PAD_LEFT);},
		0,
	);
	$oldIntent=$oldSigner->issue($session,['delta'],30);
	$t->same('previous',$signer->verify($oldIntent->token(),$session,'delta')->keyId());
	$t->throws(static fn()=>$signer->verify($oldIntent->token(),$session,'mutate'),PanelStudioCollaborationTransportException::class);

	$parts=explode('.',$intent->token());$parts[2][0]=$parts[2][0]==='A'?'B':'A';$tampered=implode('.',$parts);
	$t->throws(static fn()=>$signer->verify($tampered,$session,'delta'),PanelStudioCollaborationTransportException::class);
	$other=PanelStudioEditor::open(
		dp_panel_studio_transport_manager(),
		PanelStudioDocument::make('tenant-live','different-document','Different document'),
		'editor',
		dp_panel_studio_transport_definition(),
	);
	$t->throws(static fn()=>$signer->verify($intent->token(),$other,'delta'),PanelStudioCollaborationTransportException::class);
	$now=$intent->expiresAt()+1;
	try{$signer->verify($intent->token(),$session,'delta');$t->fail('Expired collaboration intent accepted.');}
	catch(PanelStudioCollaborationTransportException $error){$t->same('intent_expired',$error->publicCode());$t->same(401,$error->httpStatus());$t->isFalse($error->retryable());}

	$serialized=json_encode($signer,JSON_THROW_ON_ERROR);
	$t->notContains(str_repeat('K',48),$serialized);$t->notContains(str_repeat('P',48),$serialized);
	$t->isTrue($signer->jsonSerialize()['key_rotation']);$t->isFalse($signer->jsonSerialize()['private_keys_serialized']);
	$t->throws(static fn()=>new PanelStudioCollaborationIntent('short',['delta'],1,31,'key'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntentVerification(['invalid'],1,31,'key',str_repeat('a',32)),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntentVerification(['delta'],1,902,'key',str_repeat('a',32)),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntentSigner([], 'missing'),InvalidArgumentException::class);
})->tag('panel','studio','collaboration','transport','signing','rotation','security')->maxMillis(4000);

test('Studio live transport is progressive same-origin and only explicitly exposes its bearer to the browser model',static function(Context $t):void {
	$now=1800000000;$fixture=dp_panel_studio_transport_fixture($now);$transport=$fixture['transport'];$intent=$fixture['intent'];$session=$fixture['session'];$options=$fixture['options'];
	$t->same('/panel/studio/collaboration',$transport->endpointUrl());
	$t->same(750,$transport->settings()['visible_poll_milliseconds']);
	$t->same(900,$transport->settings()['typing_idle_milliseconds']);
	$t->contains($intent->token(),json_encode($transport->browserModel(),JSON_THROW_ON_ERROR));
	$manifest=json_encode($transport,JSON_THROW_ON_ERROR);
	$t->notContains($intent->token(),$manifest);$t->isFalse($transport->jsonSerialize()['raw_intent_serialized']);
	$t->isFalse($transport->jsonSerialize()['mutation_retries']);$t->isTrue($transport->jsonSerialize()['offline_aware']);

	$html=PanelStudioEditor::render($session,$options);
	$t->contains('data-dp-studio-collaboration-live="true"',$html);
	$t->contains('panel_studio_collaboration_transport',$html);$t->contains($intent->token(),$html);
	$t->contains('data-dp-studio-collaboration-live-status',$html);
	$optionsJson=json_encode($options,JSON_THROW_ON_ERROR);
	$t->notContains($intent->token(),$optionsJson);$t->notContains(str_repeat('C',32),$optionsJson);
	$t->same(3,$options->jsonSerialize()['version']);
	$t->isTrue($options->jsonSerialize()['collaboration']['live_transport_active']);
	$editorManifest=PanelStudioEditor::manifest($session,$options);
	$t->same(6,$editorManifest['version']);$t->isTrue($editorManifest['integration']['collaboration_live_transport_active']);
	$t->isTrue($editorManifest['integration']['collaboration_presence_token_host_owned']);

	$t->throws(static fn()=>new PanelStudioCollaborationTransport('https://example.test/live',$intent),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationTransport('//example.test/live',$intent),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationTransport('/live',$intent,['visible_poll_milliseconds'=>100]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationTransport('/live',$intent,['unsupported'=>1000]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioEditorOptions::make(['csrf_token'=>str_repeat('C',32),'collaboration_transport'=>$transport]),InvalidArgumentException::class);

	$platform=PanelPlatformManifest::inspect()->jsonSerialize();
	$t->isTrue($platform['domains']['studio']['features']['collaboration_transport']);
	$t->isTrue($platform['domains']['studio']['features']['collaboration_endpoint']);
	$t->isTrue($platform['domains']['studio']['features']['collaboration_transport_error']);
})->tag('panel','studio','collaboration','transport','renderer','manifest','security')->maxMillis(5000);

test('Studio collaboration endpoint fails closed and keeps presence lease custody exclusively with the host',static function(Context $t):void {
	$now=1800000000;$fixture=dp_panel_studio_transport_fixture($now);$session=$fixture['session'];$options=$fixture['options'];$signer=$fixture['signer'];$endpoint=$fixture['endpoint'];
	$delta=dp_panel_studio_transport_input($fixture,'delta',['studio_collaboration_cursor'=>'0']);
	$closed=(new PanelStudioCollaborationEndpoint($signer))->handle($session,$options,$delta);
	$t->same(403,$closed->status());$t->same('host_authorization_required',dp_panel_studio_transport_code($closed));
	$denied=$endpoint->authorizeHost(static fn(string $action,PanelStudioEditorSession $requestSession,array $context):bool=>false)->handle($session,$options,$delta);
	$t->same(403,$denied->status());$t->same('host_authorization_denied',dp_panel_studio_transport_code($denied));
	$unavailable=$endpoint->authorizeHost(static function(string $action,PanelStudioEditorSession $requestSession,array $context):bool{throw new RuntimeException('private host failure');})->handle($session,$options,$delta);
	$t->same(503,$unavailable->status());$t->same('host_authorization_unavailable',dp_panel_studio_transport_code($unavailable));
	$t->notContains('private host failure',$unavailable->content());

	$presenceInput=dp_panel_studio_transport_input($fixture,'presence_sync');
	$missingCsrf=$endpoint->handle($session,$options,array_diff_key($presenceInput,['_token'=>true]));
	$t->same(419,$missingCsrf->status());$t->same('csrf_invalid',dp_panel_studio_transport_code($missingCsrf));
	$presence=$endpoint->handle($session,$options,$presenceInput,null,'presence-sync-1');
	$t->same(200,$presence->status());$t->same('replace',$presence->presenceDisposition());
	$leaseToken=$presence->trustedPresenceToken();$t->notNull($leaseToken);$t->matches('/^[a-f0-9]{48}$/D',(string)$leaseToken);
	$t->notContains((string)$leaseToken,$presence->content());$t->notContains((string)$leaseToken,json_encode($presence,JSON_THROW_ON_ERROR));
	$t->same('no-store, private',$presence->headers()['Cache-Control']);$t->same('presence-sync-1',$presence->body()['correlation_id']);
	$t->contains('Editorial Operator',(string)$presence->body()['fragment']);

	$heartbeat=$endpoint->handle($session,$options,$presenceInput,$leaseToken);
	$t->same(200,$heartbeat->status());$t->same('replace',$heartbeat->presenceDisposition());$t->same($leaseToken,$heartbeat->trustedPresenceToken());
	$release=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'presence_release'),$leaseToken);
	$t->same(200,$release->status());$t->same('clear',$release->presenceDisposition());$t->isNull($release->trustedPresenceToken());
	$t->same(0,$release->body()['workspace']['counts']['presence']);

	$plainOptions=PanelStudioEditorOptions::make(['csrf_token'=>str_repeat('C',32)]);
	$unconfigured=$endpoint->handle($session,$plainOptions,$delta);
	$t->same(503,$unconfigured->status());$t->same('collaboration_unavailable',dp_panel_studio_transport_code($unconfigured));
	$manifestJson=json_encode($endpoint,JSON_THROW_ON_ERROR);
	$t->notContains(str_repeat('K',48),$manifestJson);$t->isTrue($endpoint->jsonSerialize()['capabilities']['csrf_on_state_changes']);
})->tag('panel','studio','collaboration','transport','endpoint','presence','csrf','security')->maxMillis(5000);

test('Studio collaboration endpoint preserves unsaved editor state and exposes bounded deltas without replaying mutations',static function(Context $t):void {
	$now=1800000000;$fixture=dp_panel_studio_transport_fixture($now,8);$session=$fixture['session'];$endpoint=$fixture['endpoint'];$options=$fixture['options'];$manager=$fixture['collaborationManager'];
	$definition=$session->definition()->root();$definition['properties']['label']='Unsaved live workspace';
	$mutation=dp_panel_studio_transport_input($fixture,'mutate',[
		'studio_definition_json'=>json_encode($definition,JSON_THROW_ON_ERROR),
		'studio_collaboration_operation'=>'create_thread',
		'studio_collaboration_title'=>'Review the live transport',
		'actor'=>'attacker',
		'subject_id'=>'attacker-document',
	]);
	$badCsrf=$endpoint->handle($session,$options,array_replace($mutation,['_token'=>'wrong']));
	$t->same(419,$badCsrf->status());$t->same(0,count($manager->threads()));
	$mutated=$endpoint->handle($session,$options,$mutation);
	$t->same(200,$mutated->status());$t->isTrue($mutated->body()['changed']);
	$t->same('Unsaved live workspace',$session->definition()->root()['properties']['label']);$t->isTrue($session->dirty());
	$t->same(1,count($manager->threads()));$threadId=(string)$manager->threads()[0]['id'];
	$t->contains('Review the live transport',(string)$mutated->body()['fragment']);
	$t->notContains('attacker-document',(string)$mutated->body()['fragment']);
	$t->same('unchanged',$mutated->presenceDisposition());
	$t->same('panel_studio_collaboration_browser_intent',$mutated->body()['intent']['type']);

	$typing=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'typing',[
		'studio_collaboration_thread_id'=>$threadId,
		'studio_collaboration_typing'=>'true',
	]));
	$t->same(200,$typing->status());$t->isTrue($typing->body()['changed']);
	$t->same(['editor'],$manager->typingUsers($threadId));
	$stopped=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'typing',[
		'studio_collaboration_thread_id'=>$threadId,
		'studio_collaboration_typing'=>'false',
	]));
	$t->same(200,$stopped->status());$t->same([],$manager->typingUsers($threadId));

	$delta=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'delta',[
		'studio_collaboration_cursor'=>'0',
	]));
	$t->same(200,$delta->status());$t->isTrue($delta->body()['changed']);$t->isFalse($delta->body()['reset_required']);
	$t->isTrue(count($delta->body()['changes'])>=3);
	foreach($delta->body()['changes']as$change){$t->same(['cursor','type','occurred_at'],array_keys($change));}
	$changesJson=json_encode($delta->body()['changes'],JSON_THROW_ON_ERROR);
	$t->notContains('actor',$changesJson);$t->notContains('subject_id',$changesJson);$t->notContains('lease',$changesJson);
	$future=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'delta',[
		'studio_collaboration_cursor'=>(string)($manager->cursor()+50),
	]));
	$t->same(200,$future->status());$t->isTrue($future->body()['reset_required']);$t->same([],$future->body()['changes']);

	for($index=0;$index<10;$index++){$manager->createThread('editor','record','retained-'.$index,'Retained '.$index);}
	$stale=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'delta',['studio_collaboration_cursor'=>'1']));
	$t->same(200,$stale->status());$t->isTrue($stale->body()['reset_required']);$t->same([],$stale->body()['changes']);

	$invalidClient=$endpoint->handle($session,$options,array_replace($mutation,['studio_definition_json'=>'{']));
	$t->same(422,$invalidClient->status());$t->same('client_state_invalid',dp_panel_studio_transport_code($invalidClient));
	$missingThread=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'typing',[
		'studio_collaboration_thread_id'=>'missing-thread',
		'studio_collaboration_typing'=>true,
	]));
	$t->same(422,$missingThread->status());$t->same('request_invalid',dp_panel_studio_transport_code($missingThread));
})->tag('panel','studio','collaboration','transport','mutation','delta','typing','local-first')->maxMillis(6000);

test('Studio collaboration endpoint enforces byte bounds stable errors and value invariants',static function(Context $t):void {
	$now=1800000000;$fixture=dp_panel_studio_transport_fixture($now);$session=$fixture['session'];$options=$fixture['options'];$signer=$fixture['signer'];$endpoint=$fixture['endpoint'];
	$tooLarge=(new PanelStudioCollaborationEndpoint($signer,['maximum_request_bytes'=>4096]))->authorizeHost(
		static fn(string $action,PanelStudioEditorSession $requestSession,array $context):bool=>true,
	)->handle($session,$options,str_repeat('x',4097));
	$t->same(413,$tooLarge->status());$t->same('request_too_large',dp_panel_studio_transport_code($tooLarge));
	$invalidJson=$endpoint->handle($session,$options,'{');
	$t->same(422,$invalidJson->status());$t->same('request_invalid',dp_panel_studio_transport_code($invalidJson));
	$invalidUtf8=$endpoint->handle($session,$options,['invalid'=>"\xB1"]);
	$t->same(422,$invalidUtf8->status());$t->same('request_invalid',dp_panel_studio_transport_code($invalidUtf8));
	$invalidAction=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'delta',[
		'studio_collaboration_transport_action'=>'unsupported',
	]));
	$t->same(422,$invalidAction->status());$t->same('request_invalid',dp_panel_studio_transport_code($invalidAction));
	$invalidIntent=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'delta',[
		'studio_collaboration_intent'=>'invalid',
	]));
	$t->same(401,$invalidIntent->status());$t->same('intent_invalid',dp_panel_studio_transport_code($invalidIntent));
	$badCorrelation=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'delta'),'','<bad>');
	$t->same(null,$badCorrelation->body()['correlation_id']);

	$thread=$fixture['collaborationManager']->createThread('editor','studio_document',hash('sha256',"tenant-live\0orders-live"),'Large response');
	$fixture['collaborationManager']->comment((string)$thread['id'],'editor',str_repeat('x',9000));
	$bounded=(new PanelStudioCollaborationEndpoint($signer,['maximum_response_bytes'=>4096]))->authorizeHost(
		static fn(string $action,PanelStudioEditorSession $requestSession,array $context):bool=>true,
	)->handle($session,$options,dp_panel_studio_transport_input($fixture,'delta',['studio_collaboration_cursor'=>'0']));
	$t->same(507,$bounded->status());$t->same('response_too_large',dp_panel_studio_transport_code($bounded));

	$t->throws(static fn()=>new PanelStudioCollaborationEndpoint($signer,['delta_limit'=>0]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationEndpoint($signer,['unsupported'=>1]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationEndpointResult(199,[],[]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationEndpointResult(200,['Bad Header' => 'value'],[]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationEndpointResult(200,[],[],'invalid'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationEndpointResult(200,[],[],'replace'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationEndpointResult(200,[],[],'unchanged','secret'),InvalidArgumentException::class);
	$error=new PanelStudioCollaborationTransportException('temporary_failure',503,'Temporary failure.',true);
	$t->same('temporary_failure',$error->publicCode());$t->same(503,$error->httpStatus());$t->isTrue($error->retryable());
	$t->throws(static fn()=>new PanelStudioCollaborationTransportException('Bad Code',500,'bad'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationTransportException('bad_status',399,'bad'),InvalidArgumentException::class);
})->tag('panel','studio','collaboration','transport','bounds','errors','values','security')->maxMillis(6000);

test('Studio collaboration transport closes parser signer policy and fail-closed residual branches',static function(Context $t):void {
	$now=1800000000;
	$fixture=dp_panel_studio_transport_fixture($now);
	$session=$fixture['session'];$options=$fixture['options'];$signer=$fixture['signer'];$intent=$fixture['intent'];$transport=$fixture['transport'];$endpoint=$fixture['endpoint'];
	$secret=str_repeat('K',48);
	$parts=explode('.',$intent->token());
	$header=json_decode(dp_panel_studio_transport_base64url_decode($parts[0]),true,32,JSON_THROW_ON_ERROR);
	$payload=json_decode(dp_panel_studio_transport_base64url_decode($parts[1]),true,32,JSON_THROW_ON_ERROR);
	$t->isTrue(is_array($header));$t->isTrue(is_array($payload));

	$t->same($intent,$transport->intent());
	$t->same($signer,$endpoint->signer());
	$verified=$signer->verify($intent->token(),$session,'delta');
	$t->same($now,$verified->issuedAt());$t->same($now+300,$verified->expiresAt());

	$t->throws(static fn()=>new PanelStudioCollaborationIntent($intent->token(),['delta'],1,1,'current'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntent($intent->token(),['delta'],1,31,'bad key'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntent($intent->token(),[],1,31,'current'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntent($intent->token(),['unsupported'],1,31,'current'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntentVerification([],1,31,'current',str_repeat('a',32)),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntentVerification(['delta'],1,31,'bad key',str_repeat('a',32)),InvalidArgumentException::class);

	$t->throws(static fn()=>new PanelStudioCollaborationIntentSigner(['current'=>$secret],'current',leeway:61),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntentSigner(['bad key'=>$secret],'bad key'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntentSigner(['current'=>'short'],'current'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationIntentSigner(['retained'=>$secret],'missing'),InvalidArgumentException::class);
	$t->throws(static fn()=>$signer->issue($session,['delta'],29),InvalidArgumentException::class);
	$t->throws(static fn()=>$signer->issue($session,[]),PanelStudioCollaborationTransportException::class);
	$badNonceSigner=new PanelStudioCollaborationIntentSigner(
		['current'=>$secret],
		'current',
		static fn():int=>1800000000,
		static fn():string=>'not-a-valid-nonce',
		0,
	);
	$t->throws(static fn()=>$badNonceSigner->issue($session),UnexpectedValueException::class);
	$t->throws(static fn()=>$signer->verify($intent->token(),$session,'unsupported'),PanelStudioCollaborationTransportException::class);

	$badHeader=$header;$badHeader['alg']='none';
	$t->throws(static fn()=>$signer->verify(dp_panel_studio_transport_signed_token($badHeader,$payload,$secret),$session,'delta'),PanelStudioCollaborationTransportException::class);
	$t->throws(static fn()=>$signer->verify(dp_panel_studio_transport_signed_token('{',$payload,$secret),$session,'delta'),PanelStudioCollaborationTransportException::class);
	$t->throws(static fn()=>$signer->verify(dp_panel_studio_transport_signed_token($header,'{',$secret),$session,'delta'),PanelStudioCollaborationTransportException::class);
	$invalidLifetime=$payload;$invalidLifetime['exp']=$invalidLifetime['iat'];
	$t->throws(static fn()=>$signer->verify(dp_panel_studio_transport_signed_token($header,$invalidLifetime,$secret),$session,'delta'),PanelStudioCollaborationTransportException::class);
	$invalidTagType=$payload;$invalidTagType['tenant_tag']=false;
	$t->throws(static fn()=>$signer->verify(dp_panel_studio_transport_signed_token($header,$invalidTagType,$secret),$session,'delta'),PanelStudioCollaborationTransportException::class);
	$mismatchedTag=$payload;$mismatchedTag['document_tag']='wrong-document-tag';
	$t->throws(static fn()=>$signer->verify(dp_panel_studio_transport_signed_token($header,$mismatchedTag,$secret),$session,'delta'),PanelStudioCollaborationTransportException::class);
	$invalidNonce=$payload;$invalidNonce['nonce']='invalid';
	$t->throws(static fn()=>$signer->verify(dp_panel_studio_transport_signed_token($header,$invalidNonce,$secret),$session,'delta'),PanelStudioCollaborationTransportException::class);
	$mutableNow=$now;
	$invalidClockSigner=new PanelStudioCollaborationIntentSigner(
		['current'=>$secret],
		'current',
		static function()use(&$mutableNow):int{return$mutableNow;},
		static fn():string=>str_repeat('a',32),
		0,
	);
	$clockIntent=$invalidClockSigner->issue($session,['delta']);
	$mutableNow=0;
	$t->throws(static fn()=>$invalidClockSigner->verify($clockIntent->token(),$session,'delta'),PanelStudioCollaborationTransportException::class);

	$listRequest=$endpoint->handle($session,$options,[]);
	$t->same(422,$listRequest->status());$t->same('request_invalid',dp_panel_studio_transport_code($listRequest));
	$invalidCursor=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'delta',['studio_collaboration_cursor'=>'-1']));
	$t->same(422,$invalidCursor->status());$t->same('request_invalid',dp_panel_studio_transport_code($invalidCursor));
	$invalidTyping=$endpoint->handle($session,$options,dp_panel_studio_transport_input($fixture,'typing',[
		'studio_collaboration_thread_id'=>'thread',
		'studio_collaboration_typing'=>'sometimes',
	]));
	$t->same(422,$invalidTyping->status());$t->same('request_invalid',dp_panel_studio_transport_code($invalidTyping));

	$stalePresence=$endpoint->handle(
		$session,
		$options,
		dp_panel_studio_transport_input($fixture,'presence_sync'),
		str_repeat('f',48),
	);
	$t->same(200,$stalePresence->status());$t->same('replace',$stalePresence->presenceDisposition());
	$t->notSame(str_repeat('f',48),$stalePresence->trustedPresenceToken());

	$deniedManager=new PanelCollaborationManager(
		new PanelInMemoryCollaborationStore(),
		static fn(string $operation,?string $actor,array $context,PanelCollaborationManager $manager):bool=>false,
	);
	$deniedConnector=new PanelStudioCollaborationConnector(
		$deniedManager,
		new PanelStudioArrayIdentityConnector([new PanelStudioIdentityProfile('editor','Editorial Operator')],'tenant-live'),
	);
	$deniedOptions=PanelStudioEditorOptions::make([
		'action_url'=>'/panel/studio',
		'csrf_token'=>str_repeat('C',32),
		'collaboration_connector'=>$deniedConnector,
		'collaboration_transport'=>$transport,
	]);
	$deniedMutation=$endpoint->handle($session,$deniedOptions,dp_panel_studio_transport_input($fixture,'mutate',[
		'studio_collaboration_operation'=>'create_thread',
		'studio_collaboration_title'=>'Denied by collaboration policy',
	]));
	$t->same(403,$deniedMutation->status());$t->same('host_authorization_denied',dp_panel_studio_transport_code($deniedMutation));

	$mismatchedConnector=new PanelStudioCollaborationConnector(
		new PanelCollaborationManager(new PanelInMemoryCollaborationStore()),
		new PanelStudioArrayIdentityConnector([new PanelStudioIdentityProfile('editor','Editorial Operator')],'other-tenant'),
	);
	$mismatchedOptions=PanelStudioEditorOptions::make([
		'action_url'=>'/panel/studio',
		'csrf_token'=>str_repeat('C',32),
		'collaboration_connector'=>$mismatchedConnector,
		'collaboration_transport'=>$transport,
	]);
	$internalFailure=$endpoint->handle($session,$mismatchedOptions,dp_panel_studio_transport_input($fixture,'delta',['studio_collaboration_cursor'=>0]));
	$t->same(500,$internalFailure->status());$t->same('collaboration_failed',dp_panel_studio_transport_code($internalFailure));$t->isTrue($internalFailure->body()['retryable']);
})->tag('panel','studio','collaboration','transport','exact-coverage','policy','security')->maxMillis(8000);
