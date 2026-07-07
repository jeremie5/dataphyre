#!/usr/bin/env php
<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
	fwrite(STDERR, "Dataphyre installer must be run from the command line.\n");
	exit(1);
}

$arguments=$_SERVER['argv'];
array_shift($arguments);
$command=$arguments[0] ?? 'help';
if($command==='help' || $command==='--help' || $command==='-h'){
	dataphyre_installer_help();
	exit(0);
}
array_shift($arguments);
$options=dataphyre_installer_options($arguments);

try{
	$installer=new DataphyreInstaller($options);
	$installer->run($command);
}
catch(Throwable $exception){
	fwrite(STDERR, "Dataphyre installer failed: ".$exception->getMessage()."\n");
	exit(1);
}

final class DataphyreInstaller {

	private string $project_root;
	private string $dataphyre_root;
	private array $project;
	private array $manifest;

	public function __construct(private array $options) {
		$this->dataphyre_root=dirname(__DIR__);
		$this->project_root=$this->resolveProjectRoot($options['root'] ?? null);
		$this->project=$this->readJson($this->project_root.'/dataphyre.project.json');
		$manifest_path=$this->project_root.'/'.$this->project['framework']['manifest_path'];
		$this->manifest=is_file($manifest_path)
			? $this->readJson($manifest_path)
			: $this->readJson($this->dataphyre_root.'/dataphyre.manifest.json');
	}

	public function run(string $command): void {
		match($command){
			'init'=>$this->init(),
			'install'=>$this->install(false),
			'update'=>$this->install(true),
			'lock'=>$this->lock(),
			'verify', 'check'=>$this->verify(),
			'doctor'=>$this->doctor(),
			default=>throw new RuntimeException("Unknown command '{$command}'. Run help for usage."),
		};
	}

	private function init(): void {
		$project_path=$this->project_root.'/dataphyre.project.json';
		if(is_file($project_path) && empty($this->options['force'])){
			throw new RuntimeException('dataphyre.project.json already exists. Use --force to replace it.');
		}
		$this->writeJson($project_path, [
			'schema_version'=>1,
			'layout'=>'standalone',
			'framework'=>[
				'install_path'=>'common/dataphyre',
				'manifest_path'=>'common/dataphyre/dataphyre.manifest.json',
				'lock_path'=>'dataphyre.lock',
				'source'=>['type'=>'git', 'repo'=>'git@github.com:dataphyre/dataphyre.git', 'ref'=>'main'],
				'managed_paths'=>['common/dataphyre'],
				'protected_paths'=>['applications'],
			],
			'applications'=>[
				'registry_path'=>'applications/dataphyre.apps.json',
				'default_repository_visibility'=>'private',
				'dependency_model'=>'applications_include_dependencies_from_other_applications',
			],
			'checks'=>['mode'=>'explicit_cli', 'runtime_request_checks'=>false],
		]);
		echo "Initialized dataphyre.project.json\n";
	}

	private function install(bool $is_update): void {
		$this->assertProjectPolicy();
		$source_root=$this->resolveSourceRoot();
		$source_manifest_path=$source_root.'/dataphyre.manifest.json';
		$source_manifest=is_file($source_manifest_path) ? $this->readJson($source_manifest_path) : $this->manifest;
		$exports=$source_manifest['exports'] ?? [];
		foreach($exports as $export){
			$from=$this->cleanRelativePath((string)($export['from'] ?? ''));
			$to=$this->cleanRelativePath((string)($export['to'] ?? ''));
			$this->assertExportAllowed($to);
			$source=$source_root.($from==='.' ? '' : '/'.$from);
			$target=$this->project_root.'/'.$to;
			if($this->samePath($source, $target)){
				continue;
			}
			$this->pruneTree($source, $target, $source_manifest['exclude'] ?? []);
			$this->copyTree($source, $target, $source_manifest['exclude'] ?? []);
		}
		$this->lock();
		echo ($is_update ? "Updated" : "Installed")." Dataphyre and refreshed dataphyre.lock\n";
	}

