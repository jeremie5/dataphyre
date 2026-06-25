<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Loads, registers, resolves, validates, previews, and exports Panel theme presets and themes.
 *
 * The library accepts PHP or JSON theme payloads, normalizes preset/theme definitions into named registries, lazily
 * materializes theme objects from array definitions, and exposes manifest, diagnostics, preview, and export surfaces for
 * Flightdeck, build tooling, theme previews, and Panel runtime consumers.
 */
final class PanelThemeLibrary {

	/** @var array<string, PanelThemePreset> */
	private array $presets=[];
	/** @var array<string, PanelTheme> */
	private array $themes=[];
	private array $themeDefinitions=[];
	private array $resolvingThemes=[];

	/**
	 * Creates an empty theme library.
	 *
	 * @return self New theme library instance.
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Creates a theme library and loads one or more files or directories into it.
	 *
	 * @param string|array<int, string> $paths File or directory paths containing theme payloads.
	 * @return self Library populated from readable theme sources.
	 */
	public static function load(string|array $paths): self {
		return self::make()->loadFrom($paths);
	}

	/**
	 * Loads readable PHP or JSON theme payloads from files or directories.
	 *
	 * Non-string and empty paths are ignored so generated path lists can be passed directly. Directories are scanned for
	 * PHP and JSON files in natural order to keep exported manifests stable across runs.
	 *
	 * @param string|array<int, string> $paths File or directory paths to inspect.
	 * @return self This library after loading supported payloads.
	 */
	public function loadFrom(string|array $paths): self {
		foreach(is_array($paths) ? $paths : [$paths] as $path){
			if(is_string($path) && trim($path)!==''){
				$this->loadPath($path);
			}
		}
		return $this;
	}

	/**
	 * Registers one preset definition by its normalized preset name.
	 *
	 * Array payloads are converted through PanelThemePreset::fromArray(), leaving preset normalization in that class. Later
	 * registrations replace earlier presets with the same name.
	 *
	 * @param PanelThemePreset|array<string, mixed> $preset Preset object or array definition.
	 * @return self This library after registering the preset.
	 */
	public function register(PanelThemePreset|array $preset): self {
		$preset=$preset instanceof PanelThemePreset ? $preset : PanelThemePreset::fromArray($preset);
		$this->presets[$preset->name()]=$preset;
		return $this;
	}

	/**
	 * Registers every valid preset in a list.
	 *
	 * Invalid entries are skipped so mixed manifest payloads can be loaded without aborting the whole library.
	 *
	 * @param array<int|string, mixed> $presets Preset objects or array definitions.
	 * @return self This library after registering valid presets.
	 */
	public function registerMany(array $presets): self {
		foreach($presets as $preset){
			if($preset instanceof PanelThemePreset || is_array($preset)){
				$this->register($preset);
			}
		}
		return $this;
	}

	/**
	 * Registers one theme definition and invalidates the resolved theme cache.
	 *
	 * Theme objects are stored as concrete resolved themes and mirrored into definition form. Array definitions are kept
	 * lazy until requested so base/preset references can be validated and resolved after all files are loaded.
	 *
	 * @param PanelTheme|array<string, mixed> $theme Theme object or array definition.
	 * @return self This library after registering the theme.
	 */
	public function registerTheme(PanelTheme|array $theme): self {
		if($theme instanceof PanelTheme){
			$this->themes=[];
			$this->themes[$theme->name()]=$theme;
			$this->themeDefinitions[$theme->name()]=$theme->toArray();
			return $this;
		}
		$name=Resource::normalizeName((string)($theme['name'] ?? ''));
		if($name===''){
			$name='theme';
			$theme['name']=$name;
		}
		$this->themeDefinitions[$name]=$theme;
		$this->themes=[];
		return $this;
	}

	/**
	 * Registers every valid theme in a list.
	 *
	 * @param array<int|string, mixed> $themes Theme objects or array definitions.
	 * @return self This library after registering valid themes.
	 */
	public function registerThemes(array $themes): self {
		foreach($themes as $theme){
			if($theme instanceof PanelTheme || is_array($theme)){
				$this->registerTheme($theme);
			}
		}
		return $this;
	}

