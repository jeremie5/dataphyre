<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Nested plugin that installs Dataphyre Access authentication on one Panel. */
final class PanelDataphyreAccessPlugin implements PanelPlugin, \JsonSerializable {
	/** @param array<string,mixed> $options */
	public function __construct(private readonly array $options=[]) {}

	public function id(): string {return 'dataphyre_access';}
	public function version(): string {return '1.0.0';}
	public function label(): string {return 'Dataphyre Access';}
	public function description(): string {return 'Panel authentication pages and authorization backed by Dataphyre Access.';}

	public function register(PanelInstance $panel): void {
		if(!class_exists(\Dataphyre\Access\PanelAuth::class)){
			throw new \RuntimeException('The Dataphyre Access PanelAuth bridge is unavailable.');
		}
		\Dataphyre\Access\PanelAuth::register($panel,$this->options);
	}

	public function boot(PanelInstance $panel): void {}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		$keys=array_values(array_map('strval',array_keys($this->options)));sort($keys,SORT_STRING);
		return [
			'type'=>'panel_dataphyre_access_plugin',
			'schema_version'=>1,
			'id'=>$this->id(),
			'version'=>$this->version(),
			'option_keys'=>$keys,
			'option_count'=>count($keys),
			'configuration_serialized'=>false,
		];
	}
}
