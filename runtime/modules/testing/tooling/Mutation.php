<?php
declare(strict_types=1);

/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 *
 * Runtime-inert mutation testing primitives. Nothing executes when this file
 * is loaded; the CLI adapter calls MutationCli::main() explicitly.
 */

namespace Dataphyre\Test\Mutation;

require_once __DIR__.'/PhpRuntime.php';

use Dataphyre\Test\PhpRuntime;
use InvalidArgumentException;
use JsonSerializable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class MutationCandidate implements JsonSerializable {

	public function __construct(
		public readonly string $id,
		public readonly string $file,
		public readonly int $line,
		public readonly int $offset,
		public readonly string $operator,
		public readonly string $description,
		public readonly string $original,
		public readonly string $replacement,
	) {}

	public function apply(string $source): string {
		$observed=substr($source, $this->offset, strlen($this->original));
		if($observed!==$this->original){
			throw new RuntimeException('Mutation source drifted for '.$this->id.'.');
		}
		return substr($source, 0, $this->offset).$this->replacement.substr($source, $this->offset+strlen($this->original));
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'id'=>$this->id,
			'file'=>$this->file,
			'line'=>$this->line,
			'operator'=>$this->operator,
			'description'=>$this->description,
			'original'=>$this->original,
			'replacement'=>$this->replacement,
		];
	}
}

/**
 * First-party operators deliberately use PHP's tokenizer rather than regexes.
 * This keeps comments and string literals outside the mutation surface.
 */
final class MutationCatalog {

	/** @return array<string,array{description:string,risk:string}> */
	public static function operators(): array {
		return [
			'strict_identity'=>['description'=>'Invert strict identity and non-identity comparisons.','risk'=>'contract'],
			'equality'=>['description'=>'Invert loose equality and non-equality comparisons.','risk'=>'contract'],
			'boundary'=>['description'=>'Remove inclusivity from greater/less boundary comparisons.','risk'=>'boundary'],
			'logical_connective'=>['description'=>'Swap logical conjunction and disjunction.','risk'=>'branch'],
			'boolean_literal'=>['description'=>'Invert boolean literals outside strings and comments.','risk'=>'branch'],
			'null_coalescing'=>['description'=>'Replace null coalescing with truthy fallback semantics.','risk'=>'fallback'],
		];
	}

	/** @return array<string,array<int,string>> */
	public static function profiles(): array {
		return [
			'core'=>array_keys(self::operators()),
			'http-contract'=>['strict_identity','equality','boolean_literal','null_coalescing'],
			'route-contract'=>['strict_identity','equality','boundary','logical_connective','null_coalescing'],
			'permission-contract'=>['strict_identity','equality','logical_connective','boolean_literal'],
			'data-contract'=>['strict_identity','equality','boundary','null_coalescing'],
		];
	}

	/** @return ?array{operator:string,description:string,replacement:string} */
	public static function replacement(int $token_id, string $text): ?array {
		$operator=null;
		$replacement=null;
		switch($token_id){
			case T_IS_IDENTICAL:
				$operator='strict_identity'; $replacement='!=='; break;
			case T_IS_NOT_IDENTICAL:
				$operator='strict_identity'; $replacement='==='; break;
			case T_IS_EQUAL:
				$operator='equality'; $replacement='!='; break;
			case T_IS_NOT_EQUAL:
				$operator='equality'; $replacement='=='; break;
			case T_IS_GREATER_OR_EQUAL:
				$operator='boundary'; $replacement='>'; break;
			case T_IS_SMALLER_OR_EQUAL:
				$operator='boundary'; $replacement='<'; break;
			case T_BOOLEAN_AND:
				$operator='logical_connective'; $replacement='||'; break;
			case T_BOOLEAN_OR:
				$operator='logical_connective'; $replacement='&&'; break;
			case T_LOGICAL_AND:
				$operator='logical_connective'; $replacement='or'; break;
			case T_LOGICAL_OR:
				$operator='logical_connective'; $replacement='and'; break;
			case T_COALESCE:
				$operator='null_coalescing'; $replacement='?:'; break;
			case T_STRING:
				$lower=strtolower($text);
				if($lower==='true'||$lower==='false'){
					$operator='boolean_literal';
					$replacement=$lower==='true' ? 'false' : 'true';
					if(strtoupper($text)===$text){$replacement=strtoupper($replacement);}
					elseif(ucfirst($lower)===$text){$replacement=ucfirst($replacement);}
				}
				break;
		}
		if($operator===null||$replacement===null){return null;}
		$definition=self::operators()[$operator];
		return ['operator'=>$operator,'description'=>$definition['description'],'replacement'=>$replacement];
	}
}

