<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Request;
use Dataphyre\Http\UploadedFile;
use Dataphyre\Mvc\FormRequest;
use Dataphyre\Mvc\ValidationException;
use Dataphyre\Mvc\Validator;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'mvc']);

final class DpMvcBaseValidationFormRequest extends FormRequest {
	public function rules(): array {
		return ['name'=>'required|string'];
	}
}

final class DpMvcDeniedValidationFormRequest extends FormRequest {
	public function rules(): array {
		return [];
	}
	public function authorize(): bool {
		return false;
	}
}

final class DpMvcInvalidValidationFormRequest extends FormRequest {
	protected string $errorBag='   ';
	public function rules(): array {
		return ['missing'=>'required'];
	}
}

final class DpMvcStopValidationFormRequest extends FormRequest {
	protected bool $stopOnFirstFailure=true;
	protected string $errorBag=' orders ';
	public function rules(): array {
		return ['first'=>'required', 'second'=>'required'];
	}
}

test('mvc form request covers lazy payload authorization validation mutation and dot access', static function(Context $t): void {
	$request=Request::create(
		'POST',
		'/orders/42',
		['source'=>'query', 'query_only'=>'yes', 'literal.key'=>'literal', 'query_nested'=>['value'=>'query']],
		['source'=>'body', 'name'=>' Ada ', 'body_only'=>'yes'],
		[],
		[],
		[],
		['source'=>'route', 'id'=>'42', 'route_nested'=>['value'=>'route']]
	);

	$form=DpMvcBaseValidationFormRequest::from($request);
	$t->same($request, $form->request());
	$t->isTrue($form->authorize());
	$t->same([], $form->messages());
	$t->same([], $form->attributes());
	$t->same('route', $form->all()['source']);
	$t->same($form->all(), $form->input());
	$t->same('literal', $form->input('literal.key'));
	$t->same('query', $form->input('query_nested.value'));
	$t->same('fallback', $form->input('missing', 'fallback'));
	$t->same('fallback', $form->input('query_nested.missing', 'fallback'));
	$t->same($request->routeParameters(), $form->route());
	$t->same('route', $form->route('route_nested.value'));
	$t->same('fallback', $form->route('route_nested.missing', 'fallback'));
	$t->same($form, $form->authorizeOrFail());
	$t->same($form, $form->validateResolved());
	$t->same(['name'=>' Ada '], $form->validated());
	$t->same(' Ada ', $form->validated('name'));
	$t->same('fallback', $form->safe('missing', 'fallback'));
	$t->same('default', $form->errorBag());

	$mutable=DpMvcBaseValidationFormRequest::from($request);
	$firstValidator=$mutable->validator();
	$mutable->merge(['name'=>'Grace', 'profile'=>['email'=>'grace@example.test']]);
	$secondValidator=$mutable->validator();
	$t->isTrue(spl_object_id($firstValidator)!==spl_object_id($secondValidator));
	$t->same('grace@example.test', $mutable->input('profile.email'));
	$mutable->replace(['name'=>'Katherine']);
	$thirdValidator=$mutable->validator();
	$t->isTrue(spl_object_id($secondValidator)!==spl_object_id($thirdValidator));
	$t->same(['name'=>'Katherine'], $mutable->validationData());
	$t->same(['name'=>'Katherine'], $mutable->safe());
	$cachedValidator=$mutable->validator();
	$mutable->merge(['ignored_after_validation'=>true]);
	$t->same($cachedValidator, $mutable->validator());

	$stopping=DpMvcStopValidationFormRequest::from(Request::create('POST', '/stop'));
	$t->same('orders', $stopping->errorBag());
	$t->same(1, count($stopping->validator()->errors()));

	$invalid=DpMvcInvalidValidationFormRequest::from(Request::create('POST', '/invalid'));
	$invalidException=$t->throws(static fn()=>$invalid->validateResolved(), ValidationException::class);
	$t->same(422, $invalidException->status());
	$t->same('default', $invalidException->errorBag());

	$denied=DpMvcDeniedValidationFormRequest::from(Request::create('POST', '/denied'));
	$authorization=$t->throws(static fn()=>$denied->validateResolved(), ValidationException::class);
	$t->same(403, $authorization->status());
	$t->same('This action is unauthorized.', $authorization->getMessage());
	$t->same(['This action is unauthorized.'], $authorization->errors()['authorization']);
	$t->throws(
		static fn()=>DpMvcDeniedValidationFormRequest::from(Request::create('POST', '/denied-again'))->authorizeOrFail(),
		ValidationException::class
	);
})->tag('mvc', 'validator', 'mvc-validation-exact')->group('framework-coverage');

