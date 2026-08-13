<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_module_required('sql', 'aceit_engine');
if(function_exists('sql_define_table')){
	sql_define_table('dataphyre.aceit_engine_experiments', __DIR__.'/aceit_engine.tables.php', 'experiments');
}

/*
* Example usage of AceItEngine
*
dataphyre\aceit_engine::define_experiment(
	$experiment_name="exp_larger_product_title_font", 
	$parameters=[
		"start"=>strtotime("2024-01-31 08:30"),
		"period"=>strtotime("7 days", 0),
		"required_sample_size"=>1,
		"save_callback"=>function($experiment){
			global $userid;
			$userdata=user::get($userid);
			$userdata['experiments'][array_key_first($experiment)]=$experiment;
			$experiments=json_encode($userdata['experiments']);
			sql_update(
				$L="users",
				$F="experiments=?",
				$P="WHERE userid=?",
				$V=[$experiments, $userid],
				$CC=false
			);
		}
	],
	$env_factors=[
		"useragent"=>$useragent,
		"userid"=>$userid
	],
	$eligibility=function(){
		global $useragent;
		if(str_contains($useragent, "mobile")){
			return"mobile_device";
		}
		return"control";
	},
	$metrification=function($events, $score, $comment){
		return $score/5;
	},
	$reporting=function($experiment_name, $leading_group){
		email::create(config("app/webmaster_email"), "plain", [
			"subject"=>"Results for experiment \"$experiment_name\" are available.",
			"body"=>"The leading test group was determined to be \"$leading_group\"."
		]);
	},
	$aggregation="hourly"
);

dataphyre\core::register_dialback("CALL_ACEIT_ENGINE_WEBSITE_EXPERIENCE_FEEDBACK_FORM", function($score, $comment){
	dataphyre\aceit_engine::metricize("exp_larger_product_title_font", $score, $comment);
	return null;
});

switch(dataphyre\aceit_engine::get_group("exp_larger_product_title_font")){
	case"mobile_device":
		$font_size="12px";
		break;
	default:
	$font_size:"14px";
}

*/

/**
 * Coordinates Dataphyre's lightweight experimentation and A/B scoring lifecycle.
 *
 * AceIt experiments are defined in process, assigned into the current
 * session, measured through event lists and metrification callbacks, persisted in
 * the SQL experiment table, and eventually aggregated/reported when duration or
 * sample-size thresholds are met.
 */
class aceit_engine{
	
    private static $experiment_list=[];

