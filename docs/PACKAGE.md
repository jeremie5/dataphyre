# Package Contract

Dataphyre publishes Composer metadata so the project can be identified,
validated, and consumed as a PHP package. The runtime does not yet expose a
complete Composer autoload surface.

## Runtime Entrypoint

Boot Dataphyre explicitly:

```php
<?php

require __DIR__.'/runtime/bootstrap.php';
```

The bootstrap file resolves `flight_sheet.php`, locates the configured
application, registers Dataphyre's runtime autoloader, and then hands control to
the selected application.

Composer vendor installs should keep install-local files outside `vendor/`.
Set `DATAPHYRE_PROJECT_ROOT` on `$_SERVER` before including the installed
runtime. The public `index.example.php` template performs this check for a
standard Composer project:

```php
<?php

$_SERVER['DATAPHYRE_PROJECT_ROOT']=__DIR__;
require __DIR__.'/vendor/dataphyre/dataphyre/runtime/bootstrap.php';
```

To initialize the minimal consumer project files from the installed package:

```powershell
php vendor/dataphyre/dataphyre/installer/init_consumer.php --root=.
```

The initializer creates `flight_sheet.php`, `index.php`, and
`applications/example_app/` in the consumer project root. It does not write into
`vendor/` and refuses to replace existing files unless `--force` is passed.

## Composer Repository Resolution

Tagged public releases are installable through Composer when the consumer
project has a repository that can resolve `dataphyre/dataphyre`. If default
Composer repositories do not resolve the package, add the GitHub VCS repository:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/jeremie5/dataphyre.git"
    }
  ],
  "require": {
    "dataphyre/dataphyre": "^2.0"
  }
}
```

Composer installs the package under `vendor/dataphyre/dataphyre/`. Boot it by
including the explicit runtime bootstrap from that installed directory after
setting `$_SERVER['DATAPHYRE_PROJECT_ROOT']` to the project directory that
contains `flight_sheet.php` and `applications/`.

## Composer Autoload

`composer.json` intentionally avoids a PSR-4 `autoload` section for now.
Dataphyre's current class surface mixes:

- legacy kernel files with filenames that do not always map to class names;
- module-local namespaces that are registered from discovered module roots;
- framework namespaces under `runtime/modules/*/Framework`;
- application-level namespace prefixes declared by each application definition.

Adding a partial Composer autoload map would make package consumers think the
runtime can be used by requiring `vendor/autoload.php` alone. That is not true
yet. The stable contract is still the explicit runtime bootstrap.

## Public Metadata

The Composer package metadata declares:

- package name: `dataphyre/dataphyre`
- license: MIT
- runtime requirement: PHP 8.1 or newer
- support links for source, issues, security policy, and documentation
  at `docs/README.md`
- `extra.dataphyre.runtime-bootstrap`: `runtime/bootstrap.php`
- `extra.dataphyre.consumer-init`: `installer/init_consumer.php`
- `extra.dataphyre.package-contract`: `docs/PACKAGE.md`
- `extra.dataphyre.cli-reference`: `docs/CLI.md`
- `extra.dataphyre.release-manifest`: `RELEASE_MANIFEST.json` when present in
  the package root. The schema and human-readable contract live under `docs/`;
  the JSON manifest describes the package artifact.
- `extra.dataphyre.agent-boundary`: machine-readable guidance that the default
  audience is `application_agents_building_apps`, application-specific behavior
  and MCP default work are application building with Dataphyre, ordinary app
  agents start from `ordinary-app-entrypoint` =
  `dataphyre_app_builder_plan_generate` using
  `ordinary-app-payload-profile` = `compact`, framework maintenance is explicit
  framework contribution work only, `agentic-enterprise-contract` points to
  `docs/AGENTIC_ENTERPRISE.md`, `escalate-only-for` names release/public
  framework claims, corporate-ready claims, security/governance-sensitive work,
  reusable Dataphyre framework work, and shared production hot-path changes,
  application-specific behavior should use config, dialbacks, callbacks,
  plugins, MCP metadata, application-owned adapters, or reusable runtime modules first,
  `app-builder-handoff-fields` names the copy-safe resume contract that
  app-builder handoffs should preserve across compact and full profiles
  (`builder_response.first_read.next_action`, chunk continuations,
  `prewrite_checklist.implementation_obligations`, `verification_handoff`,
  `implementation_matrix`, `write_handoff`, `app_contract_summary`,
  `data_sensitivity_summary`, `policy_decision_register`, and compact
  `detail_page=implementation|verification|controls` refs for
  `implementation_recipe`, `verification_execution_plan`,
  `acceptance_review_plan`, and `local_convention_probe`), and project-wide
  package validation, `dataphyre_mcp_verify_all`, hot-path benchmarks, and runtime-internal edits
  are not default app-agent requirements

The package is currently best treated as an embedded runtime distribution rather
than a drop-in library dependency.

See [Stability policy](STABILITY.md) for the public compatibility promise.

## Future Autoload Work

A future package release can add Composer autoloading once the public class
surface is normalized. That work includes:

- aligning kernel class names and filenames;
- deciding which legacy globals remain public API;
- exposing framework namespaces consistently;
- keeping module discovery behavior compatible with embedded installs;
- adding tests that boot through both Composer and direct runtime entrypoints.

Until then, use [Getting started](GETTING_STARTED.md) and
[Architecture](ARCHITECTURE.md) as the source of truth for boot behavior, and
[CLI reference](CLI.md) for command usage.

