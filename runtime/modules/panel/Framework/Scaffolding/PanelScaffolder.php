<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Generates starter PHP artifacts for Dataphyre panel applications.
 *
 * The scaffolder does not write files directly. It produces `PanelScaffoldResult`
 * objects containing target paths, generated PHP source, metadata, and registration
 * hints so callers can preview, batch, persist, or reject generated artifacts.
 */
final class PanelScaffolder {

	private ?PanelInstance $panel;

	/**
	 * Creates a scaffolder bound to an optional panel instance for metadata hints.
	 *
	 * @param ?PanelInstance $panel Panel instance used to infer panel labels and names.
	 */
	private function __construct(?PanelInstance $panel=null) {
		$this->panel=$panel;
	}

	/**
	 * Creates a scaffolder for standalone or panel-bound generation.
	*
	 * @param ?PanelInstance $panel Optional panel context for generated metadata.
	 * @return self Scaffolder instance.
	 */
	public static function make(?PanelInstance $panel=null): self {
		return new self($panel);
	}

	/**
	 * Generates a resource class skeleton with query, columns, and fields.
	*
	 * Options may provide `namespace`, `name`, `label`, `plural_label`, `icon`,
	 * `columns`, `fields`, `path`, and `base_path`. Column and field definitions
	 * are normalized to field names so the starter source stays compact and valid.
	*
	 * @param string $class Short class name or fully-qualified resource class.
	 * @param array<string, mixed> $options Resource generation options.
	 * @return PanelScaffoldResult Generated resource artifact.
	 */
	public function resource(string $class, array $options=[]): PanelScaffoldResult {
		[$namespace, $short]=self::splitClass($class, (string)($options['namespace'] ?? 'App\\Panel\\Resources'));
		$name=Resource::normalizeName((string)($options['name'] ?? self::resourceNameFromClass($short))) ?: 'resource';
		$label=(string)($options['label'] ?? self::headline($name));
		$plural=(string)($options['plural_label'] ?? self::pluralize($label));
		$icon=(string)($options['icon'] ?? 'database');
		$columns=self::fieldNames($options['columns'] ?? ['id', 'name', 'status']);
		$fields=self::fieldNames($options['fields'] ?? $columns);
		$columnLines=[];
		foreach($columns as $column){
			$columnLines[]="\t\t\t\$panel->column('".self::quote($column)."')->searchable()->sortable(),";
		}
		$fieldLines=[];
		foreach($fields as $field){
			$fieldLines[]="\t\t\t\$panel->field('".self::quote($field)."'),";
		}
		$contents=self::php($namespace, [
			'use Dataphyre\\Panel\\PanelInstance;',
			'use Dataphyre\\Panel\\Resource;',
		], "final class {$short} {\n\n"
			."\tpublic static function make(PanelInstance \$panel): Resource {\n"
			."\t\treturn \$panel->resource('".self::quote($name)."')\n"
			."\t\t\t->label('".self::quote($label)."')\n"
			."\t\t\t->pluralLabel('".self::quote($plural)."')\n"
			."\t\t\t->icon('".self::quote($icon)."')\n"
			."\t\t\t->queryUsing(static fn(): array => [])\n"
			."\t\t\t->columns([\n".implode("\n", $columnLines)."\n\t\t\t])\n"
			."\t\t\t->fields([\n".implode("\n", $fieldLines)."\n\t\t\t]);\n"
			."\t}\n"
			."}\n");
		return $this->result('resource', $name, $namespace.'\\'.$short, $options, $contents, [
			'columns'=>$columns,
			'fields'=>$fields,
			'register'=>'$panel->register('.$short.'::make($panel));',
		]);
	}

