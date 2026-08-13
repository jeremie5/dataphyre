<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits the semantic validation and upload runtime.
 */
trait PanelRendererAssetsValidationUploadRuntimeScripts {
	/**
	 * Returns this controller module for the public Panel runtime bundle.
	 */
	private static function validationUploadRuntimeScript(): string {
		return <<<'JS'
function dpPanelRefreshPatternValidity(input){
	if(!input||typeof input.setCustomValidity!=="function"){return;}
	input.setCustomValidity("");
	if(!input.hasAttribute("pattern")||!input.title){
		input.setCustomValidity(dpPanelFormattedSemanticValidityMessage(input));
		return;
	}
	if(input.validity&&input.validity.patternMismatch){
		input.setCustomValidity(input.title);
		return;
	}
	input.setCustomValidity(dpPanelFormattedSemanticValidityMessage(input));
}
/**
 * Resolves the first semantic validation message for a formatted field.
 *
 * The dispatcher maps Panel format aliases to focused validators for cards,
 * addresses, locale identifiers, network values, coordinates, and financial
 * identifiers. Empty values remain valid here so required-ness stays a separate
 * browser constraint.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|null} input Field with dpPanelFormat metadata.
 * @returns {string} Validation message, or an empty string when the value is acceptable.
 */
function dpPanelFormattedSemanticValidityMessage(input){
	if(!input||!input.dataset||!input.dataset.dpPanelFormat){return "";}
	var rule=dpPanelEffectiveFormatRule(input.dataset.dpPanelFormat,input,dpPanelParseFormatOptions(input));
	var value=String(input.value||"").trim();
	if(value===""){return "";}
	if(rule==="credit_card"||rule==="card"){
		var card=value.replace(/\D+/g,"");
		return dpPanelValidCreditCardNumber(card) ? "" : "Must be a valid card number.";
	}
	if(rule==="credit_card_expiry"||rule==="card_expiry"){
		var expiry=value.replace(/\D+/g,"");
		return dpPanelValidCreditCardExpiry(expiry) ? "" : "Must be a valid future expiry date.";
	}
	if(rule==="email"){
		return dpPanelValidEmail(value) ? "" : "Must be a valid email address.";
	}
	if(rule==="url"){
		return dpPanelValidUrl(value) ? "" : "Must be a valid URL.";
	}
	if(rule==="map_url"||rule==="maps_url"){
		return dpPanelValidMapUrl(value) ? "" : "Must be a valid Google Maps URL.";
	}
	if(rule==="domain"||rule==="hostname"){
		return dpPanelValidDomain(value) ? "" : "Must be a valid domain.";
	}
	if(rule==="timezone"||rule==="time_zone"){
		return dpPanelValidTimezone(value) ? "" : "Must be a valid timezone.";
	}
	if(rule==="locale"||rule==="language_tag"){
		return dpPanelValidLocale(value) ? "" : "Must be a valid locale.";
	}
	if(rule==="json"||rule==="json_text"){
		return dpPanelValidJson(value) ? "" : "Must be valid JSON.";
	}
	if(rule==="mime_type"||rule==="content_type"){
		return dpPanelValidMimeType(value) ? "" : "Must be a valid MIME type.";
	}
	if(rule==="semver"||rule==="semantic_version"){
		return dpPanelValidSemver(value) ? "" : "Must be a valid semantic version.";
	}
	if(rule==="cron_expression"||rule==="cron"){
		return dpPanelValidCronExpression(value) ? "" : "Must be a valid cron expression.";
	}
	if(rule==="language_code"||rule==="iso_language"){
		return dpPanelValidLanguageCode(value) ? "" : "Must be a valid ISO language code.";
	}
	if(rule==="country_code"||rule==="iso_country"){
		return dpPanelValidCountryCode(value) ? "" : "Must be a valid ISO country code.";
	}
	if(rule==="subdivision_code"||rule==="region_code"){
		return dpPanelValidSubdivisionCode(value,dpPanelFormatContext(input,dpPanelParseFormatOptions(input)).country) ? "" : "Must be a valid subdivision code.";
	}
	if(rule==="currency_code"||rule==="iso_currency"){
		return dpPanelValidCurrencyCode(value) ? "" : "Must be a valid ISO currency code.";
	}
	if(rule==="ip_address"||rule==="ip"){
		return dpPanelValidIpAddress(value) ? "" : "Must be a valid IP address.";
	}
	if(rule==="ipv4"){
		return dpPanelValidIpv4(value) ? "" : "Must be a valid IPv4 address.";
	}
	if(rule==="ipv6"){
		return dpPanelValidIpv6(value) ? "" : "Must be a valid IPv6 address.";
	}
	if(rule==="mac_address"||rule==="mac"){
		return dpPanelValidMacAddress(value) ? "" : "Must be a valid MAC address.";
	}
	if(rule==="uuid"){
		return dpPanelValidUuid(value) ? "" : "Must be a valid UUID.";
	}
	if(rule==="ulid"){
		return dpPanelValidUlid(value) ? "" : "Must be a valid ULID.";
	}
	if(rule==="hex_color"||rule==="color_hex"){
		return dpPanelValidHexColor(value) ? "" : "Must be a valid hex color.";
	}
	if(rule==="latitude"){
		return dpPanelValidCoordinate(value,-90,90) ? "" : "Must be a valid latitude between -90 and 90.";
	}
	if(rule==="longitude"){
		return dpPanelValidCoordinate(value,-180,180) ? "" : "Must be a valid longitude between -180 and 180.";
	}
	if(rule==="coordinates"||rule==="lat_lng"||rule==="lng_lat"){
		return dpPanelValidCoordinatePair(value) ? "" : "Must be valid coordinates in latitude,longitude order.";
	}
	if(rule==="iban"){
		var iban=value.replace(/[^0-9A-Za-z]+/g,"").toUpperCase();
		return dpPanelValidIban(iban) ? "" : "Must be a valid IBAN.";
	}
	return "";
}
/**
 * Checks an email address with the lightweight client-side semantic rule.
 *
 * @param {string} value Candidate email address.
 * @returns {boolean} Whether the value has local, domain, and TLD segments without whitespace.
 */
function dpPanelValidEmail(value){
	value=String(value||"").trim();
	if(value===""||/\s/.test(value)){return false;}
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}
/**
 * Checks a URL after Panel URL normalization.
 *
 * Accepted protocols are HTTP, HTTPS, FTP, mailto, and tel. Relative resolution
 * uses the current page URL only to let the browser parse the candidate.
 *
 * @param {string} value Candidate URL.
 * @returns {boolean} Whether the URL parses with an accepted protocol and no whitespace.
 */
function dpPanelValidUrl(value){
	value=dpPanelNormalizeUrl(value);
	if(/\s/.test(value)){return false;}
	try{
		var url=new URL(value, window.location.href);
		return /^(https?|ftp|mailto|tel):$/.test(url.protocol);
	}catch(error){
		return false;
	}
}
/**
 * Checks whether a URL targets a supported Google Maps host or shortlink.
 *
 * @param {string} value Candidate Maps URL.
 * @returns {boolean} Whether the URL is a valid map link for Panel semantic validation.
 */
function dpPanelValidMapUrl(value){
	value=dpPanelNormalizeMapUrl(value);
	if(!dpPanelValidUrl(value)){return false;}
	try{
		var url=new URL(value, window.location.href);
		var host=url.hostname.toLowerCase();
		var path=url.pathname.toLowerCase();
		return ((/(^|\.)google\.[a-z.]+$/.test(host)&&path.indexOf("/maps")===0)||host==="maps.app.goo.gl"||host==="goo.gl");
	}catch(error){
		return false;
	}
}
/**
 * Checks a normalized domain or IPv4-like host name.
 *
 * Domains must avoid underscores, consecutive dots, and overlong labels. IPv4
 * literals are accepted only when each octet is within range.
 *
 * @param {string} value Candidate domain or IPv4 literal.
 * @returns {boolean} Whether the host value is syntactically acceptable.
 */
function dpPanelValidDomain(value){
	value=dpPanelNormalizeDomain(value);
	if(value===""||value.length>253||value.indexOf("..")!==-1||value.indexOf("_")!==-1){return false;}
	if(/^(\d{1,3}\.){3}\d{1,3}$/.test(value)){
		return value.split(".").every(function(part){var number=parseInt(part,10); return number>=0&&number<=255;});
	}
	return /^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/.test(value);
}
/**
 * Checks a timezone identifier against the canonical timezone map.
 *
 * @param {string} value Candidate timezone identifier.
 * @returns {boolean} Whether the timezone is known to the client map.
 */
function dpPanelValidTimezone(value){
	value=dpPanelNormalizeTimezone(value);
	if(value===""){return false;}
	return !!dpPanelTimezoneCanonicalMap()[value.toLowerCase()];
}
/**
 * Checks a normalized BCP-47-like locale tag accepted by Panel fields.
 *
 * @param {string} value Candidate locale tag.
 * @returns {boolean} Whether the tag matches the supported language/script/region pattern.
 */
function dpPanelValidLocale(value){
	value=dpPanelNormalizeLocale(value);
	if(value===""){return false;}
	return /^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?(-[0-9A-Za-z]{5,8})*$/.test(value);
}
/**
 * Checks whether a non-empty string parses as JSON.
 *
 * @param {string} value Candidate JSON text.
 * @returns {boolean} Whether JSON.parse accepts the value.
 */
function dpPanelValidJson(value){
	value=String(value||"").trim();
	if(value===""){return false;}
	try{JSON.parse(value);return true;}catch(error){return false;}
}
/**
 * Checks a normalized MIME type with optional parameters.
 *
 * @param {string} value Candidate MIME type.
 * @returns {boolean} Whether type, subtype, and parameters match the accepted token grammar.
 */
function dpPanelValidMimeType(value){
	value=dpPanelNormalizeMimeType(value);
	if(value===""){return false;}
	return /^[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}\/[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}(; [a-z0-9!#$&^_.+\-]+=("([^"\\]|\\.)*"|[a-z0-9!#$&^_.+\-]+))*$/.test(value);
}
/**
 * Checks a semantic version after Panel semver normalization.
 *
 * @param {string} value Candidate semantic version.
 * @returns {boolean} Whether the value matches major.minor.patch with optional prerelease/build metadata.
 */
function dpPanelValidSemver(value){
	value=dpPanelNormalizeSemver(value);
	if(value===""){return false;}
	return /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/.test(value);
}
/**
 * Checks a five-field cron expression against Panel's basic scheduler grammar.
 *
 * @param {string} value Candidate cron expression.
 * @returns {boolean} Whether all minute, hour, day, month, and weekday fields are valid.
 */
function dpPanelValidCronExpression(value){
	value=dpPanelNormalizeCronExpression(value);
	var parts=value.split(" ");
	if(parts.length!==5){return false;}
	return dpPanelValidCronField(parts[0],0,59,{})&&
		dpPanelValidCronField(parts[1],0,23,{})&&
		dpPanelValidCronField(parts[2],1,31,{})&&
		dpPanelValidCronField(parts[3],1,12,{jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12})&&
		dpPanelValidCronField(parts[4],0,7,{sun:0,mon:1,tue:2,wed:3,thu:4,fri:5,sat:6});
}
/**
 * Checks a comma-separated cron field.
 *
 * @param {string} field Cron field text.
 * @param {number} min Minimum accepted numeric value.
 * @param {number} max Maximum accepted numeric value.
 * @param {Object} names Optional symbolic names for month or weekday fields.
 * @returns {boolean} Whether every field item is valid.
 */
function dpPanelValidCronField(field,min,max,names){
	if(!field){return false;}
	return field.split(",").every(function(item){return dpPanelValidCronFieldItem(item,min,max,names||{});});
}
/**
 * Checks one cron field item including ranges and step values.
 *
 * @param {string} item Cron field item.
 * @param {number} min Minimum accepted numeric value.
 * @param {number} max Maximum accepted numeric value.
 * @param {Object} names Optional symbolic names for month or weekday fields.
 * @returns {boolean} Whether the item is valid within the field bounds.
 */
function dpPanelValidCronFieldItem(item,min,max,names){
	if(!item){return false;}
	var pieces=item.split("/");
	if(pieces.length>2){return false;}
	var base=pieces[0];
	var step=pieces[1];
	if(step!==undefined&&(!/^[1-9][0-9]*$/.test(step)||parseInt(step,10)>max)){return false;}
	if(base==="*"){return true;}
	if(base.indexOf("-")!==-1){
		var range=base.split("-");
		if(range.length!==2){return false;}
		var start=dpPanelCronFieldValue(range[0],names);
		var end=dpPanelCronFieldValue(range[1],names);
		return start!==null&&end!==null&&start>=min&&end<=max&&start<=end;
	}
	var value=dpPanelCronFieldValue(base,names);
	return value!==null&&value>=min&&value<=max;
}
/**
 * Converts a cron token or symbolic name to its numeric value.
 *
 * @param {string} value Cron token.
 * @param {Object} names Symbolic name map.
 * @returns {number|null} Numeric value, or null when the token is not recognized.
 */
function dpPanelCronFieldValue(value,names){
	value=String(value||"").toLowerCase();
	if(Object.prototype.hasOwnProperty.call(names,value)){return names[value];}
	return /^[0-9]+$/.test(value) ? parseInt(value,10) : null;
}
/**
 * Checks a normalized ISO language code against the embedded language list.
 *
 * @param {string} value Candidate language code.
 * @returns {boolean} Whether the code is known to Panel's client validator.
 */
function dpPanelValidLanguageCode(value){
	value=dpPanelNormalizeLanguageCode(value);
	if(value===""){return false;}
	return dpPanelKnownLanguageCodes().indexOf(value)!==-1;
}
/**
 * Provides ISO-style language codes used by client-side semantic validation.
 *
 * @returns {string[]} Supported lowercase language codes.
 */
function dpPanelKnownLanguageCodes(){
	return ["aa","ab","ae","af","ak","am","an","ar","as","av","ay","az","ba","be","bg","bh","bi","bm","bn","bo","br","bs","ca","ce","ch","co","cr","cs","cu","cv","cy","da","de","dv","dz","ee","el","en","eo","es","et","eu","fa","ff","fi","fj","fo","fr","fy","ga","gd","gl","gn","gu","gv","ha","he","hi","ho","hr","ht","hu","hy","hz","ia","id","ie","ig","ii","ik","io","is","it","iu","ja","jv","ka","kg","ki","kj","kk","kl","km","kn","ko","kr","ks","ku","kv","kw","ky","la","lb","lg","li","ln","lo","lt","lu","lv","mg","mh","mi","mk","ml","mn","mr","ms","mt","my","na","nb","nd","ne","ng","nl","nn","no","nr","nv","ny","oc","oj","om","or","os","pa","pi","pl","ps","pt","qu","rm","rn","ro","ru","rw","sa","sc","sd","se","sg","si","sk","sl","sm","sn","so","sq","sr","ss","st","su","sv","sw","ta","te","tg","th","ti","tk","tl","tn","to","tr","ts","tt","tw","ty","ug","uk","ur","uz","ve","vi","vo","wa","wo","xh","yi","yo","za","zh","zu"];
}
/**
 * Checks a normalized ISO country code against the embedded country list.
 *
 * @param {string} value Candidate country code.
 * @returns {boolean} Whether the code is known to Panel's client validator.
 */
function dpPanelValidCountryCode(value){
	value=dpPanelNormalizeCountryCode(value);
	if(value===""){return false;}
	return dpPanelKnownCountryCodes().indexOf(value)!==-1;
}
/**
 * Provides ISO-style country codes used by client-side semantic validation.
 *
 * @returns {string[]} Supported uppercase country codes.
 */
function dpPanelKnownCountryCodes(){
	return ["AD","AE","AF","AG","AI","AL","AM","AO","AQ","AR","AS","AT","AU","AW","AX","AZ","BA","BB","BD","BE","BF","BG","BH","BI","BJ","BL","BM","BN","BO","BQ","BR","BS","BT","BV","BW","BY","BZ","CA","CC","CD","CF","CG","CH","CI","CK","CL","CM","CN","CO","CR","CU","CV","CW","CX","CY","CZ","DE","DJ","DK","DM","DO","DZ","EC","EE","EG","EH","ER","ES","ET","FI","FJ","FK","FM","FO","FR","GA","GB","GD","GE","GF","GG","GH","GI","GL","GM","GN","GP","GQ","GR","GS","GT","GU","GW","GY","HK","HM","HN","HR","HT","HU","ID","IE","IL","IM","IN","IO","IQ","IR","IS","IT","JE","JM","JO","JP","KE","KG","KH","KI","KM","KN","KP","KR","KW","KY","KZ","LA","LB","LC","LI","LK","LR","LS","LT","LU","LV","LY","MA","MC","MD","ME","MF","MG","MH","MK","ML","MM","MN","MO","MP","MQ","MR","MS","MT","MU","MV","MW","MX","MY","MZ","NA","NC","NE","NF","NG","NI","NL","NO","NP","NR","NU","NZ","OM","PA","PE","PF","PG","PH","PK","PL","PM","PN","PR","PS","PT","PW","PY","QA","RE","RO","RS","RU","RW","SA","SB","SC","SD","SE","SG","SH","SI","SJ","SK","SL","SM","SN","SO","SR","SS","ST","SV","SX","SY","SZ","TC","TD","TF","TG","TH","TJ","TK","TL","TM","TN","TO","TR","TT","TV","TW","TZ","UA","UG","UM","US","UY","UZ","VA","VC","VE","VG","VI","VN","VU","WF","WS","YE","YT","ZA","ZM","ZW"];
}
/**
 * Checks a normalized subdivision code against country-aware client lists.
 *
 * @param {string} value Candidate subdivision or region code.
 * @param {string} country Optional country context for narrowing accepted codes.
 * @returns {boolean} Whether the subdivision code is accepted for the country context.
 */
function dpPanelValidSubdivisionCode(value,country){
	value=dpPanelNormalizeSubdivision(value);
	if(value===""){return false;}
	return dpPanelKnownSubdivisionCodes(country).indexOf(value)!==-1;
}
/**
 * Provides subdivision codes known to the client validator.
 *
 * When a supported country is supplied, only that country's codes are returned.
 * Otherwise a de-duplicated fallback list across all embedded maps is provided.
 *
 * @param {string} country Optional country code.
 * @returns {string[]} Supported subdivision codes.
 */
function dpPanelKnownSubdivisionCodes(country){
	country=dpPanelNormalizeCountry(country||"");
	var map={
		CA:["AB","BC","MB","NB","NL","NS","NT","NU","ON","PE","QC","SK","YT"],
		US:["AL","AK","AZ","AR","CA","CO","CT","DE","FL","GA","HI","ID","IL","IN","IA","KS","KY","LA","ME","MD","MA","MI","MN","MS","MO","MT","NE","NV","NH","NJ","NM","NY","NC","ND","OH","OK","OR","PA","RI","SC","SD","TN","TX","UT","VT","VA","WA","WV","WI","WY","DC"],
		AU:["ACT","NSW","NT","QLD","SA","TAS","VIC","WA"],
		NZ:["AUK","WGN","CAN","OTA"],
		EU:["FR","DE","NL","IE"]
	};
	if(map[country]){return map[country];}
	return Object.keys(map).reduce(function(codes,key){return codes.concat(map[key]);},[]).filter(function(code,index,codes){return codes.indexOf(code)===index;});
}
/**
 * Checks a normalized ISO currency code against the embedded currency list.
 *
 * @param {string} value Candidate currency code.
 * @returns {boolean} Whether the code is known to Panel's client validator.
 */
function dpPanelValidCurrencyCode(value){
	value=dpPanelNormalizeCurrencyCode(value);
	if(value===""){return false;}
	return dpPanelKnownCurrencyCodes().indexOf(value)!==-1;
}
/**
 * Provides ISO-style currency codes used by client-side semantic validation.
 *
 * @returns {string[]} Supported uppercase currency codes.
 */
function dpPanelKnownCurrencyCodes(){
	return ["AED","AFN","ALL","AMD","ANG","AOA","ARS","AUD","AWG","AZN","BAM","BBD","BDT","BGN","BHD","BIF","BMD","BND","BOB","BOV","BRL","BSD","BTN","BWP","BYN","BZD","CAD","CDF","CHE","CHF","CHW","CLF","CLP","CNY","COP","COU","CRC","CUC","CUP","CVE","CZK","DJF","DKK","DOP","DZD","EGP","ERN","ETB","EUR","FJD","FKP","GBP","GEL","GHS","GIP","GMD","GNF","GTQ","GYD","HKD","HNL","HTG","HUF","IDR","ILS","INR","IQD","IRR","ISK","JMD","JOD","JPY","KES","KGS","KHR","KMF","KPW","KRW","KWD","KYD","KZT","LAK","LBP","LKR","LRD","LSL","LYD","MAD","MDL","MGA","MKD","MMK","MNT","MOP","MRU","MUR","MVR","MWK","MXN","MXV","MYR","MZN","NAD","NGN","NIO","NOK","NPR","NZD","OMR","PAB","PEN","PGK","PHP","PKR","PLN","PYG","QAR","RON","RSD","RUB","RWF","SAR","SBD","SCR","SDG","SEK","SGD","SHP","SLE","SLL","SOS","SRD","SSP","STN","SVC","SYP","SZL","THB","TJS","TMT","TND","TOP","TRY","TTD","TWD","TZS","UAH","UGX","USD","USN","UYI","UYU","UYW","UZS","VED","VES","VND","VUV","WST","XAF","XAG","XAU","XBA","XBB","XBC","XBD","XCD","XDR","XOF","XPD","XPF","XPT","XSU","XTS","XUA","XXX","YER","ZAR","ZMW","ZWL"];
}
/**
 * Checks an IPv4 literal with strict octet range and leading-zero rules.
 *
 * @param {string} value Candidate IPv4 address.
 * @returns {boolean} Whether the value is a valid IPv4 literal.
 */
function dpPanelValidIpv4(value){
	value=String(value||"").trim();
	if(!/^(\d{1,3}\.){3}\d{1,3}$/.test(value)){return false;}
	return value.split(".").every(function(part){
		if(part.length>1&&part.charAt(0)==="0"){return false;}
		var number=parseInt(part,10);
		return number>=0&&number<=255;
	});
}
/**
 * Checks an IPv6 literal with compact notation support.
 *
 * @param {string} value Candidate IPv6 address.
 * @returns {boolean} Whether the value is a valid IPv6 literal.
 */
function dpPanelValidIpv6(value){
	value=String(value||"").trim().toLowerCase();
	if(value===""||value.indexOf(":")===-1||/[^0-9a-f:]/.test(value)){return false;}
	if((value.match(/::/g)||[]).length>1){return false;}
	var pieces=value.split(":");
	if(value.indexOf("::")===-1&&pieces.length!==8){return false;}
	if(value.indexOf("::")!==-1&&pieces.length>8){return false;}
	return pieces.every(function(piece){return piece===""||/^[0-9a-f]{1,4}$/.test(piece);});
}
/**
 * Checks whether a value is either a valid IPv4 or IPv6 literal.
 *
 * @param {string} value Candidate IP address.
 * @returns {boolean} Whether the value is a valid IP literal.
 */
function dpPanelValidIpAddress(value){
	return dpPanelValidIpv4(value)||dpPanelValidIpv6(value);
}
/**
 * Checks a normalized MAC address in colon-separated uppercase form.
 *
 * @param {string} value Candidate MAC address.
 * @returns {boolean} Whether the value matches six hexadecimal octets.
 */
function dpPanelValidMacAddress(value){
	return /^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/.test(dpPanelNormalizeMacAddress(value));
}
/**
 * Checks a normalized RFC 4122 UUID value.
 *
 * @param {string} value Candidate UUID.
 * @returns {boolean} Whether the value matches an accepted UUID version and variant.
 */
function dpPanelValidUuid(value){
	return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(dpPanelNormalizeUuid(value));
}
/**
 * Checks a normalized ULID value.
 *
 * @param {string} value Candidate ULID.
 * @returns {boolean} Whether the value matches the Crockford Base32 ULID shape.
 */
function dpPanelValidUlid(value){
	return /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(dpPanelNormalizeUlid(value));
}
/**
 * Checks a normalized six-digit hex color.
 *
 * @param {string} value Candidate color value.
 * @returns {boolean} Whether the value is a full #RRGGBB color.
 */
function dpPanelValidHexColor(value){
	return /^#[0-9a-f]{6}$/.test(dpPanelNormalizeHexColor(value));
}
/**
 * Checks a coordinate after Panel coordinate formatting.
 *
 * @param {string|number} value Candidate coordinate.
 * @param {number} min Minimum accepted numeric value.
 * @param {number} max Maximum accepted numeric value.
 * @returns {boolean} Whether the coordinate is numeric and within range.
 */
function dpPanelValidCoordinate(value,min,max){
	value=dpPanelFormatCoordinate(value,{decimals:10});
	if(value===""||value==="-"||isNaN(Number(value))){return false;}
	var number=Number(value);
	return number>=min&&number<=max;
}
/**
 * Checks a latitude,longitude coordinate pair.
 *
 * @param {string} value Candidate coordinate pair.
 * @returns {boolean} Whether the pair has valid latitude and longitude values.
 */
function dpPanelValidCoordinatePair(value){
	value=dpPanelFormatCoordinatePair(value,{decimals:10});
	var parts=value.split(",");
	return parts.length===2&&dpPanelValidCoordinate(parts[0],-90,90)&&dpPanelValidCoordinate(parts[1],-180,180);
}
/**
 * Checks a payment card number with length, repetition, and Luhn validation.
 *
 * @param {string} value Candidate card number.
 * @returns {boolean} Whether the digits pass the client-side card checks.
 */
function dpPanelValidCreditCardNumber(value){
	var digits=String(value||"").replace(/\D+/g,"");
	if(!/^[0-9]{12,19}$/.test(digits)||/^([0-9])\1+$/.test(digits)){return false;}
	var sum=0;
	var doubleDigit=false;
	for(var index=digits.length-1;index>=0;index--){
		var digit=parseInt(digits.charAt(index),10);
		if(doubleDigit){
			digit*=2;
			if(digit>9){digit-=9;}
		}
		sum+=digit;
		doubleDigit=!doubleDigit;
	}
	return sum%10===0;
}
/**
 * Checks an MMYY credit card expiry against the current browser date.
 *
 * @param {string} value Candidate expiry value.
 * @returns {boolean} Whether the month is valid and the expiry is not in the past.
 */
function dpPanelValidCreditCardExpiry(value){
	var digits=String(value||"").replace(/\D+/g,"");
	if(!/^[0-9]{4}$/.test(digits)){return false;}
	var month=parseInt(digits.slice(0,2),10);
	if(month<1||month>12){return false;}
	var year=2000+parseInt(digits.slice(2),10);
	var now=new Date();
	var currentYear=now.getFullYear();
	var currentMonth=now.getMonth()+1;
	return year>currentYear||(year===currentYear&&month>=currentMonth);
}
/**
 * Checks an IBAN with shape validation and ISO 7064 mod-97 checksum.
 *
 * @param {string} value Candidate IBAN.
 * @returns {boolean} Whether the IBAN has a valid structure and checksum.
 */
function dpPanelValidIban(value){
	var iban=String(value||"").replace(/[^0-9A-Za-z]+/g,"").toUpperCase();
	if(!/^[A-Z]{2}[0-9]{2}[0-9A-Z]{11,30}$/.test(iban)){return false;}
	var rearranged=iban.slice(4)+iban.slice(0,4);
	var mod=0;
	for(var index=0;index<rearranged.length;index++){
		var char=rearranged.charAt(index);
		if(/[0-9]/.test(char)){
			mod=(mod*10+parseInt(char,10))%97;
		}
		else if(/[A-Z]/.test(char)){
			var code=String(char.charCodeAt(0)-55);
			for(var offset=0;offset<code.length;offset++){
				mod=(mod*10+parseInt(code.charAt(offset),10))%97;
			}
		}
		else{
			return false;
		}
	}
	return mod===1;
}
/**
 * Inserts pasted text into an input using the field formatting pipeline.
 *
 * The helper preserves caret behavior where possible and dispatches input/change
 * events so dependent masks, counters, and form state react consistently.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|null} input Field receiving pasted text.
 * @param {string|null} text Pasted text payload.
 * @returns {boolean} Whether text was inserted.
 */
function dpPanelApplyPastedValue(input,text){
	if(!input||text===undefined||text===null){return false;}
	text=String(text);
	if(text===""){return false;}
	var value=String(input.value||"");
	var start=typeof input.selectionStart==="number" ? input.selectionStart : value.length;
	var end=typeof input.selectionEnd==="number" ? input.selectionEnd : start;
	input.value=value.slice(0,start)+text+value.slice(end);
	try{input.setSelectionRange(start+text.length,start+text.length);}catch(error){}
	dpPanelApplyInputFormatting(input,"paste");
	input.dispatchEvent(new Event("input",{bubbles:true}));
	input.dispatchEvent(new Event("change",{bubbles:true}));
	return true;
}
/**
 * Computes the normalized value that should be submitted for a formatted input.
 *
 * The selected normalization rule may be explicit or resolved through the field's
 * effective format. Display-only characters are removed for masks, locale-aware
 * semantic formats are normalized, and case transforms reuse the Panel formatter.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|null} input Field with submit normalization metadata.
 * @returns {string} Value prepared for FormData submission.
 */
function dpPanelNormalizedSubmitValue(input){
	var rule=String((input&&input.dataset.dpPanelSubmitNormalized)||"").toLowerCase();
	var value=String((input&&input.value)||"");
	if(!rule){return value;}
	if(input&&input.dataset&&input.dataset.dpPanelFormat){
		rule=dpPanelEffectiveFormatRule(rule,input,dpPanelParseFormatOptions(input));
	}
	if(rule==="mask"){return value.replace(/[^0-9A-Za-z]+/g,"");}
	if(rule==="currency"||rule==="money"||rule==="percent"||rule==="percentage"){
		var decimal=value.replace(/[^\d.\-]+/g,"");
		var negative=decimal.charAt(0)==="-";
		decimal=decimal.replace(/\-/g,"");
		var pieces=decimal.split(".");
		var integer=(pieces.shift()||"").replace(/\D+/g,"").replace(/^0+(?=\d)/,"");
		var fraction=pieces.join("").replace(/\D+/g,"");
		return (negative?"-":"")+(integer||"0")+(fraction!=="" ? "."+fraction : "");
	}
	if(rule==="phone_international"){return dpPanelNormalizeInternationalPhoneValue(value,input);}
	if(rule==="phone"||rule==="phone_us"||rule==="phone_ca"||rule==="credit_card"||rule==="card"||rule==="credit_card_expiry"||rule==="card_expiry"||rule==="card_cvc"||rule==="cvc"||rule==="cvv"||rule==="digits"||rule==="ssn"||rule==="social_security_number"||rule==="ein"||rule==="tax_id"||rule==="zip_code_us"||rule==="postal_code_us"||rule==="zip"){return value.replace(/\D+/g,"");}
	if(rule==="postal_code_au"||rule==="australian_postcode"){return value.replace(/\D+/g,"");}
	if(rule==="postal_code_nz"||rule==="new_zealand_postcode"){return value.replace(/\D+/g,"");}
	if(rule==="postal_code_fr"||rule==="french_postcode"||rule==="postal_code_de"||rule==="german_postcode"){return value.replace(/\D+/g,"");}
	if(rule==="postal_code_nl"||rule==="dutch_postcode"||rule==="postal_code_ie"||rule==="eircode"){return value.replace(/[^0-9A-Za-z]+/g,"").toUpperCase();}
	if(rule==="postal_code_ca"||rule==="canadian_postal_code"||rule==="postal_code_gb"||rule==="uk_postcode"||rule==="postal_code_international"||rule==="iban"||rule==="alphanumeric"){return value.replace(/[^0-9A-Za-z]+/g,"").toUpperCase();}
	if(rule==="alpha"){return value.replace(/[^A-Za-z]+/g,"");}
	if(rule==="email"){return value.trim().toLowerCase();}
	if(rule==="url"){return dpPanelNormalizeUrl(value);}
	if(rule==="map_url"||rule==="maps_url"){return dpPanelNormalizeMapUrl(value);}
	if(rule==="domain"||rule==="hostname"){return dpPanelNormalizeDomain(value);}
	if(rule==="timezone"||rule==="time_zone"){return dpPanelNormalizeTimezone(value);}
	if(rule==="locale"||rule==="language_tag"){return dpPanelNormalizeLocale(value);}
	if(rule==="json"||rule==="json_text"){return dpPanelNormalizeJson(value);}
	if(rule==="mime_type"||rule==="content_type"){return dpPanelNormalizeMimeType(value);}
	if(rule==="semver"||rule==="semantic_version"){return dpPanelNormalizeSemver(value);}
	if(rule==="cron_expression"||rule==="cron"){return dpPanelNormalizeCronExpression(value);}
	if(rule==="language_code"||rule==="iso_language"){return dpPanelNormalizeLanguageCode(value);}
	if(rule==="country_code"||rule==="iso_country"){return dpPanelNormalizeCountryCode(value);}
	if(rule==="subdivision_code"||rule==="region_code"){return dpPanelNormalizeSubdivision(value);}
	if(rule==="currency_code"||rule==="iso_currency"){return dpPanelNormalizeCurrencyCode(value);}
	if(rule==="ip_address"||rule==="ip"||rule==="ipv4"||rule==="ipv6"){return value.trim().toLowerCase();}
	if(rule==="mac_address"||rule==="mac"){return dpPanelNormalizeMacAddress(value);}
	if(rule==="uuid"){return dpPanelNormalizeUuid(value);}
	if(rule==="ulid"){return dpPanelNormalizeUlid(value);}
	if(rule==="hex_color"||rule==="color_hex"){return dpPanelNormalizeHexColor(value);}
	if(rule==="latitude"||rule==="longitude"){return dpPanelFormatCoordinate(value,dpPanelParseFormatOptions(input));}
	if(rule==="coordinates"||rule==="lat_lng"||rule==="lng_lat"){
		var pairOptions=dpPanelParseFormatOptions(input);
		if(rule==="lng_lat"){pairOptions=Object.assign({},pairOptions,{order:"lng_lat"});}
		return dpPanelFormatCoordinatePair(value,pairOptions);
	}
	if(rule==="lowercase"){return value.trim().toLowerCase();}
	if(rule==="uppercase"){return value.trim().toUpperCase();}
	if(rule==="title_case"){return dpPanelApplyFormatValue(value,"title_case",{});}
	if(rule==="sentence_case"){return dpPanelApplyFormatValue(value,"sentence_case",{});}
	if(rule==="snake_case"){return dpPanelApplyFormatValue(value,"snake_case",{});}
	if(rule==="kebab_case"){return dpPanelApplyFormatValue(value,"kebab_case",{});}
	if(rule==="camel_case"){return dpPanelApplyFormatValue(value,"camel_case",{});}
	if(rule==="trim"){return value.trim();}
	return value;
}
/**
 * Rewrites FormData entries for fields that opt into normalized submission.
 *
 * File, checkbox, radio, and select controls are skipped because their submission
 * shape is managed by the browser or dedicated Panel widgets.
 *
 * @param {HTMLFormElement|null} form Form being submitted.
 * @param {FormData|null} formData Mutable FormData payload.
 * @returns {void}
 */
function dpPanelNormalizeFormData(form,formData){
	if(!form||!formData||typeof formData.set!=="function"){return;}
	form.querySelectorAll("[data-dp-panel-submit-normalized]").forEach(function(input){
		if(!input.name||input.disabled||input.type==="file"||input.type==="checkbox"||input.type==="radio"||input.tagName==="SELECT"){return;}
		formData.set(input.name,dpPanelNormalizedSubmitValue(input));
	});
}
/**
 * Finds the input controlled by a field button.
 *
 * @param {HTMLElement} button Field action button.
 * @returns {HTMLInputElement|HTMLTextAreaElement|HTMLSelectElement|null} Controlled field.
 */
function dpPanelFieldButtonTarget(button){
	var shell=button.closest("[data-dp-panel-input-shell]");
	return shell ? shell.querySelector("input:not([type='hidden']),textarea,select") : null;
}
/**
 * Applies a numeric step operation while respecting min, max, and precision.
 *
 * @param {HTMLInputElement} input Numeric input.
 * @param {number} direction Positive or negative step direction.
 * @returns {void}
 */
function dpPanelNumericStep(input,direction){
	var current=parseFloat(String(input.value||"").replace(/,/g,""));
	var step=parseFloat(input.getAttribute("step")||"1");
	var min=input.getAttribute("min");
	var max=input.getAttribute("max");
	min=min===null||min==="" ? null : parseFloat(min);
	max=max===null||max==="" ? null : parseFloat(max);
	if(!isFinite(step)||step===0){step=1;}
	if(!isFinite(current)){current=min!==null&&isFinite(min) ? min : 0;}
	var next=current+(direction*step);
	if(min!==null&&isFinite(min)){next=Math.max(min,next);}
	if(max!==null&&isFinite(max)){next=Math.min(max,next);}
	var precision=0;
	[String(step), String(current)].forEach(function(value){
		var dot=value.indexOf(".");
		if(dot!==-1){precision=Math.max(precision,value.length-dot-1);}
	});
	input.value=precision>0 ? next.toFixed(Math.min(8,precision)) : String(Math.round(next));
}
/**
 * Runs a Panel field button action against its target input.
 *
 * Built-in actions cover clearing, copying, password visibility, date/time
 * insertion, casing, slugging, trimming, setting fixed values, and numeric steps.
 * Registered runtime handlers receive the same field and formatting callback.
 *
 * @param {HTMLElement} button Field action button.
 * @returns {void}
 */
function dpPanelRunFieldButton(button){
	var input=dpPanelFieldButtonTarget(button);
	if(!input){return;}
	var action=(button.dataset.dpPanelFieldButton||"").toLowerCase();
	var runtime=dpPanelRuntime();
	if(runtime.fieldButtons[action]){
		runtime.fieldButtons[action](input,button,{format:function(){dpPanelApplyInputFormatting(input,"button");}});
	}
	else if(action==="clear"){
		input.value="";
	}
	else if(action==="copy"){
		dpPanelApplyInputFormatting(input,"button");
		var copyValue=button.dataset.dpPanelFieldButtonCopy==="normalized" ? dpPanelNormalizedSubmitValue(input) : (input.value||"");
		if(navigator.clipboard){navigator.clipboard.writeText(copyValue).catch(function(){});}
		button.dataset.dpPanelFieldButtonState="copied";
		var previous=button.getAttribute("aria-label")||button.getAttribute("title")||dpPanelText("common.copy","Copy");
		button.setAttribute("aria-label",dpPanelText("common.copied","Copied"));
		button.setAttribute("title",dpPanelText("common.copied","Copied"));
		clearTimeout(button._dpPanelCopiedTimer);
		button._dpPanelCopiedTimer=setTimeout(function(){
			button.dataset.dpPanelFieldButtonState="";
			button.setAttribute("aria-label",previous);
			button.setAttribute("title",previous);
		},1200);
	}
	else if(action==="toggle_password"||action==="password_toggle"||action==="show_password"){
		input.type=input.type==="password" ? "text" : "password";
		button.dataset.dpPanelFieldButtonState=input.type==="text" ? "active" : "";
		button.setAttribute("aria-pressed",input.type==="text" ? "true" : "false");
	}
	else if(action==="today"){
		var today=new Date();
		input.value=(new Date(today.getTime()-(today.getTimezoneOffset()*60000))).toISOString().slice(0,10);
	}
	else if(action==="now"){
		input.value=(new Date(Date.now()-((new Date()).getTimezoneOffset()*60000))).toISOString().slice(0,16);
	}
	else if(action==="upper"||action==="uppercase"){
		input.value=(input.value||"").toUpperCase();
	}
	else if(action==="lower"||action==="lowercase"){
		input.value=(input.value||"").toLowerCase();
	}
	else if(action==="title"||action==="title_case"||action==="sentence"||action==="sentence_case"||action==="snake"||action==="snake_case"||action==="kebab"||action==="kebab_case"||action==="camel"||action==="camel_case"||action==="digits"||action==="alpha"||action==="alphanumeric"){
		var rule=action;
		if(rule==="title"){rule="title_case";}
		if(rule==="sentence"){rule="sentence_case";}
		if(rule==="snake"){rule="snake_case";}
		if(rule==="kebab"){rule="kebab_case";}
		if(rule==="camel"){rule="camel_case";}
		input.value=dpPanelApplyFormatValue(input.value,rule,dpPanelParseFormatOptions(input));
	}
	else if(action==="slug"){
		input.value=dpPanelApplyFormatValue(input.value,"slug",{});
	}
	else if(action==="trim"){
		input.value=(input.value||"").trim();
	}
	else if(action==="set"){
		input.value=button.dataset.dpPanelFieldButtonValue||"";
	}
	else if(action==="increment"||action==="step_up"||action==="plus"){
		dpPanelNumericStep(input,1);
	}
	else if(action==="decrement"||action==="step_down"||action==="minus"){
		dpPanelNumericStep(input,-1);
	}
	dpPanelApplyInputFormatting(input,"button");
	input.dispatchEvent(new Event("input",{bubbles:true}));
	input.dispatchEvent(new Event("change",{bubbles:true}));
	input.focus();
}
/**
 * Refreshes derived formatted fields when a source field changes.
 *
 * Source-field derived values are regenerated only when the target is still empty
 * or still matches the previous generated value, preserving user edits.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|HTMLSelectElement|null} source Changed source field.
 * @returns {void}
 */
function dpPanelRefreshLocaleFormatsForSource(source){
	var form=source&&source.form ? source.form : source&&source.closest&&source.closest("form");
	if(!form){return;}
	var sourceName=source.getAttribute&&String(source.getAttribute("name")||"").replace(/\[\]$/,"");
	if(!sourceName){return;}
	form.querySelectorAll("[data-dp-panel-format]").forEach(function(input){
		var options=dpPanelParseFormatOptions(input);
		if(dpPanelFieldSourceMatches(input,source,String(options.source_field||""))){
			dpPanelRefreshFormatFromSource(input,source,options);
			return;
		}
		if(!dpPanelFieldSourceMatches(input,source,String(options.country_field||""))&&!dpPanelFieldSourceMatches(input,source,String(options.subdivision_field||""))){return;}
		dpPanelApplyInputFormatting(input,"change");
	});
}
/**
 * Regenerates one formatted field from a matching source field.
 *
 * @param {HTMLElement|null} input Target formatted field.
 * @param {HTMLElement|null} source Source field.
 * @param {Object|null} options Parsed format options.
 * @returns {void}
 */
function dpPanelRefreshFormatFromSource(input,source,options){
	if(!input||!source||!options){return;}
	var rule=dpPanelEffectiveFormatRule(input.dataset.dpPanelFormat,input,options);
	if(!rule){return;}
	var current=String(input.value||"");
	var generated=dpPanelApplyFormatValue(String(source.value||""),rule,options||{});
	if(current!==""&&current!==input.dataset.dpPanelGeneratedValue){return;}
	input.value=generated;
	input.dataset.dpPanelGeneratedValue=generated;
	dpPanelApplyInputFormatting(input,"change");
	input.dispatchEvent(new Event("input",{bubbles:true}));
	input.dispatchEvent(new Event("change",{bubbles:true}));
}
/**
 * Seeds generated-value tracking for a source-derived formatted field.
 *
 * Initial priming lets later source changes distinguish untouched generated values
 * from values the user has manually edited.
 *
 * @param {HTMLElement|null} input Target formatted field.
 * @returns {void}
 */
function dpPanelPrimeFormatFromSource(input){
	if(!input){return;}
	var options=dpPanelParseFormatOptions(input);
	var sourceName=String(options.source_field||"");
	if(!sourceName){return;}
	var form=input.form ? input.form : input.closest&&input.closest("form");
	if(!form){return;}
	var rule=dpPanelEffectiveFormatRule(input.dataset.dpPanelFormat,input,options);
	if(!rule){return;}
	var sources=form.querySelectorAll("input,select,textarea");
	for(var i=0;i<sources.length;i++){
		var source=sources[i];
		if(source===input||!dpPanelFieldSourceMatches(input,source,sourceName)){continue;}
		var generated=dpPanelApplyFormatValue(String(source.value||""),rule,options||{});
		var current=String(input.value||"");
		if(current===""||current===generated){
			input.dataset.dpPanelGeneratedValue=generated;
			if(current===""){
				dpPanelRefreshFormatFromSource(input,source,options);
			}
		}
		return;
	}
}
/**
 * Parses uploader JSON metadata with a caller-provided fallback.
 *
 * @param {string} value JSON text from uploader data attributes or responses.
 * @param {*} fallback Value returned when parsing fails or input is empty.
 * @returns {*} Parsed JSON value or fallback.
 */
function dpPanelUploaderParseJson(value,fallback){
	if(!value){return fallback;}
	try{return JSON.parse(value);}catch(error){return fallback;}
}
/**
 * Resolves uploader localized text with token interpolation.
 *
 * Per-uploader labels override the global Panel text catalog, allowing each field
 * to customize upload status and policy language.
 *
 * @param {HTMLElement|null} uploader Uploader shell.
 * @param {string} key Translation key suffix.
 * @param {string} fallback Fallback message.
 * @param {Object} tokens Replacement token map.
 * @returns {string} Localized uploader text.
 */
function dpPanelUploaderText(uploader,key,fallback,tokens){
	var labels=dpPanelUploaderParseJson((uploader&&uploader.dataset.dpPanelUploaderI18n)||"",{});
	var text=labels&&labels[key]!==undefined ? String(labels[key]) : dpPanelText("uploader."+key,String(fallback||""),tokens||{});
	tokens=tokens||{};
	Object.keys(tokens).forEach(function(token){
		text=text.split("{"+token+"}").join(String(tokens[token]));
	});
	return text;
}
/**
 * Formats a byte count for uploader status and policy copy.
 *
 * @param {number|string} bytes Byte count.
 * @returns {string} Human-readable size label.
 */
function dpPanelUploaderFormatBytes(bytes){
	bytes=Math.max(0,parseInt(bytes||0,10)||0);
	if(bytes>=1073741824){return (bytes/1073741824).toFixed(2)+" GB";}
	if(bytes>=1048576){return (bytes/1048576).toFixed(2)+" MB";}
	if(bytes>=1024){return (bytes/1024).toFixed(1)+" KB";}
	return bytes+" B";
}
/**
 * Composes the uploader accept and max-size policy label.
 *
 * @param {HTMLElement|null} uploader Uploader shell.
 * @returns {string} Human-readable upload policy summary.
 */
function dpPanelUploaderPolicyLabel(uploader){
	var accept=String((uploader&&uploader.dataset.dpPanelUploaderAcceptLabel)||"").trim();
	var maxSize=parseInt((uploader&&uploader.dataset.dpPanelUploaderMaxSize)||"0",10)||0;
	var parts=[];
	if(accept){parts.push(accept);}
	if(maxSize>0){parts.push(dpPanelUploaderText(uploader,"files_up_to","up to {count}",{count:dpPanelUploaderFormatBytes(maxSize)}));}
	return parts.join(", ");
}
/**
 * Formats per-chunk transfer detail for an active upload.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} state Upload state object.
 * @param {number} index Zero-based chunk index.
 * @param {number} total Total chunk count.
 * @param {number} loaded Bytes loaded in the current chunk.
 * @returns {string} Transfer detail text.
 */
function dpPanelUploaderTransferDetail(uploader,state,index,total,loaded){
	if(!state||!state.file){return "";}
	var now=Date.now();
	state.startedAt=state.startedAt||now;
	var elapsed=Math.max(1,(now-state.startedAt)/1000);
	var sent=Math.min(state.file.size||0,(index*state.chunkSize)+(loaded||0));
	var speed=sent>0 ? dpPanelUploaderText(uploader,"transfer_speed"," at {speed}/s",{speed:dpPanelUploaderFormatBytes(sent/elapsed)}) : "";
	return dpPanelUploaderText(uploader,"transfer_detail","{sent} of {size} - chunk {index} of {total}{speed}",{
		sent:dpPanelUploaderFormatBytes(sent),
		size:dpPanelUploaderFormatBytes(state.file.size),
		index:index+1,
		total:total,
		speed:speed
	});
}
/**
 * Creates a locally unique upload identifier for a File object.
 *
 * @param {File|Object} file File-like object.
 * @returns {string} Upload identifier for request correlation and row state.
 */
function dpPanelUploaderFileId(file){
	var raw=[file.name,file.size,file.type,file.lastModified||Date.now(),Math.random().toString(36).slice(2)].join("|");
	var hash=0;
	for(var index=0;index<raw.length;index++){hash=((hash<<5)-hash)+raw.charCodeAt(index);hash|=0;}
	return "up_"+Date.now().toString(36)+"_"+Math.abs(hash).toString(36);
}
/**
 * Updates the uploader status region and tone.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {string} text Status text.
 * @param {string} tone Visual state token.
 * @returns {void}
 */
function dpPanelUploaderSetStatus(uploader,text,tone){
	var status=uploader.querySelector("[data-dp-panel-uploader-status]");
	if(!status){return;}
	status.textContent=text;
	status.dataset.dpPanelUploaderTone=tone||"idle";
}
/**
 * Recalculates aggregate uploader progress, completion, and status text.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @returns {void}
 */
function dpPanelUploaderRefreshSummary(uploader){
	var rows=Array.prototype.slice.call(uploader.querySelectorAll("[data-dp-panel-uploader-item]"));
	var total=uploader.querySelector("[data-dp-panel-uploader-total]");
	var status=uploader.querySelector("[data-dp-panel-uploader-status]");
	if(!total){return;}
	var bar=total.querySelector("span");
	if(!rows.length){
		total.hidden=true;
		if(status&&status.textContent===""){dpPanelUploaderSetStatus(uploader,dpPanelUploaderText(uploader,"status_empty","No files queued."),"idle");}
		return;
	}
	var complete=0;
	var failed=0;
	var active=0;
	var percent=0;
	rows.forEach(function(row){
		var tone=row.dataset.dpPanelUploaderTone||"queued";
		if(tone==="complete"){complete++;}
		else if(tone==="error"){failed++;}
		else if(tone!=="cancelled"){active++;}
		var progress=row.querySelector(".dp-panel-uploader-progress span");
		var width=progress ? parseFloat(progress.style.width||"0")||0 : (tone==="complete" ? 100 : 0);
		percent+=Math.max(0,Math.min(100,width));
	});
	var average=Math.round(percent/rows.length);
	total.hidden=false;
	total.dataset.dpPanelUploaderTone=failed>0 ? "error" : (complete===rows.length ? "complete" : (active>0 ? "uploading" : "idle"));
	if(bar){bar.style.width=average+"%";}
	if(status){
		var label=dpPanelUploaderText(uploader,"status_complete","{complete} of {total} complete",{complete:complete,total:rows.length});
		if(failed>0){label+=" - "+dpPanelUploaderText(uploader,"status_failed","{failed} failed",{failed:failed});}
		else if(active>0){label+=" - "+dpPanelUploaderText(uploader,"status_uploading","uploading");}
		dpPanelUploaderSetStatus(uploader,label,total.dataset.dpPanelUploaderTone);
	}
}
/**
 * Finds the hidden field that persists uploader state.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @returns {HTMLInputElement|null} Hidden storage input.
 */
function dpPanelUploaderHidden(uploader){
	return uploader.querySelector("input[type='hidden'][name='"+dpPanelCssEscape(uploader.dataset.dpPanelUploaderName||"")+"']");
}
/**
 * Retrieves stored uploader items from cache or hidden input JSON.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @returns {Object[]} Stored file item objects.
 */
function dpPanelUploaderCurrentItems(uploader){
	if(Array.isArray(uploader._dpPanelUploaderItems)){
		return uploader._dpPanelUploaderItems.slice();
	}
	var hidden=dpPanelUploaderHidden(uploader);
	if(!hidden){return [];}
	var multiple=uploader.dataset.dpPanelUploaderMultiple==="1";
	var value=hidden.value||"";
	if(!value){return [];}
	var parsed=dpPanelUploaderParseJson(value,multiple?[]:null);
	var items=Array.isArray(parsed) ? parsed : (parsed ? [parsed] : []);
	uploader._dpPanelUploaderItems=items.slice();
	return items;
}
/**
 * Persists uploader items to the hidden input and refreshes constraints.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object[]} items Stored file item objects.
 * @returns {void}
 */
function dpPanelUploaderSetItems(uploader,items){
	var hidden=dpPanelUploaderHidden(uploader);
	if(!hidden){return;}
	var multiple=uploader.dataset.dpPanelUploaderMultiple==="1";
	var clean=items.filter(function(item){return item&&typeof item==="object";});
	uploader._dpPanelUploaderItems=clean.slice();
	hidden.value=multiple ? JSON.stringify(clean) : (clean[0] ? JSON.stringify(clean[0]) : "");
	hidden.dispatchEvent(new Event("input",{bubbles:true}));
	hidden.dispatchEvent(new Event("change",{bubbles:true}));
	dpPanelUploaderRefreshSummary(uploader);
	dpPanelUploaderRefreshConstraints(uploader,false);
}
/**
 * Inserts or replaces one stored uploader item.
 *
 * Single-file uploaders clear existing items before storing the new result.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} item Stored file item.
 * @returns {void}
 */
function dpPanelUploaderSyncItem(uploader,item){
	var items=dpPanelUploaderCurrentItems(uploader);
	var multiple=uploader.dataset.dpPanelUploaderMultiple==="1";
	if(!multiple){items=[];}
	var replaced=false;
	items=items.map(function(existing){
		if(existing&&item&&(existing.upload_id===item.upload_id||existing.id===item.id||existing.path===item.path)){
			replaced=true;
			return item;
		}
		return existing;
	});
	if(!replaced){items.push(item);}
	dpPanelUploaderSetItems(uploader,items);
}
/**
 * Reorders a stored uploader item in the hidden persisted item list.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} state Row upload state.
 * @param {number} direction Move direction, usually -1 or 1.
 * @returns {void}
 */
function dpPanelUploaderMoveStoredItem(uploader,state,direction){
	if(!state||!direction){return;}
	var items=dpPanelUploaderCurrentItems(uploader);
	var index=items.findIndex(function(item){
		return item&&(item.upload_id===state.id||item.id===state.id||item.path===state.path);
	});
	var next=index+direction;
	if(index<0||next<0||next>=items.length){return;}
	var item=items[index];
	items[index]=items[next];
	items[next]=item;
	dpPanelUploaderSetItems(uploader,items);
}
/**
 * Collects completed item payloads from rendered uploader rows.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @returns {Object[]} Stored item payloads represented by current rows.
 */
function dpPanelUploaderRowItems(uploader){
	return Array.prototype.slice.call(uploader.querySelectorAll("[data-dp-panel-uploader-item]")).map(function(row){
		var state=row._dpPanelUploaderState;
		return state&&state.item ? state.item : null;
	}).filter(function(item){return item&&typeof item==="object";});
}
/**
 * Counts completed uploader rows.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @returns {number} Completed stored item count.
 */
function dpPanelUploaderCompletedCount(uploader){
	return dpPanelUploaderRowItems(uploader).length;
}
/**
 * Produces min/max file constraint messaging for the current uploader state.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {number} nextCount Optional projected item count.
 * @returns {string} Constraint message, or an empty string when valid.
 */
function dpPanelUploaderConstraintMessage(uploader,nextCount){
	var min=parseInt(uploader.dataset.dpPanelUploaderMinFiles||"0",10)||0;
	var max=parseInt(uploader.dataset.dpPanelUploaderMaxFiles||"0",10)||0;
	var count=typeof nextCount==="number" ? nextCount : dpPanelUploaderCompletedCount(uploader);
	if(min>0&&count<min){
		return dpPanelUploaderText(uploader,"constraint_min","Add at least {count} {file}.",{count:min,file:dpPanelUploaderText(uploader,min===1?"file_singular":"file_plural",min===1?"file":"files")});
	}
	if(max>0&&count>max){
		return dpPanelUploaderText(uploader,"constraint_max","Remove files until only {count} {remain}.",{count:max,remain:dpPanelUploaderText(uploader,max===1?"remain_singular":"remain_plural",max===1?"remains":"remain")});
	}
	return "";
}
/**
 * Refreshes uploader validity state and optionally shows the constraint message.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {boolean} showMessage Whether to surface constraint text in the status region.
 * @returns {boolean} Whether current file count constraints are satisfied.
 */
function dpPanelUploaderRefreshConstraints(uploader,showMessage){
	var message=dpPanelUploaderConstraintMessage(uploader);
	uploader.dataset.dpPanelUploaderInvalid=message!=="" ? "1" : "0";
	if(message!==""&&showMessage===true){
		dpPanelUploaderSetStatus(uploader,message,"error");
	}
	return message==="";
}
/**
 * Adds configured uploader fields and safe custom headers to an XHR request.
 *
 * Header names are restricted to HTTP token characters before being applied.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {XMLHttpRequest} xhr Request object.
 * @param {FormData} form Request form payload.
 * @returns {void}
 */
function dpPanelUploaderApplyRequestMetadata(uploader,xhr,form){
	var fields=dpPanelUploaderParseJson(uploader.dataset.dpPanelUploaderFields||"",{});
	Object.keys(fields).forEach(function(key){
		if(fields[key]!==null&&fields[key]!==undefined){form.append(key,String(fields[key]));}
	});
	var headers=dpPanelUploaderParseJson(uploader.dataset.dpPanelUploaderHeaders||"",{});
	Object.keys(headers).forEach(function(name){
		if(/^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/.test(name)&&headers[name]!==null&&headers[name]!==undefined){
			xhr.setRequestHeader(name,String(headers[name]));
		}
	});
}
/**
 * Sends the optional server-side delete request for a stored uploader item.
 *
 * Missing endpoints resolve as skipped deletes so purely client-side removal can
 * share the same workflow.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} state Row upload state.
 * @returns {Promise<Object>} Delete response payload.
 */
function dpPanelUploaderDeleteRequest(uploader,state){
	return new Promise(function(resolve,reject){
		var endpoint=uploader.dataset.dpPanelUploaderDeleteEndpoint||"";
		if(!endpoint||!state||!state.item){resolve({ok:true,skipped:true});return;}
		var xhr=new XMLHttpRequest();
		var form=new FormData();
		var item=state.item||{};
		form.append("dp_panel_upload_delete","1");
		form.append("field",uploader.dataset.dpPanelUploaderName||"");
		form.append("upload_id",String(item.upload_id||item.id||state.id||""));
		form.append("disk",String(item.disk||""));
		form.append("path",String(item.path||state.path||""));
		form.append("filename",String(item.filename||item.name||state.file.name||""));
		xhr.open("POST",endpoint,true);
		xhr.withCredentials=true;
		xhr.setRequestHeader("Accept","application/json");
		xhr.setRequestHeader("X-Requested-With","DataphyrePanelUploaderDelete");
		dpPanelUploaderApplyRequestMetadata(uploader,xhr,form);
		xhr.onload=function(){
			var payload=dpPanelUploaderParseJson(xhr.responseText||"",{});
			if(xhr.status>=200&&xhr.status<300&&(payload.ok===true||payload.deleted===true)){
				resolve(payload);
				return;
			}
			reject(new Error(payload.error||dpPanelUploaderText(uploader,"delete_failed_http","Delete failed with HTTP {status}",{status:xhr.status})));
		};
		xhr.onerror=function(){reject(new Error(dpPanelUploaderText(uploader,"delete_network_error","Delete network error.")));};
		xhr.send(form);
	});
}
/**
 * Removes a stored item from the hidden uploader payload.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} state Row upload state.
 * @returns {void}
 */
function dpPanelUploaderRemoveStoredItem(uploader,state){
	if(!state){return;}
	var items=dpPanelUploaderCurrentItems(uploader).filter(function(item){
		return item&&item.upload_id!==state.id&&item.id!==state.id&&item.path!==state.path;
	});
	dpPanelUploaderSetItems(uploader,items);
}
/**
 * Creates row state for an already persisted uploader item.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} item Stored file item from hidden input JSON.
 * @returns {Object} Upload state shaped like an active row state.
 */
function dpPanelUploaderExistingState(uploader,item){
	item=item&&typeof item==="object" ? item : {};
	var name=String(item.original_name||item.filename||item.name||item.path||dpPanelUploaderText(uploader,"uploaded_file","Uploaded file")).split("/").pop();
	var size=parseInt(item.size||0,10)||0;
	var mime=String(item.mime||item.type||"");
	return {
		id:String(item.upload_id||item.id||item.path||name||dpPanelUploaderFileId({name:name,size:size,type:mime,lastModified:Date.now()})),
		path:String(item.path||""),
		stored:true,
		item:item,
		file:{name:name,size:size,type:mime,lastModified:0},
		url:typeof item.url==="string" ? item.url : ""
	};
}
/**
 * Creates and binds the DOM row for an uploader state object.
 *
 * Rows own preview rendering, retry, remove, and reorder actions. Stored rows are
 * immediately marked complete, while active rows are updated by upload progress.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} state Upload state object.
 * @returns {HTMLElement|null} Rendered uploader row.
 */
function dpPanelUploaderRow(uploader,state){
	var list=uploader.querySelector("[data-dp-panel-uploader-list]");
	if(!list){return null;}
	var row=document.createElement("div");
	row.className="dp-panel-uploader-item";
	row.dataset.dpPanelUploaderItem=state.id;
	row._dpPanelUploaderState=state;
	row.innerHTML='<div class="dp-panel-uploader-preview" aria-hidden="true"></div><div class="dp-panel-uploader-item-main"><strong></strong><small></small><em class="dp-panel-uploader-transfer"></em><div class="dp-panel-uploader-progress"><span></span></div></div><div class="dp-panel-uploader-item-state" aria-live="polite"></div><div class="dp-panel-uploader-actions"><button type="button" class="dp-panel-uploader-move" data-dp-panel-uploader-move="-1"></button><button type="button" class="dp-panel-uploader-move" data-dp-panel-uploader-move="1"></button><button type="button" class="dp-panel-uploader-retry" hidden></button><button type="button" class="dp-panel-uploader-remove"></button></div>';
	row.querySelector("strong").textContent=state.file.name;
	row.querySelector(".dp-panel-uploader-item-state").textContent=dpPanelUploaderText(uploader,"queued","Queued");
	row.querySelector("small").textContent=(state.file.size ? dpPanelUploaderFormatBytes(state.file.size) : dpPanelUploaderText(uploader,"stored_file","Stored file"))+(state.file.type ? " - "+state.file.type : "");
	var moveUp=row.querySelector("[data-dp-panel-uploader-move='-1']");
	var moveDown=row.querySelector("[data-dp-panel-uploader-move='1']");
	var retryButton=row.querySelector(".dp-panel-uploader-retry");
	var removeButton=row.querySelector(".dp-panel-uploader-remove");
	if(moveUp){moveUp.title=dpPanelUploaderText(uploader,"move_up","Move up");moveUp.textContent=dpPanelUploaderText(uploader,"up","Up");}
	if(moveDown){moveDown.title=dpPanelUploaderText(uploader,"move_down","Move down");moveDown.textContent=dpPanelUploaderText(uploader,"down","Down");}
	if(retryButton){retryButton.textContent=dpPanelUploaderText(uploader,"retry","Retry");}
	if(removeButton){removeButton.textContent=dpPanelUploaderText(uploader,"remove","Remove");}
	var preview=row.querySelector(".dp-panel-uploader-preview");
	if(preview&&state.url&&state.file.type&&state.file.type.indexOf("image/")===0){
		var existingImg=document.createElement("img");
		existingImg.src=state.url;
		existingImg.alt="";
		preview.appendChild(existingImg);
	}
	else if(preview&&state.file.type&&state.file.type.indexOf("image/")===0&&state.file instanceof File&&window.URL&&URL.createObjectURL){
		var img=document.createElement("img");
		state.previewUrl=URL.createObjectURL(state.file);
		img.src=state.previewUrl;
		img.alt="";
		preview.appendChild(img);
	}
	else if(preview){
		preview.textContent=(state.file.name.split(".").pop()||"FILE").slice(0,4).toUpperCase();
	}
	retryButton.addEventListener("click",function(){
		retryButton.hidden=true;
		state.cancelled=false;
		dpPanelUploaderUploadFile(uploader,state);
	});
	removeButton.addEventListener("click",function(){
		state.cancelled=true;
		if(state.xhr&&typeof state.xhr.abort==="function"){state.xhr.abort();}
		/**
		 * Finalizes client-side row removal after optional server deletion.
		 *
		 * @returns {void}
		 */
		function finishRemove(){
			dpPanelUploaderRemoveStoredItem(uploader,state);
			if(state.previewUrl&&window.URL&&URL.revokeObjectURL){URL.revokeObjectURL(state.previewUrl);}
			row.remove();
			if(uploader.dataset.dpPanelUploaderMultiple==="1"){dpPanelUploaderSetItems(uploader,dpPanelUploaderRowItems(uploader));}
			dpPanelUploaderSetStatus(uploader,dpPanelUploaderText(uploader,"file_removed","File removed."),"idle");
			dpPanelUploaderRefreshSummary(uploader);
		}
		if(state.item&&uploader.dataset.dpPanelUploaderDeleteEndpoint){
			dpPanelUploaderUpdateRow(row,100,dpPanelUploaderText(uploader,"removing","Removing"),"retrying");
			dpPanelUploaderDeleteRequest(uploader,state).then(finishRemove).catch(function(error){
				state.cancelled=false;
				dpPanelUploaderUpdateRow(row,100,error.message||dpPanelUploaderText(uploader,"remove_failed","Remove failed."),"error");
				dpPanelUploaderSetStatus(uploader,error.message||dpPanelUploaderText(uploader,"remove_failed","Remove failed."),"error");
			});
			return;
		}
		finishRemove();
	});
	row.querySelectorAll("[data-dp-panel-uploader-move]").forEach(function(button){
		button.addEventListener("click",function(){
			var direction=parseInt(button.dataset.dpPanelUploaderMove||"0",10)||0;
			if(!direction){return;}
			var sibling=direction<0 ? row.previousElementSibling : row.nextElementSibling;
			if(!sibling){return;}
			if(direction<0){row.parentNode.insertBefore(row,sibling);}
			else{row.parentNode.insertBefore(sibling,row);}
			if(uploader.dataset.dpPanelUploaderMultiple==="1"){dpPanelUploaderSetItems(uploader,dpPanelUploaderRowItems(uploader));}
			else{dpPanelUploaderMoveStoredItem(uploader,state,direction);}
		});
	});
	list.appendChild(row);
	if(state.stored){
		state.item=state.item||{};
		row._dpPanelUploaderState=state;
		dpPanelUploaderUpdateRow(row,100,dpPanelUploaderText(uploader,"complete","Complete"),"complete");
	}
	dpPanelUploaderRefreshSummary(uploader);
	return row;
}
/**
 * Updates visual progress, row tone, state text, and transfer detail for a row.
 *
 * @param {HTMLElement|null} row Uploader row.
 * @param {number} percent Progress percentage.
 * @param {string} label Row state label.
 * @param {string} tone Visual state token.
 * @param {string} detail Optional transfer detail text.
 * @returns {void}
 */
function dpPanelUploaderUpdateRow(row,percent,label,tone,detail){
	if(!row){return;}
	var bar=row.querySelector(".dp-panel-uploader-progress span");
	var state=row.querySelector(".dp-panel-uploader-item-state");
	var transfer=row.querySelector(".dp-panel-uploader-transfer");
	var value=Math.max(0,Math.min(100,Math.round(percent||0)));
	if(bar){bar.style.width=value+"%";}
	if(state){state.textContent=label||value+"%";}
	if(transfer&&detail!==undefined){transfer.textContent=detail||"";}
	row.dataset.dpPanelUploaderTone=tone||"uploading";
	var uploader=row.closest("[data-dp-panel-uploader]");
	if(uploader){dpPanelUploaderRefreshSummary(uploader);}
}
/**
 * Uploads one file chunk to the configured uploader endpoint.
 *
 * The request includes field metadata, storage options, chunk coordinates, and the
 * binary chunk. Progress updates the owning row while success and failure resolve
 * through a JSON response contract.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} state Upload state object.
 * @param {Blob} chunk File chunk.
 * @param {number} index Zero-based chunk index.
 * @param {number} total Total chunk count.
 * @param {number} attempt Current retry attempt.
 * @returns {Promise<Object>} Parsed upload response payload.
 */
function dpPanelUploaderRequest(uploader,state,chunk,index,total,attempt){
	return new Promise(function(resolve,reject){
		var endpoint=uploader.dataset.dpPanelUploaderEndpoint||"";
		if(!endpoint){reject(new Error(dpPanelUploaderText(uploader,"upload_no_endpoint","No upload endpoint configured.")));return;}
		var xhr=new XMLHttpRequest();
		state.xhr=xhr;
		var form=new FormData();
		var storage=dpPanelUploaderParseJson(uploader.dataset.dpPanelUploaderStorage||"",{});
		form.append("dp_panel_upload","1");
		form.append("field",uploader.dataset.dpPanelUploaderName||"");
		form.append("upload_id",state.id);
		form.append("filename",state.file.name);
		form.append("size",String(state.file.size));
		form.append("type",state.file.type||"application/octet-stream");
		form.append("chunk_index",String(index));
		form.append("chunks",String(total));
		form.append("chunk_size",String(chunk.size));
		Object.keys(storage).forEach(function(key){form.append("storage_"+key,String(storage[key]));});
		form.append("file",chunk,state.file.name);
		xhr.open("POST",endpoint,true);
		xhr.withCredentials=true;
		xhr.setRequestHeader("Accept","application/json");
		xhr.setRequestHeader("X-Requested-With","DataphyrePanelUploader");
		dpPanelUploaderApplyRequestMetadata(uploader,xhr,form);
		xhr.upload.onprogress=function(event){
			if(!event.lengthComputable){return;}
			var base=(index/total)*100;
			var part=(event.loaded/event.total)*(100/total);
			dpPanelUploaderUpdateRow(state.row,base+part,dpPanelUploaderText(uploader,"uploading_percent","Uploading {percent}%",{percent:Math.round(base+part)}),"uploading",dpPanelUploaderTransferDetail(uploader,state,index,total,event.loaded));
		};
		xhr.onload=function(){
			var payload=dpPanelUploaderParseJson(xhr.responseText||"",{});
			if(xhr.status>=200&&xhr.status<300&&(payload.ok===true||payload.complete===true||payload.pending===true)){
				resolve(payload);
				return;
			}
			reject(new Error(payload.error||dpPanelUploaderText(uploader,"upload_failed_http","Upload failed with HTTP {status}",{status:xhr.status})));
		};
		xhr.onerror=function(){reject(new Error(dpPanelUploaderText(uploader,"upload_network_error","Upload network error.")));};
		xhr.onabort=function(){reject(new Error(state.cancelled ? dpPanelUploaderText(uploader,"upload_cancelled","Upload cancelled.") : dpPanelUploaderText(uploader,"upload_aborted","Upload aborted.")));};
		xhr.send(form);
	});
}
/**
 * Uploads one chunk with bounded retry behavior.
 *
 * Retries back off slightly between attempts and stop immediately when the row is
 * cancelled or the configured retry count is exhausted.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} state Upload state object.
 * @param {number} index Zero-based chunk index.
 * @param {number} total Total chunk count.
 * @param {number} retries Maximum retry count.
 * @returns {Promise<Object>} Parsed response for the chunk.
 */
function dpPanelUploaderUploadChunk(uploader,state,index,total,retries){
	var start=index*state.chunkSize;
	var end=Math.min(state.file.size,start+state.chunkSize);
	var chunk=state.file.slice(start,end);
	var attempt=0;
	/**
	 * Executes the current chunk upload attempt and schedules retries.
	 *
	 * @returns {Promise<Object>} Parsed response for the chunk.
	 */
	function run(){
		if(state.cancelled){return Promise.reject(new Error(dpPanelUploaderText(uploader,"upload_cancelled","Upload cancelled.")));}
		return dpPanelUploaderRequest(uploader,state,chunk,index,total,attempt).catch(function(error){
			if(state.cancelled){throw error;}
			if(attempt>=retries){throw error;}
			attempt++;
			dpPanelUploaderUpdateRow(state.row,(index/total)*100,dpPanelUploaderText(uploader,"retry_of","Retry {attempt} of {retries}",{attempt:attempt,retries:retries}),"retrying");
			return new Promise(function(resolve){setTimeout(resolve,Math.min(2400,300*attempt));}).then(run);
		});
	}
	return run();
}
/**
 * Uploads a full file as an ordered chain of chunk requests.
 *
 * Completion stores the server-provided file payload in the hidden input, updates
 * row state, and leaves failed rows retryable unless the user cancelled transfer.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {Object} state Upload state object.
 * @returns {Promise<void>} Resolves after completion or handled failure.
 */
function dpPanelUploaderUploadFile(uploader,state){
	var retries=parseInt(uploader.dataset.dpPanelUploaderRetries||"3",10)||0;
	var total=Math.max(1,Math.ceil(state.file.size/state.chunkSize));
	var lastPayload=null;
	state.cancelled=false;
	state.startedAt=Date.now();
	state.row=state.row||dpPanelUploaderRow(uploader,state);
	dpPanelUploaderSetStatus(uploader,dpPanelUploaderText(uploader,"uploading_file","Uploading {file}.",{file:state.file.name}),"uploading");
	dpPanelUploaderUpdateRow(state.row,0,dpPanelUploaderText(uploader,"queued","Queued"),"queued",dpPanelUploaderText(uploader,"waiting_slot","Waiting for transfer slot."));
	var chain=Promise.resolve();
	for(var index=0;index<total;index++){
		(function(chunkIndex){
			chain=chain.then(function(){
				if(state.cancelled){throw new Error(dpPanelUploaderText(uploader,"upload_cancelled","Upload cancelled."));}
				return dpPanelUploaderUploadChunk(uploader,state,chunkIndex,total,retries).then(function(payload){
					lastPayload=payload;
					dpPanelUploaderUpdateRow(state.row,((chunkIndex+1)/total)*100,dpPanelUploaderText(uploader,"uploading_percent","Uploading {percent}%",{percent:Math.round(((chunkIndex+1)/total)*100)}),"uploading",dpPanelUploaderText(uploader,"chunk_stored","Chunk {index} of {total} stored.",{index:chunkIndex+1,total:total}));
				});
			});
		})(index);
	}
	return chain.then(function(){
		var item=(lastPayload&&lastPayload.file) ? lastPayload.file : {
			upload_id:state.id,
			original_name:state.file.name,
			name:state.file.name,
			size:state.file.size,
			mime:state.file.type||"application/octet-stream"
		};
		item.upload_id=item.upload_id||state.id;
		state.path=item.path||"";
		state.item=item;
		if(state.row){state.row._dpPanelUploaderState=state;}
		if(uploader.dataset.dpPanelUploaderMultiple==="1"){dpPanelUploaderSetItems(uploader,dpPanelUploaderRowItems(uploader));}
		else{dpPanelUploaderSyncItem(uploader,item);}
		dpPanelUploaderUpdateRow(state.row,100,dpPanelUploaderText(uploader,"complete","Complete"),"complete",item.path ? dpPanelUploaderText(uploader,"stored_at","Stored at {path}",{path:item.path}) : dpPanelUploaderText(uploader,"stored_ready","Stored and ready to submit."));
		dpPanelUploaderRefreshSummary(uploader);
	}).catch(function(error){
		if(state.cancelled){
			dpPanelUploaderUpdateRow(state.row,0,dpPanelUploaderText(uploader,"cancelled","Cancelled"),"cancelled",dpPanelUploaderText(uploader,"cancel_detail","Transfer cancelled before completion."));
			return;
		}
		dpPanelUploaderUpdateRow(state.row,0,error.message||dpPanelUploaderText(uploader,"upload_failed","Upload failed"),"error",dpPanelUploaderText(uploader,"retry_available","Retry is available if the file still matches policy."));
		var retry=state.row ? state.row.querySelector(".dp-panel-uploader-retry") : null;
		if(retry){retry.hidden=false;}
		dpPanelUploaderSetStatus(uploader,error.message||dpPanelUploaderText(uploader,"upload_failed","Upload failed"),"error");
	});
}
/**
 * Checks a selected file against the input accept attribute.
 *
 * Extension, exact MIME type, and wildcard MIME rules are supported.
 *
 * @param {HTMLInputElement|null} input File input.
 * @param {File} file Selected file.
 * @returns {boolean} Whether the file matches accepted types.
 */
function dpPanelUploaderAcceptsFile(input,file){
	if(!input||!input.accept){return true;}
	var accepted=input.accept.split(",").map(function(item){return item.trim().toLowerCase();}).filter(Boolean);
	if(!accepted.length){return true;}
	var name=(file.name||"").toLowerCase();
	var type=(file.type||"").toLowerCase();
	return accepted.some(function(rule){
		if(rule==="*"||rule==="*/*"){return true;}
		if(rule.charAt(0)==="."){return name.endsWith(rule);}
		if(rule.endsWith("/*")){return type.indexOf(rule.slice(0,-1))===0;}
		return type===rule;
	});
}
/**
 * Queues selected or dropped files for policy validation and upload.
 *
 * The queue enforces single/multiple mode, max file count, max size, accepted
 * types, chunk size, and concurrency before dispatching upload workers.
 *
 * @param {HTMLElement} uploader Uploader shell.
 * @param {FileList|File[]} files Selected or dropped files.
 * @returns {void}
 */
function dpPanelUploaderQueueFiles(uploader,files){
	var input=uploader.querySelector("[data-dp-panel-uploader-input]");
	var list=Array.prototype.slice.call(files||[]);
	if(!list.length){return;}
	var maxSize=parseInt(uploader.dataset.dpPanelUploaderMaxSize||"0",10)||0;
	var maxFiles=parseInt(uploader.dataset.dpPanelUploaderMaxFiles||"0",10)||0;
	var chunkSize=parseInt(uploader.dataset.dpPanelUploaderChunkSize||"5242880",10)||5242880;
	if(uploader.dataset.dpPanelUploaderMultiple!=="1"){list=list.slice(0,1);dpPanelUploaderSetItems(uploader,[]);var existing=uploader.querySelector("[data-dp-panel-uploader-list]");if(existing){existing.innerHTML="";}}
	if(maxFiles>0&&uploader.dataset.dpPanelUploaderMultiple==="1"){
		var currentRows=uploader.querySelectorAll("[data-dp-panel-uploader-item]:not([data-dp-panel-uploader-tone='error']):not([data-dp-panel-uploader-tone='cancelled'])").length;
		var room=Math.max(0,maxFiles-currentRows);
		if(room<=0){
			dpPanelUploaderSetStatus(uploader,dpPanelUploaderText(uploader,"remove_before_add","Remove a file before adding another."),"error");
			dpPanelUploaderRefreshConstraints(uploader,true);
			return;
		}
		if(list.length>room){
			list=list.slice(0,room);
			dpPanelUploaderSetStatus(uploader,dpPanelUploaderText(uploader,"only_more","Only {count} more {file} can be added.",{
				count:room,
				file:dpPanelUploaderText(uploader,room===1 ? "file_singular" : "file_plural",room===1 ? "file" : "files")
			}),"error");
		}
	}
	var queue=list.map(function(file){
		var state={id:dpPanelUploaderFileId(file),file:file,chunkSize:chunkSize,row:null};
		state.row=dpPanelUploaderRow(uploader,state);
		if(maxSize>0&&file.size>maxSize){
			dpPanelUploaderUpdateRow(state.row,0,dpPanelUploaderText(uploader,"too_large","Too large"),"error",dpPanelUploaderText(uploader,"too_large_detail","{size} exceeds the {limit} limit.",{
				size:dpPanelUploaderFormatBytes(file.size),
				limit:dpPanelUploaderFormatBytes(maxSize)
			}));
			dpPanelUploaderSetStatus(uploader,dpPanelUploaderText(uploader,"too_large_status","{file} is too large. Accepted: {policy}.",{
				file:file.name,
				policy:dpPanelUploaderPolicyLabel(uploader)||dpPanelUploaderText(uploader,"configured_policy","configured policy")
			}),"error");
			return null;
		}
		if(!dpPanelUploaderAcceptsFile(input,file)){
			dpPanelUploaderUpdateRow(state.row,0,dpPanelUploaderText(uploader,"type_not_accepted","Type not accepted"),"error",dpPanelUploaderText(uploader,"type_not_accepted_detail","Accepted types: {types}.",{
				types:uploader.dataset.dpPanelUploaderAcceptLabel||input.accept||dpPanelUploaderText(uploader,"configured_policy","configured policy")
			}));
			dpPanelUploaderSetStatus(uploader,dpPanelUploaderText(uploader,"type_not_accepted_status","{file} is not an accepted file type.",{file:file.name}),"error");
			return null;
		}
		return state;
	}).filter(Boolean);
	var concurrency=parseInt(uploader.dataset.dpPanelUploaderConcurrency||"2",10)||2;
	var active=0;
	var index=0;
	/**
	 * Starts queued uploads while concurrency slots are available.
	 *
	 * @returns {void}
	 */
	function pump(){
		while(active<concurrency&&index<queue.length){
			active++;
			dpPanelUploaderUploadFile(uploader,queue[index++]).finally(function(){active--;pump();});
		}
	}
	pump();
	dpPanelUploaderRefreshConstraints(uploader,false);
}
/**
 * Initializes a Panel uploader shell and restores existing uploaded rows.
 *
 * Initialization binds browse, input, drag/drop, keyboard activation, stored item
 * hydration, and initial summary/constraint refresh in an idempotent pass.
 *
 * @param {HTMLElement|null} uploader Uploader shell.
 * @returns {void}
 */
function dpPanelInitUploader(uploader){
	if(!uploader||uploader.dataset.dpPanelUploaderReady==="1"){return;}
	uploader.dataset.dpPanelUploaderReady="1";
	var input=uploader.querySelector("[data-dp-panel-uploader-input]");
	var drop=uploader.querySelector("[data-dp-panel-uploader-drop]");
	var browse=uploader.querySelector("[data-dp-panel-uploader-browse]");
	if(browse&&input){browse.addEventListener("click",function(){input.click();});}
	if(input){input.addEventListener("change",function(){dpPanelUploaderQueueFiles(uploader,input.files);input.value="";});}
	if(drop){
		["dragenter","dragover"].forEach(function(type){
			drop.addEventListener(type,function(event){event.preventDefault();drop.classList.add("dp-panel-uploader-drop-active");});
		});
		["dragleave","drop"].forEach(function(type){
			drop.addEventListener(type,function(event){event.preventDefault();drop.classList.remove("dp-panel-uploader-drop-active");});
		});
		drop.addEventListener("drop",function(event){
			if(event.dataTransfer&&event.dataTransfer.files){dpPanelUploaderQueueFiles(uploader,event.dataTransfer.files);}
		});
		drop.addEventListener("keydown",function(event){
			if((event.key==="Enter"||event.key===" ")&&input){event.preventDefault();input.click();}
		});
	}
	var list=uploader.querySelector("[data-dp-panel-uploader-list]");
	if(list&&!list.children.length){
		dpPanelUploaderCurrentItems(uploader).forEach(function(item){
			dpPanelUploaderRow(uploader,dpPanelUploaderExistingState(uploader,item));
		});
	}
	dpPanelUploaderRefreshConstraints(uploader,false);
}
/**
 * Initializes all Panel uploader shells below a root element.
 *
 * @param {ParentNode|null} root Root search scope, defaulting to document.
 * @returns {void}
 */
function dpPanelInitUploaders(root){
	(root||document).querySelectorAll("[data-dp-panel-uploader='1']").forEach(dpPanelInitUploader);
}
/**
 * Parses an RGB or RGBA CSS color into numeric channels.
 *
 * Transparent colors and unsupported formats return null so callers can continue
 * walking inherited backgrounds.
 *
 * @param {string} value CSS color value from computed style.
 * @returns {number[]|null} RGB channels, or null when the color cannot be measured.
 */
function dpPanelCssColorToRgb(value){
	if(!value||value==="transparent"){return null;}
	var match=String(value).match(/rgba?\(([^)]+)\)/i);
	if(!match){return null;}
	var parts=match[1].split(",").map(function(part){return part.trim();});
	if(parts.length<3){return null;}
	var alpha=parts.length>3 ? parseFloat(parts[3]) : 1;
	if(alpha===0){return null;}
	return [parseFloat(parts[0]),parseFloat(parts[1]),parseFloat(parts[2])];
}
/**
 * Calculates WCAG relative luminance for RGB channels.
 *
 * @param {number[]} rgb RGB channels in 0-255 range.
 * @returns {number} Relative luminance value.
 */
function dpPanelRelativeLuminance(rgb){
	return rgb.map(function(value){
		value=Math.max(0,Math.min(255,value))/255;
		return value<=0.03928 ? value/12.92 : Math.pow((value+0.055)/1.055,2.4);
	}).reduce(function(total,value,index){
		return total+value*([0.2126,0.7152,0.0722][index]||0);
	},0);
}
/**
 * Calculates WCAG contrast ratio between foreground and background colors.
 *
 * @param {number[]} foreground Foreground RGB channels.
 * @param {number[]} background Background RGB channels.
 * @returns {number} Contrast ratio.
 */
function dpPanelContrastRatio(foreground,background){
	var first=dpPanelRelativeLuminance(foreground);
	var second=dpPanelRelativeLuminance(background);
	var light=Math.max(first,second);
	var dark=Math.min(first,second);
	return (light+0.05)/(dark+0.05);
}
/**
 * Finds the nearest measurable background color for an element.
 *
 * Transparent ancestors are skipped until a concrete background is found; white is
 * used as the final fallback to keep contrast checks deterministic.
 *
 * @param {Element|null} element Element whose background should be resolved.
 * @returns {number[]} RGB background channels.
 */
function dpPanelElementBackgroundColor(element){
	while(element&&element.nodeType===1){
		var color=dpPanelCssColorToRgb(getComputedStyle(element).backgroundColor);
		if(color){return color;}
		element=element.parentElement;
	}
	return [255,255,255];
}
/**
 * Chooses the element measured by a field accessibility policy.
 *
 * Specialized controls such as choice lists, ratings, uploaders, and input shells
 * are preferred over the field wrapper so width and touch metrics reflect the
 * actual interactive surface.
 *
 * @param {HTMLElement} field Panel field wrapper.
 * @returns {HTMLElement} Element used for accessibility measurements.
 */
function dpPanelA11yPolicyTarget(field){
	var choiceList=field.querySelector(".dp-panel-choice-list");
	if(choiceList){return choiceList;}
	var rating=field.querySelector("[data-dp-panel-rating],.dp-panel-rating");
	if(rating){return rating;}
	var uploader=field.querySelector("[data-dp-panel-uploader],.dp-panel-uploader-drop");
	if(uploader){return uploader;}
	return field.querySelector("[data-dp-panel-input-shell] .dp-panel-input-control")
		|| field.querySelector("[data-dp-panel-input-shell]")
		|| field.querySelector("input:not([type='hidden']),textarea,select")
		|| field;
}
/**
 * Labels the kind of element selected as the policy measurement target.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @param {HTMLElement|null} target Measurement target.
 * @returns {string} Stable target kind token for diagnostics.
 */
function dpPanelA11yPolicyTargetKind(field,target){
	if(!field||!target){return "field";}
	if(target.matches&&target.matches(".dp-panel-input-control")){return "control";}
	if(target.matches&&target.matches("[data-dp-panel-input-shell]")){return "shell";}
	if(target.matches&&target.matches(".dp-panel-choice-list")){return "choices";}
	if(target.matches&&target.matches("[data-dp-panel-rating],.dp-panel-rating")){return "rating";}
	if(target.matches&&target.matches("[data-dp-panel-uploader],.dp-panel-uploader-drop")){return "uploader";}
	if(target.matches&&target.matches("input:not([type='hidden']),textarea,select")){return "input";}
	return target===field ? "field" : "element";
}
/**
 * Resolves proxy controls used to measure interactive hit area.
 *
 * Hidden file inputs are represented by their uploader shell, and checkbox/radio
 * inputs are represented by their visible choice label when available.
 *
 * @param {HTMLElement|null} target Raw form control or widget element.
 * @returns {HTMLElement|null} Element whose visible rectangle should be measured.
 */
function dpPanelA11yEffectiveControlTarget(target){
	if(!target||!target.closest){return target;}
	var type=(target.getAttribute("type")||"").toLowerCase();
	if(type==="file"){
		var uploader=target.closest("[data-dp-panel-uploader]")||target.closest(".dp-panel-uploader-drop");
		if(uploader){
			var uploaderRect=uploader.getBoundingClientRect();
			if(uploaderRect.width>0&&uploaderRect.height>0){return uploader;}
		}
	}
	if(type==="radio"||type==="checkbox"){
		var choice=target.closest(".dp-panel-choice,.dp-panel-checkbox,label");
		if(choice){
			var choiceRect=choice.getBoundingClientRect();
			if(choiceRect.width>0&&choiceRect.height>0){return choice;}
		}
	}
	return target;
}
var dpPanelA11yMeasureCanvas=null;
/**
 * Measures rendered text width using the target element's computed font.
 *
 * A shared canvas avoids DOM measurement churn during policy refreshes.
 *
 * @param {HTMLElement|null} target Element supplying font style.
 * @param {string} text Text to measure.
 * @returns {number} Width in CSS pixels.
 */
function dpPanelA11yMeasureTextWidth(target,text){
	if(!target||!window.getComputedStyle){return 0;}
	var style=getComputedStyle(target);
	if(!dpPanelA11yMeasureCanvas){dpPanelA11yMeasureCanvas=document.createElement("canvas");}
	var context=dpPanelA11yMeasureCanvas.getContext("2d");
	if(!context){return 0;}
	context.font=style.font||[style.fontStyle,style.fontVariant,style.fontWeight,style.fontSize,style.fontFamily].filter(Boolean).join(" ");
	return context.measureText(text).width||0;
}
/**
 * Measures horizontal control padding used in usable-width calculations.
 *
 * @param {HTMLElement|null} target Control or shell element.
 * @returns {number} Combined left and right padding in CSS pixels.
 */
function dpPanelA11yControlPadding(target){
	if(!target||!window.getComputedStyle){return 0;}
	var style=getComputedStyle(target);
	return (parseFloat(style.paddingLeft)||0)+(parseFloat(style.paddingRight)||0);
}
/**
 * Estimates the average character width for a control.
 *
 * Canvas measurement is preferred, with a font-size heuristic as fallback for
 * environments where canvas context creation is unavailable.
 *
 * @param {HTMLElement} target Control or shell element.
 * @returns {number} Average character width in CSS pixels.
 */
function dpPanelA11yCharacterWidth(target){
	var sample="abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
	var measured=dpPanelA11yMeasureTextWidth(target,sample);
	if(measured>0){return measured/sample.length;}
	var fontSize=parseFloat(getComputedStyle(target).fontSize||"14")||14;
	return fontSize*0.55;
}
/**
 * Measures how much width input adornments consume inside a field shell.
 *
 * Already-stacked adornments are treated as having no inline pressure so repeated
 * refreshes do not keep inflating mitigation state.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @param {HTMLElement|null} target Measurement target.
 * @returns {Object} Width and ratio describing adornment pressure.
 */
function dpPanelA11yAdornmentPressure(field,target){
	var shell=field ? field.querySelector("[data-dp-panel-input-shell]") : null;
	if(!shell||!target||target===shell){
		return {width:0,ratio:0};
	}
	if(field.classList&&field.classList.contains("dp-panel-a11y-adornment-stacked")){
		return {width:0,ratio:0};
	}
	var shellWidth=shell.getBoundingClientRect().width;
	var targetWidth=target.getBoundingClientRect().width;
	var measuredWidth=Math.max(0,shellWidth-targetWidth);
	var adornmentWidth=0;
	shell.querySelectorAll(".dp-panel-input-adornments,.dp-panel-input-addon,.dp-panel-input-button,[data-dp-panel-field-button]").forEach(function(item){
		if(target.contains&&target.contains(item)){return;}
		var style=getComputedStyle(item);
		var rect=item.getBoundingClientRect();
		if(style.display!=="none"&&style.visibility!=="hidden"&&rect.width>0){adornmentWidth+=rect.width;}
	});
	var width=Math.max(measuredWidth,adornmentWidth);
	return {width:width,ratio:shellWidth>0 ? width/shellWidth : 0};
}
/**
 * Measures label and hint width pressure within a field.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {Object} Width and ratio describing label pressure.
 */
function dpPanelA11yLabelPressure(field){
	var label=field ? field.querySelector(".dp-panel-field-label") : null;
	if(!label){return {width:0,ratio:0};}
	if(field.classList&&field.classList.contains("dp-panel-a11y-label-stacked")){
		return {width:0,ratio:0};
	}
	var fieldWidth=field.getBoundingClientRect().width;
	var width=0;
	label.querySelectorAll(".dp-panel-field-label-text,.dp-panel-field-hint").forEach(function(item){
		var style=getComputedStyle(item);
		var rect=item.getBoundingClientRect();
		if(style.display!=="none"&&style.visibility!=="hidden"&&rect.width>0){width+=rect.width;}
	});
	return {width:width,ratio:fieldWidth>0 ? width/fieldWidth : 0};
}
/**
 * Applies or removes stacked label layout styles.
 *
 * Inline styles are used because this mitigation must override dense renderer
 * layouts without requiring additional generated CSS variants.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @param {boolean} stacked Whether label content should stack.
 * @returns {void}
 */
function dpPanelA11yApplyLabelStack(field,stacked){
	var label=field ? field.querySelector(".dp-panel-field-label") : null;
	if(!label){return;}
	label.style.setProperty("display",stacked ? "grid" : "",stacked ? "important" : "");
	label.style.setProperty("grid-template-columns",stacked ? "minmax(0,1fr)" : "",stacked ? "important" : "");
	label.style.setProperty("align-items",stacked ? "start" : "",stacked ? "important" : "");
	label.querySelectorAll(".dp-panel-field-hint").forEach(function(hint){
		hint.style.setProperty("max-width",stacked ? "100%" : "");
		hint.style.setProperty("margin-left",stacked ? "0" : "");
		hint.style.setProperty("white-space",stacked ? "normal" : "");
	});
	label.querySelectorAll(".dp-panel-field-label-text").forEach(function(text){
		text.style.setProperty("white-space",stacked ? "normal" : "");
		text.style.setProperty("overflow",stacked ? "visible" : "");
		text.style.setProperty("text-overflow",stacked ? "clip" : "");
	});
}
/**
 * Lists grid column CSS properties controlled by adaptive accessibility policies.
 *
 * @returns {string[]} Grid column custom properties and direct style property.
 */
function dpPanelA11yGridColumnProps(){
	return ["--dp-grid-column","--dp-grid-column-sm","--dp-grid-column-md","--dp-grid-column-lg","--dp-grid-column-xl","--dp-grid-column-2xl"];
}
/**
 * Saves original grid column styles before adaptive policies mutate them.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {void}
 */
function dpPanelA11yRememberGridColumns(field){
	if(!field||!field.dataset||field.dataset.dpPanelA11yOriginalGridColumns!==undefined){return;}
	var originals={"grid-column":field.style.getPropertyValue("grid-column")||""};
	dpPanelA11yGridColumnProps().forEach(function(prop){
		originals[prop]=field.style.getPropertyValue(prop)||"";
	});
	field.dataset.dpPanelA11yOriginalGridColumns=JSON.stringify(originals);
	field.dataset.dpPanelA11yOriginalGridColumn=originals["--dp-grid-column"]||"";
}
/**
 * Restores grid and DOM placement captured before adaptive policy changes.
 *
 * The function handles both modern serialized grid state and the older single
 * custom-property marker so fields can recover from previous runtime passes.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {void}
 */
function dpPanelA11yRestoreGridColumns(field){
	if(!field||!field.dataset){return;}
	if(field.dataset.dpPanelA11yOriginalParentId!==undefined){
		var parentId=field.dataset.dpPanelA11yOriginalParentId||"";
		var parent=parentId ? document.querySelector('[data-dp-panel-a11y-reflow-parent-id="'+dpPanelCssEscape(parentId)+'"]') : null;
		var index=parseInt(field.dataset.dpPanelA11yOriginalIndex||"-1",10);
		if(parent&&field.parentElement===parent&&index>=0){
			var reference=Array.prototype.slice.call(parent.children).filter(function(child){return child!==field;})[index]||null;
			parent.insertBefore(field,reference);
		}
		delete field.dataset.dpPanelA11yOriginalParentId;
		delete field.dataset.dpPanelA11yOriginalIndex;
	}
	if(field.dataset.dpPanelA11yOriginalGridColumns!==undefined){
		var originals={};
		try {originals=JSON.parse(field.dataset.dpPanelA11yOriginalGridColumns||"{}")||{};}
		catch(error){originals={};}
		var gridColumn=originals["grid-column"]||"";
		if(gridColumn){field.style.setProperty("grid-column",gridColumn);}
		else {field.style.removeProperty("grid-column");}
		dpPanelA11yGridColumnProps().forEach(function(prop){
			var value=originals[prop]||"";
			if(value){field.style.setProperty(prop,value);}
			else {field.style.removeProperty(prop);}
		});
		delete field.dataset.dpPanelA11yOriginalGridColumns;
		delete field.dataset.dpPanelA11yOriginalGridColumn;
		return;
	}
	if(field.dataset.dpPanelA11yOriginalGridColumn!==undefined){
		var original=field.dataset.dpPanelA11yOriginalGridColumn||"";
		if(original){field.style.setProperty("--dp-grid-column",original);}
		else {field.style.removeProperty("--dp-grid-column");}
		delete field.dataset.dpPanelA11yOriginalGridColumn;
	}
}
/**
 * Clears all adaptive accessibility classes, measurements, and status datasets.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {void}
 */
function dpPanelA11yResetAdaptiveField(field){
	if(!field||!field.dataset){return;}
	dpPanelA11yRestoreGridColumns(field);
	field.classList.remove("dp-panel-a11y-expanded");
	field.classList.remove("dp-panel-a11y-constrained");
	field.classList.remove("dp-panel-a11y-adornment-pressure");
	field.classList.remove("dp-panel-a11y-adornment-expanded");
	field.classList.remove("dp-panel-a11y-adornment-stacked");
	field.classList.remove("dp-panel-a11y-label-expanded");
	field.classList.remove("dp-panel-a11y-label-stacked");
	field.classList.remove("dp-panel-a11y-row-reflowed");
	dpPanelA11yApplyLabelStack(field,false);
	delete field.dataset.dpPanelA11yWidthStatus;
	delete field.dataset.dpPanelA11yUsableWidth;
	delete field.dataset.dpPanelA11yRequiredWidth;
	delete field.dataset.dpPanelA11yRequiredWidthSource;
	delete field.dataset.dpPanelA11yCharacterWidth;
	delete field.dataset.dpPanelA11yControlPadding;
	delete field.dataset.dpPanelA11yAdornmentStatus;
	delete field.dataset.dpPanelA11yAdornmentRatio;
	delete field.dataset.dpPanelA11yAdornmentWidth;
	delete field.dataset.dpPanelA11yLabelStatus;
	delete field.dataset.dpPanelA11yLabelRatio;
	delete field.dataset.dpPanelA11yLabelWidth;
	delete field.dataset.dpPanelA11yRowReflowStatus;
	delete field.dataset.dpPanelA11yRowReflowSpan;
	delete field.dataset.dpPanelA11yRowReflowSource;
	delete field.dataset.dpPanelA11yDomReflowStatus;
	delete field.dataset.dpPanelA11yIssues;
	delete field.dataset.dpPanelA11yActions;
	delete field.dataset.dpPanelA11yIssueMessages;
	delete field.dataset.dpPanelA11yActionMessages;
	delete field.dataset.dpPanelA11yIssueCount;
	delete field.dataset.dpPanelA11yActionCount;
	delete field.dataset.dpPanelA11yStatus;
	dpPanelA11yUpdateFieldStatus(field,{issue_messages:[],action_messages:[]});
}
/**
 * Chooses the element used for contrast measurement within a field.
 *
 * The scope may be inherited from an a11y default container or specified directly
 * on the field.
 *
 * @param {HTMLElement} field Panel field wrapper.
 * @returns {HTMLElement} Element whose foreground/background are checked.
 */
function dpPanelA11yContrastTarget(field){
	var scope=dpPanelA11yInheritedValue(field,"dpPanelA11yContrastScope")||"control";
	if(scope==="field"){return field;}
	if(scope==="label"){return field.querySelector(".dp-panel-field-label")||field;}
	return field.querySelector("[data-dp-panel-input-shell]")||field.querySelector("input:not([type='hidden']),textarea,select")||field;
}
/**
 * Resolves an accessibility policy value from the field or inherited defaults.
 *
 * Disabled fields return an empty value immediately. Otherwise direct field
 * configuration wins, followed by the nearest configured default container.
 *
 * @param {HTMLElement} field Panel field wrapper.
 * @param {string} name Dataset property name to resolve.
 * @returns {string} Effective dataset value.
 */
function dpPanelA11yInheritedValue(field,name){
	if(field&&field.dataset&&field.dataset.dpPanelA11yDisabled==="1"){return "";}
	var own=field.dataset ? field.dataset[name] : undefined;
	if(own!==undefined&&own!==""){return own;}
	var defaultName=name.replace(/^dpPanelA11y/,"dpPanelA11yDefault");
	var node=field.closest("[data-dp-panel-a11y-default='1']");
	while(node){
		if(node.dataset&&node.dataset[defaultName]!==undefined&&node.dataset[defaultName]!==""){
			return node.dataset[defaultName];
		}
		node=node.parentElement ? node.parentElement.closest("[data-dp-panel-a11y-default='1']") : null;
	}
	return "";
}
/**
 * Determines whether a field participates in accessibility policy processing.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {boolean} Whether policy checks should evaluate the field.
 */
JS;
	}

}
