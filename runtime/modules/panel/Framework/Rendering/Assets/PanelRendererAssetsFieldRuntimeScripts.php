<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits the field controls, formatting, and enhancement runtime.
 */
trait PanelRendererAssetsFieldRuntimeScripts {
	/**
	 * Returns this controller module for the public Panel runtime bundle.
	 */
	private static function fieldRuntimeScript(): string {
		return <<<'JS'
function dpPanelRuntime(){
	var root=window.DataphyrePanel||{};
	root.fieldFormatters=root.fieldFormatters||{};
	root.fieldButtons=root.fieldButtons||{};
	root.registerFieldFormatter=function(name,formatter){
		name=String(name||"").toLowerCase();
		if(name&&formatter){root.fieldFormatters[name]=formatter;}
		return root;
	};
	root.registerFieldButton=function(name,handler){
		name=String(name||"").toLowerCase();
		if(name&&typeof handler==="function"){root.fieldButtons[name]=handler;}
		return root;
	};
	window.DataphyrePanel=root;
	return root;
}
dpPanelRuntime();
/**
 * Parses field formatting options from an input dataset.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|null} input Form control.
 * @returns {Object<string, *>} Formatting options.
 */
function dpPanelParseFormatOptions(input){
	if(!input||!input.dataset.dpPanelFormatOptions){return {};}
	try{return JSON.parse(input.dataset.dpPanelFormatOptions)||{};}catch(error){return {};}
}
/**
 * Normalizes common country names into ISO-like country codes.
 *
 * @param {string} value Country label or code.
 * @returns {string} Uppercase country code or normalized raw value.
 */
function dpPanelNormalizeCountry(value){
	value=String(value||"").trim().toUpperCase();
	if(value==="CANADA"){return "CA";}
	if(value==="UNITED STATES"||value==="UNITED STATES OF AMERICA"||value==="USA"){return "US";}
	if(value==="UNITED KINGDOM"||value==="GREAT BRITAIN"||value==="BRITAIN"||value==="UK"){return "GB";}
	if(value==="AUSTRALIA"){return "AU";}
	if(value==="NEW ZEALAND"||value==="AOTEAROA"){return "NZ";}
	if(value==="FRANCE"){return "FR";}
	if(value==="GERMANY"){return "DE";}
	if(value==="NETHERLANDS"){return "NL";}
	if(value==="IRELAND"){return "IE";}
	if(value==="EUROPEAN UNION"){return "EU";}
	return value;
}
/**
 * Normalizes common subdivision names into province, state, or region codes.
 *
 * @param {string} value Subdivision label or code.
 * @returns {string} Uppercase subdivision code or normalized raw value.
 */
function dpPanelNormalizeSubdivision(value){
	value=String(value||"").trim().toUpperCase();
	var map={
		"ALBERTA":"AB","BRITISH COLUMBIA":"BC","MANITOBA":"MB","NEW BRUNSWICK":"NB","NEWFOUNDLAND AND LABRADOR":"NL","NEWFOUNDLAND":"NL","NOVA SCOTIA":"NS","NORTHWEST TERRITORIES":"NT","NUNAVUT":"NU","ONTARIO":"ON","PRINCE EDWARD ISLAND":"PE","QUEBEC":"QC","QUÉBEC":"QC","SASKATCHEWAN":"SK","YUKON":"YT"
		,"NEW YORK":"NY","CALIFORNIA":"CA","TEXAS":"TX","WASHINGTON":"WA"
		,"FRANCE":"FR","GERMANY":"DE","NETHERLANDS":"NL","IRELAND":"IE"
		,"NEW SOUTH WALES":"NSW","VICTORIA":"VIC","QUEENSLAND":"QLD","SOUTH AUSTRALIA":"SA","WESTERN AUSTRALIA":"WA","TASMANIA":"TAS","AUSTRALIAN CAPITAL TERRITORY":"ACT","NORTHERN TERRITORY":"NT"
		,"AUCKLAND":"AUK","WELLINGTON":"WGN","CANTERBURY":"CAN","OTAGO":"OTA"
	};
	return map[value]||value;
}
/**
 * Infers a postal-code formatting rule from a subdivision code.
 *
 * @param {string} subdivision Province, state, region, or country-like subdivision.
 * @returns {string} Postal formatting rule name or an empty string.
 */
function dpPanelPostalRuleFromSubdivision(subdivision){
	subdivision=dpPanelNormalizeSubdivision(subdivision);
	if(!subdivision){return "";}
	if(["AB","BC","MB","NB","NS","NU","ON","PE","QC","SK","YT"].indexOf(subdivision)!==-1){return "postal_code_ca";}
	if(["AL","AK","AZ","AR","CA","CO","CT","FL","GA","HI","ID","IL","IN","IA","KS","KY","LA","ME","MD","MA","MI","MN","MS","MO","MT","NE","NV","NH","NJ","NM","NY","NC","ND","OH","OK","OR","PA","RI","SC","SD","TN","TX","UT","VT","VA","WV","WI","WY","DC"].indexOf(subdivision)!==-1){return "zip_code_us";}
	if(["ACT","NSW","QLD","SA","TAS","VIC"].indexOf(subdivision)!==-1){return "postal_code_au";}
	if(["AUK","WGN","CAN","OTA"].indexOf(subdivision)!==-1){return "postal_code_nz";}
	var map={FR:"postal_code_fr",IE:"postal_code_ie"};
	return map[subdivision]||"";
}
/**
 * Extracts the base field name from a possibly scoped form control name.
 *
 * @param {string} name Form control name.
 * @returns {string} Base field name.
 */
function dpPanelFieldBaseName(name){
	name=String(name||"").replace(/\[\]$/,"");
	var match=name.match(/\[([^\]]+)\]$/);
	return match ? match[1] : name;
}
/**
 * Resolves a dependency field name in the same repeatable scope as an input.
 *
 * @param {Element|null} input Current formatted input.
 * @param {string} name Dependency field name.
 * @returns {string} Scoped field name when applicable.
 */
function dpPanelScopedFieldName(input,name){
	var current=input&&input.getAttribute ? String(input.getAttribute("name")||"") : "";
	name=String(name||"").replace(/\[\]$/,"");
	if(!current||!name||name.indexOf("[")!==-1){return name;}
	if(/\[[^\]]+\]$/.test(current)){
		return current.replace(/\[[^\]]+\]$/,"["+name+"]");
	}
	return name;
}
/**
 * Checks whether a candidate source control matches a dependency field.
 *
 * @param {Element|null} input Current formatted input.
 * @param {Element|null} source Candidate source control.
 * @param {string} name Dependency field name.
 * @returns {boolean} Whether the source is the dependency for this input.
 */
function dpPanelFieldSourceMatches(input,source,name){
	if(!source||!source.getAttribute){return false;}
	var sourceName=String(source.getAttribute("name")||"").replace(/\[\]$/,"");
	name=String(name||"").replace(/\[\]$/,"");
	if(!sourceName||!name){return false;}
	if(sourceName===name||sourceName===dpPanelScopedFieldName(input,name)){return true;}
	var inputRow=input&&input.closest ? input.closest("[data-dp-panel-repeater-row]") : null;
	var sourceRow=source.closest ? source.closest("[data-dp-panel-repeater-row]") : null;
	return inputRow&&sourceRow&&inputRow===sourceRow&&dpPanelFieldBaseName(sourceName)===name;
}
/**
 * Reads a dependency source value for formatting context.
 *
 * Search prefers controls in the same repeater row, then falls back to the form.
 * Checked radio/checkbox values win, then non-hidden values, then hidden values.
 *
 * @param {HTMLFormElement|null} form Owning form.
 * @param {string} name Dependency field name.
 * @param {Element|null} input Current formatted input.
 * @returns {string} Source value or an empty string.
 */
function dpPanelFormatSourceValue(form,name,input){
	if(!form||!name){return "";}
	var names=[String(name)];
	var scoped=dpPanelScopedFieldName(input,name);
	if(scoped&&names.indexOf(scoped)===-1){names.unshift(scoped);}
	var row=input&&input.closest ? input.closest("[data-dp-panel-repeater-row]") : null;
	var scopes=row ? [row, form] : [form];
	var controls=[];
	for(var scopeIndex=0;scopeIndex<scopes.length;scopeIndex++){
		for(var nameIndex=0;nameIndex<names.length;nameIndex++){
			var sourceName=names[nameIndex];
			var selector='[name="'+dpPanelCssEscape(sourceName)+'"],[name="'+dpPanelCssEscape(sourceName)+'[]"]';
			controls=Array.prototype.slice.call(scopes[scopeIndex].querySelectorAll(selector));
			if(controls.length){scopeIndex=scopes.length;break;}
		}
	}
	for(var index=0;index<controls.length;index++){
		var control=controls[index];
		if(control.disabled){continue;}
		var type=String(control.type||"").toLowerCase();
		if((type==="radio"||type==="checkbox")&&control.checked){return control.value||"";}
	}
	for(var valueIndex=0;valueIndex<controls.length;valueIndex++){
		var valueControl=controls[valueIndex];
		if(valueControl.disabled){continue;}
		var valueType=String(valueControl.type||"").toLowerCase();
		if(valueType==="radio"||valueType==="checkbox"||valueType==="hidden"){continue;}
		return valueControl.value||"";
	}
	for(var hiddenIndex=0;hiddenIndex<controls.length;hiddenIndex++){
		var hidden=controls[hiddenIndex];
		if(hidden.disabled){continue;}
		if(String(hidden.type||"").toLowerCase()!=="hidden"){continue;}
		return hidden.value||"";
	}
	return "";
}
/**
 * Builds country and subdivision context for locale-aware field formatting.
 *
 * @param {Element|null} input Current formatted input.
 * @param {Object<string, *>} options Formatting options.
 * @returns {{country: string, subdivision: string}} Normalized formatting context.
 */
