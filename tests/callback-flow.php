<?php

declare(strict_types=1);

namespace pocketmine\command {
    class Command {}
    class CommandSender {
        /** @var list<string> */
        public array $messages = [];
        public function sendMessage(string $message): void { $this->messages[] = $message; }
    }
}

namespace pocketmine\console {
    class ConsoleCommandSender extends \pocketmine\command\CommandSender {
        public function __construct(mixed $server, mixed $language) {}
    }
}

namespace pocketmine\player {
    class Player extends \pocketmine\command\CommandSender {}
}

namespace pocketmine\plugin {
    class PluginBase {
        public function getServer(): \pocketmine\Server { return \pocketmine\Server::getInstance(); }
    }
}

namespace pocketmine\scheduler {
    class AsyncTask {
        private mixed $result = null;
        public function setResult(mixed $result): void { $this->result = $result; }
        public function getResult(): mixed { return $this->result; }
    }
}

namespace pocketmine\utils {
    class Internet {
        public static string $requestedUrl = '';
        /** @var list<string> */
        public static array $headers = [];
        /** @param list<string> $headers */
        public static function getURL(string $url, int $timeout, array $headers): object {
            self::$requestedUrl = $url;
            self::$headers = $headers;
            return new class {
                public function getBody(): string { return '{"online":true,"port":19132}'; }
            };
        }
    }
    class TextFormat {
        public const YELLOW = '';
        public const WHITE = '';
        public const GREEN = '';
        public const RED = '';
    }
}

namespace pocketmine {
    class AsyncPool {
        /** @var list<object> */
        public array $tasks = [];
        public function submitTask(object $task): void { $this->tasks[] = $task; }
    }
    class Server {
        private static ?self $instance = null;
        public AsyncPool $pool;
        public function __construct() { self::$instance = $this; $this->pool = new AsyncPool(); }
        public static function getInstance(): self { return self::$instance ?? throw new \LogicException(); }
        public function getLanguage(): object { return new \stdClass(); }
        public function getAsyncPool(): AsyncPool { return $this->pool; }
    }
}

namespace {
    require __DIR__ . '/../src/QueryTask.php';
    require __DIR__ . '/../src/FallbackQueryTask.php';
    require __DIR__ . '/../src/API.php';
    require __DIR__ . '/../src/Main.php';

    function check(bool $condition, string $description): void {
        if (!$condition) { throw new \RuntimeException($description); }
    }

    $server = new \pocketmine\Server();
    $plugin = new \NhanAZ\QueryServer\Main();
    (new \ReflectionMethod($plugin, 'onLoad'))->invoke($plugin);

    $httpTask = new \NhanAZ\QueryServer\QueryTask('test.pmmp.io', 'QueryServer fixture');
    $httpTask->onRun();
    check(\pocketmine\utils\Internet::$requestedUrl === 'https://api.mcsrvstat.us/bedrock/3/test.pmmp.io', 'HTTP task used the Java endpoint instead of the Bedrock endpoint');
    check(\pocketmine\utils\Internet::$headers === ['User-Agent: QueryServer fixture'], 'HTTP task omitted its User-Agent header');
    check(($httpTask->getResult()['ok'] ?? null) === true, 'HTTP task did not accept the Bedrock status response');

    $received = [];
    $id = $plugin->registerCallback(static function (array $result) use (&$received): void { $received[] = $result; });
    \NhanAZ\QueryServer\Main::handleQueryResult([
        'address' => 'example.org:19132', 'requestId' => $id, 'ok' => false, 'error' => 'HTTP failed'
    ]);
    check($received === [], 'HTTP failure consumed callback before UDP fallback');
    check(count($server->pool->tasks) === 1 && $server->pool->tasks[0] instanceof \NhanAZ\QueryServer\FallbackQueryTask, 'UDP fallback not queued');
    \NhanAZ\QueryServer\Main::handleFallbackResult([
        'requestId' => $id, 'ok' => true, 'host' => 'example.org', 'port' => 19132,
        'data' => ['HostName' => 'Fixture'], 'query' => null
    ]);
    check(count($received) === 1 && ($received[0]['data']['HostName'] ?? null) === 'Fixture' && $received[0]['source'] === 'udp', 'UDP result not delivered with source');
    check($plugin->deliverCallback($id, []) === false, 'Callback delivered twice');

