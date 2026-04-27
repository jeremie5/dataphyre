<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(class_exists('dataphyre_flightdeck_view', false)){
	return;
}

final class dataphyre_flightdeck_view {

	private static bool $ready=false;

	public static function layout(string $title, string $content, string $active='dashboard', array $options=[]): string {
		self::ensure_ready();
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		return (string)\dataphyre\templating::render(self::template('layout.tpl'), [
			'title'=>$title,
		], [], [
			'css'=>self::css(),
			'nav'=>self::nav($active),
			'sidebar_bottom'=>self::sidebar_bottom($options),
			'head'=>(string)($options['head'] ?? ''),
			'content'=>$content,
		]);
	}

	public static function module_page(string $module, string $title, string $description, string $content, string $active='modules', array $options=[]): string {
		self::ensure_ready();
		$body=(string)\dataphyre\templating::render(self::template('module.tpl'), [
			'module'=>$module,
			'title'=>$title,
			'description'=>$description,
		], [], [
			'actions'=>(string)($options['actions'] ?? ''),
			'content'=>$content,
		]);
		return self::layout($title, $body, $active, $options);
	}

	public static function card(string $title, string $content, array $options=[]): string {
		$subtitle=isset($options['subtitle']) ? '<p class="fd-muted">'.self::e((string)$options['subtitle']).'</p>' : '';
		$actions=(string)($options['actions'] ?? '');
		return '<section class="fd-card"><div class="fd-section-title"><div><h1>'.self::e($title).'</h1>'.$subtitle.'</div>'.$actions.'</div>'.$content.'</section>';
	}

	public static function table(array $headers, array $rows): string {
		$head='';
		foreach($headers as $header){
			$head.='<th>'.self::e((string)$header).'</th>';
		}
		$body='';
		foreach($rows as $row){
			$body.='<tr>';
			foreach($row as $cell){
				$body.='<td>'.$cell.'</td>';
			}
			$body.='</tr>';
		}
		if($body===''){
			$body='<tr><td colspan="'.max(1, count($headers)).'"><span class="fd-muted">No records available.</span></td></tr>';
		}
		return '<div class="fd-table-wrap"><table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></div>';
	}

	public static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	public static function code(string $value): string {
		return '<pre class="fd-code">'.self::e($value).'</pre>';
	}

	public static function badge(string $value, string $level='info'): string {
		$class=match(strtolower($level)){
			'fatal', 'error'=>'fd-badge fd-badge-danger',
			'warning'=>'fd-badge fd-badge-warning',
			'success', 'ok'=>'fd-badge fd-badge-success',
			default=>'fd-badge',
		};
		return '<span class="'.$class.'">'.self::e($value).'</span>';
	}

	public static function capture(callable $callback): string {
		$buffer_level=ob_get_level();
		ob_start();
		try{
			$callback();
			return (string)ob_get_clean();
		}catch(\Throwable $exception){
			while(ob_get_level()>$buffer_level){
				ob_end_clean();
			}
			throw $exception;
		}
	}

	private static function ensure_ready(): void {
		if(self::$ready===true && class_exists('\dataphyre\templating', false)){
			return;
		}
		self::load_templating_module();
		if(class_exists('\dataphyre\templating', false)!==true){
			throw new \RuntimeException('Flightdeck requires the Dataphyre templating module.');
		}
		\dataphyre\templating::init(
			is_dev_mode: defined('IS_PRODUCTION') ? IS_PRODUCTION!==true : true,
			cache_dir: self::cache_dir(),
			strict_mode: false
		);
		self::register_contracts();
		self::$ready=true;
	}

	private static function load_templating_module(): void {
		if(class_exists('\dataphyre\templating', false)===true){
			return;
		}
		self::load_core_helpers();
		$candidates=[];
		if(function_exists('dp_module_present')){
			$module=dp_module_present('templating');
			if(is_array($module) && isset($module[0]) && is_string($module[0])){
				$candidates[]=$module[0];
			}
		}
		if(defined('ROOTPATH')){
			if(!empty(ROOTPATH['common_dataphyre_runtime'])){
				$candidates[]=rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/templating/kernel/templating.main.php';
			}
			if(!empty(ROOTPATH['dataphyre'])){
				$candidates[]=rtrim((string)ROOTPATH['dataphyre'], '/\\').'/modules/templating/kernel/templating.main.php';
			}
		}
		$candidates[]=rtrim(dirname(__DIR__, 2), '/\\').'/templating/kernel/templating.main.php';
		foreach(array_unique($candidates) as $candidate){
			if(is_file($candidate)){
				require_once($candidate);
				if(class_exists('\dataphyre\templating', false)===true){
					return;
				}
			}
		}
	}

