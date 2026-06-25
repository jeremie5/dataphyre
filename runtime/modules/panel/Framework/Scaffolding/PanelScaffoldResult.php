<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Represents one generated Panel scaffold artifact.
 *
 * Scaffold results separate artifact planning from filesystem mutation. The
 * object carries the normalized identity, target path, generated contents, and
 * metadata produced by scaffold builders; nothing is written until write() is
 * called. This lets previews, tests, and package installers inspect generated
 * resources without touching disk.
 */
final class PanelScaffoldResult implements \JsonSerializable, \Stringable {

	/**
	 * Stores the generated artifact payload.
	 *
	 * The constructor preserves values exactly as supplied. Use make() when
	 * accepting external scaffold definitions so artifact kind/name/class/path
	 * normalization is applied consistently.
	 *
	 * @param readonly string $kind Artifact category such as resource, page, or artifact.
	 * @param readonly string $name Normalized artifact name.
	 * @param readonly string $class Fully-qualified PHP class name without a leading slash.
	 * @param readonly string $path Target file path selected by the scaffold planner.
	 * @param readonly string $contents Generated file contents.
	 * @param readonly array<string,mixed> $metadata Serializable planner metadata for diagnostics or UI.
	 */
	public function __construct(
		private readonly string $kind,
		private readonly string $name,
		private readonly string $class,
		private readonly string $path,
		private readonly string $contents,
		private readonly array $metadata=[]
	){}

	/**
	 * Creates a normalized scaffold result from planner output.
	 *
	 * Empty kind and name values fall back to "artifact" so downstream
	 * manifests always have stable keys. The class name is trimmed and stored
	 * without a leading slash because generated namespace declarations and
	 * references compose that prefix themselves.
	 *
	 * @param string $kind Artifact category from the scaffold command.
	 * @param string $name Artifact machine name.
	 * @param string $class PHP class name represented by the artifact.
	 * @param string $path Planned filesystem target.
	 * @param string $contents Generated source or asset contents.
	 * @param array<string,mixed> $metadata Serializable metadata describing the scaffold decision.
	 * @return self Normalized scaffold result ready for preview or writing.
	 */
	public static function make(string $kind, string $name, string $class, string $path, string $contents, array $metadata=[]): self {
		return new self(
			Resource::normalizeName($kind) ?: 'artifact',
			Resource::normalizeName($name) ?: 'artifact',
			ltrim(trim($class), '\\'),
			trim($path),
			$contents,
			$metadata
		);
	}

	/**
	 * Returns the artifact category.
	 *
	 * @return string Normalized kind used by scaffold summaries and JSON payloads.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Returns the artifact machine name.
	 *
	 * @return string Normalized name used for lookup, logging, and display labels.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the PHP class represented by the artifact.
	 *
	 * @return string Class name without a leading namespace separator.
	 */
	public function class(): string {
		return $this->class;
	}

	/**
	 * Returns the planned write target.
	 *
	 * @return string Filesystem path selected by the scaffold planner.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Returns the generated artifact contents.
	 *
	 * @return string Full source or asset body that write() will persist.
	 */
	public function contents(): string {
		return $this->contents;
	}

	/**
	 * Returns scaffold metadata without mutating it.
	 *
	 * Metadata is intentionally untyped at the top level so scaffold builders
	 * can include source module, namespace, route, template, prompt, or package
	 * context while keeping the main artifact payload compact.
	 *
	 * @return array<string,mixed> Serializable metadata emitted by the scaffold planner.
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Writes the generated artifact to disk.
	 *
	 * This is the only filesystem side effect on the value object. The target
	 * may be overridden at call time, parent directories are created with group
	 * writable permissions, and existing files are protected unless overwrite is
	 * explicitly enabled.
	 *
	 * @param ?string $path Optional write target overriding the planned path.
	 * @param bool $overwrite Whether an existing target file may be replaced.
	 * @return self Same scaffold result after successful persistence.
	 * @throws \InvalidArgumentException When no target path is available.
	 * @throws \RuntimeException When the file exists, a directory cannot be created, or the write fails.
	 */
	public function write(?string $path=null, bool $overwrite=false): self {
		$target=trim((string)($path ?? $this->path));
		if($target===''){
			throw new \InvalidArgumentException('Panel scaffold write target cannot be empty.');
		}
		if(is_file($target) && !$overwrite){
			throw new \RuntimeException("Panel scaffold target already exists: {$target}");
		}
		$directory=dirname($target);
		if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){
			throw new \RuntimeException("Unable to create Panel scaffold directory: {$directory}");
		}
		if(file_put_contents($target, $this->contents)===false){
			throw new \RuntimeException("Unable to write Panel scaffold artifact: {$target}");
		}
		return $this;
	}

	/**
	 * Serializes the scaffold result without embedding full file contents.
	 *
	 * The payload reports byte length instead of contents so logs, API
	 * previews, and package audits can describe large generated files without
	 * duplicating source text or leaking generated secrets.
	 *
	 * @return array{kind:string,name:string,class:string,path:string,bytes:int,metadata:array<string,mixed>}
	 */
	public function jsonSerialize(): array {
		return [
			'kind'=>$this->kind,
			'name'=>$this->name,
			'class'=>$this->class,
			'path'=>$this->path,
			'bytes'=>strlen($this->contents),
			'metadata'=>$this->metadata,
		];
	}

	/**
	 * Returns the generated contents for string contexts.
	 *
	 * This supports preview renderers and tests that compare the result object
	 * directly with the expected generated file body.
	 *
	 * @return string Generated artifact contents.
	 */
	public function __toString(): string {
		return $this->contents;
	}
}
