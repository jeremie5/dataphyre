<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Callback-backed validator whose executable callback never enters a manifest. */
final class PanelEditorCallbackValidator implements PanelEditorValidator {
	private \Closure $callback;
	public function __construct(private string $adapterName, callable $callback, private array $metadata=[]) {
		$this->adapterName=PanelEditorManifest::name($adapterName, 'validator');
		$this->callback=\Closure::fromCallable($callback);
		$this->metadata=PanelEditorManifest::sanitize($metadata);
	}
	public function name(): string { return $this->adapterName; }
	public function validate(string $content, PanelEditorContext $context): array {
		$result=PanelUtilityResolver::evaluate($this->callback, ['content'=>$content, 'context'=>$context], ['content','context']);
		if($result===true || $result===null){ return []; }
		if($result===false){ return ['Editor content is invalid.']; }
		return array_values(array_filter(array_map(static fn(mixed $message): string => trim((string)$message), is_array($result) ? $result : [$result])));
	}
	public function manifest(): array { return ['name'=>$this->name(), 'runtime'=>'callback', 'ready'=>true, 'options'=>$this->metadata]; }
}
