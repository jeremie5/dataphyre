<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
final class dataphyre_log_viewer {

	private const ENTRY_DELIMITER='<!--ENDLOG-->';
	private const INITIAL_ENTRY_LIMIT=40;
	private const POLL_ENTRY_LIMIT=20;
	private const INITIAL_TAIL_BYTES=131072;
	private const MAX_INITIAL_TAIL_BYTES=1048576;

	public static function dispatch(): void {
		if(self::is_ajax_request()){
			self::render_poll_response();
			return;
		}
		self::render_page();
	}

	private static function is_ajax_request(): bool {
		return ($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST' && (string)($_POST['ajax'] ?? '')==='1';
	}

	private static function render_poll_response(): void {
		header('Content-Type: application/json');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		$info=self::latest_log_file_info();
		if($info===null){
			echo json_encode([
				'ok'=>true,
				'available'=>false,
				'reset'=>true,
				'file_name'=>'',
				'file_key'=>'',
				'offset'=>0,
				'entries'=>[],
				'has_more'=>false,
				'message'=>'No log files found in '.self::log_directory().'.',
			]);
			exit;
		}
		$client_file_key=(string)($_POST['file_key'] ?? '');
		$offset=(int)($_POST['offset'] ?? 0);
		if($offset<0){
			$offset=0;
		}
		if($client_file_key!==$info['key']){
			$recent=self::recent_entries($info);
			echo json_encode([
				'ok'=>true,
				'available'=>true,
				'reset'=>true,
				'file_name'=>$info['name'],
				'file_key'=>$info['key'],
				'offset'=>$recent['offset'],
				'entries'=>$recent['entries'],
				'has_more'=>false,
				'message'=>'',
			]);
			exit;
		}
		$poll=self::poll_entries($info, $offset);
		echo json_encode([
			'ok'=>true,
			'available'=>true,
			'reset'=>false,
			'file_name'=>$info['name'],
			'file_key'=>$info['key'],
			'offset'=>$poll['offset'],
			'entries'=>$poll['entries'],
			'has_more'=>$poll['has_more'],
			'message'=>'',
		]);
		exit;
	}

	private static function render_page(): void {
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		echo <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Dataphyre Failure Logs</title>
	<style>
		:root{
			--page-bg:#0f172a;
			--panel-bg:#f8fafc;
			--panel-border:#cbd5e1;
			--text:#0f172a;
			--muted:#475569;
			--accent:#2563eb;
			--accent-muted:#dbeafe;
			--warning:#f59e0b;
			--danger:#dc2626;
			--success:#15803d;
			--shadow:0 16px 40px rgba(15,23,42,0.18);
		}

		*{
			box-sizing:border-box;
		}

		body{
			margin:0;
			font-family:Segoe UI, Tahoma, Geneva, Verdana, sans-serif;
			background:
				radial-gradient(circle at top left, rgba(37,99,235,0.18), transparent 28rem),
				radial-gradient(circle at top right, rgba(14,165,233,0.12), transparent 26rem),
				var(--page-bg);
			color:var(--text);
		}

		.viewer-shell{
			max-width:1800px;
			margin:0 auto;
			padding:32px 20px 48px;
		}

		.viewer-panel{
			background:var(--panel-bg);
			border:1px solid rgba(148,163,184,0.35);
			border-radius:18px;
			box-shadow:var(--shadow);
			overflow:hidden;
		}

		.viewer-toolbar{
			display:flex;
			flex-wrap:wrap;
			align-items:center;
			justify-content:space-between;
			gap:16px;
			padding:20px 24px;
			background:linear-gradient(135deg, rgba(37,99,235,0.08), rgba(15,23,42,0.02));
			border-bottom:1px solid rgba(148,163,184,0.25);
		}

		.viewer-title{
			margin:0;
			font-size:1.9rem;
			line-height:1.1;
		}

		.viewer-subtitle{
			margin:8px 0 0;
			color:var(--muted);
			font-size:0.97rem;
		}

		.viewer-meta{
			display:flex;
			flex-wrap:wrap;
			gap:12px;
			align-items:center;
			justify-content:flex-end;
		}

		.viewer-badge{
			display:inline-flex;
			align-items:center;
			gap:8px;
			padding:8px 12px;
			border-radius:999px;
			background:#ffffff;
			border:1px solid rgba(148,163,184,0.35);
			color:var(--muted);
			font-size:0.92rem;
		}

		.viewer-badge[data-tone="live"]{
			background:rgba(21,128,61,0.08);
			border-color:rgba(21,128,61,0.2);
			color:var(--success);
		}

		.viewer-badge[data-tone="busy"]{
			background:rgba(37,99,235,0.08);
			border-color:rgba(37,99,235,0.22);
			color:var(--accent);
		}

		.viewer-badge[data-tone="paused"]{
			background:rgba(245,158,11,0.08);
			border-color:rgba(245,158,11,0.24);
			color:#92400e;
		}

		.viewer-badge[data-tone="error"]{
			background:rgba(220,38,38,0.08);
			border-color:rgba(220,38,38,0.22);
			color:var(--danger);
		}

		.viewer-button{
			border:0;
			border-radius:12px;
			padding:10px 16px;
			font-size:0.95rem;
			font-weight:600;
			cursor:pointer;
			color:#fff;
			background:linear-gradient(135deg, #2563eb, #1d4ed8);
			box-shadow:0 10px 24px rgba(37,99,235,0.24);
		}

		.viewer-button:hover{
			filter:brightness(1.03);
		}

		.viewer-content{
			padding:0 24px 24px;
		}

		.log-table{
			width:100%;
			border-collapse:collapse;
			table-layout:fixed;
			background:#fff;
			border:1px solid rgba(148,163,184,0.25);
			border-radius:16px;
			overflow:hidden;
		}

		.log-table thead th{
			position:sticky;
			top:0;
			z-index:1;
			background:#e2e8f0;
			color:#0f172a;
			padding:14px 16px;
			text-align:left;
			font-size:0.9rem;
			letter-spacing:0.02em;
			border-bottom:1px solid rgba(148,163,184,0.35);
		}

		.log-table tbody tr:nth-child(odd){
			background:#ffffff;
		}

		.log-table tbody tr:nth-child(even){
			background:#f8fafc;
		}

		.log-table tbody td{
			padding:14px 16px;
			vertical-align:top;
			border-bottom:1px solid rgba(226,232,240,0.95);
			word-break:break-word;
		}

		.log-table tbody tr:last-child td{
			border-bottom:0;
		}

		.placeholder td{
			color:var(--muted);
			text-align:center;
			padding:28px 16px;
		}

		.card{
			border:1px solid rgba(148,163,184,0.28);
			border-radius:12px;
			overflow:hidden;
			margin-top:12px;
		}

		.bg-light{
			background:#f8fafc;
		}

		.card-header{
			padding:12px 14px;
			font-weight:700;
			background:#e2e8f0;
			border-bottom:1px solid rgba(148,163,184,0.25);
		}

		.card-body{
			padding:14px;
		}

		.card-text{
			margin:0 0 12px;
		}

		.card-text:last-child{
			margin-bottom:0;
		}

		.bg-dark{
			background:#0f172a;
		}

		.text-white{
			color:#fff;
		}

		.p-2{
			padding:12px;
		}

		.mb-3{
			margin-bottom:1rem;
		}

		.log-table pre{
			margin:0;
			font-family:Consolas, Monaco, monospace;
			font-size:0.88rem;
			white-space:pre-wrap;
			word-break:break-word;
			overflow-wrap:anywhere;
		}

		.plain-log-line{
			background:transparent;
			color:#0f172a;
		}

		@media (max-width: 900px){
			.viewer-toolbar{
				padding:18px 18px;
			}

			.viewer-content{
				padding:0 18px 18px;
			}

			.log-table thead{
				display:none;
			}

			.log-table,
			.log-table tbody,
			.log-table tr,
			.log-table td{
				display:block;
				width:100%;
			}

			.log-table tbody td{
				padding:12px 14px;
			}
		}
	</style>
</head>
<body>
	<div class="viewer-shell">
		<div class="viewer-panel">
			<div class="viewer-toolbar">
				<div>
					<h1 class="viewer-title">Dataphyre Failure Logs</h1>
					<p class="viewer-subtitle" id="viewer-file">Looking for the latest log file...</p>
				</div>
				<div class="viewer-meta">
					<span class="viewer-badge" id="viewer-status" data-tone="busy">Connecting...</span>
					<button class="viewer-button" id="toggle-logs" type="button">Pause Logs</button>
				</div>
			</div>
			<div class="viewer-content">
				<table class="log-table">
					<thead>
						<tr>
							<th style="width:180px;">Timestamp</th>
							<th>Error</th>
						</tr>
					</thead>
					<tbody id="log-content">
						<tr class="placeholder">
							<td colspan="2">Waiting for log events...</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<script>
		const state = {
			fileKey: '',
			offset: 0,
			timer: null,
			requestInFlight: false,
			paused: false,
			selecting: false,
			pollInterval: 2000,
			fastPollInterval: 150,
			maxRows: 200,
			hasMore: false,
		};

		const logContent = document.getElementById('log-content');
		const statusBadge = document.getElementById('viewer-status');
		const fileLabel = document.getElementById('viewer-file');
		const toggleButton = document.getElementById('toggle-logs');
		let selectionReleaseTimer = null;

		function setStatus(message, tone){
			statusBadge.textContent = message;
			statusBadge.dataset.tone = tone || 'busy';
		}

		function setFileLabel(message){
			fileLabel.textContent = message;
		}

		function renderPlaceholder(message){
			logContent.innerHTML = '<tr class="placeholder"><td colspan="2"></td></tr>';
			const cell = logContent.querySelector('td');
			if(cell){
				cell.textContent = message;
			}
		}

		function trimRows(){
			while(logContent.children.length > state.maxRows){
				logContent.removeChild(logContent.lastElementChild);
			}
		}

		function renderEntries(entries, replace){
			if(!Array.isArray(entries) || entries.length === 0){
				if(replace){
					renderPlaceholder('Watching the current log file. Waiting for log events...');
				}
				return;
			}

			const html = entries.slice().reverse().join('');
			if(replace){
				logContent.innerHTML = html;
			}
			else{
				const hasPlaceholder = logContent.querySelector('.placeholder') !== null;
				if(hasPlaceholder){
					logContent.innerHTML = '';
				}
				logContent.insertAdjacentHTML('afterbegin', html);
			}

			trimRows();
		}

		function scheduleNextPoll(delay){
			window.clearTimeout(state.timer);
			state.timer = window.setTimeout(fetchLogs, delay);
		}

		function applyResponse(response){
			if(!response || response.ok !== true){
				setStatus('Unexpected log viewer response.', 'error');
				scheduleNextPoll(state.pollInterval);
				return;
			}

			if(response.available !== true){
				if(state.fileKey === ''){
					renderPlaceholder(response.message || 'No log files found yet.');
				}
				setFileLabel(response.message || 'No active log file found.');
				setStatus('Waiting for logs', 'paused');
				scheduleNextPoll(state.pollInterval);
				return;
			}

			const reset = response.reset === true || response.file_key !== state.fileKey;
			state.fileKey = response.file_key || '';
			state.offset = Number(response.offset || 0);
			state.hasMore = response.has_more === true;

			setFileLabel('Watching: ' + (response.file_name || 'Unknown log file'));
			renderEntries(response.entries || [], reset);

			if(state.paused){
				setStatus('Paused', 'paused');
			}
			else if(state.hasMore){
				setStatus('Catching up...', 'busy');
			}
			else{
				setStatus('Live', 'live');
			}

			scheduleNextPoll(state.hasMore ? state.fastPollInterval : state.pollInterval);
		}

		async function fetchLogs(){
			if(state.requestInFlight){
				return;
			}

			if(state.paused){
				setStatus('Paused', 'paused');
				scheduleNextPoll(state.pollInterval);
				return;
			}

			if(state.selecting){
				setStatus('Selection paused live updates', 'paused');
				scheduleNextPoll(state.pollInterval);
				return;
			}

			state.requestInFlight = true;

			try{
				const body = new URLSearchParams();
				body.set('ajax', '1');
				body.set('file_key', state.fileKey);
				body.set('offset', String(state.offset));

				const response = await fetch(window.location.pathname + window.location.search, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
						'X-Requested-With': 'XMLHttpRequest'
					},
					body: body.toString(),
					cache: 'no-store'
				});

				if(!response.ok){
					throw new Error('HTTP ' + response.status);
				}

				const payload = await response.json();
				applyResponse(payload);
			}
			catch(error){
				console.error('Error loading logs.', error);
				setStatus('Unable to load logs right now.', 'error');
				scheduleNextPoll(state.pollInterval);
			}
			finally{
				state.requestInFlight = false;
			}
		}

		toggleButton.addEventListener('click', function (){
			state.paused = !state.paused;
			toggleButton.textContent = state.paused ? 'Resume Logs' : 'Pause Logs';

			if(state.paused){
				setStatus('Paused', 'paused');
				return;
			}

			setStatus('Reconnecting...', 'busy');
			fetchLogs();
		});

		logContent.addEventListener('mousedown', function (){
			state.selecting = true;
		});

		document.addEventListener('mouseup', function (){
			window.clearTimeout(selectionReleaseTimer);
			selectionReleaseTimer = window.setTimeout(function (){
				state.selecting = false;
			}, 1200);
		});

		fetchLogs();
	</script>
</body>
</html>
HTML;
	}

