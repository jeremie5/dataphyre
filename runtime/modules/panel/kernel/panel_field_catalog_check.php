<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

require_once dirname(__DIR__).'/Framework/Support/PanelExtensible.php';
require_once dirname(__DIR__).'/Framework/Support/PanelUtilityResolver.php';
require_once dirname(__DIR__).'/Framework/Support/PanelComponentRegistry.php';
require_once dirname(__DIR__).'/Framework/Support/PanelTrace.php';
require_once dirname(__DIR__).'/Framework/Core/PanelContext.php';
require_once dirname(__DIR__).'/Framework/Core/PanelConfig.php';
require_once dirname(__DIR__).'/Framework/Http/PanelRequest.php';
require_once dirname(__DIR__).'/Framework/Localization/PanelLocalization.php';
require_once dirname(__DIR__).'/Framework/Localization/PanelLocalizationScope.php';
require_once dirname(__DIR__).'/Framework/Resources/Resource.php';
require_once dirname(__DIR__).'/Framework/Forms/FormSection.php';
require_once dirname(__DIR__).'/Framework/Forms/Field.php';
require_once dirname(__DIR__).'/Framework/Theming/PanelThemeAsset.php';
require_once dirname(__DIR__).'/Framework/Theming/PanelThemePreset.php';
require_once dirname(__DIR__).'/Framework/Theming/PanelThemeLibrary.php';
require_once dirname(__DIR__).'/Framework/Theming/PanelTheme.php';
require_once dirname(__DIR__).'/Framework/Resources/ResourceForm.php';
require_once dirname(__DIR__).'/Framework/Schemas/SchemaComponent.php';
require_once dirname(__DIR__).'/Framework/Schemas/Schema.php';
require_once dirname(__DIR__).'/Framework/Schemas/SchemaLifecycle.php';
require_once dirname(__DIR__).'/Framework/Support/PanelFormState.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererPages.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererImports.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererActions.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererBulkOperations.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererShell.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererRecordSections.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererTables.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererData.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererForms.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRendererAssets.php';
require_once dirname(__DIR__).'/Framework/Rendering/PanelRenderer.php';

use Dataphyre\Panel\Field;
use Dataphyre\Panel\FormSection;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelThemePreset;
use Dataphyre\Panel\ResourceForm;

/**
 * Records a field catalog regression failure without stopping the rest of the harness.
 *
 * The catalog check aggregates failures so one run reports every broken field builder,
 * renderer assertion, or generated asset expectation instead of only the first mismatch.
 *
 * @param bool $condition Assertion result produced by the current check.
 * @param string $message Human-readable failure message emitted at process exit.
 * @param array<int, string> $failures Shared failure list mutated by reference.
 * @return void
 */
function panel_field_catalog_assert(bool $condition, string $message, array &$failures): void {
	if(!$condition){
		$failures[]=$message;
	}
}

/**
 * Reads a metadata value from a serialized Panel field definition.
 *
 * Field builders store validation, formatting, accessibility, and rendering controls under the
 * nested `meta` array. The harness centralizes access here so absent keys consistently compare
 * as `null`.
 *
 * @param array<string, mixed> $field Field array returned by `Field::toArray()`.
 * @param string $key Metadata key to read.
 * @return mixed Metadata value, or `null` when the key is absent.
 */
function panel_field_catalog_meta(array $field, string $key): mixed {
	return $field['meta'][$key] ?? null;
}

/**
 * Renders the private Panel field-control fragment for regression assertions.
 *
 * The production renderer keeps low-level control HTML private, so the catalog harness uses
 * reflection to inspect exact generated markup without broadening the public Panel API.
 *
 * @param string $name Submitted field name to render.
 * @param Field $field Field builder under test.
 * @param mixed $value Current value seeded into the control.
 * @return string Rendered control HTML.
 */
function panel_field_catalog_render_control(string $name, Field $field, mixed $value=''): string {
	$method=new ReflectionMethod(PanelRenderer::class, 'fieldControl');
	$method->setAccessible(true);
	return (string)$method->invoke(null, $name, $field->toArray(), $value);
}

/**
 * Renders a complete private Panel field wrapper for regression assertions.
 *
 * This helper verifies wrapper-level behavior such as labels, hints, accessibility attributes,
 * error rendering, and display-only fields while keeping the renderer internals encapsulated in
 * application code.
 *
 * @param string $name Submitted field name to render.
 * @param Field $field Field builder under test.
 * @param mixed $value Current value seeded into the field.
 * @param array<string, string|array<int, string>> $errors Validation errors keyed by field name.
 * @return string Rendered field HTML.
 */
function panel_field_catalog_render_field(string $name, Field $field, mixed $value='', array $errors=[]): string {
	$method=new ReflectionMethod(PanelRenderer::class, 'fieldHtml');
	$method->setAccessible(true);
	return (string)$method->invoke(null, $name, $field->toArray(), $value, $errors, false);
}

/**
 * Renders default accessibility attributes for a serialized field metadata set.
 *
 * The harness calls the renderer's private policy helper directly so accessibility defaults can
 * be regression-tested independently from any specific field type.
 *
 * @param array<string, mixed> $meta Field metadata containing accessibility policy entries.
 * @return string HTML attribute fragment generated for default accessibility state.
 */
function panel_field_catalog_a11y_default_attrs(array $meta): string {
	$method=new ReflectionMethod(PanelRenderer::class, 'accessibilityDefaultAttrs');
	$method->setAccessible(true);
	return (string)$method->invoke(null, $meta);
}

$failures=[];

$email=Field::make('contact_email')->email()->toArray();
panel_field_catalog_assert($email['type']==='email', 'email() sets the field type to email.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($email, 'input_mode')==='email', 'email() sets email input mode.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($email, 'autocomplete')==='email', 'email() sets email autocomplete.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($email, 'format_rule')==='email', 'email() registers the email format rule.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($email, 'format_event')==='blur', 'email() formats on blur by default.', $failures);
panel_field_catalog_assert(Field::make('contact_email')->email()->dehydrateValue('  USER@Example.COM  ')==='user@example.com', 'email() normalizes submitted email text.', $failures);
panel_field_catalog_assert(Field::make('contact_email')->email()->validateValue(' USER@Example.COM ')===[], 'email() validates trimmed formatted email text.', $failures);
panel_field_catalog_assert(Field::make('contact_email')->email()->validateValue('user@@example.com')!==[], 'email() rejects invalid formatted email text.', $failures);