final class MutationPlanner {

	public function __construct(private readonly string $root) {
		if(!is_dir($root)){throw new InvalidArgumentException('Mutation root does not exist: '.$root);}
	}

	/**
	 * @param array<int,string> $paths
	 * @param array<int,string> $operators
	 * @return array<int,MutationCandidate>
	 */
	public function plan(array $paths=[], array $operators=[], int $limit=0): array {
		if($operators===[]){$operators=MutationCatalog::profiles()['core'];}
		$unknown=array_diff($operators, array_keys(MutationCatalog::operators()));
		if($unknown!==[]){throw new InvalidArgumentException('Unknown mutation operator: '.implode(', ', $unknown));}
		$allowed=array_fill_keys($operators, true);
		$candidates=[];
		foreach($this->files($paths) as $absolute=>$relative){
			$source=file_get_contents($absolute);
			if(!is_string($source)){throw new RuntimeException('Unable to read mutation source: '.$relative);}
			foreach($this->candidatesForSource($relative, $source) as $candidate){
				if(!isset($allowed[$candidate->operator])){continue;}
				$candidates[]=$candidate;
				if($limit>0&&count($candidates)>=$limit){return $candidates;}
			}
		}
		return $candidates;
	}

	/** @return array<int,MutationCandidate> */
	public function candidatesForSource(string $relative_file, string $source): array {
		try{$tokens=token_get_all($source, TOKEN_PARSE);}
		catch(Throwable $exception){throw new RuntimeException('Unable to tokenize '.$relative_file.': '.$exception->getMessage(), 0, $exception);}
		$candidates=[];
		$offset=0;
		$line=1;
		foreach($tokens as $token){
			if(is_array($token)){
				[$token_id,$text,$line]=$token;
				$mutation=MutationCatalog::replacement($token_id, $text);
				if($mutation!==null){
					$identity=$relative_file.':'.$line.':'.$offset.':'.$mutation['operator'].':'.$text.':'.$mutation['replacement'];
					$candidates[]=new MutationCandidate(
						'm-'.substr(hash('sha256', $identity), 0, 16),
						$relative_file,
						$line,
						$offset,
						$mutation['operator'],
						$mutation['description'],
						$text,
						$mutation['replacement'],
					);
				}
			}
			else{$text=$token;}
			$offset+=strlen($text);
		}
		return $candidates;
	}

	/** @param array<int,string> $paths @return array<string,string> */
	private function files(array $paths): array {
		if($paths===[]){$paths=['runtime/modules'];}
		$root=$this->normalizedRoot();
		$files=[];
		foreach($paths as $path){
			$path=trim(str_replace('\\', '/', $path));
			if($path===''||str_contains($path, "\0")){throw new InvalidArgumentException('Mutation path cannot be empty or contain null bytes.');}
			$absolute=$this->resolveInsideRoot($root, $path);
			if(is_file($absolute)){
				$this->addFile($files, $root, $absolute);
				continue;
			}
			if(!is_dir($absolute)){throw new InvalidArgumentException('Mutation path does not exist: '.$path);}
			$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS));
			foreach($iterator as $entry){
				if($entry->isFile()){$this->addFile($files, $root, $entry->getPathname());}
			}
		}
		ksort($files, SORT_STRING);
		return $files;
	}

	/** @param array<string,string> $files */
	private function addFile(array &$files, string $root, string $absolute): void {
		$absolute=str_replace('\\', '/', $absolute);
		$relative=ltrim(substr($absolute, strlen($root)), '/');
		$normalized='/'.$relative;
		if(!str_ends_with(strtolower($relative), '.php')){return;}
		if(str_ends_with(strtolower($relative), '.test.php')){return;}
		if(preg_match('~/(?:unit_tests|testing|vendor|cache|\.git|\.codex-tmp)(?:/|$)~i', $normalized)===1){return;}
		$files[$absolute]=$relative;
	}

	private function normalizedRoot(): string {
		$root=realpath($this->root);
		if(!is_string($root)){throw new RuntimeException('Unable to resolve mutation root.');}
		return rtrim(str_replace('\\', '/', $root), '/');
	}

	private function resolveInsideRoot(string $root, string $path): string {
		if(preg_match('~^(?:[A-Za-z]:)?/~', $path)===1){$candidate=$path;}
		else{$candidate=$root.'/'.$path;}
		$resolved=realpath($candidate);
		if(!is_string($resolved)){return str_replace('\\', '/', $candidate);}
		$resolved=str_replace('\\', '/', $resolved);
		if($resolved!==$root&&!str_starts_with($resolved, $root.'/')){
			throw new InvalidArgumentException('Mutation path escapes the repository root: '.$path);
		}
		return $resolved;
	}
}

