# Dataphyre Panel Compliance Automation

Panel can collect, retain, sign, and compare operational evidence without
claiming that an application is legally compliant or certified. The runtime is
host-bound: Panel supplies the execution and integrity contracts, while the
host supplies collectors, credentials, authorization policy, schedules, and
the final interpretation of each framework.

The automation graph is composed from:

- `PanelComplianceCollector` for typed host evidence sources;
- `PanelComplianceCollectorRegistry` for provenance and exact implementation
  fingerprints;
- `PanelComplianceFrameworkPack` and
  `PanelComplianceFrameworkCatalog` for source-bound evidence mappings;
- `PanelComplianceCollectionPlan` for immutable framework, control, collector,
  subject, freshness, and input commitments;
- `PanelComplianceAutomation` for bounded execution and failure isolation;
- `PanelComplianceObservation` for typed, freshness-bounded results;
- `PanelComplianceCollectionRun` for signed run outcomes;
- `PanelComplianceCoverageReport` for evidence gaps, stale observations,
  crosswalk visibility, and drift;
- `PanelComplianceLedger` for the existing HMAC evidence chain, retention,
  signed packs, and legal holds.

## Trust Boundary

Collector credentials belong inside collector instances. They are never plan
inputs and never appear in registry, plan, run, coverage, platform, or console
manifests. Plan inputs pass through Panel's recursive sensitive-data sanitizer
and the public plan serializes only their digests.

Every plan pins the full framework-pack fingerprint, individual control
fingerprint, and every collector fingerprint. A missing or replaced dependency
becomes explicit `dependency_drift`; Panel does not silently execute the new
implementation. Collector exceptions are reduced to their class, isolated to
their control, and recorded as `error` observations without stack traces or
exception payloads.

PHP cannot safely interrupt arbitrary in-process code. The collector and run
budgets therefore fail the completed result when a collector returns late and
stop later work once the run budget is exhausted. Hosts must execute collectors
that can block indefinitely in a supervised worker or process boundary with a
real transport timeout.

## Observation Statuses

The closed status grammar is:

- `satisfied`
- `not_satisfied`
- `indeterminate`
- `not_applicable`
- `error`

`missing` is a plan/run coverage state, not collector evidence. It is emitted
when a framework control has no bound collector. Every actual observation has
an observation time, validity deadline, subject, source reference, sanitized
evidence map, and canonical digest.

Coverage reports expose current, stale, missing, negative, indeterminate, and
error counts. `evidence_coverage_basis_points` means only that current evidence
was collected. It is not a compliance score. `no_negative_observations` is also
not a certification or legal conclusion.

## Built-in Reference Profiles

`PanelComplianceFrameworkCatalog::firstParty()` includes 29 reference-level
controls across four profiles:

