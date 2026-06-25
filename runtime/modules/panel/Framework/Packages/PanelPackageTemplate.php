<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Builds a deterministic scaffold for a distributable Panel package.
 *
 * A template combines a package manifest, generation options, marketplace
 * metadata, and optional caller-supplied files into a serializable artifact
 * manifest. It does not write to disk; consumers receive explicit paths,
 * contents, byte counts, and artifact kinds so installers, tests, previews, or
 * UI previews can decide how and where to materialize the package.
 */
final class PanelPackageTemplate implements \JsonSerializable {

	private PanelPackageManifest $package;
	private string $namespace='App\\Panel\\Packages';
	private array $files=[];
	private array $options=[
		'plugin'=>true,
		'provider'=>true,
		'theme'=>false,
		'docs'=>true,
		'tests'=>true,
		'marketplace'=>true,
	];
	private array $marketplace=[];
	private array $meta=[];

	/**
	 * Normalizes package identity into a package manifest for scaffolding.
	 *
	 * String input is treated as the package id and paired with the optional
	 * label. Array input is delegated to `PanelPackageManifest::from()`, giving
	 * callers one entry point for ad-hoc definitions and already validated
	 * manifest objects.
	 *
	 * @param PanelPackageManifest|array|string $package Package manifest, manifest payload, or package id.
	 * @param string $label Human-readable label used only when `$package` is a string id.
	 */
	public function __construct(PanelPackageManifest|array|string $package, string $label='') {
		$this->package=$package instanceof PanelPackageManifest ? $package : PanelPackageManifest::from(is_array($package) ? $package : ['id'=>(string)$package, 'label'=>$label]);
	}

	/**
	 * Creates a fluent template for a Panel package scaffold.
	 *
	 * @param PanelPackageManifest|array|string $package Package manifest, manifest payload, or package id.
	 * @param string $label Human-readable label used only when `$package` is a string id.
	 * @return self New mutable template instance.
	 */
	public static function make(PanelPackageManifest|array|string $package, string $label=''): self {
		return new self($package, $label);
	}

	/**
	 * Returns the normalized package manifest backing the scaffold.
	 *
	 * @return PanelPackageManifest Manifest used for generated file contents, marketplace defaults, and class naming.
	 */
	public function package(): PanelPackageManifest {
		return $this->package;
	}

	/**
	 * Sets the PHP namespace used inside generated source stubs.
	 *
	 * Empty input is ignored after trimming separators and whitespace, which
	 * keeps the existing namespace stable for chained option builders.
	 *
	 * @param string $namespace Namespace root without leading or trailing backslashes.
	 * @return self Same template for fluent configuration.
	 */
	public function namespace(string $namespace): self {
		$namespace=trim($namespace, "\\ \t\n\r\0\x0B");
		if($namespace!==''){
			$this->namespace=$namespace;
		}
		return $this;
	}

	/**
	 * Enables or disables one known generated artifact group.
	 *
	 * Artifact names are normalized through `Resource::normalizeName()`.
	 * Unknown names are ignored rather than persisted, preserving a closed set
	 * of scaffold switches that downstream installers can understand.
	 *
	 * @param string $artifact Artifact option such as `plugin`, `provider`, `theme`, `docs`, `tests`, or `marketplace`.
	 * @param bool $enabled Whether the default files for that artifact group should be generated.
	 * @return self Same template for fluent configuration.
	 */
	public function with(string $artifact, bool $enabled=true): self {
		$artifact=Resource::normalizeName($artifact);
		if(array_key_exists($artifact, $this->options)){
			$this->options[$artifact]=$enabled;
		}
		return $this;
	}

	/**
	 * Toggles generation of the Panel plugin implementation stub.
	 *
	 * @param bool $enabled Whether `src/*Plugin.php` should be included.
	 * @return self Same template for fluent configuration.
	 */
	public function plugin(bool $enabled=true): self {
		return $this->with('plugin', $enabled);
	}