$url=Field::make('website')->url()->toArray();
panel_field_catalog_assert($url['type']==='url', 'url() sets the field type to url.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($url, 'input_mode')==='url', 'url() sets url input mode.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($url, 'format_rule')==='url', 'url() registers the url format rule.', $failures);
panel_field_catalog_assert(Field::make('website')->url()->dehydrateValue(' https://example.test/path ')==='https://example.test/path', 'url() trims submitted URL text.', $failures);
panel_field_catalog_assert(Field::make('website')->url()->dehydrateValue('example.test/path')==='https://example.test/path', 'url() adds https to submitted URL text without a scheme.', $failures);
panel_field_catalog_assert(Field::make('website')->url()->validateValue('example.test/path')===[], 'url() validates schemeless operator input after normalization.', $failures);
panel_field_catalog_assert(Field::make('website')->url()->validateValue('not a url')!==[], 'url() rejects invalid URL text after normalization.', $failures);
$map_url=Field::make('map_url')->mapUrl()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($map_url, 'format_rule')==='map_url', 'mapUrl() registers the map URL format rule.', $failures);
panel_field_catalog_assert(Field::make('map_url')->mapUrl()->dehydrateValue('45.501689,-73.567256')==='https://www.google.com/maps?q=45.501689%2C-73.567256', 'mapUrl() normalizes coordinate pairs to Google Maps URLs.', $failures);
panel_field_catalog_assert(Field::make('map_url')->mapUrl()->validateValue('https://www.google.com/maps?q=45.501689,-73.567256')===[], 'mapUrl() accepts Google Maps URLs.', $failures);
panel_field_catalog_assert(Field::make('map_url')->mapUrl()->validateValue('https://example.com/maps?q=45.5,-73.5')!==[], 'mapUrl() rejects non-Google map URLs.', $failures);
$domain=Field::make('store_domain')->domain()->toArray();
panel_field_catalog_assert($domain['type']==='text' && panel_field_catalog_meta($domain, 'format_rule')==='domain', 'domain() registers a text domain formatter.', $failures);
panel_field_catalog_assert(Field::make('store_domain')->domain()->dehydrateValue('https://Shop.EXAMPLE.com/path?x=1')==='shop.example.com', 'domain() normalizes pasted URLs to hostnames.', $failures);
panel_field_catalog_assert(Field::make('store_domain')->domain()->validateValue('shop.example.com')===[], 'domain() accepts valid hostnames.', $failures);
panel_field_catalog_assert(Field::make('store_domain')->domain()->validateValue('bad_domain')!==[], 'domain() rejects invalid hostnames.', $failures);
$timezone=Field::make('timezone')->timezone()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($timezone, 'format_rule')==='timezone', 'timezone() registers the timezone format rule.', $failures);
panel_field_catalog_assert(Field::make('timezone')->timezone()->dehydrateValue(' america/toronto ')==='America/Toronto', 'timezone() canonicalizes submitted timezone casing.', $failures);
panel_field_catalog_assert(Field::make('timezone')->timezone()->validateValue('America/Toronto')===[], 'timezone() accepts valid IANA timezones.', $failures);
panel_field_catalog_assert(Field::make('timezone')->timezone()->validateValue('Moon/Base')!==[], 'timezone() rejects unknown timezones.', $failures);
$locale=Field::make('locale')->locale()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($locale, 'format_rule')==='locale', 'locale() registers the locale format rule.', $failures);
panel_field_catalog_assert(Field::make('locale')->locale()->dehydrateValue(' en_ca ')==='en-CA', 'locale() canonicalizes submitted locale casing and separators.', $failures);
panel_field_catalog_assert(Field::make('locale')->locale()->validateValue('fr-CA')===[], 'locale() accepts valid language-region tags.', $failures);
panel_field_catalog_assert(Field::make('locale')->locale()->validateValue('not-a-real-locale-tag')!==[], 'locale() rejects invalid locale tags.', $failures);
$json=Field::make('metadata')->json()->toArray();
panel_field_catalog_assert($json['type']==='textarea' && panel_field_catalog_meta($json, 'format_rule')==='json', 'json() registers a textarea JSON formatter.', $failures);
panel_field_catalog_assert(Field::make('metadata')->json()->dehydrateValue(' { "b": 2, "a": true } ')==='{"b":2,"a":true}', 'json() stores compact normalized JSON.', $failures);
panel_field_catalog_assert(Field::make('metadata')->json()->validateValue('{"ok":true}')===[], 'json() accepts valid JSON text.', $failures);
panel_field_catalog_assert(Field::make('metadata')->json()->validateValue('{"ok":')!==[], 'json() rejects invalid JSON text.', $failures);
$mime_type=Field::make('content_type')->mimeType()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($mime_type, 'format_rule')==='mime_type', 'mimeType() registers the MIME type format rule.', $failures);
panel_field_catalog_assert(Field::make('content_type')->mimeType()->dehydrateValue(' Application/JSON ; Charset = UTF-8 ')==='application/json; charset=utf-8', 'mimeType() normalizes submitted media types.', $failures);
panel_field_catalog_assert(Field::make('content_type')->mimeType()->validateValue('application/json; charset=utf-8')===[], 'mimeType() accepts valid media types.', $failures);
panel_field_catalog_assert(Field::make('content_type')->mimeType()->validateValue('application')!==[], 'mimeType() rejects incomplete media types.', $failures);
$semver=Field::make('version')->semver()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($semver, 'format_rule')==='semver', 'semver() registers the semantic version format rule.', $failures);
panel_field_catalog_assert(Field::make('version')->semver()->dehydrateValue(' v1.2.3-BETA+Build.5 ')==='1.2.3-beta+build.5', 'semver() normalizes submitted semantic versions.', $failures);
panel_field_catalog_assert(Field::make('version')->semver()->validateValue('1.2.3-beta+build.5')===[], 'semver() accepts valid semantic versions.', $failures);
panel_field_catalog_assert(Field::make('version')->semver()->validateValue('1.2')!==[], 'semver() rejects incomplete semantic versions.', $failures);
$cron=Field::make('schedule')->cronExpression()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($cron, 'format_rule')==='cron_expression', 'cronExpression() registers the cron format rule.', $failures);
panel_field_catalog_assert(Field::make('schedule')->cronExpression()->dehydrateValue(' 0   9 * * MON-FRI ')==='0 9 * * mon-fri', 'cronExpression() normalizes submitted cron spacing and casing.', $failures);
panel_field_catalog_assert(Field::make('schedule')->cronExpression()->validateValue('*/15 9-17 * jan,mar mon-fri')===[], 'cronExpression() accepts valid cron expressions.', $failures);
panel_field_catalog_assert(Field::make('schedule')->cronExpression()->validateValue('60 9 * * *')!==[], 'cronExpression() rejects out-of-range cron fields.', $failures);
$language_code=Field::make('language')->languageCode()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($language_code, 'format_rule')==='language_code', 'languageCode() registers the ISO language format rule.', $failures);
panel_field_catalog_assert(Field::make('language')->languageCode()->dehydrateValue(' English ')==='en', 'languageCode() normalizes common language names.', $failures);
panel_field_catalog_assert(Field::make('language')->languageCode()->dehydrateValue('fr-CA')==='fr', 'languageCode() stores the primary language subtag from locale tags.', $failures);
panel_field_catalog_assert(Field::make('language')->languageCode()->validateValue('en')===[], 'languageCode() accepts valid ISO language codes.', $failures);
panel_field_catalog_assert(Field::make('language')->languageCode()->validateValue('zz')!==[], 'languageCode() rejects unknown language codes.', $failures);
$country_code=Field::make('country')->countryCode()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($country_code, 'format_rule')==='country_code', 'countryCode() registers the ISO country format rule.', $failures);
panel_field_catalog_assert(Field::make('country')->countryCode()->dehydrateValue(' Canada ')==='CA', 'countryCode() normalizes common country names.', $failures);
panel_field_catalog_assert(Field::make('country')->countryCode()->dehydrateValue('usa')==='US', 'countryCode() normalizes common alpha-3 aliases.', $failures);
panel_field_catalog_assert(Field::make('country')->countryCode()->validateValue('CA')===[], 'countryCode() accepts valid ISO alpha-2 codes.', $failures);
panel_field_catalog_assert(Field::make('country')->countryCode()->validateValue('ZZ')!==[], 'countryCode() rejects unknown country codes.', $failures);
$subdivision_code=Field::make('region')->subdivisionCode()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($subdivision_code, 'format_rule')==='subdivision_code', 'subdivisionCode() registers the subdivision format rule.', $failures);
panel_field_catalog_assert(Field::make('region')->subdivisionCode()->dehydrateValue(' Quebec ')==='QC', 'subdivisionCode() normalizes common subdivision names.', $failures);
panel_field_catalog_assert(Field::make('region')->subdivisionCodeForCountry('CA')->validateValue('QC')===[], 'subdivisionCodeForCountry() accepts subdivisions for its country.', $failures);
panel_field_catalog_assert(Field::make('region')->subdivisionCodeForCountry('CA')->validateValue('NY')!==[], 'subdivisionCodeForCountry() rejects subdivisions outside its country.', $failures);
panel_field_catalog_assert(Field::make('region')->subdivisionCodeCountryField('country')->validateValue('New York', ['country'=>'US'])===[], 'subdivisionCodeCountryField() validates against sibling country values.', $failures);
$currency_code=Field::make('currency')->currencyCode()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($currency_code, 'format_rule')==='currency_code', 'currencyCode() registers the ISO currency format rule.', $failures);
panel_field_catalog_assert(Field::make('currency')->currencyCode()->dehydrateValue(' Canadian dollar ')==='CAD', 'currencyCode() normalizes common currency names.', $failures);
panel_field_catalog_assert(Field::make('currency')->currencyCode()->dehydrateValue('€')==='EUR', 'currencyCode() normalizes common currency symbols.', $failures);
panel_field_catalog_assert(Field::make('currency')->currencyCode()->validateValue('CAD')===[], 'currencyCode() accepts valid ISO currency codes.', $failures);
panel_field_catalog_assert(Field::make('currency')->currencyCode()->validateValue('ZZZ')!==[], 'currencyCode() rejects unknown currency codes.', $failures);
$ip=Field::make('server_ip')->ipAddress()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($ip, 'format_rule')==='ip_address', 'ipAddress() registers the IP address format rule.', $failures);
panel_field_catalog_assert(Field::make('server_ip')->ipAddress()->dehydrateValue(' 2001:DB8::1 ')==='2001:db8::1', 'ipAddress() normalizes submitted IP text.', $failures);
panel_field_catalog_assert(Field::make('server_ip')->ipAddress()->validateValue('2001:db8::1')===[], 'ipAddress() accepts valid IPv6 addresses.', $failures);
panel_field_catalog_assert(Field::make('server_ip')->ipAddress()->validateValue('192.0.2.10')===[], 'ipAddress() accepts valid IPv4 addresses.', $failures);
panel_field_catalog_assert(Field::make('server_ip')->ipv4()->validateValue('999.0.2.10')!==[], 'ipv4() rejects invalid IPv4 addresses.', $failures);
panel_field_catalog_assert(Field::make('server_ip')->ipv6()->validateValue('192.0.2.10')!==[], 'ipv6() rejects IPv4 addresses.', $failures);
$mac=Field::make('device_mac')->macAddress()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($mac, 'format_rule')==='mac_address', 'macAddress() registers the MAC address format rule.', $failures);
panel_field_catalog_assert(Field::make('device_mac')->macAddress()->dehydrateValue('001a.2b3c.4d5e')==='00:1A:2B:3C:4D:5E', 'macAddress() normalizes submitted MAC text.', $failures);
panel_field_catalog_assert(Field::make('device_mac')->macAddress()->validateValue('00-1a-2b-3c-4d-5e')===[], 'macAddress() accepts common MAC separators.', $failures);
panel_field_catalog_assert(Field::make('device_mac')->macAddress()->validateValue('00:1A:2B:3C:4D')!==[], 'macAddress() rejects incomplete MAC addresses.', $failures);
$uuid=Field::make('external_id')->uuid()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($uuid, 'format_rule')==='uuid', 'uuid() registers the UUID format rule.', $failures);
panel_field_catalog_assert(Field::make('external_id')->uuid()->dehydrateValue('{550E8400E29B41D4A716446655440000}')==='550e8400-e29b-41d4-a716-446655440000', 'uuid() normalizes submitted UUID text.', $failures);
panel_field_catalog_assert(Field::make('external_id')->uuid()->validateValue('550e8400-e29b-41d4-a716-446655440000')===[], 'uuid() accepts valid UUID values.', $failures);
panel_field_catalog_assert(Field::make('external_id')->uuid()->validateValue('550e8400-e29b-01d4-a716-446655440000')!==[], 'uuid() rejects invalid UUID version bits.', $failures);
$ulid=Field::make('event_id')->ulid()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($ulid, 'format_rule')==='ulid', 'ulid() registers the ULID format rule.', $failures);
panel_field_catalog_assert(Field::make('event_id')->ulid()->dehydrateValue(' 01arz3ndek tsv4rrffq69g5fav ')==='01ARZ3NDEKTSV4RRFFQ69G5FAV', 'ulid() normalizes submitted ULID text.', $failures);
panel_field_catalog_assert(Field::make('event_id')->ulid()->validateValue('01ARZ3NDEKTSV4RRFFQ69G5FAV')===[], 'ulid() accepts valid ULID values.', $failures);
panel_field_catalog_assert(Field::make('event_id')->ulid()->validateValue('81ARZ3NDEKTSV4RRFFQ69G5FAV')!==[], 'ulid() rejects invalid ULID timestamp prefixes.', $failures);
$hex_color=Field::make('brand_color')->hexColor()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($hex_color, 'format_rule')==='hex_color', 'hexColor() registers the hex color format rule.', $failures);
panel_field_catalog_assert(Field::make('brand_color')->hexColor()->dehydrateValue('ABC')==='#aabbcc', 'hexColor() expands shorthand submitted color text.', $failures);
panel_field_catalog_assert(Field::make('brand_color')->hexColor()->validateValue('#3366cc')===[], 'hexColor() accepts valid hex colors.', $failures);
panel_field_catalog_assert(Field::make('brand_color')->hexColor()->validateValue('#12')!==[], 'hexColor() rejects incomplete hex colors.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta(Field::make('brand_color')->hexColor()->hideColorSwatch()->toArray(), 'color_swatch')===false, 'hideColorSwatch() disables hex color swatch metadata.', $failures);
$native_color=Field::make('brand_color')->color('#3366cc')->toArray();
panel_field_catalog_assert($native_color['type']==='color' && ($native_color['default'] ?? null)==='#3366cc' && in_array('native_color_picker', $native_color['component']['capabilities'], true), 'color() configures native color picker metadata.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('color')['input'] ?? null)==='color', 'Component registry exposes native color input metadata.', $failures);
$latitude=Field::make('latitude')->latitude()->toArray();
panel_field_catalog_assert($latitude['type']==='number' && panel_field_catalog_meta($latitude, 'format_rule')==='latitude', 'latitude() registers number coordinate formatting.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($latitude, 'min')===-90 && panel_field_catalog_meta($latitude, 'max')===90 && panel_field_catalog_meta($latitude, 'step')==='0.000001', 'latitude() registers native coordinate bounds and step.', $failures);
panel_field_catalog_assert(Field::make('latitude')->latitude(6)->dehydrateValue('45.5016899')===45.50169, 'latitude() normalizes submitted coordinate precision.', $failures);
panel_field_catalog_assert(Field::make('latitude')->latitude()->validateValue('90.000001')!==[], 'latitude() rejects out-of-range values.', $failures);
$longitude=Field::make('longitude')->longitude()->toArray();
panel_field_catalog_assert($longitude['type']==='number' && panel_field_catalog_meta($longitude, 'min')===-180 && panel_field_catalog_meta($longitude, 'max')===180, 'longitude() registers number coordinate bounds.', $failures);
panel_field_catalog_assert(Field::make('longitude')->longitude()->validateValue('-73.567256')===[], 'longitude() accepts valid coordinate values.', $failures);
panel_field_catalog_assert(Field::make('longitude')->longitude()->validateValue('-181')!==[], 'longitude() rejects out-of-range values.', $failures);
$coordinates=Field::make('coordinates')->coordinates()->toArray();
panel_field_catalog_assert($coordinates['type']==='text' && panel_field_catalog_meta($coordinates, 'format_rule')==='coordinates', 'coordinates() registers paired coordinate formatting.', $failures);
panel_field_catalog_assert(Field::make('coordinates')->coordinates(6)->dehydrateValue('45.5016899 -73.5672569')==='45.50169,-73.567257', 'coordinates() normalizes submitted coordinate pairs.', $failures);
panel_field_catalog_assert(Field::make('coordinates')->coordinates()->validateValue('45.501689,-73.567256')===[], 'coordinates() accepts valid latitude,longitude pairs.', $failures);
panel_field_catalog_assert(Field::make('coordinates')->coordinates()->validateValue('91,-73.567256')!==[], 'coordinates() rejects out-of-range latitude in pairs.', $failures);
panel_field_catalog_assert(Field::make('coordinates')->lngLat(6)->dehydrateValue('-73.5672569,45.5016899')==='45.50169,-73.567257', 'lngLat() normalizes longitude,latitude input into latitude,longitude output.', $failures);

$zip=Field::make('delivery_zip')->zipCode()->toArray();
panel_field_catalog_assert($zip['type']==='text', 'zipCode() keeps a text field for predictable masking/formatting.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($zip, 'input_mode')==='numeric', 'zipCode() sets numeric input mode.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($zip, 'autocomplete')==='postal-code', 'zipCode() sets postal-code autocomplete.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($zip, 'format_rule')==='zip_code_us', 'zipCode() registers the US ZIP format rule.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($zip, 'format_event')==='input', 'zipCode() formats on input by default.', $failures);
panel_field_catalog_assert(Field::make('delivery_zip')->zipCode()->dehydrateValue('12345-6789')==='123456789', 'zipCode() normalizes submitted ZIP text to digits.', $failures);
$locale_zip=Field::make('delivery_zip')->zipCodeCountryField('market')->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($locale_zip, 'input_mode')==='text', 'zipCodeCountryField() aliases locale-aware postalCodeCountryField() and keeps postal input text-friendly.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($locale_zip, 'autocomplete')==='postal-code', 'postalCodeCountryField() sets postal-code autocomplete.', $failures);
panel_field_catalog_assert(($locale_zip['meta']['format_options']['country_field'] ?? null)==='market', 'zipCodeCountryField() stores the postal country source field.', $failures);
$locale_postal=Field::make('delivery_postal')->postalCodeLocaleFields('market', 'delivery_region')->toArray();
panel_field_catalog_assert(($locale_postal['meta']['format_options']['country_field'] ?? null)==='market' && ($locale_postal['meta']['format_options']['subdivision_field'] ?? null)==='delivery_region', 'postalCodeLocaleFields() stores country and subdivision source fields.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($locale_postal, 'autocomplete')==='postal-code', 'postalCodeLocaleFields() sets postal-code autocomplete.', $failures);
$subdivision_postal=Field::make('delivery_postal')->postalCodeSubdivisionField('delivery_region')->toArray();
panel_field_catalog_assert(($subdivision_postal['meta']['format_options']['subdivision_field'] ?? null)==='delivery_region', 'postalCodeSubdivisionField() stores the subdivision source field.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($subdivision_postal, 'autocomplete')==='postal-code', 'postalCodeSubdivisionField() sets postal-code autocomplete.', $failures);
$subdivision_zip=Field::make('delivery_zip')->zipCodeCountryField('market')->formatSubdivisionField('delivery_region')->toArray();
panel_field_catalog_assert(($subdivision_zip['meta']['format_options']['subdivision_field'] ?? null)==='delivery_region', 'formatSubdivisionField() stores the subdivision source field.', $failures);
$locale_phone=Field::make('contact_phone')->phoneCountryField('market')->toArray();
panel_field_catalog_assert(($locale_phone['meta']['format_options']['country_field'] ?? null)==='market', 'phoneCountryField() stores the country source field.', $failures);
$subdivision_phone=Field::make('contact_phone')->phoneCountryField('market')->formatSubdivisionField('delivery_region')->toArray();
panel_field_catalog_assert(($subdivision_phone['meta']['format_options']['subdivision_field'] ?? null)==='delivery_region', 'formatSubdivisionField() can refine phone formatting.', $failures);
panel_field_catalog_assert(Field::make('delivery_zip')->zipCodeCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('H2X1Y4', ['market'=>'CA', 'delivery_region'=>'QC'])===[], 'Server validation accepts Quebec postal codes for Quebec.', $failures);
panel_field_catalog_assert(Field::make('delivery_zip')->zipCodeCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('K1A0B1', ['market'=>'CA', 'delivery_region'=>'QC'])!==[], 'Server validation rejects Ontario postal codes for Quebec.', $failures);
panel_field_catalog_assert(Field::make('delivery_postal')->postalCodeSubdivisionField('delivery_region')->validateValue('H2X1Y4', ['delivery_region'=>'QC'])===[], 'Server validation infers Canadian postal rules from an unambiguous province source.', $failures);
panel_field_catalog_assert(Field::make('delivery_postal')->postalCodeSubdivisionField('delivery_region')->validateValue('10001', ['delivery_region'=>'NY'])===[], 'Server validation infers US ZIP rules from a state source.', $failures);
panel_field_catalog_assert(Field::make('delivery_postal')->postalCodeSubdivisionField('delivery_region')->validateValue('2000', ['delivery_region'=>'NSW'])===[], 'Server validation infers Australian postcode rules from a state source.', $failures);
panel_field_catalog_assert(Field::make('delivery_zip')->zipCodeCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('10001', ['market'=>'US', 'delivery_region'=>'NY'])===[], 'Server validation accepts New York ZIPs for New York.', $failures);
panel_field_catalog_assert(Field::make('delivery_zip')->zipCodeCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('90210', ['market'=>'US', 'delivery_region'=>'NY'])!==[], 'Server validation rejects California ZIPs for New York.', $failures);
panel_field_catalog_assert(Field::make('contact_phone')->phoneCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('4930123456', ['market'=>'EU', 'delivery_region'=>'DE'])===[], 'Server validation accepts German phone numbers for Germany.', $failures);
panel_field_catalog_assert(Field::make('contact_phone')->phoneCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('33612345678', ['market'=>'EU', 'delivery_region'=>'DE'])!==[], 'Server validation rejects French phone numbers for Germany.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->validateValue('4930123456', ['country'=>'Germany'])===[], 'Server validation accepts Germany country-field phone numbers.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->validateValue('030123456', ['country'=>'Germany'])===[], 'Server validation accepts German local trunk phone numbers.', $failures);
panel_field_catalog_assert((Field::make('postal')->postalCode('GB')->toArray()['meta']['format_rule'] ?? null)==='postal_code_gb', 'postalCode(GB) registers the UK postcode format rule.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('country')->validateValue('SW1A1AA', ['country'=>'GB'])===[], 'Server validation accepts UK postcodes for GB.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('country')->validateValue('10001', ['country'=>'GB'])!==[], 'Server validation rejects US ZIPs for GB postcodes.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->validateValue('442079460958', ['country'=>'GB'])===[], 'Server validation accepts +44 phone numbers for GB.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->validateValue('020 7946 0958', ['country'=>'GB'])===[], 'Server validation accepts GB local trunk phone numbers.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->validateValue('+33 6 12 34 56 78', ['country'=>'GB'])===[], 'Server validation accepts explicit international phone prefixes even when a country context exists.', $failures);
panel_field_catalog_assert((Field::make('postal')->postalCode('AU')->toArray()['meta']['format_rule'] ?? null)==='postal_code_au', 'postalCode(AU) registers the Australian postcode format rule.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('country')->formatSubdivisionField('state')->validateValue('2000', ['country'=>'AU', 'state'=>'NSW'])===[], 'Server validation accepts New South Wales postcodes for NSW.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('country')->formatSubdivisionField('state')->validateValue('3000', ['country'=>'AU', 'state'=>'NSW'])!==[], 'Server validation rejects Victoria postcodes for NSW.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->validateValue('61298765432', ['country'=>'AU'])===[], 'Server validation accepts +61 phone numbers for AU.', $failures);
panel_field_catalog_assert((Field::make('postal')->postalCode('NZ')->toArray()['meta']['format_rule'] ?? null)==='postal_code_nz', 'postalCode(NZ) registers the New Zealand postcode format rule.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('country')->formatSubdivisionField('region')->validateValue('1010', ['country'=>'NZ', 'region'=>'Auckland'])===[], 'Server validation accepts Auckland postcodes for Auckland.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('country')->formatSubdivisionField('region')->validateValue('9016', ['country'=>'NZ', 'region'=>'Auckland'])!==[], 'Server validation rejects Otago postcodes for Auckland.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->validateValue('6491234567', ['country'=>'NZ'])===[], 'Server validation accepts +64 phone numbers for NZ.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('10115', ['market'=>'EU', 'delivery_region'=>'DE'])===[], 'Server validation accepts German postcodes for Germany.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('1012AB', ['market'=>'EU', 'delivery_region'=>'DE'])!==[], 'Server validation rejects Dutch postcodes for Germany.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('1012AB', ['market'=>'EU', 'delivery_region'=>'NL'])===[], 'Server validation accepts Dutch postcodes for the Netherlands.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('1012 AB', ['market'=>'EU', 'delivery_region'=>'NL'])===[], 'Server validation accepts formatted Dutch postcodes for the Netherlands.', $failures);
panel_field_catalog_assert(Field::make('delivery_zip')->zipCodeCountryField('market')->formatSubdivisionField('delivery_region')->validateValue('H2X 1Y4', ['market'=>'CA', 'delivery_region'=>'QC'])===[], 'Server validation accepts formatted Quebec postal codes for Quebec.', $failures);
$ca_locale_request=PanelRequest::fromArray(['method'=>'POST', 'resource'=>'orders', 'operation'=>'store', 'input'=>['market'=>'CA', 'delivery_region'=>'QC']]);
$us_locale_request=PanelRequest::fromArray(['method'=>'POST', 'resource'=>'orders', 'operation'=>'store', 'input'=>['market'=>'US', 'delivery_region'=>'NY']]);
$de_locale_request=PanelRequest::fromArray(['method'=>'POST', 'resource'=>'orders', 'operation'=>'store', 'input'=>['market'=>'EU', 'delivery_region'=>'DE']]);
$gb_locale_request=PanelRequest::fromArray(['method'=>'POST', 'resource'=>'orders', 'operation'=>'store', 'input'=>['country'=>'GB']]);
$au_locale_request=PanelRequest::fromArray(['method'=>'POST', 'resource'=>'orders', 'operation'=>'store', 'input'=>['country'=>'AU', 'state'=>'NSW']]);
$nz_locale_request=PanelRequest::fromArray(['method'=>'POST', 'resource'=>'orders', 'operation'=>'store', 'input'=>['country'=>'NZ', 'region'=>'Auckland']]);
$nl_locale_request=PanelRequest::fromArray(['method'=>'POST', 'resource'=>'orders', 'operation'=>'store', 'input'=>['market'=>'EU', 'delivery_region'=>'NL']]);
panel_field_catalog_assert(Field::make('delivery_zip')->zipCodeCountryField('market')->formatSubdivisionField('delivery_region')->dehydrateValue('H2X 1Y4', null, $ca_locale_request)==='H2X1Y4', 'Server dehydration keeps Canadian postal letters for locale-aware ZIP fields.', $failures);
panel_field_catalog_assert(Field::make('delivery_zip')->zipCodeCountryField('market')->formatSubdivisionField('delivery_region')->dehydrateValue('10001-2345', null, $us_locale_request)==='100012345', 'Server dehydration keeps US locale-aware ZIP fields digit-only.', $failures);
panel_field_catalog_assert(Field::make('contact_phone')->phoneCountryField('market')->formatSubdivisionField('delivery_region')->dehydrateValue('+49 30 1234 56', null, $de_locale_request)==='4930123456', 'Server dehydration normalizes subdivision-aware international phone fields.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->dehydrateValue('020 7946 0958', null, $gb_locale_request)==='442079460958', 'Server dehydration strips international phone trunk prefixes for GB.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->dehydrateValue('+33 6 12 34 56 78', null, $gb_locale_request)==='33612345678', 'Server dehydration preserves explicit international phone prefixes even when a country context exists.', $failures);
panel_field_catalog_assert(Field::make('phone')->phoneCountryField('country')->dehydrateValue('02 9876 5432', null, $au_locale_request)==='61298765432', 'Server dehydration strips international phone trunk prefixes for AU.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('country')->dehydrateValue('SW1A 1AA', null, $gb_locale_request)==='SW1A1AA', 'Server dehydration normalizes UK postcodes compactly.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('country')->formatSubdivisionField('state')->dehydrateValue('2000', null, $au_locale_request)==='2000', 'Server dehydration normalizes Australian postcodes digit-only.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('country')->formatSubdivisionField('region')->dehydrateValue('1010', null, $nz_locale_request)==='1010', 'Server dehydration normalizes New Zealand postcodes digit-only.', $failures);
panel_field_catalog_assert(Field::make('postal')->postalCodeCountryField('market')->formatSubdivisionField('delivery_region')->dehydrateValue('1012 AB', null, $nl_locale_request)==='1012AB', 'Server dehydration normalizes Dutch postcodes compactly.', $failures);
$repeater_locale=Field::make('addresses', 'repeater')->repeaterFields([
	Field::make('country'),
	Field::make('region'),
	Field::make('postal')->zipCodeCountryField('country')->formatSubdivisionField('region'),
]);
$repeater_locale_rows=[
	['country'=>'CA', 'region'=>'QC', 'postal'=>'H2X 1Y4'],
	['country'=>'US', 'region'=>'NY', 'postal'=>'10001-2345'],
];
$repeater_dehydrated=$repeater_locale->dehydrateValue($repeater_locale_rows);
panel_field_catalog_assert(($repeater_dehydrated[0]['postal'] ?? null)==='H2X1Y4' && ($repeater_dehydrated[1]['postal'] ?? null)==='100012345', 'Repeater dehydration uses each row as locale context for child fields.', $failures);
panel_field_catalog_assert($repeater_locale->validateValue($repeater_locale_rows)===[], 'Repeater validation uses each row as locale context for child fields.', $failures);
panel_field_catalog_assert($repeater_locale->validateValue([['country'=>'CA', 'region'=>'QC', 'postal'=>'K1A 0B1']])!==[], 'Repeater validation rejects child values against their row locale context.', $failures);

$ssn_field=Field::make('operator_ssn')->ssn();
$ssn=$ssn_field->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($ssn, 'input_mode')==='numeric', 'ssn() sets numeric input mode.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($ssn, 'mask')==='999-99-9999', 'ssn() applies the SSN mask.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($ssn, 'mask_submit_normalized')===true, 'ssn() submits the mask value normalized.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($ssn, 'format_event')==='input', 'ssn() formats on input by default.', $failures);
panel_field_catalog_assert($ssn['component']['capabilities']!==[] && in_array('normalizes_submit', $ssn['component']['capabilities'], true), 'ssn() manifest advertises normalized submit behavior.', $failures);
panel_field_catalog_assert($ssn_field->dehydrateValue('123-45-6789')==='123456789', 'ssn() strips punctuation from submitted values.', $failures);

$masked_field=Field::make('masked_ssn')->mask('999-99-9999', true);
$masked=$masked_field->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($masked, 'mask')==='999-99-9999', 'mask() stores the configured mask.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($masked, 'mask_submit_normalized')===true, 'mask(..., true) enables normalized submit.', $failures);
panel_field_catalog_assert($masked_field->dehydrateValue('987-65-4321')==='987654321', 'mask(..., true) strips punctuation from submitted values.', $failures);

$placeholder_mask=Field::make('tracking_code')->mask('AA-999999')->maskPlaceholder('AA-000000')->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($placeholder_mask, 'mask_placeholder')==='AA-000000', 'maskPlaceholder() stores an explicit placeholder.', $failures);
$hidden_placeholder_mask=Field::make('tracking_code')->mask('AA-999999')->hideMaskPlaceholder()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($hidden_placeholder_mask, 'mask_placeholder')===false, 'hideMaskPlaceholder() disables generated mask placeholders.', $failures);
$format_placeholder=Field::make('delivery_zip')->zipCode()->formatPlaceholder('ZIP or ZIP+4')->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($format_placeholder, 'format_placeholder')==='ZIP or ZIP+4', 'formatPlaceholder() stores an explicit placeholder.', $failures);
$hidden_format_placeholder=Field::make('delivery_zip')->zipCode()->hideFormatPlaceholder()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($hidden_format_placeholder, 'format_placeholder')===false, 'hideFormatPlaceholder() disables generated format placeholders.', $failures);

$array_format=Field::fromArray([
	'name'=>'delivery_zip',
	'format_rule'=>'postal_code',
	'country_field'=>'market',
	'subdivision_field'=>'delivery_region',
	'format_placeholder'=>false,
	'submit_formatted'=>true,
])->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($array_format, 'format_rule')==='postal_code', 'fromArray() accepts format_rule aliases.', $failures);
panel_field_catalog_assert((panel_field_catalog_meta($array_format, 'format_options')['country_field'] ?? null)==='market', 'fromArray() lifts top-level country_field into format options.', $failures);
panel_field_catalog_assert((panel_field_catalog_meta($array_format, 'format_options')['subdivision_field'] ?? null)==='delivery_region', 'fromArray() lifts top-level subdivision_field into format options.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($array_format, 'format_placeholder')===false, 'fromArray() accepts format_placeholder controls.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($array_format, 'submit_normalized')===false, 'fromArray() accepts submit_formatted controls.', $failures);
$array_mask=Field::fromArray(['name'=>'serial', 'mask'=>'AA-999', 'mask_placeholder'=>false, 'submit_unmasked'=>true, 'title'=>'Serial code'])->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($array_mask, 'mask_placeholder')===false && panel_field_catalog_meta($array_mask, 'mask_submit_normalized')===true, 'fromArray() accepts mask placeholder and submit_unmasked controls.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($array_mask, 'title')==='Serial code', 'fromArray() accepts native title metadata.', $failures);
$array_counter=Field::fromArray(['name'=>'short_note', 'max_length'=>24, 'character_counter'=>true, 'counter_position'=>'prepend'])->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($array_counter, 'character_counter')===true && panel_field_catalog_meta($array_counter, 'character_counter_position')==='prepend', 'fromArray() accepts character counter controls.', $failures);
$array_autosize=Field::fromArray(['name'=>'notes', 'type'=>'textarea', 'rows'=>2, 'auto_resize'=>true])->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($array_autosize, 'auto_resize')===true && panel_field_catalog_meta($array_autosize, 'rows')===2, 'fromArray() accepts textarea auto-resize controls.', $failures);
$array_copyable=Field::fromArray(['name'=>'sku', 'copyable'=>true, 'copy_normalized'=>true])->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($array_copyable, 'copyable')===true && panel_field_catalog_meta($array_copyable, 'copy_normalized')===true, 'fromArray() accepts copyable field controls.', $failures);
$array_swatch=Field::fromArray(['name'=>'brand_color', 'format_rule'=>'hex_color', 'color_swatch'=>false])->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($array_swatch, 'color_swatch')===false, 'fromArray() accepts color swatch controls.', $failures);

