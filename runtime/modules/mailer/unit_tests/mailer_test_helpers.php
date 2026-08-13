<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

require_once __DIR__.'/../../testing/tooling/bootstrap.php';
require_once __DIR__.'/../Framework/Message.php';
require_once __DIR__.'/../Framework/SendResult.php';
require_once __DIR__.'/../Framework/Contracts/MailProvider.php';
require_once __DIR__.'/../Framework/Providers/LogProvider.php';

function mailer_send_result_json(): string {
	$success=\Dataphyre\Mailer\SendResult::success('unit', 202, 'Queued', 'msg_1', ['id'=>'remote'], ['attempt'=>1]);
	$failure=\Dataphyre\Mailer\SendResult::failure('unit', 'Nope', 500, ['error'=>'bad'], ['retry'=>false]);
	return json_encode([
		'failure'=>$failure,
		'failure_ok'=>$failure->ok(),
		'success'=>$success,
		'success_id'=>$success->messageId(),
	], JSON_UNESCAPED_SLASHES);
}

/** Gives legacy JSON mailer fixtures an owned workspace lifecycle. */
final class MailerFixtureOwner {
	public static function run(callable $scenario): mixed {
		$context=new \Dataphyre\Test\Context('mailer legacy JSON fixture', file:__FILE__, suite:'mailer');
		$workspace=$context->workspace('mailer-json');
		try{
			return $scenario($workspace);
		}finally{
			$context->runDeferred();
		}
	}
}

function mailer_log_provider_json(): string {
	return MailerFixtureOwner::run(static function(\Dataphyre\Test\TempWorkspace $workspace): string {
		$path=$workspace->path('mailer.log');
		$provider=new \Dataphyre\Mailer\Providers\LogProvider(['path'=>$path]);
		$result=$provider->send(\Dataphyre\Mailer\Message::make([
			'to'=>'buyer@example.com',
			'subject'=>'Receipt',
			'text'=>'Thanks',
		]));
		$line=is_file($path) ? trim((string)file_get_contents($path)) : '';
		$decoded=json_decode($line, true);
		return json_encode([
			'logged_subject'=>$decoded['message']['subject'] ?? null,
			'logged_to'=>$decoded['message']['to'][0]['email'] ?? null,
			'provider'=>$provider->name(),
			'result_ok'=>$result->ok(),
			'status'=>$result->status(),
		], JSON_UNESCAPED_SLASHES);
	});
}
