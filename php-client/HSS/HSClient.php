<?

namespace HSS;
require_once __DIR__."/HSClientUDP.php";
require_once __DIR__."/HSCommon.php";


class HSClient
{
	private static $header_terminator = "\r\n\r\n";
	private static $header_terminator_len = 4;
	
	private $_Socket = false;
	private $conn_key = false;
	
	private static $conn_props = [];
	private static $all_clients = [];
	
	private $opt_timeout = 5;
	private $opt_connect_timeout = 2;
	private $opt_persistent_connection = true;
	
	const HS_HOST = '127.0.0.1';
	const HS_PORT = '7777';
	public function __construct($host = self::HS_HOST, $port = self::HS_PORT, $connect_timeout = 2)
	{
		$this->host = $host;
		$this->port = $port;
		$this->conn_key = "{$this->host}:{$this->port}";
		$this->opt_connect_timeout = $connect_timeout;
		
		// connect and initialize client, if client initialization failed it means something is wrong
		// and we need to connect and initialize again (try it twice)
		if ($this->_connect())
		{
			if (!$this->initializeClient())
			{
				$this->_disconnect();
				if ($this->_connect())
					$this->initializeClient();
			}
		}
		
		self::$all_clients[] = $this;
	}

	public function __destruct()
	{
		// if connection is not persistent - disconnect!
		if (!$this->opt_persistent_connection)
			$this->_disconnect();
	}
	
	function setTimeout($timeout)
	{
		$this->opt_timeout = $timeout;
		return true;
	}
	
	function setDisablePersistent()
	{
		$this->opt_persistent_connection = false;
		return true;
	}
	
	// ####################################################################################
	// Connection support
	private function _connect()
	{
		if (isset(self::$conn_props[$this->conn_key]))
		{
			self::$conn_props[$this->conn_key]['referenced']++;
			$this->_Socket =  self::$conn_props[$this->conn_key]['conn'];
			return true;
		}	
		
		$s = @pfsockopen($this->host, $this->port, $errno, $errstr, $this->opt_connect_timeout);
		if ($s === false)
		{
			$this->_Socket = false;
			error_log("websockets: can't connect to socket at ($errstr): ".$this->host." / port: ".$this->port);
			return false;
		}
		
		$sck = socket_import_stream($s);
		socket_set_option($sck, SOL_TCP, TCP_NODELAY, 1);
		stream_set_timeout($s, $this->opt_timeout);
		$this->_Socket = $s;
		
		self::$conn_props[$this->conn_key] = ['safe'=>false, 'referenced'=>1, 'conn'=>$s];
		return true;
	}

	private function _reconnect()
	{
		$references = -1;
		if (isset(self::$conn_props[$this->conn_key]))
		{
			$references = self::$conn_props[$this->conn_key]['referenced'];
			@fclose(self::$conn_props[$this->conn_key]['conn']);
			unset(self::$conn_props[$this->conn_key]);
		}
		
		// connect and initialize client, if client initialization failed it means something is wrong
		// and we need to connect and initialize again (try it twice)
		if ($this->_connect())
		{
			if (!$this->initializeClient())
			{
				// close socket if it failed to initialize
				if ($this->_Socket !== false)
				{
					if (isset(self::$conn_props[$this->conn_key]))
						unset(self::$conn_props[$this->conn_key]);
					@fclose($this->_Socket);
				}	
				
				$this->_connect();
				$this->initializeClient();
			}
		}
		
		// override reference counter
		if ($references > 0)
			self::$conn_props[$this->conn_key]['referenced'] = $references;
		
		// update sockets in all clients
		foreach (self::$all_clients as $client)
		{
			if (strcasecmp($client->conn_key, $this->conn_key) === 0)
				$client->_Socket = $this->_Socket;
		}	
	}
	