$date=Field::make('follow_up_date', 'date')->todayButton()->toArray();
$date_buttons=panel_field_catalog_meta($date, 'append_buttons');
panel_field_catalog_assert(is_array($date_buttons) && ($date_buttons[0]['action'] ?? null)==='today', 'todayButton() appends a today side button.', $failures);
panel_field_catalog_assert(($date_buttons[0]['icon'] ?? null)==='today', 'todayButton() declares the today icon.', $failures);
panel_field_catalog_assert(in_array('adornments', $date['component']['capabilities'], true), 'todayButton() manifest advertises adornments.', $failures);
$date_range=Field::make('scheduled_for')->date('2026-01-01', '2026-12-31')->todayButton()->toArray();
panel_field_catalog_assert($date_range['type']==='date' && panel_field_catalog_meta($date_range, 'min')==='2026-01-01' && panel_field_catalog_meta($date_range, 'max')==='2026-12-31', 'date() configures native date bounds.', $failures);
panel_field_catalog_assert(in_array('bounded', $date_range['component']['capabilities'], true) && in_array('quick_fill', $date_range['component']['capabilities'], true), 'date() exposes bounded quick-fill capabilities.', $failures);
$date_range_html=panel_field_catalog_render_control('scheduled_for', Field::make('scheduled_for')->date('2026-01-01', '2026-12-31')->todayButton(), '2026-05-12');
panel_field_catalog_assert(str_contains($date_range_html, 'type="date"') && str_contains($date_range_html, 'min="2026-01-01"') && str_contains($date_range_html, 'max="2026-12-31"') && str_contains($date_range_html, 'data-dp-panel-field-button="today"'), 'Renderer emits bounded date input and today quick-fill button.', $failures);

$datetime=Field::make('handoff_at', 'datetime')->nowButton('Use now', 'prepend')->toArray();
$datetime_buttons=panel_field_catalog_meta($datetime, 'prepend_buttons');
panel_field_catalog_assert(is_array($datetime_buttons) && ($datetime_buttons[0]['action'] ?? null)==='now', 'nowButton() can prepend a now side button.', $failures);
panel_field_catalog_assert(($datetime_buttons[0]['label'] ?? null)==='Use now', 'nowButton() keeps the provided label.', $failures);
$datetime_range=Field::make('handoff_at')->dateTime('2026-05-01T08:00', '2026-05-31T18:00')->nowButton('Use now', 'prepend')->toArray();
panel_field_catalog_assert($datetime_range['type']==='datetime' && panel_field_catalog_meta($datetime_range, 'min')==='2026-05-01T08:00' && panel_field_catalog_meta($datetime_range, 'max')==='2026-05-31T18:00', 'dateTime() configures native datetime bounds.', $failures);
$month_html=panel_field_catalog_render_control('billing_month', Field::make('billing_month')->month('2026-01', '2026-12'), '2026-05');
panel_field_catalog_assert(str_contains($month_html, 'type="month"') && str_contains($month_html, 'min="2026-01"') && str_contains($month_html, 'max="2026-12"'), 'Renderer emits native month input bounds.', $failures);
$week_html=panel_field_catalog_render_control('ship_week', Field::make('ship_week')->week('2026-W01', '2026-W52'), '2026-W20');
panel_field_catalog_assert(str_contains($week_html, 'type="week"') && str_contains($week_html, 'min="2026-W01"') && str_contains($week_html, 'max="2026-W52"'), 'Renderer emits native week input bounds.', $failures);
$time_range=Field::make('opens_at')->time('09:00', '17:00', '900')->toArray();
panel_field_catalog_assert($time_range['type']==='time' && panel_field_catalog_meta($time_range, 'min')==='09:00' && panel_field_catalog_meta($time_range, 'max')==='17:00' && panel_field_catalog_meta($time_range, 'step')==='900', 'time() configures native time bounds and step.', $failures);

$sample=Field::make('sample_reference')->setButton('Use sample', 'sample-value')->toArray();
$sample_buttons=panel_field_catalog_meta($sample, 'append_buttons');
panel_field_catalog_assert(is_array($sample_buttons) && ($sample_buttons[0]['action'] ?? null)==='set', 'setButton() appends a set side button.', $failures);
panel_field_catalog_assert(($sample_buttons[0]['value'] ?? null)==='sample-value', 'setButton() stores the target value.', $failures);

$copy_normalized=Field::make('tax_reference')->ein()->copyNormalizedButton()->toArray();
$copy_normalized_buttons=panel_field_catalog_meta($copy_normalized, 'append_buttons');
panel_field_catalog_assert(is_array($copy_normalized_buttons) && ($copy_normalized_buttons[0]['copy_normalized'] ?? null)===true, 'copyNormalizedButton() marks the copy button as normalized.', $failures);
$format_button=Field::make('customer_name')->titleCaseButton()->toArray();
$format_button_buttons=panel_field_catalog_meta($format_button, 'append_buttons');
panel_field_catalog_assert(is_array($format_button_buttons) && ($format_button_buttons[0]['action'] ?? null)==='title_case', 'titleCaseButton() appends a title_case side button.', $failures);
$slug_from=Field::make('slug')->slugFrom('title')->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($slug_from, 'format_rule')==='slug' && (panel_field_catalog_meta($slug_from, 'format_options')['source_field'] ?? null)==='title', 'slugFrom() registers slug source field metadata.', $failures);
$source_field=Field::make('handle')->lowercase()->sourceField('customer_name')->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($source_field, 'format_rule')==='lowercase' && (panel_field_catalog_meta($source_field, 'format_options')['source_field'] ?? null)==='customer_name', 'sourceField() registers generic formatter source metadata.', $failures);

