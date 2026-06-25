<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Request;

/**
 * Base class for request-specific authorization and validation workflows.
 *
 * FormRequest wraps a Dataphyre HTTP request with lazy preparation, authorization,
 * validator construction, failure hooks, validated data caching, and safe
 * controller access to merged query/body/file/route input.
 */
abstract class FormRequest {

	protected Request $request;
	private ?Validator $validator=null;
	private ?array $validated=null;
	private ?array $validationData=null;
	private bool $prepared=false;
	protected bool $stopOnFirstFailure=false;
	protected string $errorBag='default';

	/**
	 * Binds the form request wrapper to an HTTP request.
	 *
	 * Validation state is lazy: input preparation, validator construction, and rule
	 * execution are deferred until validator(), validateResolved(), or validated() is
	 * called.
	 *
	 * @param Request $request Source HTTP request for validation and route data.
	 */
	public function __construct(Request $request){
		$this->request=$request;
	}

	/**
	 * Creates the concrete form request for a source HTTP request.
	 *
	 * @param Request $request Source HTTP request for validation and route data.
	 * @return static Concrete form request instance.
	 */
	public static function from(Request $request): static {
		return new static($request);
	}

	/**
	 * Returns validation rules for the request payload.
	 *
	 * Subclasses define the field contract consumed by Validator::make(). Rule keys
	 * may target merged query, body, file, and route data.
	 *
	 * @return array<string, mixed> Validation rules keyed by input field.
	 */
	abstract public function rules(): array;

	/**
	 * Determines whether the current request may run validation/controller logic.
	 *
	 * Override this in subclasses for policy checks that depend on the wrapped
	 * Request, route parameters, user attributes, or prepared input.
	 *
	 * @return bool True when the request is authorized.
	 */
	public function authorize(): bool {
		return true;
	}

	/**
	 * Raises the validation exception used for authorization failure.
	 *
	 * The default exception uses the authorization message, authorization status, and
	 * active error bag so callers receive the same error shape as validation errors.
	 *
	 * @return never
	 * @throws ValidationException Always thrown when authorization fails.
	 */
	protected function failedAuthorization(): never {
		throw ValidationException::withMessages(['authorization'=>[$this->authorizationMessage()]], $this->authorizationMessage(), $this->authorizationStatus(), $this->errorBag());
	}

	/**
	 * Returns the default authorization failure message.
	 *
	 * @return string Message stored under the authorization error key.
	 */
	protected function authorizationMessage(): string {
		return 'This action is unauthorized.';
	}

	/**
	 * Returns the HTTP status used for authorization failure.
	 *
	 * @return int HTTP status code, defaulting to 403.
	 */
	protected function authorizationStatus(): int {
		return 403;
	}

	/**
	 * Returns custom validation messages.
	 *
	 * @return array<string, string|array<int, string>> Custom messages keyed by rule or field.rule.
	 */
	public function messages(): array {
		return [];
	}

	/**
	 * Returns human-readable validation attribute names.
	 *
	 * @return array<string, string> Display labels keyed by input field.
	 */
	public function attributes(): array {
		return [];
	}

	/**
	 * Hook for normalizing validation data before validator construction.
	 *
	 * Use merge() or replace() in subclasses to adjust the lazy validation payload.
	 * The hook runs at most once per form request instance.
	 *
	 * @return void
	 */
	protected function prepareForValidation(): void {}

	/**
	 * Hook invoked after validation succeeds and validated data has been cached.
	 *
	 * @return void
	 */
	protected function passedValidation(): void {}

	/**
	 * Raises the exception used when validation rules fail.
	 *
	 * @param Validator $validator Validator containing errors and validated state.
	 * @return never
	 * @throws ValidationException Always thrown when validation fails.
	 */
	protected function failedValidation(Validator $validator): never {
		throw new ValidationException($validator, 'The given data was invalid.', 422, $this->errorBag());
	}

	/**
	 * Returns the validation error bag name.
	 *
	 * Empty configured names fall back to default so downstream error rendering always
	 * receives a usable bag key.
	 *
	 * @return string Non-empty error bag name.
	 */
	public function errorBag(): string {
		$bag=trim($this->errorBag);
		return $bag!=='' ? $bag : 'default';
	}

	/**
	 * Hook for customizing the validator after construction and before execution.
	 *
	 * Subclasses can attach after callbacks or conditionally modify validator state.
	 *
	 * @param Validator $validator Newly constructed validator instance.
	 * @return void
	 */
	public function withValidator(Validator $validator): void {}

	/**
	 * Returns the wrapped HTTP request.
	 *
	 * @return Request Source request object.
	 */
	public function request(): Request {
		return $this->request;
	}

	/**
	 * Returns request data used by validation rules.
	 *
	 * If prepareForValidation() has merged or replaced data, that prepared payload is
	 * returned; otherwise the base request data is assembled lazily.
	 *
	 * @return array<string, mixed> Validation input map.
	 */
	public function all(): array {
		if($this->validationData!==null){
			return $this->validationData;
		}
		return $this->baseValidationData();
	}

	/**
	 * Recursively merges data into the validation payload.
	 *
	 * Calling merge() before validation invalidates a previously constructed validator
	 * unless validated data has already been cached.
	 *
	 * @param array<string, mixed> $data Data to merge into validation input.
	 * @return static This form request instance.
	 */
	public function merge(array $data): static {
		$this->validationData=array_replace_recursive($this->all(), $data);
		if($this->validated===null){
			$this->validator=null;
		}
		return $this;
	}

