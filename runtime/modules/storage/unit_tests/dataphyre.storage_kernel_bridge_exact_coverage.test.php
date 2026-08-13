<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists(__NAMESPACE__.'\\dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults): array {
			if(!defined($constant)){
				define($constant, $defaults);
			}
			return constant($constant);
		}
	}
	if(!class_exists(core::class, false)){
		final class core {
			public static function load_framework_module(string $module): bool {
				return (bool)\Dataphyre\Test\TestState::channel('storage.kernel-bridge')->get('module_loaded', true);
			}
		}
	}
}

namespace Dataphyre\Storage {
	final class KernelBridgeValue {
		public function __construct(private array $value=['stub'=>true]) {}
		public function toArray(): array { return $this->value; }
	}

	final class Storage {
		public static function __callStatic(string $name, array $arguments): mixed {
			\Dataphyre\Test\TestState::channel('storage.kernel-bridge')->append('calls', [$name, $arguments]);
			if(in_array($name, [
				'exists', 'put', 'putFile', 'putUploadedFile', 'delete', 'copy', 'move',
				'completeMultipartUpload', 'abortMultipartUpload', 'restoreVersion', 'purgeVersion',
				'setRetention', 'releaseRetention', 'tagObject',
			], true)){
				return true;
			}
			if(in_array($name, ['get', 'checksum', 'temporaryUrl', 'temporaryUploadUrl'], true)){
				return 'stub-value';
			}
			if($name==='readStream'){
				return fopen('php://temp', 'r+');
			}
			if($name==='metadata'){
				return new KernelBridgeValue(['path'=>'fixture.txt']);
			}
			if(in_array($name, ['list', 'findByTags'], true)){
				return [new KernelBridgeValue(['path'=>'fixture.txt'])];
			}
			if($name==='disk'){
				return new \stdClass();
			}
			if($name==='fakeFlush'){
				return null;
			}
			return ['ok'=>true, 'method'=>$name];
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use dataphyre\core;
	use dataphyre\storage;
	use function Dataphyre\Test\test;

	$dp_storage_kernel_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/storage/kernel/storage.main.php';
	require_once $dp_storage_kernel_root;

	/** @return array<int,mixed> */
	function dp_storage_kernel_bridge_arguments(ReflectionFunctionAbstract $method): array {
		$arguments=[];
		foreach($method->getParameters() as $parameter){
			$type=$parameter->getType();
			$typeName=$type instanceof ReflectionNamedType ? $type->getName() : null;
			$name=$parameter->getName();
			if($typeName==='array' || $type instanceof ReflectionUnionType){
				$arguments[]=$name==='parts' && $method->getName()==='temporary_multipart_upload_urls' ? 2 : ($name==='expires' ? 60 : []);
				continue;
			}
			if($typeName==='int'){
				$arguments[]=2;
				continue;
			}
			if($typeName==='string'){
				$arguments[]=match($name){
					'prefix'=>'',
					'algorithm'=>'sha256',
					default=>'fixture',
				};
				continue;
			}
			$arguments[]=null;
		}
		return $arguments;
	}

	test('storage kernel bridge covers configuration and every available and unavailable delegate', static function(Context $t): void {
		$bridge=$t->state('storage.kernel-bridge', ['module_loaded'=>true, 'calls'=>[]]);
		$t->isTrue(is_array(storage::config()));
		$t->same('local', storage::config('default_disk'));
		$t->same('local', storage::config('disks.local.driver'));
		$t->same('fallback', storage::config('disks.missing.driver', 'fallback'));
		$t->same('fallback', storage::config('default_disk.child', 'fallback'));

		$inventory=$t->inventory(storage::class);
		$methods=array_values(array_filter(
			$inventory->publicMethods(static:true),
			static fn($method): bool=>$method->getName()!=='config'
		));
		foreach($methods as $method){
			$result=$inventory->invokeWithArguments($method, null, dp_storage_kernel_bridge_arguments($method));
			if(is_resource($result)){
				fclose($result);
			}
		}
		$t->same(count($methods), count($bridge->get('calls')));

		$bridge->put('module_loaded', false);
		foreach($methods as $method){
			$result=$inventory->invokeWithArguments($method, null, dp_storage_kernel_bridge_arguments($method));
			$t->isFalse(is_resource($result));
		}
	})->tag('storage', 'kernel-bridge', 'coverage')->group('framework-coverage');
}
