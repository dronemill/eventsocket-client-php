<?php

namespace DroneMill\EventsocketClient;

use Closure;
use Exception;
use InvalidArgumentException;
use WebSocket\Client as WebsocketClient;

class ClientException extends Exception {}

class Client {

	const MESSAGE_TYPE_BROADCAST = 1;
	const MESSAGE_TYPE_STANDARD = 2;
	const MESSAGE_TYPE_REQUEST = 3;
	const MESSAGE_TYPE_REPLY = 4;
	const MESSAGE_TYPE_SUSCRIBE = 5;
	const MESSAGE_TYPE_UNSUSCRIBE = 6;

	const ERROR_REQUEST_CLIENT_NO_EXIST = 'ErrorRequestClientNoExist';
	const ERROR_REQUEST_CLIENT_NOT_CONNECTED = 'ErrorRequestClientNotConnected';


	/**
	 * the Eventsocket server url
	 *
	 * @var  string
	 */
	protected $serverUrl;

	/**
	 * The client object returned from the server
	 *
	 * @var  stdClass
	 */
	protected $client;

	/**
	 * The websocket connection
	 *
	 * @var  WebsocketClient
	 */
	protected $ws;

	/**
	 * the message handlers
	 *
	 * @var  []
	 */
	protected $handlers = [
		self::MESSAGE_TYPE_BROADCAST  => [],
		self::MESSAGE_TYPE_STANDARD   => [],
		self::MESSAGE_TYPE_REQUEST    => [],
		self::MESSAGE_TYPE_REPLY      => [], // these are the live request handlers
	];


	///////////////////////////
	// BEGIN PUBLIC METHODS //
	///////////////////////////

	/**
	 * Construct a new Eventsocket Client.
	 *
	 * @param  [type]  $serverUrl  [description]
	 */
	public function __construct($serverUrl)
	{
		if (empty($serverUrl))
		{
			throw new InvalidArgumentException("Server URL cannot be empty");
		}

		$this->serverUrl = $serverUrl;

		$this->newClient();
		$this->connectWs();
	}

	/**
	 * Utility method to get the client id
	 *
	 * @return  string  the client id
	 */
	public function getId()
	{
		return $this->client->Id;
	}

	/**
	 * Register a broadcast handler
	 *
	 * @param   Closure  $handler  [description]
	 * @return  void
	 */
	public function registerBroadcastHandler(Closure $handler)
	{
		$this->handlers[static::MESSAGE_TYPE_BROADCAST][] = $handler;
	}

	/**
	 * Register a request handler
	 *
	 * @param   Closure  $handler  [description]
	 * @return  void
	 */
	public function registerRequestHandler(Closure $handler)
	{
		$this->handlers[static::MESSAGE_TYPE_REQUEST][] = $handler;
	}

	/**
	 * Receive a message from the socket
	 *
	 * @return void
	 */
	public function recv()
	{
		// receive a message from the socket
		$message = $this->ws->receive();

		// is the message is empty?
		if (empty($message))
		{
			return;
		}

		// decode the message (json_decode + error checking)
		$message = $this->decodeMessage($message);

		// injest and route the message
		$this->injest($message);
	}

	/**
	 * Suscribe to a given event
	 *
	 * @param   string|[]  $event    the event to suscribe to
	 * @param   Closure    $handler  the event handler to be called
	 *   upon receiving of the event
	 * @return  void
	 */
	public function suscribe($events, Closure $handler)
	{
		if (is_string($events)) $events = [$events];

		// store the handler for each of the events it represents
		foreach ($events as $event)
		{
			// check that we have a place to store the handler
			if (! array_key_exists($event, $this->handlers[static::MESSAGE_TYPE_STANDARD]))
			{
				$this->handlers[static::MESSAGE_TYPE_STANDARD][$event] = [];
			}

			$this->handlers[static::MESSAGE_TYPE_STANDARD][$event][] = $handler;
		}

		// create a new bare message, and store the events
		$m = $this->bareMessage(static::MESSAGE_TYPE_SUSCRIBE);
		$m->Payload['Events'] = $events;

		// finally, send the message
		$this->send($m);
	}

