<?php

namespace PocketDockConsole;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Main extends PluginBase implements Listener {

	public $attachment = null;

	public function onLoad() : void {
		require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
		$this->getLogger()->info(TextFormat::WHITE . "Loaded");
	}

	public function onEnable() : void {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->saveDefaultConfig();
		$this->reloadConfig();
		$this->getLogger()->info(TextFormat::DARK_GREEN . "Enabled");
		$this->setPassword();
		$this->legacy = true;
		if (method_exists($this->getServer(), "getNetwork")) {
			$this->legacy = false;
		}
		$this->thread = new PDCServer("0.0.0.0", $this->getConfig()->get("port"), $this->getServer()->getLogger(), $this->getServer()->getLoader(), $this->getConfig()->get("password"), stream_get_contents($pluginIndex = $this->getResource("PluginIndex.html")), $this->getConfig()->get("backlog"), $this->legacy);
		@fclose($pluginIndex);
		$this->rc = new RunCommand($this);
		$this->getScheduler()->scheduleRepeatingTask($this->rc, 1);
		$this->lastBufferLine = "";
		$this->attachment = new Attachment($this->thread);
		$this->getServer()->getLogger()->addAttachment($this->attachment);
	}

	public function setPassword() {
		if ($this->getConfig()->get("password") == "PocketDockRules!") {
			$this->getConfig()->set("password", $this->getServer()->getConfigGroup()->getConfigString("rcon.password", ""));
			$this->getLogger()->info("The password is now the RCON password.");
			$this->getLogger()->info("If you would like to change the password, please do so in the PDC config.");
			$this->getConfig()->save();
			$this->reloadConfig();
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
		switch ($command->getName()) {
			case "consoleclients":
				if (!$sender->hasPermission("pocketdockconsole.command.consoleclients")) {
					$sender->sendMessage(TextFormat::RED . "[PDC] Get some permissions...");
					return true;
				}
				$authedclients = explode(";", $this->thread->connectedips);
				if (count($authedclients) < 2) {
					$sender->sendMessage("[PDC] There are no connected clients");
					return true;
				}
				$sender->sendMessage("[PDC] Connected client(s) are: " . implode("; ", $authedclients));
				return true;
			case "killclient":
				if (!$sender->hasPermission("pocketdockconsole.command.killclient")) {
					$sender->sendMessage(TextFormat::RED . "[PDC] Get some permissions...");
					return true;
				}
				if (!isset($args[0])) {
					$sender->sendMessage($command->getUsage());
					return true;
				}
				$sender->sendMessage("[PDC] Killing client: " . $args[0]);
				$this->thread->clienttokill = $args[0];
				return true;
			default:
				return false;
		}
	}

	public function PlayerLoginEvent(PlayerLoginEvent $event) {
		$this->rc->updateInfo();
		$this->sendFiles();
	}

	public function sendFiles() {
		if ($this->getConfig()->get("viewfiles")) {
			$this->thread->jsonStream .= json_encode(["type" => "files", "files" => $this->getFiles(realpath($this->getServer()->getDataPath()))]) . "\n";
		}
		return false;
	}

	public function getFiles($dir) {
		$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach ($objects as $name => $object) {
			if (!strpos($name, "bin")) {
				$names[] = $name;
			}
		}
		return $names;
	}

	public function onPlayerQuitEvent(PlayerQuitEvent $event) {
		$name = $event->getPlayer()->getName();
		$this->rc->updateInfo($name);
	}

	public function onPlayerRespawnEvent(PlayerRespawnEvent $event) {
		$this->rc->updateInfo();
	}

	public function onDisable() : void {
		$this->getLogger()->info(TextFormat::DARK_RED . "Disabled");
		$this->thread->stop();
	}

}
