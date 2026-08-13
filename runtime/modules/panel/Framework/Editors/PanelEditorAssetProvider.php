<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Complete storage-neutral asset library consumed by editor integrations. */
interface PanelEditorAssetProvider extends PanelEditorUploadAdapter, PanelEditorMediaAdapter, \JsonSerializable {
	public function browse(array $query, PanelEditorContext $context): PanelEditorAssetPage;
	public function findAsset(string $id, PanelEditorContext $context): ?PanelEditorAsset;
	public function storeAsset(array $upload, PanelEditorContext $context): PanelEditorAssetResult;
	public function deleteAsset(string $id, PanelEditorContext $context): PanelEditorAssetResult;
	public function delivery(string $id, PanelEditorContext $context): PanelEditorAssetResult;
}
