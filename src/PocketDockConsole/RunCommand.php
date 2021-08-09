<?php

namespace PocketDockConsole;

use pocketmine\console\ConsoleCommandSender;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\Internet;
use pocketmine\utils\Process;
use Webmozart\PathUtil\Path;
use const pocketmine\PLUGIN_PATH;

class RunCommand extends Task {

	public $temp = [];
	public $owner = null;
	protected $currentTick = 0;


	public function __construct($owner) {
		$this->owner = $owner;
		$this->sender = new ConsoleCommandSender(Server::getInstance(), Server::getInstance()->getLanguage());
	}

	public function onRun() : void {
		$buffer = $this->owner->thread->getBuffer();
		if (substr($buffer, 0, 6) == "{JSON}") {
			$buffer = str_replace("{JSON}", "", $buffer);
			$this->parseJSON(trim($buffer));
			$this->owner->thread->buffer = "";
			$this->updateInfo();
		} elseif (substr($buffer, -1) == "\r" && $buffer && !$this->isJSON(trim($buffer)) && !strpos($buffer, "{JSON}")) {
			$buffer = trim($buffer);
			echo $buffer . "\n";
			$this->owner->attachment->log("info", $buffer);
			$this->owner->getServer()->dispatchCommand($this->sender, $buffer);
			$this->owner->thread->buffer = "";
			$this->updateInfo();
		} elseif ($this->isJSON(trim($buffer)) && trim($buffer) != "") {
			$this->parseJSON($buffer);
			$this->owner->thread->buffer = "";
			$this->updateInfo();
		}

		if ($this->owner->thread->sendUpdate) {
			$this->updateInfo();
			$this->owner->sendFiles();
		}

		$this->owner->thread->sendUpdate = false;

		if ($this->currentTick % 20) {
			$this->updateInfo();
			$this->owner->thread->sendUpdate = false;
			$this->owner->thread->buffer = "";
		}

		if ($this->owner->thread->clearstream) {
			$this->owner->attachment->stream = "";
			$this->owner->thread->clearstream = false;
		}

		if ($this->currentTick % 10) {
			$this->updateInfo();
		}
		$this->currentTick++;
	}

