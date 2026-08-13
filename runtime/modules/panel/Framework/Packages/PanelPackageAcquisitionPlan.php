<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Explicit, transport-neutral acquisition plan for resolved Panel packages.
 *
 * Construction and serialization perform no I/O. `acquire()` is the only
 * transport/cache boundary. Registry locators are opaque values passed only to
 * the host adapter; they are never interpreted as paths or emitted in results.
 */
final class PanelPackageAcquisitionPlan implements \JsonSerializable {
	public const FORMAT='dataphyre.panel.package.bundle.v1';
	private PanelPackageResolutionPlan $resolution;
	private PanelPackageTransport $transport;
	private PanelPackageArtifactCache $cache;
	private PanelPackageSignatureVerifier $verifier;
	private PanelPackageTrustPolicy $trustPolicy;
	private array $options;

	public function __construct(
		PanelPackageResolutionPlan $resolution,
		PanelPackageTransport $transport,
		PanelPackageArtifactCache $cache,
		PanelPackageSignatureVerifier $verifier,
		PanelPackageTrustPolicy $trustPolicy,
		array $options=[]
	) {
		$this->resolution=$resolution;
		$this->transport=$transport;
		$this->cache=$cache;
		$this->verifier=clone $verifier;
		$this->trustPolicy=clone $trustPolicy;
		$this->options=$this->sanitizeOptions($options);
	}

	public static function make(
		PanelPackageResolutionPlan $resolution,
		PanelPackageTransport $transport,
		PanelPackageArtifactCache $cache,
		PanelPackageSignatureVerifier $verifier,
		PanelPackageTrustPolicy $trustPolicy,
		array $options=[]
	): self {
		return new self($resolution, $transport, $cache, $verifier, $trustPolicy, $options);
	}

