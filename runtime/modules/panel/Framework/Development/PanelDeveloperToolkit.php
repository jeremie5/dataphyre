<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cohesive developer entrypoint for inspection, diffs, schema generation, and QA plans. */
final class PanelDeveloperToolkit {
	public static function inspect(mixed $manifest): PanelManifestInspector { return PanelManifestInspector::inspect($manifest); }
	public static function diff(array|\JsonSerializable $before, array|\JsonSerializable $after): PanelManifestDiff { return PanelManifestDiff::between($before, $after); }
	public static function blueprint(string $table, array $columns, array $options=[]): PanelSchemaBlueprint { return PanelSchemaBlueprint::make($table, $columns, $options); }
	public static function studioCompiler(): PanelStudioCompiler { return new PanelStudioCompiler(); }
	public static function studioRegistry(string $version='1.0.0'): PanelStudioSchemaRegistry { return PanelStudioSchemaRegistry::defaults($version); }
	public static function studioMaterializer(): PanelStudioMaterializer { return new PanelStudioMaterializer(); }
	public static function studioMaterialize(PanelStudioDefinition|array $definition,?PanelStudioSchemaRegistry $registry=null): PanelStudioMaterialization { return (new PanelStudioMaterializer())->materialize($definition,$registry??PanelStudioSchemaRegistry::defaults()); }
	public static function studioImpact(array|\JsonSerializable $before, array|\JsonSerializable $after): PanelStudioImpactPlan { return new PanelStudioImpactPlan($before, $after); }
	/** @param array<string,string> $routes @param array<string,mixed> $options */
	public static function sdkContract(string $id,string $version,array $routes=[],array $options=[]): PanelSdkContract { return PanelSdkProtocolCatalog::firstParty($id,$version,$routes,$options); }
	public static function sdkGenerator(): PanelSdkGenerator { return new PanelSdkGenerator(); }
	public static function sdkCompatibility(PanelSdkContract $before,PanelSdkContract $after): PanelSdkCompatibilityReport { return PanelSdkCompatibilityReport::between($before,$after); }
	public static function qualityMatrix(string $name, string $url, array $axes=[]): PanelQualityMatrix { return PanelQualityMatrix::make($name, $url, $axes); }
	public static function inclusiveQualityMatrix(string $name,string $url,array $options=[]): PanelInclusiveQualityMatrix { return PanelQualityMatrix::inclusive($name,$url,$options); }
	public static function accessibility(PanelPageResult|string $result, array $meta=[]): PanelAccessibilityAudit { return PanelAccessibilityAudit::from($result, $meta); }
}
