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

dataphyre\core::register_dialback("WEBSITE_EXPERIENCE_FEEDBACK_FORM", function($score, $comment){
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

class aceit_engine{
	
    private static $experiment_list=[];

	public static function import_experiments(array $experiments) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		self::$experiment_list=array_merge($experiments, self::$experiment_list);
	}

	private static function load_experiment_list() : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(empty(self::$experiment_list)){
			$data=file_get_contents(__DIR__."/experimentation_data.json");
			self::$experiment_list=json_decode($data);
		}
	}
	
	private static function save_experiment(string $experiment_name) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(!empty(self::$experiment_list)){
			if(is_callable($save_callback=self::$experiment_list[$experiment_name]['save_callback'])){
				$save_callback(self::$experiment_list[$experiment_name]);
			}
			else
			{
				$data=file_get_contents(__DIR__."/experimentation_data.json", $data);
				$data=json_decode($data,true);
				$data[$experiment_name]=self::$experiment_list[$experiment_name];
				$data=json_encode($data);
				file_put_contents(__DIR__."/experimentation_data.json", $data);
			}
		}
	}
	
	public static function get_group(string $experiment_name) : string {
		if(isset($_SESSION['ongoing_experiments'][$experiment_name])){
			return $_SESSION['ongoing_experiments'][$experiment_name]['group'];
		}
		return "control";
	}

    public static function define_experiment(string $experiment_name, array $experiment_parameters, array $environmental_factors, callable $eligibility_callback, callable $metrification_callback, callable $reporting_callback, string $aggregation="hourly") : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		self::load_experiment_list();
		if(self::$experiment_list[$experiment_name]['start']<time()){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Experiment is not yet started');
			return;
		}
        if(isset(self::$experiment_list[$experiment_name])){
			if(isset(self::$experiment_list[$experiment_name]['is_finished'])){
				$is_finished=true;
			}
			else
			{
				if(isset($experiment_parameters['start'])){
					$experiment_start=$experiment_parameters['start'];
					$period=$experiment_parameters['period'] ?? null;
					if($period && (time()-$experiment_start>$period)){
						$is_finished=true;
					}
				}
				if(isset($experiment_parameters['required_sample_size'])){
					if($experiment_parameters['required_sample_size']<=self::$experiment_list[$experiment_name]['count']){
						$is_finished=true;
					}
				}
				if($is_finished){
					if(null!==$leading_group=self::get_leading_test_group($experiment_name)){
						$reporting_callback($experiment_name, $leading_group);
					}
				}
			}
        }
		else
		{
			self::$experiment_list[$experiment_name]['count']=0;
			if(is_callable($experiment_parameters['save_callback'])){
				self::$experiment_list[$experiment_name]['save_callback']=$experiment_parameters['save_callback'];
			}
			self::save_experiment($experiment_name);
		}
		$group=$eligibility_callback()??"control";
		$_SESSION['ongoing_experiments'][$experiment_name]=[
			'events'=>[],
			'group'=>$group,
			'metrification_callback'=>$metrification_callback, 
			'environmental_factors'=>$environmental_factors
		];
		if($is_finished && !isset(self::$experiment_list[$experiment_name]['is_aggregated'])){
			register_shutdown_function(function()use($experiment_name, $aggregation){
				self::aggregate_experiment($experiment_name, $aggregation);
			});
		}
    }

    public static function metricize($experiment_name) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		self::load_experiment_list();
		if(isset(self::$experiment_list[$experiment_name])){
			if(isset(self::$experiment_list[$experiment_name]['is_finished'])){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Experiment is known as over');
				return true;
			}
			if(isset($_SESSION['ongoing_experiments'][$experiment_name])){
				$segment_identifier=md5(implode('', $environmental_factors));
				if(false===sql_select(
					$S="*",
					$L="dataphyre.aceit_engine_experiments", 
					$P="WHERE segment_identifier=? AND experiment_name=? LIMIT 1",
					$V=[$segment_identifier, $experiment_name]
				) && !isset($_SESSION['ongoing_experiments'][$experiment_name]['submitted'])){
					$metrification=$_SESSION['ongoing_experiments'][$experiment_name]['metrification_callback'];
					$events=$_SESSION['ongoing_experiments'][$experiment_name]['events'];
					if(false!==$score=$metrification($events)){
						$environmental_factors=[];
						for($i=0; $i<5; $i++){
							$environmental_factors["env_factor".($i+1)]=$_SESSION['ongoing_experiments'][$experiment_name]['environmental_factors'][$i];
						}
						sql_insert(
							$L="dataphyre.aceit_engine_experiments", 
							$F=array_merge($environmental_factors, [
								"experiment_name"=>$experiment_name,
								"group"=>$_SESSION['ongoing_experiments'][$experiment_name]['group'],
								"segment_identifier"=>$segment_identifier,
								"events"=>json_encode($events),
								"score"=>$score
							])
						);
						$_SESSION['ongoing_experiments'][$experiment_name]['submitted']=true;
						self::$experiment_list[$experiment_name]['count']++;
						self::save_experiment($experiment_name);
						return true;
					}
					else
					{
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Cannot get score, metrification function returned false', $S='fatal');
					}
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Patient has already been experimented upon for this experiment', $S='warning');
				}
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Experiment is not ongoing', $S='warning');
			}
        }
		else
		{
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Experiment is not defined', $S='warning');
		}
        return false;
    }
	
	public static function aggregate_experiment(string $experiment_name, string $granulation="hourly") : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
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
		foreach($groups as $group){
			$query="SELECT group, $time_granulation_query as time_frame, SUM(score) as total_score, COUNT(*) as total_entries FROM dataphyre.aceit_engine_experiments WHERE experiment_name=? AND group=? GROUP BY group, time_frame";
			$aggregate_result=sql_query($query, [$experiment_name, $group['group']]);
			foreach($aggregate_result as $aggregated_row){
				$query="INSERT INTO dataphyre.aceit_engine_experiments (group, score, experiment_name, is_aggregate) VALUES (?, ?, ?, ?)";
				sql_query($query, [$aggregated_row['group'], $aggregated_row['total_score'], $experiment_name, true]);
			}
			$query="DELETE FROM dataphyre.aceit_engine_experiments WHERE experiment_name=? AND group=?";
			sql_query($query, [$experiment_name, $group['group']]);
		}
		self::$experiment_list[$experiment_name]['is_aggregated']=true;
		self::save_experiment($experiment_name);
	}

	public static function event(string $event_name, mixed $event_value, string ...$experiment_names) : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
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
	
	private static function get_leading_test_group(string $experiment_name) : string|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$query="SELECT group, SUM(score) as total_score FROM dataphyre.aceit_engine_experiments WHERE experiment_name=? GROUP BY group ORDER BY total_score DESC LIMIT 1";
		$result=sql_query($query, [$experiment_name]);
		self::$experiment_list[$experiment_name]['is_finished']=true;
		self::save_experiment($experiment_name);
		if(!empty($result)){
			return $result[0]['group'];
		}
		return null;
	}
	
	public static function chart_experiment(string $experiment_name, ?string $test_group, ?array $parameters) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
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
		$groupedResults=[];
		$dateRange=[];
		if(isset($parameters['start_date'], $parameters['end_date'])){
			$period=new DatePeriod(
				new DateTime($parameters['start_date']),
				new DateInterval('P1D'),
				(new DateTime($parameters['end_date']))->modify('+1 day')
			);
			foreach($period as $date){
				$dateRange[$date->format("Y-m-d")]=0;
			}
		}
		if($result){
			foreach($result as $row){
				$group=$row['group'];
				$date=$row['experiment_date'];
				$score=$row['total_score'];
				if(!isset($groupedResults[$group])){
					$groupedResults[$group]=$dateRange;
				}
				if(array_key_exists($date, $groupedResults[$group])){
					$groupedResults[$group][$date]=$score;
				}
			}
		}
		return $groupedResults;
	}
	
}