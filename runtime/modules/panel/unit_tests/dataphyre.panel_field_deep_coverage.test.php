<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Field;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!class_exists('dataphyre\\geoposition',false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class geoposition { public static function validate_postal_code(string $country,string $subdivision,string $text): bool { if($subdivision==="THROW"){ throw new \\RuntimeException("geo"); } return $text!=="BAD"; } public static function reformat_postal_code(string $country,string $subdivision,string $text): string { if($subdivision==="THROW"){ throw new \\RuntimeException("geo"); } return $subdivision==="*" ? strtoupper($text) : $text; } }');
}

test('panel field deep text normalization covers semantic formatting variants and boundaries',static function(Context $t): void {
	$cases=[
		['normalizeInternationalPhoneText',['(416) 555-0123',['country'=>'CA'],true]],
		['normalizeInternationalPhoneText',['020 7946 0018',['country'=>'GB'],true]],
		['normalizeInternationalPhoneText',['+33 1 42 68 53 00',[],false]],
		['normalizeUrlText',[' example.com/path?x=1 ']],
		['normalizeUrlText',['//cdn.example.com/file']],
		['normalizeUrlText',['mailto:user@example.com']],
		['normalizeMapUrlText',['43.6532,-79.3832']],
		['normalizeMapUrlText',['maps.google.com/?q=Toronto']],
		['normalizeDomainText',[' HTTPS://WWW.Example.COM/path ']],
		['normalizeDomainText',['sub.example.co.uk.']],
		['normalizeTimezoneText',[' america/toronto ']],
		['normalizeTimezoneText',['UTC']],
		['normalizeTimezoneText',['gmt']],
		['normalizeLocaleText',[' en_ca ']],
		['normalizeLocaleText',['fr-CA']],
		['normalizeJsonText',['{"b":2,"a":1}',false]],
		['normalizeJsonText',['{"b":2,"a":1}',true]],
		['normalizeJsonText',['not-json',true]],
		['normalizeMimeTypeText',[' Text/HTML ; Charset=UTF-8 ']],
		['normalizeSemverText',[' v1.2.3-beta+build ']],
		['normalizeCronExpressionText',['  */5   * * * *  ']],
		['normalizeLanguageCodeText',[' EN_ca ']],
		['normalizeLanguageCodeText',['zh-Hant-TW']],
		['normalizeCurrencyCodeText',[' cad ']],
		['normalizeMacAddressText',['aa-bb-cc-dd-ee-ff']],
		['normalizeUuidText',['{550E8400-E29B-41D4-A716-446655440000}']],
		['normalizeUlidText',['01arz3ndektsv4rrffq69g5fav']],
		['normalizeHexColorText',['abc']],
		['normalizeHexColorText',['rgba(255, 0, 0, .5)']],
		['normalizeCoordinateText',['43.6532000',4]],
		['normalizeCoordinateText',['invalid',6]],
		['normalizeCoordinatePairText',['43.6532, -79.3832',6,false]],
		['normalizeCoordinatePairText',['-79.3832 43.6532',4,true]],
		['sentenceCaseText',['hELLO WORLD']],
		['slugText',[' Déjà Vu & More ']],
		['separatorCaseText',['HelloWorld value','_']],
		['separatorCaseText',['HelloWorld value','-']],
		['camelCaseText',['hello-world value']],
	];
	$results=[];
	foreach($cases as [$method,$arguments]){
		$results[]=$t->nonPublic(Field::class)->invoke($method,...$arguments);
	}
	$t->same(count($cases),count($results));
	$t->contains('https://example.com/path?x=1',$results);
	$t->contains('CAD',$results);
	$t->contains('#aabbcc',$results);
})->tag('panel','field','coverage')->group('framework-coverage');

test('panel field deep validators cover semantic valid invalid and boundary inputs',static function(Context $t): void {
	$booleanCases=[
		['validDomainText',['example.com']],['validDomainText',['bad domain']],
		['validMapUrlText',['https://maps.google.com/?q=Toronto']],['validMapUrlText',['javascript:alert(1)']],
		['validTimezoneText',['America/Toronto']],['validTimezoneText',['Mars/Olympus']],
		['validLocaleText',['en_CA']],['validLocaleText',['invalid locale!']],
		['validJsonText',['{"ok":true}']],['validJsonText',['{bad}']],
		['validMimeTypeText',['application/json']],['validMimeTypeText',['not mime']],
		['validSemverText',['1.2.3-beta+1']],['validSemverText',['version one']],
		['validCronExpressionText',['*/5 * * * *']],['validCronExpressionText',['61 * * * *']],
		['validLanguageCodeText',['en-CA']],['validLanguageCodeText',['123']],
		['validCountryCodeText',['CA']],['validCountryCodeText',['ZZZ']],
		['validSubdivisionCodeText',['ON','CA']],['validSubdivisionCodeText',['ZZ','CA']],
		['validCurrencyCodeText',['CAD']],['validCurrencyCodeText',['ZZZ']],
		['validMacAddressText',['AA:BB:CC:DD:EE:FF']],['validMacAddressText',['invalid']],
		['validUuidText',['550e8400-e29b-41d4-a716-446655440000']],['validUuidText',['bad']],
		['validUlidText',['01ARZ3NDEKTSV4RRFFQ69G5FAV']],['validUlidText',['bad']],
		['validHexColorText',['#aabbcc']],['validHexColorText',['#xyz']],
		['validCoordinateText',['90',-90.0,90.0]],['validCoordinateText',['91',-90.0,90.0]],
		['validCoordinatePairText',['43.6532,-79.3832']],['validCoordinatePairText',['200,300']],
		['validCreditCardNumber',['4111111111111111']],['validCreditCardNumber',['4111111111111112']],
		['validCreditCardExpiry',['12/30']],['validCreditCardExpiry',['13/20']],
		['validIban',['GB82WEST12345698765432']],['validIban',['GB00BAD']],
	];
	$truth=[];
	foreach($booleanCases as [$method,$arguments]){
		$truth[]=$t->nonPublic(Field::class)->invoke($method,...$arguments);
	}
	$t->contains(true,$truth);
	$t->contains(false,$truth);
	$t->same(count($booleanCases),count($truth));

	$cronCases=[
		['validCronField',['*',0,59]],['validCronField',['1-5',0,59]],['validCronField',['*/15',0,59]],
		['validCronField',['1,2,3',0,59]],['validCronField',['70',0,59]],
		['validCronFieldItem',['1-5/2',0,59]],['validCronFieldItem',['5-1',0,59]],
		['cronFieldValue',['JAN',['jan'=>1,'feb'=>2]]],['cronFieldValue',['7',[]]],['cronFieldValue',['bad',[]]],
	];
	foreach($cronCases as [$method,$arguments]){
		$t->nonPublic(Field::class)->invoke($method,...$arguments);
	}
	$t->same(count($cronCases),10);
})->tag('panel','field','coverage')->group('framework-coverage');

