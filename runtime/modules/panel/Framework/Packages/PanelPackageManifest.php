<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes a package that extends the panel runtime.
 *
 * A package manifest is the serializable schema used by package registries, operator diagnostics, and panel
 * bootstrapping code to understand what a plugin or package is, what it requires, what it provides, and where support or
 * provenance metadata can be found. The object is mutable by design so package definitions can be assembled fluently while
 * still emitting a stable array shape through toArray() and jsonSerialize().
 *
 * Identifiers, package types, statuses, requirements, modules, themes, provided capabilities, support keys, and signature
 * keys are normalized into panel resource names. Human-facing labels, descriptions, links, and metadata remain caller
 * supplied so registry output can preserve display text and external URLs.
 */
final class PanelPackageManifest implements \JsonSerializable {

	/** @var string Normalized package id used as the registry key. */
	private string $id;
	/** @var string Human-facing package label. */
	private string $label;
	/** @var ?string Package version displayed by registry and compatibility reports. */
	private ?string $version=null;
	/** @var ?string Human-facing package summary. */
	private ?string $description=null;
	/** @var ?class-string Package or plugin class when the package is backed by PHP code. */
	private ?string $class=null;
	/** @var ?string Normalized package type such as plugin, theme, or integration. */
	private ?string $type='plugin';
	/** @var string Normalized lifecycle status such as stable, beta, or deprecated. */
	private string $status='stable';
	/** @var array{php:?string, panel:?string, reactor:?string, modules:array<string, string>, themes:array<int, string>} Compatibility requirements. */
	private array $requirements=[
		'php'=>null,
		'panel'=>null,
		'reactor'=>null,
		'modules'=>[],
		'themes'=>[],
	];
	/** @var array<int, string> Normalized capabilities or extension points supplied by this package. */
	private array $provides=[];
	/** @var array<int, array{label:string, target:string}> External documentation, repository, or support links. */
	private array $links=[];
	/** @var array<string, mixed> Support channels and maintainer-facing contact metadata. */
	private array $support=[];
	/** @var array<string, mixed> Provenance, checksum, publisher, or signature metadata. */
	private array $signature=[];
	/** @var array<string, mixed> Free-form registry metadata preserved in serialized output. */
	private array $meta=[];

	/**
	 * Creates a manifest with a normalized package id and display label.
	 *
	 * Blank or non-normalizable ids fall back to panel_package so registry consumers always receive a non-empty key. Blank
	 * labels are generated from the normalized id to keep diagnostics readable even for minimal definitions.
	 *
	 * @param string $id Package id, class name, or registry name.
	 * @param string $label Optional human-facing label.
	 */
	public function __construct(string $id, string $label='') {
		$this->id=Resource::normalizeName($id) ?: 'panel_package';
		$this->label=trim($label)!=='' ? trim($label) : self::humanize($this->id);
	}

	/**
	 * Builds a manifest for fluent package declarations.
	 *
	 * @param string $id Package id, class name, or registry name.
	 * @param string $label Optional human-facing label.
	 * @return self New manifest with normalized identity fields.
	 */
	public static function make(string $id, string $label=''): self {
		return new self($id, $label);
	}

