<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Callback-backed normalizer whose executable callback never enters a manifest. */
final class PanelEditorCallbackNormalizer implements PanelEditorNormalizer {
	private \Closure $callback;
	public function __construct(private string $adapterName, callable $callback, private array $metadata=[]) {
		$this->adapterName=PanelEditorManifest::name($adapterName, 'normalizer');
		$this->callback=\Closure::fromCallable($callback);
		$this->metadata=PanelEditorManifest::sanitize($metadata);
	}
	public function name(): string { return $this->adapterName; }
	public function normalize(string $content, PanelEditorContext $context): PanelEditorContentResult {
		$result=PanelUtilityResolver::evaluate($this->callback, ['content'=>$content, 'context'=>$context], ['content','context']);
		if($result instanceof PanelEditorContentResult){ return $result; }
		if(!is_string($result) && !$result instanceof \Stringable){ return PanelEditorContentResult::reject($content, 'Editor normalizers must return text or an editor content result.'); }
		$result=(string)$result;
		return PanelEditorContentResult::accept($result, $result!==$content);
	}
	public function manifest(): array { return ['name'=>$this->name(), 'runtime'=>'callback', 'ready'=>true, 'options'=>$this->metadata]; }
}