	private static function poll_entries(array $info, int $offset): array {
		if(self::is_html_log($info)){
			return self::poll_html_entries($info, $offset);
		}
		return self::poll_plain_entries($info, $offset);
	}

	private static function recent_entries(array $info): array {
		if(self::is_html_log($info)){
			return self::recent_html_entries($info);
		}
		return self::recent_plain_entries($info);
	}

	private static function recent_html_entries(array $info): array {
		if($info['size']<=0){
			return ['entries'=>[], 'offset'=>0];
		}
		$window=self::tail_window($info['path'], $info['size'], self::INITIAL_ENTRY_LIMIT + 1, self::ENTRY_DELIMITER);
		$entries=self::parse_tail_entries($window['segment'], $window['start']>0);
		if(count($entries)>self::INITIAL_ENTRY_LIMIT){
			$entries=array_slice($entries, -self::INITIAL_ENTRY_LIMIT);
		}
		return [
			'entries'=>array_values($entries),
			'offset'=>self::complete_offset_from_tail($window['segment'], $window['start'], $info['size'], self::ENTRY_DELIMITER),
		];
	}

	private static function recent_plain_entries(array $info): array {
		if($info['size']<=0){
			return ['entries'=>[], 'offset'=>0];
		}
		$window=self::tail_window($info['path'], $info['size'], self::INITIAL_ENTRY_LIMIT + 1, "\n");
		$entries=self::parse_tail_plain_lines($window['segment'], $window['start']>0);
		if(count($entries)>self::INITIAL_ENTRY_LIMIT){
			$entries=array_slice($entries, -self::INITIAL_ENTRY_LIMIT);
		}
		return [
			'entries'=>array_values($entries),
			'offset'=>self::complete_offset_from_tail($window['segment'], $window['start'], $info['size'], "\n"),
		];
	}

