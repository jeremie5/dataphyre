<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use InvalidArgumentException;

/** Process-local registry connecting universal simulation mechanics to app-owned domains. */
final class SimulationRegistry {
	/** @var array<string,array{adapter:SimulationDomainAdapter,scenarios:array<string,SimulationScenario>,default:string}> */
	private array $domains=[];

	/** @param array<int,SimulationScenario> $scenarios */
	public function register(string $domain, SimulationDomainAdapter $adapter, array $scenarios, ?string $defaultScenario=null): self {
		$domain=$this->domainName($domain);
		$indexed=[];
		foreach($scenarios as $scenario){
			if($scenario instanceof SimulationScenario) $indexed[$scenario->id()]=$scenario;
		}
		if($domain==='' || $indexed===[]) throw new InvalidArgumentException('Simulation domains require a safe name and at least one scenario.');
		$defaultScenario=$defaultScenario!==null ? strtolower(trim($defaultScenario)) : (string)array_key_first($indexed);
		if(!isset($indexed[$defaultScenario])) throw new InvalidArgumentException('Default simulation scenario is not registered.');
		$this->domains[$domain]=['adapter'=>$adapter, 'scenarios'=>$indexed, 'default'=>$defaultScenario];
		return $this;
	}

	public function has(string $domain): bool { return isset($this->domains[$this->domainName($domain)]); }
	public function adapter(string $domain): ?SimulationDomainAdapter { return $this->domains[$this->domainName($domain)]['adapter'] ?? null; }
	public function scenario(string $domain, ?string $scenario=null): ?SimulationScenario {
		$definition=$this->domains[$this->domainName($domain)] ?? null;
		if($definition===null) return null;
		$scenario=strtolower(trim((string)($scenario ?? $definition['default'])));
		return $definition['scenarios'][$scenario] ?? null;
	}

	public function defaultScenario(string $domain): ?string { return $this->domains[$this->domainName($domain)]['default'] ?? null; }

	/** @return array<int,array<string,mixed>> */
	public function describe(string $domain): array {
		$definition=$this->domains[$this->domainName($domain)] ?? null;
		if($definition===null) return [];
		return array_values(array_map(static fn(SimulationScenario $scenario): array => $scenario->jsonSerialize(), $definition['scenarios']));
	}

	private function domainName(string $domain): string {
		$domain=strtolower(trim($domain));
		$domain=preg_replace('/[^a-z0-9_.-]+/', '_', $domain) ?? '';
		return substr(trim($domain, '_.-'), 0, 128);
	}
}
