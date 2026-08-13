<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Versioned locale, input-method, and assistive-technology quality plan. */
final class PanelInclusiveQualityMatrix implements \JsonSerializable {
	private const MAX_PROFILES=32;
	private const MAX_CONTRACTS=64;
	private const MAX_CASES=2048;
	private const MAX_LIST=32;
	private const MAX_SERIALIZED_BROWSER_BYTES=8*1024*1024;
	private const DOMAINS=['locale','input','assistive_technology','display'];
	private const EXECUTIONS=['php','browser','adapter','manual'];
	private const AUTOMATION=['fully_automated','automated_proxy','adapter','manual'];
	private const PLURALS=['zero','one','two','few','many','other'];
	private const REAL_AT_PATTERN='/(?:^|_)(?:nvda|jaws|voiceover|talkback|dragon|voice_access|native_high_contrast|native_browser_zoom|native_ime|switch_device|virtual_cursor_at)(?:_|$)/';

	/** @param list<array<string,mixed>> $profiles @param list<array<string,mixed>> $contracts @param array<string,int> $budgets */
	private function __construct(
		private readonly string $name,
		private readonly string $url,
		private readonly array $profiles,
		private readonly array $contracts,
		private readonly array $budgets
	){}

	/** @param array<string,mixed> $options */
	public static function make(string $name,string $url,array $options=[]): self {
		if(array_diff(array_keys($options),['profiles','contracts','budgets'])!==[]){ throw new \InvalidArgumentException('Inclusive quality matrix contains unknown option keys.'); }
		$name=Resource::normalizeName($name) ?: 'inclusive_quality';
		if(strlen($name)>64){ throw new \LengthException('Inclusive quality matrix names support at most 64 bytes.'); }
		$url=PanelBrowserRegressionManifest::normalizeUrl($url);
		$profiles=array_key_exists('profiles',$options)?$options['profiles']:self::defaultProfiles();
		$contracts=array_key_exists('contracts',$options)?$options['contracts']:self::defaultContracts();
		if(!is_array($profiles) || !array_is_list($profiles) || $profiles===[]){ throw new \InvalidArgumentException('Inclusive quality locale profiles must be a non-empty list.'); }
		if(!is_array($contracts) || !array_is_list($contracts) || $contracts===[]){ throw new \InvalidArgumentException('Inclusive quality interaction contracts must be a non-empty list.'); }
		if(count($profiles)>self::MAX_PROFILES || count($contracts)>self::MAX_CONTRACTS){ throw new \LengthException('Inclusive quality profiles or contracts exceed their matrix budget.'); }
		$normalizedProfiles=self::normalizeProfiles($profiles);
		$normalizedContracts=self::normalizeContracts($contracts,$normalizedProfiles);
		$budgets=self::normalizeBudgets(is_array($options['budgets'] ?? null)?$options['budgets']:[]);
		$self=new self($name,$url,$normalizedProfiles,$normalizedContracts,$budgets);
		$self->cases();
		return $self;
	}

	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload): self {
		$allowed=['type','version','name','url','profiles','contracts','budgets','digest','case_count','automated_case_count','declared_case_count','browser_manifests'];
		if(array_diff(array_keys($payload),$allowed)!==[]){ throw new \InvalidArgumentException('Inclusive quality matrix contains unknown top-level keys.'); }
		if(isset($payload['type']) && $payload['type']!=='panel_inclusive_quality_matrix'){ throw new \InvalidArgumentException('Unknown inclusive quality matrix type.'); }
		if(isset($payload['version']) && (int)$payload['version']!==1){ throw new \InvalidArgumentException('Unsupported inclusive quality matrix version.'); }
		$self=self::make((string)($payload['name'] ?? ''),(string)($payload['url'] ?? ''),['profiles'=>$payload['profiles'] ?? null,'contracts'=>$payload['contracts'] ?? null,'budgets'=>$payload['budgets'] ?? null]);
		if(isset($payload['digest']) && !hash_equals($self->digest(),(string)$payload['digest'])){ throw new \UnexpectedValueException('Inclusive quality matrix digest mismatch.'); }
		return $self;
	}

	public function name(): string { return $this->name; }
	public function url(): string { return $this->url; }
	/** @return list<array<string,mixed>> */ public function profiles(): array { return $this->profiles; }
	/** @return list<array<string,mixed>> */ public function contracts(): array { return $this->contracts; }
	/** @return array<string,int> */ public function budgets(): array { return $this->budgets; }

	/** @return list<array<string,mixed>> */
	public function cases(): array {
		$cases=[];
		foreach($this->contracts as $contract){
			foreach($this->profiles as $profile){
				if(!$this->applies($contract,$profile)){ continue; }
				$id=$this->caseId((string)$profile['id'],(string)$contract['id']);
				$cases[]=['id'=>$id,'profile'=>$profile,'contract'=>$contract];
				if(count($cases)>$this->budgets['max_cases'] || count($cases)>self::MAX_CASES){ throw new \OverflowException('Inclusive quality matrix exceeds its case budget.'); }
			}
		}
		usort($cases,static fn(array $left,array $right):int=>strcmp((string)$left['id'],(string)$right['id']));
		if($cases===[]){ throw new \InvalidArgumentException('Inclusive quality matrix produced no executable or declared cases.'); }
		return $cases;
	}

	/** @return list<PanelBrowserRegressionManifest> */
	public function browserManifests(): array {
		$manifests=[]; $serializedBytes=0;
		foreach($this->cases() as $case){
			$contract=$case['contract'];
			if(($contract['execution'] ?? null)!=='browser'){ continue; }
			$profile=$case['profile'];
			$zoom=(int)($contract['settings']['zoom'] ?? 100);
			$motion=(string)($contract['settings']['motion'] ?? 'normal');
			$manifest=PanelBrowserRegressionManifest::make((string)$case['id'],$this->url)
				->viewport(1280,800,['is_mobile'=>(bool)($contract['settings']['mobile'] ?? false),'device_scale_factor'=>1.0])
				->interaction('quality_environment',['options'=>['locale'=>$profile['locale'],'script'=>$profile['script'],'direction'=>$profile['direction'],'timezone'=>$profile['timezone'],'numbering_system'=>$profile['numbering_system'],'calendar'=>$profile['calendar'],'long_text_factor'=>$profile['long_text_factor'],'pseudo_locale'=>$profile['pseudo_locale']]])
				->interaction('quality_contract',['options'=>['id'=>$contract['id'],'settings'=>$contract['settings'],'proves'=>$contract['proves'],'does_not_prove'=>$contract['does_not_prove']]])
				->accessibility(['enabled'=>true,'fail_on'=>['critical','serious'],'rules'=>['keyboard'=>true,'focus_trap'=>true,'contrast'=>true,'zoom'=>$zoom,'motion'=>$motion]])
				->consolePolicy(['fail_on'=>['error','assert'],'allow'=>[],'ignore'=>[]])
				->meta(['quality_matrix'=>$this->name,'quality_matrix_digest'=>$this->digest(),'quality_case_id'=>$case['id'],'quality_profile'=>$profile,'quality_contract'=>$contract,'automation_claim'=>$contract['automation']]);
			$serializedBytes+=strlen(json_encode($manifest->toArray(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
			if($serializedBytes>self::MAX_SERIALIZED_BROWSER_BYTES){ throw new \OverflowException('Inclusive quality browser manifests exceed their 8388608 byte serialization budget.'); }
			$manifests[]=$manifest;
		}
		return $manifests;
	}

	public function register(PanelRegressionSuite $suite): PanelRegressionSuite {
		foreach($this->browserManifests() as $manifest){ $suite->browser($manifest); }
		return $suite->meta(['inclusive_quality_matrix'=>$this->name,'inclusive_quality_digest'=>$this->digest()]);
	}

	/** @param list<PanelQualityEvidence|array<string,mixed>> $evidence @param array<string,mixed> $budgets */
	public function evaluate(PanelQualityCapabilityReport $capabilities,array $evidence=[],array $budgets=[]): PanelInclusiveQualityResult {
		return new PanelInclusiveQualityResult($this,$capabilities,$evidence,array_replace($this->budgets,self::normalizeBudgets($budgets,true)));
	}

	public function digest(): string { return hash('sha256',self::canonicalJson($this->baseManifest())); }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		$base=$this->baseManifest();
		$base['digest']=$this->digest();
		$base['case_count']=count($this->cases());
		$base['automated_case_count']=count(array_filter($this->cases(),static fn(array $case):bool=>in_array($case['contract']['execution'],['php','browser'],true)));
		$base['declared_case_count']=$base['case_count']-$base['automated_case_count'];
		$base['browser_manifests']=array_map(static fn(PanelBrowserRegressionManifest $manifest):array=>$manifest->toArray(),$this->browserManifests());
		return $base;
	}

	/** @return array<string,mixed> */
	private function baseManifest(): array { return ['type'=>'panel_inclusive_quality_matrix','version'=>1,'name'=>$this->name,'url'=>$this->url,'profiles'=>$this->profiles,'contracts'=>$this->contracts,'budgets'=>$this->budgets]; }

	/** @param array<string,mixed> $contract @param array<string,mixed> $profile */
	private function applies(array $contract,array $profile): bool {
		return match($contract['locale_scope']){
			'all'=>true,
			'representative'=>$profile['representative']===true,
			'pseudo'=>$profile['pseudo_locale']===true,
			'list'=>in_array($profile['id'],$contract['locales'],true),
			default=>false,
		};
	}

	private function caseId(string $profile,string $contract): string { return $this->name.'.p'.strlen($profile).'.'.$profile.'.c'.strlen($contract).'.'.$contract; }

	/** @param list<mixed> $profiles @return list<array<string,mixed>> */
	private static function normalizeProfiles(array $profiles): array {
		$out=[];
		foreach($profiles as $profile){
			if(!is_array($profile)){ throw new \InvalidArgumentException('Inclusive quality locale profiles must be objects.'); }
			$allowed=['id','locale','script','direction','timezone','numbering_system','calendar','plural_categories','long_text_factor','pseudo_locale','representative'];
			if(array_diff(array_keys($profile),$allowed)!==[]){ throw new \InvalidArgumentException('Inclusive quality locale profiles contain unknown keys.'); }
			$id=self::id((string)($profile['id'] ?? ''));
			$locale=trim((string)($profile['locale'] ?? ''));
			$script=ucfirst(strtolower(trim((string)($profile['script'] ?? ''))));
			$direction=strtolower(trim((string)($profile['direction'] ?? '')));
			$timezone=trim((string)($profile['timezone'] ?? ''));
			$numbering=self::id((string)($profile['numbering_system'] ?? ''));
			$calendar=self::id((string)($profile['calendar'] ?? 'gregory'));
			$plurals=self::ids(is_array($profile['plural_categories'] ?? null)?$profile['plural_categories']:[]);
			$factor=(float)($profile['long_text_factor'] ?? 1.0);
			if($id==='' || isset($out[$id])){ throw new \InvalidArgumentException('Inclusive quality locale profile ids must be unique and stable.'); }
			if(preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,3}$/D',$locale)!==1 || preg_match('/^[A-Z][a-z]{3}$/D',$script)!==1 || !in_array($direction,['ltr','rtl'],true)){ throw new \InvalidArgumentException("Inclusive quality locale profile '{$id}' has invalid locale, script, or direction data."); }
			try{ new \DateTimeZone($timezone); }catch(\Throwable $exception){ throw new \InvalidArgumentException("Inclusive quality locale profile '{$id}' has an invalid timezone.",0,$exception); }
			if($numbering==='' || $calendar==='' || $plurals===[] || array_diff($plurals,self::PLURALS)!==[]){ throw new \InvalidArgumentException("Inclusive quality locale profile '{$id}' has invalid number, date, or plural data."); }
			if(!is_finite($factor) || $factor<1.0 || $factor>4.0){ throw new \InvalidArgumentException("Inclusive quality locale profile '{$id}' has an invalid long-text factor."); }
			$out[$id]=['id'=>$id,'locale'=>$locale,'script'=>$script,'direction'=>$direction,'timezone'=>$timezone,'numbering_system'=>$numbering,'calendar'=>$calendar,'plural_categories'=>$plurals,'long_text_factor'=>$factor,'pseudo_locale'=>(bool)($profile['pseudo_locale'] ?? false),'representative'=>(bool)($profile['representative'] ?? false)];
		}
		ksort($out,SORT_STRING); return array_values($out);
	}

	/** @param list<mixed> $contracts @param list<array<string,mixed>> $profiles @return list<array<string,mixed>> */
	private static function normalizeContracts(array $contracts,array $profiles): array {
		$profileIds=array_column($profiles,'id'); $out=[];
		foreach($contracts as $contract){
			if(!is_array($contract)){ throw new \InvalidArgumentException('Inclusive quality interaction contracts must be objects.'); }
			$allowed=['id','label','domain','execution','automation','locale_scope','locales','required_capabilities','proves','does_not_prove','settings','max_millis'];
			if(array_diff(array_keys($contract),$allowed)!==[]){ throw new \InvalidArgumentException('Inclusive quality contracts contain unknown keys.'); }
			$id=self::id((string)($contract['id'] ?? ''));
			$label=self::text($contract['label'] ?? str_replace('_',' ',$id),256);
			$domain=strtolower(trim((string)($contract['domain'] ?? '')));
			$execution=strtolower(trim((string)($contract['execution'] ?? '')));
			$automation=strtolower(trim((string)($contract['automation'] ?? '')));
			$scope=strtolower(trim((string)($contract['locale_scope'] ?? 'representative')));
			$locales=self::ids(is_array($contract['locales'] ?? null)?$contract['locales']:[]);
			$capabilities=self::ids(is_array($contract['required_capabilities'] ?? null)?$contract['required_capabilities']:[]);
			$proves=self::texts(is_array($contract['proves'] ?? null)?$contract['proves']:[]);
			$doesNotProve=self::texts(is_array($contract['does_not_prove'] ?? null)?$contract['does_not_prove']:[]);
			$settings=is_array($contract['settings'] ?? null)?$contract['settings']:[];
			self::assertJson($settings);
			if($id==='' || isset($out[$id])){ throw new \InvalidArgumentException('Inclusive quality contract ids must be unique and stable.'); }
			if(!in_array($domain,self::DOMAINS,true) || !in_array($execution,self::EXECUTIONS,true) || !in_array($automation,self::AUTOMATION,true)){ throw new \InvalidArgumentException("Inclusive quality contract '{$id}' has an invalid domain or execution classification."); }
			$automated=in_array($execution,['php','browser'],true);
			if($automated!==in_array($automation,['fully_automated','automated_proxy'],true) || (!$automated && $automation!==$execution)){ throw new \InvalidArgumentException("Inclusive quality contract '{$id}' has a misleading automation classification."); }
			if($automated && $domain==='assistive_technology' && $automation!=='automated_proxy'){ throw new \InvalidArgumentException("Inclusive quality assistive-technology contract '{$id}' must be an explicitly bounded automation proxy."); }
			if($automated && preg_match(self::REAL_AT_PATTERN,$id)===1){ throw new \InvalidArgumentException("Inclusive quality contract '{$id}' cannot automate a native assistive-technology claim."); }
			if($automation==='automated_proxy' && $doesNotProve===[]){ throw new \InvalidArgumentException("Inclusive quality proxy '{$id}' must state what it does not prove."); }
			if($proves===[] || count($capabilities)>16){ throw new \InvalidArgumentException("Inclusive quality contract '{$id}' requires bounded proof and capability declarations."); }
			if(!in_array($scope,['all','representative','pseudo','list'],true) || ($scope==='list' && ($locales===[] || array_diff($locales,$profileIds)!==[])) || ($scope!=='list' && $locales!==[])){ throw new \InvalidArgumentException("Inclusive quality contract '{$id}' has an invalid locale scope."); }
			$maxMillis=(int)($contract['max_millis'] ?? 30000);
			if($maxMillis<1 || $maxMillis>300000){ throw new \InvalidArgumentException("Inclusive quality contract '{$id}' has an invalid duration budget."); }
			$out[$id]=['id'=>$id,'label'=>$label,'domain'=>$domain,'execution'=>$execution,'automation'=>$automation,'locale_scope'=>$scope,'locales'=>$locales,'required_capabilities'=>$capabilities,'proves'=>$proves,'does_not_prove'=>$doesNotProve,'settings'=>$settings,'max_millis'=>$maxMillis];
		}
		ksort($out,SORT_STRING); return array_values($out);
	}

	/** @param array<string,mixed> $input @return array<string,int> */
	private static function normalizeBudgets(array $input,bool $partial=false): array {
		$defaults=['max_cases'=>512,'max_evidence'=>1024,'max_automated_failures'=>0,'max_automated_missing'=>0,'max_manual_failures'=>0,'min_manual_passes'=>0,'minimum_assertions'=>1];
		$allowed=array_keys($defaults);
		if(array_diff(array_keys($input),$allowed)!==[]){ throw new \InvalidArgumentException('Inclusive quality matrix contains unknown budget keys.'); }
		$out=$partial?[]:$defaults;
		foreach($input as $key=>$value){
			if(!is_int($value) && !(is_string($value) && ctype_digit($value))){ throw new \InvalidArgumentException("Inclusive quality budget '{$key}' must be an integer."); }
			$value=(int)$value;
			$maximum=in_array($key,['max_cases','max_evidence'],true)?self::MAX_CASES:100000;
			if($value<0 || $value>$maximum || in_array($key,['max_cases','max_evidence','minimum_assertions'],true) && $value<1){ throw new \InvalidArgumentException("Inclusive quality budget '{$key}' is outside its supported range."); }
			$out[$key]=$value;
		}
		return $out;
	}

	/** @return list<array<string,mixed>> */
	private static function defaultProfiles(): array {
		return [
			self::profile('en_us','en-US','Latn','ltr','America/Toronto','latn','gregory',['one','other'],1.0,false,true),
			self::profile('fr_ca','fr-CA','Latn','ltr','America/Toronto','latn','gregory',['one','many','other'],1.35,false,false),
			self::profile('ar_eg','ar-EG','Arab','rtl','Africa/Cairo','arab','gregory',['zero','one','two','few','many','other'],1.25,false,true),
			self::profile('he_il','he-IL','Hebr','rtl','Asia/Jerusalem','latn','gregory',['one','two','other'],1.2,false,false),
			self::profile('ja_jp','ja-JP','Jpan','ltr','Asia/Tokyo','latn','gregory',['other'],1.15,false,true),
			self::profile('zh_hans_cn','zh-Hans-CN','Hans','ltr','Asia/Shanghai','latn','gregory',['other'],1.1,false,false),
			self::profile('zh_hant_tw','zh-Hant-TW','Hant','ltr','Asia/Taipei','latn','gregory',['other'],1.15,false,false),
			self::profile('hi_in','hi-IN','Deva','ltr','Asia/Kolkata','latn','gregory',['one','other'],1.3,false,false),
			self::profile('ru_ru','ru-RU','Cyrl','ltr','Europe/Moscow','latn','gregory',['one','few','many','other'],1.45,false,false),
			self::profile('th_th','th-TH','Thai','ltr','Asia/Bangkok','latn','buddhist',['other'],1.25,false,false),
			self::profile('en_xa','en-XA','Latn','ltr','UTC','latn','gregory',['one','other'],2.0,true,true),
			self::profile('ar_xb','ar-XB','Arab','rtl','UTC','arab','gregory',['zero','one','two','few','many','other'],1.8,true,false),
		];
	}

	/** @return list<array<string,mixed>> */
	private static function defaultContracts(): array {
		return [
			self::contract('locale_intl','locale','browser','fully_automated','all',['browser.dom','browser.javascript_intl'],['number, date, timezone, calendar, numbering-system, and plural APIs resolve for the profile']),
			self::contract('long_text_reflow','locale','browser','fully_automated','all',['browser.dom'],['declared long-text expansion preserves readable reflow']),
			self::contract('pseudo_locale_reflow','locale','browser','automated_proxy','pseudo',['browser.dom'],['pseudo-localized expansion and bidirectional layout remain operable'],['production translation quality']),
			self::contract('keyboard_only','input','browser','fully_automated','representative',['browser.dom','browser.keyboard'],['focus order, visible focus, activation, and escape paths work without pointer input']),
			self::contract('switch_keyboard_proxy','assistive_technology','browser','automated_proxy','representative',['browser.dom','browser.keyboard'],['single-switch-equivalent sequential focus and activation contract'],['physical switch hardware, switch scanning software, or user fatigue']),
			self::contract('screen_reader_semantics_proxy','assistive_technology','browser','automated_proxy','representative',['browser.accessibility_tree','browser.dom'],['roles, names, states, headings, and landmarks exist in the browser accessibility tree'],['NVDA, JAWS, VoiceOver, or TalkBack execution and announcements']),
			self::contract('voice_control_names_proxy','assistive_technology','browser','automated_proxy','representative',['browser.dom'],['interactive controls expose nonempty accessible names usable as voice-target candidates'],['contextual name disambiguation or Dragon, Voice Control, and Voice Access command recognition']),
			self::contract('forced_colors','display','browser','fully_automated','representative',['browser.forced_colors'],['forced-colors media activation preserves focus and control visibility'],[],['forced_colors'=>'active']),
			self::contract('zoom_200','display','browser','automated_proxy','representative',['browser.zoom_reflow'],['a 200 percent equivalent CSS viewport exercises reflow and operation'],['native browser zoom rendering, text rasterization, and browser chrome interaction'],['zoom'=>200]),
			self::contract('zoom_300','display','browser','automated_proxy','representative',['browser.zoom_reflow'],['a 300 percent equivalent CSS viewport exercises reflow and operation'],['native browser zoom rendering, text rasterization, and browser chrome interaction'],['zoom'=>300]),
			self::contract('zoom_400','display','browser','automated_proxy','representative',['browser.zoom_reflow'],['a 400 percent equivalent CSS viewport exercises reflow and operation'],['native browser zoom rendering, text rasterization, and browser chrome interaction'],['zoom'=>400]),
			self::contract('reduced_motion','display','browser','fully_automated','representative',['browser.reduced_motion'],['reduced-motion media preference suppresses nonessential motion'],[],['motion'=>'reduced']),
			self::contract('touch','input','browser','fully_automated','representative',['browser.touch'],['touch activation and target-size contracts execute'],[],['mobile'=>true,'pointer'=>'touch']),
			self::contract('coarse_pointer','input','browser','fully_automated','representative',['browser.coarse_pointer'],['coarse-pointer layout and activation contracts execute'],[],['mobile'=>true,'pointer'=>'coarse']),
			self::contract('ime_composition','input','browser','automated_proxy','representative',['browser.composition_events','browser.dom'],['synthetic compositionstart, compositionupdate, compositionend, and input sequencing preserves composed text'],['native operating-system IME candidate windows and language-engine behavior']),
			self::contract('virtual_cursor_semantics_proxy','assistive_technology','browser','automated_proxy','representative',['browser.accessibility_tree'],['linear accessibility-tree navigation has meaningful structure and names'],['assistive-technology virtual-cursor execution or announcement order']),
			self::contract('switch_device','assistive_technology','adapter','adapter','representative',['adapter.switch_device'],['physical switch navigation, activation, recovery, and dwell behavior']),
			self::contract('screen_reader_nvda','assistive_technology','adapter','adapter','representative',['adapter.nvda'],['NVDA focus, browse-mode, forms-mode, announcement, and live-region behavior']),
			self::contract('screen_reader_jaws','assistive_technology','adapter','adapter','representative',['adapter.jaws'],['JAWS focus, virtual-cursor, forms-mode, announcement, and live-region behavior']),
			self::contract('screen_reader_voiceover','assistive_technology','adapter','adapter','representative',['adapter.voiceover'],['VoiceOver rotor, focus, announcement, gesture, and live-region behavior']),
			self::contract('screen_reader_talkback','assistive_technology','adapter','adapter','representative',['adapter.talkback'],['TalkBack focus, swipe, announcement, gesture, and live-region behavior']),
			self::contract('voice_dragon','assistive_technology','adapter','adapter','representative',['adapter.dragon'],['Dragon voice targeting, dictation, correction, and command behavior']),
			self::contract('voice_control_apple','assistive_technology','adapter','adapter','representative',['adapter.voice_control_apple'],['Apple Voice Control name and number targeting plus dictation behavior']),
			self::contract('voice_access_android','assistive_technology','adapter','adapter','representative',['adapter.voice_access_android'],['Android Voice Access targeting, numbering, and dictation behavior']),
			self::contract('native_high_contrast','display','adapter','adapter','representative',['adapter.native_high_contrast'],['native operating-system high-contrast rendering and operability']),
			self::contract('native_browser_zoom','display','adapter','adapter','representative',['adapter.browser_zoom'],['native browser zoom at 200, 300, and 400 percent preserves reflow, rendering, and operation'],[],['zoom_levels'=>[200,300,400]]),
			self::contract('native_ime_composition','input','adapter','adapter','representative',['adapter.native_ime'],['native IME candidate, conversion, composition, cancellation, and submission behavior']),
			self::contract('virtual_cursor_at','assistive_technology','manual','manual','representative',[],['human-observed assistive-technology virtual-cursor order and announcements']),
		];
	}

	/** @return array<string,mixed> */
	private static function profile(string $id,string $locale,string $script,string $direction,string $timezone,string $numbering,string $calendar,array $plurals,float $factor,bool $pseudo,bool $representative): array { return compact('id','locale','script','direction','timezone')+['numbering_system'=>$numbering,'calendar'=>$calendar,'plural_categories'=>$plurals,'long_text_factor'=>$factor,'pseudo_locale'=>$pseudo,'representative'=>$representative]; }

	/** @return array<string,mixed> */
	private static function contract(string $id,string $domain,string $execution,string $automation,string $scope,array $capabilities,array $proves,array $doesNotProve=[],array $settings=[]): array { return ['id'=>$id,'label'=>str_replace('_',' ',$id),'domain'=>$domain,'execution'=>$execution,'automation'=>$automation,'locale_scope'=>$scope,'locales'=>[],'required_capabilities'=>$capabilities,'proves'=>$proves,'does_not_prove'=>$doesNotProve,'settings'=>$settings,'max_millis'=>30000]; }

	private static function id(string $value): string { $value=strtolower(trim($value)); return preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D',$value)===1?$value:''; }

	/** @param array<mixed> $values @return list<string> */
	private static function ids(array $values): array { if(count($values)>self::MAX_LIST){ throw new \LengthException('Inclusive quality id lists support at most 32 values.'); } $out=[]; foreach($values as $value){ if(!is_scalar($value)){ throw new \InvalidArgumentException('Inclusive quality ids must be scalar.'); } $id=self::id((string)$value); if($id===''){ throw new \InvalidArgumentException('Inclusive quality lists contain an invalid id.'); } $out[$id]=true; } $out=array_keys($out); sort($out,SORT_STRING); return $out; }

	/** @param array<mixed> $values @return list<string> */
	private static function texts(array $values): array { if(count($values)>self::MAX_LIST){ throw new \LengthException('Inclusive quality proof lists support at most 32 values.'); } $out=[]; foreach($values as $value){ $text=self::text($value,1024); $out[$text]=true; } $out=array_keys($out); sort($out,SORT_STRING); return $out; }

	private static function text(mixed $value,int $maximum): string { if(!is_scalar($value)){ throw new \InvalidArgumentException('Inclusive quality text must be scalar.'); } $value=trim((string)$value); if($value==='' || preg_match('//u',$value)!==1 || strlen($value)>$maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===1){ throw new \InvalidArgumentException('Inclusive quality text is invalid or exceeds its budget.'); } return $value; }

	private static function assertJson(mixed $value,int $depth=0): void {
		if($depth>8){ throw new \LengthException('Inclusive quality JSON exceeds its nesting budget.'); }
		if(is_array($value)){ if(count($value)>128){ throw new \LengthException('Inclusive quality JSON exceeds its item budget.'); } foreach($value as $key=>$item){ if(!is_int($key) && (preg_match('//u',(string)$key)!==1 || strlen((string)$key)>128)){ throw new \InvalidArgumentException('Inclusive quality JSON keys must be bounded UTF-8 text.'); } self::assertJson($item,$depth+1); } return; }
		if(is_float($value) && !is_finite($value)){ throw new \InvalidArgumentException('Inclusive quality JSON numbers must be finite.'); }
		if(!is_scalar($value) && $value!==null){ throw new \InvalidArgumentException('Inclusive quality settings must contain JSON data only.'); }
		if(is_string($value) && (preg_match('//u',$value)!==1 || strlen($value)>4096)){ throw new \InvalidArgumentException('Inclusive quality JSON strings must be bounded UTF-8 text.'); }
	}

	private static function canonicalJson(array $value): string { return json_encode(self::canonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION); }
	private static function canonical(mixed $value): mixed { if(!is_array($value)){ return $value; } if(!array_is_list($value)){ ksort($value,SORT_STRING); } foreach($value as $key=>$item){ $value[$key]=self::canonical($item); } return $value; }
}
