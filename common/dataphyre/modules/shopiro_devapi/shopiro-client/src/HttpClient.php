<?php
namespace Shopiro;

class HttpClient {

	public function __construct() {
		
	}

    public function sendRequest($url, string $method='POST', array $data = [], array $headers = [], int $maxRetries=1) {
        $attempt = 0;
        do {
            $response = $this->executeCurl($url, $method, $data, $headers);
            $attempt++;
			if($response===false)usleep(50000);
        } while ($response === false && $attempt < $maxRetries);
        if ($response === false) {
            return false;
        }
        return $response;
    }

	private function executeCurl($url, $method, $data, $headers) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($ch,CURLOPT_FAILONERROR,false);
		$headers[] = 'User-Agent: Shopiro-PHP-Client';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		if (!empty($data)) {
			if ($method === 'POST' || $method === 'PUT') {
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
			}
		}
		$response = curl_exec($ch);
		if(empty($response))return false;
		$curl_info=curl_getinfo($ch);
		file_put_contents(__DIR__."/last_curl_error.txt", json_encode($curl_info).'response:'.$response);
		if($curl_info['http_code']!=200)return false;
		curl_close($ch);
		return $response;
	}
	
}