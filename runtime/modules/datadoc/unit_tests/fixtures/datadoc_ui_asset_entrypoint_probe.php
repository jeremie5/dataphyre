<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class routing { public static array $bindings=[]; }
}

namespace {
	$assets=(string)($argv[1] ?? '');
	$mode=(string)($argv[2] ?? '');
	if($assets==='' || $mode===''){
		throw new InvalidArgumentException('DataDoc asset entrypoint and mode are required.');
	}
	require_once dirname($assets).'/assets_support.php';
	$_GET=[]; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Standalone DataDoc asset fixture must model the native asset query boundary."
	$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/dataphyre/datadoc/assets/datadoc-ui.css']; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Standalone DataDoc asset fixture must model the native HTTP request boundary."
	if($mode==='missing'){
		$_GET['asset']='missing.txt'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Asset fixture must exercise an unknown native asset request."
	}elseif($mode==='head'){
		$_GET['asset']='datadoc-ui.css'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Asset fixture must select the requested native asset."
		$_SERVER['REQUEST_METHOD']='HEAD'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Asset fixture must exercise the native HEAD request boundary."
	}elseif($mode==='etag'){
		$body=dataphyre_datadoc_ui_asset_content('datadoc-ui.css')['content'];
		$_GET['asset']='datadoc-ui.css'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Asset fixture must select the requested native asset."
		$_SERVER['HTTP_IF_NONE_MATCH']='"'.hash('sha256','datadoc-ui.css|'.$body).'"'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Asset fixture must exercise native conditional request headers."
	}elseif($mode==='modified'){
		$_GET['asset']='datadoc-ui.css'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Asset fixture must select the requested native asset."
		$_SERVER['HTTP_IF_MODIFIED_SINCE']='Wed, 01 Jan 2031 00:00:00 GMT'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Asset fixture must exercise native conditional request headers."
	}elseif($mode==='binding'){
		\dataphyre\routing::$bindings=['asset'=>'datadoc-ui.css'];
	}
	require $assets;
}
