<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mailer\Contracts\BatchMailProvider;
use Dataphyre\Mailer\Contracts\MailProvider;
use Dataphyre\Mailer\MailerManager;
use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\SendResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(...$arguments): void {}
}
if(!class_exists('dataphyre\\core', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class core {
	public static array $dialbacks=[];
	public static array $modules=[];
	public static function dialback(string $name, mixed ...$arguments): mixed {
		$value=self::$dialbacks[$name] ?? null;
		return is_callable($value) ? $value(...$arguments) : $value;
	}
	public static function load_framework_module(string $module): bool {
		return (bool)(self::$modules[$module] ?? false);
	}
	public static function load_framework_modules(array $modules): void {}
}
PHP);
}
if(!class_exists('Dataphyre\\Async\\Async', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Async;
final class Async {
	public static function dispatch(callable $job, array $arguments=[], mixed $driver=null): mixed {
		return $job(...$arguments);
	}
}
PHP);
}
if(!class_exists('Dataphyre\\Templating\\Templating', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Templating;
final class DpMailerDeepRendered {
	public function __construct(private string $value){}
	public function content(): string { return $this->value; }
}
final class Templating {
	public static function render(string $file, array $data=[]): DpMailerDeepRendered {
		return new DpMailerDeepRendered('modern-file:'.basename($file).':'.($data['name'] ?? ''));
	}
	public static function renderString(string $source, array $data=[], array $globals=[], array $options=[], string $name=''): DpMailerDeepRendered {
		return new DpMailerDeepRendered('modern-inline:'.$name.':'.($data['name'] ?? ''));
	}
}
PHP);
}
if(!class_exists('Dataphyre\\Localization\\Localization', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Localization;
final class Localization {
	public static function translate(string $key, ?string $fallback=null, array $parameters=[], ?string $language=null, ?string $page=null, ?string $theme=null): string {
		return 'modern:'.$key.':'.($language ?? '').':'.($page ?? '').':'.($theme ?? '');
	}
}
PHP);
}

if(!defined('DP_MAILER_CFG')){
	define('DP_MAILER_CFG', [
		'default_provider'=>'ok',
		'providers'=>['ok'=>['driver'=>'ok']],
		'outbox'=>['enabled'=>true],
		'suppression'=>['enabled'=>true],
	]);
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'mailer'=>true, 'templating'=>true, 'localization'=>true, 'async'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

$dp_mailer_deep_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_mailer_deep_modules_root.'/core/kernel/autoloader.php';
require_once $dp_mailer_deep_modules_root.'/mailer/Framework/Contracts/MailProvider.php';
\dataphyre\autoloader::register($dp_mailer_deep_modules_root);
\dataphyre\autoloader::register_framework_modules(['mailer']);

final class DpMailerDeepSql {
	public static mixed $selectHandler=null;
	public static mixed $insertHandler=null;
	public static mixed $updateHandler=null;
	public static mixed $deleteHandler=null;
	public static array $calls=[];

	public static function reset(): void {
		self::$selectHandler=null;
		self::$insertHandler=null;
		self::$updateHandler=null;
		self::$deleteHandler=null;
		self::$calls=[];
	}

	public static function invoke(string $operation, array $arguments, mixed $default): mixed {
		self::$calls[]=[$operation, $arguments];
		$handler=self::${$operation.'Handler'};
		return is_callable($handler) ? $handler(...$arguments) : ($handler ?? $default);
	}
}

if(!function_exists('sql_select')){
	function sql_select(mixed ...$arguments): mixed { return DpMailerDeepSql::invoke('select', $arguments, []); }
}
if(!function_exists('sql_insert')){
	function sql_insert(mixed ...$arguments): mixed { return DpMailerDeepSql::invoke('insert', $arguments, true); }
}
if(!function_exists('sql_update')){
	function sql_update(mixed ...$arguments): mixed { return DpMailerDeepSql::invoke('update', $arguments, true); }
}
if(!function_exists('sql_delete')){
	function sql_delete(mixed ...$arguments): mixed { return DpMailerDeepSql::invoke('delete', $arguments, 1); }
}

final class DpMailerDeepProvider implements BatchMailProvider {
	public function __construct(private string $provider, private string $mode='success'){}
	public function name(): string { return $this->provider; }
	public function send(Message $message, array $options=[]): SendResult {
		if($this->mode==='throw'){
			throw new RuntimeException('Deep provider exception.');
		}
		if($this->mode==='failure'){
			return SendResult::failure($this->provider, 'Deep provider failure.', 503, ['retryable'=>true]);
		}
		return SendResult::success($this->provider, 202, 'Deep provider accepted.', 'deep-'.$this->provider, ['accepted'=>true], ['options'=>$options]);
	}
	public function sendBatch(array $messages, array $options=[]): array {
		$results=array_map(fn(Message $message): SendResult=>$this->send($message, $options), $messages);
		return $this->mode==='short' ? array_slice($results, 0, 1) : $results;
	}
}

final class DpMailerDeepSingleProvider implements MailProvider {
	public function __construct(private string $provider){}
	public function name(): string { return $this->provider; }
	public function send(Message $message, array $options=[]): SendResult {
		return SendResult::success($this->provider, 200, 'Single provider accepted.', 'single-'.$this->provider);
	}
}

function dp_mailer_deep_set_config(array $config): void {
	if(!class_exists('dataphyre\\mailer', false)){
		\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class mailer {
	public static array $values=[];
	public static function config(string $key='', mixed $default=null): mixed {
		if($key===''){
			return self::$values;
		}
		$current=self::$values;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return $default;
			}
			$current=$current[$segment];
		}
		return $current;
	}
}
PHP);
	}
	\dataphyre\mailer::$values=$config;
}