	/**
	 * Replaces the validation payload.
	 *
	 * Calling replace() before validation invalidates a previously constructed
	 * validator unless validated data has already been cached.
	 *
	 * @param array<string, mixed> $data Complete validation input map.
	 * @return static This form request instance.
	 */
	public function replace(array $data): static {
		$this->validationData=$data;
		if($this->validated===null){
			$this->validator=null;
		}
		return $this;
	}

	/**
	 * Returns the payload passed to Validator::make().
	 *
	 * Override this to expose a different validation view without changing the raw
	 * wrapped request.
	 *
	 * @return array<string, mixed> Validation input map.
	 */
	public function validationData(): array {
		return $this->all();
	}

	/**
	 * Builds the default validation payload from the wrapped request.
	 *
	 * Query, body input, files, and route parameters are merged in that order, so
	 * later sources replace duplicate top-level keys from earlier sources.
	 *
	 * @return array<string, mixed> Base validation input map.
	 */
	private function baseValidationData(): array {
		return array_replace(
			$this->request->query(),
			$this->request->input(),
			$this->request->files(),
			$this->request->routeParameters()
		);
	}

	/**
	 * Reads validation input using optional dot notation.
	 *
	 * @param string|null $key Optional input key or dot path.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Full validation input, one value, or default.
	 */
	public function input(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->all();
		}
		return $this->dataGet($this->all(), $key, $default);
	}

	/**
	 * Reads wrapped request route parameters using optional dot notation.
	 *
	 * @param string|null $key Optional route key or dot path.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Full route parameter map, one value, or default.
	 */
	public function route(?string $key=null, mixed $default=null): mixed {
		$parameters=$this->request->routeParameters();
		if($key===null){
			return $parameters;
		}
		return $this->dataGet($parameters, $key, $default);
	}

	/**
	 * Returns the lazily constructed validator for this form request.
	 *
	 * The method runs preparation once, creates the validator from validationData(),
	 * applies stopOnFirstFailure when configured, and then calls withValidator().
	 *
	 * @return Validator Validator configured for this request.
	 */
	public function validator(): Validator {
		if($this->validator===null){
			$this->prepareOnce();
			$this->validator=Validator::make($this->validationData(), $this->rules(), $this->messages(), $this->attributes());
			if($this->stopOnFirstFailure){
				$this->validator->stopOnFirstFailure();
			}
			$this->withValidator($this->validator);
		}
		return $this->validator;
	}

	/**
	 * Runs the full form request lifecycle.
	 *
	 * Preparation runs once, authorization is checked, validation is executed, failed
	 * states throw, and successful validated data is cached before passedValidation().
	 *
	 * @return static This form request instance after successful validation.
	 * @throws ValidationException When authorization or validation fails.
	 */
	public function validateResolved(): static {
		$this->prepareOnce();
		if($this->authorize()===false){
			$this->failedAuthorization();
		}
		$validator=$this->validator();
		if($validator->fails()){
			$this->failedValidation($validator);
		}
		$this->validated=$validator->validated();
		$this->passedValidation();
		return $this;
	}

	/**
	 * Runs preparation and authorization without executing validation rules.
	 *
	 * @return static This form request instance when authorized.
	 * @throws ValidationException When authorize() returns false.
	 */
	public function authorizeOrFail(): static {
		$this->prepareOnce();
		if($this->authorize()===false){
			$this->failedAuthorization();
		}
		return $this;
	}

	/**
	 * Returns validated data, running validation lazily if needed.
	 *
	 * Passing no key returns the complete validated map. Dot notation can read nested
	 * validated fields with a fallback default.
	 *
	 * @param string|null $key Optional validated key or dot path.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Full validated map, one validated value, or default.
	 * @throws ValidationException When authorization or validation fails.
	 */
	public function validated(?string $key=null, mixed $default=null): mixed {
		if($this->validated===null){
			$this->validateResolved();
		}
		if($key===null){
			return $this->validated;
		}
		return $this->dataGet($this->validated, $key, $default);
	}

	/**
	 * Alias for validated() for controller readability.
	 *
	 * @param string|null $key Optional validated key or dot path.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed Full validated map, one validated value, or default.
	 * @throws ValidationException When authorization or validation fails.
	 */
	public function safe(?string $key=null, mixed $default=null): mixed {
		return $this->validated($key, $default);
	}

	/**
	 * Runs validation preparation once per request instance.
	 *
	 * The base payload is captured before the hook so prepareForValidation() can merge
	 * or replace a stable snapshot.
	 *
	 * @return void
	 */
	private function prepareOnce(): void {
		if($this->prepared){
			return;
		}
		$this->prepared=true;
		$this->validationData=$this->baseValidationData();
		$this->prepareForValidation();
	}

	/**
	 * Reads a value from an array using literal keys or dot notation.
	 *
	 * Literal key matches win before dot-path traversal so input names containing dots
	 * remain addressable.
	 *
	 * @param array<string, mixed> $data Source array.
	 * @param string $key Literal key or dot path.
	 * @param mixed $default Value returned when the key is absent.
	 * @return mixed literal-key value, nested dot-path value, or the caller default when absent.
	 */
	private function dataGet(array $data, string $key, mixed $default=null): mixed {
		if(array_key_exists($key, $data)){
			return $data[$key];
		}
		if(!str_contains($key, '.')){
			return $default;
		}
		$current=$data;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return $default;
			}
			$current=$current[$segment];
		}
		return $current;
	}
}
