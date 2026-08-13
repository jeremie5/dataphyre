<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Panel\Testing;

use Dataphyre\Http\Request as HttpRequest;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTestHarness;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use Dataphyre\Test\Contracts\TestContext;

/**
 * Module-owned, self-describing Panel vocabulary for TestKit contracts.
 *
 * The kit keeps HTTP construction, stale ambient route identity, selected-row
 * inputs, dispatch, and structured assertions out of business scenarios. It is
 * loaded only by the testing module bootstrap and is never part of a normal
 * Panel request.
 */
final class PanelTestKit {

	private PanelTestHarness $harness;

	/** @var array<string,mixed> */
	private array $ambientQuery=[];

	public function __construct(private TestContext $context, PanelInstance|PanelManager|PanelTestHarness|null $panel=null) {
		$this->harness=$panel instanceof PanelTestHarness ? $panel : PanelTestHarness::make($panel);
	}

	/** Registers `$t->panel()` through TestKit's module-extension boundary. */
	public static function register(): void {
		if(!method_exists(Context::class, 'extend')){
			throw new \LogicException('PanelTestKit requires a TestKit build with Context extensions.');
		}
		Context::extend('panel', static fn(TestContext $context): self=>new self($context));
	}

	public function harness(): PanelTestHarness {
		return $this->harness;
	}

	public function using(PanelInstance|PanelManager|PanelTestHarness $panel): self {
		$copy=clone $this;
		$copy->harness=$panel instanceof PanelTestHarness ? $panel : PanelTestHarness::make($panel);
		return $copy;
	}

	public function registerResource(Resource|array $resource): self {
		$this->harness->register($resource);
		return $this;
	}

	/**
	 * Adds context that should be irrelevant to the pretty route under test.
	 *
	 * This is intentionally named as ambient state: a contract using it states
	 * that current-page query identity must not override the requested route.
	 *
	 * @param array<string,mixed> $query
	 */
	public function underAmbientQuery(array $query): self {
		$copy=clone $this;
		$copy->ambientQuery=array_replace($this->ambientQuery, $query);
		return $copy;
	}

	/** Adds a complete stale route identity while preserving supplied view state. */
	public function underStaleRouteIdentity(array $viewState=[]): self {
		return $this->underAmbientQuery(array_replace([
			'resource'=>'stale_resource',
			'operation'=>'show',
			'record'=>'stale-record',
			'action'=>'stale_action',
			'relation'=>'stale_relation',
		], $viewState));
	}

	/**
	 * Begins a real mounted pretty-route journey through HttpRequest,
	 * PanelRequest, and the harness manager.
	 *
	 * @param list<string>|string $segments
	 */
	public function journey(array|string $segments, string $method='GET'): PanelJourney {
		$segments=is_array($segments)
			? array_values(array_filter(array_map(static fn(mixed $segment): string=>trim((string)$segment, '/'), $segments), static fn(string $segment): bool=>$segment!==''))
			: array_values(array_filter(explode('/', trim($segments, '/')), static fn(string $segment): bool=>$segment!==''));
		return new PanelJourney($this->context, $this->harness, $segments, $method, $this->ambientQuery);
	}

	/** @param list<string> $selected */
	public function bulkUpdate(string $resource, array $selected): PanelJourney {
		return $this->journey([$resource, 'bulk_update'], 'POST')->selected($selected);
	}

	/** @param list<string> $selected */
	public function bulkTransition(string $resource, string $transition, array $selected): PanelJourney {
		return $this->journey([$resource, 'bulk_transition'], 'POST')
			->selected($selected)
			->query(['transition'=>$transition]);
	}

	/** @param list<string> $selected */
	public function bulkExport(string $resource, array $selected, string $format='csv'): PanelJourney {
		return $this->journey([$resource, 'bulk_export'], 'POST')
			->selected($selected)
			->query(['format'=>$format]);
	}

	/** @param list<string> $selected */
	public function confirmedBulkAction(string $resource, string $action, array $selected): PanelJourney {
		return $this->journey([$resource, 'action', $action], 'POST')
			->selected($selected)
			->input(['__panel_action_confirm'=>'1']);
	}

	/** Runs Panel's source-contract analyzer outside phpdbg line instrumentation. */
	public function staticAnalysis(): PanelStaticAnalysisProbe {
		return new PanelStaticAnalysisProbe($this->context);
	}
}