$title_case=Field::make('customer')->titleCase()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($title_case, 'format_rule')==='title_case', 'titleCase() registers title_case formatting.', $failures);
panel_field_catalog_assert(Field::make('customer')->titleCase()->dehydrateValue('  CLARA roy  ')==='Clara Roy', 'titleCase() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('code')->uppercase()->dehydrateValue('abc-123')==='ABC-123', 'uppercase() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('code')->lowercase()->dehydrateValue('ABC-123')==='abc-123', 'lowercase() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('note')->sentenceCase()->dehydrateValue('  READY FOR PICKUP ')==='Ready for pickup', 'sentenceCase() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('key')->snakeCase()->dehydrateValue('Order Status ID')==='order_status_id', 'snakeCase() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('key')->kebabCase()->dehydrateValue('Order Status ID')==='order-status-id', 'kebabCase() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('key')->camelCase()->dehydrateValue('Order Status ID')==='orderStatusId', 'camelCase() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('slug')->slug()->dehydrateValue('Custom Slug')==='custom-slug', 'slug() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('tracking')->alphanumeric()->dehydrateValue('ab-123 !')==='AB123', 'alphanumeric() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('digits')->digits()->dehydrateValue('(514) 555-0134')==='5145550134', 'digits() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('letters')->alpha()->dehydrateValue('A1 b2')==='Ab', 'alpha() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('trimmed')->trimmed()->dehydrateValue('  keep me  ')==='keep me', 'trimmed() normalizes submitted text.', $failures);
panel_field_catalog_assert(Field::make('card_number')->creditCard()->validateValue('4111 1111 1111 1111')===[], 'creditCard() accepts Luhn-valid card numbers.', $failures);
panel_field_catalog_assert(Field::make('card_number')->creditCard()->validateValue('4111 1111 1111 1112')!==[], 'creditCard() rejects Luhn-invalid card numbers.', $failures);
$future_expiry=(new DateTimeImmutable('first day of next month'))->format('my');
panel_field_catalog_assert(Field::make('card_expiry')->creditCardExpiry()->validateValue(substr($future_expiry, 0, 2).'/'.substr($future_expiry, 2))===[], 'creditCardExpiry() accepts future expiry dates.', $failures);
panel_field_catalog_assert(Field::make('card_expiry')->creditCardExpiry()->validateValue('00/99')!==[], 'creditCardExpiry() rejects invalid months.', $failures);
panel_field_catalog_assert(Field::make('card_expiry')->creditCardExpiry()->validateValue('01/20')!==[], 'creditCardExpiry() rejects past expiry dates.', $failures);
panel_field_catalog_assert(Field::make('card_expiry')->creditCardExpiry()->dehydrateValue('03/29')==='0329', 'creditCardExpiry() normalizes submitted expiry text.', $failures);
panel_field_catalog_assert(Field::make('card_cvc')->cardCvc()->validateValue('123')===[], 'cardCvc() accepts three-digit security codes.', $failures);
panel_field_catalog_assert(Field::make('card_cvc')->cardCvc()->validateValue('12345')!==[], 'cardCvc() rejects overlong security codes.', $failures);
panel_field_catalog_assert(Field::make('card_cvc')->cardCvc()->dehydrateValue('12 3')==='123', 'cardCvc() normalizes submitted security codes.', $failures);
panel_field_catalog_assert(Field::make('billing_iban')->iban()->validateValue('GB82 WEST 1234 5698 7654 32')===[], 'iban() accepts mod-97-valid IBAN values.', $failures);
panel_field_catalog_assert(Field::make('billing_iban')->iban()->validateValue('GB82 WEST 1234 5698 7654 33')!==[], 'iban() rejects mod-97-invalid IBAN values.', $failures);

$stepper=Field::make('stock', 'number')->min(0)->max(20)->step(5)->stepperButtons('-5', '+5')->toArray();
$stepper_prepend=panel_field_catalog_meta($stepper, 'prepend_buttons');
$stepper_append=panel_field_catalog_meta($stepper, 'append_buttons');
panel_field_catalog_assert(is_array($stepper_prepend) && ($stepper_prepend[0]['action'] ?? null)==='decrement', 'stepperButtons() prepends a decrement side button.', $failures);
panel_field_catalog_assert(is_array($stepper_append) && ($stepper_append[0]['action'] ?? null)==='increment', 'stepperButtons() appends an increment side button.', $failures);
panel_field_catalog_assert(($stepper_prepend[0]['label'] ?? null)==='-5' && ($stepper_append[0]['label'] ?? null)==='+5', 'stepperButtons() keeps provided labels.', $failures);
$bounded_number=Field::make('quantity')->number(0, 100, 0.5)->toArray();
panel_field_catalog_assert($bounded_number['type']==='number' && panel_field_catalog_meta($bounded_number, 'min')===0 && panel_field_catalog_meta($bounded_number, 'max')===100 && panel_field_catalog_meta($bounded_number, 'step')===0.5, 'number() configures bounded numeric metadata.', $failures);
panel_field_catalog_assert(in_array('numeric', $bounded_number['component']['capabilities'], true) && in_array('bounded', $bounded_number['component']['capabilities'], true) && in_array('stepped', $bounded_number['component']['capabilities'], true), 'number() exposes numeric component capabilities.', $failures);
$integer=Field::make('stock')->integer(0, 20)->stepperButtons()->toArray();
panel_field_catalog_assert($integer['type']==='integer' && panel_field_catalog_meta($integer, 'input_mode')==='numeric' && panel_field_catalog_meta($integer, 'step')===1 && in_array('steppers', $integer['component']['capabilities'], true), 'integer() configures numeric input mode and stepper capabilities.', $failures);
panel_field_catalog_assert(Field::make('stock')->integer(0, 20)->dehydrateValue('4')===4, 'integer() dehydrates submitted integers.', $failures);
panel_field_catalog_assert(Field::make('stock')->integer(0, 20)->validateValue('20.5')!==[], 'integer() rejects fractional values.', $failures);
panel_field_catalog_assert(Field::make('stock')->integer(0, 20)->validateValue('21')!==[], 'integer() validates max bounds.', $failures);
$decimal=Field::make('price')->decimal(2, 0, 999)->toArray();
panel_field_catalog_assert($decimal['type']==='decimal' && panel_field_catalog_meta($decimal, 'step')==='0.01' && panel_field_catalog_meta($decimal, 'decimal_scale')===2 && panel_field_catalog_meta($decimal, 'input_mode')==='decimal', 'decimal() configures scale, step, and decimal input mode.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('number')['input'] ?? null)==='number' && in_array('steppers', \Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('integer')['capabilities'] ?? [], true), 'Component registry exposes numeric input metadata.', $failures);
$money=Field::make('subtotal')->money('USD', 2)->toArray();
panel_field_catalog_assert($money['type']==='money' && panel_field_catalog_meta($money, 'prepend_label')==='USD' && panel_field_catalog_meta($money, 'step')==='0.01' && panel_field_catalog_meta($money, 'format_rule')==='currency', 'money() configures currency adornment and decimal formatting.', $failures);
panel_field_catalog_assert(Field::make('subtotal')->money('USD', 2)->dehydrateValue('12.30')===12.3, 'money() dehydrates submitted numeric values.', $failures);
panel_field_catalog_assert(Field::make('subtotal')->money('USD', 2)->minValue(0)->validateValue('-1')!==[], 'money() validates numeric min bounds.', $failures);
$percent=Field::make('tax_rate')->percentage(2)->toArray();
panel_field_catalog_assert($percent['type']==='percent' && panel_field_catalog_meta($percent, 'append_label')==='%' && panel_field_catalog_meta($percent, 'step')==='0.01' && panel_field_catalog_meta($percent, 'format_rule')==='percent', 'percentage() configures percent adornment and decimal formatting.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('money')['input'] ?? null)==='number' && in_array('currency_format', \Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('money')['capabilities'] ?? [], true), 'Component registry exposes money field metadata.', $failures);
$money_html=panel_field_catalog_render_control('subtotal', Field::make('subtotal')->money('USD', 2), '12.3');
panel_field_catalog_assert(str_contains($money_html, 'type="number"') && str_contains($money_html, 'step="0.01"') && str_contains($money_html, 'data-dp-panel-format="currency"') && str_contains($money_html, 'USD'), 'Renderer emits money numeric input formatting and adornment metadata.', $failures);
$percent_html=panel_field_catalog_render_control('tax_rate', Field::make('tax_rate')->percentage(2), '7.5');
panel_field_catalog_assert(str_contains($percent_html, 'type="number"') && str_contains($percent_html, 'step="0.01"') && str_contains($percent_html, 'data-dp-panel-format="percent"'), 'Renderer emits percent numeric input formatting metadata.', $failures);
panel_field_catalog_assert(str_contains($percent_html, 'dp-panel-input-addon-append') && str_contains($percent_html, '%'), 'Renderer emits percent append adornment metadata.', $failures);
$password=Field::make('admin_password')->password()->toArray();
panel_field_catalog_assert($password['type']==='password' && panel_field_catalog_meta($password, 'password_reveal')===true && panel_field_catalog_meta($password, 'autocomplete')==='current-password', 'password() configures revealable current-password metadata.', $failures);
panel_field_catalog_assert(in_array('secret', $password['component']['capabilities'], true) && in_array('revealable', $password['component']['capabilities'], true), 'password() exposes secret and revealable capabilities.', $failures);
panel_field_catalog_assert(Field::make('admin_password')->newPassword(false)->toArray()['meta']['password_reveal']===false, 'newPassword() can disable reveal controls.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('password')['input'] ?? null)==='password' && in_array('revealable', \Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('password')['capabilities'] ?? [], true), 'Component registry exposes password field metadata.', $failures);
$password_html=panel_field_catalog_render_control('admin_password', Field::make('admin_password')->password(), 'secret');
panel_field_catalog_assert(str_contains($password_html, 'type="password"') && str_contains($password_html, 'autocomplete="current-password"') && str_contains($password_html, 'data-dp-panel-field-button="toggle_password"'), 'Renderer emits revealable password input metadata.', $failures);
$hidden_password_html=panel_field_catalog_render_control('api_secret', Field::make('api_secret')->password(false), 'secret');
panel_field_catalog_assert(str_contains($hidden_password_html, 'type="password"') && !str_contains($hidden_password_html, 'toggle_password'), 'Renderer omits password reveal button when revealable is disabled.', $failures);
$hidden_field=Field::make('return_to')->hiddenField('/orders')->toArray();
panel_field_catalog_assert($hidden_field['type']==='hidden' && $hidden_field['default']==='/orders' && $hidden_field['hidden']===false, 'hiddenField() configures a submitted hidden input without hiding the schema component.', $failures);
panel_field_catalog_assert(in_array('hidden_input', $hidden_field['component']['capabilities'], true) && $hidden_field['component']['category']==='hidden', 'hiddenField() exposes hidden input component metadata.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('hidden')['input'] ?? null)==='hidden', 'Component registry exposes hidden input metadata.', $failures);
$hidden_html=panel_field_catalog_render_control('return_to', Field::make('return_to')->hiddenInput(), '/orders');
panel_field_catalog_assert($hidden_html==='<input type="hidden" name="return_to" value="/orders">', 'Renderer emits hidden input controls without visible adornment wrappers.', $failures);
panel_field_catalog_assert(Field::make('return_to')->hiddenValue('/orders')->toArray()['default']==='/orders', 'hiddenValue() aliases hiddenField() default values.', $failures);
$text=Field::make('title')->text(80)->placeholder('Order title')->toArray();
panel_field_catalog_assert($text['type']==='text' && panel_field_catalog_meta($text, 'max_length')===80 && in_array('max_length', $text['component']['capabilities'], true), 'text() configures explicit text input max length metadata.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('text')['input'] ?? null)==='text', 'Component registry exposes text input metadata.', $failures);
$text_html=panel_field_catalog_render_control('title', Field::make('title')->text(80)->placeholder('Order title'), 'Draft');
panel_field_catalog_assert(str_contains($text_html, 'type="text"') && str_contains($text_html, 'maxlength="80"') && str_contains($text_html, 'placeholder="Order title"'), 'Renderer emits text input max length and placeholder metadata.', $failures);
$search_html=panel_field_catalog_render_control('query', Field::make('query')->search(48)->placeholder('Search orders'), 'SO');
panel_field_catalog_assert(str_contains($search_html, 'type="search"') && str_contains($search_html, 'maxlength="48"') && str_contains($search_html, 'autocomplete="off"'), 'Renderer emits native search input metadata.', $failures);
$autocomplete=Field::make('operator')->autocomplete(['noel'=>'Noel Dupui', 'iris'=>'Iris Patel'])->placeholder('Choose or type')->toArray();
panel_field_catalog_assert($autocomplete['type']==='autocomplete' && panel_field_catalog_meta($autocomplete, 'autocomplete')==='off' && in_array('datalist', $autocomplete['component']['capabilities'], true) && in_array('free_text', $autocomplete['component']['capabilities'], true), 'autocomplete() configures free-text datalist metadata.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('autocomplete')['input'] ?? null)==='text' && in_array('datalist', \Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('autocomplete')['capabilities'] ?? [], true), 'Component registry exposes autocomplete datalist metadata.', $failures);
$autocomplete_html=panel_field_catalog_render_control('operator', Field::make('operator')->autocomplete(['noel'=>'Noel Dupui', 'iris'=>'Iris Patel'])->placeholder('Choose or type'), 'Iris Patel');
panel_field_catalog_assert(str_contains($autocomplete_html, 'data-dp-panel-autocomplete="1"') && str_contains($autocomplete_html, '<datalist') && str_contains($autocomplete_html, 'value="Iris Patel"') && str_contains($autocomplete_html, 'option value="noel"') && str_contains($autocomplete_html, 'label="Noel Dupui"'), 'Renderer emits autocomplete input and datalist options.', $failures);
$combobox=Field::make('route')->comboBox(['priority'=>'Priority lane'])->toArray();
panel_field_catalog_assert($combobox['type']==='combobox' && in_array('free_text', $combobox['component']['capabilities'], true), 'comboBox() aliases autocomplete-style free-text suggestions.', $failures);
$length_field=Field::make('nickname')->text()->lengthBetween(3, 12)->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($length_field, 'min_length')===3 && panel_field_catalog_meta($length_field, 'max_length')===12 && in_array('min_length', $length_field['component']['capabilities'], true) && in_array('max_length', $length_field['component']['capabilities'], true), 'lengthBetween() configures text length bounds and capabilities.', $failures);
panel_field_catalog_assert(Field::make('nickname')->text()->lengthBetween(3, 12)->validateValue('Ada')===[] && Field::make('nickname')->text()->lengthBetween(3, 12)->validateValue('Al')!==[] && Field::make('nickname')->text()->lengthBetween(3, 12)->validateValue('Alexanderthegreat')!==[], 'Text length metadata validates minimum and maximum lengths server-side.', $failures);
$exact_length=Field::make('pin')->password()->length(6)->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($exact_length, 'exact_length')===6 && in_array('exact_length', $exact_length['component']['capabilities'], true), 'length() configures exact-length metadata.', $failures);
panel_field_catalog_assert(Field::make('pin')->password()->exactLength(6)->validateValue('123456')===[] && Field::make('pin')->password()->exactLength(6)->validateValue('12345')!==[], 'exactLength() validates exact text lengths server-side.', $failures);
$length_html=panel_field_catalog_render_control('nickname', Field::make('nickname')->text()->lengthBetween(3, 12)->characterCounter(), 'Ada');
panel_field_catalog_assert(str_contains($length_html, 'minlength="3"') && str_contains($length_html, 'maxlength="12"') && str_contains($length_html, 'data-dp-panel-character-counter-max="12"'), 'Renderer emits min/max length attributes and counter metadata from length bounds.', $failures);
$icon_adorned=Field::make('search')->text()->prefixIcon('search', 'Search')->suffixIcon('sparkles', 'Smart suggestions')->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($icon_adorned, 'prepend_icons')[0]['icon']==='search' && panel_field_catalog_meta($icon_adorned, 'append_icons')[0]['icon']==='sparkles' && in_array('adornment_icons', $icon_adorned['component']['capabilities'], true), 'prefixIcon() and suffixIcon() configure input icon adornments.', $failures);
$icon_adorned_html=panel_field_catalog_render_control('search', Field::make('search')->text()->prefixIcon('search', 'Search')->suffixIcon('sparkles', 'Smart suggestions'), 'boots');
panel_field_catalog_assert(str_contains($icon_adorned_html, 'data-dp-panel-input-icon="search"') && str_contains($icon_adorned_html, 'data-dp-panel-input-icon="sparkles"') && str_contains($icon_adorned_html, 'title="Smart suggestions"'), 'Renderer emits input icon adornments with accessible titles.', $failures);
$a11y_field=Field::make('sku')->text()->minUsableWidth(320)->minUsableCharacters(24)->minTouchTarget(44)->maxAdornmentRatio(0.45)->maxLabelRatio(0.55)->contrastPolicy(['min_ratio'=>4.5, 'scope'=>'control'])->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($a11y_field, 'accessibility')['min_usable_width']===320 && panel_field_catalog_meta($a11y_field, 'accessibility')['min_usable_chars']===24 && panel_field_catalog_meta($a11y_field, 'accessibility')['min_touch_target']===44 && panel_field_catalog_meta($a11y_field, 'accessibility')['max_adornment_ratio']===0.45 && panel_field_catalog_meta($a11y_field, 'accessibility')['max_label_ratio']===0.55 && in_array('accessibility_policy', $a11y_field['component']['capabilities'], true) && in_array('usable_width_policy', $a11y_field['component']['capabilities'], true) && in_array('touch_target_policy', $a11y_field['component']['capabilities'], true) && in_array('adornment_pressure_policy', $a11y_field['component']['capabilities'], true) && in_array('label_pressure_policy', $a11y_field['component']['capabilities'], true) && in_array('contrast_policy', $a11y_field['component']['capabilities'], true), 'Accessibility policy helpers configure usable width, touch target, adornment pressure, label pressure, and contrast metadata.', $failures);
$a11y_html=panel_field_catalog_render_field('sku', Field::make('sku')->text()->minUsableWidth(320)->minUsableCharacters(24)->minTouchTarget(44)->maxAdornmentRatio(0.45)->maxLabelRatio(0.55)->contrastPolicy(4.5), 'ABC-123');
panel_field_catalog_assert(str_contains($a11y_html, 'data-dp-panel-a11y-policy="1"') && str_contains($a11y_html, 'data-dp-panel-a11y-min-usable-width="320"') && str_contains($a11y_html, 'data-dp-panel-a11y-min-usable-chars="24"') && str_contains($a11y_html, 'data-dp-panel-a11y-min-touch-target="44"') && str_contains($a11y_html, 'data-dp-panel-a11y-max-adornment-ratio="0.45"') && str_contains($a11y_html, 'data-dp-panel-a11y-max-label-ratio="0.55"') && str_contains($a11y_html, 'data-dp-panel-a11y-contrast-min="4.5"'), 'Renderer emits field accessibility policy data attributes.', $failures);
$a11y_array=Field::fromArray(['name'=>'sku', 'accessibility'=>['min_width'=>280, 'contrast'=>['min_ratio'=>7, 'scope'=>'label']]])->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($a11y_array, 'accessibility')['min_usable_width']===280 && panel_field_catalog_meta($a11y_array, 'accessibility')['contrast_policy']['scope']==='label', 'Field::fromArray() accepts accessibility policy definitions.', $failures);
$section_a11y=FormSection::make('Shipping')->minUsableWidth(300)->minTouchTarget(44)->maxAdornmentRatio(0.4)->maxLabelRatio(0.5)->contrastPolicy(7, 'label')->toArray();
panel_field_catalog_assert(($section_a11y['meta']['accessibility']['min_usable_width'] ?? null)===300 && ($section_a11y['meta']['accessibility']['min_touch_target'] ?? null)===44 && ($section_a11y['meta']['accessibility']['max_adornment_ratio'] ?? null)===0.4 && ($section_a11y['meta']['accessibility']['max_label_ratio'] ?? null)===0.5 && ($section_a11y['meta']['accessibility']['contrast_policy']['scope'] ?? null)==='label', 'FormSection accessibility policies configure inherited field defaults.', $failures);
$form_a11y=ResourceForm::make()->minUsableCharacters(28)->minTouchTarget(44)->maxAdornmentRatio(0.45)->maxLabelRatio(0.55)->contrastPolicy(['min_ratio'=>4.5])->toArray();
panel_field_catalog_assert(($form_a11y['meta']['accessibility']['min_usable_chars'] ?? null)===28 && ($form_a11y['meta']['accessibility']['min_touch_target'] ?? null)===44 && ($form_a11y['meta']['accessibility']['max_adornment_ratio'] ?? null)===0.45 && ($form_a11y['meta']['accessibility']['max_label_ratio'] ?? null)===0.55 && ($form_a11y['meta']['accessibility']['contrast_policy']['min_ratio'] ?? null)===4.5, 'ResourceForm accessibility policies configure form-wide defaults.', $failures);
$a11y_default_attrs=panel_field_catalog_a11y_default_attrs($form_a11y['meta']);
panel_field_catalog_assert(str_contains($a11y_default_attrs, 'data-dp-panel-a11y-default="1"') && str_contains($a11y_default_attrs, 'data-dp-panel-a11y-default-min-usable-chars="28"') && str_contains($a11y_default_attrs, 'data-dp-panel-a11y-default-min-touch-target="44"') && str_contains($a11y_default_attrs, 'data-dp-panel-a11y-default-max-adornment-ratio="0.45"') && str_contains($a11y_default_attrs, 'data-dp-panel-a11y-default-max-label-ratio="0.55"') && str_contains($a11y_default_attrs, 'data-dp-panel-a11y-default-contrast-min="4.5"'), 'Renderer emits inherited accessibility default attributes.', $failures);
$a11y_opt_out=Field::make('internal_note')->text()->withoutAccessibilityPolicy()->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($a11y_opt_out, 'accessibility_inherit')===false && in_array('accessibility_policy_opt_out', $a11y_opt_out['component']['capabilities'], true), 'withoutAccessibilityPolicy() disables inherited accessibility policies for a field.', $failures);
$a11y_opt_out_html=panel_field_catalog_render_field('internal_note', Field::make('internal_note')->text()->withoutAccessibilityPolicy(), 'Draft');
panel_field_catalog_assert(str_contains($a11y_opt_out_html, 'data-dp-panel-a11y-disabled="1"') && !str_contains($a11y_opt_out_html, 'data-dp-panel-a11y-policy="1"'), 'Renderer emits accessibility opt-out markers without direct policy markers.', $failures);
$a11y_opt_out_array=Field::fromArray(['name'=>'internal_note', 'a11y'=>false])->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($a11y_opt_out_array, 'accessibility_inherit')===false, 'Field::fromArray() accepts a11y=false accessibility opt-outs.', $failures);
$textarea=Field::make('notes')->textarea(7)->toArray();
panel_field_catalog_assert($textarea['type']==='textarea' && panel_field_catalog_meta($textarea, 'rows')===7 && panel_field_catalog_meta($textarea, 'auto_resize')===true && in_array('multiline', $textarea['component']['capabilities'], true), 'textarea() configures rows and autoresize metadata.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('textarea')['input'] ?? null)==='textarea', 'Component registry exposes textarea metadata.', $failures);
$textarea_html=panel_field_catalog_render_control('notes', Field::make('notes')->longText(6), "Line one\nLine two");
panel_field_catalog_assert(str_contains($textarea_html, '<textarea') && str_contains($textarea_html, 'rows="6"') && str_contains($textarea_html, 'data-dp-panel-auto-resize="1"') && str_contains($textarea_html, "Line one\nLine two"), 'Renderer emits textarea rows, autoresize, and multiline values.', $failures);
$file=Field::make('attachment')->file(['.pdf', 'image/*'], 5242880, true)->toArray();
panel_field_catalog_assert($file['type']==='file' && $file['multiple']===true && $file['accepted_types']===['.pdf', 'image/*'] && $file['max_file_size']===5242880, 'file() configures accepted types, max file size, and multiple metadata.', $failures);
panel_field_catalog_assert(in_array('accepted_types', $file['component']['capabilities'], true) && in_array('multiple', $file['component']['capabilities'], true), 'file() exposes upload component capabilities.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('file')['input'] ?? null)==='file' && (\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('file_upload')['file_upload'] ?? false)===true, 'Component registry exposes file upload metadata.', $failures);
$file_html=panel_field_catalog_render_control('attachment', Field::make('attachment')->file(['.pdf', 'image/*'], 5242880, true));
panel_field_catalog_assert(str_contains($file_html, 'type="file"') && str_contains($file_html, 'name="attachment[]"') && str_contains($file_html, 'accept=".pdf,image/*"') && str_contains($file_html, 'multiple'), 'Renderer emits native multiple file input metadata.', $failures);
$image_upload=Field::make('photo')->imageUpload(2097152)->toArray();
panel_field_catalog_assert($image_upload['type']==='image' && $image_upload['accepted_types']===['image/*'] && $image_upload['max_file_size']===2097152, 'imageUpload() configures image-only upload metadata.', $failures);
panel_field_catalog_assert(in_array('image_only', \Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('image')['capabilities'] ?? [], true), 'Component registry exposes image-only file metadata.', $failures);
$enum=Field::make('status')->enum(['draft'=>'Draft', 'published'=>'Published'])->toArray();
panel_field_catalog_assert($enum['type']==='enum' && ($enum['options']['draft'] ?? null)==='Draft' && in_array('choices', $enum['component']['capabilities'], true), 'enum() configures native enum choice metadata.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('enum')['input'] ?? null)==='select', 'Component registry exposes enum select metadata.', $failures);
$enum_html=panel_field_catalog_render_control('status', Field::make('status')->enum(['draft'=>'Draft', 'published'=>'Published']), 'published');
panel_field_catalog_assert(str_contains($enum_html, '<select') && str_contains($enum_html, '<option value="published" selected>Published</option>'), 'Renderer emits enum select options.', $failures);
$range=Field::make('opacity')->range(0, 1, 0.05)->toArray();
panel_field_catalog_assert($range['type']==='range' && panel_field_catalog_meta($range, 'min')===0 && panel_field_catalog_meta($range, 'max')===1 && panel_field_catalog_meta($range, 'step')===0.05, 'range() configures native range bounds and step metadata.', $failures);
panel_field_catalog_assert(in_array('numeric', $range['component']['capabilities'], true) && in_array('bounded', $range['component']['capabilities'], true), 'range() exposes numeric range capabilities.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('range')['input'] ?? null)==='range', 'Component registry exposes native range metadata.', $failures);
$range_html=panel_field_catalog_render_control('opacity', Field::make('opacity')->range(0, 1, 0.05), 0.5);
panel_field_catalog_assert(str_contains($range_html, 'type="range"') && str_contains($range_html, 'min="0"') && str_contains($range_html, 'max="1"') && str_contains($range_html, 'step="0.05"'), 'Renderer emits native range input metadata.', $failures);
$repeater=Field::make('line_items')->repeater([
	Field::make('sku')->text(32),
	Field::make('qty')->integer(1, 99),
], 1, 3)->addItemLabel('Add line')->toArray();
panel_field_catalog_assert($repeater['type']==='repeater' && count($repeater['repeater_fields'])===2 && panel_field_catalog_meta($repeater, 'min_items')===1 && panel_field_catalog_meta($repeater, 'max_items')===3, 'repeater() configures nested fields and item count metadata.', $failures);
panel_field_catalog_assert(in_array('nested_fields', $repeater['component']['capabilities'], true) && in_array('max_items', $repeater['component']['capabilities'], true), 'repeater() exposes nested field and item count capabilities.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('repeater')['input'] ?? null)==='fieldset', 'Component registry exposes repeater fieldset metadata.', $failures);
$repeater_html=panel_field_catalog_render_control('line_items', Field::make('line_items')->repeater([Field::make('sku')->text()], 1, 2)->addItemLabel('Add line'), [['sku'=>'ABC']]);
panel_field_catalog_assert(str_contains($repeater_html, 'data-dp-panel-repeater-min="1"') && str_contains($repeater_html, 'data-dp-panel-repeater-max="2"') && str_contains($repeater_html, 'data-dp-panel-repeater-template') && str_contains($repeater_html, 'Add line'), 'Renderer emits repeater min/max, template, and add label metadata.', $failures);
$field_group=Field::make('shipping_contact')->fieldset([
	Field::make('name')->text(),
	Field::make('phone')->phoneCountryField('market'),
])->description('Nested object submitted with the order.')->toArray();
panel_field_catalog_assert($field_group['type']==='fieldset' && count($field_group['meta']['child_fields'] ?? [])===2 && in_array('field_group', $field_group['component']['capabilities'], true), 'fieldset() configures nested child fields and component capabilities.', $failures);
$field_group_value=Field::make('shipping_contact')->fieldset([
	Field::make('name')->titleCase(),
	Field::make('phone')->digits(),
])->dehydrateValue(['name'=>' iris patel ', 'phone'=>'(514) 555-0199']);
panel_field_catalog_assert($field_group_value===['name'=>'Iris Patel', 'phone'=>'5145550199'], 'fieldset() dehydrates nested child field values.', $failures);
$field_group_html=panel_field_catalog_render_control('shipping_contact', Field::make('shipping_contact')->fieldset([Field::make('name')->text(), Field::make('phone')->phone()])->description('Nested object.'), ['name'=>'Iris', 'phone'=>'5145550199']);
panel_field_catalog_assert(str_contains($field_group_html, 'data-dp-panel-fieldset="1"') && str_contains($field_group_html, 'name="shipping_contact[name]"') && str_contains($field_group_html, 'name="shipping_contact[phone]"') && str_contains($field_group_html, '<legend>Shipping Contact</legend>'), 'Renderer emits fieldset group controls with nested field names.', $failures);
$country_select=Field::make('country')->countrySelect(['CA', 'US'])->toArray();
panel_field_catalog_assert($country_select['type']==='select' && ($country_select['options']['CA'] ?? null)==='Canada' && ($country_select['options']['US'] ?? null)==='United States' && panel_field_catalog_meta($country_select, 'format_rule')==='country_code', 'countrySelect() configures searchable country code options.', $failures);
$subdivision_select=Field::make('province')->subdivisionSelect('CA')->toArray();
panel_field_catalog_assert($subdivision_select['type']==='select' && ($subdivision_select['options']['QC'] ?? null)==='Quebec' && ($subdivision_select['options']['ON'] ?? null)==='Ontario' && panel_field_catalog_meta($subdivision_select, 'format_rule')==='subdivision_code', 'subdivisionSelect() configures country-specific subdivision options.', $failures);
$address=Field::make('shipping_address')->address('CA')->toArray();
panel_field_catalog_assert($address['type']==='address' && count($address['meta']['child_fields'] ?? [])===6 && panel_field_catalog_meta($address, 'address_country')==='CA' && ($address['meta']['child_fields'][3]['type'] ?? null)==='select' && ($address['meta']['child_fields'][3]['options']['QC'] ?? null)==='Quebec' && in_array('address', $address['component']['capabilities'], true) && in_array('country_aware_validation', $address['component']['capabilities'], true), 'address() configures country-aware structured address child fields and capabilities.', $failures);
$address_value=Field::make('shipping_address')->address('CA')->dehydrateValue(['line1'=>' 123 main st ', 'city'=>' montreal ', 'subdivision'=>'qc', 'postal_code'=>'h2x 1y4', 'country'=>'ca']);
panel_field_catalog_assert($address_value['city']==='Montreal' && $address_value['subdivision']==='QC' && $address_value['postal_code']==='H2X1Y4' && $address_value['country']==='CA', 'address() dehydrates nested country, subdivision, and postal fields.', $failures);
$address_html=panel_field_catalog_render_control('shipping_address', Field::make('shipping_address')->address('CA'), ['line1'=>'123 Main', 'city'=>'Montreal', 'subdivision'=>'QC', 'postal_code'=>'H2X 1Y4', 'country'=>'CA']);
panel_field_catalog_assert(str_contains($address_html, 'data-dp-panel-address="1"') && str_contains($address_html, 'data-dp-panel-address-country="CA"') && str_contains($address_html, 'name="shipping_address[postal_code]"') && str_contains($address_html, 'data-dp-panel-format="postal_code_ca"'), 'Renderer emits address fieldsets with nested postal metadata.', $failures);
$builder=Field::make('content_blocks')->builder([
	'hero'=>[
		'label'=>'Hero',
		'fields'=>[
			Field::make('heading')->titleCase(),
			Field::make('body')->textarea(),
		],
	],
	'cta'=>[
		'label'=>'Call to action',
		'fields'=>[
			Field::make('label')->text(),
			Field::make('url')->url(),
		],
	],
], 1, 4)->toArray();
panel_field_catalog_assert($builder['type']==='builder' && count($builder['meta']['builder_blocks'] ?? [])===2 && in_array('builder_blocks', $builder['component']['capabilities'], true), 'builder() configures block schemas and component capabilities.', $failures);
$builder_value=Field::make('content_blocks')->builder([
	'hero'=>['fields'=>[Field::make('heading')->titleCase(), Field::make('body')->textarea()]],
	'cta'=>['fields'=>[Field::make('label')->text(), Field::make('url')->url()]],
])->dehydrateValue([
	['_type'=>'hero', 'heading'=>' spring launch ', 'body'=>'Ready'],
	['_type'=>'cta', 'label'=>'Shop', 'url'=>'example.test'],
]);
panel_field_catalog_assert($builder_value===[['_type'=>'hero', 'heading'=>'Spring Launch', 'body'=>'Ready'], ['_type'=>'cta', 'label'=>'Shop', 'url'=>'https://example.test']], 'builder() dehydrates block rows through each block schema.', $failures);
$builder_html=panel_field_catalog_render_control('content_blocks', Field::make('content_blocks')->builder([
	'hero'=>['label'=>'Hero', 'fields'=>[Field::make('heading')->text()]],
	'cta'=>['label'=>'CTA', 'fields'=>[Field::make('label')->text()]],
], 0, 3), [['_type'=>'hero', 'heading'=>'Launch']]);
panel_field_catalog_assert(str_contains($builder_html, 'data-dp-panel-builder="1"') && str_contains($builder_html, 'data-dp-panel-builder-template="hero"') && str_contains($builder_html, 'data-dp-panel-builder-add="cta"') && str_contains($builder_html, 'name="content_blocks[0][_type]"') && str_contains($builder_html, 'name="content_blocks[0][heading]"'), 'Renderer emits builder block templates, add buttons, and nested field names.', $failures);
$hinted=Field::make('reference')->text()->helperText('Shown on receipts')->hint('Optional', 'info')->toArray();
panel_field_catalog_assert($hinted['help']==='Shown on receipts' && panel_field_catalog_meta($hinted, 'hint')==='Optional' && panel_field_catalog_meta($hinted, 'hint_icon')==='info', 'helperText(), hint(), and hintIcon() configure field guidance metadata.', $failures);
panel_field_catalog_assert(in_array('helper_text', $hinted['component']['capabilities'], true) && in_array('hint', $hinted['component']['capabilities'], true) && in_array('hint_icon', $hinted['component']['capabilities'], true), 'Field guidance exposes helper and hint component capabilities.', $failures);
$hinted_html=panel_field_catalog_render_field('reference', Field::make('reference')->text()->helperText('Shown on receipts')->hint('Optional', 'info'), 'INV-100');
panel_field_catalog_assert(str_contains($hinted_html, 'dp-panel-field-label') && str_contains($hinted_html, 'dp-panel-field-hint') && str_contains($hinted_html, 'Optional') && str_contains($hinted_html, 'Shown on receipts'), 'Renderer emits field label hints and helper text.', $failures);
$disabled=Field::make('external_id')->text()->disabled()->toArray();
panel_field_catalog_assert($disabled['readonly']===true && panel_field_catalog_meta($disabled, 'disabled')===true && in_array('disabled', $disabled['component']['capabilities'], true), 'disabled() aliases readonly state and exposes disabled capabilities.', $failures);
panel_field_catalog_assert(Field::make('external_id')->readonly()->toArray()['readonly']===true, 'readonly() configures read-only field state.', $failures);
panel_field_catalog_assert(Field::make('external_id')->dehydrated(false)->toArray()['meta']['dehydrated']===false && in_array('not_dehydrated', Field::make('external_id')->dehydrated(false)->toArray()['component']['capabilities'], true), 'dehydrated(false) configures non-submitted field metadata.', $failures);
$state_request=PanelRequest::fromArray(['method'=>'POST', 'operation'=>'store', 'input'=>['locked'=>'submitted', 'forced'=>'submitted', 'skip'=>'submitted', 'open'=>'submitted']]);
$state_form=ResourceForm::make()->fields([
	Field::make('locked')->disabled(),
	Field::make('forced')->disabled()->dehydrated(),
	Field::make('skip')->dehydrated(false),
	Field::make('open'),
]);
$state_values=$state_form->dehydrate($state_request)->values();
panel_field_catalog_assert(!array_key_exists('locked', $state_values) && ($state_values['forced'] ?? null)==='submitted' && !array_key_exists('skip', $state_values) && ($state_values['open'] ?? null)==='submitted', 'Form dehydration respects disabled and explicit dehydrated field metadata.', $failures);
$disabled_html=panel_field_catalog_render_control('external_id', Field::make('external_id')->text()->disabled(), 'LOCKED');
panel_field_catalog_assert(str_contains($disabled_html, 'readonly') && str_contains($disabled_html, 'value="LOCKED"'), 'Renderer emits disabled alias fields using existing readonly controls.', $failures);
$display=Field::make('fulfillment_notice', 'placeholder')->content('Inventory is reserved after save.')->description('Visible only inside the form.')->toArray();
panel_field_catalog_assert($display['type']==='placeholder' && panel_field_catalog_meta($display, 'dehydrated')===false && panel_field_catalog_meta($display, 'display_content')==='Inventory is reserved after save.', 'placeholder field type stores display content and disables dehydration by default.', $failures);
panel_field_catalog_assert(in_array('display_only', $display['component']['capabilities'], true) && in_array('static_content', $display['component']['capabilities'], true), 'placeholder field type exposes display-only capabilities.', $failures);
$display_html=panel_field_catalog_render_field('fulfillment_notice', Field::make('fulfillment_notice')->placeholderField('<strong>Reserved</strong>', true)->description('Visible only inside the form.'));
panel_field_catalog_assert(str_contains($display_html, '<div class="dp-panel-field dp-panel-field-display') && str_contains($display_html, 'data-dp-panel-display-field="1"') && str_contains($display_html, '<strong>Reserved</strong>') && !str_contains($display_html, '<label class="dp-panel-field'), 'Renderer emits display fields as non-label safe HTML blocks.', $failures);
$display_form=ResourceForm::make()->fields([
	Field::make('fulfillment_notice')->placeholderField('Not submitted'),
	Field::make('open'),
]);
$display_values=$display_form->dehydrate(PanelRequest::fromArray(['method'=>'POST', 'operation'=>'store', 'input'=>['fulfillment_notice'=>'tampered', 'open'=>'submitted']]))->values();
panel_field_catalog_assert(!array_key_exists('fulfillment_notice', $display_values) && ($display_values['open'] ?? null)==='submitted', 'Display-only fields are not dehydrated by default.', $failures);
$nullable=Field::make('memo')->required()->nullable()->toArray();
panel_field_catalog_assert($nullable['required']===false && in_array('nullable', $nullable['rules'], true) && in_array('nullable', $nullable['component']['capabilities'], true), 'nullable() clears required state and exposes nullable validation metadata.', $failures);
$validation_field=Field::make('promo_code')->regex('/^[A-Z]{3}[0-9]{2}$/')->startsWith(['VIP', 'PRO'])->endsWith(['01', '02'])->toArray();
panel_field_catalog_assert(in_array('validation_rules', $validation_field['component']['capabilities'], true) && in_array('regex:/^[A-Z]{3}[0-9]{2}$/', $validation_field['rules'], true) && in_array('starts_with:VIP,PRO', $validation_field['rules'], true) && in_array('ends_with:01,02', $validation_field['rules'], true), 'regex(), startsWith(), and endsWith() append serializable validation rules.', $failures);
panel_field_catalog_assert(Field::make('promo_code')->regex('/^[A-Z]{3}[0-9]{2}$/')->validateValue('VIP01')===[] && Field::make('promo_code')->regex('/^[A-Z]{3}[0-9]{2}$/')->validateValue('vip01')!==[], 'regex() validates submitted values server-side.', $failures);
panel_field_catalog_assert(Field::make('promo_code')->startsWith(['VIP', 'PRO'])->endsWith(['01', '02'])->validateValue('VIP01')===[] && Field::make('promo_code')->startsWith(['VIP'])->validateValue('PRO01')!==[] && Field::make('promo_code')->endsWith(['01'])->validateValue('VIP02')!==[], 'startsWith() and endsWith() validate submitted values server-side.', $failures);
panel_field_catalog_assert(Field::make('password')->confirmed()->validateValue('secret', ['password_confirmation'=>'secret'])===[] && Field::make('password')->confirmed()->validateValue('secret', ['password_confirmation'=>'other'])!==[], 'confirmed() validates against the conventional confirmation field.', $failures);
panel_field_catalog_assert(Field::make('billing_email')->same('email')->validateValue('buyer@example.com', ['email'=>'buyer@example.com'])===[] && Field::make('billing_email')->different('email')->validateValue('other@example.com', ['email'=>'buyer@example.com'])===[], 'same() and different() validate against sibling field values.', $failures);
$required_if=Field::make('tax_id')->requiredIf('company', true)->toArray();
panel_field_catalog_assert(($required_if['required_when']['company'] ?? null)===true && in_array('company', $required_if['depends_on'], true), 'requiredIf() aliases conditional required dependencies.', $failures);

$ssn_html=panel_field_catalog_render_control('operator_ssn', $ssn_field);
panel_field_catalog_assert(str_contains($ssn_html, 'data-dp-panel-mask="999-99-9999"'), 'Renderer emits mask attributes for masked fields.', $failures);
panel_field_catalog_assert(str_contains($ssn_html, 'data-dp-panel-format-event="input"'), 'Renderer emits format event attributes for masked/formatted fields.', $failures);
panel_field_catalog_assert(str_contains($ssn_html, 'inputmode="numeric"'), 'Renderer emits inputmode attributes.', $failures);
panel_field_catalog_assert(str_contains($ssn_html, 'placeholder="000-00-0000"'), 'Renderer emits generated placeholders for masked fields.', $failures);
panel_field_catalog_assert(str_contains($ssn_html, 'maxlength="11"'), 'Renderer derives maxlength from mask patterns.', $failures);
panel_field_catalog_assert(str_contains($ssn_html, 'pattern="[0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][0-9][0-9]"'), 'Renderer derives native patterns from mask patterns.', $failures);
panel_field_catalog_assert(str_contains($ssn_html, 'title="Expected format: 000-00-0000"'), 'Renderer derives native validation titles from mask patterns.', $failures);
panel_field_catalog_assert(str_contains($ssn_html, 'data-dp-panel-submit-normalized="mask"'), 'Renderer marks unmasked mask fields for submit-time browser normalization.', $failures);
$otp=Field::make('login_code')->oneTimeCode(6)->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($otp, 'mask')==='999999' && panel_field_catalog_meta($otp, 'autocomplete')==='one-time-code', 'oneTimeCode() configures numeric one-time-code metadata.', $failures);
panel_field_catalog_assert(panel_field_catalog_meta($otp, 'character_counter_max')===6 && panel_field_catalog_meta($otp, 'mask_submit_normalized')===true, 'oneTimeCode() enables counters and normalized submit.', $failures);
panel_field_catalog_assert(Field::make('login_code')->oneTimeCode(6)->dehydrateValue('123 456')==='123456', 'oneTimeCode() normalizes submitted code digits.', $failures);
$otp_html=panel_field_catalog_render_control('login_code', Field::make('login_code')->oneTimeCode(6));
panel_field_catalog_assert(str_contains($otp_html, 'autocomplete="one-time-code"') && str_contains($otp_html, 'maxlength="6"') && str_contains($otp_html, 'data-dp-panel-character-counter="1"'), 'Renderer emits one-time code autocomplete, maxlength, and counter metadata.', $failures);
$typed_otp=Field::make('login_code', 'otp')->toArray();
panel_field_catalog_assert($typed_otp['type']==='otp' && panel_field_catalog_meta($typed_otp, 'mask')==='999999' && panel_field_catalog_meta($typed_otp, 'autocomplete')==='one-time-code', 'otp field type configures one-time-code metadata.', $failures);
$typed_pin=Field::make('pin', 'pin_code')->toArray();
panel_field_catalog_assert($typed_pin['type']==='pin_code' && panel_field_catalog_meta($typed_pin, 'mask')==='9999', 'pin_code field type configures four digit PIN metadata.', $failures);
$typed_credit_card=Field::make('card_number', 'credit_card')->toArray();
panel_field_catalog_assert($typed_credit_card['type']==='credit_card' && panel_field_catalog_meta($typed_credit_card, 'format_rule')==='credit_card', 'credit_card field type configures card formatting metadata.', $failures);
$typed_credit_card_html=panel_field_catalog_render_control('card_number', Field::make('card_number', 'credit_card'));
panel_field_catalog_assert(str_contains($typed_credit_card_html, 'data-dp-panel-format="credit_card"') && str_contains($typed_credit_card_html, 'placeholder="0000 0000 0000 0000"'), 'Renderer emits first-class credit_card type formatting metadata.', $failures);
$typed_country=Field::make('country', 'country_code')->toArray();
panel_field_catalog_assert($typed_country['type']==='country_code' && panel_field_catalog_meta($typed_country, 'format_rule')==='country_code' && in_array('format', $typed_country['component']['capabilities'], true), 'country_code field type configures formatter metadata.', $failures);
panel_field_catalog_assert(Field::make('country', 'country_code')->dehydrateValue(' Canada ')==='CA', 'country_code field type normalizes country names.', $failures);
$typed_currency_html=panel_field_catalog_render_control('currency', Field::make('currency', 'currency_code'), 'CAD');
panel_field_catalog_assert(str_contains($typed_currency_html, 'data-dp-panel-format="currency_code"') && str_contains($typed_currency_html, 'placeholder="CAD"'), 'Renderer emits first-class currency_code type metadata.', $failures);
$typed_postal_html=panel_field_catalog_render_control('postal', Field::make('postal', 'postal_code_ca'), 'H2Y 1C6');
panel_field_catalog_assert(str_contains($typed_postal_html, 'data-dp-panel-format="postal_code_ca"') && str_contains($typed_postal_html, 'placeholder="A0A 0A0"'), 'Renderer emits first-class postal code type metadata.', $failures);
$typed_latitude_html=panel_field_catalog_render_control('latitude', Field::make('latitude', 'latitude'), '45.501689');
panel_field_catalog_assert(str_contains($typed_latitude_html, 'type="number"') && str_contains($typed_latitude_html, 'data-dp-panel-format="latitude"') && str_contains($typed_latitude_html, 'min="-90"'), 'Renderer emits first-class latitude number metadata.', $failures);
$typed_coordinates=Field::make('coordinates', 'coordinates')->toArray();
panel_field_catalog_assert($typed_coordinates['type']==='coordinates' && panel_field_catalog_meta($typed_coordinates, 'format_rule')==='coordinates', 'coordinates field type configures coordinate-pair metadata.', $failures);
panel_field_catalog_assert(Field::make('coordinates', 'coordinates')->dehydrateValue('45.501689,-73.567256')==='45.501689,-73.567256', 'coordinates field type normalizes coordinate pairs.', $failures);
$typed_hex_html=panel_field_catalog_render_control('brand_hex', Field::make('brand_hex', 'hex_color'), '#3366cc');
panel_field_catalog_assert(str_contains($typed_hex_html, 'data-dp-panel-format="hex_color"') && str_contains($typed_hex_html, 'data-dp-panel-color-swatch="1"'), 'Renderer emits first-class hex_color type metadata and swatch.', $failures);

$sku_html=panel_field_catalog_render_control('sku', Field::make('sku')->mask('AA-999'));
panel_field_catalog_assert(str_contains($sku_html, 'placeholder="AA-000"'), 'Renderer translates mask patterns into input placeholders.', $failures);
panel_field_catalog_assert(str_contains($sku_html, 'pattern="[A-Z][A-Z]-[0-9][0-9][0-9]"'), 'Renderer emits letter and digit mask patterns.', $failures);
panel_field_catalog_assert(!str_contains($sku_html, 'data-dp-panel-submit-normalized='), 'Renderer leaves submitMasked() mask fields formatted on submit.', $failures);
$long_mask_html=panel_field_catalog_render_control('custom_mask', Field::make('custom_mask')->mask('999-999')->maxLength(20));
panel_field_catalog_assert(str_contains($long_mask_html, 'maxlength="20"') && !str_contains($long_mask_html, 'maxlength="7"'), 'Explicit maxLength() overrides mask-derived maxlength.', $failures);
$custom_pattern_html=panel_field_catalog_render_control('custom_mask', Field::make('custom_mask')->mask('999-999')->pattern('[0-9-]+'));
panel_field_catalog_assert(str_contains($custom_pattern_html, 'pattern="[0-9-]+"') && !str_contains($custom_pattern_html, 'pattern="[0-9][0-9][0-9]') && !str_contains($custom_pattern_html, 'Expected format:'), 'Explicit pattern() overrides mask-derived patterns and titles.', $failures);
$custom_title_html=panel_field_catalog_render_control('custom_mask', Field::make('custom_mask')->mask('999-999')->meta(['title'=>'Use six digits with a dash']));
panel_field_catalog_assert(str_contains($custom_title_html, 'title="Use six digits with a dash"') && !str_contains($custom_title_html, 'Expected format:'), 'Explicit title metadata overrides generated mask titles.', $failures);
$custom_placeholder_html=panel_field_catalog_render_control('compliance_ssn', Field::make('compliance_ssn')->mask('999-99-9999', true)->maskPlaceholder('000-00-0000'));
panel_field_catalog_assert(str_contains($custom_placeholder_html, 'placeholder="000-00-0000"'), 'Renderer emits explicit mask placeholders.', $failures);
$hidden_placeholder_html=panel_field_catalog_render_control('secret_ssn', Field::make('secret_ssn')->mask('999-99-9999')->hideMaskPlaceholder());
panel_field_catalog_assert(!str_contains($hidden_placeholder_html, 'placeholder='), 'Renderer suppresses mask placeholders when hidden.', $failures);
$counter_html=panel_field_catalog_render_control('short_note', Field::make('short_note')->maxLength(24)->characterCounter());
panel_field_catalog_assert(str_contains($counter_html, 'data-dp-panel-character-counter="1"') && str_contains($counter_html, 'data-dp-panel-character-counter-max="24"') && str_contains($counter_html, '0/24'), 'Renderer emits character counter adornments.', $failures);
$prepend_counter_html=panel_field_catalog_render_control('short_note', Field::make('short_note')->characterCounter(12, 'prepend'));
$prepend_counter_pos=strpos($prepend_counter_html, 'data-dp-panel-character-counter="1"');
$prepend_input_pos=strpos($prepend_counter_html, '<input');
panel_field_catalog_assert($prepend_counter_pos!==false && $prepend_input_pos!==false && $prepend_counter_pos<$prepend_input_pos, 'Renderer can prepend character counter adornments.', $failures);

$copy_html=panel_field_catalog_render_control('tax_reference', Field::make('tax_reference')->ein()->copyButton());
panel_field_catalog_assert(str_contains($copy_html, 'data-dp-panel-field-button="copy"') && !str_contains($copy_html, 'data-dp-panel-field-button-copy="normalized"'), 'copyButton() copies visible values by default.', $failures);
$copy_normalized_html=panel_field_catalog_render_control('tax_reference', Field::make('tax_reference')->ein()->copyNormalizedButton());
panel_field_catalog_assert(str_contains($copy_normalized_html, 'data-dp-panel-field-button-copy="normalized"'), 'Renderer emits normalized copy button metadata.', $failures);
$copyable_html=panel_field_catalog_render_control('tax_reference', Field::make('tax_reference')->ein()->copyableNormalized());
panel_field_catalog_assert(str_contains($copyable_html, 'data-dp-panel-field-button="copy"') && str_contains($copyable_html, 'data-dp-panel-field-button-copy="normalized"'), 'Renderer auto-adds normalized copy buttons for copyable fields.', $failures);

$zip_html=panel_field_catalog_render_control('delivery_zip', Field::make('delivery_zip')->zipCode());
panel_field_catalog_assert(str_contains($zip_html, 'data-dp-panel-format="zip_code_us"'), 'Renderer emits ZIP format attributes.', $failures);
panel_field_catalog_assert(str_contains($zip_html, 'data-dp-panel-format-event="input"'), 'Renderer emits ZIP format event attributes.', $failures);
panel_field_catalog_assert(str_contains($zip_html, 'data-dp-panel-submit-normalized="zip_code_us"'), 'Renderer marks formatted fields for submit-time browser normalization.', $failures);
panel_field_catalog_assert(str_contains($zip_html, 'placeholder="00000-0000"'), 'Renderer emits generated format placeholders.', $failures);
panel_field_catalog_assert(str_contains($zip_html, 'pattern="[0-9]{5}(-[0-9]{4})?"'), 'Renderer emits generated ZIP validation patterns.', $failures);
panel_field_catalog_assert(str_contains($zip_html, 'title="Expected format: 00000-0000"'), 'Renderer emits generated ZIP validation titles.', $failures);

$phone_html=panel_field_catalog_render_control('contact_phone', Field::make('contact_phone')->phone());
panel_field_catalog_assert(str_contains($phone_html, 'data-dp-panel-format="phone_international"'), 'phone() registers the canonical international phone format rule.', $failures);
panel_field_catalog_assert(str_contains($phone_html, 'placeholder="+1 000 000 0000"'), 'Renderer emits generated international phone placeholders.', $failures);
panel_field_catalog_assert(str_contains($phone_html, 'pattern="\+[0-9]{1,3}[0-9 \-]{5,18}"'), 'Renderer emits generated international phone validation patterns.', $failures);
panel_field_catalog_assert(str_contains($phone_html, 'title="Expected international phone number with country code."'), 'Renderer emits semantic international phone validation titles.', $failures);
$phone_us_html=panel_field_catalog_render_control('contact_phone', Field::make('contact_phone')->phoneUs());
panel_field_catalog_assert(str_contains($phone_us_html, 'data-dp-panel-format="phone_us"') && str_contains($phone_us_html, 'placeholder="(000) 000-0000"'), 'phoneUs() keeps explicit NANP formatting available.', $failures);
$credit_card_html=panel_field_catalog_render_control('card_number', Field::make('card_number')->creditCard());
panel_field_catalog_assert(str_contains($credit_card_html, 'placeholder="0000 0000 0000 0000"'), 'Renderer emits generated credit card placeholders.', $failures);
panel_field_catalog_assert(str_contains($credit_card_html, 'pattern="') && str_contains($credit_card_html, 'title="Expected format: 0000 0000 0000 0000"'), 'Renderer emits generated credit card validation metadata.', $failures);
$expiry_html=panel_field_catalog_render_control('card_expiry', Field::make('card_expiry')->creditCardExpiry());
panel_field_catalog_assert(str_contains($expiry_html, 'placeholder="MM/YY"') && str_contains($expiry_html, 'pattern="(0[1-9]|1[0-2])/[0-9]{2}"'), 'Renderer emits generated card expiry validation metadata.', $failures);
$cvc_html=panel_field_catalog_render_control('card_cvc', Field::make('card_cvc')->cardCvc());
panel_field_catalog_assert(str_contains($cvc_html, 'placeholder="000"') && str_contains($cvc_html, 'pattern="[0-9]{3,4}"'), 'Renderer emits generated card CVC validation metadata.', $failures);
$iban_html=panel_field_catalog_render_control('billing_iban', Field::make('billing_iban')->iban());
panel_field_catalog_assert(str_contains($iban_html, 'placeholder="CA00 0000 0000 0000 0000 0000"'), 'Renderer emits generated IBAN placeholders.', $failures);
panel_field_catalog_assert(str_contains($iban_html, 'pattern="[A-Z]{2}[0-9]{2}( [0-9A-Z]{4}){2,7}( [0-9A-Z]{1,4})?"'), 'Renderer emits generated IBAN validation patterns.', $failures);
$custom_format_pattern_html=panel_field_catalog_render_control('contact_phone', Field::make('contact_phone')->phone()->pattern('[0-9]+')->meta(['title'=>'Digits only']));
panel_field_catalog_assert(str_contains($custom_format_pattern_html, 'pattern="[0-9]+"') && str_contains($custom_format_pattern_html, 'title="Digits only"') && !str_contains($custom_format_pattern_html, 'Expected format:'), 'Explicit pattern() and title metadata override generated format validation metadata.', $failures);
$custom_format_placeholder_html=panel_field_catalog_render_control('delivery_zip', Field::make('delivery_zip')->zipCode()->formatPlaceholder('ZIP or ZIP+4'));
panel_field_catalog_assert(str_contains($custom_format_placeholder_html, 'placeholder="ZIP or ZIP+4"'), 'Renderer emits explicit format placeholders.', $failures);
$hidden_format_placeholder_html=panel_field_catalog_render_control('delivery_zip', Field::make('delivery_zip')->zipCode()->hideFormatPlaceholder());
panel_field_catalog_assert(!str_contains($hidden_format_placeholder_html, 'placeholder='), 'Renderer suppresses format placeholders when hidden.', $failures);
$locale_zip_html=panel_field_catalog_render_control('delivery_zip', Field::make('delivery_zip')->zipCodeCountryField('market')->prependLabel('CA'));
panel_field_catalog_assert(str_contains($locale_zip_html, '&quot;country_field&quot;:&quot;market&quot;'), 'Renderer emits country-aware format options.', $failures);
panel_field_catalog_assert(str_contains($locale_zip_html, 'data-dp-panel-format="postal_code"') && str_contains($locale_zip_html, 'pattern="[0-9A-Z][0-9A-Z ]{2,16}"'), 'Renderer keeps a safe canonical postal-code pattern before browser locale refresh.', $failures);
$subdivision_zip_html=panel_field_catalog_render_control('delivery_zip', Field::make('delivery_zip')->zipCodeCountryField('market')->formatSubdivisionField('delivery_region'));
panel_field_catalog_assert(str_contains($subdivision_zip_html, '&quot;subdivision_field&quot;:&quot;delivery_region&quot;'), 'Renderer emits subdivision-aware format options.', $failures);
$explicit_locale_zip_html=panel_field_catalog_render_control('delivery_zip', Field::make('delivery_zip')->zipCodeCountryField('market')->placeholder('Postal')->pattern('[A-Z]+')->meta(['title'=>'Postal only']));
panel_field_catalog_assert(str_contains($explicit_locale_zip_html, 'data-dp-panel-explicit-placeholder="1"') && str_contains($explicit_locale_zip_html, 'data-dp-panel-explicit-pattern="1"') && str_contains($explicit_locale_zip_html, 'data-dp-panel-explicit-title="1"'), 'Renderer marks explicit format validation attributes so locale refresh preserves them.', $failures);

$formatted_html=panel_field_catalog_render_control('website', Field::make('website')->url()->submitFormatted());
panel_field_catalog_assert(!str_contains($formatted_html, 'data-dp-panel-submit-normalized='), 'submitFormatted() suppresses browser submit normalization.', $failures);
$map_url_html=panel_field_catalog_render_control('map_url', Field::make('map_url')->mapUrl());
panel_field_catalog_assert(str_contains($map_url_html, 'data-dp-panel-format="map_url"') && str_contains($map_url_html, 'placeholder="https://www.google.com/maps?q=45.501689,-73.567256"'), 'Renderer emits generated map URL metadata.', $failures);
$domain_html=panel_field_catalog_render_control('store_domain', Field::make('store_domain')->domain());
panel_field_catalog_assert(str_contains($domain_html, 'placeholder="example.com"') && str_contains($domain_html, 'pattern="[A-Za-z0-9.-]{3,253}"'), 'Renderer emits generated domain validation metadata.', $failures);
$timezone_html=panel_field_catalog_render_control('timezone', Field::make('timezone')->timezone());
panel_field_catalog_assert(str_contains($timezone_html, 'placeholder="America/Toronto"') && str_contains($timezone_html, 'data-dp-panel-format="timezone"'), 'Renderer emits generated timezone metadata.', $failures);
$locale_html=panel_field_catalog_render_control('locale', Field::make('locale')->locale());
panel_field_catalog_assert(str_contains($locale_html, 'placeholder="en-CA"') && str_contains($locale_html, 'data-dp-panel-format="locale"'), 'Renderer emits generated locale metadata.', $failures);
$json_html=panel_field_catalog_render_control('metadata', Field::make('metadata')->json());
panel_field_catalog_assert(str_contains($json_html, '<textarea') && str_contains($json_html, 'placeholder="{&quot;key&quot;:&quot;value&quot;}"') && str_contains($json_html, 'data-dp-panel-format="json"') && str_contains($json_html, 'data-dp-panel-auto-resize="1"'), 'Renderer emits generated JSON textarea metadata.', $failures);
$typed_json=Field::make('payload', 'json')->toArray();
panel_field_catalog_assert($typed_json['type']==='json' && panel_field_catalog_meta($typed_json, 'format_rule')==='json' && in_array('multiline', $typed_json['component']['capabilities'], true), 'json field type configures formatted multiline metadata.', $failures);
$date_range=Field::make('window')->dateRange('2026-01-01', '2026-12-31')->toArray();
panel_field_catalog_assert($date_range['type']==='date_range' && panel_field_catalog_meta($date_range, 'min')==='2026-01-01' && in_array('range_pair', $date_range['component']['capabilities'], true), 'dateRange() configures date range bounds and component capabilities.', $failures);
panel_field_catalog_assert(Field::make('window', 'date_range')->dehydrateValue(['start'=>'2026-05-01', 'end'=>'2026-05-31'])===['start'=>'2026-05-01', 'end'=>'2026-05-31'], 'date_range field type dehydrates paired values.', $failures);
$date_range_html=panel_field_catalog_render_control('window', Field::make('window')->dateRange('2026-01-01', '2026-12-31'), ['start'=>'2026-05-01', 'end'=>'2026-05-31']);
panel_field_catalog_assert(str_contains($date_range_html, 'data-dp-panel-range-pair="date"') && str_contains($date_range_html, 'name="window[start]"') && str_contains($date_range_html, 'name="window[end]"') && str_contains($date_range_html, 'min="2026-01-01"'), 'Renderer emits date range pair controls.', $failures);
$time_range_html=panel_field_catalog_render_control('service_window', Field::make('service_window')->timeRange('09:00', '17:00', '900'), ['start'=>'09:00', 'end'=>'12:00']);
panel_field_catalog_assert(str_contains($time_range_html, 'data-dp-panel-range-pair="time"') && str_contains($time_range_html, 'step="900"'), 'Renderer emits time range pair controls.', $failures);
$rating=Field::make('satisfaction')->rating(5)->toArray();
panel_field_catalog_assert($rating['type']==='rating' && panel_field_catalog_meta($rating, 'max')===5 && in_array('rating', $rating['component']['capabilities'], true), 'rating() configures rating bounds and component capabilities.', $failures);
panel_field_catalog_assert(Field::make('satisfaction')->rating(5)->dehydrateValue('4')===4, 'rating() dehydrates numeric values.', $failures);
$rating_html=panel_field_catalog_render_control('satisfaction', Field::make('satisfaction')->rating(5), 4);
panel_field_catalog_assert(str_contains($rating_html, 'data-dp-panel-rating="1"') && str_contains($rating_html, 'role="radiogroup"') && str_contains($rating_html, 'value="4" checked'), 'Renderer emits accessible rating radio controls.', $failures);
$markdown=Field::make('body')->markdown()->toArray();
panel_field_catalog_assert($markdown['type']==='markdown' && panel_field_catalog_meta($markdown, 'editor')==='markdown' && panel_field_catalog_meta($markdown, 'preview_mode')==='markdown', 'markdown() configures the Markdown editor.', $failures);
$html_editor=Field::make('body')->htmlEditor()->toArray();
panel_field_catalog_assert($html_editor['type']==='html' && panel_field_catalog_meta($html_editor, 'editor')==='html' && panel_field_catalog_meta($html_editor, 'preview_mode')==='html', 'htmlEditor() configures the HTML editor.', $failures);
$rich_text=Field::make('body')->richText()->toArray();
panel_field_catalog_assert($rich_text['type']==='rich_text' && panel_field_catalog_meta($rich_text, 'editor')==='rich_text' && in_array('editor', $rich_text['component']['capabilities'], true), 'richText() configures rich text editor capabilities.', $failures);
$rich_html=panel_field_catalog_render_control('body', Field::make('body')->richText()->placeholder('Write the product story'), '<p>Hello</p>');
panel_field_catalog_assert(str_contains($rich_html, 'data-dp-panel-editor="rich_text"') && str_contains($rich_html, 'data-dp-panel-editor-visual="1"') && str_contains($rich_html, 'data-dp-panel-editor-command="bold"') && str_contains($rich_html, 'data-dp-panel-editor-view="write"') && str_contains($rich_html, 'data-dp-panel-editor-view="preview"') && str_contains($rich_html, 'data-dp-panel-editor-source="1"'), 'Renderer emits rich editor shell, toolbar state metadata, write/preview switch, visual surface, and synced source textarea.', $failures);
panel_field_catalog_assert(str_contains($rich_html, 'data-dp-panel-editor-placeholder="Write the product story"') && str_contains($rich_html, 'data-dp-panel-editor-empty="1"'), 'Renderer mirrors rich editor placeholders to the visual editing surface.', $failures);
$rich_sanitized_html=panel_field_catalog_render_control('body', Field::make('body')->htmlEditor(), '<p>Safe</p><script>bad()</script><strong><p></p></strong><strong><p>Bold block</p></strong>');
panel_field_catalog_assert(str_contains($rich_sanitized_html, '<div hidden class="dp-panel-editor-preview dp-panel-editor-preview-html"><p>Safe</p><p><strong>Bold block</strong></p></div>') && !str_contains($rich_sanitized_html, '<strong><p></p></strong>') && !str_contains($rich_sanitized_html, '<strong><p>'), 'Renderer sanitizes rich editor previews and normalizes inline wrappers around blocks.', $failures);
$markdown_html=panel_field_catalog_render_control('body', Field::make('body')->markdown(), '**Hello**');
panel_field_catalog_assert(str_contains($markdown_html, 'data-dp-panel-editor="markdown"') && str_contains($markdown_html, 'dp-panel-editor-preview-markdown'), 'Renderer emits Markdown editor metadata and preview.', $failures);
$code_editor=Field::make('snippet')->codeEditor('php')->toArray();
panel_field_catalog_assert($code_editor['type']==='code' && panel_field_catalog_meta($code_editor, 'editor')==='code' && panel_field_catalog_meta($code_editor, 'preview_mode')==='code' && panel_field_catalog_meta($code_editor, 'code_language')==='php', 'codeEditor() configures the code editor and language metadata.', $failures);
panel_field_catalog_assert(in_array('monospace', $code_editor['component']['capabilities'], true) && in_array('syntax_language', $code_editor['component']['capabilities'], true) && in_array('editor', $code_editor['component']['capabilities'], true), 'codeEditor() exposes component capabilities.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('code')['input'] ?? null)==='textarea', 'Component registry exposes code editor textarea metadata.', $failures);
$code_html=panel_field_catalog_render_control('snippet', Field::make('snippet')->codeEditor('php'), '<?php echo 1;');
panel_field_catalog_assert(str_contains($code_html, 'data-dp-panel-editor="code"') && str_contains($code_html, 'data-dp-panel-code-editor="1"') && str_contains($code_html, 'data-dp-panel-code-language="php"') && str_contains($code_html, 'spellcheck="false"') && str_contains($code_html, 'dp-panel-editor-preview-code'), 'Renderer emits code editor language metadata and preview.', $failures);
$mime_type_html=panel_field_catalog_render_control('content_type', Field::make('content_type')->mimeType());
panel_field_catalog_assert(str_contains($mime_type_html, 'placeholder="application/json"') && str_contains($mime_type_html, 'data-dp-panel-format="mime_type"'), 'Renderer emits generated MIME type metadata.', $failures);
$semver_html=panel_field_catalog_render_control('version', Field::make('version')->semver());
panel_field_catalog_assert(str_contains($semver_html, 'placeholder="1.2.3"') && str_contains($semver_html, 'data-dp-panel-format="semver"'), 'Renderer emits generated semantic version metadata.', $failures);
$cron_html=panel_field_catalog_render_control('schedule', Field::make('schedule')->cronExpression());
panel_field_catalog_assert(str_contains($cron_html, 'placeholder="0 9 * * mon-fri"') && str_contains($cron_html, 'data-dp-panel-format="cron_expression"'), 'Renderer emits generated cron expression metadata.', $failures);
$language_code_html=panel_field_catalog_render_control('language', Field::make('language')->languageCode());
panel_field_catalog_assert(str_contains($language_code_html, 'placeholder="en"') && str_contains($language_code_html, 'pattern="[a-z]{2}"'), 'Renderer emits generated language code metadata.', $failures);
$country_code_html=panel_field_catalog_render_control('country', Field::make('country')->countryCode());
panel_field_catalog_assert(str_contains($country_code_html, 'placeholder="CA"') && str_contains($country_code_html, 'pattern="[A-Z]{2}"'), 'Renderer emits generated country code metadata.', $failures);
$subdivision_code_html=panel_field_catalog_render_control('region', Field::make('region')->subdivisionCodeForCountry('CA'));
panel_field_catalog_assert(str_contains($subdivision_code_html, 'placeholder="QC"') && str_contains($subdivision_code_html, 'pattern="[A-Z]{2,3}"') && str_contains($subdivision_code_html, '&quot;country&quot;:&quot;CA&quot;'), 'Renderer emits generated subdivision code metadata.', $failures);
$currency_code_html=panel_field_catalog_render_control('currency', Field::make('currency')->currencyCode());
panel_field_catalog_assert(str_contains($currency_code_html, 'placeholder="CAD"') && str_contains($currency_code_html, 'pattern="[A-Z]{3}"'), 'Renderer emits generated currency code metadata.', $failures);
$ip_html=panel_field_catalog_render_control('server_ip', Field::make('server_ip')->ipAddress());
panel_field_catalog_assert(str_contains($ip_html, 'placeholder="192.0.2.10"') && str_contains($ip_html, 'pattern="[0-9A-Fa-f:.]{3,45}"'), 'Renderer emits generated IP address validation metadata.', $failures);
$mac_html=panel_field_catalog_render_control('device_mac', Field::make('device_mac')->macAddress());
panel_field_catalog_assert(str_contains($mac_html, 'placeholder="00:1A:2B:3C:4D:5E"') && str_contains($mac_html, 'pattern="[0-9A-F]{2}(:[0-9A-F]{2}){5}"'), 'Renderer emits generated MAC address validation metadata.', $failures);
$uuid_html=panel_field_catalog_render_control('external_id', Field::make('external_id')->uuid());
panel_field_catalog_assert(str_contains($uuid_html, 'placeholder="550e8400-e29b-41d4-a716-446655440000"') && str_contains($uuid_html, 'pattern="[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}"'), 'Renderer emits generated UUID validation metadata.', $failures);
$ulid_html=panel_field_catalog_render_control('event_id', Field::make('event_id')->ulid());
panel_field_catalog_assert(str_contains($ulid_html, 'placeholder="01ARZ3NDEKTSV4RRFFQ69G5FAV"') && str_contains($ulid_html, 'pattern="[0-7][0-9A-HJKMNP-TV-Z]{25}"'), 'Renderer emits generated ULID validation metadata.', $failures);
$hex_color_html=panel_field_catalog_render_control('brand_color', Field::make('brand_color')->hexColor());
panel_field_catalog_assert(str_contains($hex_color_html, 'placeholder="#3366cc"') && str_contains($hex_color_html, 'pattern="#[0-9a-f]{6}"'), 'Renderer emits generated hex color validation metadata.', $failures);
panel_field_catalog_assert(str_contains($hex_color_html, 'data-dp-panel-color-swatch="1"'), 'Renderer emits hex color swatch adornments.', $failures);
$hex_color_no_swatch_html=panel_field_catalog_render_control('brand_color', Field::make('brand_color')->hexColor()->hideColorSwatch());
panel_field_catalog_assert(!str_contains($hex_color_no_swatch_html, 'data-dp-panel-color-swatch="1"'), 'Renderer can suppress hex color swatch adornments.', $failures);
$native_color_html=panel_field_catalog_render_control('brand_color', Field::make('brand_color')->color('#3366cc'), '#3366cc');
panel_field_catalog_assert(str_contains($native_color_html, 'type="color"') && str_contains($native_color_html, 'value="#3366cc"') && str_contains($native_color_html, 'data-dp-panel-color-swatch="1"'), 'Renderer emits native color input with swatch adornment.', $failures);
$latitude_html=panel_field_catalog_render_control('latitude', Field::make('latitude')->latitude());
panel_field_catalog_assert(str_contains($latitude_html, 'type="number"') && str_contains($latitude_html, 'min="-90"') && str_contains($latitude_html, 'max="90"') && str_contains($latitude_html, 'placeholder="45.501689"'), 'Renderer emits generated latitude metadata.', $failures);
$longitude_html=panel_field_catalog_render_control('longitude', Field::make('longitude')->longitude());
panel_field_catalog_assert(str_contains($longitude_html, 'type="number"') && str_contains($longitude_html, 'min="-180"') && str_contains($longitude_html, 'max="180"') && str_contains($longitude_html, 'placeholder="-73.567256"'), 'Renderer emits generated longitude metadata.', $failures);
$coordinates_html=panel_field_catalog_render_control('coordinates', Field::make('coordinates')->coordinates());
panel_field_catalog_assert(str_contains($coordinates_html, 'placeholder="45.501689,-73.567256"') && str_contains($coordinates_html, 'pattern="-?[0-9]{1,3}(\.[0-9]+)?,-?[0-9]{1,3}(\.[0-9]+)?"'), 'Renderer emits generated coordinate-pair metadata.', $failures);

$select_html=panel_field_catalog_render_control('status', Field::make('status', 'select')->options(['open'=>'Open'])->setButton('Use sample', 'sample-value'), 'open');
panel_field_catalog_assert(str_contains($select_html, 'data-dp-panel-input-shell="1"'), 'Renderer wraps select controls when side buttons are present.', $failures);
panel_field_catalog_assert(str_contains($select_html, 'data-dp-panel-field-button="set"'), 'Renderer emits side button action metadata.', $failures);
panel_field_catalog_assert(str_contains($select_html, 'data-dp-panel-field-button-value="sample-value"'), 'Renderer emits side button value metadata.', $failures);
$searchable_select=Field::make('assignee', 'select')->options(['noel'=>'Noel Dupui', 'iris'=>'Iris Patel'])->searchable()->searchPlaceholder('Find operator')->noResultsText('No operators found')->toArray();
panel_field_catalog_assert(panel_field_catalog_meta($searchable_select, 'searchable')===true && panel_field_catalog_meta($searchable_select, 'search_placeholder')==='Find operator' && in_array('searchable', $searchable_select['component']['capabilities'], true), 'searchable() configures select search metadata and capabilities.', $failures);
$searchable_select_html=panel_field_catalog_render_control('assignee', Field::make('assignee', 'select')->options(['noel'=>'Noel Dupui', 'iris'=>'Iris Patel'])->searchable()->searchPlaceholder('Find operator')->noResultsText('No operators found'), 'iris');
panel_field_catalog_assert(str_contains($searchable_select_html, 'data-dp-panel-searchable-select="1"') && str_contains($searchable_select_html, 'data-dp-panel-searchable-select-input') && str_contains($searchable_select_html, 'placeholder="Find operator"') && str_contains($searchable_select_html, 'data-dp-panel-select-no-results="No operators found"'), 'Renderer emits searchable select shell metadata.', $failures);
$searchable_multi_html=panel_field_catalog_render_control('operators', Field::make('operators')->multiSelect(['noel'=>'Noel Dupui', 'iris'=>'Iris Patel'])->searchable(), ['noel']);
panel_field_catalog_assert(str_contains($searchable_multi_html, 'multiple') && str_contains($searchable_multi_html, 'data-dp-panel-searchable-select-multiple="1"'), 'Renderer emits searchable multi-select metadata.', $failures);
$relationship=Field::make('owner_id')->relationship('users', ['1'=>'Mina', '2'=>'Noel'], 'name', 'id')->toArray();
panel_field_catalog_assert($relationship['type']==='relationship' && panel_field_catalog_meta($relationship, 'related_resource')==='users' && panel_field_catalog_meta($relationship, 'title_attribute')==='name' && panel_field_catalog_meta($relationship, 'key_attribute')==='id' && in_array('relationship', $relationship['component']['capabilities'], true) && in_array('related_resource', $relationship['component']['capabilities'], true), 'relationship() configures related resource picker metadata.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('relationship')['input'] ?? null)==='select' && in_array('relationship', \Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('relationship')['capabilities'] ?? [], true), 'Component registry exposes relationship field metadata.', $failures);
$relationship_html=panel_field_catalog_render_control('owner_id', Field::make('owner_id')->relationship('users', ['1'=>'Mina', '2'=>'Noel'], 'name', 'id'), '2');
panel_field_catalog_assert(str_contains($relationship_html, 'data-dp-panel-relationship="1"') && str_contains($relationship_html, 'data-dp-panel-relationship-related-resource="users"') && str_contains($relationship_html, 'data-dp-panel-relationship-title-attribute="name"') && str_contains($relationship_html, 'data-dp-panel-relationship-key-attribute="id"') && str_contains($relationship_html, 'data-dp-panel-searchable-select="1"'), 'Renderer emits relationship select metadata and searchable shell.', $failures);
panel_field_catalog_assert(Field::make('owner_id')->relationship('users', ['1'=>'Mina'])->validateValue('2')!==[], 'relationship() validates submitted values against available related options.', $failures);
$belongs_to=Field::make('seller_id')->belongsTo('sellers', ['10'=>'Seller One'])->toArray();
panel_field_catalog_assert($belongs_to['type']==='belongs_to' && panel_field_catalog_meta($belongs_to, 'related_resource')==='sellers', 'belongsTo() aliases relationship fields.', $failures);
$multi_relationship=Field::make('watcher_ids')->multiRelationship('users', ['1'=>'Mina', '2'=>'Noel'])->toArray();
panel_field_catalog_assert($multi_relationship['type']==='multi_relationship' && panel_field_catalog_meta($multi_relationship, 'multiple')===true && panel_field_catalog_meta($multi_relationship, 'related_resource')==='users' && in_array('multiple', $multi_relationship['component']['capabilities'], true) && in_array('relationship', $multi_relationship['component']['capabilities'], true), 'multiRelationship() configures multi related record picker metadata.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('multi_relationship')['input'] ?? null)==='select' && in_array('multiple', \Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('multi_relationship')['capabilities'] ?? [], true), 'Component registry exposes multi relationship field metadata.', $failures);
$multi_relationship_html=panel_field_catalog_render_control('watcher_ids', Field::make('watcher_ids')->multiRelationship('users', ['1'=>'Mina', '2'=>'Noel']), ['1']);
panel_field_catalog_assert(str_contains($multi_relationship_html, 'name="watcher_ids[]"') && str_contains($multi_relationship_html, 'multiple') && str_contains($multi_relationship_html, 'data-dp-panel-relationship="1"') && str_contains($multi_relationship_html, 'data-dp-panel-searchable-select-multiple="1"'), 'Renderer emits multi relationship select metadata.', $failures);
panel_field_catalog_assert(Field::make('watcher_ids')->multiRelationship('users', ['1'=>'Mina'])->dehydrateValue('["1","2"]')===['1', '2'], 'multiRelationship() dehydrates JSON submitted values to lists.', $failures);
panel_field_catalog_assert(Field::make('watcher_ids')->multiRelationship('users', ['1'=>'Mina'])->validateValue(['2'])!==[], 'multiRelationship() validates submitted related ids against available options.', $failures);
$belongs_to_many=Field::make('seller_ids')->belongsToMany('sellers', ['10'=>'Seller One'])->toArray();
panel_field_catalog_assert($belongs_to_many['type']==='belongs_to_many' && panel_field_catalog_meta($belongs_to_many, 'related_resource')==='sellers' && panel_field_catalog_meta($belongs_to_many, 'multiple')===true, 'belongsToMany() aliases multi relationship fields.', $failures);
$rich_options=Field::make('route', 'select')
	->optionGroup('Active lanes', [
		'standard'=>['label'=>'Standard', 'description'=>'Default fulfillment queue'],
		'express'=>['label'=>'Express', 'description'=>'Prioritized shipment'],
	])
	->optionGroup('Unavailable lanes', [
		'hold'=>['label'=>'Hold', 'description'=>'Requires supervisor approval', 'disabled'=>true],
	])
	->toArray();
panel_field_catalog_assert(in_array('option_groups', $rich_options['component']['capabilities'], true) && in_array('disabled_options', $rich_options['component']['capabilities'], true) && in_array('option_descriptions', $rich_options['component']['capabilities'], true), 'Rich select options expose group, disabled, and description capabilities.', $failures);
$rich_options_html=panel_field_catalog_render_control('route', Field::make('route', 'select')
	->optionGroup('Active lanes', [
		'standard'=>['label'=>'Standard', 'description'=>'Default fulfillment queue'],
		'express'=>['label'=>'Express', 'description'=>'Prioritized shipment'],
	])
	->optionGroup('Unavailable lanes', [
		'hold'=>['label'=>'Hold', 'description'=>'Requires supervisor approval', 'disabled'=>true],
	]), 'express');
panel_field_catalog_assert(str_contains($rich_options_html, '<optgroup label="Active lanes"') && str_contains($rich_options_html, 'title="Prioritized shipment"') && str_contains($rich_options_html, 'data-description="Requires supervisor approval"') && str_contains($rich_options_html, 'value="hold" disabled'), 'Renderer emits option groups, descriptions, and disabled options.', $failures);
panel_field_catalog_assert(Field::make('route', 'select')->disabledOption('hold', 'Hold')->validateValue('hold')!==[], 'Disabled select options are rejected by server-side option validation.', $failures);
$choice_description_html=panel_field_catalog_render_control('lane', Field::make('lane')->radio([
	'standard'=>['label'=>'Standard', 'description'=>'Default queue'],
	'hold'=>['label'=>'Hold', 'description'=>'Needs review', 'disabled'=>true],
]), 'standard');
panel_field_catalog_assert(str_contains($choice_description_html, '<small>Default queue</small>') && str_contains($choice_description_html, 'dp-panel-choice-disabled') && str_contains($choice_description_html, 'value="hold" disabled'), 'Choice card controls emit option descriptions and disabled states.', $failures);

$textarea_html=panel_field_catalog_render_control('notes', Field::make('notes', 'textarea')->nowButton());
panel_field_catalog_assert(str_contains($textarea_html, 'data-dp-panel-input-shell="1"'), 'Renderer wraps textareas when side buttons are present.', $failures);
panel_field_catalog_assert(str_contains($textarea_html, 'data-dp-panel-field-button="now"'), 'Renderer emits now side button metadata.', $failures);
$autosize_html=panel_field_catalog_render_control('notes', Field::make('notes', 'textarea')->rows(2)->autoResize());
panel_field_catalog_assert(str_contains($autosize_html, 'rows="2"') && str_contains($autosize_html, 'data-dp-panel-auto-resize="1"'), 'Renderer emits textarea auto-resize metadata.', $failures);

$stepper_html=panel_field_catalog_render_control('stock', Field::make('stock', 'number')->min(0)->max(20)->step(5)->stepperButtons(), 10);
panel_field_catalog_assert(str_contains($stepper_html, 'min="0"') && str_contains($stepper_html, 'max="20"') && str_contains($stepper_html, 'step="5"'), 'Renderer emits numeric bounds for steppers.', $failures);
panel_field_catalog_assert(str_contains($stepper_html, 'data-dp-panel-field-button="decrement"') && str_contains($stepper_html, 'data-dp-panel-field-button="increment"'), 'Renderer emits stepper side button metadata.', $failures);

$slider=Field::make('priority')->slider(1, 10, 0.5)->toArray();
panel_field_catalog_assert($slider['type']==='slider' && panel_field_catalog_meta($slider, 'min')===1 && panel_field_catalog_meta($slider, 'max')===10 && panel_field_catalog_meta($slider, 'step')===0.5, 'slider() configures slider bounds and step metadata.', $failures);
panel_field_catalog_assert(in_array('value_display', $slider['component']['capabilities'], true) && in_array('bounded', $slider['component']['capabilities'], true) && in_array('stepped', $slider['component']['capabilities'], true), 'slider() exposes component capabilities.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('slider')['input'] ?? null)==='range', 'Component registry exposes slider range input metadata.', $failures);
panel_field_catalog_assert(Field::make('priority')->slider(1, 10, 0.5)->dehydrateValue('4.5')===4.5, 'slider() dehydrates submitted numeric values.', $failures);
panel_field_catalog_assert(Field::make('priority')->slider(1, 10, 0.5)->validateValue('11')!==[], 'slider() validates max bounds.', $failures);
$slider_html=panel_field_catalog_render_control('priority', Field::make('priority')->slider(1, 10, 0.5), 4.5);
panel_field_catalog_assert(str_contains($slider_html, 'type="range"') && str_contains($slider_html, 'data-dp-panel-slider="1"') && str_contains($slider_html, 'data-dp-panel-slider-value="1"') && str_contains($slider_html, 'aria-label="Priority"'), 'Renderer emits accessible slider control and value display metadata.', $failures);
panel_field_catalog_assert(str_contains($slider_html, 'min="1"') && str_contains($slider_html, 'max="10"') && str_contains($slider_html, 'step="0.5"') && str_contains($slider_html, '>4.5</output>'), 'Renderer emits slider min/max/step and current value display.', $failures);
$tags=Field::make('labels')->tags(['new'=>'New', 'vip'=>'VIP'])->minTags(1)->maxTags(3)->toArray();
panel_field_catalog_assert($tags['type']==='tags' && panel_field_catalog_meta($tags, 'tag_separator')===',' && panel_field_catalog_meta($tags, 'min_tags')===1 && panel_field_catalog_meta($tags, 'max_tags')===3, 'tags() configures tag separator and count metadata.', $failures);
panel_field_catalog_assert(in_array('chips', $tags['component']['capabilities'], true) && in_array('suggestions', $tags['component']['capabilities'], true), 'tags() exposes chip and suggestion capabilities.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('tags')['input'] ?? null)==='text', 'Component registry exposes tags text input metadata.', $failures);
panel_field_catalog_assert(Field::make('labels')->tags()->dehydrateValue('alpha, beta, alpha')===['alpha', 'beta'], 'tags() dehydrates duplicate-separated values to unique tag arrays.', $failures);
panel_field_catalog_assert(Field::make('labels')->tags()->minTags(2)->validateValue('alpha')!==[], 'tags() validates minimum tag counts.', $failures);
$tags_html=panel_field_catalog_render_control('labels', Field::make('labels')->tags(['new'=>'New'])->placeholder('Add labels'), ['alpha', 'beta']);
panel_field_catalog_assert(str_contains($tags_html, 'data-dp-panel-tags="1"') && str_contains($tags_html, 'data-dp-panel-tag-separator=","') && str_contains($tags_html, 'data-dp-panel-tags-shell="1"') && str_contains($tags_html, 'data-dp-panel-tags-list'), 'Renderer emits tags input shell and chip list metadata.', $failures);
panel_field_catalog_assert(str_contains($tags_html, 'value="alpha, beta"') && str_contains($tags_html, '<datalist'), 'Renderer emits normalized tag input values and suggestions.', $failures);
$key_value=Field::make('attributes')->keyValue()->minPairs(1)->maxPairs(4)->toArray();
panel_field_catalog_assert($key_value['type']==='key_value' && panel_field_catalog_meta($key_value, 'key_separator')==='=' && panel_field_catalog_meta($key_value, 'min_pairs')===1 && panel_field_catalog_meta($key_value, 'max_pairs')===4, 'keyValue() configures pair separators and count metadata.', $failures);
panel_field_catalog_assert(in_array('key_value_pairs', $key_value['component']['capabilities'], true) && in_array('preview', $key_value['component']['capabilities'], true), 'keyValue() exposes pair preview capabilities.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('key_value')['input'] ?? null)==='textarea', 'Component registry exposes key-value textarea metadata.', $failures);
panel_field_catalog_assert(Field::make('attributes')->keyValue()->dehydrateValue("color=blue\nsize=large")===['color'=>'blue', 'size'=>'large'], 'keyValue() dehydrates text pairs to arrays.', $failures);
panel_field_catalog_assert(Field::make('attributes')->keyValue()->minPairs(2)->validateValue('color=blue')!==[], 'keyValue() validates minimum pair counts.', $failures);
$key_value_html=panel_field_catalog_render_control('attributes', Field::make('attributes')->keyValue()->placeholder('key=value'), ['color'=>'blue', 'size'=>'large']);
panel_field_catalog_assert(str_contains($key_value_html, 'data-dp-panel-key-value="1"') && str_contains($key_value_html, 'data-dp-panel-key-separator="="') && str_contains($key_value_html, 'data-dp-panel-key-value-shell="1"') && str_contains($key_value_html, 'data-dp-panel-key-value-preview'), 'Renderer emits key-value shell and preview metadata.', $failures);
panel_field_catalog_assert(str_contains($key_value_html, "color=blue\nsize=large") && str_contains($key_value_html, 'data-dp-panel-auto-resize="1"'), 'Renderer emits normalized key-value text and autoresize metadata.', $failures);
$radio=Field::make('status')->radio(['open'=>'Open', 'closed'=>'Closed'])->choiceColumns(2)->toArray();
panel_field_catalog_assert($radio['type']==='radio' && in_array('choice_cards', $radio['component']['capabilities'], true) && in_array('choice_columns', $radio['component']['capabilities'], true), 'radio() exposes choice card and column capabilities.', $failures);
$checkbox_list=Field::make('channels')->checkboxList(['mail'=>'Mail', 'sms'=>'SMS'])->inlineChoices()->toArray();
panel_field_catalog_assert($checkbox_list['type']==='checkbox_list' && panel_field_catalog_meta($checkbox_list, 'inline_choices')===true && in_array('choices', $checkbox_list['component']['capabilities'], true), 'checkboxList() exposes inline multi-choice capabilities.', $failures);
panel_field_catalog_assert(Field::make('channels')->checkboxList(['mail'=>'Mail'])->dehydrateValue(['mail'])===['mail'], 'checkboxList() dehydrates selected values as lists.', $failures);
panel_field_catalog_assert(Field::make('channels')->checkboxList(['mail'=>'Mail'])->validateValue(['fax'])!==[], 'checkboxList() validates submitted values against options.', $failures);
$radio_html=panel_field_catalog_render_control('status', Field::make('status')->radio(['open'=>'Open', 'closed'=>'Closed'])->choiceColumns(2), 'open');
panel_field_catalog_assert(str_contains($radio_html, 'role="radiogroup"') && str_contains($radio_html, 'data-dp-panel-choice-list="single"') && str_contains($radio_html, 'data-dp-panel-choice-columns="2"') && str_contains($radio_html, 'style="--dp-choice-columns:2"') && str_contains($radio_html, 'checked'), 'Renderer emits accessible radio choice card metadata.', $failures);
$checkbox_list_html=panel_field_catalog_render_control('channels', Field::make('channels')->checkboxList(['mail'=>'Mail', 'sms'=>'SMS'])->inlineChoices(), ['sms']);
panel_field_catalog_assert(str_contains($checkbox_list_html, 'role="group"') && str_contains($checkbox_list_html, 'data-dp-panel-choice-list="multiple"') && str_contains($checkbox_list_html, 'data-dp-panel-choice-inline="1"') && str_contains($checkbox_list_html, 'type="checkbox"'), 'Renderer emits accessible checkbox-list choice card metadata.', $failures);
$multi_select=Field::make('channels')->multiSelect(['mail'=>'Mail'])->toArray();
panel_field_catalog_assert($multi_select['type']==='multi_select' && ($multi_select['multiple'] ?? false)===true, 'multiSelect() configures multi-select fields.', $failures);
$toggle_buttons=Field::make('view')->toggleButtons(['cards'=>'Cards', 'table'=>'Table'])->choiceColumns(2)->toArray();
panel_field_catalog_assert($toggle_buttons['type']==='toggle_buttons' && in_array('segmented_buttons', $toggle_buttons['component']['capabilities'], true), 'toggleButtons() exposes segmented choice capabilities.', $failures);
$toggle_buttons_html=panel_field_catalog_render_control('view', Field::make('view')->toggleButtons(['cards'=>'Cards', 'table'=>'Table'])->choiceColumns(2), 'table');
panel_field_catalog_assert(str_contains($toggle_buttons_html, 'dp-panel-choice-list-buttons') && str_contains($toggle_buttons_html, 'data-dp-panel-choice-style="buttons"') && str_contains($toggle_buttons_html, 'type="radio"') && str_contains($toggle_buttons_html, 'checked'), 'Renderer emits segmented toggle button choices.', $failures);
$multi_toggle_buttons=Field::make('flags')->toggleButtons(['rush'=>'Rush', 'gift'=>'Gift'], true)->dehydrateValue(['gift']);
panel_field_catalog_assert($multi_toggle_buttons===['gift'], 'Multi toggleButtons() dehydrates selected values as lists.', $failures);
$toggle=Field::make('active')->toggle('Active', 'Paused')->toArray();
panel_field_catalog_assert($toggle['type']==='toggle' && panel_field_catalog_meta($toggle, 'on_label')==='Active' && panel_field_catalog_meta($toggle, 'off_label')==='Paused', 'toggle() configures boolean labels.', $failures);
panel_field_catalog_assert(in_array('switch', $toggle['component']['capabilities'], true) && in_array('boolean_labels', $toggle['component']['capabilities'], true), 'toggle() exposes switch capabilities.', $failures);
panel_field_catalog_assert((\Dataphyre\Panel\PanelComponentRegistry::fieldTypeDefinition('toggle')['input'] ?? null)==='checkbox', 'Component registry exposes toggle checkbox metadata.', $failures);
panel_field_catalog_assert(Field::make('active')->toggle()->dehydrateValue('1')===true && Field::make('active')->toggle()->dehydrateValue('0')===false, 'toggle() dehydrates truthy and falsey values.', $failures);
$toggle_html=panel_field_catalog_render_control('active', Field::make('active')->toggle('Active', 'Paused'), true);
panel_field_catalog_assert(str_contains($toggle_html, 'data-dp-panel-switch="1"') && str_contains($toggle_html, 'role="switch"') && str_contains($toggle_html, 'name="active" value="0"') && str_contains($toggle_html, 'name="active" value="1"') && str_contains($toggle_html, 'checked'), 'Renderer emits accessible toggle switch with hidden false value.', $failures);
panel_field_catalog_assert(str_contains($toggle_html, 'dp-panel-switch-track') && str_contains($toggle_html, '<strong>Active</strong>') && str_contains($toggle_html, '<small>Paused</small>'), 'Renderer emits toggle track and labels.', $failures);

$uploader_html=panel_field_catalog_render_control('receipt', Field::make('receipt', 'file_upload')->acceptedTypes(['image/*', '.pdf'])->maxFileSize(5242880)->storageUploader('local', 'panel_uploads/{date}/{filename}')->uploadChunkSize(1048576)->uploadRetries(4)->uploadConcurrency(3)->uploadMinFiles(1)->uploadMaxFiles(3)->uploadHeader('X-Panel-Test', 'yes')->uploadField('tenant_id', 'demo')->uploadLabels(['browse'=>'Select files', 'drop_title'=>'Drop attachments', 'accepted_wildcard'=>'{type} assets', 'status_empty'=>'Nothing queued.']));
panel_field_catalog_assert(str_contains($uploader_html, 'data-dp-panel-uploader="1"') && str_contains($uploader_html, 'data-dp-panel-uploader-endpoint="/dataphyre/panel/upload"') && str_contains($uploader_html, 'data-dp-panel-uploader-total'), 'Renderer emits custom uploader shell metadata.', $failures);
panel_field_catalog_assert(str_contains($uploader_html, 'data-dp-panel-uploader-delete-endpoint="/dataphyre/panel/upload"'), 'Renderer emits uploader delete endpoint metadata.', $failures);
panel_field_catalog_assert(str_contains($uploader_html, 'data-dp-panel-uploader-chunk-size="1048576"') && str_contains($uploader_html, 'data-dp-panel-uploader-retries="4"') && str_contains($uploader_html, 'data-dp-panel-uploader-concurrency="3"'), 'Renderer emits uploader chunk, retry, and concurrency metadata.', $failures);
panel_field_catalog_assert(str_contains($uploader_html, 'data-dp-panel-uploader-min-files="1"') && str_contains($uploader_html, 'data-dp-panel-uploader-max-files="3"'), 'Renderer emits uploader count constraints.', $failures);
panel_field_catalog_assert(str_contains($uploader_html, 'data-dp-panel-uploader-headers=') && str_contains($uploader_html, '&quot;X-Panel-Test&quot;:&quot;yes&quot;') && str_contains($uploader_html, 'data-dp-panel-uploader-fields=') && str_contains($uploader_html, '&quot;tenant_id&quot;:&quot;demo&quot;'), 'Renderer emits uploader request metadata.', $failures);
panel_field_catalog_assert(str_contains($uploader_html, '&quot;driver&quot;:&quot;dataphyre_storage&quot;') && str_contains($uploader_html, '&quot;disk&quot;:&quot;local&quot;') && str_contains($uploader_html, '&quot;path&quot;:&quot;panel_uploads/{date}/{filename}&quot;'), 'Renderer emits Dataphyre Storage uploader metadata.', $failures);
panel_field_catalog_assert(str_contains($uploader_html, 'Select files') && str_contains($uploader_html, 'Drop attachments') && str_contains($uploader_html, 'Nothing queued.') && str_contains($uploader_html, 'Image assets') && str_contains($uploader_html, 'data-dp-panel-uploader-i18n=') && str_contains($uploader_html, '&quot;status_empty&quot;:&quot;Nothing queued.&quot;'), 'Renderer emits configurable uploader labels for localization.', $failures);
$uploader_value=Field::make('receipt', 'file_upload')->storageUploader()->dehydrateValue('{"path":"panel_uploads/test.txt","disk":"local"}');
panel_field_catalog_assert(is_array($uploader_value) && ($uploader_value['path'] ?? '')==='panel_uploads/test.txt', 'Custom uploader JSON submit payloads hydrate to arrays.', $failures);
$uploader_existing_html=panel_field_catalog_render_control('receipt', Field::make('receipt', 'file_upload')->storageUploader(), ['path'=>'panel_uploads/test.txt', 'filename'=>'test.txt', 'disk'=>'local']);
panel_field_catalog_assert(str_contains($uploader_existing_html, 'name="receipt"') && str_contains($uploader_existing_html, '&quot;path&quot;:&quot;panel_uploads/test.txt&quot;'), 'Renderer seeds custom uploader hidden values for edit forms.', $failures);

$script=PanelRenderer::assetContent('panel.js')['content'] ?? '';
foreach(['dpPanelLocalization', 'dpPanelText(', 'data-dp-panel-localization', 'zip_code_us', 'social_security_number', 'tax_id', 'email', 'url', 'map_url', 'domain', 'timezone', 'locale', 'json', 'mime_type', 'semver', 'cron_expression', 'language_code', 'country_code', 'subdivision_code', 'currency_code', 'ip_address', 'ipv4', 'ipv6', 'mac_address', 'uuid', 'ulid', 'hex_color', 'latitude', 'longitude', 'coordinates', 'lng_lat', 'dpPanelValidEmail', 'dpPanelNormalizeUrl', 'dpPanelValidUrl', 'dpPanelNormalizeMapUrl', 'dpPanelValidMapUrl', 'dpPanelNormalizeDomain', 'dpPanelValidDomain', 'dpPanelNormalizeTimezone', 'dpPanelValidTimezone', 'dpPanelTimezoneCanonicalMap', 'dpPanelNormalizeLocale', 'dpPanelValidLocale', 'dpPanelNormalizeJson', 'dpPanelFormatJson', 'dpPanelValidJson', 'dpPanelNormalizeMimeType', 'dpPanelValidMimeType', 'dpPanelNormalizeSemver', 'dpPanelValidSemver', 'dpPanelNormalizeCronExpression', 'dpPanelValidCronExpression', 'dpPanelValidCronField', 'dpPanelNormalizeLanguageCode', 'dpPanelValidLanguageCode', 'dpPanelKnownLanguageCodes', 'dpPanelNormalizeCountryCode', 'dpPanelValidCountryCode', 'dpPanelKnownCountryCodes', 'dpPanelValidSubdivisionCode', 'dpPanelKnownSubdivisionCodes', 'dpPanelNormalizeCurrencyCode', 'dpPanelValidCurrencyCode', 'dpPanelKnownCurrencyCodes', 'dpPanelValidIpAddress', 'dpPanelValidIpv4', 'dpPanelValidIpv6', 'dpPanelNormalizeMacAddress', 'dpPanelValidMacAddress', 'dpPanelNormalizeUuid', 'dpPanelValidUuid', 'dpPanelNormalizeUlid', 'dpPanelValidUlid', 'dpPanelNormalizeHexColor', 'dpPanelValidHexColor', 'dpPanelFormatCoordinate', 'dpPanelFormatCoordinatePair', 'dpPanelValidCoordinate', 'dpPanelValidCoordinatePair', 'dpPanelRefreshColorSwatch', 'data-dp-panel-color-swatch', 'dpPanelRefreshSliderValue', 'data-dp-panel-slider', 'data-dp-panel-slider-value', 'dpPanelInitSearchableSelects', 'dpPanelRefreshSearchableSelect', 'data-dp-panel-searchable-select-input', 'dpPanelRefreshTags', 'dpPanelNormalizeTagsInput', 'data-dp-panel-tags-list', 'dpPanelRefreshKeyValue', 'dpPanelNormalizeKeyValueInput', 'data-dp-panel-key-value-preview', 'source_field', 'dpPanelRefreshFormatFromSource', 'dpPanelPrimeFormatFromSource', 'dpPanelGeneratedValue', 'sentence_case', 'snake_case', 'kebab_case', 'camel_case', 'action==="title"', 'action==="today"', 'getTimezoneOffset', 'action==="now"', 'dpPanelNumericStep', 'action==="increment"', 'action==="decrement"', 'input:not([type=\'hidden\']),textarea,select', 'dpPanelNormalizeFormData', 'data-dp-panel-submit-normalized', 'dpPanelFieldButtonCopy==="normalized"', 'dpPanelApplyPastedValue', 'addEventListener("paste"', 'dpPanelEditorHandleShortcut', 'dpPanelEditorHandleCodeKeydown', 'event.key!=="Tab"', 'dpPanelEditorHandlePaste', 'insertHTML', 'insertText', 'dpPanelSanitizeRichHtml(html)', 'dpPanelCleanRichHtmlFragment', 'dpPanelEditorApplyVisualCommand', 'dpPanelEditorApplySourceCommand', 'dpPanelSetEditorMode', 'toggle.setAttribute("aria-pressed"', 'dpPanelRefreshPatternValidity', 'dpPanelRefreshCharacterCounter', 'data-dp-panel-character-counter', 'dpPanelAutoResizeTextarea', 'data-dp-panel-auto-resize', 'dpPanelApplyAccessibilityPolicies', 'dpPanelApplyFieldAccessibilityPolicy', 'dpPanelRefreshAccessibilityPolicySummary', 'DataphyrePanelAccessibilityPolicy', 'dpPanelA11yChecked', 'dpPanelA11yConstrained', 'dpPanelA11yContrastFailures', 'dpPanelContrastRatio', 'dpPanelA11yPolicyTarget', 'dpPanelA11yInheritedValue', 'data-dp-panel-a11y-policy', 'data-dp-panel-a11y-default', 'dpPanelA11yDisabled', 'dpPanelFormattedSemanticValidityMessage', 'dpPanelValidCreditCardNumber', 'dpPanelValidCreditCardExpiry', 'dpPanelValidIban', 'credit_card_expiry', 'card_cvc', 'addEventListener("invalid"', 'dpPanelEffectiveFormatRule', 'dpPanelFormatTitle', 'Expected international phone number with country code.', 'country_field', 'subdivision_field', 'dpPanelCanadianPostalPrefixPattern', 'dpPanelUsZipPrefixPattern', 'dpPanelAustralianPostcodePrefixPattern', 'dpPanelNewZealandPostcodePrefixPattern', 'dpPanelInternationalPhoneCode', 'dpPanelNormalizeInternationalPhoneValue', 'phone_prefix', 'postal_code_international', 'phone_international', 'local.charAt(0)==="0"', 'postal_code_gb', 'uk_postcode', 'SW1A 1AA', 'GB:"44"', 'postal_code_au', 'australian_postcode', 'AU:"61"', 'postal_code_nz', 'new_zealand_postcode', 'NZ:"64"', 'DE:"49"', 'postal_code_de', 'german_postcode', 'postal_code_nl', 'dutch_postcode', 'postal_code_ie', 'eircode', 'postal_code_fr', 'french_postcode', '1012 AB', 'D02 X285', 'dpPanelScopedFieldName', 'dpPanelFieldSourceMatches', 'dpPanelInitFieldEnhancements(row)', 'dpPanelRefreshLocaleFormatsForSource(source)', 'dpPanelInitRepeaters', 'dpPanelRefreshRepeater', 'dpPanelRepeaterMax', 'dpPanelRepeaterMin', 'data-dp-panel-repeater-add', 'data-dp-panel-builder-add', 'data-dp-panel-builder-template', 'dpPanelInitUploaders', 'dpPanelUploaderUploadChunk', 'dpPanelUploaderRequest', 'dpPanelUploaderRefreshSummary', 'data-dp-panel-uploader-total', 'dpPanelUploaderConstraintMessage', 'dpPanelUploaderMinFiles', 'dpPanelUploaderMaxFiles', 'dpPanelUploaderApplyRequestMetadata', 'dpPanelUploaderDeleteRequest', 'DataphyrePanelUploaderDelete', 'dp_panel_upload_delete', 'dpPanelUploaderHeaders', 'dpPanelUploaderFields', 'setRequestHeader(name', 'form.append(key', '_dpPanelUploaderItems', 'dpPanelUploaderRowItems', '_dpPanelUploaderState', 'complete++', 'dpPanelUploaderRemoveStoredItem', 'dpPanelUploaderMoveStoredItem', 'data-dp-panel-uploader-move', 'dpPanelUploaderExistingState', 'state.stored', 'Stored file', 'dpPanelUploaderRemove', 'URL.createObjectURL', 'Upload cancelled.', 'data-dp-panel-uploader', 'dpPanelUploaderChunkSize', 'DataphyrePanelUploader', 'Wait for uploads to finish before saving'] as $needle){
	panel_field_catalog_assert(str_contains($script, $needle), 'Panel JS bundle contains field runtime support for '.$needle.'.', $failures);
}
foreach(['dpPanelA11yTouchTargetFailures', 'dpPanelA11yMinTouchTarget', 'touch_target_failures', 'dpPanelA11yPolicyTargetKind', 'dpPanelA11yUsableTarget', 'dpPanelA11yMaxAdornmentRatio', 'dpPanelA11yMaxLabelRatio', 'dpPanelA11yLabelExpanded', 'dpPanelA11yLabelStacked', 'dpPanelA11yApplyLabelStack', 'dpPanelA11yResetAdaptiveField', 'dpPanelScheduleAccessibilityPolicyRefresh', 'dpPanelObserveAccessibilityPolicies', 'dpPanelScheduleFieldMutationEnhancements', 'dpPanelObserveFieldMutations', 'MutationObserver', 'rootNode.matches', 'ResizeObserver', 'dpPanelA11yAdornmentPressure', 'dpPanelA11yAdornmentExpanded', 'dpPanelA11yAdornmentStacked', 'dpPanelA11yAdornmentPressure(field,target)', 'dpPanelA11yLabelPressure(field)', 'dpPanelA11yMeasureTextWidth', 'dpPanelA11yCharacterWidth', 'dpPanelA11yControlPadding', 'dpPanelA11yRequiredWidthSource', 'dpPanelA11yCharacterWidth', 'dpPanelA11yControlPadding', 'required_width_source', 'character_width', 'control_padding', 'configured character policy', 'dpPanelA11yStatusId', 'dpPanelA11yDescribedControls', 'dpPanelA11yUpdateFieldStatus', 'data-dp-panel-a11y-status-message', 'aria-describedby', 'aria-live', 'dpPanelA11yFieldName', 'dpPanelA11yFieldSummary', 'dpPanelA11yTokenMessage', 'dpPanelA11yTokenMessages', '_dpPanelA11ySummary', 'dpPanelA11yIssues', 'dpPanelA11yActions', 'dpPanelA11yIssueMessages', 'dpPanelA11yActionMessages', 'dpPanelA11yIssueCount', 'dpPanelA11yActionCount', 'dpPanelA11yAdjustmentCount', 'width_constrained', 'contrast_fail', 'touch_target_fail', 'adornment_pressure', 'label_stacked', 'Usable width ', 'Field expanded to satisfy usable width policy.', 'Label stacked to preserve usable control width.', 'dp-panel-a11y-adornment-stacked', 'adornmentWidth+=rect.width', 'adornment_pressure', 'adornment_expanded', 'adornment_stacked', 'label_expanded', 'label_stacked'] as $needle){
	panel_field_catalog_assert(str_contains($script, $needle), 'Panel JS bundle contains touch target accessibility policy support for '.$needle.'.', $failures);
}
foreach(['dpPanelEditorRefreshCommandState', 'queryCommandState', 'selectionchange'] as $needle){
	panel_field_catalog_assert(str_contains($script, $needle), 'Panel JS bundle contains rich editor command state support for '.$needle.'.', $failures);
}
$component_css=PanelRenderer::assetContent('panel.css')['content'] ?? '';
$flat_minima_preset=PanelThemePreset::flatMinima()->toArray();
panel_field_catalog_assert(($flat_minima_preset['tokens']['theme_effects'] ?? null)==='flat_minima', 'Flat Minima preset registers its theme effect.', $failures);
panel_field_catalog_assert(PanelTheme::themeLibrary()->has('flat_minima'), 'Theme library registers Flat Minima as a built-in preset.', $failures);
panel_field_catalog_assert(!PanelTheme::themeLibrary()->has('dataphyre'), 'Theme library no longer registers a Dataphyre-named theme preset artifact.', $failures);
panel_field_catalog_assert((PanelTheme::presetDefinition('default')->toArray()['name'] ?? null)==='flat_minima', 'Default theme preset resolves to Flat Minima.', $failures);
panel_field_catalog_assert(str_contains($component_css, 'body[data-dp-theme-effects~="flat_minima"]'), 'Panel component CSS contains Flat Minima theme effect support.', $failures);
foreach(['dpPanelEditorRefreshEmptyState', 'dpPanelEditorVisualHasContent', 'data-dp-panel-editor-placeholder'] as $needle){
	panel_field_catalog_assert(str_contains($script.$component_css, $needle), 'Panel assets contain rich editor empty placeholder support for '.$needle.'.', $failures);
}
foreach(['dp-panel-field-label', 'dp-panel-field-hint', 'dp-panel-field-hint-icon', 'dp-panel-input-icon', 'dp-panel-a11y-expanded', 'dp-panel-a11y-constrained', 'dp-panel-a11y-contrast-fail', 'dp-panel-input-color-swatch', 'dp-panel-input-color-swatch-wrap', 'dp-panel-slider', 'dp-panel-slider-value', 'dp-panel-slider-bounds', 'dp-panel-range-pair', 'dp-panel-rating', 'dp-panel-rating-option', 'dp-panel-field-display', 'dp-panel-display-field', 'dp-panel-fieldset', 'dp-panel-fieldset-grid', 'dp-panel-builder', 'dp-panel-builder-row', 'dp-panel-builder-actions', 'dp-panel-searchable-select', 'dp-panel-searchable-select-search', 'dp-panel-searchable-select-status', 'dp-panel-tags-input', 'dp-panel-tag-chip', 'dp-panel-key-value', 'dp-panel-key-value-chip', 'dp-panel-choice-list', 'dp-panel-choice-list-inline', 'dp-panel-choice:has(input:checked)', 'dp-panel-switch', 'dp-panel-switch-track', 'dp-panel-switch:has(input:checked)', 'dp-panel-code-editor-control', 'dp-panel-editor-preview-code:before', 'dp-panel-uploader-drop', 'dp-panel-uploader-progress', 'dp-panel-uploader-total', 'dp-panel-uploader-retry', 'dp-panel-uploader-remove', 'dp-panel-uploader-preview', 'dp-panel-uploader-move'] as $needle){
	panel_field_catalog_assert(str_contains($component_css, $needle), 'Panel component CSS contains color swatch support for '.$needle.'.', $failures);
}
panel_field_catalog_assert(str_contains($component_css, 'dp-panel-a11y-touch-fail'), 'Panel component CSS contains touch target accessibility policy support.', $failures);
panel_field_catalog_assert(str_contains($component_css, 'dp-panel-a11y-adornment-pressure'), 'Panel component CSS contains adornment pressure accessibility policy support.', $failures);
panel_field_catalog_assert(str_contains($component_css, 'dp-panel-a11y-adornment-expanded'), 'Panel component CSS contains adornment expansion accessibility policy support.', $failures);
panel_field_catalog_assert(str_contains($component_css, 'dp-panel-a11y-adornment-stacked'), 'Panel component CSS contains adornment stacking accessibility policy support.', $failures);
panel_field_catalog_assert(str_contains($component_css, 'dp-panel-a11y-label-stacked'), 'Panel component CSS contains label stacking accessibility policy support.', $failures);
panel_field_catalog_assert(str_contains($component_css, 'dp-panel-a11y-label-expanded'), 'Panel component CSS contains label expansion accessibility policy support.', $failures);
$field_source=(string)file_get_contents(dirname(__DIR__).'/Framework/Forms/Field.php');
foreach(['sourceField(', 'fromField(', 'mapUrl(', 'mapsUrl(', 'timezone(', 'locale(', 'json(', 'jsonText(', 'date(', 'dateTime(', 'dateTimeLocal(', 'dateRange(', 'dateTimeRange(', 'time(', 'timeRange(', 'number(', 'integer(', 'float(', 'decimal(', 'numeric(', 'minValue(', 'maxValue(', 'stepValue(', 'text(', 'textInput(', 'textarea(', 'longText(', 'file(', 'fileUpload(', 'upload(', 'imageUpload(', 'money(', 'currency(', 'percent(', 'percentage(', 'password(', 'currentPassword(', 'newPassword(', 'passwordConfirmation(', 'revealable(', 'passwordReveal(', 'hiddenField(', 'hiddenInput(', 'hiddenValue(', 'placeholderField(', 'displayOnly(', 'viewField(', 'content(', 'htmlContent(', 'enum(', 'range(', 'rangeInput(', 'rating(', 'repeater(', 'builder(', 'builderBlocks(', 'builderBlock(', 'fieldset(', 'fieldGroup(', 'group(', 'childFields(', 'groupField(', 'helperText(', 'hint(', 'hintIcon(', 'accessibilityPolicy(', 'minUsableWidth(', 'minUsableCharacters(', 'contrastPolicy(', 'inheritAccessibilityPolicy(', 'withoutAccessibilityPolicy(', 'noAccessibilityPolicy(', 'disabled(', 'disable(', 'dehydrated(', 'dehydrate(', 'nullable(', 'regex(', 'confirmed(', 'same(', 'different(', 'startsWith(', 'endsWith(', 'requiredIf(', 'prefixIcon(', 'suffixIcon(', 'prependIcon(', 'appendIcon(', 'length(', 'exactLength(', 'lengthBetween(', 'betweenLength(', 'minDate(', 'maxDate(', 'minDateTime(', 'maxDateTime(', 'minTime(', 'maxTime(', 'codeEditor(', 'codeLanguage(', 'tags(', 'tagsInput(', 'minTags(', 'maxTags(', 'keyValue(', 'keyValuePairs(', 'minPairs(', 'maxPairs(', 'select(', 'multiSelect(', 'radio(', 'checkboxList(', 'choiceColumns(', 'inlineChoices(', 'stackedChoices(', 'boolean(', 'toggle(', 'checkbox(', 'booleanLabels(', 'onLabel(', 'offLabel(', 'color(', 'mimeType(', 'contentType(', 'semver(', 'semanticVersion(', 'cronExpression(', 'cron(', 'languageCode(', 'isoLanguage(', 'countryCode(', 'isoCountry(', 'subdivisionCode(', 'regionCode(', 'subdivisionCodeForCountry(', 'subdivisionCodeCountryField(', 'currencyCode(', 'isoCurrency(', 'ulid(', 'customUploader(', 'uploadEndpoint(', 'uploadDeleteEndpoint(', 'deleteEndpoint(', 'uploadChunkSize(', 'uploadRetries(', 'uploadConcurrency(', 'uploadHeaders(', 'uploadHeader(', 'uploadFields(', 'uploadField(', 'uploadLabels(', 'uploadLabel(', 'uploadCsrf(', 'uploadMinFiles(', 'uploadMaxFiles(', 'minFiles(', 'maxFiles(', 'storageUploader(', 'dataphyreStorageUpload(', 'colorSwatch(', 'hideColorSwatch(', 'slider(', 'rangeSlider(', 'sliderValueDisplay(', 'showSliderValue(', 'hideSliderValue(', 'latitude(', 'longitude(', 'coordinates(', 'latLng(', 'lngLat(', 'geopositionPostalCodeValid', 'geopositionReformatPostalCode', 'geopositionSubdivisions', '\\dataphyre\\geoposition::validate_postal_code', '\\dataphyre\\geoposition::reformat_postal_code'] as $needle){
	panel_field_catalog_assert(str_contains($field_source, $needle), 'Field server runtime contains optional geoposition postal support for '.$needle.'.', $failures);
}
panel_field_catalog_assert(str_contains($field_source, 'minTouchTarget('), 'Field server runtime contains touch target accessibility policy support.', $failures);
panel_field_catalog_assert(str_contains($field_source, 'maxAdornmentRatio('), 'Field server runtime contains adornment pressure accessibility policy support.', $failures);
panel_field_catalog_assert(str_contains($field_source, 'maxLabelRatio('), 'Field server runtime contains label pressure accessibility policy support.', $failures);
$form_source=(string)file_get_contents(dirname(__DIR__).'/Framework/Resources/ResourceForm.php');
$section_source=(string)file_get_contents(dirname(__DIR__).'/Framework/Forms/FormSection.php');
foreach(['accessibilityPolicy(', 'minUsableWidth(', 'minUsableCharacters(', 'contrastPolicy('] as $needle){
	panel_field_catalog_assert(str_contains($form_source, $needle) && str_contains($section_source, $needle), 'Form and section runtime contain accessibility default support for '.$needle.'.', $failures);
}
panel_field_catalog_assert(str_contains($form_source, 'minTouchTarget(') && str_contains($section_source, 'minTouchTarget('), 'Form and section runtime contain touch target accessibility default support.', $failures);
panel_field_catalog_assert(str_contains($form_source, 'maxAdornmentRatio(') && str_contains($section_source, 'maxAdornmentRatio('), 'Form and section runtime contain adornment pressure accessibility default support.', $failures);
panel_field_catalog_assert(str_contains($form_source, 'maxLabelRatio(') && str_contains($section_source, 'maxLabelRatio('), 'Form and section runtime contain label pressure accessibility default support.', $failures);

if($failures!==[]){
	fwrite(STDERR, "Panel field catalog check failed:\n");
	foreach($failures as $failure){
		fwrite(STDERR, ' - '.$failure."\n");
	}
	exit(1);
}

fwrite(STDOUT, "Panel field catalog check passed.\n");