	/**
	 * Converts plugin objects, class names, arrays, and manifests into a package manifest.
	 *
	 * Existing manifests are returned unchanged. PanelPlugin instances are first converted through PluginManifest so plugin
	 * metadata stays consistent with the plugin contract, then enriched with the plugin class and common provided
	 * capabilities. String input is treated as a class name or package id. Array input accepts registry-like keys including
	 * id, name, label, title, version, description, type, status, class, requires, requirements, provides, links, support,
	 * signature, and meta.
	 *
	 * @param PanelPlugin|array<string, mixed>|string|self $package Package source.
	 * @param array<string, mixed> $config Configuration passed to PluginManifest when converting PanelPlugin instances.
	 * @return self Manifest representing the supplied package source.
	 */
	public static function from(PanelPlugin|array|string|self $package, array $config=[]): self {
		if($package instanceof self){
			return $package;
		}
		if($package instanceof PanelPlugin){
			$manifest=PluginManifest::from($package, $config)->toArray();
			$instance=new self((string)($manifest['id'] ?? $package->id()), (string)($manifest['label'] ?? ''));
			$instance->version((string)($manifest['version'] ?? ''));
			$instance->description((string)($manifest['description'] ?? ''));
			$instance->class($package::class);
			$instance->type('plugin');
			$instance->provides(['plugin', 'render_hooks', 'resources']);
			return $instance;
		}
		if(is_string($package)){
			$class=trim($package);
			$instance=new self(Resource::normalizeName($class) ?: 'panel_package', self::humanize($class));
			if(class_exists($class)){
				$instance->class($class);
				if(is_subclass_of($class, PanelPlugin::class)){
					$instance->type('plugin');
					$instance->provides(['plugin']);
				}
			}
			return $instance;
		}
		$id=(string)($package['id'] ?? $package['name'] ?? 'panel_package');
		$instance=new self($id, (string)($package['label'] ?? $package['title'] ?? ''));
		$instance
			->version((string)($package['version'] ?? ''))
			->description((string)($package['description'] ?? ''))
			->type((string)($package['type'] ?? 'plugin'))
			->status((string)($package['status'] ?? 'stable'));
		if(isset($package['class'])){
			$instance->class((string)$package['class']);
		}
		if(isset($package['requires']) && is_array($package['requires'])){
			$instance->requires($package['requires']);
		}
		if(isset($package['requirements']) && is_array($package['requirements'])){
			$instance->requires($package['requirements']);
		}
		if(isset($package['provides'])){
			$instance->provides((array)$package['provides']);
		}
		foreach((array)($package['links'] ?? []) as $link){
			if(is_array($link)){
				$instance->link((string)($link['label'] ?? $link['target'] ?? 'Link'), (string)($link['target'] ?? ''));
			}
		}
		if(isset($package['support']) && is_array($package['support'])){
			$instance->support($package['support']);
		}
		if(isset($package['signature']) && is_array($package['signature'])){
			$instance->signature($package['signature']);
		}
		if(isset($package['meta']) && is_array($package['meta'])){
			$instance->meta($package['meta']);
		}
		return $instance;
	}

	/**
	 * Returns the normalized registry id.
	 *
	 * @return string Non-empty package id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Reads or replaces the human-facing display label.
	 *
	 * Null reads the current label. Non-empty strings replace the label; blank strings are ignored to avoid accidentally
	 * erasing a useful generated label during config merging.
	 *
	 * @param ?string $label Label to store; null reads the current value.
	 * @return string|self Current label when reading, otherwise this manifest for fluent assembly.
	 */
	public function label(?string $label=null): string|self {
		if($label===null){
			return $this->label;
		}
		$label=trim($label);
		if($label!==''){
			$this->label=$label;
		}
		return $this;
	}

	/**
	 * Reads or replaces the package version string.
	 *
	 * Blank versions are stored as null so serialized manifests can distinguish "unknown version" from a literal empty
	 * string.
	 *
	 * @param ?string $version Version to store; null reads the current value.
	 * @return string|null|self Current version when reading, otherwise this manifest for fluent assembly.
	 */
	public function version(?string $version=null): string|null|self {
		if($version===null){
			return $this->version;
		}
		$version=trim($version);
		$this->version=$version!=='' ? $version : null;
		return $this;
	}

	/**
	 * Reads or replaces the package description.
	 *
	 * Blank descriptions are stored as null so registries can distinguish absent
	 * package copy from an intentionally meaningful string.
	 *
	 * @param ?string $description Description to store; null reads the current value.
	 * @return string|null|self Current description when reading, otherwise this manifest for fluent assembly.
	 */
	public function description(?string $description=null): string|null|self {
		if($description===null){
			return $this->description;
		}
		$description=trim($description);
		$this->description=$description!=='' ? $description : null;
		return $this;
	}

	/**
	 * Reads or replaces the PHP class that backs the package.
	 *
	 * The class string is not validated here because registry generation may run before optional package code is installed.
	 * Callers that need runtime safety should validate class existence before activating the package.
	 *
	 * @param ?string $class Class name to store; null reads the current value.
	 * @return string|null|self Current class name when reading, otherwise this manifest for fluent assembly.
	 */
	public function class(?string $class=null): string|null|self {
		if($class===null){
			return $this->class;
		}
		$class=trim($class);
		$this->class=$class!=='' ? $class : null;
		return $this;
	}