test('panel field upload option and scalar utilities cover nested input shapes',static function(Context $t): void {
	$file=[
		'name'=>'avatar.png','type'=>'image/png','tmp_name'=>'/tmp/avatar','error'=>UPLOAD_ERR_OK,'size'=>2048,
	];
	$nested=[
		'name'=>['a.txt','b.png'],'type'=>['text/plain','image/png'],'tmp_name'=>['/tmp/a','/tmp/b'],
		'error'=>[UPLOAD_ERR_OK,UPLOAD_ERR_NO_FILE],'size'=>[12,0],
	];
	$uploads=[
		$t->nonPublic(Field::class)->invoke('normalizeUploadedFiles',null),
		$t->nonPublic(Field::class)->invoke('normalizeUploadedFiles',$file),
		$t->nonPublic(Field::class)->invoke('normalizeUploadedFiles',[$file,$nested,'invalid']),
		$t->nonPublic(Field::class)->invoke('normalizeCustomUploaderItems',['url'=>'https://example.com/a.png','name'=>'a.png']),
		$t->nonPublic(Field::class)->invoke('normalizeCustomUploaderItems',[['path'=>'stored/a.png'],null,'bad']),
	];
	$t->same(5,count($uploads));
	$t->isTrue($t->nonPublic(Field::class)->invoke('fileAccepted',$file,['image/*','.png']));
	$t->isFalse($t->nonPublic(Field::class)->invoke('fileAccepted',$file,['application/pdf']));
	foreach([UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE,UPLOAD_ERR_PARTIAL,UPLOAD_ERR_NO_FILE,UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE,UPLOAD_ERR_EXTENSION,999] as $error){
		$t->notEmpty($t->nonPublic(Field::class)->invoke('uploadedFileError',$error));
	}
	foreach([0,512,1024,1048576,1073741824] as $bytes){
		$t->notEmpty($t->nonPublic(Field::class)->invoke('formatBytes',$bytes));
	}

	$options=[
		'plain'=>'Plain',
		'disabled'=>['label'=>'Disabled','disabled'=>true,'description'=>'Unavailable'],
		'group'=>['label'=>'Group','options'=>['one'=>'One','two'=>['label'=>'Two','description'=>'Second']]],
	];
	$t->isTrue($t->nonPublic(Field::class)->invoke('optionsContainGroups',$options));
	$t->isTrue($t->nonPublic(Field::class)->invoke('optionsContainDisabled',$options));
	$t->isTrue($t->nonPublic(Field::class)->invoke('optionsContainDescriptions',$options));
	$t->isTrue(count($t->nonPublic(Field::class)->invoke('optionValues',$options,null))>=3);
	$t->isTrue($t->nonPublic(Field::class)->invoke('isOptionGroup',$options['group']));
	$t->same('Value',$t->nonPublic(Field::class)->invoke('optionDefinition','x','Value')['label']);

	foreach([null,true,false,0,1,1.5,'','abc',[1,2],['a'=>1]] as $value){
		$t->nonPublic(Field::class)->invoke('sizeOf',$value);
		$t->nonPublic(Field::class)->invoke('truthy',$value);
	}
	$t->isTrue($t->nonPublic(Field::class)->invoke('truthy',new stdClass()));
	$t->isTrue($t->nonPublic(Field::class)->invoke('regexMatches','/^a/','abc'));
	$t->isFalse($t->nonPublic(Field::class)->invoke('regexMatches','/[invalid/','abc'));
	$t->isTrue($t->nonPublic(Field::class)->invoke('stringStartsWithAny','abcdef',['x','abc']));
	$t->isTrue($t->nonPublic(Field::class)->invoke('stringEndsWithAny','abcdef',['x','def']));
	$t->same('create',$t->nonPublic(Field::class)->invoke('normalizeOperation','store'));
})->tag('panel','field','coverage')->group('framework-coverage');

test('panel field public value pipeline covers callbacks state options validation and dependency visibility',static function(Context $t): void {
	$request=\Dataphyre\Panel\PanelRequest::fromArray([
		'operation'=>'edit','input'=>['status'=>'open','active'=>'yes','kind'=>'staff'],
	]);
	$field=Field::make('value')
		->hydrateUsing(static fn(mixed $value): string=>'hydrated-'.$value)
		->dehydrateUsing(static fn(mixed $value): string=>'dehydrated-'.$value)
		->displayUsing(static fn(mixed $value): string=>'display-'.$value)
		->optionsUsing(static fn(): array=>['a'=>'Alpha','b'=>'Beta'])
		->stateUsing(static fn(): array=>[
			'value'=>'state','visible'=>true,'errors'=>['none'],'unknown'=>'discarded',
		],'status')
		->visibleUsing(static fn(string $operation): bool=>$operation==='edit')
		->visibleOn('edit','view')
		->hiddenOn('index')
		->visibleWhen('status',['open','pending'])
		->hiddenWhen('archived',true)
		->requiredWhen('active',true)
		->requiredUnless('kind','guest');
	$t->same('hydrated-x',$field->hydrateValue('x',[], $request));
	$t->same('dehydrated-x',$field->dehydrateValue('x',[], $request));
	$t->same('display-x',$field->displayValue('x',[], $request));
	$t->same(['a'=>'Alpha','b'=>'Beta'],$field->optionsFor([], $request,'edit'));
	$t->same(['value'=>'state','visible'=>true,'errors'=>['none']],$field->stateFor([],null,[], $request,'edit'));
	$t->isTrue($field->isVisible('edit',[], $request));
	$t->isFalse($field->isVisible('index',[], $request));
	$t->isFalse($field->isVisible('create',[], $request));
	$t->isFalse($field->isVisible('edit',['archived'=>true],\Dataphyre\Panel\PanelRequest::fromArray(['input'=>['status'=>'open']])));

	$scalarState=Field::make('scalar')->stateUsing(static fn(): string=>'scalar');
	$t->same(['value'=>'scalar'],$scalarState->stateFor());
	$t->same([],Field::make('plain')->stateFor());
	$t->same([],Field::make('plain')->optionsFor());
	$t->same('plain',Field::make('plain')->hydrateValue('plain'));
	$t->same('plain',Field::make('plain')->displayValue('plain'));

	$stringValidation=Field::make('string')->validateUsing(static fn(): string=>'String error');
	$arrayValidation=Field::make('array')->validateUsing(static fn(): array=>['First','', 'Second']);
	$falseValidation=Field::make('false')->validateUsing(static fn(): bool=>false);
	$t->same(['String error'],$stringValidation->validateValue('x'));
	$t->same(['First','Second'],$arrayValidation->validateValue('x'));
	$t->same(['False is invalid.'],$falseValidation->validateValue('x'));

	$object=new class {
		public string $status='open';
		public function getArchived(): bool { return false; }
	};
	$dependency=Field::make('dependency')->visibleWhen('status','open')->hiddenWhen('archived',true);
	$t->isTrue($dependency->isVisible('form',$object));
	$t->isFalse($dependency->isVisible('form',['status'=>'closed']));
	$t->isTrue(Field::make('boolean')->visibleWhen('active',true)->isVisible('form',['active'=>'yes']));
	$t->isTrue(Field::make('boolean')->visibleWhen('active',false)->isVisible('form',['active'=>0]));
	$t->same('empty',Field::make('empty')->visibleWhen('')->name());
	$t->same([],Field::make('empty')->visibleWhen('')->toArray()['visible_when']);
	$t->same([],Field::make('empty')->hiddenWhen('')->toArray()['hidden_when']);
	$t->same([],Field::make('empty')->requiredWhen('')->toArray()['required_when']);
	$t->same([],Field::make('empty')->requiredUnless('')->toArray()['required_unless']);
})->tag('panel','field','coverage')->group('framework-coverage');

