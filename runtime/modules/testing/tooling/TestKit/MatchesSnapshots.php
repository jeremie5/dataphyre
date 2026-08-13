<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Owns the Context capabilities described by its name. */
trait MatchesSnapshots {

	public function snapshot(string $name, mixed $actual, string $message=''): void {
		$this->recordAssertion();
		$path=$this->snapshotPath($name);
		$content=$this->snapshotContent($actual);
		$update=in_array(strtolower((string)(getenv('DATAPHYRE_UPDATE_SNAPSHOTS') ?: '')), ['1', 'true', 'yes', 'on'], true);
		if($update){
			$dir=dirname($path);
			if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)){
				$this->fail('Snapshot directory could not be created.', $dir, null);
			}
			file_put_contents($path, $content);
			return;
		}
		if(!is_file($path)){
			$this->fail($message!=='' ? $message : 'Snapshot file is missing. Set DATAPHYRE_UPDATE_SNAPSHOTS=1 to create it.', $path, null);
		}
		$expected=(string)file_get_contents($path);
		if($expected!==$content){
			$this->fail($message!=='' ? $message : 'Snapshot content changed.', $expected, $content, ['diff'=>$this->unifiedDiff($expected, $content)]);
		}
	}

	private function snapshotPath(string $name): string {
		$base=$this->file!=='' ? dirname($this->file) : sys_get_temp_dir();
		$id=$this->sanitizeSnapshotName(basename($this->file ?: 'dataphyre-test').'.'.$this->name.($this->dataset!=='' ? '.'.$this->dataset : '').'.'.$name);
		return $base.'/__snapshots__/'.$id.'.snap';
	}

	private function snapshotContent(mixed $actual): string {
		if(is_string($actual)){
			return $actual;
		}
		return json_encode($actual, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
	}

	private function unifiedDiff(string $expected, string $actual, int $context=3): string {
		$old=preg_split('/\R/', rtrim($expected, "\r\n")) ?: [];
		$new=preg_split('/\R/', rtrim($actual, "\r\n")) ?: [];
		$old_count=count($old);
		$new_count=count($new);
		$prefix=0;
		while($prefix<$old_count && $prefix<$new_count && $old[$prefix]===$new[$prefix]){
			$prefix++;
		}
		$suffix=0;
		while($suffix<$old_count-$prefix && $suffix<$new_count-$prefix && $old[$old_count-1-$suffix]===$new[$new_count-1-$suffix]){
			$suffix++;
		}
		$lines=['--- expected', '+++ actual'];
		$head_start=max(0, $prefix-$context);
		for($i=$head_start; $i<$prefix; $i++){
			$lines[]=' '.$old[$i];
		}
		for($i=$prefix; $i<$old_count-$suffix; $i++){
			$lines[]='-'.$old[$i];
		}
		for($i=$prefix; $i<$new_count-$suffix; $i++){
			$lines[]='+'.$new[$i];
		}
		$tail_end=min($old_count, $old_count-$suffix+$context);
		for($i=max($prefix, $old_count-$suffix); $i<$tail_end; $i++){
			$lines[]=' '.$old[$i];
		}
		if(count($lines)>80){
			$lines=array_merge(array_slice($lines, 0, 40), ['... diff truncated ...'], array_slice($lines, -40));
		}
		return implode("\n", $lines);
	}

	private function sanitizeSnapshotName(string $name): string {
		$name=strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) ?? $name);
		return trim($name, '._-') ?: 'snapshot';
	}
}
