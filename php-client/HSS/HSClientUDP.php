<?php

namespace HSS;

class HSClientUDP
{
	private $_Socket = false;
	private $conn_key = false;
	private static $all_clients = [];
	
	public function __construct($host = HS_HOST, $port = HS_PORT)
	{
		$this->host = $host;
		$this->port = $port;
		$this->conn_key = "{$this->host}:{$this->port}";
		$this->_connect();
	}

	function __destruct()
	{
		if ($this->_Socket !== false)
			socket_close($this->_Socket);
	}

	private function _connect()
	{
		if (isset(self::$all_clients[$this->conn_key]))
		{
			$this->_Socket = self::$all_clients[$this->conn_key];
			return true;
		}
		
		// open UDP socket
		if (!($sock = socket_create(AF_INET, SOCK_DGRAM, 0)))
		{
			$errorcode = socket_last_error();
			$errstr = socket_strerror($errorcode);

			error_log("websockets: UDP / Can't open UDP socket ({$errstr} / {$errorcode}): ".$this->host." / port: ".$this->port);
			return false;
		}

		// Bind the source address
		if (!socket_bind($sock, "0.0.0.0", 0))
		{
			$errorcode = socket_last_error();
			$errstr = socket_strerror($errorcode);

			error_log("websockets: UDP / Can't bind UDP socket ({$errstr} / {$errorcode}): ".$this->host." / port: ".$this->port);
			return false;
		}

		$this->_Socket = $sock;
		self::$all_clients[$this->conn_key] = $sock;
		return true;
	}

	function isConnectedOk()
	{
		return $this->_Socket !== false;
	}

	public function sendData($data)
	{
		return $this->sendMulti($data);
	}

	public function sendMulti()
	{
		if ($this->_Socket === false)
			return false;

		$mt = microtime(false);
		$mt = explode(" ", $mt);
		$guid = getmypid().'|'.substr($mt[0], 2, 6).'|'.substr($mt[1], -3);
		
		$send = '';
		foreach (func_get_args() as $data)
		{
			if (!is_array($data))
				$data = ['action'=>$data];
			$compress_threshold = HSCommon::shouldCompress($this->host);

			$tmp = [pack("v", strlen($guid)), $guid];
			foreach ($data as $k=>$v)
			{
				$tmp[] = pack("vV", strlen($k), strlen($v));
				$tmp[] = $k;
				$tmp[] = $v;
			}
			$data = implode("", $tmp);
			$data_size = strlen($data);

			$head = 'b';
			if ($data_size > $compress_threshold && $compress_threshold !== false)
			{
				$tmp = gzdeflate($data, 1);
				if (strlen($tmp) < $data_size)
				{
					$data = $tmp;
					$data_size = strlen($tmp);
					$head = 'B';
				}
			}
			$data_size = $data_size + 1 + 4;
			$data = $head.pack("V", $data_size).$data;
			
			$send .= $data;
		}

		return socket_sendto($this->_Socket, $send, strlen($send), 0, $this->host, $this->port);
	}



	// ####################################################################################
	// functions for obsolete protocol support
	public function sendMultiOldProto()
	{
		if ($this->_Socket === false)
			return false;

		$mt = microtime(false);
		$mt = explode(" ", $mt);
		$guid = getmypid().'|'.substr($mt[0], 2, 6).'|'.substr($mt[1], -3);

		$send = '';
		foreach (func_get_args() as $data)
		{
			if (is_array($data))
				$data = ltrim(self::url_assemble($data), '?');

			// compression support!
			if (strlen($data) > 512)
			{
				$data = gzdeflate($data);
				$data = "!".strlen($data)."|{$guid}\r\n\r\n{$data}";
			} else
			{
				$data = strlen($data)."|{$guid}\r\n\r\n{$data}";
			}

			$send .= $data;
		}

		return socket_sendto($this->_Socket, $send, strlen($send), 0, $this->host, $this->port);
	}
	
	// Url assemble
	static function url_assemble_($arr, $pos = array())
	{
		$ret = array();

		$pos_curr = $pos;
		foreach ($arr as $k=>$v)
		{
			$pos = $pos_curr;
			$pos[] = $k;

			if (is_array($v))
			{
				$tmp = self::url_assemble_($v, $pos);
				foreach ($tmp as $k=>$v)
					$ret[] = $v;
				continue;
			}

			$pos[] = $v;
			$ret[] = $pos;
		}

		return $ret;
	}


	static function url_assemble($arr, $start = '', $include_fields = true)
	{
		if ($include_fields !== true)
		{
			foreach ($arr as $k=>$v)
				if (!in_array($k, $include_fields))
				{
					unset($arr[$k]);
				}
		}

		$ret = array();
		$tmp = self::url_assemble_($arr);
		foreach ($tmp as $v)
		{
			$value = urlencode(array_pop($v));
			$k1 = urlencode($v[0]);
			unset($v[0]);
			$key = '';
			if (count($v) > 0)
			{
				foreach ($v as $kk=>$vv)
					$v[$kk] = urlencode($vv);

				$key = '['.implode("][", $v).']';
			}

			$ret[] = "{$k1}{$key}={$value}";
		}

		$url = implode("&", $ret);

		$start = rtrim($start, '?');
		$start .= "?";
		return rtrim($start.$url, '&?');
	}
}