	private static function load_core_helpers(): void {
		if(function_exists('dp_module_required') && function_exists('dp_module_present')){
			return;
		}
		$candidates=[];
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre_runtime'])){
			$candidates[]=rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/core/kernel/helper_functions.php';
		}
		$candidates[]=rtrim(dirname(__DIR__, 2), '/\\').'/core/kernel/helper_functions.php';
		foreach(array_unique($candidates) as $candidate){
			if(is_file($candidate)){
				require_once($candidate);
				if(function_exists('dp_module_required') && function_exists('dp_module_present')){
					return;
				}
			}
		}
	}

	private static function register_contracts(): void {
		\dataphyre\templating::register_template_contract(self::template('layout.tpl'), [
			'required'=>['title'],
			'required_slots'=>['css', 'nav', 'content'],
			'optional_slots'=>['head', 'sidebar_bottom'],
		]);
		\dataphyre\templating::register_template_contract(self::template('module.tpl'), [
			'required'=>['module', 'title', 'description'],
			'required_slots'=>['content'],
			'optional_slots'=>['actions'],
		]);
		\dataphyre\templating::add_to_global_context('flightdeck_name', 'Dataphyre Flightdeck');
	}

	private static function template(string $name): string {
		return rtrim(dirname(__DIR__), '/\\').'/templates/'.$name;
	}

	private static function cache_dir(): string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/cache/flightdeck/templates/';
		}
		return rtrim(dirname(__DIR__, 4), '/\\').'/cache/flightdeck/templates/';
	}

	private static function nav(string $active): string {
		$items=[
			'dashboard'=>['/dataphyre', 'Dashboard'],
			'logs'=>['/dataphyre/logs', 'Logs'],
			'modules'=>['/dataphyre/modules', 'Modules'],
			'flight-sheet'=>['/dataphyre/flight-sheet', 'Flight Sheet'],
			'debugbar'=>['/dataphyre/debugbar', 'Runtime Toolbar'],
			'datadoc'=>['/dataphyre/datadoc', 'DataDoc'],
			'tracelog'=>['/dataphyre/tracelog', 'Tracelog'],
			'dpanel'=>['/dataphyre/dpanel', 'Dpanel'],
		];
		$html='';
		foreach($items as $key=>$item){
			$html.='<a class="'.($active===$key ? 'active' : '').'" href="'.$item[0].'">'.$item[1].'</a>';
		}
		return $html;
	}

	private static function sidebar_bottom(array $options): string {
		$logout=(($options['logout'] ?? true)===true && class_exists('dataphyre_flightdeck_auth', false) && dataphyre_flightdeck_auth::auth_required())
			? '<a href="/dataphyre/logout">Logout</a>'
			: '';
		$app=defined('APP') ? '<span class="fd-side-meta">App: '.self::e((string)APP).'</span>' : '';
		return $app.$logout;
	}

	private static function css(): string {
		return ':root{--bg:#07111f;--panel:#f8fafc;--line:#dbe4ef;--text:#0f172a;--muted:#64748b;--accent:#0ea5e9;--accent2:#f97316;--danger:#dc2626;--ok:#16a34a}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,rgba(14,165,233,.18),transparent 28rem),linear-gradient(135deg,#07111f,#111827 55%,#172033);font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text)}a{color:inherit}.fd-sidebar{position:fixed;inset:0 auto 0 0;width:250px;background:rgba(7,17,31,.92);border-right:1px solid rgba(148,163,184,.18);color:#dbeafe;padding:22px;display:flex;flex-direction:column;gap:26px}.fd-logo{font-size:1.65rem;font-weight:900;line-height:1;letter-spacing:-.04em;color:#fff;text-decoration:none}.fd-logo span{color:#7dd3fc}.fd-sidebar nav{display:grid;gap:8px}.fd-sidebar nav a,.fd-sidebar-bottom a{padding:11px 12px;border-radius:14px;text-decoration:none;color:#b8c7df}.fd-sidebar nav a.active,.fd-sidebar nav a:hover{background:rgba(125,211,252,.12);color:#fff}.fd-sidebar-bottom{margin-top:auto;display:grid;gap:10px}.fd-side-meta{display:block;color:#8da2bd;font-size:.8rem;padding:0 12px}.fd-main{margin-left:250px;padding:30px;max-width:1680px}.fd-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;color:#fff;margin-bottom:22px;padding:30px;border-radius:28px;background:linear-gradient(135deg,rgba(14,165,233,.2),rgba(249,115,22,.12));border:1px solid rgba(255,255,255,.12);box-shadow:0 20px 80px rgba(0,0,0,.22)}.fd-module-hero{background:linear-gradient(135deg,rgba(20,184,166,.18),rgba(14,165,233,.16))}.fd-hero h1{font-size:3rem;margin:.1rem 0}.fd-hero p{max-width:760px;color:#dbeafe}.fd-kicker{text-transform:uppercase;letter-spacing:.16em;font-size:.75rem;font-weight:900;color:#7dd3fc;margin:0}.fd-card,.fd-metric{background:rgba(248,250,252,.97);border:1px solid rgba(219,228,239,.8);box-shadow:0 18px 70px rgba(0,0,0,.2);border-radius:24px;padding:22px;margin-bottom:20px}.fd-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}.fd-metric span{color:var(--muted);font-weight:700}.fd-metric b{display:block;font-size:1.8rem;margin:.4rem 0}.fd-metric p{color:var(--muted);margin:0}.fd-section-title{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px}.fd-section-title h1,.fd-section-title h2{margin:0}.fd-link-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.fd-link-card{display:block;padding:16px;border:1px solid var(--line);border-radius:18px;text-decoration:none;background:#fff}.fd-link-card b{display:block;margin-bottom:8px}.fd-link-card span,.fd-muted{color:var(--muted)}.fd-primary,.fd-danger{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:11px 16px;text-decoration:none;font-weight:900;border:0}.fd-primary{background:#7dd3fc;color:#082f49}.fd-danger{background:#fee2e2;color:#991b1b}.fd-warning,.fd-alert{border-radius:18px;padding:14px 16px;margin-bottom:18px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.fd-alert{background:#fee2e2;color:#991b1b;border-color:#fecaca}.fd-pill,.fd-badge{display:inline-flex;padding:8px 11px;border-radius:999px;background:#eef8ff;color:#075985;font-weight:800}.fd-badge-danger{background:#fee2e2;color:#991b1b}.fd-badge-warning{background:#fef3c7;color:#92400e}.fd-badge-success{background:#dcfce7;color:#166534}.fd-table-wrap{overflow:auto;border-radius:18px;border:1px solid var(--line)}table{width:100%;border-collapse:collapse;background:#fff}th,td{padding:13px 14px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{background:#eaf1f8;color:#334155}tr:last-child td{border-bottom:0}.fd-log-table th:first-child{width:190px}.fd-code,pre{background:#07111f;color:#dbeafe;border-radius:18px;padding:16px;overflow:auto;white-space:pre-wrap;line-height:1.55}.fd-login{max-width:560px;margin:10vh auto}.fd-login input{width:100%;border:1px solid var(--line);border-radius:14px;padding:13px 14px;margin:12px 0;font-size:1rem}.fd-login button{border:0;border-radius:14px;background:#0f172a;color:#fff;padding:13px 16px;font-weight:900;cursor:pointer;width:100%}@media(max-width:1040px){.fd-sidebar{position:static;width:auto;display:block}.fd-sidebar nav{grid-template-columns:repeat(3,1fr);margin-top:18px}.fd-main{margin-left:0;padding:16px}.fd-metrics,.fd-link-grid{grid-template-columns:1fr 1fr}.fd-hero{display:block}.fd-hero h1{font-size:2.2rem}}@media(max-width:680px){.fd-metrics,.fd-link-grid{grid-template-columns:1fr}.fd-sidebar nav{grid-template-columns:1fr}.fd-section-title{display:block}}';
	}
}
