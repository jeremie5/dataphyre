<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__,2).'/testing/tooling/TypeInventory.php';

if(!defined('DATAPHYRE_DPANEL_WORKER_FIXTURE_STATE_LOADED')){
	define('DATAPHYRE_DPANEL_WORKER_FIXTURE_STATE_LOADED', true);

	final class dataphyre_dpanel_worker_fixture_state {
		/** @var array<string,callable> */
		private static array $sql_responders=[];
		/** @var array<string,mixed> */
		private static array $sql_results=[];
		/** @var array<string,list<array<int,mixed>>> */
		private static array $sql_calls=[];

		public static function installDeterministicCoreConfig(): void {
			if(defined('DP_CORE_CFG')){
				return;
			}
			define('DP_CORE_CFG',[
				'private_key'=>['dataphyre-dpanel-worker-unit-test-key'],
				'encryption_version'=>0,
			]);
		}

		public static function resetSql(): void {
			self::$sql_responders=[];
			self::$sql_results=[];
			self::$sql_calls=[];
		}

		public static function respondToSql(string $operation, callable $responder): void {
			$operation=self::sqlOperation($operation);
			self::$sql_responders[$operation]=$responder;
			unset(self::$sql_results[$operation]);
		}

		public static function returnFromSql(string $operation, mixed $result): void {
			$operation=self::sqlOperation($operation);
			self::$sql_results[$operation]=$result;
			unset(self::$sql_responders[$operation]);
		}

		public static function clearSqlResponse(string $operation): void {
			$operation=self::sqlOperation($operation);
			unset(self::$sql_responders[$operation],self::$sql_results[$operation]);
		}

		/** @param array<int,mixed> $arguments */
		public static function dispatchSql(string $operation, array $arguments, mixed $default=false): mixed {
			$operation=self::sqlOperation($operation);
			self::$sql_calls[$operation][]=$arguments;
			if(isset(self::$sql_responders[$operation])){
				return (self::$sql_responders[$operation])(...$arguments);
			}
			if(array_key_exists($operation,self::$sql_results)){
				return self::$sql_results[$operation];
			}
			return $default;
		}

		/** @return list<array<int,mixed>> */
		public static function sqlCalls(string $operation): array {
			return self::$sql_calls[self::sqlOperation($operation)] ?? [];
		}

		/** @return array<int,mixed> */
		public static function sqlCall(string $operation, int $index=0): array {
			return self::sqlCalls($operation)[$index] ?? [];
		}

		public static function sqlCallCount(?string $operation=null): int {
			if($operation!==null){
				return count(self::sqlCalls($operation));
			}
			return array_sum(array_map('count',self::$sql_calls));
		}

		/** @param array<int,mixed> $arguments */
		public static function invokeNonPublic(object|string $target, string $method, array $arguments=[]): mixed {
			$reflection=new \ReflectionMethod($target,$method);
			$reflection->setAccessible(true);
			return $reflection->invokeArgs(is_object($target) ? $target : null,$arguments);
		}

		/** @param array<string,mixed> $properties */
		public static function replaceNonPublicProperties(object|string $target, array $properties): void {
			$class=is_object($target) ? $target::class : $target;
			foreach($properties as $property=>$value){
				$reflection=new \ReflectionProperty($class,$property);
				$reflection->setAccessible(true);
				$reflection->setValue(is_object($target) ? $target : null,$value);
			}
		}

		public static function inventory(object|string $target): \Dataphyre\Test\TypeInventory {
			return \Dataphyre\Test\TypeInventory::of($target);
		}

		private static function sqlOperation(string $operation): string {
			$operation=strtolower(trim($operation));
			if(!in_array($operation,['query','select','insert','update','delete'],true)){
				throw new \InvalidArgumentException('Unsupported Dpanel worker SQL fixture operation `'.$operation.'`.');
			}
			return $operation;
		}
	}

	final class dataphyre_dpanel_worker_application_state {
		public static function serverAddress(string $address): void {
			self::replaceServerValue('SERVER_ADDR',$address);
		}

		public static function remoteAddress(string $address): void {
			self::replaceServerValue('REMOTE_ADDR',$address);
		}

		public static function requestUri(string $uri): void {
			self::replaceServerValue('REQUEST_URI',$uri);
		}

		/** @return array<string,mixed> */
		public static function server(): array {
			return $_SERVER;
		}

		/** @param array<string,mixed> $server */
		public static function replaceServer(array $server): void {
			$_SERVER=$server;
		}

		public static function hasServer(string $key): bool {
			return array_key_exists(self::stateKey($key,'server'),$_SERVER);
		}

		public static function serverValue(string $key,mixed $default=null): mixed {
			$key=self::stateKey($key,'server');
			return $_SERVER[$key] ?? $default;
		}

		public static function replaceServerValue(string $key,mixed $value): void {
			$_SERVER[self::stateKey($key,'server')]=$value;
		}

		public static function forgetServer(string $key): void {
			unset($_SERVER[self::stateKey($key,'server')]);
		}

		/** @return array<string,mixed> */
		public static function query(): array {
			return $_GET;
		}

		/** @param array<string,mixed> $query */
		public static function replaceQuery(array $query): void {
			$_GET=$query;
		}

		/** @return array<string,mixed> */
		public static function cookies(): array {
			return $_COOKIE;
		}

		/** @param array<string,mixed> $cookies */
		public static function replaceCookies(array $cookies): void {
			$_COOKIE=$cookies;
		}

		public static function putCookie(string $key,mixed $value): void {
			$_COOKIE[self::stateKey($key,'cookie')]=$value;
		}

		public static function forgetCookie(string $key): void {
			unset($_COOKIE[self::stateKey($key,'cookie')]);
		}

		/** @param array<string,mixed> $session */
		public static function replaceSession(array $session): void {
			$_SESSION=$session;
		}

		/** @return array<string,mixed> */
		public static function session(): array {
			return $_SESSION;
		}

		public static function sessionHas(string $key): bool {
			return array_key_exists(self::stateKey($key,'session'),$_SESSION);
		}

		public static function sessionValue(string $key, mixed $default=null): mixed {
			$key=self::stateKey($key,'session');
			return $_SESSION[$key] ?? $default;
		}

		public static function putSession(string $key,mixed $value): void {
			$_SESSION[self::stateKey($key,'session')]=$value;
		}

		public static function forgetSession(string $key): void {
			unset($_SESSION[self::stateKey($key,'session')]);
		}

		public static function hasGlobal(string $name): bool {
			return array_key_exists(self::globalName($name),$GLOBALS);
		}

		public static function globalValue(string $name, mixed $default=null): mixed {
			$name=self::globalName($name);
			return $GLOBALS[$name] ?? $default;
		}

		public static function replaceGlobal(string $name, mixed $value): void {
			$GLOBALS[self::globalName($name)]=$value;
		}

		public static function forgetGlobal(string $name): void {
			unset($GLOBALS[self::globalName($name)]);
		}

		public static function authenticatedUserId(int $userid): void {
			self::replaceGlobal('userid',$userid);
		}

		public static function forgetAuthenticatedUserId(): void {
			self::forgetGlobal('userid');
		}

		private static function globalName(string $name): string {
			$name=trim($name);
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$name)!==1){
				throw new \InvalidArgumentException('Dpanel worker application globals require a PHP identifier.');
			}
			return $name;
		}

		private static function stateKey(string $key,string $kind): string {
			$key=trim($key);
			if($key===''){
				throw new \InvalidArgumentException('Dpanel worker '.$kind.' keys cannot be empty.');
			}
			return $key;
		}
	}

	final class dataphyre_dpanel_worker_workspace {
		private static ?self $active=null;

		private function __construct(private string $root) {}

		public static function activate(string $name='worker',?string $base=null): self {
			if(self::$active!==null){
				return self::$active;
			}
			$name=trim((string)preg_replace('/[^a-z0-9_-]+/i','-',trim($name)),'-_') ?: 'worker';
			$base=rtrim($base ?? sys_get_temp_dir(),'/\\');
			$root=$base.DIRECTORY_SEPARATOR.'dataphyre-dpanel-'.$name.'-'.bin2hex(random_bytes(6));
			if(!@mkdir($root,0777,true) && !is_dir($root)){
				throw new \RuntimeException('Unable to create Dpanel worker workspace: '.$root);
			}
			self::$active=new self(str_replace('\\','/',$root));
			register_shutdown_function([self::class,'cleanupActive']);
			return self::$active;
		}

		public static function active(): self {
			if(self::$active===null){
				throw new \RuntimeException('No Dpanel worker workspace is active for this test case.');
			}
			return self::$active;
		}

		public static function cleanupActive(): void {
			self::$active?->cleanup();
		}

		public function root(): string {
			return $this->root;
		}

		public function path(string $relative=''): string {
			$relative=str_replace('\\','/',$relative);
			if(str_contains($relative,"\0") || preg_match('~^(?:/|[A-Za-z]:/)~',$relative)===1){
				throw new \InvalidArgumentException('Dpanel worker workspace paths must be relative.');
			}
			$parts=[];
			foreach(explode('/',$relative) as $part){
				if($part==='' || $part==='.'){
					continue;
				}
				if($part==='..'){
					if($parts===[]){
						throw new \InvalidArgumentException('Dpanel worker workspace paths cannot escape their root.');
					}
					array_pop($parts);
					continue;
				}
				$parts[]=$part;
			}
			return $parts===[] ? $this->root : $this->root.'/'.implode('/',$parts);
		}

		public function directory(string $relative=''): string {
			$directory=$this->path($relative);
			if(!is_dir($directory) && !@mkdir($directory,0777,true) && !is_dir($directory)){
				throw new \RuntimeException('Unable to create Dpanel worker fixture directory: '.$directory);
			}
			return $directory;
		}

		public function file(string $relative,string $contents): string {
			$path=$this->path($relative);
			$this->directory(dirname(str_replace('\\','/',$relative)));
			if(@file_put_contents($path,$contents)===false){
				throw new \RuntimeException('Unable to write Dpanel worker fixture file: '.$path);
			}
			return $path;
		}

		public function removeFile(string $relative): bool {
			$path=$this->path($relative);
			return !is_file($path) || @unlink($path);
		}

		public function cleanup(): void {
			if(is_dir($this->root)){
				$iterator=new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),
					\RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach($iterator as $entry){
					$entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
				}
				@rmdir($this->root);
			}
			if(self::$active===$this){
				self::$active=null;
			}
		}
	}
}