function dpPanelFormatContext(input,options){
	options=options||dpPanelParseFormatOptions(input);
	var form=input&&input.form ? input.form : input&&input.closest&&input.closest("form");
	var country=options.country||options.country_code||"";
	var subdivision=options.subdivision||options.region||options.state||options.province||"";
	if(!country&&options.country_field){country=dpPanelFormatSourceValue(form,String(options.country_field),input);}
	if(!subdivision&&options.subdivision_field){subdivision=dpPanelFormatSourceValue(form,String(options.subdivision_field),input);}
	return {
		country:dpPanelNormalizeCountry(country),
		subdivision:dpPanelNormalizeSubdivision(subdivision)
	};
}
/**
 * Resolves aliases and context-sensitive rules to the effective format rule.
 *
 * Postal and phone rules are refined using country/subdivision context so one
 * generic field declaration can behave correctly in repeated address forms.
 *
 * @param {string} rule Requested format rule.
 * @param {Element|null} input Current formatted input.
 * @param {Object<string, *>} options Formatting options.
 * @returns {string} Effective rule name.
 */
function dpPanelEffectiveFormatRule(rule,input,options){
	rule=String(rule||"").toLowerCase();
	var context=dpPanelFormatContext(input,options);
	if(rule==="postal_code"||rule==="postal"||rule==="zip_code_us"||rule==="postal_code_us"||rule==="zip"){
		var subdivisionRule=dpPanelPostalRuleFromSubdivision(context.subdivision);
		if(context.country==="CA"){return "postal_code_ca";}
		if(context.country==="GB"){return "postal_code_gb";}
		if(context.country==="AU"){return "postal_code_au";}
		if(context.country==="NZ"){return "postal_code_nz";}
		if(context.country==="FR"){return "postal_code_fr";}
		if(context.country==="DE"){return "postal_code_de";}
		if(context.country==="NL"){return "postal_code_nl";}
		if(context.country==="IE"){return "postal_code_ie";}
		if(context.country==="EU"){
			if(context.subdivision==="FR"){return "postal_code_fr";}
			if(context.subdivision==="DE"){return "postal_code_de";}
			if(context.subdivision==="NL"){return "postal_code_nl";}
			if(context.subdivision==="IE"){return "postal_code_ie";}
			return subdivisionRule||"postal_code_international";
		}
		if(context.country==="US"){return "zip_code_us";}
		if(context.country===""){return subdivisionRule||"zip_code_us";}
		return subdivisionRule||"postal_code_international";
	}
	if(rule==="phone"){
		return "phone_international";
	}
	if(rule==="phone_us"||rule==="phone_ca"){
		if(context.country&&context.country!=="US"&&context.country!=="CA"){return "phone_international";}
		return rule;
	}
	return rule;
}
/**
 * Returns a placeholder example for a format rule and context.
 *
 * @param {string} rule Format rule name.
 * @param {Element|null} input Current formatted input.
 * @param {Object<string, *>} options Formatting options.
 * @returns {string} Placeholder text.
 */
function dpPanelFormatPlaceholder(rule,input,options){
	rule=dpPanelEffectiveFormatRule(rule,input,options);
	var context=dpPanelFormatContext(input,options);
	if(rule==="phone"||rule==="phone_us"||rule==="phone_ca"){return "(000) 000-0000";}
	if(rule==="phone_international"){return dpPanelInternationalPhonePlaceholder(context);}
	if(rule==="postal_code_ca"||rule==="canadian_postal_code"){return dpPanelCanadianPostalPlaceholder(context.subdivision);}
	if(rule==="postal_code_gb"||rule==="uk_postcode"){return "SW1A 1AA";}
	if(rule==="postal_code_au"||rule==="australian_postcode"){return dpPanelAustralianPostcodePlaceholder(context.subdivision);}
	if(rule==="postal_code_nz"||rule==="new_zealand_postcode"){return dpPanelNewZealandPostcodePlaceholder(context.subdivision);}
	if(rule==="postal_code_fr"||rule==="french_postcode"){return "75001";}
	if(rule==="postal_code_de"||rule==="german_postcode"){return "10115";}
	if(rule==="postal_code_nl"||rule==="dutch_postcode"){return "1012 AB";}
	if(rule==="postal_code_ie"||rule==="eircode"){return "D02 X285";}
	if(rule==="zip_code_us"||rule==="postal_code_us"||rule==="zip"){return dpPanelUsZipPlaceholder(context.subdivision);}
	if(rule==="postal_code_international"){return "Postal code";}
	if(rule==="credit_card"||rule==="card"){return "0000 0000 0000 0000";}
	if(rule==="credit_card_expiry"||rule==="card_expiry"){return "MM/YY";}
	if(rule==="card_cvc"||rule==="cvc"||rule==="cvv"){return "000";}
	if(rule==="iban"){return "GB82 WEST 1234 5698 7654 32";}
	if(rule==="domain"||rule==="hostname"){return "example.com";}
	if(rule==="map_url"||rule==="maps_url"){return "https://www.google.com/maps?q=45.501689,-73.567256";}
	if(rule==="timezone"||rule==="time_zone"){return "America/Toronto";}
	if(rule==="locale"||rule==="language_tag"){return "en-CA";}
	if(rule==="json"||rule==="json_text"){return "{\"key\":\"value\"}";}
	if(rule==="mime_type"||rule==="content_type"){return "application/json";}
	if(rule==="semver"||rule==="semantic_version"){return "1.2.3";}
	if(rule==="cron_expression"||rule==="cron"){return "0 9 * * mon-fri";}
	if(rule==="language_code"||rule==="iso_language"){return "en";}
	if(rule==="country_code"||rule==="iso_country"){return "CA";}
	if(rule==="subdivision_code"||rule==="region_code"){return context.country==="US" ? "NY" : "QC";}
	if(rule==="currency_code"||rule==="iso_currency"){return "CAD";}
	if(rule==="ip_address"||rule==="ip"){return "192.0.2.10";}
	if(rule==="ipv4"){return "192.0.2.10";}
	if(rule==="ipv6"){return "2001:db8::1";}
	if(rule==="mac_address"||rule==="mac"){return "00:1A:2B:3C:4D:5E";}
	if(rule==="uuid"){return "550e8400-e29b-41d4-a716-446655440000";}
	if(rule==="ulid"){return "01ARZ3NDEKTSV4RRFFQ69G5FAV";}
	if(rule==="hex_color"||rule==="color_hex"){return "#3366cc";}
	if(rule==="latitude"){return "45.501689";}
	if(rule==="longitude"){return "-73.567256";}
	if(rule==="coordinates"||rule==="lat_lng"||rule==="lng_lat"){return "45.501689,-73.567256";}
	return "";
}
/**
 * Resolves the international calling code for a formatting context.
 *
 * @param {Object<string, *>} context Country/subdivision context.
 * @returns {string} Numeric country calling code without plus sign.
 */
function dpPanelInternationalPhoneCode(context){
	context=context||{};
	var country=dpPanelNormalizeCountry(context.country||"");
	var countryMap={US:"1",CA:"1",GB:"44",AU:"61",NZ:"64",FR:"33",DE:"49",NL:"31",IE:"353"};
	if(countryMap[country]){return countryMap[country];}
	var subdivision=dpPanelNormalizeSubdivision(context.subdivision||"");
	var map={FR:"33",DE:"49",NL:"31",IE:"353"};
	return map[subdivision]||"";
}
/**
 * Returns an international phone placeholder for a context.
 *
 * @param {Object<string, *>} context Country/subdivision context.
 * @returns {string} Example phone number.
 */
function dpPanelInternationalPhonePlaceholder(context){
	var code=dpPanelInternationalPhoneCode(context);
	return code ? "+"+code+" 0000 0000" : "+1 000 000 0000";
}
/**
 * Returns the validation pattern for international phone display values.
 *
 * @param {Object<string, *>} context Country/subdivision context.
 * @returns {string} Regex pattern fragment.
 */
function dpPanelInternationalPhonePattern(context){
	return "\\+[0-9]{1,3}[0-9 \\-]{5,18}";
}
/**
 * Normalizes an international phone value to digits with optional context prefix.
 *
 * @param {string} value Raw phone value.
 * @param {Element|null} input Current formatted input.
 * @returns {string} Digits-only normalized phone value.
 */
function dpPanelNormalizeInternationalPhoneValue(value,input){
	value=String(value||"");
	var explicitPlus=value.trim().charAt(0)==="+";
	var digits=value.replace(/\D+/g,"").slice(0,15);
	var options=input&&input.dataset ? dpPanelParseFormatOptions(input) : {};
	var prefix=dpPanelInternationalPhoneCode(input ? dpPanelFormatContext(input,options) : options).replace(/\D+/g,"");
	if(prefix&&digits!==""&&digits.indexOf(prefix)!==0){
		if(explicitPlus){return digits;}
		if(digits.length>1&&digits.charAt(0)==="0"){
			return (prefix+digits.slice(1)).slice(0,15);
		}
		return (prefix+digits).slice(0,15);
	}
	if(prefix&&digits.indexOf(prefix+"0")===0){
		return (prefix+digits.slice(prefix.length+1)).slice(0,15);
	}
	return digits;
}
/**
 * Returns the Canadian postal-code leading-letter pattern for a province.
 *
 * @param {string} subdivision Province or territory.
 * @returns {string} Regex fragment for first postal-code character.
 */
function dpPanelCanadianPostalPrefixPattern(subdivision){
	subdivision=dpPanelNormalizeSubdivision(subdivision);
	var map={
		"NL":"A","NS":"B","PE":"C","NB":"E","QC":"[GHJ]","ON":"[KLMNP]","MB":"R","SK":"S","AB":"T","BC":"V","NT":"X","NU":"X","YT":"Y"
	};
	return map[subdivision]||"[ABCEGHJKLMNPRSTVXY]";
}
/**
 * Returns a Canadian postal-code placeholder for a province.
 *
 * @param {string} subdivision Province or territory.
 * @returns {string} Example postal code.
 */
