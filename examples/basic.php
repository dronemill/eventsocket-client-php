<?php

require __DIR__.'/../vendor/autoload.php';

use DroneMill\EventsocketClient\Client;

$options = getopt('', ['requestClientId:']);

$client = new Client('127.0.0.1:8080');

echo 'ClientId: ' . $client->getId() . PHP_EOL . PHP_EOL;


$client->registerBroadcastHandler(function($m) use ($client) {
	echo 'BROADCAST: ' . $m->Payload->value . PHP_EOL;
});

$client->emit('foo', ['secretNumber' => rand(1000000,99999999)]);

$client->suscribe('foo', function($m) use ($client) {
	echo $m->Event . ': ' . $m->Payload->awesomeValue . PHP_EOL;
});

$client->registerRequestHandler(function($m) use ($client) {
	echo 'REQUEST: requestId:' . $m->RequestId . ' replyingTo:'. $m->ReplyClientId . PHP_EOL;
	$client->reply($m->RequestId, $m->ReplyClientId, ['I_SEE_YOU' => 'hello!']);
});

$client->request('clientIDThatNoExist', ['ClientDoesNotExist' => 'I Know'], function($m) use ($client) {
	if ($m->Error === null) {
		echo "Should have received error...\n";
	}

	if ($m->Error->Type !== Client::ERROR_REQUEST_CLIENT_NO_EXIST) {
		echo 'Received incorrect Errortype (expected: ' . Client::ERROR_REQUEST_CLIENT_NO_EXIST . ")\n";
	}
});

if (array_key_exists('requestClientId', $options))
{
	$client->request($options['requestClientId'], ['PEEK_ABOO' => 'hello'], function($m) use ($client) {
		echo 'REPLY: requestId:' . $m->RequestId . ' payload:'. print_r($m->Payload, true) . PHP_EOL;
	});
}

while(true)
{
	$client->recv();
}