	private static function poll_html_entries(array $info, int $offset): array {
		$offset=max(0, min($offset, $info['size']));
		$chunk=self::read_complete_chunk($info['path'], $offset, self::ENTRY_DELIMITER);
		if($chunk===''){
			return ['entries'=>[], 'offset'=>$offset, 'has_more'=>false];
		}
		$selection=self::take_entries_from_complete_html_chunk($chunk, self::POLL_ENTRY_LIMIT);
		return [
			'entries'=>$selection['entries'],
			'offset'=>$offset + $selection['bytes'],
			'has_more'=>$selection['bytes']<strlen($chunk),
		];
	}

	private static function poll_plain_entries(array $info, int $offset): array {
		$offset=max(0, min($offset, $info['size']));
		$chunk=self::read_complete_chunk($info['path'], $offset, "\n");
		if($chunk===''){
			return ['entries'=>[], 'offset'=>$offset, 'has_more'=>false];
		}
		$selection=self::take_entries_from_complete_plain_chunk($chunk, self::POLL_ENTRY_LIMIT);
		return [
			'entries'=>$selection['entries'],
			'offset'=>$offset + $selection['bytes'],
			'has_more'=>$selection['bytes']<strlen($chunk),
		];
	}

	private static function latest_log_file_info(): ?array {
		$directory=self::log_directory();
		if(!is_dir($directory) || !is_readable($directory)){
			return null;
		}
		$latest=null;
		foreach(new DirectoryIterator($directory) as $file){
			if(!$file->isFile() || !$file->isReadable()){
				continue;
			}
			$extension=strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
			if($extension!=='html' && $extension!=='log'){
				continue;
			}
			$current=[
				'path'=>$file->getPathname(),
				'name'=>$file->getFilename(),
				'size'=>(int)$file->getSize(),
				'mtime'=>(int)$file->getMTime(),
				'extension'=>$extension,
			];
			if(
				$latest===null
				|| $current['mtime']>$latest['mtime']
				|| ($current['mtime']===$latest['mtime'] && strcmp($current['name'], $latest['name'])>0)
			){
				$latest=$current;
			}
		}
		if($latest===null){
			return null;
		}

		$latest['key']=self::build_file_key($latest);
		return $latest;
	}

