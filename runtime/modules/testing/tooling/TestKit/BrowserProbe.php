<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\TestContext;

final class BrowserProbe {

	private string $root;
	private string $worker;
	private string $node;

	public function __construct(string $root='', private array $options=[]) {
		$this->root=rtrim(str_replace('\\', '/', $root!=='' ? $root : getcwd()), '/');
		$this->worker=(string)($options['worker'] ?? $this->root.'/runtime/modules/testing/tooling/browser_worker.js');
		$this->node=(string)($options['node'] ?? 'node');
	}

	public function assertHtml(TestContext $t, string $html, array $options=[]): array {
		return $this->assert($t, ['html'=>$html]+$options);
	}

	public function assertUrl(TestContext $t, string $url, array $options=[]): array {
		return $this->assert($t, ['url'=>$url]+$options);
	}

	public function screenshot(TestContext $t, string $html, string $name, array $options=[]): array {
		$path=$this->snapshotPath($t, $name, 'png');
		return $this->assertHtml($t, $html, $options+['screenshot_path'=>$path]);
	}

	public function visualSnapshot(TestContext $t, string $html, string $name, bool $update=false, array $options=[]): array {
		$path=$this->snapshotPath($t, $name, 'png');
		return $this->assertHtml($t, $html, $options+[
			'screenshot_path'=>$this->snapshotPath($t, $name.'.actual', 'png'),
			'visual_baseline_path'=>$path,
			'update_visual_baseline'=>$update || in_array(strtolower((string)(getenv('DATAPHYRE_UPDATE_VISUAL_SNAPSHOTS') ?: '')), ['1', 'true', 'yes', 'on'], true),
		]);
	}

	public function assert(TestContext $t, array $payload): array {
		if(!is_file($this->worker)){
			$t->skip('Browser worker is unavailable at '.$this->worker.'.');
		}
		$tmp=$this->tempDir();
		$payload_path=$tmp.'/browser-payload-'.bin2hex(random_bytes(6)).'.json';
		$output_path=$tmp.'/browser-result-'.bin2hex(random_bytes(6)).'.json';
		$payload+=['output_path'=>$output_path];
		file_put_contents($payload_path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
		$process=$this->run($t, [$this->node, $this->worker, $payload_path], (int)($payload['timeout_seconds'] ?? 20));
		$result=is_file($output_path) ? json_decode((string)file_get_contents($output_path), true) : null;
		@unlink($payload_path);
		@unlink($output_path);
		if(!is_array($result)){
			$t->fail('Browser worker did not return a valid result.', 'browser result JSON', $process);
		}
		if(($result['skipped'] ?? false)===true){
			$t->skip((string)($result['reason'] ?? 'Browser worker skipped.'));
		}
		if(($result['passed'] ?? false)!==true || $process['exit_code']!==0){
			$t->fail('Browser assertion failed.', [], $result+['process'=>$process]);
		}
		$t->isTrue(true, 'Browser assertion passed.');
		return $result;
	}

	private function snapshotPath(TestContext $t, string $name, string $extension): string {
		$base=$this->root.'/cache/unit-test-browser';
		$id=preg_replace('/[^A-Za-z0-9_.-]+/', '_', $t->name().($t->dataset()!=='' ? '.'.$t->dataset() : '').'.'.$name) ?: 'browser';
		return $base.'/'.trim(strtolower($id), '._-').'.'.$extension;
	}

	private function tempDir(): string {
		$dir=$this->root.'/cache/unit-test-browser';
		if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)){
			throw new \RuntimeException('Unable to create browser test cache directory.');
		}
		return $dir;
	}

	private function run(TestContext $context, array $command, int $timeout_seconds): array {
		try{
			return ProcessProbe::run(
				$context->workspace('browser-worker-process'),
				$command,
				working_directory:$this->root,
				timeout_millis:$timeout_seconds*1000
			)->diagnostic();
		}catch(\RuntimeException $error){
			return [
				'exit_code'=>127,
				'stdout'=>'',
				'stderr'=>$error->getMessage(),
				'timed_out'=>false,
			];
		}
	}
}