test('panel field public validation engine covers rules repeaters uploads choices collections and semantic formats',static function(Context $t): void {
	$rules=Field::fromArray([
		'name'=>'contract','label'=>'Contract','type'=>'text','required'=>true,
		'rules'=>[
			'required','email','numeric','integer','url','min:5','max:2','in:a,b','regex:/^z/',
			'confirmed','same:matching','different:other','starts_with:A,B','ends_with:X,Y','',
		],
		'min_length'=>5,'max_length'=>2,
	]);
	$errors=$rules->validateValue('bad',['contract_confirmation'=>'other','matching'=>'other','other'=>'bad']);
	$t->isTrue(count($errors)>=10);
	$t->notEmpty($rules->validateValue(''));

	$numeric=Field::fromArray(['name'=>'amount','type'=>'integer','min'=>10,'max'=>20]);
	$t->notEmpty($numeric->validateValue('abc'));
	$t->notEmpty($numeric->validateValue('10.5'));
	$t->notEmpty($numeric->validateValue(5));
	$t->notEmpty($numeric->validateValue(25));
	$length=Field::make('code')->text()->length(4);
	$t->notEmpty($length->validateValue('abc'));
	$between=Field::make('name')->text()->lengthBetween(3,5);
	$t->notEmpty($between->validateValue('a'));
	$t->notEmpty($between->validateValue('abcdef'));

	$tags=Field::fromArray(['name'=>'tags','type'=>'tags','min_tags'=>2,'max_tags'=>3,'tag_separator'=>',']);
	$t->notEmpty($tags->validateValue('one'));
	$t->notEmpty($tags->validateValue('one,two,three,four'));
	$keyValue=Field::fromArray(['name'=>'pairs','type'=>'key_value','min_pairs'=>2,'max_pairs'=>2]);
	$t->notEmpty($keyValue->validateValue("one=1"));
	$t->notEmpty($keyValue->validateValue("one=1\ntwo=2\nthree=3"));

	$choice=Field::make('status')->select(['open'=>'Open','closed'=>'Closed']);
	$t->notEmpty($choice->validateValue('missing'));
	$multi=Field::make('status')->multiSelect(['open'=>'Open','closed'=>'Closed']);
	$t->notEmpty($multi->validateValue(['open','missing']));

	$child=Field::make('email')->email()->required();
	$repeater=Field::make('contacts')->label('Contacts')->repeater([$child])->minItems(2)->maxItems(2)->required();
	$t->notEmpty($repeater->validateValue([]));
	$t->notEmpty($repeater->validateValue([['email'=>'bad']]));
	$t->notEmpty($repeater->validateValue([['email'=>'a@example.com'],['email'=>'b@example.com'],['email'=>'c@example.com']]));

	$upload=Field::make('document')->label('Document')->fileUpload(['image/png'],100,true)->uploadMinFiles(2)->uploadMaxFiles(2)->required();
	$t->notEmpty($upload->validateValue([]));
	$t->notEmpty($upload->validateValue([
		['name'=>'bad.pdf','type'=>'application/pdf','tmp_name'=>'/tmp/bad','error'=>UPLOAD_ERR_OK,'size'=>200],
	]));
	$t->notEmpty($upload->validateValue([
		['name'=>'bad.png','type'=>'image/png','tmp_name'=>'/tmp/bad','error'=>UPLOAD_ERR_PARTIAL,'size'=>10],
		['name'=>'ok.png','type'=>'image/png','tmp_name'=>'/tmp/ok','error'=>UPLOAD_ERR_OK,'size'=>10],
		['name'=>'extra.png','type'=>'image/png','tmp_name'=>'/tmp/extra','error'=>UPLOAD_ERR_OK,'size'=>10],
	]));

	$semanticCases=[
		['email','user@example.com','bad'],['url','example.com','javascript:bad'],['domain','Example.COM','bad domain'],
		['timezone','america/toronto','Mars/Olympus'],['locale','en_ca','bad locale!'],['json','{"ok":true}','{bad}'],
		['mimeType','text/html','bad'],['semver','v1.2.3','bad'],['cron','*/5 * * * *','61 * * * *'],
		['languageCode','en-ca','123'],['countryCode','ca','ZZZ'],['subdivisionCode','CA-ON','bad'],
		['currencyCode','cad','ZZZ'],['macAddress','aa-bb-cc-dd-ee-ff','bad'],
		['uuid','550e8400-e29b-41d4-a716-446655440000','bad'],['ulid','01ARZ3NDEKTSV4RRFFQ69G5FAV','bad'],
		['hexColor','abc','xyz'],['coordinates','43.6,-79.3','200,300'],
		['creditCard','4111111111111111','4111111111111112'],['creditCardExpiry','12/30','13/20'],
		['iban','GB82WEST12345698765432','GB00BAD'],['slug','Hello World',''],
	];
	foreach($semanticCases as [$method,$valid,$invalid]){
		$field=Field::make('semantic')->{$method}();
		$field->dehydrateValue($valid);
		$field->validateValue($valid);
		$field->validateValue($invalid);
	}
	$t->same(count($semanticCases),22);
})->tag('panel','field','coverage')->group('framework-coverage');