	private static function build_file_key(array $info): string {
		return $info['name'].'|'.$info['mtime'].'|'.$info['size'];
	}

	private static function log_directory(): string {
		return ROOTPATH['dataphyre'].'logs';
	}

	private static function is_html_log(array $info): bool {
		return (string)($info['extension'] ?? '')==='html';
	}

	private static function tail_window(string $path, int $size, int $minimum_breaks, string $break_marker): array {
		$bytes=min($size, self::INITIAL_TAIL_BYTES);
		$start=max(0, $size - $bytes);
		$segment='';
		while(true){
			$start=max(0, $size - $bytes);
			$segment=self::read_bytes($path, $start, $bytes);
			if(
				$start===0
				|| $bytes>=$size
				|| $bytes>=self::MAX_INITIAL_TAIL_BYTES
				|| self::break_count($segment, $break_marker)>=$minimum_breaks
			){
				break;
			}
			$bytes=min($size, $bytes * 2);
		}
		return ['segment'=>$segment, 'start'=>$start];
	}

	private static function break_count(string $segment, string $break_marker): int {
		if($segment===''){
			return 0;
		}
		if($break_marker===self::ENTRY_DELIMITER){
			return substr_count($segment, self::ENTRY_DELIMITER);
		}
		return substr_count($segment, "\n");
	}

