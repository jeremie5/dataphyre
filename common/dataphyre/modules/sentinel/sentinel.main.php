 <?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */


// Somewhere in a global namespace...

// This helper function in global namespace is used so the sentinel module can process test dependencies.
// An object is returned so tracelog() can distinguish between function argument logging and sentinel.
function sentinel(?array &$trace=[], ?string $name): object {
	if(isset($name)){
		$trace[$name]=$trace;
	}
	return (object)$trace;
}

function example_function(int $userid){
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // This is an existing mechanism of the framework.
	if(strlen($userid)===8){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='$userid was 8 digits long as expected', $T='info', $A=\sentinel($sentinel, 'USERID_LENGTH_MATCH'));
	}
	else
	{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='$userid was not 8 digits long as expected', $T='fatal', $A=\sentinel($sentinel, 'USERID_LENGTH_MATCH'));
	}
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Returning result'); // This is how non-errors are logged with the framework's tracelog module currently.
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Returning result', $T='info', $A=\sentinel($sentinel)); // Here sentinel() is called even though it's not necessary in order to maintin a convention.
	return $userid;
}

namespace dataphyre;

// Here's a proof of concept for a Sentinel module within the Dataphyre framework.
// This sentinel module would be used as a means of having non-intrusive unit-tests within very large projects and pretty much guaranteeing safe production deployments.
// The way I've envisioned this module, I think it will be extremely powerful combined with the tracelog module which features over-time function edits latency analysis and graphing
// as we grow into a multi-million dollar business which cannot experience downtime and is poised to have unforgiving performance.
// It could be integrated within a deployment / pipeline and require the developer to trigger the tests within the portion of code they worked on.
// If one of the tests has a log of 'fatal' the deployment / submission for review fails. 

// Tracelog() would call a function in the Sentinel module and forward useful info like function arguments, file, line, class function, etc.
// Sentinel, if enabled would see if it knows the test name. If it doesn't it adds it to a local environment database within the project.

class sentinel{
	
	private $known_tests=[];
	
	private function load_tests(){
		$tests=file_get_contents(__DIR__."/known_tests.json");
		$tests=json_decode($tests, true);
		$this->known_tests=$tests;
	}
	
	public static function unload_callback(){
		foreach($this->known_tests as $test){
			if($test['status']!=='not_run'){
				sql_update(
					$L="sentinel_tests",
					$F=[
						"status"=>$test['status']
					]
				);
			}
		}
		file_put_contents(__DIR__."/known_tests.json", json_encode($this->known_tests));
	}
	
	public static function get_status(){
		$result=[];
		foreach($this->known_tests as $test){
			if($test['status']==='not_run'){
				$result['not_run'][]=$test;
			}
			if($test['status']==='failed'){
				$result['failed']=$test;
			}
		}
	}
	
    public static function tracelog_callback(string $file, int $line, string $class, string $function, bool $passed, object $sentinel_trace): void {
		$test_name=$class;
		if(isset($function))$test_name.='_'.$function;
        if(isset($sentinel_trace)){
            $test_name.='_'.end($sentinel_trace);
        }
		if(!isset($this->known_tests[$test_name])){
			$this->register_test($test_name);
		}
		else
		{
			if($passed && $this->known_tests[$test_name]!=='passed'){
				$this->known_tests[$test_name]['status']='passed';
			}
		}
    }
	
    private function register_test(string $test_name): void {
		$this->known_tests[$test_name]=[
			'status'=>'not_run',
			'fatal'=>false,
			'dependencies'=>$dependencies
		];
		sql_insert(
			$L="sentinel_tests",
			$F=[
				"test_name"=>$test_name
			]
		);
    }

}