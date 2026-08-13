<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\Providers\SmtpProvider;
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

$dp_smtp_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_smtp_modules_root.'/core/kernel/autoloader.php';
require_once $dp_smtp_modules_root.'/mailer/Framework/Contracts/MailProvider.php';
\dataphyre\autoloader::register($dp_smtp_modules_root);
\dataphyre\autoloader::register_framework_modules(['mailer']);

final class DpSmtpTranscriptStream {
	public mixed $context=null;
	public static string $transcript='';
	public static string $writes='';
	public static bool $failWrites=false;
	private int $offset=0;

	public static function prepare(string $transcript, bool $failWrites=false): void {
		self::$transcript=$transcript;
		self::$writes='';
		self::$failWrites=$failWrites;
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->offset=0;
		return true;
	}

	public function stream_read(int $count): string {
		$newline=strpos(self::$transcript, "\n", $this->offset);
		$length=$newline===false ? $count : min($count, $newline-$this->offset+1);
		$chunk=substr(self::$transcript, $this->offset, $length);
		$this->offset+=strlen($chunk);
		return $chunk;
	}

	public function stream_write(string $data): int|false {
		if(self::$failWrites){
			return false;
		}
		self::$writes.=$data;
		return strlen($data);
	}

	public function stream_eof(): bool {
		return $this->offset>=strlen(self::$transcript);
	}

	/** @return array<string,mixed> */
	public function stream_stat(): array {
		return [];
	}

	public function stream_set_option(int $option, int $arg1, ?int $arg2): bool {
		return true;
	}
}

if(!in_array('dpsmtp', stream_get_wrappers(), true)){
	stream_wrapper_register('dpsmtp', DpSmtpTranscriptStream::class);
}

function dp_smtp_message(array $overrides=[]): Message {
	return Message::make(array_replace_recursive([
		'from'=>['email'=>'sender@example.com', 'name'=>'Séndér'],
		'reply_to'=>['email'=>'reply@example.com', 'name'=>'Reply'],
		'to'=>[
			['email'=>'ada@example.com', 'name'=>'Ada'],
			['email'=>'bare@example.com'],
		],
		'cc'=>[['email'=>'cc@example.com', 'name'=>'CC']],
		'bcc'=>[['email'=>'bcc@example.com']],
		'subject'=>'Résumé coverage',
		'html'=>'<p>Hello</p>',
		'text'=>'Hello',
		'headers'=>['X-Coverage'=>'ready'],
		'attachments'=>[[
			'filename'=>"report\\\"\r\n.txt",
			'type'=>'text/plain',
			'content'=>'attachment-data',
		]],
	], $overrides));
}

function dp_smtp_sparse_message(): Message {
	return Message::make([
		'from'=>['email'=>'sender@example.com'],
		'to'=>[['email'=>'target@example.com']],
		'subject'=>'Sparse',
		'text'=>'Body',
	]);
}

function dp_smtp_factory(): Closure {
	return static function(string $peer, int $port, int &$errorNumber, string &$errorMessage, int $timeout) {
		return fopen('dpsmtp://server', 'r+');
	};
}

function dp_smtp_transcript(array $lines): string {
	return implode("\r\n", $lines)."\r\n";
}

test('SMTP provider reports missing host plus native and injected connection failures', static function(Context $t): void {
	$provider=new SmtpProvider();
	$t->same('smtp', $provider->name());
	$t->isFalse($provider->send(dp_smtp_sparse_message())->ok());
	$native=$provider->send(dp_smtp_sparse_message(), [
		'host'=>'127.0.0.1', 'port'=>1, 'secure'=>'none', 'timeout'=>1,
	]);
	$t->isFalse($native->ok());
	$injected=$provider->send(dp_smtp_sparse_message(), [
		'host'=>'smtp.example.test', 'secure'=>'none',
		'socket_factory'=>static function(string $peer, int $port, int &$number, string &$message): bool {
			$number=61;
			$message='injected refusal';
			return false;
		},
	]);
	$t->isFalse($injected->ok());
	$t->contains('injected refusal', $injected->message());
	$t->same(61, $injected->response()['error_number']);
})->tag('mailer', 'smtp', 'coverage')->group('framework-coverage');