	/**
	 * Reads or replaces the normalized package type.
	 *
	 * Empty type input falls back to plugin, matching the default package role in the panel ecosystem.
	 *
	 * @param ?string $type Type token to normalize and store; null reads the current value.
	 * @return string|null|self Current type when reading, otherwise this manifest for fluent assembly.
	 */
	public function type(?string $type=null): string|null|self {
		if($type===null){
			return $this->type;
		}
		$type=Resource::normalizeName($type);
		$this->type=$type!=='' ? $type : 'plugin';
		return $this;
	}

	/**
	 * Reads or replaces the normalized lifecycle status.
	 *
	 * Blank status input falls back to stable so registry consumers always receive a lifecycle value.
	 *
	 * @param ?string $status Status token to normalize and store; null reads the current value.
	 * @return string|self Current status when reading, otherwise this manifest for fluent assembly.
	 */
	public function status(?string $status=null): string|self {
		if($status===null){
			return $this->status;
		}
		$status=Resource::normalizeName($status);
		$this->status=$status!=='' ? $status : 'stable';
		return $this;
	}

	/**
	 * Adds runtime-level requirements from a key/value map or one named requirement.
	 *
	 * Supported top-level requirement keys are php, panel, reactor, modules, and themes. Module requirements are delegated to
	 * requiresModule() so module names and constraints are normalized consistently. Theme requirements are delegated to
	 * requiresTheme() and are treated as installed/missing rather than semver ranges.
	 *
	 * @param array<string, mixed>|string $name Requirement map or top-level requirement name.
	 * @param ?string $constraint Version constraint for php, panel, or reactor when $name is a string.
	 * @return self Same manifest after merging recognized requirements.
	 */
	public function requires(array|string $name, ?string $constraint=null): self {
		if(is_array($name)){
			foreach($name as $key=>$value){
				if($key==='modules' && is_array($value)){
					foreach($value as $module=>$moduleConstraint){
						$this->requiresModule((string)$module, (string)$moduleConstraint);
					}
					continue;
				}
				if($key==='themes' && is_array($value)){
					foreach($value as $theme){
						$this->requiresTheme((string)$theme);
					}
					continue;
				}
				if(in_array($key, ['php', 'panel', 'reactor'], true)){
					$this->requirements[$key]=trim((string)$value) ?: null;
				}
			}
			return $this;
		}
		$name=Resource::normalizeName($name);
		if(in_array($name, ['php', 'panel', 'reactor'], true)){
			$this->requirements[$name]=trim((string)$constraint) ?: null;
		}
		return $this;
	}

	/**
	 * Adds a module dependency and version constraint.
	 *
	 * @param string $module Module name required by the package.
	 * @param string $constraint Version constraint, or wildcard when any installed version is acceptable.
	 * @return self Same manifest after recording the module requirement.
	 */
	public function requiresModule(string $module, string $constraint='*'): self {
		$module=Resource::normalizeName($module);
		if($module!==''){
			$this->requirements['modules'][$module]=trim($constraint) ?: '*';
		}
		return $this;
	}

	/**
	 * Adds a required panel theme.
	 *
	 * Duplicate theme requirements are ignored after normalization.
	 *
	 * @param string $theme Theme id required by the package.
	 * @return self Same manifest after recording the theme requirement.
	 */
	public function requiresTheme(string $theme): self {
		$theme=Resource::normalizeName($theme);
		if($theme!=='' && !in_array($theme, $this->requirements['themes'], true)){
			$this->requirements['themes'][]=$theme;
		}
		return $this;
	}

	/**
	 * Adds capabilities, extension points, or package roles provided by this manifest.
	 *
	 * Duplicate and blank capability names are discarded so serialized manifests stay compact and deterministic.
	 *
	 * @param array<int, string>|string $provides Capability name or list of names.
	 * @return self Same manifest after merging provided capabilities.
	 */
	public function provides(array|string $provides): self {
		foreach((array)$provides as $provide){
			$provide=Resource::normalizeName((string)$provide);
			if($provide!=='' && !in_array($provide, $this->provides, true)){
				$this->provides[]=$provide;
			}
		}
		return $this;
	}

