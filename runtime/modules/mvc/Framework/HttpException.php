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
 * Represents an MVC exception that can be converted into an HTTP response.
 *
 * Controllers and middleware can throw HttpException to stop dispatch with a
 * specific status code and response headers. The exception preserves normal
 * Throwable chaining while exposing both HTML and JSON response conversions for
 * route handlers that negotiate output format.
 */
final class HttpException extends \RuntimeException {

	/**
	 * Creates an HTTP exception.
	 *
	 * Empty messages are replaced with a conventional status message so generated
	 * responses never expose a blank body.
	 *
	 * @param int $status HTTP status code to return.
	 * @param string $message Optional response message.
	 * @param array<string, string|string[]> $headers Headers to include in converted responses.
	 * @param ?\Throwable $previous Previous exception for normal exception chaining.
	 */
	public function __construct(
		private int $status,
		string $message='',
		private array $headers=[],
		?\Throwable $previous=null
	){
		parent::__construct($message!=='' ? $message : self::defaultMessage($status), $status, $previous);
	}

	/**
	 * Returns the HTTP status code carried by the exception.
	 *
	 * @return int HTTP status code.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Returns response headers carried by the exception.
	 *
	 * @return array<string, string|string[]> Header map.
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * Converts the exception into an HTML response.
	 *
	 * The message becomes the response body exactly as stored on the exception;
	 * callers should throw only safe public messages for user-facing routes.
	 *
	 * @return Response HTML response with the exception status and headers.
	 */
	public function toResponse(): Response {
		return Response::html($this->getMessage(), $this->status, $this->headers);
	}

	/**
	 * Converts the exception into a JSON response.
	 *
	 * The payload contains `message` and `status`, matching the response status
	 * and preserving headers for API callers.
	 *
	 * @return Response JSON response with the exception status and headers.
	 */
	public function toJsonResponse(): Response {
		return Response::json([
			'message'=>$this->getMessage(),
			'status'=>$this->status,
		], $this->status, $this->headers);
	}

	/**
	 * Returns the conventional fallback message for a status code.
	 *
	 * @param int $status HTTP status code.
	 * @return string Public fallback message.
	 */
	private static function defaultMessage(int $status): string {
		return match($status){
			400=>'Bad Request',
			401=>'Unauthorized',
			403=>'Forbidden',
			404=>'Not Found',
			419=>'Page Expired',
			422=>'Unprocessable Entity',
			429=>'Too Many Requests',
			500=>'Server Error',
			503=>'Service Unavailable',
			default=>'HTTP Error',
		};
	}
}