final class MutationJournal {

	public function __construct(private readonly string $path, private readonly string $root) {
		if(!is_dir($root)){throw new InvalidArgumentException('Mutation journal root does not exist: '.$root);}
	}

	public function arm(string $absolute_file, string $original, string $mutant): void {
		$absolute_file=$this->sourcePath($absolute_file);
		$directory=dirname($this->path);
		if(!is_dir($directory)&&!mkdir($directory, 0775, true)&&!is_dir($directory)){
			throw new RuntimeException('Unable to create mutation journal directory.');
		}
		$payload=[
			'format'=>'dataphyre-mutation-recovery-v1',
			'file'=>str_replace('\\', '/', $absolute_file),
			'original_sha256'=>hash('sha256', $original),
			'mutant_sha256'=>hash('sha256', $mutant),
			'original_base64'=>base64_encode($original),
		];
		$json=json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
		if(file_put_contents($this->path, $json, LOCK_EX)===false){throw new RuntimeException('Unable to write mutation recovery journal.');}
	}

	public function clear(): void {
		if(is_file($this->path)&&!unlink($this->path)){throw new RuntimeException('Unable to clear mutation recovery journal.');}
	}

	public function pending(): bool {return is_file($this->path);}

	public function recover(): ?string {
		if(!$this->pending()){return null;}
		$decoded=json_decode((string)file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
		if(!is_array($decoded)||($decoded['format'] ?? '')!=='dataphyre-mutation-recovery-v1'){
			throw new RuntimeException('Mutation recovery journal has an unsupported format.');
		}
		$file=(string)($decoded['file'] ?? '');
		$file=$this->sourcePath($file);
		$original=base64_decode((string)($decoded['original_base64'] ?? ''), true);
		if($file===''||!is_string($original)||hash('sha256', $original)!==($decoded['original_sha256'] ?? '')){
			throw new RuntimeException('Mutation recovery journal failed integrity validation.');
		}
		$current=is_file($file) ? file_get_contents($file) : false;
		if(is_string($current)&&hash('sha256', $current)!==($decoded['mutant_sha256'] ?? '')&&hash('sha256', $current)!==($decoded['original_sha256'] ?? '')){
			throw new RuntimeException('Refusing recovery because the mutated file changed after the journal was armed: '.$file);
		}
		if(file_put_contents($file, $original, LOCK_EX)===false){throw new RuntimeException('Unable to restore mutation source: '.$file);}
		$this->clear();
		return $file;
	}

	private function sourcePath(string $file): string {
		$root=realpath($this->root);
		$resolved=realpath($file);
		if(!is_string($root)||!is_string($resolved)){
			throw new RuntimeException('Mutation journal source path is missing or unreadable: '.$file);
		}
		$root=rtrim(str_replace('\\', '/', $root), '/');
		$resolved=str_replace('\\', '/', $resolved);
		if($resolved!==$root&&!str_starts_with($resolved, $root.'/')){
			throw new RuntimeException('Mutation journal source escapes its repository root: '.$resolved);
		}
		return $resolved;
	}
}

final class MutationProcess {

