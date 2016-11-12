<?php

namespace HSS;

class HSCommon
{
	static private $opt_compress = ['127.'=>false, '*'=>false];
	
	static function setCompressionSettings($settings)
	{
		if (!is_array($settings))
		{
			error_log("Bad compression settings, should be [pattern=>true/false/compress_above]");
			return false;
		}

		self::$opt_compress = $settings;
		return true;
	}
	
	static function shouldCompress($host)
	{
		foreach (self::$opt_compress as $host_pattern=>$settings)
		{
			if (strpos($host, $host_pattern) !== 0)
				continue;

			if ($settings === true)
				$settings = 50;
			return $settings;
		}

		return false;
	}
	
}



