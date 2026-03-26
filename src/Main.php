<?php

declare(strict_types=1);

namespace NhanAZ\QueryServer;

use NhanAZ\QueryServer\libpmquery\PMQuery;
use NhanAZ\QueryServer\libpmquery\PmQueryException;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\TextFormat as TF;
use function explode;
use function str_contains;

final class Main extends PluginBase {

	private const PREFIX = TF::YELLOW . ">" . TF::WHITE . " ";
	private const ERROR_PREFIX = TF::YELLOW . ">" . TF::RED . " ";
	private const ARRAY_SEPARATOR = TF::WHITE . ", " . TF::GREEN;

	public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
		if (strtolower($cmd->getName()) !== "query") {
			return false;
		}

		return $this->handleQuery($sender, $args);
	}

	private function handleQuery(CommandSender $sender, array $args): bool {
		if (!isset($args[0])) {
			$sender->sendMessage(self::error("Usage: /query <domain/ip[:port]> [port]"));
			$sender->sendMessage(self::error("Example: /query test.pmmp.io 19132"));
			return true;
		}

		if ($sender instanceof Player) {
			$sender->sendMessage(self::error("Please use the command on the console!"));
			return true;
		}

		// If a port is provided separately, use direct PMQuery; otherwise use API v3 (async).
		if (isset($args[1])) {
			$this->getServer()->getAsyncPool()->submitTask(new FallbackQueryTask($args[0], (int) $args[1]));
			return true;
		}

		$this->getServer()->getAsyncPool()->submitTask(new QueryTask($args[0], $this->buildUserAgent()));
		return true;
	}

	public static function handleQueryResult(mixed $result): void {
		$console = new ConsoleCommandSender(Server::getInstance(), Server::getInstance()->getLanguage());

		$address = is_array($result) ? ($result["address"] ?? null) : null;
		[$host, $port] = self::splitAddress($address);

		$apiShown = false;
		$fallbackShown = false;

		if (is_array($result) && ($result["ok"] ?? false) === true && is_object($result["data"] ?? null)) {
			/** @var object $status */
			$status = $result["data"];
			$apiShown = self::sendApiStatus($console, $status);
		} else {
			$error = is_array($result) ? ($result["error"] ?? "Unknown error") : "No data returned";
			$console->sendMessage(self::info("Primary API failed: " . $error));
		}

		if ($host !== null && $port !== null) {
			$console->sendMessage(self::info("Using fallback UDP query..."));
			Server::getInstance()->getAsyncPool()->submitTask(new FallbackQueryTask($host, $port));
			return;
		}

		if (!$apiShown && !$fallbackShown) {
			$console->sendMessage(self::error("The server is offline or has blocked queries!"));
			$console->sendMessage(self::info("Try another query method using /query <domain> <port>"));
		}
	}

	private static function sendApiStatus(CommandSender $sender, object $status): bool {
		if (!($status->online ?? false)) {
			return false;
		}

		$serverInfo = ($status->ip ?? "unknown") . ":" . ($status->port ?? "unknown");
		$sender->sendMessage(self::info("Domain: ") . TF::GREEN . str_replace(":", TF::WHITE . ":" . TF::GREEN, $serverInfo));

		$fields = [
			"ip" => "IP/Port",
			"debug->ping" => "Ping",
			"debug->query" => "Query",
			"debug->srv" => "SRV",
			"debug->querymismatch" => "QueryMisMatch",
			"debug->ipinsrv" => "IPInSRV",
			"debug->cnameinsrv" => "CNameInSRV",
			"debug->animatedmotd" => "AnimatedMotd",
			"debug->cachetime" => "CacheTime",
			"motd->clean" => "Motd",
			"players->online" => "Online",
			"players->max" => "Max",
			"players->list" => "Players",
			"players->uuid" => "UUIDS",
			"version" => "Version",
			"protocol" => "Protocol",
			"hostname" => "HostName",
			"icon" => "Icon",
			"software" => "Software",
			"map" => "Map",
			"plugins->raw" => "Plugins",
			"mods->raw" => "Mods",
			"info->clean" => "Info"
		];

		foreach ($fields as $field => $label) {
			self::sendField($sender, $label, self::getNestedValue($status, $field));
		}

		return true;
	}

	/**
	 * Fallback handler for libpmquery results.
	 *
	 * @param array<string, mixed> $query
	 */
	private static function sendLegacyQuery(CommandSender $sender, array $query): void {
		$keys = [
			"GameName" => "GameName",
			"HostName" => "HostName",
			"Protocol" => "Protocol",
			"Version" => "Version",
			"Players" => "Players",
			"MaxPlayers" => "MaxPlayers",
			"ServerId" => "ServerId",
			"Map" => "Map",
			"GameMode" => "GameMode",
			"NintendoLimited" => "NintendoLimited",
			"IPv4Port" => "IPv4Port",
			"IPv6Port" => "IPv6Port",
			"Extra" => "Extra"
		];

		foreach ($keys as $key => $label) {
			self::sendField($sender, $label, $query[$key] ?? null);
		}
	}

	private static function sendField(CommandSender $sender, string $label, mixed $value): void {
		if ($value === null || $value === "") {
			$sender->sendMessage(self::info("$label: ") . TF::RED . "Unavailable");
			return;
		}

		if (is_array($value)) {
			$value = implode(self::ARRAY_SEPARATOR, $value);
		} elseif (is_bool($value)) {
			$value = $value ? "true" : "false";
		}

		$sender->sendMessage(self::info("$label: ") . TF::GREEN . (string) $value);
	}

	private static function getNestedValue(object $payload, string $path): mixed {
		$current = $payload;
		foreach (explode("->", $path) as $segment) {
			if (!is_object($current) || !property_exists($current, $segment)) {
				return null;
			}
			$current = $current->{$segment};
		}

		return $current;
	}

	private static function info(string $message): string {
		return self::PREFIX . $message;
	}

	private static function error(string $message): string {
		return self::ERROR_PREFIX . $message;
	}

	public static function handleFallbackResult(mixed $result): void {
		$console = new ConsoleCommandSender(Server::getInstance(), Server::getInstance()->getLanguage());

		if (!is_array($result) || (($result["ok"] ?? false) !== true) || !is_array($result["data"] ?? null)) {
			$error = is_array($result) ? ($result["error"] ?? "Unknown error") : "No data returned";
			$console->sendMessage(self::error("Fallback query failed: " . $error));
			return;
		}

		$host = $result["host"] ?? "unknown";
		$port = $result["port"] ?? 0;
		$console->sendMessage(self::info("Fallback UDP query result (udp://{$host}:{$port}):"));
		self::sendLegacyQuery($console, $result["data"]);
	}

	private static function splitAddress(?string $address): array {
		if ($address !== null && str_contains($address, ":")) {
			[$host, $port] = explode(":", $address, 2);
			$host = $host !== "" ? $host : null;
			$port = $port !== "" ? (int) $port : null;
			return [$host, $port];
		}

		return [null, null];
	}

	private function buildUserAgent(): string {
		return sprintf(
			"QueryServer/%s (PocketMine-MP plugin; %s)",
			$this->getDescription()->getVersion(),
			PHP_OS_FAMILY
		);
	}
}