test('SMTP provider completes a rich no-auth dialogue with multipart MIME and envelope recipients', static function(Context $t): void {
	DpSmtpTranscriptStream::prepare(dp_smtp_transcript([
		'220 smtp.example.test ready',
		'250-smtp.example.test', '250 PIPELINING',
		'250 sender accepted',
		'250 first accepted', '251 second forwarded', '250 cc accepted', '250 bcc accepted',
		'354 end with dot',
		'250 queued',
		'221 goodbye',
	]));
	$result=(new SmtpProvider())->send(dp_smtp_message(), [
		'host'=>'smtp.example.test', 'port'=>2525, 'secure'=>'none',
		'helo'=>'client.example.test', 'socket_factory'=>dp_smtp_factory(),
	]);
	$t->isTrue($result->ok());
	$t->same(250, $result->status());
	$t->contains('EHLO client.example.test', DpSmtpTranscriptStream::$writes);
	$t->contains('RCPT TO:<bcc@example.com>', DpSmtpTranscriptStream::$writes);
	$t->contains('multipart/alternative', DpSmtpTranscriptStream::$writes);
	$t->contains('Content-Disposition: attachment', DpSmtpTranscriptStream::$writes);
	$t->isFalse(str_contains(DpSmtpTranscriptStream::$writes, "\r\nBcc:"));
})->tag('mailer', 'smtp', 'coverage')->group('framework-coverage');

test('SMTP provider covers STARTTLS with LOGIN authentication and a simple body', static function(Context $t): void {
	DpSmtpTranscriptStream::prepare(dp_smtp_transcript([
		'220 ready',
		'250 hello',
		'220 begin tls',
		'250 secure hello',
		'334 username',
		'334 password',
		'235 authenticated',
		'250 sender',
		'250 recipient',
		'354 data',
		'250 accepted',
		'221 bye',
	]));
	$cryptoCalls=0;
	$result=(new SmtpProvider())->send(dp_smtp_sparse_message(), [
		'host'=>'smtp.example.test', 'secure'=>'starttls',
		'username'=>'user', 'password'=>'secret', 'auth'=>'login',
		'socket_factory'=>dp_smtp_factory(),
		'crypto_handler'=>static function(mixed $socket) use (&$cryptoCalls): bool {
			$cryptoCalls++;
			return is_resource($socket);
		},
	]);
	$t->isTrue($result->ok());
	$t->same(1, $cryptoCalls);
	$t->contains('STARTTLS', DpSmtpTranscriptStream::$writes);
	$t->contains('AUTH LOGIN', DpSmtpTranscriptStream::$writes);
	$t->contains(base64_encode('user'), DpSmtpTranscriptStream::$writes);
	$t->contains('Content-Type: text/plain', DpSmtpTranscriptStream::$writes);
})->tag('mailer', 'smtp', 'coverage')->group('framework-coverage');

test('SMTP provider covers AUTH PLAIN and multipart mixed with one body format', static function(Context $t): void {
	DpSmtpTranscriptStream::prepare(dp_smtp_transcript([
		'220 ready', '250 hello', '235 authenticated', '250 sender', '250 recipient',
		'354 data', '250 accepted', '221 bye',
	]));
	$message=Message::make([
		'from'=>'sender@example.com',
		'to'=>'target@example.com',
		'subject'=>'Attachment',
		'html'=>'<b>Only HTML</b>',
		'attachments'=>[['filename'=>'one.txt', 'content'=>'one']],
	]);
	$result=(new SmtpProvider())->send($message, [
		'host'=>'smtp.example.test', 'secure'=>'none',
		'username'=>'user', 'password'=>'secret', 'auth'=>'plain',
		'socket_factory'=>dp_smtp_factory(),
	]);
	$t->isTrue($result->ok());
	$t->contains('AUTH PLAIN '.base64_encode("\0user\0secret"), DpSmtpTranscriptStream::$writes);
	$t->contains('Content-Type: text/html', DpSmtpTranscriptStream::$writes);
	$t->contains('multipart/mixed', DpSmtpTranscriptStream::$writes);
})->tag('mailer', 'smtp', 'coverage')->group('framework-coverage');

