<?php
namespace Shopiro;

class Affiliate {
    
    public static function get_listing_link() {
        $response = \Shopiro\ShopiroClient::createRequest($endpoint=['get', 'user', 'referral_link']);
		return $response;
    }
	
}