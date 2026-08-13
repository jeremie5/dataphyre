# Dataphyre Panel Release Evidence

Panel can bind a release gate to the exact bytes it claims to have tested.
`PanelReleaseEvidenceBundle` captures a root-confined artifact tree, binds every
executed claim to a report digest and byte count, binds the run to source,
release-contract, release-manifest, and quality-matrix digests, then signs the
canonical envelope with a host-held HMAC key.

This closes the gap between "the report path exists" and "these exact report
and screenshot bytes were produced for this exact source closure." It does not
turn browser proxies into native assistive-technology evidence.

## Trust boundary

Panel owns:

- normalized relative artifact paths and exact SHA-256 and byte bindings;
- an optional strict-tree mode that rejects missing, extra, linked, or changed
  artifacts;
- source, release-contract, optional release-manifest, matrix, runner,
  environment, capability, run-id, and expiry bindings;
- artifact-backed automated claims for `php`, `browser`, and `adapter`
  execution only;
- domain-separated HMAC-SHA-256 signing, rotating key ids, independent
  verification, and a stable replay key;
- bounded files, trees, JSON inputs, clocks, claims, context, and CLI output;
- a redacted verification result that never serializes the artifact root or
  signing key.

The host owns:

- signing-key generation, storage, rotation, distribution, and revocation;
- the authoritative prepared-package source digest;
- trusted runner isolation and the meaning of adapter evidence;
- durable replay-key consumption, retention, and audit publication;
- native NVDA, JAWS, VoiceOver, TalkBack, switch, voice-control, IME, and device
  labs.

HMAC proves that a verifier holding the same trusted key recognizes the bundle.
It is not public-key transparency and should not be presented as third-party
certification.

## Issue and verify in PHP

```php
use Dataphyre\Panel\PanelReleaseEvidenceBundle;

$bundle = PanelReleaseEvidenceBundle::issue(
    artifactRoot: '/release/panel-browser',
    artifactPaths: [
        'interaction.json',
        'visual/report.json',
        'visual/orders-mobile.png',
    ],
    context: [
        'source_digest' => $preparedPackageTreeSha256,
        'contract_digest' => hash_file('sha256', $panelReleaseContract),
        'release_digest' => hash_file('sha256', $releaseManifest),
        'matrix_digests' => ['inclusive' => $inclusiveMatrixDigest],
        'runner' => [
            'id' => 'panel-release-gate',
            'version' => '1',
            'channel' => 'ci',
            'browser' => 'Chromium 140',
        ],
        'environment' => ['os' => 'ubuntu-24.04', 'php' => '8.4'],
        'capabilities' => ['browser.interaction', 'browser.visual'],
    ],
    claims: [
        [
            'id' => 'interaction',
            'status' => 'passed',
            'execution' => 'browser',
            'assertions' => 50,
            'report_path' => 'interaction.json',
            'capabilities' => ['browser.interaction'],
        ],
    ],
    keyId: 'quality-v2',
    key: $hostHeldKey,
    issuedAt: time(),
    ttl: 3600,
    runId: $ciRunId,
    strictTree: true,
);

$verification = $bundle->verify(
    artifactRoot: '/release/panel-browser',
    keys: ['quality-v2' => $hostHeldKey],
    expectations: [
        'source_digest' => $preparedPackageTreeSha256,
        'contract_digest' => hash_file('sha256', $panelReleaseContract),
        'release_digest' => hash_file('sha256', $releaseManifest),
        'matrix_digests' => ['inclusive' => $inclusiveMatrixDigest],
        'run_id' => $ciRunId,
    ],
);

$verification->assertPassed();
$replayKey = $verification->replayKey();
```

The expectation object is mandatory. Verification never trusts a bundle's own
source or contract claim as the authority for what should have run. A host can
also pass a replay callback to `verify()`; returning anything except `true`
produces `replay_rejected`.

## CLI

The CLI reads the key from a file. Key bytes never appear in arguments, bundle
JSON, or verification output.

```bash
php source-checkout-maintainer-tool issue \
  --root cache/ci/panel-release \
  --spec cache/ci/panel-evidence-issue.json \
  --key-file /run/secrets/panel-quality-hmac \
  --output cache/ci/panel-release-evidence.json

php source-checkout-maintainer-tool verify \
  --root cache/ci/panel-release \
  --spec cache/ci/panel-evidence-expected.json \
  --key-file /run/secrets/panel-quality-hmac \
  --bundle cache/ci/panel-release-evidence.json
```

An issue specification contains `artifacts`, `context`, `claims`, `key_id`, and
optional `issued_at`, `ttl`, `run_id`, and `strict_tree`. A verification
specification contains independent `source_digest` and `contract_digest`
expectations plus optional release, matrix, and run-id expectations. In strict
mode, the output bundle must be outside the attested artifact root so it cannot
invalidate its own tree.

## Release gate integration

`panel_release_gate.js` can issue and immediately verify a bundle after writing
all selected lane reports. It inventories screenshots and diagnostics as well
as JSON reports, derives the release-contract digest itself, and requires the
prepared-package tree digest from the caller.

```bash
node runtime/modules/panel/testing/panel_release_gate.js \
  --base-url http://127.0.0.1:8098/panel \
  --artifact-dir cache/ci/panel-release \
  --evidence-key-file /run/secrets/panel-quality-hmac \
  --evidence-key-id quality-v2 \
  --evidence-source-digest "$PREPARED_PACKAGE_TREE_SHA256" \
  --evidence-release-digest "$RELEASE_MANIFEST_SHA256" \
  --evidence-run-id "$CI_RUN_ID" \
  --evidence-bundle cache/ci/panel-release-evidence.json
```

If evidence options are present but incomplete, signing fails, verification
fails, an artifact changes between issue and verify, or the bundle path is
inside the strict artifact root, the release gate exits nonzero.

## Native assistive-technology evidence

The bundle accepts an `adapter` execution channel because a host may run a real
platform lab adapter. The claim still needs an exact artifact-backed report and
the runner context must identify that adapter. `manual` is intentionally not a
valid automated claim channel. Manual declarations stay in the inclusive
quality result's separate `declared_manual` section and keep their independent
budgets.
