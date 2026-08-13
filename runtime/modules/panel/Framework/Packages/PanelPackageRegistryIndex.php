<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Verified, freshness-bound view of a signed remote package registry index.
 *
 * The index is transport neutral. Callers provide already-fetched JSON bytes,
 * host-owned public keys, a trust policy, a trusted host clock, and monotonic
 * sequence state. Registry timestamps are signed evidence but never replace the
 * host clock or previously persisted sequence/digest state.
 */
final class PanelPackageRegistryIndex implements \JsonSerializable {
	public const FORMAT='dataphyre.panel.package.registry.v1';
	public const CONTENT_TYPE='application/vnd.dataphyre.panel-package-registry+json';
	public const BUNDLE_CONTENT_TYPE='application/vnd.dataphyre.panel-package+json';
	private bool $ok=false;
	private string $registry='';
	private string $publisher='';
	private int $sequence=0;
	private string $generatedAt='';
	private string $expiresAt='';
	private string $digest='';
	private string $envelopeDigest='';
	private array $entries=[];
	private array $errors=[];
	private array $verification=[];
	private array $trust=[];
	private array $transparency=[];
	private array $revocation=[];
	private array $publisherTrust=[];
	private array $meta=[];
	private bool $stale=false;

	/**
	 * @param array<string,mixed>|string $index Signed index payload or JSON bytes.
	 * @param array<string,mixed> $options Host-owned freshness, limits, and transparency policy.
	 */
	public function __construct(
		array|string $index,
		PanelPackageSignatureVerifier $verifier,
		PanelPackageTrustPolicy $trustPolicy,
		array $options=[]
	) {
		$maxIndexBytes=max(1024, min(16777216, (int)($options['max_index_bytes'] ?? 2097152)));
		if(is_string($index)){
			if(strlen($index)>$maxIndexBytes){$this->errors[]='Registry index exceeds the configured byte limit.';return;}
			try{$decoded=json_decode($index, true, 128, JSON_THROW_ON_ERROR);}
			catch(\Throwable){$this->errors[]='Registry index JSON is invalid.';return;}
			if(!is_array($decoded)){$this->errors[]='Registry index must be a JSON object.';return;}
			$index=$decoded;
		}
		try{$encoded=self::canonicalJson($index);}
		catch(\Throwable){$this->errors[]='Registry index is not canonically serializable.';return;}
		if(strlen($encoded)>$maxIndexBytes){$this->errors[]='Registry index exceeds the configured byte limit.';return;}
		$this->envelopeDigest=hash('sha256', $encoded);

		$rawSignature=$index['signature'] ?? null;
		$signature=$this->signatureDescriptor($rawSignature);
		$body=$index;
		unset($body['signature']);
		$canonicalBody=self::canonicalJson($body);
		$this->digest=hash('sha256', $canonicalBody);
		$rawRegistry=$body['registry'] ?? null;
		$rawPublisher=$body['publisher'] ?? null;
		$this->registry=is_string($rawRegistry) ? Resource::normalizeName($rawRegistry) : '';
		$this->publisher=is_string($rawPublisher) ? Resource::normalizeName($rawPublisher) : '';
		$this->sequence=is_int($body['sequence'] ?? null) ? max(0, $body['sequence']) : 0;
		$this->generatedAt=is_string($body['generated_at'] ?? null) ? $body['generated_at'] : '';
		$this->expiresAt=is_string($body['expires_at'] ?? null) ? $body['expires_at'] : '';
		if(isset($body['transparency']) && !is_array($body['transparency'])){$this->errors[]='Registry index transparency proof is malformed.';}
		$this->transparency=is_array($body['transparency'] ?? null) ? $body['transparency'] : [];
		$this->meta=is_array($options['meta'] ?? null) ? $this->sanitize($options['meta']) : [];

		if(($body['format'] ?? null)!==self::FORMAT){$this->errors[]='Registry index format is unsupported.';}
		if($this->registry==='' || $rawRegistry!==$this->registry){$this->errors[]='Registry index identifier is missing or non-canonical.';}
		if($this->publisher==='' || $rawPublisher!==$this->publisher){$this->errors[]='Registry index publisher is missing or non-canonical.';}
		if($this->sequence<1){$this->errors[]='Registry index sequence must be a positive integer.';}

		$signaturePublisher=Resource::normalizeName((string)($signature['publisher'] ?? ''));
		if($signaturePublisher==='' || $signaturePublisher!==$this->publisher){
			$this->errors[]='Registry signature publisher does not match the signed index publisher.';
		}
		$bundle=self::verificationBundle($body, $signature);
		$this->verification=$verifier->verify($bundle, ['boundary'=>'registry_index'])->toArray();
		if(($this->verification['ok'] ?? false)!==true){$this->errors[]='Registry index cryptographic verification failed.';}
		$registryManifest=PanelPackageManifest::from($bundle['package']);
		$this->trust=$trustPolicy->evaluate($registryManifest);
		if(($this->trust['trusted'] ?? false)!==true){$this->errors[]='Registry index publisher or signing key is not trusted.';}
		$registrySubject=['registry'=>$this->registry,'publisher'=>$this->publisher,'key_id'=>(string)($signature['key_id']??'')];
		$this->revocation=$this->revocationDecision('registry',$registrySubject,$options,true);
		$this->publisherTrust=$this->publisherDecision('registry',$registrySubject,$options,true);

		$this->verifyFreshness($options);
		$this->entries=$this->normalizeEntries($body['packages'] ?? null, $signature, $options);
		$this->verifyTransparency('index', self::indexTransparencySubject($body), $this->transparency, $options);
		$this->errors=array_values(array_unique($this->errors));
		$this->ok=$this->errors===[] && $this->entries!==[];
	}

