<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\AssertionContext;

final class FakeMailer {

	/** @var array<int, array{to:string, subject:string, payload:array<string, mixed>}> */
	private array $messages=[];

	/** @param array<string, mixed> $payload */
	public function send(string $to, string $subject, array $payload=[]): void {
		$this->messages[]=[
			'to'=>$to,
			'subject'=>$subject,
			'payload'=>$payload,
			'queued'=>false,
		];
	}

	/** @param array<string, mixed> $payload */
	public function queue(string $to, string $subject, array $payload=[]): void {
		$this->messages[]=[
			'to'=>$to,
			'subject'=>$subject,
			'payload'=>$payload,
			'queued'=>true,
		];
	}

	/** @return array<int, array{to:string, subject:string, payload:array<string, mixed>}> */
	public function sent(): array {
		return $this->messages;
	}

	/** @return array{to:string, subject:string, payload:array<string, mixed>}|null */
	public function last(): ?array {
		return $this->messages[count($this->messages)-1] ?? null;
	}

	public function count(): int {
		return count($this->messages);
	}

	public function assertSent(AssertionContext $t, string $to='', string $subject='', array $payload_subset=[]): void {
		$found=false;
		foreach($this->messages as $message){
			if($to!=='' && $message['to']!==$to){
				continue;
			}
			if($subject!=='' && $message['subject']!==$subject){
				continue;
			}
			if($payload_subset!==[]){
				try{
					$t->subset($payload_subset, $message['payload']);
				}catch(AssertionFailed){
					continue;
				}
			}
			$found=true;
			break;
		}
		if($found===false){
			$t->fail('Expected fake mailer to contain a sent message.', ['to'=>$to, 'subject'=>$subject, 'payload_subset'=>$payload_subset], $this->messages);
		}
		$t->isTrue(true, 'Mail message was sent.');
	}

	public function assertSentCount(AssertionContext $t, int $expected): void {
		$t->same($expected, $this->count(), 'Expected fake mailer sent count to match.');
	}
}
