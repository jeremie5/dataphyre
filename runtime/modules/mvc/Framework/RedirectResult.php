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
 * Represents an MVC redirect response with flash data and cookie mutations.
 *
 * RedirectResult lets controllers compose redirect location, status, headers,
 * flashed input/errors, and Set-Cookie headers before the dispatcher normalizes
 * it into an HTTP Response.
 */
final class RedirectResult implements ResponseResult {

	/**
	 * Stores the redirect target and initial response metadata.
	 *
	 * @param string $location Location header value.
	 * @param int $status Redirect status code.
	 * @param array<string, mixed> $headers Additional response headers.
	 */
	public function __construct(
		private string $location,
		private int $status=302,
		private array $headers=[]
	){}

	/**
	 * Flashes a value to the session for the next request.
	 *
	 * @param string $key Flash key.
	 * @param mixed $value Flash value.
	 * @return self Same redirect result for fluent composition.
	 */
	public function with(string $key, mixed $value): self {
		Session::flash($key, $value);
		return $this;
	}

	/**
	 * Flashes submitted input for validation redirects.
	 *
	 * @param array<string, mixed> $input Request input to expose as old input.
	 * @return self Same redirect result for fluent composition.
	 */
	public function withInput(array $input): self {
		Session::flashInput($input);
		return $this;
	}

	/**
	 * Flashes validation errors to a named error bag.
	 *
	 * ValidationException instances contribute their own bag name when the caller
	 * keeps the default bag.
	 *
	 * @param ValidationException|Validator|array<string, mixed> $errors Validation source.
	 * @param string $bag Error bag name.
	 * @return self Same redirect result for fluent composition.
	 */
	public function withErrors(ValidationException|Validator|array $errors, string $bag='default'): self {
		if($errors instanceof ValidationException){
			if($bag==='default'){
				$bag=$errors->errorBag();
			}
			$errors=$errors->errors();
		} elseif($errors instanceof Validator){
			$errors=$errors->errors();
		}
		Session::flashErrors($errors, $bag);
		return $this;
	}

	/**
	 * Adds a Set-Cookie header to the redirect response.
	 *
	 * @param string $name Cookie name.
	 * @param string $value Cookie value.
	 * @param int $minutes Lifetime in minutes; zero creates a session cookie.
	 * @param string $path Cookie path.
	 * @param string $domain Cookie domain.
	 * @param bool $secure Whether the cookie requires HTTPS.
	 * @param bool $httpOnly Whether JavaScript should be denied cookie access.
	 * @param string $sameSite SameSite policy.
	 * @return self Same redirect result for fluent composition.
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
		return $this->withCookieHeader(Response::cookieHeader($name, $value, $minutes>0 ? time()+($minutes*60) : 0, $path, $domain, $secure, $httpOnly, $sameSite));
	}

	/**
	 * Adds an expired Set-Cookie header that removes a cookie.
	 *
	 * @param string $name Cookie name.
	 * @param string $path Cookie path.
	 * @param string $domain Cookie domain.
	 * @return self Same redirect result for fluent composition.
	 */
	public function withoutCookie(string $name, string $path='/', string $domain=''): self {
		return $this->withCookieHeader(Response::cookieHeader($name, '', time()-31536000, $path, $domain, false, true, 'Lax'));
	}

	/**
	 * Converts the redirect result to an HTTP response.
	 *
	 * @param ?MvcApplication $app Current MVC application; accepted for ResponseResult compatibility.
	 * @return Response Empty response with Location and accumulated headers.
	 */
	public function toResponse(?MvcApplication $app=null): Response {
		return Response::make('', $this->status, array_replace(['Location'=>$this->location], $this->headers));
	}

	/**
	 * Appends a Set-Cookie header while preserving existing cookie headers.
	 *
	 * @param string $cookie Complete Set-Cookie header value.
	 * @return self Same redirect result for fluent composition.
	 */
	private function withCookieHeader(string $cookie): self {
		$current=$this->headers['Set-Cookie'] ?? [];
		if($current===[]){
			$this->headers['Set-Cookie']=[$cookie];
			return $this;
		}
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