	/**
	 * Generates a custom panel page class skeleton.
	*
	 * Options may provide `namespace`, `name`, `label`, `group`, `icon`, `path`,
	 * and `base_path`. The generated page includes a minimal render callback so it
	 * can be registered and opened immediately.
	 *
	 * @param string $class Short class name or fully-qualified page class.
	 * @param array<string, mixed> $options Page generation options.
	 * @return PanelScaffoldResult Generated page artifact.
	 */
	public function page(string $class, array $options=[]): PanelScaffoldResult {
		[$namespace, $short]=self::splitClass($class, (string)($options['namespace'] ?? 'App\\Panel\\Pages'));
		$name=Resource::normalizeName((string)($options['name'] ?? self::pageNameFromClass($short))) ?: 'page';
		$label=(string)($options['label'] ?? self::headline($name));
		$group=(string)($options['group'] ?? 'Pages');
		$icon=(string)($options['icon'] ?? 'layout-dashboard');
		$contents=self::php($namespace, [
			'use Dataphyre\\Panel\\PanelInstance;',
			'use Dataphyre\\Panel\\PanelPage;',
		], "final class {$short} {\n\n"
			."\tpublic static function make(PanelInstance \$panel): PanelPage {\n"
			."\t\treturn \$panel->page('".self::quote($name)."')\n"
			."\t\t\t->label('".self::quote($label)."')\n"
			."\t\t\t->group('".self::quote($group)."')\n"
			."\t\t\t->icon('".self::quote($icon)."')\n"
			."\t\t\t->render(static fn(): string => '<section class=\"dp-panel-card\"><h2>".self::quote($label)."</h2><p>Replace this generated page body.</p></section>');\n"
			."\t}\n"
			."}\n");
		return $this->result('page', $name, $namespace.'\\'.$short, $options, $contents, [
			'register'=>'$panel->registerPage('.$short.'::make($panel));',
		]);
	}

	/**
	 * Generates a panel provider class that registers resources and pages.
	*
	 * Resource and page lists are normalized to fully-qualified class strings
	 * without leading slashes. The generated provider applies a panel label and
	 * emits registration calls for each configured artifact.
	 *
	 * @param string $class Short class name or fully-qualified provider class.
	 * @param array<string, mixed> $options Provider generation options.
	 * @return PanelScaffoldResult Generated provider artifact.
	 */
	public function provider(string $class, array $options=[]): PanelScaffoldResult {
		[$namespace, $short]=self::splitClass($class, (string)($options['namespace'] ?? 'App\\Panel'));
		$panelLabel=(string)($options['label'] ?? self::headline($this->panel?->name() ?: 'Panel'));
		$resources=self::classList($options['resources'] ?? []);
		$pages=self::classList($options['pages'] ?? []);
		$body="\tpublic function panel(PanelInstance \$panel): PanelInstance {\n"
			."\t\t\$panel->label('".self::quote($panelLabel)."');\n";
		foreach($resources as $resource){
			$body.="\t\t\$panel->register(\\{$resource}::make(\$panel));\n";
		}
		foreach($pages as $page){
			$body.="\t\t\$panel->registerPage(\\{$page}::make(\$panel));\n";
		}
		$body.="\t\treturn \$panel;\n\t}\n";
		$contents=self::php($namespace, [
			'use Dataphyre\\Panel\\PanelInstance;',
			'use Dataphyre\\Panel\\PanelProvider;',
		], "final class {$short} implements PanelProvider {\n\n{$body}}\n");
		return $this->result('provider', Resource::normalizeName($short), $namespace.'\\'.$short, $options, $contents, [
			'resources'=>$resources,
			'pages'=>$pages,
			'register'=>'$panel->provide('.$short.'::class);',
		]);
	}

	/**
	 * Generates a panel plugin class skeleton.
	*
	 * The plugin source includes identity, label, version, register, and boot hooks.
	 * Hook bodies intentionally contain only starter comments in the generated file,
	 * because the user-facing extension behavior belongs in the new artifact.
	*
	 * @param string $class Short class name or fully-qualified plugin class.
	 * @param array<string, mixed> $options Plugin generation options.
	 * @return PanelScaffoldResult Generated plugin artifact.
	 */
	public function plugin(string $class, array $options=[]): PanelScaffoldResult {
		[$namespace, $short]=self::splitClass($class, (string)($options['namespace'] ?? 'App\\Panel\\Plugins'));
		$id=Resource::normalizeName((string)($options['id'] ?? self::resourceNameFromClass($short))) ?: 'panel_plugin';
		$label=(string)($options['label'] ?? self::headline($id));
		$version=(string)($options['version'] ?? '1.0.0');
		$contents=self::php($namespace, [
			'use Dataphyre\\Panel\\PanelInstance;',
			'use Dataphyre\\Panel\\PanelPlugin;',
		], "final class {$short} implements PanelPlugin {\n\n"
			."\tpublic function id(): string {\n\t\treturn '".self::quote($id)."';\n\t}\n\n"
			."\tpublic function label(): string {\n\t\treturn '".self::quote($label)."';\n\t}\n\n"
			."\tpublic function version(): string {\n\t\treturn '".self::quote($version)."';\n\t}\n\n"
			."\tpublic function register(PanelInstance \$panel): void {\n\t\t// Register resources, pages, commands, widgets, or macros here.\n\t}\n\n"
			."\tpublic function boot(PanelInstance \$panel): void {\n\t\t// Attach render hooks or late-bound behavior here.\n\t}\n"
			."}\n");
		return $this->result('plugin', $id, $namespace.'\\'.$short, $options, $contents, [
			'version'=>$version,
			'register'=>'$panel->plugin('.$short.'::class);',
		]);
	}

