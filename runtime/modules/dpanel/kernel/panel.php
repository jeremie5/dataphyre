<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/dpanel.main.php';

/** Renders the browser-facing diagnostic panel from normalized findings. */
final class dataphyre_dpanel_page {
	/** @param array<string,mixed> $runtime */
	public static function bootstrap(?bool $dispatch=null, array $runtime=[]): string {
		$dispatch ??= !defined('DATAPHYRE_DPANEL_PAGE_NO_DISPATCH');
		if(!$dispatch){
			return '';
		}
		$post=is_array($runtime['post'] ?? null) ? $runtime['post'] : ($_POST ?? []);
		$scan=array_key_exists('dataphyre_full', $post);
		$trace=[];
		if($scan){
			$diagnose=is_callable($runtime['diagnose'] ?? null) ? $runtime['diagnose'] : [\dataphyre\dpanel::class, 'diagnose_modules_in_folder'];
			$verbose=is_callable($runtime['verbose'] ?? null) ? $runtime['verbose'] : [\dataphyre\dpanel::class, 'get_verbose'];
			$paths=is_array($runtime['module_paths'] ?? null) ? $runtime['module_paths'] : self::modulePaths();
			foreach(array_values(array_unique($paths)) as $path){
				if(is_string($path) && $path!==''){
					$diagnose($path);
				}
			}
			$result=$verbose();
			$trace=is_array($result) ? $result : [];
		}
		$app=trim((string)($runtime['app'] ?? (defined('APP') ? APP : 'application')));
		return self::render($trace, $app!=='' ? $app : 'application', $scan);
	}

	/** @param list<array<string,mixed>> $trace */
	public static function render(array $trace, string $app='application', bool $scanned=false): string {
		$rows='';
		foreach($trace as $index=>$entry){
			if(is_array($entry)){
				$rows.=self::renderEntry($entry, (int)$index);
			}
		}
		$content=$rows!==''
			? '<div class="trace-table"><table><thead><tr><th>Type</th><th>File / Module</th><th>Message</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'
			: '<h3 class="empty">'.($scanned ? 'No diagnostic findings were reported.' : 'Choose a scan below to inspect the framework.').'</h3>';
		$app=self::escape(ucfirst($app));
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			.'<title>Dataphyre Dpanel</title><style>'.self::styles().'</style></head><body><main><header><p class="eyebrow">DATAPHYRE / DIAGNOSTICS</p>'
			.'<h1>Dataphyre Dpanel</h1><p>Inspect module diagnostics, trace evidence, and framework health from one deterministic report.</p></header>'
			.'<section>'.$content.'</section><form method="post"><button name="dataphyre_full" value="1">Diagnose Dataphyre</button>'
			.'<button name="project_full" value="1">Diagnose all projects</button><button name="project_full" value="'.$app.'">Diagnose '.$app.'</button></form>'
			.'</main></body></html>';
	}

	/** @param array<string,mixed> $entry */
	private static function renderEntry(array $entry, int $index): string {
		$type=strtolower(trim((string)($entry['type'] ?? 'info'))) ?: 'info';
		$level=strtolower(trim((string)($entry['level'] ?? $type))) ?: 'info';
		$file=(string)($entry['file'] ?? $entry['test_case_file'] ?? $entry['module'] ?? 'N/A');
		$message='';
		$details='';
		if($type==='php_exception' && ($entry['exception'] ?? null) instanceof \Throwable){
			$exception=$entry['exception'];
			$message=self::escape($exception->getMessage());
			$file=$exception->getFile().':'.$exception->getLine();
			$details='<pre>'.self::escape($exception->getTraceAsString()).'</pre>';
			$level='error';
		}
		elseif($type==='tracelog' && is_array($entry['tracelog'] ?? null))
		{
			[$message,$details,$level]=self::renderTraceRows($entry['tracelog']);
		}
		else
		{
			$raw=$entry['fail_string'] ?? $entry['warning_string'] ?? $entry['error'] ?? $entry['message'] ?? $entry['reason'] ?? '';
			$message=trim((string)$raw)!=='' ? nl2br(self::escape((string)$raw)) : '<i>No message provided</i>';
		}
		$details_id='trace-'.$index;
		$details_control=$details!=='' ? '<details id="'.$details_id.'"><summary>Expand evidence</summary>'.$details.'</details>' : '';
		return '<tr class="level-'.self::escape(self::level($level)).'"><td><span class="badge">'.self::escape($type).'</span></td>'
			.'<td title="'.self::escape($file).'">'.self::escape($file!=='' ? basename($file) : 'N/A').'</td><td><div class="message">'.$message.'</div>'.$details_control.'</td></tr>';
	}

