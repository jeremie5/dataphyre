<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class core {
		public static function load_framework_module(string $module): void {
			$state=\Dataphyre\Test\TestState::channel('api.support.loader');
			$state->increment('load_count');
			if($state->get('install_sanitation',false)===false || class_exists('Dataphyre\\Sanitation\\Sanitation', false)){
				return;
			}
			\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Sanitation;

final class SanitizationResult {
	public function __construct(private array $data){}
	public function validated(): array { return $this->data; }
	public function get(string $key, mixed $default=null): mixed { return $this->data[$key] ?? $default; }
}

final class Sanitation {
	public static function schema(array $data, array $schema, array $defaults, array $options): SanitizationResult {
		return new SanitizationResult(array_replace($defaults, $data));
	}
}
PHP);
		}
	}
}

namespace {
	use Dataphyre\Api\ApiContext;
	use Dataphyre\Http\Request;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	$dp_api_loader_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	require_once $dp_api_loader_modules_root.'/http/Framework/Request.php';
	require_once $dp_api_loader_modules_root.'/api/Framework/ApiContext.php';

	test('api context sanitation dependency covers failed and successful lazy loading', static function(Context $t): void {
		$state=$t->state('api.support.loader',['install_sanitation'=>false,'load_count'=>0]);
		$context=new ApiContext(Request::create('POST', '/support', [], ['name'=>'Ada']), []);
		$t->throws(
			static fn()=>$context->validate([], [], []),
			RuntimeException::class,
			'Dataphyre sanitation is required for API schema validation.'
		);
		$t->same(1,$state->get('load_count'));

		$state->put('install_sanitation',true);
		$result=$context->validate([], ['fallback'=>'yes'], []);
		$t->same(2,$state->get('load_count'));
		$t->same('Ada', $result->get('name'));
		$t->same('yes', $result->get('fallback'));

		$context->validate([], [], []);
		$t->same(2,$state->get('load_count'));
	})->tag('api', 'support', 'coverage')->group('framework-coverage');
}
