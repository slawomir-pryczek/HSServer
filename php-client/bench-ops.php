<?
include 'inc.common.php';
include 'conf.php';

include "HSS/HSClient.php";
use HSS\HSClient;
use HSS\HSClientUDP;
use HSS\HSCommon;

srand(0);
$hs = new HSClient(HS_HOST, HS_PORT);

$run_ops_count = 100000;


if (input::get_req('t', false) !== false)
{
	// run the benchmark!
	$data = input::random_string(15000);

	$ts = new TimeSpan();
	$data = ['action'=>'default', 'data'=>$data];
	for ($i=0; $i<$run_ops_count; $i++)
	{
		$x = [];
		$hs->sendData($data, $x, 2, true);
	}
	$took = $ts->getTimeSpanMS();
	echo "####{$took}####";

	return;
}



$url_base = "http://{$_SERVER['HTTP_HOST']}/{$_SERVER['REQUEST_URI']}";
if (strpos($url_base, '?') === false)
	$url_base .= "?";

$urls = [];
$threads = min(80, max(1, input::get_req("threads", 1)));
for ($i=0; $i<$threads; $i++)
	$urls[] = $url_base."&t={$i}";

$ts = new TimeSpan();
$return = curl::multi($urls, [], 600);

$sum = 0;
foreach ($return as $k=>$v)
{
	$matches = [];
	if (preg_match("/####([0-9\.]*)####/", $v, $matches) > 0)
	{
		if (isset($matches[1]))
		{
			$return[$k] = $matches[1];
			$sum += $matches[1];
		}	
	}
	else 
		unset($return[$k]);
}

echo "Times: ";
print_r($return);
$took_adj = $sum / count($return);


$took = $ts->getTimeSpanMS();
echo "Took: {$took}; Took adjusted: {$took_adj}<br>";
echo "OPs/s: ".(($run_ops_count*$threads) / ($took_adj/1000.0))."<br><br>";


$data = ['action' => 'server-status'];
$x = [];
echo $hs->sendData($data, $x, 2, true);
print_rr($x);
return;

?>