function dpPanelCanadianPostalPlaceholder(subdivision){
	subdivision=dpPanelNormalizeSubdivision(subdivision);
	var map={NL:"A",NS:"B",PE:"C",NB:"E",QC:"H",ON:"K",MB:"R",SK:"S",AB:"T",BC:"V",NT:"X",NU:"X",YT:"Y"};
	return (map[subdivision]||"A")+"0A 0A0";
}
/**
 * Returns a US ZIP prefix pattern for known state ranges.
 *
 * @param {string} subdivision State or district code.
 * @returns {string} Regex fragment for first ZIP digits.
 */
function dpPanelUsZipPrefixPattern(subdivision){
	subdivision=dpPanelNormalizeSubdivision(subdivision);
	var map={
		"NY":"(00[5-9]|0[1-9][0-9]|1[0-4][0-9])",
		"CA":"(9[0-5][0-9]|96[0-1])",
		"TX":"(733|7[5-9][0-9]|885)",
		"WA":"(98[0-9]|99[0-4])"
	};
	return map[subdivision]||"[0-9]{3}";
}
/**
 * Returns a US ZIP+4 placeholder for a state.
 *
 * @param {string} subdivision State or district code.
 * @returns {string} Example ZIP+4 value.
 */
function dpPanelUsZipPlaceholder(subdivision){
	subdivision=dpPanelNormalizeSubdivision(subdivision);
	var map={NY:"10000-0000",CA:"90000-0000",TX:"75000-0000",WA:"98000-0000"};
	return map[subdivision]||"00000-0000";
}
/**
 * Returns an Australian postcode prefix pattern for a state or territory.
 *
 * @param {string} subdivision State or territory code.
 * @returns {string} Regex fragment for first postcode digit.
 */
function dpPanelAustralianPostcodePrefixPattern(subdivision){
	subdivision=dpPanelNormalizeSubdivision(subdivision);
	var map={NSW:"[12]",ACT:"2",VIC:"[38]",QLD:"4",SA:"5",WA:"6",TAS:"7",NT:"0"};
	return map[subdivision]||"[0-9]";
}
/**
 * Returns an Australian postcode placeholder for a state or territory.
 *
 * @param {string} subdivision State or territory code.
 * @returns {string} Example postcode.
 */
function dpPanelAustralianPostcodePlaceholder(subdivision){
	subdivision=dpPanelNormalizeSubdivision(subdivision);
	var map={NSW:"2000",ACT:"2600",VIC:"3000",QLD:"4000",SA:"5000",WA:"6000",TAS:"7000",NT:"0800"};
	return map[subdivision]||"0000";
}
/**
 * Returns a New Zealand postcode prefix pattern for a region.
 *
 * @param {string} subdivision Region code.
 * @returns {string} Regex fragment for first postcode digit.
 */
function dpPanelNewZealandPostcodePrefixPattern(subdivision){
	subdivision=dpPanelNormalizeSubdivision(subdivision);
	var map={AUK:"[01]",WGN:"[56]",CAN:"[78]",OTA:"9"};
	return map[subdivision]||"[0-9]";
}
/**
 * Returns a New Zealand postcode placeholder for a region.
 *
 * @param {string} subdivision Region code.
 * @returns {string} Example postcode.
 */
function dpPanelNewZealandPostcodePlaceholder(subdivision){
	subdivision=dpPanelNormalizeSubdivision(subdivision);
	var map={AUK:"1010",WGN:"6011",CAN:"8011",OTA:"9016"};
	return map[subdivision]||"0000";
}
/**
 * Returns an HTML pattern for a format rule and context.
 *
 * @param {string} rule Format rule name.
 * @param {Element|null} input Current formatted input.
 * @param {Object<string, *>} options Formatting options.
 * @returns {string} HTML pattern string.
 */
function dpPanelFormatPattern(rule,input,options){
	rule=dpPanelEffectiveFormatRule(rule,input,options);
	var context=dpPanelFormatContext(input,options);
	if(rule==="phone_us"||rule==="phone_ca"){return "(\\+[0-9]{1,3} )?\\([0-9]{3}\\) [0-9]{3}-[0-9]{4}";}
	if(rule==="phone_international"){return dpPanelInternationalPhonePattern(context);}
	if(rule==="postal_code_ca"||rule==="canadian_postal_code"){return dpPanelCanadianPostalPrefixPattern(context.subdivision)+"[0-9][A-Z] [0-9][A-Z][0-9]";}
	if(rule==="postal_code_gb"||rule==="uk_postcode"){return "[A-Z]{1,2}[0-9][0-9A-Z]? [0-9][A-Z]{2}";}
	if(rule==="postal_code_au"||rule==="australian_postcode"){return dpPanelAustralianPostcodePrefixPattern(context.subdivision)+"[0-9]{3}";}
	if(rule==="postal_code_nz"||rule==="new_zealand_postcode"){return dpPanelNewZealandPostcodePrefixPattern(context.subdivision)+"[0-9]{3}";}
	if(rule==="postal_code_fr"||rule==="french_postcode"||rule==="postal_code_de"||rule==="german_postcode"){return "[0-9]{5}";}
	if(rule==="postal_code_nl"||rule==="dutch_postcode"){return "[0-9]{4} [A-Z]{2}";}
	if(rule==="postal_code_ie"||rule==="eircode"){return "[A-Z0-9]{3} [A-Z0-9]{4}";}
	if(rule==="zip_code_us"||rule==="postal_code_us"||rule==="zip"){return dpPanelUsZipPrefixPattern(context.subdivision)+"[0-9]{2}(-[0-9]{4})?";}
	if(rule==="postal_code_international"){return "[0-9A-Z][0-9A-Z ]{2,16}";}
	if(rule==="credit_card"||rule==="card"){return "([0-9]{4} ){3}[0-9]{4}( [0-9]{1,3})?|([0-9]{4} ){3}[0-9]{1,3}";}
	if(rule==="credit_card_expiry"||rule==="card_expiry"){return "(0[1-9]|1[0-2])/[0-9]{2}";}
	if(rule==="card_cvc"||rule==="cvc"||rule==="cvv"){return "[0-9]{3,4}";}
	if(rule==="iban"){return "[A-Z]{2}[0-9]{2}( [0-9A-Z]{4}){2,7}( [0-9A-Z]{1,4})?";}
	if(rule==="domain"||rule==="hostname"){return "[A-Za-z0-9.-]{3,253}";}
	if(rule==="map_url"||rule==="maps_url"){return "https://.+";}
	if(rule==="timezone"||rule==="time_zone"){return "(UTC|GMT|[A-Za-z_]+/[A-Za-z0-9_+\\-]+(/[A-Za-z0-9_+\\-]+)?)";}
	if(rule==="locale"||rule==="language_tag"){return "[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?(-[0-9A-Za-z]{5,8})*";}
	if(rule==="mime_type"||rule==="content_type"){return "[a-z0-9][a-z0-9!#$&^_.+\\-]{0,126}/[a-z0-9][a-z0-9!#$&^_.+\\-]{0,126}(; .+)?";}
	if(rule==="semver"||rule==="semantic_version"){return "v?(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\\+[0-9A-Za-z.-]+)?";}
	if(rule==="cron_expression"||rule==="cron"){return "[0-9A-Za-z*/,\-]+ [0-9A-Za-z*/,\-]+ [0-9A-Za-z*/,\-]+ [0-9A-Za-z*/,\-]+ [0-9A-Za-z*/,\-]+";}
	if(rule==="language_code"||rule==="iso_language"){return "[a-z]{2}";}
	if(rule==="country_code"||rule==="iso_country"){return "[A-Z]{2}";}
	if(rule==="subdivision_code"||rule==="region_code"){return "[A-Z]{2,3}";}
	if(rule==="currency_code"||rule==="iso_currency"){return "[A-Z]{3}";}
	if(rule==="ip_address"||rule==="ip"){return "[0-9A-Fa-f:.]{3,45}";}
	if(rule==="ipv4"){return "[0-9.]{7,15}";}
	if(rule==="ipv6"){return "[0-9A-Fa-f:.]{2,45}";}
	if(rule==="mac_address"||rule==="mac"){return "[0-9A-F]{2}(:[0-9A-F]{2}){5}";}
	if(rule==="uuid"){return "[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}";}
	if(rule==="ulid"){return "[0-7][0-9A-HJKMNP-TV-Z]{25}";}
	if(rule==="hex_color"||rule==="color_hex"){return "#[0-9a-f]{6}";}
	if(rule==="latitude"||rule==="longitude"){return "-?[0-9]{1,3}(\\.[0-9]+)?";}
	if(rule==="coordinates"||rule==="lat_lng"||rule==="lng_lat"){return "-?[0-9]{1,3}(\\.[0-9]+)?,-?[0-9]{1,3}(\\.[0-9]+)?";}
	return "";
}
/**
 * Builds an explanatory title for a format rule.
 *
 * @param {string} rule Format rule name.
 * @param {Element|null} input Current formatted input.
 * @param {Object<string, *>} options Formatting options.
 * @param {string} placeholder Placeholder example.
 * @returns {string} Title text.
 */
function dpPanelFormatTitle(rule,input,options,placeholder){
	rule=dpPanelEffectiveFormatRule(rule,input,options);
	if(rule==="phone_international"){
		return "Expected international phone number with country code.";
	}
	return placeholder ? "Expected format: "+placeholder : "";
}
/**
 * Refreshes placeholder, pattern, title, and adornment for a formatted input.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|null} input Form control.
 * @returns {void}
 */