	/** @return self Verified registry index. */
	public static function make(array|string $index, PanelPackageSignatureVerifier $verifier, PanelPackageTrustPolicy $trustPolicy, array $options=[]): self {
		return new self($index, $verifier, $trustPolicy, $options);
	}

	/**
	 * Returns the exact bytes a registry publisher signs.
	 *
	 * The input may omit `signature`; when present it is excluded from the signed
	 * body and used only as detached signature metadata during verification.
	 */
	public static function signaturePayload(array $index, PanelPackageSignatureVerifier $verifier): string {
		$signature=is_array($index['signature'] ?? null) ? $index['signature'] : [];
		unset($index['signature']);
		return $verifier->payload(self::verificationBundle($index, $signature));
	}

	/** @return array<string,mixed> Public, non-circular transparency commitment for a signed registry body. */
	public static function indexTransparencySubject(array $index): array {
		unset($index['signature']);
		$registry=Resource::normalizeName(is_string($index['registry']??null)?$index['registry']:'');
		$publisher=Resource::normalizeName(is_string($index['publisher']??null)?$index['publisher']:'');
		$sequence=is_int($index['sequence']??null)?$index['sequence']:0;
		$commitment=$index;unset($commitment['transparency']);
		if(is_array($commitment['packages']??null)){
			foreach($commitment['packages']as&$entry){if(!is_array($entry)){continue;}unset($entry['transparency']);if(is_array($entry['artifact']??null)){unset($entry['artifact']['locator']);}}
			unset($entry);
		}
		return['registry'=>$registry,'publisher'=>$publisher,'sequence'=>$sequence,'commitment'=>hash('sha256',self::canonicalJson($commitment))];
	}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	public static function packageTransparencySubject(string $registry,int $sequence,array $entry): array {
		$artifact=is_array($entry['artifact']??null)?$entry['artifact']:[];
		$dependencies=is_array($entry['dependencies']??null)?$entry['dependencies']:[];if(!array_is_list($dependencies)){ksort($dependencies,SORT_STRING);}
		$sourceRequirements=is_array($entry['requirements']??null)?$entry['requirements']:[];
		$requirements=['php'=>isset($sourceRequirements['php'])?(string)$sourceRequirements['php']:null,'panel'=>isset($sourceRequirements['panel'])?(string)$sourceRequirements['panel']:null,'reactor'=>isset($sourceRequirements['reactor'])?(string)$sourceRequirements['reactor']:null,'modules'=>is_array($sourceRequirements['modules']??null)?$sourceRequirements['modules']:[],'themes'=>is_array($sourceRequirements['themes']??null)?array_values($sourceRequirements['themes']):[]];ksort($requirements['modules'],SORT_STRING);
		return[
			'registry'=>Resource::normalizeName($registry),'sequence'=>$sequence,
			'package'=>(string)($entry['id']??''),'version'=>(string)($entry['version']??''),'status'=>(string)($entry['status']??''),
			'publisher'=>(string)($entry['publisher']??''),'key_id'=>(string)($entry['key_id']??''),
			'dependencies'=>$dependencies,
			'requirements'=>$requirements,
			'yanked'=>($entry['yanked']??false)===true,
			'artifact_sha256'=>(string)($artifact['sha256']??''),'artifact_bytes'=>(int)($artifact['bytes']??0),
			'artifact_content_type'=>(string)($artifact['content_type']??''),
		];
	}