	private function lock(): void {
		$this->assertProjectPolicy();
		$this->verifyApplications();
		$install_path=$this->cleanRelativePath((string)$this->project['framework']['install_path']);
		$tree=$this->hashTree($this->project_root.'/'.$install_path, $this->manifest['exclude'] ?? []);
		$manifest_path=$this->cleanRelativePath((string)$this->project['framework']['manifest_path']);
		$registry_path=$this->cleanRelativePath((string)($this->project['applications']['registry_path'] ?? ''));
		$lock=[
			'schema_version'=>1,
			'locked_by'=>'dataphyre-installer',
			'locked_at'=>gmdate('c'),
			'project_layout'=>$this->project['layout'] ?? null,
			'framework'=>[
				'install_path'=>$install_path,
				'source'=>$this->project['framework']['source'] ?? null,
				'manifest_path'=>$manifest_path,
				'manifest_hash'=>hash_file('sha256', $this->project_root.'/'.$manifest_path),
				'tree'=>$tree,
			],
			'applications'=>[
				'registry_path'=>$registry_path,
				'registry_hash'=>is_file($this->project_root.'/'.$registry_path) ? hash_file('sha256', $this->project_root.'/'.$registry_path) : null,
			],
			'checks'=>[
				'mode'=>'explicit_cli',
				'runtime_request_checks'=>false,
			],
		];
		$this->writeJson($this->project_root.'/'.$this->project['framework']['lock_path'], $lock);
		echo "Locked Dataphyre: {$tree['files']} files, {$tree['bytes']} bytes, tree {$tree['hash']}\n";
	}

	private function verify(): void {
		$this->assertProjectPolicy();
		$this->verifyApplications();
		$lock_path=$this->project_root.'/'.$this->project['framework']['lock_path'];
		if(!is_file($lock_path)){
			throw new RuntimeException('Missing dataphyre.lock. Run lock first.');
		}
		$lock=$this->readJson($lock_path);
		$install_path=$this->cleanRelativePath((string)$this->project['framework']['install_path']);
		$current_tree=$this->hashTree($this->project_root.'/'.$install_path, $this->manifest['exclude'] ?? []);
		$locked_tree=$lock['framework']['tree'] ?? null;
		if(!is_array($locked_tree) || ($locked_tree['hash'] ?? null)!==$current_tree['hash']){
			throw new RuntimeException('Dataphyre tree does not match dataphyre.lock. Run lock after intentional installer/update changes.');
		}
		$manifest_path=$this->cleanRelativePath((string)$this->project['framework']['manifest_path']);
		$manifest_hash=hash_file('sha256', $this->project_root.'/'.$manifest_path);
		if(($lock['framework']['manifest_hash'] ?? null)!==$manifest_hash){
			throw new RuntimeException('Dataphyre manifest hash does not match dataphyre.lock. Run lock after intentional manifest changes.');
		}
		$registry_path=$this->cleanRelativePath((string)($this->project['applications']['registry_path'] ?? ''));
		if($registry_path!==''){
			$registry_hash=hash_file('sha256', $this->project_root.'/'.$registry_path);
			if(($lock['applications']['registry_hash'] ?? null)!==$registry_hash){
				throw new RuntimeException('Application registry hash does not match dataphyre.lock. Run lock after intentional app repo changes.');
			}
		}
		echo "Verified Dataphyre lock: {$current_tree['files']} files, {$current_tree['bytes']} bytes, tree {$current_tree['hash']}\n";
	}