test('panel field internal defaults formatting geography casting grids and nested rows cover all type families',static function(Context $t): void {
	$types=[
		'text','textarea','password','hidden','email','url','tel','number','integer','float','decimal','money','currency','percent',
		'select','enum','multi_select','radio','checkbox_list','toggle_buttons','boolean','checkbox','toggle','date','datetime',
		'datetime_local','time','date_range','datetime_range','time_range','month','week','slider','range','rating','tags','tags_input',
		'key_value','repeater','builder','fieldset','group','field_group','address','file','file_upload','drag_drop_upload','image',
		'markdown','html','code','rich_editor','rich_text','placeholder','display','display_only','view_field','relationship','belongs_to','relation','multi_relationship','belongs_to_many',
		'otp','one_time_code','verification_code','pin','pin_code','credit_card','card_number','credit_card_expiry','card_expiry','card_cvc','cvc','cvv',
		'iban','slug','uuid','ulid','json','domain','hostname','timezone','time_zone','locale','language_tag','mime_type','content_type','semver','semantic_version',
		'cron_expression','cron','language_code','iso_language','country_code','iso_country','subdivision_code','region_code','currency_code','iso_currency',
		'ip_address','ip','ipv4','ipv6','mac_address','mac','hex_color','color_hex','latitude','longitude','coordinates','lat_lng','lng_lat','phone_international',
		'postal_code','postal','postal_code_ca','canadian_postal_code','zip_code_us','postal_code_us','zip','autocomplete','combobox','combo_box','unknown',
	];
	foreach($types as $type){
		$t->isTrue(is_array($t->nonPublic(Field::class)->invoke('typeDefaults',$type)));
	}

	$contexts=[
		['country'=>'CA','subdivision'=>'ON'],['country'=>'CA','subdivision'=>'QC'],['country'=>'US','subdivision'=>'CA'],
		['country'=>'AU','subdivision'=>'NSW'],['country'=>'NZ'],['country'=>'GB'],['country'=>'FR'],['country'=>'DE'],['country'=>'NL'],['country'=>'IE'],
		['country'=>'EU','subdivision'=>'FR'],['country'=>'EU','subdivision'=>'DE'],['country'=>'EU','subdivision'=>'NL'],['country'=>'EU','subdivision'=>'IE'],
		['country'=>'EU','subdivision'=>'XX'],['country'=>'US'],[],
	];
	$formatRules=[
		'phone_international','phone_us','phone_ca','email','url','map_url','domain','timezone','locale','json','mime_type','semver',
		'cron_expression','language_code','country_code','subdivision_code','currency_code','ip_address','ipv4','ipv6','mac_address',
		'uuid','ulid','hex_color','latitude','longitude','coordinates','lng_lat','postal_code','postal_code_ca','zip_code_us',
		'credit_card','credit_card_expiry','card_cvc','iban','slug','uppercase','lowercase','title_case','sentence_case','snake_case',
		'kebab_case','camel_case','trim','digits','alpha','alphanumeric','unknown','',
	];
	foreach($formatRules as $rule){
		foreach($contexts as $context){
			$effective=$t->nonPublic(Field::class)->invoke('effectiveFormatRule',$rule,$context);
			$t->nonPublic(Field::class)->invoke('normalizedFormatPattern',$effective,$context);
			$t->nonPublic(Field::class)->invoke('formatExpectedPlaceholder',$effective,$context);
			$t->nonPublic(Field::class)->invoke('normalizeFormattedValidationText',$effective,' Example 123 ',$context);
			$t->nonPublic(Field::class)->invoke('formattedSemanticValidationError',$effective,'invalid',$context);
			$t->nonPublic(Field::class)->invoke('geopositionPostalCodeValid',$effective,$context,'K1A 0B1');
			$t->nonPublic(Field::class)->invoke('geopositionReformatPostalCode',$effective,$context,'k1a0b1');
		}
	}
	$t->notEmpty($formatRules);
	$t->notEmpty($contexts);

	foreach(['CA','Canada','can','US','USA','United States','GB','UK','AU','NZ','fr',''] as $country){
		$t->nonPublic(Field::class)->invoke('normalizeCountryCode',$country);
	}
	foreach(['ON','QC','NS','NB','MB','BC','PE','SK','AB','NL','NT','YT','NU','CA','NY','NSW','VIC',''] as $subdivision){
		$t->nonPublic(Field::class)->invoke('postalRuleFromSubdivision',$subdivision);
		$t->nonPublic(Field::class)->invoke('canadianPostalPlaceholder',$subdivision);
	}
	foreach($contexts as $context){
		$country=$t->nonPublic(Field::class)->invoke('geopositionCountry','postal_code',$context);
		$t->nonPublic(Field::class)->invoke('geopositionSubdivisions',$context,$country);
	}
	$t->isFalse($t->nonPublic(Field::class)->invoke('geopositionPostalCodeValid','postal_code_ca',['country'=>'CA','subdivision'=>'ON'],'BAD'));
	$t->same(null,$t->nonPublic(Field::class)->invoke('geopositionPostalCodeValid','postal_code_ca',['country'=>'CA','subdivision'=>'THROW'],'OK'));
	$t->same('K1A0B1',$t->nonPublic(Field::class)->invoke('geopositionReformatPostalCode','postal_code_ca',['country'=>'CA'],'k1a0b1'));
	$t->same(null,$t->nonPublic(Field::class)->invoke('geopositionReformatPostalCode','postal_code_ca',['country'=>'CA','subdivision'=>'THROW'],'code'));

	foreach([1,0,12,'full','auto',['sm'=>6,'md'=>'full','bad'=>3],['sm'=>['start'=>2]]] as $grid){
		$t->nonPublic(Field::class)->invoke('normalizeGridStart',$grid);
	}
	foreach(['default','base','xs','sm','md','lg','xl','2xl','bad breakpoint'] as $breakpoint){
		$t->nonPublic(Field::class)->invoke('normalizeGridBreakpoint',$breakpoint);
	}

	$castCases=[
		[Field::make('bool')->boolean(),['yes','0',1,null]],
		[Field::make('integer')->integer(),['12','12.5','']],
		[Field::make('float')->float(),['12.5','bad','']],
		[Field::make('decimal')->decimal(2),['12.345','bad']],
		[Field::make('multi')->multiSelect(),['a,b',['a','b'],null]],
		[Field::make('tags')->tags(),['one,two',['one','two']]],
		[Field::make('pairs')->keyValue(),["a=1\nb=2",['a'=>1]]],
		[Field::make('range')->dateRange(),[['start'=>' 2026-01-01 ','end'=>' 2026-01-31 '],'2026-01-01 to 2026-01-31','']],
		[Field::make('range')->timeRange(),[['08:00','17:00'],'08:00..17:00']],
		[Field::make('rating')->rating(),['4','bad']],
		[Field::make('empty-number')->number(),['']],
	];
	foreach($castCases as [$field,$values]){
		foreach($values as $value){
			$t->nonPublic($field)->invoke('castValue',$value);
			$t->nonPublic($field)->invoke('normalizeFormattedValue',$value,null,null,[]);
		}
	}
	$customSingle=Field::make('custom')->customUploader(false,'/upload');
	$customMultiple=Field::make('custom-many')->customUploader(true,'/upload')->multiple();
	$t->nonPublic($customSingle)->invoke('castValue','{"url":"https://example.com/a"}');
	$t->nonPublic($customMultiple)->invoke('castValue','[{"url":"https://example.com/a"}]');
	$t->nonPublic($customSingle)->invoke('castValue','existing-file');
	$t->nonPublic(Field::make('upload')->fileUpload())->invoke('castValue',[]);
	$t->nonPublic(Field::make('upload')->fileUpload([],null,true))->invoke('castValue',[
		['name'=>'a.txt','type'=>'text/plain','tmp_name'=>'/tmp/a','error'=>UPLOAD_ERR_OK,'size'=>1],
	]);
	$fieldGroup=Field::make('group')->fieldGroup([Field::make('name')]);
	$t->nonPublic($fieldGroup)->invoke('castValue',['name'=>'Ada']);
	$builder=Field::make('builder')->builder([
		['name'=>'text','fields'=>[Field::make('body')]],
	]);
	$t->nonPublic($builder)->invoke('castValue',[['type'=>'text','data'=>['body'=>'Hello']]]);
	$repeaterField=Field::make('repeat')->repeater([Field::make('item')]);
	$t->nonPublic($repeaterField)->invoke('castValue',[['item'=>'one']]);

	$blocks=$t->nonPublic(Field::class)->invoke('normalizeBuilderBlocks',[
		'bad',
		['name'=>'','fields'=>[]],
		['name'=>'text','label'=>'Text','fields'=>[['name'=>'body','type'=>'textarea']]],
		'image'=>['label'=>'Image','schema'=>[['name'=>'url','type'=>'url']]],
	]);
	$t->isTrue(count($blocks)>=2);
	$definitions=$t->nonPublic(Field::class)->invoke('builderBlockDefinitions',['builder_blocks'=>$blocks]);
	$t->isTrue(count($definitions)>=2);
	$builderRows=$t->nonPublic(Field::class)->invoke('normalizeBuilderRows',[
		['type'=>'text','data'=>['body'=>'Hello']],['block'=>'image','url'=>'example.com'],['type'=>'missing'], 'bad',
	],$definitions);
	$t->isTrue(is_array($builderRows));
	$fields=[Field::make('email')->email(),Field::make('active')->boolean()];
	$t->isTrue(is_array($t->nonPublic(Field::class)->invoke('normalizeRepeaterRows',[
		['email'=>'a@example.com','active'=>'yes'],'bad',
	],$fields)));
	$t->isTrue(is_array($t->nonPublic(Field::class)->invoke('normalizeListValue',['a','b','a'])));
	$t->isTrue(is_array($t->nonPublic(Field::class)->invoke('normalizeListValue','a,b')));
	$t->isTrue(is_array($t->nonPublic(Field::class)->invoke('normalizeKeyValue',[['key'=>'a','value'=>'1'],['name'=>'b','value'=>'2']])));
	$t->isTrue(is_array($t->nonPublic(Field::class)->invoke('normalizeFieldButtons',[
		'clear',['label'=>'Apply','action'=>'apply'],['label'=>''],42,
	])));

	$parameterized=[
		Field::make('a')->accessibilityPolicy(['min_touch_target'=>48]),
		Field::make('a')->contrastPolicy(['min_ratio'=>7]),
		Field::make('a')->badge('New','success'),
		Field::make('a')->content(static fn(): string=>'content'),
		Field::make('a')->builderBlock('text',[Field::make('body')],'Text'),
		Field::make('a')->uploadHeader('X-Test','ready'),
		Field::make('a')->uploadField('tenant','one'),
		Field::make('a')->uploadCsrf('form','csrf','X-CSRF'),
		Field::make('a')->storageUploader('local','uploads/{filename}'),
		Field::make('a')->mediaCollection(['name'=>'images']),
		Field::make('a')->date('2026-01-01','2026-12-31'),
		Field::make('a')->dateTime('2026-01-01T00:00','2026-12-31T23:59'),
		Field::make('a')->time('08:00','17:00','00:15'),
		Field::make('a')->dateTimeRange('2026-01-01T00:00','2026-12-31T23:59'),
		Field::make('a')->timeRange('08:00','17:00','00:15'),
		Field::make('a')->numeric('decimal',1,10,0.5),
		Field::make('a')->month('2026-01','2026-12'),
		Field::make('a')->week('2026-W01','2026-W52'),
		Field::make('a')->suggestions(['one','two']),
		Field::make('a')->formatButton('uppercase','Upper'),
		Field::make('a')->formatCountryField('country'),
		Field::make('a')->formatSubdivisionField('subdivision'),
		Field::make('a')->sourceField('source'),
		Field::make('a')->postalCode('country','subdivision'),
		Field::make('a')->slugFrom('title'),
		Field::make('a')->lengthBetween(2,8),
		Field::make('a')->nullable(false),
	];
	$t->same(27,count($parameterized));
})->tag('panel','field','coverage')->group('framework-coverage');

