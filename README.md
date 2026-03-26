# QueryServer

A lightweight PocketMine-MP plugin that queries Bedrock servers using the public `api.mcsrvstat.us` v3 endpoint with a UDP fallback (libpmquery). It includes a small API so other plugins can request queries asynchronously without blocking the main thread.

## Features
- `/query <host[:port]> [port]` — fetch server status via HTTP; automatically falls back to UDP.
- Built-in UDP fallback uses an embedded, namespaced copy of **libpmquery**.
- Non-blocking: both HTTP and UDP queries run on async workers.
- Simple API for other plugins to trigger queries programmatically.

## Installation
1. Drop the plugin phar into your `plugins/` folder.
2. Ensure `php` version matches PocketMine-MP 5.x (PHP 8.1+).

## Command
- `/query <domain/ip[:port]> [port]`  
  Example: `/query test.pmmp.io 19132`

## API Usage (for other plugins)
```php
use NhanAZ\QueryServer\Main;

$api = Main::getInstance()->getApi();
$api->query("test.pmmp.io", 19132, function(array $result): void {
    if (($result['ok'] ?? false) === true) {
        // $result['data'] contains either API status (object) or UDP fields (array)
    } else {
        // handle $result['error']
    }
});
```

## License & Attribution
- This plugin is licensed under **LGPL-3.0-or-later**.
- Embedded library **libpmquery** (LGPL-3.0-or-later) by [jasonw4331/libpmquery](https://github.com/jasonw4331/libpmquery).