	/**
	 * Generates a panel theme preset class skeleton.
	*
	 * Options may provide `namespace`, `name`, `primary`, `radius`, `path`, and
	 * `base_path`. The generated preset sets baseline color and radius tokens that
	 * can be expanded by the application.
	*
	 * @param string $class Short class name or fully-qualified theme class.
	 * @param array<string, mixed> $options Theme generation options.
	 * @return PanelScaffoldResult Generated theme artifact.
	 */
	public function theme(string $class, array $options=[]): PanelScaffoldResult {
		[$namespace, $short]=self::splitClass($class, (string)($options['namespace'] ?? 'App\\Panel\\Themes'));
		$name=Resource::normalizeName((string)($options['name'] ?? self::resourceNameFromClass($short))) ?: 'theme';
		$primary=(string)($options['primary'] ?? '#2563eb');
		$radius=(string)($options['radius'] ?? '10px');
		$contents=self::php($namespace, [
			'use Dataphyre\\Panel\\PanelThemePreset;',
		], "final class {$short} {\n\n"
			."\tpublic static function preset(): PanelThemePreset {\n"
			."\t\treturn PanelThemePreset::make('".self::quote($name)."')\n"
			."\t\t\t->colors(['primary'=>'blue'])\n"
			."\t\t\t->token('primary_600', '".self::quote($primary)."')\n"
			."\t\t\t->radius('".self::quote($radius)."');\n"
			."\t}\n"
			."}\n");
		return $this->result('theme', $name, $namespace.'\\'.$short, $options, $contents, [
			'primary'=>$primary,
			'radius'=>$radius,
			'register'=>'Panel::registerThemePreset('.$short.'::preset());',
		]);
	}

	/**
	 * Generates a minimal panel test class for rendering one resource index.
	*
	 * The scaffolded test uses `Panel::test()` and `PanelTestHarness::assertOk()`
	 * so generated panel resources have an immediate smoke-test entrypoint.
	*
	 * @param string $class Short class name or fully-qualified test class.
	 * @param array<string, mixed> $options Test generation options.
	 * @return PanelScaffoldResult Generated test artifact.
	 */
	public function test(string $class, array $options=[]): PanelScaffoldResult {
		[$namespace, $short]=self::splitClass($class, (string)($options['namespace'] ?? 'Tests\\Panel'));
		$resource=Resource::normalizeName((string)($options['resource'] ?? 'orders')) ?: 'resource';
		$contents=self::php($namespace, [
			'use Dataphyre\\Panel\\Panel;',
			'use Dataphyre\\Panel\\PanelTestHarness;',
		], "final class {$short} {\n\n"
			."\tpublic function testIndexRenders(): void {\n"
			."\t\t\$test=Panel::test();\n"
			."\t\t\$result=\$test->render('".self::quote($resource)."', 'index');\n"
			."\t\tPanelTestHarness::assertOk(\$result);\n"
			."\t}\n"
			."}\n");
		return $this->result('test', Resource::normalizeName($short), $namespace.'\\'.$short, $options, $contents, [
			'resource'=>$resource,
		]);
	}