	/**
	 * Reply to a given request
	 *
	 * @param   $string  $requestId the  id of the request to reply to
	 * @param   $string  $replyClientId  the client to reply to
	 * @param   Array   $payload         the payload to send
	 * @return  void
	 */
	public function reply($requestId, $replyClientId, Array $payload)
	{
		$m = $this->bareMessage(static::MESSAGE_TYPE_REPLY);
		$m->ReplyClientId = $replyClientId;
		$m->RequestId = $requestId;
		$m->Payload = $payload;

		$this->send($m);
	}

	/**
	 * Make a request to a client
	 *
	 * @param   string   $requestClientId  the client to send the request to
	 * @param   Array    $payload          the payload to send to the client
	 * @param   Closure  $handler          the reply handler
	 * @return  void
	 */
	public function request($requestClientId, Array $payload, Closure $handler)
	{
		// generate a new requestId
		$requestId = $this->uuid();

		// store the handler
		$this->handlers[static::MESSAGE_TYPE_REPLY][$requestId] = $handler;

		$m = $this->bareMessage(static::MESSAGE_TYPE_REQUEST);
		$m->RequestClientId = $requestClientId;
		$m->RequestId = $requestId;
		$m->Payload = $payload;

		$this->send($m);
	}

	/**
	 * Emit a given event
	 *
	 * @param   string  $event the event to event to
	 * @param   Array   $payload the payload to send
	 * @return  void
	 */
	public function emit($event, Array $payload)
	{
		$m = $this->bareMessage(static::MESSAGE_TYPE_STANDARD);
		$m->Event = $event;
		$m->Payload = $payload;

		$this->send($m);
	}


	////////////////////////////
	// BEGIN PRIVATE METHODS //
	////////////////////////////

	/**
	 * Generate a new UUID
	 * Ref: https://gist.github.com/dahnielson/508447#file-uuid-php-L68-L96
	 *
	 * @return  string
	 */
	protected function uuid()
	{
		return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

			// 32 bits for "time_low"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),

			// 16 bits for "time_mid"
			mt_rand(0, 0xffff),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand(0, 0x0fff) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand(0, 0x3fff) | 0x8000,

			// 48 bits for "node"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);
	}

	/**
	 * Create a new client instance by fetching it from the server
	 *
	 * @return  stdClass  the client
	 */
	protected function newClient()
	{
		$context = [
			'http' => [
				'header'  => "Content-type: application/json\r\n",
				'method'  => 'POST',
				'content' => '{}',
			],
		];

		return $this->client = $this->serverRequest('/v1/clients', $context);
	}

	/**
	 * Execute a request against the server
	 *
	 * @param   string  $path     the server path
	 * @param   array  $context  contextual array for stream_context_create
	 * @return  stdClass the decoded json body
	 */
	protected function serverRequest($path, $context)
	{
		if (strpos($path, '/') === 0)
		{
			$path = substr($path, 1);
		}

		$url = sprintf('http://%s/%s', $this->serverUrl, $path);

		$context = stream_context_create($context);
		$result = @file_get_contents($url, false, $context);

		if ($result === false)
		{
			throw new ClientException('Failed connecting to ' . $url);
		}

		if (($result = json_decode($result)) === false)
		{
			throw new ClientException("Failed decoding json response from server");
		}

		if (empty($result))
		{
			throw new ClientException("Did not receive a valid client instance");
		}

		return $result;
	}

	/**
	 * Open the websocket connection to the eventsocket server
	 *
	 * @return  void
	 */
	protected function connectWs()
	{
		$wsUrl = sprintf('ws://%s/v1/clients/%s/ws', $this->serverUrl, $this->getId());

		$this->ws = new WebsocketClient($wsUrl, ['timeout' => 30]);
		$this->ws->setTimeout(30);
	}

