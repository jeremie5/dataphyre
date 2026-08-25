<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Fixed, private post-start release gate for the exact managed application image. */
final class DataphyreApplicationRuntimeReleaseProbe
{
	public const CONTRACT='dataphyre.application_runtime_release_probe.v1';
	public const OUTPUT_MAX_BYTES=2048;
	public const COMMAND_TIMEOUT_SECONDS=30;
	public const WARMUP_REQUEST_COUNT=3;
	public const WARM_REQUEST_COUNT=20;
	public const WARM_P95_BUDGET_MILLISECONDS=750;
	public const CONCURRENT_REQUEST_COUNT=8;
	public const CONCURRENT_BUDGET_MILLISECONDS=3000;
	private const TOTAL_PROBE_BUDGET_MILLISECONDS=25000;
	private const RESPONSE_MAX_BYTES=1048576;
	private const WEB_ADDRESS='tcp://127.0.0.1:8083';
	private const STATUS_ADDRESS='unix:///run/dataphyre/control/runtime.sock';
	private const HEALTH_PATH='/health';

	public static function main(array $arguments): int
	{
		if(PHP_SAPI!=='cli' || count($arguments)!==1 || !is_string($arguments[0] ?? null)
			|| !function_exists('posix_geteuid') || posix_geteuid()!==0) return 64;
		$deadline=hrtime(true)+(self::TOTAL_PROBE_BUDGET_MILLISECONDS*1_000_000);
		$warm=self::emptyWarmEvidence();$concurrent=self::emptyConcurrentEvidence();
		$cadence=self::emptyCadenceEvidence();$failureCode=null;
		try{
			for($index=0;$index<self::WARMUP_REQUEST_COUNT;$index++){
				self::request(self::WEB_ADDRESS,self::HEALTH_PATH,$deadline);
			}
			$latencies=[];
			for($index=0;$index<self::WARM_REQUEST_COUNT;$index++){
				$started=hrtime(true);
				self::request(self::WEB_ADDRESS,self::HEALTH_PATH,$deadline);
				$latencies[]=self::elapsedMilliseconds($started,hrtime(true));
			}
			sort($latencies,SORT_NUMERIC);
			$p95=$latencies[(int)ceil(count($latencies)*0.95)-1];
			$warm=[
				'path'=>self::HEALTH_PATH,'warmup_request_count'=>self::WARMUP_REQUEST_COUNT,
				'request_count'=>self::WARM_REQUEST_COUNT,'successful_count'=>self::WARM_REQUEST_COUNT,
				'p95_milliseconds'=>$p95,'budget_milliseconds'=>self::WARM_P95_BUDGET_MILLISECONDS,
			];
			if($p95>self::WARM_P95_BUDGET_MILLISECONDS) $failureCode='warm_dynamic_budget_exceeded';
		}catch(Throwable){$failureCode='warm_dynamic_unavailable';}

		if($failureCode===null){
			try{
				$concurrent=self::concurrentRequests($deadline);
				if($concurrent['successful_count']!==self::CONCURRENT_REQUEST_COUNT){
					$failureCode='concurrent_dynamic_unavailable';
				}elseif($concurrent['elapsed_milliseconds']>self::CONCURRENT_BUDGET_MILLISECONDS){
					$failureCode='concurrent_dynamic_budget_exceeded';
				}
			}catch(Throwable){$failureCode='concurrent_dynamic_unavailable';}
		}

		if($failureCode===null){
			try{
				$status=self::request(self::STATUS_ADDRESS,'/dataphyre/runtime/status',$deadline);
				$cadence=self::cadenceEvidence($status);
				if($cadence['count']<1 || $cadence['last_result']!=='ok'){
					$failureCode='scheduler_cadence_unproven';
				}
			}catch(Throwable){$failureCode='scheduler_cadence_unavailable';}
		}

		$evidence=[
			'contract'=>self::CONTRACT,'ok'=>$failureCode===null,'warm_dynamic'=>$warm,
			'concurrent_dynamic'=>$concurrent,'scheduler_cadence'=>$cadence,'failure_code'=>$failureCode,
		];
		$encoded=json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		if(strlen($encoded)>self::OUTPUT_MAX_BYTES) return 70;
		fwrite(STDOUT,$encoded."\n");
		return $failureCode===null ? 0 : 70;
	}

