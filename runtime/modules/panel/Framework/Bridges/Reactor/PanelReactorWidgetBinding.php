<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel\Bridges\Reactor;

use Dataphyre\Panel\PanelWidgetInteractionDefinition;
use Dataphyre\Panel\PanelWidgetInteractionValue;
use Dataphyre\Reactor\ReactorName;

/** Immutable host-owned route from a public Panel widget to one Reactor component. */
final class PanelReactorWidgetBinding implements \JsonSerializable {
	/** @var array<string,string> */
	private readonly array $actionMap;
	/** @var list<string> */
	private readonly array $surfaces;

	/** @param array<string,string> $actionMap @param list<string> $surfaces */
	private function __construct(
		private readonly string $routeKey,
		private readonly PanelWidgetInteractionDefinition $definition,
		private readonly string $reactorComponent,
		array $actionMap=[],
		array $surfaces=['*']
	){ $this->actionMap=$actionMap; $this->surfaces=$surfaces; }

	public static function make(string $routeKey, PanelWidgetInteractionDefinition $definition, string $reactorComponent): self {
		$routeKey=PanelWidgetInteractionValue::safeIdentifier($routeKey, 'Reactor widget route binding', 96);
		$reactorComponent=ReactorName::normalize($reactorComponent);
		if($reactorComponent===''){ throw new \InvalidArgumentException('Reactor widget bindings require a trusted component name.'); }
		return new self($routeKey, $definition, $reactorComponent);
	}

	/** @param array<string,string> $map */
	public function actions(array $map): self {
		if(array_is_list($map) && $map!==[]){ throw new \InvalidArgumentException('Reactor widget action mappings must be object-like maps.'); }
		$normalized=[];
		foreach($map as $panelAction=>$reactorAction){
			if(!is_string($panelAction) || !is_string($reactorAction)){ throw new \InvalidArgumentException('Reactor widget action mappings require string names.'); }
			$panelAction=PanelWidgetInteractionValue::safeIdentifier($panelAction, 'Panel widget action mapping', 64);
			$reactorAction=ReactorName::normalize($reactorAction);
			if($reactorAction===''){ throw new \InvalidArgumentException('Reactor widget action mappings require valid Reactor action names.'); }
			$normalized[$panelAction]=$reactorAction;
		}
		ksort($normalized, SORT_STRING);
		return new self($this->routeKey, $this->definition, $this->reactorComponent, $normalized, $this->surfaces);
	}

	/** @param list<string>|string $surfaces */
	public function surfaces(array|string $surfaces): self {
		$surfaces=is_string($surfaces) ? [$surfaces] : $surfaces;
		if($surfaces===[] || count($surfaces)>64 || !array_is_list($surfaces)){ throw new \InvalidArgumentException('Reactor widget surface policies require a non-empty list of at most 64 entries.'); }
		$normalized=[];
		foreach($surfaces as $surface){
			if(!is_string($surface)){ throw new \InvalidArgumentException('Reactor widget surface policy entries must be strings.'); }
			$surface=trim($surface);
			if($surface==='*'){ $normalized=['*']; break; }
			$normalized[]=PanelWidgetInteractionValue::safeIdentifier($surface, 'Reactor widget surface', 128);
		}
		$normalized=array_values(array_unique($normalized));
		sort($normalized, SORT_STRING);
		return new self($this->routeKey, $this->definition, $this->reactorComponent, $this->actionMap, $normalized);
	}

	public function routeKey(): string { return $this->routeKey; }
	public function definition(): PanelWidgetInteractionDefinition { return $this->definition; }
	public function reactorComponent(): string { return $this->reactorComponent; }
	/** @return array<string,string> */ public function actionMap(): array { return $this->actionMap; }
	public function reactorAction(string $panelAction): ?string { return $this->actionMap[strtolower(trim($panelAction))] ?? null; }
	/** @return list<string> */ public function allowedSurfaces(): array { return $this->surfaces; }
	public function allowsSurface(string $surface): bool { return in_array('*', $this->surfaces, true) || in_array($surface, $this->surfaces, true); }

	/** Verifies that every public action has one explicit trusted Reactor mapping. */
	public function assertComplete(): void {
		$public=array_keys($this->definition->namedActions());
		$mapped=array_keys($this->actionMap);
		sort($public, SORT_STRING); sort($mapped, SORT_STRING);
		if($public!==$mapped){ throw new \LogicException('Reactor widget bindings require an exact action mapping for every public widget action.'); }
	}

	public function manifest(): array {
		return [
			'type'=>'panel_reactor_widget_binding',
			'contract_version'=>1,
			'route_key'=>$this->routeKey,
			'definition_fingerprint'=>$this->definition->fingerprint(),
			'public_component'=>$this->definition->component(),
			'public_actions'=>array_keys($this->actionMap),
			'surfaces'=>$this->surfaces,
			'reactor_component_exposed'=>false,
			'body_selects_component_or_action_mapping'=>false,
		];
	}

	public function jsonSerialize(): array { return $this->manifest(); }
}