	private function doctor(): void {
		$this->assertProjectPolicy();
		$this->verifyApplications();
		$install_path=$this->cleanRelativePath((string)$this->project['framework']['install_path']);
		$tree=$this->hashTree($this->project_root.'/'.$install_path, $this->manifest['exclude'] ?? []);
		echo json_encode([
			'project_root'=>$this->project_root,
			'install_path'=>$install_path,
			'checks'=>'explicit_cli',
			'runtime_request_checks'=>false,
			'tree'=>$tree,
		], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
	}

	private function assertProjectPolicy(): void {
		$framework=$this->project['framework'] ?? [];
		$install_path=$this->cleanRelativePath((string)($framework['install_path'] ?? ''));
		if($install_path!=='common/dataphyre'){
			throw new RuntimeException('Dataphyre framework install_path must be common/dataphyre for this layout.');
		}
		foreach(($framework['managed_paths'] ?? []) as $managed_path){
			$managed_path=$this->cleanRelativePath((string)$managed_path);
			if($managed_path!=='common/dataphyre'){
				throw new RuntimeException("Managed path '{$managed_path}' is not framework-owned.");
			}
		}
		foreach(($this->manifest['exports'] ?? []) as $export){
			$this->assertExportAllowed((string)($export['to'] ?? ''));
		}
		if(($this->project['checks']['runtime_request_checks'] ?? null)!==false){
			throw new RuntimeException('Installer checks must remain explicit CLI checks, not runtime request checks.');
		}
	}

	private function assertExportAllowed(string $target): void {
		$target=$this->cleanRelativePath($target);
		if($target!=='common/dataphyre'){
			throw new RuntimeException("Dataphyre export target '{$target}' is outside the framework common path.");
		}
		foreach(($this->project['framework']['protected_paths'] ?? []) as $protected){
			$protected=$this->cleanRelativePath((string)$protected);
			if($target===$protected || str_starts_with($target.'/', $protected.'/')){
				throw new RuntimeException("Dataphyre export target '{$target}' intersects protected path '{$protected}'.");
			}
		}
	}

	private function verifyApplications(): void {
		$registry_path=$this->cleanRelativePath((string)($this->project['applications']['registry_path'] ?? ''));
		if($registry_path===''){
			return;
		}
		$registry=$this->readJson($this->project_root.'/'.$registry_path);
		if(($registry['policy']['installer_may_modify_application_roots'] ?? true)!==false){
			throw new RuntimeException('Application registry must keep installer_may_modify_application_roots=false.');
		}
		if(($registry['policy']['no_non_framework_common'] ?? false)!==true){
			throw new RuntimeException('Application registry must set no_non_framework_common=true.');
		}
		$names=[];
		foreach(($registry['applications'] ?? []) as $app){
			$name=(string)($app['name'] ?? '');
			$path=$this->cleanRelativePath((string)($app['path'] ?? ''));
			if($name==='' || $path===''){
				throw new RuntimeException('Application registry entries require name and path.');
			}
			if(isset($names[$name])){
				throw new RuntimeException("Duplicate application registry name '{$name}'.");
			}
			$names[$name]=true;
			if(!is_dir($this->project_root.'/'.$path)){
				throw new RuntimeException("Application path does not exist: {$path}");
			}
			if(($app['private_repository'] ?? false)!==true){
				throw new RuntimeException("Application '{$name}' must be marked private_repository=true.");
			}
			$repository=(string)($app['repository'] ?? '');
			if(!str_starts_with($repository, 'git@github.com:')){
				throw new RuntimeException("Application '{$name}' must use a private GitHub SSH repository target.");
			}
			$manifest_path=$this->project_root.'/'.$path.'/dataphyre.app.json';
			if(!is_file($manifest_path)){
				throw new RuntimeException("Application '{$name}' is missing dataphyre.app.json.");
			}
			$app_manifest=$this->readJson($manifest_path);
			if(($app_manifest['name'] ?? null)!==$name){
				throw new RuntimeException("Application '{$name}' manifest name does not match registry.");
			}
			if(($app_manifest['repository']['url'] ?? null)!==$repository){
				throw new RuntimeException("Application '{$name}' manifest repository does not match registry.");
			}
			if(($app_manifest['repository']['visibility'] ?? null)!=='private'){
				throw new RuntimeException("Application '{$name}' manifest repository visibility must be private.");
			}
			if(!is_file($this->project_root.'/'.$path.'/.gitignore')){
				throw new RuntimeException("Application '{$name}' is missing a private-repo .gitignore.");
			}
		}
		foreach(($registry['applications'] ?? []) as $app){
			foreach(($app['depends_on'] ?? []) as $dependency){
				if(!isset($names[$dependency])){
					throw new RuntimeException("Application '{$app['name']}' depends on unknown application '{$dependency}'.");
				}
			}
			$path=$this->cleanRelativePath((string)($app['path'] ?? ''));
			$app_manifest=$this->readJson($this->project_root.'/'.$path.'/dataphyre.app.json');
			foreach(($app_manifest['dependencies'] ?? []) as $dependency){
				$dependency_name=(string)($dependency['application'] ?? '');
				if($dependency_name==='' || !isset($names[$dependency_name])){
					throw new RuntimeException("Application '{$app['name']}' manifest depends on unknown application '{$dependency_name}'.");
				}
			}
		}
	}

	private function resolveSourceRoot(): string {
		if(isset($this->options['source'])){
			$source=$this->normalizePath((string)$this->options['source']);
			if(!is_dir($source)){
				throw new RuntimeException("Source path does not exist: {$source}");
			}
			return $source;
		}
		$source=$this->project['framework']['source'] ?? [];
		if(empty($this->options['local']) && (($source['type'] ?? null)==='git' || isset($this->options['repo']) || isset($this->options['ref']))){
			$repo=(string)($this->options['repo'] ?? ($source['repo'] ?? ''));
			$ref=(string)($this->options['ref'] ?? ($source['ref'] ?? 'main'));
			if($repo===''){
				throw new RuntimeException('Missing --repo or project framework source repo.');
			}
			$tmp=sys_get_temp_dir().'/dataphyre-install-'.bin2hex(random_bytes(6));
			$this->runProcess(['git', 'clone', '--depth', '1', '--branch', $ref, $repo, $tmp]);
			return $tmp;
		}
		return $this->dataphyre_root;
	}

	private function copyTree(string $source, string $target, array $excludes): void {
		if(!is_dir($source)){
			throw new RuntimeException("Export source does not exist: {$source}");
		}
		if(!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)){
			throw new RuntimeException("Unable to create target directory: {$target}");
		}
		$source=$this->normalizePath($source);
		$target=$this->normalizePath($target);
		$iterator=new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach($iterator as $item){
			$relative=str_replace('\\', '/', substr($item->getPathname(), strlen($source)+1));
			if($this->isExcluded($relative, $excludes)){
				continue;
			}
			$destination=$target.'/'.$relative;
			if($item->isDir()){
				if(!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)){
					throw new RuntimeException("Unable to create directory: {$destination}");
				}
				continue;
			}
			if(!copy($item->getPathname(), $destination)){
				throw new RuntimeException("Unable to copy {$relative}");
			}
		}
	}

	private function pruneTree(string $source, string $target, array $excludes): void {
		if(!is_dir($target)){
			return;
		}
		$source=$this->normalizePath($source);
		$target=$this->normalizePath($target);
		if(!str_starts_with($target.'/', $this->project_root.'/common/dataphyre/')){
			throw new RuntimeException("Refusing to prune outside managed Dataphyre path: {$target}");
		}
		$expected_files=[];
		if(is_dir($source)){
			$source_iterator=new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach($source_iterator as $source_file){
				if(!$source_file->isFile()){
					continue;
				}
				$relative=str_replace('\\', '/', substr($source_file->getPathname(), strlen($source)+1));
				if(!$this->isExcluded($relative, $excludes)){
					$expected_files[$relative]=true;
				}
			}
		}
		$target_iterator=new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($target_iterator as $target_item){
			$relative=str_replace('\\', '/', substr($target_item->getPathname(), strlen($target)+1));
			if($this->isExcluded($relative, $excludes)){
				continue;
			}
			if($target_item->isFile() && !isset($expected_files[$relative])){
				if(!unlink($target_item->getPathname())){
					throw new RuntimeException("Unable to remove stale Dataphyre file: {$relative}");
				}
				continue;
			}
			if($target_item->isDir()){
				$items=scandir($target_item->getPathname());
				if($items!==false && count(array_diff($items, ['.', '..']))===0){
					@rmdir($target_item->getPathname());
				}
			}
		}
	}

	private function hashTree(string $root, array $excludes): array {
		if(!is_dir($root)){
			throw new RuntimeException("Hash root does not exist: {$root}");
		}
		$root=$this->normalizePath($root);
		$files=[];
		$bytes=0;
		$iterator=new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach($iterator as $file){
			if(!$file->isFile()){
				continue;
			}
			$relative=str_replace('\\', '/', substr($file->getPathname(), strlen($root)+1));
			if($this->isExcluded($relative, $excludes)){
				continue;
			}
			$size=$file->getSize();
			$bytes+=$size;
			$files[$relative]=hash_file('sha256', $file->getPathname()).':'.$size;
		}
		ksort($files, SORT_STRING);
		$context=hash_init('sha256');
		foreach($files as $relative=>$hash){
			hash_update($context, $relative."\0".$hash."\n");
		}
		return [
			'files'=>count($files),
			'bytes'=>$bytes,
			'hash'=>hash_final($context),
		];
	}

	private function isExcluded(string $relative, array $excludes): bool {
		$relative=$this->cleanRelativePath($relative);
		foreach($excludes as $exclude){
			$exclude=$this->cleanRelativePath((string)$exclude);
			if($exclude==='' || $exclude==='.'){
				continue;
			}
			if($relative===$exclude || str_starts_with($relative.'/', $exclude.'/')){
				return true;
			}
		}
		return false;
	}

	private function resolveProjectRoot(?string $root): string {
		if($root!==null){
			return $this->normalizePath($root);
		}
		$current=getcwd() ?: dirname(__DIR__, 3);
		while(true){
			if(is_file($current.'/dataphyre.project.json')){
				return $this->normalizePath($current);
			}
			$parent=dirname($current);
			if($parent===$current){
				break;
			}
			$current=$parent;
		}
		return $this->normalizePath(dirname(__DIR__, 3));
	}

	private function readJson(string $path): array {
		if(!is_file($path)){
			throw new RuntimeException("Missing JSON file: {$path}");
		}
		$data=json_decode((string)file_get_contents($path), true);
		if(!is_array($data)){
			throw new RuntimeException("Invalid JSON file: {$path}");
		}
		return $data;
	}

	private function writeJson(string $path, array $data): void {
		$json=json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
		if(file_put_contents($path, $json)===false){
			throw new RuntimeException("Unable to write JSON file: {$path}");
		}
	}

	private function cleanRelativePath(string $path): string {
		$path=str_replace('\\', '/', trim($path));
		$path=preg_replace('#/+#', '/', $path) ?? $path;
		$path=trim($path, '/');
		if($path===''){
			return '';
		}
		$parts=[];
		foreach(explode('/', $path) as $part){
			if($part==='' || $part==='.'){
				continue;
			}
			if($part==='..'){
				throw new RuntimeException("Parent path segments are not allowed: {$path}");
			}
			$parts[]=$part;
		}
		return implode('/', $parts);
	}

	private function normalizePath(string $path): string {
		$real=realpath($path);
		return str_replace('\\', '/', $real!==false ? $real : $path);
	}

	private function samePath(string $a, string $b): bool {
		return rtrim($this->normalizePath($a), '/')===rtrim($this->normalizePath($b), '/');
	}

	private function runProcess(array $command): void {
		$descriptor=[1=>['pipe', 'w'], 2=>['pipe', 'w']];
		$process=proc_open($command, $descriptor, $pipes, $this->project_root);
		if(!is_resource($process)){
			throw new RuntimeException('Unable to start process: '.implode(' ', $command));
		}
		$output=stream_get_contents($pipes[1]);
		$error=stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit=proc_close($process);
		if($exit!==0){
			throw new RuntimeException(trim($error ?: $output) ?: ('Process failed: '.implode(' ', $command)));
		}
	}
}

function dataphyre_installer_options(array $arguments): array {
	$options=[];
	foreach($arguments as $argument){
		if(!str_starts_with($argument, '--')){
			continue;
		}
		$argument=substr($argument, 2);
		if(str_contains($argument, '=')){
			[$key, $value]=explode('=', $argument, 2);
			$options[$key]=$value;
			continue;
		}
		$options[$argument]=true;
	}
	return $options;
}

function dataphyre_installer_help(): void {
	echo <<<TXT
Dataphyre installer

Usage:
  php common/dataphyre/installer/install.php init [--root=PATH] [--force]
  php common/dataphyre/installer/install.php lock [--root=PATH]
  php common/dataphyre/installer/install.php verify|check [--root=PATH]
  php common/dataphyre/installer/install.php install [--root=PATH] [--source=PATH]
  php common/dataphyre/installer/install.php update [--root=PATH] [--source=PATH]
  php common/dataphyre/installer/install.php doctor [--root=PATH]

Notes:
  - Installer checks are explicit CLI checks, not runtime request checks.
  - install/update clone the configured Git source unless --source=PATH or --local is passed.
  - This installer manages common/dataphyre only.
  - Application roots and non-framework common folders are protected.

TXT;
}
