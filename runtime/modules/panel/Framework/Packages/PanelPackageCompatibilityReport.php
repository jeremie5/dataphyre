<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable compatibility result, baseline delta, and CI policy decision. */
final class PanelPackageCompatibilityReport implements \JsonSerializable {
	public const FORMAT='dataphyre.panel.package.compatibility-report.v1';
	private const MAX_NESTING_DEPTH=64;
	private const MAX_CANONICAL_ITEMS=4194304;

	private readonly bool $ok;
	private readonly array $cases;
	private readonly array $summary;
	private readonly array $comparison;
	private readonly array $policyFailures;
	private readonly string $planFingerprint;
	private readonly string $baselineFingerprint;
	private readonly string $fingerprint;
	private readonly array $meta;

	public function __construct(PanelPackageCompatibilityPlan $plan, array $baseline=[]) {
		$this->planFingerprint=$plan->fingerprint();
		$this->cases=array_map(static fn(PanelPackageCompatibilityCase $case): array=>$case->toArray(),$plan->cases());
		$baselineCases=$this->normalizeBaseline($baseline,$plan->limits()['max_baseline_cases']);
		$this->baselineFingerprint=$baselineCases===[] ? '' : hash('sha256',self::canonicalJson($baselineCases));
		$current=[];$blocked=[];
		foreach($this->cases as $case){$key=(string)$case['case_key'];$current[$key]=['blocked'=>(bool)$case['blocked'],'failures'=>(array)$case['failures'],'fingerprint'=>(string)$case['fingerprint']];if($case['blocked']){$blocked[]=$key;}}
		ksort($current,SORT_STRING);sort($blocked,SORT_STRING);
		$newCases=[];$newlyBlocked=[];$regressions=[];$recovered=[];$improvements=[];
		foreach($current as $key=>$case){
			if(!isset($baselineCases[$key])){$newCases[]=$key;continue;}
			$before=$baselineCases[$key];$added=array_values(array_diff($case['failures'],$before['failures']));$removed=array_values(array_diff($before['failures'],$case['failures']));sort($added,SORT_STRING);sort($removed,SORT_STRING);
			if($case['blocked'] && !$before['blocked']){$newlyBlocked[]=$key;}
			if($added!==[]){$regressions[]=['case_key'=>$key,'added_failures'=>$added,'previous_fingerprint'=>$before['fingerprint'],'current_fingerprint'=>$case['fingerprint']];}
			if(!$case['blocked'] && $before['blocked']){$recovered[]=$key;}
			if($removed!==[]){$improvements[]=['case_key'=>$key,'removed_failures'=>$removed];}
		}
		$removedCases=array_values(array_diff(array_keys($baselineCases),array_keys($current)));
		foreach([$newCases,$newlyBlocked,$recovered,$removedCases] as &$items){sort($items,SORT_STRING);}unset($items);
		usort($regressions,static fn(array $left,array $right): int=>strcmp((string)$left['case_key'],(string)$right['case_key']));
		usort($improvements,static fn(array $left,array $right): int=>strcmp((string)$left['case_key'],(string)$right['case_key']));
		$this->comparison=[
			'baseline_present'=>$baselineCases!==[],'baseline_fingerprint'=>$this->baselineFingerprint,
			'new_cases'=>$newCases,'removed_cases'=>$removedCases,'newly_blocked'=>$newlyBlocked,
			'regressions'=>$regressions,'recovered'=>$recovered,'improvements'=>$improvements,
		];
		$policy=$plan->policy();$policyFailures=[];
		if($policy['fail_on_blocked'] && $blocked!==[]){$policyFailures[]='blocked_cases';}
		if($policy['fail_on_regression'] && ($newlyBlocked!==[] || $regressions!==[])){$policyFailures[]='compatibility_regression';}
		if($policy['fail_on_removed'] && $removedCases!==[]){$policyFailures[]='removed_baseline_cases';}
		$this->policyFailures=$policyFailures;
		$this->ok=$policyFailures===[];
		$packages=[];$runtimes=[];
		foreach($this->cases as $case){$packages[(string)$case['package']['id']]=true;$runtimes[(string)$case['runtime']['id']]=true;}
		$this->summary=[
			'package_count'=>count($packages),'runtime_count'=>count($runtimes),'case_count'=>count($this->cases),
			'compatible_count'=>count($this->cases)-count($blocked),'blocked_count'=>count($blocked),'blocked_cases'=>$blocked,
			'newly_blocked_count'=>count($newlyBlocked),'regression_count'=>count($regressions),'removed_case_count'=>count($removedCases),
		];
		$this->meta=$this->sanitize((array)($plan->toArray()['meta'] ?? []));
		$this->fingerprint=hash('sha256',self::canonicalJson([
			'format'=>self::FORMAT,'plan_fingerprint'=>$this->planFingerprint,'baseline_fingerprint'=>$this->baselineFingerprint,
			'case_fingerprints'=>array_map(static fn(array $case): array=>['case_key'=>$case['case_key'],'fingerprint'=>$case['fingerprint']],$this->cases),
			'comparison'=>$this->comparison,'policy_failures'=>$this->policyFailures,
		]));
	}

