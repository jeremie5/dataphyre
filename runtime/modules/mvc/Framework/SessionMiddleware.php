<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;

/**
 * Starts MVC session state and ages flash data after the response.
 *
 * The middleware exposes the session facade class as a request attribute so handlers can access
 * session services through request context while lifecycle cleanup remains centralized.
 */
final class SessionMiddleware {

	/**
	 * Starts the session and attaches the session facade to the request.
	 *
	 * @param Request $request Current HTTP request.
	 * @param callable $next Next middleware/handler.
	 * @return mixed response-like value returned by the downstream middleware or handler.
	 */
	public function handle(Request $request, callable $next): mixed {
		Session::start();
		$request->setAttribute('session', Session::class);
		return $next($request);
	}

	/**
	 * Ages flash session data after a response has been produced.
	 *
	 * @param Request $request Completed request.
	 * @param Response $response Completed response.
	 * @return void
	 */
	public function terminate(Request $request, Response $response): void {
		Session::ageFlash();
	}
}