test('SMTP provider turns crypto protocol closure and response failures into SendResult failures', static function(Context $t): void {
	$provider=new SmtpProvider();
	DpSmtpTranscriptStream::prepare(dp_smtp_transcript(['220 ready', '250 hello', '220 begin tls']));
	$cryptoFailure=$provider->send(dp_smtp_sparse_message(), [
		'host'=>'smtp.example.test', 'secure'=>'tls',
		'socket_factory'=>dp_smtp_factory(),
		'crypto_handler'=>static fn(): bool=>false,
	]);
	$t->isFalse($cryptoFailure->ok());
	$t->contains('STARTTLS negotiation failed', $cryptoFailure->message());

	DpSmtpTranscriptStream::prepare(dp_smtp_transcript(['500 rejected']));
	$unexpected=$provider->send(dp_smtp_sparse_message(), [
		'host'=>'smtp.example.test', 'secure'=>'none', 'socket_factory'=>dp_smtp_factory(),
	]);
	$t->isFalse($unexpected->ok());
	$t->contains('Unexpected SMTP response', $unexpected->message());

	DpSmtpTranscriptStream::prepare('');
	$closed=$provider->send(dp_smtp_sparse_message(), [
		'host'=>'smtp.example.test', 'secure'=>'none', 'socket_factory'=>dp_smtp_factory(),
	]);
	$t->isFalse($closed->ok());
	$t->contains('closed the connection', $closed->message());
})->tag('mailer', 'smtp', 'coverage')->group('framework-coverage');

test('SMTP provider native crypto and private helpers cover write and header edge paths', static function(Context $t): void {
	$provider=new SmtpProvider();
	$smtpInternals=$t->nonPublic($provider);
	DpSmtpTranscriptStream::prepare(dp_smtp_transcript(['220 ready', '250 hello', '220 begin tls']));
	$nativeCrypto=$provider->send(dp_smtp_sparse_message(), [
		'host'=>'smtp.example.test', 'secure'=>'tls', 'socket_factory'=>dp_smtp_factory(),
	]);
	$t->isFalse($nativeCrypto->ok());

	$t->throws(static fn()=>$smtpInternals->invoke('write', 'line'), RuntimeException::class);
	DpSmtpTranscriptStream::prepare('', true);
	$socket=fopen('dpsmtp://failure', 'r+');
	$smtpInternals->writeProperty('socket', $socket);
	$t->throws(static fn()=>$smtpInternals->invoke('write', 'line'), RuntimeException::class);
	@fclose($socket);
	$smtpInternals->writeProperty('socket', null);

	$t->same('ASCII header', $smtpInternals->invoke('encodeHeader', 'ASCII header'));
	$t->contains('=?UTF-8?B?', $smtpInternals->invoke('encodeHeader', 'Résumé'));
	$t->same('a\\\\\"bc', $smtpInternals->invoke('escapeHeader', "a\\\"b\r\nc"));

	$htmlOnly=Message::make([
		'from'=>'sender@example.com', 'to'=>'target@example.com',
		'subject'=>'HTML', 'html'=>'<b>Body</b>',
	]);
	$mime=$smtpInternals->invoke('mime', $htmlOnly);
	$t->contains('Content-Type: text/html', $mime);
})->tag('mailer', 'smtp', 'coverage')->group('framework-coverage');
