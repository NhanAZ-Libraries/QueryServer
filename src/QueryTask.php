<?php

declare(strict_types=1);

namespace NhanAZ\QueryServer;

use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\Internet;

class QueryTask extends AsyncTask {

	public function __construct(
		private string $address,
		private string $userAgent
	) {
	}

	public function onRun(): void {
		$result = [
			"ok" => false,
			"address" => $this->address,
			"data" => null,
			"error" => null
		];

		try {
			$response = Internet::getURL(
				"https://api.mcsrvstat.us/3/" . $this->address,
				10,
				["User-Agent" => $this->userAgent]
			);
			$decoded = json_decode($response->getBody());

			if (!is_object($decoded)) {
				throw new \RuntimeException("Unexpected response from status API");
			}

			$result["ok"] = true;
			$result["data"] = $decoded;
		} catch (\Throwable $e) {
			$result["error"] = $e->getMessage();
		}

		$this->setResult($result);
	}

	public function onCompletion(): void {
		Main::handleQueryResult($this->getResult());
	}
}
