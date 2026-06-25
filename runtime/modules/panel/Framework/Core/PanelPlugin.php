<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Contract for Panel extensions that register resources, pages, assets, or runtime hooks.
 *
 * Plugins participate in two phases: `register()` declares capabilities on a `PanelInstance`,
 * and `boot()` performs work that depends on all plugins/resources already being registered.
 * Implementations should keep `id()` stable because diagnostics and package tooling use it as
 * the plugin identity.
 */
interface PanelPlugin {

	/**
	 * Returns the stable plugin identifier.
	 *
	 * @return string Unique plugin id used for diagnostics and package metadata.
	 */
	public function id(): string;

	/**
	 * Registers plugin-provided Panel capabilities.
	 *
	 * @param PanelInstance $panel Panel instance receiving resources, pages, navigation, assets, or hooks.
	 * @return void
	 */
	public function register(PanelInstance $panel): void;

	/**
	 * Boots the plugin after registration has completed.
	 *
	 * @param PanelInstance $panel Fully registered Panel instance.
	 * @return void
	 */
	public function boot(PanelInstance $panel): void;
}
