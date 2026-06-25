<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

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

function mailer_log_provider_json(): string {
	$path=sys_get_temp_dir().'/dataphyre_mailer_unit_'.bin2hex(random_bytes(4)).'.log';
	$provider=new \Dataphyre\Mailer\Providers\LogProvider(['path'=>$path]);
	$result=$provider->send(\Dataphyre\Mailer\Message::make([
		'to'=>'buyer@example.com',
		'subject'=>'Receipt',
		'text'=>'Thanks',
	]));
	$line=is_file($path) ? trim((string)file_get_contents($path)) : '';
	$decoded=json_decode($line, true);
	@unlink($path);
	return json_encode([
		'logged_subject'=>$decoded['message']['subject'] ?? null,
		'logged_to'=>$decoded['message']['to'][0]['email'] ?? null,
		'provider'=>$provider->name(),
		'result_ok'=>$result->ok(),
		'status'=>$result->status(),
	], JSON_UNESCAPED_SLASHES);
}