	/**
	 * Toggles generation of the Panel provider implementation stub.
	 *
	 * @param bool $enabled Whether `src/*Provider.php` should be included.
	 * @return self Same template for fluent configuration.
	 */
	public function provider(bool $enabled=true): self {
		return $this->with('provider', $enabled);
	}

	/**
	 * Toggles generation of the optional Panel theme preset stub.
	 *
	 * @param bool $enabled Whether `src/*Theme.php` should be included.
	 * @return self Same template for fluent configuration.
	 */
	public function theme(bool $enabled=true): self {
		return $this->with('theme', $enabled);
	}

	/**
	 * Toggles generation of README and compatibility documentation files.
	 *
	 * @param bool $enabled Whether documentation artifacts should be included.
	 * @return self Same template for fluent configuration.
	 */
	public function docs(bool $enabled=true): self {
		return $this->with('docs', $enabled);
	}

	/**
	 * Toggles generation of the package regression test stub.
	 *
	 * @param bool $enabled Whether `tests/*PackageTest.php` should be included.
	 * @return self Same template for fluent configuration.
	 */
	public function tests(bool $enabled=true): self {
		return $this->with('tests', $enabled);
	}

	/**
	 * Merges marketplace listing overrides into manifest-derived defaults.
	 *
	 * Overrides are shallow and win over package fields when the final listing
	 * is generated. This keeps marketplace copy customizable without mutating
	 * the canonical package manifest.
	 *
	 * @param array<string, mixed> $listing Marketplace fields such as status, links, support, or description.
	 * @return self Same template for fluent configuration.
	 */
	public function marketplace(array $listing): self {
		$this->marketplace=array_replace($this->marketplace, $listing);
		return $this;
	}

	/**
	 * Adds or replaces a caller-supplied artifact in the generated scaffold.
	 *
	 * Paths are normalized to forward slashes and trimmed to stay relative to
	 * the package root. Empty paths are ignored. Custom files override default
	 * generated files with the same normalized path during artifact assembly.
	 *
	 * @param string $path Package-relative artifact path.
	 * @param string $contents Complete file contents to expose in the manifest.
	 * @return self Same template for fluent configuration.
	 */
	public function file(string $path, string $contents): self {
		$path=trim(str_replace('\\', '/', $path), '/');
		if($path!==''){
			$this->files[$path]=$contents;
		}
		return $this;
	}

	/**
	 * Attaches template metadata for diagnostics or UI consumers.
	 *
	 * Array input is shallow-merged into existing metadata. String keys are
	 * trimmed and ignored when empty, preventing anonymous metadata entries from
	 * leaking into serialized manifests.
	 *
	 * @param array<string, mixed>|string $key Metadata map or single metadata key.
	 * @param mixed $value Value used when `$key` is a string.
	 * @return self Same template for fluent configuration.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->meta=array_replace($this->meta, $key);
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->meta[$key]=$value;
		}
		return $this;
	}

	/**
	 * Builds the ordered artifact list for the current scaffold state.
	 *
	 * Default files are generated from enabled options, caller-provided files
	 * replace defaults by path, and output is sorted by path for deterministic
	 * manifests. Each artifact includes its byte length and coarse kind so UI and
	 * installer flows can summarize the package without parsing file contents.
	 *
	 * @return array<int, array{path:string, contents:string, bytes:int, kind:string}> Deterministic artifact descriptors.
	 */
	public function artifacts(): array {
		$files=$this->defaultFiles();
		foreach($this->files as $path=>$contents){
			$files[$path]=$contents;
		}
		ksort($files);
		$artifacts=[];
		foreach($files as $path=>$contents){
			$artifacts[]=[
				'path'=>$path,
				'contents'=>$contents,
				'bytes'=>strlen($contents),
				'kind'=>$this->kindFromPath($path),
			];
		}
		return $artifacts;
	}

