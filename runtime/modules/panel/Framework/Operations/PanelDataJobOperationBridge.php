<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Executes the existing synchronous PanelDataJob inside the persistent operation runtime. */
final class PanelDataJobOperationBridge {

	/** @param array<string, mixed> $options */
	public static function execute(PanelDataJob $job, PanelSynchronousOperationRunner $runner, array $options=[]): PanelOperationRecord {
		$plan=$job->plan();
		$metadata=array_replace(['bridge'=>'PanelDataJob', 'plan'=>$plan], is_array($options['metadata'] ?? null) ? $options['metadata'] : []);
		$options=array_replace([
			'id'=>(string)($plan['id'] ?? ''),
			'queue'=>(string)($plan['queue'] ?? 'default'),
			'max_attempts'=>1,
			'idempotency_key'=>'panel_data_job:'.(string)($plan['id'] ?? ''),
			'total'=>(int)($plan['total'] ?? 0),
			'metadata'=>$metadata,
		], $options);
		$options['metadata']=$metadata;
		$record=$runner->submit('panel_data_job', (string)($plan['name'] ?? 'data job'), ['plan'=>$plan], $options);
		if($record->terminal()){ return $record; }
		return $runner->runWith($record->id(), static function(array $payload, PanelOperationExecution $execution)use($job): array {
			$execution->log('info', 'Legacy PanelDataJob execution started.', ['plan'=>$payload['plan'] ?? []]);
			$result=$job->run();
			$execution->progress($result->processed(), $result->total(), 'Legacy data job finished.', $result->succeeded(), $result->failed());
			foreach($result->events() as $index=>$event){
				$execution->checkpoint('legacy_event_'.($index+1), is_array($event) ? $event : ['event'=>$event]);
			}
			foreach($result->artifacts() as $artifact){
				if(!is_array($artifact)){ continue; }
				$execution->artifact(
					(string)($artifact['name'] ?? 'artifact'),
					(string)($artifact['location'] ?? 'panel-data-job://'.rawurlencode((string)($artifact['name'] ?? 'artifact'))),
					(string)($artifact['mime'] ?? 'application/octet-stream'),
					isset($artifact['bytes']) ? (int)$artifact['bytes'] : null,
					is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : []
				);
			}
			if($result->status()==='failed'){
				throw new \RuntimeException('Legacy PanelDataJob failed all work items.');
			}
			return $result->jsonSerialize();
		});
	}
}
