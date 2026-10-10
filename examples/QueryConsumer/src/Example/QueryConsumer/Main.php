<?php

declare(strict_types=1);

namespace Example\QueryConsumer;

use NhanAZ\QueryServer\Main as QueryServer;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;

final class Main extends PluginBase {

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if ($command->getName() !== "qtest") {
			return false;
		}

		$queryServer = $this->getServer()->getPluginManager()->getPlugin("QueryServer");
		if (!$queryServer instanceof QueryServer) {
			$sender->sendMessage("QueryServer plugin is not loaded.");
			return true;
		}

		$host = $args[0] ?? "test.pmmp.io";
		$portText = $args[1] ?? "19132";
		if ($host === "" || !ctype_digit($portText) || (int) $portText < 1 || (int) $portText > 65535) {
			$sender->sendMessage("Usage: /qtest [host] [port 1-65535]");
			return true;
		}
		$port = (int) $portText;
		$api = $queryServer->getApi();
		$sender->sendMessage("Querying {$host}:{$port} via QueryServer...");

		$api->query($host, $port, function (array $result) use ($sender): void {
			if (($result["ok"] ?? false) !== true) {
				$error = $result["error"] ?? null;
				$sender->sendMessage("Query failed: " . (is_string($error) ? $error : "unknown error"));
				return;
			}

			$source = $result["source"] ?? null;
			$sender->sendMessage("Source: " . (is_string($source) ? $source : "unknown"));
			$data = $result["data"] ?? null;
			$shown = false;
			if (is_array($data)) {
				$hostName = $data["HostName"] ?? null;
				$players = $data["Players"] ?? null;
				$maxPlayers = $data["MaxPlayers"] ?? null;
				if (is_string($hostName) && is_int($players) && is_int($maxPlayers)) {
					$sender->sendMessage("HostName: " . $hostName);
					$sender->sendMessage("Players: " . $players . "/" . $maxPlayers);
					$shown = true;
				}
			} elseif (is_object($data)) {
				$fields = get_object_vars($data);
				$motd = $fields["motd"] ?? null;
				$playerInfo = $fields["players"] ?? null;
				if (is_object($motd) && is_object($playerInfo)) {
					$clean = get_object_vars($motd)["clean"] ?? null;
					$online = get_object_vars($playerInfo)["online"] ?? null;
					$max = get_object_vars($playerInfo)["max"] ?? null;
					if (is_string($clean) && is_int($online) && is_int($max)) {
						$sender->sendMessage("MOTD: " . $clean);
						$sender->sendMessage("Online: " . $online . "/" . $max);
						$shown = true;
					}
				}
			}
			if (!$shown) {
				$sender->sendMessage("Result payload received.");
			}
		});

		return true;
	}
}
