<?php
namespace Shopiro;

class Address {
	
    private $shopiroClient;
	private $addressFactory;

    public function __construct(ShopiroClient $shopiroClient, AddressFactory $addressFactory) {
        $this->shopiroClient = $shopiroClient;
        $this->addressFactory = $addressFactory;
    }
	
    public function create(string $type, array $data) {
		$addressObject = $this->addressFactory->create($type, $data);
		return $addressObject;
    }
	
    public function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'addresses'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public function get(int $addressId){
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'address'], $payload=["aid" => $addressId]);
		return $response;
	}
	
	public function modify(array $addressData){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_address'], $payload=["act"=>"edit", "adt" => $addressData]);
		return $response;
	}
 
 	public function set_primary(array $addressData){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_address'], $payload=["act"=>"set_primary", "adt" => $addressData]);
		return $response;
	}
	
 	public function delete(array $addressData){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_address'], $payload=["act"=>"rempove", "adt" => $addressData]);
		return $response;
	}
	
}