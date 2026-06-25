<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Collects callable and browser-oriented panel regression checks into one serializable suite.
 *
 * A suite owns a PanelTestHarness, an ordered list of server-side checks, optional browser manifests, suite metadata, and the most recent report. It is intentionally lightweight: registration methods only build deterministic manifests, while run() performs callback execution, exception capture, timing, and report construction for diagnostics or Flightdeck surfaces.
 */
final class PanelRegressionSuite implements \JsonSerializable {

	private string $name;
	private PanelTestHarness $harness;
	private array $checks=[];
	private array $browserManifests=[];
	private array $meta=[];
	private ?PanelRegressionReport $lastReport=null;

	/**
	 * Creates a named suite around an existing panel, manager, harness, or default test harness.
	 *
	 * The suite name is normalized with Resource naming rules so generated report identifiers, JSON manifests, and UI labels stay stable across runs. When a harness is not supplied, the constructor asks PanelTestHarness to wrap the panel context.
	 *
	 * @param string $name Human-readable suite name; blank or invalid names fall back to regression_suite.
	 * @param PanelInstance|PanelManager|PanelTestHarness|null $panel Panel context or prebuilt harness used by checks.
	 */
	public function __construct(string $name='regression_suite', PanelInstance|PanelManager|PanelTestHarness|null $panel=null) {
		$this->name=Resource::normalizeName($name) ?: 'regression_suite';
		$this->harness=$panel instanceof PanelTestHarness ? $panel : PanelTestHarness::make($panel);
	}

	/**
	 * Builds a fluent panel regression suite instance.
	 *
	 * This static constructor mirrors the normal constructor for call sites that chain check registration directly after creation. It does not cache instances; every call returns an isolated suite with its own harness, metadata, manifests, and report state.
	 *
	 * @param string $name Human-readable suite name; blank or invalid names fall back to regression_suite.
	 * @param PanelInstance|PanelManager|PanelTestHarness|null $panel Panel context or prebuilt harness used by checks.
	 * @return self New suite ready for check and browser manifest registration.
	 */
	public static function make(string $name='regression_suite', PanelInstance|PanelManager|PanelTestHarness|null $panel=null): self {
		return new self($name, $panel);
	}

	/**
	 * Returns the harness shared by all registered checks.
	 *
	 * Checks receive the same object during run(), which lets them share panel routing helpers, request simulation, resource factories, and any harness state accumulated during the suite.
	 *
	 * @return PanelTestHarness Harness bound to this suite.
	 */
	public function harness(): PanelTestHarness {
		return $this->harness;
	}

	/**
	 * Returns the normalized suite name used in reports and manifests.
	 *
	 * @return string Stable suite identifier.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Counts registered server-side checks.
	 *
	 * Browser manifests are tracked separately because they may be executed by a different runner. This count reflects only callbacks and skipped checks stored in the ordered check list.
	 *
	 * @return int Number of callback or skipped checks.
	 */
	public function count(): int {
		return count($this->checks);
	}

	/**
	 * Appends an executable server-side regression check.
	 *
	 * The callback receives the suite harness and the suite itself. During run(), false is treated as an assertion failure, a non-empty string becomes the result message, and an array becomes the result details payload. Blank names are ignored to keep manifests addressable.
	 *
	 * @param string $name Check label displayed in reports.
	 * @param callable $callback Function invoked as callback(PanelTestHarness $harness, PanelRegressionSuite $suite).
	 * @param array<string,mixed> $meta Arbitrary check metadata copied into manifest and report rows.
	 * @return self Same suite for fluent registration.
	 */
	public function check(string $name, callable $callback, array $meta=[]): self {
		$name=trim($name);
		if($name===''){
			return $this;
		}
		$this->checks[]=[
			'name'=>$name,
			'callback'=>$callback,
			'meta'=>$meta,
			'skip_reason'=>null,
		];
		return $this;
	}

	/**
	 * Appends a skipped check placeholder to preserve regression plan visibility.
	 *
	 * Skipped entries are included in check manifests and reports but do not execute a callback. This lets incomplete or environment-gated scenarios remain visible in regression reports and operator diagnostics without failing the suite.
	 *
	 * @param string $name Check label displayed in reports.
	 * @param string $reason Optional skip reason; defaults to "Skipped" when blank.
	 * @param array<string,mixed> $meta Arbitrary check metadata copied into manifest and report rows.
	 * @return self Same suite for fluent registration.
	 */
	public function skip(string $name, string $reason='', array $meta=[]): self {
		$name=trim($name);
		if($name===''){
			return $this;
		}
		$this->checks[]=[
			'name'=>$name,
			'callback'=>null,
			'meta'=>$meta,
			'skip_reason'=>trim($reason) ?: 'Skipped',
		];
		return $this;
	}

	/**
	 * Merges suite-level metadata into future manifests and reports.
	 *
	 * Passing an array merges many keys at once. Passing a string stores a single value when the key is not blank. Metadata is not interpreted by the runner; callers can use it for environment tags, release ids, browser targets, or documentation annotations.
	 *
	 * @param array|string $key Metadata map or individual metadata key.
	 * @param mixed $value Metadata value used when $key is a string.
	 * @return self Same suite for fluent registration.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->meta=array_replace($this->meta, $key);
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->meta[$key]=$value;
		}
		return $this;
	}

	/**
	 * Exposes a safe manifest view of registered checks.
	 *
	 * The returned rows deliberately omit callbacks so JSON output, documentation extraction, and diagnostics never leak closures or executable state. Each row reports whether the check is pending execution or pre-marked as skipped.
	 *
	 * @return array<int,array{name:string,status:string,meta:array}> Serializable check manifest rows.
	 */
	public function checks(): array {
		return array_map(static function(array $check): array {
			return [
				'name'=>$check['name'],
				'status'=>$check['skip_reason']!==null ? 'skipped' : 'pending',
				'meta'=>$check['meta'],
			];
		}, $this->checks);
	}