test('mvc validator covers bail conditionals wildcard messages native values distinct arrays and upload fallbacks', static function(Context $t): void {
	$fixture=$t->workspace('mvc-validation-upload')->file('notes.txt', 'validation fixture');
	$txt=new UploadedFile('notes.txt', 'text/plain', $fixture, UPLOAD_ERR_OK, filesize($fixture) ?: 0);
	$fallbackImage=new UploadedFile('fallback.png', 'application/octet-stream', $fixture, UPLOAD_ERR_OK, filesize($fixture) ?: 0);
	{
		$data=[
			'control'=>'value',
			'bail_string'=>'not-an-email',
			'bail_callable'=>'value',
			'blank_rule'=>'value',
			'mode'=>'business',
			'filled'=>'yes',
			'conditional_missing_field'=>'present',
			'conditional_no_values'=>'present',
			'conditional_with'=>'present',
			'conditional_without'=>'present',
			'prohibited_upload'=>$txt,
			'native_integer'=>7,
			'native_boolean'=>true,
			'integer_boolean'=>1,
			'invalid_boolean'=>['not'=>'boolean'],
			'native_accepted'=>true,
			'date_object'=>new DateTimeImmutable('2026-07-11'),
			'date_timestamp'=>1700000000,
			'date_invalid_type'=>['year'=>2026],
			'distinct_scalar'=>'value',
			'distinct_unique'=>[1, 2],
			'distinct_duplicate'=>[1, '1'],
			'distinct_strict'=>[1, '1'],
			'distinct_booleans'=>[true, false],
			'distinct_objects'=>[(object)['id'=>1], (object)['id'=>2]],
			'mime_extension_failure'=>$txt,
			'mimetype_wildcard'=>$txt,
			'mimetype_failure'=>$txt,
			'image_extension_fallback'=>$fallbackImage,
			'digits_missing_parameter'=>'123',
			'items'=>[['email'=>'invalid']],
			'groups'=>[[
				'reference'=>'A',
				'items'=>[['code'=>'A']],
			]],
		];
		$rules=[
			'control'=>'required',
			'bail_string'=>'bail|email|min:100',
			'bail_callable'=>['bail', static fn(): bool=>false, static fn(): string=>'must not run'],
			'blank_rule'=>['   ', 'string'],
			'conditional_missing_field'=>'required_if:missing,value',
			'conditional_no_values'=>'required_if:mode',
			'conditional_with'=>'required_with:missing',
			'conditional_without'=>'required_without:filled',
			'prohibited_upload'=>'prohibited',
			'native_integer'=>'integer',
			'native_boolean'=>'boolean',
			'integer_boolean'=>'boolean',
			'invalid_boolean'=>'boolean',
			'native_accepted'=>'accepted',
			'date_object'=>'date',
			'date_timestamp'=>'date',
			'date_invalid_type'=>'date',
			'distinct_scalar'=>'distinct',
			'distinct_unique'=>'distinct',
			'distinct_duplicate'=>'distinct',
			'distinct_strict'=>'distinct:strict',
			'distinct_booleans'=>'distinct',
			'distinct_objects'=>'distinct',
			'mime_extension_failure'=>'file|mimes:png',
			'mimetype_wildcard'=>'file|mimetypes:text/*',
			'mimetype_failure'=>'file|mimetypes:image/png',
			'image_extension_fallback'=>'file|image',
			'digits_missing_parameter'=>'digits',
			'items.*.email'=>'email',
			'groups.*.items.*.code'=>'same:groups.*.reference',
			'missing.branch.*.value'=>'required',
		];
		$validator=Validator::make(
			$data,
			$rules,
			['items.*.email.email'=>'Wildcard :attribute failure.'],
			['items.*.email'=>'contact email']
		);
		$t->isFalse($validator->passes());
		$errors=$validator->errors();
		$t->same(1, count($errors['bail_string']));
		$t->same(1, count($errors['bail_callable']));
		$t->contains('contact email', $errors['items.0.email'][0]);
		$t->contains('distinct_duplicate', array_keys($errors));
		$t->contains('mime_extension_failure', array_keys($errors));
		$t->contains('mimetype_failure', array_keys($errors));
		$t->contains('digits_missing_parameter', array_keys($errors));
		$t->isFalse(array_key_exists('mimetype_wildcard', $errors));
		$t->isFalse(array_key_exists('image_extension_fallback', $errors));
		$t->isFalse(array_key_exists('distinct_strict', $errors));
		$t->isFalse(array_key_exists('distinct_booleans', $errors));
		$t->isFalse(array_key_exists('distinct_objects', $errors));
		$t->same('fallback', $validator->safe('validated.missing.path', 'fallback'));
	}
})->tag('mvc', 'validator', 'mvc-validation-exact')->group('framework-coverage');

test('mvc validation exception covers manual messages blank bags response and serialized payload', static function(Context $t): void {
	$exception=ValidationException::withMessages(
		['general'=>['Manual validation failure.']],
		'Manual failure',
		409,
		'   '
	);
	$t->same(409, $exception->status());
	$t->same('default', $exception->errorBag());
	$t->same([], $exception->validated());
	$t->same(['Manual validation failure.'], $exception->errors()['general']);
	$t->same([
		'message'=>'Manual failure',
		'errors'=>['general'=>['Manual validation failure.']],
		'error_bag'=>'default',
	], $exception->toArray());
	$response=$exception->toResponse();
	$t->same(409, $response->status);
	$t->contains('Manual failure', $response->body);
})->tag('mvc', 'validator', 'mvc-validation-exact')->group('framework-coverage');