	/**
	 * Adds an external link to documentation, repository, support, or release information.
	 *
	 * Empty targets are ignored because links without destinations are not actionable in operator UI or registry output.
	 *
	 * @param string $label Human-facing link label.
	 * @param string $target URL, route, or package registry target.
	 * @return self Same manifest after appending a valid link.
	 */
	public function link(string $label, string $target): self {
		$target=trim($target);
		if($target===''){
			return $this;
		}
		$this->links[]=[
			'label'=>trim($label) ?: $target,
			'target'=>$target,
		];
		return $this;
	}

	/**
	 * Adds support metadata for maintainers and operators.
	 *
	 * Typical keys include email, docs, issues, repository, sponsor, or escalation contacts. Keys are normalized to resource
	 * names so registry consumers can present a predictable schema.
	 *
	 * @param array<string, mixed>|string $key Support map or support key.
	 * @param mixed $value Support value when setting one key.
	 * @return self Same manifest after merging support metadata.
	 */
	public function support(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->support=array_replace($this->support, $key);
			return $this;
		}
		$key=Resource::normalizeName($key);
		if($key!==''){
			$this->support[$key]=$value;
		}
		return $this;
	}

	/**
	 * Adds provenance or integrity metadata.
	 *
	 * This manifest stores signature data only; it does not verify checksums, publishers, certificates, or trust policy.
	 * Verification belongs to the package installer or registry consumer that has access to the package artifact.
	 *
	 * @param array<string, mixed>|string $key Signature map or normalized signature key.
	 * @param mixed $value Signature value when setting one key.
	 * @return self Same manifest after merging signature metadata.
	 */
	public function signature(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->signature=array_replace($this->signature, $key);
			return $this;
		}
		$key=Resource::normalizeName($key);
		if($key!==''){
			$this->signature[$key]=$value;
		}
		return $this;
	}

	/**
	 * Adds free-form metadata preserved in manifest serialization.
	 *
	 * Metadata keys are only trimmed, not resource-normalized, so package authors can use external schema keys where needed.
	 *
	 * @param array<string, mixed>|string $key Metadata map or metadata key.
	 * @param mixed $value Metadata value when setting one key.
	 * @return self Same manifest after merging metadata.
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
	 * Evaluates the manifest requirements against a runtime inventory.
	 *
	 * Runtime inventory may include php, panel, reactor, modules, and themes keys. Versioned requirements use
	 * matchesConstraint(); themes are presence checks against the runtime theme list. Missing required versions are reported
	 * as actual "missing" values so diagnostics can distinguish absent runtime data from a failed comparison.
	 *
	 * @param array{php?:string, panel?:string, reactor?:string, modules?:array<string, string>, themes?:array<int, string>} $runtime Runtime versions and installed assets.
	 * @return array{ok:bool, status:string, checks:array<int, array{name:string, expected:string, actual:string, ok:bool}>} Compatibility result.
	 */
	public function compatibility(array $runtime): array {
		$checks=[];
		foreach(['php', 'panel', 'reactor'] as $key){
			$constraint=$this->requirements[$key] ?? null;
			if($constraint===null || $constraint===''){
				continue;
			}
			$version=(string)($runtime[$key] ?? '');
			$checks[]=$this->checkVersion($key, $version, $constraint);
		}
		foreach($this->requirements['modules'] as $module=>$constraint){
			$version=(string)($runtime['modules'][$module] ?? '');
			$checks[]=$this->checkVersion('module:'.$module, $version, $constraint);
		}
		foreach($this->requirements['themes'] as $theme){
			$checks[]=[
				'name'=>'theme:'.$theme,
				'expected'=>'installed',
				'actual'=>in_array($theme, (array)($runtime['themes'] ?? []), true) ? 'installed' : 'missing',
				'ok'=>in_array($theme, (array)($runtime['themes'] ?? []), true),
			];
		}
		$ok=count(array_filter($checks, static fn(array $check): bool => ($check['ok'] ?? false)!==true))===0;
		return [
			'ok'=>$ok,
			'status'=>$ok ? 'compatible' : 'blocked',
			'checks'=>$checks,
		];
	}

	/**
	 * Serializes the manifest for registries, diagnostics, and JSON output.
	 *
	 * Passing a runtime inventory includes a live compatibility block. Leaving runtime empty keeps compatibility null so
	 * static package metadata can be rendered without implying that a runtime check was performed.
	 *
	 * @param array<string, mixed> $runtime Optional runtime inventory for compatibility evaluation.
	 * @return array{id:string, label:string, version:?string, description:?string, class:?string, type:?string, status:string, requirements:array<string, mixed>, provides:array<int, string>, links:array<int, array{label:string, target:string}>, support:array<string, mixed>, signature:array<string, mixed>, compatibility:?array<string, mixed>, meta:array<string, mixed>} Serialized manifest.
	 */
	public function toArray(array $runtime=[]): array {
		return [
			'id'=>$this->id,
			'label'=>$this->label,
			'version'=>$this->version,
			'description'=>$this->description,
			'class'=>$this->class,
			'type'=>$this->type,
			'status'=>$this->status,
			'requirements'=>$this->requirements,
			'provides'=>$this->provides,
			'links'=>$this->links,
			'support'=>$this->support,
			'signature'=>$this->signature,
			'compatibility'=>$runtime!==[] ? $this->compatibility($runtime) : null,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Serializes the manifest for json_encode().
	 *
	 * JSON serialization is static and does not include runtime compatibility because JsonSerializable receives no runtime
	 * inventory. Call toArray($runtime) directly when compatibility checks should be embedded.
	 *
	 * @return array<string, mixed> Manifest payload without runtime compatibility evaluation.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Builds one version compatibility check record.
	 *
	 * @param string $name Runtime or module name being checked.
	 * @param string $actual Runtime version discovered for the name.
	 * @param string $constraint Manifest constraint that must be satisfied.
	 * @return array{name:string, expected:string, actual:string, ok:bool} Compatibility check row.
	 */
	private function checkVersion(string $name, string $actual, string $constraint): array {
		$actual=trim($actual);
		$ok=$actual!=='' && self::matchesConstraint($actual, $constraint);
		return [
			'name'=>$name,
			'expected'=>$constraint,
			'actual'=>$actual!=='' ? $actual : 'missing',
			'ok'=>$ok,
		];
	}

	/**
	 * Tests a version string against the manifest's compact constraint language.
	 *
	 * Supported constraints are wildcard or blank, comma-separated exact versions, comparison operators accepted by
	 * version_compare(), and caret ranges limited to the same major version. Every comma-separated part must match.
	 *
	 * @param string $version Actual runtime or module version.
	 * @param string $constraint Constraint expression from the manifest.
	 * @return bool Whether the actual version satisfies every constraint part.
	 */
	public static function matchesConstraint(string $version, string $constraint): bool {
		$constraint=trim($constraint);
		if($constraint==='' || $constraint==='*'){
			return true;
		}
		foreach(preg_split('/\s*,\s*/', $constraint) ?: [] as $part){
			$part=trim($part);
			if($part==='' || $part==='*'){
				continue;
			}
			if($part[0]==='^'){
				$base=substr($part, 1);
				$major=(int)explode('.', $base)[0];
				if(!version_compare($version, $base, '>=') || !version_compare($version, (string)($major + 1).'.0.0', '<')){
					return false;
				}
				continue;
			}
			if(preg_match('/^(>=|<=|>|<|=|==)\s*(.+)$/', $part, $matches)===1){
				$operator=$matches[1]==='==' ? '=' : $matches[1];
				if(!version_compare($version, trim($matches[2]), $operator)){
					return false;
				}
				continue;
			}
			if(!version_compare($version, $part, '=')){
				return false;
			}
		}
		return true;
	}

	/**
	 * Converts ids and class names into readable default labels.
	 *
	 * @param string $value Raw id, slug, or class name.
	 * @return string Title-cased label, or "Panel Package" for blank input.
	 */
	private static function humanize(string $value): string {
		$value=trim(preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace(['_', '-', '\\'], ' ', $value)) ?? $value);
		return $value==='' ? 'Panel Package' : ucwords($value);
	}
}
