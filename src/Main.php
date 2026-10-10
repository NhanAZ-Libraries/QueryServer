<?php

declare(strict_types=1);

namespace NhanAZ\QueryServer;

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

	private static ?self $instance = null;

	/** @var array<int, callable(array<string, mixed>):void> */
	private array $callbacks = [];
	private int $nextRequestId = 1;
	private API $api;

	public static function getInstance(): self {
		return self::$instance ?? throw new \LogicException("Plugin instance not ready yet");
	}

	public function getApi(): API {
		return $this->api;
	}

	protected function onLoad(): void {
		self::$instance = $this;
		$this->api = new API($this);
	}

	public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
		if (strtolower($cmd->getName()) !== "query") {
			return false;
		}

		return $this->handleQuery($sender, $args);
	}

	/** @param array<string> $args */
	private function handleQuery(CommandSender $sender, array $args): bool {
		$host = $args[0] ?? null;
		if (!is_string($host) || $host === "") {
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
			$portText = $args[1];
			if (!ctype_digit($portText) || (int) $portText < 1 || (int) $portText > 65535) {
				$sender->sendMessage(self::error("Port must be between 1 and 65535."));
				return true;
			}
			$this->getServer()->getAsyncPool()->submitTask(new FallbackQueryTask($host, (int) $portText));
			return true;
		}

		$this->getServer()->getAsyncPool()->submitTask(new QueryTask($host, $this->buildUserAgent()));
		return true;
	}

	public static function handleQueryResult(mixed $result): void {
		$console = new ConsoleCommandSender(Server::getInstance(), Server::getInstance()->getLanguage());

		$address = is_array($result) && is_string($result["address"] ?? null) ? $result["address"] : null;
		$requestId = is_array($result) && is_int($result["requestId"] ?? null) ? $result["requestId"] : null;
		[$host, $port] = self::splitAddress($address);

		$apiShown = false;
		$status = null;

		if (is_array($result) && ($result["ok"] ?? false) === true && is_object($result["data"] ?? null)) {
			$status = $result["data"];
			$apiShown = self::sendApiStatus($console, $status);
		}

		if ($apiShown) {
			if ($requestId !== null) {
				self::getInstance()->deliverCallback($requestId, [
					"ok" => true,
					"source" => "api",
					"address" => $address,
					"data" => $status
				]);
			}
			if ($requestId === null && $host !== null && $port !== null) {
				$console->sendMessage(self::info("Using fallback UDP query..."));
				Server::getInstance()->getAsyncPool()->submitTask(new FallbackQueryTask($host, $port, debug: true));
			}
			return;
		}

		$error = is_array($result) && is_string($result["error"] ?? null) ? $result["error"] : "offline or blocked";
		$console->sendMessage(self::info("Primary API failed: " . $error));

		if ($host !== null && $port !== null) {
			$console->sendMessage(self::info("Using fallback UDP query..."));
			Server::getInstance()->getAsyncPool()->submitTask(new FallbackQueryTask($host, $port, requestId: $requestId, debug: true));
			return;
		}

		$console->sendMessage(self::error("The server is offline or has blocked queries!"));
		$console->sendMessage(self::info("Try another query method using /query <domain> <port>"));
		if ($requestId !== null) {
			self::getInstance()->deliverCallback($requestId, ["ok" => false, "source" => "api", "error" => $error]);
		}
	}

	private static function sendApiStatus(CommandSender $sender, object $status): bool {
		if (!($status->online ?? false)) {
			return false;
		}

		$ip = $status->ip ?? null;
		$port = $status->port ?? null;
		$serverInfo = (is_scalar($ip) ? (string) $ip : "unknown") . ":" . (is_scalar($port) ? (string) $port : "unknown");
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
			$parts = [];
			foreach ($value as $item) {
				if (is_scalar($item)) {
					$parts[] = (string) $item;
				}
			}
			$value = implode(self::ARRAY_SEPARATOR, $parts);
		} elseif (is_bool($value)) {
			$value = $value ? "true" : "false";
		} elseif (!is_scalar($value) && !$value instanceof \Stringable) {
			$value = "Unavailable";
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

		if (!is_array($result)) {
			$console->sendMessage(self::error("Fallback query failed: Invalid result"));
			return;
		}

		$requestId = is_int($result["requestId"] ?? null) ? $result["requestId"] : null;

		if (($result["ok"] ?? false) !== true || !is_array($result["data"] ?? null)) {
			$error = is_string($result["error"] ?? null) ? $result["error"] : "Unknown error";
			if ($requestId !== null && self::getInstance()->deliverCallback($requestId, ["ok" => false, "source" => "udp", "error" => $error])) {
				return;
			}
			$console->sendMessage(self::error("Fallback query failed: " . $error));
			return;
		}

		$host = is_string($result["host"] ?? null) ? $result["host"] : "unknown";
		$port = is_int($result["port"] ?? null) ? $result["port"] : 0;
		/** @var array<string, mixed> $data */
		$data = $result["data"];
		$query = is_array($result["query"] ?? null) ? $result["query"] : null;
		$queryError = is_string($result["queryError"] ?? null) ? $result["queryError"] : null;
		$payload = [
			"ok" => true,
			"source" => "udp",
			"host" => $host,
			"port" => $port,
			"data" => $data,
			"query" => $query,
			"queryError" => $queryError
		];

		if ($requestId !== null && self::getInstance()->deliverCallback($requestId, $payload)) {
			return;
		}

		$console->sendMessage(self::info("Fallback UDP query result (udp://{$payload["host"]}:{$payload["port"]}):"));
		self::sendLegacyQuery($console, $payload["data"]);

		if ($query !== null) {
			/** @var array<string, mixed> $query */
			self::sendQueryPlugins($console, $query);
		} elseif ($queryError !== null && $queryError !== "") {
			$console->sendMessage(self::info("Query (plugins) failed: ") . TF::RED . $queryError);
			$debug = $result["debug"] ?? null;
			if (is_array($debug)) {
				$challenge = is_scalar($debug["challengeRaw"] ?? null) ? (string) $debug["challengeRaw"] : "n/a";
				$full = is_scalar($debug["fullStatRaw"] ?? null) ? (string) $debug["fullStatRaw"] : "n/a";
				$console->sendMessage(self::info("Query debug: challenge=$challenge full=$full"));
			}
		} else {
			$console->sendMessage(self::info("Query (plugins): ") . TF::RED . "No response (query may be disabled or blocked)");
		}
	}

	/** @return array{?string, ?int} */
	public static function splitAddress(?string $address): array {
		if ($address !== null && str_contains($address, ":")) {
			[$host, $port] = explode(":", $address, 2);
			$host = $host !== "" ? $host : null;
			$port = $port !== "" ? (int) $port : null;
			return [$host, $port];
		}

		return [null, null];
	}

	public function buildUserAgent(): string {
		return sprintf(
			"QueryServer/%s (PocketMine-MP plugin; %s)",
			$this->getDescription()->getVersion(),
			PHP_OS_FAMILY
		);
	}

	/** @param callable(array<string, mixed>):void $cb */
	public function registerCallback(callable $cb): int {
		$id = $this->nextRequestId++;
		$this->callbacks[$id] = $cb;
		return $id;
	}

	/** @param array<string, mixed> $payload */
	public function deliverCallback(int $id, array $payload): bool {
		if (!isset($this->callbacks[$id])) {
			return false;
		}
		$cb = $this->callbacks[$id];
		unset($this->callbacks[$id]);
		$cb($payload);
		return true;
	}

	/** @param array<string, mixed> $query */
	private static function sendQueryPlugins(CommandSender $sender, array $query): void {
		$plugins = $query["plugins"] ?? [];
		if (is_array($plugins) && count($plugins) > 0) {
			$names = [];
			foreach ($plugins as $plugin) {
				if (is_string($plugin)) {
					$names[] = $plugin;
				}
			}
			$sender->sendMessage(self::info("Plugins: ") . TF::GREEN . implode(self::ARRAY_SEPARATOR, $names));
		} elseif (is_array($query["raw"] ?? null) && isset($query["raw"]["plugins"])) {
			$sender->sendMessage(self::info("Plugins: ") . TF::RED . "Unavailable (query returned no list)");
		}
	}
}
