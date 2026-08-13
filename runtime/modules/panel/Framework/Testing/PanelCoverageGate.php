<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Validates an exact-line coverage report against the complete Panel runtime.
 *
 * A raw coverage percentage only describes files that the engine happened to
 * compile. This gate also inventories the production source tree, so an
 * entirely untested file cannot disappear from the denominator.
 */
final class PanelCoverageGate implements \JsonSerializable {
	private const EXACT_ENGINES=['xdebug', 'phpdbg'];
	private string $moduleRoot;
	private array $engines;
	private array $sourceFiles;
	private array $lineFiles;
	private array $missingFiles;
	private array $uncoveredFiles;
	private array $failures;
	private int $coveredLines;
	private int $executableLines;
	private float $minimumPercent;

	private function __construct(array $report, string $moduleRoot, array $options=[]) {
		$resolvedRoot=realpath($moduleRoot);
		if(!is_string($resolvedRoot) || !is_dir($resolvedRoot)){
			throw new \InvalidArgumentException('Panel coverage module root is missing or unreadable.');
		}
		$this->moduleRoot=$this->normalizePath($resolvedRoot);
		$this->minimumPercent=max(0.0, min(100.0, (float)($options['minimum_percent'] ?? 100.0)));
		$this->engines=$this->normalizeEngines($report['engines'] ?? $report['engine'] ?? []);
		$this->sourceFiles=$this->discoverSourceFiles($options['source_directories'] ?? ['Framework', 'kernel']);
		$this->lineFiles=$this->normalizeLineFiles($report['line_files'] ?? []);
		$this->missingFiles=[];
		$this->uncoveredFiles=[];
		$this->coveredLines=0;
		$this->executableLines=0;

		foreach($this->sourceFiles as $relative){
			$key=$this->pathKey($relative);
			if(!array_key_exists($key, $this->lineFiles)){
				$this->missingFiles[]=$relative;
				continue;
			}
			$stats=$this->lineFiles[$key];
			$this->coveredLines+=(int)$stats['covered'];
			$this->executableLines+=(int)$stats['executable'];
			if((int)$stats['covered']<(int)$stats['executable']){
				$this->uncoveredFiles[$relative]=[
					'executable'=>(int)$stats['executable'],
					'covered'=>(int)$stats['covered'],
					'uncovered_lines'=>$stats['uncovered_lines'],
				];
			}
		}
		$this->failures=$this->buildFailures($options);
	}

	public static function fromFile(string $coveragePath, ?string $moduleRoot=null, array $options=[]): self {
		if(!is_file($coveragePath) || !is_readable($coveragePath)){
			throw new \InvalidArgumentException('Panel coverage report is missing or unreadable.');
		}
		try{
			$report=json_decode((string)file_get_contents($coveragePath), true, 512, JSON_THROW_ON_ERROR);
		}catch(\JsonException $exception){
			throw new \InvalidArgumentException('Panel coverage report contains invalid JSON.', 0, $exception);
		}
		if(!is_array($report)){
			throw new \InvalidArgumentException('Panel coverage report must contain a JSON object.');
		}
		return new self($report, $moduleRoot ?? dirname(__DIR__, 2), $options);
	}

	public static function fromReport(array $report, ?string $moduleRoot=null, array $options=[]): self {
		return new self($report, $moduleRoot ?? dirname(__DIR__, 2), $options);
	}