	/**
	 * Serializes scaffold metadata and artifacts into a package template manifest.
	 *
	 * The manifest contains the normalized package payload, namespace, artifact
	 * counts by kind, aggregate byte size, marketplace listing, active scaffold
	 * options, and merged metadata. It is the stable read model consumed by
	 * package installers, marketplace previews, and diagnostics.
	 *
	 * @param array<string, mixed> $meta Per-call metadata merged over template metadata.
	 * @return array<string, mixed> Complete package template manifest.
	 */
	public function manifest(array $meta=[]): array {
		$artifacts=$this->artifacts();
		$kinds=[];
		$bytes=0;
		foreach($artifacts as $artifact){
			$kinds[$artifact['kind']]=($kinds[$artifact['kind']] ?? 0)+1;
			$bytes+=(int)$artifact['bytes'];
		}
		ksort($kinds);
		return [
			'type'=>'panel_package_template',
			'package'=>$this->package->toArray(),
			'namespace'=>$this->namespace,
			'artifact_count'=>count($artifacts),
			'artifact_kinds'=>$kinds,
			'bytes'=>$bytes,
			'artifacts'=>$artifacts,
			'marketplace'=>$this->marketplaceListing(),
			'options'=>$this->options,
			'meta'=>array_replace($this->meta, $meta),
		];
	}

	/**
	 * Serializes the current package template manifest with stored metadata.
	 *
	 * @return array<string, mixed> Template manifest without extra call-site metadata.
	 */
	public function toArray(): array {
		return $this->manifest();
	}

	/**
	 * Exposes the template manifest to json_encode().
	 *
	 * @return array<string, mixed> Serializable template manifest.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Generates the default file map for all enabled artifact groups.
	 *
	 * @return array<string, string> Package-relative paths mapped to generated file contents.
	 */
	private function defaultFiles(): array {
		$files=[
			'dataphyre-panel-package.json'=>json_encode($this->package->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
		];
		$class=$this->classBase();
		if($this->options['plugin']){
			$files['src/'.$class.'Plugin.php']=$this->pluginStub($class);
		}
		if($this->options['provider']){
			$files['src/'.$class.'Provider.php']=$this->providerStub($class);
		}
		if($this->options['theme']){
			$files['src/'.$class.'Theme.php']=$this->themeStub($class);
		}
		if($this->options['docs']){
			$files['README.md']=$this->readmeStub();
			$files['docs/compatibility.md']=$this->compatibilityStub();
		}
		if($this->options['tests']){
			$files['tests/'.$class.'PackageTest.php']=$this->testStub($class);
		}
		if($this->options['marketplace']){
			$files['marketplace/listing.json']=json_encode($this->marketplaceListing(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
		}
		return $files;
	}

	/**
	 * Builds the marketplace listing from package fields and overrides.
	 *
	 * @return array<string, mixed> Marketplace-ready metadata payload.
	 */
	private function marketplaceListing(): array {
		$package=$this->package->toArray();
		return array_replace([
			'id'=>$package['id'] ?? '',
			'label'=>$package['label'] ?? '',
			'version'=>$package['version'] ?? null,
			'type'=>$package['type'] ?? 'plugin',
			'description'=>$package['description'] ?? '',
			'status'=>$package['status'] ?? 'stable',
			'provides'=>$package['provides'] ?? [],
			'requirements'=>$package['requirements'] ?? [],
			'support'=>$package['support'] ?? [],
			'links'=>$package['links'] ?? [],
		], $this->marketplace);
	}

	/**
	 * Converts the package id into the PascalCase base used for generated classes.
	 *
	 * @return string Class-name-safe base derived from underscores and hyphens in the package id.
	 */
	private function classBase(): string {
		return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $this->package->id())));
	}

