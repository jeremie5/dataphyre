<?php
namespace CJ;

class CJClient{
	
    private $email;
    private $password;
	
	private static $access_token;
	public static $max_network_retries;
	private static $httpClient;

	const API_BASE_URL="https://developers.cjdropshipping.com/api2.0/v1";
	const MAX_RPS = 3;

    public function __construct(string $email, string $password){
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            throw new \Exception("CJClient requires PHP version 8.0 or higher.");
        }
		if(!file_exists(__DIR__ . '/AccessToken.json')){
			file_put_contents(__DIR__ . '/AccessToken.json', '');
		}
        $this->email = $email;
        $this->password = $password;
		self::$max_network_retries = 3;
		$this->getAccessToken();
    }
	
    private function getAccessToken() {
        $tokenFile = __DIR__ . '/AccessToken.json';
		if (!is_writable($tokenFile)) {
			throw new \Exception('Cannot write to '.$tokenFile);
		}
		if (!is_readable($tokenFile)) {
			throw new \Exception('Cannot read from '.$tokenFile);
		}
        if (file_exists($tokenFile)) {
            $tokenData = json_decode(file_get_contents($tokenFile), true);
			if(!empty($tokenData)){
				$expiryDate = new \DateTime($tokenData['accessTokenExpiryDate']);
				$now = new \DateTime();
				if ($expiryDate > $now) {
				   return self::$access_token = $tokenData['accessToken'];
				}
			}
        }
        $this->requestNewAccessToken();
    }

    private function requestNewAccessToken() {
		self::rateLimit();
        $url = self::API_BASE_URL . '/authentication/getAccessToken';
        $headers = ['Content-Type: application/json'];
        $data = ['email' => $this->email, 'password' => $this->password];
        $response = \CJ\HttpClient::sendRequest($url, 'POST', $data, $headers, self::$max_network_retries);
        if ($response === false) {
            throw new \Exception('Failed to get new access token');
        }
        if ($response['code'] == 200 && $response['result']) {
            $tokenData = $response['data'];
            file_put_contents(__DIR__ . '/AccessToken.json', json_encode($tokenData));
            self::$access_token = $tokenData['accessToken'];
        }
		else
		{
            throw new \Exception('Failed to authenticate with CJ API, error '.$response['code']);
        }
    }
	
    public static function createRequest(string $endpoint_path, $method='POST', ?array $payload=null, callable|null $callback = null) {
		self::rateLimit();
		if(!isset(self::$access_token)){
			throw new \Exception('CJClient class not initialized');
		}
        $url = self::API_BASE_URL . '/' . $endpoint_path;
        $headers = [
            'CJ-Access-Token: '.self::$access_token,
            'Content-Type: application/json'
        ];
        $response =  \CJ\HttpClient::sendRequest($url, $method, $payload, $headers, self::$max_network_retries);
		if($response['message']==='Too much request, QPS limit is 1 time/1second'){
			sleep(1);
			$response =  \CJ\HttpClient::sendRequest($url, $method, $payload, $headers, self::$max_network_retries);
		}
        return self::processResponse($response);
    }
	
	private static function rateLimit() {
		$file = __DIR__.'/request_times.log';
		$now = microtime(true);
		$lastSecond = $now - 1;
		$times = file_exists($file) ? explode("\n", file_get_contents($file)) : [];
		$times = array_filter($times, function($time) use ($lastSecond) {
			return (float)$time > $lastSecond;
		});
		if(count($times) >= self::MAX_RPS) {
			$sleepTime = 1000000 * (1 - (microtime(true) - $lastSecond));
			if ($sleepTime > 0) {
				usleep($sleepTime);
			}
		}
		$times[] = $now;
		file_put_contents($file, implode("\n", $times));
	}
	
    private static function processResponse(?array $response) {
        if ($response['code'] == 200) {
            return [
                'status' => 'success',
                'message' => $response['message'],
                'data' => $response['data']
            ];
        } else {
            return [
                'status' => 'failed',
                'message' => $response['message'],
                'code' => $response['code']
            ];
        }
    }

}