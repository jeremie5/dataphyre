# Migrating Filament resources to Dataphyre Panel

Panel includes a preview-first, non-executing migration planner for Filament 3,
4, and 5 applications. The current migration target follows Filament 5's
resource, schema, and table split while retaining support for the common
declarations emitted by older resource layouts.

The planner is an exit tool, not a compatibility runtime. It does not install
Filament, boot Laravel, resolve a service container, query a database, invoke a
closure, or load an application class.

## Preview and write

From the application root, run:

```bash
php vendor/dataphyre/dataphyre/dev/tools/panel_filament_migrate.php
```

The command only prints a JSON plan. It writes nothing unless `--write` is
present:

```bash
php vendor/dataphyre/dataphyre/dev/tools/panel_filament_migrate.php \
  --root /srv/application \
  --paths app/Filament \
  --target-directory app/Panel/Resources \
  --target-namespace App\\Panel\\Resources \
  --policy error \
  --write
```

Supported conflict policies are `error`, `skip`, and `replace`. Publication is
root-confined, preflighted, staged, stale-target checked, transactional, and
rolled back if a multi-file commit fails. The default is `error`.

## What is mapped

The analyzer records resource classes, static literal resource metadata,
component declarations, and static companion references. This lets it follow a
Filament 5 resource shell into referenced schema and table classes without
executing those classes.

Automatic mappings currently include:

- common form fields such as text, textarea, select, checkbox, toggle, radio,
  date/time, upload, editors, repeater, builder, tags, key/value, color, code,
  and hidden fields;
- common table columns such as text, icon, image, color, select, toggle,
  inline text, and checkbox columns;
- verified literal field and column options such as labels, required and
  visibility flags, placeholders, options, lengths, search, sort, copy, format,
  color, alignment, and simple display limits;
- static model, labels, navigation group, parent, icon, sort, visibility, slug,
  and record-title metadata.

Filters, actions, layouts, infolists, relation managers, pages, widgets,
clusters, plugins, importers, exporters, dynamic component names, closures, and
unknown fluent methods remain explicit findings. The report identifies every
such item rather than guessing its semantics.

The relevant Filament source concepts are documented in the official
[resource](https://filamentphp.com/docs/5.x/resources/overview),
[schema layout](https://filamentphp.com/docs/5.x/schemas/layouts), and
[table](https://filamentphp.com/docs/5.x/tables/overview) guides.

## Fail-safe output

Generated resources include only declarative Panel presentation metadata.
They intentionally omit:

- query and repository adapters;
- create, update, delete, bulk, import, and action handlers;
- authorization and record policy callbacks;
- tenant and ownership scopes;
- source closures or generated callbacks.

Consequently `ready_to_activate` is always false. `ready_to_write` only means
that source analysis had no blocking integrity error and the generated files
can enter a review branch. It is not a deployment approval.

Before activating a migrated resource, the host must bind an allowlisted data
adapter, bind each mutation explicitly, reproduce every policy and tenant
boundary, review all manual findings, and run unit, browser, responsive, theme,
accessibility, and rollback certification.

## Programmatic use

```php
use Dataphyre\Panel\PanelFilamentMigrationPlan;
use Dataphyre\Panel\PanelFilamentSourceAnalyzer;

$inventory=PanelFilamentSourceAnalyzer::make($applicationRoot)->analyze([
    'app/Filament',
]);

$plan=PanelFilamentMigrationPlan::make($inventory, [
    'target_namespace'=>'App\\Panel\\Resources',
    'target_directory'=>'app/Panel/Resources',
]);

// Transactional dry run. No files are written.
$preview=$plan->write($applicationRoot, 'error', true);

// Explicit publication after reviewing the report.
$result=$plan->write($applicationRoot, 'error', false);
```

The inventory and plan manifests contain relative source paths, digests,
counts, mappings, and findings. They never contain source contents, the source
root, executable callbacks, credentials, or generated file bodies. Generated
file bodies remain process-local `PanelScaffoldResult` payloads until the
transactional writer is called.

## Analysis boundaries

Analysis is bounded by file count, per-file bytes, and total bytes. Paths must
remain under a resolved non-symlink project root. Traversal, source symlinks,
malformed PHP, oversized input, dynamic identities, and unsafe generated class
identifiers fail closed or become explicit review findings.

Static analysis cannot prove runtime authorization equivalence, implicit model
scopes, plugin behavior, action side effects, or closure semantics. Keep the
Filament application available as a behavioral oracle until the migrated Panel
surface passes application-owned parity and security tests.