function dp_mailer_deep_config(array $overrides=[]): array {
	$base=[
		'default_provider'=>'ok',
		'failover_providers'=>[],
		'providers'=>[
			'ok'=>['driver'=>'ok'],
			'fail'=>['driver'=>'fail'],
			'throw'=>['driver'=>'throw'],
			'short'=>['driver'=>'short'],
			'single'=>['driver'=>'single'],
			'limited'=>['driver'=>'ok'],
		],
		'delivery_safety'=>['enabled'=>false],
		'idempotency'=>['enabled'=>true, 'metadata_key'=>'idempotency_key'],
		'outbox'=>[
			'enabled'=>true,
			'table'=>'deep_outbox',
			'events_table'=>'deep_events',
			'track_events'=>true,
			'default_priority'=>0,
			'default_max_attempts'=>3,
			'recover_stale_sending'=>['enabled'=>true, 'timeout_seconds'=>120, 'batch_size'=>25],
			'rate_limits'=>['enabled'=>false, 'providers'=>[], 'default_per_flush'=>null, 'defer_seconds'=>2],
		],
		'suppression'=>[
			'enabled'=>true,
			'enforce'=>true,
			'table'=>'deep_suppressions',
			'list'=>[],
			'hash_salt'=>'deep-salt',
			'store_email'=>false,
		],
		'webhooks'=>[
			'dedupe_enabled'=>true,
			'events_table'=>'deep_webhooks',
			'require_signature'=>false,
			'providers'=>[],
		],
		'retention'=>[],
	];
	return array_replace_recursive($base, $overrides);
}

function dp_mailer_deep_manager(array $config=[]): MailerManager {
	DpMailerDeepSql::reset();
	\dataphyre\core::$dialbacks=[];
	\dataphyre\core::$modules=[];
	dp_mailer_deep_set_config(dp_mailer_deep_config($config));
	MailerManager::flushInstance();
	$manager=MailerManager::instance();
	$manager->extend('ok', static fn(array $config, string $name): BatchMailProvider=>new DpMailerDeepProvider($name));
	$manager->extend('fail', static fn(array $config, string $name): BatchMailProvider=>new DpMailerDeepProvider($name, 'failure'));
	$manager->extend('throw', static fn(array $config, string $name): BatchMailProvider=>new DpMailerDeepProvider($name, 'throw'));
	$manager->extend('short', static fn(array $config, string $name): BatchMailProvider=>new DpMailerDeepProvider($name, 'short'));
	$manager->extend('single', static fn(array $config, string $name): MailProvider=>new DpMailerDeepSingleProvider($name));
	return $manager;
}

function dp_mailer_deep_message(array $overrides=[]): array {
	return array_replace_recursive([
		'from'=>'sender@example.com',
		'to'=>'recipient@example.com',
		'subject'=>'Deep coverage',
		'html'=>'<p>Deep</p>',
		'text'=>'Deep',
		'metadata'=>[],
	], $overrides);
}

function dp_mailer_deep_flush_rows(array $rows, array $stale=[]): void {
	DpMailerDeepSql::$selectHandler=static function(mixed $columns, mixed $table, mixed $where='', mixed $params=[], mixed ...$rest) use ($rows, $stale): mixed {
		$where=(string)$where;
		if(str_contains($where, 'updated_at<=?')){
			return $stale;
		}
		if(str_contains($where, 'not_before IS NULL')){
			return $rows;
		}
		if(str_contains($where, 'email_hash=?')){
			return [];
		}
		return [];
	};
}

