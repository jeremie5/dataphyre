<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Allows applications or modules to configure a panel instance.
 *
 * Providers receive the panel under construction and return the configured
 * instance after registering resources, pages, commands, widgets, navigation,
 * theme settings, or other panel integrations.
 */
interface PanelProvider {

	/**
	 * Configures and returns the supplied panel instance.
	 *
	 * @param PanelInstance $panel Panel instance being registered.
	 * @return PanelInstance Configured panel instance.
	 */
	public function panel(PanelInstance $panel): PanelInstance;
}
