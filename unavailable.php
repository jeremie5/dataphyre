<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
if(defined('ROOTPATH') && !empty(ROOTPATH['views']) && is_file(ROOTPATH['views'].'problem.php')){
	require(ROOTPATH['views'].'problem.php');
	exit();
}
http_response_code(503);
echo '<h1>Service unavailable</h1>';
exit();
