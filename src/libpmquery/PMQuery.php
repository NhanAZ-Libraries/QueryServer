<?php

declare(strict_types=1);

/**
 * Embedded copy of jasonw4331/libpmquery (LGPL-3.0-or-later).
 * Source: https://github.com/jasonw4331/libpmquery/blob/master/src/jasonw4331/libpmquery/PMQuery.php
 */
namespace NhanAZ\QueryServer\libpmquery;

use function array_fill;
use function explode;
use function fclose;
use function fread;
use function fsockopen;
use function fwrite;
use function pack;
use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;
use function stream_set_blocking;
use function stream_set_timeout;
use function strlen;
use function substr;
use function time;
use const E_WARNING;

final class PMQuery {

	private const DEFAULT_TIMEOUT = 2;
	private const BUFFER_SIZE = 4096;
	private const EXPECTED_PREFIX = "\x1C"; // ID_UNCONNECTED_PONG
	private const MAGIC_BYTES = "\x00\xFF\xFF\x00\xFE\xFE\xFE\xFE\xFD\xFD\xFD\xFD\x12\x34\x56\x78";
	private const FIELD_COUNT = 13;

	/**
	 * @param string $host    Ip/dns address being queried
	 * @param int    $port    Port on the ip being queried
	 * @param int    $timeout Seconds before socket times out
	 *
	 * @return string[]|int[]
	 * @phpstan-return array{
	 *     GameName: string|null,
	 *     HostName: string|null,
	 *     Protocol: string|null,
	 *     Version: string|null,
	 *     Players: int,
	 *     MaxPlayers: int,
	 *     ServerId: string|null,
	 *     Map: string|null,
	 *     GameMode: string|null,
	 *     NintendoLimited: string|null,
	 *     IPv4Port: int,
	 *     IPv6Port: int,
	 *     Extra: string|null,
	 * }
	 * @throws PmQueryException
	 */
	public static function query(string $host, int $port, int $timeout = self::DEFAULT_TIMEOUT): array {
		self::assertPortInRange($port);

		$socket = self::openSocket($host, $port, $timeout);

		try {
			self::configureSocket($socket, $timeout);
			self::sendPing($socket);
			$data = self::readResponse($socket);
		} finally {
			fclose($socket);
		}

		return self::parseResponse($data);
	}

	private static function openSocket(string $host, int $port, int $timeout) {
		$lastError = null;
		$handler = set_error_handler(static function (int $errno, string $errstr) use (&$lastError): bool {
			$lastError = $errstr;
			return true; // swallow warning
		});

		try {
			$socket = fsockopen("udp://{$host}", $port, $errno, $errstr, $timeout);
		} finally {
			restore_error_handler();
		}

		if ($socket === false) {
			$message = $errstr ?: ($lastError ?: "Unable to open UDP socket");
			throw new PmQueryException($message, $errno ?: E_WARNING);
		}

		return $socket;
	}

	private static function configureSocket($socket, int $timeout): void {
		stream_set_timeout($socket, $timeout);
		stream_set_blocking($socket, true);
	}

	private static function sendPing($socket): void {
		$command = pack('cQ', 0x01, time()); // DefaultMessageIDTypes::ID_UNCONNECTED_PING + 64bit current time
		$command .= self::MAGIC_BYTES;
		$command .= pack('Q', 2); // 64bit guid
		$length = strlen($command);

		if ($length !== fwrite($socket, $command, $length)) {
			throw new PmQueryException("Failed to write on socket.", E_WARNING);
		}
	}

	private static function readResponse($socket): string {
		$data = fread($socket, self::BUFFER_SIZE);

		if ($data === false || $data === '') {
			throw new PmQueryException("Server failed to respond", E_WARNING);
		}

		return $data;
	}

	private static function parseResponse(string $data): array {
		if (!str_starts_with($data, self::EXPECTED_PREFIX)) {
			throw new PmQueryException("First byte is not ID_UNCONNECTED_PONG.", E_WARNING);
		}

		if (strlen($data) < 35) {
			throw new PmQueryException("Truncated response from server.", E_WARNING);
		}

		if (substr($data, 17, 16) !== self::MAGIC_BYTES) {
			throw new PmQueryException("Magic bytes do not match.");
		}

		// Skip header, magic bytes, and reserved bytes.
		$payload = substr($data, 35);

		// Limit to expected fields to prevent extra ';' in MOTD from breaking the layout too badly.
		$fields = explode(';', $payload, self::FIELD_COUNT);

		// Ensure array has at least expected indexes.
		$fields += array_fill(0, self::FIELD_COUNT, null);

		return [
			'GameName' => $fields[0],
			'HostName' => $fields[1],
			'Protocol' => $fields[2],
			'Version' => $fields[3],
			'Players' => (int) ($fields[4] ?? 0),
			'MaxPlayers' => (int) ($fields[5] ?? 0),
			'ServerId' => $fields[6],
			'Map' => $fields[7],
			'GameMode' => $fields[8],
			'NintendoLimited' => $fields[9],
			'IPv4Port' => (int) ($fields[10] ?? 0),
			'IPv6Port' => (int) ($fields[11] ?? 0),
			'Extra' => $fields[12], // Unknown content.
		];
	}

	private static function assertPortInRange(int $port): void {
		if ($port < 1 || $port > 65535) {
			throw new PmQueryException("Port must be between 1 and 65535, got {$port}.");
		}
	}
}
