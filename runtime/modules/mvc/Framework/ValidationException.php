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
 * Carries validation failures through MVC dispatch and response rendering.
 *
 * ValidationException preserves the field error map, successfully validated
 * data, HTTP status, and error bag name in one throwable. Controllers and
 * middleware can throw it to interrupt normal execution while still giving the
 * response layer a deterministic JSON payload for clients.
 */
final class ValidationException extends \RuntimeException {

	/** @var array<string, mixed> Field errors grouped by input name or validation context. */
	private array $errors;

	/** @var array<string, mixed> Data accepted by the validator before failure reporting. */
	private array $validated;

	/** HTTP status used when converting the exception into a response. */
	private int $status;

	/** Named error bag used by forms with multiple validation contexts. */
	private string $errorBag;

	/**
	 * Builds an exception from a Validator instance or an error array.
	 *
	 * Passing a Validator preserves both errors() and validated() output. Passing
	 * an array is a shortcut for manually assembled failures and leaves the
	 * validated payload empty. Blank error bag names normalize to "default" so
	 * client payloads always have a stable bag identifier.
	 *
	 * @param Validator|array $validator Validator object or prebuilt error map.
	 * @param string $message Exception and response message.
	 * @param int $status HTTP status code, typically 422.
	 * @param string $errorBag Named form/error context.
	 */
	public function __construct(Validator|array $validator, string $message='The given data was invalid.', int $status=422, string $errorBag='default'){
		parent::__construct($message, $status);
		$this->status=$status;
		$this->errorBag=trim($errorBag) !== '' ? trim($errorBag) : 'default';
		if($validator instanceof Validator){
			$this->errors=$validator->errors();
			$this->validated=$validator->validated();
		} else {
			$this->errors=$validator;
			$this->validated=[];
		}
	}

	/**
	 * Creates a validation exception from explicit messages.
	 *
	 * This factory is useful when validation happens outside the Validator
	 * object but should still use the same throwable and response contract.
	 *
	 * @param array<string, mixed> $errors Field or context error payload.
	 * @param string $message Exception and response message.
	 * @param int $status HTTP status code for the response.
	 * @param string $errorBag Named form/error context.
	 * @return self Validation exception containing the supplied errors.
	 */
	public static function withMessages(array $errors, string $message='The given data was invalid.', int $status=422, string $errorBag='default'): self {
		return new self($errors, $message, $status, $errorBag);
	}

	/**
	 * Returns the HTTP status for response conversion.
	 *
	 * @return int Status code passed to Response::json().
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Returns the validation error map.
	 *
	 * @return array<string, mixed> Field or context errors.
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Returns the named validation bag.
	 *
	 * @return string Error bag name, never blank.
	 */
	public function errorBag(): string {
		return $this->errorBag;
	}

	/**
	 * Returns data accepted by the validator before the exception was raised.
	 *
	 * @return array<string, mixed> Validated input, or an empty array for manual error payloads.
	 */
	public function validated(): array {
		return $this->validated;
	}

	/**
	 * Serializes the client-facing validation error payload.
	 *
	 * The validated data is intentionally excluded to avoid echoing accepted but
	 * potentially sensitive request input back to API clients.
	 *
	 * @return array{message:string,errors:array,error_bag:string}
	 */
	public function toArray(): array {
		return [
			'message'=>$this->getMessage(),
			'errors'=>$this->errors,
			'error_bag'=>$this->errorBag,
		];
	}

	/**
	 * Converts the exception into a JSON HTTP response.
	 *
	 * @return Response JSON response containing message, errors, and error bag.
	 */
	public function toResponse(): Response {
		return Response::json($this->toArray(), $this->status);
	}
}
