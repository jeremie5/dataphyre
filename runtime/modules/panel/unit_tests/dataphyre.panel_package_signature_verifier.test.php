<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelPackageInstallPlan;
use Dataphyre\Panel\PanelPackageManifest;
use Dataphyre\Panel\PanelPackageSignatureVerifier;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!class_exists(\dataphyre\core::class, false)){
	require_once dirname(__DIR__, 2).'/core/kernel/core_functions.php';
}
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

/** @return array{0:PanelPackageManifest,1:PanelPackageTemplate,2:PanelPackageSignatureVerifier} */
function dp_panel_signed_package(string $id='signed-package'): array {
	$keypair=sodium_crypto_sign_keypair();
	$public=sodium_crypto_sign_publickey($keypair);
	$secret=sodium_crypto_sign_secretkey($keypair);
	$manifest=PanelPackageManifest::make($id, 'Signed package')->version('1.2.3')->support('owner', 'shopiro');
	$template=PanelPackageTemplate::make($manifest)
		->plugin(false)
		->provider(false)
		->docs(false)
		->tests(false)
		->with('marketplace', false)
		->file('src/Feature.php', '<?php return "signed";');
	$verifier=PanelPackageSignatureVerifier::make([
		'release-2026'=>[
			'algorithm'=>'ed25519',
			'public_key'=>base64_encode($public),
			'meta'=>['owner'=>'Shopiro'],
		],
	], ['meta'=>['environment'=>'test']]);
	$payload=$verifier->payload($template);
	$signature=sodium_crypto_sign_detached($payload, $secret);
	$manifest->signature([
		'algorithm'=>'ed25519',
		'key_id'=>'release-2026',
		'digest'=>'sha256:'.hash('sha256', $payload),
		'signature'=>rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
		'publisher'=>'shopiro',
	]);
	return [$manifest, $template, $verifier];
}

test('package signatures cover canonical manifest and artifact content with host-owned Ed25519 keys', static function(Context $t): void {
	[, $template, $verifier]=dp_panel_signed_package();
	$result=$verifier->verify($template, ['request'=>'install']);

	$t->isTrue($result->ok());
	$t->same('signed-package', $result->package());
	$t->same('ed25519', $result->algorithm());
	$t->same('release-2026', $result->keyId());
	$t->same(64, strlen($result->digest()));
	$t->same([], $result->errors());
	$t->same(9, count($result->checks()));
	$t->same('install', $result->toArray()['meta']['request']);
	$t->same($result->toArray(), $result->jsonSerialize());

	$policy=$verifier->toArray();
	$t->same('panel_package_signature_verifier', $policy['type']);
	$t->same('ed25519', $policy['keys'][0]['algorithm']);
	$t->same(64, strlen($policy['keys'][0]['fingerprint']));
	$t->isFalse(str_contains(json_encode($policy) ?: '', 'public_key'));
	$t->isFalse(str_contains(json_encode($policy) ?: '', 'BEGIN PRIVATE'));
})->tag('panel', 'package', 'signature', 'security')->maxMillis(1000);

test('package verification fails closed for tampering unknown keys bad digests and unsupported algorithms', static function(Context $t): void {
	[$manifest, $template, $verifier]=dp_panel_signed_package('tamper-package');
	$template->file('src/Feature.php', '<?php return "tampered";');
	$tampered=$verifier->verify($template);
	$t->isFalse($tampered->ok());
	$t->contains('Package payload digest does not match.', $tampered->errors());
	$t->contains('Package cryptographic signature is invalid.', $tampered->errors());

	[, $freshTemplate, $freshVerifier]=dp_panel_signed_package('unknown-key');
	$freshTemplate->package()->signature('key_id', 'untrusted-key');
	$unknown=$freshVerifier->verify($freshTemplate);
	$t->isFalse($unknown->ok());
	$t->contains('Package signing key is not trusted by this verifier.', $unknown->errors());

	[, $digestTemplate, $digestVerifier]=dp_panel_signed_package('bad-digest');
	$digestTemplate->package()->signature('digest', str_repeat('0', 64));
	$badDigest=$digestVerifier->verify($digestTemplate);
	$t->isFalse($badDigest->ok());
	$t->contains('Package payload digest does not match.', $badDigest->errors());

	$manifest->signature(['algorithm'=>'rot13', 'key_id'=>'release-2026']);
	$unsupported=$verifier->verify($template);
	$t->isFalse($unsupported->ok());
	$t->contains('Package signature algorithm is unsupported or missing.', $unsupported->errors());
})->tag('panel', 'package', 'signature', 'security')->maxMillis(1000);

