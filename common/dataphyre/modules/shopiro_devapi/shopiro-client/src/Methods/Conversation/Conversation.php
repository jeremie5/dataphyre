<?php
namespace Shopiro;

class Conversation {
	
    private $shopiroClient;

    public function __construct(ShopiroClient $shopiroClient) {
        $this->shopiroClient = $shopiroClient;
    }
	
    public function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'conversations'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public function get(int $conversationId){
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'conversation'], $payload=["cov" => $conversationId]);
		return $response;
	}

	public function startConversation(int $userId, int $agentId){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'start_conversation'], $payload=["uid" => $userId, "auid"=>$agentId]);
		return $response;
	}

	public function sendMessage(int $userId, int $agentId, string $msg){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'send_message'], $payload=["uid" => $userId, "auid"=>$agentId, "msg"=>$msg]);
		return $response;
	}

}