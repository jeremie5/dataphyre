<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Framework-owned editor package contract, independent of a specific JS editor. */
final class PanelEditorProfile implements \JsonSerializable {
	private PanelEditorSanitizationPolicy $policy;
	private ?PanelEditorSanitizer $sanitizer=null;
	private ?PanelEditorToolbar $toolbar=null;
	/** @var array<string,PanelEditorPlugin> */
	private array $plugins=[];
	private ?PanelEditorUploadAdapter $upload=null;
	private ?PanelEditorMediaAdapter $media=null;
	private ?PanelEditorAssetProvider $assets=null;
	private ?PanelEditorSyntaxAdapter $syntax=null;
	private ?PanelEditorBrowserAdapter $browserAdapter=null;
	private ?PanelEditorBrowserAdapter $browserSyntax=null;
	/** @var list<PanelEditorNormalizer> */
	private array $normalizers=[];
	/** @var list<PanelEditorValidator> */
	private array $validators=[];
	/** @var list<string> */
	private array $missingRuntime=[];

	private function __construct(private string $profileName, private string $editorMode) {
		$this->profileName=PanelEditorManifest::name($profileName, 'default');
		$this->editorMode=PanelEditorContext::normalizeMode($editorMode);
		$this->policy=PanelEditorSanitizationPolicy::strict();
		if(in_array($this->editorMode, ['html','rich_text'], true)){ $this->sanitizer=new PanelEditorHtmlSanitizer(); }
	}

	public static function make(string $name='default', string $mode='plain'): self { return new self($name, $mode); }
	public static function defaultFor(string $mode): self { return new self('default_'.$mode, $mode); }

	public static function fromArray(array $definition): self {
		$profile=self::make((string)($definition['name'] ?? 'default'), (string)($definition['mode'] ?? 'plain'));
		if(is_array($definition['policy'] ?? null)){ $profile=$profile->sanitizationPolicy(PanelEditorSanitizationPolicy::fromArray($definition['policy'])); }
		if(is_array($definition['toolbar'] ?? null)){ $profile=$profile->toolbar(PanelEditorToolbar::fromArray($definition['toolbar'])); }
		foreach(($definition['plugins'] ?? []) as $plugin){ if(is_array($plugin)){ $profile=$profile->plugin(PanelEditorPlugin::fromArray($plugin)); } }
		if(is_array($definition['upload'] ?? null)){ $profile=$profile->uploadAdapter(PanelEditorUpload::fromArray($definition['upload'])); }
		if(is_array($definition['media'] ?? null)){ $profile=$profile->mediaAdapter(PanelEditorMedia::fromArray($definition['media'])); }
		if(is_array($definition['asset_provider'] ?? null)){ $profile=$profile->assetProvider(PanelEditorCallbackAssetProvider::fromArray($definition['asset_provider']), false); }
		if(is_array($definition['syntax'] ?? null)){ $profile=$profile->syntaxAdapter(PanelEditorSyntaxHighlighter::unavailable($definition['syntax'])); }
		if(is_array($definition['browser_adapter'] ?? null)){
			$adapter=PanelEditorBrowserAdapter::fromArray($definition['browser_adapter']);
			if($adapter->isSurface()){ $profile=$profile->browserAdapter($adapter); }
		}
		if(is_array($definition['browser_syntax'] ?? null)){
			$adapter=PanelEditorBrowserAdapter::fromArray($definition['browser_syntax']);
			if($adapter->isSyntax()){ $profile=$profile->browserSyntaxAdapter($adapter); }
		}
		foreach(($definition['normalizers'] ?? []) as $normalizer){
			if(is_array($normalizer)){ $profile->missingRuntime[]='normalizer:'.PanelEditorManifest::name((string)($normalizer['name'] ?? 'unknown'), 'unknown'); }
		}
		foreach(($definition['validators'] ?? []) as $validator){
			if(is_array($validator)){ $profile->missingRuntime[]='validator:'.PanelEditorManifest::name((string)($validator['name'] ?? 'unknown'), 'unknown'); }
		}
		$sanitizerName=PanelEditorManifest::name((string)($definition['sanitizer']['name'] ?? ''));
		if($sanitizerName!=='' && $sanitizerName!=='dom_allow_list'){ $profile->missingRuntime[]='sanitizer:'.$sanitizerName; }
		foreach(($definition['missing_runtime'] ?? []) as $adapter){
			$adapter=strtolower(trim((string)$adapter));
			$adapter=preg_replace('/[^a-z0-9_:-]+/', '_', $adapter) ?? '';
			if($adapter!=='' && !in_array($adapter, $profile->missingRuntime, true)){ $profile->missingRuntime[]=$adapter; }
		}
		$profile->missingRuntime=array_values(array_unique($profile->missingRuntime));
		return $profile;
	}

