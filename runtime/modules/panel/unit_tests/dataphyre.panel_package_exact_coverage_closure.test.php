<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel\TestFixtures {
	final class PanelPackageExactCoverageProbe {
		/** @var array<string,\Closure> */
		private static array $hooks=[];

		public static function reset(): void {
			self::$hooks=[];
		}

		public static function hook(string $function, \Closure $hook): void {
			self::$hooks[$function]=$hook;
		}

		public static function call(string $function, array $arguments, \Closure $native): mixed {
			$key=str_contains($function,'\\') ? substr($function,(int)strrpos($function,'\\')+1) : $function;
			$hook=self::$hooks[$key] ?? null;
			return $hook instanceof \Closure ? $hook(...$arguments) : $native();
		}
	}
}

namespace Dataphyre\Panel {
	use Dataphyre\Panel\TestFixtures\PanelPackageExactCoverageProbe as Probe;

	function is_file(string $filename): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>\is_file($filename));
	}
	function is_dir(string $filename): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>\is_dir($filename));
	}
	function file_exists(string $filename): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>\file_exists($filename));
	}
	function is_link(string $filename): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>\is_link($filename));
	}
	function realpath(string $path): string|false {
		return Probe::call(__FUNCTION__,func_get_args(),static fn(): string|false=>\realpath($path));
	}
	function hash_file(string $algorithm,string $filename,bool $binary=false): string|false {
		return Probe::call(__FUNCTION__,func_get_args(),static fn(): string|false=>\hash_file($algorithm,$filename,$binary));
	}
	function copy(string $from,string $to,mixed $context=null): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>$context===null ? \copy($from,$to) : \copy($from,$to,$context));
	}
	function link(string $from,string $to): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>\link($from,$to));
	}
	function rename(string $from,string $to,mixed $context=null): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>$context===null ? \rename($from,$to) : \rename($from,$to,$context));
	}
	function unlink(string $filename,mixed $context=null): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>$context===null ? \unlink($filename) : \unlink($filename,$context));
	}
	function mkdir(string $directory,int $permissions=0777,bool $recursive=false,mixed $context=null): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>$context===null ? \mkdir($directory,$permissions,$recursive) : \mkdir($directory,$permissions,$recursive,$context));
	}
	function fopen(string $filename,string $mode,bool $useIncludePath=false,mixed $context=null): mixed {
		return Probe::call(__FUNCTION__,func_get_args(),static fn(): mixed=>$context===null ? \fopen($filename,$mode,$useIncludePath) : \fopen($filename,$mode,$useIncludePath,$context));
	}
	function flock(mixed $stream,int $operation,?int &$wouldBlock=null): bool {
		return (bool)Probe::call(__FUNCTION__,[$stream,$operation],static fn(): bool=>\flock($stream,$operation,$wouldBlock));
	}
	function random_bytes(int $length): string {
		return (string)Probe::call(__FUNCTION__,func_get_args(),static fn(): string=>\random_bytes($length));
	}
	function file_put_contents(string $filename,mixed $data,int $flags=0,mixed $context=null): int|false {
		return Probe::call(__FUNCTION__,func_get_args(),static fn(): int|false=>$context===null ? \file_put_contents($filename,$data,$flags) : \file_put_contents($filename,$data,$flags,$context));
	}
	function chmod(string $filename,int $permissions): bool {
		return (bool)Probe::call(__FUNCTION__,func_get_args(),static fn(): bool=>\chmod($filename,$permissions));
	}
	function hash(string $algorithm,string $data,bool $binary=false): string {
		return (string)Probe::call(__FUNCTION__,func_get_args(),static fn(): string=>\hash($algorithm,$data,$binary));
	}
	function openssl_pkey_get_public(mixed $publicKey): mixed {
		return Probe::call(__FUNCTION__,func_get_args(),static fn(): mixed=>\openssl_pkey_get_public($publicKey));
	}
	function is_callable(mixed $value,bool $syntaxOnly=false,mixed &$callableName=null): bool {
		return (bool)Probe::call(__FUNCTION__,[$value,$syntaxOnly],static function()use($value,$syntaxOnly,&$callableName): bool {
			return \is_callable($value,$syntaxOnly,$callableName);
		});
	}
}

