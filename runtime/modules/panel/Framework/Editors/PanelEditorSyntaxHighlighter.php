<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Callback-backed syntax adapter constrained to inert type/text token streams. */
final class PanelEditorSyntaxHighlighter implements PanelEditorSyntaxAdapter, \JsonSerializable {
	private ?\Closure $highlighter;
	/** @var list<string> */
	private array $languages=[];
	private array $metadata=[];
	private function __construct(private string $adapterName, ?callable $highlighter, array $languages, array $metadata=[]) {
		$this->adapterName=PanelEditorManifest::name($adapterName, 'syntax');
		$this->highlighter=$highlighter!==null ? \Closure::fromCallable($highlighter) : null;
		foreach($languages as $language){ $language=trim((string)$language)==='*' ? '*' : PanelEditorContext::normalizeLanguage((string)$language); if(!in_array($language, $this->languages, true)){ $this->languages[]=$language; } }
		$this->metadata=PanelEditorManifest::sanitize($metadata);
	}
	public static function make(string $name, callable $highlighter, array|string $languages=['plain'], array $metadata=[]): self { return new self($name, $highlighter, (array)$languages, $metadata); }
	public static function unavailable(array $definition): self { return new self((string)($definition['name'] ?? 'syntax'), null, is_array($definition['languages'] ?? null) ? $definition['languages'] : [], is_array($definition['options'] ?? null) ? $definition['options'] : []); }
	public function name(): string { return $this->adapterName; }
	public function ready(): bool { return $this->highlighter!==null; }
	public function supports(string $language): bool { $language=PanelEditorContext::normalizeLanguage($language); return $this->ready() && (in_array('*', $this->languages, true) || in_array($language, $this->languages, true)); }
	public function tokens(string $code, string $language, PanelEditorContext $context): array {
		if(!$this->supports($language)){ return [['type'=>'plain','text'=>$code]]; }
		$raw=PanelUtilityResolver::evaluate($this->highlighter, ['code'=>$code, 'language'=>$language, 'context'=>$context], ['code','language','context']);
		if(!is_array($raw)){ return [['type'=>'plain','text'=>$code]]; }
		$tokens=[]; $joined='';
		foreach(array_slice($raw, 0, 100000) as $token){
			if(!is_array($token)){ continue; }
			$type=PanelEditorManifest::name((string)($token['type'] ?? 'plain'), 'plain');
			$text=(string)($token['text'] ?? '');
			$tokens[]=['type'=>$type, 'text'=>$text]; $joined.=$text;
		}
		return $tokens!==[] && $joined===$code ? $tokens : [['type'=>'plain','text'=>$code]];
	}
	public function highlightHtml(string $code, string $language, PanelEditorContext $context): string {
		$html=''; foreach($this->tokens($code, $language, $context) as $token){ $html.='<span class="dp-panel-token dp-panel-token-'.htmlspecialchars($token['type'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'">'.htmlspecialchars($token['text'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</span>'; } return $html;
	}
	public function manifest(): array { return ['name'=>$this->name(), 'languages'=>$this->languages, 'ready'=>$this->ready(), 'runtime'=>$this->ready() ? 'token_callback' : 'unavailable', 'options'=>$this->metadata]; }
	public function jsonSerialize(): array { return $this->manifest(); }
}
