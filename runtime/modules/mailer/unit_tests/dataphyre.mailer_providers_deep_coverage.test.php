<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\Providers\AwsSesProvider;
use Dataphyre\Mailer\Providers\BrevoProvider;
use Dataphyre\Mailer\Providers\CloudflareProvider;
use Dataphyre\Mailer\Providers\MailgunProvider;
use Dataphyre\Mailer\Providers\PostmarkProvider;
use Dataphyre\Mailer\Providers\ResendProvider;
use Dataphyre\Mailer\Providers\SendGridProvider;
use Dataphyre\Mailer\Support\HttpJsonClient;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(...$arguments): void {}
}
if(!class_exists('dataphyre\\core', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; class core { public static function dialback(...$arguments): mixed { return null; } public static function load_framework_module(string $module): void {} public static function load_framework_modules(array $modules): void {} }');
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'mailer'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

$dp_mailer_provider_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_mailer_provider_modules_root.'/core/kernel/autoloader.php';
require_once $dp_mailer_provider_modules_root.'/mailer/Framework/Contracts/MailProvider.php';
\dataphyre\autoloader::register($dp_mailer_provider_modules_root);
\dataphyre\autoloader::register_framework_modules(['mailer']);

final class DpMailerProviderHttpHarness {
	/** @var list<array<string,mixed>> */
	public static array $responses=[];
	/** @var list<array<string,mixed>> */
	public static array $calls=[];

	/** @param list<array<string,mixed>> $responses */
	public static function install(array $responses): void {
		self::$responses=$responses;
		self::$calls=[];
		HttpJsonClient::useHandler([self::class, 'request']);
	}

	/** @return array<string,mixed> */
	public static function request(string $method, string $url, array|string|null $payload, array $headers, int $timeout): array {
		self::$calls[]=['method'=>$method, 'url'=>$url, 'payload'=>$payload, 'headers'=>$headers, 'timeout'=>$timeout];
		if(self::$responses===[]){
			throw new RuntimeException('No deterministic mailer HTTP response remains.');
		}
		return array_shift(self::$responses);
	}

	public static function reset(): void {
		self::$responses=[];
		self::$calls=[];
		HttpJsonClient::useHandler(null);
	}
}

/** @return array{ok:bool,status:int,headers:list<string>,body:string,json:?array,error:string} */
function dp_mailer_provider_response(bool $ok, int $status, ?array $json=null, array $headers=[], string $error=''): array {
	return [
		'ok'=>$ok,
		'status'=>$status,
		'headers'=>$headers,
		'body'=>$json===null ? '' : (json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
		'json'=>$json,
		'error'=>$error,
	];
}

function dp_mailer_provider_message(array $overrides=[]): Message {
	return Message::make(array_replace_recursive([
		'from'=>['email'=>'sender@example.com', 'name'=>'Séndér'],
		'reply_to'=>['email'=>'reply@example.com', 'name'=>'Reply'],
		'to'=>[
			['email'=>'ada@example.com', 'name'=>'Ada'],
			['email'=>'bare@example.com'],
		],
		'cc'=>[['email'=>'cc@example.com', 'name'=>'CC']],
		'bcc'=>[['email'=>'bcc@example.com']],
		'subject'=>'Provider coverage',
		'html'=>'<p>Hello</p>',
		'text'=>'Hello',
		'headers'=>['X-Coverage'=>'ready'],
		'attachments'=>[],
		'tags'=>['transactional', 'coverage'],
		'metadata'=>[
			'campaign'=>'coverage',
			'count'=>2,
			'nil'=>null,
			'nested'=>['ignored'=>true],
		],
	], $overrides));
}

function dp_mailer_provider_rich_message(array $metadata=[]): Message {
	return dp_mailer_provider_message([
		'attachments'=>[array_merge([
			'filename'=>'report".txt',
			'type'=>'text/plain',
			'content'=>'attachment-data',
			'disposition'=>'inline',
			'content_id'=>'report-1',
		], [])],
		'metadata'=>array_replace([
			'campaign'=>'coverage',
			'count'=>2,
			'nil'=>null,
			'nested'=>['ignored'=>true],
		], $metadata),
	]);
}

function dp_mailer_provider_sparse_message(): Message {
	return Message::make([
		'from'=>['email'=>'sender@example.com'],
		'to'=>[['email'=>'bare@example.com']],
		'subject'=>'Sparse',
		'html'=>'',
		'text'=>'',
	]);
}

test('AWS SES provider covers credentials payload body signing responses and error normalization', static function(Context $t): void {
	$provider=new AwsSesProvider();
	$t->same('aws', $provider->name());
	$t->isFalse($provider->send(dp_mailer_provider_sparse_message())->ok());

	try{
		DpMailerProviderHttpHarness::install([
			dp_mailer_provider_response(true, 200, ['MessageId'=>'aws-1']),
			dp_mailer_provider_response(true, 202, ['messageId'=>'aws-2']),
			dp_mailer_provider_response(false, 400, ['Message'=>'bad aws']),
		]);
		$configured=new AwsSesProvider(['access_key'=>'key', 'secret_key'=>'secret', 'region'=>'ca-central-1']);
		$success=$configured->send(dp_mailer_provider_rich_message(), ['session_token'=>'session']);
		$t->isTrue($success->ok());
		$t->same('aws-1', $success->messageId());
		$t->contains('ca-central-1', DpMailerProviderHttpHarness::$calls[0]['url']);
		$t->contains('EmailTags', array_keys(json_decode((string)DpMailerProviderHttpHarness::$calls[0]['payload'], true) ?: []));
		$override=$configured->send(dp_mailer_provider_sparse_message(), [
			'access_key'=>'other', 'secret_key'=>'secret', 'region'=>'us-east-2',
			'endpoint'=>'https://ses.example.test/send', 'timeout'=>4,
		]);
		$t->same('aws-2', $override->messageId());
		$t->same(4, DpMailerProviderHttpHarness::$calls[1]['timeout']);
		$t->isFalse($configured->send(dp_mailer_provider_sparse_message())->ok());
	}
	finally{
		DpMailerProviderHttpHarness::reset();
	}

	$t->same(['Text'=>['Data'=>'', 'Charset'=>'UTF-8']], $t->nonPublic($provider)->invoke('body', dp_mailer_provider_sparse_message()));
	$t->same('typed', $t->nonPublic(AwsSesProvider::class)->invoke('errorMessage', ['json'=>['__type'=>'typed']]));
	$t->same('transport', $t->nonPublic(AwsSesProvider::class)->invoke('errorMessage', ['error'=>'transport']));
})->tag('mailer', 'coverage')->group('framework-coverage');

test('Brevo provider covers direct and template payloads headers attachments and response shapes', static function(Context $t): void {
	$provider=new BrevoProvider();
	$t->same('brevo', $provider->name());
	$t->isFalse($provider->send(dp_mailer_provider_sparse_message())->ok());
	try{
		DpMailerProviderHttpHarness::install([
			dp_mailer_provider_response(true, 201, ['messageId'=>'brevo-1']),
			dp_mailer_provider_response(true, 201, ['message_id'=>'brevo-2']),
			dp_mailer_provider_response(false, 422, ['error'=>'bad brevo']),
		]);
		$configured=new BrevoProvider([
			'api_key'=>'key', 'headers'=>['X-Config'=>'yes'], 'sandbox'=>true,
		]);
		$success=$configured->send(dp_mailer_provider_rich_message(), [
			'headers'=>['X-Option'=>'yes'], 'idempotency_key'=>str_repeat('b', 300),
		]);
		$t->isTrue($success->ok());
		$t->same('brevo-1', $success->messageId());
		$t->same(256, strlen(DpMailerProviderHttpHarness::$calls[0]['headers']['Idempotency-Key']));
		$t->same('drop', DpMailerProviderHttpHarness::$calls[0]['headers']['X-Sib-Sandbox']);

		$template=dp_mailer_provider_rich_message(['brevo_template_id'=>'42', 'template_params'=>['name'=>'Ada']]);
		$templateResult=$configured->send($template, ['api_key'=>'override', 'endpoint'=>'https://brevo.example.test']);
		$t->same('brevo-2', $templateResult->messageId());
		$t->same(42, DpMailerProviderHttpHarness::$calls[1]['payload']['templateId']);
		$t->isFalse($configured->send(dp_mailer_provider_sparse_message())->ok());
	}
	finally{
		DpMailerProviderHttpHarness::reset();
	}
	$t->same('transport', $t->nonPublic(BrevoProvider::class)->invoke('errorMessage', ['error'=>'transport']));
})->tag('mailer', 'coverage')->group('framework-coverage');

test('Cloudflare provider covers endpoint auth payload success and all error fallbacks', static function(Context $t): void {
	$provider=new CloudflareProvider();
	$t->same('cloudflare', $provider->name());
	$t->isFalse($provider->send(dp_mailer_provider_sparse_message())->ok());
	try{
		DpMailerProviderHttpHarness::install([
			dp_mailer_provider_response(true, 202, ['id'=>'cf-1']),
			dp_mailer_provider_response(true, 202, ['message_id'=>'cf-2']),
			dp_mailer_provider_response(false, 403, ['errors'=>[['message'=>'denied']]]),
		]);
		$configured=new CloudflareProvider([
			'endpoint'=>'https://worker.example.test/mail',
			'headers'=>['X-Worker'=>'mail'],
			'api_token'=>'token',
		]);
		$first=$configured->send(dp_mailer_provider_rich_message());
		$t->isTrue($first->ok());
		$t->same('cf-1', $first->messageId());
		$t->same('Bearer token', DpMailerProviderHttpHarness::$calls[0]['headers']['Authorization']);
		$second=$configured->send(dp_mailer_provider_sparse_message(), ['api_token'=>'', 'endpoint'=>'https://override.example.test', 'timeout'=>3]);
		$t->same('cf-2', $second->messageId());
		$t->isFalse($configured->send(dp_mailer_provider_sparse_message())->ok());
	}
	finally{
		DpMailerProviderHttpHarness::reset();
	}
	$t->same('message', $t->nonPublic(CloudflareProvider::class)->invoke('errorMessage', ['json'=>['message'=>'message']]));
	$t->same('transport', $t->nonPublic(CloudflareProvider::class)->invoke('errorMessage', ['error'=>'transport']));
})->tag('mailer', 'coverage')->group('framework-coverage');

test('Mailgun provider covers form and multipart payloads templates variables headers and errors', static function(Context $t): void {
	$provider=new MailgunProvider();
	$t->same('mailgun', $provider->name());
	$t->isFalse($provider->send(dp_mailer_provider_sparse_message())->ok());
	try{
		DpMailerProviderHttpHarness::install([
			dp_mailer_provider_response(true, 200, ['id'=>'<mailgun-1>']),
			dp_mailer_provider_response(true, 200, []),
			dp_mailer_provider_response(false, 400, ['error'=>'bad mailgun']),
		]);
		$configured=new MailgunProvider([
			'api_key'=>'key', 'domain'=>'mg.example.test', 'base_url'=>'https://eu.example.test/',
			'headers'=>['X-Config'=>'yes'],
		]);
		$rich=$configured->send(dp_mailer_provider_rich_message(), [
			'headers'=>['X-Option'=>'yes'], 'idempotency_key'=>str_repeat('m', 300),
		]);
		$t->isTrue($rich->ok());
		$t->same('mailgun-1', $rich->messageId());
		$t->contains('multipart/form-data', DpMailerProviderHttpHarness::$calls[0]['headers']['Content-Type']);
		$t->same(256, strlen(DpMailerProviderHttpHarness::$calls[0]['headers']['Idempotency-Key']));

		$template=dp_mailer_provider_message([
			'metadata'=>[
				'mailgun_template'=>'welcome',
				'template_variables'=>['name'=>'Ada'],
				'scalar'=>'value',
				'array'=>['ignored'],
			],
		]);
		$templateResult=$configured->send($template, ['endpoint'=>'https://mailgun.example.test/send']);
		$t->isTrue($templateResult->ok());
		$t->same(null, $templateResult->messageId());
		$t->same('application/x-www-form-urlencoded', DpMailerProviderHttpHarness::$calls[1]['headers']['Content-Type']);
		$t->isFalse($configured->send(dp_mailer_provider_sparse_message())->ok());
	}
	finally{
		DpMailerProviderHttpHarness::reset();
	}
	$t->same('transport', $t->nonPublic(MailgunProvider::class)->invoke('errorMessage', ['error'=>'transport']));
})->tag('mailer', 'coverage')->group('framework-coverage');

test('Postmark provider covers direct template and batch response paths', static function(Context $t): void {
	$provider=new PostmarkProvider();
	$messages=[dp_mailer_provider_rich_message(), dp_mailer_provider_sparse_message()];
	$t->same('postmark', $provider->name());
	$t->isFalse($provider->send($messages[0])->ok());
	$t->isFalse($provider->sendBatch($messages)[0]->ok());
	try{
		DpMailerProviderHttpHarness::install([
			dp_mailer_provider_response(true, 200, ['MessageID'=>'postmark-1']),
			dp_mailer_provider_response(true, 200, ['MessageId'=>'postmark-2']),
			dp_mailer_provider_response(false, 422, ['Message'=>'bad postmark']),
			dp_mailer_provider_response(false, 503, ['message'=>'batch down']),
			dp_mailer_provider_response(true, 200, [
				['ErrorCode'=>0, 'Message'=>'OK', 'MessageID'=>'batch-1'],
				['ErrorCode'=>10, 'Message'=>'Rejected'],
			]),
			dp_mailer_provider_response(true, 200, []),
		]);
		$configured=new PostmarkProvider([
			'server_token'=>'token', 'headers'=>['X-Config'=>'yes'],
		]);
		$first=$configured->send($messages[0], ['headers'=>['X-Option'=>'yes'], 'idempotency_key'=>str_repeat('p', 300)]);
		$t->same('postmark-1', $first->messageId());
		$t->same(256, strlen(DpMailerProviderHttpHarness::$calls[0]['headers']['Idempotency-Key']));
		$numericTemplate=dp_mailer_provider_rich_message(['postmark_template_id'=>'17', 'template_model'=>['name'=>'Ada']]);
		$t->same('postmark-2', $configured->send($numericTemplate, ['api_key'=>'override'])->messageId());
		$aliasTemplate=dp_mailer_provider_rich_message(['postmark_template_alias'=>'welcome']);
		$t->isFalse($configured->send($aliasTemplate)->ok());
		$batchFailure=$configured->sendBatch($messages);
		$t->isFalse($batchFailure[0]->ok());
		$batch=$configured->sendBatch($messages, ['batch_endpoint'=>'https://postmark.example.test/batch']);
		$t->isTrue($batch[0]->ok());
		$t->same('batch-1', $batch[0]->messageId());
		$t->isFalse($batch[1]->ok());
		$missing=$configured->sendBatch([$messages[0]]);
		$t->isTrue($missing[0]->ok());
		$t->same(null, $missing[0]->messageId());
	}
	finally{
		DpMailerProviderHttpHarness::reset();
	}
	$t->same('transport', $t->nonPublic(PostmarkProvider::class)->invoke('errorMessage', ['error'=>'transport']));
})->tag('mailer', 'coverage')->group('framework-coverage');

test('Resend provider covers single and batch headers payload response lists and errors', static function(Context $t): void {
	$provider=new ResendProvider();
	$messages=[dp_mailer_provider_rich_message(), dp_mailer_provider_sparse_message()];
	$t->same('resend', $provider->name());
	$t->isFalse($provider->send($messages[0])->ok());
	$t->isFalse($provider->sendBatch($messages)[0]->ok());
	try{
		DpMailerProviderHttpHarness::install([
			dp_mailer_provider_response(true, 200, ['id'=>'resend-1']),
			dp_mailer_provider_response(true, 200, []),
			dp_mailer_provider_response(false, 429, ['error'=>'rate limited']),
			dp_mailer_provider_response(false, 503, ['message'=>'batch down']),
			dp_mailer_provider_response(true, 200, ['data'=>[['id'=>'batch-1'], ['id'=>'batch-2']]]),
			dp_mailer_provider_response(true, 200, [['id'=>'list-1']]),
			dp_mailer_provider_response(true, 200, ['accepted'=>true]),
		]);
		$configured=new ResendProvider(['api_key'=>'key', 'headers'=>['X-Config'=>'yes']]);
		$first=$configured->send($messages[0], [
			'headers'=>['X-Option'=>'yes'], 'idempotency_key'=>str_repeat('r', 300),
		]);
		$t->same('resend-1', $first->messageId());
		$t->same(256, strlen(DpMailerProviderHttpHarness::$calls[0]['headers']['Idempotency-Key']));
		$t->same(null, $configured->send($messages[1], ['endpoint'=>'https://resend.example.test'])->messageId());
		$t->isFalse($configured->send($messages[1])->ok());
		$t->isFalse($configured->sendBatch($messages)[0]->ok());
		$dataBatch=$configured->sendBatch($messages, ['headers'=>['X-Batch'=>'yes']]);
		$t->same('batch-2', $dataBatch[1]->messageId());
		$listBatch=$configured->sendBatch($messages);
		$t->same('list-1', $listBatch[0]->messageId());
		$t->same(null, $listBatch[1]->messageId());
		$emptyBatch=$configured->sendBatch([$messages[0]], ['batch_endpoint'=>'https://resend.example.test/batch']);
		$t->same(null, $emptyBatch[0]->messageId());
	}
	finally{
		DpMailerProviderHttpHarness::reset();
	}
	$t->same('transport', $t->nonPublic(ResendProvider::class)->invoke('errorMessage', ['error'=>'transport']));
})->tag('mailer', 'coverage')->group('framework-coverage');

test('SendGrid provider covers rich and empty content payloads response headers and errors', static function(Context $t): void {
	$provider=new SendGridProvider();
	$t->same('sendgrid', $provider->name());
	$t->isFalse($provider->send(dp_mailer_provider_sparse_message())->ok());
	try{
		DpMailerProviderHttpHarness::install([
			dp_mailer_provider_response(true, 202, null, ['Date: now', 'x-message-id: sendgrid-1']),
			dp_mailer_provider_response(true, 202, null, ['Date: now']),
			dp_mailer_provider_response(false, 400, ['errors'=>[['message'=>'bad sendgrid']]]),
		]);
		$configured=new SendGridProvider(['api_key'=>'key', 'headers'=>['X-Config'=>'yes']]);
		$first=$configured->send(dp_mailer_provider_rich_message(), [
			'headers'=>['X-Option'=>'yes'], 'idempotency_key'=>str_repeat('s', 300),
		]);
		$t->same('sendgrid-1', $first->messageId());
		$t->same(256, strlen(DpMailerProviderHttpHarness::$calls[0]['headers']['Idempotency-Key']));
		$t->same(null, $configured->send(dp_mailer_provider_sparse_message(), ['endpoint'=>'https://sendgrid.example.test'])->messageId());
		$t->isFalse($configured->send(dp_mailer_provider_sparse_message())->ok());
	}
	finally{
		DpMailerProviderHttpHarness::reset();
	}
	$t->same('transport', $t->nonPublic(SendGridProvider::class)->invoke('errorMessage', ['error'=>'transport']));
})->tag('mailer', 'coverage')->group('framework-coverage');

test('mailer HTTP transport adapter validates deterministic handler responses', static function(Context $t): void {
	try{
		HttpJsonClient::useHandler(static fn(): string=>'invalid');
		$t->throws(
			static fn()=>HttpJsonClient::request('POST', 'https://example.test', []),
			UnexpectedValueException::class
		);
	}
	finally{
		HttpJsonClient::useHandler(null);
	}
})->tag('mailer', 'coverage')->group('framework-coverage');
