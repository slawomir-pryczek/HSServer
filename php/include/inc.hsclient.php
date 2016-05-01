<?php

class HSClient
{
	const USE_PERSISTENT_CONNECTIONS = true;
	private $_Socket = false;

	public function __construct($host = HS_HOST, $port = HS_PORT)
	{
		$this->host = $host;
		$this->port = $port;
		$this->_connect();
	}

	public function __destruct()
	{
		// persistent connections!
		if (!self::USE_PERSISTENT_CONNECTIONS)
		{
			$this->_disconnect();
		}
	}

	public function sendDataJSON($data, &$header = false, $read_timeout = 2, $skip_compression = false)
	{
		$ret = $this->sendData($data, $header, $read_timeout, $skip_compression);
		$ret = @json_decode($ret, true);
		if (!is_array($ret))
			return false;
		
		return $ret;
	}
	
	public function sendData($data, &$header = false, $read_timeout = 2, $skip_compression = false)
	{
		if ($this->_Socket === false)
			return false;

		$mt = microtime(false);
		$mt = explode(" ", $mt);
		$guid = getmypid().'|'.substr($mt[0], 2, 6).'|'.substr($mt[1], -3);

		if (!is_array($data))
			$data = ['action'=>$data];
		

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
		if ($data_size > 1024 && !$skip_compression)
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

		/* Old text protocol, which was slow
		if ($data === false)
		{
			$data_size = strlen($data);
			if ($data_size > 100 && !$skip_compression)
			{
				// compression support!
				$tmp = gzdeflate($data);
				if ($data_size < 2000 && strlen($tmp) > $data_size)
					$tmp = $data;
				$data = "!".strlen($tmp)."|{$guid}\r\n\r\n{$tmp}";
			}
			else
				$data = strlen($data)."|{$guid}\r\n\r\n{$data}";
		}*/

		$pos = 0;
		$retries_left = 3;
		stream_set_timeout($this->_Socket, 2);
		while ($pos < strlen($data) && $retries_left >= 0)
		{
			$res = false;
			if ($pos > 0)
			{
				$res = @fwrite($this->_Socket, substr($data, $pos));
			}
			else
			{
				$res = @fwrite($this->_Socket, $data);
			}

			if ($res === false || $res === 0)
			{
				// reconnect
				$retries_left --;
				$this->_disconnect();
				$this->_connect();
				$pos = 0;
				continue;
			}
			else
			{
				$pos += $res;
			}
		}

		if ($retries_left < 0)
		{
			error_log("Socket write error: {$this->host} : {$this->port}");
			return false;
		}

		$ret = '';
		$header = false;
		$content_length = false;

		$terminator = "\r\n\r\n";
		$terminator_len = strlen($terminator);

		$buffer_len = 4096*8;
		//$ts = new TimeSpan();

		stream_set_timeout($this->_Socket, $read_timeout);
		while ($content_length === false || strlen($ret) < $content_length)
		{
			$_r = fread($this->_Socket, $buffer_len);
			if (strlen($_r) == 0)
			{
				$this->_disconnect();
				$this->_connect();
				error_log("websockets: Read timeout, re-opening connection");
				return false;
			}
			$ret .= $_r;

			/*if (false)
			{
				echo "<br>T: ".$ts->getTimeSpanMS().'/'.strlen($ret).'<Br>';
				echo $ret;
			}*/

			if ($header === false)
			{
				$header = strpos($ret, $terminator);
				if ($header !== false)
				{
					$tmp = array();
					foreach (explode("\n", substr($ret, 0, $header)) as $v)
					{
						if (strpos($v, ":") === false)
							continue;

						$v = explode(":", $v);
						$_outkey = strtolower(trim($v[0]));
						unset($v[0]);
						$_outval = trim(implode(",", $v));
						$tmp[$_outkey] = $_outval;
					}

					$ret = substr($ret, $header + $terminator_len);
					$header = $tmp;

					$content_length = intval($header['content-length']);
				}
			}

			$clen = strlen($ret);

			// if we're reaching end of data - get exactly X bytes to prevent blocking!
			// otherwise PHP will lock waiting for data
			$buffer_len = min($buffer_len, $content_length - $clen);

			if ($buffer_len == 0)
				break;
		}

		if (isset($header['guid']) && $header['guid'] != $guid)
		{
			$this->_disconnect();
			$this->_connect();
			error_log("websockets: GUID mismatch, you got content of previous connection, re-opening connection");
			return false;
		}

		if (strlen($ret) != $content_length)
		{
			$this->_disconnect();
			$this->_connect();
			error_log("websockets: Message content length doesn't match - re-opening connection");

			if (strlen($ret) > $content_length)
				return substr($ret, 0, $content_length);
			else
				return false;
		}

		// compression support
		if (isset($header['content-encoding']) && strpos($header['content-encoding'], 'gzip') !== false)
			$ret = gzinflate($ret);

		return $ret;
	}

	private function _connect()
	{
		$s = @pfsockopen($this->host, $this->port, $errno, $errstr, 2);
		$sck = socket_import_stream($s);
		socket_set_option($sck, SOL_TCP, TCP_NODELAY, 1);
		
		if ($s === false)
		{
			$this->_Socket = false;
			error_log("Can't connect to socket at ($errstr): ".$this->host." / port: ".$this->port);
			return false;
		}

		stream_set_timeout($s, 2);
		$this->_Socket = $s;
		return true;
	}

	private function _disconnect()
	{
		if ($this->_Socket !== false)
		{
			fclose($this->_Socket);
			$this->_Socket = false;
		}
	}

	function isConnectedOk()
	{
		return $this->_Socket !== false;
	}
}



class HSClientUDP
{
	private $_Socket = false;

	public function __construct($host = HS_HOST, $port = HS_PORT)
	{
		$this->host = $host;
		$this->port = $port;
		$this->_connect();
	}

	function __destruct()
	{
		if ($this->_Socket !== false)
			socket_close($this->_Socket);
	}

	private function _connect()
	{
		// open UDP socket
		if (!($sock = socket_create(AF_INET, SOCK_DGRAM, 0)))
		{
			$errorcode = socket_last_error();
			$errstr = socket_strerror($errorcode);

			error_log("Can't open UDP socket ({$errstr} / {$errorcode}): ".$this->host." / port: ".$this->port);
			return false;
		}

		// Bind the source address
		if (!socket_bind($sock, "0.0.0.0", 0))
		{
			$errorcode = socket_last_error();
			$errstr = socket_strerror($errorcode);

			error_log("Can't bind UDP socket ({$errstr} / {$errorcode}): ".$this->host." / port: ".$this->port);
			return false;
		}

		$this->_Socket = $sock;
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

	private function sendMulti()
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