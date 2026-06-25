<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Response;
use Dataphyre\Templating\Templating;

/**
 * Represents a templated MVC response.
 *
 * ViewResult stores the template name or path, render data, HTTP status, headers,
 * and Set-Cookie headers until the MVC layer converts it into a Response. Fluent
 * modifiers clone the result so intermediate response values remain immutable.
 */
final class ViewResult implements ResponseResult {

	/**
	 * Creates a view result.
	 *
	 * @param string $template Template path or logical view name.
	 * @param array<string, mixed> $data Data passed to the templating renderer.
	 * @param int $status HTTP status code.
	 * @param array<string, mixed> $headers Response headers.
	 */
	public function __construct(
		private string $template,
		private array $data=[],
		private int $status=200,
		private array $headers=[]
	){}

	/**
	 * Creates a view result with default status and headers.
	 *
	 *
	 * @param array<string, mixed> $data Data passed to the templating renderer.
	 * @return self New view result.
	 */
	public static function make(string $template, array $data=[]): self {
		return new self($template, $data);
	}

	/**
	 * Returns a clone with additional render data.
	 *
	 * New keys replace existing keys with the same name.
	 *
	 * @param array<string, mixed> $data Data to merge into the view payload.
	 * @return self Cloned view result.
	 */
	public function with(array $data): self {
		$clone=clone $this;
		$clone->data=array_replace($clone->data, $data);
		return $clone;
	}

	/**
	 * Returns a clone with a different HTTP status.
	 *
	 *
	 * @param int $status HTTP status code.
	 * @return self Cloned view result.
	 */
	public function status(int $status): self {
		$clone=clone $this;
		$clone->status=$status;
		return $clone;
	}

	/**
	 * Returns a clone with one response header set.
	 *
	 *
	 * @param string $name Header name.
	 * @param string $value Header value.
	 * @return self Cloned view result.
	 */
	public function header(string $name, string $value): self {
		$clone=clone $this;
		$clone->headers[$name]=$value;
		return $clone;
	}

	/**
	 * Returns a clone with a Set-Cookie header.
	 *
	 * Cookie attributes are delegated to Response::cookieHeader() so view results
	 * share the same cookie formatting as direct HTTP responses.
	 *
	 * @param string $name Cookie name.
	 * @param string $value Cookie value.
	 * @param int $minutes Lifetime in minutes, or zero for session cookie.
	 * @param string $path Cookie path.
	 * @param string $domain Cookie domain.
	 * @param bool $secure Whether to require HTTPS.
	 * @param bool $httpOnly Whether to hide the cookie from JavaScript.
	 * @param string $sameSite SameSite attribute.
	 * @return self Cloned view result.
	 */
	public function withCookie(
		string $name,
		string $value,
		int $minutes=0,
		string $path='/',
		string $domain='',
		bool $secure=false,
		bool $httpOnly=true,
		string $sameSite='Lax'
	): self {
		$clone=clone $this;
		return $clone->withCookieHeader(Response::cookieHeader($name, $value, $minutes>0 ? time()+($minutes*60) : 0, $path, $domain, $secure, $httpOnly, $sameSite));
	}

	/**
	 * Returns a clone with an expired Set-Cookie header.
	 *
	 * This is used to remove a cookie from the browser using the same path and
	 * domain that originally scoped it.
	 *
	 * @param string $name Cookie name.
	 * @param string $path Cookie path.
	 * @param string $domain Cookie domain.
	 * @return self Cloned view result.
	 */
	public function withoutCookie(string $name, string $path='/', string $domain=''): self {
		$clone=clone $this;
		return $clone->withCookieHeader(Response::cookieHeader($name, '', time()-31536000, $path, $domain, false, true, 'Lax'));
	}

	/**
	 * Renders the template and returns an HTTP response.
	 *
	 * Template resolution prefers an explicit file path, then the application view
	 * path with dot and slash normalization, and finally the original template
	 * value for the templating module to resolve.
	 *
	 * @param ?MvcApplication $app Optional application used to resolve logical view names.
	 * @return Response Rendered HTML response.
	 */
	public function toResponse(?MvcApplication $app=null): Response {
		$template=$this->resolveTemplate($app);
		$html=Templating::render($template, $this->data)->content();
		return Response::html($html, $this->status, $this->headers);
	}

	/**
	 * Resolves the template path used for rendering.
	 *
	 * @param ?MvcApplication $app Optional application with a configured view path.
	 * @return string Template path or logical template name.
	 */
	private function resolveTemplate(?MvcApplication $app): string {
		if(is_file($this->template)){
			return $this->template;
		}
		$viewPath=$app?->viewPath();
		if($viewPath!==null){
			$candidate=$viewPath.'/'.str_replace(['\\', '.'], '/', $this->template);
			if(pathinfo($candidate, PATHINFO_EXTENSION)===''){
				$candidate.='.php';
			}
			if(is_file($candidate)){
				return $candidate;
			}
		}
		return $this->template;
	}

	/**
	 * Adds a Set-Cookie header value to this result.
	 *
	 * @param string $cookie Formatted Set-Cookie header value.
	 * @return self Current cloned instance.
	 */
	private function withCookieHeader(string $cookie): self {
		$current=$this->headers['Set-Cookie'] ?? [];
		$current=is_array($current) ? $current : [$current];
		$cookies=[];
		foreach($current as $value){
			if(is_string($value) && $value!==''){
				$cookies[]=$value;
			}
		}
		$cookies[]=$cookie;
		$this->headers['Set-Cookie']=$cookies;
		return $this;
	}
}