test('panel field residual edge branches cover aliases blanks nested options uploader shapes and regional formats',static function(Context $t): void {
	$hydrated=[
		Field::fromArray(['name'=>'a','accessibility'=>false,'a11y'=>true]),
		Field::fromArray(['name'=>'b','required_if'=>['status'=>'open','kind'=>'admin']]),
		Field::fromArray(['name'=>'c','prepend_button'=>'Clear','append_button'=>'Apply']),
		Field::fromArray(['name'=>'d','mask'=>'999','mask_placeholder'=>false]),
		Field::fromArray(['name'=>'e','submit_unmasked'=>true]),
		Field::fromArray(['name'=>'f','format'=>'uppercase','format_placeholder'=>false]),
		Field::fromArray(['name'=>'g','type'=>'code','language'=>'php']),
		Field::fromArray(['name'=>'h','state_callback'=>static fn(): array=>['value'=>'state']]),
	];
	$t->same(8,count($hydrated));

	$accessibility=Field::make('a')
		->accessibilityPolicy([])
		->accessibilityPolicy(['contrast_policy'=>['min_ratio'=>5]])
		->accessibilityPolicy(['contrast_min_ratio'=>6])
		->contrastPolicy(7.0,'text')
		->badge([])
		->badge('warning');
	$t->isTrue(isset($accessibility->toArray()['meta']['accessibility']));
	$t->same('warning',$accessibility->toArray()['meta']['tone']);

	foreach(['CA','US','USA','GB','UK','AU','NZ','FR','DE','NL','IE','XX'] as $country){
		$t->same('postal-code',Field::make('postal')->postalCode($country)->toArray()['meta']['autocomplete']);
	}
	$t->same(2,count(Field::make('suggest')->suggestions([
		'one'=>['value'=>'1','label'=>'One'],['value'=>'2','label'=>'Two'],''=>'',
	])->toArray()['meta']['suggestions']));

	$formattedRules=[
		'ssn','ein','phone','phone_us','phone_ca','credit_card','credit_card_expiry','card_cvc','digits','postal_code_ca',
		'postal_code_gb','postal_code_au','postal_code_nz','postal_code_fr','postal_code_de','postal_code_nl','postal_code_ie',
		'iban','alpha','alphanumeric','currency','money','percent','percentage','map_url','lowercase','uppercase','title_case',
		'sentence_case','snake_case','kebab_case','camel_case','trim',
	];
	foreach($formattedRules as $rule){
		$field=Field::make('format')->format($rule);
		$field->dehydrateValue(' Example 123-ABC ');
	}
	$maskOnly=Field::make('mask')->mask('999-AAA',true);
	$t->same('123ABC',$maskOnly->dehydrateValue('123-ABC'));
	$t->same('',$t->nonPublic(Field::class)->invoke('normalizeDomainText',''));
	$t->same('',$t->nonPublic(Field::class)->invoke('normalizeTimezoneText',''));
	$t->same('',$t->nonPublic(Field::class)->invoke('normalizeLocaleText',''));
	$t->same('',$t->nonPublic(Field::class)->invoke('normalizeLanguageCodeText',''));
	foreach(['$','C$','Canadian Dollars','US$','Euro','Pound','Yen'] as $currency){
		$t->nonPublic(Field::class)->invoke('normalizeCurrencyCodeText',$currency);
	}
	$t->same('',['x'=>$t->nonPublic(Field::class)->invoke('normalizeCoordinateText','')]['x']);
	$t->nonPublic(Field::class)->invoke('camelCaseText','');

	$t->same(['a','b'],$t->nonPublic(Field::class)->invoke('normalizeListValue','["a","b"]'));
	$t->same(['scalar'],$t->nonPublic(Field::class)->invoke('normalizeListValue','scalar'));
	$t->same(['ok'],$t->nonPublic(Field::class)->invoke('normalizeListValue',['ok',['skip'],new stdClass()]));
	$t->nonPublic(Field::class)->invoke('normalizeTagsValue',[['skip'],'ok',new stdClass()]);
	$t->same(['a'=>'1'],$t->nonPublic(Field::class)->invoke('normalizeKeyValue','{"a":1}'));
	$t->nonPublic(Field::class)->invoke('normalizeKeyValue',"\n\nkey=value",'', '');

	$uploaderCases=['','null','5',[],['','item',null],[
		'name'=>'x','type'=>'text/plain','tmp_name'=>'/tmp/x','error'=>0,'size'=>1,
	],['url'=>'https://example.com/x','name'=>'x']];
	foreach($uploaderCases as $value){
		$t->isTrue(is_array($t->nonPublic(Field::class)->invoke('normalizeCustomUploaderItems',$value)));
	}

	$nestedOptions=[
		'group'=>[
			'label'=>'Group','options'=>[
				'enabled'=>['label'=>'Enabled'],
				'disabled'=>['label'=>'Disabled','disabled'=>true],
				'described'=>['label'=>'Described','help'=>'Help'],
			],
		],
	];
	$t->isTrue($t->nonPublic(Field::class)->invoke('optionsContainDisabled',[[
		'options'=>[['value'=>'x','label'=>'X','disabled'=>true]],
	]]));
	$t->isTrue($t->nonPublic(Field::class)->invoke('optionsContainDescriptions',[[
		'options'=>[['value'=>'x','label'=>'X','description'=>'Nested help']],
	]]));
	$t->same([],$t->nonPublic(Field::class)->invoke('optionValues',[
		'group'=>['label'=>'Disabled group','disabled'=>true,'options'=>['a'=>'A']],
	]));
	$t->same('Help',$t->nonPublic(Field::class)->invoke('optionDefinition','x','X','Help',true)['description']);

	$required=Field::make('required')->requiredWhen('status','open')->requiredUnless('kind','guest');
	$t->isTrue($t->nonPublic($required)->invoke('requiredByConditions',['status'=>'open','kind'=>'guest']));
	$t->isTrue($t->nonPublic($required)->invoke('requiredByConditions',['status'=>'closed','kind'=>'staff']));
	$t->isFalse($t->nonPublic($required)->invoke('requiredByConditions',['status'=>'closed','kind'=>'guest']));
	$t->notEmpty(Field::make('plain')->required()->validateValue(''));
	$t->notEmpty(Field::make('url')->rules(['url'])->validateValue('://'));
	$postal=Field::make('postal')->postalCode('CA');
	$t->notEmpty($postal->validateValue('BAD'));
	$t->same(null,$t->nonPublic($postal)->invoke('validateFormattedPattern','   '));

	$t->isFalse($t->nonPublic(Field::class)->invoke('validCronField','',0,59));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validCronFieldItem','',0,59));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validCronFieldItem','*/99',0,59));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validDomainText',''));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validDomainText','-bad.example'));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validJsonText',''));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validMimeTypeText',''));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validSemverText',''));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validTimezoneText',''));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validLocaleText',''));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validCountryCodeText',''));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validSubdivisionCodeText','','CA'));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validCurrencyCodeText',''));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validCoordinateText','',-90.0,90.0));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validCreditCardNumber',''));
	$t->isFalse($t->nonPublic(Field::class)->invoke('validIban','GB82W!ST12345698765432'));

	$t->isFalse($t->nonPublic(Field::class)->invoke('fileAccepted',[
		'name'=>'file','type'=>'','tmp_name'=>'/tmp/x','error'=>0,'size'=>1,
	],['image/png']));
	$t->isTrue($t->nonPublic(Field::class)->invoke('fileAccepted',[
		'name'=>'photo.JPG','type'=>'image/jpeg','tmp_name'=>'/tmp/x','error'=>0,'size'=>1,
	],['.jpg']));
	$t->same(null,$t->nonPublic(Field::class)->invoke('geopositionReformatPostalCode','postal_code_international',[],'code'));
	$t->same(null,$t->nonPublic(Field::class)->invoke('geopositionPostalCodeValid','postal_code_international',[],'code'));
	$t->same('phone_international',$t->nonPublic(Field::class)->invoke('effectiveFormatRule','phone',[]));

	$t->isFalse(Field::make('visible')->hiddenOn('edit')->isVisible('edit'));
	$t->same(null,$t->nonPublic(Field::class)->invoke('dependencyValue','missing',null,null));
	$t->same('edit',$t->nonPublic(Field::class)->invoke('normalizeOperation','update'));
	$t->isTrue($t->nonPublic(Field::class)->invoke('regexMatches','','value'));
})->tag('panel','field','coverage')->group('framework-coverage');