	/**
	 * Acquires and re-verifies every selected package. Offline mode never calls
	 * transport and only accepts digest-verified cache entries. A stale cache is
	 * usable solely when offline and explicitly allowed by host policy.
	 */
	public function acquire(array $options=[]): PanelPackageAcquisitionResult {
		$options=array_replace($this->options, $this->sanitizeOptions($options));
		$errors=[];$rows=[];$templates=[];$activationGates=[];
		if(!$this->resolution->ok()){$errors[]='Cannot acquire packages from an invalid resolution plan.';}
		if(!$this->cache->ready()){$errors[]='Package artifact cache is not ready.';}
		$selected=$this->resolution->selected();
		if($selected===[]){$errors[]='Resolution plan contains no selected packages.';}
		$offline=(bool)($options['offline'] ?? false);
		$allowStale=$offline && (bool)($options['allow_stale_cache'] ?? false);
		$maxBundleBytes=max(1024, min(1073741824, (int)($options['max_bundle_bytes'] ?? 67108864)));
		$maxArtifacts=max(1, min(10000, (int)($options['max_artifacts'] ?? 5000)));
		$maxArtifactBytes=max(1, min(1073741824, (int)($options['max_artifact_bytes'] ?? 67108864)));
		if($errors===[]){
			foreach($selected as $id=>$entry){
				if(!is_array($entry)){$errors[]='Resolution plan contains a malformed selected package.';continue;}
				if(!$this->marketplaceGate($entry,$options)){$errors[]='Package is revoked or its publisher evidence does not satisfy current marketplace policy.';continue;}
				$artifact=is_array($entry['artifact'] ?? null) ? $entry['artifact'] : [];
				$digest=$this->normalizeDigest((string)($artifact['sha256'] ?? ''));
				$bytes=(int)($artifact['bytes'] ?? 0);
				$contentType=$this->contentType((string)($artifact['content_type'] ?? ''));
				$locator=(string)($artifact['locator'] ?? '');
				$id=Resource::normalizeName((string)$id);
				if($id==='' || $id!==(string)($entry['id'] ?? '') || $digest==='' || $bytes<1 || $bytes>$maxBundleBytes || $contentType!==PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE || $locator===''){
					$errors[]='Resolved package artifact descriptor is invalid.';continue;
				}
				$cached=$this->cache->read($digest, [
					'max_bytes'=>min($bytes, $maxBundleBytes),
					'content_type'=>$contentType,
					'now'=>(int)($options['now'] ?? time()),
					'max_age_seconds'=>(int)($options['cache_max_age_seconds'] ?? 2592000),
					'allow_stale'=>$allowStale,
				]);
				$source='cache';$body=is_array($cached) ? (string)($cached['body'] ?? '') : '';
				$stale=is_array($cached) && !empty($cached['stale']);
				if($body==='' && $offline){$errors[]='Offline package acquisition requires a valid cached artifact.';continue;}
				if($body===''){
					$source='transport';
					try{$response=$this->transport->fetch($locator, [
						'package'=>$id,'version'=>(string)($entry['version'] ?? ''),'sha256'=>$digest,
						'max_bytes'=>min($bytes, $maxBundleBytes),'content_type'=>$contentType,
					]);}
					catch(\Throwable){$response=[];}
					$validated=$this->validateResponse($response ?? null, $digest, $bytes, $contentType, $maxBundleBytes);
					if($validated===null){$errors[]='Package transport response failed digest, size, status, encoding, or content-type validation.';continue;}
					$body=$validated;
				}
				$decoded=$this->decodeBundle($body, $id, (string)($entry['version'] ?? ''), $maxArtifacts, $maxArtifactBytes, $maxBundleBytes);
				if($decoded===null){$errors[]='Package bundle schema or artifact limits are invalid.';continue;}
				$template=$decoded['template'];
				$manifest=$template->package()->toArray();
				$verification=$this->verifier->verify($template, ['boundary'=>'registry_acquisition'])->toArray();
				$trust=$this->trustPolicy->evaluate($template->package());
				$signature=is_array($manifest['signature'] ?? null) ? $manifest['signature'] : [];
				$publisher=Resource::normalizeName((string)($signature['publisher'] ?? $manifest['support']['owner'] ?? $manifest['meta']['publisher'] ?? ''));
				$keyId=trim((string)($signature['key_id'] ?? $signature['key'] ?? ''));
				if(($verification['ok'] ?? false)!==true || ($trust['trusted'] ?? false)!==true){$errors[]='Acquired package signature or publisher trust verification failed.';continue;}
				if($publisher!==Resource::normalizeName((string)($entry['publisher'] ?? '')) || $keyId!==trim((string)($entry['key_id'] ?? ''))
					|| (string)($manifest['status'] ?? '')!==(string)($entry['status'] ?? '')
					|| $this->canonicalJson($this->normalizeRequirements($manifest['requirements'] ?? null) ?? [])!==$this->canonicalJson($this->normalizeRequirements($entry['requirements'] ?? null) ?? ['invalid'])){
					$errors[]='Acquired package publisher, key, status, or compatibility requirements do not match authenticated registry metadata.';continue;
				}
				if(!$this->verifyTransparency($entry, $digest, $options)){$errors[]='Package artifact transparency verification failed.';continue;}
				if(!$this->marketplaceGate($entry,$options)){$errors[]='Package marketplace trust changed during acquisition; verified bytes were not released.';continue;}
				if($source==='transport' && !$this->cache->write($digest, $body, $contentType, [
					'package'=>$id,'version'=>(string)($entry['version'] ?? ''),'registry_digest'=>$this->resolution->toArray()['index_digest'] ?? '',
				], ['now'=>(int)($options['now'] ?? time())])){$errors[]='Verified package artifact could not be committed atomically to cache.';continue;}
				$templates[$id]=$template;
				$activationGates[$id]=$this->activationGateFor($entry,$options);
				$rows[]=[
					'package'=>$id,'version'=>(string)($entry['version'] ?? ''),'source'=>$source,'stale_cache'=>$stale,
					'artifact_sha256'=>$digest,'artifact_bytes'=>$bytes,'content_type'=>$contentType,
					'signature'=>['algorithm'=>(string)($verification['algorithm'] ?? ''),'key_id'=>$keyId,'digest'=>(string)($verification['digest'] ?? '')],
					'trust'=>['trusted'=>true,'publisher'=>$publisher],
				];
			}
		}
		$errors=array_values(array_unique($errors));
		return PanelPackageAcquisitionResult::make([
			'ok'=>$errors===[] && count($templates)===count($selected),
			'packages'=>$rows,
			'errors'=>$errors,
			'meta'=>is_array($options['meta'] ?? null) ? $options['meta'] : [],
		], $this->verifier, $this->trustPolicy, $templates, $activationGates);
	}