	/** @param array<int,string> $command @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,duration_ms:float} */
	public static function run(array $command, string $cwd, int $timeout_seconds): array {
		$started=microtime(true);
		$process=proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd, null, ['bypass_shell'=>true]);
		if(!is_resource($process)){throw new RuntimeException('Unable to start mutation subprocess.');}
		foreach($pipes as $pipe){stream_set_blocking($pipe, false);}
		$stdout=''; $stderr=''; $timed_out=false; $last_status=null;
		while(true){
			$status=proc_get_status($process);
			$last_status=$status;
			$stdout.=stream_get_contents($pipes[1]);
			$stderr.=stream_get_contents($pipes[2]);
			if(!$status['running']){break;}
			if((microtime(true)-$started)>$timeout_seconds){$timed_out=true; proc_terminate($process); break;}
			usleep(10000);
		}
		$stdout.=stream_get_contents($pipes[1]); $stderr.=stream_get_contents($pipes[2]);
		foreach($pipes as $pipe){fclose($pipe);}
		$closed=proc_close($process);
		$exit_code=$timed_out ? 124 : (int)(is_array($last_status)&&($last_status['exitcode'] ?? -1)>=0 ? $last_status['exitcode'] : $closed);
		return ['exit_code'=>$exit_code,'stdout'=>$stdout,'stderr'=>$stderr,'timed_out'=>$timed_out,'duration_ms'=>(microtime(true)-$started)*1000];
	}
}

final class MutationRunner {

	private const SYNTAX_CHECK_TIMEOUT_SECONDS=30;
	private MutationJournal $journal;
	private readonly string $php_binary;

	public function __construct(
		private readonly string $root,
		?string $php_binary=null,
		?string $journal_path=null,
	) {
		$this->php_binary=PhpRuntime::binary($php_binary);
		$this->journal=new MutationJournal($journal_path ?? rtrim($root, '/\\').'/cache/mutation/recovery.json', $root);
	}

	/**
	 * @param array<int,MutationCandidate> $candidates
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	public function run(array $candidates, array $options=[]): array {
		$lock=$this->acquireLock();
		try{
		$recovered=$this->journal->recover();
		$timeout=max(1, (int)($options['timeout'] ?? 120));
		$baseline=(bool)($options['baseline'] ?? true);
		$results=[];
		$started=microtime(true);
		if($baseline&&$candidates!==[]){
			$commands=[];
			foreach($candidates as $candidate){
				$command=$this->testCommand($candidate, $options);
				$commands[hash('sha256', json_encode($command, JSON_THROW_ON_ERROR))]=$command;
			}
			foreach($commands as $command){
				$baseline_result=MutationProcess::run($command, $this->root, $timeout);
				if($baseline_result['exit_code']!==0){
					throw new RuntimeException('Mutation baseline is not green. Fix the selected tests before measuring mutants.\n'.$baseline_result['stderr'].$baseline_result['stdout']);
				}
			}
		}
		foreach($candidates as $candidate){$results[]=$this->runCandidate($candidate, $options, $timeout);}
		$counts=['killed'=>0,'survived'=>0,'timeout'=>0,'invalid'=>0,'error'=>0];
		foreach($results as $result){$counts[$result['status']]++;}
		$measured=$counts['killed']+$counts['survived'];
		return [
			'format'=>'dataphyre-mutation-report-v1',
			'recovered_file'=>$recovered,
			'total'=>count($results),
			'counts'=>$counts,
			'mutation_score'=>$measured>0 ? round(($counts['killed']/$measured)*100, 2) : null,
			'duration_ms'=>(microtime(true)-$started)*1000,
			'results'=>$results,
		];
		}
		finally{flock($lock, LOCK_UN); fclose($lock);}
	}

	/** @return resource */
	private function acquireLock() {
		$path=rtrim($this->root, '/\\').'/cache/mutation/runner.lock';
		$directory=dirname($path);
		if(!is_dir($directory)&&!mkdir($directory, 0775, true)&&!is_dir($directory)){
			throw new RuntimeException('Unable to create mutation lock directory.');
		}
		$lock=fopen($path, 'c+');
		if(!is_resource($lock)||!flock($lock, LOCK_EX|LOCK_NB)){
			if(is_resource($lock)){fclose($lock);}
			throw new RuntimeException('Another mutation run already owns this repository.');
		}
		return $lock;
	}