test('package verifier rejects unsafe duplicate and oversized bundles before cryptographic work', static function(Context $t): void {
	$verifier=PanelPackageSignatureVerifier::make([], ['max_artifacts'=>1, 'max_bytes'=>4]);
	$result=$verifier->verify([
		'package'=>[
			'id'=>'unsafe',
			'signature'=>['algorithm'=>'ed25519', 'key_id'=>'missing', 'digest'=>str_repeat('0', 64), 'signature'=>'not-valid'],
		],
		'artifacts'=>[
			['path'=>'../escape.php', 'contents'=>'escape'],
			['path'=>'safe.php', 'contents'=>'12345'],
		],
	]);

	$t->isFalse($result->ok());
	$t->contains('Package exceeds the verifier artifact limit.', $result->errors());
	$t->contains('Package contains an unsafe artifact path.', $result->errors());
	$t->contains('Package exceeds the verifier byte limit.', $result->errors());
	$t->same(1, $result->toArray()['artifact_count']);

	$malformed=$verifier->verify(['package'=>['id'=>'object', 'meta'=>new stdClass()], 'artifacts'=>[]]);
	$t->isFalse($malformed->ok());
	$t->contains('Package payload is not canonically serializable.', $malformed->errors());
})->tag('panel', 'package', 'signature', 'security', 'limits')->maxMillis(1000);

test('install plans expose verification and refuse any write when package verification fails', static function(Context $t): void {
	[, $template, $verifier]=dp_panel_signed_package('verified-install');
	$root=$t->workspace('dp-panel-verified-install')->root();
	$plan=PanelPackageInstallPlan::make($template)->signatureVerifier($verifier);
	$preview=$plan->manifest();
	$t->isTrue($preview['ready']);
	$t->isTrue($preview['verification']['ok']);

	$result=$plan->apply($root);
	$t->isTrue($result->ok());
	$t->isTrue($result->toArray()['meta']['signature_verified']);
	$t->same(64, strlen((string)$result->toArray()['meta']['verification_digest']));
	$t->same('<?php return "signed";', file_get_contents($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Feature.php'));

	$template->file('src/Feature.php', '<?php return "tampered";');
	$blockedRoot=$t->workspace('dp-panel-blocked-install')->root();
	$blocked=PanelPackageInstallPlan::make($template, '', ['signature_verifier'=>$verifier])->apply($blockedRoot);
	$t->isFalse($blocked->ok());
	$t->same([], $blocked->written());
	$t->isFalse(is_file($blockedRoot.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Feature.php'));
})->tag('panel', 'package', 'signature', 'installer', 'security')->maxMillis(1500);

test('apply-time overwrite_policy override is honored independently of the plan default', static function(Context $t): void {
	$root=$t->workspace('dp-panel-overwrite-override')->root();
	$template=PanelPackageTemplate::make('overwrite-override')
		->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('value.txt', 'new');
	file_put_contents($root.DIRECTORY_SEPARATOR.'value.txt', 'old');
	$result=PanelPackageInstallPlan::make($template)->apply($root, ['overwrite_policy'=>'skip']);

	$t->isTrue($result->ok());
	$t->same('old', file_get_contents($root.DIRECTORY_SEPARATOR.'value.txt'));
	$t->same(1, count($result->skipped()));
	$t->same('skip', $result->toArray()['meta']['overwrite_policy']);
})->tag('panel', 'package', 'installer', 'regression')->maxMillis(1000);

test('Panel facade and surface expose package signature verifier factories', static function(Context $t): void {
	$t->instanceOf(PanelPackageSignatureVerifier::class, Panel::packageSignatureVerifier());
	$t->instanceOf(PanelPackageSignatureVerifier::class, PanelInstance::make('signature-factory')->packageSignatureVerifier());
})->tag('panel', 'package', 'signature', 'facade')->maxMillis(1000);
