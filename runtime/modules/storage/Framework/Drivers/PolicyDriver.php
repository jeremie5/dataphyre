<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Drivers;

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Storage\Support\Path;

/**
 * Storage decorator that enforces path, action, extension, and actor policies.
 *
 * The policy driver delegates all allowed operations to a target disk while
 * denying disallowed operations locally. Rules are evaluated in order and the
 * last matching rule wins; if no rule matches, `default_allow` determines the
 * decision. Denied reads, writes, deletes, metadata lookups, listings, and
 * temporary URLs fail closed with the same false or empty-list shapes used by
 * storage drivers, and no denied operation reaches the target disk.
 */
final class PolicyDriver implements StorageDriver {

	/** @var string Target disk that owns object storage. */
	private string $disk;
	/** @var bool Fallback decision when no policy rule matches. */
	private bool $defaultAllow;
	/**
	 * Normalized policy rules in evaluation order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $rules;

	/**
	 * Initializes policy rules, fallback decision, and delegated target storage.
	 *
	 * `disk` or `target` selects the delegated storage disk. `default_allow`
	 * controls the no-match decision, and `rules` supplies allow/deny entries
	 * used by every operation. Rules may include `actions`, `prefix`,
	 * `extensions`, `actors`, and `effect`/`type`; unknown keys are preserved in
	 * diagnostics but ignored by the evaluator.
	 *
	 * @param array<string, mixed> $config Policy driver configuration.
	 * @param ?StorageManager $manager Storage manager used for target-disk delegation.
	 *
	 * @throws \RuntimeException When no target disk is configured.
	 */
	public function __construct(private array $config, private ?StorageManager $manager=null) {
		$this->manager ??= StorageManager::instance();
		$this->disk=(string)($config['disk'] ?? $config['target'] ?? '');
		$this->defaultAllow=(bool)($config['default_allow'] ?? true);
		$this->rules=$this->normalizeRules($config['rules'] ?? []);
		if($this->disk===''){
			throw new \RuntimeException('Policy storage disks require a target disk.');
		}
	}

	/**
	 * Checks target existence only when read policy allows the path.
	 *
	 * @param string $path Object path to check.
	 * @return bool True when read policy allows the path and the target object exists.
	 */
	public function exists(string $path): bool {
		return $this->allowed('read', $path) && $this->manager->exists($path, $this->disk);
	}

	/**
	 * Reads an object when read policy allows the path.
	 *
	 * Denied reads return false without touching the target disk.
	 *
	 * @param string $path Object path to read.
	 * @param array<string, mixed> $options Read options, including optional actor.
	 * @return string|false Object contents, or false when denied or target read fails.
	 */
	public function read(string $path, array $options=[]): string|false {
		return $this->allowed('read', $path, $options) ? $this->manager->get($path, $this->disk, $options) : false;
	}

	/**
	 * Opens a read stream when read policy allows the path.
	 *
	 * Denied stream reads return false without touching the target disk.
	 *
	 * @param string $path Object path to stream.
	 * @param array<string, mixed> $options Stream options, including optional actor.
	 * @return mixed Target stream resource/value, or false when denied.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		return $this->allowed('read', $path, $options) ? $this->manager->readStream($path, $this->disk, $options) : false;
	}

	/**
	 * Writes an object when write policy allows the path.
	 *
	 * Denied writes return false and do not mutate the target disk.
	 *
	 * @param string $path Object path to write.
	 * @param mixed $contents Contents accepted by the target driver.
	 * @param array<string, mixed> $options Write options, including optional actor.
	 * @return bool True when policy allows the write and the target write succeeds.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		return $this->allowed('write', $path, $options) ? $this->manager->put($path, $contents, $this->disk, $options) : false;
	}

	/**
	 * Deletes an object when delete policy allows the path.
	 *
	 * Denied deletes return false and do not mutate the target disk.
	 *
	 * @param string $path Object path to delete.
	 * @return bool True when policy allows the delete and the target delete succeeds.
	 */
	public function delete(string $path): bool {
		return $this->allowed('delete', $path) ? $this->manager->delete($path, $this->disk) : false;
	}

	/**
	 * Reads object metadata when metadata policy allows the path.
	 *
	 * Returned metadata is enriched with the delegated disk name under a `policy`
	 * extra entry so diagnostics can identify the protected target.
	 *
	 * @param string $path Object path whose metadata should be loaded.
	 * @return FileMetadata|false Metadata with policy extras, or false when denied or unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		if(!$this->allowed('metadata', $path)){
			return false;
		}
		$metadata=$this->manager->metadata($path, $this->disk);
		if(!$metadata instanceof FileMetadata){
			return false;
		}
		$extra=$metadata->extra();
		$extra['policy']=['disk'=>$this->disk];
		return new FileMetadata($metadata->path(), $metadata->size(), $metadata->modifiedAt(), $metadata->mimeType(), $extra);
	}

	/**
	 * Lists target objects when list policy allows the prefix.
	 *
	 * The target listing is additionally filtered through read policy for each
	 * returned path, preventing list operations from revealing denied objects.
	 *
	 * @param string $prefix Listing prefix.
	 * @param array<string, mixed> $options Listing options, including optional actor.
	 * @return array<int, FileMetadata> Visible metadata entries from the target disk.
	 */
	public function list(string $prefix='', array $options=[]): array {
		if(!$this->allowed('list', $prefix, $options)){
			return [];
		}
		return array_values(array_filter(
			$this->manager->list($prefix, $this->disk, $options),
			fn(FileMetadata $metadata): bool => $this->allowed('read', $metadata->path(), $options)
		));
	}

