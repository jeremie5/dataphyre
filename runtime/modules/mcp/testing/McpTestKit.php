<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Mcp\Testing;

use Dataphyre\Test\Context;
use Dataphyre\Test\Contracts\TestContext;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\ProcessResult;
use RuntimeException;

/** PHP 8.2-compatible JSON syntax validation for test protocol envelopes. */
function mcp_test_json_validate(string $json): bool {
	if(\function_exists('json_validate')){
		return \json_validate($json);
	}
	try{
		\json_decode($json, null, 512, JSON_THROW_ON_ERROR);
		return true;
	}
	catch(\JsonException){
		return false;
	}
}

/** Parsed JSON-RPC transcript indexed by stable request id. */
final class McpTranscript {
	/** @var list<ProcessResult> */
	private array $processes;

	/** @param list<array<string,mixed>> $messages */
	public function __construct(private array $messages,ProcessResult|array $processes) {
		$this->processes=$processes instanceof ProcessResult ? [$processes] : array_values($processes);
		foreach($this->processes as $process){
			if(!$process instanceof ProcessResult){throw new \InvalidArgumentException('MCP transcript processes must be ProcessResult instances.');}
		}
	}

	/** @return list<array<string,mixed>> */
	public function messages(): array {return $this->messages;}
	public function process(): ProcessResult {
		if(count($this->processes)!==1){throw new \LogicException('A batched MCP transcript has multiple process results; use processes().');}
		return $this->processes[0];
	}
	/** @return list<ProcessResult> */
	public function processes(): array {return $this->processes;}

	/**
	 * Retains protocol evidence while releasing payload and stdout bodies that a
	 * matrix has already parsed. This keeps exhaustive contracts bounded even
	 * when one valid tool response contains a multi-megabyte generated plan.
	 */
	public function compactedForContractMatrix(): self {
		$messages=[];
		foreach($this->messages as $message){
			$text=$message['result']['content'][0]['text'] ?? null;
			if(is_string($text) && mcp_test_json_validate($text)){
				$message['result']['content'][0]['text']=json_encode([
					'_dataphyre_testkit_payload'=>[
						'valid_json'=>true,
						'bytes'=>strlen($text),
						'sha256'=>hash('sha256',$text),
					],
				],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
			}
			$messages[]=$message;
		}
		$processes=[];
		foreach($this->processes as $process){
			$processes[]=new ProcessResult(
				$process->command(),
				$process->exitCode(),
				'',
				$process->succeeded() ? '' : $process->diagnostic(1024)['stderr'],
				$process->timedOut(),
				$process->durationSeconds(),
			);
		}
		return new self($messages,$processes);
	}

	/** @return array<string,mixed> */
	public function response(string|int $id): array {
		foreach($this->messages as $message){if(($message['id'] ?? null)===$id){return $message;}}
		throw new \OutOfBoundsException('MCP response id was not returned: '.(string)$id);
	}

	/** @return array<string,mixed> */
	public function result(string|int $id): array {
		$response=$this->response($id);
		if(isset($response['error'])){throw new RuntimeException('MCP request failed: '.(string)($response['error']['message'] ?? 'unknown error'));}
		$result=$response['result'] ?? null;
		if(!is_array($result)){throw new RuntimeException('MCP response result is not an object.');}
		return $result;
	}

	/** @return array<string,mixed> */
	public function toolPayload(string|int $id): array {
		$result=$this->result($id);
		$text=$result['content'][0]['text'] ?? null;
		$decoded=is_string($text) ? json_decode($text,true,512,JSON_THROW_ON_ERROR) : null;
		if(!is_array($decoded)){throw new RuntimeException('MCP tool result did not contain a JSON object payload.');}
		return $decoded;
	}
}

/** Shell-free, coverage-aware MCP stdio scenario. */
final class McpScenario {
	private string $frameworkRoot;
	private string $workspaceRoot;
	private string $entrypoint;
	private bool $coverChildProcess=true;
	/** @var array<string,string|int|float|bool|null> */
	private array $environment=[];

	public function __construct(private TestContext $context) {
		$this->frameworkRoot=\Dataphyre\Test\dataphyre_path();
		$this->workspaceRoot=dirname($this->frameworkRoot);
		$this->entrypoint=$this->frameworkRoot.'/runtime/modules/mcp/kernel/dataphyre_mcp.php';
	}

