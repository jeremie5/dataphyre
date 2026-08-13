<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Http\Request;
use Dataphyre\Mvc\HttpException;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\MvcRouteContext;
use Dataphyre\Mvc\RedirectResult;
use Dataphyre\Mvc\RouteDefinition;
use Dataphyre\Mvc\Session;
use Dataphyre\Mvc\SignedUrl;
use Dataphyre\Mvc\ValidationException;
use Dataphyre\Mvc\Validator;
use Dataphyre\Mvc\ViewResult;
use Dataphyre\Templating\Templating;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'http'=>true, 'routing'=>true, 'templating'=>true, 'mvc'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_mvc_results_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_mvc_results_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_mvc_results_modules_root);
\dataphyre\autoloader::register_framework_modules(['http', 'routing', 'templating', 'mvc']);
require_once $dp_mvc_results_modules_root.'/templating/unit_tests/templating_render_test_helpers.php';

test('mvc small results cover HTTP exceptions route context and signed URL rejection paths', static function(Context $t): void {
	$messages=[
		400=>'Bad Request',
		401=>'Unauthorized',
		403=>'Forbidden',
		404=>'Not Found',
		419=>'Page Expired',
		422=>'Unprocessable Entity',
		429=>'Too Many Requests',
		500=>'Server Error',
		503=>'Service Unavailable',
		599=>'HTTP Error',
	];
	foreach($messages as $status=>$message){
		$previous=new RuntimeException('previous');
		$exception=new HttpException($status, '', ['X-Status'=>(string)$status], $previous);
		$t->same($status, $exception->status());
		$t->same($message, $exception->getMessage());
		$t->same(['X-Status'=>(string)$status], $exception->headers());
		$t->same($previous, $exception->getPrevious());
		$html=$exception->toResponse();
		$t->same($status, $html->status);
		$t->same($message, $html->body);
		$json=$exception->toJsonResponse();
		$t->same(['message'=>$message, 'status'=>$status], json_decode($json->body, true));
	}
	$t->same('custom', (new HttpException(418, 'custom'))->getMessage());

	$app=new MvcApplication('context');
	$route=RouteDefinition::make('GET', '/users/{id}', static fn(): string=>'ok', ['name'=>'users.show']);
	$compiled=['path'=>'/users/{id}', 'methods'=>['GET']];
	$context=new MvcRouteContext($app, $route, $compiled, ['id'=>'42']);
	$t->same($app, $context->app());
	$t->same($route, $context->route());
	$t->same($compiled, $context->compiledRoute());
	$t->same(['id'=>'42'], $context->parameters());
	$t->same('42', $context->parameter('id'));
	$t->same('fallback', $context->parameter('missing', 'fallback'));
	$t->same('users.show', $context->name());

	$t->isFalse(SignedUrl::validUrl('/unsigned', [], 'secret'));
	$valid=SignedUrl::sign('/valid?mode=one', 'secret', time()+60);
	$validParts=parse_url($valid);
	parse_str((string)($validParts['query'] ?? ''), $validQuery);
	$t->isTrue(SignedUrl::validUrl((string)($validParts['path'] ?? ''), $validQuery, 'secret'));
	$t->isTrue(SignedUrl::valid(Request::create('GET', (string)($validParts['path'] ?? ''), $validQuery), 'secret'));
	$expired=SignedUrl::sign('/expired?mode=one', 'secret', time()-1);
	$parts=parse_url($expired);
	parse_str((string)($parts['query'] ?? ''), $query);
	$t->isFalse(SignedUrl::validUrl((string)($parts['path'] ?? ''), $query, 'secret'));
	$t->throws(static fn()=>SignedUrl::sign('/invalid', '  '), RuntimeException::class);
})->tag('mvc', 'results', 'deep-coverage')->group('framework-coverage');

