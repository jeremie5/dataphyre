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
use Dataphyre\Http\ResponseEmitter;

/**
 * Small runtime facade for dispatching an MVC application through MvcManager.
 *
 * The host captures the optional application id once and supplies it to every
 * dispatch call, giving web front controllers and embedded runtimes a stable
 * object that can either return or emit the resolved HTTP response.
 */
final class MvcHost {

	/**
	 * Stores the manager and optional application scope for later dispatches.
	 *
	 * @param MvcManager $manager Dispatcher responsible for resolving routes and controllers.
	 * @param ?string $app Optional application id used when multiple apps share the manager.
	 */
	public function __construct(
		private MvcManager $manager,
		private ?string $app=null
	){}

	/**
	 * Dispatches a request through the configured MVC application.
	 *
	 * @param ?Request $request Request to dispatch; null lets MvcManager create or infer the current request.
	 * @return Response Response produced by the matching MVC route and controller.
	 */
	public function dispatch(?Request $request=null): Response {
		return $this->manager->dispatch($request, $this->app);
	}

	/**
	 * Dispatches and immediately emits the response to the active PHP output.
	 *
	 * @param ?Request $request Request to dispatch; null lets MvcManager infer the current request.
	 * @return Response Emitted response, returned for callers that need status or headers after output.
	 */
	public function emit(?Request $request=null): Response {
		$response=$this->dispatch($request);
		ResponseEmitter::emit($response);
		return $response;
	}
}