function dpPanelRefreshFormatLocale(input){
	if(!input||!input.dataset||!input.dataset.dpPanelFormat){return;}
	var options=dpPanelParseFormatOptions(input);
	var placeholder=dpPanelFormatPlaceholder(input.dataset.dpPanelFormat,input,options);
	var pattern=dpPanelFormatPattern(input.dataset.dpPanelFormat,input,options);
	if(placeholder&&input.dataset.dpPanelExplicitPlaceholder!=="1"){input.setAttribute("placeholder",placeholder);}
	if(pattern&&input.dataset.dpPanelExplicitPattern!=="1"){input.setAttribute("pattern",pattern);}
	if(pattern&&input.dataset.dpPanelExplicitTitle!=="1"){
		var title=dpPanelFormatTitle(input.dataset.dpPanelFormat,input,options,placeholder);
		if(title){input.setAttribute("title",title);}
	}
	if(input.dataset.dpPanelFormat==="zip_code_us"||input.dataset.dpPanelFormat==="postal_code"||input.dataset.dpPanelFormat==="postal"){
		input.setAttribute("inputmode",dpPanelEffectiveFormatRule(input.dataset.dpPanelFormat,input,options)==="zip_code_us" ? "numeric" : "text");
	}
	var shell=input.closest&&input.closest("[data-dp-panel-input-shell]");
	if(shell&&options.country_label!==false){
		var addon=shell.querySelector(".dp-panel-input-adornments-prepend .dp-panel-input-addon");
		var country=dpPanelFormatContext(input,options).country;
		if(addon&&country){addon.textContent=country;}
	}
	dpPanelRefreshPatternValidity(input);
}
/**
 * Normalizes the event that should trigger field formatting.
 *
 * @param {Element|null} input Form control.
 * @returns {string} One of `input`, `change`, `blur`, or `submit`.
 */
function dpPanelFormattingEvent(input){
	var event=String((input&&input.dataset.dpPanelFormatEvent)||"input").toLowerCase();
	return ["input","change","blur","submit"].indexOf(event)===-1 ? "input" : event;
}
/**
 * Counts meaningful alphanumeric characters before a caret position.
 *
 * @param {string} value Current value.
 * @param {number} end Caret end offset.
 * @returns {number} Meaningful character count.
 */
function dpPanelMeaningfulCount(value,end){
	return String(value||"").slice(0,Math.max(0,end||0)).replace(/[^0-9A-Za-z]/g,"").length;
}
/**
 * Finds the caret offset that preserves a meaningful character count.
 *
 * @param {string} value Formatted value.
 * @param {number} count Meaningful character count.
 * @returns {number} Caret offset.
 */
function dpPanelCaretFromMeaningfulCount(value,count){
	if(count<=0){return 0;}
	var seen=0;
	var text=String(value||"");
	for(var index=0;index<text.length;index++){
		if(/[0-9A-Za-z]/.test(text.charAt(index))){
			seen++;
			if(seen>=count){return index+1;}
		}
	}
	return text.length;
}
/**
 * Applies a simple alphanumeric mask to a value.
 *
 * Mask tokens are `9` for digits, `A` for uppercase letters, `a` for lowercase
 * letters, and `*` for alphanumerics. Literal mask characters are inserted as
 * progress markers after meaningful input begins.
 *
 * @param {string} value Raw value.
 * @param {string} mask Mask pattern.
 * @returns {string} Masked value.
 */
function dpPanelApplyMaskValue(value,mask){
	if(!mask){return value;}
	var raw=String(value||"").replace(/[^0-9A-Za-z]/g,"");
	var output="";
	var index=0;
	for(var i=0;i<mask.length;i++){
		var token=mask.charAt(i);
		var candidate="";
		while(index<raw.length){
			candidate=raw.charAt(index++);
			if(token==="9"&&/[0-9]/.test(candidate)){output+=candidate;break;}
			if(token==="A"&&/[A-Za-z]/.test(candidate)){output+=candidate.toUpperCase();break;}
			if(token==="a"&&/[A-Za-z]/.test(candidate)){output+=candidate.toLowerCase();break;}
			if(token==="*"&&/[0-9A-Za-z]/.test(candidate)){output+=candidate;break;}
			candidate="";
		}
		if(candidate===""){
			if(token==="9"||token==="A"||token==="a"||token==="*"){break;}
			if(raw.length>0||output!==""){output+=token;}
			continue;
		}
		if(index<raw.length&&i+1<mask.length){
			var next=mask.charAt(i+1);
			if(next!=="9"&&next!=="A"&&next!=="a"&&next!=="*"){output+=next;i++;}
		}
	}
	return output;
}
/**
 * Groups digit runs with a separator.
 *
 * @param {string} value Raw value.
 * @param {number[]} groups Digit group sizes.
 * @param {string} separator Group separator.
 * @returns {string} Grouped digit string.
 */
function dpPanelFormatDigits(value,groups,separator){
	var digits=String(value||"").replace(/\D/g,"");
	var parts=[];
	var index=0;
	groups.forEach(function(size){
		if(index>=digits.length){return;}
		parts.push(digits.slice(index,index+size));
		index+=size;
	});
	if(index<digits.length){parts.push(digits.slice(index));}
	return parts.filter(Boolean).join(separator||" ");
}
/**
 * Formats a currency-like decimal value without adding a currency symbol.
 *
 * @param {string} value Raw value.
 * @param {Object<string, *>} options Formatting options.
 * @returns {string} Normalized currency string.
 */
function dpPanelFormatCurrency(value,options){
	var raw=String(value||"").replace(/[^\d.\-]/g,"");
	var negative=raw.charAt(0)==="-";
	raw=raw.replace(/\-/g,"");
	var pieces=raw.split(".");
	var integer=(pieces.shift()||"").replace(/^0+(?=\d)/,"");
	var decimals=pieces.join("").replace(/\D/g,"");
	var precision=options.decimals===0 ? 0 : parseInt(options.decimals||"2",10);
	if(precision>=0){decimals=decimals.slice(0,precision);}
	integer=integer.replace(/\B(?=(\d{3})+(?!\d))/g,",");
	return (negative?"-":"")+(integer||"0")+(precision>0&&decimals!=="" ? "."+decimals : "");
}
/**
 * Formats an IBAN into four-character groups.
 *
 * @param {string} value Raw value.
 * @returns {string} Uppercase grouped IBAN.
 */
function dpPanelFormatIban(value){
	return String(value||"").replace(/[^0-9A-Za-z]/g,"").toUpperCase().replace(/(.{4})/g,"$1 ").trim();
}
/**
 * Formats a percent-like decimal value without adding a percent sign.
 *
 * @param {string} value Raw value.
 * @param {Object<string, *>} options Formatting options.
 * @returns {string} Normalized percent string.
 */
function dpPanelFormatPercent(value,options){
	var precision=parseInt((options&&options.decimals)||"2",10);
	var raw=String(value||"").replace(/[^\d.\-]/g,"");
	var pieces=raw.split(".");
	var integer=(pieces.shift()||"").replace(/^0+(?=\d)/,"");
	var decimals=pieces.join("").replace(/\D/g,"").slice(0,Math.max(0,precision));
	return (integer||"0")+(decimals!=="" ? "."+decimals : "");
}
/**
 * Formats a latitude or longitude number with bounded decimal precision.
 *
 * @param {string} value Raw value.
 * @param {Object<string, *>} options Formatting options.
 * @returns {string} Normalized coordinate value.
 */
function dpPanelFormatCoordinate(value,options){
	var precision=parseInt((options&&options.decimals)!==undefined ? options.decimals : "6",10);
	precision=Math.max(0,Math.min(10,isNaN(precision) ? 6 : precision));
	var raw=String(value||"").replace(/[^\d.\-]/g,"");
	if(raw===""||raw==="-"){return raw;}
	var negative=raw.charAt(0)==="-";
	raw=raw.replace(/\-/g,"");
	var pieces=raw.split(".");
	var integer=(pieces.shift()||"").replace(/^0+(?=\d)/,"");
	var decimals=pieces.join("").replace(/\D/g,"");
	var numeric=Number((negative ? "-" : "")+(integer||"0")+(decimals!=="" ? "."+decimals : ""));
	if(!isFinite(numeric)){return "";}
	var next=numeric.toFixed(precision);
	return next.replace(/\.0+$/,"").replace(/(\.\d*?)0+$/,"$1").replace(/\.$/,"");
}
/**
 * Formats a coordinate pair in latitude-longitude or longitude-latitude order.
 *
 * @param {string} value Raw pair value.
 * @param {Object<string, *>} options Formatting options.
 * @returns {string} Normalized coordinate pair.
 */
function dpPanelFormatCoordinatePair(value,options){
	var parts=String(value||"").trim().split(/\s*,\s*|\s+/).filter(Boolean);
	if(parts.length<2){return String(value||"").trim();}
	var first=dpPanelFormatCoordinate(parts[0],options||{});
	var second=dpPanelFormatCoordinate(parts[1],options||{});
	if(String((options&&options.order)||"").toLowerCase()==="lng_lat"){
		return second+","+first;
	}
	return first+","+second;
}
/**
 * Applies a named formatting rule to an input value.
 *
 * Custom registered formatters run first, then built-in formatters cover casing,
 * slugs, phone/postal formats, semantic identifiers, numeric-like values, and
 * text cleanup. The function is pure with respect to DOM state.
 *
 * @param {string} value Raw value.
 * @param {string} rule Format rule name.
 * @param {Object<string, *>} options Formatting options.
 * @returns {string} Formatted value.
 */
