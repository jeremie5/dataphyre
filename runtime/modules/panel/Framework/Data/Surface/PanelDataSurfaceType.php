<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Closed set of first-party collection surfaces understood by Panel. */
enum PanelDataSurfaceType:string {
	case TABLE='table';
	case LIST='list';
	case CARDS='cards';
	case TIMELINE='timeline';
	case CALENDAR='calendar';
	case GALLERY='gallery';
	case SPREADSHEET='spreadsheet';
	case PIVOT='pivot';
	case TREE='tree';
	case GRAPH='graph';
	case GANTT='gantt';
	case HEATMAP='heatmap';
	case MAP='map';
	case CANVAS='canvas';

	public static function normalize(self|string $type): self {
		if($type instanceof self){ return $type; }
		$type=strtolower(trim($type));
		return self::tryFrom($type) ?? throw new \InvalidArgumentException("Unsupported Panel data surface type '{$type}'.");
	}

	/** @return list<string> */
	public static function values(): array {
		return array_column(self::cases(), 'value');
	}

	public function advanced():bool{return PanelDataCanvasSpec::advanced($this);}
}