	/**
	 * Decode a message from the socket
	 *
	 * @param   string  $message  the message to decode
	 * @return  stdClass
	 */
	protected function decodeMessage($message)
	{
		if (($message = json_decode($message)) === false)
		{
			throw new ClientException("Failed decoding json response from server eventsocket");
		}

		return $message;
	}

	/**
	 * Encode a message to be put on the socket
	 *
	 * @param   stdClass  $message  the message to encode
	 * @return  string
	 */
	protected function encodeMessage($message)
	{
		if (($message = json_encode($message)) === false)
		{
			throw new ClientException("Failed encoding message");
		}

		return $message;
	}

	/**
	 * Instantiate a new message
	 *
	 * @param   int  $type  the message tyoe
	 * @return  stdClass  the new instance
	 */
	protected function bareMessage($type)
	{
		$m = [
			'MessageType' => $type,
			'Event' => null,
			'RequestId' => null,
			'ReplyClientId' => null,
			'RequestClientId' => null,
			'Payload' => [],
			'Error' => null,
		];

		return (object) $m;
	}

	/**
	 * Send a Message
	 *
	 * @param   string  $message  the message to send
	 * @return  void
	 */
	protected function send($message)
	{
		$message = $this->encodeMessage($message);
		$this->ws->send($message);
	}

	/**
	 * Injest, and route an incomming message
	 *
	 * @param   stdClass  $message  the incomming message
	 * @return  void
	 */
	protected function injest($message)
	{
		switch($message->MessageType)
		{
			case static::MESSAGE_TYPE_BROADCAST:
				$this->handleBroadcast($message);
				break;
			case static::MESSAGE_TYPE_STANDARD:
				$this->handleStandard($message);
				break;
			case static::MESSAGE_TYPE_REQUEST:
				$this->handleRequest($message);
				break;
			case static::MESSAGE_TYPE_REPLY:
				$this->handleReply($message);
				break;
			default:
				throw new ClientException('Unknown MessageType: ' . var_export($message->MessageType, true));
		}
	}

	/**
	 * Handle an incomming Broadcast message
	 *
	 * @param   stdClass  $message  the broadcast message
	 * @return  void
	 */
	protected function handleBroadcast($message)
	{
		// enumerate each of the broadcast handlers
		foreach ($this->handlers[static::MESSAGE_TYPE_BROADCAST] as $handle)
		{
			$handle($message);
		}
	}

	/**
	 * Handle an incomming Standard event message (from a subscription)
	 *
	 * @param   stdClass  $message  the incomming event message
	 * @return  void
	 */
	protected function handleStandard($message)
	{
		// if we dont have any handlers for this event, then just return out
		if (!array_key_exists($message->Event, $this->handlers[static::MESSAGE_TYPE_STANDARD]))
		{
			return;
		}

		// pass the message to each of the corrosponding handlers
		foreach ($this->handlers[static::MESSAGE_TYPE_STANDARD][$message->Event] as $handle)
		{
			$handle($message);
		}
	}

	/**
	 * Handle an incomming request
	 *
	 * @param   stdClass  $message  the request message
	 * @return  void
	 */
	protected function handleRequest($message)
	{
		// enumerate each of the broadcast handlers
		foreach ($this->handlers[static::MESSAGE_TYPE_REQUEST] as $handle)
		{
			$handle($message);
		}
	}

	/**
	 * Handle an incomming reply
	 *
	 * @param   stdClass  $message  the request message
	 * @return  void
	 */
	protected function handleReply($message)
	{
		// if we dont have a handler for this request, then something is wrong
		if (!array_key_exists($message->RequestId, $this->handlers[static::MESSAGE_TYPE_REPLY]))
		{
			throw new ClientException("Request handler not found");
		}

		// handle the event
		$this->handlers[static::MESSAGE_TYPE_REPLY][$message->RequestId]($message);

		// cleanup the handler, as it is no longer needed
		unset($this->handlers[static::MESSAGE_TYPE_REPLY][$message->RequestId]);
	}

}
