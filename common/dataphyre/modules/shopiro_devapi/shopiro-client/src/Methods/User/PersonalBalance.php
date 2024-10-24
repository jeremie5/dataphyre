<?php
namespace Shopiro;

class PersonalBalance {
    
    public static function get() {
        $response = \Shopiro\ShopiroClient::createRequest($endpoint=['get', 'user', 'balance']);
		return self::processResponse($response);
    }
	
}