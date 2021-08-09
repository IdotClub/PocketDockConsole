<?php

namespace PocketDockConsole;

use pocketmine\thread\Thread;
use pocketmine\utils\Terminal;
use Wrench\Server;

class PDCServer extends Thread {

	public $null = null;
	public $buffer = "";
	public $stuffToSend = "";
	public $jsonStream = "";
	public $stuffTitle = "";
	public $stop = false;

	public function __construct($host, $port, $logger, $loader, $password, $html, $backlog, $legacy = false) {
		$this->host = $host;
		$this->port = $port;
		$this->password = $password;
		$this->logger = $logger;
		$this->loader = $loader;
		$this->data = $html;
		$this->backlog = $backlog;
		$this->clienttokill = "";
		$this->sendUpate = false;
		$this->legacy = $legacy;
		$this->start();
		$this->log("Started SocksServer on " . $this->host . ":" . $this->port);
	}

	public function log($data) {
		$this->logger->info("[PDC] " . $data);
	}

	public function getBuffer() {
		return $this->buffer;
	}

	public function onRun() : void {
		require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
		set_exception_handler(function ($ex) {
			//var_dump($ex);
			$this->logger->debug($ex->getMessage());
		});

		if (!$this->legacy) {
			Terminal::init();
		}

		$server = new Server('ws://' . $this->host . ':' . $this->port, ["logger" => function ($msg, $pri) {
		}]);

		$server->registerApplication("app", new PDCApp($this, $this->password));
		$server->addListener(Server::EVENT_SOCKET_CONNECT, function ($data, $other) {
			$header = $other->getSocket()->receive();
			if ($this->isHTTP($header)) {
				$other->getSocket()->send("HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n" . $this->data);
				$other->close(200);
			} else {
				$other->onData($header);
			}
		});
		$server->getConnectionManager()->listen();
		$property = new \ReflectionProperty($server, 'applications');
		$property->setAccessible(true);
		while (true) {
			if ($this->stop) {
				exit(1);
			}
			/*
			 * If there's nothing changed on any of the sockets, the server
			 * will sleep and other processes will have a change to run. Control
			 * this behaviour with the timeout options.
			 */
			$server->getConnectionManager()->selectAndProcess();
			/*
			 * If the application wants to perform periodic operations or queries and push updates to clients based on the result then that logic can be implemented in the 'onUpdate' method.
			 */
			foreach ($property->getValue($server) as $application) {
				if (method_exists($application, 'onUpdate')) {
					$application->onUpdate();
				}
			}
			usleep(2);
		}
	}

	public function isHTTP($data) {
		if (strpos($data, "websocket")) {
			return false;
		} else {
			return true;
		}
	}

	public function stop() {
		$this->stop = true;
	}

}