    $received = [];
    $id = $plugin->registerCallback(static function (array $result) use (&$received): void { $received[] = $result; });
    \NhanAZ\QueryServer\Main::handleFallbackResult([
        'requestId' => $id, 'ok' => false, 'error' => 'UDP timed out'
    ]);
    check(count($received) === 1 && $received[0]['ok'] === false && $received[0]['source'] === 'udp', 'UDP failure source not delivered');

    $received = [];
    $id = $plugin->registerCallback(static function (array $result) use (&$received): void { $received[] = $result; });
    \NhanAZ\QueryServer\Main::handleQueryResult([
        'address' => 'example.org', 'requestId' => $id, 'ok' => false, 'error' => 'HTTP failed'
    ]);
    check($received === [], 'No-port HTTP failure consumed callback before UDP fallback');
    check(count($server->pool->tasks) === 2 && $server->pool->tasks[1] instanceof \NhanAZ\QueryServer\FallbackQueryTask, 'No-port HTTP failure did not queue UDP fallback');
    check((new \ReflectionProperty($server->pool->tasks[1], 'host'))->getValue($server->pool->tasks[1]) === 'example.org', 'No-port fallback changed the host');
    check((new \ReflectionProperty($server->pool->tasks[1], 'port'))->getValue($server->pool->tasks[1]) === 19132, 'No-port fallback did not use the Bedrock default port');
    \NhanAZ\QueryServer\Main::handleFallbackResult([
        'requestId' => $id, 'ok' => true, 'host' => 'example.org', 'port' => 19132,
        'data' => ['HostName' => 'Default Port Fixture'], 'query' => null
    ]);
    check(count($received) === 1 && ($received[0]['data']['HostName'] ?? null) === 'Default Port Fixture', 'No-port UDP result was not delivered');

    $received = [];
    $id = $plugin->registerCallback(static function (array $result) use (&$received): void { $received[] = $result; });
    \NhanAZ\QueryServer\Main::handleQueryResult([
        'address' => 'example.org', 'requestId' => $id, 'ok' => true,
        'data' => (object) ['online' => false]
    ]);
    check($received === [] && count($server->pool->tasks) === 3, 'Offline HTTP status did not try the Bedrock default port');
    \NhanAZ\QueryServer\Main::handleFallbackResult([
        'requestId' => $id, 'ok' => false, 'error' => 'UDP timed out'
    ]);
    check(count($received) === 1 && $received[0]['ok'] === false && $received[0]['source'] === 'udp', 'Offline HTTP status did not deliver the final UDP failure');

    $received = [];
    $id = $plugin->registerCallback(static function (array $result) use (&$received): void { $received[] = $result; });
    \NhanAZ\QueryServer\Main::handleQueryResult([
        'address' => 'example.org:19132', 'requestId' => $id, 'ok' => true,
        'data' => (object) ['online' => true, 'ip' => '127.0.0.1', 'port' => 19132]
    ]);
    check(count($received) === 1 && $received[0]['source'] === 'api', 'HTTP success did not deliver once');
    check(count($server->pool->tasks) === 3, 'HTTP success queued redundant fallback');
    \NhanAZ\QueryServer\Main::handleQueryResult([
        'address' => 'example.org', 'requestId' => null, 'ok' => true,
        'data' => (object) ['online' => true, 'ip' => '127.0.0.1', 'port' => 19132]
    ]);
    check(count($server->pool->tasks) === 3, 'Successful no-port console lookup queued redundant UDP query');

    check(\NhanAZ\QueryServer\Main::splitAddress('example.org:19132') === ['example.org', 19132], 'Valid host and port were not parsed');
    foreach (['example.org:bad', 'example.org:0', 'example.org:65536', '2001:db8::1'] as $invalid) {
        check(\NhanAZ\QueryServer\Main::splitAddress($invalid) === [null, null], 'Invalid host and port were accepted');
    }

    $sender = new \pocketmine\command\CommandSender();
    (new \ReflectionMethod($plugin, 'handleQuery'))->invoke($plugin, $sender, ['example.org:19132', '19133']);
    check(count($server->pool->tasks) === 4, 'Explicit UDP port did not queue a task');
    $task = $server->pool->tasks[3];
    check($task instanceof \NhanAZ\QueryServer\FallbackQueryTask, 'Explicit UDP port queued the wrong task');
    check((new \ReflectionProperty($task, 'host'))->getValue($task) === 'example.org', 'Explicit UDP port kept the embedded port in the hostname');
    check((new \ReflectionProperty($task, 'port'))->getValue($task) === 19133, 'Explicit UDP port did not override the embedded port');

    echo "PASS: callback delivery and UDP fallback order\n";
}