	private static function complete_offset_from_tail(string $segment, int $start, int $size, string $break_marker): int {
		if($segment===''){
			return 0;
		}
		if($break_marker===self::ENTRY_DELIMITER){
			if(self::ends_with($segment, self::ENTRY_DELIMITER)){
				return $size;
			}
			$last_break=strrpos($segment, self::ENTRY_DELIMITER);
			return $last_break===false ? $start : $start + $last_break + strlen(self::ENTRY_DELIMITER);
		}
		if(self::ends_with_newline($segment)){
			return $size;
		}
		$last_break=self::last_newline_position($segment);
		return $last_break===null ? $start : $start + $last_break + 1;
	}

	private static function parse_tail_entries(string $segment, bool $drop_leading_partial): array {
		if($segment===''){
			return [];
		}
		$pieces=explode(self::ENTRY_DELIMITER, $segment);
		if($drop_leading_partial && !empty($pieces)){
			array_shift($pieces);
		}
		if(self::ends_with($segment, self::ENTRY_DELIMITER)){
			array_pop($pieces);
		}
		elseif(!empty($pieces)){
			array_pop($pieces);
		}
		$entries=[];
		foreach($pieces as $piece){
			$entry=trim($piece);
			if($entry!==''){
				$entries[]=$entry;
			}
		}
		if(empty($entries) && !$drop_leading_partial){
			$whole_entry=trim($segment);
			if($whole_entry!==''){
				$entries[]=$whole_entry;
			}
		}
		return $entries;
	}

	private static function parse_tail_plain_lines(string $segment, bool $drop_leading_partial): array {
		if($segment===''){
			return [];
		}
		$lines=preg_split("/\r\n|\n|\r/", $segment);
		if($drop_leading_partial && !empty($lines)){
			array_shift($lines);
		}
		if(self::ends_with_newline($segment)){
			array_pop($lines);
		}
		elseif(!empty($lines)){
			array_pop($lines);
		}
		$entries=[];
		foreach($lines as $line){
			if(trim($line)===''){
				continue;
			}
			$entries[]=self::plain_line_row($line);
		}
		if(empty($entries) && !$drop_leading_partial){
			$line=trim($segment);
			if($line!==''){
				$entries[]=self::plain_line_row($line);
			}
		}
		return $entries;
	}