	private function _disconnect()
	{
		if (isset(self::$conn_props[$this->conn_key]))
		{
			self::$conn_props[$this->conn_key]['referenced'] --;
			if (self::$conn_props[$this->conn_key]['referenced'] > 0)
			{
				// the socket is still used!
				$this->_Socket = false;
				return true;
			}
			
			// this is the last connection, close it!
			unset(self::$conn_props[$this->conn_key]);
		}	
		
		if ($this->_Socket !== false)
		{
			fclose($this->_Socket);
			$this->_Socket = false;
		}
		
		return true;
	}

	function isConnectedOk()
	{
		return $this->_Socket !== false;
	}

	// ####################################################################################
	// Sending and receiving data
	public function sendDataJSON($data, &$header = false)
	{
		if ($this->_Socket === false)
			return false;
		
		$ret = $this->sendData($data, $header);
		$ret = @json_decode($ret, true);
		if (!is_array($ret))
			return false;
		
		return $ret;
	}
	
	/*
	 * Send one time initialization, on-connect so the server knows client properties
	 */
	private function initializeClient()
	{
		if ($this->_Socket === false)
			return false;
		if (ftell($this->_Socket) > 0)
			return true;
		
		// Send initialization packet
		$data = [];
		if (function_exists('snappy_uncompress'))
			$data['features'] = 'snappy';
		
		if (count($data) == 0)
			return true;
		$data['action'] = 'conn-ex';
				
		$mt = microtime(false);
		$mt = explode(" ", $mt);
		$guid = "conn".getmypid().'|'.substr($mt[0], 2, 6).'|'.substr($mt[1], -3);

		$head = 'b';
		$raw_data = [pack("v", strlen($guid)), $guid];
		foreach ($data as $k=>$v)
		{
			$raw_data[] = pack("vV", strlen($k), strlen($v));
			$raw_data[] = $k;
			$raw_data[] = $v;
		}
		$raw_data = implode("", $raw_data);
		$data_size = strlen($raw_data) + 1 + 4;	// head + content length
		$raw_data = $head.pack("V", $data_size).$raw_data;
		
		stream_set_timeout($this->_Socket, 3);
		$pos = 0;
		while ($pos < strlen($raw_data))
		{
			if ($pos > 0)
				$res = @fwrite($this->_Socket, substr($raw_data, $pos));
			else
				$res = @fwrite($this->_Socket, $raw_data);
			if ($res === false || $res === 0)
				return false;
			$pos += $res;
		}

		// Read response
		$ret = "";
		$content_length = false;
		$buffer_len = 4096;
		$header = false;
		while ($content_length === false || strlen($ret) < $content_length)
		{
			$_r = fread($this->_Socket, $buffer_len);
			if (strlen($_r) == 0)
			{
				if (strlen($ret) > 4096)
					$ret = substr($ret, 0, 4096).'...';
				error_log("websockets init error: request ... ".$ret);
				return false;
			}
			$ret .= $_r;

			if ($header === false)
			{
				$header = strpos($ret, self::$header_terminator);
				if ($header !== false)
				{
					// simplified header processing
					$tmp = [];
					foreach (explode("\n", substr($ret, 0, $header)) as $v)
					{
						$v = explode(":", $v);
						if (count($v) <= 1)
							continue;
						$tmp[strtolower($v[0])] = trim($v[1]);
					}
					$ret = substr($ret, $header + self::$header_terminator_len);
					
					$header = $tmp;
					$content_length = intval($header['content-length']);
				}
			}
			
			// if we're reaching end of data - get exactly X bytes to prevent blocking!
			// otherwise PHP will lock waiting for data
			$buffer_len = min($buffer_len, $content_length - strlen($ret));

			if ($buffer_len == 0)
				break;
		}
		
		if (strcasecmp($header['guid'] ?? "", $guid) != 0)
			return false;

		// we got correct response, so mark connection as SAFE, so we can use it to just send data, without waiting 
		// for the response!
		self::$conn_props[$this->conn_key]['safe'] = true;
		return true;
	}
	
