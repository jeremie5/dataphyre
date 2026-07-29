<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

use LogicException;

/** Resolves definitions into public occurrences and delegates app-owned incident observation. */
final class RecoveryManager {
	private mixed $helpResolver;
	private mixed $incidentObserver;

	public function __construct(
		private RecoveryRegistry $registry,
		private string $typeBase='',
		?callable $helpResolver=null,
		?callable $incidentObserver=null
	) {
		$this->typeBase=rtrim(trim($this->typeBase), '/');
		$this->helpResolver=$helpResolver;
		$this->incidentObserver=$incidentObserver;
	}

	public function registry(): RecoveryRegistry {
		return $this->registry;
	}

	/**
	 * @param array<string,mixed> $overrides Public title/detail/instance overrides.
	 * @param array<string,mixed> $evidenceSource Candidate evidence filtered by the definition allowlist.
	 */
	public function problem(string $code, RecoveryContext $context, array $overrides=[], array $evidenceSource=[]): Problem {
		$definition=$this->registry->problem($code);
		if($definition===null) throw new LogicException('Dataphyre Recovery has no registered problem definitions.');
		$definition=$definition->withTypeBase($this->typeBase);
		if($definition->helpUrl()===null && is_callable($this->helpResolver)){
			$definition=$definition->withHelpUrl(($this->helpResolver)($definition, $context));
		}
		$correlationId=$context->correlationId() ?? self::correlationId();
		$instance=self::instance($overrides['instance'] ?? null, $context, $correlationId);
		$title=self::publicText($overrides['title'] ?? null, $definition->title($context->locale()), 200);
		$detail=self::publicText($overrides['detail'] ?? null, $definition->detail($context->locale()), 1000);
		$evidence=Evidence::from($evidenceSource, $definition->evidenceKeys());
		$actions=[];
		foreach($definition->actionIds() as $actionId){
			$action=$this->registry->action($actionId)?->resolve($context);
			if($action!==null) $actions[]=$action;
		}
		$fingerprint=$definition->incidentPolicy()==='none'
			? null
			: IncidentFingerprint::for($definition, $context, $evidence);
		$problem=new Problem($definition, $title, $detail, $instance, $correlationId, $actions, $evidence, $fingerprint);
		if($fingerprint!==null && is_callable($this->incidentObserver)){
			$problem=$problem->withIncidentAcknowledgement(
				($this->incidentObserver)($problem, $context)===true
			);
		}
		return $problem;
	}

	public static function correlationId(): string {
		try{
			return 'rec_'.bin2hex(random_bytes(16));
		}catch(\Throwable){
			return 'rec_'.hash('sha256', uniqid('', true).microtime(true));
		}
	}

	private static function publicText(mixed $value, string $fallback, int $limit): string {
		$value=is_string($value) || is_numeric($value) ? trim((string)$value) : '';
		if($value==='') $value=$fallback;
		$value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $fallback;
		return substr($value, 0, $limit);
	}

	private static function instance(mixed $value, RecoveryContext $context, string $correlationId): string {
		$value=is_string($value) ? trim($value) : '';
		if($value!=='' && str_starts_with($value, '/') && !str_starts_with($value, '//') && strlen($value)<=2048){
			return $value;
		}
		$path=$context->requestPath();
		return $path.(str_contains($path, '?') ? '&' : '?').'problem='.$correlationId;
	}
}
