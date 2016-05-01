# HSServer Introduction

HSServer is golang RPC-like server framework, that'll allow you to create and manage high performance micro-services that can be accessed via high performance binary protocol, and using HTTP. Its purpose is to allow PHP developers to easily migrate algorithms that require persistent data or a lot of CPU power to golang.



Features
-----------
 * Slab memory allocator and buffer sharing to limit memory allocation and GC
 * Complete solution, contains high performance client implementing binary protocol, and server
 * Easy developement and deployment
 * Built-in, configurable compression support
 * Comprehensive statistics
 * High performance and production-tested. Ability to serve 10-20k req/s per CPU core

Simple Run <pre>./server</pre>
 
Production deployment with auto-restart
<pre>
screen
cd ./go-worker/bin
started=\`date +%Y%m%d@%H:%M\`; for i in {1..999999}; do ./go-worker 1>/dev/null 2>"error-$started-$i.log.txt"; sleep 10; done;</pre>

Configuration
-----------------
Configuration needs to be stored in conf.json in the binary directory. By default, the server will listen on localhost. Connections can be made using built-in protocol on TCP and UDP port 7777, and also HTTP on port 7778.
<pre>{
"BIND_TO":"127.0.0.1:7777,u127.0.0.1:7777,h127.0.0.1:7778",
"FORCE_START":true,
"DEBUG":false,
"VERBOSE":false,
"RUN_SERVICES":"profiler,echo"
}</pre>


PHP Communication
------------------
You can communicate with the server easily by using both TCP and UDP.

```php
<?php
include 'inc.hsclient.php';

define("HS_HOST", "127.0.0.1");
define("HS_PORT", "7777");

$go = new HSClient(HS_HOST, HS_PORT); // change to HSClientUDP to use UDP packets instead of TCP connection
$header_out = [];
$go_call = $go->sendDataJSON(['action'=>'echo', 'data'=>123], $header_out, 2);

print_r($go_call);
echo "<hr><h1>Header</h1>";
print_r($header);
```

You can also use do the same thing using HTTP by pointing your browser to
<pre>http://127.0.0.1:7778/?action=echo&data=123</pre>

Developing modules
------------------
Adding modules to server should be self-explanatory, please see /echo directory inside server sources to see example of simple module, later there'll be documentation added.