	/** @return array{jsonrpc:string,id:int|string,method:string,params:array<string,mixed>} */
	public static function request(int|string $id,string $method,array $params=[]): array {
		return ['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params];
	}

	/** Returns an isolated scenario carrying only the requested child environment. */
	public function withEnvironment(array $environment): self {
		$clone=clone $this;
		$clone->environment=$environment;
		return $clone;
	}

	/** Returns an isolated scenario whose MCP process starts in a deliberate fixture root. */
	public function inDirectory(string $directory): self {
		$resolved=realpath($directory);
		if(!is_string($resolved) || !is_dir($resolved)){
			throw new \InvalidArgumentException('MCP scenario working directory is unavailable: '.$directory);
		}
		$clone=clone $this;
		$clone->workspaceRoot=$resolved;
		return $clone;
	}

	/**
	 * Runs a source-intensive protocol probe in ordinary PHP.
	 *
	 * Use this when the same production behavior has direct exact-line tests and
	 * debugger oplog growth would otherwise measure token-scanner iterations
	 * instead of the protocol contract being asserted here.
	 */
	public function usingOrdinaryPhpForSourceIntrospection(): self {
		$clone=clone $this;
		$clone->coverChildProcess=false;
		return $clone;
	}

	/** Encodes one Content-Length framed JSON-RPC request. */
	public static function frame(array $request,array $headers=[]): string {
		$body=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
		$lines=[];
		foreach($headers as $name=>$value){$lines[]=trim((string)$name).': '.trim((string)$value);}
		$lines[]='Content-Length: '.strlen($body);
		return implode("\r\n",$lines)."\r\n\r\n".$body;
	}

	/** @param list<array<string,mixed>> $requests */
	public function exchange(array $requests,int $timeoutMillis=120000): McpTranscript {
		$lines=[];
		foreach($requests as $request){$lines[]=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
		return $this->exchangeRaw(implode("\n",$lines)."\n",'lines',$timeoutMillis);
	}

	/** @param list<array<string,mixed>> $requests */
	public function exchangeFramed(array $requests,int $timeoutMillis=120000,array $headers=[]): McpTranscript {
		$stdin='';
		foreach($requests as $request){$stdin.=self::frame($request,$headers);}
		return $this->exchangeRaw($stdin,'headers',$timeoutMillis);
	}

	/** Runs deliberately malformed or custom-framed protocol input through one bounded server. */
	public function exchangeRaw(string $stdin,string $responseTransport='lines',int $timeoutMillis=120000): McpTranscript {
		if(!in_array($responseTransport,['lines','headers'],true)){
			throw new \InvalidArgumentException('MCP response transport must be lines or headers.');
		}
		$process=$this->coverChildProcess
			? $this->context->coveredPhpProcess(
				[$this->entrypoint],
				$stdin,
				$this->workspaceRoot,
				$this->environment,
				$timeoutMillis,
				$this->frameworkRoot
			)
			: $this->context->phpProcess(
				[$this->entrypoint],
				$stdin,
				$this->workspaceRoot,
				$this->environment,
				$timeoutMillis
			);
		if(!$process->succeeded()){
			throw new RuntimeException('MCP scenario process failed: '.$process->stderr());
		}
		$messages=$responseTransport==='headers'
			? $this->decodeHeaderResponses($process->stdout())
			: $this->decodeLineResponses($process->stdout(),$process->stderr());
		return new McpTranscript($messages,$process);
	}

	/** @return list<array<string,mixed>> */
	private function decodeLineResponses(string $stdout,string $stderr): array {
		$messages=[];
		foreach(preg_split('/\R/',trim($stdout)) ?: [] as $index=>$line){
			if(trim($line)===''){continue;}
			try{
				$message=json_decode($line,true,512,JSON_THROW_ON_ERROR);
			}catch(\JsonException $failure){
				throw new RuntimeException(
					'MCP response line '.((int)$index+1).' was not valid JSON: '.substr(trim($line),0,240).'; stderr: '.substr(trim($stderr),0,240),
					0,
					$failure
				);
			}
			if(!is_array($message)){throw new RuntimeException('MCP response line is not an object.');}
			$messages[]=$message;
		}
		return $messages;
	}

	/** @return list<array<string,mixed>> */
	private function decodeHeaderResponses(string $stdout): array {
		$messages=[];
		$offset=0;
		$total=strlen($stdout);
		while($offset<$total){
			$separator="\r\n\r\n";
			$headerEnd=strpos($stdout,$separator,$offset);
			if($headerEnd===false){
				$separator="\n\n";
				$headerEnd=strpos($stdout,$separator,$offset);
			}
			if($headerEnd===false){throw new RuntimeException('MCP header response did not terminate its header block.');}
			$headerBlock=substr($stdout,$offset,$headerEnd-$offset);
			if(preg_match('/^Content-Length:\s*([0-9]+)\s*$/mi',$headerBlock,$match)!==1){
				throw new RuntimeException('MCP header response omitted Content-Length.');
			}
			$bodyLength=(int)$match[1];
			$bodyStart=$headerEnd+strlen($separator);
			$body=substr($stdout,$bodyStart,$bodyLength);
			if(strlen($body)!==$bodyLength){throw new RuntimeException('MCP header response body was incomplete.');}
			$message=json_decode($body,true,512,JSON_THROW_ON_ERROR);
			if(!is_array($message)){throw new RuntimeException('MCP header response is not an object.');}
			$messages[]=$message;
			$offset=$bodyStart+$bodyLength;
		}
		return $messages;
	}

	/**
	 * Runs a large protocol matrix as independent bounded sessions and presents
	 * their responses as one transcript. This prevents one stateful tool or
	 * platform transport limit from hiding every contract that follows it.
	 *
	 * @param list<array<string,mixed>> $requests
	 */
	public function exchangeBatched(array $requests,int $batchSize=20,int $timeoutMillisPerBatch=120000): McpTranscript {
		if($batchSize<1){throw new \InvalidArgumentException('MCP exchange batch size must be positive.');}
		$messages=[];
		$processes=[];
		foreach(array_chunk($requests,$batchSize) as $batch){
			$transcript=$this->exchange($batch,$timeoutMillisPerBatch)->compactedForContractMatrix();
			array_push($messages,...$transcript->messages());
			array_push($processes,...$transcript->processes());
		}
		return new McpTranscript($messages,$processes);
	}

	/**
	 * Runs every request in its own coverage-carrying server lifecycle while
	 * retaining full payloads. Use this for stateful loaders and debugger-sensitive
	 * extension lifecycles where sharing one PHP process would couple independent
	 * contracts or destabilize shutdown coverage collection.
	 *
	 * @param list<array<string,mixed>> $requests
	 */
	public function exchangeSharded(array $requests,int $timeoutMillisPerRequest=120000): McpTranscript {
		$messages=[];
		$processes=[];
		foreach($requests as $request){
			$transcript=$this->exchange([$request],$timeoutMillisPerRequest);
			array_push($messages,...$transcript->messages());
			array_push($processes,...$transcript->processes());
		}
		return new McpTranscript($messages,$processes);
	}

	/** @return list<array<string,mixed>> */
	public function tools(): array {
		$transcript=$this->exchange([self::request('catalog','tools/list')]);
		$tools=$transcript->result('catalog')['tools'] ?? null;
		if(!is_array($tools)){throw new RuntimeException('MCP tool catalog is unavailable.');}
		return array_values(array_filter($tools,'is_array'));
	}
}

/** Named protocol-edge evidence shared by concise MCP transport contracts. */
final class McpProtocolBoundaryHarness {

	public function __construct(private TestContext $context) {}

	/** @return list<string> */
	public static function contractNames(): array {
		return [
			'runtime module bootstrap remains trace-only and never starts stdio',
			'line transport recovers from malformed and invalid request shapes',
			'header transport validates content length and incomplete frames',
			'protocol catalogs expose every static resource and prompt branch',
			'debug logging and response encoding fail closed',
		];
	}

	/** @return array<string,mixed> */
	public function contract(string $name): array {
		if(!in_array($name,self::contractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP protocol boundary contract: '.$name);
		}
		$evidence=match($name){
			'runtime module bootstrap remains trace-only and never starts stdio'=>$this->moduleBootstrapContract(),
			'line transport recovers from malformed and invalid request shapes'=>$this->lineTransportContract(),
			'header transport validates content length and incomplete frames'=>$this->headerTransportContract(),
			'protocol catalogs expose every static resource and prompt branch'=>$this->catalogContract(),
			'debug logging and response encoding fail closed'=>$this->debugAndEncodingContract(),
		};
		return ['contract'=>$name]+$evidence;
	}

	/** @return array<string,mixed> */
	private function moduleBootstrapContract(): array {
		$moduleRoot=dirname(__DIR__);
		$process=$this->context->coveredPhpFixture(
			$moduleRoot.'/unit_tests/fixtures/mcp_module_bootstrap_probe.php',
			[$moduleRoot.'/kernel/mcp.main.php'],
			'',
			dirname(\Dataphyre\Test\dataphyre_path()),
			[],
			10000,
			\Dataphyre\Test\dataphyre_path()
		);
		return [
			'exit_code'=>$process->exitCode(),
			'payload'=>json_decode($process->stdout(),true,512,JSON_THROW_ON_ERROR),
			'stderr'=>$process->stderr(),
		];
	}

	/** @return array<string,mixed> */
	private function lineTransportContract(): array {
		$stdin="{malformed\n[]\n";
		$stdin.=json_encode(['jsonrpc'=>'2.0','id'=>'missing-method'],JSON_THROW_ON_ERROR)."\n";
		$stdin.=json_encode(McpScenario::request('unknown-prompt','prompts/get',['name'=>'unknown_prompt']),JSON_THROW_ON_ERROR)."\n";
		$stdin.=json_encode(McpScenario::request('default-initialize','initialize'),JSON_THROW_ON_ERROR)."\n";
		$transcript=(new McpScenario($this->context))->exchangeRaw($stdin,'lines');
		$messages=$transcript->messages();
		return [
			'message_count'=>count($messages),
			'codes'=>array_map(static fn(array $message): ?int=>isset($message['error']['code']) ? (int)$message['error']['code'] : null,$messages),
			'ids'=>array_map(static fn(array $message): mixed=>$message['id'] ?? null,$messages),
			'unknown_message'=>$transcript->response('unknown-prompt')['error']['message'] ?? null,
			'default_protocol'=>$transcript->result('default-initialize')['protocolVersion'] ?? null,
		];
	}

	/** @return array<string,mixed> */
	private function headerTransportContract(): array {
		$scenario=new McpScenario($this->context);
		$request=McpScenario::request('header-initialize','initialize');
		$valid=$scenario->exchangeRaw('Ignored header without colon' . "\r\n" . McpScenario::frame($request,['X-Contract'=>'framed']),'headers');
		$missing=$scenario->exchangeRaw("X-Contract: missing-length\r\n\r\n",'headers');
		$eofHeader=$scenario->exchangeRaw("X-Contract: ended-at-eof\r\n",'headers');
		$oversized=$scenario->exchangeRaw("Content-Length: 4194305\r\n\r\n",'headers');
		$incomplete=$scenario->exchangeRaw("Content-Length: 20\r\n\r\n{}",'headers');
		$malformedBody='{bad';
		$malformed=$scenario->exchangeRaw('Content-Length: '.strlen($malformedBody)."\r\n\r\n".$malformedBody,'headers');
		$blank=$scenario->exchangeRaw("\r\n",'headers');
		return [
			'default_protocol'=>$valid->result('header-initialize')['protocolVersion'] ?? null,
			'errors'=>[
				'missing'=>$missing->messages()[0]['error'] ?? [],
				'eof_header'=>$eofHeader->messages()[0]['error'] ?? [],
				'oversized'=>$oversized->messages()[0]['error'] ?? [],
				'incomplete'=>$incomplete->messages()[0]['error'] ?? [],
				'malformed'=>$malformed->messages()[0]['error'] ?? [],
			],
			'blank'=>$blank->messages()[0]['error'] ?? [],
		];
	}

	/** @return array<string,mixed> */
	private function catalogContract(): array {
		$kernel=new McpKernelHarness($this->context);
		$prompts=[];
		foreach([
			'dataphyre_feature_plan',
			'dataphyre_debug_triage',
			'dataphyre_panel_workflow',
			'dataphyre_panel_platform_workflow',
			'dataphyre_panel_operations_workflow',
			'dataphyre_panel_studio_workflow',
			'dataphyre_panel_realtime_workflow',
			'dataphyre_panel_adapter_workflow',
			'dataphyre_runtime_guidelines',
			'dataphyre_release_triage',
			'dataphyre_sql_schema_workflow',
			'dataphyre_route_manifest_workflow',
			'dataphyre_diagnostics_workflow',
			'dataphyre_contract_workflow',
		] as $name){
			$prompts[$name]=strlen((string)$kernel->invoke('prompt_text',$name));
		}
		$sourceResources=(new McpScenario($this->context))
			->usingOrdinaryPhpForSourceIntrospection()
			->exchange([
				McpScenario::request('contract-resource','resources/read',['uri'=>'dataphyre://contracts']),
				McpScenario::request('panel-resource','resources/read',['uri'=>'dataphyre://panel']),
			]);
		$resources=[];
		foreach([
			'dataphyre://module-index',
			'dataphyre://runtime-readme',
			'dataphyre://mcp-plan',
			'dataphyre://ai-guidelines',
			'dataphyre://agentic-enterprise',
			'dataphyre://mcp-capabilities',
			'dataphyre://contracts',
			'dataphyre://panel',
		] as $uri){
			$payload=match($uri){
				'dataphyre://contracts'=>$sourceResources->result('contract-resource'),
				'dataphyre://panel'=>$sourceResources->result('panel-resource'),
				default=>$kernel->invoke('read_resource',['uri'=>$uri]),
			};
			$content=is_array($payload['contents'][0] ?? null) ? $payload['contents'][0] : [];
			$resources[$uri]=[
				'mime_type'=>$content['mimeType'] ?? null,
				'bytes'=>strlen((string)($content['text'] ?? '')),
			];
		}
		return [
			'prompts'=>$prompts,
			'resources'=>$resources,
			'unknown_prompt'=>$this->exceptionMessage(static fn()=>$kernel->invoke('prompt_text','unknown_prompt')),
		];
	}

	/** @return array<string,mixed> */
	private function debugAndEncodingContract(): array {
		$workspace=$this->context->workspace('mcp-debug-lifecycle');
		$defaultLog=$workspace->path('.tmp/dataphyre_mcp_debug.log');
		(new McpScenario($this->context))
			->inDirectory($workspace->root())
			->withEnvironment(['DATAPHYRE_MCP_DEBUG_LOG'=>'1'])
			->exchange([McpScenario::request('debug-default','initialize')]);
		$explicitLog=$workspace->path('explicit-debug.log');
		(new McpScenario($this->context))
			->inDirectory($workspace->root())
			->withEnvironment(['DATAPHYRE_MCP_DEBUG_LOG'=>$explicitLog])
			->exchange([McpScenario::request('debug-explicit','initialize')]);
		$records=static function(string $path): array {
			$lines=file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
			if(!is_array($lines)){return [];}
			return array_values(array_filter(array_map(
				static fn(string $line): mixed=>json_decode($line,true),
				$lines
			),'is_array'));
		};
		$fatalLog=$workspace->path('fatal-debug.log');
		$moduleRoot=dirname(__DIR__);
		$fatal=$this->context->phpFixture(
			$moduleRoot.'/unit_tests/fixtures/mcp_fatal_shutdown_probe.php',
			[$moduleRoot.'/kernel/dataphyre_mcp.php'],
			'',
			$workspace->root(),
			['DATAPHYRE_MCP_DEBUG_LOG'=>$fatalLog]
		);
		$fatalRecords=$records($fatalLog);
		$kernel=new McpKernelHarness($this->context);
		$directLog=$workspace->path('direct-shutdown-debug.log');
		$previousDebugLog=getenv('DATAPHYRE_MCP_DEBUG_LOG');
		putenv('DATAPHYRE_MCP_DEBUG_LOG='.$directLog);
		try{
			\dataphyre_mcp_debug_shutdown([
				'type'=>E_WARNING,
				'message'=>'Nonfatal diagnostic must remain silent.',
				'file'=>__FILE__,
				'line'=>__LINE__,
			]);
			\dataphyre_mcp_debug_shutdown([
				'type'=>E_USER_ERROR,
				'message'=>'Direct fatal classification probe.',
				'file'=>__FILE__,
				'line'=>__LINE__,
			]);
		}finally{
			putenv($previousDebugLog===false
				? 'DATAPHYRE_MCP_DEBUG_LOG'
				: 'DATAPHYRE_MCP_DEBUG_LOG='.$previousDebugLog);
		}
		$directRecords=$records($directLog);
		$events=static fn(array $items): array=>array_map(
			static fn(array $item): mixed=>$item['event'] ?? null,
			$items
		);
		$valid=$kernel->invoke('mcp_json_response_body',['jsonrpc'=>'2.0','id'=>'contract','result'=>['ok'=>true]]);
		$invalid=$kernel->invoke('mcp_json_response_body',['invalid_utf8'=>"\xB1\x31"]);
		$resource=fopen('php://memory','rb');
		$invalidToolJson=$kernel->invoke('json',$resource);
		if(is_resource($resource)){fclose($resource);}
		return [
			'default_events'=>$events($records($defaultLog)),
			'explicit_events'=>$events($records($explicitLog)),
			'fatal_exit_code'=>$fatal->exitCode(),
			'fatal_events'=>$events($fatalRecords),
			'fatal_context'=>$fatalRecords[2]['context'] ?? [],
			'direct_shutdown_events'=>$events($directRecords),
			'direct_shutdown_context'=>$directRecords[0]['context'] ?? [],
			'valid_body'=>json_decode($valid,true,512,JSON_THROW_ON_ERROR),
			'invalid_body'=>json_decode($invalid,true,512,JSON_THROW_ON_ERROR),
			'invalid_tool_json'=>$invalidToolJson,
		];
	}

	private function exceptionMessage(callable $operation): string {
		try{$operation();}
		catch(\Throwable $error){return $error->getMessage();}
		return '';
	}
}

/** Fluent, valid-by-default client-owned workflow transcript fixture. */
final class McpTranscriptBuilder {
	/** @var array<string,mixed> */
	private array $transcript;
	private int $nextOrder=1;

	private function __construct(string $workflow) {
		$this->transcript=[
			'transcript_id'=>'contract-transcript',
			'workflow'=>$workflow,
			'created_at'=>'2026-07-14T00:00:00Z',
			'client'=>['name'=>'Dataphyre TestKit','version'=>'1','transport'=>'stdio'],
			'server'=>['name'=>'dataphyre-mcp','protocol'=>'2025-11-25'],
			'steps'=>[],
			'final_status'=>'passed',
			'follow_up_tools'=>[],
		];
	}

	public static function valid(string $workflow='feature'): self {return new self($workflow);}

	public function step(string $method,string $tool='',?bool $ok=true,string $summary='Step completed.',array $resultKeys=['content'],array $redactions=[]): self {
		$request=['method'=>$method,'arguments'=>[]];
		if($tool!==''){$request['tool']=$tool;}
		$response=['content_summary'=>$summary,'result_keys'=>$resultKeys];
		if($ok!==null){$response['ok']=$ok;}
		$this->transcript['steps'][]=[
			'order'=>$this->nextOrder++,
			'request'=>$request,
			'response'=>$response,
			'redactions'=>$redactions,
		];
		return $this;
	}

	public function successfulTool(string $tool,string $summary='Tool completed.',array $resultKeys=['content']): self {
		return $this->step('tools/call',$tool,true,$summary,$resultKeys);
	}

	public function failedTool(string $tool,string $summary='Tool failed.',array $resultKeys=['error']): self {
		return $this->step('tools/call',$tool,false,$summary,$resultKeys);
	}

	public function rawStep(mixed $step): self {
		$this->transcript['steps'][]=$step;
		$this->nextOrder++;
		return $this;
	}

	public function finalStatus(string $status): self {$this->transcript['final_status']=$status;return $this;}

	public function followUpTools(mixed ...$tools): self {$this->transcript['follow_up_tools']=$tools;return $this;}

	public function without(string $field): self {unset($this->transcript[$field]);return $this;}

	public function field(string $field,mixed $value): self {$this->transcript[$field]=$value;return $this;}

	/** @return array<string,mixed> */
	public function toArray(): array {return $this->transcript;}

	public function toJson(): string {return json_encode($this->transcript,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
}

/** Named audit/summary/checkpoint evidence built from fluent transcript stories. */
final class McpTranscriptBoundaryHarness {
	private McpKernelHarness $kernel;

	public function __construct(private TestContext $context) {$this->kernel=new McpKernelHarness($context);}

	/** @return list<string> */
	public static function contractNames(): array {
		return [
			'transcript input accepts objects and JSON while rejecting malformed payloads',
			'audit separates shape tool registration and redaction failures',
			'summary windows preserve totals and application-first handoffs',
			'checkpoints classify empty healthy review and blocked progress',
			'schema and handoff exports retain normalized audience boundaries',
		];
	}

	/** @return array<string,mixed> */
	public function contract(string $name): array {
		if(!in_array($name,self::contractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP transcript boundary contract: '.$name);
		}
		$evidence=match($name){
			'transcript input accepts objects and JSON while rejecting malformed payloads'=>$this->inputContract(),
			'audit separates shape tool registration and redaction failures'=>$this->auditContract(),
			'summary windows preserve totals and application-first handoffs'=>$this->summaryContract(),
			'checkpoints classify empty healthy review and blocked progress'=>$this->checkpointContract(),
			'schema and handoff exports retain normalized audience boundaries'=>$this->schemaAndHandoffContract(),
		};
		return ['contract'=>$name]+$evidence;
	}

	/** @return array<string,mixed> */
	private function inputContract(): array {
		$transcript=McpTranscriptBuilder::valid('feature')->successfulTool('dataphyre_module_describe')->toArray();
		return [
			'array'=>$this->kernel->invoke('mcp_workflow_transcript_input',['transcript'=>$transcript]),
			'json'=>$this->kernel->invoke('mcp_workflow_transcript_input',['transcript_json'=>json_encode($transcript,JSON_THROW_ON_ERROR)]),
			'array_precedence'=>$this->kernel->invoke('mcp_workflow_transcript_input',['transcript'=>$transcript,'transcript_json'=>'{malformed']),
			'missing'=>$this->kernel->invoke('mcp_workflow_transcript_input',[]),
			'invalid'=>$this->kernel->invoke('mcp_workflow_transcript_input',['transcript_json'=>'{malformed']),
			'workflow_names'=>[
				'normalized'=>$this->kernel->invoke('mcp_workflow_name',' ROUTES ',['feature','routes'],'feature'),
				'fallback'=>$this->kernel->invoke('mcp_workflow_name','unsupported',['feature','routes'],'feature'),
			],
		];
	}

	/** @return array<string,mixed> */
	private function auditContract(): array {
		$valid=McpTranscriptBuilder::valid('feature')
			->step('initialize','',true,'Server initialized.',['protocolVersion'])
			->successfulTool('dataphyre_module_describe','Module described.',['content'])
			->toJson();
		$problem=McpTranscriptBuilder::valid('feature')
			->finalStatus('unexpected')
			->rawStep('not-an-object')
			->rawStep([
				'order'=>2,
				'request'=>['method'=>'tools/call','tool'=>'not_registered'],
				'response'=>[],
				'redactions'=>[],
			])
			->successfulTool(
				'dataphyre_module_describe',
				'password=hunter2 Bearer abcdefghijklmnop mysql://root:secret@localhost/db C:\\Users\\Alice\\secret X-Amz-Signature=signed tenant_id=acme',
				['content']
			);
		for($index=0;$index<49;$index++){$problem->successfulTool('dataphyre_module_describe','Bounded step '.$index.'.',['content']);}
		$problemAudit=$this->kernel->invoke('mcp_workflow_transcript_audit',['workflow'=>'routes','transcript'=>$problem->toArray()]);
		$validAudit=$this->kernel->invoke('mcp_workflow_transcript_audit',['workflow'=>'feature','transcript_json'=>$valid]);
		$missingWorkflowAudit=$this->kernel->invoke('mcp_workflow_transcript_audit',[
			'workflow'=>'feature',
			'transcript'=>McpTranscriptBuilder::valid('feature')->without('workflow')->successfulTool('dataphyre_module_describe')->toArray(),
		]);
		$malformed=$this->kernel->invoke('mcp_workflow_transcript_audit',['workflow'=>'unsupported','transcript_json'=>'{malformed']);
		$missing=$this->kernel->invoke('mcp_workflow_transcript_audit',[]);
		return [
			'valid'=>self::auditEvidence($validAudit),
			'missing_workflow'=>self::auditEvidence($missingWorkflowAudit),
			'problem'=>self::auditEvidence($problemAudit),
			'malformed'=>self::auditEvidence($malformed),
			'missing'=>self::auditEvidence($missing),
		];
	}

	/** @return array<string,mixed> */
	private function summaryContract(): array {
		$transcript=McpTranscriptBuilder::valid('client')
			->step('initialize','',true,'Server initialized.',['protocolVersion'])
			->failedTool('dataphyre_module_describe','Module lookup failed.',['error'])
			->step('resources/read','',null,'Documentation inspected.',['contents'],['absolute_path'])
			->rawStep('invalid-step')
			->finalStatus('partial')
			->followUpTools('', 'dataphyre_mcp_verify_all','dataphyre_mcp_workflow_state_audit','dataphyre_mcp_workflow_state_audit');
		$summary=$this->kernel->invoke('mcp_workflow_transcript_summary_export',[
			'workflow'=>'UNSUPPORTED',
			'transcript_json'=>$transcript->toJson(),
			'max_summary_steps'=>2,
		]);
		return [
			'summary'=>$summary,
			'limits'=>[
				'defaulted'=>$this->kernel->invoke('mcp_workflow_summary_step_limit',['max_summary_steps'=>0]),
				'bounded'=>$this->kernel->invoke('mcp_workflow_summary_step_limit',['max_summary_steps'=>99]),
				'custom'=>$this->kernel->invoke('mcp_workflow_summary_step_limit',['max_summary_steps'=>3]),
			],
			'non_omitting_window'=>$this->kernel->invoke('mcp_workflow_step_window',2,5,3),
		];
	}

	/** @return array<string,mixed> */
	private function checkpointContract(): array {
		$healthy=McpTranscriptBuilder::valid('feature')
			->successfulTool('dataphyre_module_describe','Module described.',['content'])
			->finalStatus('passed');
		$blocked=McpTranscriptBuilder::valid('feature')
			->failedTool('dataphyre_module_describe','Module failed.',['error'])
			->finalStatus('failed');
		$review=McpTranscriptBuilder::valid('feature')
			->successfulTool('dataphyre_module_describe','Bearer abcdefghijklmnop',['content'])
			->finalStatus('partial');
		$checkpoints=[];
		$checkpoints['empty']=$this->kernel->invoke('mcp_workflow_checkpoint_export',['task'=>'No transcript']);
		$checkpoints['healthy']=$this->kernel->invoke('mcp_workflow_checkpoint_export',['task'=>'Healthy handoff','transcript'=>$healthy->toArray()]);
		$checkpoints['blocked']=$this->kernel->invoke('mcp_workflow_checkpoint_export',['task'=>'Blocked handoff','transcript_json'=>$blocked->toJson()]);
		$checkpoints['review']=$this->kernel->invoke('mcp_workflow_checkpoint_export',['task'=>'Review handoff','transcript'=>$review->toArray()]);
		return ['checkpoints'=>$checkpoints];
	}

	/** @return array<string,mixed> */
	private function schemaAndHandoffContract(): array {
		$schema=$this->kernel->invoke('mcp_workflow_transcript_schema_export',['workflow'=>'unsupported']);
		$handoff=$this->kernel->invoke('mcp_workflow_handoff_pack_export',['workflow'=>'unsupported','include_frames'=>false]);
		$tools=[
			'dataphyre_mcp_workflow_session_export',
			'dataphyre_mcp_workflow_readiness_audit',
			'dataphyre_mcp_safety_boundary_report',
			'dataphyre_mcp_workflow_transcript_schema_export',
			'dataphyre_mcp_workflow_state_audit',
			'dataphyre_mcp_workflow_state_schema_export',
			'dataphyre_mcp_workflow_next_action_export',
			'dataphyre_mcp_live_validate',
			'dataphyre_mcp_verify_all',
			'not_registered',
		];
		return [
			'schema'=>$schema,
			'handoff'=>$handoff,
			'app_tools'=>$this->kernel->invoke('mcp_workflow_app_tools',['', 'dataphyre_mcp_verify_all','dataphyre_mcp_workflow_state_audit','dataphyre_mcp_workflow_state_audit',123]),
			'boundaries'=>$this->kernel->invoke('mcp_workflow_tool_boundaries',$tools),
		];
	}

	/** @return array<string,mixed> */
	private static function auditEvidence(array $audit): array {
		return [
			'passed'=>$audit['passed'] ?? null,
			'workflow'=>$audit['workflow'] ?? null,
			'expected_workflow'=>$audit['expected_workflow'] ?? null,
			'error_count'=>$audit['error_count'] ?? null,
			'warning_count'=>$audit['warning_count'] ?? null,
			'step_count'=>$audit['step_count'] ?? null,
			'codes'=>array_values(array_map(static fn(array $finding): string=>(string)($finding['code'] ?? ''),is_array($audit['findings'] ?? null) ? $audit['findings'] : [])),
			'redaction_signals'=>$audit['redaction_signals'] ?? [],
		];
	}
}

/** Fluent, valid-by-default client-owned workflow-state fixture. */
final class McpWorkflowStateBuilder {
	/** @var array<string,mixed> */
	private array $state;

	private function __construct(string $workflow) {
		$this->state=[
			'state_id'=>'contract-state',
			'workflow'=>$workflow,
			'task'=>'Continue the application workflow.',
			'created_at'=>'2026-07-14T00:00:00Z',
			'updated_at'=>'2026-07-14T00:05:00Z',
			'client'=>['name'=>'Dataphyre TestKit','version'=>'1','transport'=>'stdio'],
			'selected_workflow'=>['name'=>$workflow,'score'=>1,'ready'=>true],
			'current_phase'=>'start',
			'last_decision'=>'start_workflow',
			'last_tool'=>'dataphyre_mcp_task_start_pack_export',
			'transcript_ref'=>'contract-transcript',
			'checkpoint_ref'=>'contract-checkpoint',
			'checkpoint_status'=>'healthy',
			'pending_tools'=>['dataphyre_mcp_workflow_handoff_pack_export'],
			'completed_tools'=>['dataphyre_mcp_task_start_pack_export'],
			'findings'=>[],
			'notes'=>[],
		];
	}

	public static function valid(string $workflow='feature'): self {return new self($workflow);}
	public function phase(string $phase): self {$this->state['current_phase']=$phase;return $this;}
	public function decision(string $decision): self {$this->state['last_decision']=$decision;return $this;}
	public function checkpoint(string $status): self {$this->state['checkpoint_status']=$status;return $this;}
	public function lastTool(mixed $tool): self {$this->state['last_tool']=$tool;return $this;}
	public function pendingTools(mixed ...$tools): self {$this->state['pending_tools']=$tools;return $this;}
	public function completedTools(mixed ...$tools): self {$this->state['completed_tools']=$tools;return $this;}
	public function finding(mixed $finding): self {$this->state['findings'][]=$finding;return $this;}
	public function note(mixed $note): self {$this->state['notes'][]=$note;return $this;}
	public function without(string $field): self {unset($this->state[$field]);return $this;}
	public function field(string $field,mixed $value): self {$this->state[$field]=$value;return $this;}
	/** @return array<string,mixed> */
	public function toArray(): array {return $this->state;}
	public function toJson(): string {return json_encode($this->state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
}

/** Named lifecycle evidence built from fluent client-owned workflow-state stories. */
final class McpWorkflowStateBoundaryHarness {
	private McpKernelHarness $kernel;

	public function __construct(private TestContext $context) {$this->kernel=new McpKernelHarness($context);}

	/** @return list<string> */
	public static function contractNames(): array {
		return [
			'state input preserves object JSON missing and malformed provenance',
			'audit separates lifecycle shape tool and redaction failures',
			'summaries bound and redact handoff state without release-gate leakage',
			'transitions and sync packs describe client-owned lifecycle patches',
			'timelines resume briefs and phase tools make continuity explicit',
		];
	}

	/** @return array<string,mixed> */
	public function contract(string $name): array {
		if(!in_array($name,self::contractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP workflow-state boundary contract: '.$name);
		}
		$evidence=match($name){
			'state input preserves object JSON missing and malformed provenance'=>$this->inputContract(),
			'audit separates lifecycle shape tool and redaction failures'=>$this->auditContract(),
			'summaries bound and redact handoff state without release-gate leakage'=>$this->summaryContract(),
			'transitions and sync packs describe client-owned lifecycle patches'=>$this->transitionAndSyncContract(),
			'timelines resume briefs and phase tools make continuity explicit'=>$this->continuityContract(),
		};
		return ['contract'=>$name]+$evidence;
	}

	/** @return array<string,mixed> */
	private function inputContract(): array {
		$state=McpWorkflowStateBuilder::valid('feature')->toArray();
		return [
			'array'=>$this->kernel->invoke('mcp_workflow_state_input',['state'=>$state]),
			'json'=>$this->kernel->invoke('mcp_workflow_state_input',['state_json'=>json_encode($state,JSON_THROW_ON_ERROR)]),
			'array_precedence'=>$this->kernel->invoke('mcp_workflow_state_input',['state'=>$state,'state_json'=>'{malformed']),
			'missing'=>$this->kernel->invoke('mcp_workflow_state_input',[]),
			'invalid'=>$this->kernel->invoke('mcp_workflow_state_input',['state_json'=>'{malformed']),
			'schemas'=>[
				'normalized'=>$this->kernel->invoke('mcp_workflow_state_schema_export',['workflow'=>' ROUTES ']),
				'fallback'=>$this->kernel->invoke('mcp_workflow_state_schema_export',['workflow'=>'unsupported']),
			],
		];
	}

	/** @return array<string,mixed> */
	private function auditContract(): array {
		$valid=$this->kernel->invoke('mcp_workflow_state_audit',[
			'workflow'=>'feature',
			'state_json'=>McpWorkflowStateBuilder::valid('feature')->toJson(),
		]);
		$missingWorkflow=$this->kernel->invoke('mcp_workflow_state_audit',[
			'workflow'=>'feature',
			'state'=>McpWorkflowStateBuilder::valid('feature')->without('workflow')->toArray(),
		]);
		$problem=McpWorkflowStateBuilder::valid('feature')
			->without('updated_at')
			->without('client')
			->phase('teleport')
			->decision('guess')
			->checkpoint('mystery')
			->lastTool(42)
			->field('pending_tools','not_registered')
			->completedTools('dataphyre_mcp_task_start_pack_export',42,'also_not_registered')
			->field('findings','not-an-array')
			->field('notes','not-an-array')
			->field('task','password=hunter2 Bearer abcdefghijklmnop mysql://root:secret@localhost/db C:\\Users\\Alice\\secret X-Amz-Signature=signed tenant_id=acme')
			->toArray();
		return [
			'valid'=>self::auditEvidence($valid),
			'missing_workflow'=>self::auditEvidence($missingWorkflow),
			'problem'=>self::auditEvidence($this->kernel->invoke('mcp_workflow_state_audit',['workflow'=>'routes','state'=>$problem])),
			'malformed'=>self::auditEvidence($this->kernel->invoke('mcp_workflow_state_audit',['workflow'=>'unsupported','state_json'=>'{malformed'])),
			'missing'=>self::auditEvidence($this->kernel->invoke('mcp_workflow_state_audit',[])),
		];
	}

	/** @return array<string,mixed> */
	private function summaryContract(): array {
		$safe=McpWorkflowStateBuilder::valid('client')
			->phase('checkpoint')
			->decision('summarize_or_verify')
			->pendingTools('dataphyre_mcp_workflow_state_audit','dataphyre_mcp_verify_all','dataphyre_mcp_workflow_state_audit')
			->completedTools('dataphyre_mcp_task_start_pack_export','dataphyre_mcp_workflow_handoff_pack_export')
			->note('Ready for a compact handoff.')
			->finding(['severity'=>'info','message'=>'No blockers.']);
		$redacted=McpWorkflowStateBuilder::valid('client')
			->pendingTools('dataphyre_mcp_verify_all','dataphyre_mcp_workflow_state_audit')
			->note('Bearer abcdefghijklmnop')
			->note('C:\\Users\\Alice\\secret')
			->finding(['password'=>'hunter2'])
			->finding('tenant_id=acme');
		return [
			'safe'=>$this->kernel->invoke('mcp_workflow_state_summary_export',['workflow'=>'unsupported','state_json'=>$safe->toJson()]),
			'redacted'=>$this->kernel->invoke('mcp_workflow_state_summary_export',['workflow'=>'client','state'=>$redacted->toArray()]),
			'empty_items'=>$this->kernel->invoke('mcp_workflow_state_summary_items','not-a-list',6,300),
			'zero_window'=>$this->kernel->invoke('mcp_workflow_state_summary_items',['one'],0,300),
		];
	}

	/** @return array<string,mixed> */
	private function transitionAndSyncContract(): array {
		$checkpoint=McpWorkflowStateBuilder::valid('feature')
			->field('task','')
			->phase('checkpoint')
			->decision('summarize_or_verify')
			->lastTool('dataphyre_mcp_workflow_checkpoint_export')
			->completedTools('dataphyre_mcp_task_start_pack_export');
		$phases=[
			'start'=>McpWorkflowStateBuilder::valid('feature')->phase('start'),
			'review'=>McpWorkflowStateBuilder::valid('feature')->phase('client_run'),
			'blocked'=>McpWorkflowStateBuilder::valid('feature')->phase('start')->checkpoint('blocked'),
			'done'=>McpWorkflowStateBuilder::valid('feature')->phase('done')->decision('done'),
		];
		$transitions=[];
		foreach($phases as $name=>$state){
			$transitions[$name]=$this->kernel->invoke('mcp_workflow_state_transition_export',['workflow'=>'feature','state'=>$state->toArray()]);
		}
		$checkpointTransition=$this->kernel->invoke('mcp_workflow_state_transition_export',[
			'workflow'=>'feature',
			'state_json'=>$checkpoint->toJson(),
			'task'=>'Resume Bearer abcdefghijklmnop safely',
		]);
		return [
			'checkpoint'=>$checkpointTransition,
			'transitions'=>$transitions,
			'sync'=>$this->kernel->invoke('mcp_workflow_state_sync_pack_export',['workflow'=>'feature','state_json'=>$checkpoint->toJson(),'task'=>'Resume safely']),
			'malformed_sync'=>$this->kernel->invoke('mcp_workflow_state_sync_pack_export',['workflow'=>'unsupported','state_json'=>'{malformed']),
		];
	}

	/** @return array<string,mixed> */
	private function continuityContract(): array {
		$state=McpWorkflowStateBuilder::valid('routes')
			->phase('client_run')
			->decision('review_transcript')
			->lastTool('dataphyre_mcp_workflow_session_export')
			->completedTools('dataphyre_mcp_task_start_pack_export','dataphyre_mcp_workflow_handoff_pack_export')
			->toArray();
		$timeline=$this->kernel->invoke('mcp_workflow_state_timeline_export',['workflow'=>'routes','state'=>$state]);
		$resume=$this->kernel->invoke('mcp_workflow_state_resume_brief_export',['workflow'=>'routes','state'=>$state]);
		$phaseTools=[];
		foreach(['start','choose_workflow','pre_run_handoff','client_run','capture','audit','checkpoint','handoff_summary','verify','done','unknown'] as $phase){
			$phaseTools[$phase]=$this->kernel->invoke('mcp_workflow_phase_tool',$phase);
		}
		return [
			'timeline'=>$timeline,
			'resume'=>$resume,
			'continuity'=>$this->kernel->invoke('mcp_workflow_continuity_policy','contract'),
			'phase_tools'=>$phaseTools,
		];
	}

	/** @return array<string,mixed> */
	private static function auditEvidence(array $audit): array {
		return [
			'passed'=>$audit['passed'] ?? null,
			'workflow'=>$audit['workflow'] ?? null,
			'expected_workflow'=>$audit['expected_workflow'] ?? null,
			'error_count'=>$audit['error_count'] ?? null,
			'warning_count'=>$audit['warning_count'] ?? null,
			'pending_tool_count'=>$audit['pending_tool_count'] ?? null,
			'completed_tool_count'=>$audit['completed_tool_count'] ?? null,
			'codes'=>array_values(array_map(static fn(array $finding): string=>(string)($finding['code'] ?? ''),is_array($audit['findings'] ?? null) ? $audit['findings'] : [])),
			'redaction_signals'=>$audit['redaction_signals'] ?? [],
		];
	}
}

/** Fluent, valid-by-default caller-owned MCP client config fixture. */
final class McpClientConfigBuilder {
	private const SERVER='dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php';
	private const MODULE_BOOTSTRAP='dataphyre/runtime/modules/mcp/kernel/mcp.main.php';
	/** @var array<string,mixed> */
	private array $config;

	private function __construct() {
		$this->config=['mcpServers'=>['dataphyre'=>[
			'command'=>'php',
			'args'=>[self::SERVER],
			'cwd'=>'project root',
		]]];
	}

	public static function valid(): self {return new self();}
	public function command(mixed $command): self {$this->config['mcpServers']['dataphyre']['command']=$command;return $this;}
	public function args(mixed ...$args): self {$this->config['mcpServers']['dataphyre']['args']=$args;return $this;}
	public function cwd(mixed $cwd): self {$this->config['mcpServers']['dataphyre']['cwd']=$cwd;return $this;}
	public function useModuleBootstrap(): self {return $this->args(self::MODULE_BOOTSTRAP);}
	public function allowUnsafe(): self {$this->config['mcpServers']['dataphyre']['args'][]='--allow-unsafe';return $this;}
	public function productLocal(?string $path=null): self {
		$this->config['mcpServers']['dataphyre']['private_path']=$path ?? ('applications/'.'sho'.'piro/backend');
		return $this;
	}
	public function withoutServer(): self {$this->config['mcpServers']=[];return $this;}
	public function serverField(string $field,mixed $value): self {$this->config['mcpServers']['dataphyre'][$field]=$value;return $this;}
	/** @return array<string,mixed> */
	public function toArray(): array {return $this->config;}
	public function toJson(): string {return json_encode($this->config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
}

/** Named setup, troubleshooting, and client-config evidence. */
final class McpClientSetupBoundaryHarness {
	private McpKernelHarness $kernel;

	public function __construct(private TestContext $context) {$this->kernel=new McpKernelHarness($context);}

	/** @return list<string> */
	public static function contractNames(): array {
		return [
			'install plans normalize every supported client without machine-local writes',
			'troubleshooting maps recognizable symptoms to focused setup diagnoses',
			'config input preserves object JSON missing and malformed provenance',
			'config audits separate blocking issues warnings and portability passes',
			'smoke onboarding and compatibility exports keep validation audiences explicit',
		];
	}

	/** @return array<string,mixed> */
	public function contract(string $name): array {
		if(!in_array($name,self::contractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP client-setup boundary contract: '.$name);
		}
		$evidence=match($name){
			'install plans normalize every supported client without machine-local writes'=>$this->installContract(),
			'troubleshooting maps recognizable symptoms to focused setup diagnoses'=>$this->troubleshootContract(),
			'config input preserves object JSON missing and malformed provenance'=>$this->configInputContract(),
			'config audits separate blocking issues warnings and portability passes'=>$this->configAuditContract(),
			'smoke onboarding and compatibility exports keep validation audiences explicit'=>$this->compatibilityContract(),
		};
		return ['contract'=>$name]+$evidence;
	}

	/** @return array<string,mixed> */
	private function installContract(): array {
		$plans=[];
		$checklists=[];
		foreach(['codex','claude','cursor','generic','unsupported'] as $target){
			$plans[$target]=$this->kernel->invoke('mcp_client_config_install_plan',['target'=>$target,'php_command'=>'','config_path'=>'']);
			$checklists[$target]=$this->kernel->invoke('mcp_client_install_checklist',['target'=>$target,'php_command'=>'','include_cwd'=>true]);
		}
		return [
			'plans'=>$plans,
			'checklists'=>$checklists,
			'entrypoint_contract'=>$this->kernel->invoke('mcp_server_entrypoint_contract'),
			'normalized_target'=>$this->kernel->invoke('mcp_client_target',' CURSOR '),
			'fallback_target'=>$this->kernel->invoke('mcp_client_target','telepathic-client'),
		];
	}

	/** @return array<string,mixed> */
	private function troubleshootContract(): array {
		$focused=$this->kernel->invoke('mcp_client_troubleshoot',[
			'target'=>'codex',
			'symptoms'=>['No response','Content-Length parse error','No such file','php: command not found','missing tool','SQL execution permission'],
		]);
		$generic=$this->kernel->invoke('mcp_client_troubleshoot',['target'=>'unsupported','symptoms'=>['','unrecognized symptom']]);
		return [
			'focused'=>$focused,
			'focused_ids'=>self::ids($focused['diagnoses'] ?? []),
			'generic'=>$generic,
			'generic_ids'=>self::ids($generic['diagnoses'] ?? []),
		];
	}

	/** @return array<string,mixed> */
	private function configInputContract(): array {
		$config=McpClientConfigBuilder::valid()->toArray();
		return [
			'array'=>$this->kernel->invoke('mcp_client_config_input',['config'=>$config]),
			'json'=>$this->kernel->invoke('mcp_client_config_input',['config_json'=>json_encode($config,JSON_THROW_ON_ERROR)]),
			'array_precedence'=>$this->kernel->invoke('mcp_client_config_input',['config'=>$config,'config_json'=>'{malformed']),
			'missing'=>$this->kernel->invoke('mcp_client_config_input',[]),
			'invalid'=>$this->kernel->invoke('mcp_client_config_input',['config_json'=>'{malformed']),
			'non_object'=>$this->kernel->invoke('mcp_client_config_input',['config_json'=>'true']),
		];
	}

	/** @return array<string,mixed> */
	private function configAuditContract(): array {
		$broken=McpClientConfigBuilder::valid()
			->command('')
			->useModuleBootstrap()
			->allowUnsafe()
			->cwd('')
			->productLocal();
		$nonPhp=McpClientConfigBuilder::valid()->command('node');
		return [
			'valid'=>self::auditEvidence($this->kernel->invoke('mcp_client_config_audit',['config_json'=>McpClientConfigBuilder::valid()->toJson()])),
			'windows_php'=>self::auditEvidence($this->kernel->invoke('mcp_client_config_audit',['config'=>McpClientConfigBuilder::valid()->command('C:\\php\\php.exe')->toArray()])),
			'empty'=>self::auditEvidence($this->kernel->invoke('mcp_client_config_audit',[])),
			'malformed'=>self::auditEvidence($this->kernel->invoke('mcp_client_config_audit',['config_json'=>'{malformed'])),
			'missing_server'=>self::auditEvidence($this->kernel->invoke('mcp_client_config_audit',['config'=>McpClientConfigBuilder::valid()->withoutServer()->toArray()])),
			'broken'=>self::auditEvidence($this->kernel->invoke('mcp_client_config_audit',['config'=>$broken->toArray()])),
			'non_php'=>self::auditEvidence($this->kernel->invoke('mcp_client_config_audit',['config'=>$nonPhp->toArray()])),
		];
	}

	/** @return array<string,mixed> */
	private function compatibilityContract(): array {
		$smoke=[];
		foreach(['powershell','bash','node','php','unsupported'] as $format){
			$export=$this->kernel->invoke('mcp_smoke_test_export',['format'=>$format]);
			$smoke[$format]=['script_names'=>array_keys($export['scripts'] ?? []),'request_count'=>count($export['requests'] ?? [])];
		}
		$all=$this->kernel->invoke('mcp_client_compatibility_matrix',[]);
		$filtered=$this->kernel->invoke('mcp_client_compatibility_matrix',['targets'=>['cursor','unknown','']]);
		$fallback=$this->kernel->invoke('mcp_client_compatibility_matrix',['targets'=>['unknown']]);
		return [
			'smoke'=>$smoke,
			'onboarding'=>$this->kernel->invoke('mcp_client_onboarding_pack',['target'=>'cursor','smoke_format'=>'php','include_schemas'=>true]),
			'all'=>$all,
			'all_row_targets'=>array_values(array_map(static fn(array $row): string=>(string)($row['target'] ?? ''),$all['rows'] ?? [])),
			'filtered'=>$filtered,
			'fallback'=>$fallback,
			'boundaries'=>$this->kernel->invoke('mcp_client_validation_tool_boundaries',['dataphyre_mcp_client_onboarding_pack','dataphyre_mcp_live_validate','not_registered']),
		];
	}

	/** @return list<string> */
	private static function ids(mixed $items): array {
		return array_values(array_map(static fn(array $item): string=>(string)($item['id'] ?? ''),is_array($items) ? $items : []));
	}

	/** @return array<string,mixed> */
	private static function auditEvidence(array $audit): array {
		return [
			'passed'=>$audit['passed'] ?? null,
			'issue_count'=>$audit['issue_count'] ?? null,
			'warning_count'=>$audit['warning_count'] ?? null,
			'issue_ids'=>self::ids($audit['issues'] ?? []),
			'warning_ids'=>self::ids($audit['warnings'] ?? []),
			'passes'=>$audit['passes'] ?? [],
		];
	}
}

/** Fluent request for a bounded task-pack story. */
final class McpTaskPackBuilder {
	/** @var array<string,mixed> */
	private array $args;

	private function __construct(string $task) {$this->args=['task'=>$task];}
	public static function forTask(string $task='Build a project tracker admin panel.'): self {return new self($task);}
	public function modules(mixed ...$modules): self {$this->args['modules']=$modules;return $this;}
	public function profile(string $profile): self {$this->args['payload_profile']=$profile;return $this;}
	public function governance(?bool $include): self {$this->args['include_governance']=$include;return $this;}
	public function maxChunks(int $chunks): self {$this->args['max_chunks']=$chunks;return $this;}
	public function scaffold(string $type,string $name='',string $path=''): self {
		$this->args['scaffold_type']=$type;
		$this->args['name']=$name;
		$this->args['path']=$path;
		return $this;
	}
	public function field(string $field,mixed $value): self {$this->args[$field]=$value;return $this;}
	/** @return array<string,mixed> */
	public function toArgs(): array {return $this->args;}
}

/** Fluent, caller-owned proposed-change story for apply-audit planning. */
final class McpApplyAuditBuilder {
	/** @var array<string,mixed> */
	private array $args;

	private function __construct(string $task) {$this->args=['task'=>$task,'change_summary'=>'Focused application change.','proposed_files'=>[]];}
	public static function forTask(string $task='Implement the requested change.'): self {return new self($task);}
	public function summary(string $summary): self {$this->args['change_summary']=$summary;return $this;}
	public function files(mixed ...$files): self {$this->args['proposed_files']=$files;return $this;}
	public function verification(mixed ...$checks): self {$this->args['verification']=$checks;return $this;}
	public function risk(string $risk): self {$this->args['risk_level']=$risk;return $this;}
	public function field(string $field,mixed $value): self {$this->args[$field]=$value;return $this;}
	/** @return array<string,mixed> */
	public function toArgs(): array {return $this->args;}
}

/** Named task-pack and apply-audit planning evidence. */
final class McpTaskPackBoundaryHarness {
	private McpKernelHarness $kernel;

	public function __construct(private TestContext $context) {$this->kernel=new McpKernelHarness($context);}

	/** @return list<string> */
	public static function contractNames(): array {
		return [
			'task packs reject empty work and separate compact scaffold from governance',
			'proposed files normalize scope category and safety warnings consistently',
			'apply plans infer focused verification publication evidence and risk',
			'placement and risk classifiers distinguish app work from framework escalation',
			'module prompt and verification helpers explain the generated task context',
		];
	}

	/** @return array<string,mixed> */
	public function contract(string $name): array {
		if(!in_array($name,self::contractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP task-pack boundary contract: '.$name);
		}
		$evidence=match($name){
			'task packs reject empty work and separate compact scaffold from governance'=>$this->taskPackContract(),
			'proposed files normalize scope category and safety warnings consistently'=>$this->fileContract(),
			'apply plans infer focused verification publication evidence and risk'=>$this->applyPlanContract(),
			'placement and risk classifiers distinguish app work from framework escalation'=>$this->placementAndRiskContract(),
			'module prompt and verification helpers explain the generated task context'=>$this->promptContract(),
		};
		return ['contract'=>$name]+$evidence;
	}

	/** @return array<string,mixed> */
	private function taskPackContract(): array {
		$compact=$this->kernel->invoke('generate_task_pack',McpTaskPackBuilder::forTask('Build a project tracker admin panel with Project records.')
			->profile('unsupported')
			->governance(false)
			->maxChunks(0)
			->scaffold('panel_resource','Projects','applications/example/Projects.php')
			->toArgs());
		$governance=$this->kernel->invoke('generate_task_pack',McpTaskPackBuilder::forTask('Prepare a release-facing corporate-ready shared MCP workflow.')
			->profile('governance')
			->governance(null)
			->modules('mcp')
			->maxChunks(30)
			->toArgs());
		return [
			'missing_task'=>$this->exceptionMessage(fn()=> $this->kernel->invoke('generate_task_pack',[])),
			'compact'=>[
				'profile'=>$compact['payload_profile'] ?? null,
				'governance_inline'=>$compact['context_policy']['governance_inline'] ?? null,
				'governance_collapsed'=>$compact['governance_lane']['collapsed'] ?? null,
				'scaffold'=>$compact['scaffold_plan'] ?? null,
				'verification'=>$compact['verification'] ?? [],
				'prompt'=>$compact['prompt'] ?? '',
				'has_builder_plan'=>array_key_exists('builder_plan',$compact),
			],
			'governance'=>[
				'profile'=>$governance['payload_profile'] ?? null,
				'governance_inline'=>$governance['context_policy']['governance_inline'] ?? null,
				'governance_collapsed'=>$governance['governance_lane']['collapsed'] ?? null,
				'has_extension_boundary'=>array_key_exists('extension_boundary',$governance),
				'has_publication_validation'=>array_key_exists('publication_validation',$governance),
				'has_guardrails'=>array_key_exists('guardrails',$governance),
			],
		];
	}

	/** @return array<string,mixed> */
	private function fileContract(): array {
		$entryPaths=[
			'normalized'=>'dataphyre//runtime/modules/mcp/kernel/example.php',
			'parent'=>'../../outside/secret.env',
			'third_party'=>'runtime/vendor/package/file.php',
		];
		$entries=[];
		foreach($entryPaths as $name=>$path){$entries[$name]=$this->kernel->invoke('apply_audit_file_entry',$path);}
		$categories=[];
		foreach([
			'documentation'=>'docs/guide.md',
			'test'=>'runtime/modules/mcp/unit_tests/example.php',
			'php_source'=>'runtime/modules/mcp/kernel/example.php',
			'json_manifest'=>'manifest.json',
			'script'=>'scripts/check.sh',
			'other'=>'assets/logo.svg',
		] as $name=>$path){$categories[$name]=$this->kernel->invoke('apply_audit_file_category',$path);}
		return [
			'normalized'=>$this->kernel->invoke('apply_audit_normalized_path',' //common\\dataphyre//runtime///modules/mcp/example.php '),
			'scopes'=>[
				'dataphyre'=>$this->kernel->invoke('apply_audit_scope_path','./dataphyre/runtime/modules/mcp/example.php'),
				'common'=>$this->kernel->invoke('apply_audit_scope_path','././common/dataphyre//docs/guide.md'),
				'caller'=>$this->kernel->invoke('apply_audit_scope_path','applications/example/file.php'),
			],
			'membership'=>[
				'exact'=>$this->kernel->invoke('apply_audit_path_is_in_scope','runtime','runtime'),
				'child'=>$this->kernel->invoke('apply_audit_path_is_in_scope','runtime/modules/mcp','runtime'),
				'sibling'=>$this->kernel->invoke('apply_audit_path_is_in_scope','runtime-old/file.php','runtime'),
			],
			'package'=>[
				'runtime'=>$this->kernel->invoke('apply_audit_package_scope','runtime/modules/mcp/example.php'),
				'dev'=>$this->kernel->invoke('apply_audit_package_scope','dev/tools/example.php'),
				'docs'=>$this->kernel->invoke('apply_audit_package_scope','docs/guide.md'),
				'documentation'=>$this->kernel->invoke('apply_audit_package_scope','documentation/guide.md'),
				'caller'=>$this->kernel->invoke('apply_audit_package_scope','applications/example/file.php'),
			],
			'entries'=>$entries,
			'categories'=>$categories,
		];
	}

	/** @return array<string,mixed> */
	private function applyPlanContract(): array {
		$plan=$this->kernel->invoke('apply_audit_plan',McpApplyAuditBuilder::forTask('Audit a focused mixed-surface change.')
			->summary('Update SQL route auth and remove a stale credential path.')
			->files(
				'dataphyre/runtime/modules/mcp/kernel/example.php',
				'dataphyre/runtime/modules/panel/Framework/Example.php',
				'docs/guide.md',
				'RELEASE_MANIFEST',
				'applications/example/config/app.php',
				'scripts/deploy.sh',
				'manifest.json',
				'../../outside/secret.env',
				'runtime/node_modules/package/file.php',
				''
			)
			->verification('custom focused check','')
			->risk('unsupported')
			->toArgs());
		$hinted=$this->kernel->invoke('apply_audit_plan',McpApplyAuditBuilder::forTask('Document a low-risk change.')
			->files('docs/readme.md')
			->risk('medium')
			->toArgs());
		return [
			'missing_task'=>$this->exceptionMessage(fn()=> $this->kernel->invoke('apply_audit_plan',[])),
			'plan'=>$plan,
			'hinted'=>$hinted,
			'blank_readiness'=>$this->kernel->invoke('apply_runtime_readiness_plan',['task'=>'']),
			'readiness'=>$this->kernel->invoke('apply_runtime_readiness_plan',['task'=>'Continue safely']),
		];
	}

	/** @return array<string,mixed> */
	private function placementAndRiskContract(): array {
		$appFile=$this->kernel->invoke('apply_audit_file_entry','applications/example/Service.php');
		$docsFile=$this->kernel->invoke('apply_audit_file_entry','docs/guide.md');
		$runtimeFile=$this->kernel->invoke('apply_audit_file_entry','runtime/modules/mcp/kernel/example.php');
		$riskFiles=[
			$this->kernel->invoke('apply_audit_file_entry','applications/example/config/app.php'),
			$this->kernel->invoke('apply_audit_file_entry','scripts/deploy.sh'),
			$this->kernel->invoke('apply_audit_file_entry','../../outside/secret.pem'),
		];
		return [
			'next_actions'=>[
				'app'=>$this->kernel->invoke('apply_next_action','contract',[$appFile]),
				'docs'=>$this->kernel->invoke('apply_next_action','contract',[$docsFile]),
				'runtime'=>$this->kernel->invoke('apply_next_action','contract',[$runtimeFile]),
			],
			'risks'=>$this->kernel->invoke('apply_audit_risks',$riskFiles,'Delete SQL route token credentials.'),
			'levels'=>[
				'critical'=>$this->kernel->invoke('apply_audit_risk_level',['sensitive_file_type'],[]),
				'high'=>$this->kernel->invoke('apply_audit_risk_level',['configuration_surface'],[]),
				'medium_count'=>$this->kernel->invoke('apply_audit_risk_level',[],array_fill(0,6,[])),
				'medium_script'=>$this->kernel->invoke('apply_audit_risk_level',['script_surface'],[]),
				'low'=>$this->kernel->invoke('apply_audit_risk_level',[],[]),
			],
		];
	}

	/** @return array<string,mixed> */
	private function promptContract(): array {
		$lane=[
			'files_to_create'=>['one.php','two.php','three.php','four.php','five.php','six.php','seven.php','eight.php','nine.php'],
			'next_edits'=>['one','two','three','four','five','six'],
			'entities'=>['One','Two','Three','Four','Five','Six','Seven'],
			'entity_planning'=>['truncated'=>true,'deferred_entities'=>['Seven','Eight']],
			'data_model'=>['invalid',['table'=>'projects','artifact_paths'=>['schema.php','repository.php','resource.php','extra.php']]],
			'acceptance_criteria'=>['Create files.','Verify behavior.','Keep ownership local.','Ignore fourth.'],
		];
		$modules=$this->kernel->invoke('infer_task_modules','Build an admin panel route controller with SQL database MVC view Tracelog diagnostics and MCP stdio tools.');
		return [
			'modules'=>$modules,
			'app_modules'=>$this->kernel->invoke('infer_task_modules','Create an internal project tracker.'),
			'default_modules'=>$this->kernel->invoke('infer_task_modules','Polish an unrelated sentence.'),
			'prompt'=>$this->kernel->invoke('task_pack_prompt','Build the system.',$modules,['focused check'],$lane),
			'empty_prompt'=>$this->kernel->invoke('task_pack_prompt','Inspect first.',['mcp'],['dataphyre_php_lint'],[]),
			'data_model'=>$this->kernel->invoke('task_pack_data_model_summary',$lane),
			'empty_data_model'=>$this->kernel->invoke('task_pack_data_model_summary',[]),
			'invalid_data_model'=>$this->kernel->invoke('task_pack_data_model_summary',['data_model'=>['invalid']]),
			'acceptance'=>$this->kernel->invoke('task_pack_acceptance_summary',$lane),
			'empty_acceptance'=>$this->kernel->invoke('task_pack_acceptance_summary',[]),
			'verification'=>$this->kernel->invoke('task_pack_verification',['panel','routing','mvc','sql','tracelog'],['verification'=>['custom check']]),
		];
	}

	private function exceptionMessage(callable $operation): string {
		try{$operation();}catch(\Throwable $error){return $error->getMessage();}
		return '';
	}
}

/** Fluent release-check transcript fixture with stable failure families. */
final class McpReleaseOutputBuilder {
	/** @var list<string> */
	private array $lines=[];

	public static function create(): self {return new self();}
	public function noise(string $line='PASS: unrelated check'): self {$this->lines[]=$line;return $this;}
	public function failure(string $family,string $detail='fixture'): self {
		$message=match($family){
			'module_docs'=>$detail.' has no markdown documentation',
			'module_index'=>'MODULES.md is missing '.$detail,
			'invalid_json'=>'Invalid JSON in '.$detail,
			'license_wording'=>'Stale proprietary/license wording in '.$detail,
			'release_hygiene'=>'Release hygiene issue in '.$detail,
			'missing_spdx_headers'=>$detail.' missing MIT/SPDX header',
			default=>'Unclassified failure in '.$detail,
		};
		$this->lines[]='FAIL: '.$message;
		return $this;
	}
	public function everyFamily(): self {
		foreach(['module_docs','module_index','invalid_json','license_wording','release_hygiene','missing_spdx_headers','other'] as $family){$this->failure($family,$family.'-fixture');}
		return $this;
	}
	public function repeat(string $family,int $times): self {for($index=0;$index<$times;$index++){$this->failure($family,$family.'-'.$index);}return $this;}
	public function toString(): string {return implode("\n",$this->lines);}
}

/** Named release diagnostics, repair planning, and coupling-guard evidence. */
final class McpInspectionBoundaryHarness {
	private McpKernelHarness $kernel;

	public function __construct(private TestContext $context) {$this->kernel=new McpKernelHarness($context);}

	/** @return list<string> */
	public static function contractNames(): array {
		return [
			'release check executes the fixed local preflight with a deterministic verdict',
			'release output classifies every failure family while ignoring non-fail lines',
			'repair plans batch failures in priority order with bounded examples',
			'repair contracts keep priority action and verification metadata synchronized',
			'maintainer inspection surfaces remain read-only and internally bounded',
			'managed repository fixtures expose missing validators and coupling leaks safely',
		];
	}

	/** @return array<string,mixed> */
	public function contract(string $name): array {
		if(!in_array($name,self::contractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP inspection boundary contract: '.$name);
		}
		$evidence=match($name){
			'release check executes the fixed local preflight with a deterministic verdict'=>$this->predictionContract(),
			'release output classifies every failure family while ignoring non-fail lines'=>$this->classificationContract(),
			'repair plans batch failures in priority order with bounded examples'=>$this->repairPlanContract(),
			'repair contracts keep priority action and verification metadata synchronized'=>$this->repairCatalogContract(),
			'maintainer inspection surfaces remain read-only and internally bounded'=>$this->maintainerContract(),
			'managed repository fixtures expose missing validators and coupling leaks safely'=>$this->fixtureContract(),
		};
		return ['contract'=>$name]+$evidence;
	}

	/** @return array<string,mixed> */
	private function predictionContract(): array {
		$commands=[];
		$runner=$this->preflightRunner($commands);
		$args=[
			'project_root'=>\Dataphyre\Test\dataphyre_path(),
			'application'=>'fixture-app',
			'environment'=>'staging',
		];
		$invalidCommands=[];
		$base=$this->preflightPayload();
		$extraHealthEvidence=$base['checks'];
		$extraHealthEvidence[3]['evidence']['response_body']='SECRET_HEALTH_BODY_MUST_NOT_LEAK';
		$unsafeMissingKeys=$base['checks'];
		$unsafeMissingKeys[3]['evidence']['missing_environment_keys']=['ZZZ_SECRET','AAA_SECRET'];
		$valueBearingMissingKey=$base['checks'];
		$valueBearingMissingKey[3]['evidence']['missing_environment_keys']=['SERVE_SIGNING_KEY=SECRET_VALUE_MUST_NOT_LEAK'];
		$tooManyMissingKeys=$base['checks'];
		$tooManyMissingKeys[3]['evidence']['missing_environment_keys']=array_map(
			static fn(int $index): string=>sprintf('SERVE_KEY_%02d',$index),
			range(0,64)
		);
		$unhealthyPassed=$base['checks'];
		$unhealthyPassed[3]['evidence']['http_status']=503;
		$zeroAttemptPass=$base['checks'];
		$zeroAttemptPass[3]['evidence']['attempts']=0;
		$databaseRuntimeConnectionSha='sha256:'.str_repeat('d',64);
		$databaseRuntimeChecks=$base['checks'];
		$databaseRuntimeChecks[2]=[
			'id'=>'database_runtime',
			'status'=>'passed',
			'evidence'=>[
				'connection_sha256'=>$databaseRuntimeConnectionSha,
				'declared'=>true,
				'purpose'=>'primary',
			],
		];
		$databaseRuntimeFailureChecks=[
			$base['checks'][0],
			$base['checks'][1],
			[
				'id'=>'database_runtime',
				'status'=>'failed',
				'evidence'=>[
					'connection_sha256'=>null,
					'declared'=>true,
					'purpose'=>'primary',
				],
			],
		];
		$extraDatabaseRuntimeEvidence=$databaseRuntimeChecks;
		$extraDatabaseRuntimeEvidence[2]['evidence']['dsn']='SECRET_DATABASE_DSN_MUST_NOT_LEAK';
		$invalidDatabaseRuntimeHash=$databaseRuntimeChecks;
		$invalidDatabaseRuntimeHash[2]['evidence']['connection_sha256']='postgresql://SECRET_DATABASE_VALUE_MUST_NOT_LEAK';
		$contradictoryDatabaseRuntimeNotApplicable=$base['checks'];
		$contradictoryDatabaseRuntimeNotApplicable[2]['evidence']['declared']=true;
		$failedDatabaseRuntimeHash=$databaseRuntimeFailureChecks;
		$failedDatabaseRuntimeHash[2]['evidence']['connection_sha256']=$databaseRuntimeConnectionSha;
		$migrationManifest=[
			'algorithm'=>'sha256',
			'bootstrap_cutoff'=>'001_base',
			'migration_count'=>3,
			'schema_version'=>3,
			'sha256'=>str_repeat('a',64),
		];
		$migrationPlan=[
			'mode'=>'bootstrap',
			'eligible'=>true,
			'errors'=>[],
			'pending_migrations'=>['001_base','002_expand','003_contract'],
			'selected_migrations'=>['001_base','002_expand','003_contract'],
			'deferred_migrations'=>[],
			'rolling_scan'=>[
				'performed'=>false,
				'migration_count'=>0,
				'issue_count'=>0,
				'issues'=>[],
			],
		];
		$migrationChecks=$base['checks'];
		$migrationChecks[1]=[
			'id'=>'database_migrations',
			'status'=>'passed',
			'evidence'=>[
				'declared'=>true,
				'dry_run'=>true,
				'contract'=>'dataphyre.postgresql_migration_command.v1',
				'manifest'=>$migrationManifest,
				'plan'=>$migrationPlan,
			],
		];
		$sqliteMigrationChecks=$base['checks'];
		$sqliteMigrationChecks[1]=[
			'id'=>'database_migrations',
			'status'=>'passed',
			'evidence'=>[
				'contract'=>'dataphyre.sqlite_migration_command.v1',
				'declared'=>true,
				'dry_run'=>false,
				'engine'=>'sqlite',
				'manifest'=>[
					'algorithm'=>'sha256',
					'migration_count'=>3,
					'sha256'=>str_repeat('e',64),
				],
				'result'=>[
					'applied_migrations'=>['001_initial','002_expand','003_index'],
					'database_file'=>'tenant.sqlite',
					'pending_migrations'=>[],
				],
				'write_scope'=>'isolated_application_data',
			],
		];
		$sqlitePendingChecks=$sqliteMigrationChecks;
		$sqlitePendingChecks[1]['evidence']['result']['applied_migrations']=['001_initial','002_expand'];
		$sqlitePendingChecks[1]['evidence']['result']['pending_migrations']=['003_index'];
		$sqliteUnsafeDatabaseFile=$sqliteMigrationChecks;
		$sqliteUnsafeDatabaseFile[1]['evidence']['result']['database_file']='../tenant.sqlite';
		$sqliteOutOfOrder=$sqliteMigrationChecks;
		$sqliteOutOfOrder[1]['evidence']['result']['applied_migrations']=['001_initial','003_index','002_expand'];
		$migrationFailurePlan=[
			'mode'=>'rolling',
			'eligible'=>false,
			'errors'=>['pending_rolling_migrations_contain_incompatible_sql'],
			'pending_migrations'=>['002_expand','003_contract'],
			'selected_migrations'=>['002_expand'],
			'deferred_migrations'=>['003_contract'],
			'rolling_scan'=>[
				'performed'=>true,
				'migration_count'=>1,
				'issue_count'=>1,
				'issues'=>[ [
					'migration'=>'002_expand',
					'code'=>'add_not_null_column',
					'statement'=>1,
				] ],
			],
		];
		$migrationFailureChecks=[
			$base['checks'][0],
			[
				'id'=>'database_migrations',
				'status'=>'failed',
				'evidence'=>[
					'declared'=>true,
					'dry_run'=>true,
					'contract'=>'dataphyre.postgresql_migration_command.v1',
					'manifest'=>$migrationManifest,
					'plan'=>$migrationFailurePlan,
					'exit_status'=>70,
					'error_code'=>'migration_plan_ineligible',
				],
			],
		];
		$largeMigrationErrors=array_map(
			static fn(int $index): string=>'migration_state_status_not_deployable:'.sprintf('%03d_migration',$index),
			range(2,300)
		);
		$largeMigrationErrorPlan=[
			'mode'=>'rolling',
			'eligible'=>false,
			'errors'=>$largeMigrationErrors,
			'pending_migrations'=>[],
			'selected_migrations'=>[],
			'deferred_migrations'=>[],
			'rolling_scan'=>[
				'performed'=>true,
				'migration_count'=>0,
				'issue_count'=>0,
				'issues'=>[],
			],
		];
		$largeMigrationErrorChecks=[
			$base['checks'][0],
			[
				'id'=>'database_migrations',
				'status'=>'failed',
				'evidence'=>[
					'declared'=>true,
					'dry_run'=>true,
					'contract'=>'dataphyre.postgresql_migration_command.v1',
					'manifest'=>[
						'algorithm'=>'sha256',
						'bootstrap_cutoff'=>'001_migration',
						'migration_count'=>300,
						'schema_version'=>3,
						'sha256'=>str_repeat('b',64),
					],
					'plan'=>$largeMigrationErrorPlan,
					'exit_status'=>70,
					'error_code'=>'migration_plan_ineligible',
				],
			],
		];
		$largeRollingIssues=array_map(
			static fn(int $statement): array=>[
				'migration'=>'002_expand',
				'code'=>'add_not_null_column',
				'statement'=>$statement===1000 ? 2048 : $statement,
			],
			range(1,1000)
		);
		$largeRollingIssuePlan=[
			'mode'=>'rolling',
			'eligible'=>false,
			'errors'=>['pending_rolling_migrations_contain_incompatible_sql'],
			'pending_migrations'=>['002_expand'],
			'selected_migrations'=>['002_expand'],
			'deferred_migrations'=>[],
			'rolling_scan'=>[
				'performed'=>true,
				'migration_count'=>1,
				'issue_count'=>count($largeRollingIssues),
				'issues'=>$largeRollingIssues,
			],
		];
		$largeRollingIssueChecks=[
			$base['checks'][0],
			[
				'id'=>'database_migrations',
				'status'=>'failed',
				'evidence'=>[
					'declared'=>true,
					'dry_run'=>true,
					'contract'=>'dataphyre.postgresql_migration_command.v1',
					'manifest'=>[
						'algorithm'=>'sha256',
						'bootstrap_cutoff'=>'001_base',
						'migration_count'=>2,
						'schema_version'=>3,
						'sha256'=>str_repeat('c',64),
					],
					'plan'=>$largeRollingIssuePlan,
					'exit_status'=>70,
					'error_code'=>'migration_plan_ineligible',
				],
			],
		];
		$invalidMigrationPlan=$migrationFailurePlan;
		$invalidMigrationPlan['rolling_scan']['issues'][0]['private_sql']='SECRET_SQL_VALUE_MUST_NOT_LEAK';
		$invalidMigrationChecks=$migrationFailureChecks;
		$invalidMigrationChecks[1]['evidence']['plan']=$invalidMigrationPlan;
		$reorderedMigrationPlan=$migrationFailurePlan;
		$reorderedMigrationPlan['pending_migrations']=['003_contract','002_expand'];
		$reorderedMigrationChecks=$migrationFailureChecks;
		$reorderedMigrationChecks[1]['evidence']['plan']=$reorderedMigrationPlan;
		$unknownIssuePlan=$migrationFailurePlan;
		$unknownIssuePlan['rolling_scan']['issues'][0]['code']='unknown_sql_issue';
		$unknownIssueChecks=$migrationFailureChecks;
		$unknownIssueChecks[1]['evidence']['plan']=$unknownIssuePlan;
		$deferredIssuePlan=$migrationFailurePlan;
		$deferredIssuePlan['rolling_scan']['issues'][0]['migration']='003_contract';
		$deferredIssueChecks=$migrationFailureChecks;
		$deferredIssueChecks[1]['evidence']['plan']=$deferredIssuePlan;
		$mismatchedMigrationCodeChecks=$migrationFailureChecks;
		$mismatchedMigrationCodeChecks[1]['evidence']['error_code']='migration_failed';
		$duplicateMigrationErrorsPlan=$migrationFailurePlan;
		$duplicateMigrationErrorsPlan['errors'][]='pending_rolling_migrations_contain_incompatible_sql';
		$duplicateMigrationErrorsChecks=$migrationFailureChecks;
		$duplicateMigrationErrorsChecks[1]['evidence']['plan']=$duplicateMigrationErrorsPlan;
		$missingRollingErrorPlan=$migrationFailurePlan;
		$missingRollingErrorPlan['errors']=[];
		$missingRollingErrorChecks=$migrationFailureChecks;
		$missingRollingErrorChecks[1]['evidence']['plan']=$missingRollingErrorPlan;
		$wrongTypeMigrationExitChecks=$migrationFailureChecks;
		$wrongTypeMigrationExitChecks[1]['evidence']['exit_status']='70';
		$zeroMigrationIdChecks=$migrationChecks;
		$zeroMigrationIdChecks[1]['evidence']['manifest']['bootstrap_cutoff']='000_base';
		$duplicateRollingIssuePlan=$migrationFailurePlan;
		$duplicateRollingIssuePlan['rolling_scan']['issue_count']=2;
		$duplicateRollingIssuePlan['rolling_scan']['issues'][]=$duplicateRollingIssuePlan['rolling_scan']['issues'][0];
		$duplicateRollingIssueChecks=$migrationFailureChecks;
		$duplicateRollingIssueChecks[1]['evidence']['plan']=$duplicateRollingIssuePlan;
		$healthBootAfterRejectionChecks=array_slice($base['checks'],0,4);
		$healthBootAfterRejectionChecks[3]=[
			'id'=>'application_health',
			'status'=>'failed',
			'evidence'=>[
				'path'=>'/health',
				'loopback_only'=>true,
				'attempts'=>1,
				'http_status'=>503,
				'response_contract_valid'=>true,
				'missing_environment_keys'=>[],
			],
		];
		$healthFallbackChecks=array_slice($base['checks'],0,4);
		$healthFallbackChecks[3]=[
			'id'=>'application_health',
			'status'=>'failed',
			'evidence'=>[
				'path'=>'/health',
				'loopback_only'=>true,
				'attempts'=>0,
				'http_status'=>null,
				'response_contract_valid'=>false,
				'missing_environment_keys'=>[],
			],
		];
		$invalidMetadata=[];
		foreach([
			'wrong_application'=>['application'=>'another-app'],
			'wrong_environment'=>['environment'=>'production'],
			'wrong_contract_version'=>['contract_version'=>2],
			'wrong_execution'=>['execution'=>'not_started'],
			'wrong_execution_boundary'=>['execution_boundary'=>'caller_selected_command'],
			'wrong_write_policy'=>['write_policy'=>'arbitrary_process'],
			'wrong_claim_boundary'=>['claim_boundary'=>'Unbounded release claim.'],
			'extra_envelope_field'=>['secret_value'=>'SECRET_ENVELOPE_VALUE_MUST_NOT_LEAK'],
			'extra_database_runtime_evidence'=>['checks'=>$extraDatabaseRuntimeEvidence],
			'invalid_database_runtime_hash'=>['checks'=>$invalidDatabaseRuntimeHash],
			'contradictory_database_runtime_not_applicable'=>['checks'=>$contradictoryDatabaseRuntimeNotApplicable],
			'failed_database_runtime_hash'=>[
				'exit_status'=>69,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$failedDatabaseRuntimeHash,
				'failures'=>[ [
					'kind'=>'dependency',
					'code'=>'application_database_identity_failed',
					'message'=>'The application-resolved managed database identity could not be verified.',
				] ],
			],
			'extra_health_evidence'=>['checks'=>$extraHealthEvidence],
			'unsafe_missing_keys'=>['checks'=>$unsafeMissingKeys],
			'value_bearing_missing_key'=>['checks'=>$valueBearingMissingKey],
			'too_many_missing_keys'=>['checks'=>$tooManyMissingKeys],
			'unhealthy_passed_check'=>['checks'=>$unhealthyPassed],
			'zero_attempt_pass'=>['checks'=>$zeroAttemptPass],
			'sqlite_pending_migration'=>['checks'=>$sqlitePendingChecks],
			'sqlite_unsafe_database_file'=>['checks'=>$sqliteUnsafeDatabaseFile],
			'sqlite_out_of_order_journal'=>['checks'=>$sqliteOutOfOrder],
			'raw_migration_evidence'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$invalidMigrationChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_plan_ineligible',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'reordered_migration_plan'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$reorderedMigrationChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_plan_ineligible',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'unknown_migration_issue'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$unknownIssueChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_plan_ineligible',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'deferred_migration_issue'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$deferredIssueChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_plan_ineligible',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'mismatched_migration_code'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$mismatchedMigrationCodeChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_plan_ineligible',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'arbitrary_failure_message'=>[
				'exit_status'=>75,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$healthBootAfterRejectionChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'application_boot_failed',
					'message'=>'SECRET_FAILURE_DETAIL_MUST_NOT_LEAK',
				] ],
			],
			'mismatched_fixed_failure_message'=>[
				'exit_status'=>78,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>[],
				'failures'=>[ [
					'kind'=>'configuration',
					'code'=>'application_definition_missing',
					'message'=>'The database migration profile, manifest, or connection configuration is invalid.',
				] ],
			],
			'wrong_type_migration_exit'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$wrongTypeMigrationExitChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_plan_ineligible',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'zero_migration_id'=>['checks'=>$zeroMigrationIdChecks],
			'duplicate_rolling_issue'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$duplicateRollingIssueChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_plan_ineligible',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'duplicate_migration_errors'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$duplicateMigrationErrorsChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_plan_ineligible',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'missing_rolling_error'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>$missingRollingErrorChecks,
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_plan_ineligible',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'contractful_migration_fallback'=>[
				'exit_status'=>70,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>[
					$base['checks'][0],
					[
						'id'=>'database_migrations',
						'status'=>'failed',
						'evidence'=>[
							'declared'=>true,
							'dry_run'=>true,
							'contract'=>'dataphyre.postgresql_migration_command.v1',
							'manifest'=>[],
							'plan'=>[],
							'exit_status'=>42,
							'error_code'=>'migration_preflight_failed',
						],
					],
				],
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'migration_preflight_failed',
					'message'=>'The database migration preflight found drift or an ineligible migration plan.',
				] ],
			],
			'impossible_migration_child_tuple'=>[
				'exit_status'=>78,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>[
					$base['checks'][0],
					[
						'id'=>'database_migrations',
						'status'=>'failed',
						'evidence'=>[
							'declared'=>true,
							'dry_run'=>true,
							'contract'=>'dataphyre.postgresql_migration_command.v1',
							'manifest'=>[],
							'plan'=>[],
							'exit_status'=>65,
							'error_code'=>'profile_invalid',
						],
					],
				],
				'failures'=>[ [
					'kind'=>'configuration',
					'code'=>'profile_invalid',
					'message'=>'The database migration profile, manifest, or connection configuration is invalid.',
				] ],
			],
			'impossible_health_null_status'=>[
				'exit_status'=>75,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>[
					$base['checks'][0],
					$base['checks'][1],
					$base['checks'][2],
					[
						'id'=>'application_health',
						'status'=>'failed',
						'evidence'=>[
							'path'=>'/health',
							'loopback_only'=>true,
							'attempts'=>1,
							'http_status'=>null,
							'response_contract_valid'=>false,
							'missing_environment_keys'=>[],
						],
					],
				],
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'application_health_rejected',
					'message'=>'The application did not become healthy through the fixed loopback probe.',
				] ],
			],
			'impossible_health_invalid_attempts'=>[
				'exit_status'=>75,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>[
					$base['checks'][0],
					$base['checks'][1],
					$base['checks'][2],
					[
						'id'=>'application_health',
						'status'=>'failed',
						'evidence'=>[
							'path'=>'/health',
							'loopback_only'=>true,
							'attempts'=>0,
							'http_status'=>503,
							'response_contract_valid'=>false,
							'missing_environment_keys'=>[],
						],
					],
				],
				'failures'=>[ [
					'kind'=>'verification',
					'code'=>'application_health_evidence_invalid',
					'message'=>'The application did not become healthy through the fixed loopback probe.',
				] ],
			],
			'unsupported_exit'=>[
				'exit_status'=>71,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>[],
				'failures'=>[['kind'=>'verification','code'=>'unsupported_exit_fixture','message'=>'Unsupported exit fixture.']],
			],
		] as $name=>$overrides){
			$invalidMetadata[$name]=$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightRunner($invalidCommands, $overrides)
			);
		}
		$missingEnvironment=$this->preflightPayload([
			'exit_status'=>75,
			'ok'=>false,
			'likely_to_deploy'=>false,
			'checks'=>[
				$base['checks'][0],
				$base['checks'][1],
				$base['checks'][2],
				[
					'id'=>'application_health',
					'status'=>'failed',
					'evidence'=>[
						'path'=>'/health',
						'loopback_only'=>true,
						'attempts'=>3,
						'http_status'=>503,
						'response_contract_valid'=>true,
						'missing_environment_keys'=>['SERVE_SIGNING_KEY','SERVE_STAFF_SESSION_SECRET'],
					],
				],
			],
			'failures'=>[ [
				'kind'=>'verification',
				'code'=>'application_environment_keys_missing',
				'message'=>'The application did not become healthy through the fixed loopback probe.',
			] ],
		]);
		$stderrCommands=[];
		$stderrRunner=$this->preflightRunner($stderrCommands, [], 'SECRET_STDERR_VALUE_MUST_NOT_LEAK');
		return [
			'release'=>$this->kernel->invoke('run_release_check',$args,$runner),
			'commands'=>$commands,
			'configuration_failure'=>$this->kernel->invoke('run_release_check',$args,$this->failedPreflightRunner('configuration','flight_sheet_missing',78)),
			'invalid_executable_result'=>$this->kernel->invoke('run_release_check',$args,static fn(array $command): array=>['exit_code'=>0,'stdout'=>'not-json','stderr'=>'']),
			'invalid_metadata_results'=>$invalidMetadata,
			'missing_environment_failure'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($missingEnvironment)
			),
			'database_runtime_success'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload(['checks'=>$databaseRuntimeChecks]))
			),
			'database_runtime_failure'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload([
					'exit_status'=>69,
					'ok'=>false,
					'likely_to_deploy'=>false,
					'checks'=>$databaseRuntimeFailureChecks,
					'failures'=>[ [
						'kind'=>'dependency',
						'code'=>'application_database_identity_failed',
						'message'=>'The application-resolved managed database identity could not be verified.',
					] ],
				]))
			),
			'migration_success'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload(['checks'=>$migrationChecks]))
			),
			'sqlite_migration_success'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload(['checks'=>$sqliteMigrationChecks]))
			),
			'migration_failure'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload([
					'exit_status'=>70,
					'ok'=>false,
					'likely_to_deploy'=>false,
					'checks'=>$migrationFailureChecks,
					'failures'=>[ [
						'kind'=>'verification',
						'code'=>'migration_plan_ineligible',
						'message'=>'The database migration preflight found drift or an ineligible migration plan.',
					] ],
				]))
			),
			'large_migration_errors'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload([
					'exit_status'=>70,
					'ok'=>false,
					'likely_to_deploy'=>false,
					'checks'=>$largeMigrationErrorChecks,
					'failures'=>[ [
						'kind'=>'verification',
						'code'=>'migration_plan_ineligible',
						'message'=>'The database migration preflight found drift or an ineligible migration plan.',
					] ],
				]))
			),
			'large_rolling_issues'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload([
					'exit_status'=>70,
					'ok'=>false,
					'likely_to_deploy'=>false,
					'checks'=>$largeRollingIssueChecks,
					'failures'=>[ [
						'kind'=>'verification',
						'code'=>'migration_plan_ineligible',
						'message'=>'The database migration preflight found drift or an ineligible migration plan.',
					] ],
				]))
			),
			'health_boot_after_rejection'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload([
					'exit_status'=>75,
					'ok'=>false,
					'likely_to_deploy'=>false,
					'checks'=>$healthBootAfterRejectionChecks,
					'failures'=>[ [
						'kind'=>'verification',
						'code'=>'application_boot_failed',
						'message'=>'The application did not become healthy through the fixed loopback probe.',
					] ],
				]))
			),
			'health_fallback'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload([
					'exit_status'=>75,
					'ok'=>false,
					'likely_to_deploy'=>false,
					'checks'=>$healthFallbackChecks,
					'failures'=>[ [
						'kind'=>'verification',
						'code'=>'application_health_failed',
						'message'=>'The application did not become healthy through the fixed loopback probe.',
					] ],
				]))
			),
			'arbitrary_migration_exit'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload([
					'exit_status'=>70,
					'ok'=>false,
					'likely_to_deploy'=>false,
					'checks'=>[
						$base['checks'][0],
						[
							'id'=>'database_migrations',
							'status'=>'failed',
							'evidence'=>[
								'declared'=>true,
								'dry_run'=>true,
								'contract'=>'',
								'manifest'=>[],
								'plan'=>[],
								'exit_status'=>42,
								'error_code'=>'migration_preflight_failed',
							],
						],
					],
					'failures'=>[ [
						'kind'=>'verification',
						'code'=>'migration_preflight_failed',
						'message'=>'The database migration preflight found drift or an ineligible migration plan.',
					] ],
				]))
			),
			'zero_migration_exit'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				$this->preflightPayloadRunner($this->preflightPayload([
					'exit_status'=>70,
					'ok'=>false,
					'likely_to_deploy'=>false,
					'checks'=>[
						$base['checks'][0],
						[
							'id'=>'database_migrations',
							'status'=>'failed',
							'evidence'=>[
								'declared'=>true,
								'dry_run'=>true,
								'contract'=>'',
								'manifest'=>[],
								'plan'=>[],
								'exit_status'=>0,
								'error_code'=>'migration_preflight_failed',
							],
						],
					],
					'failures'=>[ [
						'kind'=>'verification',
						'code'=>'migration_preflight_failed',
						'message'=>'The database migration preflight found drift or an ineligible migration plan.',
					] ],
				]))
			),
			'hyphenated_environment'=>$this->kernel->invoke(
				'run_release_check',
				[
					'project_root'=>\Dataphyre\Test\dataphyre_path(),
					'application'=>'fixture-app',
					'environment'=>'preview-123',
				],
				$this->preflightPayloadRunner($this->preflightPayload(['environment'=>'preview-123']))
			),
			'profile_application_identifier'=>$this->kernel->invoke(
				'run_release_check',
				[
					'project_root'=>\Dataphyre\Test\dataphyre_path(),
					'application'=>'_fixture$worker',
					'environment'=>'staging',
				],
				$this->preflightPayloadRunner($this->preflightPayload(['application'=>'_fixture$worker']))
			),
			'stderr_is_ignored'=>$this->kernel->invoke('run_release_check',$args,$stderrRunner),
			'oversized_runner_result'=>$this->kernel->invoke(
				'run_release_check',
				$args,
				static fn(array $command): array=>[
					'exit_code'=>0,
					'stdout'=>'SECRET_OVERSIZED_VALUE_MUST_NOT_LEAK'.str_repeat('x', 530000),
					'stderr'=>'SECRET_STDERR_VALUE_MUST_NOT_LEAK',
				]
			),
			'bounded_process'=>$this->kernel->invoke(
				'run_release_preflight_command',
				[
					\Dataphyre\Test\PhpRuntime::binary(),
					'-r',
					'fwrite(STDOUT,str_repeat("x",530000)); fwrite(STDERR,"SECRET_CHILD_STDERR_MUST_NOT_LEAK");',
				],
				5000
			),
			'stderr_process'=>$this->kernel->invoke(
				'run_release_preflight_command',
				[
					\Dataphyre\Test\PhpRuntime::binary(),
					'-r',
					'fwrite(STDERR,str_repeat("PRIVATE_STDERR_VALUE_MUST_NOT_LEAK",50000)); fwrite(STDOUT,"{\\"ok\\":true}");',
				],
				5000
			),
		];
	}

	/** @param list<list<string>> $commands @param array<string,mixed> $overrides */
	private function preflightRunner(array &$commands, array $overrides=[], string $stderr=''): \Closure {
		$payload=$this->preflightPayload($overrides);
		$exitStatus=is_int($payload['exit_status'] ?? null) ? $payload['exit_status'] : 0;
		return static function(array $command) use (&$commands, $payload, $exitStatus, $stderr): array {
			$commands[]=$command;
			return [
				'exit_code'=>$exitStatus,
				'stdout'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
				'stderr'=>$stderr,
			];
		};
	}

	/** @param array<string,mixed> $overrides @return array<string,mixed> */
	private function preflightPayload(array $overrides=[]): array {
		return array_replace([
			'contract'=>'dataphyre.application_release_preflight.v1',
			'contract_version'=>1,
			'exit_status'=>0,
			'ok'=>true,
			'likely_to_deploy'=>true,
			'application'=>'fixture-app',
			'environment'=>'staging',
			'execution'=>'completed',
			'execution_boundary'=>'fixed_dataphyre_commands_and_loopback_application_boot',
			'write_policy'=>'isolated_database_preflight_and_ephemeral_application_boot',
			'checks'=>[
				[
					'id'=>'configuration_bootstrap',
					'status'=>'passed',
					'evidence'=>[
						'application_layout'=>'standalone_application_root',
						'application_definition'=>true,
						'flight_sheet'=>true,
						'runtime_bootstrap'=>true,
					],
				],
				[
					'id'=>'database_migrations',
					'status'=>'not_applicable',
					'evidence'=>[
						'declared'=>false,
						'reason'=>'no_database_migration_profile',
					],
				],
				[
					'id'=>'database_runtime',
					'status'=>'not_applicable',
					'evidence'=>[
						'connection_sha256'=>null,
						'declared'=>false,
						'purpose'=>null,
					],
				],
				[
					'id'=>'application_health',
					'status'=>'passed',
					'evidence'=>[
						'path'=>'/health',
						'loopback_only'=>true,
						'attempts'=>1,
						'http_status'=>200,
						'response_contract_valid'=>true,
						'missing_environment_keys'=>[],
					],
				],
				[
					'id'=>'realtime_registration',
					'status'=>'passed',
					'evidence'=>[
						'authorization_before_upgrade'=>true,
						'fixed_public_port'=>8080,
						'origin_required'=>true,
						'private_web_port'=>8083,
						'registration_sha256'=>'sha256:'.hash('sha256','[]'),
						'registered_table_count'=>0,
						'registered_table_materialization_contract'=>'dataphyre.registered_table_materialization.v1',
						'registered_table_set_sha256'=>'sha256:'.hash('sha256','[]'),
						'route_count'=>0,
						'scheduler_definition_count'=>0,
						'scheduler_definition_sha256'=>'sha256:'.hash('sha256','[]'),
						'tls_termination'=>'platform_edge',
					],
				],
			],
			'failures'=>[],
			'claim_boundary'=>'This verdict covers local configuration bootstrap, the native PostgreSQL migration dry-run or isolated SQL-only SQLite apply when declared, application startup against the same database state, GET /health, and deterministic realtime callback, scheduler definition, and registered table-definition inventories. A release platform must run this same command inside the exact candidate image and separately prove the ordered schema stages (declared application migrations first, then fixed registered-table materialization), the three fixed process identities, scheduler callback execution, a framework listener roundtrip, execution and strict invalid-Origin rejection by every registered application authorization callback, WebSocket ping/pong and close, signal lifecycle, persistent application-data binding, and source, image, environment, database, and traffic identity.',
		], $overrides);
	}

	/** @param array<string,mixed> $payload */
	private function preflightPayloadRunner(array $payload, string $stderr=''): \Closure {
		$exitStatus=is_int($payload['exit_status'] ?? null) ? $payload['exit_status'] : 0;
		return static fn(array $command): array=>[
				'exit_code'=>$exitStatus,
				'stdout'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
				'stderr'=>$stderr,
			];
	}

	private function failedPreflightRunner(string $kind,string $code,int $exitStatus): \Closure {
		$message=$code==='application_realtime_registration_failed'
			? 'The application realtime callbacks, scheduler definitions, or registered table definitions did not load through the fixed framework bootstrap.'
			: match($exitStatus){
			64=>'Use only the documented typed application release preflight options.',
			66=>'The selected application project root is unavailable.',
			69=>'The configured database dependency could not be verified.',
			70=>'The database migration preflight found drift or an ineligible migration plan.',
			75=>'The application did not become healthy through the fixed loopback probe.',
			default=>'The application bootstrap configuration is incomplete or invalid.',
		};
		return $this->preflightPayloadRunner($this->preflightPayload([
				'exit_status'=>$exitStatus,
				'ok'=>false,
				'likely_to_deploy'=>false,
				'checks'=>[],
				'failures'=>[['kind'=>$kind,'code'=>$code,'message'=>$message]],
			]));
	}

	/** @return array<string,mixed> */
	private function classificationContract(): array {
		$output=McpReleaseOutputBuilder::create()->noise()->everyFamily()->repeat('module_docs',1)->toString();
		$categories=$this->kernel->invoke('categorize_release_failures',$output);
		return [
			'categories'=>$categories,
			'category_order'=>array_keys($categories),
			'failure_count'=>array_sum(array_map('count',$categories)),
			'empty'=>$this->kernel->invoke('categorize_release_failures',"PASS: clean\nnoise"),
		];
	}

	/** @return array<string,mixed> */
	private function repairPlanContract(): array {
		$output=McpReleaseOutputBuilder::create()->everyFamily()->repeat('invalid_json',3)->toString();
		$plan=$this->kernel->invoke('release_fix_plan',['release_output'=>$output,'max_examples_per_batch'=>2]);
		$boundary=$this->kernel->invoke('release_fix_plan',['release_output'=>$output,'max_examples_per_batch'=>99]);
		$fallback=$this->kernel->invoke('release_fix_plan',['release_output'=>$output,'max_examples_per_batch'=>0]);
		$empty=$this->kernel->invoke('release_fix_plan',[]);
		return [
			'plan'=>$plan,
			'batch_categories'=>array_values(array_map(static fn(array $batch): string=>(string)($batch['category'] ?? ''),$plan['batches'] ?? [])),
			'invalid_examples'=>count($plan['batches'][2]['examples'] ?? []),
			'bounded_examples'=>count($boundary['batches'][2]['examples'] ?? []),
			'fallback_examples'=>count($fallback['batches'][2]['examples'] ?? []),
			'empty'=>$empty,
		];
	}

	/** @return array<string,mixed> */
	private function repairCatalogContract(): array {
		$contracts=[];
		foreach(['module_index','module_docs','invalid_json','missing_spdx_headers','license_wording','release_hygiene','other'] as $category){
			$contract=$this->kernel->invoke('release_fix_contract',$category);
			$contracts[$category]=[
				'contract'=>$contract,
				'priority'=>$this->kernel->invoke('release_fix_priority',$category),
				'action'=>$this->kernel->invoke('release_fix_action',$category),
				'verification'=>$this->kernel->invoke('release_fix_verification',$category),
			];
		}
		return [
			'categories'=>$this->kernel->invoke('release_failure_categories'),
			'repair_order'=>$this->kernel->invoke('release_fix_category_order'),
			'contracts'=>$contracts,
		];
	}

	/** @return array<string,mixed> */
	private function maintainerContract(): array {
		$commands=[];
		$runner=$this->preflightRunner($commands);
		$args=['project_root'=>\Dataphyre\Test\dataphyre_path(),'application'=>'fixture-app','environment'=>'staging'];
		$release=$this->kernel->invoke('run_release_check',$args,$runner);
		$triage=$this->kernel->invoke('release_triage_summary',$args,$runner);
		$doctor=$this->kernel->invoke('mcp_doctor');
		$couplingLeaks=$this->kernel->invoke('mcp_app_coupling_leaks');
		$verify=$this->kernel->invoke('mcp_verify_all');
		return [
			'release'=>$release,
			'triage'=>$triage,
			'doctor'=>[
				'passed'=>$doctor['passed'] ?? null,
				'failed_count'=>$doctor['failed_count'] ?? null,
				'check_count'=>count($doctor['checks'] ?? []),
				'failed_names'=>array_values(array_map(static fn(array $check): string=>(string)($check['name'] ?? ''),array_filter($doctor['checks'] ?? [],static fn(array $check): bool=>($check['passed'] ?? false)!==true))),
				'coupling_leaks'=>$couplingLeaks,
			],
			'verify'=>[
				'passed'=>$verify['passed'] ?? null,
				'failed_count'=>$verify['failed_count'] ?? null,
				'step_count'=>$verify['step_count'] ?? null,
				'step_names'=>array_values(array_map(static fn(array $step): string=>(string)($step['name'] ?? ''),$verify['steps'] ?? [])),
				'failed_names'=>array_values(array_map(static fn(array $step): string=>(string)($step['name'] ?? ''),array_filter($verify['steps'] ?? [],static fn(array $step): bool=>($step['passed'] ?? false)!==true))),
				'failed_results'=>array_values(array_map(static function(array $step): array {
					$result=is_array($step['result']['result'] ?? null) ? $step['result']['result'] : (is_array($step['result'] ?? null) ? $step['result'] : []);
					return [
						'name'=>(string)($step['name'] ?? ''),
						'exit_code'=>$result['exit_code'] ?? null,
						'stdout'=>substr((string)($result['stdout'] ?? ''),-1200),
						'stderr'=>substr((string)($result['stderr'] ?? ''),-1200),
					];
				},array_filter($verify['steps'] ?? [],static fn(array $step): bool=>($step['passed'] ?? false)!==true))),
			],
		];
	}

	/** @return array<string,mixed> */
	private function fixtureContract(): array {
		$missingWorkspace=$this->context->workspace('mcp-missing-validator-repository');
		$missingKernel=(new McpKernelHarness($this->context))->useRepositoryRoot($missingWorkspace->root());
		$workspace=$this->context->workspace('mcp-coupling-repository');
		$coupled='sho'.'piro';
		$workspace->file('dataphyre/runtime/modules/mcp/leaky.php','<?php // '.$coupled);
		$workspace->file('dataphyre/dev/tools/public/mcp_config.php','<?php // '.$coupled);
		$workspace->file('dataphyre/dev/tools/public/mcp_self_test.php','<?php // clean');
		$workspace->file('dataphyre/dev/tools/public/mcp_live_validate.php','<?php // clean');
		$fixtureKernel=(new McpKernelHarness($this->context))->useRepositoryRoot($workspace->root());
		return [
			'missing_validator'=>$this->exceptionMessage(fn()=> $missingKernel->invoke('mcp_live_validate')),
			'leaks'=>$fixtureKernel->invoke('mcp_app_coupling_leaks'),
		];
	}

	private function exceptionMessage(callable $operation): string {
		try{$operation();}catch(\Throwable $error){return $error->getMessage();}
		return '';
	}
}

