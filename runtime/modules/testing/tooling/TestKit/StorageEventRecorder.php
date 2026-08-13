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

final class StorageEventRecorder {

	/** @var array<int, array<string, mixed>> */
	private array $events=[];

	public function record(array $event): void {
		if(isset($event['event']) && !isset($event['name'])){
			$event['name']=$event['event'];
		}
		$this->events[]=$event;
	}

	/** @return array<int, array<string, mixed>> */
	public function events(): array {
		return $this->events;
	}

	public function assertRecorded(AssertionContext $t, string $event, array $subset=[]): void {
		$t->eventContains($this->events, $event, $subset, 'Expected Dataphyre Storage event to be recorded.');
	}
}