function dpPanelApplyFormatValue(value,rule,options){
	rule=String(rule||"").toLowerCase();
	var runtime=dpPanelRuntime();
	var custom=runtime.fieldFormatters[rule];
	if(custom){
		if(typeof custom==="function"){return custom(value,options||{});}
		if(typeof custom.format==="function"){return custom.format(value,options||{});}
	}
	if(rule==="uppercase"){return String(value||"").toUpperCase();}
	if(rule==="lowercase"){return String(value||"").toLowerCase();}
	if(rule==="title_case"){return String(value||"").trim().toLowerCase().replace(/\b[a-z]/g,function(match){return match.toUpperCase();});}
	if(rule==="sentence_case"){
		var sentence=String(value||"").trim().replace(/\s+/g," ").toLowerCase();
		return sentence ? sentence.charAt(0).toUpperCase()+sentence.slice(1) : "";
	}
	if(rule==="trim"){return String(value||"").trim();}
	if(rule==="slug"){
		var slug=String(value||"").toLowerCase().trim().replace(/[^a-z0-9-]+/g,"-").replace(/-+/g,"-").replace(/^-+/g,"");
		return options&&options.preserve_trailing===true ? slug : slug.replace(/-+$/g,"");
	}
	if(rule==="snake_case"||rule==="kebab_case"||rule==="camel_case"){
		var words=String(value||"").replace(/([a-z])([A-Z])/g,"$1 $2").trim().split(/[^0-9A-Za-z]+/).filter(Boolean).map(function(word){return word.toLowerCase();});
		if(rule==="camel_case"){
			return words.map(function(word,index){return index===0 ? word : word.charAt(0).toUpperCase()+word.slice(1);}).join("");
		}
		return words.join(rule==="snake_case" ? "_" : "-");
	}
	if(rule==="phone"||rule==="phone_us"||rule==="phone_ca"){
		var digits=String(value||"").replace(/\D/g,"").slice(0,11);
		if(digits.length<=3){return digits;}
		if(digits.length<=6){return "("+digits.slice(0,3)+") "+digits.slice(3);}
		if(digits.length<=10){return "("+digits.slice(0,3)+") "+digits.slice(3,6)+"-"+digits.slice(6);}
		return "+"+digits.slice(0,1)+" ("+digits.slice(1,4)+") "+digits.slice(4,7)+"-"+digits.slice(7);
	}
	if(rule==="phone_international"){
		var phone=String(value||"").replace(/[^\d+]/g,"");
		var plus=phone.charAt(0)==="+";
		phone=phone.replace(/\D/g,"").slice(0,15);
		var prefix=String((options&&options.phone_prefix)||"").replace(/\D/g,"");
		var formatExplicitInternational=function(digits){
			if(digits===""){return plus ? "+" : "";}
			return "+"+String(digits||"").replace(/(.{1,4})/g,"$1 ").trim();
		};
		if(prefix){
			if(plus&&phone===""){
				return "+";
			}
			if(plus&&phone!==""&&phone.indexOf(prefix)!==0){
				return formatExplicitInternational(phone);
			}
			var local=phone.indexOf(prefix)===0 ? phone.slice(prefix.length) : phone;
			if(local.length>1&&local.charAt(0)==="0"){local=local.slice(1);}
			local=local.slice(0, Math.max(0, 15-prefix.length));
			if(local===""){return plus || phone!=="" ? "+"+prefix : "";}
			var chunks=[];
			if(local.length<=2){
				chunks=[local];
			}
			else{
				chunks=[local.slice(0,2)];
				for(var offset=2;offset<local.length;offset+=4){
					chunks.push(local.slice(offset, offset+4));
				}
			}
			return "+"+prefix+" "+chunks.filter(Boolean).join(" ");
		}
		return formatExplicitInternational(phone);
	}
	if(rule==="postal_code_ca"||rule==="canadian_postal_code"){
		var text=String(value||"").replace(/[^0-9A-Za-z]/g,"").toUpperCase().slice(0,6);
		return text.length>3 ? text.slice(0,3)+" "+text.slice(3) : text;
	}
	if(rule==="postal_code_gb"||rule==="uk_postcode"){
		var postcode=String(value||"").replace(/[^0-9A-Za-z]/g,"").toUpperCase().slice(0,7);
		return postcode.length>3 ? postcode.slice(0,-3)+" "+postcode.slice(-3) : postcode;
	}
	if(rule==="postal_code_au"||rule==="australian_postcode"){return String(value||"").replace(/\D/g,"").slice(0,4);}
	if(rule==="postal_code_nz"||rule==="new_zealand_postcode"){return String(value||"").replace(/\D/g,"").slice(0,4);}
	if(rule==="postal_code_fr"||rule==="french_postcode"||rule==="postal_code_de"||rule==="german_postcode"){return String(value||"").replace(/\D/g,"").slice(0,5);}
	if(rule==="postal_code_nl"||rule==="dutch_postcode"){
		var nl=String(value||"").replace(/[^0-9A-Za-z]/g,"").toUpperCase().slice(0,6);
		return nl.length>4 ? nl.slice(0,4)+" "+nl.slice(4) : nl;
	}
	if(rule==="postal_code_ie"||rule==="eircode"){
		var ie=String(value||"").replace(/[^0-9A-Za-z]/g,"").toUpperCase().slice(0,7);
		return ie.length>3 ? ie.slice(0,3)+" "+ie.slice(3) : ie;
	}
	if(rule==="zip_code_us"||rule==="postal_code_us"||rule==="zip"){
		var zip=String(value||"").replace(/\D/g,"").slice(0,9);
		return zip.length>5 ? zip.slice(0,5)+"-"+zip.slice(5) : zip;
	}
	if(rule==="postal_code_international"){return String(value||"").replace(/[^0-9A-Za-z -]/g,"").toUpperCase().slice(0,17);}
	if(rule==="ssn"||rule==="social_security_number"){return dpPanelFormatDigits(value,[3,2,4],"-");}
	if(rule==="ein"||rule==="tax_id"){return dpPanelFormatDigits(value,[2,7],"-");}
	if(rule==="email"){return String(value||"").trim().toLowerCase();}
	if(rule==="url"){return dpPanelNormalizeUrl(value);}
	if(rule==="map_url"||rule==="maps_url"){return dpPanelNormalizeMapUrl(value);}
	if(rule==="domain"||rule==="hostname"){return dpPanelNormalizeDomain(value);}
	if(rule==="timezone"||rule==="time_zone"){return dpPanelNormalizeTimezone(value);}
	if(rule==="locale"||rule==="language_tag"){return dpPanelNormalizeLocale(value);}
	if(rule==="json"||rule==="json_text"){return dpPanelFormatJson(value,options||{});}
	if(rule==="mime_type"||rule==="content_type"){return dpPanelNormalizeMimeType(value);}
	if(rule==="semver"||rule==="semantic_version"){return dpPanelNormalizeSemver(value);}
	if(rule==="cron_expression"||rule==="cron"){return dpPanelNormalizeCronExpression(value);}
	if(rule==="language_code"||rule==="iso_language"){return dpPanelNormalizeLanguageCode(value);}
	if(rule==="country_code"||rule==="iso_country"){return dpPanelNormalizeCountryCode(value);}
	if(rule==="subdivision_code"||rule==="region_code"){return dpPanelNormalizeSubdivision(value);}
	if(rule==="currency_code"||rule==="iso_currency"){return dpPanelNormalizeCurrencyCode(value);}
	if(rule==="ip_address"||rule==="ip"||rule==="ipv4"||rule==="ipv6"){return String(value||"").trim().toLowerCase();}
	if(rule==="mac_address"||rule==="mac"){return dpPanelNormalizeMacAddress(value);}
	if(rule==="uuid"){return dpPanelNormalizeUuid(value);}
	if(rule==="ulid"){return dpPanelNormalizeUlid(value);}
	if(rule==="hex_color"||rule==="color_hex"){return dpPanelNormalizeHexColor(value);}
	if(rule==="credit_card"||rule==="card"){return dpPanelFormatDigits(value,[4,4,4,4,3]," ");}
	if(rule==="credit_card_expiry"||rule==="card_expiry"){
		var expiry=String(value||"").replace(/\D/g,"").slice(0,4);
		return expiry.length>2 ? expiry.slice(0,2)+"/"+expiry.slice(2) : expiry;
	}
	if(rule==="card_cvc"||rule==="cvc"||rule==="cvv"){return String(value||"").replace(/\D/g,"").slice(0,4);}
	if(rule==="iban"){return dpPanelFormatIban(value);}
	if(rule==="currency"||rule==="money"){return dpPanelFormatCurrency(value,options||{});}
	if(rule==="percent"||rule==="percentage"){return dpPanelFormatPercent(value,options||{});}
	if(rule==="latitude"||rule==="longitude"){return dpPanelFormatCoordinate(value,options||{});}
	if(rule==="coordinates"||rule==="lat_lng"||rule==="lng_lat"){
		var pairOptions=Object.assign({},options||{},rule==="lng_lat" ? {order:"lng_lat"} : {});
		return dpPanelFormatCoordinatePair(value,pairOptions);
	}
	if(rule==="digits"){return String(value||"").replace(/\D/g,"");}
	if(rule==="alpha"){return String(value||"").replace(/[^A-Za-z]/g,"");}
	if(rule==="alphanumeric"){return String(value||"").replace(/[^0-9A-Za-z]/g,"");}
	return String(value||"");
}
/**
 * Normalizes a URL-like value by adding HTTPS when no scheme exists.
 *
 * @param {string} value Raw URL value.
 * @returns {string} Normalized URL value.
 */
function dpPanelNormalizeUrl(value){
	value=String(value||"").trim();
	if(value===""||value.indexOf("://")!==-1||value.indexOf("//")===0||value.toLowerCase().indexOf("mailto:")===0||value.toLowerCase().indexOf("tel:")===0){return value;}
	return "https://"+value;
}
/**
 * Normalizes a map URL or coordinate pair into a map URL.
 *
 * @param {string} value Raw map URL or coordinate pair.
 * @returns {string} Normalized map URL.
 */
function dpPanelNormalizeMapUrl(value){
	value=String(value||"").trim();
	if(value.indexOf("://")===-1&&value.indexOf("//")!==0&&dpPanelValidCoordinatePair(value)){
		return "https://www.google.com/maps?q="+encodeURIComponent(dpPanelFormatCoordinatePair(value,{decimals:6}));
	}
	return dpPanelNormalizeUrl(value);
}
/**
 * Extracts and normalizes a hostname from URL-like or domain-like input.
 *
 * @param {string} value Raw domain or URL value.
 * @returns {string} Lowercase hostname without surrounding dots.
 */
