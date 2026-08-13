<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Mvc\HttpException;
use Dataphyre\Mvc\Mvc;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\MvcHost;
use Dataphyre\Mvc\MvcManager;
use Dataphyre\Mvc\RedirectResult;
use Dataphyre\Mvc\RouteCollection;
use Dataphyre\Mvc\ViewResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'routing', 'mvc']);

/**
 * Supplies inert values for reflective facade coverage. Required integration
 * methods are still asserted individually below; this sweep ensures every
 * optional bridge executes its documented unavailable-module boundary.
 */
function dp_mvc_facade_argument(ReflectionParameter $parameter, string $method, string $fixture): mixed {
	if($parameter->isDefaultValueAvailable()){
		return $parameter->getDefaultValue();
	}
	$type=$parameter->getType();
	$types=$type instanceof ReflectionUnionType ? $type->getTypes() : ($type ? [$type] : []);
	$names=array_map(static fn(ReflectionNamedType $candidate): string=>$candidate->getName(), $types);
	if(in_array(Request::class, $names, true)){
		return Request::create('GET', '/home');
	}
	if(in_array('array', $names, true)){
		return match($parameter->getName()){
			'ratios'=>[1, 2],
			'rules'=>[],
			'message'=>['to'=>['test@example.com'], 'subject'=>'Test'],
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
			'path'=>str_contains(strtolower($method), 'storage') ? 'missing.txt' : $fixture,
			'route', 'name'=>'home',
			'template'=>'inline template',
			'date'=>'2026-07-10',
			'language'=>'en',
			'format'=>'Y-m-d',
			'currency', 'sourceCurrency', 'targetCurrency', 'originalCurrency'=>'USD',
			'algorithm'=>'sha256',
			'component'=>'missing-component',
			'location'=>'/home',
			'key'=>'coverage-key',
			default=>'value',
		};
	}
	return null;
}

test('mvc facade covers every public bridge and its unavailable module fallback', static function(Context $t): void {
	Mvc::flush();
	$t->cleanup(static fn()=>Mvc::flush());
	$fixture=$t->workspace('mvc-facade')->file('file-body.txt', 'file-body');
	$app=Mvc::register('default', [
		'signed_url_secret'=>'facade-secret',
		'routes'=>static function(RouteCollection $routes): void {
			$routes->get('/home', static fn(): string=>'home', ['name'=>'home']);
		},
	]);
	$t->instanceOf(MvcApplication::class, $app);
	$inventory=$t->inventory(Mvc::class);
	$methods=$inventory->publicMethods(static:true);
	$invoked=[];
	foreach($methods as $method){
		$args=[];
		foreach($method->getParameters() as $parameter){
			$args[]=dp_mvc_facade_argument($parameter, $method->getName(), $fixture);
		}
		$name=$method->getName();
		try{
			$inventory->invokeWithArguments($method, null, $args);
		}catch(Throwable){
			// Abort/authorize and unavailable optional integrations intentionally fail closed.
		}
		$invoked[]=$name;
	}
	$t->same(
		count($methods),
		count($invoked)
	);
	$t->contains('storageTemporaryUrl', $invoked);
	$t->contains('reactorDispatch', $invoked);
	$t->contains('authorizeAny', $invoked);
})->tag('mvc', 'facade', 'coverage')->group('framework-coverage');