/** Generates bounded, schema-valid representative arguments for every tool. */
final class McpToolArguments {
	/** @param array<string,mixed> $tool @return array<string,mixed> */
	public static function representative(array $tool): array {
		$schema=is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
		$properties=is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
		$required=array_fill_keys(array_map('strval',is_array($schema['required'] ?? null) ? $schema['required'] : []),true);
		$arguments=[];
		foreach($properties as $name=>$property){
			if(!is_array($property)){continue;}
			$value=self::value((string)$name,$property);
			if(isset($required[(string)$name]) || $value!==null){$arguments[(string)$name]=$value;}
		}
		if(($tool['name']??null)==='dataphyre_panel_capability_describe'){$arguments['id']='panel:domain:studio';}
		return $arguments;
	}

	/**
	 * Produces named, schema-valid behavioral variants without a Cartesian
	 * explosion. Required-only and fully-engaged shapes cover optionality; each
	 * recognized semantic axis is then varied independently.
	 *
	 * @param array<string,mixed> $tool
	 * @return array<string,array<string,mixed>>
	 */
	public static function contractVariants(array $tool): array {
		$name=(string)($tool['name'] ?? '');
		$schema=is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
		$properties=is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
		$required=array_fill_keys(array_map('strval',is_array($schema['required'] ?? null) ? $schema['required'] : []),true);
		$requiredOnly=[];
		$engaged=[];
		foreach($properties as $propertyName=>$property){
			if(!is_array($property)){continue;}
			$propertyName=(string)$propertyName;
			$value=self::engagedValue($name,$propertyName,$property);
			$engaged[$propertyName]=$value;
			if(isset($required[$propertyName])){$requiredOnly[$propertyName]=$value;}
		}
		$variants=['required-only'=>$requiredOnly,'fully-engaged'=>$engaged];
		foreach($properties as $propertyName=>$property){
			$propertyName=(string)$propertyName;
			foreach(self::axisValues($propertyName) as $label=>$value){
				$variant=$engaged;
				$variant[$propertyName]=$value;
				$variants[$propertyName.'-'.$label]=$variant;
			}
		}
		foreach($variants as $label=>$arguments){
			$variants[$label]=self::normalizeToolVariant($name,$arguments);
		}
		$unique=[];
		$seen=[];
		foreach($variants as $label=>$arguments){
			$key=json_encode($arguments,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
			if(!is_string($key) || isset($seen[$key])){continue;}
			$seen[$key]=true;
			$unique[$label]=$arguments;
		}
		return $unique;
	}

	/** @param array<string,mixed> $arguments @return array<string,mixed> */
	private static function normalizeToolVariant(string $tool,array $arguments): array {
		if($tool!=='dataphyre_sql_migration_scaffold_plan'){
			return $arguments;
		}
		$values=[
			'application_id'=>'example_app',
			'schema'=>'example_app',
			'journal_table'=>'schema_migrations',
			'event_table'=>'schema_migration_events',
			'release_digest_column'=>'release_sha256',
			'advisory_lock'=>'example_app.postgresql_migrations',
			'bootstrap_cutoff'=>'001_schema_baseline',
			'migration_id'=>'002_add_status',
			'phase'=>'rolling_expand',
			'description'=>'Add a nullable status column.',
			'up_sql'=>'ALTER TABLE example_app.items ADD COLUMN status TEXT;',
			'manifest_public_path'=>'database/postgresql/manifest.json',
			'lock_timeout'=>'5s',
			'statement_timeout'=>'120s',
		];
		foreach($values as $name=>$value){
			if(array_key_exists($name,$arguments)){
				$arguments[$name]=$value;
			}
		}
		if(array_key_exists('bootstrap_ids',$arguments)){
			$arguments['bootstrap_ids']=['001_schema_baseline'];
		}
		if(array_key_exists('database_root',$arguments)){
			$arguments['database_root']='dataphyre';
		}
		unset($arguments['minimum_compatible_release']);
		if(array_key_exists('down_sql',$arguments)){
			$arguments['down_sql']='ALTER TABLE example_app.items DROP COLUMN status;';
			$arguments['down_safety']='lossless';
			unset($arguments['irreversible_reason']);
		}else{
			unset($arguments['down_safety']);
			$arguments['irreversible_reason']='No reversible operation is available.';
		}
		return $arguments;
	}

	/** @param array<string,mixed> $schema */
	private static function engagedValue(string $tool,string $name,array $schema): mixed {
		$enum=is_array($schema['enum'] ?? null) ? array_values($schema['enum']) : [];
		if($enum!==[]){return $enum[array_key_last($enum)];}
		return match((string)($schema['type'] ?? 'string')){
			'boolean'=>true,
			'integer'=>(int)max((int)($schema['minimum'] ?? 1),min(8,(int)($schema['maximum'] ?? 8))),
			'number'=>(float)max((float)($schema['minimum'] ?? 1),min(8,(float)($schema['maximum'] ?? 8))),
			'array'=>self::engagedArrayValue($tool,$name,$schema),
			'object'=>self::engagedObjectValue($name),
			default=>self::engagedStringValue($tool,$name),
		};
	}

	/** @return list<mixed> */
	private static function engagedArrayValue(string $tool,string $name,array $schema): array {
		$itemEnum=is_array($schema['items']['enum']??null)?array_values($schema['items']['enum']):[];
		if($itemEnum!==[]){return array_values(array_map('strval',$itemEnum));}
		return match($name){
			'keys'=>str_contains($tool,'config_value_preview')?['default_disk','disks.local.driver']:['primary','secondary'],
			'roots'=>['studio','realtime'],
			'domains'=>str_contains($tool,'panel_')?['studio','realtime']:['primary','secondary'],
			'changed_paths'=>['runtime/modules/panel/Framework/Studio/PanelStudioManager.php'],
			'entities'=>['Organization','Workspace','User','Project','Ticket','Subscription','Invoice','Webhook'],
			'modules'=>str_contains($tool,'contract_') || str_contains($tool,'unit_tests_list') || str_contains($tool,'verification_surface_catalog')
				? ['mcp']
				: ['mcp','testing','routing','panel','sql'],
			'paths'=>str_contains($tool,'php_lint')
				? ['dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php']
				: ['dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php','dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md'],
			'languages'=>['en','fr'],
			'capabilities'=>['tools','resources','prompts'],
			'methods'=>['GET','POST','PATCH','DELETE'],
			'names'=>str_contains($tool,'skill') ? ['dataphyre-runtime-guidelines'] : (str_contains($tool,'prompt') ? ['dataphyre_feature_plan'] : ['Project','Ticket']),
			default=>['primary','secondary'],
		};
	}

	/** @return array<string,mixed> */
	private static function engagedObjectValue(string $name): array {
		return match($name){
			'fields'=>[
				'Project'=>[
					'tenant_id'=>['type'=>'integer','required'=>true,'foreign_key_target'=>'tenants'],
					'owner_id'=>['type'=>'integer','foreign_key_target'=>'users'],
					'name'=>['type'=>'string','required'=>true],
					'status'=>['type'=>'string','options'=>['draft','active','archived'],'default'=>'draft'],
					'amount_minor'=>['type'=>'integer'],
					'currency'=>['type'=>'string','default'=>'USD'],
					'customer_email'=>['type'=>'string'],
					'encrypted_secret_ref'=>['type'=>'string'],
					'retention_until'=>['type'=>'datetime'],
					'approved_by'=>['type'=>'integer','foreign_key_target'=>'users'],
				],
				'Ticket'=>[
					'project_id'=>['type'=>'integer','required'=>true,'foreign_key_target'=>'projects'],
					'assignee_id'=>['type'=>'integer','foreign_key_target'=>'users'],
					'title'=>['type'=>'string','required'=>true],
					'priority'=>['type'=>'string','options'=>['low','normal','high'],'default'=>'normal'],
					'due_at'=>['type'=>'datetime'],
				],
			],
			'dependency_context'=>['policy_context'=>['tenant'=>'tenant_id','actor'=>'owner_id','entitlement'=>'project.manage']],
			'parameters'=>['id'=>7,'include'=>'relationships'],
			'query'=>['page'=>2,'limit'=>8,'status'=>'active'],
			'context'=>['task'=>'Build a multi-tenant governed application','risk'=>'critical'],
			default=>['enabled'=>true,'mode'=>'strict'],
		};
	}

	private static function engagedStringValue(string $tool,string $name): string {
		return match($name){
			'id'=>str_contains($tool,'contract_describe')?'test:mcp.protocol.discovery@1':(str_contains($tool,'panel_capability_describe')?'panel:domain:studio':'engaged-example'),
			'task'=>'Build a multi-tenant API and Panel workflow for Projects and Tickets with users, subscriptions, billing, approvals, audit retention, privacy, security incidents, notifications, analytics, imports, exports, idempotent webhooks, retries, rollback, and support diagnostics.',
			'application_path'=>'applications/example/backend/dataphyre',
			'app_namespace'=>'ExampleApp',
			'path'=>match(true){
				str_contains($tool,'config_shape'),str_contains($tool,'config_value')=>'dataphyre/config/storage.example.php',
				str_contains($tool,'tracelog_read')=>'dataphyre/runtime/modules/mcp/testing/fixtures/semantic-tracelog.log',
				str_contains($tool,'unit_test_manifest_read')=>'dataphyre/runtime/modules/access/unit_tests/dataphyre.access.userid.json',
				default=>'dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md',
			},
			'config_path'=>str_contains($tool,'sql_') ? 'dataphyre/runtime/modules/mcp/testing/fixtures/config/sql.php' : 'dataphyre/config/storage.example.php',
			'manifest_path'=>'dataphyre/runtime/modules/mcp/testing/fixtures/semantic-route-manifest.php',
			'name'=>str_contains($tool,'route_url_preview') ? 'semantic.route' : 'engaged-example',
			'query'=>'testing coverage routing diagnostics',
			'module'=>'mcp',
			'target'=>'Project',
			'language'=>'fr',
			'payload_profile','profile'=>'full',
			'format'=>'markdown',
			'provider'=>'local-provider',
			'model'=>'local-model',
			'base_url'=>'https://docs.example.test',
			'uri'=>'dataphyre://mcp-capabilities',
			default=>'engaged-example',
		};
	}

	/** @return array<string,mixed> */
	private static function axisValues(string $name): array {
		return match($name){
			'payload_profile'=>['compact'=>'compact','builder'=>'builder','detail'=>'detail','deep'=>'deep','governance'=>'governance','full'=>'full'],
			'profile'=>['compact'=>'compact','detail'=>'detail','deep'=>'deep','governance'=>'governance','full'=>'full'],
			'detail_page'=>['planning'=>'planning','implementation'=>'implementation','verification'=>'verification','controls'=>'controls','governance'=>'governance'],
			'target'=>['codex'=>'codex','claude'=>'claude','cursor'=>'cursor','generic'=>'generic','all'=>'all'],
			'format'=>['json'=>'json','markdown'=>'markdown'],
			'scaffold_type','type'=>['panel'=>'panel_resource','api'=>'api_endpoint','sql'=>'sql_table','routing'=>'routing_controller','mvc'=>'mvc_controller','module'=>'runtime_module'],
			'auth'=>['none'=>'none','jwt'=>'jwt','api-key'=>'api_key','session'=>'session','custom'=>'custom'],
			'field_scope'=>['chunk'=>'chunk_entities','all'=>'all_entities'],
			default=>[],
		};
	}

	/** @param array<string,mixed> $schema */
	private static function value(string $name,array $schema): mixed {
		if(array_key_exists('default',$schema)){return $schema['default'];}
		$enum=is_array($schema['enum'] ?? null) ? array_values($schema['enum']) : [];
		if($enum!==[]){return $enum[0];}
		$type=(string)($schema['type'] ?? 'string');
		return match($type){
			'boolean'=>false,
			'integer'=>(int)max((int)($schema['minimum'] ?? 0),min(2,(int)($schema['maximum'] ?? 2))),
			'number'=>(float)max((float)($schema['minimum'] ?? 0),min(1,(float)($schema['maximum'] ?? 1))),
			'array'=>self::arrayValue($name,is_array($schema['items'] ?? null) ? $schema['items'] : []),
			'object'=>self::objectValue($name),
			default=>self::stringValue($name),
		};
	}

	/** @param array<string,mixed> $item @return list<mixed> */
	private static function arrayValue(string $name,array $item): array {
		return match($name){
			'roots'=>['studio'],
			'domains'=>['studio'],
			'changed_paths'=>['runtime/modules/panel/Framework/Studio/PanelStudioManager.php'],
			'modules'=>['mcp'],
			'paths'=>['dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php'],
			'entities'=>['Project','Task'],
			'languages'=>['en'],
			'capabilities'=>['tools'],
			default=>[$item!==[] ? self::value($name.'_item',$item) : 'example'],
		};
	}

	/** @return array<string,mixed> */
	private static function objectValue(string $name): array {
		return match($name){
			'fields'=>['Project'=>['name'=>'string required','status'=>'enum active,archived default active']],
			'parameters'=>['id'=>1],
			'query'=>['page'=>1],
			'context'=>['task'=>'Build a small project tracker'],
			default=>[],
		};
	}

	private static function stringValue(string $name): string {
		return match($name){
			'module'=>'mcp',
			'query'=>'testing coverage',
			'path'=>'dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md',
			'manifest_path'=>'dataphyre/runtime/modules/routing/unit_tests/fixtures/routes.php',
			'application_path'=>'applications/example/backend/dataphyre',
			'app_namespace'=>'ExampleApp',
			'task'=>'Build a small project tracker with Project and Task resources',
			'target'=>'Project',
			'language'=>'en',
			'payload_profile'=>'compact',
			'profile'=>'compact',
			'format'=>'json',
			'provider'=>'local-provider',
			'model'=>'local-model',
			'base_url'=>'https://docs.example.test',
			'uri'=>'dataphyre://mcp-capabilities',
			default=>'example',
		};
	}
}

/** Result and reusable assertions for an exhaustive registered-tool run. */
final class McpToolMatrixReport {
	/** @param list<array<string,mixed>> $tools @param list<array{id:string,tool:string,variant:string}> $calls */
	public function __construct(private array $tools,private array $calls,private McpTranscript $transcript) {}

