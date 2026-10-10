<?php

declare(strict_types=1);

require __DIR__ . '/../src/libpmquery/PmQueryException.php';
require __DIR__ . '/../src/libpmquery/PMQuery.php';

use NhanAZ\QueryServer\libpmquery\PMQuery;
use NhanAZ\QueryServer\libpmquery\PmQueryException;

if (($argv[1] ?? null) === '--server') {
	$socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
	if ($socket === false) {
		fwrite(STDERR, "UDP bind failed: {$error}\n");
		exit(1);
	}
	$address = stream_socket_get_name($socket, false);
	if ($address === false) {
		fwrite(STDERR, "Could not read UDP socket address\n");
		exit(1);
	}
	$port = (int) substr($address, strrpos($address, ':') + 1);
	fwrite(STDOUT, "{$port}\n");
	fflush(STDOUT);
	stream_set_timeout($socket, 5);
	$peer = '';
	$ping = stream_socket_recvfrom($socket, 4096, 0, $peer);
	if ($ping === false || strlen($ping) !== 33 || ord($ping[0]) !== 1) {
		fwrite(STDERR, "Invalid UDP ping\n");
		exit(1);
	}
	$magic = "\x00\xFF\xFF\x00\xFE\xFE\xFE\xFE\xFD\xFD\xFD\xFD\x12\x34\x56\x78";
	$payload = 'MCPE;Loopback;776;1.21.80;3;20;123456;Flat;Survival;1;19132;19133;fixture';
	$response = "\x1C" . pack('Q', 1) . pack('Q', 2)
		. (($argv[2] ?? '') === 'bad-magic' ? str_repeat("\x00", 16) : $magic)
		. pack('n', strlen($payload)) . $payload;
	if (stream_socket_sendto($socket, $response, 0, $peer) !== strlen($response)) {
		fwrite(STDERR, "Could not send UDP response\n");
		exit(1);
	}
	fclose($socket);
	exit(0);
}

/** @return array<string, string|int|null> */
function queryFixture(string $mode): array {
	$process = proc_open([PHP_BINARY, __FILE__, '--server', $mode], [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w']
	], $pipes);
	if (!is_resource($process)) {
		throw new RuntimeException('Could not start the UDP fixture');
	}
	fclose($pipes[0]);
	try {
		$port = (int) fgets($pipes[1]);
		if ($port < 1 || $port > 65535) {
			throw new RuntimeException('UDP fixture did not report a valid port');
		}
		return PMQuery::query('127.0.0.1', $port, 2);
	} finally {
		fclose($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);
		if ($exitCode !== 0) {
			throw new RuntimeException("UDP fixture failed: {$error}");
		}
	}
}

$result = queryFixture('valid');
if ($result['HostName'] !== 'Loopback' || $result['Players'] !== 3 || $result['IPv4Port'] !== 19132) {
	throw new RuntimeException('Valid UDP reply was not parsed correctly');
}

try {
	queryFixture('bad-magic');
	throw new RuntimeException('A UDP reply with invalid magic bytes was accepted');
} catch (PmQueryException $exception) {
	if ($exception->getMessage() !== 'Magic bytes do not match.') {
		throw $exception;
	}
}

try {
	PMQuery::query('127.0.0.1', 0);
	throw new RuntimeException('Port zero was accepted');
} catch (PmQueryException $exception) {
	if (!str_contains($exception->getMessage(), 'Port must be between')) {
		throw $exception;
	}
}

echo "PASS: loopback UDP ping, response parsing, invalid magic and port bounds\n";
