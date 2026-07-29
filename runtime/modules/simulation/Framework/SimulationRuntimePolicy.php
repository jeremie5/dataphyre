<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use JsonSerializable;

/** Fail-closed environment policy that makes the module incapable of production mutation. */
final class SimulationRuntimePolicy implements JsonSerializable {
	/** @param array<int,string> $allowedEnvironments */
	public function __construct(private bool $enabled, private string $environment, private array $allowedEnvironments) {
		$this->environment=strtolower(trim($this->environment));
		$this->allowedEnvironments=array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => strtolower(trim((string)$value)), $this->allowedEnvironments))));
	}

	/** @param array<string,mixed> $config */
	public static function fromConfig(array $config=[], ?string $environment=null): self {
		if($config===[] && class_exists('dataphyre\\simulation', false)){
			$config=[
				'enabled'=>\dataphyre\simulation::config('enabled', false),
				'allowed_environments'=>\dataphyre\simulation::config('allowed_environments', ['local', 'development', 'dev', 'test', 'testing']),
			];
		}
		$environment=$environment ?? (string)(getenv('APP_ENV') ?: getenv('DATAPHYRE_ENV') ?: getenv('ENVIRONMENT') ?: 'production');
		$enabled=$config['enabled'] ?? false;
		return new self(
			$enabled===true || $enabled===1 || $enabled==='1',
			$environment,
			is_array($config['allowed_environments'] ?? null) ? $config['allowed_environments'] : ['local', 'development', 'dev', 'test', 'testing'],
		);
	}

	public function allows(bool $sessionEnabled=true): bool {
		return $sessionEnabled && $this->enabled && in_array($this->environment, $this->allowedEnvironments, true) && !in_array($this->environment, ['production', 'prod', 'live'], true);
	}

	public function status(bool $sessionEnabled=true): string {
		if(!$sessionEnabled) return 'simulation_session_disabled';
		if(!$this->enabled) return 'simulation_module_disabled';
		if(in_array($this->environment, ['production', 'prod', 'live'], true)) return 'simulation_forbidden_in_production';
		if(!in_array($this->environment, $this->allowedEnvironments, true)) return 'simulation_environment_not_allowed';
		return 'simulation_allowed';
	}

	public function jsonSerialize(): array {
		return ['enabled'=>$this->enabled, 'environment'=>$this->environment, 'allowed_environments'=>$this->allowedEnvironments, 'allowed'=>$this->allows()];
	}
}