/**
 * Process-isolated evidence for Panel's developer-only source analyzer.
 *
 * The analyzer tokenizes source in long loops. Running that tooling through the
 * ordinary PHP CLI keeps phpdbg's operation log bounded while the test worker
 * still covers and asserts this typed boundary.
 */
final class PanelStaticAnalysisProbe {

	private const PROGRAM=<<<'PHP'
define('DATAPHYRE_PANEL_STATIC_ANALYSIS_EMBEDDED', true);
require $argv[1];
$operation=$argv[2] ?? '';
if($operation==='contract'){
	$contract=dp_panel_static_analysis_contract();
	$payload=[
		'contract'=>$contract,
		'check'=>dp_panel_static_analysis_check(),
		'phpdoc_failures'=>dp_panel_static_analysis_phpdoc_failures(),
		'deterministic'=>dp_panel_static_analysis_json($contract)===dp_panel_static_analysis_json(dp_panel_static_analysis_contract()),
	];
}elseif($operation==='drift'){
	$directory=$argv[3] ?? '';
	if($directory==='' || (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory))){
		throw new RuntimeException('Static-analysis drift workspace is unavailable.');
	}
	$missing=dp_panel_static_analysis_check(null, $directory.'/missing-contract.json');
	file_put_contents($directory.'/invalid-contract.json', '{');
	$invalid=dp_panel_static_analysis_check(null, $directory.'/invalid-contract.json');
	$contract=dp_panel_static_analysis_contract();
	$contract['targets']['Dataphyre\\Panel\\Field']['class_tags'][]='@template TDrift';
	file_put_contents($directory.'/drifted-contract.json', dp_panel_static_analysis_json($contract));
	$payload=['missing'=>$missing, 'invalid'=>$invalid, 'drift'=>dp_panel_static_analysis_check(null, $directory.'/drifted-contract.json')];
}else{
	throw new InvalidArgumentException('Unknown Panel static-analysis probe operation.');
}
echo json_encode($payload, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
PHP;

	public function __construct(private TestContext $context) {}

	/** @return array{contract:array<string,mixed>,check:list<string>,phpdoc_failures:list<string>,deterministic:bool} */
	public function contractEvidence(): array {
		return $this->run('contract');
	}

	/** @return array{missing:list<string>,invalid:list<string>,drift:list<string>} */
	public function driftEvidence(string $workspace): array {
		return $this->run('drift', [$workspace]);
	}

	/** @param list<string> $arguments @return array<string,mixed> */
	private function run(string $operation, array $arguments=[]): array {
		$root=dirname(__DIR__, 5);
		$result=$this->context->phpProcess(
			['-r', self::PROGRAM, $root.'/dev/tools/panel_static_analysis.php', $operation, ...$arguments],
			timeout_millis:30000
		);
		if(!$result->succeeded()){
			throw new \RuntimeException('Panel static-analysis probe failed: '.trim($result->stderr().' '.$result->stdout()));
		}
		$payload=$result->json();
		if(!is_array($payload)){
			throw new \UnexpectedValueException('Panel static-analysis probe must return a JSON object.');
		}
		return $payload;
	}
}

/**
 * One observable Panel request journey with causal, structured assertions.
 */
final class PanelJourney {

	/** @var array<string,mixed> */
	private array $query;

	/** @var array<string,mixed> */
	private array $input=[];

	/** @var array<string,mixed> */
	private array $headers=[];

	private ?PanelRequest $request=null;
	private ?PanelPageResult $result=null;

	/**
	 * @param list<string> $segments
	 * @param array<string,mixed> $ambientQuery
	 */
	public function __construct(
		private TestContext $context,
		private PanelTestHarness $harness,
		private array $segments,
		private string $method='GET',
		array $ambientQuery=[]
	) {
		$this->method=strtoupper(trim($method)) ?: 'GET';
		$this->query=$ambientQuery;
	}

	/** @param array<string,mixed> $query */
	public function query(array $query): self {
		$this->query=array_replace($this->query, $query);
		return $this->invalidate();
	}

	/** @param array<string,mixed> $input */
	public function input(array $input): self {
		$this->input=array_replace($this->input, $input);
		return $this->invalidate();
	}

