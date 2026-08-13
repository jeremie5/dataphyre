<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/assets_support.php';

/** Consumes bounded plotting frames and renders the Tracelog graph shell. */
final class dataphyre_tracelog_plotter_page {
	/** @param array<string,mixed> $runtime */
	public static function bootstrap(?bool $dispatch=null, array $runtime=[]): string {
		$dispatch ??=!defined('DATAPHYRE_TRACELOG_PLOTTER_NO_DISPATCH');
		if(!$dispatch){
			return '';
		}
		ini_set('memory_limit', (string)($runtime['memory_limit'] ?? '1024M'));
		$root=(string)($runtime['dataphyre_root'] ?? (defined('ROOTPATH') && is_array(ROOTPATH) ? (ROOTPATH['dataphyre'] ?? '') : ''));
		$path=(string)($runtime['path'] ?? rtrim($root, '/\\').'/cache/tracelog_plotting.dat');
		return self::fromFile($path, $runtime);
	}

	/** @param array<string,mixed> $runtime */
	public static function fromFile(string $path, array $runtime=[]): string {
		$exists=$runtime['exists'] ?? 'is_file';
		$read=$runtime['read'] ?? static fn(string $file): array|false=>@file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
		$remove=$runtime['remove'] ?? static fn(string $file): bool=>@unlink($file);
		if(!is_callable($exists) || !is_callable($read) || !is_callable($remove)){
			throw new LogicException('Tracelog plotter filesystem boundaries must be callable.');
		}
		if(!$exists($path)){
			return self::render([]);
		}
		$lines=$read($path);
		$traces=[];
		$limit=max(1, (int)($runtime['limit'] ?? 10000));
		if(is_array($lines)){
			foreach($lines as $line){
				$decoded=json_decode((string)$line, true);
				if(is_array($decoded) && $decoded!==[]){
					$traces[]=$decoded;
					if(count($traces)>=$limit){
						break;
					}
				}
			}
		}
		if($traces!==[]){
			$remove($path);
		}
		return self::render($traces);
	}

	/** @param list<array<mixed>> $traces */
	public static function render(array $traces): string {
		if($traces===[]){
			return 'No plotting data available. Load a page on the application to generate.';
		}
		$json=json_encode($traces, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
		if(!is_string($json)){
			throw new RuntimeException('Unable to encode Tracelog plotting data.');
		}
		$css=htmlspecialchars(dataphyre_tracelog_asset_url('plotter.css'), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
		$javascript=htmlspecialchars(dataphyre_tracelog_asset_url('plotter.js'), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			.'<title>Dataphyre Tracelog Plotter</title><script src="https://d3js.org/d3.v6.min.js"></script><link rel="stylesheet" href="'.$css.'">'
			.'</head><body><div id="tooltip"></div><svg width="100%" height="100%"></svg>'
			.'<script>window.tracelogData='.$json.';</script><script src="'.$javascript.'" defer></script></body></html>';
	}
}

echo dataphyre_tracelog_plotter_page::bootstrap();