	public function passed(): bool { return $this->failures===[]; }
	public function failures(): array { return $this->failures; }
	public function missingFiles(): array { return $this->missingFiles; }
	public function uncoveredFiles(): array { return $this->uncoveredFiles; }
	public function sourceFileCount(): int { return count($this->sourceFiles); }
	public function coveredLines(): int { return $this->coveredLines; }
	public function executableLines(): int { return $this->executableLines; }
	public function coveragePercent(): ?float {
		return $this->executableLines>0 ? round(($this->coveredLines/$this->executableLines)*100, 2) : null;
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_coverage_gate',
			'passed'=>$this->passed(),
			'module_root'=>$this->moduleRoot,
			'engines'=>$this->engines,
			'source_file_count'=>$this->sourceFileCount(),
			'reported_source_file_count'=>$this->sourceFileCount()-count($this->missingFiles),
			'missing_source_file_count'=>count($this->missingFiles),
			'executable_lines'=>$this->executableLines,
			'covered_lines'=>$this->coveredLines,
			'coverage_percent'=>$this->coveragePercent(),
			'minimum_percent'=>$this->minimumPercent,
			'missing_files'=>$this->missingFiles,
			'uncovered_files'=>$this->uncoveredFiles,
			'failures'=>$this->failures,
		];
	}

	private function normalizeEngines(mixed $engines): array {
		$engines=is_array($engines) ? $engines : [$engines];
		$normalized=[];
		foreach($engines as $engine){
			$engine=strtolower(trim((string)$engine));
			if($engine!==''){$normalized[$engine]=true;}
		}
		return array_keys($normalized);
	}

	private function discoverSourceFiles(mixed $directories): array {
		$directories=is_array($directories) ? $directories : [$directories];
		$files=[];
		foreach($directories as $directory){
			$relativeDirectory=trim($this->normalizePath((string)$directory), '/');
			if($relativeDirectory==='' || str_contains('/'.$relativeDirectory.'/', '/../')){
				throw new \InvalidArgumentException('Panel coverage source directories must remain inside the module root.');
			}
			$absolute=$this->moduleRoot.'/'.$relativeDirectory;
			$resolved=realpath($absolute);
			if(!is_string($resolved) || !is_dir($resolved) || !$this->pathInsideModule($resolved)){
				throw new \InvalidArgumentException('Panel coverage source directory is missing or outside the module root: '.$relativeDirectory);
			}
			$iterator=new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			/** @var \SplFileInfo $file */
			foreach($iterator as $file){
				$path=$this->normalizePath($file->getRealPath() ?: $file->getPathname());
				$this->assertSourceEntry($path, $file->isLink());
				if(!$file->isFile() || strtolower($file->getExtension())!=='php'){
					continue;
				}
				$files[substr($path, strlen($this->moduleRoot)+1)]=true;
			}
		}
		$files=array_keys($files);
		sort($files, SORT_STRING);
		return $files;
	}

	private function normalizeLineFiles(mixed $lineFiles): array {
		if(!is_array($lineFiles)){
			throw new \InvalidArgumentException('Panel coverage report line_files must be an object.');
		}
		$normalized=[];
		foreach($lineFiles as $file=>$stats){
			if(!is_array($stats)){
				continue;
			}
			$relative=$this->coverageRelativePath((string)$file);
			if($relative===null){
				continue;
			}
			$executable=max(0, (int)($stats['executable'] ?? 0));
			$covered=max(0, min($executable, (int)($stats['covered'] ?? 0)));
			$uncovered=[];
			foreach(is_array($stats['uncovered_lines'] ?? null) ? $stats['uncovered_lines'] : [] as $line){
				$line=(int)$line;
				if($line>0){$uncovered[$line]=true;}
			}
			$uncovered=array_keys($uncovered);
			sort($uncovered, SORT_NUMERIC);
			$key=$this->pathKey($relative);
			$current=$normalized[$key] ?? null;
			if($current===null){
				$normalized[$key]=[
					'relative'=>$relative,
					'executable'=>$executable,
					'covered'=>$covered,
					'uncovered_lines'=>$uncovered,
				];
				continue;
			}
			$mergedUncovered=array_values(array_unique(array_merge((array)$current['uncovered_lines'], $uncovered)));
			sort($mergedUncovered, SORT_NUMERIC);
			$mergedExecutable=max((int)$current['executable'], $executable);
			$normalized[$key]=[
				'relative'=>(string)$current['relative'],
				'executable'=>$mergedExecutable,
				// Duplicate physical paths must never improve coverage. A stale mirror,
				// junction, or conflicting report therefore fails conservatively.
				'covered'=>min($mergedExecutable, (int)$current['covered'], $covered),
				'uncovered_lines'=>$mergedUncovered,
			];
		}
		return $normalized;
	}

	private function coverageRelativePath(string $file): ?string {
		$file=$this->normalizePath($file);
		$modulePrefix=$this->moduleRoot.'/';
		if($this->pathStartsWith($file, $modulePrefix)){
			return substr($file, strlen($modulePrefix));
		}
		if(preg_match('#(?:^|/)runtime/modules/panel/(.+)$#i', $file, $matches)===1){
			return ltrim((string)$matches[1], '/');
		}
		if(preg_match('#^(Framework|kernel)/.+\.php$#i', $file)===1){
			return ltrim($file, '/');
		}
		return null;
	}

	private function buildFailures(array $options): array {
		$failures=[];
		$requireEngine=strtolower(trim((string)($options['require_engine'] ?? 'exact')));
		if($requireEngine==='exact'){
			if(array_intersect(self::EXACT_ENGINES, $this->engines)===[]){
				$failures[]=['name'=>'exact_engine_missing', 'required'=>self::EXACT_ENGINES, 'actual'=>$this->engines];
			}
		}elseif($requireEngine!=='' && !in_array($requireEngine, $this->engines, true)){
			$failures[]=['name'=>'required_engine_missing', 'required'=>$requireEngine, 'actual'=>$this->engines];
		}
		if(($options['require_all_sources'] ?? true)===true && $this->missingFiles!==[]){
			$failures[]=['name'=>'source_files_missing', 'count'=>count($this->missingFiles)];
		}
		if($this->uncoveredFiles!==[]){
			$failures[]=['name'=>'uncovered_lines', 'file_count'=>count($this->uncoveredFiles)];
		}
		$percent=$this->coveragePercent();
		if($this->minimumPercent>0.0 && ($percent===null || $percent<$this->minimumPercent)){
			$failures[]=['name'=>'coverage_below_minimum', 'minimum'=>$this->minimumPercent, 'actual'=>$percent];
		}
		return $failures;
	}

	private function pathInsideModule(string $path): bool {
		$path=$this->normalizePath($path);
		return $this->pathStartsWith($path.'/', $this->moduleRoot.'/');
	}

	private function assertSourceEntry(string $path, bool $linked=false): void {
		if($linked){
			throw new \UnexpectedValueException('Panel coverage source trees cannot contain symbolic links.');
		}
		if(!$this->pathInsideModule($path)){
			throw new \UnexpectedValueException('Panel coverage source traversal escaped the module root.');
		}
	}

	private function pathStartsWith(string $path, string $prefix): bool {
		return self::usesWindowsSemantics($path) || self::usesWindowsSemantics($prefix)
			? str_starts_with(strtolower($path), strtolower($prefix))
			: str_starts_with($path, $prefix);
	}

	private function pathKey(string $path): string {
		$path=$this->normalizePath($path);
		return self::usesWindowsSemantics($path) ? strtolower($path) : $path;
	}

	private static function usesWindowsSemantics(string $path): bool { return preg_match('/^[A-Za-z]:[\\\\\/]/', trim($path))===1 || str_starts_with(trim($path), '\\\\'); }

	private function normalizePath(string $path): string {
		return rtrim(str_replace('\\', '/', trim($path)), '/');
	}
}
