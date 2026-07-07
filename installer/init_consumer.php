#!/usr/bin/env php
<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
	fwrite(STDERR, "Dataphyre consumer initializer must be run from the command line.\n");
	exit(1);
}

$arguments=$_SERVER['argv'];
array_shift($arguments);
if(in_array('help', $arguments, true) || in_array('--help', $arguments, true) || in_array('-h', $arguments, true)){
	dataphyre_consumer_init_help();
	exit(0);
}

try{
	$options=dataphyre_consumer_init_options($arguments);
	$initializer=new DataphyreConsumerInitializer($options);
	$initializer->run();
}
catch(Throwable $exception){
	fwrite(STDERR, "Dataphyre consumer initialization failed: ".$exception->getMessage()."\n");
	exit(1);
}

final class DataphyreConsumerInitializer {

	private string $package_root;
	private string $consumer_root;
	private bool $force;

	/**
	 * @param array<string, mixed> $options Parsed CLI options.
	 */
	public function __construct(private array $options) {
		$this->package_root=$this->normalizePath(dirname(__DIR__));
		$this->consumer_root=$this->resolveConsumerRoot((string)($options['root'] ?? ''));
		$this->force=($options['force'] ?? false)===true;
	}

	public function run(): void {
		$this->assertPackageShape();
		$this->assertConsumerRoot();
		$created=[];
		$this->copyFile('examples/minimal/flight_sheet.example.php', 'flight_sheet.php', $created);
		$this->copyFile('examples/minimal/index.example.php', 'index.php', $created);
		$this->copyDirectory('examples/minimal/applications', 'applications', $created);
		echo json_encode([
			'ok'=>true,
			'consumer_root'=>$this->consumer_root,
			'package_root'=>$this->package_root,
			'created'=>$created,
		], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
	}

	private function resolveConsumerRoot(string $root): string {
		if($root===''){
			$root=getcwd() ?: dirname(__DIR__, 3);
		}
		if(!is_dir($root)){
			if(!mkdir($root, 0775, true) && !is_dir($root)){
				throw new RuntimeException("Unable to create consumer root: {$root}");
			}
		}
		return $this->normalizePath($root);
	}

	private function assertPackageShape(): void {
		foreach(['runtime/bootstrap.php', 'examples/minimal/index.example.php', 'examples/minimal/flight_sheet.example.php', 'examples/minimal/applications/example_app/app.php'] as $relative){
			if(!is_file($this->package_root.'/'.$relative)){
				throw new RuntimeException("Package is missing {$relative}.");
			}
		}
	}

	private function assertConsumerRoot(): void {
		if($this->samePath($this->consumer_root, $this->package_root)){
			throw new RuntimeException('Refusing to initialize the Dataphyre package directory as the consumer project.');
		}
		if(str_starts_with($this->consumer_root.'/', $this->package_root.'/')){
			throw new RuntimeException('Refusing to initialize a consumer project inside the Dataphyre package directory.');
		}
	}

	/**
	 * @param array<int, string> $created Relative paths initialized by this run.
	 */
	private function copyFile(string $source_relative, string $target_relative, array &$created): void {
		$source=$this->package_root.'/'.$source_relative;
		$target=$this->consumer_root.'/'.$target_relative;
		$this->assertWritableTarget($target_relative, $target);
		$directory=dirname($target);
		if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){
			throw new RuntimeException("Unable to create directory: {$directory}");
		}
		if(!copy($source, $target)){
			throw new RuntimeException("Unable to copy {$target_relative}.");
		}
		$created[]=$target_relative;
	}

	/**
	 * @param array<int, string> $created Relative paths initialized by this run.
	 */
	private function copyDirectory(string $source_relative, string $target_relative, array &$created): void {
		$source=$this->package_root.'/'.$source_relative;
		if(!is_dir($source)){
			throw new RuntimeException("Package is missing {$source_relative}.");
		}
		$source=$this->normalizePath($source);
		$iterator=new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach($iterator as $item){
			$relative=str_replace('\\', '/', substr($item->getPathname(), strlen($source)+1));
			$target_path=$this->consumer_root.'/'.$target_relative.'/'.$relative;
			if($item->isDir()){
				if(!is_dir($target_path) && !mkdir($target_path, 0775, true) && !is_dir($target_path)){
					throw new RuntimeException("Unable to create directory: {$target_relative}/{$relative}");
				}
				continue;
			}
			$destination_relative=$target_relative.'/'.$relative;
			$this->assertWritableTarget($destination_relative, $target_path);
			$directory=dirname($target_path);
			if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){
				throw new RuntimeException("Unable to create directory: {$directory}");
			}
			if(!copy($item->getPathname(), $target_path)){
				throw new RuntimeException("Unable to copy {$destination_relative}.");
			}
			$created[]=$destination_relative;
		}
	}

	private function assertWritableTarget(string $relative, string $target): void {
		if(is_file($target) && !$this->force){
			throw new RuntimeException("Target already exists: {$relative}. Use --force to replace it.");
		}
	}

	private function normalizePath(string $path): string {
		$real=realpath($path);
		$path=$real!==false ? $real : $path;
		return rtrim(str_replace('\\', '/', $path), '/');
	}

	private function samePath(string $a, string $b): bool {
		return $this->normalizePath($a)===$this->normalizePath($b);
	}
}

/**
 * @param array<int, string> $arguments Raw CLI arguments.
 * @return array<string, mixed>
 */
function dataphyre_consumer_init_options(array $arguments): array {
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

function dataphyre_consumer_init_help(): void {
	echo <<<TXT
Dataphyre consumer initializer

Usage:
  php vendor/dataphyre/dataphyre/installer/init_consumer.php [--root=PATH] [--force]

Creates the minimal Dataphyre consumer project files outside vendor:
  flight_sheet.php
  index.php
  applications/example_app/

Options:
  --root=PATH  Consumer project root. Defaults to the current directory.
  --force      Replace existing initialized files.

TXT;
}
