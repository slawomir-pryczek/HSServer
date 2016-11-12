<?php
include 'inc.common.php';
include 'conf.php';

include "HSS/HSClient.php";
use HSS\HSClient;
use HSS\HSClientUDP;
use HSS\HSCommon;


$go = new HSClientUDP(HS_HOST, HS_PORT);
$go->sendData(['action'=>'udp-test']);

// Run operation that returns JSON data
// change to HSClientUDP to use UDP packets instead of TCP connection
$go = new HSClient(HS_HOST, HS_PORT); 
$header_out = [];
$echo = $go->sendDataJSON(['action'=>'echo', 'data'=>123], $header_out, 2);
if ($echo === false)
	die("Cannot send data");
echo "ECHO Call:<br> echo 123 > ";
print_r($echo);
echo "<br><br>";

// Get server status (always returned as string)
$status = $go->sendData(['action'=>'server-status'], $header_out, 2);
if ($status === false)
	die("Cannot send data");

echo $status;
echo "<br><br><hr><h3>Header</h3><pre>";
print_r($header_out);