	/** @param array<string,mixed> $options @return array<string,mixed> */
	private function runCandidate(MutationCandidate $candidate, array $options, int $timeout): array {
		$absolute=rtrim(str_replace('\\', '/', $this->root), '/').'/'.$candidate->file;
		$original=file_get_contents($absolute);
		if(!is_string($original)){return $this->result($candidate, 'error', 0.0, 'Unable to read source file.');}
		try{$mutant=$candidate->apply($original);}
		catch(Throwable $exception){return $this->result($candidate, 'error', 0.0, $exception->getMessage());}
		$this->journal->arm($absolute, $original, $mutant);
		if(file_put_contents($absolute, $mutant, LOCK_EX)===false){$this->journal->recover(); return $this->result($candidate, 'error', 0.0, 'Unable to write mutant.');}
		try{
			$lint=MutationProcess::run([$this->php_binary,'-l',$absolute], $this->root, self::SYNTAX_CHECK_TIMEOUT_SECONDS);
			if($lint['exit_code']!==0){return $this->result($candidate, 'invalid', $lint['duration_ms'], trim($lint['stderr'].$lint['stdout']));}
			$process=MutationProcess::run($this->testCommand($candidate, $options), $this->root, $timeout);
			$status=$process['timed_out'] ? 'timeout' : ($process['exit_code']===0 ? 'survived' : 'killed');
			return $this->result($candidate, $status, $process['duration_ms'], trim($process['stderr']), $process['exit_code']);
		}
		catch(Throwable $exception){return $this->result($candidate, 'error', 0.0, $exception->getMessage());}
		finally{$this->journal->recover();}
	}

	/** @param array<string,mixed> $options @return array<int,string> */
	private function testCommand(MutationCandidate $candidate, array $options): array {
		if(isset($options['command'])){
			$command=$options['command'];
			if(!is_array($command)||$command===[]){throw new InvalidArgumentException('Mutation test command must be a non-empty argument array.');}
			return array_values(array_map('strval', $command));
		}
		$command=[$this->php_binary, rtrim($this->root, '/\\').'/bin/dataphyre-test', 'run', '--no-test-cache', '--json'];
		if(preg_match('~^runtime/modules/([^/]+)/~', $candidate->file, $match)===1){$command[]='--owner='.$match[1];}
		else{$command[]='--scope=all';}
		if(isset($options['test_name'])&&trim((string)$options['test_name'])!==''){$command[]='--name='.(string)$options['test_name'];}
		return $command;
	}

	/** @return array<string,mixed> */
	private function result(MutationCandidate $candidate, string $status, float $duration_ms, string $diagnostic='', ?int $exit_code=null): array {
		return ['mutant'=>$candidate->jsonSerialize(),'status'=>$status,'duration_ms'=>$duration_ms,'exit_code'=>$exit_code,'diagnostic'=>$diagnostic];
	}
}

final class MutationCli {

	/** @param array<int,string> $argv */
	public static function main(array $argv, string $root): int {
		$arguments=$argv; array_shift($arguments);
		$command=$arguments[0] ?? 'help';
		if(!str_starts_with($command, '-')){array_shift($arguments);}else{$command='run';}
		try{
			$options=self::options($arguments);
			if(isset($options['help'])||$command==='help'){self::help(); return 0;}
			if($command==='operators'){self::emit(['operators'=>MutationCatalog::operators(),'profiles'=>MutationCatalog::profiles()], $options); return 0;}
			if($command==='recover'){
				$journal=new MutationJournal(rtrim($root, '/\\').'/cache/mutation/recovery.json', $root);
				self::emit(['recovered_file'=>$journal->recover()], $options);
				return 0;
			}
			$planner=new MutationPlanner($root);
			$operators=self::csv($options['operator'] ?? '');
			if(isset($options['profile'])){
				$profile=(string)$options['profile'];
				$profiles=MutationCatalog::profiles();
				if(!isset($profiles[$profile])){throw new InvalidArgumentException('Unknown mutation profile: '.$profile);}
				$operators=array_values(array_unique(array_merge($operators, $profiles[$profile])));
			}
			$paths=self::many($options['path'] ?? []);
			$candidates=$planner->plan($paths, $operators, max(0, (int)($options['limit'] ?? 0)));
			if($command==='plan'||isset($options['dry-run'])){
				self::emit(['format'=>'dataphyre-mutation-plan-v1','total'=>count($candidates),'mutants'=>$candidates], $options);
				return 0;
			}
			if($command!=='run'){throw new InvalidArgumentException('Unknown mutation command: '.$command);}
			$runner=new MutationRunner($root);
			$report=$runner->run($candidates, [
				'timeout'=>(int)($options['timeout'] ?? 120),
				'baseline'=>!isset($options['skip-baseline']),
				'test_name'=>$options['test-name'] ?? null,
			]);
			self::emit($report, $options);
			return (($report['counts']['survived'] ?? 0)+($report['counts']['timeout'] ?? 0)+($report['counts']['error'] ?? 0))===0 ? 0 : 1;
		}
		catch(Throwable $exception){fwrite(STDERR, $exception->getMessage()."\n"); return 2;}
	}

