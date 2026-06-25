<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Renders a standalone HTML preview for a panel theme definition.
 *
 * The preview accepts either a PanelTheme instance or an already-materialized
 * preview array, emits escaped markup for swatches, mode samples, and contrast
 * rows, and can inline the small stylesheet needed by diagnostic and preview
 * screens. It performs no persistence or runtime theme activation.
 */
final class PanelThemePreview {

	/**
	 * Converts a theme preview payload into embeddable HTML.
	 *
	 * Empty or malformed preview arrays return an empty string so callers can
	 * omit the section without special branching. All displayed values are HTML
	 * escaped, while CSS color values remain plain strings for browser parsing.
	 *
	 * @param PanelTheme|array $themeOrPreview Theme object or preview array produced by PanelTheme::preview().
	 * @param array{css?:bool} $options Rendering options; set css=false when the host already loaded preview styles.
	 * @return string Complete preview section HTML, or an empty string when no preview is available.
	 */
	public static function render(PanelTheme|array $themeOrPreview, array $options=[]): string {
		$preview=$themeOrPreview instanceof PanelTheme ? $themeOrPreview->preview() : $themeOrPreview;
		if(!is_array($preview) || $preview===[]){
			return '';
		}
		$showCss=($options['css'] ?? true)!==false;
		$html=$showCss ? '<style>'.self::css().'</style>' : '';
		$html.='<section class="dp-theme-preview" data-theme="'.self::e((string)($preview['name'] ?? 'theme')).'">';
		$html.=self::header($preview);
		$html.=self::swatches(is_array($preview['colors'] ?? null) ? $preview['colors'] : []);
		$html.=self::modeSamples(is_array($preview['modes'] ?? null) ? $preview['modes'] : []);
		$html.=self::contrast(is_array($preview['contrast'] ?? null) ? $preview['contrast'] : []);
		$html.='</section>';
		return $html;
	}

	/**
	 * Builds the preview heading from brand metadata and default mode.
	 *
	 * @param array<string,mixed> $preview Theme preview payload.
	 * @return string Escaped header HTML.
	 */
	private static function header(array $preview): string {
		$brand=is_array($preview['brand'] ?? null) ? $preview['brand'] : [];
		$name=(string)($brand['name'] ?? $preview['name'] ?? 'Theme');
		$mode=(string)($preview['default_mode'] ?? 'system');
		return '<header class="dp-theme-preview-header"><div><span>Theme</span><h2>'.self::e($name).'</h2></div><small>Default: '.self::e($mode).'</small></header>';
	}

	/**
	 * Renders color-token cards for the preview.
	 *
	 * @param array<string,array<string,mixed>> $colors Preview color definitions keyed by token name.
	 * @return string Swatch section HTML, or an empty string when no colors are defined.
	 */
	private static function swatches(array $colors): string {
		if($colors===[]){
			return '';
		}
		$html='<div class="dp-theme-preview-section"><h3>Colors</h3><div class="dp-theme-preview-swatches">';
		foreach($colors as $name=>$definition){
			$key=is_array($definition['key'] ?? null) ? $definition['key'] : [];
			$base=(string)($key['base'] ?? '#888888');
			$html.='<article class="dp-theme-preview-swatch"><span style="background:'.self::e($base).'"></span><strong>'.self::e((string)$name).'</strong><small>'.self::e($base).'</small></article>';
		}
		return $html.'</div></div>';
	}

