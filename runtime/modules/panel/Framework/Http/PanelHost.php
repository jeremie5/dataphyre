<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * HTTP-facing host for dispatching and rendering a Panel surface.
 *
 * The host binds a `PanelInstance` to an optional user context and translates
 * arrays, captured panel requests, and framework HTTP requests into
 * `PanelRequest` objects. It also adapts fragment requests into JSON payloads
 * expected by Panel's partial-refresh clients.
 */
final class PanelHost {

	/**
	 * Stores the panel surface and optional user context.
	 *
	 * @param PanelInstance $surface Panel surface that will handle dispatch and render calls.
	 * @param mixed $user Optional user value injected into generated requests and render contexts.
	 */
	public function __construct(
		private readonly PanelInstance $surface,
		private readonly mixed $user=null
	){}

	/**
	 * Creates a host for a panel instance or named surface.
	 *
	 * @param PanelInstance|string|null $surface Existing panel, surface name, or `null` for the default surface.
	 * @param mixed $user Optional user value to bind to requests.
	 * @return self Host bound to the resolved panel surface.
	 */
	public static function surface(PanelInstance|string|null $surface=null, mixed $user=null): self {
		if($surface instanceof PanelInstance){
			return new self($surface, $user);
		}
		$name=is_string($surface) && trim($surface)!=='' ? $surface : 'default';
		return new self(Panel::surface($name), $user);
	}

	/**
	 * Returns a host for the same panel surface with a different user context.
	 *
	 * @param mixed $user User value to inject into future requests.
	 * @return self New host preserving the panel surface.
	 */
	public function withUser(mixed $user): self {
		return new self($this->surface, $user);
	}

	/**
	 * Returns the hosted panel instance.
	 *
	 * @return PanelInstance Panel surface used by this host.
	 */
	public function panel(): PanelInstance {
		return $this->surface;
	}

	/**
	 * Dispatches a panel request through the hosted surface.
	 *
	 * Array and null inputs are normalized through `request()`, including user
	 * injection when a host user is present.
	 *
	 * @param PanelRequest|array<string, mixed>|null $request Panel request, request payload, or `null` to capture current input.
	 * @return PanelPageResult Result returned by the panel dispatcher.
	 */
	public function dispatch(PanelRequest|array|null $request=null): PanelPageResult {
		return $this->surface->dispatch($this->request($request));
	}

	/**
	 * Dispatches a framework HTTP request through the panel bridge.
	 *
	 * Fragment requests are converted to JSON `PanelPageResult` payloads so
	 * frontend refresh clients receive HTML, effects, notifications, and
	 * redirect metadata instead of a full page response.
	 *
	 * @param \Dataphyre\Http\Request $request Framework HTTP request.
	 * @param array<string, mixed> $options Additional panel request options.
	 * @return PanelPageResult Full or fragment-adapted panel result.
	 */
	public function dispatchHttp(\Dataphyre\Http\Request $request, array $options=[]): PanelPageResult {
		$panelRequest=PanelRequest::fromHttpRequest($request, ['user'=>$this->user]+$options);
		$result=$this->dispatch($panelRequest);
		return $panelRequest->isPanelFragmentRequest() ? $this->fragmentResult($result) : $result;
	}

	/**
	 * Dispatches and converts the result to the framework response shape.
	 *
	 * @param PanelRequest|array<string, mixed>|\Dataphyre\Http\Request|null $request Request source.
	 * @param array<string, mixed> $options Additional HTTP bridge options.
	 * @return mixed Response value emitted by `PanelPageResult::toResponse()`.
	 */
	public function response(PanelRequest|array|\Dataphyre\Http\Request|null $request=null, array $options=[]): mixed {
		if($request instanceof \Dataphyre\Http\Request){
			return $this->dispatchHttp($request, $options)->toResponse();
		}
		return $this->dispatch($request)->toResponse();
	}

	/**
	 * Renders a panel resource operation directly.
	 *
	 * Host user context is injected into the render context when the caller did
	 * not already provide a `user` entry.
	 *
	 * @param Resource|string|null $resource Resource object, resource name, or `null` for the panel default.
	 * @param string $operation Operation name to render.
	 * @param array<string, mixed> $context Render context.
	 * @return PanelPageResult Rendered panel result.
	 */
	public function render(Resource|string|null $resource=null, string $operation='index', array $context=[]): PanelPageResult {
		if($this->user!==null && !array_key_exists('user', $context)){
			$context['user']=$this->user;
		}
		return $this->surface->render($resource, $operation, $context);
	}

	/**
	 * Dispatches a request and emits it through the Panel response emitter.
	 *
	 * Fragment requests are adapted before emission. `$sendBody=false` lets
	 * callers prepare headers/status while still receiving the body string.
	 *
	 * @param PanelRequest|array<string, mixed>|null $request Request source.
	 * @param bool $sendBody Whether the emitter should send the response body.
	 * @return string Emitted response body.
	 */
	public function emit(PanelRequest|array|null $request=null, bool $sendBody=true): string {
		$request=$this->request($request);
		$result=$this->surface->dispatch($request);
		if($request->isPanelFragmentRequest()){
			$result=$this->fragmentResult($result);
		}
		return PanelResponseEmitter::emit($result, $sendBody);
	}

	/**
	 * Converts full panel results into fragment-friendly JSON results.
	 *
	 * @param PanelPageResult $result Result returned by the panel dispatcher.
	 * @return PanelPageResult JSON fragment payload preserving redirects, effects, notifications, status, and refresh timestamp.
	 */
	private function fragmentResult(PanelPageResult $result): PanelPageResult {
		if($result->isRedirect()){
			$data=$result->data();
			return PanelPageResult::json([
				'redirect_to'=>$result->redirectTo(),
				'status'=>$result->status(),
				'data'=>$data,
				'effects'=>is_array($data['effects'] ?? null) ? $data['effects'] : [],
				'notifications'=>$result->notifications(),
				'refreshed_at'=>date('c'),
			], 200);
		}
		$content=$result->content();
		$data=$result->data();
		return PanelPageResult::json([
			'html'=>$content,
			'title'=>$this->htmlTitle($content),
			'signature'=>hash('sha256', $content),
			'refreshed_at'=>date('c'),
			'status'=>$result->status(),
			'data'=>$data,
			'effects'=>is_array($data['effects'] ?? null) ? $data['effects'] : [],
			'notifications'=>$result->notifications(),
		], $result->status());
	}

	/**
	 * Extracts the document title from rendered HTML.
	 *
	 * @param string $html Rendered HTML.
	 * @return string Decoded title text, or an empty string when no title exists.
	 */
	private function htmlTitle(string $html): string {
		if(preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)===1){
			return html_entity_decode(trim(strip_tags($match[1])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}
		return '';
	}

	/**
	 * Normalizes request sources into a `PanelRequest`.
	 *
	 * @param PanelRequest|array<string, mixed>|null $request Request source.
	 * @return PanelRequest Request carrying the host user when one is bound.
	 */
	private function request(PanelRequest|array|null $request): PanelRequest {
		if($request instanceof PanelRequest){
			return $this->user!==null ? $request->withUser($this->user) : $request;
		}
		if(is_array($request)){
			if($this->user!==null && !array_key_exists('user', $request)){
				$request['user']=$this->user;
			}
			return PanelRequest::fromArray($request);
		}
		$request=PanelRequest::capture();
		return $this->user!==null ? $request->withUser($this->user) : $request;
	}
}