	/** @param array<string,mixed> $headers */
	public function headers(array $headers): self {
		$this->headers=array_replace($this->headers, $headers);
		return $this->invalidate();
	}

	/** @param list<string> $selected */
	public function selected(array $selected): self {
		return $this->input(['selected'=>array_values(array_map('strval', $selected))]);
	}

	public function returningTo(string $url): self {
		return $this->input(['return_to'=>$url]);
	}

	public function asModal(): self {
		return $this->query(['__panel_partial'=>'modal'])
			->headers(['X-Requested-With'=>'DataphyrePanelModal']);
	}

	public function asFragment(): self {
		return $this->query(['__panel_partial'=>'fragment'])
			->headers(['X-Requested-With'=>'DataphyrePanelFragment']);
	}

	public function request(): PanelRequest {
		if($this->request instanceof PanelRequest){
			return $this->request;
		}
		$path='/panel/'.implode('/', array_map('rawurlencode', $this->segments));
		$http=HttpRequest::create(
			$this->method,
			$path,
			$this->query,
			$this->input,
			[],
			[],
			$this->headers,
			['panel_segments'=>$this->segments],
		);
		return $this->request=PanelRequest::fromHttpRequest($http, ['infer_segments'=>true]);
	}

	public function dispatch(): self {
		$this->result=$this->harness->dispatch($this->request());
		return $this;
	}

	public function result(): PanelPageResult {
		if(!$this->result instanceof PanelPageResult){
			$this->dispatch();
		}
		return $this->result;
	}

	/**
	 * @param array{resource?:mixed,operation?:mixed,record?:mixed,action?:mixed,relation?:mixed} $identity
	 */
	public function expectIdentity(array $identity): self {
		$accessors=[];
		foreach([
			'resource'=>'resourceName',
			'operation'=>'operation',
			'record'=>'recordKey',
			'action'=>'actionName',
			'relation'=>'relationName',
		] as $key=>$accessor){
			if(array_key_exists($key, $identity)){
				$accessors[$accessor]=$identity[$key];
			}
		}
		if($accessors===[]){
			throw new \InvalidArgumentException('Panel identity expectation must name at least one route value.');
		}
		$this->context->hasAccessorValues($accessors, $this->request());
		return $this;
	}

	/** @param array<string,mixed> $values */
	public function expectQuery(array $values): self {
		foreach($values as $key=>$expected){
			$this->context->same($expected, $this->request()->query((string)$key), "Panel query value '{$key}' did not match its contract.");
		}
		return $this;
	}

	public function expectStatus(int $status): self {
		$this->context->same($status, $this->result()->status(), 'Panel response status did not match its contract.');
		return $this;
	}

	public function expectRedirect(string $url, int $status=303): self {
		$this->expectStatus($status);
		$this->context->same($url, $this->result()->redirectTo(), 'Panel redirect destination did not match its contract.');
		return $this;
	}

	public function expectHeader(string $name, string $value): self {
		$this->context->responseHeader($name, $value, $this->result());
		return $this;
	}

	public function expectHeaderContains(string $name, string $fragment): self {
		$headers=$this->result()->headers();
		$actual='';
		foreach($headers as $header=>$value){
			if(strcasecmp((string)$header, $name)===0){
				$actual=is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
				break;
			}
		}
		$this->context->contains($fragment, $actual, "Panel response header '{$name}' omitted a required contract fragment.");
		return $this;
	}

	/** @param array<string,mixed> $values */
	public function expectData(array $values): self {
		$this->context->hasPathValues($values, $this->result()->data());
		return $this;
	}

	public function expectContentContains(string ...$fragments): self {
		$this->context->containsAll($fragments, $this->result()->content(), 'Panel response content omitted a required contract fragment.');
		return $this;
	}

	public function expectContentMissing(string ...$fragments): self {
		$this->context->containsNone($fragments, $this->result()->content(), 'Panel response content included a forbidden contract fragment.');
		return $this;
	}

	public function expectNotificationCount(int $count): self {
		$this->context->count($count, $this->result()->notifications(), 'Panel notification count did not match its contract.');
		return $this;
	}

	private function invalidate(): self {
		$this->request=null;
		$this->result=null;
		return $this;
	}
}
