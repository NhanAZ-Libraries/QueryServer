<?php

declare(strict_types=1);

namespace NhanAZ\QueryServer;

/**
 * Public API for other plugins.
 *
 * Usage:
 *   $api = Main::getInstance()->getApi();
 *   $api->query("test.pmmp.io", 19132, function(array $result): void {
 *       // handle $result
 *   });
 */
final class API {

	public function __construct(
		private Main $plugin
	) {
	}

	/**
	 * Query a server.
	 *
	 * @param string        $host       Domain or IP (host[:port] also accepted).
	 * @param int|null      $port       Optional port; if null and host has port, it's used; else defaults apply upstream.
	 * @param callable(array):void $onComplete Callback receives normalized array result.
	 */
	public function query(string $host, ?int $port, callable $onComplete): void {
		[$resolvedHost, $resolvedPort] = Main::splitAddress($host);

		if ($port !== null) {
			$resolvedPort = $port;
		}

		$requestId = $this->plugin->registerCallback($onComplete);

		if ($resolvedPort !== null) {
			$this->plugin->getServer()->getAsyncPool()->submitTask(new FallbackQueryTask($resolvedHost ?? $host, $resolvedPort, timeout: 2, requestId: $requestId));
			return;
		}

		// No port supplied; use HTTP API v3 then internal fallback.
		$this->plugin->getServer()->getAsyncPool()->submitTask(new QueryTask($host, $this->plugin->buildUserAgent(), requestId: $requestId));
	}
}
