<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Spools exact worker coverage and unions it with bounded memory. */
final class CoverageAccumulator {
	private \SplTempFileObject $spool;
	/** @var list<array<string,mixed>>|null */
	private ?array $merged=null;

	public function __construct() { $this->reset(); }

	public function reset(): void {
		$this->spool=new \SplTempFileObject(1048576);
		$this->merged=null;
	}

	/** @param array<string,mixed> $part */
	public function add(array $part): void {
		$engine=(string)($part['engine'] ?? '');
		if(!in_array($engine,['xdebug','phpdbg','included_files'],true) || !is_array($part['files'] ?? null)){
			throw new \UnexpectedValueException('Covered subprocess returned an invalid coverage payload.');
		}
		if($engine!=='included_files'){
			foreach($part['files'] as $file=>$stats){
				if(!is_array($stats)){throw new \UnexpectedValueException('Covered subprocess returned invalid exact line statistics for '.$file.'.');}
			}
		}
		$this->spool->fseek(0,SEEK_END);
		$this->spool->fwrite(json_encode($part,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
		$this->merged=null;
	}

	/** @return list<array<string,mixed>> */
	public function all(): array {
		if($this->merged!==null){return $this->merged;}
		$this->spool->rewind();
		$merged=[];
		while(!$this->spool->eof()){
			$encoded=$this->spool->fgets();
			if(!is_string($encoded) || trim($encoded)===''){continue;}
			$part=json_decode($encoded,true,512,JSON_THROW_ON_ERROR);
			if(!is_array($part)){throw new \UnexpectedValueException('Covered subprocess returned an invalid coverage payload.');}
			$engine=(string)$part['engine'];
			if(isset($merged[$engine])){$this->mergeInto($merged[$engine],$part,$engine);continue;}
			$merged[$engine]=$this->normalize($part,$engine);
		}
		return $this->merged=array_values($merged);
	}

	/** @param array<string,mixed> $part @return array<string,mixed> */
	private function normalize(array $part,string $engine): array {
		$normalized=['engine'=>$engine,'files'=>[],'included_files'=>[]];
		$this->mergeInto($normalized,$part,$engine);
		return $normalized;
	}

	/** @param array<string,mixed> $left @param array<string,mixed> $right */
	private function mergeInto(array &$left,array $right,string $engine): void {
		$included=[];
		foreach([...(is_array($left['included_files'] ?? null) ? $left['included_files'] : []),...(is_array($right['included_files'] ?? null) ? $right['included_files'] : [])] as $file){
			$included[(string)$file]=true;
		}
		ksort($included,SORT_STRING);
		$left['engine']=$engine;
		$left['included_files']=array_keys($included);
		if($engine==='included_files'){
			$files=[];
			foreach([...(is_array($left['files'] ?? null) ? $left['files'] : []),...(is_array($right['files'] ?? null) ? $right['files'] : [])] as $file){
				$files[(string)$file]=true;
			}
			ksort($files,SORT_STRING);
			$left['files']=array_keys($files);
			return;
		}
		if(!is_array($left['files'] ?? null)){$left['files']=[];}
		foreach(is_array($right['files'] ?? null) ? $right['files'] : [] as $file=>$stats){
			$key=(string)$file;
			$left['files'][$key]=$this->mergeStats(
				is_array($left['files'][$key] ?? null) ? $left['files'][$key] : [],
				$stats,
			);
		}
		ksort($left['files'],SORT_STRING);
	}

	/** @param array<string,mixed> $left @param array<string,mixed> $right */
	private function mergeStats(array $left,array $right): array {
		$leftExecutable=$this->statRange($left,'executable_ranges','executable_lines');
		$rightExecutable=$this->statRange($right,'executable_ranges','executable_lines');
		$executable=$this->mergeRanges($leftExecutable,$rightExecutable);
		$covered=$this->mergeRanges(
			$this->statRange($left,'covered_ranges','covered_lines'),
			$this->statRange($right,'covered_ranges','covered_lines'),
		);
		$ignored=$this->mergeRanges(
			$this->statRange($left,'ignored_ranges','ignored_lines'),
			$this->statRange($right,'ignored_ranges','ignored_lines'),
		);
		$leftRaw=$this->hasStatRange($left,'raw_executable_ranges','raw_executable_lines')
			? $this->statRange($left,'raw_executable_ranges','raw_executable_lines')
			: $leftExecutable;
		$rightRaw=$this->hasStatRange($right,'raw_executable_ranges','raw_executable_lines')
			? $this->statRange($right,'raw_executable_ranges','raw_executable_lines')
			: $rightExecutable;
		$raw=$this->mergeRanges($leftRaw,$rightRaw);
		$reasons=$this->reasonRanges($left);
		foreach($this->reasonRanges($right) as $reason=>$ranges){
			$reasons[$reason]=$this->mergeRanges($reasons[$reason] ?? '',$ranges);
		}
		ksort($reasons,SORT_STRING);
		return [
			'raw_executable'=>$this->rangeCount($raw),
			'executable'=>$this->rangeCount($executable),
			'covered'=>$this->rangeCount($covered),
			'ignored'=>$this->rangeCount($ignored),
			'raw_executable_ranges'=>$raw,
			'executable_ranges'=>$executable,
			'covered_ranges'=>$covered,
			'ignored_ranges'=>$ignored,
			'ignored_reasons'=>$reasons,
		];
	}

	private function hasStatRange(array $stats,string $rangeKey,string $linesKey): bool {
		return is_string($stats[$rangeKey] ?? null) || is_array($stats[$linesKey] ?? null);
	}

	private function statRange(array $stats,string $rangeKey,string $linesKey): string {
		if(is_string($stats[$rangeKey] ?? null)){return $this->mergeRanges($stats[$rangeKey],'');}
		$lines=is_array($stats[$linesKey] ?? null) ? array_values(array_filter(array_map('intval',$stats[$linesKey]),static fn(int $line): bool=>$line>0)) : [];
		return $this->ranges($lines);
	}

	/** @return array<string,string> */
	private function reasonRanges(array $stats): array {
		$source=is_array($stats['ignored_reasons'] ?? null)
			? $stats['ignored_reasons']
			: (is_array($stats['ignored_by_reason'] ?? null) ? $stats['ignored_by_reason'] : []);
		$reasons=[];
		foreach($source as $reason=>$ranges){
			if(is_string($ranges)){$reasons[(string)$reason]=$this->mergeRanges($ranges,'');continue;}
			if(is_array($ranges)){$reasons[(string)$reason]=$this->ranges(array_values(array_filter(array_map('intval',$ranges),static fn(int $line): bool=>$line>0)));continue;}
			throw new \UnexpectedValueException('Covered subprocess returned invalid ignored-line ranges for '.$reason.'.');
		}
		return $reasons;
	}

	private function mergeRanges(string $left,string $right): string {
		$intervals=[...$this->intervals($left),...$this->intervals($right)];
		if($intervals===[]){return '';}
		usort($intervals,static fn(array $a,array $b): int=>$a[0]<=>$b[0] ?: $a[1]<=>$b[1]);
		$merged=[];
		foreach($intervals as [$start,$end]){
			$last=array_key_last($merged);
			if($last!==null && $start<=$merged[$last][1]+1){$merged[$last][1]=max($merged[$last][1],$end);continue;}
			$merged[]=[$start,$end];
		}
		return implode(',',array_map(static fn(array $range): string=>$range[0]===$range[1] ? (string)$range[0] : $range[0].'-'.$range[1],$merged));
	}

	/** @return list<array{0:int,1:int}> */
	private function intervals(string $ranges): array {
		$intervals=[];
		foreach(array_filter(explode(',',trim($ranges)),static fn(string $part): bool=>trim($part)!=='') as $part){
			$part=trim($part);
			if(preg_match('/^(\d+)(?:-(\d+))?$/',$part,$matches)!==1){throw new \UnexpectedValueException('Covered subprocess returned an invalid line range: '.$part);}
			$start=(int)$matches[1];$end=isset($matches[2]) && $matches[2]!=='' ? (int)$matches[2] : $start;
			if($start<1 || $end<$start){throw new \UnexpectedValueException('Covered subprocess returned an invalid line range: '.$part);}
			$intervals[]=[$start,$end];
		}
		return $intervals;
	}

	private function rangeCount(string $ranges): int {
		$count=0;
		foreach($this->intervals($ranges) as [$start,$end]){$count+=$end-$start+1;}
		return $count;
	}

	/** @param list<int> $lines */
	private function ranges(array $lines): string {
		if($lines===[]){return '';}
		$lines=array_values(array_unique(array_map('intval',$lines)));sort($lines,SORT_NUMERIC);
		$ranges=[];$start=$lines[0];$end=$start;
		foreach(array_slice($lines,1) as $line){
			if($line===$end+1){$end=$line;continue;}
			$ranges[]=$start===$end ? (string)$start : $start.'-'.$end;
			$start=$end=$line;
		}
		$ranges[]=$start===$end ? (string)$start : $start.'-'.$end;
		return implode(',',$ranges);
	}
}
