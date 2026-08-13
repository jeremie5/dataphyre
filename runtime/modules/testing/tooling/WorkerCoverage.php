<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

require_once __DIR__.'/CoverageLineNormalizer.php';
require_once __DIR__.'/PhpdbgLineMap.php';

/** Exact line-coverage transport shared by non-TestKit framework workers. */
final class WorkerCoverage {

	/** @var list<string> */
	private array $includedBefore;
	private bool $xdebug=false;
	private bool $phpdbg=false;
	private ?array $result=null;
	/** @var array<string,mixed> */
	private array $runtime;

	/** @param array<string,mixed> $rootpath @param array<string,mixed> $runtime */
	private function __construct(private array $rootpath, private bool $enabled, array $runtime=[]) {
		$this->runtime=$runtime;
		$this->includedBefore=$this->includedSnapshot();
		if(!$enabled){
			return;
		}
		$xdebugAvailable=(bool)($runtime['xdebug_available'] ?? function_exists('xdebug_start_code_coverage'));
		if($xdebugAvailable){
			$flags=defined('XDEBUG_CC_UNUSED') ? XDEBUG_CC_UNUSED : 0;
			$flags|=defined('XDEBUG_CC_DEAD_CODE') ? XDEBUG_CC_DEAD_CODE : 0;
			$start=$runtime['xdebug_start'] ?? 'xdebug_start_code_coverage';
			if(is_callable($start)){@$start($flags);}
			$this->xdebug=true;
			return;
		}
		$phpdbgAvailable=(bool)($runtime['phpdbg_available'] ?? (function_exists('phpdbg_start_oplog') && function_exists('phpdbg_end_oplog') && function_exists('phpdbg_get_executable')));
		if($phpdbgAvailable){
			$start=$runtime['phpdbg_start'] ?? 'phpdbg_start_oplog';
			if(is_callable($start)){@$start();}
			$this->phpdbg=true;
		}
	}

	/** @param array<string,mixed> $rootpath @param array<string,mixed> $runtime */
	public static function start(array $rootpath, bool $enabled, array $runtime=[]): self {
		return new self($rootpath, $enabled, $runtime);
	}

	/** @return array{engine:string,files:array<mixed>}|null */
	public function finish(): ?array {
		if(!$this->enabled){
			return null;
		}
		if($this->result!==null){
			return $this->result;
		}
		$xdebugGet=$this->runtime['xdebug_get'] ?? (function_exists('xdebug_get_code_coverage') ? 'xdebug_get_code_coverage' : null);
		if($this->xdebug && is_callable($xdebugGet)){
			$this->result=['engine'=>'xdebug', 'files'=>$this->xdebugFiles((array)($xdebugGet() ?: [])), 'included_files'=>$this->includedFiles()];
			$xdebugStop=$this->runtime['xdebug_stop'] ?? (function_exists('xdebug_stop_code_coverage') ? 'xdebug_stop_code_coverage' : null);
			if(is_callable($xdebugStop)){@$xdebugStop(false);}
			return $this->result;
		}
		$phpdbgEnd=$this->runtime['phpdbg_end'] ?? (function_exists('phpdbg_end_oplog') ? 'phpdbg_end_oplog' : null);
		$phpdbgGet=$this->runtime['phpdbg_get'] ?? (function_exists('phpdbg_get_executable') ? 'phpdbg_get_executable' : null);
		if($this->phpdbg && is_callable($phpdbgEnd) && is_callable($phpdbgGet)){
			$executable=PhpdbgLineMap::detach(@$phpdbgGet());
			$oplog=PhpdbgLineMap::detach(@$phpdbgEnd());
			$this->result=['engine'=>'phpdbg', 'files'=>$this->phpdbgFiles($executable, $oplog), 'included_files'=>$this->includedFiles()];
			return $this->result;
		}
		return $this->result=['engine'=>'included_files', 'files'=>$this->includedFiles()];
	}

	/** @return list<string> */
	private function includedFiles(): array {
		$files=[];
		foreach(array_diff($this->includedSnapshot(), $this->includedBefore) as $file){
			$normalized=$this->normalize((string)$file);
			if($this->inScope($normalized)){$files[]=$this->relative($normalized);}
		}
		sort($files, SORT_STRING);
		return array_values(array_unique($files));
	}

	/** @return list<string> */
	private function includedSnapshot(): array {
		$reader=$this->runtime['included_files'] ?? 'get_included_files';
		$files=is_callable($reader) ? $reader() : [];
		return array_values(array_map('strval', is_array($files) ? $files : []));
	}

	/** @param array<string,array<int,int>> $coverage @return array<string,array<string,int|string>> */
	private function xdebugFiles(array $coverage): array {
		$files=[];
		foreach($coverage as $file=>$lines){
			$normalized=$this->normalize((string)$file);
			if(!$this->inScope($normalized) || !is_array($lines)){continue;}
			$executable=[];$covered=[];
			foreach($lines as $line=>$hit){
				if((int)$hit===-2){continue;}
				$line=(int)$line;if($line<1){continue;}
				$executable[]=$line;if((int)$hit>0){$covered[]=$line;}
			}
			$files[$this->relative($normalized)]=$this->stats($executable,$covered);
		}
		ksort($files, SORT_STRING);
		return $files;
	}