test('mailer manager deep provider factories dialbacks batch async and failover branches', static function(Context $t): void {
	$builtins=[
		'log'=>'log', 'cloudflare'=>'cloudflare', 'sendgrid'=>'sendgrid', 'smtp'=>'smtp',
		'mailgun'=>'mailgun', 'postmark'=>'postmark', 'resend'=>'resend', 'brevo'=>'brevo',
		'sendinblue'=>'sendinblue', 'aws'=>'aws', 'aws_ses'=>'aws_ses', 'ses'=>'ses',
	];
	$providers=[];
	foreach($builtins as $name=>$driver){
		$providers[$name]=['driver'=>$driver];
	}
	$manager=dp_mailer_deep_manager(['providers'=>$providers+dp_mailer_deep_config()['providers']]);
	$private=$t->nonPublic($manager);
	foreach(array_keys($builtins) as $name){
		$t->instanceOf(MailProvider::class, $manager->provider($name));
	}

	\dataphyre\core::$dialbacks['CALL_MAILER_FRAMEWORK_SEND_BEFORE']=SendResult::success('before', 299, 'before override');
	$t->same('before', $manager->send(dp_mailer_deep_message())->provider());
	\dataphyre\core::$dialbacks=[];
	\dataphyre\core::$dialbacks['CALL_MAILER_FRAMEWORK_SEND_AFTER']=SendResult::failure('after', 'after override', 499);
	$t->same('after', $manager->send(dp_mailer_deep_message())->provider());
	\dataphyre\core::$dialbacks=[];

	$t->same(422, $manager->send(dp_mailer_deep_message(['subject'=>'']))->status());
	$t->same(422, $manager->send(dp_mailer_deep_message(['html'=>'', 'text'=>'']))->status());
	$t->same(500, $manager->send(dp_mailer_deep_message(), 'throw')->status());
	$empty=$private->invokeWithArguments('sendThroughProviders', [Message::make(dp_mailer_deep_message()), [], []]);
	$t->isFalse($empty->ok());

	\dataphyre\core::$dialbacks['CALL_MAILER_FRAMEWORK_SEND_BATCH_BEFORE']=[SendResult::success('batch-before')];
	$t->same('batch-before', $manager->sendBatch([dp_mailer_deep_message()])[0]->provider());
	\dataphyre\core::$dialbacks=[];
	$invalidBatch=$manager->sendBatch([
		dp_mailer_deep_message(['to'=>[]]),
		dp_mailer_deep_message(['subject'=>'']),
		dp_mailer_deep_message(['html'=>'', 'text'=>'']),
	]);
	$t->same(3, count($invalidBatch));
	$blockedBatch=$manager->sendBatch([dp_mailer_deep_message()], null, [
		'delivery_safety'=>['enabled'=>true, 'allowed_domains'=>[], 'allowed_emails'=>[], 'block_unmatched'=>true],
	]);
	$t->same(451, $blockedBatch[0]->status());

	DpMailerDeepSql::$insertHandler=true;
	$queued=$manager->sendBatch([dp_mailer_deep_message(), dp_mailer_deep_message(['subject'=>'Queued 2'])], 'ok', ['queue'=>true]);
	$t->same(2, count($queued));
	$short=$manager->sendBatch([dp_mailer_deep_message(), dp_mailer_deep_message(['subject'=>'Short 2'])], 'short');
	$t->isTrue($short[0]->ok());
	$t->isFalse($short[1]->ok());
	$single=$manager->sendBatch([dp_mailer_deep_message()], 'single');
	$t->isTrue($single[0]->ok());
	$multi=$manager->sendBatch([dp_mailer_deep_message()], null, ['providers'=>['fail', 'ok']]);
	$t->isTrue($multi[0]->ok());
	\dataphyre\core::$dialbacks['CALL_MAILER_FRAMEWORK_SEND_BATCH_AFTER']=[SendResult::failure('batch-after', 'batch override')];
	$t->same('batch-after', $manager->sendBatch([dp_mailer_deep_message()])[0]->provider());
	\dataphyre\core::$dialbacks=[];

	\dataphyre\core::$dialbacks['CALL_MAILER_FRAMEWORK_SEND_ASYNC_BEFORE']='async-before';
	$t->same('async-before', $manager->sendAsync(dp_mailer_deep_message()));
	\dataphyre\core::$dialbacks=[];
	\dataphyre\core::$modules['async']=true;
	$async=$manager->sendAsync(dp_mailer_deep_message(), 'ok', ['async_driver'=>'deep']);
	$t->isTrue(is_array($async));
	\dataphyre\core::$dialbacks['CALL_MAILER_FRAMEWORK_SEND_ASYNC_AFTER']='async-after';
	$t->same('async-after', $manager->sendAsync(dp_mailer_deep_message()));
})->tag('mailer', 'manager', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('mailer manager deep queue priority idempotency and delivery safety branches', static function(Context $t): void {
	$manager=dp_mailer_deep_manager();
	$private=$t->nonPublic($manager);
	DpMailerDeepSql::$insertHandler=true;
	foreach([
		new DateTimeImmutable('2026-07-10 12:00:00'),
		1783684800,
		'2026-07-11 13:00:00',
		null,
	] as $notBefore){
		$result=$manager->queue(dp_mailer_deep_message(), 'ok', ['not_before'=>$notBefore, 'priority'=>'high']);
		$t->isTrue($result->ok());
	}
	DpMailerDeepSql::$insertHandler=false;
	$t->isFalse($manager->queue(dp_mailer_deep_message(), 'ok')->ok());
	DpMailerDeepSql::$insertHandler=true;
	$t->same(451, $manager->queue(dp_mailer_deep_message(), 'ok', [
		'delivery_safety'=>['enabled'=>true, 'allowed_domains'=>[], 'allowed_emails'=>[], 'block_unmatched'=>true],
	])->status());
	$t->same(422, $manager->queue(dp_mailer_deep_message(['subject'=>'']), 'ok')->status());

	\dataphyre\mailer::$values=dp_mailer_deep_config(['suppression'=>[
		'list'=>[['email'=>'recipient@example.com', 'reason'=>'configured', 'source'=>'deep']],
	]]);
	$t->same(423, $manager->queue(dp_mailer_deep_message(), 'ok')->status());
	\dataphyre\mailer::$values=dp_mailer_deep_config();

	$message=Message::make(dp_mailer_deep_message(['metadata'=>['priority'=>'urgent']]));
	foreach([
		[1500, 1000], [-1500, -1000], ['critical', 100], ['urgent', 100], ['high', 50],
		['normal', 0], ['default', 0], ['low', -50], ['bulk', -100], ['background', -100], ['unknown', 0],
	] as [$value, $expected]){
		$t->same($expected, $private->invokeWithArguments('messagePriority', [$message, ['priority'=>$value]]));
	}

	$explicit=$private->invokeWithArguments('withIdempotency', [$message, ['idempotency_key'=>'explicit']]);
	$t->same('explicit', $explicit['idempotency_key']);
	$metadata=Message::make(dp_mailer_deep_message(['metadata'=>['idempotency_key'=>'metadata-key']]));
	$t->same('metadata-key', $private->invokeWithArguments('withIdempotency', [$metadata, []])['idempotency_key']);
	$t->same('fallback', $private->invokeWithArguments('withIdempotency', [$message, [], 'fallback'])['idempotency_key']);
	$t->same(64, strlen($private->invokeWithArguments('withIdempotency', [$message, []])['idempotency_key']));
	\dataphyre\mailer::$values=dp_mailer_deep_config(['idempotency'=>['enabled'=>false]]);
	$t->same([], $private->invokeWithArguments('withIdempotency', [$message, []]));
	\dataphyre\mailer::$values=dp_mailer_deep_config();

	$alreadySafe=Message::make(dp_mailer_deep_message(['metadata'=>['delivery_safety_applied'=>true]]));
	$t->same($alreadySafe, $private->invokeWithArguments('applyDeliverySafety', [$alreadySafe, 'ok', [
		'delivery_safety'=>['enabled'=>true],
	]]));
	$unchanged=$private->invokeWithArguments('applyDeliverySafety', [$message, 'ok', [
		'delivery_safety'=>['enabled'=>true, 'block_unmatched'=>false],
	]]);
	$t->instanceOf(Message::class, $unchanged);
	$t->isTrue($private->invokeWithArguments('deliverySafetyRecipientAllowed', ['allowed@example.com', [
		'allowed_emails'=>['allowed@example.com'], 'allowed_domains'=>[],
	]]));
	$t->isFalse($private->invokeWithArguments('deliverySafetyRecipientAllowed', ['', ['allowed_emails'=>[], 'allowed_domains'=>[]]]));
	$t->same([], $private->invokeWithArguments('flattenRecipients', [['to'=>[['email'=>'invalid']]]]));
})->tag('mailer', 'manager', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('mailer manager deep flush covers recovery rate limiting safety suppression retry and terminal states', static function(Context $t): void {
	$manager=dp_mailer_deep_manager([
		'outbox'=>['rate_limits'=>['enabled'=>true, 'providers'=>['limited'=>1], 'default_per_flush'=>null, 'defer_seconds'=>1]],
		'suppression'=>['list'=>[['email'=>'suppressed@example.com', 'reason'=>'manual', 'source'=>'config']]],
	]);
	DpMailerDeepSql::$insertHandler=true;
	DpMailerDeepSql::$updateHandler=static function(mixed $table, mixed $fields, mixed $where, mixed $params): mixed {
		return (($params[0] ?? '')==='stale-fail') ? false : true;
	};
	$valid=static fn(string $to, string $subject='Deep'): string => json_encode(Message::make(dp_mailer_deep_message(['to'=>$to, 'subject'=>$subject]))->toArray()) ?: '{}';
	$rows=[
		'not-an-array',
		['id'=>'limited-1', 'provider'=>'limited', 'attempts'=>0, 'max_attempts'=>3, 'message_json'=>$valid('one@example.com')],
		['id'=>'limited-2', 'provider'=>'limited', 'attempts'=>0, 'max_attempts'=>3, 'message_json'=>$valid('two@example.com')],
		['id'=>'invalid', 'provider'=>'ok', 'attempts'=>0, 'max_attempts'=>3, 'message_json'=>'{}'],
		['id'=>'suppressed', 'provider'=>'ok', 'attempts'=>0, 'max_attempts'=>3, 'message_json'=>$valid('suppressed@example.com')],
		['id'=>'retry', 'provider'=>'fail', 'attempts'=>0, 'max_attempts'=>3, 'message_json'=>$valid('retry@example.com')],
		['id'=>'terminal', 'provider'=>'fail', 'attempts'=>2, 'max_attempts'=>3, 'message_json'=>$valid('terminal@example.com')],
		['id'=>'sent', 'provider'=>'ok', 'attempts'=>0, 'max_attempts'=>3, 'message_json'=>$valid('sent@example.com')],
	];
	dp_mailer_deep_flush_rows($rows, ['bad', ['id'=>''], ['id'=>'stale-fail'], ['id'=>'stale-ok', 'provider'=>'ok', 'attempts'=>2]]);
	$summary=$manager->flush(999);
	$t->isTrue($summary['ok']);
	$t->same(7, $summary['processed']);
	$t->same(1, $summary['rate_limited']);
	$t->same(1, $summary['recovered']);
	$t->isTrue($summary['sent']>=2);
	$t->isTrue($summary['failed']>=3);
	$t->same(1, $summary['suppressed']);

	$runSafety=static function(array $safety, array $message, array $suppression=[]) use ($manager): array {
		\dataphyre\mailer::$values=dp_mailer_deep_config([
			'delivery_safety'=>$safety,
			'outbox'=>['recover_stale_sending'=>['enabled'=>false]],
			'suppression'=>['list'=>$suppression],
		]);
		$row=['id'=>'safety', 'provider'=>'ok', 'attempts'=>0, 'max_attempts'=>1, 'message_json'=>json_encode(Message::make($message)->toArray()) ?: '{}'];
		dp_mailer_deep_flush_rows([$row]);
		return $manager->flush(1);
	};
	$t->same(1, $runSafety(['enabled'=>true, 'block_unmatched'=>true], dp_mailer_deep_message())['failed']);
	$t->same(1, $runSafety(['enabled'=>true, 'rewrite_to'=>'safe@example.com'], dp_mailer_deep_message(['subject'=>'']))['failed']);
	$t->same(1, $runSafety(['enabled'=>true, 'rewrite_to'=>'safe@example.com'], dp_mailer_deep_message(), [
		['email'=>'safe@example.com', 'reason'=>'manual', 'source'=>'config'],
	])['suppressed']);
	$t->same(1, $runSafety(['enabled'=>true, 'rewrite_to'=>'safe@example.com'], dp_mailer_deep_message())['sent']);
})->tag('mailer', 'manager', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('mailer manager deep persistence summaries pruning campaigns trace and health branches', static function(Context $t): void {
	$manager=dp_mailer_deep_manager([
		'providers'=>[
			'sendgrid'=>['driver'=>'sendgrid'], 'mailgun'=>['driver'=>'mailgun'], 'postmark'=>['driver'=>'postmark'],
			'resend'=>['driver'=>'resend'], 'brevo'=>['driver'=>'brevo'], 'sendinblue'=>['driver'=>'sendinblue'],
			'smtp'=>['driver'=>'smtp'], 'aws'=>['driver'=>'aws'], 'cloudflare'=>['driver'=>'cloudflare'],
		],
	]);
	$private=$t->nonPublic($manager);
	DpMailerDeepSql::$insertHandler=true;
	DpMailerDeepSql::$updateHandler=true;
	DpMailerDeepSql::$deleteHandler=2;

	$t->isFalse($manager->suppress('invalid'));
	DpMailerDeepSql::$selectHandler=[];
	$t->isTrue($manager->suppress('new@example.com', '', [
		'expires_at'=>new DateTimeImmutable('+1 day'), 'metadata'=>['kind'=>'deep'], 'source'=>'', 'store_email'=>true,
	]));
	DpMailerDeepSql::$selectHandler=['id'=>'existing'];
	$t->isTrue($manager->suppress('existing@example.com', 'bounce', ['expires'=>1783684800]));
	$t->isTrue($manager->unsuppress('existing@example.com'));

	DpMailerDeepSql::$selectHandler=false;
	$t->isFalse($manager->outboxSummary()['ok']);
	DpMailerDeepSql::$selectHandler=[null, ['status'=>'queued', 'total'=>'2'], ['status'=>'sent']];
	$statuses=$manager->outboxSummary();
	$t->same(2, $statuses['statuses']['queued']);
	$t->same(0, $statuses['statuses']['sent']);

	DpMailerDeepSql::$deleteHandler=static function(mixed $table, mixed ...$arguments): mixed {
		return str_contains((string)$table, 'webhook') ? false : 3;
	};
	$pruned=$manager->prune([
		'outbox_sent_days'=>1, 'outbox_failed_days'=>2, 'outbox_suppressed_days'=>3,
		'events_days'=>4, 'webhook_events_days'=>5, 'expired_suppressions'=>true,
	]);
	$t->isFalse($pruned['ok']);
	$t->isTrue(count($pruned['sections'])>=6);

	DpMailerDeepSql::$selectHandler=false;
	$t->isFalse($manager->campaignSummary()['ok']);
	$matchMessage=dp_mailer_deep_message(['tags'=>['campaign-a', 'tag-a'], 'metadata'=>[
		'campaign'=>'campaign-a', 'template_alias'=>'welcome', 'segment'=>'vip',
	]]);
	DpMailerDeepSql::$selectHandler=static function(mixed $columns, mixed $table, mixed $where='', mixed $params=[]) use ($matchMessage): mixed {
		if(str_contains((string)$where, 'created_at>=?')){
			return [
				'bad-row',
				['id'=>'bad-json', 'provider'=>'ok', 'status'=>'sent', 'message_json'=>'{'],
				['id'=>'match', 'provider'=>'ok', 'status'=>'sent', 'message_json'=>json_encode($matchMessage)],
			];
		}
		if(str_contains((string)$where, 'GROUP BY event, severity')){
			return [null, ['event'=>'delivered', 'severity'=>'info', 'total'=>2]];
		}
		return [];
	};
	$campaign=$manager->campaignSummary([
		'campaign'=>'campaign-a', 'tag'=>'tag-a', 'template'=>'welcome',
		'metadata_key'=>'segment', 'metadata_value'=>'vip', 'since'=>'2026-01-01', 'sample'=>99,
	]);
	$t->same(1, $campaign['matches']);
	$t->same(2, $campaign['events']['delivered']['info']);

	foreach([
		[['metadata'=>[], 'tags'=>[]], ['campaign'=>'x']],
		[$matchMessage, ['tag'=>'missing']],
		[$matchMessage, ['template'=>'missing']],
		[$matchMessage, ['metadata_key'=>'missing']],
		[$matchMessage, ['metadata_key'=>'segment', 'metadata_value'=>'other']],
	] as [$message, $filters]){
		$t->isFalse($private->invokeWithArguments('campaignSummaryMatch', [$message, $filters]));
	}
	$t->isTrue($private->invokeWithArguments('campaignSummaryMatch', [$matchMessage, []]));
	$t->same([], $private->invokeWithArguments('campaignEventSummary', [[]]));

	$t->isFalse($manager->trace('')['ok']);
	DpMailerDeepSql::$selectHandler=static function(mixed $columns, mixed $table, mixed $where='', mixed $params=[]): mixed {
		$where=(string)$where;
		if(str_contains($where, 'WHERE id=?')){
			return ['id'=>'trace-1', 'message_json'=>'{"subject":"Hi"}', 'result_json'=>'{'];
		}
		if(str_contains($where, 'message_id=?') && str_contains((string)$table, 'webhook')){
			return [['payload_json'=>'{"webhook":true}'], 'bad'];
		}
		if(str_contains($where, 'message_id=?')){
			return [['payload_json'=>'{"event":true}'], null];
		}
		if(str_contains($where, 'GROUP BY status')){
			return [['status'=>'sent', 'total'=>1]];
		}
		if(str_contains($where, 'created_at>=? GROUP BY event, severity')){
			return [['event'=>'sent', 'severity'=>'info', 'total'=>1]];
		}
		if(str_contains($where, 'GROUP BY reason')){
			return [['reason'=>'manual', 'total'=>2]];
		}
		if(str_contains($where, 'GROUP BY provider, event')){
			return [['provider'=>'ok', 'event'=>'delivered', 'total'=>3]];
		}
		return [];
	};
	$trace=$manager->trace('trace-1');
	$t->isTrue($trace['ok']);
	$t->same('Hi', $trace['outbox']['message_json']['subject']);
	$t->same('{', $trace['outbox']['result_json']);
	$health=$manager->health(9999);
	$t->same(744, $health['window_hours']);
	$t->isTrue(count($health['providers'])>=9);
})->tag('mailer', 'manager', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('mailer manager deep provider event normalization webhook signatures and suppression ingestion branches', static function(Context $t): void {
	$manager=dp_mailer_deep_manager();
	$private=$t->nonPublic($manager);
	DpMailerDeepSql::$insertHandler=true;
	DpMailerDeepSql::$selectHandler=[];
	$events=[
		['mailgun', ['event-data'=>['event'=>'failed', 'severity'=>'permanent', 'recipient'=>'MAILGUN@example.com', 'id'=>'mg-id', 'message'=>['headers'=>['message-id'=>'mg-header']]]]],
		['postmark', ['RecordType'=>'Bounce', 'Type'=>'HardBounce', 'Email'=>'postmark@example.com', 'MessageID'=>'pm-id']],
		['resend', ['type'=>'email.bounced', 'data'=>['to'=>['resend@example.com'], 'email_id'=>'rs-id']]],
		['brevo', ['event'=>'spam', 'email'=>'brevo@example.com', 'message-id'=>'br-id']],
		['sendinblue', ['event'=>'delivered', 'email'=>'blue@example.com', 'messageId'=>'bl-id']],
	];
	foreach($events as [$provider, $payload]){
		$result=$manager->ingestDeliveryEvent($provider, $payload);
		$t->isTrue($result['ok']);
	}
	$mailNested=$manager->ingestDeliveryEvent('ok', [
		'event'=>'bounced',
		'mail'=>['messageId'=>'mail-id', 'destination'=>['mail@example.com']],
		'bounce'=>['bouncedRecipients'=>[['emailAddress'=>'bounce@example.com']]],
		'complaint'=>['complainedRecipients'=>[['emailAddress'=>'complaint@example.com']]],
	]);
	$t->same('bounce', $mailNested['event']);
	$t->same('mail-id', $mailNested['message_id']);

	DpMailerDeepSql::$selectHandler=static function(mixed $columns, mixed $table, mixed $where=''): mixed {
		return str_contains((string)$where, 'event_hash=?') ? ['event_hash'=>'seen'] : [];
	};
	$t->isTrue($manager->ingestDeliveryEvent('ok', ['event'=>'open', 'email'=>'seen@example.com'])['duplicate']);

	foreach([
		['email_bounced', 'bounce'], ['hardbounce', 'bounce'], ['spamreport', 'complaint'],
		['abuse', 'complaint'], ['unsubscription', 'unsubscribe'], ['list_unsubscribe', 'unsubscribe'],
	] as [$raw, $expected]){
		$t->same($expected, $private->invokeWithArguments('normalizeDeliveryEvent', [$raw]));
	}
	$t->same('complaint', $private->invokeWithArguments('suppressionReasonForDeliveryEvent', ['complaint']));
	$t->same('unsubscribe', $private->invokeWithArguments('suppressionReasonForDeliveryEvent', ['unsubscribe']));
	$t->same('error', $private->invokeWithArguments('deliveryEventSeverity', ['failed']));
	$recipients=$private->invokeWithArguments('deliveryEventRecipients', [[
		'email'=>new stdClass(),
		'to'=>['first@example.com', ['address'=>'second@example.com'], 5],
		'destination'=>['nested'=>[['recipient'=>'third@example.com']]],
	]]);
	$t->same(3, count($recipients));

	$t->isFalse($manager->ingestDeliveryWebhook('ok', '')['ok']);
	\dataphyre\mailer::$values=dp_mailer_deep_config(['webhooks'=>[
		'require_signature'=>true,
		'providers'=>['ok'=>['hmac_secret'=>'secret', 'signature_header'=>'x-deep-signature']],
	]]);
	$body='{"event":"open","email":"signed@example.com"}';
	$t->isFalse($manager->ingestDeliveryWebhook('ok', $body)['ok']);
	$t->isFalse($manager->ingestDeliveryWebhook('ok', $body, ['X-Deep-Signature'=>'bad'])['ok']);
	$signature=hash_hmac('sha256', $body, 'secret');
	$t->isFalse($manager->ingestDeliveryWebhook('ok', '{', ['X-Deep-Signature'=>hash_hmac('sha256', '{', 'secret')])['ok']);
	$valid=$manager->ingestDeliveryWebhook('ok', $body, ['X-Deep-Signature'=>['sha256='.$signature]]);
	$t->isTrue($valid['ok']);

	$t->same('', $private->invokeWithArguments('headerValue', [[], 'missing']));
	$t->same([['event'=>'open']], $private->invokeWithArguments('webhookEventPayloads', [[['event'=>'open'], 'bad']]));
	$t->same([['event'=>'open']], $private->invokeWithArguments('webhookEventPayloads', [['events'=>['events'=>[['event'=>'open']]]]]));
})->tag('mailer', 'manager', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('mailer manager deep template config translation dates rate limits and helper branch matrix', static function(Context $t): void {
	MailerManager::flushInstance();
	$manager=MailerManager::instance();
	$private=$t->nonPublic($manager);
	$t->isTrue(is_array($private->invokeWithArguments('config', [''])));
	dp_mailer_deep_set_config(dp_mailer_deep_config());
	DpMailerDeepSql::reset();
	\dataphyre\core::$modules=[];

	$workspace=$t->workspace('mailer-templates');
	$directory=$workspace->root();
	$stem=$workspace->path('welcome');
	$workspace->file('welcome.subject.tpl', 'Subject {{name}}');
	$workspace->file('welcome.html.tpl', '<h1>Hello {{name}}</h1>');
	$workspace->file('welcome.text.tpl', 'Text {{name}}');
	$single=$workspace->file('single.tpl', '<p>Single {{name}}</p>');
	$bundle=$manager->render($stem, ['name'=>'Ada']);
	$t->same('Subject Ada', $bundle['subject']);
	$t->contains('Hello Ada', $bundle['html']);
	$t->contains('Text Ada', $bundle['text']);
	$t->contains('Single Ada', $manager->render($single, ['name'=>'Ada'])['html']);
	\dataphyre\mailer::$values=dp_mailer_deep_config(['templates_path'=>$directory]);
	$t->contains('Hello Ada', $manager->render('welcome', ['name'=>'Ada'])['html']);

	\dataphyre\core::$modules['templating']=true;
	$t->contains('modern-file', $manager->render($single, ['name'=>'Ada'])['html']);
	$t->contains('modern-inline', $manager->render('inline {{name}}', ['name'=>'Ada'])['html']);
	\dataphyre\core::$modules['templating']=false;

	$fallback=$private->invokeWithArguments('translate', ['deep.subject', 'Hello {{name}}', ['name'=>'Ada'], []]);
	$t->same('Hello Ada', $fallback);
	if(!class_exists('dataphyre\\localization', false)){
		\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class localization { public static function locale(string $key, ?string $fallback=null, array $parameters=[], ?string $language=null, ?string $page=null): string { return "legacy:".$key.":".($language ?? "").":".($page ?? ""); } }');
	}
	$t->same('legacy:deep.subject:fr:mail', $private->invokeWithArguments('translate', [
		'deep.subject', null, [], ['language'=>'fr', 'page'=>'mail'],
	]));
	\dataphyre\core::$modules['localization']=true;
	$t->same('modern:deep.subject:en:mail:dark', $private->invokeWithArguments('translate', [
		'deep.subject', null, [], ['language'=>'en', 'page'=>'mail', 'theme'=>'dark'],
	]));

	$t->same('', $private->invokeWithArguments('renderTemplateFile', ['', []]));
	$t->same('A &lt;b&gt; B  C [1,2]', $private->invokeWithArguments('replaceTokens', [
		'A {{value}} B {{missing}} C {{items}}', ['value'=>'<b>', 'items'=>[1, 2]],
	]));
	$t->same('ok,fail', implode(',', $private->invokeWithArguments('providerChain', [null, ['providers'=>'ok, fail, ok,']])));
	\dataphyre\mailer::$values=dp_mailer_deep_config(['failover_providers'=>'fail, ok']);
	$t->same(['ok', 'fail'], $private->invokeWithArguments('providerChain', [null, []]));
	$t->same(['log'], $private->invokeWithArguments('providerChain', [null, ['providers'=>[]]]));

	$t->same('2026-07-10 12:00:00', $private->invokeWithArguments('normalizeDate', [new DateTimeImmutable('2026-07-10 12:00:00')]));
	$t->same(date('Y-m-d H:i:s', 1783684800), $private->invokeWithArguments('normalizeDate', [1783684800]));
	$t->same(null, $private->invokeWithArguments('normalizeDate', ['not-a-date']));
	$t->same(null, $private->invokeWithArguments('normalizeDate', [null]));
	\dataphyre\mailer::$values=dp_mailer_deep_config(['outbox'=>['retry_backoff_seconds'=>'1,2']]);
	$t->isTrue(strtotime($private->invokeWithArguments('nextRetryAt', [99]))>time());
	\dataphyre\mailer::$values=dp_mailer_deep_config(['outbox'=>['retry_backoff_seconds'=>[]]]);
	$t->isTrue(strtotime($private->invokeWithArguments('nextRetryAt', [1]))>time());

	\dataphyre\mailer::$values=dp_mailer_deep_config(['outbox'=>['rate_limits'=>['enabled'=>false]]]);
	$t->isFalse($private->invokeWithArguments('providerRateLimitReached', ['ok', ['ok'=>100]]));
	\dataphyre\mailer::$values=dp_mailer_deep_config(['outbox'=>['rate_limits'=>[
		'enabled'=>true, 'providers'=>['ok'=>2], 'default_per_flush'=>3, 'defer_seconds'=>0,
	]]]);
	$t->isTrue($private->invokeWithArguments('providerRateLimitReached', ['ok', ['ok'=>2]]));
	$t->same(3, $private->invokeWithArguments('providerRateLimit', ['other']));
	$t->isTrue(strtotime($private->invokeWithArguments('rateLimitRetryAt'))>=time());
	$t->same([], $private->invokeWithArguments('stringList', [new stdClass()]));
	$t->same(['a', 'b'], $private->invokeWithArguments('stringList', ['a, b, a,']));
})->tag('mailer', 'manager', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('mailer manager deep final suppression summary health and failure guards', static function(Context $t): void {
	$manager=dp_mailer_deep_manager(['suppression'=>['list'=>[
		['email'=>'recipient@example.com', 'reason'=>'manual', 'source'=>'config'],
	]]]);
	$private=$t->nonPublic($manager);
	DpMailerDeepSql::$selectHandler=[];
	DpMailerDeepSql::$insertHandler=true;
	$t->same(423, $manager->send(dp_mailer_deep_message())->status());
	$t->same(423, $manager->sendBatch([dp_mailer_deep_message()])[0]->status());
	$t->isFalse($private->invokeWithArguments('suppressionResult', [
		Message::make(dp_mailer_deep_message()), 'ok', ['ignore_suppression'=>true],
	]));

	\dataphyre\mailer::$values=dp_mailer_deep_config(['suppression'=>['list'=>[]]]);
	DpMailerDeepSql::$selectHandler=static function(mixed $columns, mixed $table, mixed $where=''): mixed {
		if(str_contains((string)$where, 'updated_at<=?')){
			return [];
		}
		if(str_contains((string)$where, 'not_before IS NULL')){
			return false;
		}
		return [];
	};
	$t->isFalse($manager->flush(1)['ok']);

	DpMailerDeepSql::$selectHandler=false;
	$t->same(0, $private->invokeWithArguments('recoverStaleSending'));
	$t->same([], $private->invokeWithArguments('campaignEventSummary', [['message-1']]));
	$t->isFalse($private->invokeWithArguments('eventSummary', ['2026-01-01 00:00:00'])['ok']);
	$t->isFalse($private->invokeWithArguments('suppressionSummary')['ok']);
	$t->isFalse($private->invokeWithArguments('webhookSummary', ['2026-01-01 00:00:00'])['ok']);

	\dataphyre\mailer::$values=dp_mailer_deep_config(['webhooks'=>['dedupe_enabled'=>false]]);
	$t->isFalse($private->invokeWithArguments('webhookSummary', ['2026-01-01 00:00:00'])['dedupe_enabled']);
	$t->same(['id'=>'row'], $private->invokeWithArguments('decodeTraceRow', [['id'=>'row'], ['missing_json']]));

	\dataphyre\mailer::$values=dp_mailer_deep_config(['suppression'=>['enabled'=>false]]);
	$t->isFalse($manager->isSuppressed('invalid'));
	\dataphyre\mailer::$values=dp_mailer_deep_config(['suppression'=>['list'=>[
		['email'=>'expired@example.com', 'expires_at'=>'2000-01-01 00:00:00'],
	]]]);
	DpMailerDeepSql::$selectHandler=[];
	$t->isFalse($manager->isSuppressed('expired@example.com'));

	dp_mailer_deep_set_config([
		'default_provider'=>'sendgrid',
		'providers'=>[],
		'outbox'=>['enabled'=>true, 'table'=>'deep_outbox', 'events_table'=>'deep_events'],
		'suppression'=>['enabled'=>true, 'table'=>'deep_suppressions', 'list'=>[]],
		'webhooks'=>['dedupe_enabled'=>true, 'events_table'=>'deep_webhooks'],
	]);
	DpMailerDeepSql::$selectHandler=[];
	$providers=$private->invokeWithArguments('providerHealth');
	$t->same('sendgrid', $providers[0]['name']);
	$t->isFalse($providers[0]['ready']);
	$t->isFalse($manager->health()['ok']);

	\dataphyre\core::$modules['localization']=false;
	$rendered=$manager->render('Hello {{name}}', ['name'=>'Ada'], [
		'subject_key'=>'mail.subject', 'subject_fallback'=>'Subject {{name}}',
	]);
	$t->same('Subject Ada', $rendered['subject']);
})->tag('mailer', 'manager', 'coverage')->group('framework-coverage')->maxMillis(10000);