| Profile | Scope | Primary source |
| --- | --- | --- |
| NIST CSF 2.0 | Six function-level evidence references | [NIST Cybersecurity Framework 2.0](https://tsapps.nist.gov/publication/get_pdf.cfm?pub_id=957258) |
| GDPR | Selected operational references to Articles 5, 25, 30, 32, 33, and 35 | [Regulation (EU) 2016/679](https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng) |
| HIPAA Security Rule | Safeguard and documentation section references | [HHS Security Rule summary](https://www.hhs.gov/hipaa/for-professionals/security/laws-regulations/index.html) |
| PCI DSS 4.0.1 | Twelve top-level requirement references | [PCI SSC document library](https://www.pcisecuritystandards.org/document_library/?class=pcidss&doc=pci_dss) |

These packs were source-checked on 2026-07-16. They are deliberately marked
`reference_profile`, contain short Panel-authored labels rather than copied
control text, and make no completeness, equivalence, certification, or legal
advice claim. The host must verify current law, standards, scope, applicability,
licensed materials, assessor requirements, and local interpretations before
using a profile.

Built-in crosswalks use `related` only. The runtime supports `supports`,
`overlaps`, and `equivalent` for host packs, but an equivalence relationship is
never inferred.

## Defining a Collector

```php
use Dataphyre\Panel\PanelCallbackComplianceCollector;
use Dataphyre\Panel\PanelComplianceCollectionContext;
use Dataphyre\Panel\PanelComplianceObservation;

$collector=new PanelCallbackComplianceCollector(
	'access_review_probe',
	'2026.07.16',
	static function (PanelComplianceCollectionContext $context):PanelComplianceObservation {
		// The injected service owns any credential. Do not put it in $context.
		$result=app_access_review_service()->latest($context->subject());

		return PanelComplianceObservation::make(
			$result->passed ? 'satisfied' : 'not_satisfied',
			[
				'review_id'=>$result->publicId,
				'completed_at'=>$result->completedAt,
			],
			[
				'observed_at'=>$context->requestedAt(),
				'max_age_seconds'=>86400,
				'subject'=>$context->subject(),
				'source_reference'=>'access-review-service',
			]
		);
	},
	['read_only'=>true, 'freshness'=>true],
	['owner'=>'security-platform'],
);
```

Register collectors when composing the Operations OS:

```php
$os=PanelOperationsOs::fromConfig($stateRoot, [
	'master_key'=>$masterKey,
	'compliance_collectors'=>[
		[
			'collector'=>$collector,
			'contributor'=>'security',
			'priority'=>100,
		],
	],
	'compliance_collection_limits'=>[
		'collector_millis'=>5000,
		'run_millis'=>60000,
		'evidence_items'=>128,
	],
]);
```

## Planning and Collecting

```php
$automation=$os->complianceAutomation();

$plan=$automation->plan(
	['nist_csf_2'],
	[
		'nist_csf_2.protect'=>['access_review_probe'],
	],
	[
		'generated_at'=>$clock->now(),
		'deadline_at'=>$clock->now()->modify('+10 minutes'),
		'subject'=>'tenant:example',
		'input'=>['region'=>'ca'],
	]
);

$run=$automation->collect($plan, 'operator:42', [
	'run_id'=>'nightly_security_evidence',
]);

if (!$run->verify($trustedComplianceKeys)) {
	throw new RuntimeException('Collection run signature is not trusted.');
}

$coverage=$automation->coverage($run);
$drift=$coverage->drift($previousCoverage);
```

Installing and recording evidence remains deny-by-default through the same
ledger authorizer. The policy boundary sees `compliance.control.register`,
`compliance.evidence.record`, and `compliance.run.sign` abilities.

## Custom and Licensed Profiles

Hosts may register their own `PanelComplianceFrameworkPack` with one of these
coverage scopes:

- `reference_profile`
- `complete_host_mapping`
- `licensed_profile`

The scope is descriptive. Panel does not validate a host's legal right to use
licensed control material and does not turn `complete_host_mapping` into a
certification claim. Store only material the application is entitled to use.

## Collector Conformance

Run the production contract before activating a custom collector:

```php
$report=(new PanelAdapterConformanceRunner())->run(
	PanelAdapterConformanceCatalog::complianceCollector(),
	$collector,
	[
		'collection_context'=>$probeContext,
		'forbidden_fragments'=>[$knownCredential],
	]
);

if (!$report->passed()) {
	throw new RuntimeException('Compliance collector failed conformance.');
}
```

The suite verifies stable identity, SHA-256 fingerprints, object-shaped
capabilities, explicit credential-free manifests, typed observations, subject
binding, validity windows, and canonical observation digests.

## Host Responsibilities

Panel does not install routes, schedulers, workers, databases, external API
clients, credentials, legal interpretations, assessors, or submission flows.
The host remains responsible for:

- collector authentication and least-privilege network access;
- external timeouts, cancellation, retries, and worker supervision;
- evidence source reliability and semantic correctness;
- framework applicability and licensed control content;
- collection schedules and freshness policy;
- key custody, rotation, archive, retention, and legal-hold procedure;
- human review and any auditor, regulator, or card-brand submission.