	/** @param array<string,array<int,mixed>> $executable @param array<string,array<int,mixed>> $oplog @return array<string,array<string,int|string>> */
	private function phpdbgFiles(array $executable, array $oplog): array {
		$files=[];
		foreach($executable as $file=>$lines){
			$normalized=$this->normalize((string)$file);
			if(!$this->inScope($normalized) || !is_array($lines)){continue;}
			$executableLines=array_filter(array_map('intval', array_keys($lines)), static fn(int $line): bool=>$line>0);
			$hits=$oplog[$file] ?? $oplog[str_replace('/', '\\', (string)$file)] ?? [];
			$coveredLines=is_array($hits) ? array_filter(array_map('intval', array_keys($hits)), static fn(int $line): bool=>$line>0) : [];
			$files[$this->relative($normalized)]=$this->phpdbgStats($normalized,$executableLines,$coveredLines);
		}
		ksort($files, SORT_STRING);
		return $files;
	}

	/** @param list<int> $executable @param list<int> $covered @return array{executable:int,covered:int,executable_ranges:string,covered_ranges:string} */
	private function stats(array $executable, array $covered): array {
		$executable=array_values(array_unique(array_map('intval',$executable)));
		$covered=array_values(array_intersect(array_unique(array_map('intval',$covered)),$executable));
		sort($executable,SORT_NUMERIC);sort($covered,SORT_NUMERIC);
		return ['executable'=>count($executable),'covered'=>count($covered),'executable_ranges'=>$this->ranges($executable),'covered_ranges'=>$this->ranges($covered)];
	}

	/** @param list<int> $executable @param list<int> $covered @return array<string,int|string|array<string,string>> */
	private function phpdbgStats(string $file, array $executable, array $covered): array {
		$normalized=CoverageLineNormalizer::phpdbg($file,$executable,$covered);
		$reasons=[];
		foreach($normalized['ignored_by_reason'] as $reason=>$lines){$reasons[$reason]=$this->ranges($lines);}
		return [
			'raw_executable'=>count($normalized['raw_executable_lines']),
			'executable'=>count($normalized['executable_lines']),
			'covered'=>count($normalized['covered_lines']),
			'ignored'=>count($normalized['ignored_lines']),
			'raw_executable_ranges'=>$this->ranges($normalized['raw_executable_lines']),
			'executable_ranges'=>$this->ranges($normalized['executable_lines']),
			'covered_ranges'=>$this->ranges($normalized['covered_lines']),
			'ignored_ranges'=>$this->ranges($normalized['ignored_lines']),
			'ignored_reasons'=>$reasons,
		];
	}

	private function normalize(string $file): string {
		$resolved=realpath($file);
		return str_replace('\\','/',is_string($resolved) ? $resolved : $file);
	}

	private function projectRoot(): string {
		$root=trim((string)($this->rootpath['common_dataphyre'] ?? ''));
		if($root==='' && !empty($this->rootpath['common_dataphyre_runtime'])){$root=dirname(rtrim((string)$this->rootpath['common_dataphyre_runtime'],'/\\'));}
		$resolved=realpath($root);
		return str_replace('\\','/',rtrim(is_string($resolved) ? $resolved : $root,'/\\')).'/';
	}

	private function resultRoot(): string {
		$root=(string)($this->rootpath['common_root'] ?? $this->rootpath['root'] ?? $this->projectRoot());
		$resolved=realpath($root);
		return str_replace('\\','/',rtrim(is_string($resolved) ? $resolved : $root,'/\\')).'/';
	}

	private function inScope(string $file): bool {
		$root=$this->projectRoot();
		if($root==='/' || !str_starts_with($file,$root)){return false;}
		$relative=substr($file,strlen($root));
		if(str_contains('/'.$relative,'/unit_tests/') || str_contains($relative,'.test.php')){return false;}
		return !in_array($relative,['runtime/modules/dpanel/kernel/dpanel.worker.php','runtime/modules/testing/tooling/code_worker.php','runtime/modules/testing/tooling/WorkerCoverage.php','runtime/modules/testing/tooling/CoverageSubprocess.php'],true);
	}

	private function relative(string $file): string {
		$root=$this->resultRoot();
		return str_starts_with($file,$root) ? substr($file,strlen($root)) : $file;
	}

	/** @param list<int> $lines */
	private function ranges(array $lines): string {
		if($lines===[]){return '';}
		$lines=array_values(array_unique(array_map('intval',$lines)));sort($lines,SORT_NUMERIC);
		$ranges=[];$start=$lines[0];$end=$start;
		foreach(array_slice($lines,1)as$line){if($line===$end+1){$end=$line;continue;}$ranges[]=$start===$end?(string)$start:$start.'-'.$end;$start=$end=$line;}
		$ranges[]=$start===$end?(string)$start:$start.'-'.$end;
		return implode(',',$ranges);
	}
}
