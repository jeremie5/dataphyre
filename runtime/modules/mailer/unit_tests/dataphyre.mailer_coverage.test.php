<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mailer\Contracts\BatchMailProvider;
use Dataphyre\Mailer\Mailer;
use Dataphyre\Mailer\MailerManager;
use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\Providers\LogProvider;
use Dataphyre\Mailer\SendResult;
use Dataphyre\Mailer\Support\AwsSignatureV4;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(...$arguments): void {}
}
if(!class_exists('dataphyre\\core', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; class core { public static function dialback(...$arguments): mixed { return null; } public static function load_framework_module(string $module): void {} public static function load_framework_modules(array $modules): void {} }');
}
if(!defined('DP_MAILER_CFG')){
	define('DP_MAILER_CFG', [
		'default_provider'=>'coverage',
		'failover_providers'=>[],
		'from'=>['email'=>'no-reply@example.com', 'name'=>'Dataphyre'],
		'providers'=>[
			'coverage'=>['driver'=>'coverage'],
			'failure'=>['driver'=>'failure'],
		],
		'delivery_safety'=>['enabled'=>false],
		'outbox'=>['enabled'=>true, 'default_priority'=>0],
	]);
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'mailer'=>true, 'templating'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_mailer_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_mailer_modules_root.'/core/kernel/autoloader.php';
require_once $dp_mailer_modules_root.'/mailer/Framework/Contracts/MailProvider.php';
\dataphyre\autoloader::register($dp_mailer_modules_root);
\dataphyre\autoloader::register_framework_modules(['mailer', 'templating']);

final class DpMailerCoverageProvider implements BatchMailProvider {
	public function __construct(private string $provider='coverage', private bool $fail=false){}
	public function name(): string { return $this->provider; }
	public function send(Message $message, array $options=[]): SendResult {
		if($this->fail || str_contains(strtolower($message->subject()), 'fail')){
			return SendResult::failure($this->provider, 'Provider rejected message.', 503, ['retryable'=>true]);
		}
		return SendResult::success($this->provider, 202, 'Accepted', 'msg-'.substr(hash('sha256', $message->subject()), 0, 8), ['accepted'=>true], ['option_keys'=>array_keys($options)]);
	}
	public function sendBatch(array $messages, array $options=[]): array {
		return array_map(fn(Message $message): SendResult=>$this->send($message, $options), $messages);
	}
}

function dp_mailer_coverage_manager(): MailerManager {
	MailerManager::flushInstance();
	$manager=MailerManager::instance();
	$manager->extend('coverage', static fn(array $config, string $name): BatchMailProvider=>new DpMailerCoverageProvider($name));
	$manager->extend('failure', static fn(array $config, string $name): BatchMailProvider=>new DpMailerCoverageProvider($name, true));
	return $manager;
}

function dp_mailer_coverage_message(array $overrides=[]): array {
	return array_replace_recursive([
		'from'=>['email'=>'sender@example.com', 'name'=>'Sender'],
		'reply_to'=>'Support <support@example.com>',
		'to'=>['Ada <ada@example.com>', 'grace@example.com'=>'Grace'],
		'cc'=>'cc@example.com',
		'bcc'=>[['email'=>'bcc@example.com', 'name'=>'BCC']],
		'subject'=>'Coverage message',
		'html'=>'<p>Hello</p>',
		'text'=>'Hello',
		'headers'=>['X-Coverage'=>'ready'],
		'tags'=>['coverage', '', 'transactional'],
		'metadata'=>['campaign'=>'coverage'],
		'unsubscribe_url'=>'https://example.com/unsubscribe',
		'unsubscribe_email'=>'unsubscribe@example.com',
		'one_click_unsubscribe'=>true,
	], $overrides);
}

test('mailer message and send result normalize addresses attachments unsubscribe tags and accessors', static function(Context $t): void {
	$tmp=$t->tempFile('attachment-content', 'mailer-attachment');
	{
		$message=Message::make(dp_mailer_coverage_message([
			'attachments'=>[
				$tmp,
				['filename'=>'inline.txt', 'type'=>'text/plain', 'content'=>'inline', 'disposition'=>'inline', 'cid'=>'inline-1'],
				['path'=>$tmp, 'filename'=>'renamed.txt'],
				['content'=>''],
			],
		]));
		$t->same('sender@example.com', $message->from()['email']);
		$t->same('support@example.com', $message->replyTo()['email']);
		$t->same(2, count($message->to()));
		$t->same(1, count($message->cc()));
		$t->same(1, count($message->bcc()));
		$t->same('Coverage message', $message->subject());
		$t->same('<p>Hello</p>', $message->html());
		$t->same('Hello', $message->text());
		$t->contains('List-Unsubscribe', array_keys($message->headers()));
		$t->same('List-Unsubscribe=One-Click', $message->headers()['List-Unsubscribe-Post']);
		$t->same(3, count($message->attachments()));
		$t->same(['coverage', 'transactional'], $message->tags());
		$t->same('coverage', $message->metadata()['campaign']);
		$t->same($message->payload(), $message->toArray());
		$t->same($message->toArray(), $message->jsonSerialize());
		$t->same('Changed', $message->with(['subject'=>'Changed'])->subject());

		$success=SendResult::success('coverage', 202, 'Accepted', 'msg-1', ['accepted'=>true], ['attempt'=>1]);
		$t->isTrue($success->ok());
		$t->same('coverage', $success->provider());
		$t->same(202, $success->status());
		$t->same('Accepted', $success->message());
		$t->same('msg-1', $success->messageId());
		$t->isTrue($success->response()['accepted']);
		$t->same(1, $success->meta()['attempt']);
		$t->same($success->toArray(), $success->jsonSerialize());
		$failure=SendResult::failure('coverage', 'Failed', 500, ['code'=>'bad'], ['attempt'=>2]);
		$t->isFalse($failure->ok());
		$t->same(null, $failure->messageId());
	}
})->tag('mailer', 'coverage')->group('framework-coverage');

test('mailer manager custom batch provider covers success validation failover safety and provider errors', static function(Context $t): void {
	$manager=dp_mailer_coverage_manager();
	$t->same(DP_MAILER_CFG, $t->nonPublic($manager)->invoke('config', ''));
	$t->same('coverage', $manager->provider()->name());
	$t->same($manager->provider(), $manager->provider('coverage'));
	$t->throws(static fn()=>$manager->extend('', static fn()=>null), InvalidArgumentException::class);
	$manager->extend('invalid', static fn()=>new stdClass());
	$t->throws(static fn()=>$manager->provider('invalid'), RuntimeException::class);
	$t->throws(static fn()=>$manager->provider('missing'), RuntimeException::class);

	$result=$manager->send(dp_mailer_coverage_message());
	$t->isTrue($result->ok());
	$t->same(202, $result->status());
	$t->notEmpty($result->messageId());
	$t->isFalse($result->meta()['failover_used']);

	$failover=$manager->send(dp_mailer_coverage_message(), null, ['providers'=>['failure', 'coverage'], 'idempotency_key'=>'coverage-1']);
	$t->isTrue($failover->ok());
	$t->same('coverage', $failover->provider());
	$t->isTrue($failover->meta()['failover_used']);
	$t->same(2, count($failover->meta()['attempts']));

	$failed=$manager->send(dp_mailer_coverage_message(['subject'=>'Fail now']), 'failure');
	$t->isFalse($failed->ok());
	$t->same(503, $failed->status());
	$invalid=$manager->send(['to'=>[], 'subject'=>'', 'html'=>'', 'text'=>'']);
	$t->isFalse($invalid->ok());
	$t->same(422, $invalid->status());

	$blocked=$manager->send(dp_mailer_coverage_message(), null, [
		'delivery_safety'=>['enabled'=>true, 'allowed_domains'=>[], 'allowed_emails'=>[], 'block_unmatched'=>true],
	]);
	$t->isFalse($blocked->ok());
	$t->same(451, $blocked->status());
	$rewritten=$manager->send(dp_mailer_coverage_message(), null, [
		'delivery_safety'=>['enabled'=>true, 'rewrite_to'=>'safe@example.com', 'block_unmatched'=>true],
	]);
	$t->isTrue($rewritten->ok());
	$allowed=$manager->send(dp_mailer_coverage_message(), null, [
		'delivery_safety'=>['enabled'=>true, 'allowed_domains'=>['example.com']],
	]);
	$t->isTrue($allowed->ok());

	$batch=$manager->sendBatch([dp_mailer_coverage_message(), Message::make(dp_mailer_coverage_message(['subject'=>'Second']))], 'coverage');
	$t->same(2, count($batch));
	$t->isTrue($batch[0]->ok());
	$t->isTrue($batch[1]->ok());
	$batchMixed=$manager->sendBatch([[], dp_mailer_coverage_message()], 'coverage');
	$t->isFalse($batchMixed[0]->ok());
	$t->isTrue($batchMixed[1]->ok());
})->tag('mailer', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('mailer queue events summaries health render and facade degrade safely without SQL', static function(Context $t): void {
	$manager=dp_mailer_coverage_manager();
	$queued=$manager->queue(dp_mailer_coverage_message());
	$t->instanceOf(SendResult::class, $queued);
	$t->isFalse($queued->ok());
	$queuedViaSend=$manager->send(dp_mailer_coverage_message(), null, ['queue'=>true]);
	$t->instanceOf(SendResult::class, $queuedViaSend);
	$t->isFalse($queuedViaSend->ok());
	$t->isTrue(is_object($manager->sendAsync(dp_mailer_coverage_message())) || $manager->sendAsync(dp_mailer_coverage_message()) instanceof SendResult);
	$t->isTrue(is_array($manager->flush(5)));
	$t->isTrue(is_array($manager->outboxSummary()));
	$t->isTrue(is_array($manager->prune(['days'=>30, 'limit'=>10])));
	$t->isTrue(is_array($manager->campaignSummary(['campaign'=>'coverage'])));
	$t->isFalse($manager->suppress('ada@example.com', 'manual', ['source'=>'coverage']));
	$t->isFalse($manager->unsuppress('ada@example.com'));
	$t->isFalse($manager->isSuppressed('ada@example.com'));

	$event=$manager->ingestDeliveryEvent('coverage', [
		'event'=>'delivered', 'message_id'=>'msg-1', 'email'=>'ada@example.com',
		'timestamp'=>'2026-07-10T12:00:00Z', 'metadata'=>['campaign'=>'coverage'],
	]);
	$t->same('delivered', $event['event']);
	$t->same('msg-1', $event['message_id']);
	$events=$manager->ingestDeliveryEvents('coverage', [
		['event'=>'open', 'message_id'=>'msg-1'],
		['event'=>'click', 'message_id'=>'msg-1'],
	]);
	$t->same(2, $events['processed']);
	$t->same(2, count($events['events']));
	$webhook=$manager->ingestDeliveryWebhook('coverage', json_encode(['events'=>[['event'=>'bounce', 'message_id'=>'msg-2']]]) ?: '{}', ['X-Signature'=>'coverage']);
	$t->same(1, $webhook['processed']);
	$t->isTrue(is_array($manager->health(24)));
	$t->isTrue(is_array($manager->trace('msg-1')));

	$inline=$manager->render('<h1>Hello {{name}}</h1>', ['name'=>'Ada'], ['subject'=>'Greeting']);
	$t->same('Greeting', $inline['subject']);
	$t->contains('Hello Ada', $inline['html']);
	$t->contains('Hello Ada', $inline['text']);
	$templated=$manager->send(dp_mailer_coverage_message([
		'template'=>'<p>Template {{name}}</p>',
		'data'=>['name'=>'Ada'],
		'html'=>'', 'text'=>'',
	]));
	$t->isTrue($templated->ok());

	Mailer::flushManager();
	Mailer::extend('coverage', static fn(array $config, string $name): BatchMailProvider=>new DpMailerCoverageProvider($name));
	$t->instanceOf(MailerManager::class, Mailer::manager());
	$t->instanceOf(BatchMailProvider::class, Mailer::provider());
	$t->instanceOf(Message::class, Mailer::message(dp_mailer_coverage_message()));
	$t->isTrue(Mailer::send(dp_mailer_coverage_message())->ok());
	$t->same(2, count(Mailer::sendBatch([dp_mailer_coverage_message(), dp_mailer_coverage_message()])));
	$t->instanceOf(SendResult::class, Mailer::queue(dp_mailer_coverage_message()));
	$t->isTrue(is_array(Mailer::flush(1)));
	$t->isTrue(is_array(Mailer::render('<b>{{name}}</b>', ['name'=>'Ada'])));
	$t->isTrue(is_array(Mailer::outboxSummary()));
	$t->isTrue(is_array(Mailer::prune()));
	$t->isTrue(is_array(Mailer::campaignSummary()));
	$t->isFalse(Mailer::suppress('ada@example.com'));
	$t->isFalse(Mailer::unsuppress('ada@example.com'));
	$t->isFalse(Mailer::isSuppressed('ada@example.com'));
	$t->isTrue(is_array(Mailer::ingestDeliveryEvent('coverage', ['event'=>'open'])));
	$t->isTrue(is_array(Mailer::ingestDeliveryEvents('coverage', [['event'=>'open']])));
	$t->isTrue(is_array(Mailer::ingestDeliveryWebhook('coverage', '{"event":"open"}')));
	$t->isTrue(is_array(Mailer::health()));
	$t->isTrue(is_array(Mailer::trace('msg-1')));
})->tag('mailer', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('mailer log provider and AWS signer cover local transport and deterministic signing', static function(Context $t): void {
	$path=$t->workspace('mailer-log')->path('messages.jsonl');
	{
		$provider=new LogProvider(['path'=>$path]);
		$t->same('log', $provider->name());
		$result=$provider->send(Message::make(dp_mailer_coverage_message()), ['path'=>$path]);
		$t->isTrue($result->ok());
		$t->same(202, $result->status());
		$t->isTrue(is_file($path));
		$t->contains('Coverage message', (string)file_get_contents($path));

		$headers=AwsSignatureV4::headers(
			'POST', 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails',
			'us-east-1', 'ses', 'AKIDEXAMPLE', 'secret',
			'{"FromEmailAddress":"sender@example.com"}', 'session-token', 1783684800
		);
		$t->contains('Authorization', array_keys($headers));
		$t->contains('X-Amz-Date', array_keys($headers));
		$t->same('session-token', $headers['X-Amz-Security-Token']);
	}
})->tag('mailer', 'coverage')->group('framework-coverage');

test('mailer value objects signer log failures and async facade cover normalization edge branches', static function(Context $t): void {
	$edge=Message::make([
		'from'=>['email'=>'sender@example.com'],
		'to'=>42,
		'cc'=>['', ['email'=>'invalid'], new stdClass()],
		'bcc'=>[],
		'subject'=>'Edge',
		'text'=>'Edge body',
		'headers'=>'invalid',
		'list_unsubscribe'=>' <mailto:unsubscribe@example.com> ',
		'attachments'=>'invalid',
	]);
	$t->same([], $edge->to());
	$t->same([], $edge->cc());
	$t->same([], $edge->attachments());
	$t->same('<mailto:unsubscribe@example.com>', $edge->headers()['List-Unsubscribe']);

	$nestedUnsubscribe=Message::make([
		'from'=>'sender@example.com','to'=>'target@example.com','subject'=>'Nested','text'=>'Body',
		'unsubscribe'=>['url'=>'https://example.com/out','one_click'=>'yes'],
		'attachments'=>[new stdClass()],
	]);
	$t->same('List-Unsubscribe=One-Click',$nestedUnsubscribe->headers()['List-Unsubscribe-Post']);
	$t->same([], $nestedUnsubscribe->attachments());
	$noClick=Message::make([
		'from'=>'sender@example.com','to'=>'target@example.com','subject'=>'No click','text'=>'Body',
	]);
	$t->isFalse(isset($noClick->headers()['List-Unsubscribe-Post']));

	$queryHeaders=AwsSignatureV4::headers(
		'POST','https://example.amazonaws.com?z=last&a=first&a[]=second',
		'us-east-1','service','AKID','secret','payload',null,1783684800
	);
	$t->contains('Authorization',array_keys($queryHeaders));

	$logWorkspace=$t->workspace('mailer-edge-log');
	$nestedPath=$logWorkspace->path('nested/mail.jsonl');
	{
		$logged=(new LogProvider(['path'=>$nestedPath]))->send(Message::make(dp_mailer_coverage_message()));
		$t->isTrue($logged->ok());
		$t->isTrue(is_file($nestedPath));
	}
	$failed=(new LogProvider(['path'=>$logWorkspace->root()]))->send(Message::make(dp_mailer_coverage_message()));
	$t->isFalse($failed->ok());
	$t->same(500,$failed->status());
	$defaultPath=rtrim((string)ROOTPATH['dataphyre'],'/\\').'/cache/mailer/mailer.log';
	$defaultExisted=is_file($defaultPath);
	$defaultBefore=$defaultExisted ? file_get_contents($defaultPath) : null;
	try{
		$defaultLogged=(new LogProvider([]))->send(Message::make(dp_mailer_coverage_message()));
		$t->isTrue($defaultLogged->ok());
		$t->same($defaultPath,$defaultLogged->response()['path']);
	}
	finally{
		if($defaultExisted){
			file_put_contents($defaultPath,(string)$defaultBefore);
		}
		else{
			@unlink($defaultPath);
		}
	}

	Mailer::flushManager();
	Mailer::extend('coverage',static fn(array $config,string $name): BatchMailProvider=>new DpMailerCoverageProvider($name));
	$t->isTrue(is_object(Mailer::sendAsync(dp_mailer_coverage_message())) || Mailer::sendAsync(dp_mailer_coverage_message()) instanceof SendResult);
})->tag('mailer','coverage')->group('framework-coverage');