	/** @return array<string,mixed> No-I/O plan manifest. */
	public function toArray(): array {
		$resolution=$this->resolution->toArray();
		return [
			'type'=>'panel_package_acquisition_plan',
			'ready'=>$this->resolution->ok() && $this->cache->ready(),
			'resolution_checksum'=>$this->resolution->checksum(),
			'package_count'=>(int)($resolution['package_count'] ?? 0),
			'offline'=>(bool)($this->options['offline'] ?? false),
			'allow_stale_cache'=>(bool)($this->options['offline'] ?? false) && (bool)($this->options['allow_stale_cache'] ?? false),
			'require_revocation_check'=>(bool)($this->options['require_revocation_check'] ?? false),
			'require_publisher_trust'=>(bool)($this->options['require_publisher_trust'] ?? false),
			'cache'=>$this->cache->toArray(),
			'transport_supplied'=>true,
			'meta'=>is_array($this->options['meta'] ?? null) ? $this->sanitize($this->options['meta']) : [],
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	private function validateResponse(mixed $response, string $digest, int $bytes, string $contentType, int $maxBytes): ?string {
		if(!is_array($response) || ($response['ok'] ?? false)!==true){return null;}
		if(isset($response['status']) && !is_int($response['status'])){return null;}
		if(!is_string($response['content_type'] ?? null) || (isset($response['content_encoding']) && !is_string($response['content_encoding'])) || (isset($response['bytes']) && !is_int($response['bytes']))){return null;}
		$status=$response['status'] ?? 200;
		$body=$response['body'] ?? null;
		$type=$this->contentType((string)($response['content_type'] ?? ''));
		$encoding=strtolower(trim((string)($response['content_encoding'] ?? 'identity')));
		if($status<200 || $status>=300 || !is_string($body) || $body==='' || $type!==$contentType || !in_array($encoding, ['', 'identity'], true)){return null;}
		$actual=strlen($body);
		if($actual!==$bytes || $actual>$maxBytes || (isset($response['bytes']) && $response['bytes']!==$actual) || !hash_equals($digest, hash('sha256', $body))){return null;}
		return $body;
	}

	/** @return array{template:PanelPackageTemplate}|null */
	private function decodeBundle(string $body, string $id, string $version, int $maxArtifacts, int $maxArtifactBytes, int $maxBundleBytes): ?array {
		if(strlen($body)>$maxBundleBytes){return null;}
		try{$bundle=json_decode($body, true, 128, JSON_THROW_ON_ERROR);}
		catch(\Throwable){return null;}
		if(!is_array($bundle) || ($bundle['format'] ?? null)!==self::FORMAT || !is_array($bundle['package'] ?? null) || !is_array($bundle['artifacts'] ?? null) || !array_is_list($bundle['artifacts']) || $bundle['artifacts']===[]){return null;}
		if(array_diff(array_keys($bundle), ['format','package','artifacts'])!==[] || count($bundle['artifacts'])>$maxArtifacts || !$this->packageManifestValid($bundle['package'])){return null;}
		$manifest=PanelPackageManifest::from($bundle['package']);
		if($manifest->id()!==$id || (string)$manifest->version()!==$version){return null;}
		$template=PanelPackageTemplate::make($manifest)->plugin(false)->provider(false)->theme(false)->docs(false)->tests(false)->with('marketplace', false);
		$total=0;$seen=[];$manifestArtifact=false;
		foreach($bundle['artifacts'] as $artifact){
			if(!is_array($artifact) || array_diff(array_keys($artifact), ['path','contents','bytes','sha256','kind'])!==[] || !array_key_exists('path', $artifact) || !array_key_exists('contents', $artifact) || !is_string($artifact['path']) || !is_string($artifact['contents'])){return null;}
			$path=$artifact['path'];$normalizedPath=$this->normalizeArtifactPath($path);$collision=strtolower($normalizedPath);
			if($normalizedPath==='' || $normalizedPath!==$path || isset($seen[$collision])){return null;}
			$seen[$collision]=true;
			$contents=$artifact['contents'];$size=strlen($contents);$total+=$size;
			if($size>$maxArtifactBytes || $total>$maxBundleBytes){return null;}
			if(array_key_exists('bytes', $artifact) && (!is_int($artifact['bytes']) || $artifact['bytes']!==$size)){return null;}
			if(array_key_exists('sha256', $artifact) && (!is_string($artifact['sha256']) || $this->normalizeDigest($artifact['sha256'])!==$artifact['sha256'] || !hash_equals($artifact['sha256'], hash('sha256', $contents)))){return null;}
			if(array_key_exists('kind', $artifact) && !is_string($artifact['kind'])){return null;}
			if($path==='dataphyre-panel-package.json'){
				try{$artifactManifest=json_decode($contents, true, 128, JSON_THROW_ON_ERROR);}
				catch(\Throwable){return null;}
				if(!is_array($artifactManifest) || $this->canonicalJson($artifactManifest)!==$this->canonicalJson($bundle['package'])){return null;}
				$manifestArtifact=true;
			}
			$template->file($path, $contents);
		}
		return $manifestArtifact ? ['template'=>$template] : null;
	}

	private function verifyTransparency(array $entry, string $digest, array $options): bool {
		$required=(bool)($options['require_transparency'] ?? false);
		$hook=$options['transparency_verifier'] ?? null;
		if(!$required && !is_callable($hook)){return true;}
		$proof=is_array($entry['transparency'] ?? null) ? $entry['transparency'] : [];
		if($proof===[] || !is_callable($hook)){return false;}
		$subject=PanelPackageRegistryIndex::packageTransparencySubject($this->resolution->registry(),$this->resolution->sequence(),$entry);
		try{return $hook('package', $subject, $proof)===true;}
		catch(\Throwable){return false;}
	}

	/** @param array<string,mixed> $entry */
	private function marketplaceGate(array $entry,array $options):bool {
		return self::marketplaceDecision($this->resolution->registry(),$entry,$options)['allowed']===true;
	}

	/** @param array<string,mixed> $entry */
	private function activationGateFor(array $entry,array $options):\Closure {
		$registry=$this->resolution->registry();
		$entry=[
			'id'=>(string)($entry['id']??''),'version'=>(string)($entry['version']??''),
			'publisher'=>(string)($entry['publisher']??''),'key_id'=>(string)($entry['key_id']??''),
			'artifact'=>['sha256'=>(string)($entry['artifact']['sha256']??'')],
			'revoked'=>($entry['revoked']??false)===true,
			'publisher_blocked'=>($entry['publisher_blocked']??false)===true,
		];
		$options=array_intersect_key($options,array_fill_keys([
			'require_revocation_check','revocation_checker','require_publisher_trust',
			'publisher_trust_resolver','allowed_publisher_statuses',
		],true));
		return static fn(array $context=[]):array=>self::marketplaceDecision($registry,$entry,$options);
	}

	/** @param array<string,mixed> $entry @param array<string,mixed> $options @return array<string,mixed> */
	private static function marketplaceDecision(string $registry,array $entry,array $options):array {
		$subject=[
			'registry'=>$registry,'publisher'=>(string)($entry['publisher']??''),
			'key_id'=>(string)($entry['key_id']??''),'package'=>(string)($entry['id']??''),
			'version'=>(string)($entry['version']??''),
			'artifact_sha256'=>(string)($entry['artifact']['sha256']??''),
		];
		$context=array_key_exists('now',$options) ? ['at'=>$options['now']] : [];
		$reasons=[];$complete=true;$stale=false;
		$revoked=($entry['revoked']??false)===true;
		$publisherBlocked=($entry['publisher_blocked']??false)===true;
		$revocationChecked=false;$publisherChecked=false;$revocationAllowed=!$revoked;$publisherStatus=$publisherBlocked?'blocked':'unchecked';
		if($revoked){$reasons['registry_entry_revoked']=true;}
		if($publisherBlocked){$reasons['registry_publisher_blocked']=true;}

		$revocationRequired=($options['require_revocation_check']??false)===true;
		$revocation=$options['revocation_checker']??null;
		if($revocationRequired&&!is_callable($revocation)){
			$complete=false;$stale=true;$revocationAllowed=false;$reasons['revocation_checker_unavailable']=true;
		}
		elseif(is_callable($revocation)){
			$revocationChecked=true;
			try{
				$decision=$revocation('package',$subject,$context);
				if($decision instanceof PanelPackageRevocationDecision){$decision=$decision->jsonSerialize();}
				elseif(is_bool($decision)){$decision=['complete'=>true,'stale'=>false,'revoked'=>$decision,'allowed'=>!$decision];}
				if(!is_array($decision)){throw new \UnexpectedValueException('Revocation decision is invalid.');}
				$decisionComplete=($decision['complete']??false)===true;
				$decisionStale=($decision['stale']??true)===true;
				$decisionRevoked=($decision['revoked']??false)===true;
				$decisionAllowed=array_key_exists('allowed',$decision)?($decision['allowed']===true):!$decisionRevoked;
				$complete=$complete&&$decisionComplete;$stale=$stale||$decisionStale;
				$revoked=$revoked||$decisionRevoked;
				$revocationAllowed=$revocationAllowed&&$decisionAllowed&&$decisionComplete&&!$decisionStale&&!$decisionRevoked;
				if(!$decisionComplete){$reasons['revocation_state_incomplete']=true;}
				if($decisionStale){$reasons['revocation_state_stale']=true;}
				if($decisionRevoked){$reasons['package_revoked']=true;}
				if(!$decisionAllowed){$reasons['revocation_policy_denied']=true;}
			}
			catch(\Throwable){$complete=false;$stale=true;$revocationAllowed=false;$reasons['revocation_checker_unavailable']=true;}
		}

		$publisherRequired=($options['require_publisher_trust']??false)===true;
		$publisherResolver=$options['publisher_trust_resolver']??null;
		if($publisherRequired&&!is_callable($publisherResolver)){
			$complete=false;$stale=true;$publisherBlocked=true;$publisherStatus='unknown';$reasons['publisher_evidence_unavailable']=true;
		}
		elseif(is_callable($publisherResolver)){
			$publisherChecked=true;
			try{
				$profile=$publisherResolver('package',$subject,$context);
				if($profile instanceof PanelPackagePublisherTrustProfile){$profile=$profile->jsonSerialize();}
				if(!is_array($profile)){throw new \UnexpectedValueException('Publisher profile is invalid.');}
				$profileComplete=($profile['complete']??false)===true;
				$profileStale=($profile['stale']??true)===true;
				$publisherStatus=(string)($profile['status']??'unknown');
				$eligible=in_array($publisherStatus,self::allowedPublisherStatuses($options),true);
				$complete=$complete&&$profileComplete;$stale=$stale||$profileStale;
				$publisherBlocked=$publisherBlocked||!$profileComplete||$profileStale||!$eligible;
				if(!$profileComplete){$reasons['publisher_evidence_incomplete']=true;}
				if($profileStale){$reasons['publisher_evidence_stale']=true;}
				if(!$eligible){$reasons['publisher_status_denied']=true;}
			}
			catch(\Throwable){$complete=false;$stale=true;$publisherBlocked=true;$publisherStatus='unknown';$reasons['publisher_evidence_unavailable']=true;}
		}

		$allowed=$complete&&!$stale&&!$revoked&&!$publisherBlocked&&$revocationAllowed;
		$reasonCodes=array_keys($reasons);sort($reasonCodes,SORT_STRING);
		return [
			'allowed'=>$allowed,'complete'=>$complete,'stale'=>$stale,'revoked'=>$revoked,
			'blocked'=>$publisherBlocked||!$revocationAllowed,'publisher_status'=>$publisherStatus,
			'revocation_checked'=>$revocationChecked,'publisher_checked'=>$publisherChecked,
			'reason_codes'=>$reasonCodes,
		];
	}

	/** @return list<string> */
	private static function allowedPublisherStatuses(array $options):array {$values=is_array($options['allowed_publisher_statuses']??null)?$options['allowed_publisher_statuses']:['observed'];$result=[];foreach($values as$value){$status=Resource::normalizeName((string)$value);if(in_array($status,['unknown','observed','restricted','blocked'],true)){$result[$status]=true;}}return$result!==[]?array_keys($result):['observed'];}

	private function sanitizeOptions(array $options): array {
		$allowed=['offline','allow_stale_cache','now','cache_max_age_seconds','max_bundle_bytes','max_artifacts','max_artifact_bytes','require_transparency','transparency_verifier','require_revocation_check','revocation_checker','require_publisher_trust','publisher_trust_resolver','allowed_publisher_statuses','meta'];
		return array_intersect_key($options, array_fill_keys($allowed, true));
	}

	private function normalizeDigest(string $digest): string {
		$digest=strtolower(trim($digest));if(str_starts_with($digest, 'sha256:')){$digest=substr($digest, 7);}
		return preg_match('/^[a-f0-9]{64}$/', $digest)===1 ? $digest : '';
	}

	private function normalizeArtifactPath(string $path): string {
		if($path==='' || trim($path)!==$path || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)===1){return '';}
		$segments=[];
		foreach(explode('/', $path) as $segment){
			if($segment==='' || $segment==='.' || $segment==='..' || preg_match('/[\x00-\x1F\x7F:]/', $segment)===1 || rtrim($segment, ". ")!==$segment || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?\z/i', $segment)===1){return '';}
			$segments[]=$segment;
		}
		return implode('/', $segments);
	}

	private function packageManifestValid(array $package): bool {
		$allowed=['id','label','version','description','class','type','status','requirements','provides','links','support','signature','compatibility','meta'];
		if(array_diff(array_keys($package), $allowed)!==[]){return false;}
		foreach(['id','label','version','type','status'] as $field){if(!is_string($package[$field] ?? null)){return false;}}
		foreach(['description','class'] as $field){if(array_key_exists($field, $package) && !is_string($package[$field]) && $package[$field]!==null){return false;}}
		$id=Resource::normalizeName($package['id']);$type=Resource::normalizeName($package['type']);$status=Resource::normalizeName($package['status']);
		if($id==='' || $id!==$package['id'] || $type==='' || $type!==$package['type'] || $status==='' || $status!==$package['status'] || !PanelPackageManifest::validVersion($package['version'])){return false;}
		if(array_key_exists('compatibility', $package) && $package['compatibility']!==null){return false;}
		if($this->normalizeRequirements($package['requirements'] ?? null)===null){return false;}
		$provides=$package['provides'] ?? [];
		if(!is_array($provides) || !array_is_list($provides)){return false;}
		$seen=[];foreach($provides as $provide){$normalized=is_string($provide) ? Resource::normalizeName($provide) : '';if($normalized==='' || $normalized!==$provide || isset($seen[$normalized])){return false;}$seen[$normalized]=true;}
		$links=$package['links'] ?? [];
		if(!is_array($links) || !array_is_list($links)){return false;}
		foreach($links as $link){if(!is_array($link) || !is_string($link['label'] ?? null) || !is_string($link['target'] ?? null) || trim($link['target'])===''){return false;}}
		$support=$package['support'] ?? [];$meta=$package['meta'] ?? [];$signature=$package['signature'] ?? null;
		if(!is_array($support) || !is_array($meta) || !is_array($signature)){return false;}
		if(isset($support['owner']) && (!is_string($support['owner']) || Resource::normalizeName($support['owner'])!==$support['owner'])){return false;}
		if(isset($meta['publisher']) && (!is_string($meta['publisher']) || Resource::normalizeName($meta['publisher'])!==$meta['publisher'])){return false;}
		if(array_key_exists('public_key', $signature) || array_key_exists('private_key', $signature) || array_key_exists('secret_key', $signature)){return false;}
		foreach(['algorithm','key_id','publisher','digest','signature'] as $field){if(!is_string($signature[$field] ?? null) || trim($signature[$field])==='' || trim($signature[$field])!==$signature[$field]){return false;}}
		if(!in_array($signature['algorithm'], ['ed25519','rsa-sha256','ecdsa-sha256'], true)
			|| Resource::normalizeName($signature['publisher'])!==$signature['publisher']
			|| strlen($signature['key_id'])>256 || preg_match('/^[A-Za-z0-9._:-]+$/D', $signature['key_id'])!==1
			|| preg_match('/^[a-f0-9]{64}$/D', $signature['digest'])!==1){return false;}
		return true;
	}

	/** @return array<string,mixed>|null */
	private function normalizeRequirements(mixed $requirements): ?array {
		if(!is_array($requirements) || ($requirements!==[] && array_is_list($requirements)) || array_diff(array_keys($requirements), ['php','panel','reactor','modules','themes'])!==[]){return null;}
		$normalized=['php'=>null,'panel'=>null,'reactor'=>null,'modules'=>[],'themes'=>[]];
		foreach(['php','panel','reactor'] as $name){
			if(!array_key_exists($name, $requirements)){continue;}$value=$requirements[$name];
			if($value===null){continue;}if(!is_string($value) || !$this->constraintValid($value)){return null;}$normalized[$name]=trim($value);
		}
		$modules=$requirements['modules'] ?? [];
		if(!is_array($modules) || ($modules!==[] && array_is_list($modules))){return null;}
		foreach($modules as $module=>$constraint){$id=is_string($module) ? Resource::normalizeName($module) : '';if($id==='' || $id!==$module || !is_string($constraint) || !$this->constraintValid($constraint)){return null;}$normalized['modules'][$id]=trim($constraint);}
		ksort($normalized['modules'], SORT_STRING);
		$themes=$requirements['themes'] ?? [];
		if(!is_array($themes) || !array_is_list($themes)){return null;}
		foreach($themes as $theme){$id=is_string($theme) ? Resource::normalizeName($theme) : '';if($id==='' || $id!==$theme || in_array($id, $normalized['themes'], true)){return null;}$normalized['themes'][]=$id;}
		return $normalized;
	}

	private function constraintValid(string $constraint): bool {
		$constraint=trim($constraint);if($constraint==='' || $constraint==='*'){return $constraint==='*';}
		foreach(preg_split('/\s*,\s*/', $constraint) ?: [] as $part){$part=trim($part);if($part==='*'){continue;}if(preg_match('/^(?:\^|>=|<=|>|<|==|=)?\s*(\S+)$/D', $part, $matches)!==1 || !PanelPackageManifest::validVersion($matches[1])){return false;}}
		return true;
	}

	private function canonicalJson(mixed $value): string {
		return json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	private function canonicalize(mixed $value): mixed {
		if(is_array($value)){
			if(!array_is_list($value)){ksort($value, SORT_STRING);}
			foreach($value as $key=>$item){$value[$key]=$this->canonicalize($item);}
			return $value;
		}
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){return $value;}
		if(is_float($value) && is_finite($value)){return $value;}
		throw new \InvalidArgumentException('Unsupported package bundle value.');
	}

	private function contentType(string $contentType): string { return strtolower(trim(explode(';', $contentType)[0])); }

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && $this->sensitiveKey($key)){return '[REDACTED]';}
		if(!is_array($value)){return is_object($value) ? '[OBJECT]' : $value;}
		$safe=[];foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item, is_string($itemKey) ? $itemKey : '');}return $safe;
	}

	private function sensitiveKey(string $key): bool {
		$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
		return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i', $key)===1;
	}
}
