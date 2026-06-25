<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Response;

/**
 * Represents a controller result that can become an HTTP response.
 *
 * MVC actions can return objects implementing this interface when they need to
 * defer response construction until the application context is available. The
 * conversion must produce a concrete immutable Response for emission.
 */
interface ResponseResult {

	/**
	 * Converts the result into an HTTP response.
	 *
	 * @param ?MvcApplication $app Current MVC application context, when available.
	 * @return Response Response ready for middleware or emitter handling.
	 */
	public function toResponse(?MvcApplication $app=null): Response;
}
