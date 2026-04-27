<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre;

// Flightdeck is a cold control-plane module. Route handlers load the concrete
// auth, toolbar, and renderer classes only when a Flightdeck surface is hit.
\dp_module_required('flightdeck', 'templating');
