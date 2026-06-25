<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Renders record detail sections for Panel resource pages.
 *
 * Record sections include relation managers and configured detail groups after
 * authorization, visibility, and escaping have been applied.
 */
trait PanelRendererRecordSections {
	/**
	 * Formats a localized record-section count label for headers.
	 *
	 * keeps count grammar inside the renderer so sections can expose
	 * stable text keys while escaping translated labels before they enter record
	 * detail chrome.
	 */
	private static function recordCountLabel(int $count, string $singularKey, string $pluralKey): string {
		return $count.' '.self::e(self::panelText($count===1 ? $singularKey : $pluralKey));
	}

	/**
	 * Renders visible relation managers for a record detail page.
	 *
	 * relation managers are nested resource surfaces. Each manager is
	 * independently authorized against the active record, current user, and parent
	 * resource before relation-table markup is appended.
	 */
	private static function relationsHtml(Resource $resource, PanelRequest $request, mixed $record=null): string {
		$html='';
		foreach($resource->relationManagers() as $relation){
			if($relation->can('view', $record, $request->user(), $resource)===false){
				continue;
			}
			$html.=self::relationTableHtml($resource, $relation, $request, $record);
		}
		return $html;
	}

	/**
	 * Renders alert cards for a record when alert data is available.
	 *
	 * this read-only attention surface checks record capability, normalizes
	 * loose alert payloads, escapes display fields, sanitizes action URLs, and
	 * records a Flightdeck trace containing the rendered item count.
	 */
	private static function alertsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasAlerts() || $resource->can('alert', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeAlertItems($resource->recordAlerts($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'warning'));
			$title=self::e((string)($item['title'] ?? self::panelText('record.needs_attention')));
			$message=trim((string)($item['message'] ?? ''));
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$action=trim((string)($item['action'] ?? self::panelText('record.open')));
			$meta='';
			foreach($item['meta'] as $detail){
				$detail=trim(self::stringValue($detail));
				if($detail!==''){
					$meta.='<span>'.self::e($detail).'</span>';
				}
			}
			$html.='<article class="dp-panel-alert-card dp-panel-alert-'.$tone.'">'
				.'<div><strong>'.$title.'</strong>'.($message!=='' ? '<p>'.self::e($message).'</p>' : '').($meta!=='' ? '<small>'.$meta.'</small>' : '').'</div>'
				.($url!=='' ? '<a class="dp-panel-action dp-panel-action-'.$tone.'" href="'.self::e($url).'">'.self::e($action!=='' ? $action : self::panelText('record.open')).'</a>' : '')
				.'</article>';
		}
		PanelTrace::record('record.alerts_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-alerts">'.$html.'</section>';
	}