	public function ok(): bool { return $this->ok; }
	public function registry(): string { return $this->registry; }
	public function publisher(): string { return $this->publisher; }
	public function sequence(): int { return $this->sequence; }
	public function digest(): string { return $this->digest; }
	public function envelopeDigest(): string { return $this->envelopeDigest; }
	/** @return array<int,array<string,mixed>> Trusted internal entries, including opaque transport locators. */
	public function entries(): array { return $this->entries; }
	/** @return array<int,string> */
	public function errors(): array { return $this->errors; }

	/** @return array<string,mixed> CI-verifiable index manifest without transport locators or signature bytes. */
	public function toArray(): array {
		$safeEntries=[];
		foreach($this->entries as $entry){
			$safe=$entry;
			$locator=(string)($safe['artifact']['locator'] ?? '');
			unset($safe['artifact']['locator']);
			$safe['artifact']['locator_digest']=$locator!=='' ? hash('sha256', $locator) : '';
			$safeEntries[]=$this->sanitize($safe);
		}
		$signature=[
			'algorithm'=>(string)($this->verification['algorithm'] ?? ''),
			'key_id'=>(string)($this->verification['key_id'] ?? ''),
			'digest'=>(string)($this->verification['digest'] ?? ''),
		];
		return [
			'type'=>'panel_package_registry_index',
			'format'=>self::FORMAT,
			'ok'=>$this->ok,
			'registry'=>$this->registry,
			'publisher'=>$this->publisher,
			'sequence'=>$this->sequence,
			'generated_at'=>$this->generatedAt,
			'expires_at'=>$this->expiresAt,
			'stale'=>$this->stale,
			'digest'=>$this->digest,
			'envelope_digest'=>$this->envelopeDigest,
			'package_count'=>count($safeEntries),
			'packages'=>$safeEntries,
			'signature'=>$signature,
			'trust'=>$this->sanitize($this->trust),
			'transparency'=>$this->sanitize($this->transparency),
			'revocation'=>$this->sanitize($this->revocation),
			'publisher_trust'=>$this->sanitize($this->publisherTrust),
			'errors'=>$this->errors,
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return array<string,mixed> Synthetic signed bundle understood by the package verifier. */
	private static function verificationBundle(array $body, array $signature): array {
		$registry=Resource::normalizeName(is_string($body['registry'] ?? null) ? $body['registry'] : 'registry') ?: 'registry';
		$publisher=Resource::normalizeName(is_string($body['publisher'] ?? null) ? $body['publisher'] : '');
		$sequence=is_int($body['sequence'] ?? null) ? max(0, $body['sequence']) : 0;
		return [
			'package'=>[
				'id'=>'registry_'.$registry,
				'label'=>'Panel package registry '.$registry,
				'version'=>(string)$sequence,
				'type'=>'registry',
				'status'=>'stable',
				'support'=>['owner'=>$publisher],
				'meta'=>['publisher'=>$publisher],
				'signature'=>$signature,
			],
			'artifacts'=>[[
				'path'=>'registry-index.json',
				'contents'=>self::canonicalJson($body),
			]],
		];
	}

	private function verifyFreshness(array $options): void {
		$now=(int)($options['now'] ?? time());
		$clockSkew=max(0, min(3600, (int)($options['clock_skew_seconds'] ?? 300)));
		$maxAge=max(60, min(31536000, (int)($options['max_age_seconds'] ?? 86400)));
		$generated=$this->parseTime($this->generatedAt);
		$expires=$this->parseTime($this->expiresAt);
		if($generated===null){$this->errors[]='Registry index generated_at is invalid.';}
		elseif($generated>$now+$clockSkew){$this->errors[]='Registry index was generated in the future relative to the trusted host clock.';}
		elseif(($now-$generated)>$maxAge){$this->stale=true;}
		if($expires===null){$this->errors[]='Registry index expires_at is invalid.';}
		elseif($expires<$now-$clockSkew){$this->stale=true;}
		if($generated!==null && $expires!==null && $expires<$generated){$this->errors[]='Registry index expires_at precedes generated_at.';}
		$allowStale=!empty($options['offline']) && !empty($options['allow_stale_cache']);
		if($this->stale && !$allowStale){$this->errors[]='Registry index is stale or expired under the trusted host clock policy.';}

		$minimum=max(0, (int)($options['minimum_sequence'] ?? 0));
		$previous=$this->normalizeDigest((string)($options['previous_digest'] ?? ''));
		if($minimum>0 && $this->sequence<$minimum){
			$this->errors[]='Registry index sequence is older than trusted host state.';
		}
		elseif($minimum>0 && $this->sequence===$minimum && ($previous==='' || !hash_equals($previous, $this->digest))){
			$this->errors[]='Registry index reuses a trusted sequence with different content.';
		}
	}

	/** @return array<int,array<string,mixed>> */
	private function normalizeEntries(mixed $packages, array $indexSignature, array $options): array {
		if(!is_array($packages) || !array_is_list($packages) || $packages===[]){
			$this->errors[]='Registry index packages must be a non-empty list.';
			return [];
		}
		$maxPackages=max(1, min(10000, (int)($options['max_packages'] ?? 2000)));
		if(count($packages)>$maxPackages){$this->errors[]='Registry index exceeds the configured package-count limit.';return [];}
		$maxPackageBytes=max(1024, min(1073741824, (int)($options['max_package_bytes'] ?? 67108864)));
		$allowedTypes=array_values(array_unique(array_map(static fn(mixed $type): string=>strtolower(trim((string)$type)), (array)($options['allowed_content_types'] ?? [self::BUNDLE_CONTENT_TYPE]))));
		$indexKey=trim((string)($indexSignature['key_id'] ?? $indexSignature['key'] ?? ''));
		$seen=[];
		$entries=[];
		foreach($packages as $package){
			if(!is_array($package)){$this->errors[]='Registry contains a malformed package entry.';continue;}
			$rawId=$package['id'] ?? null;
			$rawVersion=$package['version'] ?? null;
			$id=is_string($rawId) ? Resource::normalizeName($rawId) : '';
			$version=is_string($rawVersion) ? trim($rawVersion) : '';
			$key=$id.'@'.strtolower($version);
			if($id==='' || $id!==$rawId){$this->errors[]='Registry package id is missing or non-canonical.';continue;}
			if($version!==$rawVersion || !PanelPackageManifest::validVersion($version)){$this->errors[]='Registry package version is not valid semantic version metadata.';continue;}
			if(isset($seen[$key])){$this->errors[]='Registry contains a duplicate package id and version.';continue;}
			$seen[$key]=true;
			$rawEntryPublisher=$package['publisher'] ?? null;
			$rawKeyId=$package['key_id'] ?? null;
			$publisher=is_string($rawEntryPublisher) ? Resource::normalizeName($rawEntryPublisher) : '';
			$keyId=is_string($rawKeyId) ? trim($rawKeyId) : '';
			if($publisher==='' || $publisher!==$rawEntryPublisher || $keyId==='' || $keyId!==$rawKeyId){$this->errors[]='Registry package publisher or key id is malformed.';continue;}
			if($publisher!==$this->publisher){$this->errors[]='Registry package publisher does not match the authenticated index publisher.';continue;}
			if($keyId==='' || $indexKey==='' || !hash_equals($indexKey, $keyId)){$this->errors[]='Registry package key id does not match the authenticated index key.';continue;}
			$artifact=is_array($package['artifact'] ?? null) ? $package['artifact'] : [];
			$rawLocator=$artifact['locator'] ?? null;$rawSha=$artifact['sha256'] ?? null;$rawBytes=$artifact['bytes'] ?? null;$rawContentType=$artifact['content_type'] ?? null;
			$locator=is_string($rawLocator) ? trim($rawLocator) : '';
			$sha=is_string($rawSha) ? $this->normalizeDigest($rawSha) : '';
			$bytes=is_int($rawBytes) ? $rawBytes : 0;
			$contentType=is_string($rawContentType) ? strtolower(trim(explode(';', $rawContentType)[0])) : '';
			if($locator==='' || $locator!==$rawLocator || strlen($locator)>2048 || str_contains($locator, "\0")){$this->errors[]='Registry package artifact locator is invalid.';continue;}
			if($sha==='' || $bytes<1 || $bytes>$maxPackageBytes){$this->errors[]='Registry package artifact digest or size is invalid.';continue;}
			if($contentType==='' || $contentType!==$rawContentType){$this->errors[]='Registry package artifact content type is non-canonical.';continue;}
			if(!in_array($contentType, $allowedTypes, true)){$this->errors[]='Registry package artifact content type is not allowed.';continue;}
			if(array_key_exists('archive', $artifact) || str_contains($contentType, 'zip') || str_contains($contentType, 'tar') || str_contains($contentType, 'gzip')){
				$this->errors[]='Archive package artifacts are not supported.';continue;
			}
			$dependencies=[];
			$rawDependencies=$package['dependencies'] ?? [];
			if(!is_array($rawDependencies) || ($rawDependencies!==[] && array_is_list($rawDependencies))){$this->errors[]='Registry package dependencies must be an id-to-constraint map.';continue;}
			$dependencyValid=true;
			foreach($rawDependencies as $dependency=>$constraint){
				$normalized=is_string($dependency) ? Resource::normalizeName($dependency) : '';
				$constraint=is_string($constraint) ? trim($constraint) : '';
				if($normalized==='' || $normalized!==(string)$dependency || $normalized===$id || !$this->constraintValid($constraint) || strlen($constraint)>128 || isset($dependencies[$normalized])){$dependencyValid=false;break;}
				$dependencies[$normalized]=$constraint;
			}
			if(!$dependencyValid){$this->errors[]='Registry package dependencies contain an unsafe, self-referential, or duplicate entry.';continue;}
			ksort($dependencies, SORT_STRING);
			if(isset($package['transparency']) && !is_array($package['transparency'])){$this->errors[]='Registry package transparency proof is malformed.';continue;}
			$transparency=is_array($package['transparency'] ?? null) ? $package['transparency'] : [];
			$requirements=$this->normalizeRequirements($package['requirements'] ?? []);
			if($requirements===null){$this->errors[]='Registry package compatibility requirements are malformed.';continue;}
			$rawStatus=$package['status'] ?? 'stable';
			$status=is_string($rawStatus) ? Resource::normalizeName($rawStatus) : '';
			if($status==='' || $status!==$rawStatus){$this->errors[]='Registry package status is malformed or non-canonical.';continue;}
			if(isset($package['yanked']) && !is_bool($package['yanked'])){$this->errors[]='Registry package yanked flag is malformed.';continue;}
			$listing=$this->normalizeListing($package['listing'] ?? []);
			if($listing===null){$this->errors[]='Registry package marketplace listing is malformed.';continue;}
			$entry=[
				'id'=>$id,'version'=>$version,
				'status'=>$status,
				'publisher'=>$publisher,'key_id'=>$keyId,
				'dependencies'=>$dependencies,
				'requirements'=>$requirements,
				'yanked'=>(bool)($package['yanked'] ?? false),
				'artifact'=>['locator'=>$locator,'sha256'=>$sha,'bytes'=>$bytes,'content_type'=>$contentType],
				'transparency'=>$transparency,
			];
			if($listing!==[]){$entry['listing']=$listing;}
			$packageSubject=['registry'=>$this->registry,'publisher'=>$publisher,'key_id'=>$keyId,'package'=>$id,'version'=>$version,'artifact_sha256'=>$sha];
			$entry['revocation']=$this->revocationDecision('package',$packageSubject,$options,false);
			$entry['revoked']=($entry['revocation']['checked']??false)===true&&(($entry['revocation']['allowed']??false)!==true||($entry['revocation']['revoked']??false)===true||($entry['revocation']['complete']??true)!==true||($entry['revocation']['stale']??false)===true);
			$entry['publisher_trust']=$this->publisherDecision('package',$packageSubject,$options,false);
			$allowedPublisherStatuses=$this->allowedPublisherStatuses($options);
			$entry['publisher_blocked']=($entry['publisher_trust']['checked']??false)===true&&(!in_array((string)($entry['publisher_trust']['status']??'unknown'),$allowedPublisherStatuses,true)||($entry['publisher_trust']['complete']??true)!==true||($entry['publisher_trust']['stale']??false)===true);
			$this->verifyTransparency('package', self::packageTransparencySubject($this->registry,$this->sequence,$entry), $transparency, $options);
			$entries[]=$entry;
		}
		usort($entries, static function(array $left, array $right): int {
			$id=strcmp((string)$left['id'], (string)$right['id']);
			if($id!==0){return $id;}
			$version=PanelPackageManifest::compareVersions((string)$right['version'], (string)$left['version']);
			return $version!==0 ? $version : strcmp((string)$left['artifact']['sha256'], (string)$right['artifact']['sha256']);
		});
		return $entries;
	}

	/** @param array<string,mixed> $subject @return array<string,mixed> */
	private function revocationDecision(string $kind,array $subject,array $options,bool $fatalRevocation):array {
		$required=($options['require_revocation_check']??false)===true;$hook=$options['revocation_checker']??null;
		if(!$required&&!is_callable($hook)){return['checked'=>false,'complete'=>false,'stale'=>false,'revoked'=>false,'allowed'=>true,'matches'=>[]];}
		if(!is_callable($hook)){$this->errors[]='Registry policy requires a revocation checker.';return['checked'=>false,'complete'=>false,'stale'=>true,'revoked'=>false,'allowed'=>false,'matches'=>[]];}
		try{$raw=$hook($kind,$subject,['at'=>$options['now']??time()]);if($raw instanceof PanelPackageRevocationDecision){$raw=$raw->jsonSerialize();}elseif(is_bool($raw)){$raw=['complete'=>true,'stale'=>false,'revoked'=>$raw,'allowed'=>!$raw,'matches'=>[]];}if(!is_array($raw)){throw new \UnexpectedValueException('Revocation checker returned an unsupported decision.');}$decision=['checked'=>true,'complete'=>($raw['complete']??false)===true,'stale'=>($raw['stale']??true)===true,'revoked'=>($raw['revoked']??false)===true,'allowed'=>($raw['allowed']??false)===true,'matches'=>is_array($raw['matches']??null)?$raw['matches']:[]];}
		catch(\Throwable){$decision=['checked'=>true,'complete'=>false,'stale'=>true,'revoked'=>false,'allowed'=>false,'matches'=>[]];}
		if(!$decision['complete']||$decision['stale']){$this->errors[]='Registry revocation state is incomplete, stale, or unavailable.';}
		if($fatalRevocation&&(!$decision['allowed']||$decision['revoked'])){$this->errors[]='Registry publisher or signing key is revoked or denied by revocation policy.';}
		return$decision;
	}

	/** @param array<string,mixed> $subject @return array<string,mixed> */
	private function publisherDecision(string $kind,array $subject,array $options,bool $fatal):array {
		$required=($options['require_publisher_trust']??false)===true;$hook=$options['publisher_trust_resolver']??null;
		if(!$required&&!is_callable($hook)){return['checked'=>false,'complete'=>false,'stale'=>false,'status'=>'unknown','eligible'=>true,'reason_codes'=>[]];}
		if(!is_callable($hook)){$this->errors[]='Registry policy requires a publisher trust resolver.';return['checked'=>false,'complete'=>false,'stale'=>true,'status'=>'unknown','eligible'=>false,'reason_codes'=>[]];}
		try{$raw=$hook($kind,$subject,['at'=>$options['now']??time()]);if($raw instanceof PanelPackagePublisherTrustProfile){$raw=$raw->jsonSerialize();}if(!is_array($raw)){throw new \UnexpectedValueException('Publisher trust resolver returned an unsupported profile.');}$status=(string)($raw['status']??'unknown');if(!in_array($status,['unknown','observed','restricted','blocked'],true)){throw new \UnexpectedValueException('Publisher trust resolver returned an unknown status.');}$complete=($raw['complete']??false)===true;$stale=($raw['stale']??true)===true;$eligible=$complete&&!$stale&&in_array($status,$this->allowedPublisherStatuses($options),true);$decision=['checked'=>true,'complete'=>$complete,'stale'=>$stale,'status'=>$status,'eligible'=>$eligible,'reason_codes'=>is_array($raw['reason_codes']??null)?array_values($raw['reason_codes']):[]];}
		catch(\Throwable){$decision=['checked'=>true,'complete'=>false,'stale'=>true,'status'=>'unknown','eligible'=>false,'reason_codes'=>[]];}
		if((!$decision['complete']||$decision['stale'])&&($required||$fatal)){$this->errors[]='Registry publisher evidence is incomplete, stale, or unavailable.';}
		if($fatal&&!$decision['eligible']){$this->errors[]='Registry publisher evidence does not satisfy marketplace trust policy.';}
		return$decision;
	}

	/** @return list<string> */
	private function allowedPublisherStatuses(array $options):array {
		$values=is_array($options['allowed_publisher_statuses']??null)?$options['allowed_publisher_statuses']:['observed'];$result=[];
		foreach($values as$value){$status=Resource::normalizeName((string)$value);if(!in_array($status,['unknown','observed','restricted','blocked'],true)){continue;}$result[$status]=true;}
		return$result!==[]?array_keys($result):['observed'];
	}

	private function verifyTransparency(string $kind, array $subject, array $proof, array $options): void {
		$required=(bool)($options['require_transparency'] ?? false);
		$hook=$options['transparency_verifier'] ?? null;
		if(!$required && !is_callable($hook)){return;}
		if($proof===[]){$this->errors[]='Required transparency receipt or proof is missing.';return;}
		if(!is_callable($hook)){$this->errors[]='Transparency policy requires a host verifier hook.';return;}
		try{$verified=$hook($kind, $subject, $proof)===true;}
		catch(\Throwable){$verified=false;}
		if(!$verified){$this->errors[]='Transparency receipt or proof verification failed.';}
	}

	private function parseTime(string $value): ?int {
		if(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $value)!==1){return null;}
		$canonical=str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
		$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical);
		$errors=\DateTimeImmutable::getLastErrors();
		if($parsed===false || (is_array($errors) && ($errors['warning_count']!==0 || $errors['error_count']!==0)) || $parsed->format('Y-m-d\TH:i:sP')!==$canonical){return null;}
		return $parsed->getTimestamp();
	}

