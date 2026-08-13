# Dataphyre Panel Marketplace Trust

Panel's marketplace trust layer makes package publication history, revocation,
publisher evidence, acquisition, and final activation one verifiable chain. It
does not make arbitrary PHP safe and it does not replace host authorization,
network isolation, code review, or deployment policy.

## Trust model

The chain has five independent boundaries:

1. A registry index and each package bundle are signed by host-trusted keys.
2. Transparency receipts bind canonical public events to a signed Merkle-tree
   checkpoint.
3. A stateful verifier rejects stale checkpoints, rollback, inconsistent tree
   growth, unknown logs, bad witness quorum, and split views.
4. A durable trust network ingests every event contiguously and derives current
   revocation and publisher-evidence projections.
5. Registry load, resolution, acquisition, governance review, and installation
   re-evaluate current policy. Installation checks again under its package lock
   immediately before publishing any artifact.

The host must seed trusted log keys and checkpoints. Trust on first use is off by
default and should remain off in production. Signed timestamps are evidence,
not a clock source; checkpoint freshness is evaluated against a host clock.

## Transparency log and verifier

`PanelPackageTransparencyLog` is the first-party append-only log implementation.
It uses domain-separated SHA-256 Merkle leaves and nodes, logarithmic inclusion
proofs, consistency proofs, signed checkpoints, optional witness signatures, and
atomic local persistence. A remote service may implement the same receipt
contract.

```php
$verifier=new PanelPackageTransparencyVerifier(
	$verifyLogOrWitnessSignature,
	['public_packages'],
	$persistedTrustedCheckpoints,
	[
		'allowed_witnesses'=>['witness_east', 'witness_west'],
		'required_witnesses'=>2,
		'require_consistency'=>true,
		'allow_trust_on_first_use'=>false,
		'max_checkpoint_age_seconds'=>86400,
		'clock'=>$trustedClock,
	]
);

$receipt=PanelPackageTransparencyReceipt::fromArray($remoteReceipt);
if(!$verifier('package', $canonicalPackageSubject, $receipt->jsonSerialize())) {
	throw new RuntimeException('Package transparency verification failed.');
}

$persist($verifier->checkpoint());
```

`PanelPackageRegistryIndex::indexTransparencySubject()` and
`PanelPackageRegistryIndex::packageTransparencySubject()` produce the exact
non-circular public subjects committed by the log. Artifact locators, proof
bytes, and registry signatures are excluded from those commitments. A package
with `yanked=true` verifies as a `package_yank` event rather than a release.

## Durable revocation and publisher evidence

`PanelPackageMarketplaceTrustNetwork` stores a contiguous projection of verified
events. A gap is rejected because absence claims are not complete when earlier
events may be missing. Network health is complete only when every allow-listed
log is observed, fully processed through its checkpoint head, and fresh.

```php
$network=new PanelPackageMarketplaceTrustNetwork(
	$privateStateRoot,
	$verifier,
	$trustedClock,
);

foreach($remoteReceipts as $receipt) {
	$network->ingest($receipt);
}

if(!$network->health()['complete']) {
	throw new RuntimeException('Marketplace trust projection is incomplete.');
}

$revocations=$network->revocations();
$publishers=$network->publishers();
```

Revocation scopes are `registry`, `publisher`, `key`, `package`, `version`, and
`artifact`. Decisions support effective and optional expiry times. Revocations
are append-only; an event id cannot be reused to silently change a decision.

Publisher attestations carry issuer, category, signal, evidence digest, issue
time, validity window, and optional package scope. Signals are `verified`,
`warning`, `failed`, and `withdrawn`. Evidence can expire, supersede earlier
evidence, or explicitly withdraw it. Profiles are evidence views, not
certification, endorsement, popularity, or a scalar reputation score.

## Distribution enforcement

Use the same live registries at every distribution boundary:

```php
$security=[
	'require_transparency'=>true,
	'transparency_verifier'=>$verifier,
	'require_revocation_check'=>true,
	'revocation_checker'=>$revocations,
	'require_publisher_trust'=>true,
	'publisher_trust_resolver'=>$publishers,
	'allowed_publisher_statuses'=>['observed'],
];

$index=PanelPackageRegistryIndex::make(
	$signedIndex,
	$packageSignatureVerifier,
	$packageTrustPolicy,
	$security,
);

$resolution=PanelPackageResolver::make($index)->resolve(['orders_pack'=>'^2.0.0']);
$acquired=PanelPackageAcquisitionPlan::make(
	$resolution,
	$transport,
	$cache,
	$packageSignatureVerifier,
	$packageTrustPolicy,
	$security,
)->acquire();

$install=$acquired->installPlan('orders_pack');
$result=$install?->apply($targetRoot);
```

The resolver never selects a revoked entry or blocked publisher. Acquisition
checks before transport access and again after signature, metadata, artifact,
and transparency verification. The acquisition result carries a process-local
activation callback into the install plan. The callback is checked during
preflight and again under the package lock before the first artifact write. A
revocation published after download therefore blocks installation with zero
published package artifacts.

Callbacks, transport locators, bundle bytes, signatures, public keys, local
paths, credentials, and evidence contents are never serialized. Manifests expose
only bounded decisions, counts, digests, reason codes, and whether a callback was
configured.

## Operations OS integration

Operations OS can own the trust network and require marketplace governance to
share its exact revocation and publisher registries. The platform then exposes
typed optional services for the network, revocation registry, and publisher
registry, while its cohesion checks reject split trust graphs.

```php
$os=PanelOperationsOs::fromConfig($stateRoot, [
	'marketplace_trust_network'=>$network,
	'marketplace_governance'=>$governance,
]);
```

`PanelMarketplaceGovernance` rechecks live revocation and publisher evidence at
review and activation time. An earlier approval cannot override a later
revocation, stale projection, failed attestation, or policy restriction.

## Host obligations

Panel deliberately does not provide registry credentials, public-key discovery,
remote log transport, witness operation, network scheduling, key custody,
malware execution sandboxes, or deployment isolation. Hosts must persist trusted
checkpoints, ingest every event in order, monitor freshness, protect state and
keys, rotate trust anchors deliberately, and block package execution until all
local and external controls have passed.
