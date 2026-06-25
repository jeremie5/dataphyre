<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
/**
 * Delegates locale lookup, formatting, and mutation to the localization kernel.
 *
 * This global helper preserves the historical shorthand API while keeping all
 * locale state and translation resolution inside dataphyre\localization.
 *
 * @param mixed $a Locale key, locale code, or first localization argument.
 * @param mixed $b Optional localization argument.
 * @param mixed $c Optional localization argument.
 * @param mixed $d Optional localization argument.
 * @param mixed $e Optional localization argument.
 * @return mixed Result returned by dataphyre\localization::locale().
 */
if(!function_exists('locale')){
	function locale($a=null, $b=null, $c=null, $d=null, $e=null){ return \dataphyre\localization::locale($a, $b, $c, $d, $e); }
}
/**
 * Translates or formats a localized value through the localization kernel.
 *
 * The double-underscore helper is an alias for locale() so templates and legacy
 * code can use the familiar translation shorthand without owning state.
 *
 * @param mixed $a Translation key or first localization argument.
 * @param mixed $b Optional localization argument.
 * @param mixed $c Optional localization argument.
 * @param mixed $d Optional localization argument.
 * @param mixed $e Optional localization argument.
 * @return mixed Result returned by dataphyre\localization::locale().
 */
if(!function_exists('__')){
	function __($a=null, $b=null, $c=null, $d=null, $e=null){ return \dataphyre\localization::locale($a, $b, $c, $d, $e); }
}