namespace {
	use Dataphyre\Panel\PanelCompatibilityMatrix;
	use Dataphyre\Panel\PanelPackageApplyResult;
	use Dataphyre\Panel\PanelPackageInstallPlan;
	use Dataphyre\Panel\PanelPackageManifest;
	use Dataphyre\Panel\PanelPackageRollbackPlan;
	use Dataphyre\Panel\PanelPackageRollbackResult;
	use Dataphyre\Panel\PanelPackageSignatureVerifier;
	use Dataphyre\Panel\PanelPackageTemplate;
	use Dataphyre\Panel\TestFixtures\PanelPackageExactCoverageProbe as Probe;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	require_once __DIR__.'/panel_test_harness_helpers.php';
	dp_panel_unit_test_bootstrap();
	if(!class_exists(\dataphyre\core::class,false)){
		require_once dirname(__DIR__,2).'/core/kernel/core_functions.php';
	}
	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}

	/** @param array<string,string> $files */
	function dp_panel_package_exact_template(string $id,array $files=[]): PanelPackageTemplate {
		$template=PanelPackageTemplate::make($id)
			->plugin(false)->provider(false)->theme(false)->docs(false)->tests(false)
			->with('marketplace',false);
		foreach($files as $path=>$contents){$template->file($path,$contents);}
		return $template;
	}

	function dp_panel_package_exact_apply_payload(string $root,array $written=[],array $backups=[],array $extra=[]): array {
		return array_replace([
			'type'=>'panel_package_apply_result','ok'=>true,'package'=>['id'=>'exact-package'],
			'target_root'=>$root,'written'=>$written,'backups'=>$backups,'skipped'=>[],
			'blocked'=>[],'attempted'=>[],'reverted'=>[],'meta'=>['dry_run'=>false],
		],$extra);
	}

	function dp_panel_package_exact_reset(Context $t): void {
		Probe::reset();
		$t->cleanup(static fn()=>Probe::reset());
	}

	test('package value objects close strict semantic version and rollback envelope edges',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$t->isFalse(PanelPackageManifest::matchesConstraint('not-semver','*'));
		$t->same(0,PanelPackageManifest::compareVersions('1.2.3-alpha.1','1.2.3-alpha.1'));

		$result=PanelPackageRollbackResult::make([
			'ok'=>true,'package'=>['id'=>'value-package','api_token'=>'secret'],
			'target_root'=>'/target','restored'=>[['target'=>'a']],
			'deleted'=>[['target'=>'b']],'skipped'=>[['target'=>'c']],
		]);
		$t->same('[REDACTED]',$result->package()['api_token']);
		$t->same('/target',$result->targetRoot());
		$t->same('c',$result->skipped()[0]['target']);
	});

	test('package signature verifier covers constructors canonical failures aliases and crypto fallbacks',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$keypair=sodium_crypto_sign_keypair();
		$public=sodium_crypto_sign_publickey($keypair);
		$verifier=new PanelPackageSignatureVerifier([
			['id'=>'numeric','algorithm'=>'eddsa','public_key'=>base64_encode($public)],
			'string-key'=>base64_encode($public),
		]);
		$t->same($verifier,$verifier->meta(' request_id ','coverage'));
		$t->same($verifier,$verifier->meta(' ',123));
		$t->same($verifier,$verifier->forgetKey('string-key'));
		$t->same($verifier->toArray(),$verifier->jsonSerialize());

		$bundle=['id'=>'top-level','artifacts'=>[]];
		$t->same(64,strlen($verifier->digest($bundle)));
		$t->throws(static fn()=>$verifier->payload([
			'package'=>['id'=>'unsafe'],'artifacts'=>[['path'=>'../escape','contents'=>'x']],
		]),InvalidArgumentException::class);

		$invalid=$verifier->verify([
			'package'=>['signature'=>['algorithm'=>'rs256','token'=>'forbidden']],
			'artifacts'=>['invalid',['path'=>'dataphyre-panel-package.json','contents'=>'1']],
		]);
		$t->isFalse($invalid->ok());
		$t->contains('Package contains an invalid artifact descriptor.',$invalid->errors());
		$t->contains('Package manifest artifact is not valid JSON.',$invalid->errors());
		$t->contains('Package manifest is missing an identifier.',$invalid->errors());

		$reflection=$t->nonPublic($verifier);
		$t->same('',$reflection->invoke('normalizeArtifactPath','C:\\escape.php'));
		$t->same('ed25519',$reflection->invoke('normalizeAlgorithm','ed25519-sha512'));
		$t->same('rsa-sha256',$reflection->invoke('normalizeAlgorithm','rsa_sha256'));
		$t->same('ecdsa-sha256',$reflection->invoke('normalizeAlgorithm','es256'));
		$t->same("A",$reflection->invoke('decodeBinary','41'));
		$t->same(null,$reflection->invoke('decodePublicKey',''));
		$t->same("-----BEGIN PUBLIC KEY-----\ninvalid\n-----END PUBLIC KEY-----",$reflection->invoke('decodePublicKey',"-----BEGIN PUBLIC KEY-----\ninvalid\n-----END PUBLIC KEY-----"));
		$t->isFalse($reflection->invoke('verifyBytes','ed25519','payload',str_repeat('x',64),str_repeat('k',65537)));
		$t->isFalse($reflection->invoke('verifyBytes','unsupported','payload','signature','key'));
		$rsa=openssl_pkey_new(['private_key_bits'=>1024,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
		$t->isTrue($rsa!==false);
		$rsaDetails=openssl_pkey_get_details($rsa);
		$t->isTrue(is_array($rsaDetails));
		$rsaSignature='';
		$t->isTrue(openssl_sign('signed payload',$rsaSignature,$rsa,OPENSSL_ALGO_SHA256));
		$t->isTrue($reflection->invoke('verifyBytes','rsa-sha256','signed payload',$rsaSignature,(string)$rsaDetails['key']));

		Probe::hook('openssl_pkey_get_public',static fn(mixed $key): mixed=>throw new RuntimeException('crypto probe'));
		$t->isFalse($reflection->invoke('verifyBytes','rsa-sha256','payload','signature','key'));

		Probe::hook('hash',static function(string $algorithm,string $data,bool $binary=false): string {
			throw new RuntimeException('digest probe');
		});
		$failed=$verifier->verify(['package'=>['id'=>'hash-failure','signature'=>[]],'artifacts'=>[]]);
		$t->isFalse($failed->ok());
		$t->contains('Package payload is not canonically serializable.',$failed->errors());
	});

	test('package installer treats unavailable host dialbacks as optional boundaries',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		Probe::hook('is_callable',static function(mixed $value,bool $syntaxOnly=false): bool {
			if(is_array($value) && ($value[0] ?? null)==='\\dataphyre\\core' && ($value[1] ?? null)==='dialback'){
				return false;
			}
			return \is_callable($value,$syntaxOnly);
		});
		$root=$t->workspace('dp-panel-package-exact-no-dialback')->root();
		$result=PanelPackageInstallPlan::make(dp_panel_package_exact_template('no-dialback'))->apply($root,['dry_run'=>true]);
		$t->isTrue($result->ok());
		$t->isTrue($result->toArray()['meta']['dry_run']);
	});

	test('package installer private boundaries fail closed for backup publication lock and recovery faults',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$base=$t->workspace('dp-panel-package-exact-install-helpers')->root();
		$root=$base.DIRECTORY_SEPARATOR.'root';
		$backups=$base.DIRECTORY_SEPARATOR.'backups';
		mkdir($root,0775,true);mkdir($backups,0775,true);
		$target=$root.DIRECTORY_SEPARATOR.'target.txt';
		file_put_contents($target,'old');
		$plan=PanelPackageInstallPlan::make(dp_panel_package_exact_template('helper-package'));
		$private=$t->nonPublic($plan);

		$t->same(null,$private->invoke('backupTarget',$target,'target.txt','helper-package',$backups,false,'fixed',str_repeat('0',64)));
		Probe::hook('copy',static fn(string $from,string $to,mixed $context=null): bool=>false);
		$t->same(null,$private->invoke('backupTarget',$target,'target.txt','helper-package',$backups,false,'copy-fail',hash_file('sha256',$target)));
		Probe::reset();

		$t->isTrue($private->invoke('pathContainsSymlink','',$root),'empty path is unsafe');
		$normalizedRoot=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$root);
		Probe::hook('is_link',static fn(string $path): bool=>$path===$normalizedRoot || \is_link($path));
		$t->isTrue($private->invoke('pathContainsSymlink',$target,$root),'root symlink is unsafe');
		Probe::hook('is_link',static fn(string $path): bool=>str_ends_with($path,'nested') || \is_link($path));
		$t->isTrue($private->invoke('pathContainsSymlink',$root.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'file',$root),'ancestor symlink is unsafe');
		Probe::reset();

		$unsafe=$base.DIRECTORY_SEPARATOR.'missing'.DIRECTORY_SEPARATOR.'file.txt';
		$t->isFalse($private->invoke('publishArtifact',$unsafe,'value','create','',$root,null)['ok']);
		$t->isFalse($private->invoke('publishArtifact',$target,'value','replace',str_repeat('0',64),$root,null)['ok']);
		$t->isFalse($private->invoke('publishArtifact',$target,'value','unknown','',$root,null)['ok']);

		$create=$root.DIRECTORY_SEPARATOR.'create.txt';
		Probe::hook('link',static fn(string $from,string $to): bool=>false);
		$t->isFalse($private->invoke('publishArtifact',$create,'value','create','',$root,null)['ok']);
		Probe::reset();

		$replace=$root.DIRECTORY_SEPARATOR.'replace.txt';file_put_contents($replace,'old');
		Probe::hook('rename',static fn(string $from,string $to,mixed $context=null): bool=>false);
		$t->isFalse($private->invoke('publishArtifact',$replace,'value','replace',hash_file('sha256',$replace),$root,null)['ok']);
		Probe::reset();

		$missingSnapshot=$base.DIRECTORY_SEPARATOR.'missing.snapshot';
		$t->isFalse($private->invoke('replaceFromSnapshot',$missingSnapshot,$target));
		$snapshot=$base.DIRECTORY_SEPARATOR.'snapshot';file_put_contents($snapshot,'snapshot');
		$blockedDirectory=$base.DIRECTORY_SEPARATOR.'blocked-parent';file_put_contents($blockedDirectory,'file');
		$t->isFalse($private->invoke('replaceFromSnapshot',$snapshot,$blockedDirectory.DIRECTORY_SEPARATOR.'target'));
		Probe::hook('copy',static fn(string $from,string $to,mixed $context=null): bool=>false);
		$t->isFalse($private->invoke('replaceFromSnapshot',$snapshot,$target));
		Probe::reset();

		Probe::hook('fopen',static fn(string $path,string $mode,bool $include=false,mixed $context=null): mixed=>false);
		$t->same(null,$private->invoke('acquirePackageLock',$root,0));
		Probe::reset();
		$lock=$private->invoke('acquirePackageLock',$root,0);
		$t->isTrue(is_resource($lock));
		if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}

		$t->same(null,$private->invoke('removeTransactionTree',$base));
	});

	test('package installer stages and backups reject every post-check race and digest fault',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$base=$t->workspace('dp-panel-package-exact-install-publication')->root();
		$root=$base.DIRECTORY_SEPARATOR.'root';$backups=$base.DIRECTORY_SEPARATOR.'backups';
		mkdir($root,0775,true);mkdir($backups,0775,true);
		$source=$root.DIRECTORY_SEPARATOR.'source.txt';file_put_contents($source,'source');
		$plan=PanelPackageInstallPlan::make(dp_panel_package_exact_template('publication-package'));
		$private=$t->nonPublic($plan);

		$normalizedBackups=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$backups);
		Probe::hook('is_link',static function(string $path)use($normalizedBackups): bool {
			if(str_starts_with($path,$normalizedBackups) && str_ends_with($path,'source.txt')){return \is_dir(dirname($path));}
			return \is_link($path);
		});
		$t->same(null,$private->invoke('backupTarget',$source,'source.txt','publication-package',$backups,false,'race',hash_file('sha256',$source)));
		Probe::reset();

		Probe::hook('hash_file',static function(string $algorithm,string $path,bool $binary=false)use($normalizedBackups): string|false {
			if(str_starts_with($path,$normalizedBackups) && str_ends_with($path,'source.txt')){return str_repeat('0',64);}
			return \hash_file($algorithm,$path,$binary);
		});
		$t->same(null,$private->invoke('backupTarget',$source,'source.txt','publication-package',$backups,false,'digest-race',hash_file('sha256',$source)));
		Probe::reset();

		$stagedDigest=$root.DIRECTORY_SEPARATOR.'staged-digest.txt';
		Probe::hook('hash_file',static function(string $algorithm,string $path,bool $binary=false): string|false {
			return str_contains($path,'.dp-install-') && str_ends_with($path,'.tmp') ? str_repeat('0',64) : \hash_file($algorithm,$path,$binary);
		});
		$t->isFalse($private->invoke('publishArtifact',$stagedDigest,'value','create','',$root,null)['ok'],'staged digest mismatch');
		Probe::reset();

		$symlinkRace=$root.DIRECTORY_SEPARATOR.'symlink-race.txt';$symlinkChecks=0;
		$normalizedSymlinkRace=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$symlinkRace);
		Probe::hook('is_link',static function(string $path)use($normalizedSymlinkRace,&$symlinkChecks): bool {
			if($path===$normalizedSymlinkRace){return ++$symlinkChecks>=2;}
			return \is_link($path);
		});
		$t->isFalse($private->invoke('publishArtifact',$symlinkRace,'value','create','',$root,null)['ok'],'publication symlink race');
		Probe::reset();

		$appeared=$root.DIRECTORY_SEPARATOR.'appeared.txt';
		Probe::hook('file_exists',static fn(string $path): bool=>$path===$appeared || \file_exists($path));
		$t->isFalse($private->invoke('publishArtifact',$appeared,'value','create','',$root,null)['ok'],'publication create race');
		Probe::reset();

		$linkFailure=$root.DIRECTORY_SEPARATOR.'replace-link.txt';file_put_contents($linkFailure,'old');
		Probe::hook('link',static fn(string $from,string $to): bool=>false);
		$t->isFalse($private->invoke('publishArtifact',$linkFailure,'new','replace',hash_file('sha256',$linkFailure),$root,null)['ok'],'replacement publish link failure');
		Probe::reset();

		$replaceDigest=$root.DIRECTORY_SEPARATOR.'replace-digest.txt';file_put_contents($replaceDigest,'old');$replaceHashCalls=0;
		Probe::hook('hash_file',static function(string $algorithm,string $path,bool $binary=false)use($replaceDigest,&$replaceHashCalls): string|false {
			if($path===$replaceDigest && ++$replaceHashCalls>=2){return str_repeat('0',64);}
			return \hash_file($algorithm,$path,$binary);
		});
		$t->isFalse($private->invoke('publishArtifact',$replaceDigest,'new','replace',hash('sha256','old'),$root,null)['ok'],'replacement final digest mismatch');
		Probe::reset();

		$createDigest=$root.DIRECTORY_SEPARATOR.'create-digest.txt';
		Probe::hook('hash_file',static fn(string $algorithm,string $path,bool $binary=false): string|false=>$path===$createDigest ? str_repeat('0',64) : \hash_file($algorithm,$path,$binary));
		$t->isFalse($private->invoke('publishArtifact',$createDigest,'new','create','',$root,null)['ok'],'create final digest mismatch');
		Probe::reset();

		$exception=$root.DIRECTORY_SEPARATOR.'exception.txt';
		Probe::hook('hash_file',static fn(string $algorithm,string $path,bool $binary=false): string|false=>throw new RuntimeException('publication hash failure'));
		$t->throws(static fn()=>$private->invoke('publishArtifact',$exception,'new','create','',$root,null),RuntimeException::class);
		Probe::reset();
		$finally=$root.DIRECTORY_SEPARATOR.'finally.txt';
		Probe::hook('is_file',static function(string $path): bool {
			if(str_contains($path,'.dp-install-') && str_ends_with($path,'.tmp')){throw new RuntimeException('publication finally probe');}
			return \is_file($path);
		});
		$t->throws(static fn()=>$private->invoke('publishArtifact',$finally,'new','create','',$root,null),RuntimeException::class);
		Probe::reset();

		Probe::hook('is_link',static fn(string $path): bool=>str_ends_with($path,'dataphyre-panel-package-locks') || \is_link($path));
		$t->same(null,$private->invoke('acquirePackageLock',$root,0));
		Probe::reset();
		Probe::hook('flock',static fn(mixed $handle,int $operation): bool=>false);
		$t->same(null,$private->invoke('acquirePackageLock',$root,15));
		Probe::reset();

		Probe::hook('is_link',static fn(string $path): bool=>str_ends_with($path,'dataphyre-panel-package-transactions') || \is_link($path));
		$t->same('',$private->invoke('transactionDirectory'));
		Probe::reset();

		$snapshot=$base.DIRECTORY_SEPARATOR.'snapshot';file_put_contents($snapshot,'snapshot');
		$restoreTarget=$root.DIRECTORY_SEPARATOR.'restore-target.txt';
		Probe::hook('hash_file',static function(string $algorithm,string $path,bool $binary=false): string|false {
			return str_contains($path,'.dp-install-restore-') ? str_repeat('0',64) : \hash_file($algorithm,$path,$binary);
		});
		$t->isFalse($private->invoke('replaceFromSnapshot',$snapshot,$restoreTarget),'snapshot staged digest mismatch');
		Probe::reset();
		Probe::hook('rename',static fn(string $from,string $to,mixed $context=null): bool=>str_contains($from,'.dp-install-restore-') ? false : \rename($from,$to));
		$t->isFalse($private->invoke('replaceFromSnapshot',$snapshot,$restoreTarget),'snapshot final rename failure');
	});

	test('package installer atomic apply refuses symlink appearance target races and snapshot failures',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$base=$t->workspace('dp-panel-package-exact-install-atomic-races')->root();
		$scenario=static function(string $name,?string $existing,\Closure $configure)use($base): PanelPackageApplyResult {
			Probe::reset();
			$root=$base.DIRECTORY_SEPARATOR.$name;mkdir($root,0775,true);
			$target=$root.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json';
			if($existing!==null){file_put_contents($target,$existing);}
			$configure($target,$root);
			$plan=PanelPackageInstallPlan::make(dp_panel_package_exact_template('atomic-'.$name));
			if($existing!==null){$plan->overwritePolicy('replace');}
			return $plan->apply($root);
		};

		$symlink=$scenario('symlink',null,static function(string $target): void {
			$target=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target);$calls=0;
			Probe::hook('is_link',static function(string $path)use($target,&$calls): bool {
				if($path===$target){return ++$calls>=2;}
				return \is_link($path);
			});
		});
		$t->isFalse($symlink->ok());
		$t->contains('Artifact target or one of its ancestors is a symbolic link.',array_column($symlink->blocked(),'reason'));

		$appeared=$scenario('appeared',null,static function(string $target): void {
			$target=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target);$calls=0;
			Probe::hook('is_file',static function(string $path)use($target,&$calls): bool {
				if($path===$target){return ++$calls>=2;}
				return \is_file($path);
			});
		});
		$t->isFalse($appeared->ok());
		$t->contains('Artifact target appeared after package planning; atomic install refused the race.',array_column($appeared->blocked(),'reason'));

		$disappeared=$scenario('disappeared','old',static function(string $target): void {
			$target=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target);$calls=0;
			Probe::hook('is_file',static function(string $path)use($target,&$calls): bool {
				if($path===$target){return ++$calls<4;}
				return \is_file($path);
			});
		});
		$t->isFalse($disappeared->ok());
		$t->contains('Replacement target disappeared after package planning; atomic install refused the race.',array_column($disappeared->blocked(),'reason'));

		$transaction=$scenario('transaction-directory',null,static function(): void {
			Probe::hook('is_link',static fn(string $path): bool=>str_ends_with($path,'dataphyre-panel-package-transactions') || \is_link($path));
		});
		$t->isFalse($transaction->ok());
		$t->contains('Atomic package transaction snapshot could not be created.',array_column($transaction->blocked(),'reason'));

		$snapshotCopy=$scenario('snapshot-copy','old',static function(): void {
			Probe::hook('copy',static fn(string $from,string $to,mixed $context=null): bool=>str_ends_with($to,'.snapshot') ? false : \copy($from,$to));
		});
		$t->isFalse($snapshotCopy->ok());
		$t->contains('Existing target could not be snapshotted for atomic install.',array_column($snapshotCopy->blocked(),'reason'));

		$snapshotDigest=$scenario('snapshot-digest','old',static function(): void {
			Probe::hook('hash_file',static fn(string $algorithm,string $path,bool $binary=false): string|false=>str_ends_with($path,'.snapshot') ? str_repeat('0',64) : \hash_file($algorithm,$path,$binary));
		});
		$t->isFalse($snapshotDigest->ok());
		$t->contains('Atomic install snapshot did not match the planned replacement digest.',array_column($snapshotDigest->blocked(),'reason'));
		Probe::reset();
	});

	test('package installer preserves transaction evidence when recovery itself fails',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$root=$t->workspace('dp-panel-package-exact-install-recovery')->root();
		$template=dp_panel_package_exact_template('atomic-recovery',['z-last.txt'=>'last']);
		$manifestTarget=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json');
		Probe::hook('link',static fn(string $from,string $to): bool=>str_ends_with($to,'z-last.txt') ? false : \link($from,$to));
		Probe::hook('unlink',static fn(string $path,mixed $context=null): bool=>$path===$manifestTarget ? false : ($context===null ? \unlink($path) : \unlink($path,$context)));
		$result=PanelPackageInstallPlan::make($template)->apply($root);
		$t->isFalse($result->ok());
		$t->contains('Atomic package transaction recovery failed.',array_column($result->blocked(),'reason'));
		$t->notEmpty($result->toArray()['meta']['transaction_snapshot']);
	});

	test('package rollback validates every malformed audit section and containment helper',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$base=$t->workspace('dp-panel-package-exact-rollback-structure')->root();
		$outside=$t->workspace('dp-panel-package-exact-rollback-outside')->root();
		$plan=PanelPackageRollbackPlan::make(dp_panel_package_exact_apply_payload($base));
		$private=$t->nonPublic($plan);

		$malformed=dp_panel_package_exact_apply_payload($base,[],[],[
			'ok'=>false,'package'=>null,'meta'=>'bad','skipped'=>'bad','blocked'=>'bad',
			'attempted'=>[['target'=>'x']],'reverted'=>[['target'=>'y']],'written'=>'bad','backups'=>'bad',
		]);
		$private->writeProperty('installPlan',$malformed);
		$errors=$private->invoke('sourceValidationErrors',false);
		$t->contains('Rollback apply result does not identify a package.',$errors);
		$t->contains('Rollback apply result has a malformed written section.',$errors);
		$private->writeProperty('installPlan',array_replace($malformed,['written'=>[],'backups'=>'bad']));
		$t->contains('Rollback apply result has a malformed backups section.',$private->invoke('sourceValidationErrors',false));

		$rows=dp_panel_package_exact_apply_payload($base,[
			'invalid',['target'=>'','action'=>'create'],['target'=>'A','action'=>'custom','sha256'=>'bad'],
			['target'=>'A','action'=>'create','sha256'=>str_repeat('a',64)],
			['target'=>'replace','action'=>'replace','sha256'=>str_repeat('b',64)],
		],[
			'invalid',['target'=>''],['target'=>'A','backup'=>'','sha256'=>'bad'],
			['target'=>'A','backup'=>'one','sha256'=>str_repeat('c',64)],
			['target'=>'A','backup'=>'two','sha256'=>str_repeat('d',64)],
		]);
		$private->writeProperty('installPlan',$rows);
		$errors=$private->invoke('sourceValidationErrors',false);
		$t->contains('Rollback apply result contains a malformed written row.',$errors);
		$t->contains('Rollback apply result contains duplicate written targets.',$errors);
		$t->contains('Rollback apply result contains a malformed backup row.',$errors);
		$t->contains('Rollback cannot restore a replacement write because its verified backup is missing.',$errors);

		$t->isFalse($private->invoke('pathWithinAnyDirectoryRoot',$outside,[$base]));
		$t->isFalse($private->invoke('pathWithinRoot',$outside.DIRECTORY_SEPARATOR.'x',$base));
		$t->isFalse($private->invoke('pathWithinAnyRoot',$base.DIRECTORY_SEPARATOR.'missing',[$base]));
		$t->same(
			str_replace(['/','\\'],DIRECTORY_SEPARATOR,$base.DIRECTORY_SEPARATOR.'child'),
			$private->invoke('normalizeFilesystemPath',$base.DIRECTORY_SEPARATOR.'x'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'child')
		);
	});

	test('package rollback private filesystem helpers reject lock transaction and publication failures',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$base=$t->workspace('dp-panel-package-exact-rollback-helpers')->root();
		$root=$base.DIRECTORY_SEPARATOR.'root';$backupRoot=$base.DIRECTORY_SEPARATOR.'backups';
		mkdir($root,0775,true);mkdir($backupRoot,0775,true);
		$payload=dp_panel_package_exact_apply_payload($root,[],[],['meta'=>['backup_root'=>$backupRoot]]);
		$object=PanelPackageApplyResult::make($payload);
		$plan=PanelPackageRollbackPlan::fromApplyResult($object);
		$private=$t->nonPublic($plan);
		$failure=static function(callable $callback): string {
			try{$callback();}catch(RuntimeException $exception){return $exception->getMessage();}
			return '';
		};
		$t->same([realpath($backupRoot)],$private->invoke('allowedBackupRoots',[]));

		$normalizedRoot=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$root);
		Probe::hook('realpath',static fn(string $path): string|false=>$path===$normalizedRoot ? false : \realpath($path));
		$t->isFalse($private->invoke('pathWithinRoot',$root.DIRECTORY_SEPARATOR.'file',$root),'rollback root realpath failure');
		Probe::reset();
		$t->same('relative'.DIRECTORY_SEPARATOR.'child',$private->invoke('normalizeFilesystemPath','relative/child'));

		Probe::hook('is_link',static fn(string $path): bool=>str_ends_with($path,'dataphyre-panel-package-locks') || \is_link($path));
		$t->same(null,$private->invoke('acquireLock',$root,0));
		Probe::reset();
		Probe::hook('fopen',static fn(string $path,string $mode,bool $include=false,mixed $context=null): mixed=>false);
		$t->same(null,$private->invoke('acquireLock',$root,0));
		Probe::reset();
		Probe::hook('flock',static fn(mixed $stream,int $operation): bool=>false);
		$t->same(null,$private->invoke('acquireLock',$root,15));
		Probe::reset();

		Probe::hook('is_link',static fn(string $path): bool=>str_ends_with($path,'dataphyre-panel-package-transactions') || \is_link($path));
		$t->contains('transaction directory could not be created',$failure(static fn()=>$private->invoke('transactionDirectory')));
		Probe::reset();
		Probe::hook('mkdir',static function(string $path,int $permissions=0777,bool $recursive=false,mixed $context=null): bool {
			if(str_contains($path,'dataphyre-panel-package-transactions') && !str_ends_with($path,'dataphyre-panel-package-transactions')){return false;}
			return $context===null ? \mkdir($path,$permissions,$recursive) : \mkdir($path,$permissions,$recursive,$context);
		});
		$t->contains('transaction snapshot could not be created',$failure(static fn()=>$private->invoke('transactionDirectory')));
		Probe::reset();

		$source=$backupRoot.DIRECTORY_SEPARATOR.'source.txt';file_put_contents($source,'backup');
		$target=$root.DIRECTORY_SEPARATOR.'target.txt';file_put_contents($target,'installed');
		$digest=hash_file('sha256',$source);
		$t->contains('source file no longer exists',$failure(static fn()=>$private->invoke('replaceFromFile',$backupRoot.DIRECTORY_SEPARATOR.'missing',$target,$digest)));
		$blocker=$root.DIRECTORY_SEPARATOR.'blocker';file_put_contents($blocker,'file');
		$t->contains('target directory could not be created',$failure(static fn()=>$private->invoke('replaceFromFile',$source,$blocker.DIRECTORY_SEPARATOR.'target',$digest)));

		Probe::hook('copy',static fn(string $from,string $to,mixed $context=null): bool=>false);
		$t->contains('source could not be staged',$failure(static fn()=>$private->invoke('replaceFromFile',$source,$target,$digest)));
		Probe::reset();
		Probe::hook('hash_file',static fn(string $algorithm,string $path,bool $binary=false): string|false=>str_contains($path,'.dp-rollback-') ? str_repeat('0',64) : \hash_file($algorithm,$path,$binary));
		$t->contains('failed digest verification',$failure(static fn()=>$private->invoke('replaceFromFile',$source,$target,$digest)));
		Probe::reset();

		file_put_contents($target,'installed');
		Probe::hook('link',static fn(string $from,string $to): bool=>false);
		$t->contains('could not be published',$failure(static fn()=>$private->invoke('replaceFromFile',$source,$target,$digest)));
		Probe::reset();
		file_put_contents($target,'installed');
		Probe::hook('link',static fn(string $from,string $to): bool=>false);
		Probe::hook('rename',static function(string $from,string $to,mixed $context=null): bool {
			if(str_contains($from,'.dp-rollback-displaced-')){return false;}
			return $context===null ? \rename($from,$to) : \rename($from,$to,$context);
		});
		$t->contains('original target could not be restored',$failure(static fn()=>$private->invoke('replaceFromFile',$source,$target,$digest)));
		Probe::reset();

		file_put_contents($target,'installed');
		$normalizedTarget=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target);
		Probe::hook('hash_file',static fn(string $algorithm,string $path,bool $binary=false): string|false=>str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path)===$normalizedTarget ? str_repeat('0',64) : \hash_file($algorithm,$path,$binary));
		$t->contains('Published rollback bytes failed digest verification',$failure(static fn()=>$private->invoke('replaceFromFile',$source,$target,$digest)));
		Probe::reset();
		$t->same(null,$private->invoke('removeTree',$base));
	});

	test('package rollback apply rejects explicit root escapes and reports leave actions',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$root=$t->workspace('dp-panel-package-exact-rollback-root')->root();
		$outside=$t->workspace('dp-panel-package-exact-rollback-trusted-outside')->root();
		$payload=dp_panel_package_exact_apply_payload($root,[],[],[
			'skipped'=>[['target'=>$root.DIRECTORY_SEPARATOR.'kept']],
			'blocked'=>[['target'=>$root.DIRECTORY_SEPARATOR.'blocked','reason'=>'blocked']],
		]);
		$result=PanelPackageRollbackPlan::make($payload)->apply(['target_root'=>$outside]);
		$t->isFalse($result->ok());
		$t->contains('Package target root is outside the explicitly trusted rollback target roots.',array_column($result->blocked(),'reason'));
	});

	test('package rollback revalidates targets backups and snapshots under its package lock',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$base=$t->workspace('dp-panel-package-exact-rollback-lock-races')->root();
		$deleteScenario=static function(string $name,\Closure $configure)use($base): PanelPackageRollbackResult {
			Probe::reset();
			$root=$base.DIRECTORY_SEPARATOR.$name;mkdir($root,0775,true);
			$target=$root.DIRECTORY_SEPARATOR.'installed.txt';file_put_contents($target,'installed');
			$digest=hash_file('sha256',$target);
			$configure($target,$root);
			$payload=dp_panel_package_exact_apply_payload($root,[['action'=>'create','target'=>$target,'sha256'=>$digest]]);
			return PanelPackageRollbackPlan::fromApplyResult(PanelPackageApplyResult::make($payload))->apply();
		};

		$unsafeUnderLock=$deleteScenario('unsafe-under-lock',static function(string $target): void {
			$target=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target);$calls=0;
			Probe::hook('is_link',static function(string $path)use($target,&$calls): bool {
				if($path===$target){return ++$calls>=2;}
				return \is_link($path);
			});
		});
		$t->contains('Rollback target became unsafe while the package lock was being acquired',array_column($unsafeUnderLock->blocked(),'reason')[0]);

		$changedUnderLock=$deleteScenario('changed-under-lock',static function(string $target): void {
			$target=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target);$calls=0;
			Probe::hook('hash_file',static function(string $algorithm,string $path,bool $binary=false)use($target,&$calls): string|false {
				if(str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path)===$target && ++$calls>=2){return str_repeat('0',64);}
				return \hash_file($algorithm,$path,$binary);
			});
		});
		$t->contains('Target changed while the rollback lock was being acquired',array_column($changedUnderLock->blocked(),'reason')[0]);

		$snapshotCopy=$deleteScenario('snapshot-copy',static function(): void {
			Probe::hook('copy',static fn(string $from,string $to,mixed $context=null): bool=>str_ends_with($to,'.snapshot') ? false : \copy($from,$to));
		});
		$t->contains('Current target could not be snapshotted',array_column($snapshotCopy->blocked(),'reason')[0]);

		$snapshotDigest=$deleteScenario('snapshot-digest',static function(): void {
			Probe::hook('hash_file',static fn(string $algorithm,string $path,bool $binary=false): string|false=>str_ends_with($path,'.snapshot') ? str_repeat('0',64) : \hash_file($algorithm,$path,$binary));
		});
		$t->contains('Current target snapshot failed digest verification',array_column($snapshotDigest->blocked(),'reason')[0]);

		$unsafeBeforeMutation=$deleteScenario('unsafe-before-mutation',static function(string $target): void {
			$target=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target);$calls=0;
			Probe::hook('is_link',static function(string $path)use($target,&$calls): bool {
				if($path===$target){return ++$calls>=3;}
				return \is_link($path);
			});
		});
		$t->contains('Rollback target became unsafe immediately before mutation',array_column($unsafeBeforeMutation->blocked(),'reason')[0]);

		$changedBeforeMutation=$deleteScenario('changed-before-mutation',static function(string $target): void {
			$target=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target);$calls=0;
			Probe::hook('hash_file',static function(string $algorithm,string $path,bool $binary=false)use($target,&$calls): string|false {
				if(str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path)===$target && ++$calls>=4){return str_repeat('0',64);}
				return \hash_file($algorithm,$path,$binary);
			});
		});
		$t->contains('Rollback target changed immediately before mutation',array_column($changedBeforeMutation->blocked(),'reason')[0]);

		$deleteFailure=$deleteScenario('delete-failure',static function(string $target): void {
			$normalized=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target);
			Probe::hook('unlink',static fn(string $path,mixed $context=null): bool=>str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path)===$normalized ? false : ($context===null ? \unlink($path) : \unlink($path,$context)));
		});
		$t->contains('Installed package file could not be deleted',array_column($deleteFailure->blocked(),'reason')[0]);
		$t->isTrue($deleteFailure->toArray()['meta']['transaction_reverted']);
		Probe::reset();
	});

	test('package rollback revalidates restore backups at lock and mutation boundaries',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$base=$t->workspace('dp-panel-package-exact-rollback-backup-races')->root();
		$restoreScenario=static function(string $name,\Closure $configure)use($base): PanelPackageRollbackResult {
			Probe::reset();
			$root=$base.DIRECTORY_SEPARATOR.$name;$backups=$root.DIRECTORY_SEPARATOR.'backups';mkdir($backups,0775,true);
			$target=$root.DIRECTORY_SEPARATOR.'installed.txt';$backup=$backups.DIRECTORY_SEPARATOR.'original.txt';
			file_put_contents($target,'installed');file_put_contents($backup,'original');
			$installedDigest=hash_file('sha256',$target);$backupDigest=hash_file('sha256',$backup);
			$configure($target,$backup,$root,$backups);
			$payload=dp_panel_package_exact_apply_payload($root,[['action'=>'replace','target'=>$target,'sha256'=>$installedDigest]],[['target'=>$target,'backup'=>$backup,'sha256'=>$backupDigest]],['meta'=>['dry_run'=>false,'backup_root'=>$backups]]);
			return PanelPackageRollbackPlan::fromApplyResult(PanelPackageApplyResult::make($payload))->apply();
		};

		$unsafe=$restoreScenario('unsafe-backup',static function(string $target,string $backup): void {
			$backup=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$backup);$calls=0;
			Probe::hook('is_link',static function(string $path)use($backup,&$calls): bool {
				if(str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path)===$backup){return ++$calls>=3;}
				return \is_link($path);
			});
		});
		$t->contains('Rollback backup became unsafe while the package lock was being acquired',array_column($unsafe->blocked(),'reason')[0]);

		$changed=$restoreScenario('changed-backup',static function(string $target,string $backup): void {
			$backup=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$backup);$calls=0;
			Probe::hook('hash_file',static function(string $algorithm,string $path,bool $binary=false)use($backup,&$calls): string|false {
				if(str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path)===$backup && ++$calls>=2){return str_repeat('0',64);}
				return \hash_file($algorithm,$path,$binary);
			});
		});
		$t->contains('Backup changed while the rollback lock was being acquired',array_column($changed->blocked(),'reason')[0]);

		$changedMutation=$restoreScenario('changed-backup-mutation',static function(string $target,string $backup): void {
			$backup=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$backup);$calls=0;
			Probe::hook('hash_file',static function(string $algorithm,string $path,bool $binary=false)use($backup,&$calls): string|false {
				if(str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path)===$backup && ++$calls>=3){return str_repeat('0',64);}
				return \hash_file($algorithm,$path,$binary);
			});
		});
		$t->contains('Rollback backup changed immediately before mutation',array_column($changedMutation->blocked(),'reason')[0]);
		Probe::reset();
	});

	test('package rollback attempts every snapshot recovery path and preserves failed evidence',static function(Context $t): void {
		dp_panel_package_exact_reset($t);
		$base=$t->workspace('dp-panel-package-exact-rollback-recovery')->root();
		$root=$base.DIRECTORY_SEPARATOR.'partial';$backups=$root.DIRECTORY_SEPARATOR.'backups';mkdir($backups,0775,true);
		$created=$root.DIRECTORY_SEPARATOR.'created-from-backup.txt';
		$failedDelete=$root.DIRECTORY_SEPARATOR.'failed-delete.txt';file_put_contents($failedDelete,'installed');
		$backup=$backups.DIRECTORY_SEPARATOR.'original.txt';file_put_contents($backup,'original');
		$installedDigest=hash_file('sha256',$failedDelete);$backupDigest=hash_file('sha256',$backup);
		$payload=dp_panel_package_exact_apply_payload($root,[
			['action'=>'replace','target'=>$created,'sha256'=>str_repeat('a',64)],
			['action'=>'create','target'=>$failedDelete,'sha256'=>$installedDigest],
		],[['target'=>$created,'backup'=>$backup,'sha256'=>$backupDigest]],['meta'=>['dry_run'=>false,'backup_root'=>$backups]]);
		$normalizedCreated=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$created);
		$normalizedDelete=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$failedDelete);
		Probe::hook('unlink',static function(string $path,mixed $context=null)use($normalizedCreated,$normalizedDelete): bool {
			$normalized=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path);
			if($normalized===$normalizedDelete || $normalized===$normalizedCreated){return false;}
			return $context===null ? \unlink($path) : \unlink($path,$context);
		});
		$result=PanelPackageRollbackPlan::fromApplyResult(PanelPackageApplyResult::make($payload))->apply();
		$t->isFalse($result->ok());
		$t->contains('Rollback transaction could not remove a partial write.',array_column($result->blocked(),'reason'));
		$t->notEmpty($result->toArray()['meta']['transaction_snapshot']);

		Probe::reset();
		$root2=$base.DIRECTORY_SEPARATOR.'exception';mkdir($root2,0775,true);
		$target2=$root2.DIRECTORY_SEPARATOR.'installed.txt';file_put_contents($target2,'installed');$digest2=hash_file('sha256',$target2);
		$payload2=dp_panel_package_exact_apply_payload($root2,[['action'=>'create','target'=>$target2,'sha256'=>$digest2]]);
		$normalized2=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$target2);$copyCalls=0;
		Probe::hook('unlink',static fn(string $path,mixed $context=null): bool=>str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path)===$normalized2 ? false : ($context===null ? \unlink($path) : \unlink($path,$context)));
		Probe::hook('copy',static function(string $from,string $to,mixed $context=null)use(&$copyCalls): bool {
			if(++$copyCalls>=2){return false;}
			return $context===null ? \copy($from,$to) : \copy($from,$to,$context);
		});
		$result2=PanelPackageRollbackPlan::fromApplyResult(PanelPackageApplyResult::make($payload2))->apply();
		$t->isFalse($result2->ok());
		$t->contains('Rollback transaction recovery failed:',implode(' ',array_column($result2->blocked(),'reason')));
		$t->notEmpty($result2->reverted());
	});
}