	/**
	 * Returns a registered preset by normalized name.
	 *
	 * @param string $name Preset name or alias-like input.
	 * @return ?PanelThemePreset Registered preset, or null when absent.
	 */
	public function get(string $name): ?PanelThemePreset {
		$name=Resource::normalizeName($name);
		return $this->presets[$name] ?? null;
	}

	/**
	 * Reports whether a preset exists under the normalized name.
	 *
	 * @param string $name Preset name or alias-like input.
	 * @return bool True when a matching preset is registered.
	 */
	public function has(string $name): bool {
		return $this->get($name) instanceof PanelThemePreset;
	}

	/**
	 * Returns a resolved theme copy by normalized name.
	 *
	 * Resolved themes are cloned through toArray()/fromArray() before returning so callers can inspect or mutate the result
	 * without altering the library cache.
	 *
	 * @param string $name Theme name.
	 * @return ?PanelTheme Resolved theme copy, or null when the theme cannot be found or resolved.
	 */
	public function getTheme(string $name): ?PanelTheme {
		$name=Resource::normalizeName($name);
		if($name===''){
			return null;
		}
		if(isset($this->themes[$name])){
			return PanelTheme::fromArray($this->themes[$name]->toArray());
		}
		if(isset($this->themeDefinitions[$name])){
			$theme=$this->resolveTheme($name);
			return $theme instanceof PanelTheme ? PanelTheme::fromArray($theme->toArray()) : null;
		}
		return null;
	}

	/**
	 * Reports whether a theme can be resolved under the normalized name.
	 *
	 * @param string $name Theme name.
	 * @return bool True when the library can return a PanelTheme for the name.
	 */
	public function hasTheme(string $name): bool {
		return $this->getTheme($name) instanceof PanelTheme;
	}

	/**
	 * Returns the registered preset objects keyed by normalized preset name.
	 *
	 * @return array<string, PanelThemePreset> Preset registry.
	 */
	public function all(): array {
		return $this->presets;
	}

	/**
	 * Resolves and returns all theme objects keyed by normalized theme name.
	 *
	 * @return array<string, PanelTheme> Resolved theme registry.
	 */
	public function allThemes(): array {
		foreach(array_keys($this->themeDefinitions) as $name){
			$this->resolveTheme((string)$name);
		}
		return $this->themes;
	}

	/**
	 * Exports preset definitions to arrays.
	 *
	 * @return array<string, array<string, mixed>> Preset definitions keyed by normalized preset name.
	 */
	public function toArray(): array {
		return array_map(static fn(PanelThemePreset $preset): array => $preset->toArray(), $this->presets);
	}

	/**
	 * Exports resolved theme definitions to arrays.
	 *
	 * @return array<string, array<string, mixed>> Theme definitions keyed by normalized theme name.
	 */
	public function themesToArray(): array {
		return array_map(static fn(PanelTheme $theme): array => $theme->toArray(), $this->allThemes());
	}

	/**
	 * Builds the canonical theme manifest payload.
	 *
	 * @return array{presets: list<array<string, mixed>>, themes: list<array<string, mixed>>} Manifest suitable for JSON export.
	 */
	public function manifest(): array {
		return [
			'presets'=>array_values($this->toArray()),
			'themes'=>array_values($this->themesToArray()),
		];
	}

	/**
	 * Encodes the canonical theme manifest as JSON.
	 *
	 * @param int $flags Additional json_encode flags.
	 * @return string Manifest JSON, or an empty manifest fallback if encoding fails.
	 */
	public function toJson(int $flags=0): string {
		return json_encode($this->manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $flags) ?: '{"presets":[],"themes":[]}';
	}

	/**
	 * Writes the canonical manifest JSON to disk.
	 *
	 * Parent directories are created when possible. The method reports write failure instead of throwing so build steps can
	 * include the status in export diagnostics.
	 *
	 * @param string $path Destination manifest path.
	 * @param int $flags Additional json_encode flags.
	 * @return bool True when the manifest file was written.
	 */
	public function writeManifest(string $path, int $flags=0): bool {
		return self::writeFile($path, $this->toJson(JSON_PRETTY_PRINT | $flags)."\n");
	}