function dpPanelNormalizeDomain(value){
	value=String(value||"").trim().toLowerCase();
	if(value===""){return "";}
	try{
		if(value.indexOf("://")!==-1||value.indexOf("//")===0){
			return (new URL(value.indexOf("//")===0 ? "https:"+value : value)).hostname.replace(/^\.+|\.+$/g,"");
		}
	}catch(error){}
	value=value.replace(/[?#].*$/,"").split("/")[0].split(":")[0];
	return value.replace(/^\.+|\.+$/g,"");
}
/**
 * Normalizes a timezone label into canonical slash-separated casing.
 *
 * @param {string} value Raw timezone label.
 * @returns {string} Canonical timezone label when known.
 */
function dpPanelNormalizeTimezone(value){
	value=String(value||"").trim().replace(/\\/g,"/");
	if(value===""){return "";}
	value=value.replace(/\s+/g,"_");
	var upper=value.toUpperCase();
	if(upper==="UTC"||upper==="GMT"){return upper;}
	var canonical=dpPanelTimezoneCanonicalMap();
	var candidate=value.split("/").map(function(part){
		return part.split("_").map(function(piece){
			piece=piece.toLowerCase();
			return piece.charAt(0).toUpperCase()+piece.slice(1);
		}).join("_");
	}).join("/");
	return canonical[candidate.toLowerCase()]||candidate;
}
/**
 * Builds a cached lowercase-to-canonical timezone map.
 *
 * @returns {Object<string, string>} Canonical timezone map.
 */
function dpPanelTimezoneCanonicalMap(){
	if(dpPanelTimezoneCanonicalMap.cache){return dpPanelTimezoneCanonicalMap.cache;}
	var map={utc:"UTC",gmt:"GMT"};
	if(typeof Intl!=="undefined"&&typeof Intl.supportedValuesOf==="function"){
		try{
			Intl.supportedValuesOf("timeZone").forEach(function(timezone){
				map[String(timezone).toLowerCase()]=timezone;
			});
		}catch(error){}
	}
	[
		"America/Toronto","America/New_York","America/Chicago","America/Denver","America/Los_Angeles","America/Vancouver",
		"Europe/London","Europe/Paris","Europe/Berlin","Europe/Amsterdam","Europe/Dublin",
		"Australia/Sydney","Australia/Melbourne","Pacific/Auckland","Asia/Tokyo","Asia/Dubai"
	].forEach(function(timezone){map[String(timezone).toLowerCase()]=timezone;});
	dpPanelTimezoneCanonicalMap.cache=map;
	return map;
}
/**
 * Normalizes a locale or language tag.
 *
 * @param {string} value Raw locale value.
 * @returns {string} Canonical locale when supported.
 */
function dpPanelNormalizeLocale(value){
	value=String(value||"").trim().replace(/_/g,"-");
	if(value===""){return "";}
	if(typeof Intl!=="undefined"&&typeof Intl.getCanonicalLocales==="function"){
		try{return Intl.getCanonicalLocales(value)[0]||value;}catch(error){}
	}
	return value.split("-").filter(Boolean).map(function(part,index){
		if(index===0){return part.toLowerCase();}
		if(/^[A-Za-z]{4}$/.test(part)){return part.charAt(0).toUpperCase()+part.slice(1).toLowerCase();}
		if(/^[A-Za-z]{2}$/.test(part)||/^[0-9]{3}$/.test(part)){return part.toUpperCase();}
		return part.toLowerCase();
	}).join("-");
}
/**
 * Minifies valid JSON while leaving invalid text unchanged.
 *
 * @param {string} value Raw JSON text.
 * @returns {string} Minified JSON or original value.
 */
function dpPanelNormalizeJson(value){
	value=String(value||"").trim();
	if(value===""){return "";}
	try{return JSON.stringify(JSON.parse(value));}catch(error){return value;}
}
/**
 * Formats valid JSON for editing.
 *
 * @param {string} value Raw JSON text.
 * @param {Object<string, *>} options Formatting options.
 * @returns {string} Pretty or compact JSON, or original value on parse failure.
 */
function dpPanelFormatJson(value,options){
	value=String(value||"").trim();
	if(value===""){return "";}
	try{
		return JSON.stringify(JSON.parse(value),null,options&&options.pretty===false ? 0 : 2);
	}catch(error){
		return value;
	}
}
/**
 * Normalizes a MIME type and parameter spacing.
 *
 * @param {string} value Raw MIME type.
 * @returns {string} Lowercase normalized MIME type.
 */
function dpPanelNormalizeMimeType(value){
	value=String(value||"").trim().toLowerCase();
	if(value===""){return "";}
	return value.replace(/\s*;\s*/g,"; ").replace(/\s*=\s*/g,"=");
}
/**
 * Normalizes a semantic version by removing leading `v` and lowercasing metadata.
 *
 * @param {string} value Raw semantic version.
 * @returns {string} Normalized semantic version.
 */
function dpPanelNormalizeSemver(value){
	value=String(value||"").trim();
	if(value===""){return "";}
	if(/^v(?=[0-9])/i.test(value)){value=value.slice(1);}
	return value.toLowerCase();
}
/**
 * Normalizes whitespace and casing in a cron expression.
 *
 * @param {string} value Raw cron expression.
 * @returns {string} Normalized cron expression.
 */
function dpPanelNormalizeCronExpression(value){
	return String(value||"").trim().toLowerCase().replace(/\s+/g," ");
}
/**
 * Normalizes a language label or tag to a two-letter language code.
 *
 * @param {string} value Raw language label or code.
 * @returns {string} Lowercase language code.
 */
function dpPanelNormalizeLanguageCode(value){
	var text=String(value||"").trim().replace(/_/g,"-");
	if(text===""){return "";}
	var upper=text.toUpperCase();
	var map={
		ENGLISH:"en",ENG:"en",
		FRENCH:"fr",FRANCAIS:"fr","FRAN\u00c7AIS":"fr",FRE:"fr",FRA:"fr",
		GERMAN:"de",DEUTSCH:"de",GER:"de",DEU:"de",
		DUTCH:"nl",NEDERLANDS:"nl",DUT:"nl",NLD:"nl",
		SPANISH:"es",ESPANOL:"es","ESPA\u00d1OL":"es",SPA:"es",
		PORTUGUESE:"pt",POR:"pt",
		ITALIAN:"it",ITA:"it",
		JAPANESE:"ja",JPN:"ja",
		CHINESE:"zh",CHI:"zh",ZHO:"zh",
		ARABIC:"ar",ARA:"ar"
	};
	if(map[upper]){return map[upper];}
	return dpPanelNormalizeLocale(text).split("-")[0].replace(/[^A-Za-z]+/g,"").toLowerCase();
}
/**
 * Normalizes common country labels to ISO-like two-letter country codes.
 *
 * @param {string} value Raw country label or code.
 * @returns {string} Uppercase country code.
 */
function dpPanelNormalizeCountryCode(value){
	var text=String(value||"").trim().toUpperCase();
	var map={
		CANADA:"CA",CAN:"CA",CA:"CA",
		"UNITED STATES":"US","UNITED STATES OF AMERICA":"US",USA:"US",US:"US",
		"UNITED KINGDOM":"GB","GREAT BRITAIN":"GB",BRITAIN:"GB",UK:"GB",GBR:"GB",GB:"GB",
		AUSTRALIA:"AU",AUS:"AU",AU:"AU",
		"NEW ZEALAND":"NZ",AOTEAROA:"NZ",NZL:"NZ",NZ:"NZ",
		FRANCE:"FR",FRA:"FR",FR:"FR",
		GERMANY:"DE",DEU:"DE",GER:"DE",DE:"DE",
		NETHERLANDS:"NL",NLD:"NL",NL:"NL",
		IRELAND:"IE",IRL:"IE",IE:"IE"
	};
	return map[text]||text;
}
/**
 * Normalizes currency symbols and labels into ISO-like currency codes.
 *
 * @param {string} value Raw currency label, symbol, or code.
 * @returns {string} Uppercase currency code.
 */
function dpPanelNormalizeCurrencyCode(value){
	var text=String(value||"").trim().toUpperCase().replace(/\s+/g," ");
	var map={
		"$":"USD",
		"C$":"CAD","CA$":"CAD","CAD$":"CAD","CANADIAN DOLLAR":"CAD","CANADIAN DOLLARS":"CAD",
		"US$":"USD","USD$":"USD",DOLLAR:"USD",DOLLARS:"USD","US DOLLAR":"USD","US DOLLARS":"USD","UNITED STATES DOLLAR":"USD","UNITED STATES DOLLARS":"USD",
		"\u20ac":"EUR",EURO:"EUR",EUROS:"EUR",
		"\u00a3":"GBP",POUND:"GBP",POUNDS:"GBP","POUND STERLING":"GBP","BRITISH POUND":"GBP","BRITISH POUNDS":"GBP",
		"\u00a5":"JPY",YEN:"JPY","JAPANESE YEN":"JPY"
	};
	return map[text]||text.replace(/[^A-Z]+/g,"");
}
/**
 * Normalizes a MAC address into colon-separated uppercase octets.
 *
 * @param {string} value Raw MAC address.
 * @returns {string} Normalized MAC address.
 */
function dpPanelNormalizeMacAddress(value){
	var hex=String(value||"").replace(/[^0-9A-Fa-f]+/g,"").toUpperCase().slice(0,12);
	return (hex.match(/.{1,2}/g)||[]).join(":");
}
/**
 * Normalizes UUID text by inserting canonical hyphens while typing.
 *
 * @param {string} value Raw UUID text.
 * @returns {string} Lowercase UUID-shaped text.
 */
function dpPanelNormalizeUuid(value){
	var hex=String(value||"").replace(/[^0-9A-Fa-f]+/g,"").toLowerCase().slice(0,32);
	if(hex.length<=8){return hex;}
	var parts=[hex.slice(0,8),hex.slice(8,12),hex.slice(12,16),hex.slice(16,20),hex.slice(20)].filter(Boolean);
	return parts.join("-");
}
/**
 * Normalizes ULID text to uppercase Crockford-like characters.
 *
 * @param {string} value Raw ULID text.
 * @returns {string} Normalized ULID text.
 */
function dpPanelNormalizeUlid(value){
	return String(value||"").replace(/[^0-9A-Za-z]+/g,"").toUpperCase().slice(0,26);
}
/**
 * Normalizes hex color text to a six-digit CSS color.
 *
 * @param {string} value Raw color text.
 * @returns {string} Normalized `#rrggbb` color or empty string.
 */
function dpPanelNormalizeHexColor(value){
	var hex=String(value||"").replace(/[^0-9A-Fa-f]+/g,"").toLowerCase();
	if(hex.length===3){hex=hex.charAt(0)+hex.charAt(0)+hex.charAt(1)+hex.charAt(1)+hex.charAt(2)+hex.charAt(2);}
	else{hex=hex.slice(0,6);}
	return hex ? "#"+hex : "";
}
/**
 * Applies all configured formatting behavior to one input.
 *
 * The routine updates locale-sensitive placeholder/pattern metadata, applies
 * optional masks and format rules, preserves caret position by meaningful
 * character count, and refreshes dependent field UI state.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|null} input Form control.
 * @param {string} trigger Trigger name such as `input`, `blur`, or `submit`.
 * @returns {void}
 */
function dpPanelApplyInputFormatting(input,trigger){
	if(!input||input.dataset.dpPanelFormatting==="1"){return;}
	trigger=trigger||"input";
	var wanted=dpPanelFormattingEvent(input);
	if(wanted==="submit"&&trigger!=="submit"){return;}
	if(wanted==="blur"&&trigger!=="blur"&&trigger!=="submit"&&trigger!=="button"&&trigger!=="paste"&&!input.dataset.dpPanelMask){return;}
	if(wanted==="change"&&trigger!=="change"&&trigger!=="submit"&&trigger!=="button"&&trigger!=="paste"&&!input.dataset.dpPanelMask){return;}
	var before=input.value;
	var next=before;
	var meaningful=dpPanelMeaningfulCount(before, input.selectionStart||0);
	dpPanelRefreshFormatLocale(input);
	if(input.dataset.dpPanelMask){next=dpPanelApplyMaskValue(next,input.dataset.dpPanelMask);}
	if(input.dataset.dpPanelFormat){
		var options=dpPanelParseFormatOptions(input);
		var rule=dpPanelEffectiveFormatRule(input.dataset.dpPanelFormat,input,options);
		if(rule==="phone_international"){
			var phonePrefix=dpPanelInternationalPhoneCode(dpPanelFormatContext(input,options));
			options=Object.assign({},options,{phone_prefix:phonePrefix});
			var phoneDigits=String(before||"").replace(/\D/g,"");
			if(phonePrefix&&String(before||"").replace(/[^\d+]/g,"").charAt(0)!=="+"&&phoneDigits.indexOf(phonePrefix)!==0){
				meaningful+=phonePrefix.length;
			}
		}
		if(rule==="slug"&&trigger==="input"){
			options=Object.assign({},options,{preserve_trailing:true});
		}
		if(input.type==="number"&&(rule==="currency"||rule==="money")){
			next=String(next||"").replace(/[^\d.\-]/g,"");
		}
		else {
			next=dpPanelApplyFormatValue(next,rule,options);
		}
	}
	if(next!==before){
		input.dataset.dpPanelFormatting="1";
		input.value=next;
		try{
			var caret=dpPanelCaretFromMeaningfulCount(next,meaningful);
			input.setSelectionRange(caret,caret);
		}catch(error){}
		delete input.dataset.dpPanelFormatting;
	}
	dpPanelRefreshPatternValidity(input);
	dpPanelRefreshCharacterCounter(input);
	dpPanelRefreshColorSwatch(input);
	dpPanelAutoResizeTextarea(input);
}
/**
 * Refreshes a character counter associated with an input shell.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|null} input Form control.
 * @returns {void}
 */
function dpPanelRefreshCharacterCounter(input){
	if(!input||!input.closest){return;}
	var shell=input.closest("[data-dp-panel-input-shell]");
	if(!shell){return;}
	var counter=shell.querySelector("[data-dp-panel-character-counter]");
	if(!counter){return;}
	var value=String(input.value||"");
	var max=parseInt(counter.dataset.dpPanelCharacterCounterMax||input.getAttribute("maxlength")||"0",10);
	counter.textContent=max>0 ? value.length+"/"+max : String(value.length);
	counter.dataset.dpPanelCharacterCounterOver=max>0&&value.length>max ? "1" : "";
}
/**
 * Refreshes a color swatch associated with a hex color input.
 *
 * @param {HTMLInputElement|null} input Color text input.
 * @returns {void}
 */
function dpPanelRefreshColorSwatch(input){
	if(!input||!input.closest){return;}
	var shell=input.closest("[data-dp-panel-input-shell]");
	if(!shell){return;}
	var swatch=shell.querySelector("[data-dp-panel-color-swatch]");
	if(!swatch){return;}
	var color=dpPanelNormalizeHexColor(input.value||"");
	if(dpPanelValidHexColor(color)){
		swatch.style.backgroundColor=color;
		swatch.dataset.dpPanelColorSwatchEmpty="";
	}
	else{
		swatch.style.backgroundColor="";
		swatch.dataset.dpPanelColorSwatchEmpty="1";
	}
}
/**
 * Refreshes the visible output for a slider control.
 *
 * @param {HTMLInputElement|null} input Slider input.
 * @returns {void}
 */
function dpPanelRefreshSliderValue(input){
	if(!input||!input.closest||!input.matches("[data-dp-panel-slider]")){return;}
	var shell=input.closest("[data-dp-panel-slider-shell]");
	if(!shell){return;}
	var output=shell.querySelector("[data-dp-panel-slider-value]");
	if(!output){return;}
	var prefix=output.querySelector(".dp-panel-sr-only");
	var text=input.value||"";
	output.textContent="";
	if(prefix){output.appendChild(prefix);}
	output.appendChild(document.createTextNode(text));
}
/**
 * Resolves the separator used by a tags input.
 *
 * @param {HTMLInputElement|null} input Tags input.
 * @returns {string} Tag separator.
 */
function dpPanelTagsSeparator(input){
	var separator=input&&input.dataset ? String(input.dataset.dpPanelTagSeparator||",").trim() : ",";
	return separator || ",";
}
/**
 * Parses unique tag values from a tags input.
 *
 * @param {HTMLInputElement|null} input Tags input.
 * @returns {string[]} Unique tag values.
 */
function dpPanelTagsValues(input){
	if(!input){return [];}
	var separator=dpPanelTagsSeparator(input).replace(/[.*+?^${}()|[\]\\]/g,"\\$&");
	var pattern=new RegExp("["+separator+"\\n\\r]+","g");
	var seen={};
	return String(input.value||"").split(pattern).map(function(tag){return tag.trim();}).filter(function(tag){
		var key=tag.toLowerCase();
		if(!tag||seen[key]){return false;}
		seen[key]=true;
		return true;
	});
}
/**
 * Normalizes a tags input value and refreshes its chip preview.
 *
 * @param {HTMLInputElement|null} input Tags input.
 * @returns {void}
 */
function dpPanelNormalizeTagsInput(input){
	if(!input||input.readOnly||input.disabled){return;}
	var values=dpPanelTagsValues(input);
	var separator=dpPanelTagsSeparator(input);
	input.value=values.join(separator+" ");
	dpPanelRefreshTags(input);
}
/**
 * Refreshes the chip preview for a tags input.
 *
 * @param {HTMLInputElement|null} input Tags input.
 * @returns {void}
 */
function dpPanelRefreshTags(input){
	if(!input||!input.matches||!input.matches("[data-dp-panel-tags]")){return;}
	var shell=input.closest("[data-dp-panel-tags-shell]");
	var list=shell ? shell.querySelector("[data-dp-panel-tags-list]") : null;
	if(!list){return;}
	var values=dpPanelTagsValues(input);
	list.innerHTML="";
	if(!values.length){
		list.dataset.dpPanelTagsEmpty="1";
		list.textContent=input.getAttribute("placeholder")||dpPanelText("client.no_tags","No tags");
		return;
	}
	list.dataset.dpPanelTagsEmpty="";
	values.forEach(function(tag){
		var chip=document.createElement("span");
		chip.className="dp-panel-tag-chip";
		chip.textContent=tag;
		list.appendChild(chip);
	});
}
/**
 * Resolves key and pair separators for a key-value textarea.
 *
 * @param {HTMLTextAreaElement|null} textarea Key-value textarea.
 * @returns {{key: string, pair: string}} Separator contract.
 */
function dpPanelKeyValueSeparators(textarea){
	return {
		key:(textarea&&textarea.dataset ? String(textarea.dataset.dpPanelKeySeparator||"=").trim() : "=") || "=",
		pair:(textarea&&textarea.dataset ? String(textarea.dataset.dpPanelPairSeparator||"\\n") : "\\n") || "\\n"
	};
}
/**
 * Parses key-value pairs from textarea text.
 *
 * @param {HTMLTextAreaElement|null} textarea Key-value textarea.
 * @returns {Array<{key: string, value: string}>} Parsed pairs.
 */
function dpPanelKeyValuePairs(textarea){
	if(!textarea){return [];}
	var separators=dpPanelKeyValueSeparators(textarea);
	var pairSeparator=separators.pair.replace(/\\n/g,"\n").replace(/\\r/g,"\r").trim();
	var source=String(textarea.value||"");
	var lines=pairSeparator ? source.split(pairSeparator) : source.split(/\r\n|\r|\n/g);
	if(pairSeparator!=="\n"){lines=lines.join("\n").split(/\r\n|\r|\n/g);}
	var pairs=[];
	lines.forEach(function(line){
		line=String(line||"").trim();
		if(!line){return;}
		var index=line.indexOf(separators.key);
		var key=index===-1 ? line : line.slice(0,index);
		var value=index===-1 ? "" : line.slice(index+separators.key.length);
		key=key.trim();
		if(!key){return;}
		pairs.push({key:key,value:value.trim()});
	});
	return pairs;
}
/**
 * Normalizes key-value textarea text and refreshes its preview.
 *
 * @param {HTMLTextAreaElement|null} textarea Key-value textarea.
 * @returns {void}
 */
function dpPanelNormalizeKeyValueInput(textarea){
	if(!textarea||textarea.readOnly||textarea.disabled){return;}
	var separators=dpPanelKeyValueSeparators(textarea);
	var pairs=dpPanelKeyValuePairs(textarea);
	textarea.value=pairs.map(function(pair){return pair.key+separators.key+pair.value;}).join("\n");
	dpPanelRefreshKeyValue(textarea);
	dpPanelAutoResizeTextarea(textarea);
}
/**
 * Refreshes the compact key-value preview chips.
 *
 * @param {HTMLTextAreaElement|null} textarea Key-value textarea.
 * @returns {void}
 */
function dpPanelRefreshKeyValue(textarea){
	if(!textarea||!textarea.matches||!textarea.matches("[data-dp-panel-key-value]")){return;}
	var shell=textarea.closest("[data-dp-panel-key-value-shell]");
	var preview=shell ? shell.querySelector("[data-dp-panel-key-value-preview]") : null;
	if(!preview){return;}
	var pairs=dpPanelKeyValuePairs(textarea);
	preview.innerHTML="";
	if(!pairs.length){
		preview.dataset.dpPanelKeyValueEmpty="1";
	preview.textContent=textarea.getAttribute("placeholder")||dpPanelText("client.no_pairs","No pairs");
		return;
	}
	preview.dataset.dpPanelKeyValueEmpty="";
	pairs.slice(0,12).forEach(function(pair){
		var row=document.createElement("span");
		row.className="dp-panel-key-value-chip";
		var key=document.createElement("strong");
		key.textContent=pair.key;
		var value=document.createElement("span");
		value.textContent=pair.value;
		row.appendChild(key);
		row.appendChild(value);
		preview.appendChild(row);
	});
	if(pairs.length>12){
		var more=document.createElement("em");
		more.textContent=dpPanelText("client.more_count","+{count} more",{count:pairs.length-12});
		preview.appendChild(more);
	}
}
/**
 * Initializes searchable select shells in a root.
 *
 * @param {Document|Element|null} root Initialization root.
 * @returns {void}
 */
function dpPanelInitSearchableSelects(root){
	(root||document).querySelectorAll("[data-dp-panel-searchable-select]").forEach(function(shell){
		if(shell.dataset.dpPanelSearchableSelectReady==="1"){return;}
		shell.dataset.dpPanelSearchableSelectReady="1";
		var search=shell.querySelector("[data-dp-panel-searchable-select-input]");
		var select=shell.querySelector("select[data-dp-panel-searchable='1']");
		if(!search||!select){return;}
		search.addEventListener("input",function(){dpPanelRefreshSearchableSelect(shell);});
		select.addEventListener("change",function(){dpPanelRefreshSearchableSelect(shell);});
		dpPanelRefreshSearchableSelect(shell);
	});
}
/**
 * Applies search filtering to a searchable select shell.
 *
 * @param {Element|null} shell Searchable select shell.
 * @returns {void}
 */
function dpPanelRefreshSearchableSelect(shell){
	if(!shell){return;}
	var search=shell.querySelector("[data-dp-panel-searchable-select-input]");
	var select=shell.querySelector("select[data-dp-panel-searchable='1']");
	var status=shell.querySelector("[data-dp-panel-searchable-select-status]");
	if(!search||!select){return;}
	var query=String(search.value||"").trim().toLowerCase();
	var visible=0;
	var hiddenSelected=0;
	Array.prototype.forEach.call(select.options,function(option){
		if(!option.dataset.dpPanelOriginalDisabled){
			option.dataset.dpPanelOriginalDisabled=option.disabled ? "1" : "0";
		}
		var isEmpty=option.value==="";
		var text=String(option.textContent||option.label||option.value||"").toLowerCase();
		var value=String(option.value||"").toLowerCase();
		var match=!query||isEmpty||text.indexOf(query)!==-1||value.indexOf(query)!==-1;
		option.hidden=!match;
		option.disabled=option.dataset.dpPanelOriginalDisabled==="1";
		if(!match&&!isEmpty){
			option.disabled=true;
			if(option.selected){hiddenSelected++;}
		}
		else if(option.dataset.dpPanelOriginalDisabled!=="1"){
			option.disabled=false;
		}
		if(match&&!isEmpty){visible++;}
	});
	if(status){
		if(query&&visible===0){
			status.textContent=shell.dataset.dpPanelSelectNoResults||dpPanelText("client.no_matching_options","No matching options");
			status.dataset.dpPanelSearchableSelectEmpty="1";
		}
		else{
			status.textContent=query ? dpPanelText("client.matching_options","{count} matching options",{count:visible}) : "";
			status.dataset.dpPanelSearchableSelectEmpty="";
		}
		if(hiddenSelected>0){
			status.textContent=status.textContent ? status.textContent+" " : "";
			status.textContent+=dpPanelText("client.hidden_selected_options","{count} selected options are hidden by search",{count:hiddenSelected});
		}
	}
}
/**
 * Autosizes a textarea marked for Panel auto-resize behavior.
 *
 * @param {HTMLTextAreaElement|null} textarea Textarea control.
 * @returns {void}
 */
function dpPanelAutoResizeTextarea(textarea){
	if(!textarea||textarea.tagName!=="TEXTAREA"||!textarea.dataset||textarea.dataset.dpPanelAutoResize!=="1"){return;}
	textarea.style.overflowY="hidden";
	textarea.style.height="auto";
	var minHeight=parseFloat(textarea.dataset.dpPanelAutoResizeMinHeight||"0");
	if(!minHeight){
		minHeight=textarea.offsetHeight||0;
		textarea.dataset.dpPanelAutoResizeMinHeight=String(minHeight);
	}
	textarea.style.height=Math.max(minHeight,textarea.scrollHeight)+"px";
}
/**
 * Escapes arbitrary text for safe HTML insertion.
 *
 * @param {string} value Raw text.
 * @returns {string} Escaped HTML text.
 */
function dpPanelEscapeHtml(value){
	var div=document.createElement("div");
	div.textContent=String(value||"");
	return div.innerHTML;
}
/**
 * Sanitizes rich HTML to the limited editor-safe element and attribute set.
 *
 * Scriptable and embedded content is removed, unknown tags are flattened to text,
 * event/style/id/class attributes are stripped, and links are forced to open with
 * `noopener noreferrer`.
 *
 * Media elements remain disabled unless the caller supplies an exact URL
 * allow-list callback. This keeps ordinary rich paste inert while allowing the
 * editor asset picker to preserve provider-issued image references.
 *
 * @param {string} value Raw HTML.
 * @param {{allowMediaUrl?: function(string): boolean}=} options Optional media policy.
 * @returns {string} Sanitized rich HTML.
 */
function dpPanelSanitizeRichHtml(value,options){
	options=options&&typeof options==="object"?options:{};
	var allowMediaUrl=typeof options.allowMediaUrl==="function"?options.allowMediaUrl:null;
	var template=document.createElement("template");
	template.innerHTML=String(value||"");
	Array.prototype.slice.call(template.content.querySelectorAll("script,style,iframe,object,embed")).forEach(function(node){
		node.remove();
	});
	var allowed={P:1,BR:1,STRONG:1,B:1,EM:1,I:1,U:1,S:1,A:1,UL:1,OL:1,LI:1,BLOCKQUOTE:1,PRE:1,CODE:1,H1:1,H2:1,H3:1,H4:1,H5:1,H6:1,HR:1};
	if(allowMediaUrl){allowed.IMG=1;}
	Array.prototype.slice.call(template.content.querySelectorAll("*")).forEach(function(node){
		if(!allowed[node.tagName]){
			node.replaceWith(document.createTextNode(node.textContent||""));
			return;
		}
		if(node.tagName==="IMG"){
			var source=String(node.getAttribute("src")||"").trim();
			if(!source||!allowMediaUrl||allowMediaUrl(source)!==true){node.remove();return;}
			Array.prototype.slice.call(node.attributes).forEach(function(attribute){
				if(!["src","alt","width","height","loading"].includes(attribute.name.toLowerCase())){node.removeAttribute(attribute.name);}
			});
			["width","height"].forEach(function(name){
				var dimension=String(node.getAttribute(name)||"");
				if(!/^\d{1,6}$/.test(dimension)||Number(dimension)<1||Number(dimension)>100000){node.removeAttribute(name);}
			});
			node.setAttribute("alt",String(node.getAttribute("alt")||"").slice(0,512));
			node.setAttribute("loading","lazy");
			return;
		}
		Array.prototype.slice.call(node.attributes).forEach(function(attribute){
			var name=attribute.name.toLowerCase();
			var value=attribute.value||"";
			if(name.indexOf("on")===0||name==="style"||name==="class"||name==="id"||(name!=="href"&&name!=="target"&&name!=="rel")){
				node.removeAttribute(attribute.name);
			}
			else if(name==="href"&&/^\s*javascript:/i.test(value)){
				node.removeAttribute(attribute.name);
			}
		});
		if(node.tagName==="A"&&node.getAttribute("href")){
			node.setAttribute("target","_blank");
			node.setAttribute("rel","noopener noreferrer");
		}
	});
	dpPanelCleanRichHtmlFragment(template.content);
	return template.innerHTML;
}
/**
 * Normalizes incoming rich editor content before the sanitizer contract is applied.
 *
 * Plain multiline text is promoted to paragraph HTML, top-level text nodes are
 * wrapped into paragraphs, legacy div blocks become paragraphs, and bare line
 * breaks become editable empty paragraphs so contenteditable state stays stable.
 *
 * @param {string} value Raw HTML or plain text from a source textarea, paste, or visual editor.
 * @returns {string} Normalized HTML fragment ready for rich sanitization.
 */
JS;
	}

}
