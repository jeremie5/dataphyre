# Release Manifest

Dataphyre package artifacts can include `RELEASE_MANIFEST.json` at the package
root. The manifest is a non-sensitive attestation for package consumers, CI
systems, and agent tooling. It describes package contents without exposing local
source paths, host names, user names, credentials, tenant identifiers, or
application-owned adapter names.

A machine-readable JSON Schema is published beside this document:
[RELEASE_MANIFEST.schema.json](RELEASE_MANIFEST.schema.json).

## Schema

`schema` identifies the manifest shape. The current value is:

```json
"dataphyre.public_export_manifest.v1"
```

Consumers should reject unknown schema values unless they explicitly support the
new version.

## Top-Level Fields

| Field | Type | Description |
|---|---|---|
| `schema` | string | Manifest schema identifier. |
| `package` | string | Composer package name. |
| `generated_by` | string | Stable identifier for the manifest/package preparation process. |
| `generated_at_utc` | string | UTC timestamp when the manifest was written. |
| `copied_source_files` | integer | Number of source files represented before the manifest was written. |
| `skipped_source_files` | integer | Number of source files outside the package boundary. |
| `export_file_count` | integer | Total file count represented by the manifest, including `RELEASE_MANIFEST.json`. |
| `export_tree_sha256` | string | Deterministic SHA-256 of the represented file list, excluding `RELEASE_MANIFEST.json`. |
| `release_boundary` | object | Machine-readable app-agent and project evidence boundary for the package artifact. |
| `excluded_categories` | string array | Non-sensitive package boundary categories. |
| `verification` | string array | Package attestation checks represented by the manifest process. These are provenance, not framework-user or application-agent commands. |
| `verification_scope` | string | Constant scope marker: `release_attestation_not_app_runtime_requirement`. |
| `modules` | object array | Public module inventory generated from `docs/MODULES.md`. |
| `bundled_components` | object array | Bundled third-party component inventory. |
| `files` | object array | Packaged file inventory, excluding `RELEASE_MANIFEST.json`. |

## Release Boundary

`release_boundary` tells agents and CI consumers how to interpret the package
artifact. Its `default_audience` is `application_agents_building_apps`. Ordinary
application behavior should use focused application or module checks owned by
the consuming application. Its `ordinary_app_entrypoint` is
`dataphyre_app_builder_plan_generate` with `ordinary_app_payload_profile` set to
`compact`, so application agents can start from the lightweight app-builder
lane instead of release or maintainer workflows. App-builder handoffs should
preserve the `app_builder_handoff_fields` contract across compact and full
profiles:
`prewrite_checklist.implementation_obligations`, `verification_handoff`,
`verification_execution_plan`, `acceptance_review_plan`,
`local_convention_probe`, `write_handoff`, `implementation_matrix`,
`implementation_recipe`, `app_contract_summary`, `tenant_identity_handoff`,
`data_sensitivity_summary`, and `policy_decision_register`. Compact app-builder
payloads must keep `builder_response.first_read.next_action`, write readiness,
chunk continuations, and focused `verification_handoff` machine-readable.
Larger handoff bodies may be discoverable through `detail_page=planning`,
`detail_page=implementation`, `detail_page=verification`,
`detail_page=controls`, `compact_detail_policy.collapsed_sections`, or
`detail_refs` rather than inline bodies. The contract is that app-owned
relationship, field metadata, local convention, tenant identity, contract,
sensitivity, policy, implementation sequencing, write-readiness, acceptance,
and focused verification work stays discoverable across agent resumes without
requiring maintainer evidence.

The boundary's `app_owned_extension_points` field names app-owned extension
points: config, dialbacks, callbacks, plugins, MCP metadata,
application-owned adapters, and reusable runtime modules.
Application agents should use those before considering runtime-internal
Dataphyre changes for one application.

The boundary's `escalate_only_for` field names when heavier review applies:
release-facing or public Dataphyre framework claims, corporate-ready or
enterprise-readiness claims, security/identity/access/session/credential/
governance/tenant isolation/billing/privacy/compliance/data residency/
retention/legal-hold/access-policy work, Dataphyre framework internals or
reusable framework contributions, and shared production hot-path changes.

The boundary's `not_ordinary_app_ceremony` field names items that are
explicitly not ordinary app ceremony:
`dataphyre_mcp_verify_all`, Dataphyre project-wide package validation,
Dataphyre hot-path benchmarks, and Dataphyre runtime-internal edits to make one
application work. The `project_evidence_scope` field names when package or
benchmark evidence is relevant: Dataphyre framework changes,
MCP/package-surface claims, package preparation, and shared production hot-path
changes.

## Module Entries

Each `modules` entry describes one packaged runtime module:

| Field | Type | Description |
|---|---|---|
| `name` | string | Module directory name under `runtime/modules/`. |
| `status` | string | Public status label from `docs/MODULES.md`. |
| `runtime_critical` | boolean | Whether the module is required by the boot path. |
| `docs` | string | Documentation link target from `docs/MODULES.md`. |
| `purpose` | string | Short public purpose summary. |

Package validation can check this inventory against both `runtime/modules/` and
`docs/MODULES.md`.

## Bundled Component Entries

Each `bundled_components` entry describes one third-party component represented
by the package artifact:

| Field | Type | Description |
|---|---|---|
| `name` | string | Public component name. |
| `path` | string | Public relative directory path for the bundled component. |
| `license` | string | SPDX-style license label used by the public notice inventory. |
| `license_file` | string | Public relative path to the component license file. |

Package validation can check this inventory against
`docs/THIRD_PARTY_NOTICES.md` and the represented license files.

## File Entries

Each `files` entry describes one packaged file:

| Field | Type | Description |
|---|---|---|
| `path` | string | Public relative path inside the package artifact. |
| `bytes` | integer | File length in bytes. |
| `sha256` | string | Lowercase SHA-256 hash of the file contents. |

Package validation can check file paths, duplicate entries, byte counts, and
SHA-256 hashes against the represented package tree.

## Tree Hash

`export_tree_sha256` is computed from sorted file entries, excluding the manifest
itself. Each line in the hash input is:

```text
path<TAB>bytes<TAB>sha256<LF>
```

This makes the tree hash stable across machines and useful for comparing two
package artifacts with the same public contents.



