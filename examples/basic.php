<?php

require __DIR__.'/../vendor/autoload.php';

use DroneMill\EventsocketClient\Client;

$options = getopt('', ['requestClientId:']);

$client = new Client('127.0.0.1:8080');

echo 'ClientId: ' . $client->getId() . PHP_EOL . PHP_EOL;


$client->registerBroadcastHandler(function($m) use ($client) {
	echo 'BROADCAST: ' . $m->Payload->value . PHP_EOL;
});


$client->suscribe('foo', function($m) use ($client) {
	echo $m->Event . ': ' . $m->Payload->awesomeValue . PHP_EOL;
});


$client->registerRequestHandler(function($m) use ($client) {
	echo 'REQUEST: requestId:' . $m->RequestId . ' replyingTo:'. $m->ReplyClientId . PHP_EOL;
	$client->reply($m->RequestId, $m->ReplyClientId, ['I_SEE_YOU' => (string) rand(99999,9999999)]);
});


if ($options['requestClientId'])
{
	$client->request($options['requestClientId'], ['PEEK_ABOO' => 'hello'], function($m) use ($client) {
		echo 'REPLY: requestId:' . $m->RequestId . ' payload:'. print_r($m->Payload, true) . PHP_EOL;
	});
}


while(true)
{
	$client->recv();
}