	/** @return array{path:string,warmup_request_count:int,request_count:int,successful_count:int,p95_milliseconds:int,budget_milliseconds:int} */
	private static function emptyWarmEvidence(): array
	{
		return [
			'path'=>self::HEALTH_PATH,'warmup_request_count'=>self::WARMUP_REQUEST_COUNT,
			'request_count'=>self::WARM_REQUEST_COUNT,'successful_count'=>0,'p95_milliseconds'=>0,
			'budget_milliseconds'=>self::WARM_P95_BUDGET_MILLISECONDS,
		];
	}

	/** @return array{request_count:int,successful_count:int,elapsed_milliseconds:int,budget_milliseconds:int} */
	private static function emptyConcurrentEvidence(): array
	{
		return [
			'request_count'=>self::CONCURRENT_REQUEST_COUNT,'successful_count'=>0,'elapsed_milliseconds'=>0,
			'budget_milliseconds'=>self::CONCURRENT_BUDGET_MILLISECONDS,
		];
	}

	/** @return array{count:int,last_at:?string,last_result:string,definition_budget_enforced:bool} */
	private static function emptyCadenceEvidence(): array
	{
		return ['count'=>0,'last_at'=>null,'last_result'=>'never','definition_budget_enforced'=>true];
	}

	/** @return array{request_count:int,successful_count:int,elapsed_milliseconds:int,budget_milliseconds:int} */
	private static function concurrentRequests(int $absoluteDeadline): array
	{
		$started=hrtime(true);$streams=[];$responses=[];
		$request=self::requestBytes(self::HEALTH_PATH);
		try{
			for($index=0;$index<self::CONCURRENT_REQUEST_COUNT;$index++){
				$remaining=self::remainingSeconds($absoluteDeadline,self::CONCURRENT_BUDGET_MILLISECONDS/1000);
				$stream=@stream_socket_client(self::WEB_ADDRESS,$errno,$error,$remaining,STREAM_CLIENT_CONNECT);
				if(!is_resource($stream)) throw new RuntimeException('Concurrent application connection failed.');
				stream_set_blocking($stream,true);stream_set_timeout($stream,0,500000);
				self::writeAll($stream,$request);stream_socket_shutdown($stream,STREAM_SHUT_WR);
				stream_set_blocking($stream,false);$id=(int)$stream;
				$streams[$id]=$stream;$responses[$id]='';
			}
			$concurrentDeadline=min(
				$absoluteDeadline,$started+(self::CONCURRENT_BUDGET_MILLISECONDS*1_000_000),
			);
			while($streams!==[] && hrtime(true)<$concurrentDeadline){
				$read=array_values($streams);$write=[];$except=[];
				$remainingNanoseconds=max(0,$concurrentDeadline-hrtime(true));
				$seconds=intdiv($remainingNanoseconds,1_000_000_000);
				$microseconds=intdiv($remainingNanoseconds%1_000_000_000,1000);
				$selected=@stream_select($read,$write,$except,$seconds,$microseconds);
				if($selected===false) throw new RuntimeException('Concurrent application select failed.');
				foreach($read as $stream){
					$id=(int)$stream;$chunk=@fread($stream,65536);
					if($chunk===false) throw new RuntimeException('Concurrent application read failed.');
					$responses[$id].=$chunk;
					if(strlen($responses[$id])>self::RESPONSE_MAX_BYTES) throw new RuntimeException('Application response exceeded its bound.');
					if(feof($stream)){fclose($stream);unset($streams[$id]);}
				}
			}
			$elapsed=self::elapsedMilliseconds($started,hrtime(true));$successful=0;
			if($streams===[]){
				foreach($responses as $response){self::validatedResponse($response);$successful++;}
			}
			return [
				'request_count'=>self::CONCURRENT_REQUEST_COUNT,'successful_count'=>$successful,
				'elapsed_milliseconds'=>$elapsed,'budget_milliseconds'=>self::CONCURRENT_BUDGET_MILLISECONDS,
			];
		}finally{
			foreach($streams as $stream) if(is_resource($stream)) fclose($stream);
		}
	}