	public function assertComplete(Context $t,float $minimumSuccessFraction=0.0): void {
		$names=[];
		foreach($this->tools as $tool){
			$name=(string)($tool['name'] ?? '');
			$t->notEmpty($name);
			$t->hasKey('inputSchema',$tool,$name);
			foreach(['type','properties','required'] as $key){$t->hasKey($key,$tool['inputSchema'],$name);}
			$names[]=$name;
		}
		$t->same($names,array_values(array_unique($names)));
		$t->count(count($this->calls),$this->transcript->messages());
		$t->greaterThan(0,count($this->transcript->processes()));
		$successful=0;
		$boundedErrors=[];
		foreach($this->calls as $call){
			$response=$this->transcript->response($call['id']);
			if(isset($response['result'])){
				$t->hasKey('content',$response['result'],$call['id']);
				$t->same('text',$response['result']['content'][0]['type'],$call['id']);
				$t->isTrue(mcp_test_json_validate($response['result']['content'][0]['text']),$call['id']);
				$successful++;
				continue;
			}
			$message=(string)($response['error']['message'] ?? '');
			$t->notContains('Unknown Dataphyre tool',$message,$call['id']);
			$t->notContains('Missing required tool argument',$message,$call['id']);
			$boundedErrors[$call['id']]=$message;
		}
		$minimum=(int)floor(count($this->calls)*$minimumSuccessFraction);
		$t->greaterThan($minimum,$successful,json_encode($boundedErrors,JSON_UNESCAPED_SLASHES));
	}
}

/** One-line executable contract matrices derived from the live MCP registry. */
final class McpToolContractMatrix {
	private const SOURCE_INDEXED_TOOLS=[
		'dataphyre_unit_tests_list',
		'dataphyre_unit_test_manifest_read',
		'dataphyre_contract_catalog',
		'dataphyre_contract_describe',
		'dataphyre_panel_capability_catalog',
		'dataphyre_panel_capability_describe',
		'dataphyre_panel_surface_graph',
		'dataphyre_panel_recipe_plan',
		'dataphyre_panel_integration_plan',
		'dataphyre_panel_verification_plan',
	];
	private McpScenario $scenario;

