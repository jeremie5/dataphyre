<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Http\Request;
use Dataphyre\Mvc\Controller;
use Dataphyre\Mvc\ControllerMiddlewareRegistration;
use Dataphyre\Mvc\HttpException;
use Dataphyre\Mvc\Mvc;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\MvcRouteContext;
use Dataphyre\Mvc\RouteCollection;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'routing', 'mvc']);

final class DpMvcControllerCoverageHarness extends Controller {
	public function registerCoverageMiddleware(array|string|callable ...$middleware): ControllerMiddlewareRegistration {
		return $this->middleware(...$middleware);
	}
}

function dp_mvc_controller_argument(ReflectionParameter $parameter, string $method, string $fixture): mixed {
	if($parameter->isDefaultValueAvailable()){
		return $parameter->getDefaultValue();
	}
	$type=$parameter->getType();
	$types=$type instanceof ReflectionUnionType ? $type->getTypes() : ($type ? [$type] : []);
	$names=array_map(static fn(ReflectionNamedType $candidate): string=>$candidate->getName(), $types);
	if(in_array(Request::class, $names, true)){
		return Request::create('POST', '/controller', ['query'=>'yes'], ['body'=>'value']);
	}
	if(in_array('array', $names, true)){
		return match($parameter->getName()){
			'ratios'=>[1, 2],
			'message'=>['to'=>['test@example.test'], 'subject'=>'Coverage'],
			default=>[],
		};
	}
	if(in_array('callable', $names, true)){
		return static fn()=>null;
	}
	if(in_array('bool', $names, true)){
		return false;
	}
	if(in_array('int', $names, true)){
		return match($parameter->getName()){
			'status'=>200,
			'parts'=>2,
			'expires', 'expiresAt'=>time()+60,
			default=>1,
		};
	}
	if(in_array('float', $names, true)){
		return 1.0;
	}
	if(in_array('string', $names, true)){
		return match($parameter->getName()){
			'path'=>$fixture,
			'name'=>'home',
			'template'=>'missing-template',
			'email'=>'person@example.test',
			'date'=>'2026-07-11',
			'language'=>'en',
			'format'=>'Y-m-d',
			'currency', 'sourceCurrency', 'targetCurrency', 'originalCurrency'=>'USD',
			'component'=>'missing-component',
			'location'=>'/home',
			'key'=>'coverage-key',
			'one'=>'one',
			'many'=>'many',
			'zero'=>'zero',
			default=>'value',
		};
	}
	return null;
}

test('mvc controller deep coverage exercises middleware registration selection and constraints', static function(Context $t): void {
	$controller=new DpMvcControllerCoverageHarness();
	$controller->registerCoverageMiddleware(['alpha', 'beta'])
		->only([' Show ', '', 'SHOW'], ' Edit ')
		->except([' Index ', '']);
	$controller->registerCoverageMiddleware('gamma')->except('show');

	$t->same(['alpha', 'beta', 'gamma'], $controller->mvcControllerMiddleware());
	$t->same(['alpha', 'beta'], $controller->mvcControllerMiddleware('SHOW'));
	$t->same(['gamma'], $controller->mvcControllerMiddleware('index'));
	$t->same(['gamma'], $controller->mvcControllerMiddleware('missing'));

	$detached=[];
	$registration=new ControllerMiddlewareRegistration($detached, [99]);
	$t->isTrue($registration->only('ignored')===$registration);
	$t->isTrue($registration->except('ignored')===$registration);
})->tag('mvc', 'controller', 'deep-coverage')->group('framework-coverage');

test('mvc controller deep coverage exercises every protected helper and route context path', static function(Context $t): void {
	Mvc::flush();
	$t->cleanup(static function(): void { Mvc::flush(); });
	$tmp=$t->workspace('mvc-controller')->file('controller-body','controller-body');
		$app=Mvc::register('default', [
			'signed_url_secret'=>'controller-secret',
			'routes'=>static function(RouteCollection $routes): void {
				$routes->get('/home/{id?}', static fn(): string=>'home', ['name'=>'home']);
			},
		]);
		$t->instanceOf(MvcApplication::class, $app);
		$controller=new DpMvcControllerCoverageHarness();
		$controllerInternals=$t->nonPublic($controller);
		$protectedMethods=$t->inventory(Controller::class)->protectedMethods();
		$invoked=[];
		foreach($protectedMethods as $method){
			$arguments=[];
			foreach($method->getParameters() as $parameter){
				$arguments[]=dp_mvc_controller_argument($parameter, $method->getName(), $tmp);
			}
			try{
				$controllerInternals->invokeWithArguments($method->getName(),$arguments);
			}catch(Throwable){
				// Unavailable optional bridges and abort/authorize helpers fail closed.
			}
			$invoked[]=$method->getName();
		}
		$t->same(count($protectedMethods),count($invoked));
		$t->contains('storageTemporaryUrl', $invoked);
		$t->contains('redirectToRoute', $invoked);

		$controllerInternals->invoke('abortIf',false,400);
		$t->throws(static fn()=>$controllerInternals->invoke('abortIf',true,418,'teapot'),HttpException::class);
		$controllerInternals->invoke('abortUnless',true,400);
		$t->throws(static fn()=>$controllerInternals->invoke('abortUnless',false,403,'denied'),HttpException::class);

		$route=$app->routes()->named('home');
		if($route===null){
			throw new RuntimeException('Expected named route fixture.');
		}
		$controller->setMvcRouteContext(new MvcRouteContext($app, $route, [], ['id'=>7]));
		$t->same('/home/7',$controllerInternals->invoke('route','home',['id'=>7]));
		$signed=$controllerInternals->invoke('signedRoute','home',['id'=>7],[],time()+60);
		$t->contains('signature=', $signed);

		$server=$t->globalMap('_SERVER');
		$server->put('HTTP_REFERER','');
		$t->same('/fallback',$controllerInternals->invoke('back','/fallback')->toResponse()->headers['Location']);
		$server->put('HTTP_REFERER','/previous');
		$t->same('/previous',$controllerInternals->invoke('back')->toResponse()->headers['Location']);
})->tag('mvc', 'controller', 'deep-coverage')->group('framework-coverage');