	/**
	 * Imports experiment definitions ahead of lazy JSON-backed loading.
	 *
	 * imported experiments are prepended into the in-memory definition
	 * registry, allowing application bootstrap or user-storage callbacks to
	 * override bundled experimentation_data.json definitions for the current
	 * request lifecycle.
	 */
	public static function import_experiments(array $experiments) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		self::$experiment_list=array_merge($experiments, self::$experiment_list);
	}

	/**
	 * Lazily hydrates experiment definitions from disk.
	 *
	 * the static experiment registry is loaded only when empty, preserving
	 * imported runtime definitions. The JSON file acts as the fallback persistence
	 * surface when an experiment does not supply its own save callback.
	 */
	private static function load_experiment_list(?callable $reader=null) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(empty(self::$experiment_list)){
			$reader??=static fn(string $path): string|false=>is_file($path) ? file_get_contents($path) : false;
			$data=$reader(__DIR__."/experimentation_data.json");
			$decoded=is_string($data) ? json_decode($data, true) : null;
			self::$experiment_list=is_array($decoded) ? $decoded : [];
		}
	}
	
	/**
	 * Persists one experiment definition through its configured storage strategy.
	 *
	 * experiments may provide a save_callback to write into user or
	 * application state. Without a callback, the module rewrites the local JSON
	 * registry so count, finished, and aggregated flags survive later requests.
	 */
	private static function save_experiment(string $experiment_name, ?callable $reader=null, ?callable $writer=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(!isset(self::$experiment_list[$experiment_name])){
			return false;
		}
		$experiment=self::$experiment_list[$experiment_name];
		$saveCallback=$experiment['save_callback'] ?? null;
		if(is_callable($saveCallback)){
			$saveCallback($experiment);
			return true;
		}
		$path=__DIR__."/experimentation_data.json";
		$reader??=static fn(string $file): string|false=>is_file($file) ? file_get_contents($file) : false;
		$writer??=static fn(string $file, string $data): int|false=>file_put_contents($file, $data);
		$stored=$reader($path);
		$stored=is_string($stored) ? json_decode($stored, true) : [];
		$stored=is_array($stored) ? $stored : [];
		unset($experiment['save_callback']);
		$stored[$experiment_name]=$experiment;
		$encoded=json_encode($stored);
		return is_string($encoded) && $writer($path, $encoded)!==false;
	}
	
	/**
	 * Returns the session-assigned group for an experiment.
	 *
	 * group membership is request/session scoped and established by
	 * define_experiment. Missing assignments fall back to control so callers can
	 * branch safely even when a visitor is ineligible or the experiment was not
	 * initialized.
	 */
	public static function get_group(string $experiment_name) : string {
		if(isset($_SESSION['ongoing_experiments'][$experiment_name])){
			return $_SESSION['ongoing_experiments'][$experiment_name]['group'];
		}
		return "control";
	}

	/**
	 * Defines or resumes an experiment and assigns the current session to a group.
	 *
	 * definition loads persisted state, checks start time and completion
	 * thresholds, optionally reports a leading group, initializes new experiments,
	 * stores callbacks/environmental factors in session state, and schedules
	 * aggregation at shutdown when finished results still need compaction.
	 */
    public static function define_experiment(
		string $experiment_name,
		array $experiment_parameters,
		array $environmental_factors,
		callable $eligibility_callback,
		callable $metrification_callback,
		callable $reporting_callback,
		string $aggregation="hourly",
		array $runtime=[]
	) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		self::load_experiment_list($runtime['read_experiments'] ?? null);
		$now=(int)(isset($runtime['clock']) ? $runtime['clock']() : time());
		$existing=self::$experiment_list[$experiment_name] ?? null;
		$start=(int)($experiment_parameters['start'] ?? ($existing['start'] ?? 0));
		if($start>$now){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Experiment is not yet started');
			return;
		}
		$isFinished=(bool)($existing['is_finished'] ?? false);
		if(is_array($existing)){
			$period=(int)($experiment_parameters['period'] ?? ($existing['period'] ?? 0));
			$requiredSampleSize=(int)($experiment_parameters['required_sample_size'] ?? ($existing['required_sample_size'] ?? 0));
			$isFinished=$isFinished
				|| ($period>0 && $now-$start>$period)
				|| ($requiredSampleSize>0 && $requiredSampleSize<=(int)($existing['count'] ?? 0));
			if($isFinished){
				$leadingGroup=isset($runtime['leading_group'])
					? $runtime['leading_group']($experiment_name)
					: self::get_leading_test_group($experiment_name);
				if($leadingGroup!==null){
					$reporting_callback($experiment_name, $leadingGroup);
				}
			}
		}else{
			self::$experiment_list[$experiment_name]=[
				...$experiment_parameters,
				'count'=>0,
			];
			self::save_experiment(
				$experiment_name,
				$runtime['read_experiments'] ?? null,
				$runtime['write_experiments'] ?? null
			);
		}
		$group=$eligibility_callback()??"control";
		$_SESSION['ongoing_experiments'][$experiment_name]=[
			'events'=>[],
			'group'=>$group,
			'metrification_callback'=>$metrification_callback,
			'environmental_factors'=>array_values($environmental_factors),
		];
		if($isFinished && empty(self::$experiment_list[$experiment_name]['is_aggregated'])){
			$aggregate=$runtime['aggregate'] ?? [self::class, 'aggregate_experiment'];
			$registerShutdown=$runtime['register_shutdown'] ?? 'register_shutdown_function';
			$registerShutdown(static fn()=>$aggregate($experiment_name, $aggregation));
		}
    }

	/**
	 * Submits the current session's experiment score once.
	 *
	 * metricization prevents duplicate submissions per experiment segment,
	 * runs the session's metrification callback over captured events, stores up to
	 * five environmental factors plus score/group metadata in SQL, increments the
	 * experiment sample count, and persists definition state.
	 */
    public static function metricize(string $experiment_name, array $runtime=[]) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		self::load_experiment_list($runtime['read_experiments'] ?? null);
		$experiment=self::$experiment_list[$experiment_name] ?? null;
		if(!is_array($experiment)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Experiment is not defined', $S='warning');
			return false;
		}
		if(!empty($experiment['is_finished'])){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Experiment is known as over');
			return true;
		}
		$ongoing=$_SESSION['ongoing_experiments'][$experiment_name] ?? null;
		if(!is_array($ongoing)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Experiment is not ongoing', $S='warning');
			return false;
		}
		$environmentalFactors=array_values((array)($ongoing['environmental_factors'] ?? []));
		$segmentIdentifier=md5((string)json_encode($environmentalFactors));
		$alreadyStored=false!==sql_select(
			$S="*",
			$L="dataphyre.aceit_engine_experiments",
			$P="WHERE segment_identifier=? AND experiment_name=? LIMIT 1",
			$V=[$segmentIdentifier, $experiment_name]
		);
		if($alreadyStored || !empty($ongoing['submitted'])){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Patient has already been experimented upon for this experiment', $S='warning');
			return false;
		}
		$metrification=$ongoing['metrification_callback'] ?? null;
		$events=(array)($ongoing['events'] ?? []);
		$score=is_callable($metrification) ? $metrification($events) : false;
		if($score===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Cannot get score, metrification function returned false', $S='fatal');
			return false;
		}
		$factorColumns=[];
		for($i=0; $i<5; $i++){
			$factorColumns["env_factor".($i+1)]=$environmentalFactors[$i] ?? null;
		}
		$inserted=sql_insert(
			$L="dataphyre.aceit_engine_experiments",
			$F=array_merge($factorColumns, [
				"experiment_name"=>$experiment_name,
				"group"=>(string)($ongoing['group'] ?? 'control'),
				"segment_identifier"=>$segmentIdentifier,
				"events"=>json_encode($events),
				"score"=>$score,
			])
		);
		if($inserted===false){
			return false;
		}
		$_SESSION['ongoing_experiments'][$experiment_name]['submitted']=true;
		self::$experiment_list[$experiment_name]['count']=(int)(self::$experiment_list[$experiment_name]['count'] ?? 0)+1;
		self::save_experiment(
			$experiment_name,
			$runtime['read_experiments'] ?? null,
			$runtime['write_experiments'] ?? null
		);
		return true;
    }
	
	/**
	 * Compacts raw experiment rows into aggregate score rows.
	 *
	 * aggregation groups experiment scores by group and hourly/daily
	 * timeframe, inserts aggregate rows, removes the raw rows for each group, marks
	 * the experiment aggregated, and persists that lifecycle flag.
	 */
	public static function aggregate_experiment(string $experiment_name, string $granulation="hourly") : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		$time_granulation_query="";
		switch($granulation){
			case"hourly":
				$time_granulation_query="DATE_FORMAT(experiment_date, '%Y-%m-%d %H:00:00')";
				break;
			case"daily":
				$time_granulation_query="DATE_FORMAT(experiment_date, '%Y-%m-%d')";
				break;
			default:
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Unknown aggregation granulation', $S='fatal');
				return;
		}
		$query="SELECT DISTINCT group FROM dataphyre.aceit_engine_experiments WHERE experiment_name=?";
		$groups=sql_query($query, [$experiment_name]);
		if(!is_iterable($groups)){
			return;
		}
		foreach($groups as $group){
			$query="SELECT group, $time_granulation_query as time_frame, SUM(score) as total_score, COUNT(*) as total_entries FROM dataphyre.aceit_engine_experiments WHERE experiment_name=? AND group=? GROUP BY group, time_frame";
			$aggregate_result=sql_query($query, [$experiment_name, $group['group']]);
			if(is_iterable($aggregate_result)){
				foreach($aggregate_result as $aggregated_row){
					$query="INSERT INTO dataphyre.aceit_engine_experiments (group, score, experiment_name, is_aggregate) VALUES (?, ?, ?, ?)";
					sql_query($query, [$aggregated_row['group'], $aggregated_row['total_score'], $experiment_name, true]);
				}
			}
			$query="DELETE FROM dataphyre.aceit_engine_experiments WHERE experiment_name=? AND group=?";
			sql_query($query, [$experiment_name, $group['group']]);
		}
		if(isset(self::$experiment_list[$experiment_name])){
			self::$experiment_list[$experiment_name]['is_aggregated']=true;
			self::save_experiment($experiment_name);
		}
	}

	/**
	 * Records an interaction event for one or more active experiments.
	 *
	 * events are stored in the session until metricize runs, preserving a
	 * timestamped name/value list for the metrification callback. Unknown or
	 * inactive experiment names are ignored so shared event emitters can broadcast
	 * without per-experiment guards.
	 */
	public static function event(string $event_name, mixed $event_value, string ...$experiment_names) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		foreach($experiment_names as $experiment_name){
			if(isset($_SESSION['ongoing_experiments'][$experiment_name])){
				$_SESSION['ongoing_experiments'][$experiment_name]['events'][]=[
					'name'=>$event_name, 
					'time'=>microtime(true), 
					'value'=>$event_value
				];
			}
		}
	}
	
	/**
	 * Determines and persists the currently leading experiment group.
	 *
	 * the winning group is selected by descending summed score from SQL.
	 * Calling this method also marks the experiment finished and saves that state
	 * before returning the leading group name or null when no scored rows exist.
	 */
	private static function get_leading_test_group(string $experiment_name) : string|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		$query="SELECT group, SUM(score) as total_score FROM dataphyre.aceit_engine_experiments WHERE experiment_name=? GROUP BY group ORDER BY total_score DESC LIMIT 1";
		$result=sql_query($query, [$experiment_name]);
		self::$experiment_list[$experiment_name]['is_finished']=true;
		self::save_experiment($experiment_name);
		if(!empty($result)){
			return $result[0]['group'];
		}
		return null;
	}
	
	/**
	 * Builds chart-ready score series for an experiment.
	 *
	 * chart data is grouped by test group and day, optionally narrowed to
	 * one group and/or a date range. When a range is provided, missing dates are
	 * prefilled with zero so consumers can render continuous time-series charts.
	 */
	public static function chart_experiment(string $experiment_name, ?string $test_group, ?array $parameters) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		$query="SELECT `group`, DATE(date_column) as experiment_date, SUM(score) as total_score ";
		$query.="FROM dataphyre.aceit_engine_experiments WHERE experiment_name=?";
		$params=[$experiment_name];
		if($test_group!==null){
			$query.=" AND `group`=?";
			$params[]=$test_group;
		}
		if(isset($parameters['start_date'], $parameters['end_date'])){
			$query.=" AND DATE(date_column) BETWEEN ? AND ?";
			$params[]=$parameters['start_date'];
			$params[]=$parameters['end_date'];
		}
		$query.=" GROUP BY `group`, experiment_date ORDER BY `group`, experiment_date";
		$result=sql_query($query, $params);
		$grouped_results=[];
		$date_range=[];
		if(isset($parameters['start_date'], $parameters['end_date'])){
			$period=new DatePeriod(
				new DateTime($parameters['start_date']),
				new DateInterval('P1D'),
				(new DateTime($parameters['end_date']))->modify('+1 day')
			);
			foreach($period as $date){
				$date_range[$date->format("Y-m-d")]=0;
			}
		}
		if($result){
			foreach($result as $row){
				$group=$row['group'];
				$date=$row['experiment_date'];
				$score=$row['total_score'];
				if(!isset($grouped_results[$group])){
					$grouped_results[$group]=$date_range;
				}
				if($date_range===[] || array_key_exists($date, $grouped_results[$group])){
					$grouped_results[$group][$date]=$score;
				}
			}
		}
		return $grouped_results;
	}
	
}