	public static function fromPlan(PanelPackageCompatibilityPlan $plan,array $baseline=[]): self { return new self($plan,$baseline); }
	public function ok(): bool { return $this->ok; }
	/** @return array<int,array<string,mixed>> */
	public function cases(): array { return $this->cases; }
	/** @return array<string,mixed> */
	public function summary(): array { return $this->summary; }
	/** @return array<string,mixed> */
	public function comparison(): array { return $this->comparison; }
	/** @return array<int,string> */
	public function policyFailures(): array { return $this->policyFailures; }
	public function fingerprint(): string { return $this->fingerprint; }

	/** @return array<string,mixed> Stable redacted CI report. */
	public function toArray(): array {
		return [
			'type'=>'panel_package_compatibility_report','format'=>self::FORMAT,'ok'=>$this->ok,
			'fingerprint'=>$this->fingerprint,'plan_fingerprint'=>$this->planFingerprint,'baseline_fingerprint'=>$this->baselineFingerprint,
			'summary'=>$this->summary,'comparison'=>$this->comparison,'policy_failures'=>$this->policyFailures,
			'cases'=>$this->cases,'meta'=>$this->meta,
		];
	}
	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return array<string,array{blocked:bool,failures:array<int,string>,fingerprint:string}> */
	private function normalizeBaseline(array $baseline,int $maxCases): array {
		if($baseline===[]){return [];}
		if(($baseline['type'] ?? null)!=='panel_package_compatibility_report' || ($baseline['format'] ?? null)!==self::FORMAT){throw new \InvalidArgumentException('Compatibility baseline format is unsupported.');}
		$rows=$baseline['cases'] ?? null;
		if(!is_array($rows) || !array_is_list($rows) || count($rows)>$maxCases){throw new \InvalidArgumentException('Compatibility baseline cases are malformed or exceed the limit.');}
		$normalized=[];
		foreach($rows as $row){
			if(!is_array($row) || !is_string($row['case_key'] ?? null) || !self::validCaseKey($row['case_key']) || !is_bool($row['blocked'] ?? null) || !is_array($row['failures'] ?? null) || !array_is_list($row['failures']) || count($row['failures'])>256 || !is_string($row['fingerprint'] ?? null) || preg_match('/^[a-f0-9]{64}$/D',$row['fingerprint'])!==1){throw new \InvalidArgumentException('Compatibility baseline case is malformed.');}
			$key=$row['case_key'];if($key==='' || isset($normalized[$key])){throw new \InvalidArgumentException('Compatibility baseline case key is blank or duplicated.');}
			$failures=[];foreach($row['failures'] as $failure){if(!is_string($failure) || preg_match('/^[a-z0-9][a-z0-9_.:-]{0,255}$/D',$failure)!==1 || in_array($failure,$failures,true)){throw new \InvalidArgumentException('Compatibility baseline failure code is malformed.');}$failures[]=$failure;}sort($failures,SORT_STRING);if($row['blocked']!==($failures!==[])){throw new \InvalidArgumentException('Compatibility baseline blocked state contradicts its failures.');}
			$normalized[$key]=['blocked'=>$row['blocked'],'failures'=>$failures,'fingerprint'=>$row['fingerprint']];
		}
		ksort($normalized,SORT_STRING);return $normalized;
	}
	private static function validCaseKey(string $key): bool {$hash=strrpos($key,'#');$at=$hash===false?false:strrpos(substr($key,0,$hash),'@');if($at===false||$hash===false){return false;}$package=substr($key,0,$at);$version=substr($key,$at+1,$hash-$at-1);$runtime=substr($key,$hash+1);return $package!==''&&strlen($package)<=128&&Resource::normalizeName($package)===$package&&PanelPackageManifest::validVersion($version)&&$runtime!==''&&strlen($runtime)<=128&&Resource::normalizeName($runtime)===$runtime;}

	private static function canonicalJson(mixed $value): string {$items=0;return json_encode(self::canonicalize($value,0,$items),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
	private static function canonicalize(mixed $value,int $depth,int &$items): mixed {if($depth>self::MAX_NESTING_DEPTH || ++$items>self::MAX_CANONICAL_ITEMS){throw new \InvalidArgumentException('Compatibility report value exceeds canonicalization limits.');}if(is_array($value)){if(!array_is_list($value)){ksort($value,SORT_STRING);}foreach($value as $key=>$item){$value[$key]=self::canonicalize($item,$depth+1,$items);}return $value;}if($value===null||is_bool($value)||is_int($value)||is_float($value)||is_string($value)){return $value;}throw new \InvalidArgumentException('Unsupported compatibility report value.');}
	private function sanitize(mixed $value,string $key=''): mixed {if($key!==''&&($this->sensitiveKey($key)||$this->locationKey($key))){return '[REDACTED]';}if(!is_array($value)){if(is_object($value)){return '[OBJECT]';}return is_string($value)&&$this->absolutePath($value)?'[REDACTED]':$value;}$safe=[];foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item,is_string($itemKey)?$itemKey:'');}return $safe;}
	private function sensitiveKey(string $key): bool {$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/','_',$key)??$key;return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i',$key)===1;}
	private function locationKey(string $key): bool {$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/','_',$key)??$key;return preg_match('/(?:^|[_\-.])(?:path|root|directory|filename|filepath|locator)(?:$|[_\-.])/i',$key)===1;}
	private function absolutePath(string $value): bool {$value=trim($value);return str_starts_with($value,'/')||str_starts_with($value,'\\\\')||preg_match('~^[A-Za-z]:[\\\\/]~D',$value)===1;}
}