	/** @param array<int,string> $arguments @return array<string,mixed> */
	private static function options(array $arguments): array {
		$options=[];
		$known=['help','path','operator','profile','limit','timeout','test-name','report','dry-run','skip-baseline','json'];
		for($index=0;$index<count($arguments);$index++){
			$argument=$arguments[$index];
			if(!str_starts_with($argument, '--')){throw new InvalidArgumentException('Unexpected mutation argument: '.$argument);}
			$argument=substr($argument, 2);
			if(str_contains($argument, '=')){[$name,$value]=explode('=', $argument, 2);}
			else{
				$name=$argument; $value=true;
				if(in_array($name, ['path','operator','profile','limit','timeout','test-name','report'], true)&&isset($arguments[$index+1])&&!str_starts_with($arguments[$index+1], '--')){$value=$arguments[++$index];}
			}
			if(!in_array($name, $known, true)){
				throw new InvalidArgumentException('Unknown mutation option: --'.$name);
			}
			if(in_array($name, ['path','operator'], true)){$options[$name][]=$value;}
			else{$options[$name]=$value;}
		}
		return $options;
	}

	/** @return array<int,string> */
	private static function many(mixed $value): array {
		if(!is_array($value)){$value=[$value];}
		$result=[];
		foreach($value as $item){foreach(self::csv((string)$item) as $part){$result[]=$part;}}
		return array_values(array_unique($result));
	}

	/** @return array<int,string> */
	private static function csv(mixed $value): array {
		if(is_array($value)){$value=implode(',', array_map('strval', $value));}
		return array_values(array_filter(array_map('trim', explode(',', (string)$value)), static fn(string $part): bool=>$part!==''));
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $options */
	private static function emit(array $payload, array $options): void {
		$json=json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
		if(isset($options['report'])){
			$path=(string)$options['report']; $directory=dirname($path);
			if(!is_dir($directory)&&!mkdir($directory, 0775, true)&&!is_dir($directory)){throw new RuntimeException('Unable to create mutation report directory.');}
			if(file_put_contents($path, $json)===false){throw new RuntimeException('Unable to write mutation report: '.$path);}
		}
		echo $json;
	}

	private static function help(): void {
		echo <<<'TEXT'
Dataphyre mutation testing

Usage:
  php bin/dataphyre-mutate plan [selection] [output]
  php bin/dataphyre-mutate run  [selection] [execution] [output]
  php bin/dataphyre-mutate operators
  php bin/dataphyre-mutate recover

Selection:
  --path=path          Source file or directory; repeat or comma-separate.
  --operator=name      Operator name; repeat or comma-separate.
  --profile=name       Named operator profile from the operators command.
  --limit=N            Stop after planning N mutants; zero means unlimited.

Execution:
  --timeout=N          Per baseline or mutant command timeout in seconds.
  --test-name=text     Narrow the selected owner suite by contract name.
  --skip-baseline      Skip the pre-mutation green-suite safety gate.
  --dry-run            Plan a run without changing source or running tests.

Output:
  --report=path        Also write the emitted JSON document to this path.
  --json               Explicit JSON-output marker; output is JSON by default.
  --help               Show this help.

Unknown options and positional arguments are rejected.
Exit codes: 0=successful command or no actionable survivors; 1=survived,
timed-out, or errored mutant; 2=invalid usage, failed baseline, or engine error.

Each mutant is syntax-checked, tested through its smallest owner suite, and
restored through a checksummed journal. Under phpdbg, child commands resolve
to the sibling ordinary PHP executable.

TEXT;
	}
}