	/**
	 * Renders light, dark, or custom mode sample cards.
	 *
	 * @param array<string,array<string,mixed>|null> $modes Preview mode samples keyed by mode name.
	 * @return string Mode sample section HTML.
	 */
	private static function modeSamples(array $modes): string {
		$html='<div class="dp-theme-preview-section"><h3>Modes</h3><div class="dp-theme-preview-modes">';
		foreach($modes as $mode=>$definition){
			if(!is_array($definition)){
				continue;
			}
			$samples=is_array($definition['samples'] ?? null) ? $definition['samples'] : [];
			$surface=is_array($samples['surface'] ?? null) ? $samples['surface'] : [];
			$control=is_array($samples['control'] ?? null) ? $samples['control'] : [];
			$action=is_array($samples['action'] ?? null) ? $samples['action'] : [];
			$html.='<article class="dp-theme-preview-mode" style="background:'.self::e((string)($surface['background'] ?? '#ffffff')).';color:'.self::e((string)($surface['text'] ?? '#111111')).';border-color:'.self::e((string)($surface['border'] ?? '#dddddd')).'">';
			$html.='<h4>'.self::e((string)$mode).'</h4><p style="color:'.self::e((string)($surface['muted_text'] ?? $surface['text'] ?? '#555555')).'">Surface text and muted supporting copy.</p>';
			$html.='<div class="dp-theme-preview-control" style="background:'.self::e((string)($control['background'] ?? '#ffffff')).';color:'.self::e((string)($control['text'] ?? '#111111')).';border-color:'.self::e((string)($control['border'] ?? '#dddddd')).';padding:'.self::e((string)($control['padding'] ?? '8px 10px')).'">Input value</div>';
			$html.='<button type="button" style="background:'.self::e((string)($action['background'] ?? '#2563eb')).';color:'.self::e((string)($action['text'] ?? '#ffffff')).';padding:'.self::e((string)($action['padding'] ?? '8px 12px')).';border-radius:'.self::e((string)($action['radius'] ?? '8px')).'">Action</button>';
			$html.='</article>';
		}
		return $html.'</div></div>';
	}

	/**
	 * Renders contrast-check rows for theme quality review.
	 *
	 * @param array<string,list<array<string,mixed>>> $contrast Contrast check payloads grouped by mode.
	 * @return string Contrast table HTML, or an empty string when no checks exist.
	 */
	private static function contrast(array $contrast): string {
		$rows='';
		foreach($contrast as $mode=>$checks){
			foreach(is_array($checks) ? $checks : [] as $check){
				$status=(string)($check['status'] ?? 'unknown');
				$rows.='<tr><td>'.self::e((string)$mode).'</td><td>'.self::e((string)($check['background'] ?? '')).' / '.self::e((string)($check['text'] ?? '')).'</td><td>'.self::e((string)($check['ratio'] ?? 'n/a')).'</td><td><span class="dp-theme-preview-status-'.$status.'">'.self::e($status).'</span></td></tr>';
			}
		}
		return $rows==='' ? '' : '<div class="dp-theme-preview-section"><h3>Contrast</h3><table class="dp-theme-preview-contrast"><tbody>'.$rows.'</tbody></table></div>';
	}

	/**
	 * Returns the self-contained stylesheet used by preview embeds.
	 *
	 * @return string Compact CSS scoped to the dp-theme-preview class family.
	 */
	private static function css(): string {
		return '.dp-theme-preview{display:grid;gap:14px;font-family:Arial,sans-serif;color:#111827}.dp-theme-preview-header{display:flex;justify-content:space-between;gap:12px;align-items:flex-end}.dp-theme-preview-header span,.dp-theme-preview-header small{color:#667085;font-size:12px;font-weight:700}.dp-theme-preview-header h2,.dp-theme-preview-section h3{margin:0}.dp-theme-preview-section{display:grid;gap:10px}.dp-theme-preview-swatches,.dp-theme-preview-modes{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.dp-theme-preview-swatch,.dp-theme-preview-mode{border:1px solid #e5e7eb;border-radius:8px;padding:12px}.dp-theme-preview-swatch{display:grid;gap:5px}.dp-theme-preview-swatch span{display:block;height:42px;border-radius:6px}.dp-theme-preview-swatch strong{text-transform:capitalize}.dp-theme-preview-swatch small{color:#667085}.dp-theme-preview-mode{display:grid;gap:9px}.dp-theme-preview-mode h4{margin:0;text-transform:capitalize}.dp-theme-preview-mode p{margin:0}.dp-theme-preview-mode button{border:0;font-weight:700}.dp-theme-preview-control{border:1px solid;border-radius:6px}.dp-theme-preview-contrast{width:100%;border-collapse:collapse;border:1px solid #e5e7eb}.dp-theme-preview-contrast td{padding:8px 10px;border-bottom:1px solid #eef2f7}.dp-theme-preview-status-pass{color:#067647;font-weight:700}.dp-theme-preview-status-fail{color:#b42318;font-weight:700}.dp-theme-preview-status-unknown{color:#667085;font-weight:700}';
	}

	/**
	 * Escapes preview text and attribute values for HTML output.
	 *
	 * @param string $value Raw preview value.
	 * @return string UTF-8 safe escaped value.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
