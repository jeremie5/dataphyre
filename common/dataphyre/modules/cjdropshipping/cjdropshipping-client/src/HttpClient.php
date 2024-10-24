<?php
namespace CJ;

class HttpClient {
	
    public static function sendRequest(string $url, string $method='POST', array|string|null $data = [], array $headers = [], int $maxRetries=1) {
        $attempt = 0;
		$response=false;
        do {
            $response = self::executeCurl($url, $method, $data, $headers);
			if($response===false)usleep(550000);
            $attempt++;
        } while ($response === false && $attempt < $maxRetries);
        if ($response === false) {
            throw new \Exception('HTTP request failed after ' . $maxRetries . ' attempts to '.$url.' by '.$method);
        }
        return  json_decode($response, true);
    }

    private static function executeCurl(string $url, string $method, array|string|null $data, array $headers) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		$headers[] = 'User-Agent: CJ-PHP-Client';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (!empty($data)) {
            if ($method === 'GET' || $method==='DELETE') {
                $queryString = is_array($data) ? http_build_query($data) : $data;
                curl_setopt($ch, CURLOPT_URL, $url . '?' . $queryString);
            } else if (in_array($method, ['POST', 'PUT'])) {
                $jsonData = is_string($data) ? $data : json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            }
        }
        $response = curl_exec($ch);
		if(empty($response))return false;
		$curl_info=curl_getinfo($ch);
		if($curl_info['http_code']!=200)return false;
        curl_close($ch);
        return $response;
    }
	
}