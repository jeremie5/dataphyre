<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Module initialization');

if(file_exists($filepath=ROOTPATH['common_dataphyre'].'config/api.php')){
	require_once($filepath);
}
if(file_exists($filepath=ROOTPATH['dataphyre'].'config/api.php')){
	require_once($filepath);
}

class api {
}