	/**
	 * Generates multiple scaffold artifacts from declarative suite definitions.
	*
	 * Each definition must include `kind` or `type` plus `class`. Unknown kinds,
	 * malformed definitions, and entries without a class are skipped rather than
	 * failing the whole suite, making the method suitable for user-authored config.
	*
	 * @param array<int, array<string, mixed>|mixed> $definitions Scaffold definitions.
	 * @return array<int, PanelScaffoldResult> Generated artifacts in definition order.
	 */
	public function suite(array $definitions): array {
		$results=[];
		foreach($definitions as $definition){
			if(!is_array($definition)){
				continue;
			}
			$kind=Resource::normalizeName((string)($definition['kind'] ?? $definition['type'] ?? ''));
			$class=(string)($definition['class'] ?? '');
			if($kind==='' || $class===''){
				continue;
			}
			$options=is_array($definition['options'] ?? null) ? $definition['options'] : $definition;
			$results[] = match($kind){
				'resource'=>$this->resource($class, $options),
				'page'=>$this->page($class, $options),
				'provider'=>$this->provider($class, $options),
				'plugin'=>$this->plugin($class, $options),
				'theme'=>$this->theme($class, $options),
				'test'=>$this->test($class, $options),
				default=>null,
			};
		}
		return array_values(array_filter($results, static fn(mixed $result): bool => $result instanceof PanelScaffoldResult));
	}

	/**
	 * Builds the common `PanelScaffoldResult` envelope for generated artifacts.
	 *
	 * @param string $kind Artifact kind such as `resource`, `page`, or `plugin`.
	 * @param string $name Normalized artifact name.
	 * @param string $class Fully-qualified class represented by the artifact.
	 * @param array<string, mixed> $options Options used to derive target path.
	 * @param string $contents Generated PHP source.
	 * @param array<string, mixed> $metadata Extra metadata and registration hints.
	 * @return PanelScaffoldResult Generated artifact envelope.
	 */
	private function result(string $kind, string $name, string $class, array $options, string $contents, array $metadata=[]): PanelScaffoldResult {
		return PanelScaffoldResult::make(
			$kind,
			$name,
			$class,
			self::targetPath($class, $options, $kind),
			$contents,
			array_replace([
				'panel'=>$this->panel?->name(),
				'namespace'=>self::splitClass($class)[0],
				'short_class'=>self::splitClass($class)[1],
			], $metadata)
		);
	}

	/**
	 * Splits a class input into namespace and safe short class name.
	 *
	 * Slashes are accepted as namespace separators. Empty or invalid short names
	 * become generated class names so scaffolding can still produce valid PHP.
	 *
	 * @param string $class Short class, FQCN, or path-like class input.
	 * @param string $defaultNamespace Namespace used when the class has no namespace.
	 * @return array{0:string,1:string} Namespace and short class name.
	 */
	private static function splitClass(string $class, string $defaultNamespace='App\\Panel'): array {
		$class=ltrim(trim(str_replace('/', '\\', $class)), '\\');
		if($class===''){
			$class='GeneratedPanelArtifact';
		}
		if(!str_contains($class, '\\')){
			return [trim($defaultNamespace, '\\') ?: 'App\\Panel', self::className($class)];
		}
		$parts=array_values(array_filter(explode('\\', $class), static fn(string $part): bool => $part!==''));
		$short=self::className((string)array_pop($parts));
		$namespace=implode('\\', $parts);
		return [$namespace!=='' ? $namespace : (trim($defaultNamespace, '\\') ?: 'App\\Panel'), $short];
	}

	/**
	 * Converts arbitrary text into a valid PascalCase PHP class name.
	 *
	 * @param string $value Raw class-like input.
	 * @return string Safe short class name.
	 */
	private static function className(string $value): string {
		$value=preg_replace('/[^a-zA-Z0-9_]+/', ' ', $value) ?? '';
		$value=str_replace(' ', '', ucwords(trim($value)));
		if($value==='' || preg_match('/^[0-9]/', $value)){
			$value='Generated'.$value;
		}
		return $value;
	}

	/**
	 * Derives the output path for a generated class.
	 *
	 * Explicit `path` wins. Otherwise the class namespace is mapped under
	 * `base_path`, trimming conventional `App\Panel` or `App` prefixes so generated
	 * artifacts land in predictable application folders.
	 *
	 * @param string $class Class input used for namespace resolution.
	 * @param array<string, mixed> $options Path and namespace options.
	 * @param string $kind Artifact kind used as fallback folder name.
	 * @return string Target filesystem path for the generated PHP file.
	 */
	private static function targetPath(string $class, array $options, string $kind): string {
		if(trim((string)($options['path'] ?? ''))!==''){
			return (string)$options['path'];
		}
		$base=trim((string)($options['base_path'] ?? 'app/Panel'));
		[$namespace, $short]=self::splitClass($class, (string)($options['namespace'] ?? 'App\\Panel'));
		$relative=str_replace('\\', DIRECTORY_SEPARATOR, $namespace);
		foreach(['App'.DIRECTORY_SEPARATOR.'Panel', 'App'] as $prefix){
			if(str_starts_with($relative, $prefix)){
				$relative=trim(substr($relative, strlen($prefix)), DIRECTORY_SEPARATOR);
				break;
			}
		}
		$folder=$relative!=='' ? $relative : self::headline($kind);
		return rtrim($base, '/\\').DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.$short.'.php';
	}

