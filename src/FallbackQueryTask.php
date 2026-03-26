<?php

declare(strict_types=1);

namespace NhanAZ\QueryServer;

use pocketmine\scheduler\AsyncTask;

class FallbackQueryTask extends AsyncTask {

	public function __construct(
		private string $host,
		private int $port,
		private int $timeout = 2,
		private ?int $requestId = null,
		private bool $debug = false
	) {
	}

	public function onRun(): void {
		$result = [
			"ok" => false,
			"host" => $this->host,
			"port" => $this->port,
			"data" => null,
			"error" => null,
			"query" => null,
			"queryError" => null,
			"requestId" => $this->requestId,
			"debug" => []
		];

		try {
			$query = \NhanAZ\QueryServer\libpmquery\PMQuery::query($this->host, $this->port, $this->timeout);
			$result["ok"] = true;
			$result["data"] = $query;
			// try full stat (query plugins) but ignore failure
			if ($this->debug || true) { // always attempt, capture debug if enabled
				try {
					$result["query"] = $this->fullStat($this->host, $this->port, $this->timeout, $result["debug"]);
				} catch (\Throwable $ignored) {
					$result["queryError"] = $ignored->getMessage();
				}
			}
		} catch (\Throwable $e) {
			$result["error"] = $e->getMessage();
		}

		$this->setResult($result);
	}

	public function onCompletion(): void {
		Main::handleFallbackResult($this->getResult());
	}

	/**
	 * Run Minecraft Query full stat (GameSpy v4).
	 *
	 * @return array<string, mixed>
	 */
	private function fullStat(string $host, int $port, int $timeout, array &$debug): array {
		$socket = @fsockopen("udp://{$host}", $port, $errno, $errstr, $timeout);
		if ($socket === false) {
			throw new \RuntimeException($errstr ?: "Unable to open query socket", $errno);
		}

		stream_set_timeout($socket, $timeout);
		stream_set_blocking($socket, true);

		$sessionId = random_int(1, 0x7fffffff);
		$challengeReq = "\xFE\xFD\x09" . pack("N", $sessionId);
		if (fwrite($socket, $challengeReq) !== strlen($challengeReq)) {
			fclose($socket);
			throw new \RuntimeException("Failed to write challenge request");
		}

		$challengeResp = fread($socket, 1500);
		if ($this->debug) {
			$debug["challengeRaw"] = bin2hex((string) $challengeResp);
		}
		if ($challengeResp === false || strlen($challengeResp) < 5) {
			fclose($socket);
			throw new \RuntimeException("Invalid challenge response");
		}

		$challengeStr = trim(substr($challengeResp, 5));
		$challenge = (int) $challengeStr;

		$fullReq = "\xFE\xFD\x00" . pack("N", $sessionId) . pack("N", $challenge) . "\x00\x00\x00\x00";
		if (fwrite($socket, $fullReq) !== strlen($fullReq)) {
			fclose($socket);
			throw new \RuntimeException("Failed to write full stat request");
		}

		$resp = fread($socket, 4096);
		if ($this->debug) {
			$debug["fullStatRaw"] = bin2hex((string) $resp);
		}
		fclose($socket);
		if ($resp === false || strlen($resp) < 5) {
			throw new \RuntimeException("Invalid full stat response");
		}

		$payload = substr($resp, 5);
		$sections = explode("\x00\x00\x01player_\x00\x00", $payload);
		$kvRaw = $sections[0] ?? "";
		$kvParts = explode("\x00", $kvRaw);

		$kv = [];
		for ($i = 0; $i + 1 < count($kvParts); $i += 2) {
			$key = $kvParts[$i];
			$value = $kvParts[$i + 1];
			if ($key === "") {
				continue;
			}
			$kv[$key] = $value;
		}

		$plugins = [];
		if (isset($kv["plugins"])) {
			$parts = explode("; ", $kv["plugins"]);
			if (count($parts) > 1) {
				array_shift($parts);
				$plugins = $parts;
			}
		}

		return [
			"raw" => $kv,
			"plugins" => $plugins
		];
	}
}