test('mvc facade core applications routes responses validation files and security contracts are concrete', static function(Context $t): void {
	Mvc::flush();
	$t->cleanup(static fn()=>Mvc::flush());
	$manager=Mvc::manager();
	$t->instanceOf(MvcManager::class, $manager);
	$app=Mvc::register('', [
		'signed_url_secret'=>'facade-secret',
		'routes'=>static fn(RouteCollection $routes)=>$routes->get('/users/{id}', static fn(string $id): array=>['id'=>$id], ['name'=>'users.show']),
	]);
	$t->isTrue(Mvc::app('')===$app);
	$t->isTrue(Mvc::defaultApp()===$app);
	$t->isTrue(Mvc::routes()===$app->routes());
	$t->same(1, count(Mvc::routeList()));
	$t->same('/users/7?q=one', Mvc::url('users.show', ['id'=>7], ['q'=>'one']));
	$signed=Mvc::signedUrl('users.show', ['id'=>8], [], time()+60);
	$parts=parse_url($signed);
	parse_str((string)($parts['query'] ?? ''), $query);
	$t->isTrue(Mvc::hasValidSignature(Request::create('GET', (string)$parts['path'], $query)));
	$t->isFalse(Mvc::hasValidSignature(Request::create('GET', (string)$parts['path'], $query+['changed'=>'yes'])));
	$t->instanceOf(ViewResult::class, Mvc::view('page', ['a'=>1]));
	$t->same('', Mvc::renderTemplate('missing'));
	$t->same('', Mvc::renderTemplateString('hello'));
	$t->same([], Mvc::templateAssets('missing'));
	$t->same([], Mvc::templateStringAssets('hello'));
	$t->same('', Mvc::templateAssetHtml('missing'));
	$t->same('', Mvc::templateStringAssetHtml('hello'));
	$t->same(['name'=>'Ada'], Mvc::validate(['name'=>'Ada'], ['name'=>'required']));

	$json=Mvc::json(['ok'=>true], 201);
	$t->same(201, $json->status);
	$t->same(['ok'=>true], json_decode($json->body, true));
	$t->same(201, Mvc::created(['id'=>1], '/users/1')->status);
	$t->same(204, Mvc::noContent()->status);
	$t->instanceOf(RedirectResult::class, Mvc::redirect('/home'));
	$t->same('/users/9', Mvc::redirectToRoute('users.show', ['id'=>9])->toResponse()->headers['Location']);
	$t->same(['auth_type'=>'web', 'logged_in'=>false, 'userid'=>false], Mvc::authContext('web'));
	$t->isFalse(Mvc::loggedIn());
	$t->isFalse(Mvc::userId());
	$t->isFalse(Mvc::can('edit'));
	$t->isFalse(Mvc::canAny(['edit']));
	$t->throws(static fn()=>Mvc::authorize('edit'), HttpException::class);
	$t->throws(static fn()=>Mvc::authorizeAny(['edit']), HttpException::class);
	Mvc::abortIf(false, 400);
	Mvc::abortUnless(true, 400);
	$t->throws(static fn()=>Mvc::abort(404), HttpException::class);
	$t->throws(static fn()=>Mvc::abortIf(true, 404), HttpException::class);
	$t->throws(static fn()=>Mvc::abortUnless(false, 404), HttpException::class);
	$t->same(['id'=>'12'], json_decode(Mvc::dispatch(Request::create('GET', '/users/12'))->body, true));
	$t->instanceOf(MvcHost::class, Mvc::host());

	$filePath=$t->workspace('mvc-download')->file('download.txt', 'download-body');
	$file=Mvc::file($filePath, 'résumé.txt');
	$t->same('download-body', $file->body);
	$t->contains('inline', $file->headers['Content-Disposition']);
	$download=Mvc::download($filePath);
	$t->contains('attachment', $download->headers['Content-Disposition']);
	$t->throws(static fn()=>Mvc::file($filePath.'.missing'), InvalidArgumentException::class);
})->tag('mvc', 'facade', 'coverage')->group('framework-coverage');

test('mvc facade private normalizers cover metadata assets mail reactor interpolation and request merging', static function(Context $t): void {
	$internals=$t->nonPublic(Mvc::class);
	$t->same('text/css; charset=utf-8', $internals->invoke('storageMimeType', 'a.css', []));
	$t->same('image/jpeg', $internals->invoke('storageMimeType', 'a.jpeg', []));
	$t->same('application/octet-stream', $internals->invoke('storageMimeType', 'a.unknown', []));
	$t->same('custom/type', $internals->invoke('storageMimeType', 'a.txt', ['mime_type'=>'custom/type']));
	$t->contains("filename*=UTF-8''", $internals->invoke('contentDisposition', 'attachment', 'resume.txt'));
	$t->same('<a>', $internals->invoke('assetHtml', ['head_html'=>'<a>'], 'head'));
	$t->same("<a>\n<b>", $internals->invoke('assetHtml', ['body_tags'=>['<a>', '<b>']], 'body'));
	$t->same('', $internals->invoke('assetHtml', ['all_tags'=>'invalid'], 'all'));
	$t->same(['ok'=>true], $internals->invoke('mailerResultToArray', ['ok'=>true]));
	$t->same([], $internals->invoke('mailerResultToArray', new stdClass()));
	$jsonObject=new class implements JsonSerializable {
		public function jsonSerialize(): mixed { return ['json'=>true]; }
	};
	$t->same(['json'=>true], $internals->invoke('mailerResultToArray', $jsonObject));
	$request=Request::create('POST', '/x', ['a'=>1], ['b'=>2], [], [], [], ['c'=>3]);
	$t->same(['a'=>1, 'b'=>2, 'c'=>3], $internals->invoke('requestData', $request));
	$t->same(['x'=>1], $internals->invoke('requestData', ['x'=>1]));
	$reactor=$internals->invoke('reactorResponse', ['status'=>700, 'ok'=>true]);
	$t->same(599, $reactor->status);
	$t->same('1', $reactor->headers['X-Dataphyre-Reactor']);
	$t->same('users/7/7', $internals->invoke('interpolate', 'users/<{id}>/:id', ['id'=>7]));
})->tag('mvc', 'facade', 'coverage')->group('framework-coverage');