	private function normalizeDigest(string $digest): string {
		$digest=strtolower(trim($digest));
		if(str_starts_with($digest, 'sha256:')){$digest=substr($digest, 7);}
		return preg_match('/^[a-f0-9]{64}$/', $digest)===1 ? $digest : '';
	}

	private function constraintValid(string $constraint): bool {
		$constraint=trim($constraint);
		if($constraint==='' || $constraint==='*'){return $constraint==='*';}
		foreach(preg_split('/\s*,\s*/', $constraint) ?: [] as $part){
			$part=trim($part);
			if($part==='*'){continue;}
			if(preg_match('/^(?:\^|>=|<=|>|<|==|=)?\s*(\S+)$/D', $part, $matches)!==1 || !PanelPackageManifest::validVersion($matches[1])){return false;}
		}
		return true;
	}

	/** @return array<string,mixed>|null */
	private function normalizeRequirements(mixed $requirements): ?array {
		if(!is_array($requirements) || ($requirements!==[] && array_is_list($requirements))){return null;}
		$unknown=array_diff(array_keys($requirements), ['php','panel','reactor','modules','themes']);
		if($unknown!==[]){return null;}
		$normalized=['php'=>null,'panel'=>null,'reactor'=>null,'modules'=>[],'themes'=>[]];
		foreach(['php','panel','reactor'] as $name){
			if(!array_key_exists($name, $requirements)){continue;}
			$value=$requirements[$name];
			if($value===null){$normalized[$name]=null;continue;}
			if(!is_string($value) || !$this->constraintValid($value)) { return null; }
			$normalized[$name]=trim($value);
		}
		if(array_key_exists('modules', $requirements)){
			$modules=$requirements['modules'];if(!is_array($modules) || ($modules!==[] && array_is_list($modules))){return null;}
			foreach($modules as $module=>$constraint){
				$id=is_string($module) ? Resource::normalizeName($module) : '';
				if($id==='' || $id!==$module || !is_string($constraint) || !$this->constraintValid($constraint)){return null;}
				$normalized['modules'][$id]=trim($constraint);
			}
			ksort($normalized['modules'], SORT_STRING);
		}
		if(array_key_exists('themes', $requirements)){
			$themes=$requirements['themes'];if(!is_array($themes) || !array_is_list($themes)){return null;}
			foreach($themes as $theme){$id=is_string($theme) ? Resource::normalizeName($theme) : '';if($id==='' || $id!==$theme || in_array($id, $normalized['themes'], true)){return null;}$normalized['themes'][]=$id;}
		}
		return $normalized;
	}