	/**
	 * Normalizes alert payloads into title, message, tone, URL, action, and meta.
	 *
	 * scalar shorthand and associative arrays are accepted from resource
	 * callbacks. Empty alerts are discarded, meta is always list-shaped, and URLs
	 * are constrained through the widget URL sanitizer before rendering.
	 */
	private static function normalizeAlertItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item) || is_numeric($item)){
				$item=[
					'title'=>is_string($key) ? $key : self::panelText('record.needs_attention'),
					'message'=>(string)$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$title=trim((string)($item['title'] ?? $item['label'] ?? $item['name'] ?? (is_string($key) ? $key : self::panelText('record.needs_attention'))));
			$message=trim((string)($item['message'] ?? $item['description'] ?? $item['detail'] ?? $item['body'] ?? ''));
			if($title==='' && $message===''){
				continue;
			}
			$meta=$item['meta'] ?? [];
			if(!is_array($meta)){
				$meta=[$meta];
			}
			$normalized[]=[
				'title'=>$title!=='' ? $title : self::panelText('record.needs_attention'),
				'message'=>$message,
				'tone'=>(string)($item['tone'] ?? $item['status'] ?? 'warning'),
				'url'=>self::safeWidgetUrl((string)($item['url'] ?? $item['href'] ?? $item['to'] ?? '')),
				'action'=>trim((string)($item['action'] ?? $item['action_label'] ?? $item['button'] ?? self::panelText('record.open'))),
				'meta'=>$meta,
			];
		}
		return $normalized;
	}

	/**
	 * Renders compact insight cards for metrics or facts attached to a record.
	 *
	 * insight data is display-only; capability checks happen before
	 * normalization, values are stringified for mixed sources, URLs are sanitized,
	 * and the render trace reports how many insight cards were emitted.
	 */
	private static function insightsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasInsights() || $resource->can('insight', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeInsightItems($resource->recordInsights($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$label=self::e((string)($item['label'] ?? self::panelText('record.insight')));
			$value=self::e(self::stringValue($item['value'] ?? ''));
			$description=trim((string)($item['description'] ?? ''));
			$icon=trim((string)($item['icon'] ?? ''));
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$body=($icon!=='' ? '<span class="dp-panel-insight-icon">'.self::e($icon).'</span>' : '')
				.'<span class="dp-panel-insight-label">'.$label.'</span>'
				.'<strong>'.$value.'</strong>'
				.($description!=='' ? '<small>'.self::e($description).'</small>' : '');
			$html.=$url!==''
				? '<a class="dp-panel-insight dp-panel-insight-'.$tone.'" href="'.self::e($url).'">'.$body.'</a>'
				: '<article class="dp-panel-insight dp-panel-insight-'.$tone.'">'.$body.'</article>';
		}
		PanelTrace::record('record.insights_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-insights">'.$html.'</section>';
	}

	/**
	 * Normalizes insight definitions into label, value, description, tone, icon, and URL.
	 *
	 * scalar entries become metric values with the array key as label when
	 * available. The returned shape is renderer-native while allowing resource
	 * callbacks to use aliases such as title, detail, metric, count, or help.
	 */
	private static function normalizeInsightItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item) || is_numeric($item) || is_bool($item)){
				$item=[
					'label'=>is_string($key) ? $key : self::panelText('record.insight'),
					'value'=>$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$label=trim((string)($item['label'] ?? $item['title'] ?? (is_string($key) ? $key : self::panelText('record.insight'))));
			$value=$item['value'] ?? $item['metric'] ?? $item['count'] ?? '';
			$normalized[]=[
				'label'=>$label!=='' ? $label : self::panelText('record.insight'),
				'value'=>$value,
				'description'=>trim((string)($item['description'] ?? $item['detail'] ?? $item['help'] ?? '')),
				'tone'=>(string)($item['tone'] ?? $item['status'] ?? 'neutral'),
				'icon'=>trim((string)($item['icon'] ?? '')),
				'url'=>self::safeWidgetUrl((string)($item['url'] ?? $item['href'] ?? '')),
			];
		}
		return $normalized;
	}

	/**
	 * Renders external or internal reference links for the record.
	 *
	 * link cards are emitted only after the resource link ability allows
	 * them. URLs are pre-sanitized by the normalizer, display fields are escaped,
	 * and HTTP destinations receive opener isolation attributes.
	 */
	private static function linksHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasLinks() || $resource->can('link', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeLinkItems($resource->recordLinks($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$label=self::e((string)($item['label'] ?? self::panelText('record.link_default')));
			$url=self::e((string)($item['url'] ?? ''));
			$description=trim((string)($item['description'] ?? ''));
			$group=trim((string)($item['group'] ?? ''));
			$icon=trim((string)($item['icon'] ?? ''));
			$external=!empty($item['external']) || preg_match('/^https?:\/\//i', (string)($item['url'] ?? ''))===1;
			$html.='<a class="dp-panel-link dp-panel-link-'.$tone.'" href="'.$url.'"'.($external ? ' target="_blank" rel="noopener noreferrer"' : '').'>'
				.'<span class="dp-panel-link-top">'.($icon!=='' ? '<span class="dp-panel-link-icon">'.self::e($icon).'</span>' : '').($group!=='' ? '<small>'.self::e($group).'</small>' : '').'</span>'
				.'<strong>'.$label.'</strong>'
				.($description!=='' ? '<span>'.self::e($description).'</span>' : '')
				.'</a>';
		}
		PanelTrace::record('record.links_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-links"><header><h2>'.self::e(self::panelText('record.links')).'</h2><span>'.self::recordCountLabel(count($items), 'record.link', 'record.link_plural').'</span></header><div class="dp-panel-link-grid">'.$html.'</div></section>';
	}

	/**
	 * Normalizes record link definitions into renderer-safe cards.
	 *
	 * string entries are treated as URLs, malformed or unsafe URLs are
	 * dropped, and aliases for labels, groups, detail copy, tone, icon, and external
	 * state are folded into a stable payload.
	 */
	private static function normalizeLinkItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item)){
				$item=[
					'label'=>is_string($key) ? $key : basename(parse_url($item, PHP_URL_PATH) ?: $item),
					'url'=>$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$url=self::safeWidgetUrl((string)($item['url'] ?? $item['href'] ?? $item['to'] ?? ''));
			if($url===''){
				continue;
			}
			$label=trim((string)($item['label'] ?? $item['title'] ?? $item['name'] ?? (is_string($key) ? $key : self::panelText('record.link_default'))));
			$normalized[]=[
				'label'=>$label!=='' ? $label : self::panelText('record.link_default'),
				'url'=>$url,
				'description'=>trim((string)($item['description'] ?? $item['detail'] ?? $item['help'] ?? '')),
				'group'=>trim((string)($item['group'] ?? $item['type'] ?? '')),
				'tone'=>(string)($item['tone'] ?? $item['status'] ?? 'neutral'),
				'icon'=>trim((string)($item['icon'] ?? '')),
				'external'=>(bool)($item['external'] ?? false),
			];
		}
		return $normalized;
	}

	/**
	 * Renders contact cards associated with the record.
	 *
	 * contacts are authorized separately from the main show surface. The
	 * renderer emits mail and telephone anchors only from normalized fields, keeps
	 * profile links sanitized, and traces rendered item count.
	 */
	private static function contactsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasContacts() || $resource->can('contact', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeContactItems($resource->recordContacts($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$name=self::e((string)($item['name'] ?? self::panelText('record.contact_default')));
			$role=trim((string)($item['role'] ?? ''));
			$status=trim((string)($item['status'] ?? ''));
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$details='';
			if(($item['email'] ?? '')!==''){
				$email=self::e((string)$item['email']);
				$details.='<a href="mailto:'.$email.'">'.$email.'</a>';
			}
			if(($item['phone'] ?? '')!==''){
				$phone=self::e((string)$item['phone']);
				$details.='<a href="tel:'.$phone.'">'.$phone.'</a>';
			}
			foreach(['company', 'location'] as $key){
				$value=trim((string)($item[$key] ?? ''));
				if($value!==''){
					$details.='<span>'.self::e($value).'</span>';
				}
			}
			$title=$url!=='' ? '<a href="'.self::e($url).'">'.$name.'</a>' : '<strong>'.$name.'</strong>';
			$html.='<article class="dp-panel-contact dp-panel-contact-'.$tone.'">'
				.'<header><div>'.$title.($role!=='' ? '<small>'.self::e($role).'</small>' : '').'</div>'.($status!=='' ? '<span class="dp-panel-badge dp-panel-badge-'.$tone.'">'.self::e($status).'</span>' : '').'</header>'
				.($details!=='' ? '<div class="dp-panel-contact-details">'.$details.'</div>' : '')
				.'</article>';
		}
		PanelTrace::record('record.contacts_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-contacts"><header><h2>'.self::e(self::panelText('record.contacts')).'</h2><span>'.self::recordCountLabel(count($items), 'record.contact', 'record.contact_plural').'</span></header><div class="dp-panel-contact-list">'.$html.'</div></section>';
	}

	/**
	 * Normalizes contact payloads into identity, reachability, status, and tone fields.
	 *
	 * string shorthand becomes either a named contact or email address.
	 * Status values are canonicalized through Resource naming, and default tones
	 * communicate blocked, verified, active, or neutral states.
	 */
	private static function normalizeContactItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item)){
				$item=[
					'name'=>is_string($key) ? $key : $item,
					'email'=>str_contains($item, '@') ? $item : '',
				];
			}
			if(!is_array($item)){
				continue;
			}
			$name=trim((string)($item['name'] ?? $item['label'] ?? $item['title'] ?? $item['display_name'] ?? (is_string($key) ? $key : self::panelText('record.contact_default'))));
			$status=Resource::normalizeName((string)($item['status'] ?? $item['state'] ?? ''));
			$normalized[]=[
				'name'=>$name!=='' ? $name : self::panelText('record.contact_default'),
				'role'=>trim((string)($item['role'] ?? $item['type'] ?? $item['kind'] ?? '')),
				'email'=>trim((string)($item['email'] ?? $item['mail'] ?? '')),
				'phone'=>trim((string)($item['phone'] ?? $item['telephone'] ?? $item['mobile'] ?? '')),
				'company'=>trim((string)($item['company'] ?? $item['organization'] ?? $item['org'] ?? '')),
				'location'=>trim((string)($item['location'] ?? $item['address'] ?? $item['city'] ?? '')),
				'status'=>$status,
				'url'=>self::safeWidgetUrl((string)($item['url'] ?? $item['href'] ?? $item['profile_url'] ?? '')),
				'tone'=>(string)($item['tone'] ?? ($status==='blocked' ? 'danger' : ($status==='verified' || $status==='active' ? 'success' : 'neutral'))),
			];
		}
		return $normalized;
	}

	/**
	 * Renders postal or map-oriented locations for a record.
	 *
	 * the section is read-only and guarded by the resource location
	 * ability. Address, timezone, and coordinate fragments are escaped separately,
	 * while map/profile URLs pass through the widget URL sanitizer.
	 */
	private static function locationsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasLocations() || $resource->can('location', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeLocationItems($resource->recordLocations($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$label=self::e((string)($item['label'] ?? self::panelText('record.location_default')));
			$type=trim((string)($item['type'] ?? ''));
			$status=trim((string)($item['status'] ?? ''));
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$addressLines='';
			foreach($item['address_lines'] as $line){
				$line=trim((string)$line);
				if($line!==''){
					$addressLines.='<span>'.self::e($line).'</span>';
				}
			}
			$meta='';
			foreach(['timezone', 'coordinates'] as $key){
				$value=trim((string)($item[$key] ?? ''));
				if($value!==''){
					$meta.='<span>'.self::e($value).'</span>';
				}
			}
			$title=$url!=='' ? '<a href="'.self::e($url).'" target="_blank" rel="noopener noreferrer">'.$label.'</a>' : '<strong>'.$label.'</strong>';
			$html.='<article class="dp-panel-location dp-panel-location-'.$tone.'">'
				.'<header><div>'.$title.($type!=='' ? '<small>'.self::e($type).'</small>' : '').'</div>'.($status!=='' ? '<span class="dp-panel-badge dp-panel-badge-'.$tone.'">'.self::e($status).'</span>' : '').'</header>'
				.($addressLines!=='' ? '<address>'.$addressLines.'</address>' : '')
				.($meta!=='' ? '<small class="dp-panel-location-meta">'.$meta.'</small>' : '')
				.'</article>';
		}
		PanelTrace::record('record.locations_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-locations"><header><h2>'.self::e(self::panelText('record.locations')).'</h2><span>'.self::recordCountLabel(count($items), 'record.location', 'record.location_plural').'</span></header><div class="dp-panel-location-list">'.$html.'</div></section>';
	}

	/**
	 * Normalizes location payloads into address lines, coordinates, and display state.
	 *
	 * accepts address shorthand and common CRM/address aliases. The
	 * normalizer composes locality and coordinate strings without geocoding or
	 * persistence side effects, leaving the renderer with a deterministic shape.
	 */
	private static function normalizeLocationItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item)){
				$item=[
					'label'=>is_string($key) ? $key : self::panelText('record.location_default'),
					'address'=>$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$label=trim((string)($item['label'] ?? $item['title'] ?? $item['name'] ?? (is_string($key) ? $key : self::panelText('record.location_default'))));
			$status=Resource::normalizeName((string)($item['status'] ?? $item['state'] ?? ''));
			$lines=[];
			foreach(['address', 'address1', 'line1', 'street'] as $field){
				if(isset($item[$field]) && trim((string)$item[$field])!==''){
					$lines[]=trim((string)$item[$field]);
					break;
				}
			}
			foreach(['address2', 'line2', 'unit', 'suite'] as $field){
				if(isset($item[$field]) && trim((string)$item[$field])!==''){
					$lines[]=trim((string)$item[$field]);
					break;
				}
			}
			$locality=array_values(array_filter([
				trim((string)($item['city'] ?? $item['locality'] ?? '')),
				trim((string)($item['subdivision'] ?? $item['province'] ?? $item['state'] ?? $item['region'] ?? '')),
				trim((string)($item['postal_code'] ?? $item['postal'] ?? $item['zip'] ?? '')),
			], static fn(string $value): bool => $value!==''));
			if($locality!==[]){
				$lines[]=implode(', ', $locality);
			}
			$country=trim((string)($item['country'] ?? $item['country_code'] ?? ''));
			if($country!==''){
				$lines[]=$country;
			}
			$lat=trim((string)($item['lat'] ?? $item['latitude'] ?? ''));
			$lng=trim((string)($item['lng'] ?? $item['lon'] ?? $item['longitude'] ?? ''));
			$coordinates=$lat!=='' && $lng!=='' ? $lat.', '.$lng : '';
			$normalized[]=[
				'label'=>$label!=='' ? $label : self::panelText('record.location_default'),
				'type'=>trim((string)($item['type'] ?? $item['kind'] ?? $item['role'] ?? '')),
				'status'=>$status,
				'address_lines'=>$lines,
				'timezone'=>trim((string)($item['timezone'] ?? $item['tz'] ?? '')),
				'coordinates'=>$coordinates,
				'url'=>self::safeWidgetUrl((string)($item['url'] ?? $item['href'] ?? $item['map_url'] ?? '')),
				'tone'=>(string)($item['tone'] ?? ($status==='invalid' ? 'danger' : ($status==='verified' ? 'success' : 'neutral'))),
			];
		}
		return $normalized;
	}

	/**
	 * Renders record tags and optional add/remove controls.
	 *
	 * tags combine display and mutation affordances. Every mutating
	 * control is gated by resource-level tag update support, action-specific
	 * abilities, CSRF input, return URL state, and modal confirmation attributes.
	 */
	private static function tagsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasTags() || $resource->can('tag', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeTagItems($resource->recordTags($record, $request));
		if($items===[] && !$resource->canUpdateTag()){
			return '';
		}
		$list='';
		foreach($items as $item){
			$name=(string)($item['name'] ?? '');
			$label=trim((string)($item['label'] ?? self::panelText('record.tag')));
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$description=trim((string)($item['description'] ?? ''));
			$remove='';
			if($name!=='' && $resource->canUpdateTag() && $resource->can('tag:update', $record, $request->user())!==false && $resource->can('tag:remove', $record, $request->user())!==false && $resource->can('tag:'.$name, $record, $request->user())!==false){
				$key=$resource->recordKey($record);
				$removeTitle=self::panelText('record.remove_tag_title', ['label'=>$label]);
				$removeBody=self::panelText('record.remove_tag_body', ['label'=>$label]);
				$remove='<form method="post" action="'.self::e(PanelConfig::resourceUrl($resource, 'tag/'.rawurlencode($key))).'">'
					.self::csrfInput()
					.self::returnInputUrl(self::showReturnUrl($resource, $record))
					.'<input type="hidden" name="tag" value="'.self::e($name).'">'
					.'<input type="hidden" name="tag_action" value="remove">'
					.'<button type="submit" title="'.self::e($removeTitle).'" data-confirm="'.self::e(self::panelText('record.remove_tag_confirm', ['label'=>$label])).'"'.self::resourceModalAttributes('remove_tag', self::panelText('record.remove_tag'), $removeBody, 'sm', 'dialog', false, self::panelText('record.remove_tag'), self::panelText('common.cancel'), 'danger').'>x</button>'
					.'</form>';
			}
			$list.='<span class="dp-panel-tag dp-panel-tag-'.$tone.'"'.($description!=='' ? ' title="'.self::e($description).'"' : '').'>'.self::e($label).$remove.'</span>';
		}
		if($list===''){
			$list='<p class="dp-panel-empty">'.self::e(self::panelText('record.tags_empty')).'</p>';
		}
		$form='';
		if($resource->canUpdateTag() && $resource->can('tag:update', $record, $request->user())!==false && $resource->can('tag:add', $record, $request->user())!==false){
			$key=$resource->recordKey($record);
			$form='<form class="dp-panel-tag-form" method="post" action="'.self::e(PanelConfig::resourceUrl($resource, 'tag/'.rawurlencode($key))).'">'
				.self::csrfInput()
				.self::returnInputUrl(self::showReturnUrl($resource, $record))
				.'<input type="hidden" name="tag_action" value="add">'
				.'<label><span>'.self::e(self::panelText('record.add_tag')).'</span><input type="text" name="tag" required></label>'
				.'<div class="dp-panel-modal-form-actions"><button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-modal-cancel>'.self::e(self::panelText('common.cancel')).'</button><button class="dp-panel-button" type="submit">'.self::e(self::panelText('record.add_tag')).'</button></div>'
				.'</form>';
		}
		$action=$form!=='' ? '<button class="dp-panel-button dp-panel-button-secondary" type="button"'.self::contentModalAttributes('add_tag', self::panelText('record.add_tag'), self::panelText('record.tags_empty'), $form, 'sm').'>'.self::e(self::panelText('record.add_tag')).'</button>' : '';
		PanelTrace::record('record.tags_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
			'can_update'=>$form!=='',
		]);
		return '<section class="dp-panel-tags"><header><h2>'.self::e(self::panelText('record.tags')).'</h2><span>'.self::recordCountLabel(count($items), 'record.tag', 'record.tag_plural').'</span>'.$action.'</header><div class="dp-panel-tag-list">'.$list.'</div></section>';
	}

	/**
	 * Normalizes tag definitions into stable names, labels, tones, and descriptions.
	 *
	 * scalar tags become canonical resource names, array tags can provide
	 * friendly labels or descriptions, and empty names are repaired from labels so
	 * mutation forms submit stable tag identifiers.
	 */
	private static function normalizeTagItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item) || is_numeric($item)){
				$item=[
					'name'=>$item,
					'label'=>(string)$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$name=Resource::normalizeName((string)($item['name'] ?? $item['key'] ?? $item['slug'] ?? $item['label'] ?? (is_string($key) ? $key : '')));
			$label=trim((string)($item['label'] ?? $item['title'] ?? $item['name'] ?? $name));
			if($name==='' && $label===''){
				continue;
			}
			$normalized[]=[
				'name'=>$name!=='' ? $name : Resource::normalizeName($label),
				'label'=>$label!=='' ? $label : $name,
				'description'=>trim((string)($item['description'] ?? $item['detail'] ?? $item['help'] ?? '')),
				'tone'=>(string)($item['tone'] ?? $item['status'] ?? 'neutral'),
			];
		}
		return $normalized;
	}

	/**
	 * Renders line-item style record details.
	 *
	 * the items section is a presentational ledger surface. It respects
	 * the resource item ability, normalizes line shapes before output, escapes all
	 * cells, sanitizes item URLs, and traces how many rows were displayed.
	 */
	private static function itemsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasItems() || $resource->can('item', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeRecordItems($resource->recordItems($record, $request));
		if($items===[]){
			return '';
		}
		$rows='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$title=self::e((string)($item['title'] ?? self::panelText('record.item_default')));
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$status=trim((string)($item['status'] ?? ''));
			$details='';
			foreach(['sku', 'type', 'quantity', 'unit_price', 'total'] as $key){
				$value=trim((string)($item[$key] ?? ''));
				if($value!==''){
					$details.='<span><b>'.self::e(self::humanPaymentType($key)).'</b> '.self::e($value).'</span>';
				}
			}
			$heading=$url!=='' ? '<a href="'.self::e($url).'">'.$title.'</a>' : '<strong>'.$title.'</strong>';
			$rows.='<article class="dp-panel-item dp-panel-item-'.$tone.'">'
				.'<header>'.$heading.($status!=='' ? '<span class="dp-panel-badge dp-panel-badge-'.$tone.'">'.self::e($status).'</span>' : '').'</header>'
				.($details!=='' ? '<small>'.$details.'</small>' : '')
				.'</article>';
		}
		PanelTrace::record('record.items_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-items"><header><h2>'.self::e(self::panelText('record.items')).'</h2><span>'.self::recordCountLabel(count($items), 'record.item', 'record.item_plural').'</span></header><div class="dp-panel-item-list">'.$rows.'</div></section>';
	}

	/**
	 * Normalizes record item payloads into title, quantity, amount, and metadata.
	 *
	 * scalar values become titles and common order/invoice aliases are
	 * accepted for SKU, pricing, quantity, status, and link data. Monetary fields
	 * are formatted here so the renderer receives ready-to-escape text.
	 */
	private static function normalizeRecordItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item) || is_numeric($item)){
				$item=[
					'title'=>(string)$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$title=trim((string)($item['title'] ?? $item['label'] ?? $item['name'] ?? $item['product'] ?? $item['service'] ?? (is_string($key) ? $key : self::panelText('record.item_default'))));
			$status=Resource::normalizeName((string)($item['status'] ?? $item['state'] ?? ''));
			$currency=strtoupper(trim((string)($item['currency'] ?? '')));
			$unitPrice=self::itemMoneyValue($item['unit_price'] ?? $item['price'] ?? $item['rate'] ?? '', $currency);
			$total=self::itemMoneyValue($item['total'] ?? $item['amount'] ?? $item['subtotal'] ?? '', $currency);
			$quantity=$item['quantity'] ?? $item['qty'] ?? $item['count'] ?? '';
			$normalized[]=[
				'title'=>$title!=='' ? $title : self::panelText('record.item_default'),
				'sku'=>self::stringValue($item['sku'] ?? $item['code'] ?? $item['reference'] ?? ''),
				'type'=>Resource::normalizeName((string)($item['type'] ?? $item['kind'] ?? $item['category'] ?? '')),
				'quantity'=>self::stringValue($quantity),
				'unit_price'=>$unitPrice,
				'total'=>$total,
				'status'=>$status,
				'url'=>self::safeWidgetUrl((string)($item['url'] ?? $item['href'] ?? $item['item_url'] ?? '')),
				'tone'=>(string)($item['tone'] ?? ($status==='cancelled' ? 'danger' : ($status==='fulfilled' || $status==='active' ? 'success' : 'neutral'))),
			];
		}
		return $normalized;
	}

	/**
	 * Formats a mixed monetary value for item and total displays.
	 *
	 * non-empty values keep their source text, with an optional currency
	 * prefix added only when the value does not already look currency-prefixed.
	 */
	private static function itemMoneyValue(mixed $value, string $currency=''): string {
		$text=trim(self::stringValue($value));
		if($text==='' || $currency===''){
			return $text;
		}
		return preg_match('/^[A-Z]{3}\s/i', $text)===1 ? $text : $currency.' '.$text;
	}

	/**
	 * Renders summary total cards for a record.
	 *
	 * totals are independently capability guarded from line items. Empty
	 * normalized totals collapse to no markup, and every emitted value is escaped
	 * after currency-aware formatting.
	 */
	private static function totalsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasTotals() || $resource->can('total', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeTotalItems($resource->recordTotals($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$label=self::e((string)($item['label'] ?? self::panelText('record.total_default')));
			$value=self::e((string)($item['value'] ?? ''));
			$description=trim((string)($item['description'] ?? ''));
			$html.='<article class="dp-panel-total dp-panel-total-'.$tone.'">'
				.'<span>'.$label.'</span>'
				.'<strong>'.$value.'</strong>'
				.($description!=='' ? '<small>'.self::e($description).'</small>' : '')
				.'</article>';
		}
		PanelTrace::record('record.totals_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-totals"><header><h2>'.self::e(self::panelText('record.totals')).'</h2><span>'.self::recordCountLabel(count($items), 'record.line', 'record.line_plural').'</span></header><div class="dp-panel-total-list">'.$html.'</div></section>';
	}

	/**
	 * Normalizes total payloads into label, value, description, and tone fields.
	 *
	 * supports a shared currency key, scalar totals, and common aliases for
	 * amount, balance, paid, and state. The method performs formatting only, leaving
	 * aggregate arithmetic with the resource callback.
	 */
	private static function normalizeTotalItems(array $items): array {
		$normalized=[];
		$currency=strtoupper(trim((string)($items['currency'] ?? '')));
		foreach($items as $key=>$item){
			if($key==='currency'){
				continue;
			}
			if(is_string($item) || is_numeric($item)){
				$item=[
					'label'=>is_string($key) ? $key : self::panelText('record.total_default'),
					'value'=>$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$label=trim((string)($item['label'] ?? $item['title'] ?? $item['name'] ?? (is_string($key) ? $key : self::panelText('record.total_default'))));
			$itemCurrency=strtoupper(trim((string)($item['currency'] ?? $currency)));
			$value=$item['value'] ?? $item['amount'] ?? $item['total'] ?? $item['balance'] ?? $item['paid'] ?? '';
			$status=Resource::normalizeName((string)($item['status'] ?? $item['state'] ?? ''));
			$normalized[]=[
				'label'=>$label!=='' ? self::humanPaymentType($label) : self::panelText('record.total_default'),
				'value'=>self::itemMoneyValue($value, $itemCurrency),
				'description'=>trim((string)($item['description'] ?? $item['detail'] ?? $item['help'] ?? '')),
				'tone'=>(string)($item['tone'] ?? ($status==='due' || $status==='unpaid' ? 'warning' : ($status==='paid' || $status==='settled' ? 'success' : 'neutral'))),
			];
		}
		return $normalized;
	}

	/**
	 * Renders approval requests and optional approve/reject actions.
	 *
	 * approval controls are a sensitive mutation boundary. The renderer
	 * checks global and per-approval abilities, includes CSRF and return inputs, and
	 * wraps actions in confirmation modal metadata before exposing form submits.
	 */
	private static function approvalsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasApprovals() || $resource->can('approval', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeApprovalItems($resource->recordApprovals($record, $request));
		if($items===[] && !$resource->canResolveApproval()){
			return '';
		}
		$list='';
		$pendingCount=0;
		foreach($items as $item){
			$name=(string)($item['name'] ?? '');
			$title=trim((string)($item['title'] ?? self::panelText('record.approval')));
			$status=Resource::normalizeName((string)($item['status'] ?? 'pending'));
			$pending=in_array($status, ['', 'pending', 'open', 'requested', 'waiting'], true);
			if($pending){
				$pendingCount++;
			}
			$tone=self::safeTone((string)($item['tone'] ?? ($pending ? 'warning' : ($status==='approved' ? 'success' : ($status==='rejected' ? 'danger' : 'neutral')))));
			$description=trim((string)($item['description'] ?? ''));
			$meta='';
			foreach(['requester', 'time', 'due'] as $key){
				$value=trim((string)($item[$key] ?? ''));
				if($value!==''){
					$meta.='<span>'.self::e($value).'</span>';
				}
			}
			$actions='';
			if($pending && $name!=='' && $resource->canResolveApproval() && $resource->can('approval:resolve', $record, $request->user())!==false && $resource->can('approval:'.$name, $record, $request->user())!==false){
				$key=$resource->recordKey($record);
				foreach(['approve'=>self::panelText('record.approve'), 'reject'=>self::panelText('record.reject')] as $decision=>$label){
					if($resource->can('approval:'.$name.':'.$decision, $record, $request->user())===false){
						continue;
					}
					$actions.='<form class="dp-panel-inline-action" method="post" action="'.self::e(PanelConfig::resourceUrl($resource, 'approval/'.rawurlencode($key))).'">'
						.self::csrfInput()
						.self::returnInputUrl(self::showReturnUrl($resource, $record))
						.'<input type="hidden" name="approval" value="'.self::e($name).'">'
						.'<input type="hidden" name="decision" value="'.$decision.'">'
						.'<button class="dp-panel-action dp-panel-action-'.($decision==='approve' ? 'success' : 'danger').'" type="submit" data-confirm="'.self::e(self::panelText('record.approval_action_confirm', ['action'=>$label, 'title'=>$title])).'"'.self::resourceModalAttributes('approval_'.$decision, self::panelText('record.approval_action_title', ['action'=>$label]), self::panelText('record.approval_action_confirm', ['action'=>$label, 'title'=>$title]), 'sm', 'dialog', false, $label, self::panelText('common.cancel'), $decision==='approve' ? 'success' : 'danger').'>'.self::e($label).'</button>'
						.'</form>';
				}
			}
			$list.='<article class="dp-panel-approval dp-panel-approval-'.$tone.($pending ? '' : ' dp-panel-approval-resolved').'">'
				.'<div class="dp-panel-approval-body"><strong>'.self::e($title).'</strong>'.($description!=='' ? '<p>'.self::e($description).'</p>' : '').($meta!=='' ? '<small>'.$meta.'</small>' : '').'</div>'
				.'<div class="dp-panel-approval-side"><span class="dp-panel-badge dp-panel-badge-'.$tone.'">'.self::e($status!=='' ? $status : self::panelText('record.pending')).'</span>'.($actions!=='' ? '<div class="dp-panel-approval-actions">'.$actions.'</div>' : '').'</div>'
				.'</article>';
		}
		if($list===''){
			$list='<p class="dp-panel-empty">'.self::e(self::panelText('record.approvals_empty')).'</p>';
		}
		PanelTrace::record('record.approvals_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
			'pending_count'=>$pendingCount,
			'can_resolve'=>$resource->canResolveApproval(),
		]);
		return '<section class="dp-panel-approvals"><header><h2>'.self::e(self::panelText('record.approvals')).'</h2><span>'.$pendingCount.' '.self::e(self::panelText('record.pending')).'</span></header><div class="dp-panel-approval-list">'.$list.'</div></section>';
	}

	/**
	 * Normalizes approval payloads into named workflow steps.
	 *
	 * scalar values become approval titles, statuses are canonicalized,
	 * and default tones communicate approved, rejected, pending, and neutral states
	 * for resources that only provide business data.
	 */
	private static function normalizeApprovalItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item) || is_numeric($item)){
				$item=['title'=>(string)$item];
			}
			if(!is_array($item)){
				continue;
			}
			$name=Resource::normalizeName((string)($item['name'] ?? $item['id'] ?? $item['key'] ?? (is_string($key) ? $key : '')));
			$title=trim((string)($item['title'] ?? $item['label'] ?? $item['name'] ?? $name));
			$status=Resource::normalizeName((string)($item['status'] ?? ($item['state'] ?? 'pending')));
			$normalized[]=[
				'name'=>$name,
				'title'=>$title!=='' ? $title : self::panelText('record.approval'),
				'description'=>trim((string)($item['description'] ?? $item['message'] ?? $item['detail'] ?? '')),
				'status'=>$status!=='' ? $status : self::panelText('record.pending'),
				'requester'=>self::stringValue($item['requester'] ?? $item['requested_by'] ?? $item['actor'] ?? $item['user'] ?? ''),
				'time'=>self::stringValue($item['time'] ?? $item['requested_at'] ?? $item['created_at'] ?? ''),
				'due'=>self::stringValue($item['due'] ?? $item['due_at'] ?? $item['deadline'] ?? ''),
				'tone'=>(string)($item['tone'] ?? $item['status'] ?? ''),
			];
		}
		return $normalized;
	}

	/**
	 * Renders chronological activity entries for a record.
	 *
	 * the activity feed is display-only and capability guarded. It accepts
	 * normalized actor, time, URL, and meta fragments, escapes each fragment, and
	 * traces the number of entries surfaced on the detail page.
	 */
	private static function activityHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasActivity() || $resource->can('activity', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeActivityItems($resource->recordActivity($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$title=self::e((string)($item['title'] ?? self::panelText('record.activity')));
			$message=trim((string)($item['message'] ?? ''));
			$time=trim((string)($item['time'] ?? ''));
			$actor=trim((string)($item['actor'] ?? ''));
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$meta=array_values(array_filter(array_map(static fn(mixed $value): string => self::stringValue($value), is_array($item['meta'] ?? null) ? $item['meta'] : []), static fn(string $value): bool => trim($value)!==''));
			$heading=$url!=='' ? '<a href="'.self::e($url).'">'.$title.'</a>' : '<strong>'.$title.'</strong>';
			$details='';
			foreach([$time, $actor] as $detail){
				if($detail!==''){
					$details.='<span>'.self::e($detail).'</span>';
				}
			}
			foreach($meta as $detail){
				$details.='<span>'.self::e($detail).'</span>';
			}
			$html.='<article class="dp-panel-activity-item dp-panel-activity-'.$tone.'">'
				.'<div class="dp-panel-activity-dot"></div>'
				.'<div class="dp-panel-activity-body">'.$heading.($message!=='' ? '<p>'.self::e($message).'</p>' : '').($details!=='' ? '<small>'.$details.'</small>' : '').'</div>'
				.'</article>';
		}
		PanelTrace::record('record.activity_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-activity"><header><h2>'.self::e(self::panelText('record.activity')).'</h2><span>'.self::recordCountLabel(count($items), 'record.event', 'record.event_plural').'</span></header>'.$html.'</section>';
	}

	/**
	 * Normalizes activity entries into title, message, actor, time, URL, and meta fields.
	 *
	 * scalar entries become event titles, associative arrays may use audit
	 * aliases such as action, event, by, or timestamp, and non-array meta is folded
	 * into a list so rendering stays deterministic.
	 */
	private static function normalizeActivityItems(array $items): array {
		$normalized=[];
		foreach($items as $item){
			if(is_string($item) || is_numeric($item)){
				$item=['title'=>(string)$item];
			}
			if(!is_array($item)){
				continue;
			}
			$title=trim((string)($item['title'] ?? $item['label'] ?? $item['event'] ?? $item['type'] ?? self::panelText('record.activity')));
			$message=trim((string)($item['message'] ?? $item['description'] ?? $item['detail'] ?? ''));
			$time=$item['time'] ?? $item['at'] ?? $item['created_at'] ?? $item['timestamp'] ?? null;
			$actor=$item['actor'] ?? $item['user'] ?? $item['by'] ?? null;
			$tone=(string)($item['tone'] ?? $item['status'] ?? 'neutral');
			$meta=$item['meta'] ?? [];
			if(!is_array($meta)){
				$meta=[$meta];
			}
			$normalized[]=[
				'title'=>$title!=='' ? $title : self::panelText('record.activity'),
				'message'=>$message,
				'time'=>$time!==null ? self::stringValue($time) : '',
				'actor'=>$actor!==null ? self::stringValue($actor) : '',
				'tone'=>self::safeTone($tone),
				'url'=>self::safeWidgetUrl((string)($item['url'] ?? '')),
				'meta'=>$meta,
			];
		}
		return $normalized;
	}

	/**
	 * Renders record change history with before/after values.
	 *
	 * changes are a read-only audit surface. The renderer emits one card
	 * per normalized field change, stringifies mixed values, escapes field names
	 * and values, and traces the displayed change count.
	 */
	private static function changesHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasChanges() || $resource->can('change', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeChangeItems($resource->recordChanges($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$field=self::e((string)($item['field'] ?? self::panelText('record.change')));
			$before=self::e((string)($item['before'] ?? ''));
			$after=self::e((string)($item['after'] ?? ''));
			$reason=trim((string)($item['reason'] ?? ''));
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$meta='';
			foreach([$item['time'] ?? '', $item['actor'] ?? ''] as $detail){
				$detail=trim(self::stringValue($detail));
				if($detail!==''){
					$meta.='<span>'.self::e($detail).'</span>';
				}
			}
			$heading=$url!=='' ? '<a href="'.self::e($url).'">'.$field.'</a>' : '<strong>'.$field.'</strong>';
			$html.='<article class="dp-panel-change dp-panel-change-'.$tone.'">'
				.'<header>'.$heading.($meta!=='' ? '<small>'.$meta.'</small>' : '').'</header>'
				.'<div class="dp-panel-change-values"><div><span>'.self::e(self::panelText('record.before')).'</span><code>'.$before.'</code></div><div><span>'.self::e(self::panelText('record.after')).'</span><code>'.$after.'</code></div></div>'
				.($reason!=='' ? '<p>'.self::e($reason).'</p>' : '')
				.'</article>';
		}
		PanelTrace::record('record.changes_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-changes"><header><h2>'.self::e(self::panelText('record.changes')).'</h2><span>'.self::recordCountLabel(count($items), 'record.change', 'record.change_plural').'</span></header><div class="dp-panel-change-list">'.$html.'</div></section>';
	}

	/**
	 * Normalizes audit change payloads into field, old, new, actor, and time data.
	 *
	 * supports compact field-keyed arrays plus explicit before/after
	 * aliases. Entries with no field name are discarded, preserving a stable audit
	 * shape for downstream renderers and record diagnostics.
	 */
	private static function normalizeChangeItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item) || is_numeric($item)){
				$item=[
					'field'=>is_string($key) ? $key : self::panelText('record.change'),
					'after'=>$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$field=trim((string)($item['field'] ?? $item['label'] ?? $item['name'] ?? (is_string($key) ? $key : self::panelText('record.change'))));
			$before=$item['before'] ?? $item['old'] ?? $item['from'] ?? '';
			$after=$item['after'] ?? $item['new'] ?? $item['to'] ?? '';
			if($field==='' && self::stringValue($before)==='' && self::stringValue($after)===''){
				continue;
			}
			$normalized[]=[
				'field'=>$field!=='' ? $field : self::panelText('record.change'),
				'before'=>self::stringValue($before),
				'after'=>self::stringValue($after),
				'time'=>self::stringValue($item['time'] ?? $item['changed_at'] ?? $item['created_at'] ?? ''),
				'actor'=>self::stringValue($item['actor'] ?? $item['user'] ?? $item['by'] ?? ''),
				'reason'=>trim((string)($item['reason'] ?? $item['message'] ?? $item['description'] ?? '')),
				'tone'=>(string)($item['tone'] ?? $item['status'] ?? 'neutral'),
				'url'=>self::safeWidgetUrl((string)($item['url'] ?? $item['href'] ?? '')),
			];
		}
		return $normalized;
	}

	/**
	 * Renders payment cards associated with the record.
	 *
	 * payment information is display-only in this trait; capture and
	 * refund behavior live elsewhere. The renderer normalizes amount, status, and
	 * type aliases, escapes all fields, and opens dashboard URLs through sanitized
	 * links.
	 */
	private static function paymentsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasPayments() || $resource->can('payment', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizePaymentItems($resource->recordPayments($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$title=self::e((string)($item['title'] ?? self::panelText('record.payment_default')));
			$amount=self::e((string)($item['amount'] ?? ''));
			$status=trim((string)($item['status'] ?? ''));
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$details='';
			foreach(['type', 'provider', 'reference', 'time'] as $key){
				$value=trim((string)($item[$key] ?? ''));
				if($value!==''){
					$details.='<span>'.self::e($value).'</span>';
				}
			}
			$heading=$url!=='' ? '<a href="'.self::e($url).'">'.$title.'</a>' : '<strong>'.$title.'</strong>';
			$html.='<article class="dp-panel-payment dp-panel-payment-'.$tone.'">'
				.'<header>'.$heading.($status!=='' ? '<span class="dp-panel-badge dp-panel-badge-'.$tone.'">'.self::e($status).'</span>' : '').'</header>'
				.($amount!=='' ? '<div class="dp-panel-payment-amount">'.$amount.'</div>' : '')
				.($details!=='' ? '<small>'.$details.'</small>' : '')
				.'</article>';
		}
		PanelTrace::record('record.payments_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-payments"><header><h2>'.self::e(self::panelText('record.payments')).'</h2><span>'.count($items).' '.self::e(self::panelText(count($items)===1 ? 'record.entry' : 'record.entries')).'</span></header><div class="dp-panel-payment-list">'.$html.'</div></section>';
	}

	/**
	 * Normalizes payment payloads into amount, currency, status, type, and reference data.
	 *
	 * scalar entries become payment amounts, statuses are canonicalized,
	 * and default tones map common settlement states to operator-friendly visual
	 * signals without adding gateway coupling.
	 */
	private static function normalizePaymentItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item) || is_numeric($item)){
				$item=[
					'title'=>is_string($key) ? $key : self::panelText('record.payment_default'),
					'amount'=>$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$type=Resource::normalizeName((string)($item['type'] ?? $item['kind'] ?? $item['event'] ?? 'payment'));
			$status=Resource::normalizeName((string)($item['status'] ?? $item['state'] ?? ''));
			$tone=(string)($item['tone'] ?? ($status==='failed' || $status==='disputed' ? 'danger' : (in_array($status, ['refunded', 'voided', 'pending'], true) ? 'warning' : (in_array($status, ['paid', 'captured', 'succeeded', 'completed'], true) ? 'success' : 'neutral'))));
			$currency=strtoupper(trim((string)($item['currency'] ?? '')));
			$amount=$item['amount'] ?? $item['value'] ?? $item['total'] ?? $item['gross'] ?? '';
			$amountText=trim((string)($item['amount_label'] ?? ''));
			if($amountText===''){
				$amountText=trim((string)$amount);
				if($amountText!=='' && $currency!==''){
					$amountText=$currency.' '.$amountText;
				}
			}
			$title=trim((string)($item['title'] ?? $item['label'] ?? $item['name'] ?? self::humanPaymentType($type)));
			$reference=$item['reference'] ?? $item['transaction_id'] ?? $item['payment_intent'] ?? $item['charge_id'] ?? $item['refund_id'] ?? $item['payout_id'] ?? null;
			$normalized[]=[
				'title'=>$title!=='' ? $title : self::panelText('record.payment_default'),
				'amount'=>$amountText,
				'type'=>$type,
				'status'=>$status,
				'provider'=>self::stringValue($item['provider'] ?? $item['processor'] ?? $item['gateway'] ?? ''),
				'reference'=>$reference!==null ? self::stringValue($reference) : '',
				'time'=>self::stringValue($item['time'] ?? $item['at'] ?? $item['created_at'] ?? $item['paid_at'] ?? ''),
				'url'=>self::safeWidgetUrl((string)($item['url'] ?? $item['href'] ?? $item['dashboard_url'] ?? '')),
				'tone'=>$tone,
			];
		}
		return $normalized;
	}

	/**
	 * Converts a payment or ledger field token into a human label.
	 *
	 * underscores are treated as word separators and empty tokens fall
	 * back to the localized payment default, keeping generated item/total labels
	 * readable even when resource callbacks provide raw keys.
	 */
	private static function humanPaymentType(string $type): string {
		$type=trim(str_replace('_', ' ', $type));
		return $type!=='' ? ucwords($type) : self::panelText('record.payment_default');
	}

	/**
	 * Renders shipment cards for fulfillment or delivery state.
	 *
	 * shipments are read-only record context. Tracking URLs are sanitized,
	 * carrier/service/origin/destination metadata is escaped, external links use
	 * opener isolation, and render tracing captures the count shown to operators.
	 */
	private static function shipmentsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasShipments() || $resource->can('shipment', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeShipmentItems($resource->recordShipments($record, $request));
		if($items===[]){
			return '';
		}
		$html='';
		foreach($items as $item){
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$title=self::e((string)($item['title'] ?? self::panelText('record.shipment_default')));
			$status=self::e((string)($item['status'] ?? ''));
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$tracking=trim((string)($item['tracking'] ?? ''));
			$details='';
			foreach(['carrier', 'service', 'eta', 'origin', 'destination'] as $key){
				$value=trim((string)($item[$key] ?? ''));
				if($value!==''){
					$details.='<span>'.self::e($value).'</span>';
				}
			}
			$heading=$url!=='' ? '<a href="'.self::e($url).'" target="_blank" rel="noopener noreferrer">'.$title.'</a>' : '<strong>'.$title.'</strong>';
			$html.='<article class="dp-panel-shipment dp-panel-shipment-'.$tone.'">'
				.'<header>'.$heading.($status!=='' ? '<span class="dp-panel-badge dp-panel-badge-'.$tone.'">'.$status.'</span>' : '').'</header>'
				.($tracking!=='' ? '<code>'.self::e($tracking).'</code>' : '')
				.($details!=='' ? '<small>'.$details.'</small>' : '')
				.'</article>';
		}
		PanelTrace::record('record.shipments_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
		]);
		return '<section class="dp-panel-shipments"><header><h2>'.self::e(self::panelText('record.shipments')).'</h2><span>'.self::recordCountLabel(count($items), 'record.shipment', 'record.shipment_plural').'</span></header><div class="dp-panel-shipment-list">'.$html.'</div></section>';
	}

	/**
	 * Normalizes shipment payloads into tracking, carrier, ETA, route, and tone fields.
	 *
	 * accepts scalar tracking shorthand and fulfillment aliases. Status
	 * tokens drive default tones for delivered, delayed, failed, shipped, and
	 * in-transit states while leaving source values otherwise untouched.
	 */
	private static function normalizeShipmentItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item) || is_numeric($item)){
				$item=[
					'title'=>is_string($key) ? $key : self::panelText('record.shipment_default'),
					'tracking'=>(string)$item,
				];
			}
			if(!is_array($item)){
				continue;
			}
			$status=Resource::normalizeName((string)($item['status'] ?? $item['state'] ?? ''));
			$tone=(string)($item['tone'] ?? ($status==='delivered' ? 'success' : (in_array($status, ['delayed', 'exception', 'failed'], true) ? 'danger' : ($status==='in_transit' || $status==='shipped' ? 'info' : 'neutral'))));
			$title=trim((string)($item['title'] ?? $item['label'] ?? $item['name'] ?? (is_string($key) ? $key : self::panelText('record.shipment_default'))));
			$tracking=trim((string)($item['tracking'] ?? $item['tracking_number'] ?? $item['number'] ?? $item['code'] ?? ''));
			$normalized[]=[
				'title'=>$title!=='' ? $title : self::panelText('record.shipment_default'),
				'tracking'=>$tracking,
				'carrier'=>self::stringValue($item['carrier'] ?? $item['provider'] ?? ''),
				'service'=>self::stringValue($item['service'] ?? $item['method'] ?? ''),
				'status'=>$status!=='' ? $status : self::stringValue($item['status_label'] ?? ''),
				'eta'=>self::stringValue($item['eta'] ?? $item['estimated_delivery'] ?? $item['delivery_eta'] ?? ''),
				'origin'=>self::stringValue($item['origin'] ?? $item['from'] ?? ''),
				'destination'=>self::stringValue($item['destination'] ?? $item['to'] ?? ''),
				'url'=>self::safeWidgetUrl((string)($item['url'] ?? $item['href'] ?? $item['tracking_url'] ?? '')),
				'tone'=>$tone,
			];
		}
		return $normalized;
	}

	/**
	 * Renders record notes and an optional add-note modal trigger.
	 *
	 * note creation is gated separately from note visibility. The form
	 * includes CSRF and return URL state, while existing notes are normalized,
	 * escaped, and traced with the current create capability.
	 */
	private static function notesHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasNotes() || $resource->can('note', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeNoteItems($resource->recordNotes($record, $request));
		$list='';
		foreach($items as $item){
			$message=trim((string)($item['message'] ?? ''));
			if($message===''){
				continue;
			}
			$author=trim((string)($item['author'] ?? ''));
			$time=trim((string)($item['time'] ?? ''));
			$meta='';
			foreach([$author, $time] as $detail){
				if($detail!==''){
					$meta.='<span>'.self::e($detail).'</span>';
				}
			}
			$list.='<article class="dp-panel-note"><p>'.self::e($message).'</p>'.($meta!=='' ? '<small>'.$meta.'</small>' : '').'</article>';
		}
		if($list===''){
			$list='<p class="dp-panel-empty">'.self::e(self::panelText('record.notes_empty')).'</p>';
		}
		$form='';
		if($resource->canAddNote() && $resource->can('note:create', $record, $request->user())!==false){
			$key=$resource->recordKey($record);
			$form='<form class="dp-panel-note-form" method="post" action="'.self::e(PanelConfig::resourceUrl($resource, 'note/'.rawurlencode($key))).'">'
				.self::csrfInput()
				.self::returnInputUrl(self::showReturnUrl($resource, $record))
				.'<label><span>'.self::e(self::panelText('record.add_note')).'</span><textarea name="note" rows="3" required></textarea></label>'
				.'<div class="dp-panel-modal-form-actions"><button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-modal-cancel>'.self::e(self::panelText('common.cancel')).'</button><button class="dp-panel-button" type="submit">'.self::e(self::panelText('record.add_note')).'</button></div>'
				.'</form>';
		}
		$action=$form!=='' ? '<button class="dp-panel-button dp-panel-button-secondary" type="button"'.self::contentModalAttributes('add_note', self::panelText('record.add_note'), self::panelText('record.add_note_body'), $form, 'md').'>'.self::e(self::panelText('record.add_note')).'</button>' : '';
		PanelTrace::record('record.notes_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
			'can_add'=>$form!=='',
		]);
		return '<section class="dp-panel-notes"><header><h2>'.self::e(self::panelText('record.notes')).'</h2><span>'.self::recordCountLabel(count($items), 'record.note', 'record.note_plural').'</span>'.$action.'</header><div class="dp-panel-note-list">'.$list.'</div></section>';
	}

	/**
	 * Normalizes note payloads into message, author, and time fields.
	 *
	 * scalar values become note bodies, actor and timestamp aliases are
	 * stringified when present, and empty messages stay explicit so the renderer can
	 * decide whether to skip them.
	 */
	private static function normalizeNoteItems(array $items): array {
		$normalized=[];
		foreach($items as $item){
			if(is_string($item) || is_numeric($item)){
				$item=['message'=>(string)$item];
			}
			if(!is_array($item)){
				continue;
			}
			$message=trim((string)($item['message'] ?? $item['note'] ?? $item['body'] ?? $item['text'] ?? ''));
			$author=$item['author'] ?? $item['actor'] ?? $item['user'] ?? $item['by'] ?? null;
			$time=$item['time'] ?? $item['at'] ?? $item['created_at'] ?? $item['timestamp'] ?? null;
			$normalized[]=[
				'message'=>$message,
				'author'=>$author!==null ? self::stringValue($author) : '',
				'time'=>$time!==null ? self::stringValue($time) : '',
			];
		}
		return $normalized;
	}

	/**
	 * Renders attachment links and an optional upload modal trigger.
	 *
	 * attachment upload crosses into multipart mutation. The renderer only
	 * exposes the form when both attach support and create ability allow it, then
	 * binds CSRF, return URL state, and modal content around the upload control.
	 */
	private static function attachmentsHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasAttachments() || $resource->can('attachment', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeAttachmentItems($resource->recordAttachments($record, $request));
		$list='';
		foreach($items as $item){
			$name=trim((string)($item['name'] ?? ''));
			if($name===''){
				continue;
			}
			$url=self::safeWidgetUrl((string)($item['url'] ?? ''));
			$meta='';
			foreach(['size', 'type', 'time', 'author'] as $key){
				$value=trim((string)($item[$key] ?? ''));
				if($value!==''){
					$meta.='<span>'.self::e($value).'</span>';
				}
			}
			$title=$url!=='' ? '<a href="'.self::e($url).'">'.$name.'</a>' : '<strong>'.self::e($name).'</strong>';
			$list.='<article class="dp-panel-attachment">'.$title.($meta!=='' ? '<small>'.$meta.'</small>' : '').'</article>';
		}
		if($list===''){
			$list='<p class="dp-panel-empty">'.self::e(self::panelText('record.attachments_empty')).'</p>';
		}
		$form='';
		if($resource->canAttach() && $resource->can('attachment:create', $record, $request->user())!==false){
			$key=$resource->recordKey($record);
			$form='<form class="dp-panel-attachment-form" method="post" enctype="multipart/form-data" action="'.self::e(PanelConfig::resourceUrl($resource, 'attach/'.rawurlencode($key))).'">'
				.self::csrfInput()
				.self::returnInputUrl(self::showReturnUrl($resource, $record))
				.'<label><span>'.self::e(self::panelText('record.add_attachment')).'</span><input type="file" name="attachment" required></label>'
				.'<div class="dp-panel-modal-form-actions"><button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-modal-cancel>'.self::e(self::panelText('common.cancel')).'</button><button class="dp-panel-button" type="submit">'.self::e(self::panelText('record.upload')).'</button></div>'
				.'</form>';
		}
		$action=$form!=='' ? '<button class="dp-panel-button dp-panel-button-secondary" type="button"'.self::contentModalAttributes('add_attachment', self::panelText('record.upload_attachment'), self::panelText('record.upload_attachment_body'), $form, 'md').'>'.self::e(self::panelText('record.upload')).'</button>' : '';
		PanelTrace::record('record.attachments_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
			'can_attach'=>$form!=='',
		]);
		return '<section class="dp-panel-attachments"><header><h2>'.self::e(self::panelText('record.attachments')).'</h2><span>'.self::recordCountLabel(count($items), 'record.file', 'record.file_plural').'</span>'.$action.'</header><div class="dp-panel-attachment-list">'.$list.'</div></section>';
	}

	/**
	 * Normalizes attachment payloads into name, URL, type, size, time, and author fields.
	 *
	 * string entries are treated as linked filenames, missing names are
	 * inferred from URL paths, sizes are formatted through the panel byte formatter,
	 * and unsafe URLs collapse before link rendering.
	 */
	private static function normalizeAttachmentItems(array $items): array {
		$normalized=[];
		foreach($items as $item){
			if(is_string($item)){
				$item=['name'=>basename($item), 'url'=>$item];
			}
			if(!is_array($item)){
				continue;
			}
			$name=trim((string)($item['name'] ?? $item['filename'] ?? $item['title'] ?? ''));
			$url=(string)($item['url'] ?? $item['href'] ?? '');
			$type=trim((string)($item['type'] ?? $item['mime'] ?? $item['mime_type'] ?? ''));
			$size=$item['size'] ?? $item['bytes'] ?? null;
			$time=$item['time'] ?? $item['at'] ?? $item['created_at'] ?? $item['uploaded_at'] ?? null;
			$author=$item['author'] ?? $item['actor'] ?? $item['user'] ?? $item['by'] ?? null;
			if($name==='' && $url!==''){
				$name=basename((string)parse_url($url, PHP_URL_PATH));
			}
			$normalized[]=[
				'name'=>$name,
				'url'=>self::safeWidgetUrl($url),
				'type'=>$type,
				'size'=>$size!==null ? self::formatBytes($size) : '',
				'time'=>$time!==null ? self::stringValue($time) : '',
				'author'=>$author!==null ? self::stringValue($author) : '',
			];
		}
		return $normalized;
	}

	/**
	 * Renders message history and an optional send-message modal trigger.
	 *
	 * message sending is a guarded mutation boundary. The renderer shows
	 * historical messages after normalization and only exposes the send form when
	 * the resource declares send support and the current user is allowed.
	 */
	private static function messagesHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasMessages() || $resource->can('message', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeMessageItems($resource->recordMessages($record, $request));
		$list='';
		foreach($items as $item){
			$subject=trim((string)($item['subject'] ?? ''));
			$body=trim((string)($item['body'] ?? ''));
			if($subject==='' && $body===''){
				continue;
			}
			$tone=self::safeTone((string)($item['tone'] ?? 'neutral'));
			$meta='';
			foreach(['channel', 'status', 'recipient', 'sender', 'time'] as $key){
				$value=trim((string)($item[$key] ?? ''));
				if($value!==''){
					$meta.='<span>'.self::e($value).'</span>';
				}
			}
			$list.='<article class="dp-panel-message dp-panel-message-'.$tone.'">'
				.($subject!=='' ? '<strong>'.self::e($subject).'</strong>' : '')
				.($body!=='' ? '<p>'.self::e($body).'</p>' : '')
				.($meta!=='' ? '<small>'.$meta.'</small>' : '')
				.'</article>';
		}
		if($list===''){
			$list='<p class="dp-panel-empty">'.self::e(self::panelText('record.messages_empty')).'</p>';
		}
		$form='';
		if($resource->canSendMessage() && $resource->can('message:send', $record, $request->user())!==false){
			$key=$resource->recordKey($record);
			$form='<form class="dp-panel-message-form" method="post" action="'.self::e(PanelConfig::resourceUrl($resource, 'message/'.rawurlencode($key))).'">'
				.self::csrfInput()
				.self::returnInputUrl(self::showReturnUrl($resource, $record))
				.'<div class="dp-panel-message-form-row"><label><span>'.self::e(self::panelText('record.channel')).'</span><select name="channel"><option value="email">'.self::e(self::panelText('record.email')).'</option><option value="sms">'.self::e(self::panelText('record.sms')).'</option><option value="chat">'.self::e(self::panelText('record.chat')).'</option><option value="system">'.self::e(self::panelText('record.system')).'</option></select></label><label><span>'.self::e(self::panelText('record.recipient')).'</span><input type="text" name="recipient"></label></div>'
				.'<label><span>'.self::e(self::panelText('record.subject')).'</span><input type="text" name="subject"></label>'
				.'<label><span>'.self::e(self::panelText('record.message_body')).'</span><textarea name="body" rows="3" required></textarea></label>'
				.'<div class="dp-panel-modal-form-actions"><button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-modal-cancel>'.self::e(self::panelText('common.cancel')).'</button><button class="dp-panel-button" type="submit">'.self::e(self::panelText('record.send_message')).'</button></div>'
				.'</form>';
		}
		$action=$form!=='' ? '<button class="dp-panel-button dp-panel-button-secondary" type="button"'.self::contentModalAttributes('send_message', self::panelText('record.send_message'), self::panelText('record.send_message_body'), $form, 'lg', 'slide_over').'>'.self::e(self::panelText('record.send_message')).'</button>' : '';
		PanelTrace::record('record.messages_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
			'can_send'=>$form!=='',
		]);
		return '<section class="dp-panel-messages"><header><h2>'.self::e(self::panelText('record.messages')).'</h2><span>'.self::recordCountLabel(count($items), 'record.message', 'record.message_plural').'</span>'.$action.'</header><div class="dp-panel-message-list">'.$list.'</div></section>';
	}

	/**
	 * Normalizes message payloads into subject, body, channel, status, endpoints, and time.
	 *
	 * string shorthand becomes a message body, status controls default
	 * tone, and sender/recipient aliases are stringified so callbacks can return
	 * domain objects or scalars without renderer-specific formatting.
	 */
	private static function normalizeMessageItems(array $items): array {
		$normalized=[];
		foreach($items as $item){
			if(is_string($item)){
				$item=['body'=>$item];
			}
			if(!is_array($item)){
				continue;
			}
			$status=Resource::normalizeName((string)($item['status'] ?? $item['state'] ?? ''));
			$tone=(string)($item['tone'] ?? ($status==='failed' ? 'danger' : ($status==='sent' || $status==='delivered' ? 'success' : 'neutral')));
			$normalized[]=[
				'subject'=>trim((string)($item['subject'] ?? $item['title'] ?? '')),
				'body'=>trim((string)($item['body'] ?? $item['message'] ?? $item['text'] ?? $item['content'] ?? '')),
				'channel'=>Resource::normalizeName((string)($item['channel'] ?? $item['type'] ?? '')),
				'status'=>$status,
				'recipient'=>self::stringValue($item['recipient'] ?? $item['to'] ?? $item['customer'] ?? ''),
				'sender'=>self::stringValue($item['sender'] ?? $item['from'] ?? $item['actor'] ?? $item['user'] ?? ''),
				'time'=>self::stringValue($item['time'] ?? $item['sent_at'] ?? $item['created_at'] ?? ''),
				'tone'=>$tone,
			];
		}
		return $normalized;
	}

	/**
	 * Renders record tasks and optional create/toggle task controls.
	 *
	 * task state changes are mutation boundaries guarded by task update,
	 * create, and per-task abilities. Every form carries CSRF and return inputs, and
	 * confirmation modal metadata wraps state transitions before submission.
	 */
	private static function tasksHtml(Resource $resource, PanelRequest $request, mixed $record): string {
		if(!$resource->hasTasks() || $resource->can('task', $record, $request->user())===false){
			return '';
		}
		$items=self::normalizeTaskItems($resource->recordTasks($record, $request));
		if($items===[] && !$resource->canUpdateTask()){
			return '';
		}
		$list='';
		$completedCount=0;
		foreach($items as $item){
			$name=(string)($item['name'] ?? '');
			$title=trim((string)($item['title'] ?? self::panelText('record.task')));
			$completed=($item['completed'] ?? false)===true;
			if($completed){
				$completedCount++;
			}
			$tone=self::safeTone((string)($item['tone'] ?? ($completed ? 'success' : 'neutral')));
			$description=trim((string)($item['description'] ?? ''));
			$meta='';
			foreach(['due', 'assignee'] as $key){
				$value=trim((string)($item[$key] ?? ''));
				if($value!==''){
					$meta.='<span>'.self::e($value).'</span>';
				}
			}
			$action='';
			if($name!=='' && $resource->canUpdateTask() && $resource->can('task:update', $record, $request->user())!==false && $resource->can('task:'.$name, $record, $request->user())!==false){
				$key=$resource->recordKey($record);
				$nextCompleted=$completed ? '0' : '1';
				$label=$completed ? self::panelText('record.reopen') : self::panelText('record.complete');
				$action='<form class="dp-panel-inline-action" method="post" action="'.self::e(PanelConfig::resourceUrl($resource, 'task/'.rawurlencode($key))).'">'
					.self::csrfInput()
					.self::returnInputUrl(self::showReturnUrl($resource, $record))
					.'<input type="hidden" name="task" value="'.self::e($name).'">'
					.'<input type="hidden" name="completed" value="'.$nextCompleted.'">'
					.'<button class="dp-panel-action dp-panel-action-'.($completed ? 'neutral' : 'success').'" type="submit" data-confirm="'.self::e(self::panelText('record.task_action_confirm', ['action'=>$label, 'title'=>$title])).'"'.self::resourceModalAttributes('task_'.$label, self::panelText('record.task_action_title', ['action'=>$label]), self::panelText('record.task_action_confirm', ['action'=>$label, 'title'=>$title]), 'sm', 'dialog', false, $label, self::panelText('common.cancel'), $completed ? 'neutral' : 'success').'>'.self::e($label).'</button>'
					.'</form>';
			}
			$list.='<article class="dp-panel-task dp-panel-task-'.$tone.($completed ? ' dp-panel-task-complete' : '').'">'
				.'<div class="dp-panel-task-check">'.($completed ? '&#10003;' : '').'</div>'
				.'<div class="dp-panel-task-body"><strong>'.self::e($title).'</strong>'.($description!=='' ? '<p>'.self::e($description).'</p>' : '').($meta!=='' ? '<small>'.$meta.'</small>' : '').'</div>'
				.($action!=='' ? '<div class="dp-panel-task-action">'.$action.'</div>' : '')
				.'</article>';
		}
		if($list===''){
			$list='<p class="dp-panel-empty">'.self::e(self::panelText('record.tasks_empty')).'</p>';
		}
		$form='';
		if($resource->canCreateTask() && $resource->can('task:create', $record, $request->user())!==false){
			$key=$resource->recordKey($record);
			$form='<form class="dp-panel-task-form" method="post" action="'.self::e(PanelConfig::resourceUrl($resource, 'task/'.rawurlencode($key))).'">'
				.self::csrfInput()
				.self::returnInputUrl(self::showReturnUrl($resource, $record))
				.'<input type="hidden" name="task_action" value="create">'
				.'<label><span>'.self::e(self::panelText('record.add_task')).'</span><input type="text" name="title" required></label>'
				.'<label><span>'.self::e(self::panelText('record.details')).'</span><textarea name="description" rows="2"></textarea></label>'
				.'<div class="dp-panel-task-form-row"><label><span>'.self::e(self::panelText('record.due')).'</span><input type="datetime-local" name="due"></label><label><span>'.self::e(self::panelText('record.assignee')).'</span><input type="text" name="assignee"></label></div>'
				.'<div class="dp-panel-modal-form-actions"><button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-modal-cancel>'.self::e(self::panelText('common.cancel')).'</button><button class="dp-panel-button" type="submit">'.self::e(self::panelText('record.add_task')).'</button></div>'
				.'</form>';
		}
		$action=$form!=='' ? '<button class="dp-panel-button dp-panel-button-secondary" type="button"'.self::contentModalAttributes('add_task', self::panelText('record.add_task'), self::panelText('record.add_task_body'), $form, 'lg', 'slide_over').'>'.self::e(self::panelText('record.add_task')).'</button>' : '';
		PanelTrace::record('record.tasks_rendered', [
			'resource'=>$resource,
			'request'=>$request,
			'item_count'=>count($items),
			'completed_count'=>$completedCount,
			'can_create'=>$form!=='',
		]);
		return '<section class="dp-panel-tasks"><header><h2>'.self::e(self::panelText('record.tasks')).'</h2><span>'.self::e(self::panelText('record.task_status', ['completed'=>$completedCount, 'total'=>count($items)])).'</span>'.$action.'</header><div class="dp-panel-task-list">'.$list.'</div></section>';
	}

	/**
	 * Normalizes task payloads into identity, title, completion, due, assignee, and tone.
	 *
	 * scalar entries become task titles, ids are canonicalized for action
	 * forms, truthy completion aliases are honored, and common status tokens map to
	 * default visual tones without requiring resource callbacks to know CSS classes.
	 */
	private static function normalizeTaskItems(array $items): array {
		$normalized=[];
		foreach($items as $key=>$item){
			if(is_string($item) || is_numeric($item)){
				$item=['title'=>(string)$item];
			}
			if(!is_array($item)){
				continue;
			}
			$name=Resource::normalizeName((string)($item['name'] ?? $item['id'] ?? $item['key'] ?? (is_string($key) ? $key : '')));
			$title=trim((string)($item['title'] ?? $item['label'] ?? $item['name'] ?? $name));
			$completed=self::truthy($item['completed'] ?? $item['done'] ?? $item['complete'] ?? false);
			$status=strtolower(trim((string)($item['status'] ?? '')));
			if(in_array($status, ['done', 'complete', 'completed', 'closed'], true)){
				$completed=true;
			}
			$normalized[]=[
				'name'=>$name,
				'title'=>$title!=='' ? $title : self::panelText('record.task'),
				'description'=>trim((string)($item['description'] ?? $item['message'] ?? $item['detail'] ?? '')),
				'completed'=>$completed,
				'due'=>self::stringValue($item['due'] ?? $item['due_at'] ?? $item['deadline'] ?? ''),
				'assignee'=>self::stringValue($item['assignee'] ?? $item['owner'] ?? $item['user'] ?? ''),
				'tone'=>(string)($item['tone'] ?? ($completed ? 'success' : ($status==='blocked' ? 'danger' : ($status==='waiting' ? 'warning' : 'neutral')))),
			];
		}
		return $normalized;
	}
}