	public function name(): string { return $this->profileName; }
	public function mode(): string { return $this->editorMode; }
	public function forMode(string $mode): self {
		$mode=PanelEditorContext::normalizeMode($mode);
		if($mode===$this->editorMode){ return $this; }
		$clone=clone $this; $clone->editorMode=$mode;
		if(in_array($mode, ['html','rich_text'], true) && $clone->sanitizer===null){ $clone->sanitizer=new PanelEditorHtmlSanitizer(); }
		return $clone;
	}
	public function policy(): PanelEditorSanitizationPolicy { return $this->policy; }
	public function sanitizerAdapter(): ?PanelEditorSanitizer { return $this->sanitizer; }
	public function toolbarDefinition(): ?PanelEditorToolbar { return $this->toolbar; }
	public function upload(): ?PanelEditorUploadAdapter { return $this->upload; }
	public function media(): ?PanelEditorMediaAdapter { return $this->media; }
	public function assets(): ?PanelEditorAssetProvider { return $this->assets; }
	public function syntax(): ?PanelEditorSyntaxAdapter { return $this->syntax; }
	public function browser(): ?PanelEditorBrowserAdapter { return $this->browserAdapter; }
	public function browserSyntax(): ?PanelEditorBrowserAdapter { return $this->browserSyntax; }

	public function sanitizationPolicy(PanelEditorSanitizationPolicy $policy): self { $clone=clone $this; $clone->policy=$policy; return $clone; }
	public function sanitizer(PanelEditorSanitizer $sanitizer): self { $clone=clone $this; $clone->sanitizer=$sanitizer; return $clone; }
	public function toolbar(PanelEditorToolbar $toolbar): self { $clone=clone $this; $clone->toolbar=$toolbar; return $clone; }
	public function plugin(PanelEditorPlugin $plugin): self { $clone=clone $this; if($plugin->name()!==''){ $clone->plugins[$plugin->name()]=$plugin; } return $clone; }
	public function plugins(array $plugins): self { $clone=$this; foreach($plugins as $plugin){ if($plugin instanceof PanelEditorPlugin){ $clone=$clone->plugin($plugin); } } return $clone; }
	public function uploadAdapter(?PanelEditorUploadAdapter $upload): self { $clone=clone $this; $clone->upload=$upload; return $clone; }
	public function mediaAdapter(?PanelEditorMediaAdapter $media): self { $clone=clone $this; $clone->media=$media; return $clone; }
	public function assetProvider(?PanelEditorAssetProvider $provider, bool $allowImages=true): self {
		$clone=clone $this;
		$previous=$clone->assets;
		$clone->assets=$provider;
		if($provider!==null){
			$clone->upload=$provider; $clone->media=$provider;
			if($allowImages){ $clone->policy=$clone->policy->allowElement('img', ['src','alt','width','height','loading']); }
		}
		else{
			if($clone->upload===$previous){ $clone->upload=null; }
			if($clone->media===$previous){ $clone->media=null; }
		}
		return $clone;
	}
	public function syntaxAdapter(?PanelEditorSyntaxAdapter $syntax): self { $clone=clone $this; $clone->syntax=$syntax; return $clone; }
	public function browserAdapter(?PanelEditorBrowserAdapter $adapter): self {
		if($adapter!==null && !$adapter->isSurface()){ throw new \InvalidArgumentException('A browser editor adapter must declare the surface kind.'); }
		$clone=clone $this; $clone->browserAdapter=$adapter; return $clone;
	}
	public function browserSyntaxAdapter(?PanelEditorBrowserAdapter $adapter): self {
		if($adapter!==null && !$adapter->isSyntax()){ throw new \InvalidArgumentException('A browser syntax adapter must declare the syntax kind.'); }
		$clone=clone $this; $clone->browserSyntax=$adapter; return $clone;
	}
	public function normalizer(PanelEditorNormalizer $normalizer): self { $clone=clone $this; $clone->normalizers[]=$normalizer; return $clone; }
	public function validator(PanelEditorValidator $validator): self { $clone=clone $this; $clone->validators[]=$validator; return $clone; }

	public function process(string $content, PanelEditorContext $context): PanelEditorContentResult {
		$missing=array_values(array_filter($this->missingRuntime, fn(string $adapter): bool => !str_starts_with($adapter, 'sanitizer:') || in_array($this->editorMode, ['html','rich_text'], true)));
		$current=$content; $errors=array_map(static fn(string $adapter): string => 'Editor runtime adapter "'.$adapter.'" must be rebound before content can be accepted.', $missing); $warnings=[]; $changed=false;
		foreach($this->normalizers as $normalizer){
			$result=$normalizer->normalize($current, $context);
			$current=$result->content(); $errors=array_merge($errors, $result->errors()); $warnings=array_merge($warnings, $result->warnings()); $changed=$changed || $result->changed();
		}
		if(in_array($this->editorMode, ['html','rich_text'], true)){
			if($this->sanitizer===null || !$this->sanitizer->ready()){
				$errors[]='A ready server-side sanitizer is required for rich editor content.';
			}
			else{
				$result=$this->sanitizer->sanitize($current, $this->policy, $context, $this->media);
				$current=$result->content(); $errors=array_merge($errors, $result->errors()); $warnings=array_merge($warnings, $result->warnings()); $changed=$changed || $result->changed();
			}
		}
		elseif($this->editorMode==='markdown'){
			$errors=array_merge($errors, $this->validateMarkdownDestinations($current));
		}
		foreach($this->validators as $validator){ $errors=array_merge($errors, $validator->validate($current, $context)); }
		$errors=self::messages($errors); $warnings=self::messages($warnings);
		return new PanelEditorContentResult($current, $errors, $warnings, $changed || $current!==$content, ['profile'=>$this->profileName, 'mode'=>$this->editorMode]);
	}

