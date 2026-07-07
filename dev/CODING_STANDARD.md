# Dataphyre Coding Standard 1

DCS-1 defines the source and documentation style for Dataphyre-owned PHP code.
It is intentionally small: Dataphyre favors stable runtime contracts, readable
PHPDoc, and minimal churn over broad formatting migrations.

## Scope

DCS-1 applies to reusable Dataphyre runtime code, module framework classes,
module kernels, diagnostics, tools, and public examples under the Dataphyre
package boundary.

Application-specific code may follow local product conventions, but shared
runtime changes should use DCS-1 when adding or updating code.

## Baseline

DCS-1 uses PHPDoc syntax compatible with phpDocumentor, PHPStan, and Psalm. It
does not adopt PSR-12 as a formatting target because Dataphyre uses a compact
runtime style with inline opening braces.

Use existing local formatting when editing older files. Do not reformat an
entire file unless the task is explicitly a formatting migration.

## Formatting

- Use tabs for indentation in PHP source.
- Keep opening braces on the same line as class, function, method, closure, and
  control signatures.
- Keep one statement per line unless an existing compact guard style is already
  clearer in context.
- Prefer single quotes for literal strings that do not need interpolation.
- Preserve existing named-argument compatibility patterns such as local
  assignment variables passed into legacy helpers.
- Keep namespace, class, method, function, and constant names consistent with
  the surrounding module.
- Avoid unrelated whitespace, import, ordering, or line-ending churn.

Framework example:

```php
final class ExampleService {

	public function run(string $displayName): bool {
		if($displayName===''){
			return false;
		}
		return true;
	}
}
```

Kernel example:

```php
class example_service {

	public static function run_task(string $display_name): bool {
		if($display_name===''){
			return false;
		}
		return true;
	}
}
```

## Naming

DCS-1 has different naming expectations for kernel compatibility code and newer
Framework code.

### Kernel

Kernel files live under `runtime/modules/<module>/kernel/` or existing legacy
module entrypoints. Kernel code keeps Dataphyre's historical snake_case style:

- Use lowercase snake_case for kernel classes, functions, methods, properties,
  parameters, and local variables.
- Use the lowercase `dataphyre` namespace for legacy kernel classes.
- Keep existing public kernel APIs stable. When a public kernel symbol already
  exists, do not rename it only for style.
- Add aliases or compatibility wrappers when a naming cleanup would otherwise
  break application code.

### Framework

Framework files live under `runtime/modules/<module>/Framework/`. Framework code
uses modern Dataphyre names:

- Use PascalCase for namespaces, classes, interfaces, traits, and enums.
- Use camelCase for methods, functions, properties, parameters, and local
  variables.
- Keep acronyms readable in PascalCase and camelCase, such as `OpenApi`,
  `JwtCodec`, `csrfToken`, and `apiContext`.
- Avoid introducing snake_case identifiers in Framework code except when
  handling external payload keys, database columns, config keys, route names,
  array shapes, or compatibility calls into kernel APIs.

### Cross-Boundary Code

When Framework code calls kernel code, keep each side's naming intact. Prefer
small translation points where Framework camelCase variables feed kernel
snake_case arrays, functions, or config keys.

## PHPDoc

Document every public class, interface, trait, enum, function, public method,
and protected method that forms part of a module contract. Document private
members when the behavior is non-obvious, security-sensitive, or useful to
future maintainers.

Docblocks should explain the contract, not narrate the syntax. Prefer:

- A short summary line.
- A short context paragraph when the runtime behavior, side effects, or module
  boundary matters.
- Precise `@param`, `@return`, and `@throws` tags when native types do not carry
  enough information.
- `@internal`, `@deprecated`, `@see`, `@template`, or array-shape annotations
  when they clarify real usage.

Do not add placeholder tags, author tags, package tags, or obvious comments just
to make a block look full.

Example:

```php
/**
 * Synchronizes a bounded batch of pending Datadoc files for a project.
 *
 * Keeps browser-triggered indexing inside a request-time budget and returns
 * counters that Flightdeck can display without starting a long-running job.
 *
 * @param non-empty-string $project Datadoc project key.
 * @param positive-int $limit Maximum pending files to synchronize.
 * @param positive-int $maxSeconds Request-time budget in seconds.
 * @return array{synced:int,pending:int,stale:int,cursor:?string}
 */
public static function syncProjectBatch(string $project, int $limit=25, int $maxSeconds=5): array {
	// ...
}
```

## Datadoc Content Style

Datadoc-facing documentation should make runtime contracts searchable and
actionable:

- Name the module or subsystem boundary when it matters.
- Call out side effects such as SQL writes, cache mutation, route dispatch,
  filesystem access, network access, session mutation, or generated state.
- Describe configuration keys, table definitions, cache locations, and required
  modules when they are part of the contract.
- Mark compatibility surfaces, legacy helpers, and diagnostic-only entrypoints.
- Keep examples short and executable in principle.

## Inline Comments

Use inline comments only when the code is doing something a future maintainer
could reasonably misread. Good inline comments explain a constraint, invariant,
security boundary, or compatibility reason.

Avoid comments that repeat the next line of code.

## Types

Prefer native PHP types where they are accurate. Use PHPDoc to refine contracts
that PHP cannot express directly, including:

- non-empty strings
- positive integers
- list and array-shape structures
- class-string values
- callable signatures
- generic collections
- literal status strings

Do not loosen a native type just to satisfy an incomplete docblock.

## Errors And Side Effects

Use `@throws` for expected exception surfaces. When a function logs, mutates
global state, writes generated files, dispatches runtime work, or touches
external systems, mention that behavior in the description when it affects
callers.

## Verification

For touched PHP files, run:

```powershell
./dev/tools/public/lint_php.ps1
```

When `php` is not on `PATH` in a Git worktree, pass `-Php <path-to-php>`
or set `DATAPHYRE_PHP` for the current shell.

Release-impacting changes should also pass the local release validation process
used by the publisher. Public checkout verification uses the tracked tools under
`dev/tools/public/`.

For documentation-only changes, review Markdown links and keep
`README.md`, `runtime/README.md`, `runtime/documentation/README.md`, and
module documentation indexes in sync when public navigation changes.