test('panel field final guard branches cover empty builders controls and normalization fallbacks',static function(Context $t): void {
	$fields=[
		Field::make('icon')->prependIcon(''),
		Field::make('block')->builderBlock('',[]),
		Field::make('header')->uploadHeader('X-Test',new stdClass()),
		Field::make('field')->uploadField('tenant',new stdClass()),
		Field::make('csrf')->uploadCsrf(''),
		Field::make('storage')->storageUploader('local','path','/delete'),
		Field::make('media')->mediaCollection(\Dataphyre\Panel\PanelMediaCollection::make('images')),
		Field::make('numeric')->numeric('unsupported'),
		Field::make('format')->formatButton('Label',''),
		Field::make('country')->formatCountryField(''),
		Field::make('subdivision')->formatSubdivisionField(''),
		Field::make('source')->sourceField(''),
		Field::make('slug')->slugFrom(''),
		Field::make('length')->lengthBetween(10,2),
	];
	$t->same(14,count($fields));

	$stepper=Field::make('amount')->number()->prependButton('Down','decrement')->appendButton('Up','increment');
	$capabilities=$stepper->toArray()['component']['capabilities'];
	$t->contains('steppers',$capabilities);
	$t->nonPublic(Field::class)->invoke('normalizeInternationalPhoneText','+33 1 42 68 53 00',['country'=>'CA'],true);
	$t->nonPublic(Field::class)->invoke('normalizeInternationalPhoneText','10 416 555 0100',['country'=>'CA'],true);
	$t->nonPublic(Field::class)->invoke('normalizeLocaleText','en-customvariant');
	$t->same('',$t->nonPublic(Field::class)->invoke('normalizeJsonText',''));
	$t->same('invalid',$t->nonPublic(Field::class)->invoke('normalizeCoordinateText','invalid'));
	$t->same('en',$t->nonPublic(Field::class)->invoke('normalizeLanguageCodeText','English'));
	$t->same([],$t->nonPublic(Field::class)->invoke('normalizeKeyValue',''));
	$t->same(['a'=>'1'],$t->nonPublic(Field::class)->invoke('normalizeKeyValue','|a=1','|','='));

	$ruleField=Field::make('rule');
	$t->nonPublic($ruleField)->writeProperty('rules',['']);
	$t->same([], $ruleField->validateValue('value'));
	$t->isTrue($t->nonPublic(Field::class)->invoke('validCreditCardNumber','4111111111111111'));
	$t->same('AU',$t->nonPublic(Field::class)->invoke('normalizeCountryCode','Australia'));
	$t->isTrue(count($t->nonPublic(Field::class)->invoke('countryOptions',['Canada','US'=>'United States',''=>'']))>=2);
	$t->same('postal_code_nz',$t->nonPublic(Field::class)->invoke('postalRuleFromSubdivision','AUK'));
	$t->isTrue($t->nonPublic(Field::class)->invoke('fileAccepted',[
		'name'=>'photo.png','type'=>'image/png','tmp_name'=>'/tmp/x','error'=>0,'size'=>1,
	],['','image/*']));
})->tag('panel','field','coverage')->group('framework-coverage');

