<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer\Providers;

use Dataphyre\Mailer\Contracts\MailProvider;
use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\SendResult;

/**
 * Mail provider that records outbound messages as JSON lines.
 *
 * This provider is useful for development, tests, and audit-style transports where accepting a
 * message means writing it to a log file. It does not contact an external mail service.
 * The configured path is trusted local filesystem configuration, and each log
 * record includes the full normalized message data.
 */
final class LogProvider implements MailProvider {

	/**
	 * Stores log-provider configuration.
	 *
	 * @param array{path?:string} $config Optional log file path.
	 */
	public function __construct(private array $config=[]){}

	/**
	 * Returns the provider identifier used in send results.
	 *
	 * @return string Provider name.
	 */
	public function name(): string {
		return 'log';
	}

	/**
	 * Appends one message record to the configured mailer log.
	 *
	 * The default path is `<ROOTPATH['dataphyre']>/cache/mailer/mailer.log` when ROOTPATH is
	 * available, otherwise the system temp directory. Each line contains a generated id,
	 * timestamp, and encoded provider-ready message data.
	 *
	 * Parent directories are created best-effort with group-write permissions.
	 * Write failures become failed SendResult values; successful logging means the
	 * local file accepted the line, not that any external delivery occurred.
	 *
	 * @param Message $message Message to record.
	 * @param array<string, mixed> $options Reserved for provider compatibility.
	 * @return SendResult Success with the generated log id, or failure when the file cannot be written.
	 */
	public function send(Message $message, array $options=[]): SendResult {
		$id='mail_'.bin2hex(random_bytes(12));
		$path=trim((string)($this->config['path'] ?? ''));
		if($path===''){
			$root=defined('ROOTPATH') && !empty(ROOTPATH['dataphyre']) ? ROOTPATH['dataphyre'] : sys_get_temp_dir().'/';
			$path=rtrim((string)$root, '/\\').'/cache/mailer/mailer.log';
		}
		$directory=dirname($path);
		if(!is_dir($directory)){
			@mkdir($directory, 0775, true);
		}
		$record=[
			'id'=>$id,
			'date'=>date('c'),
			'message'=>$message->toArray(),
		];
		$line=json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
		if(@file_put_contents($path, $line, FILE_APPEND | LOCK_EX)===false){
			return SendResult::failure($this->name(), 'Unable to write mailer log.', 500, ['path'=>$path]);
		}
		return SendResult::success($this->name(), 202, 'Message logged.', $id, ['path'=>$path]);
	}
}