test('mvc redirect results cover flash sources cookie normalization and response conversion', static function(Context $t): void {
	$sessionInternals=$t->nonPublic(Session::class);
	$sessionInternals
		->replacePropertyForTest('fallback', [])
		->replacePropertyForTest('started', false)
		->replacePropertyForTest('nativeSessionOverride', false);
	$validator=Validator::make(['email'=>'invalid'], ['email'=>'email']);
	$t->isTrue($validator->fails());
	$exception=new ValidationException(['name'=>['Name is required.']], 'invalid', 422, 'profile');

	$result=(new RedirectResult('/next', 303, ['X-Redirect'=>'yes']))
		->with('notice', 'saved')
		->withInput(['name'=>'Ada'])
		->withErrors(['plain'=>['Plain error.']], 'plain')
		->withErrors($validator, 'validator')
		->withErrors($exception)
		->withCookie('first', 'one')
		->withCookie('second', 'two', 5, '/admin', 'example.test', true, false, 'Strict')
		->withoutCookie('old', '/admin', 'example.test');
	$t->same('saved', Session::get('notice'));
	$t->same(['name'=>'Ada'], Session::old());
	$t->same(['Plain error.'], Session::errors('plain')['plain']);
	$t->contains('email', array_keys(Session::errors('validator')));
	$t->same(['Name is required.'], Session::errors('profile')['name']);
	$response=$result->toResponse(new MvcApplication('ignored'));
	$t->same(303, $response->status);
	$t->same('/next', $response->headers['Location']);
	$t->same('yes', $response->headers['X-Redirect']);
	$t->same(3, count($response->headers['Set-Cookie']));

	$stringHeader=(new RedirectResult('/string', 302, ['Set-Cookie'=>'seed=one']))->withCookie('next', 'two')->toResponse();
	$t->same(2, count($stringHeader->headers['Set-Cookie']));
	$filtered=(new RedirectResult('/filtered', 302, ['Set-Cookie'=>['', null, 'seed=one']]))->withoutCookie('seed')->toResponse();
	$t->same(2, count($filtered->headers['Set-Cookie']));
	$named=(new RedirectResult('/named'))->withErrors($exception, 'override');
	$t->same(['Name is required.'], Session::errors('override')['name']);
	$t->same('/named', $named->toResponse()->headers['Location']);

	$sessionInternals->writeProperty('nativeSessionOverride', null);
	Session::flush();
})->tag('mvc', 'results', 'deep-coverage')->group('framework-coverage');

test('mvc view results cover immutable modifiers template resolution and cookie normalization', static function(Context $t): void {
	$workspace=$t->workspace('mvc-results');
	$views=$workspace->directory('views/account');
	$views=dirname($views);
	$cache=$workspace->directory('cache').DIRECTORY_SEPARATOR;
	$direct=$workspace->file('direct.tpl', '<main>Direct {{name}}</main>');
	$workspace->file('views/account/profile.php', '<section>Profile {{name}}</section>');
	Templating::flush();
	$t->cleanup(static fn()=>Templating::flush());
	\dataphyre\templating::init(false, $cache, false);
	$original=ViewResult::make($direct, ['name'=>'Ada']);
	$modified=$original
		->with(['name'=>'Grace', 'extra'=>true])
		->status(201)
		->header('X-View', 'yes')
		->withCookie('theme', 'dark')
		->withoutCookie('legacy');
	$directResponse=$modified->toResponse();
	$t->same(201, $directResponse->status);
	$t->contains('Direct Grace', $directResponse->body);
	$t->same('yes', $directResponse->headers['X-View']);
	$t->same(2, count($directResponse->headers['Set-Cookie']));
	$t->same(200, $original->toResponse()->status);

	$app=new MvcApplication('views', ['views'=>['path'=>$views]]);
	$resolved=(new ViewResult('account.profile', ['name'=>'Lin']))->toResponse($app);
	$t->contains('Profile Lin', $resolved->body);
	$t->throws(
		static fn()=>(new ViewResult('missing-logical-template', ['name'=>'Nobody']))->toResponse($app),
		RuntimeException::class
	);

	$stringHeader=(new ViewResult($direct, ['name'=>'String'], 202, ['Set-Cookie'=>'seed=one']))->withCookie('next', 'two')->toResponse();
	$t->same(2, count($stringHeader->headers['Set-Cookie']));
	$filtered=(new ViewResult($direct, ['name'=>'Filter'], 203, ['Set-Cookie'=>['', null, 'seed=one']]))->withoutCookie('seed')->toResponse();
	$t->same(2, count($filtered->headers['Set-Cookie']));
})->tag('mvc', 'results', 'deep-coverage')->group('framework-coverage')->maxMillis(10000);
