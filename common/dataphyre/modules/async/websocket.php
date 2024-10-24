<?php
/*************************************************************************
*  Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, ifany. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd.
*/

namespace dataphyre\async;

class web_socket_server{
	
	protected $address;
	protected $port;
	protected $clients;
	protected $sockets;
	protected $callbacks;

	public function __construct($address, $port){
		$this->address=$address;
		$this->port=$port;
		$this->clients=[];
		$this->sockets=[];
		$this->callbacks=[
			'connect'=>null,
			'message'=>null,
			'disconnect'=>null
		];
	}

	public function on($event, $callback){
		if(array_key_exists($event, $this->callbacks)){
			$this->callbacks[$event]=$callback;
		}
	}

	public function start(){
		$socket=socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_bind($socket, $this->address, $this->port);
		socket_listen($socket);
		$this->sockets[]=$socket;
		while(true){
			$changed=$this->sockets;
			socket_select($changed, $null, $null, 0, 10);
			foreach($changed as $sock){
				if($sock == $socket){
					$new_socket=socket_accept($socket);
					$this->clients[]=$new_socket;
					$this->sockets[]=$new_socket;
					$this->handshake($new_socket);
					if($this->callbacks['connect']){
						call_user_func($this->callbacks['connect'], $new_socket);
					}
				}
				else
				{
					$buffer='';
					while(@socket_recv($sock, $buffer, 2048, 0) >= 1){
						$this->broadcast($sock, $buffer);
						if($this->callbacks['message']){
							call_user_func($this->callbacks['message'], $sock, $buffer);
						}
						break 2;
					}
					$buffer=@socket_read($sock, 2048, PHP_NORMAL_READ);
					if($buffer===false){
						socket_close($sock);
						unset($this->clients[array_search($sock, $this->clients)]);
						unset($this->sockets[array_search($sock, $this->sockets)]);
						if($this->callbacks['disconnect']){
							call_user_func($this->callbacks['disconnect'], $sock);
						}
					}
				}
			}
		}
	}

	private function handshake($client){
		$headers=[];
		$lines=preg_split("/\r\n/", socket_read($client, 1024));
		foreach($lines as $line){
			if(strpos($line, ":") !== false){
				list($key, $value)=explode(":", $line);
				$headers[strtolower(trim($key))]=trim($value);
			} elseif(stripos($line, "GET") !== false){
				$headers['get']=$line;
			}
		}
		$sec_key=$headers['sec-websocket-key'];
		$sec_accept=base64_encode(pack('H*', sha1($sec_key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		$upgrade="HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
				   "Upgrade: websocket\r\n" .
				   "Connection: Upgrade\r\n" .
				   "WebSocket-Origin: $this->address\r\n" .
				   "WebSocket-Location: ws://$this->address:$this->port/demo/shout.php\r\n" .
				   "Sec-WebSocket-Accept: $sec_accept\r\n\r\n";
		socket_write($client, $upgrade, strlen($upgrade));
	}

	private function broadcast($client, $msg){
		$msg=$this->unmask($msg);
		foreach($this->clients as $other_client){
			if($client !== $other_client){
				$encoded_message=$this->mask($msg);
				socket_write($other_client, $encoded_message, strlen($encoded_message));
			}
		}
	}

	private function unmask($payload){
		$length=ord($payload[1]) & 127;
		if($length == 126){
			$masks=substr($payload, 4, 4);
			$data=substr($payload, 8);
		} elseif($length == 127){
			$masks=substr($payload, 10, 4);
			$data=substr($payload, 14);
		} else {
			$masks=substr($payload, 2, 4);
			$data=substr($payload, 6);
		}
		$text='';
		for($i=0; $i < strlen($data); ++$i){
			$text .= $data[$i] ^ $masks[$i % 4];
		}
		return $text;
	}

	private function mask($text){
		$b1=0x80 | (0x1 & 0x0f);
		$length=strlen($text);
		if($length <= 125){
			$header=pack('CC', $b1, $length);
		} elseif($length > 125 && $length < 65536){
			$header=pack('CCn', $b1, 126, $length);
		} elseif($length >= 65536){
			$header=pack('CCNN', $b1, 127, $length);
		}
		return $header.$text;
	}
	
}