	/** @param list<mixed> $logs @return array{0:string,1:string,2:string} */
	private static function renderTraceRows(array $logs): array {
		$counts=[];
		$worst='info';
		$rows='';
		foreach($logs as $log){
			if(!is_array($log)){
				continue;
			}
			$type=self::level((string)($log['type'] ?? 'info'));
			$counts[$type]=($counts[$type] ?? 0)+1;
			if(self::severity($type)>self::severity($worst)){
				$worst=$type;
			}
			$rows.='<tr><td>'.self::escape(basename((string)($log['file'] ?? 'Unknown'))).'</td><td>'.self::escape((string)($log['line'] ?? '—')).'</td>'
				.'<td>'.self::escape((string)($log['class'] ?? '—')).'</td><td>'.self::escape((string)($log['function'] ?? '—')).'</td>'
				.'<td><pre>'.self::escape((string)($log['message'] ?? '')).'</pre></td></tr>';
		}
		$summary=[];
		foreach($counts as $type=>$count){
			$summary[]=$count.' '.$type;
		}
		$message='<i>'.count($logs).' trace entries'.($summary!==[] ? ' ('.implode(', ', $summary).')' : '').'</i>';
		$details='<table class="nested"><thead><tr><th>File</th><th>Line</th><th>Class</th><th>Function</th><th>Message</th></tr></thead><tbody>'.$rows.'</tbody></table>';
		return [$message,$details,$worst];
	}

	/** @param array<string,mixed>|null $rootPath @return list<string> */
	private static function modulePaths(?array $rootPath=null): array {
		$rootPath ??= defined('ROOTPATH') && is_array(ROOTPATH) ? ROOTPATH : [];
		$paths=[];
		foreach(['common_dataphyre','dataphyre'] as $key){
			$root=trim((string)($rootPath[$key] ?? ''));
			if($root!==''){
				$paths[]=rtrim($root, '/\\').'/modules';
			}
		}
		return $paths;
	}

	private static function level(string $level): string {
		$level=strtolower(trim($level));
		return in_array($level, ['info','warning','error','fatal'], true) ? $level : 'info';
	}

	private static function severity(string $level): int {
		return ['info'=>0,'warning'=>1,'error'=>2,'fatal'=>3][self::level($level)];
	}

	private static function escape(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
	}

	private static function styles(): string {
		return 'body{margin:0;background:#111827;color:#e5e7eb;font:15px system-ui,sans-serif}main{max-width:1180px;margin:auto;padding:48px 24px}header{max-width:760px;margin-bottom:28px}.eyebrow{color:#60a5fa;letter-spacing:.16em;font-size:12px}h1{font-size:42px;margin:.2em 0}section{background:#1f2937;border:1px solid #374151;padding:16px;overflow:auto}table{border-collapse:collapse;width:100%}th,td{border:1px solid #4b5563;padding:10px;text-align:left;vertical-align:top}.badge{font-weight:700;text-transform:uppercase}.level-warning .badge{color:#fbbf24}.level-error .badge,.level-fatal .badge{color:#f87171}pre{white-space:pre-wrap;margin:0}.nested{margin-top:10px}form{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}button{background:#2563eb;color:white;border:0;padding:11px 16px;font-weight:700;cursor:pointer}.empty{text-align:center;color:#9ca3af}';
	}
}

echo dataphyre_dpanel_page::bootstrap();