	public function parseJSON($string) {
		$data = json_decode($string, true);
		if ($data == null) {
			return false;
			$this->owner->getLogger()->info("File is not JSON");
		}
		$keys = array_keys($data);
		switch ($keys[0]) {
			case "op":
				$this->owner->getServer()->addOp($data[$keys[0]]['name']);
				$this->owner->getLogger()->info($data[$keys[0]]['name'] . " is now op!");
				break;
			case "kick":
				if (($player = $this->owner->getServer()->getPlayerExact($data[$keys[0]]['name'])) instanceof Player) {
					$player->kick();
					$this->owner->getLogger()->info($data[$keys[0]]['name'] . " has been kicked!");
				} else {
					$this->owner->getLogger()->info($data[$keys[0]]['name'] . " is not a valid player!");
				}
				break;
			case "ban":
				$this->owner->getServer()->getNameBans()->addBan($data[$keys[0]]['name']);
				$this->owner->getLogger()->info($data[$keys[0]]['name'] . " has been banned!");
				break;
			case "banip":
				if (($player = $this->owner->getServer()->getPlayerExact($data[$keys[0]]['name'])) instanceof Player) {
					$this->owner->getServer()->getIPBans()->addBan($player->getAddress());
				}
				break;
			case "unban":
				if (preg_match("/^([01]?\\d\\d?|2[0-4]\\d|25[0-5])\\.([01]?\\d\\d?|2[0-4]\\d|25[0-5])\\.([01]?\\d\\d?|2[0-4]\\d|25[0-5])\\.([01]?\\d\\d?|2[0-4]\\d|25[0-5])$/", $data[$keys[0]]['name'])) {
					$this->owner->getServer()->getIPBans()->remove($data[$keys[0]]['name']);
				} else {
					$this->owner->getServer()->getNameBans()->remove($data[$keys[0]]['name']);
				}
				$this->owner->getLogger()->info($data[$keys[0]]['name'] . " has been unbanned!");
				break;
			case "deop":
				$this->owner->getServer()->removeOp($data[$keys[0]]['name']);
				$this->owner->getLogger()->info($data[$keys[0]]['name'] . " is no longer op!");
				break;
			case "unbanip":
				$this->owner->getServer()->getIPBans()->remove($data[$keys[0]]['ip']);
				break;
			case "updateinfo":
				$this->updateInfo();
				break;
			case "changegm":
				if (($player = $this->owner->getServer()->getPlayerExact($data[$keys[0]]['name'])) instanceof Player) {
					$player->setGamemode($data[$keys[0]]['mode']);
				} else {
					$this->owner->getLogger()->info($data[$keys[0]]['name'] . " is not a valid player!");
				}
				break;
			case "getCode":
				$code = file_get_contents($data[$keys[0]]['file']);
				$data = ["type" => "code", "code" => $code];
				$this->owner->thread->jsonStream .= json_encode($data) . "\n";
				break;
			case "update":
				if ($this->owner->getConfig()->get("editfiles")) {
					$file = $data[$keys[0]]['file'];
					$code = str_replace("{newline}", "\n", $data[$keys[0]]['code']);
					$this->owner->getLogger()->info($file . " has been updated!");
					file_put_contents($file, $code);
				}
				break;
			case "uploadinit":
				if ($this->owner->getConfig()->get("editfiles")) {
					$this->temp['file'] = $data[$keys[0]]['file'];
					$this->temp['length'] = $data[$keys[0]]['length'];
					$this->temp['location'] = substr($data[$keys[0]]['location'], 0, -1);
					$this->temp['code'] = $data[$keys[0]]['filedata'];
					$this->temp['part'] = 0;
					$this->owner->getLogger()->info("Starting upload of: " . $this->temp['file']);
					$code = base64_decode($this->temp['code']);
					file_put_contents($this->temp['location'] . $this->temp['file'], $code);
					$this->owner->getLogger()->info($this->temp['file'] . " has been uploaded to " . $this->temp['location'] . "!");
					$this->temp = [];
				}
				break;
			case "uploaddata":
				if ($this->owner->getConfig()->get("editfiles")) {
					$file = $data[$keys[0]]['file'];
					if ($file == $this->temp['file']) {
						$this->temp['part']++;
						$this->temp['code'] .= implode("", $data[$keys[0]]['code']);
						$this->owner->getLogger()->info(round(($this->temp['part'] / $this->temp['length']) * 100) . "% of " . $this->temp['file'] . " has been uploaded!");
					}
					if ($file == $this->temp['file'] && $this->temp['part'] == $this->temp['length']) {
						$code = base64_decode($this->temp['code']);
						file_put_contents($this->temp['location'] . $file, $code);
						$this->owner->getLogger()->info($this->temp['file'] . " has been uploaded to " . $this->temp['location'] . "!");
						$this->temp = [];
					}
				}
				break;
			case "selectedPlugins":
				if ($this->owner->getConfig()->get("editfiles")) {
					$plugins = $data[$keys[0]]['plugins'];
					$this->updatePlugins($plugins);
				}
				break;
			case "removePlugins":
				if ($this->owner->getConfig()->get("editfiles")) {
					$plugins = $data[$keys[0]]['plugins'];
					$this->owner->getLogger()->info("Removing Plugins");
					$this->removePlugins($plugins);
				}
				break;
		}
		return null;
	}

	/*public function isJSON($string) {
		json_decode($string);
		return (json_last_error() == JSON_ERROR_NONE);
	}*/