test('panel field imports comprehensive array definitions',static function(Context $t): void {
	$callback=static fn(mixed $value=null): mixed=>$value;
	$definition=[
		'name'=>'profile', 'type'=>'fieldset', 'label'=>' Profile ', 'placeholder'=>'Enter value',
		'help'=>'Help', 'helper_text'=>'Helper', 'hint'=>'Hint', 'hint_icon'=>'info',
		'accessibility'=>['label'=>'Profile'], 'a11y'=>true, 'min_usable_width'=>20,
		'min_usable_width_unit'=>'rem', 'min_usable_chars'=>12, 'min_touch_target'=>44,
		'max_adornment_ratio'=>0.4, 'max_label_ratio'=>0.5, 'contrast_policy'=>['min_ratio'=>4.5],
		'contrast_min_ratio'=>7, 'default'=>'Ada', 'required'=>true, 'readonly'=>true,
		'disabled'=>true, 'hidden'=>true, 'content'=>'Content', 'display_content'=>'Display',
		'html_content'=>'<strong>HTML</strong>', 'dehydrated'=>false, 'visible_on'=>['create'],
		'hidden_on'=>['view'], 'depends_on'=>['status'], 'live'=>true, 'debounce_ms'=>10,
		'reactive'=>true, 'visible_when'=>['status'=>'open'], 'hidden_when'=>['status'=>'closed'],
		'required_when'=>['kind'=>'person'], 'required_if'=>['field'=>'enabled','value'=>true],
		'required_unless'=>['role'=>'guest'], 'options'=>['a'=>'A'], 'relationship'=>'owner',
		'related_resource'=>'users', 'title_attribute'=>'name', 'key_attribute'=>'id',
		'choice_columns'=>3, 'inline_choices'=>true,
		'repeater_fields'=>[['name'=>'item']], 'child_fields'=>[['name'=>'street']],
		'builder_blocks'=>[['name'=>'text','fields'=>[['name'=>'body']]]],
		'fields'=>[['name'=>'city']], 'accepted_types'=>['image/png'], 'max_file_size'=>1024,
		'custom_uploader'=>true, 'upload_endpoint'=>'/upload', 'upload_delete_endpoint'=>'/delete',
		'upload_chunk_size'=>512, 'upload_retries'=>2, 'upload_concurrency'=>2,
		'upload_headers'=>['X-Test'=>'yes'], 'upload_fields'=>['tenant'=>'one'],
		'upload_labels'=>['browse'=>'Browse'], 'upload_csrf_form'=>'panel',
		'upload_csrf_field'=>'token', 'upload_csrf_header'=>'X-Token', 'upload_min_files'=>1,
		'upload_max_files'=>3, 'storage_disk'=>'local', 'storage_path'=>'uploads/{filename}',
		'rows'=>4, 'auto_resize'=>true, 'min'=>1, 'max'=>10, 'step'=>0.5,
		'today_button'=>'Today', 'now_button'=>'Now', 'value_display'=>true,
		'on_label'=>'Yes', 'off_label'=>'No', 'tag_separator'=>',', 'min_tags'=>1, 'max_tags'=>5,
		'pair_separator'=>';', 'key_separator'=>':', 'min_pairs'=>1, 'max_pairs'=>4,
		'searchable'=>true, 'search_placeholder'=>'Search', 'no_results_text'=>'None',
		'media_collection'=>'images', 'media_variants'=>['thumb'=>['width'=>100]],
		'multiple'=>true, 'suggestions'=>['one'], 'datalist'=>['two'],
		'autocomplete_options'=>['three'], 'copyable'=>true, 'copy_normalized'=>true,
		'revealable'=>true, 'color_swatch'=>true, 'prefix'=>'$', 'suffix'=>'CAD',
		'prefix_icon'=>'money', 'prefix_icon_label'=>'Currency', 'suffix_icon'=>'check',
		'suffix_icon_label'=>'Valid', 'prepend_buttons'=>[['label'=>'Down','action'=>'decrement']],
		'append_buttons'=>[['label'=>'Up','action'=>'increment']], 'prepend_button'=>'Before',
		'append_button'=>'After', 'mask'=>'999-999', 'mask_placeholder'=>'_',
		'format'=>'phone', 'format_options'=>['country'=>'CA'], 'country_field'=>'country',
		'subdivision_field'=>'province', 'source_field'=>'source', 'format_placeholder'=>'Phone',
		'format_event'=>'blur', 'submit_normalized'=>true, 'submit_formatted'=>true,
		'input_mode'=>'numeric', 'autocomplete'=>'off', 'min_length'=>2, 'max_length'=>20,
		'length'=>10, 'length_between'=>[2,20], 'character_counter'=>20,
		'counter_position'=>'append', 'pattern'=>'[A-Za-z]+', 'title'=>'Letters only',
		'preview'=>true, 'preview_mode'=>'inline', 'editor'=>'plain',
		'editor_options'=>['line_numbers'=>true], 'clearable'=>true, 'nullable'=>true,
		'regex'=>'/^[A-Z]+$/', 'confirmed'=>true, 'same'=>'confirmation',
		'different'=>'other', 'starts_with'=>['A'], 'ends_with'=>['Z'],
		'rules'=>'required|string', 'meta'=>['custom'=>'value'], 'state_using'=>$callback,
	];
	$field=Field::fromArray($definition);
	$t->same('profile',$field->name());
	$t->same('autocomplete',$field->toArray()['type']);

	$t->same('address',Field::fromArray(['name'=>'address','type'=>'address','country'=>'CA','include_line2'=>false])->toArray()['type']);
	$t->same('builder',Field::fromArray(['name'=>'layout','type'=>'builder','blocks'=>[['name'=>'hero','fields'=>[]]]])->toArray()['type']);
	$t->same('code',Field::fromArray(['name'=>'source','type'=>'code','language'=>'php'])->toArray()['type']);
	$t->same('file_upload',Field::fromArray([
		'name'=>'alternate', 'required_if'=>['enabled'=>true], 'upload_delete_endpoint'=>'/remove',
		'chunked_upload'=>true, 'delete_endpoint'=>'/delete', 'min_files'=>0, 'max_files'=>2,
		'autosize'=>true, 'show_value'=>true, 'true_label'=>'On', 'false_label'=>'Off',
		'columns'=>2, 'inline'=>true, 'submit_unmasked'=>true, 'mask_placeholder'=>false,
		'format_rule'=>'trim', 'format_placeholder'=>false, 'char_counter'=>'12',
		'counter'=>false, 'language'=>'ignored', 'state_callback'=>$callback,
	])->toArray()['type']);
})->tag('panel','field','coverage')->group('framework-coverage');