	/**
	 * Generates the package plugin implementation stub.
	 *
	 * @param string $class PascalCase class base derived from the package id.
	 * @return string PHP source for the generated `PanelPlugin` implementation.
	 */
	private function pluginStub(string $class): string {
		$id=$this->package->id();
		$label=addslashes((string)$this->package->label());
		return "<?php\nnamespace {$this->namespace};\n\nuse Dataphyre\\Panel\\PanelInstance;\nuse Dataphyre\\Panel\\PanelPlugin;\n\nfinal class {$class}Plugin implements PanelPlugin {\n\tpublic function id(): string { return '{$id}'; }\n\tpublic function label(): string { return '{$label}'; }\n\tpublic function register(PanelInstance \$panel): void {}\n\tpublic function boot(PanelInstance \$panel): void {}\n}\n";
	}

	/**
	 * Generates the package provider implementation stub.
	 *
	 * @param string $class PascalCase class base derived from the package id.
	 * @return string PHP source for the generated `PanelProvider` implementation.
	 */
	private function providerStub(string $class): string {
		return "<?php\nnamespace {$this->namespace};\n\nuse Dataphyre\\Panel\\PanelInstance;\nuse Dataphyre\\Panel\\PanelProvider;\n\nfinal class {$class}Provider implements PanelProvider {\n\tpublic function panel(PanelInstance \$panel): PanelInstance {\n\t\treturn \$panel;\n\t}\n}\n";
	}

	/**
	 * Generates the optional theme preset stub.
	 *
	 * @param string $class PascalCase class base derived from the package id.
	 * @return string PHP source exposing the package theme preset.
	 */
	private function themeStub(string $class): string {
		$name=$this->package->id();
		return "<?php\nnamespace {$this->namespace};\n\nuse Dataphyre\\Panel\\PanelThemePreset;\n\nfinal class {$class}Theme {\n\tpublic static function preset(): PanelThemePreset {\n\t\treturn PanelThemePreset::make('{$name}');\n\t}\n}\n";
	}

	/**
	 * Generates the default package README.
	 *
	 * @return string Markdown summary sourced from the package manifest.
	 */
	private function readmeStub(): string {
		$package=$this->package->toArray();
		return "# ".$package['label']."\n\n".$package['description']."\n\n## Compatibility\n\nSee `docs/compatibility.md` and `dataphyre-panel-package.json`.\n";
	}

	/**
	 * Generates compatibility documentation from package requirements.
	 *
	 * @return string Markdown document containing the requirements JSON block.
	 */
	private function compatibilityStub(): string {
		$package=$this->package->toArray();
		return "# Compatibility\n\n```json\n".(json_encode($package['requirements'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')."\n```\n";
	}

	/**
	 * Generates the package regression test stub.
	 *
	 * @param string $class PascalCase class base used in the failure message.
	 * @return string PHP source for the generated package smoke test.
	 */
	private function testStub(string $class): string {
		return "<?php\nuse Dataphyre\\Panel\\Panel;\nuse Dataphyre\\Panel\\PanelTestHarness;\n\n\$panel=Panel::make('{$this->package->id()}_test');\n\$suite=\$panel->regressionSuite('{$this->package->id()}_package');\n\$suite->check('package boots', function(PanelTestHarness \$test): string { return 'Package boot contract present.'; });\n\$report=\$suite->run();\nif(!\$report->ok()){\n\tthrow new RuntimeException('{$class} package regression failed.');\n}\n";
	}

	/**
	 * Classifies an artifact path for manifest summaries.
	 *
	 * @param string $path Package-relative artifact path.
	 * @return string One of `manifest`, `marketplace`, `documentation`, `test`, `source`, or `asset`.
	 */
	private function kindFromPath(string $path): string {
		if(str_ends_with($path, '.json')){
			return str_contains($path, 'marketplace/') ? 'marketplace' : 'manifest';
		}
		if(str_ends_with($path, '.md')){
			return 'documentation';
		}
		if(str_contains($path, '/tests/') || str_starts_with($path, 'tests/')){
			return 'test';
		}
		if(str_ends_with($path, '.php')){
			return 'source';
		}
		return 'asset';
	}
}