	private static function read_complete_chunk(string $path, int $offset, string $break_marker): string {
		$chunk=self::read_bytes($path, $offset);
		if($chunk===''){
			return '';
		}
		if($break_marker===self::ENTRY_DELIMITER){
			if(self::ends_with($chunk, self::ENTRY_DELIMITER)){
				return $chunk;
			}
			$last_break=strrpos($chunk, self::ENTRY_DELIMITER);
			return $last_break===false ? '' : substr($chunk, 0, $last_break + strlen(self::ENTRY_DELIMITER));
		}
		if(self::ends_with_newline($chunk)){
			return $chunk;
		}
		$last_break=self::last_newline_position($chunk);
		return $last_break===null ? '' : substr($chunk, 0, $last_break + 1);
	}

	private static function take_entries_from_complete_html_chunk(string $chunk, int $limit): array {
		$pieces=explode(self::ENTRY_DELIMITER, $chunk);
		$entries=[];
		$consumed=0;
		$delimiter_length=strlen(self::ENTRY_DELIMITER);
		$total=count($pieces);
		for($index=0; $index<$total; $index++){
			$piece=$pieces[$index];
			if($index===($total - 1) && $piece===''){
				break;
			}
			$piece_bytes=strlen($piece) + $delimiter_length;
			$consumed += $piece_bytes;
			$entry=trim($piece);
			if($entry===''){
				continue;
			}
			$entries[]=$entry;
			if(count($entries)>=$limit){
				break;
			}
		}
		return ['entries'=>$entries, 'bytes'=>$consumed];
	}

	private static function take_entries_from_complete_plain_chunk(string $chunk, int $limit): array {
		$entries=[];
		$cursor=0;
		$length=strlen($chunk);
		while($cursor<$length){
			$newline_position=self::next_newline_position($chunk, $cursor);
			if($newline_position===null){
				break;
			}
			$line=substr($chunk, $cursor, $newline_position['position'] - $cursor);
			$cursor=$newline_position['next_cursor'];
			if(trim($line)===''){
				continue;
			}
			$entries[]=self::plain_line_row($line);
			if(count($entries)>=$limit){
				break;
			}
		}
		return ['entries'=>$entries, 'bytes'=>$cursor];
	}

	private static function next_newline_position(string $chunk, int $cursor): ?array {
		$length=strlen($chunk);
		for($index=$cursor; $index<$length; $index++){
			if($chunk[$index]==="\n"){
				return [
					'position'=>$index,
					'next_cursor'=>$index + 1,
				];
			}
			if($chunk[$index]==="\r"){
				$next_cursor=$index + 1;
				if($next_cursor<$length && $chunk[$next_cursor]==="\n"){
					$next_cursor++;
				}
				return [
					'position'=>$index,
					'next_cursor'=>$next_cursor,
				];
			}
		}

		return null;
	}

	private static function last_newline_position(string $segment): ?int {
		$linefeed=strrpos($segment, "\n");
		$carriage_return=strrpos($segment, "\r");
		if($linefeed===false && $carriage_return===false){
			return null;
		}
		if($linefeed===false){
			return $carriage_return;
		}
		if($carriage_return===false){
			return $linefeed;
		}
		return max($linefeed, $carriage_return);
	}

	private static function ends_with_newline(string $segment): bool {
		if($segment===''){
			return false;
		}
		$last_character=substr($segment, -1);
		return $last_character==="\n" || $last_character==="\r";
	}

	private static function ends_with(string $subject, string $suffix): bool {
		if($suffix===''){
			return true;
		}
		return substr($subject, -strlen($suffix))===$suffix;
	}

	private static function plain_line_row(string $line): string {
		return '<tr><td colspan="2"><pre class="plain-log-line">'.htmlspecialchars($line, ENT_QUOTES, 'UTF-8').'</pre></td></tr>';
	}

	private static function read_bytes(string $path, int $offset, ?int $length=null): string {
		if(!is_file($path) || !is_readable($path)){
			return '';
		}
		if($length===null){
			$contents=@file_get_contents($path, false, null, $offset);
			return is_string($contents) ? $contents : '';
		}
		$contents=@file_get_contents($path, false, null, $offset, $length);
		return is_string($contents) ? $contents : '';
	}
}

dataphyre_log_viewer::dispatch();