	// send data to golang server, and receives response
	// to skip response, you'll need to add __skipsendback in data array
	public function sendData($data, &$header = false)
	{
		if ($this->_Socket === false)
			return false;
		
		// if this is first send for this connection in this process
		// make full roundtrip, to be sure that there's no lingering data
		$skip_sendback = false;
		if (isset($data['__skipsendback']))
		{
			$skip_sendback = true;
			if (!self::$conn_props[$this->conn_key]['safe'])
			{
				unset($data['__skipsendback']);
				$skip_sendback = false;
			}
		}

		// build data to send
		$mt = microtime(false);
		$mt = explode(" ", $mt);
		$guid = getmypid().'|'.substr($mt[0], 2, 6).'|'.substr($mt[1], -3);
		
		if (!is_array($data))
			$data = ['action'=>$data];
		$_action = isset($data['action']) ? $data['action'] : "no action";

		$compress_threshold = HSCommon::shouldCompress($this->host);
		if ($compress_threshold !== false)
			$data['__cc'] = $compress_threshold;
		
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


		// send data to socket
		$pos = 0;
		$retries_left = 3;
		stream_set_timeout($this->_Socket, $this->opt_timeout);
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
				$this->_reconnect();
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
			error_log("websockets: write error: {$this->host} : {$this->port}");
			return false;
		}

		if ($skip_sendback)
			return "";
		
		// read response back
		$ret = '';
		$header = false;
		$content_length = false;

		$terminator = "\r\n\r\n";
		$terminator_len = strlen($terminator);

		$buffer_len = 4096*8;
		while ($content_length === false || strlen($ret) < $content_length)
		{
			$_r = fread($this->_Socket, $buffer_len);
			if (strlen($_r) == 0)
			{
				$this->_reconnect();
				error_log("websockets: Read timeout, re-opening connection ... action: {$_action}");
				if (strlen($data) > 4096)
					$data = substr($data, 0, 4096).'...';
				error_log("websockets: request ... ".$data);
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
						$_outval = trim(implode(":", $v));
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
		
		// process received data
		// uncompress if needed
		if (isset($header['guid']) && $header['guid'] != $guid)
		{
			$this->_reconnect();
			error_log("websockets: GUID mismatch - {$header['guid']} vs {$guid}, you got content of previous connection, re-opening connection");
			return false;
		}

		if (strlen($ret) != $content_length)
		{
			$this->_reconnect();
			error_log("websockets: Message content length doesn't match - re-opening connection");

			if (strlen($ret) > $content_length)
				return substr($ret, 0, $content_length);
			else
				return false;
		}

		// compression support
		if (isset($header['content-encoding']))
		{
			if (strpos($header['content-encoding'], 'gzip') !== false)
				$ret = gzinflate($ret);

			if (strpos($header['content-encoding'], 'mp-snappy') !== false)
			{
				$ret = $this->_decompress_multipart($ret, function($d) {
					try
					{
						$d = snappy_uncompress($d);
						return $d;
					}
					catch (Exception $e)
					{	return false;	}
					return false;
				});
			}

			if (strpos($header['content-encoding'], 'mp-flate') !== false)
			{
				$ret = $this->_decompress_multipart($ret, function($d) {
					try
					{
						$d = gzinflate($d);
						return $d;
					}
					catch (Exception $e)
					{	return false;	}
					return false;
				});
			}
		}

		// we got correct response, so mark connection as SAFE, so we can use it to just send data, without waiting 
		// for the response!
		self::$conn_props[$this->conn_key]['safe'] = true;
		return $ret;
	}

	private function _decompress_multipart($data, $decompress_fn)
	{
		$chunks = [];
		$i=0;
		while ($i < strlen($data))
		{
			$_tmp = unpack("V", substr($data, $i, 4))[1];
			$i+=4;
			if ($_tmp == 0)
				break;
			$chunks[] = $_tmp;
		}

		$out = [];
		$chunk_no = 0;
		while ($i < strlen($data) && $chunk_no < count($chunks))
		{
			$len = $chunks[$chunk_no];
			$_tmp = $decompress_fn(substr($data, $i, $len));
			if ($_tmp === false)
			{
				error_log("Error decompressing data in HSClient");
				return false;
			}	
			$out[] = $_tmp;
			$i += $len;
			$chunk_no++;
		}

		return implode("", $out);
	}
}

