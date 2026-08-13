<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Mcp\Panel\PanelCapabilityCatalog;
use Dataphyre\Mcp\Panel\SourcePanelCapabilityIndex;

/** Public MCP surfaces for source-derived Panel capability intelligence. */
trait dataphyre_mcp_inspection_panel_surfaces {
	private ?PanelCapabilityCatalog $panel_capability_catalog_cache=null;
	private string $panel_capability_catalog_root='';

	/** @return array<string,mixed> */
	private function panel_capability_catalog(array $args): array {
		$catalog=$this->panel_capability_engine()->catalog($args);
		$catalog['next_actions']=[
			'Describe only the affected platform domains or framework areas before choosing implementation classes.',
			'Use the surface graph before composing domains, then use a recipe or integration plan for the concrete task.',
			'Use the verification plan after changed paths and the intended proof claim are known.',
		];
		return $catalog;
	}

	/** @return array<string,mixed> */
	private function panel_capability_describe(array $args): array {
		return $this->panel_capability_engine()->describe(
			(string)($args['id']??''),(string)($args['view']??'overview'),max(1,min((int)($args['max_items']??40)?:40,200))
		);
	}

	/** @return array<string,mixed> */
	private function panel_surface_graph(array $args): array {return $this->panel_capability_engine()->graph($args);}
	/** @return array<string,mixed> */
	private function panel_recipe_plan(array $args): array {return $this->panel_capability_engine()->recipe($args);}
	/** @return array<string,mixed> */
	private function panel_integration_plan(array $args): array {return $this->panel_capability_engine()->integration($args);}
	/** @return array<string,mixed> */
	private function panel_verification_plan(array $args): array {return $this->panel_capability_engine()->verification($args);}
	/** @return array<string,mixed> */
	private function panel_resource_snapshot(): array {return $this->panel_capability_engine()->resourceSnapshot();}

	private function panel_capability_engine(): PanelCapabilityCatalog {
		$root=$this->normalize_path($this->common_root.'/dataphyre');
		if($this->panel_capability_catalog_cache===null||$this->panel_capability_catalog_root!==$root){
			$this->panel_capability_catalog_cache=new PanelCapabilityCatalog(new SourcePanelCapabilityIndex($root));
			$this->panel_capability_catalog_root=$root;
		}
		return $this->panel_capability_catalog_cache;
	}
}