	public function updateInfo($user = "") {
		$data = ["type" => "data", "data" => ["players" => $this->sendPlayers($user), "bans" => $this->sendNameBans(), "ipbans" => $this->sendIPBans(), "ops" => $this->sendOps(), "plugins" => $this->sendPlugins()]];
		$this->owner->thread->jsonStream .= json_encode($data) . "\n";

		$d = Process::getRealMemoryUsage();

		$u = Process::getAdvancedMemoryUsage();
		$usage = sprintf("%g/%g/%g/%g MB @ %d threads", round(($u[0] / 1024) / 1024, 2), round(($d[0] / 1024) / 1024, 2), round(($u[1] / 1024) / 1024, 2), round(($u[2] / 1024) / 1024, 2), Process::getThreadCount());

		$server = Server::getInstance();
		$online = count($server->getOnlinePlayers());
		$connecting = $server->getNetwork()->getConnectionCount() - $online;
		$bandwidthStats = $server->getNetwork()->getBandwidthTracker();

		$title = "\x1b]0;" . $server->getName() . " " .
			$server->getPocketMineVersion() .
			" | Online $online/" . $server->getMaxPlayers() .
			($connecting > 0 ? " (+$connecting connecting)" : "") .
			" | Memory " . $usage .
			" | U " . round($bandwidthStats->getSend()->getAverageBytes() / 1024, 2) .
			" D " . round($bandwidthStats->getReceive()->getAverageBytes() / 1024, 2) .
			" kB/s | TPS " . $server->getTicksPerSecondAverage() .
			" | Load " . $server->getTickUsageAverage() . "%\x07";

		$this->owner->thread->stuffTitle = $title;
		return true;
	}

	public function sendPlayers($user) {
		$names = [];
		$players = $this->owner->getServer()->getOnlinePlayers();
		foreach ($players as $p) {
			$names[] = $p->getName();
		}
		if ($user !== "") {
			$key = array_search($user, $names);
			unset($names[$key]);
		}
		return $names;
	}

	public function sendNameBans() {
		$barray = [];
		$bans = $this->owner->getServer()->getNameBans();
		$bans = $bans->getEntries();
		foreach ($bans as $ban) {
			$barray[] = $ban->getName();
		}
		return $barray;
	}

	public function sendIPBans() {
		$barray = [];
		$bans = $this->owner->getServer()->getIPBans();
		$bans = $bans->getEntries();
		foreach ($bans as $ban) {
			$barray[] = $ban->getName();
		}
		return $barray;
	}

	public function sendOps() {
		$oarray = [];
		$ops = $this->owner->getServer()->getOps();
		$ops = $ops->getAll(true);
		foreach ($ops as $op) {
			$oarray[] = $op;
		}
		return $oarray;
	}

	public function sendPlugins() {
		foreach ($this->owner->getServer()->getPluginManager()->getPlugins() as $plugin) {
			$names[] = str_replace(" ", "-", $plugin->getName());
		}
		return $names;
	}

	public function updatePlugins($plugins) {
		foreach ($plugins as $pl) {
			$plugininfo = $this->getUrl($pl);
			file_put_contents(Server::getInstance()->getPluginPath() . $plugininfo["name"] . ".phar", Internet::getURL($plugininfo['link'])->getBody());
			$this->owner->getLogger()->info($plugininfo["name"] . " is now installed. Please restart or reload the server.");
		}
	}

	# Taken from PocketMine-MP (new versions) for backwards compatibility

	public function getUrl($id) {
		$json = json_decode(Internet::getURL("https://poggit.pmmp.io/plugins.min.json")->getBody(), true);
		foreach ($json as $index => $res) {
			if (strval($res["id"]) == strval($id)) {
				$dlink = $res["artifact_url"];
				return ["repo" => $res["repo_name"], "name" => $res["name"], "link" => $dlink];
			}
		}
	}

	public function removePlugins($plugins) {
		$path = Server::getInstance()->getPluginPath();
		foreach ($this->owner->getServer()->getPluginManager()->getPlugins() as $plugin) {
			if (in_array($plugin->getName(), $plugins)) {
				$pluginPath = Path::join($path, $plugin->getName() . ".phar");
				if (file_exists($pluginPath)) {
					unlink($pluginPath);
					$this->owner->getLogger()->info($plugin->getName() . " was removed. Please restart or reload the server.");
				} else {
					$this->owner->getLogger()->info("Unable to remove " . $plugin->getName() . " automatically. Please remove it manually and reload the server.");
				}
			}
		}
	}

	public function isJSON($string) {
		return !preg_match('/[^,:{}\\[\\]0-9.\\-+Eaeflnr-u \\n\\r\\t]/', preg_replace('/"(\\.|[^"\\\\])*"/', '', $string));
	}

}