	public function highlightHtml(string $code, string $language, PanelEditorContext $context): string {
		if($this->syntax===null || !$this->syntax->ready() || !$this->syntax->supports($language)){ return htmlspecialchars($code, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
		$html=''; foreach($this->syntax->tokens($code, $language, $context) as $token){ $type=PanelEditorManifest::name((string)($token['type'] ?? 'plain'), 'plain'); $html.='<span class="dp-panel-token dp-panel-token-'.htmlspecialchars($type, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'">'.htmlspecialchars((string)($token['text'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</span>'; } return $html;
	}

	public function manifest(): array {
		return PanelEditorManifest::sanitize([
			'name'=>$this->profileName, 'mode'=>$this->editorMode,
			'toolbar'=>$this->toolbar?->toArray(),
			'plugins'=>array_map(static fn(PanelEditorPlugin $plugin): array => $plugin->toArray(), array_values($this->plugins)),
			'policy'=>$this->policy->toArray(),
			'sanitizer'=>$this->sanitizer?->manifest(),
				'upload'=>$this->upload?->manifest(), 'media'=>$this->media?->manifest(), 'asset_provider'=>$this->assets?->manifest(), 'syntax'=>$this->syntax?->manifest(),
			'browser_adapter'=>$this->browserAdapter?->manifest(), 'browser_syntax'=>$this->browserSyntax?->manifest(),
			'normalizers'=>array_map(static fn(PanelEditorNormalizer $normalizer): array => PanelEditorManifest::sanitize($normalizer->manifest()), $this->normalizers),
			'validators'=>array_map(static fn(PanelEditorValidator $validator): array => PanelEditorManifest::sanitize($validator->manifest()), $this->validators),
			'missing_runtime'=>$this->missingRuntime,
			'capabilities'=>array_values(array_filter([
				in_array($this->editorMode, ['html','rich_text'], true) ? 'server_sanitization' : null,
				$this->editorMode==='markdown' ? 'safe_markdown_destinations' : null,
				$this->toolbar!==null ? 'custom_toolbar' : null, $this->plugins!==[] ? 'plugins' : null,
					$this->upload?->ready() ? 'upload_adapter' : null, $this->media?->ready() ? 'media_adapter' : null, $this->assets?->ready() ? 'asset_provider' : null,
				$this->syntax?->ready() ? 'syntax_tokens' : null, $this->normalizers!==[] ? 'normalizers' : null, $this->validators!==[] ? 'validators' : null,
				$this->browserAdapter?->isConfigured() ? 'browser_editor_adapter' : null,
				$this->browserSyntax?->isConfigured() ? 'browser_syntax_adapter' : null,
			])),
		]);
	}
	public function toArray(): array { return $this->manifest(); }
	public function jsonSerialize(): array { return $this->manifest(); }

	private function validateMarkdownDestinations(string $markdown): array {
		$destinations=[];
		if(preg_match_all('/!?\[[^\]\r\n]*\]\(\s*(?:<([^>\r\n]*)>|((?:\\.|[^)\s])+))/u', $markdown, $matches, PREG_SET_ORDER)){
			foreach($matches as $match){ $destinations[]=(string)(($match[1] ?? '')!=='' ? $match[1] : ($match[2] ?? '')); }
		}
		if(preg_match_all('/^[ \t]{0,3}\[[^\]\r\n]+\]:[ \t]*(?:<([^>\r\n]+)>|(\S+))/mu', $markdown, $matches, PREG_SET_ORDER)){
			foreach($matches as $match){ $destinations[]=(string)(($match[1] ?? '')!=='' ? $match[1] : ($match[2] ?? '')); }
		}
		if(preg_match_all('/<([a-z][a-z0-9+.-]*:[^<>\s]+)>/iu', $markdown, $matches)){ foreach($matches[1] as $destination){ $destinations[]=(string)$destination; } }
		if(preg_match_all('/\b(?:href|src)\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/iu', $markdown, $matches, PREG_SET_ORDER)){
			foreach($matches as $match){ $destinations[]=(string)(($match[2] ?? '')!=='' ? $match[2] : ($match[3] ?? '')); }
		}
		$errors=[]; foreach($destinations as $destination){ if($this->policy->normalizeUrl(str_replace('\\', '', $destination))===null){ $errors[]='Markdown contains an unsafe link or media destination.'; } }
		return array_values(array_unique($errors));
	}
	private static function messages(array $messages): array { $clean=[]; foreach($messages as $message){ $message=trim((string)$message); if($message!=='' && !in_array($message, $clean, true)){ $clean[]=$message; } } return $clean; }
}