	public function __construct(private TestContext $context) {$this->scenario=new McpScenario($context);}

	public function representative(?int $batchSize=null): McpToolMatrixReport {
		$batchSize??=(PHP_SAPI==='phpdbg' || function_exists('xdebug_get_code_coverage'))?5:20;
		return $this->run(static fn(array $tool): array=>['representative'=>McpToolArguments::representative($tool)],$batchSize);
	}

	/** @return list<string> */
	public static function registeredToolNamesFromSource(): array {
		$source=file(dirname(__DIR__).'/kernel/dataphyre_mcp.registry.php',FILE_IGNORE_NEW_LINES);
		if(!is_array($source)){throw new RuntimeException('Unable to read the MCP tool dispatcher catalog.');}
		$insideMatch=false;
		$names=[];
		foreach($source as $line){
			if(!$insideMatch){$insideMatch=str_contains($line,'match($name)');continue;}
			if(preg_match('/^\s*default\s*=>/',$line)===1){break;}
			if(preg_match("/^\\s*'([^']+)'\\s*=>/",$line,$matches)===1){$names[]=(string)$matches[1];}
		}
		$names=array_values(array_unique($names));
		if($names===[]){throw new RuntimeException('The MCP tool dispatcher catalog has no discoverable match arms.');}
		return $names;
	}

	/** @param list<string>|null $toolNames */
	public function semanticVariants(?int $batchSize=null,?array $toolNames=null): McpToolMatrixReport {
		$exactCoverage=PHP_SAPI==='phpdbg' || function_exists('xdebug_get_code_coverage');
		$largePayloadTools=[
			'dataphyre_app_builder_plan_generate',
			'dataphyre_task_pack_generate',
			'dataphyre_mcp_task_start_pack_export',
		];
		$containsLargePayloadTool=$toolNames!==null && array_intersect($largePayloadTools,array_map('strval',$toolNames))!==[];
		$batchSize=$batchSize ?? ($exactCoverage ? ($containsLargePayloadTool ? 1 : 1000) : 20);
		return $this->run(static fn(array $tool): array=>McpToolArguments::contractVariants($tool),$batchSize,$toolNames);
	}

	/** @param callable(array<string,mixed>):array<string,array<string,mixed>> $variants @param list<string>|null $toolNames */
	private function run(callable $variants,int $batchSize,?array $toolNames=null): McpToolMatrixReport {
		$tools=$this->scenario->tools();
		if($toolNames!==null){
			$requested=array_fill_keys(array_values(array_unique(array_map('strval',$toolNames))),true);
			$tools=array_values(array_filter($tools,static fn(array $tool): bool=>isset($requested[(string)($tool['name'] ?? '')])));
			$found=array_fill_keys(array_map(static fn(array $tool): string=>(string)($tool['name'] ?? ''),$tools),true);
			$missing=array_values(array_diff(array_keys($requested),array_keys($found)));
			if($missing!==[]){throw new RuntimeException('Requested MCP tools are absent from the live registry: '.implode(', ',$missing));}
		}
		$requests=[];
		$sourceIntensiveRequests=[];
		$calls=[];
		foreach($tools as $tool){
			$name=(string)($tool['name'] ?? '');
			foreach($variants($tool) as $variant=>$arguments){
				$id='tool:'.$name.':'.$variant;
				$calls[]=['id'=>$id,'tool'=>$name,'variant'=>(string)$variant];
				$request=McpScenario::request($id,'tools/call',['name'=>$name,'arguments'=>$arguments]);
				if(in_array($name,self::SOURCE_INDEXED_TOOLS,true)){$sourceIntensiveRequests[]=$request;}
				else{$requests[]=$request;}
			}
		}
		$messages=[];$processes=[];
		if($requests!==[]){
			$transcript=$this->scenario->exchangeBatched($requests,$batchSize,120000);
			array_push($messages,...$transcript->messages());array_push($processes,...$transcript->processes());
		}
		if($sourceIntensiveRequests!==[]){
			$transcript=$this->scenario->usingOrdinaryPhpForSourceIntrospection()->exchangeBatched($sourceIntensiveRequests,20,120000);
			array_push($messages,...$transcript->messages());array_push($processes,...$transcript->processes());
		}
		return new McpToolMatrixReport($tools,$calls,new McpTranscript($messages,$processes));
	}
}

/**
 * In-process MCP kernel contract harness.
 *
 * Public protocol behaviour belongs in McpScenario. This harness is reserved
 * for exhaustive semantic inventories and branch families that would be
 * prohibitively noisy as hundreds of JSON-RPC round trips.
 */
final class McpKernelHarness {
	/** @var array<string,array{prefix:string,summary:string,arguments:string,handoff?:string}> */
	private const APP_BUILDER_GOVERNANCE_FAMILIES=[
		'data integrity'=>['prefix'=>'data_integrity','summary'=>'app_builder_data_integrity_summary','arguments'=>'schemas-and-planned'],
		'lifecycle policy'=>['prefix'=>'lifecycle','summary'=>'app_builder_lifecycle_policy_summary','arguments'=>'schemas','handoff'=>'app_builder_lifecycle_state_handoff'],
		'audit and retention'=>['prefix'=>'audit_retention','summary'=>'app_builder_audit_retention_summary','arguments'=>'schemas','handoff'=>'app_builder_audit_retention_handoff'],
		'access control'=>['prefix'=>'access_control','summary'=>'app_builder_access_control_summary','arguments'=>'schemas-and-planned','handoff'=>'app_builder_access_control_handoff'],
		'operational reliability'=>['prefix'=>'operational_reliability','summary'=>'app_builder_operational_reliability_summary','arguments'=>'schemas-and-task','handoff'=>'app_builder_operational_reliability_handoff'],
		'support and observability'=>['prefix'=>'support_observability','summary'=>'app_builder_support_observability_summary','arguments'=>'schemas-and-task','handoff'=>'app_builder_support_observability_handoff'],
		'change management'=>['prefix'=>'change_management','summary'=>'app_builder_change_management_summary','arguments'=>'schemas-and-task','handoff'=>'app_builder_change_management_handoff'],
		'integration boundary'=>['prefix'=>'integration_boundary','summary'=>'app_builder_integration_boundary_summary','arguments'=>'schemas-and-task','handoff'=>'app_builder_integration_boundary_handoff'],
		'business policy'=>['prefix'=>'business_policy','summary'=>'app_builder_business_policy_summary','arguments'=>'schemas-and-task'],
		'process policy'=>['prefix'=>'process_policy','summary'=>'app_builder_process_policy_summary','arguments'=>'schemas-and-task'],
		'reporting and analytics'=>['prefix'=>'reporting_analytics','summary'=>'app_builder_reporting_analytics_summary','arguments'=>'schemas-and-task','handoff'=>'app_builder_reporting_analytics_handoff'],
		'notification and communication'=>['prefix'=>'notification_communication','summary'=>'app_builder_notification_communication_summary','arguments'=>'schemas-and-task','handoff'=>'app_builder_notification_communication_handoff'],
	];

	/** @var array<string,list<string>> */
	private const APP_BUILDER_RECOVERY_FAMILIES=[
		'PHP syntax'=>['dataphyre_php_lint'],
		'Panel field catalog'=>['dataphyre_run_panel_field_catalog_check'],
		'Panel regression'=>['dataphyre_run_panel_regression'],
		'app-owned PHP tests'=>['app_local_php_unit_tests'],
		'SQL schema metadata'=>['dataphyre_sql_schema_read'],
		'route declarations and previews'=>['dataphyre_route_manifest_read','dataphyre_route_url_preview','dataphyre_route_match_preview'],
		'API static contracts'=>['dataphyre_api_docs_static_summary','dataphyre_openapi_static_contract_summary','dataphyre_api_cache_static_summary'],
		'unknown focused tools'=>['unmatched_tool'],
	];

	private object $server;
	private NonPublicAccess $access;
	private string $entrypoint;

	public function __construct(private TestContext $context) {
		$frameworkRoot=\Dataphyre\Test\dataphyre_path();
		$this->entrypoint=$frameworkRoot.'/runtime/modules/mcp/kernel/dataphyre_mcp.php';
		if(!class_exists('dataphyre_mcp_server',false)){
			if(!defined('DATAPHYRE_MCP_EMBEDDED')){define('DATAPHYRE_MCP_EMBEDDED',true);}
			require_once $this->entrypoint;
		}
		$this->server=new \dataphyre_mcp_server(dirname($frameworkRoot),[]);
		$this->access=$context->nonPublic($this->server);
	}

	public function invoke(string $method,mixed ...$arguments): mixed {
		return $this->access->invoke($method,...$arguments);
	}

	/** Redirects filesystem-bound MCP contracts to a managed repository fixture. */
	public function useRepositoryRoot(string $root): self {
		$this->access->writeProperty('root',$root)->writeProperty('common_root',$root);
		return $this;
	}

	/**
	 * Returns the literal entity keys declared by the app-builder field match.
	 * The method boundary and arm indentation keep nested field-array keys out of
	 * the inventory, so adding a new production arm automatically expands tests.
	 *
	 * @return list<string>
	 */
	public static function appBuilderEntityNamesFromSource(): array {
		$source=file(dirname(__DIR__).'/kernel/dataphyre_mcp.planning.app_builder.schema.php',FILE_IGNORE_NEW_LINES);
		if(!is_array($source)){throw new RuntimeException('Unable to read the MCP app-builder entity catalog.');}
		$insideMatch=false;
		$names=[];
		foreach($source as $line){
			if(!$insideMatch){
				$insideMatch=str_contains($line,'return match($key)');
				continue;
			}
			if(preg_match('/^\t\t\tdefault\s*=>/',$line)===1){break;}
			if(preg_match('/^\t\t\t\'/',$line)!==1 || !str_contains($line,'=>')){continue;}
			preg_match_all("/'([^']+)'/",$line,$matches);
			foreach($matches[1] ?? [] as $name){$names[]=(string)$name;}
		}
		$names=array_values(array_unique($names));
		if($names===[]){throw new RuntimeException('The MCP app-builder entity catalog has no discoverable match arms.');}
		return $names;
	}

	/** @return list<string> */
	public function appBuilderEntityNames(): array {
		return self::appBuilderEntityNamesFromSource();
	}

	/**
	 * Executes every declared entity arm with both isolated and relationship-rich
	 * context and returns the resulting field contracts.
	 *
	 * @return array<string,array{isolated:array<string,mixed>,connected:array<string,mixed>}>
	 */
	public function appBuilderDefaultFieldContracts(?array $selectedEntities=null): array {
		$entities=$this->appBuilderEntityNames();
		$selectedEntities=$selectedEntities===null ? $entities : array_values(array_map('strval',$selectedEntities));
		$contracts=[];
		foreach($selectedEntities as $entity){
			if(!in_array($entity,$entities,true)){throw new \InvalidArgumentException('Unknown MCP app-builder entity contract: '.$entity);}
			$isolated=$this->invoke('app_builder_fields_for_entity',$entity,['entities'=>[$entity],'task'=>'Create a '.$entity.' resource']);
			$connected=$this->invoke('app_builder_fields_for_entity',$entity,['entities'=>$entities,'task'=>'Create a connected enterprise workflow']);
			if(!is_array($isolated) || !is_array($connected)){
				throw new RuntimeException('MCP app-builder field contracts must be arrays: '.$entity);
			}
			$contracts[$entity]=['isolated'=>$isolated,'connected'=>$connected];
		}
		return $contracts;
	}

	/** @return list<string> */
	public static function appBuilderGovernanceFamilyNames(): array {
		return array_keys(self::APP_BUILDER_GOVERNANCE_FAMILIES);
	}

	/** @return list<string> */
	public static function appBuilderCompactPayloadScenarioNames(): array {
		return [
			'bounded response',
			'explicit scaffold overflow',
			'inferred scaffold overflow',
			'strict inferred scaffold overflow',
			'selected detail overflow',
			'detail pagination',
			'compaction primitives',
		];
	}

	/** @return list<string> */
	public static function appBuilderDefaultFieldMethodNamesFromSource(): array {
		$source=file(dirname(__DIR__).'/kernel/dataphyre_mcp.planning.app_builder.schema.php',FILE_IGNORE_NEW_LINES);
		if(!is_array($source)){throw new RuntimeException('Unable to read the MCP app-builder schema source.');}
		$methods=[];
		foreach($source as $line){
			if(preg_match('/^\s*private function (app_builder_default_[a-z0-9_]+_fields)\s*\(/',$line,$match)===1){
				$methods[]=(string)$match[1];
			}
		}
		$methods=array_values(array_unique($methods));
		if($methods===[]){throw new RuntimeException('The MCP app-builder schema has no discoverable default-field methods.');}
		return $methods;
	}

	/** @return list<string> */
	public static function appBuilderSchemaPhraseLiteralsFromSource(): array {
		return self::sourceMethodStringLiterals(
			dirname(__DIR__).'/kernel/dataphyre_mcp.planning.app_builder.schema.php',
			'app_builder_entities_from_task_phrases'
		);
	}

	/** @return list<string> */
	public static function appBuilderPlanningContractNames(): array {
		return [
			'integrity and relationships',
			'implementation and verification',
			'malformed planning rows preserve valid work',
			'verification recovery is focused and copy-safe',
			'paths, chunks, and companion APIs',
			'app intent and dependency order',
		];
	}