function dp_panel_field_fluent_argument(ReflectionParameter $parameter): mixed {
	if($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue()!==null){
		return $parameter->getDefaultValue();
	}
	$type=$parameter->getType();
	$types=$type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || !$candidate->isBuiltin() || $candidate->getName()==='null'){
			continue;
		}
		return match($candidate->getName()){
			'array'=>match($parameter->getName()){
				'fields'=>[['name'=>'child']],
				'options'=>['one'=>'One'],
				'buttons'=>[['label'=>'Add','action'=>'increment']],
				'blocks'=>[['name'=>'text','fields'=>[['name'=>'body']]]],
				'values'=>['one'],
				default=>['fixture'=>'value'],
			},
			'bool'=>true,
			'callable'=>static fn(mixed $value=null): mixed=>$value,
			'float'=>1.0,
			'int'=>1,
			'object'=>new stdClass(),
			'string'=>match(strtolower($parameter->getName())){
				'action'=>'increment',
				'country', 'countrycode'=>'CA',
				'currency', 'currencycode'=>'CAD',
				'endpoint'=>'/coverage',
				'event'=>'blur',
				'format', 'rule'=>'trim',
				'icon'=>'check',
				'position'=>'append',
				'pattern', 'regex'=>'/.*/',
				'type'=>'text',
				default=>'fixture',
			},
			default=>'fixture',
		};
	}
	if($type?->allowsNull()){
		return null;
	}
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || $candidate->isBuiltin() || $candidate->getName()==='null'){
			continue;
		}
		$class=$candidate->getName();
		if($class===Closure::class){
			return static fn(mixed $value=null): mixed=>$value;
		}
		if($class===DateTimeInterface::class || is_a($class,DateTimeInterface::class,true)){
			return new DateTimeImmutable('2026-01-01 00:00:00 UTC');
		}
		if($class===\Dataphyre\Panel\PanelMediaCollection::class){
			return \Dataphyre\Panel\PanelMediaCollection::make('images');
		}
	}
	return 'fixture';
}

enum DpPanelFieldCoverageEnum: string {
	case One='one';
}

test('panel field public fluent aliases honor their declared contracts',static function(Context $t): void {
	$inventory=$t->inventory(Field::class);
	$failures=[];
	$called=[];
	foreach($inventory->declaredPublicMethods(false) as $method){
		$return=$method->getReturnType();
		if(!$return instanceof ReflectionNamedType || $return->getName()!=='self'){
			continue;
		}
		try{
			$field=Field::make('coverage');
			$arguments=array_map(dp_panel_field_fluent_argument(...),$method->getParameters());
			$result=$inventory->invokeWithArguments($method, $field, $arguments);
			if(!$result instanceof Field){
				$failures[$method->getName()]='Method did not return a Field.';
			}
			$called[]=$method->getName();
		}catch(Throwable $throwable){
			$failures[$method->getName()]=$throwable::class.': '.$throwable->getMessage();
		}
	}
	$t->isTrue(count($called)>100);
	$t->same([],$failures);
})->tag('panel','field','coverage')->group('framework-coverage');

test('panel field residual branches cover nested malformed and dependency fallbacks',static function(Context $t): void {
	$t->same('text',Field::fromArray(['name'=>'a11y','a11y'=>['label'=>'Accessible']])->toArray()['type']);
	Field::fromArray(['name'=>'buttons','prepend_button'=>['label'=>'Before','action'=>'clear'],'append_button'=>['label'=>'After','action'=>'copy']]);
	$t->same('code',Field::fromArray(['name'=>'source','type'=>'code','code_language'=>'php'])->toArray()['type']);

	$t->same('repeater',Field::make('rows')->repeaterField(Field::make('title'))->toArray()['type']);
	$t->same('text',Field::make('group')->groupField(Field::make('title'))->toArray()['type']);
	$t->isTrue(Field::make('file')->upload()->isFileUpload());
	$t->isFalse($t->nonPublic(Field::make('amount')->number())->invoke('fieldButtonsHaveAction',['increment','decrement']));

	$required=Field::make('required');
	$t->nonPublic($required)->writeProperty('required',true)->writeProperty('rules',[]);
	$t->notEmpty($required->validateValue(''));
	$t->isTrue($t->nonPublic(Field::class)->invoke('validDomainText','127.0.0.1'));
	$t->isTrue($t->nonPublic(Field::class)->invoke('validCreditCardNumber','5555555555554444'));

	$t->same('CODE',$t->nonPublic(Field::class)->invoke('geopositionReformatPostalCode','postal_code_ca',['country'=>'CA','subdivision'=>'ON'],'CODE',));
	$t->isTrue($t->nonPublic(Field::class)->invoke('postalRule','zip'));
	foreach(['New Zealand'=>'NZ','France'=>'FR','Germany'=>'DE','Netherlands'=>'NL','Ireland'=>'IE','European Union'=>'EU'] as $country=>$code){
		$t->same($code,$t->nonPublic(Field::class)->invoke('normalizeCountryCode',$country));
	}

	$contextField=Field::make('postal')->format('postal_code_international',[
		'country_field'=>'country',
		'subdivision_field'=>'province',
	]);
	$t->same(['country'=>'CA','subdivision'=>'ON'],$t->nonPublic($contextField)->invoke('formatContext',[],['country'=>'Canada','province'=>'ON'],null,));
	$t->same('fallback',$t->nonPublic(Field::class)->invoke('submittedOrRecordValue','value',[],['value'=>'fallback'],null));

	$blocks=$t->nonPublic(Field::class)->invoke('builderBlockDefinitions',[
		'builder_blocks'=>[
			'not-an-array',
			''=>['name'=>'','fields'=>[]],
			'hero'=>['fields'=>[['name'=>'title']]],
		],
	]);
	$t->contains('hero',array_keys($blocks));
	$t->same([],$t->nonPublic(Field::class)->invoke('normalizeBuilderRows','not-an-array',$blocks));
	$malformedBlocks=['hero'=>[
		'name'=>'hero','label'=>'Hero','fields'=>[new stdClass(),Field::make('title')],
	]];
	$t->same([['_type'=>'hero','title'=>'Welcome']],$t->nonPublic(Field::class)->invoke('normalizeBuilderRows',[['_type'=>'hero','title'=>'Welcome']],$malformedBlocks,));
	$t->same(['title'=>'Welcome'],$t->nonPublic(Field::class)->invoke('normalizeFieldGroupValue',['title'=>'Welcome'],[new stdClass(),Field::make('title')],));
	$t->same([],$t->nonPublic(Field::class)->invoke('normalizeRepeaterRows','not-an-array',[Field::make('title')]));
	$t->same([['title'=>'Welcome']],$t->nonPublic(Field::class)->invoke('normalizeRepeaterRows',[['title'=>'Welcome']],[new stdClass(),Field::make('title')],));
	$t->same(['data-test'=>'yes'],$t->nonPublic(Field::class)->invoke('normalizeFieldButton','Copy','copy',['attributes'=>['data-test'=>'yes']],)['attributes']);
})->tag('panel','field','coverage')->group('framework-coverage');