	/** @return array<string,mixed>|null */
	private function normalizeListing(mixed $listing): ?array {
		if($listing===null || $listing===[]){return [];}
		if(!is_array($listing) || array_is_list($listing) || array_diff(array_keys($listing), ['label','description','type','license','tags','categories','provides','links'])!==[]){return null;}
		$result=[];
		foreach(['label'=>180,'description'=>4000,'license'=>128] as $field=>$maximum){
			if(!array_key_exists($field, $listing)){continue;}
			if(!is_string($listing[$field])){return null;}
			$value=trim($listing[$field]);
			if($value==='' || strlen($value)>$maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)===1){return null;}
			$result[$field]=$value;
		}
		if(array_key_exists('type', $listing)){
			if(!is_string($listing['type'])){return null;}
			$type=Resource::normalizeName($listing['type']);
			if($type==='' || $type!==$listing['type']){return null;}
			$result['type']=$type;
		}
		foreach(['tags'=>64,'categories'=>32,'provides'=>256] as $field=>$limit){
			if(!array_key_exists($field, $listing)){continue;}
			$values=$listing[$field];
			if(!is_array($values) || !array_is_list($values) || count($values)>$limit){return null;}
			$normalized=[];
			foreach($values as $value){
				if(!is_string($value)){return null;}
				$name=Resource::normalizeName($value);
				if($name==='' || $name!==$value || isset($normalized[$name])){return null;}
				$normalized[$name]=true;
			}
			$names=array_keys($normalized);sort($names, SORT_STRING);$result[$field]=$names;
		}
		if(array_key_exists('links', $listing)){
			$links=$listing['links'];
			if(!is_array($links) || !array_is_list($links) || count($links)>32){return null;}
			$result['links']=[];
			foreach($links as $link){
				if(!is_array($link) || array_diff(array_keys($link), ['label','target'])!==[] || !is_string($link['label'] ?? null) || !is_string($link['target'] ?? null)){return null;}
				$label=trim($link['label']);$target=trim($link['target']);
				$parts=parse_url($target);
				if($label==='' || strlen($label)>180 || preg_match('/[\x00-\x1F\x7F]/', $label)===1
					|| $target==='' || strlen($target)>2048 || !is_array($parts)
					|| strtolower((string)($parts['scheme'] ?? ''))!=='https' || trim((string)($parts['host'] ?? ''))===''
					|| isset($parts['user']) || isset($parts['pass']) || str_contains($target, "\0")){return null;}
				$result['links'][]=['label'=>$label,'target'=>$target];
			}
		}
		return $result;
	}

	/** @return array<string,string> */
	private function signatureDescriptor(mixed $signature): array {
		if(!is_array($signature)){$this->errors[]='Registry signature descriptor is missing or malformed.';return [];}
		if(array_key_exists('public_key', $signature) || array_key_exists('private_key', $signature) || array_key_exists('secret_key', $signature)){$this->errors[]='Registry signature descriptor must not provide key material.';}
		$safe=[];
		foreach(['algorithm','key_id','publisher','digest','signature'] as $field){
			$value=$signature[$field] ?? null;
			if(!is_string($value) || trim($value)==='' || trim($value)!==$value){$this->errors[]='Registry signature descriptor is missing required canonical string fields.';continue;}
			$safe[$field]=trim($value);
		}
		if(isset($safe['algorithm']) && !in_array($safe['algorithm'], ['ed25519','rsa-sha256','ecdsa-sha256'], true)){$this->errors[]='Registry signature algorithm identifier is non-canonical.';}
		if(isset($safe['key_id']) && (strlen($safe['key_id'])>256 || preg_match('/^[A-Za-z0-9._:-]+$/D', $safe['key_id'])!==1)){$this->errors[]='Registry signature key id is non-canonical.';}
		if(isset($safe['publisher']) && Resource::normalizeName($safe['publisher'])!==$safe['publisher']){$this->errors[]='Registry signature publisher is non-canonical.';}
		if(isset($safe['digest']) && preg_match('/^[a-f0-9]{64}$/D', $safe['digest'])!==1){$this->errors[]='Registry signature digest is non-canonical.';}
		return $safe;
	}

	private static function canonicalJson(mixed $value): string {
		return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	private static function canonicalize(mixed $value): mixed {
		if(is_array($value)){
			if(!array_is_list($value)){ksort($value, SORT_STRING);}
			foreach($value as $key=>$item){$value[$key]=self::canonicalize($item);}
			return $value;
		}
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){return $value;}
		if(is_float($value) && is_finite($value)){return $value;}
		throw new \InvalidArgumentException('Unsupported registry value.');
	}

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && $this->sensitiveKey($key)){return '[REDACTED]';}
		if(!is_array($value)){return is_object($value) ? '[OBJECT]' : $value;}
		$safe=[];
		foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item, is_string($itemKey) ? $itemKey : '');}
		return $safe;
	}

	private function sensitiveKey(string $key): bool {
		$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
		return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i', $key)===1;
	}
}