	/** @return array<string,mixed> */
	public function appBuilderPlanningContract(string $contract): array {
		if(!in_array($contract,self::appBuilderPlanningContractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP app-builder planning contract: '.$contract);
		}
		$schemas=$this->appBuilderPlanningSchemas();
		$noisySchemas=$this->appBuilderNoisySchemas($schemas);
		$entityPlanning=$this->appBuilderPlanningDependencyContext();
		if($contract==='integrity and relationships'){
			$integrity=$this->invoke('app_builder_data_integrity_summary',$noisySchemas,$noisySchemas);
			$relationshipMetadata=$this->invoke('app_builder_relationship_integrity_metadata',$noisySchemas,$entityPlanning,$noisySchemas);
			$scopeMetadata=$this->invoke('app_builder_scope_identifier_metadata',$noisySchemas);
			$relationshipSummary=$this->invoke('app_builder_relationship_contract_summary',$noisySchemas,$entityPlanning);
			$fieldMetadata=$this->invoke('app_builder_data_model_schema_field_metadata',array_merge(['invalid-field'],is_array($schemas[0]['fields'] ?? null) ? $schemas[0]['fields'] : []));
			return [
				'contract'=>$contract,
				'integrity_work'=>($integrity['has_integrity_work'] ?? false)===true,
				'index_count'=>count(is_array($integrity['indexes'] ?? null) ? $integrity['indexes'] : []),
				'unique_count'=>count(is_array($integrity['unique_constraints'] ?? null) ? $integrity['unique_constraints'] : []),
				'foreign_key_count'=>count(is_array($integrity['foreign_key_constraints'] ?? null) ? $integrity['foreign_key_constraints'] : []),
				'local_relationship_count'=>count(is_array($relationshipMetadata['local_relationships'] ?? null) ? $relationshipMetadata['local_relationships'] : []),
				'external_reference_count'=>count(is_array($relationshipMetadata['external_references'] ?? null) ? $relationshipMetadata['external_references'] : []),
				'scope_field_count'=>count(is_array($scopeMetadata['scope_fields'] ?? null) ? $scopeMetadata['scope_fields'] : []),
				'relationship_total'=>(int)($relationshipSummary['total_relationships'] ?? 0),
				'field_metadata_count'=>count(is_array($fieldMetadata['fields'] ?? null) ? $fieldMetadata['fields'] : []),
			];
		}
		if($contract==='implementation and verification'){
			$rows=$this->appBuilderCompactRows(16);
			$kinds=['table_schema','table_repository','table_record','panel_resource','panel_manifest','panel_regression_manifest','panel_code_unit_test','api_route','api_endpoint_handler','api_regression_manifest','api_code_unit_test','sql_code_unit_test','app_code_unit_test','unknown'];
			$skeletons=['invalid-skeleton',['kind'=>'','path'=>'']];
			foreach($kinds as $index=>$kind){
				$skeletons[]=[
					'kind'=>$kind,
					'path'=>'applications/example/backend/dataphyre/'.($kind==='panel_regression_manifest' ? 'unit_tests/panel.orders.json' : 'generated/'.$kind.($kind==='api_regression_manifest' ? '.json' : ($kind==='panel_code_unit_test' || $kind==='api_code_unit_test' || $kind==='sql_code_unit_test' || $kind==='app_code_unit_test' ? '.test.php' : '.php'))),
					'purpose'=>'Contract '.$kind,
					'adaptation_notes'=>['Preserve options/default metadata','Apply app policy'],
					'sensitive_field_policy'=>[
						'has_sensitive_fields'=>$index===0,
						'categories'=>['credentials_or_secrets'],
						'signals'=>[['field'=>'secret_token'],'invalid-signal'],
						'recommended_actions'=>['mask'],
						'category_policies'=>['credentials_or_secrets'=>['action'=>'write_only']],
						'policy_metadata'=>['owner'=>'consumer'],
					],
				];
			}
			$tools=array_values(array_filter($this->appBuilderMethodStringLiterals('app_builder_verification_plan'),static fn(string $literal): bool=>str_starts_with($literal,'dataphyre_') || $literal==='app_local_php_unit_tests'));
			$files=array_values(array_map(static fn(mixed $skeleton): string=>is_array($skeleton) ? (string)($skeleton['path'] ?? '') : '', $skeletons));
			$files[]='applications/example/backend/dataphyre/unit_tests/example.test.php';
			$dataModel=['invalid-model'];
			foreach($schemas as $schema){
				$dataModel[]=$schema+[
					'artifact_paths'=>['applications/example/backend/dataphyre/Framework/'.($schema['entity'] ?? 'Item').'.php'],
					'sql_config_path'=>'applications/example/backend/dataphyre/config/sql.php',
				];
			}
			$verification=$this->invoke('app_builder_verification_plan',$files,$dataModel,$tools);
			$recovery=$this->invoke('app_builder_verification_recovery_plan',$verification);
			$matrix=['work_items'=>['invalid-work-item']];
			foreach($skeletons as $skeleton){
				if(!is_array($skeleton) || ($skeleton['path'] ?? '')===''){continue;}
				$matrix['work_items'][]=[
					'id'=>'obligation.'.($skeleton['kind'] ?? 'unknown'),
					'paths'=>['',(string)$skeleton['path']],
					'verification_tools'=>array_merge([''],$tools),
					'action'=>'Adapt '.($skeleton['kind'] ?? 'file'),
				];
			}
			$relationshipSummary=$this->invoke('app_builder_relationship_contract_summary',$noisySchemas,$entityPlanning);
			$codeSummary=$this->invoke('app_builder_code_skeleton_summary',$skeletons);
			$adapterHandoff=$this->invoke('app_builder_relationship_adapter_handoff',$relationshipSummary,$codeSummary);
			$adapterHandoff['adapters'][]='invalid-adapter';
			$recipe=$this->invoke('app_builder_implementation_recipe',$skeletons,$matrix,$adapterHandoff,$recovery);
			$execution=$this->invoke('app_builder_verification_execution_plan',$verification,$recipe,$recovery);
			$sensitivity=['categories'=>['identity_or_contact','credentials_or_secrets']];
			$lifecycle=$this->invoke('app_builder_lifecycle_policy_summary',$noisySchemas);
			$tenantIdentity=[
				'status'=>'ready_for_app_owned_tenant_identity_design',
				'tenant_scope'=>['fields'=>['tenant_id','workspace_id'],'negative_checks'=>['cross-scope']],
				'actor_identity'=>['ownership_fields'=>['owner_id'],'access_fields'=>['role_id'],'negative_checks'=>['denied']],
				'entitlement_context'=>['billing_or_plan_fields'=>['plan_id'],'business_controls'=>['quota'],'negative_checks'=>['expired']],
			];
			$fixtures=$this->invoke('app_builder_verification_fixture_handoff',$noisySchemas,$relationshipSummary,$sensitivity,$lifecycle,$tenantIdentity);
			$placeholderShapes=[];
			foreach(['integer','boolean','datetime','date','json','jsonb','text','string'] as $type){
				$placeholderShapes[$type]=get_debug_type($this->invoke('app_builder_fixture_placeholder_value',['name'=>'sample','type'=>$type],'orders'));
			}
			$placeholderShapes['default']=get_debug_type($this->invoke('app_builder_fixture_placeholder_value',['name'=>'sample','type'=>'string','default'=>'ready'],'orders'));
			$recoveryBranches=[];
			foreach(array_merge($tools,['unmatched_tool']) as $tool){
				$recoveryBranches[$tool]=array_keys($this->invoke('app_builder_verification_recovery_branch',$tool,true));
				$this->invoke('app_builder_verification_recovery_branch',$tool,false);
				$this->invoke('app_builder_verification_evidence_capture',$tool);
			}
			$criteria=$this->invoke('app_builder_acceptance_criteria',array_merge($files,['applications/<app>/placeholder.php']),$dataModel);
			$acceptance=$this->invoke('app_builder_acceptance_review_plan',array_merge([''],$criteria),$recipe,$execution,['status'=>'blocked','prewrite_blockers'=>$rows]);
			return [
				'contract'=>$contract,
				'skeleton_total'=>(int)($codeSummary['total'] ?? 0),
				'verification_steps'=>count(is_array($verification['steps'] ?? null) ? $verification['steps'] : []),
				'recovery_branches'=>count(is_array($recovery['branches'] ?? null) ? $recovery['branches'] : []),
				'recipe_items'=>count(is_array($recipe['items'] ?? null) ? $recipe['items'] : []),
				'execution_items'=>count(is_array($execution['items'] ?? null) ? $execution['items'] : []),
				'fixture_count'=>count(is_array($fixtures['fixtures'] ?? null) ? $fixtures['fixtures'] : []),
				'negative_case_count'=>count(is_array($fixtures['negative_cases'] ?? null) ? $fixtures['negative_cases'] : []),
				'tenant_identity_case_count'=>count(is_array($fixtures['tenant_identity_cases'] ?? null) ? $fixtures['tenant_identity_cases'] : []),
				'placeholder_shapes'=>$placeholderShapes,
				'recovery_tool_count'=>count($recoveryBranches),
				'acceptance_items'=>count(is_array($acceptance['items'] ?? null) ? $acceptance['items'] : []),
			];
		}
		if($contract==='malformed planning rows preserve valid work'){
			return $this->appBuilderMalformedPlanningContract($contract,$schemas,$noisySchemas,$entityPlanning);
		}
		if($contract==='verification recovery is focused and copy-safe'){
			return $this->appBuilderVerificationRecoveryContract($contract);
		}
		if($contract==='paths, chunks, and companion APIs'){
			$workspace=$this->context->workspaceIn(\Dataphyre\Test\dataphyre_path('cache'),'mcp-app-builder-path-contract');
			$backendRoot=$workspace->directory('application/backend/dataphyre');
			$applicationRoot=dirname(dirname($backendRoot));
			$serverRoot=dirname(\Dataphyre\Test\dataphyre_path());
			$existingRelative=ltrim(str_replace('\\','/',substr($applicationRoot,strlen($serverRoot))),'/');
			$pathContexts=[];
			foreach([
				'placeholder'=>[],
				'existing'=>['application_path'=>$existingRelative,'app_namespace'=>'Acme\\Portal'],
				'missing'=>['application_path'=>'applications/missing-app','app_namespace'=>'AcmePortal'],
				'absolute'=>['application_path'=>'/tmp/application'],
				'windows absolute'=>['application_path'=>'C:/application'],
				'url'=>['application_path'=>'https://example.test/app'],
				'traversal'=>['application_path'=>'applications/../secret'],
				'invalid namespace'=>['application_path'=>'applications/example','app_namespace'=>'12 invalid://namespace'],
				'empty normalized namespace'=>['application_path'=>'applications/example','app_namespace'=>'//'],
				'backend root'=>['application_path'=>$existingRelative.'/backend/dataphyre'],
			] as $name=>$arguments){
				$pathContexts[$name]=$this->invoke('app_builder_path_context',$arguments);
			}
			$entities=['Customer','WebhookDeliveryAttempt','UsageMeter','AuditEvent','Order'];
			$fields=[];
			foreach($entities as $entity){
				$fields[$entity]=[
					'tenant_id'=>['type'=>'integer','foreign_key_target'=>'tenants'],
					'owner_id'=>['type'=>'integer','foreign_key_target'=>'users'],
					'role_id'=>['type'=>'integer','foreign_key_target'=>'roles'],
					'plan_id'=>['type'=>'integer','foreign_key_target'=>'plans'],
					'secret_token'=>['type'=>'string'],
					'diagnostic_evidence'=>['type'=>'json'],
				];
			}
			$dependencyContext=[
				'dependencies'=>[
					'invalid-dependency',
					['entity'=>'Order','field'=>'owner_id','target_entity'=>'User','scope'=>'previous_chunk','target_chunk'=>'1'],
					['entity'=>'','field'=>''],
				],
				'policy_context'=>[
					'tenant_scope_fields'=>['Earlier.tenant_id'],
					'ownership_fields'=>['Earlier.owner_id'],
					'access_fields'=>['Earlier.role_id'],
					'billing_or_plan_fields'=>['Earlier.plan_id'],
					'sensitive_fields'=>['Earlier.secret'],
				],
			];
			$planning=$this->invoke('app_builder_entity_planning',$entities,array_slice($entities,0,2),'panel_resource',2,[
				'task'=>'Build customer-facing self-service API endpoints with webhook, billing usage, and audit queries',
				'payload_profile'=>'compact',
				'application_path'=>$existingRelative,
				'app_namespace'=>'Acme\\Portal',
				'fields'=>$fields,
				'dependency_context'=>$dependencyContext,
			]);
			$this->invoke('app_builder_entity_planning',['Unknown'],['Unknown'],'panel_resource',1,[
				'path'=>$existingRelative,
				'fields'=>['Unknown'=>[]],
			]);
			$incoming=$this->invoke('app_builder_incoming_dependency_lookup',['dependency_context'=>$dependencyContext]);
			$policy=$this->invoke('app_builder_chunk_policy_context',[
				'fields'=>array_merge($fields,['Invalid'=>'not-an-array','Empty'=>[''=>['type'=>'string']]]),
				'dependency_context'=>$dependencyContext,
			]);
			$companionScenarios=[
				'none for api'=>$this->invoke('app_builder_companion_surface_handoff','Build an API endpoint','api_endpoint',[],$entities),
				'none without intent'=>$this->invoke('app_builder_companion_surface_handoff','Build admin records','panel_resource',[],$entities),
				'customer self service'=>$this->invoke('app_builder_companion_surface_handoff','Build customer-facing self-service API with webhooks, billing usage, and audit history','panel_resource',['application_path'=>$existingRelative,'app_namespace'=>'Acme\\Portal'],$entities),
				'path alias'=>$this->invoke('app_builder_companion_surface_handoff','Build a self-service API','panel_resource',['path'=>$existingRelative],['Order']),
				'empty primary fallback'=>$this->invoke('app_builder_companion_surface_handoff','Build a self-service API','panel_resource',['name'=>''],[]),
				'vendor'=>$this->invoke('app_builder_companion_surface_entity','Build vendor self-service',['Vendor','Document'],[]),
				'webhook'=>$this->invoke('app_builder_companion_surface_entity','Build webhook APIs',['WebhookEndpoint','Order'],[]),
				'fallback'=>$this->invoke('app_builder_companion_surface_entity','Build self service',[],['name'=>'Fallback']),
			];
			$this->invoke('app_builder_companion_task_context','');
			$this->invoke('app_builder_companion_task_context',str_repeat("long\ncontext ",80));
			$sensitiveChecks=[
				'release'=>$this->invoke('app_builder_task_requires_sensitive_write_confirmation','Certify this public release',[]),
				'explicit'=>$this->invoke('app_builder_task_requires_sensitive_write_confirmation','Enforce tenant isolation and credential handling',[]),
				'regex'=>$this->invoke('app_builder_task_requires_sensitive_write_confirmation','Apply token masking controls',[]),
				'signal'=>$this->invoke('app_builder_task_requires_sensitive_write_confirmation','Ordinary app',['signals'=>['release_or_enterprise_claim']]),
				'ordinary'=>$this->invoke('app_builder_task_requires_sensitive_write_confirmation','Ordinary app',[]),
			];
			$naming=$this->invoke('app_builder_naming_contract',array_merge($noisySchemas,[['entity'=>'']]),['normalized_from_explicit'=>['class'=>'ClassRecord','Order'=>'Order',''=>'Ignored']]);
			$fieldMetadataSchemas=$this->invoke('app_builder_field_metadata_schemas',$noisySchemas,[
				'continuation_calls'=>[
					'invalid-call',
					['arguments'=>'invalid'],
					['arguments'=>['fields'=>[''=>'invalid','Order'=>'invalid','Customer'=>['status'=>['type'=>'string','default'=>'draft'],'external_id'=>['type'=>'string','default'=>'duplicate']],'Deferred'=>['state'=>['type'=>'string','options'=>['new','done']]]]]],
				],
			]);
			$fieldSummary=$this->invoke('app_builder_field_metadata_summary',array_merge(
				$this->appBuilderNoisySchemas($fieldMetadataSchemas),
				[['entity'=>'Invalid','fields'=>[['name'=>'','default'=>'ignored']]]]
			));
			$missingTaskException='';
			try{$this->invoke('generate_app_builder_plan',[]);}catch(\InvalidArgumentException $error){$missingTaskException=$error->getMessage();}
			$lane=$this->invoke('app_builder_lane','Build one admin resource',['entities'=>['Order'],'application_path'=>'/invalid','app_namespace'=>'12 invalid']);
			$builderPlan=$this->invoke('app_builder_builder_plan',array_replace($lane,['scaffold_plans'=>array_merge(['invalid-plan'],$lane['scaffold_plans'] ?? [])]));
			return [
				'contract'=>$contract,
				'path_statuses'=>array_map(static fn(array $context): string=>(string)($context['detected_layout'] ?? ''),$pathContexts),
				'existing_path_found'=>($pathContexts['existing']['path_exists'] ?? false)===true,
				'continuation_count'=>count(is_array($planning['continuation_calls'] ?? null) ? $planning['continuation_calls'] : []),
				'dependency_chunks'=>count(is_array($planning['dependency_summary']['chunks'] ?? null) ? $planning['dependency_summary']['chunks'] : []),
				'incoming_dependency_count'=>count($incoming),
				'policy_signal_counts'=>array_map('count',array_intersect_key($policy,array_flip(['tenant_scope_fields','ownership_fields','access_fields','billing_or_plan_fields','sensitive_fields']))),
				'companion_queue_count'=>count(is_array($companionScenarios['customer self service']['endpoint_queue'] ?? null) ? $companionScenarios['customer self service']['endpoint_queue'] : []),
				'sensitive_checks'=>$sensitiveChecks,
				'naming_mappings'=>count(is_array($naming['mappings'] ?? null) ? $naming['mappings'] : []),
				'has_normalization_notes'=>isset($naming['normalization_notes']),
				'field_metadata_count'=>(int)($fieldSummary['field_count'] ?? 0),
				'missing_task_exception'=>$missingTaskException,
				'builder_plan_keys'=>array_keys($builderPlan),
			];
		}

		$task=implode(' ',$this->appBuilderMethodStringLiterals('app_builder_feature_intent_summary'));
		$featureIntent=$this->invoke('app_builder_feature_intent_summary',$task);
		foreach(array_merge(is_array($featureIntent['requested_features'] ?? null) ? $featureIntent['requested_features'] : [],['unmatched_feature']) as $feature){
			$this->invoke('app_builder_feature_intent_prompt',(string)$feature);
			$this->invoke('app_builder_feature_intent_policy_id',(string)$feature);
		}
		$appContract=$this->invoke('app_builder_app_contract_summary',$noisySchemas,$task);
		$dependencyOrder=$this->invoke('app_builder_schema_dependency_order',$noisySchemas);
		$emptyDependencyOrder=$this->invoke('app_builder_schema_dependency_order',['invalid-schema',['entity'=>'','table'=>'']]);
		$enterpriseNotes=$this->invoke('app_builder_enterprise_app_notes',$noisySchemas);
		$apiSkeletons=$this->invoke('app_builder_api_endpoint_code_skeletons',[
			'name'=>'Contract endpoint',
			'proposed_files'=>['routes/api/contract.php','api/ContractEndpoints.php','unit_tests/api.contract.json'],
			'endpoint'=>['path'=>'/api/contract','methods'=>[],'auth_hint'=>'token'],
		]);
		$this->invoke('api_route_code_skeleton','Contract','/contract',['TRACE'],'contract.trace','');
		$this->invoke('app_builder_has_choice_field_hints',['invalid-field',['name'=>'status'],['name'=>'priority','options'=>['high']],['name'=>'state','default'=>'draft']]);
		$this->invoke('app_builder_panel_field_metadata',['invalid-field',['name'=>''],['name'=>'plain','type'=>'string'],['name'=>'status','type'=>'string','options'=>['draft'],'default'=>'draft']]);
		return [
			'contract'=>$contract,
			'requested_features'=>count(is_array($featureIntent['requested_features'] ?? null) ? $featureIntent['requested_features'] : []),
			'decision_prompts'=>count(is_array($appContract['decision_prompts'] ?? null) ? $appContract['decision_prompts'] : []),
			'dependency_entities'=>count(is_array($dependencyOrder['entities'] ?? null) ? $dependencyOrder['entities'] : []),
			'empty_dependency_entities'=>count(is_array($emptyDependencyOrder['entities'] ?? null) ? $emptyDependencyOrder['entities'] : []),
			'enterprise_note_keys'=>array_keys($enterpriseNotes),
			'api_skeleton_count'=>count($apiSkeletons),
		];
	}

	/**
	 * Exercises row-oriented defensive boundaries once, while exposing only
	 * stable business-shaped evidence to the declarative unit test.
	 *
	 * @param list<array<string,mixed>> $schemas
	 * @param list<mixed> $noisySchemas
	 * @param array<string,mixed> $entityPlanning
	 * @return array<string,mixed>
	 */
	private function appBuilderMalformedPlanningContract(string $contract,array $schemas,array $noisySchemas,array $entityPlanning): array {
		$panelMetadata=$this->invoke('app_builder_panel_field_metadata',[
			['name'=>'','default'=>'ignored'],
			['name'=>'status','type'=>'string','default'=>'draft'],
		]);
		$probe=$this->invoke('app_builder_local_convention_probe',[],['dataphyre_root'=>'']);
		$dataModels=$this->invoke('app_builder_data_model',[
			'type'=>'panel_resource',
			'name'=>'Duplicate fields',
			'field_hints'=>[
				['name'=>'id','type'=>'integer'],
				['name'=>'status','type'=>'string'],
				['name'=>'status','type'=>'string'],
				['name'=>'','type'=>'string'],
			],
		]);
		$dataModel=is_array($dataModels[0] ?? null) ? $dataModels[0] : [];
		$fieldMetadata=$this->invoke('app_builder_data_model_schema_field_metadata',[
			'invalid-field',
			['name'=>''],
			['name'=>'status','type'=>'string','default'=>'draft'],
		]);
		$duplicateSchemas=[$schemas[0],$schemas[0]];
		$integrity=$this->invoke('app_builder_data_integrity_summary',$duplicateSchemas,$duplicateSchemas);
		$indexes=is_array($integrity['indexes'] ?? null) ? $integrity['indexes'] : [];
		$indexKeys=array_map(static fn(array $index): string=>(string)($index['table'] ?? '').'|'.implode(',',is_array($index['fields'] ?? null) ? $index['fields'] : []).'|'.(string)($index['kind'] ?? ''),$indexes);

		$recipe=$this->invoke('app_builder_implementation_recipe',[
			['kind'=>'unknown','path'=>'applications/example/backend/dataphyre/generated/unknown.php'],
			['kind'=>'panel_resource','path'=>'applications/example/backend/dataphyre/Panel/Orders.php'],
		],['work_items'=>[]],[
			'adapters'=>[
				'invalid-adapter',
				['adapter_stem'=>'OrderCustomer','panel_field_source'=>'customer_id_options','repository_touchpoint'=>'OrderRepository::relationshipOptions()','verification_focus'=>['permissions']],
			],
		],['branches'=>[]]);
		$parallel=$this->invoke('app_builder_implementation_parallel_batches',[
			'invalid-item',
			['kind'=>'table_schema','path'=>'applications/example/backend/dataphyre/Framework/Schema/Orders.php','order'=>1],
		]);

		$workflow=$this->invoke('app_builder_domain_workflow_handoff',[
			'controls'=>[
				'invalid-business-control',
				['id'=>'eligibility_policy','fields'=>['invalid-field',['entity'=>'Order','table'=>'orders','field'=>'status']]],
			],
		],[
			'controls'=>[
				'invalid-process-control',
				['id'=>'assignment_policy','fields'=>['invalid-field',['entity'=>'Order','table'=>'orders','field'=>'owner_id']]],
			],
		]);
		$workflowFieldCount=0;
		foreach(is_array($workflow['rules'] ?? null) ? $workflow['rules'] : [] as $rule){
			$workflowFieldCount+=count(is_array($rule['fields'] ?? null) ? $rule['fields'] : []);
		}

		$modelSkeletons=$this->invoke('app_builder_data_model_code_skeletons',[
			'code_skeletons'=>['invalid-skeleton',['path'=>'applications/example/backend/dataphyre/Framework/Schema/Orders.php']],
		]);
		$fallbackVerification=$this->invoke('app_builder_verification_plan',[],[],['dataphyre_run_panel_regression']);
		$fixtures=$this->invoke('app_builder_verification_fixture_handoff',[
			'invalid-schema',
			['entity'=>'','table'=>''],
			['entity'=>'Order','table'=>'orders','fields'=>[]],
		],[],[],[],[]);
		$execution=$this->invoke('app_builder_verification_execution_plan',[
			'verification_todo'=>['invalid-todo',['tool'=>''],['tool'=>'dataphyre_php_lint','arguments'=>['paths'=>[]]]],
		],[
			'items'=>['invalid-item',['path'=>''],['path'=>'orders.php','verification_tools'=>['dataphyre_php_lint']]],
		],['branches'=>[]]);
		$recovery=$this->invoke('app_builder_verification_recovery_plan',[
			'verification_todo'=>['invalid-todo',['tool'=>''],['tool'=>'dataphyre_php_lint']],
		]);
		$acceptance=$this->invoke('app_builder_acceptance_review_plan',['Implementation remains verifiable'],[
			'items'=>[['path'=>'orders.php','obligation_ids'=>['','data_integrity'],'verification_tools'=>['dataphyre_php_lint']]],
		],['items'=>[]],['status'=>'ready']);

		$malformedPlanning=$entityPlanning;
		array_unshift($malformedPlanning['incoming_dependency_context']['dependencies'],['entity'=>'','field'=>'']);
		$relationshipSummary=$this->invoke('app_builder_relationship_contract_summary',$noisySchemas,$malformedPlanning);
		$adapterHandoff=$this->invoke('app_builder_relationship_adapter_handoff',[
			'relationships'=>[
				'invalid-relationship',
				['entity'=>'Order','field'=>'customer_id','target_entity'=>'Customer','target_table'=>'customers','scope'=>'planned_entity'],
			],
		],['paths_by_kind'=>[]]);

		return [
			'contract'=>$contract,
			'panel_metadata_count'=>count($panelMetadata),
			'probe_uses_placeholder_root'=>str_contains((string)json_encode($probe),'<app>'),
			'data_model_columns'=>array_values(array_map('strval',is_array($dataModel['columns'] ?? null) ? $dataModel['columns'] : [])),
			'field_metadata_count'=>count(is_array($fieldMetadata['fields'] ?? null) ? $fieldMetadata['fields'] : []),
			'integrity_indexes_are_unique'=>count($indexKeys)===count(array_unique($indexKeys)),
			'recipe_kinds'=>array_values(array_map(static fn(array $item): string=>(string)($item['kind'] ?? ''),is_array($recipe['items'] ?? null) ? $recipe['items'] : [])),
			'parallel_batch_count'=>count(is_array($parallel['batches'] ?? null) ? $parallel['batches'] : []),
			'workflow_rule_count'=>(int)($workflow['rule_count'] ?? 0),
			'workflow_field_count'=>$workflowFieldCount,
			'model_skeleton_count'=>count($modelSkeletons),
			'fallback_verification_steps'=>count(is_array($fallbackVerification['steps'] ?? null) ? $fallbackVerification['steps'] : []),
			'fixture_count'=>count(is_array($fixtures['fixtures'] ?? null) ? $fixtures['fixtures'] : []),
			'execution_item_count'=>count(is_array($execution['items'] ?? null) ? $execution['items'] : []),
			'recovery_branch_count'=>count(is_array($recovery['branches'] ?? null) ? $recovery['branches'] : []),
			'acceptance_item_count'=>count(is_array($acceptance['items'] ?? null) ? $acceptance['items'] : []),
			'obligation_review_count'=>count(is_array($acceptance['obligation_review_items'] ?? null) ? $acceptance['obligation_review_items'] : []),
			'relationship_total'=>(int)($relationshipSummary['total_relationships'] ?? 0),
			'adapter_count'=>(int)($adapterHandoff['adapter_count'] ?? 0),
		];
	}

	/** @return array<string,mixed> */
	private function appBuilderVerificationRecoveryContract(string $contract): array {
		$toolCount=0;
		$actionable=0;
		$copySafe=0;
		$pathModes=0;
		$familyToolCounts=[];
		foreach(self::APP_BUILDER_RECOVERY_FAMILIES as $family=>$tools){
			$familyToolCounts[$family]=count($tools);
			foreach($tools as $tool){
				$toolCount++;
				$pathBound=$this->invoke('app_builder_verification_recovery_branch',$tool,true);
				$pathFree=$this->invoke('app_builder_verification_recovery_branch',$tool,false);
				if((string)($pathBound['likely_app_owned_fix'] ?? '')!=='' && is_array($pathBound['next_reads'] ?? null) && $pathBound['next_reads']!==[]){$actionable++;}
				if(in_array('raw logs',is_array($pathBound['copy_safe_failure_handoff']['not_included'] ?? null) ? $pathBound['copy_safe_failure_handoff']['not_included'] : [],true)){$copySafe++;}
				if(($pathBound['requires_concrete_paths'] ?? null)===true && ($pathFree['requires_concrete_paths'] ?? null)===false){$pathModes++;}
			}
		}
		return [
			'contract'=>$contract,
			'family_tool_counts'=>$familyToolCounts,
			'tool_count'=>$toolCount,
			'actionable_tool_count'=>$actionable,
			'copy_safe_tool_count'=>$copySafe,
			'path_mode_count'=>$pathModes,
		];
	}

	/** @return list<array<string,mixed>> */
	private function appBuilderPlanningSchemas(): array {
		$commonFields=[
			['name'=>'tenant_id','type'=>'integer','required'=>true,'foreign_key_target'=>'tenants'],
			['name'=>'workspace_id','type'=>'integer','foreign_key_target'=>'workspaces'],
			['name'=>'owner_id','type'=>'integer','foreign_key_target'=>'users'],
			['name'=>'role_id','type'=>'integer','foreign_key_target'=>'roles'],
			['name'=>'plan_id','type'=>'integer','foreign_key_target'=>'plans'],
			['name'=>'status','type'=>'string','required'=>true,'default'=>'draft','options'=>['draft','approved','archived']],
			['name'=>'priority','type'=>'string','default'=>'normal'],
			['name'=>'external_id','type'=>'string','required'=>true,'not_foreign_key'=>true,'unique_with'=>['tenant_id']],
			['name'=>'invoice_number','type'=>'string','unique'=>true],
			['name'=>'secret_token','type'=>'string','required'=>true],
			['name'=>'email','type'=>'string'],
			['name'=>'amount_minor','type'=>'integer','default'=>0],
			['name'=>'enabled','type'=>'boolean','default'=>false],
			['name'=>'occurred_at','type'=>'datetime'],
			['name'=>'retention_until','type'=>'date'],
			['name'=>'legal_hold','type'=>'boolean','default'=>false],
			['name'=>'classification','type'=>'string','default'=>'confidential'],
			['name'=>'payload','type'=>'json','default'=>'{}'],
			['name'=>'description','type'=>'text'],
		];
		return [
			[
				'entity'=>'Customer','table'=>'customers','fields'=>$commonFields,
				'relationships'=>[['field'=>'tenant_id','target_entity'=>'Tenant','target_table'=>'tenants']],
			],
			[
				'entity'=>'Order','table'=>'orders','fields'=>array_merge($commonFields,[['name'=>'customer_id','type'=>'integer','required'=>true,'foreign_key_target'=>'customers']]),
				'relationships'=>[
					['field'=>'customer_id','target_entity'=>'Customer','target_table'=>'customers'],
					['field'=>'owner_id','target_entity'=>'User','target_table'=>'users'],
				],
			],
			[
				'entity'=>'CycleA','table'=>'cycle_a','fields'=>[['name'=>'cycle_b_id','type'=>'integer','foreign_key_target'=>'cycle_b']],
				'relationships'=>[['field'=>'cycle_b_id','target_entity'=>'CycleB','target_table'=>'cycle_b']],
			],
			[
				'entity'=>'CycleB','table'=>'cycle_b','fields'=>[['name'=>'cycle_a_id','type'=>'integer','foreign_key_target'=>'cycle_a']],
				'relationships'=>[
					['field'=>'cycle_a_id','target_entity'=>'','target_table'=>'cycle_a'],
					['field'=>'external_ref','target_entity'=>'Provider','target_table'=>'providers'],
				],
			],
		];
	}

	/** @return array<string,mixed> */
	private function appBuilderPlanningDependencyContext(): array {
		return [
			'incoming_dependency_context'=>['dependencies'=>[
				'invalid-dependency',
				['entity'=>'Order','field'=>'owner_id','target_entity'=>'User','scope'=>'previous_chunk'],
				['entity'=>'Order','field'=>'customer_id','target_entity'=>'Different','scope'=>'external_reference'],
			]],
			'dependency_summary'=>['chunks'=>[
				'invalid-chunk',
				['dependencies'=>['invalid-dependency',['scope'=>'planned_entity'],['scope'=>''],['scope'=>'external_reference']]],
			]],
		];
	}

	/** @return array<string,mixed> */
	public function appBuilderSchemaPhraseContract(array $literals): array {
		$literals=array_values(array_unique(array_map('strval',$literals)));
		$matchedEntities=[];
		$matchedLiteralCount=0;
		foreach($literals as $literal){
			$result=$this->invoke('app_builder_entities_from_task_phrases',$literal);
			if(is_array($result) && $result!==[]){
				$matchedLiteralCount++;
				array_push($matchedEntities,...array_map('strval',$result));
			}
		}
		$composite=$this->invoke('app_builder_entities_from_task_phrases',implode(' · ',$literals));
		return [
			'literal_count'=>count($literals),
			'matched_literal_count'=>$matchedLiteralCount,
			'matched_entities'=>array_values(array_unique($matchedEntities)),
			'composite_entities'=>is_array($composite) ? array_values(array_map('strval',$composite)) : [],
		];
	}

	/**
	 * Executes context branches declared inside default-field methods. Method
	 * literals become isolated entity and task contexts, so elseif precedence in
	 * a relationship selector cannot hide lower branches behind a mega fixture.
	 *
	 * @param list<string> $methods
	 * @return array<string,mixed>
	 */
	public function appBuilderDefaultFieldBranchContracts(array $methods): array {
		$known=array_fill_keys(self::appBuilderDefaultFieldMethodNamesFromSource(),true);
		$contracts=[];
		foreach(array_values(array_unique(array_map('strval',$methods))) as $method){
			if(!isset($known[$method])){throw new \InvalidArgumentException('Unknown MCP default-field contract method: '.$method);}
			$literals=$this->appBuilderMethodStringLiterals($method);
			$invocations=0;
			$shapes=[];
			foreach(array_merge([''], $literals) as $literal){
				foreach([
					['entities'=>$literal==='' ? [] : [$literal]],
					['task'=>$literal],
				] as $arguments){
					$result=$this->invoke($method,$arguments);
					if(!is_array($result)){throw new RuntimeException('MCP default-field method did not return an array: '.$method);}
					$invocations++;
					$shapes[implode(',',array_keys($result))]=true;
				}
			}
			$contracts[$method]=[
				'literal_count'=>count($literals),
				'invocations'=>$invocations,
				'distinct_shapes'=>count($shapes),
			];
		}
		return $contracts;
	}

	/** @return array<string,mixed> */
	public function appBuilderSchemaHelperContract(): array {
		$scaffoldTypes=[];
		foreach(['panel_resource','routing_controller','api_endpoint','sql_table','mvc_controller','runtime_module'] as $type){
			$scaffoldTypes[$type]=$this->invoke('infer_app_builder_scaffold_type','ignored',['scaffold_type'=>$type]);
		}
		foreach(['admin CRUD panel','OpenAPI REST API','route controller endpoint','table schema migration','ordinary workflow'] as $task){
			$scaffoldTypes[$task]=$this->invoke('infer_app_builder_scaffold_type',$task,[]);
		}
		$entityScenarios=[
			'explicit'=>$this->invoke('infer_app_builder_entities','ignored',['entities'=>[' Orders ','','Statuses']]),
			'name'=>$this->invoke('infer_app_builder_entities','ignored',['name'=>'News']),
			'nested map'=>$this->invoke('infer_app_builder_entities','ignored',['fields'=>['Orders'=>['total'=>['type'=>'integer']],'Tickets'=>['fields'=>['title'=>['type'=>'string']]]]]),
			'nested rows'=>$this->invoke('infer_app_builder_entities','ignored',['fields'=>[['entity'=>'Invoices','fields'=>[]],['resource'=>'Payments','fields'=>[]]]]),
			'renewal'=>$this->invoke('infer_app_builder_entities','Build a customer success renewal opportunities platform',[]),
			'customer success'=>$this->invoke('infer_app_builder_entities','Build customer success health scores and success plans',[]),
			'learning'=>$this->invoke('infer_app_builder_entities','Build enterprise learning compliance courses and assignments',[]),
			'credentialing'=>$this->invoke('infer_app_builder_entities','Build provider credentialing applications and verification',[]),
			'property'=>$this->invoke('infer_app_builder_entities','Build property and lease operations with tenants and rent schedules',[]),
			'pascal list'=>$this->invoke('infer_app_builder_entities','Build with Ticket, Customer, Order',[]),
			'fallback'=>$this->invoke('infer_app_builder_entities','',[]),
		];
		$knownEntityChecks=[
			'task pack'=>$this->invoke('app_builder_task_mentions_known_entity','create a task pack','Task',['task']),
			'task pack with tasks'=>$this->invoke('app_builder_task_mentions_known_entity','create a task pack with tasks','Task',['task']),
			'task tracker'=>$this->invoke('app_builder_task_mentions_known_entity','create a task tracker','Task',['task']),
			'customer success'=>$this->invoke('app_builder_task_mentions_known_entity','customer success workflow','Customer',['customer']),
			'customer success records'=>$this->invoke('app_builder_task_mentions_known_entity','customer success for customer records','Customer',['customer']),
			'customer records'=>$this->invoke('app_builder_task_mentions_known_entity','customer records admin','Customer',['customer']),
			'ordinary'=>$this->invoke('app_builder_task_mentions_known_entity','orders and products','Order',['orders']),
		];
		$phraseExclusionScenarios=[
			'team audience'=>['Build a dashboard for support team','Team'],
			'company audience'=>['Build a portal for partner company','Company'],
			'case comment specialization'=>['Build case comments','Comment'],
			'project task specialization'=>['Build project tasks','Task'],
			'project issue specialization'=>['Build project issues and RAID logs','Issue'],
			'audit finding specialization'=>['Build audit findings with finding severity','EvaluationFinding'],
			'work order specialization'=>['Build work orders','Order'],
			'maintenance order specialization'=>['Build maintenance triage for orders','Order'],
			'purchase order specialization'=>['Build purchase orders','Order'],
			'production order specialization'=>['Build production orders','Order'],
		];
		$phraseExclusions=[];
		foreach($phraseExclusionScenarios as $name=>[$task,$excludedEntity]){
			$entities=$this->invoke('app_builder_entities_from_task_phrases',$task);
			$phraseExclusions[$name]=!in_array($excludedEntity,is_array($entities) ? $entities : [],true);
		}
		$pascalPropertyEntities=$this->invoke('app_builder_entities_from_task_phrases','Build Lease Tenant records for lease operations');
		$singular=[];
		foreach(['access','cas','companies','leases','addresses','cases','licenses','branches','boxes','statuses','orders','glass'] as $token){
			$singular[$token]=$this->invoke('app_builder_singular_entity_token',$token);
		}
		$preferred=$this->invoke('app_builder_preferred_entity_order',['Other','Order','Customer','Order'],['Customer','Order']);
		$nestedFields=[
			'Orders'=>['fields'=>['total'=>['type'=>'integer']]],
			'Tickets'=>['title'=>['type'=>'string']],
			['entity'=>'Invoices','fields'=>['number'=>['type'=>'string']]],
			['resource'=>'Payments','fields'=>['amount'=>['type'=>'integer']]],
			'ignored',
		];
		$fieldInputEvidence=[
			'keyed wrapped'=>$this->invoke('app_builder_entity_fields_input','Order',$nestedFields),
			'keyed direct'=>$this->invoke('app_builder_entity_fields_input','Ticket',$nestedFields),
			'row entity'=>$this->invoke('app_builder_entity_fields_input','Invoice',$nestedFields),
			'row resource'=>$this->invoke('app_builder_entity_fields_input','Payment',$nestedFields),
			'missing'=>$this->invoke('app_builder_entity_fields_input','Unknown',$nestedFields),
			'nested keyed'=>$this->invoke('app_builder_fields_input_is_nested',['Order'=>['total'=>['type'=>'integer']]]),
			'nested row'=>$this->invoke('app_builder_fields_input_is_nested',[['entity'=>'Order','fields'=>[]]]),
			'flat'=>$this->invoke('app_builder_fields_input_is_nested',[['name'=>'status','type'=>'string']]),
			'definition type'=>$this->invoke('app_builder_field_definition_like',['type'=>'string']),
			'definition metadata'=>$this->invoke('app_builder_field_definition_like',['name'=>'status','required'=>true]),
			'definition map'=>$this->invoke('app_builder_field_definition_like',['name'=>['type'=>'string']]),
		];
		$fieldHints=[
			['name'=>'','type'=>'string'],
			['name'=>'status','type'=>'string','required'=>true,'options'=>['draft','','active','draft'],'default'=>'draft','unique'=>true,'unique_with'=>['tenant_id'],'foreign_key_target'=>'statuses'],
			['name'=>'owner_id','type'=>'integer','foreign_key_target'=>'users'],
			['name'=>'external_id','type'=>'integer','not_foreign_key'=>true],
			['name'=>'enabled','type'=>'boolean','default'=>false],
			['name'=>'secret_token','type'=>'string','default'=>null],
		];
		$schema=$this->invoke('app_builder_schema_fields',$fieldHints);
		$rendered=[
			'panel'=>$this->invoke('panel_resource_code_skeleton','Contract','contracts','Contract',$fieldHints),
			'panel without filters'=>$this->invoke('panel_resource_code_skeleton','EmptyContract','empty_contracts','EmptyContract',[]),
			'record'=>$this->invoke('table_record_code_skeleton','Contract',['id','','display_name','display_name']),
			'field simple'=>$this->invoke('app_builder_panel_field_source',['name'=>'title','type'=>'string']),
			'field rich'=>$this->invoke('app_builder_panel_field_source',['name'=>'secret_token','type'=>'string','required'=>true,'options'=>['one','two'],'default'=>true]),
			'filter simple'=>$this->invoke('app_builder_panel_filter_source',['name'=>'enabled','type'=>'boolean']),
			'filter rich'=>$this->invoke('app_builder_panel_filter_source',['name'=>'status','type'=>'select','options'=>['in_progress','on-hold'],'default'=>'in_progress']),
		];
		$scalarLiterals=[];
		foreach([true,false,12,1.5,null,'',str_repeat('x',120),"quo'te"] as $value){
			$scalarLiterals[]=[$this->invoke('app_builder_scalar_default',$value),$this->invoke('app_builder_php_scalar_literal',$value)];
		}
		$casts=[];
		foreach(['integer','int','float','decimal','number','boolean','bool','date','datetime','timestamp','json','jsonb','string'] as $type){
			$casts[$type]=$this->invoke('app_builder_sql_cast',$type);
		}
		$panelTypes=[];
		foreach(['integer','float','date','datetime','boolean','string'] as $type){
			$panelTypes[$type]=$this->invoke('app_builder_panel_type','value',$type,'field');
		}
		$panelTypes['status']=$this->invoke('app_builder_panel_type','status','string','field');
		$panelTypes['relation']=$this->invoke('app_builder_panel_type_for_field',['name'=>'owner_id','type'=>'integer','foreign_key_target'=>'users'],'field');
		$panelTypes['non relation numeric']=$this->invoke('app_builder_panel_type_for_field',['name'=>'external_id','type'=>'integer','not_foreign_key'=>true],'field');
		$webhookScope=$this->invoke('app_builder_default_webhook_delivery_fields',['entities'=>['Tenant','WebhookDelivery']]);
		return [
			'scaffold_types'=>$scaffoldTypes,
			'entity_scenarios'=>$entityScenarios,
			'known_entity_checks'=>$knownEntityChecks,
			'phrase_exclusions'=>$phraseExclusions,
			'pascal_property_entities'=>$pascalPropertyEntities,
			'singular'=>$singular,
			'preferred'=>$preferred,
			'field_inputs'=>$fieldInputEvidence,
			'flat_fields_for_entity'=>$this->invoke('app_builder_fields_for_entity','Unknown',['fields'=>[['name'=>'flat_field','type'=>'string']]]),
			'invalid_field_entities'=>$this->invoke('app_builder_entities_from_fields_input',['invalid',[],''=>[]]),
			'schema_count'=>count($schema),
			'filters'=>$this->invoke('app_builder_filter_entries',$fieldHints),
			'relationships'=>$this->invoke('app_builder_relationships',$fieldHints),
			'rendered_bytes'=>array_map('strlen',$rendered),
			'scalar_literals'=>$scalarLiterals,
			'casts'=>$casts,
			'panel_types'=>$panelTypes,
			'empty_panel_options'=>$this->invoke('app_builder_panel_options_source',['','ready']),
			'empty_relationship_target'=>$this->invoke('app_builder_relationship_target_entity',''),
			'webhook_idempotency_scope'=>$webhookScope['idempotency_key']['unique_with'] ?? [],
			'labels'=>[
				'title'=>$this->invoke('title_label','api_customer_id'),
				'empty title'=>$this->invoke('title_label',''),
				'camel'=>$this->invoke('camel_name','display name'),
				'empty camel'=>$this->invoke('camel_name',''),
				'plural y'=>$this->invoke('plural_label','Category'),
				'plural s'=>$this->invoke('plural_label','Status'),
				'plural ordinary'=>$this->invoke('plural_label','Order'),
			],
			'field_names'=>$this->invoke('app_builder_field_names',$fieldHints),
			'fallback_field_names'=>$this->invoke('app_builder_field_names',[]),
			'bounded_empty'=>$this->invoke('bounded_phrase_match','anything',''),
		];
	}

	/**
	 * Runs one named compact-response pressure contract and returns bounded
	 * evidence. The deliberately tiny overflow budget keeps every progressive
	 * projection deterministic without embedding megabytes of fixture prose.
	 *
	 * @return array<string,mixed>
	 */
	public function appBuilderCompactPayloadContract(string $scenario): array {
		if(!in_array($scenario,self::appBuilderCompactPayloadScenarioNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP app-builder compact payload scenario: '.$scenario);
		}
		if($scenario==='bounded response'){
			$payload=['builder_response'=>['payload_budget'=>['max_response_chars'=>60000]]];
			$result=$this->invoke('mcp_app_builder_enforce_compact_payload_budget',$payload,[]);
			$earlyResponse=['payload_budget'=>['max_response_chars'=>0],'title'=>'small response'];
			$earlyBudget=$this->invoke('mcp_app_builder_enforce_compact_budget',$earlyResponse);
			$repairPayload=[
				'padding'=>str_repeat('bounded-pressure-',5000),
				'builder_response'=>[
					'payload_budget'=>['max_response_chars'=>1],
					'files'=>[],
					'schema'=>[],
					'first_read'=>[
						'files_summary'=>['total'=>2],
						'schema_summary'=>['total'=>2],
						'app_path_context'=>['application_path'=>'applications/example'],
					],
					'scaffold_completion_summary'=>['planned_entities'=>['Order','Customer']],
					'compact_detail_policy'=>['collapsed_sections'=>[]],
				],
			];
			$repaired=$this->invoke('mcp_app_builder_enforce_compact_payload_budget',$repairPayload,[]);
			$zeroBudgetPayload=$this->invoke('mcp_app_builder_enforce_compact_payload_budget',[
				'builder_response'=>['payload_budget'=>['max_response_chars'=>0],'padding'=>str_repeat('z',61000)],
			],[]);
			$explicitFallbackPayload=$this->appBuilderCompactFixturePayload(true,false);
			$explicitFallbackPayload['builder_response']['files']=[];
			$explicitFallbackPayload['builder_response']['files_summary']=['total'=>20];
			$explicitFallback=$this->invoke('mcp_app_builder_enforce_compact_payload_budget',$explicitFallbackPayload,[]);
			$selectedRepair=$this->invoke('mcp_app_builder_enforce_compact_payload_budget',[
				'padding'=>str_repeat('selected-repair-',5000),
				'builder_response'=>[
					'payload_budget'=>['max_response_chars'=>1],
					'selected_detail_page'=>['status'=>'selected','page'=>'implementation','data'=>[]],
					'compact_detail_policy'=>['final_payload_collapsed_sections'=>[]],
				],
			],[]);
			$incrementalBudget=1000;
			$incrementalCollapse=$this->invoke('mcp_app_builder_enforce_compact_payload_budget',[
				'builder_response'=>[
					'payload_budget'=>['max_response_chars'=>$incrementalBudget],
					'selected_detail_page'=>['status'=>'selected','page'=>'planning','data'=>[]],
					'compact_detail_policy'=>['final_payload_collapsed_sections'=>[]],
					'app_contract_summary'=>['oversized_detail'=>str_repeat('contract-detail-',1000)],
					'extension_boundary_summary'=>['sentinel'=>'preserve-after-budget-is-satisfied'],
				],
			],[]);
			$incrementalCollapsed=is_array($incrementalCollapse['builder_response']['compact_detail_policy']['final_payload_collapsed_sections'] ?? null)
				? $incrementalCollapse['builder_response']['compact_detail_policy']['final_payload_collapsed_sections']
				: [];
			return [
				'scenario'=>$scenario,
				'bounded'=>$result===$payload,
				'should_enforce'=>[
					'explicit_flag'=>$this->invoke('mcp_app_builder_should_enforce_compact_budget',['enforce_payload_budget'=>true],[]),
					'detail_page'=>$this->invoke('mcp_app_builder_should_enforce_compact_budget',['detail_page'=>'planning'],[]),
					'explicit_entities'=>$this->invoke('mcp_app_builder_should_enforce_compact_budget',['entities'=>['Order']],['entity_input_contract'=>['provided'=>true]]),
					'large_field_map'=>$this->invoke('mcp_app_builder_should_enforce_compact_budget',[],['field_input_contract'=>['provided'=>true,'explicit_entities'=>range(1,5)]]),
					'top_level_budget'=>$this->invoke('mcp_app_builder_should_enforce_compact_budget',[],['payload_budget'=>['max_response_chars'=>1],'padding'=>str_repeat('x',100)]),
					'zero_budget'=>$this->invoke('mcp_app_builder_should_enforce_compact_budget',[],['payload_budget'=>['max_response_chars'=>0],'padding'=>str_repeat('x',61000)]),
					'ordinary'=>$this->invoke('mcp_app_builder_should_enforce_compact_budget',[],['builder_response'=>['payload_budget'=>['max_response_chars'=>60000]]]),
				],
				'early_budget_identity'=>$earlyBudget===$earlyResponse,
				'zero_budget_enforced'=>($zeroBudgetPayload['compact_payload_budget_enforced'] ?? false)===true,
				'explicit_files_fallback'=>is_array($explicitFallback['builder_response']['files'] ?? null) && $explicitFallback['builder_response']['files']!==[],
				'selected_budget_repaired'=>is_array($selectedRepair['builder_response']['budget_enforcement'] ?? null),
				'incremental_collapse_stops_when_bounded'=>in_array('app_contract_summary',$incrementalCollapsed,true)
					&& isset($incrementalCollapse['builder_response']['extension_boundary_summary'])
					&& strlen((string)json_encode($incrementalCollapse,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))<=$incrementalBudget,
				'repaired_contract'=>[
					'budget'=>is_array($repaired['builder_response']['budget_enforcement'] ?? null),
					'files'=>is_array($repaired['builder_response']['files'] ?? null) && $repaired['builder_response']['files']!==[],
					'schema'=>is_array($repaired['builder_response']['schema'] ?? null) && $repaired['builder_response']['schema']!==[],
					'path'=>is_array($repaired['builder_response']['app_path_context'] ?? null),
				],
			];
		}
		if($scenario==='detail pagination'){
			$response=$this->appBuilderCompactFixtureResponse(60000);
			$paginated=$this->invoke('mcp_app_builder_paginate_compact_details',$response);
			$pagination=$this->invoke('mcp_app_builder_detail_pagination');
			$pages=is_array($pagination['pages'] ?? null) ? array_keys($pagination['pages']) : [];
			$selected=[];
			foreach($pages as $page){
				$selected[(string)$page]=$this->invoke('mcp_app_builder_selected_detail_page',(string)$page,$response,$response);
			}
			$selected['response fallback']=$this->invoke('mcp_app_builder_selected_detail_page','implementation',[],$response);
			$selected['invalid']=$this->invoke('mcp_app_builder_selected_detail_page','not-a-page',[],[]);
			$selected['governance fallback']=$this->invoke('mcp_app_builder_selected_detail_page','governance',[],[]);
			return [
				'scenario'=>$scenario,
				'pages'=>$pages,
				'selected_statuses'=>array_map(static fn(array $page): string=>(string)($page['status'] ?? ''),$selected),
				'pagination_markers'=>count(array_filter(array_keys($paginated),static fn(string $key): bool=>str_ends_with($key,'_pagination'))),
				'collapsed_sections'=>count(is_array($paginated['compact_detail_policy']['collapsed_sections'] ?? null) ? $paginated['compact_detail_policy']['collapsed_sections'] : []),
			];
		}
		if($scenario==='compaction primitives'){
			$longRows=$this->appBuilderCompactRows(8);
			$limited=$this->invoke('mcp_app_builder_limit_compact_list',['outer'=>['items'=>$longRows]],['outer','items'],3,'contract.items');
			$unchanged=$this->invoke('mcp_app_builder_limit_compact_list',['outer'=>['items'=>[1]]],['outer','items'],3,'contract.items');
			$missing=$this->invoke('mcp_app_builder_limit_compact_list',['outer'=>[]],['outer','items'],3,'contract.items');
			$nonArray=$this->invoke('mcp_app_builder_limit_compact_list',['outer'=>['items'=>'value']],['outer','items'],3,'contract.items');
			$recipe=$this->invoke('mcp_app_builder_compact_recipe_item',[
				'edit_tasks'=>range(1,8),
				'verification_tools'=>range(1,8),
				'relationship_adapters'=>range(1,8),
			],'contract.recipe');
			$nested=$this->invoke('mcp_app_builder_compact_nested_lists',[
				'rows'=>$longRows,
				'nested'=>['values'=>range(1,8)],
			],3,'contract.nested');
			$countSections=['implementation_recipe','implementation_matrix','verification_execution_plan','acceptance_review_plan','verification_fixture_handoff','verification_recovery_plan','other'];
			$countEvidence=[];
			foreach($countSections as $section){
				$countEvidence[$section]=[
					'count'=>$this->invoke('mcp_app_builder_collapsed_section_count',$section,['items'=>[1,2],'work_items'=>[1,2],'fixtures'=>[1,2],'branches'=>[1,2]]),
					'label'=>$this->invoke('mcp_app_builder_collapsed_section_count_label',$section),
				];
			}
			$summary=$this->invoke('mcp_app_builder_selected_detail_section_summary','implementation_recipe',[
				'owner'=>'contract-owner',
				'items'=>array_merge(['literal-item'],[['kind'=>'edit','path'=>'src.php','verification_tools'=>range(1,8)]]),
				'work_items'=>$longRows,
				'batches'=>$longRows,
				'steps'=>$longRows,
				'files'=>$longRows,
				'fields'=>$longRows,
				'tools'=>$longRows,
				'parallel_batches'=>['batches'=>$longRows],
				'first_batch'=>['paths'=>range(1,8),'tools'=>range(1,8)],
			],'contract.summary');
			$implementationCounts=$this->invoke('mcp_app_builder_apply_selected_page_detail_counts',[
				'selected_detail_page'=>['page'=>'implementation','data'=>[
					'implementation_recipe'=>['items'=>[]],
					'implementation_matrix'=>['work_items'=>[1,2]],
				]],
				'compact_detail_policy'=>[],
			]);
			$verificationCounts=$this->invoke('mcp_app_builder_apply_selected_page_detail_counts',[
				'selected_detail_page'=>['page'=>'verification','data'=>[
					'verification_execution_plan'=>['item_count'=>3],
					'acceptance_review_plan'=>['items'=>[1,2]],
				]],
				'compact_detail_policy'=>['detail_counts'=>[]],
			]);
			$schemaRows=$this->invoke('mcp_app_builder_schema_summary_rows',[
				'entity_planning'=>['planned_entities'=>['','FallbackEntity']],
			]);
			$enterprise=$this->invoke('mcp_app_builder_compact_enterprise_summary','unknown_summary',[
				'controls'=>['control-id',['id'=>'structured-control'],['id'=>'']],
				'fields_by_category'=>[
					'invalid'=>'not-an-array',
					'identity'=>['email',['field'=>'owner_id','entity'=>'Order']],
				],
			]);
			$detailCountSummaries=[
				'direct'=>$this->invoke('mcp_app_builder_compact_detail_counts_summary',['detail_counts'=>['files'=>[],'schema'=>[]]]),
				'preserved'=>$this->invoke('mcp_app_builder_compact_detail_counts_summary',['detail_counts_summary'=>['available'=>true,'count'=>7]]),
				'missing'=>$this->invoke('mcp_app_builder_compact_detail_counts_summary',[]),
			];
			$this->invoke('mcp_app_builder_limit_selected_detail_page',['page'=>'invalid','data'=>'not-an-array']);
			$this->invoke('mcp_app_builder_trim_compact_handoff_overhead',['builder_response'=>'not-an-array']);
			$this->invoke('mcp_app_builder_keep_compact_enterprise_summary','unknown','not-an-array');
			$this->invoke('mcp_app_builder_compact_prewrite_checklist',['prewrite_blockers'=>['invalid-blocker',['id'=>'valid']]]);
			$this->invoke('mcp_app_builder_selected_detail_entity_planning_summary',['continuation_calls'=>['invalid-call',['arguments'=>[]]]]);
			$this->invoke('mcp_app_builder_compact_inferred_entity_planning_summary',['continuation_calls'=>[['arguments'=>'invalid']]]);
			$this->invoke('mcp_app_builder_compact_recipe_item',['edit_tasks'=>'invalid'],'contract.recipe.invalid');
			$this->invoke('mcp_app_builder_enforce_compact_budget',['payload_budget'=>['max_response_chars'=>1],'padding'=>str_repeat('x',1000)]);
			$rootList=$this->invoke('mcp_app_builder_compact_nested_lists',[[range(1,8)],'literal'],3,'contract.root-list');
			return [
				'scenario'=>$scenario,
				'limited_count'=>count($limited['outer']['items'] ?? []),
				'pagination'=>$limited['outer']['items_pagination'] ?? [],
				'unchanged'=>$unchanged,
				'missing'=>$missing,
				'non_array'=>$nonArray,
				'recipe_pagination_keys'=>count(array_filter(array_keys($recipe),static fn(string $key): bool=>str_ends_with($key,'_pagination'))),
				'nested_paginated'=>isset($nested['rows_pagination'],$nested['nested']['values_pagination']),
				'count_evidence'=>$countEvidence,
				'summary'=>$summary,
				'detail_counts'=>[
					'implementation'=>$implementationCounts['compact_detail_policy']['detail_counts']['implementation_items']['count'] ?? 0,
					'verification'=>$verificationCounts['compact_detail_policy']['detail_counts']['verification_items']['count'] ?? 0,
					'acceptance'=>$verificationCounts['compact_detail_policy']['detail_counts']['acceptance_items']['count'] ?? 0,
				],
				'schema_row_entity'=>$schemaRows[0]['entity'] ?? '',
				'enterprise_controls'=>count(is_array($enterprise['controls'] ?? null) ? $enterprise['controls'] : []),
				'detail_count_summaries'=>$detailCountSummaries,
				'root_list_preserved'=>array_is_list($rootList),
				'invalid_json_is_null'=>$this->invoke('mcp_app_builder_compact_budget_json',["invalid"=>"\xB1\x31"])===null,
			];
		}

		$explicit=$scenario==='explicit scaffold overflow';
		$selected=$scenario==='selected detail overflow';
		$task=$scenario==='strict inferred scaffold overflow'
			? 'Build a customer success renewal health workflow with provider credential compliance'
			: 'Build a connected operational workflow';
		$payload=$this->appBuilderCompactFixturePayload($explicit,$selected);
		if($selected){
			foreach([15000,20000,30000,40000,50000,60000,80000,100000,150000,200000] as $crossingBudget){
				$crossing=$payload;
				unset($crossing['padding']);
				$crossing['builder_response']['payload_budget']['max_response_chars']=$crossingBudget;
				$this->invoke('mcp_app_builder_enforce_compact_payload_budget',$crossing,['task'=>$task]);
			}
		}
		$result=$this->invoke('mcp_app_builder_enforce_compact_payload_budget',$payload,['task'=>$task]);
		$response=is_array($result['builder_response'] ?? null) ? $result['builder_response'] : [];
		return [
			'scenario'=>$scenario,
			'budget_enforced'=>($result['compact_payload_budget_enforced'] ?? false)===true,
			'response_keys'=>array_keys($response),
			'collapsed_sections'=>array_values(array_map('strval',is_array($result['compact_payload_collapsed_sections'] ?? null) ? $result['compact_payload_collapsed_sections'] : [])),
			'final_collapsed_sections'=>array_values(array_map('strval',is_array($response['compact_detail_policy']['final_payload_collapsed_sections'] ?? null) ? $response['compact_detail_policy']['final_payload_collapsed_sections'] : [])),
			'has_selected_detail'=>is_array($response['selected_detail_page'] ?? null),
			'encoded_chars'=>strlen((string)json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
		];
	}

	/** @return list<array<string,mixed>> */
	private function appBuilderCompactRows(int $count): array {
		$rows=[];
		for($index=1;$index<=$count;$index++){
			$rows[]=['id'=>'row-'.$index,'value'=>str_repeat('contract-'.$index.'-',12),'handoff_fields'=>['secret'=>'remove']];
		}
		return $rows;
	}

	/** @return array<string,mixed> */
	private function appBuilderCompactFixtureResponse(int $max): array {
		$rows=$this->appBuilderCompactRows(20);
		$recipeItems=[];
		foreach(array_slice($rows,0,14) as $row){
			$recipeItems[]=$row+['kind'=>'edit','path'=>'src/'.($row['id'] ?? 'item').'.php','edit_tasks'=>range(1,8),'verification_tools'=>range(1,8),'relationship_adapters'=>range(1,8)];
		}
		$section=['owner'=>'consuming_application','status'=>'ready','items'=>$rows,'work_items'=>$rows,'checks'=>$rows,'required_checks'=>$rows,'obligations'=>$rows,'signals'=>$rows,'decisions'=>$rows,'blockers'=>$rows];
		$response=[
			'payload_budget'=>[
				'max_response_chars'=>$max,
				'escalation_policy'=>[
					'use_extension_points_first'=>range(1,8),
					'do_not_escalate_for'=>range(1,8),
					'escalate_only_for'=>range(1,8),
				],
			],
			'title'=>'Compact contract fixture',
			'active'=>true,
			'first_read_ref'=>'first_read',
			'files'=>$rows,
			'schema'=>$rows,
			'panel_fields'=>$rows,
			'filters'=>$rows,
			'actions'=>$rows,
			'verification_evidence'=>$rows,
			'verification_todo'=>$rows,
			'secondary_context'=>$section,
			'next_edits'=>$rows,
			'verification'=>$section,
			'verification_evidence_summary'=>$section,
			'policy_decision_register'=>$section,
			'governance_notes'=>$section,
			'agent_workload'=>$section,
			'semantic_contract'=>$section,
			'detail_refs'=>$rows,
			'detail_pagination'=>['full_plan_tool'=>'tool','start_pack_broader_context'=>'context','start_pack_detail'=>'detail','start_pack_deep'=>'deep','open_rule'=>'open'],
			'code_skeleton_summary'=>['path_reasons'=>$rows,'paths_by_kind'=>['class'=>$rows,'test'=>$rows]],
			'implementation_recipe'=>['items'=>$recipeItems,'parallel_batches'=>['batches'=>$rows]],
			'implementation_matrix'=>['work_items'=>$rows],
			'verification_execution_plan'=>['items'=>$rows],
			'acceptance_review_plan'=>['items'=>$rows,'obligation_review_items'=>$rows],
			'verification_recovery_plan'=>['owner'=>'consumer','branches'=>$rows],
			'verification_fixture_handoff'=>['fixtures'=>$rows,'relationship_cases'=>$rows,'lifecycle_cases'=>$rows],
			'compact_detail_policy'=>['collapsed_sections'=>array_fill_keys(range(1,20),$section),'detail_counts'=>array_fill_keys(range(1,12),['count'=>20]),'final_payload_collapsed_sections'=>[]],
			'budget_enforcement'=>['max_response_chars'=>$max,'collapsed_sections'=>$rows],
			'entity_input_contract'=>['provided'=>true,'entities'=>range(1,12),'explicit_entities'=>range(1,12),'inferred_entities'=>range(1,12)],
			'field_input_contract'=>['provided'=>true,'explicit_entities'=>range(1,12),'accepted_metadata'=>$rows],
			'app_contract_summary'=>['owner'=>'consumer','status'=>'ready','feature_intent_summary'=>['requested_features'=>range(1,12)]],
			'prewrite_checklist'=>['owner'=>'consumer','status'=>'blocked','prewrite_blockers'=>$rows,'resolution_plan'=>['items'=>$rows],'prewrite_reminders'=>$rows,'implementation_obligations'=>$rows],
			'entity_planning'=>[
				'owner'=>'consumer','truncated'=>true,'planned_entities'=>range(1,12),'deferred_entities'=>range(13,24),
				'continuation_calls'=>[['tool'=>'dataphyre_app_builder_plan_generate','entities'=>range(1,12),'arguments'=>['task'=>str_repeat('task ',80),'fields'=>$rows,'dependency_context'=>['policy_context'=>['tenant_scope_fields'=>[],'ownership_fields'=>[],'access_fields'=>[],'billing_or_plan_fields'=>[],'sensitive_fields'=>[]]]]]],
			],
			'scaffold_completion_summary'=>['owner'=>'consumer','complete'=>false,'planned_entities'=>range(1,12),'deferred_entities'=>range(13,24),'continuation_queue'=>$rows],
			'first_read'=>[
				'title'=>'First read','next_action'=>['handoff_fields'=>$rows,'write_start_packet'=>['after_write_evidence'=>$rows],'resume_cursor'=>['copy_forward'=>range(1,12)],'not_required'=>range(1,12)],
				'files_summary'=>['total'=>20],'schema_summary'=>['total'=>20],'app_path_context'=>['path'=>'apps/example'],
				'write_readiness'=>['write_start_contract'=>['after_write_evidence'=>$rows],'not_required'=>range(1,12)],
				'verification_handoff'=>['post_write_handoff_template_ref'=>'template','tools'=>range(1,12)],
			],
			'write_readiness'=>['items'=>$rows,'write_start_contract'=>['after_write_evidence'=>$rows],'not_required'=>range(1,12)],
			'verification_handoff'=>['post_write_handoff_template_ref'=>'template','tools'=>range(1,12)],
			'naming_contract'=>$section,
			'app_path_context'=>['path'=>'apps/example'],
			'files_summary'=>['total'=>20],
			'schema_summary'=>['total'=>20],
			'field_metadata_summary'=>$section,
			'relationship_contract_summary'=>$section,
			'data_sensitivity_summary'=>['categories'=>[]],
			'data_integrity_summary'=>['unique_constraints'=>$rows,'foreign_keys'=>$rows,'indexes'=>$rows],
			'lifecycle_policy_summary'=>$section,
			'audit_retention_summary'=>['has_audit_retention_fields'=>true,'controls'=>$rows,'fields_by_category'=>['audit'=>$rows]],
			'access_control_summary'=>['has_access_control_fields'=>true,'controls'=>$rows,'fields_by_category'=>['scope'=>$rows]],
			'operational_reliability_summary'=>['has_operational_reliability_signals'=>true,'task_signals'=>range(1,8),'controls'=>$rows,'fields_by_category'=>['retry'=>$rows]],
			'support_observability_summary'=>['has_support_observability_signals'=>true,'task_signals'=>range(1,8),'controls'=>$rows,'fields_by_category'=>['trace'=>$rows]],
			'change_management_summary'=>['has_change_management_signals'=>true,'task_signals'=>range(1,8),'controls'=>$rows,'fields_by_category'=>['approval'=>$rows]],
			'integration_boundary_summary'=>['has_integration_boundary_signals'=>true,'task_signals'=>range(1,8),'controls'=>$rows,'fields_by_category'=>['webhook'=>$rows]],
			'business_policy_summary'=>$section,
			'process_policy_summary'=>$section,
			'reporting_analytics_summary'=>$section,
			'notification_communication_summary'=>$section,
			'companion_surface_handoff'=>['status'=>'ready','endpoint_queue'=>[['id'=>'endpoint','checks'=>range(1,12),'follow_up_arguments'=>['task'=>'build','methods'=>range(1,8)]]]],
		];
		foreach(['data_model_handoff','lifecycle_state_handoff','access_control_handoff','operational_reliability_handoff','support_observability_handoff','change_management_handoff','integration_boundary_handoff','tenant_identity_handoff','domain_workflow_handoff','reporting_analytics_handoff','notification_communication_handoff','relationship_adapter_handoff','surface_execution_plan','local_convention_probe','write_handoff','extension_boundary_summary'] as $key){
			$response[$key]=$section;
		}
		return $response;
	}

	/** @return array<string,mixed> */
	private function appBuilderCompactFixturePayload(bool $explicit,bool $selected): array {
		$response=$this->appBuilderCompactFixtureResponse(1);
		$response['entity_input_contract']['provided']=$explicit;
		$response['field_input_contract']['provided']=$explicit;
		if($selected){
			$response['selected_detail_page']=['status'=>'selected','page'=>'implementation','data'=>['implementation_recipe'=>$response['implementation_recipe']],'padding'=>str_repeat('selected-detail-',1000)];
		}
		return [
			'builder_response'=>$response,
			'entity_input_contract'=>['provided'=>$explicit,'input_mode'=>$explicit ? 'explicit' : 'inferred','explicit_entities'=>range(1,12),'inferred_entities'=>range(1,12)],
			'field_input_contract'=>['provided'=>$explicit,'input_mode'=>$explicit ? 'explicit' : 'inferred','explicit_entities'=>range(1,12),'accepted_metadata'=>$this->appBuilderCompactRows(20)],
			'governance_notes'=>['status'=>'ready','mode'=>'contract','categories'=>range(1,12),'policy_required_count'=>12],
			'focused_context'=>['docs'=>['modules'=>range(1,12),'chunks'=>range(1,12)],'optional_guidance_docs'=>range(1,12)],
			'context_links'=>array_fill_keys(range(1,12),str_repeat('context-link-',20)),
			'padding'=>str_repeat('payload-pressure-',2000),
		];
	}

	/**
	 * Executes one source-derived governance taxonomy as a bounded semantic contract.
	 *
	 * String classifier and policy methods are expanded from their own literal
	 * arms. Their observed field/entity categories then produce representative
	 * schemas for the family's summary and handoff surfaces.
	 *
	 * @return array<string,mixed>
	 */
	public function appBuilderGovernanceFamilyContract(string $family): array {
		$configuration=self::APP_BUILDER_GOVERNANCE_FAMILIES[$family] ?? null;
		if(!is_array($configuration)){
			throw new \InvalidArgumentException('Unknown MCP app-builder governance family: '.$family);
		}
		$methods=$this->appBuilderGovernanceStringMethods((string)$configuration['prefix']);
		$methodResults=[];
		$fieldInputs=[];
		$entityInputs=[];
		$taskPhrases=[];
		foreach($methods as $method){
			$literals=$this->appBuilderMethodStringLiterals($method);
			$inputs=array_values(array_unique(array_merge([''], $literals, ['unmatched semantic contract'])));
			foreach($inputs as $input){
				$result=$this->invoke($method,$input);
				$methodResults[$method][hash('sha256',$input)]=$this->appBuilderSemanticResultShape($result);
				if(str_ends_with($method,'_field_category') && is_string($result) && $result!==''){
					$fieldInputs[$input]=$result;
				}
				if(str_ends_with($method,'_entity_category') && is_string($result) && $result!==''){
					$entityInputs[$input]=$result;
				}
			}
			if(str_ends_with($method,'_task_signals')){
				array_push($taskPhrases,...$literals);
			}
		}
		$task=implode(' ',array_values(array_unique($taskPhrases)));
		$fields=[];
		foreach($fieldInputs as $name=>$category){
			$fields[]=[
				'name'=>$name,
				'type'=>str_ends_with($name,'_at') || str_ends_with($name,'_until') ? 'datetime' : 'string',
				'required'=>true,
				'default'=>'draft',
				'options'=>['draft','active','approved','archived'],
				'unique'=>true,
				'unique_with'=>['tenant_id'],
				'foreign_key_target'=>'projects',
				'semantic_category'=>$category,
			];
		}
		if($fields===[]){
			$fields[]=['name'=>'status','type'=>'string','required'=>true,'options'=>['draft','approved'],'default'=>'draft'];
		}
		$entityRepresentatives=[];
		foreach($entityInputs as $entity=>$category){
			$entityRepresentatives[$category]=$entity;
		}
		$relationships=[];
		foreach($entityRepresentatives as $category=>$entity){
			$relationships[]=[
				'field'=>str_replace('-','_',$this->invoke('slug_name',$entity)).'_id',
				'target_entity'=>$entity,
				'target_table'=>str_replace('-','_',$this->invoke('slug_name',$entity)),
				'category'=>$category,
			];
		}
		$schemas=[[
			'entity'=>'SemanticGovernanceRecord',
			'table'=>'semantic_governance_records',
			'fields'=>$fields,
			'relationships'=>$relationships,
		]];
		foreach($entityRepresentatives as $category=>$entity){
			$schemas[]=[
				'entity'=>$entity,
				'table'=>str_replace('-','_',$this->invoke('slug_name',$entity)),
				'fields'=>[],
				'relationships'=>[],
				'semantic_category'=>$category,
			];
		}
		$summary=match((string)$configuration['arguments']){
			'schemas'=>$this->invoke((string)$configuration['summary'],$schemas),
			'schemas-and-planned'=>$this->invoke((string)$configuration['summary'],$schemas,$schemas),
			'schemas-and-task'=>$this->invoke((string)$configuration['summary'],$schemas,$task),
			default=>throw new RuntimeException('Unsupported MCP governance family argument contract.'),
		};
		if(!is_array($summary)){
			throw new RuntimeException('MCP governance summary must be an array: '.$family);
		}
		$noisySchemas=$this->appBuilderNoisySchemas($schemas);
		$noisySummary=match((string)$configuration['arguments']){
			'schemas'=>$this->invoke((string)$configuration['summary'],$noisySchemas),
			'schemas-and-planned'=>$this->invoke((string)$configuration['summary'],$noisySchemas,$noisySchemas),
			'schemas-and-task'=>$this->invoke((string)$configuration['summary'],$noisySchemas,$task),
			default=>throw new RuntimeException('Unsupported MCP governance family argument contract.'),
		};
		if(!is_array($noisySummary)){
			throw new RuntimeException('MCP noisy governance summary must be an array: '.$family);
		}
		$handoff=[];
		$noisyHandoff=[];
		if(isset($configuration['handoff'])){
			$handoff=(string)$configuration['handoff']==='app_builder_lifecycle_state_handoff'
				? $this->invoke((string)$configuration['handoff'],$schemas,$summary)
				: $this->invoke((string)$configuration['handoff'],$summary);
			if(!is_array($handoff)){
				throw new RuntimeException('MCP governance handoff must be an array: '.$family);
			}
			$noisyHandoffSummary=$summary;
			if((string)$configuration['handoff']==='app_builder_lifecycle_state_handoff'){
				$noisyHandoffSummary['state_fields']=array_merge(
					['invalid-state-field'],
					is_array($noisyHandoffSummary['state_fields'] ?? null) ? $noisyHandoffSummary['state_fields'] : []
				);
				$noisyHandoff=$this->invoke((string)$configuration['handoff'],$schemas,$noisyHandoffSummary);
			}else{
				$noisyHandoffSummary['controls']=array_merge([
					'invalid-control',
					['id'=>'noisy-contract-control','fields'=>['invalid-field']],
				],is_array($noisyHandoffSummary['controls'] ?? null) ? $noisyHandoffSummary['controls'] : []);
				$noisyHandoff=$this->invoke((string)$configuration['handoff'],$noisyHandoffSummary);
			}
			if(!is_array($noisyHandoff)){
				throw new RuntimeException('MCP noisy governance handoff must be an array: '.$family);
			}
		}
		return [
			'family'=>$family,
			'taxonomy_methods'=>array_keys($methodResults),
			'taxonomy_invocations'=>array_sum(array_map('count',$methodResults)),
			'field_categories'=>array_values(array_unique(array_values($fieldInputs))),
			'entity_categories'=>array_values(array_unique(array_values($entityInputs))),
			'task_signal_count'=>count(array_values(array_unique($taskPhrases))),
			'schema_count'=>count($schemas),
			'summary_keys'=>array_keys($summary),
			'control_ids'=>$this->appBuilderControlIds($summary),
			'handoff_keys'=>array_keys($handoff),
			'noisy_handoff_keys'=>array_keys($noisyHandoff),
			'noisy_summary_keys'=>array_keys($noisySummary),
		];
	}

	/** @param array<int,mixed> $schemas @return array<int,mixed> */
	private function appBuilderNoisySchemas(array $schemas): array {
		$noisy=['invalid-schema-row'];
		foreach($schemas as $schema){
			if(!is_array($schema)){
				$noisy[]=$schema;
				continue;
			}
			$copy=$schema;
			$copy['fields']=array_merge([
				'invalid-field-row',
				['name'=>''],
			],is_array($copy['fields'] ?? null) ? $copy['fields'] : []);
			$copy['relationships']=array_merge([
				'invalid-relationship-row',
				['field'=>'','target_entity'=>''],
			],is_array($copy['relationships'] ?? null) ? $copy['relationships'] : []);
			$noisy[]=$copy;
		}
		return $noisy;
	}

	/** @return list<string> */
	private function appBuilderGovernanceStringMethods(string $prefix): array {
		$reflection=new \ReflectionObject($this->server);
		$prefixes=['app_builder_'.$prefix.'_'];
		if($prefix==='process_policy'){
			$prefixes[]='app_builder_domain_workflow_';
		}
		$methods=[];
		foreach($reflection->getMethods() as $method){
			$name=$method->getName();
			if(!array_filter($prefixes,static fn(string $candidate): bool=>str_starts_with($name,$candidate))){
				continue;
			}
			$parameters=$method->getParameters();
			if($parameters===[] || count(array_filter($parameters,static fn(\ReflectionParameter $parameter): bool=>!$parameter->isOptional()))!==1){
				continue;
			}
			$type=$parameters[0]->getType();
			if(!$type instanceof \ReflectionNamedType || $type->getName()!=='string'){
				continue;
			}
			$methods[]=$name;
		}
		sort($methods);
		return $methods;
	}

	/** @return list<string> */
	private function appBuilderMethodStringLiterals(string $method): array {
		$reflection=new \ReflectionMethod($this->server,$method);
		$file=$reflection->getFileName();
		if(!is_string($file)){
			throw new RuntimeException('Unable to read MCP governance method source: '.$method);
		}
		return self::sourceMethodStringLiterals($file,$method);
	}

	/** @return list<string> */
	private static function sourceMethodStringLiterals(string $file,string $method): array {
		$source=file_get_contents($file);
		if(!is_string($source)){
			throw new RuntimeException('Unable to read MCP method source: '.$method);
		}
		$literals=[];
		$waitingForName=false;
		$target=false;
		$started=false;
		$depth=0;
		foreach(token_get_all($source) as $token){
			if(!$target){
				if(is_array($token) && $token[0]===T_FUNCTION){
					$waitingForName=true;
					continue;
				}
				if($waitingForName && is_array($token) && $token[0]===T_STRING){
					$target=(string)$token[1]===$method;
					$waitingForName=false;
				}
				continue;
			}
			if($token==='{'){
				$started=true;
				$depth++;
				continue;
			}
			if($token==='}'){
				$depth--;
				if($started && $depth===0){break;}
				continue;
			}
			if(!is_array($token) || $token[0]!==T_CONSTANT_ENCAPSED_STRING){continue;}
			$encoded=(string)$token[1];
			$quote=$encoded[0] ?? '';
			$value=substr($encoded,1,-1);
			$value=$quote==="'"
				? str_replace(["\\\\","\\'"],["\\","'"],$value)
				: stripcslashes($value);
			if($value!=='' && strlen($value)<=240){
				$literals[]=$value;
			}
		}
		if(!$started){throw new RuntimeException('MCP source method was not found: '.$method);}
		return array_values(array_unique($literals));
	}

	private function appBuilderSemanticResultShape(mixed $result): string {
		return match(true){
			is_array($result)=>'array:'.count($result),
			is_bool($result)=>'bool:'.($result ? 'true' : 'false'),
			is_string($result)=>'string:'.$result,
			$result===null=>'null',
			default=>get_debug_type($result),
		};
	}

	/** @param array<string,mixed> $summary @return list<string> */
	private function appBuilderControlIds(array $summary): array {
		$ids=[];
		foreach(is_array($summary['controls'] ?? null) ? $summary['controls'] : [] as $control){
			$id=is_array($control) ? trim((string)($control['id'] ?? '')) : '';
			if($id!==''){$ids[]=$id;}
		}
		return array_values(array_unique($ids));
	}
}