	/**
	 * Assembles a strict-types PHP file with namespace, imports, and class body.
	 *
	 * @param string $namespace Target PHP namespace.
	 * @param array<int, string> $uses Import lines to de-duplicate and include.
	 * @param string $body Generated class or interface body.
	 * @return string Complete PHP source text.
	 */
	private static function php(string $namespace, array $uses, string $body): string {
		$uses=array_values(array_unique(array_filter($uses, static fn(string $use): bool => trim($use)!=='')));
		return "<?php\n"
			."declare(strict_types=1);\n\n"
			."namespace ".trim($namespace, '\\').";\n\n"
			.implode("\n", $uses)."\n\n"
			.$body;
	}

	/**
	 * Escapes a string for single-quoted PHP source literals.
	 *
	 * @param string $value Raw literal value.
	 * @return string Escaped PHP single-quoted literal content.
	 */
	private static function quote(string $value): string {
		return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
	}

	/**
	 * Normalizes field or column definitions into unique resource field names.
	 *
	 * Definitions may be strings, arrays with a `name` key, or associative entries
	 * where the key names the field. Empty normalized names are discarded.
	 *
	 * @param mixed $fields Field definitions from scaffold options.
	 * @return array<int, string> Unique normalized field names.
	 */
	private static function fieldNames(mixed $fields): array {
		$fields=is_array($fields) ? $fields : [];
		$names=[];
		foreach($fields as $key=>$field){
			$name=is_string($field) ? $field : (is_array($field) ? (string)($field['name'] ?? $key) : (string)$key);
			$name=Resource::normalizeName($name);
			if($name!==''){
				$names[]=$name;
			}
		}
		return array_values(array_unique($names));
	}

	/**
	 * Normalizes configured class lists for provider scaffolds.
	 *
	 * @param mixed $classes Candidate class list.
	 * @return array<int, string> Unique class names without leading namespace slash.
	 */
	private static function classList(mixed $classes): array {
		if(!is_array($classes)){
			return [];
		}
		$list=[];
		foreach($classes as $class){
			$class=ltrim(trim((string)$class), '\\');
			if($class!==''){
				$list[]=$class;
			}
		}
		return array_values(array_unique($list));
	}

	/**
	 * Derives a normalized resource/plugin/theme name from a PHP class name.
	 *
	 * @param string $class Short class name.
	 * @return string Snake-style normalized panel name.
	 */
	private static function resourceNameFromClass(string $class): string {
		$name=preg_replace('/(Resource|Page|Provider|Plugin|Theme|Preset|Test)$/', '', $class) ?? $class;
		return Resource::normalizeName(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
	}

	/**
	 * Derives a normalized page name from a PHP page class name.
	 *
	 * @param string $class Short class name.
	 * @return string Snake-style normalized page name.
	 */
	private static function pageNameFromClass(string $class): string {
		$name=preg_replace('/Page$/', '', $class) ?? $class;
		return Resource::normalizeName(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
	}

	/**
	 * Converts a normalized panel name into a display label.
	 *
	 * @param string $name Normalized name.
	 * @return string Human-readable title.
	 */
	private static function headline(string $name): string {
		$name=trim(str_replace(['_', '-', '.'], ' ', $name));
		return $name==='' ? 'Panel' : ucwords($name);
	}

	/**
	 * Applies a small English pluralization rule for generated resource labels.
	 *
	 * @param string $label Singular label.
	 * @return string Best-effort plural label.
	 */
	private static function pluralize(string $label): string {
		$label=trim($label);
		if($label==='' || preg_match('/s$/i', $label)){
			return $label;
		}
		if(preg_match('/y$/i', $label)){
			return substr($label, 0, -1).'ies';
		}
		return $label.'s';
	}
}