	/**
	 * Creates a temporary URL when URL policy allows the path.
	 *
	 * Denied URL requests return false and never ask the target disk to create a
	 * signed or temporary URL.
	 *
	 * @param string $path Object path to expose temporarily.
	 * @param int|\DateTimeInterface $expires Expiration timestamp or date object.
	 * @param array<string, mixed> $options URL options, including optional actor.
	 * @return string|false Temporary URL, or false when denied or unsupported.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		return $this->allowed('temporary_url', $path, $options) ? $this->manager->temporaryUrl($path, $expires, $this->disk, $options) : false;
	}

	/**
	 * Reports policy rules relevant to an optional path prefix.
	 *
	 * The report is diagnostic only; it does not evaluate a specific action.
	 * Prefix matching includes rules under the requested prefix and broader rules
	 * that may govern the requested prefix. The returned rule data is the
	 * normalized configuration currently used by the evaluator.
	 *
	 * @param string $prefix Optional path prefix to scope the report.
	 * @param array<string, mixed> $options Reserved diagnostic options.
	 * @return array<string,mixed> Policy report with target disk, default decision, and matched rules.
	 */
	public function policyReport(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$matched=[];
		foreach($this->rules as $index=>$rule){
			$rulePrefix=Path::normalize((string)($rule['prefix'] ?? ''));
			if($prefix!=='' && $rulePrefix!=='' && !str_starts_with($rulePrefix, $prefix) && !str_starts_with($prefix, $rulePrefix)){
				continue;
			}
			$matched[]=['index'=>$index, 'rule'=>$rule];
		}
		return [
			'ok'=>true,
			'disk'=>$this->disk,
			'default_allow'=>$this->defaultAllow,
			'rules'=>$matched,
		];
	}

	/**
	 * Evaluates the allow/deny decision for an action and path.
	 *
	 * Matching rules are processed in order and later matches replace earlier
	 * decisions. If no rule matches, the driver's default decision is used.
	 *
	 * @param string $action Storage action being attempted.
	 * @param string $path Object path being accessed.
	 * @param array<string, mixed> $options Operation options, including optional actor.
	 * @return bool True when the operation is allowed.
	 */
	private function allowed(string $action, string $path, array $options=[]): bool {
		$path=Path::normalize($path);
		$decision=null;
		foreach($this->rules as $rule){
			if(!$this->matches($rule, $action, $path, $options)){
				continue;
			}
			$decision=strtolower((string)($rule['effect'] ?? $rule['type'] ?? 'allow'))!=='deny';
		}
		return $decision ?? $this->defaultAllow;
	}

	/**
	 * Tests whether one policy rule applies to an action, path, and actor.
	 *
	 * Rules may constrain actions, path prefix, file extensions, and actors.
	 * Missing action lists default to wildcard matching. Actor matching is exact
	 * against `options['actor']`; absent actors do not match rules that declare an
	 * actor list.
	 *
	 * @param array<string, mixed> $rule Policy rule to test.
	 * @param string $action Storage action being attempted.
	 * @param string $path Normalized object path being accessed.
	 * @param array<string, mixed> $options Operation options, including optional actor.
	 * @return bool True when the rule applies.
	 */
	private function matches(array $rule, string $action, string $path, array $options): bool {
		$actions=array_map('strtolower', array_map('strval', (array)($rule['actions'] ?? $rule['action'] ?? ['*'])));
		if(!in_array('*', $actions, true) && !in_array(strtolower($action), $actions, true)){
			return false;
		}
		$prefix=Path::normalize((string)($rule['prefix'] ?? ''));
		if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
			return false;
		}
		$extensions=(array)($rule['extensions'] ?? []);
		if($extensions!==[]){
			$extension=strtolower(pathinfo($path, PATHINFO_EXTENSION));
			$allowed=array_map(static fn(mixed $value): string => strtolower(ltrim((string)$value, '.')), $extensions);
			if(!in_array($extension, $allowed, true)){
				return false;
			}
		}
		if(isset($rule['actors'])){
			$actor=(string)($options['actor'] ?? '');
			$actors=array_map('strval', (array)$rule['actors']);
			if(!in_array($actor, $actors, true)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalizes configured policy rules into a list of associative arrays.
	 *
	 * Non-array entries are ignored so generated or partially loaded
	 * configuration cannot create malformed rule entries. Rule order is preserved
	 * because later matching rules intentionally override earlier decisions.
	 *
	 * @param mixed $rules Configured rule data.
	 * @return list<array<string, mixed>> Rule list in evaluation order.
	 */
	private function normalizeRules(mixed $rules): array {
		if(!is_array($rules)){
			return [];
		}
		$out=[];
		foreach($rules as $rule){
			if(is_array($rule)){
				$out[]=$rule;
			}
		}
		return $out;
	}
}
