To generate new language definitions for languages:

foreach($available_languages as $lang=>$values){
	$data=file_get_contents("https://www.localeplanet.com/api/".$lang."/dfs.json");
	$data=json_decode($data, true);
	$result=[];
	$result[$lang]['am']=$data['am_pm'][0];
	$result[$lang]['pm']=$data['am_pm'][1];
	$result[$lang]['abstract']['today']=strtolower(translate("en", $lang, "today"));
	$result[$lang]['abstract']['yesterday']=strtolower(translate("en", $lang, "yesterday"));
	$result[$lang]['abstract']['last week']=strtolower(translate("en", $lang, "last week"));
	$result[$lang]['abstract']['last month']=strtolower(translate("en", $lang, "last month"));
	$result[$lang]['abstract']['last year']=strtolower(translate("en", $lang, "last year"));
	$result[$lang]['abstract']['last decade']=strtolower(translate("en", $lang, "last decade"));
	$result[$lang]['abstract']['last century']=strtolower(translate("en", $lang, "last century"));
	$result[$lang]['abstract']['last millennial']=strtolower(translate("en", $lang, "last millennial"));
	$result[$lang]['months']['january']=array($data['month_name'][0], $data['month_short'][0]);
	$result[$lang]['months']['february']=array($data['month_name'][1], $data['month_short'][1]);
	$result[$lang]['months']['march']=array($data['month_name'][2], $data['month_short'][2]);
	$result[$lang]['months']['april']=array($data['month_name'][3], $data['month_short'][3]);
	$result[$lang]['months']['may']=array($data['month_name'][4], $data['month_short'][4]);
	$result[$lang]['months']['june']=array($data['month_name'][5], $data['month_short'][5]);
	$result[$lang]['months']['july']=array($data['month_name'][6], $data['month_short'][6]);
	$result[$lang]['months']['august']=array($data['month_name'][7], $data['month_short'][7]);
	$result[$lang]['months']['september']=array($data['month_name'][8], $data['month_short'][8]);
	$result[$lang]['months']['october']=array($data['month_name'][9], $data['month_short'][9]);
	$result[$lang]['months']['november']=array($data['month_name'][10], $data['month_short'][10]);
	$result[$lang]['months']['december']=array($data['month_name'][11], $data['month_short'][11]);
	$result[$lang]['weekdays']['sunday']=array($data['day_name'][0], $data['day_short'][0]);
	$result[$lang]['weekdays']['monday']=array($data['day_name'][1], $data['day_short'][1]);
	$result[$lang]['weekdays']['tuesday']=array($data['day_name'][2], $data['day_short'][2]);
	$result[$lang]['weekdays']['wednesday']=array($data['day_name'][3], $data['day_short'][3]);
	$result[$lang]['weekdays']['thursday']=array($data['day_name'][4], $data['day_short'][4]);
	$result[$lang]['weekdays']['friday']=array($data['day_name'][5], $data['day_short'][5]);
	$result[$lang]['weekdays']['saturday']=array($data['day_name'][6], $data['day_short'][6]);
	dataphyre\core::file_put_contents_forced($rootpath['dataphyre']."/config/date_translation/languages/".$lang.".json", json_encode($result));
	dataphyre\core::file_put_contents_forced($rootpath['dataphyre']."/config/date_translation/languages/".$lang.".php", '<?php'.PHP_EOL.' $date_locale='.var_export($result, true).';');
}

To add or change stuff: 

foreach($available_languages as $lang=>$values){
	$data=file_get_contents($rootpath['dataphyre']."/config/date_translation/languages/".$lang.".json");
	$data=json_decode($data, true);
	$data[$lang]['abstract']['today']=strtolower(translate("en", $lang, "today"));
	$data[$lang]['abstract']['at']=strtolower(translate("en", $lang, "at"));
	dataphyre\core::file_put_contents_forced($rootpath['dataphyre']."/config/date_translation/languages/".$lang.".json", json_encode($data));
	dataphyre\core::file_put_contents_forced($rootpath['dataphyre']."/config/date_translation/languages/".$lang.".php", '<?php'.PHP_EOL.' $date_locale='.var_export($data, true).';');
}