	/**
	 * Registers a browser regression manifest alongside server-side checks.
	 *
	 * Callers may pass a prebuilt PanelBrowserRegressionManifest, an array payload accepted by fromArray(), or a name/url/options tuple. Browser manifests are not executed by run(); they are exported for browser-capable runners.
	 *
	 * @param string|PanelBrowserRegressionManifest|array $name Manifest instance, manifest payload, or browser scenario name.
	 * @param ?string $url Target URL required when registering by name.
	 * @param array<string,mixed> $options Browser scenario options forwarded to PanelBrowserRegressionManifest::make().
	 * @return self Same suite for fluent registration.
	 * @throws \InvalidArgumentException When registering by name without a URL.
	 */
	public function browser(string|PanelBrowserRegressionManifest|array $name, ?string $url=null, array $options=[]): self {
		if($name instanceof PanelBrowserRegressionManifest){
			$this->browserManifests[]=$name;
			return $this;
		}
		if(is_array($name)){
			$this->browserManifests[]=PanelBrowserRegressionManifest::fromArray($name);
			return $this;
		}
		if($url===null || trim($url)===''){
			throw new \InvalidArgumentException('Browser regression URL cannot be empty.');
		}
		$this->browserManifests[]=PanelBrowserRegressionManifest::make($name, $url, $options);
		return $this;
	}

	/**
	 * Exposes registered browser scenarios as manifests for browser runners.
	 *
	 * Each manifest is normalized through PanelBrowserRegressionManifest::toArray(), keeping the suite export independent from object identity and safe for JSON responses or an external browser runner.
	 *
	 * @return array<int,array<string,mixed>> Browser manifest payloads.
	 */
	public function browserManifests(): array {
		return array_map(static fn(PanelBrowserRegressionManifest $manifest): array => $manifest->toArray(), $this->browserManifests);
	}

	/**
	 * Runs every registered server-side check and builds a regression report.
	 *
	 * Checks execute in registration order. Skipped checks become skipped rows, false returns become assertion failures, strings become pass messages, arrays become details payloads, and thrown exceptions are captured with class, file, and line diagnostics. The completed report is stored as the suite's last report for later manifest export.
	 *
	 * @param array<string,mixed> $meta Additional report metadata merged over suite-level metadata for this run.
	 * @return PanelRegressionReport Completed report with ordered results, duration, and metadata.
	 */
	public function run(array $meta=[]): PanelRegressionReport {
		$started=microtime(true);
		$results=[];
		foreach($this->checks as $index=>$check){
			$checkStarted=microtime(true);
			$name=(string)$check['name'];
			$status='passed';
			$message='Passed';
			$details=null;
			if($check['skip_reason']!==null){
				$status='skipped';
				$message=(string)$check['skip_reason'];
			}
			else{
				try{
					$result=($check['callback'])($this->harness, $this);
					if($result===false){
						throw new \AssertionError('Check returned false.');
					}
					if(is_string($result) && trim($result)!==''){
						$message=$result;
					}
					elseif(is_array($result)){
						$details=$result;
						$message=(string)($result['message'] ?? $message);
					}
				}
				catch(\Throwable $exception){
					$status='failed';
					$message=$exception->getMessage();
					$details=[
						'exception'=>get_class($exception),
						'file'=>$exception->getFile(),
						'line'=>$exception->getLine(),
					];
				}
			}
			$results[]=[
				'name'=>$name,
				'status'=>$status,
				'message'=>$message,
				'duration_ms'=>round((microtime(true)-$checkStarted) * 1000, 3),
				'index'=>$index + 1,
				'details'=>$details,
				'meta'=>$check['meta'],
			];
		}
		$this->lastReport=new PanelRegressionReport($this->name, $results, (microtime(true)-$started) * 1000, array_replace($this->meta, $meta));
		return $this->lastReport;
	}

	/**
	 * Returns the most recent regression report, when the suite has been run.
	 *
	 * The report remains null until run() completes. Re-running the suite replaces this reference with the newest report, while previous report objects remain available only to callers that already hold them.
	 *
	 * @return ?PanelRegressionReport Last completed report, or null before the first run.
	 */
	public function report(): ?PanelRegressionReport {
		return $this->lastReport;
	}

	/**
	 * Builds the suite manifest consumed by browser regression runners.
	 */
	public function manifest(array $meta=[]): array {
		return [
			'type'=>'panel_regression_suite',
			'name'=>$this->name,
			'check_count'=>count($this->checks),
			'checks'=>$this->checks(),
			'browser_count'=>count($this->browserManifests),
			'browser_manifests'=>$this->browserManifests(),
			'last_report'=>$this->lastReport?->toArray(),
			'meta'=>array_replace($this->meta, $meta),
		];
	}

	/**
	 * Exposes the suite manifest for array consumers.
	 */
	public function toArray(): array {
		return $this->manifest();
	}

	/**
	 * Serializes the regression suite manifest for JSON output.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
