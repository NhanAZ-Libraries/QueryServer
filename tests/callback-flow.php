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
    class AsyncTask {}
}

namespace pocketmine\utils {
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
    check(count($received) === 1 && ($received[0]['data']['HostName'] ?? null) === 'Fixture', 'UDP result not delivered');
    check($plugin->deliverCallback($id, []) === false, 'Callback delivered twice');

    $received = [];
    $id = $plugin->registerCallback(static function (array $result) use (&$received): void { $received[] = $result; });
    \NhanAZ\QueryServer\Main::handleQueryResult([
        'address' => 'example.org', 'requestId' => $id, 'ok' => false, 'error' => 'HTTP failed'
    ]);
    check(count($received) === 1 && $received[0]['ok'] === false, 'No-port failure did not finish');
    check(count($server->pool->tasks) === 1, 'No-port failure queued invalid fallback');

    $received = [];
    $id = $plugin->registerCallback(static function (array $result) use (&$received): void { $received[] = $result; });
    \NhanAZ\QueryServer\Main::handleQueryResult([
        'address' => 'example.org:19132', 'requestId' => $id, 'ok' => true,
        'data' => (object) ['online' => true, 'ip' => '127.0.0.1', 'port' => 19132]
    ]);
    check(count($received) === 1 && $received[0]['source'] === 'api', 'HTTP success did not deliver once');
    check(count($server->pool->tasks) === 1, 'HTTP success queued redundant fallback');

    echo "PASS: callback delivery and UDP fallback order\n";
}