	/**
	 * Writes the aggregate manifest and one JSON file per preset/theme into a directory.
	 *
	 * The returned payload is an export report rather than only a success flag, giving build tooling exact paths and per-file
	 * write status for diagnostics.
	 *
	 * @param string $directory Destination directory, defaulting to the current directory when empty.
	 * @return array{manifest: bool, manifest_path: string, presets: array<string, array{path: string, written: bool}>, themes: array<string, array{path: string, written: bool}>}
	 */
	public function exportTo(string $directory): array {
		$directory=trim($directory) ?: '.';
		$directory=rtrim($directory, "\\/");
		$manifestPath=$directory.DIRECTORY_SEPARATOR.'panel-themes.json';
		$result=[
			'manifest'=>$this->writeManifest($manifestPath),
			'manifest_path'=>$manifestPath,
			'presets'=>[],
			'themes'=>[],
		];
		foreach($this->presets as $name=>$preset){
			$presetPath=$directory.DIRECTORY_SEPARATOR.(Resource::normalizeName($name) ?: 'preset').'.panel-preset.json';
			$result['presets'][$name]=[
				'path'=>$presetPath,
				'written'=>self::writeFile($presetPath, json_encode($preset->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"),
			];
		}
		foreach($this->allThemes() as $name=>$theme){
			$themePath=$directory.DIRECTORY_SEPARATOR.(Resource::normalizeName($name) ?: 'theme').'.panel-theme.json';
			$result['themes'][$name]=[
				'path'=>$themePath,
				'written'=>self::writeFile($themePath, $theme->toJson(JSON_PRETTY_PRINT)."\n"),
			];
		}
		return $result;
	}

	/**
	 * Produces validation and inventory diagnostics for the theme library.
	 *
	 * Diagnostics include registry counts, sorted theme and preset names, unresolved base/preset references, inheritance
	 * cycles, contrast checks, and contrast failures so Flightdeck and CI can report why a library is not production-ready.
	 *
	 * @return array<string, mixed> Theme library diagnostic report.
	 */
	public function diagnostics(): array {
		$themeNames=array_values(array_unique(array_merge(array_keys($this->themeDefinitions), array_keys($this->themes))));
		sort($themeNames, SORT_NATURAL | SORT_FLAG_CASE);
		$resolvedThemeNames=array_keys($this->allThemes());
		return [
			'presets'=>count($this->presets),
			'themes'=>count($themeNames),
			'resolved_themes'=>count($resolvedThemeNames),
			'pending_themes'=>count(array_diff($themeNames, $resolvedThemeNames)),
			'theme_names'=>$themeNames,
			'preset_names'=>array_values(array_keys($this->presets)),
			'missing_bases'=>$this->missingBases(),
			'missing_presets'=>$this->missingPresets(),
			'cycles'=>$this->themeCycles(),
			'contrast'=>$this->contrastDiagnostics(),
			'contrast_failures'=>$this->contrastFailures(),
		];
	}

	/**
	 * Reports whether the library has no missing references, cycles, or contrast failures.
	 *
	 * @return bool True when diagnostics contain no blocking theme problems.
	 */
	public function isValid(): bool {
		$diagnostics=$this->diagnostics();
		return $diagnostics['missing_bases']===[] && $diagnostics['missing_presets']===[] && $diagnostics['cycles']===[] && $diagnostics['contrast_failures']===[];
	}

	/**
	 * Builds preview data for one theme or every resolved theme.
	 *
	 * @param ?string $name Optional theme name. Empty input returns all theme previews.
	 * @return array<string, mixed> Preview payload from PanelTheme::preview().
	 */
	public function preview(?string $name=null): array {
		$name=Resource::normalizeName((string)$name);
		if($name!==''){
			$theme=$this->getTheme($name);
			return $theme instanceof PanelTheme ? $theme->preview() : [];
		}
		$previews=[];
		foreach($this->allThemes() as $themeName=>$theme){
			$previews[(string)$themeName]=$theme->preview();
		}
		return $previews;
	}

	/**
	 * Renders preview HTML for one theme or a concatenated gallery of every theme.
	 *
	 * When rendering multiple themes, CSS is emitted only for the first preview unless options explicitly say otherwise,
	 * preventing duplicate style blocks in Flightdeck preview galleries.
	 *
	 * @param ?string $name Optional theme name. Empty input renders all themes.
	 * @param array<string, mixed> $options Preview rendering options forwarded to PanelTheme.
	 * @return string Preview HTML, or an empty string when a requested theme is missing.
	 */
	public function previewHtml(?string $name=null, array $options=[]): string {
		$name=Resource::normalizeName((string)$name);
		if($name!==''){
			$theme=$this->getTheme($name);
			return $theme instanceof PanelTheme ? $theme->previewHtml($options) : '';
		}
		$html='';
		$first=true;
		foreach($this->allThemes() as $theme){
			$itemOptions=$options;
			if(!$first && !array_key_exists('css', $itemOptions)){
				$itemOptions['css']=false;
			}
			$html.=$theme->previewHtml($itemOptions);
			$first=false;
		}
		return $html;
	}

	/**
	 * Loads one path as either a directory scan or a single readable file.
	 *
	 * @param string $path File or directory path supplied by loadFrom().
	 */
	private function loadPath(string $path): void {
		$path=trim($path);
		if(is_dir($path)){
			$this->loadDirectory($path);
			return;
		}
		if(is_file($path) && is_readable($path)){
			$this->loadFile($path);
		}
	}

	/**
	 * Loads every supported PHP or JSON theme file from a directory in stable order.
	 *
	 * @param string $directory Directory containing theme payload files.
	 */
	private function loadDirectory(string $directory): void {
		$files=glob(rtrim($directory, "\\/").DIRECTORY_SEPARATOR.'*.{php,json}', GLOB_BRACE);
		if(!is_array($files)){
			return;
		}
		sort($files, SORT_NATURAL | SORT_FLAG_CASE);
		foreach($files as $file){
			if(is_string($file) && is_file($file) && is_readable($file)){
				$this->loadFile($file);
			}
		}
	}

	/**
	 * Reads and registers one PHP or JSON theme payload file.
	 *
	 * PHP files are required inside a closure and must return a payload. JSON files must decode to arrays; invalid JSON is
	 * ignored so a bad optional theme file does not corrupt already-loaded definitions.
	 *
	 * @param string $file Readable payload file.
	 */
	private function loadFile(string $file): void {
		$extension=strtolower(pathinfo($file, PATHINFO_EXTENSION));
		$payload=null;
		if($extension==='json'){
			$decoded=json_decode((string)file_get_contents($file), true);
			$payload=is_array($decoded) ? $decoded : null;
		}
		elseif($extension==='php'){
			$payload=(static function(string $file): mixed {
				return require $file;
			})($file);
		}
		$this->registerPayload($payload, $file);
	}

	/**
	 * Routes a decoded payload to preset or theme registration.
	 *
	 * Manifests with presets/themes keys are expanded, list payloads are treated as preset lists, and single associative
	 * payloads default to presets unless their type or kind normalizes to theme. Source metadata is attached to single
	 * associative payloads for export and diagnostics traceability.
	 *
	 * @param mixed $payload Decoded PHP or JSON payload.
	 * @param string $source Source file path used for metadata.
	 */
	private function registerPayload(mixed $payload, string $source): void {
		if($payload instanceof PanelThemePreset){
			$this->register($payload);
			return;
		}
		if($payload instanceof PanelTheme){
			$this->registerTheme($payload);
			return;
		}
		if(!is_array($payload)){
			return;
		}
		if(isset($payload['presets']) && is_array($payload['presets'])){
			$this->registerMany($payload['presets']);
		}
		if(isset($payload['themes']) && is_array($payload['themes'])){
			$this->registerThemes($payload['themes']);
			return;
		}
		if(isset($payload['presets']) && is_array($payload['presets'])){
			return;
		}
		if(array_is_list($payload)){
			$this->registerMany($payload);
			return;
		}
		$type=Resource::normalizeName((string)($payload['type'] ?? $payload['kind'] ?? ''));
		$payload['meta']=array_replace(
			is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
			['source'=>$source]
		);
		if($type==='theme'){
			$this->registerTheme($payload);
			return;
		}
		$this->register($payload);
	}

	/**
	 * Materializes one lazy theme definition into the resolved theme cache.
	 *
	 * Recursive resolution of the same theme returns null to prevent inheritance cycles from causing unbounded recursion;
	 * cycle details are reported separately by themeCycles().
	 *
	 * @param string $name Theme name to resolve.
	 * @return ?PanelTheme Resolved theme object, or null when absent or already resolving.
	 */
	private function resolveTheme(string $name): ?PanelTheme {
		$name=Resource::normalizeName($name);
		if($name==='' || isset($this->resolvingThemes[$name])){
			return null;
		}
		if(isset($this->themes[$name])){
			return $this->themes[$name];
		}
		$definition=$this->themeDefinitions[$name] ?? null;
		if(!is_array($definition)){
			return null;
		}
		$this->resolvingThemes[$name]=true;
		try {
			$definition['name']=$definition['name'] ?? $name;
			$this->themes[$name]=PanelTheme::fromArray($definition);
		}
		finally {
			unset($this->resolvingThemes[$name]);
		}
		return $this->themes[$name] ?? null;
	}

	/**
	 * Finds theme base references that do not match a registered theme or preset.
	 *
	 * @return list<array{theme: string, base: string}> Missing base reference records.
	 */
	private function missingBases(): array {
		$missing=[];
		foreach($this->themeDefinitions as $themeName=>$definition){
			if(!is_array($definition)){
				continue;
			}
			foreach($this->stringReferences($definition, ['extends', 'base_theme', 'base']) as $base){
				if($this->referenceExists($base)){
					continue;
				}
				$missing[]=[
					'theme'=>(string)$themeName,
					'base'=>$base,
				];
			}
		}
		return $missing;
	}

	/**
	 * Finds preset references that do not match a registered preset.
	 *
	 * @return list<array{theme: string, preset: string}> Missing preset reference records.
	 */
	private function missingPresets(): array {
		$missing=[];
		foreach($this->themeDefinitions as $themeName=>$definition){
			if(!is_array($definition)){
				continue;
			}
			foreach($this->stringReferences($definition, ['preset', 'presets']) as $preset){
				if(isset($this->presets[$preset])){
					continue;
				}
				$missing[]=[
					'theme'=>(string)$themeName,
					'preset'=>$preset,
				];
			}
		}
		return $missing;
	}

	/**
	 * Detects cycles in theme inheritance references.
	 *
	 * @return list<list<string>> Detected inheritance cycles with the starting node repeated at the end.
	 */
	private function themeCycles(): array {
		$graph=[];
		foreach($this->themeDefinitions as $themeName=>$definition){
			if(!is_array($definition)){
				continue;
			}
			$graph[(string)$themeName]=array_values(array_filter(
				$this->stringReferences($definition, ['extends', 'base_theme', 'base']),
				fn(string $base): bool => isset($this->themeDefinitions[$base])
			));
		}
		$cycles=[];
		$visited=[];
		$visiting=[];
		foreach(array_keys($graph) as $node){
			$this->detectThemeCycles($node, $graph, [], $visiting, $visited, $cycles);
		}
		return array_values($cycles);
	}

	/**
	 * Collects contrast diagnostics from every resolved theme.
	 *
	 * @return array<string, mixed> Contrast reports keyed by theme name.
	 */
	private function contrastDiagnostics(): array {
		$diagnostics=[];
		foreach($this->allThemes() as $name=>$theme){
			$diagnostics[(string)$name]=$theme->contrastDiagnostics();
		}
		return $diagnostics;
	}

	/**
	 * Extracts failing contrast checks and annotates them with theme and mode.
	 *
	 * @return list<array<string, mixed>> Flattened contrast failure records.
	 */
	private function contrastFailures(): array {
		$failures=[];
		foreach($this->contrastDiagnostics() as $theme=>$modes){
			foreach($modes as $mode=>$checks){
				foreach(is_array($checks) ? $checks : [] as $check){
					if(($check['status'] ?? '')==='fail'){
						$check['theme']=$theme;
						$check['mode']=$mode;
						$failures[]=$check;
					}
				}
			}
		}
		return $failures;
	}

	/**
	 * Depth-first traversal helper for theme inheritance cycle detection.
	 *
	 * @param string $node Current graph node.
	 * @param array<string, list<string>> $graph Theme inheritance graph.
	 * @param list<string> $path Traversal path to the current node.
	 * @param array<string, bool> $visiting Nodes currently on the recursion stack.
	 * @param array<string, bool> $visited Fully explored nodes.
	 * @param array<string, list<string>> $cycles Cycle map keyed by path signature.
	 */
	private function detectThemeCycles(string $node, array $graph, array $path, array &$visiting, array &$visited, array &$cycles): void {
		if(isset($visited[$node])){
			return;
		}
		if(isset($visiting[$node])){
			$offset=array_search($node, $path, true);
			$cycle=$offset===false ? [$node, $node] : array_merge(array_slice($path, (int)$offset), [$node]);
			$cycles[implode('>', $cycle)]=$cycle;
			return;
		}
		$visiting[$node]=true;
		$path[]=$node;
		foreach($graph[$node] ?? [] as $next){
			$this->detectThemeCycles((string)$next, $graph, $path, $visiting, $visited, $cycles);
		}
		unset($visiting[$node]);
		$visited[$node]=true;
	}

	/**
	 * Reports whether a normalized reference name exists in any theme library registry.
	 *
	 * @param string $name Normalized theme or preset name.
	 * @return bool True when a theme definition, resolved theme, or preset exists.
	 */
	private function referenceExists(string $name): bool {
		return isset($this->themeDefinitions[$name]) || isset($this->themes[$name]) || isset($this->presets[$name]);
	}

	/**
	 * Extracts normalized string references from selected definition keys.
	 *
	 * @param array<string, mixed> $definition Theme definition being inspected.
	 * @param list<string> $keys Definition keys that may contain references.
	 * @return list<string> Unique normalized references.
	 */
	private function stringReferences(array $definition, array $keys): array {
		$references=[];
		foreach($keys as $key){
			if(!isset($definition[$key])){
				continue;
			}
			foreach($this->referenceValues($definition[$key]) as $value){
				$name=Resource::normalizeName($value);
				if($name!==''){
					$references[]=$name;
				}
			}
		}
		return array_values(array_unique($references));
	}

	/**
	 * Converts a reference field value into raw string references.
	 *
	 * Object values are ignored because resolved theme/preset instances cannot safely describe their original reference
	 * intent; only strings and lists of strings are considered declarative references.
	 *
	 * @param mixed $value Candidate reference field value.
	 * @return list<string> Raw reference strings.
	 */
	private function referenceValues(mixed $value): array {
		if(is_string($value)){
			return [$value];
		}
		if($value instanceof PanelTheme || $value instanceof PanelThemePreset || !is_array($value)){
			return [];
		}
		if(array_is_list($value)){
			$values=[];
			foreach($value as $item){
				if(is_string($item)){
					$values[]=$item;
				}
			}
			return $values;
		}
		return [];
	}

	/**
	 * Writes a file, creating parent directories when needed.
	 *
	 * @param string $path Destination file path.
	 * @param string $contents File contents.
	 * @return bool True when the file write succeeds.
	 */
	private static function writeFile(string $path, string $contents): bool {
		$path=trim($path);
		if($path===''){
			return false;
		}
		$directory=dirname($path);
		if($directory!=='' && $directory!=='.' && !is_dir($directory) && @mkdir($directory, 0775, true)!==true && !is_dir($directory)){
			return false;
		}
		return @file_put_contents($path, $contents, LOCK_EX)!==false;
	}
}
