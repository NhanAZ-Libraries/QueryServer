# QueryServer

QueryServer is a PocketMine-MP plugin that queries another server from the console or through a callback API for plugins.

The command and API use the external [Minecraft server status API](https://api.mcsrvstat.us/) when no separate port is supplied. A host with a port can fall back to a UDP query if the HTTP lookup fails. Supplying the port as a separate API argument or command argument uses UDP directly. The bundled libpmquery implementation handles the basic UDP response, and a second UDP request attempts to obtain the optional full stat.

## Requirements and compatibility

- `plugin.yml` declares PocketMine-MP API `5.0.0` and the code requires PHP 8.1 or newer.
- Static analysis targets Axolotl-PM 5 API. Runtime compatibility across the 5.x line has not yet been established. See the [build workflow](.github/workflows/build.yml) for the exact revision tested in CI.
- Plugins calling the API must depend on the `QueryServer` plugin so its instance is loaded first.

## Install and use

Build a plugin PHAR with [DevTools](https://github.com/NhanAZ/DevTools), then place the verified PHAR in the server's `plugins` directory. The console command requires the `queryserver.cmd` permission, which defaults to operators.

```text
/query example.org
/query example.org:19132
/query example.org 19132
```

The first two forms try the HTTP status API, with UDP fallback available for the address containing a port. The third form uses UDP directly. Query results depend on the remote server's query settings and network access.

Another plugin can depend on `QueryServer` and call its API after both plugins load:

```php
use NhanAZ\QueryServer\Main;

Main::getInstance()->getApi()->query("example.org", 19132, function (array $result): void {
    if (($result["ok"] ?? false) !== true) {
        // Inspect $result["error"].
        return;
    }

    // $result["source"] identifies "udp" or "api".
    // $result["data"] is a UDP array or HTTP status object.
});
```

The [example consumer](examples/QueryConsumer) shows plugin dependency and command wiring. A callback is delivered once after success or the final failure. Keep the callback lightweight because it runs when the asynchronous task completes on the server thread.

## Build and verify

The [build workflow](.github/workflows/build.yml) runs PHPStan at maximum level, PHP lint, callback and loopback UDP fixtures, and a DevTools PHAR build. It verifies the manifest, source files, license notices and SHA-256 digest before uploading an artifact. The UDP fixture checks ping parsing and malformed replies against a local responder; it does not establish reachability of public servers. A CI artifact is a test result, not a stable release.

The repository also contains historical `virion.yml` and Poggit metadata. The public API currently depends on a loaded plugin instance, so the source should not be presented as a standalone virion. No current standalone virion release is offered.

## License and upstream

The root [LICENSE](LICENSE) contains GPL-3.0 text. The bundled [libpmquery](https://github.com/jasonw4331/libpmquery) files carry LGPL-3.0-or-later notices, with its [license text](libpmquery-LICENSE.txt) also included in the PHAR. The `composer.json` and `virion.yml` license declarations currently differ from the root license. This discrepancy needs a provenance and rights review before a stable release or standalone virion distribution.

Report issues through [GitHub Issues](https://github.com/NhanAZ-Libraries/QueryServer/issues) or [NhanAZ Discord](https://discord.gg/j2X83ujT6c).
