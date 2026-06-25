<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\async;

/**
 * Minimal socket-based WebSocket broadcast server.
 *
 * The server accepts TCP clients, completes the WebSocket upgrade handshake,
 * broadcasts text frames to other connected clients, and exposes lifecycle
 * callbacks for connect, message, and disconnect events. It is a raw socket
 * helper: it does not provide TLS termination, authentication, origin checks,
 * subprotocol negotiation, ping/pong management, or backpressure handling.
 */
class web_socket_server{
	
	protected $address;
	protected $port;
	protected $clients;
	protected $sockets;
	protected $callbacks;

	/**
	 * Initializes the listening address, port, client lists, and callback table.
	*
	 * Address and port are stored without validation and passed directly to
	 * `socket_bind()` when `start()` opens the listening socket.
	 *
	 * @param string $address Interface address passed to socket_bind().
	 * @param int|string $port TCP port passed to socket_bind().
	 */
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

	/**
	 * Registers a lifecycle callback.
	*
	 * Supported events are `connect`, `message`, and `disconnect`. Unsupported
	 * event names are ignored to preserve the legacy loose API, and callability is
	 * not checked until dispatch.
	*
	 * @param string $event Lifecycle event name.
	 * @param callable|null $callback Callback invoked with event-specific socket/message arguments.
	 * @return void Registration mutates the callback table in place.
	 */
	public function on($event, $callback){
		if(array_key_exists($event, $this->callbacks)){
			$this->callbacks[$event]=$callback;
		}
	}

	/**
	 * Starts the blocking socket accept/read loop.
	 *
	 * This method does not return under normal operation. It accepts new clients,
	 * performs the WebSocket handshake, dispatches callbacks, relays text frames,
	 * and removes clients when reads fail. Socket errors are not promoted to
	 * exceptions; the process is expected to be supervised externally.
	 *
	 * @return void The process remains in the server loop until externally stopped.
	 */
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

	/**
	 * Completes the WebSocket upgrade handshake for one client socket.
	*
	 * The handshake trusts the incoming `Sec-WebSocket-Key` header and writes a
	 * basic HTTP 101 response. It does not validate Host, Origin, request path, or
	 * protocol extensions.
	 *
	 * @param resource $client Accepted socket resource.
	 * @return void Upgrade response is written directly to the client socket.
	 */
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

	/**
	 * Broadcasts an incoming client frame to every other connected client.
	*
	 * The incoming frame body is decoded as text and re-encoded once per peer.
	 * Sender sockets are excluded from the broadcast.
	 *
	 * @param resource $client Source client socket.
	 * @param string $msg Raw WebSocket frame received from the source client.
	 * @return void Encoded frames are written directly to peer sockets.
	 */
	private function broadcast($client, $msg){
		$msg=$this->unmask($msg);
		foreach($this->clients as $other_client){
			if($client !== $other_client){
				$encoded_message=$this->mask($msg);
				socket_write($other_client, $encoded_message, strlen($encoded_message));
			}
		}
	}

	/**
	 * Decodes a client-to-server masked WebSocket frame body.
	*
	 * The decoder handles the basic length encodings used by client text frames
	 * and applies the four-byte masking key. It assumes the full frame was read
	 * into memory by the caller.
	 *
	 * @param string $frameBytes Raw frame bytes from socket_recv().
	 * @return string unmasked frame body after applying the client masking key.
	 */
	private function unmask($frameBytes){
		$length=ord($frameBytes[1]) & 127;
		if($length == 126){
			$masks=substr($frameBytes, 4, 4);
			$data=substr($frameBytes, 8);
		} elseif($length == 127){
			$masks=substr($frameBytes, 10, 4);
			$data=substr($frameBytes, 14);
		} else {
			$masks=substr($frameBytes, 2, 4);
			$data=substr($frameBytes, 6);
		}
		$text='';
		for($i=0; $i < strlen($data); ++$i){
			$text .= $data[$i] ^ $masks[$i % 4];
		}
		return $text;
	}

	/**
	 * Encodes a server-to-client text frame.
	*
	 * Server frames are not masked, matching the WebSocket protocol. The method
	 * emits a single final text frame and does not fragment large messages.
	 *
	 * @param string $text Text body to send.
	 * @return string WebSocket frame bytes with the appropriate length header.
	 */
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