	private static function request(string $address,string $path,int $absoluteDeadline): string
	{
		$timeout=self::remainingSeconds($absoluteDeadline,2.0);
		$stream=@stream_socket_client($address,$errno,$error,$timeout,STREAM_CLIENT_CONNECT);
		if(!is_resource($stream)) throw new RuntimeException('Application probe connection failed.');
		try{
			$seconds=max(1,(int)ceil($timeout));stream_set_timeout($stream,$seconds,0);
			self::writeAll($stream,self::requestBytes($path));stream_socket_shutdown($stream,STREAM_SHUT_WR);
			$response='';
			while(!feof($stream)){
				if(hrtime(true)>=$absoluteDeadline) throw new RuntimeException('Application probe deadline expired.');
				$chunk=fread($stream,65536);
				if($chunk===false) throw new RuntimeException('Application probe read failed.');
				if($chunk===''){
					$metadata=stream_get_meta_data($stream);
					if(($metadata['timed_out'] ?? false)===true) throw new RuntimeException('Application probe timed out.');
					continue;
				}
				$response.=$chunk;
				if(strlen($response)>self::RESPONSE_MAX_BYTES) throw new RuntimeException('Application response exceeded its bound.');
			}
			return self::validatedResponse($response);
		}finally{fclose($stream);}
	}

	private static function requestBytes(string $path): string
	{
		if(!in_array($path,[self::HEALTH_PATH,'/dataphyre/runtime/status'],true)){
			throw new RuntimeException('Application probe path is invalid.');
		}
		return "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n";
	}

	private static function validatedResponse(string $response): string
	{
		[$head,$body]=array_pad(explode("\r\n\r\n",$response,2),2,null);
		$lines=is_string($head) ? explode("\r\n",$head) : [];
		if(!is_string($head) || !is_string($body)
			|| preg_match('/^HTTP\/1\.[01] 200 [^\r\n]{1,64}$/D',(string)($lines[0] ?? ''))!==1){
			throw new RuntimeException('Application probe response is invalid.');
		}
		if(preg_match('/(?:^|\r\n)Content-Length:\s*([0-9]+)(?:\r\n|$)/iD',$head,$match)===1
			&& (int)$match[1]!==strlen($body)){
			throw new RuntimeException('Application probe response length is invalid.');
		}
		return $body;
	}

	/** @return array{count:int,last_at:?string,last_result:string,definition_budget_enforced:bool} */
	private static function cadenceEvidence(string $body): array
	{
		$decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR);
		$cadence=is_array($decoded) ? ($decoded['business_cadence'] ?? null) : null;
		if(!is_array($decoded) || ($decoded['contract'] ?? null)!=='dataphyre.application_runtime.v7'
			|| ($decoded['active'] ?? null)!==true || !is_array($cadence)
			|| array_keys($cadence)!==['count','last_at','last_result']
			|| !is_int($cadence['count'] ?? null) || $cadence['count']<0
			|| !in_array($cadence['last_result'] ?? null,['never','ok','failed'],true)
			|| !(($cadence['count']===0 && $cadence['last_at']===null && $cadence['last_result']==='never')
				|| ($cadence['count']>0 && is_string($cadence['last_at'])
					&& preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$cadence['last_at'])===1
					&& in_array($cadence['last_result'],['ok','failed'],true)))){
			throw new RuntimeException('Scheduler cadence evidence is invalid.');
		}
		return [
			'count'=>$cadence['count'],'last_at'=>$cadence['last_at'],'last_result'=>$cadence['last_result'],
			'definition_budget_enforced'=>true,
		];
	}

	private static function remainingSeconds(int $absoluteDeadline,float $maximum): float
	{
		$remaining=($absoluteDeadline-hrtime(true))/1_000_000_000;
		if($remaining<=0) throw new RuntimeException('Application release probe deadline expired.');
		return max(0.001,min($maximum,$remaining));
	}

	private static function elapsedMilliseconds(int $started,int $completed): int
	{
		if($completed<$started) throw new RuntimeException('Application probe clock moved backwards.');
		return (int)ceil(($completed-$started)/1_000_000);
	}

	private static function writeAll(mixed $stream,string $bytes): void
	{
		$offset=0;
		while($offset<strlen($bytes)){
			$written=fwrite($stream,substr($bytes,$offset));
			if(!is_int($written) || $written<1) throw new RuntimeException('Application probe write failed.');
			$offset+=$written;
		}
	}

}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	exit(DataphyreApplicationRuntimeReleaseProbe::main($argv ?? []));
}
