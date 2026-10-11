# QueryServer

QueryServer is a PocketMine-MP plugin that queries Bedrock server status from the console or through a callback API for plugins.

The command and API use the [Minecraft server status API's Bedrock endpoint](https://api.mcsrvstat.us/) when no separate port is supplied. If the HTTP lookup fails or reports the server offline, they try UDP on the host's specified port or the Bedrock default port `19132`. Supplying the port as a separate API argument or command argument uses UDP directly. The bundled libpmquery implementation handles the basic UDP response, and a second UDP request attempts to obtain the optional full stat.

## Requirements and compatibility

- `plugin.yml` declares PocketMine-MP API `5.0.0` and the code requires PHP 8.1 or newer.
- Static analysis targets Axolotl-PM 5 API. Runtime compatibility across the 5.x line has not yet been established. See the [build workflow](.github/workflows/build.yml) for the exact revision tested in CI.
- Plugins calling the API must depend on the `QueryServer` plugin so its instance is loaded first.

## Install and use

Download a verified PHAR from [GitHub Releases](https://github.com/NhanAZ-Libraries/QueryServer/releases/latest) and place it in the server's `plugins` directory. Check the release notes and SHA-256 checksum for that build. To build from source, use [DevTools](https://github.com/NhanAZ/DevTools) and validate the generated PHAR. The console command requires the `queryserver.cmd` permission, which defaults to operators.

```text
/query example.org
/query example.org:19132
/query example.org 19132
```

The first two forms try the HTTP status API, then UDP if the API fails or reports the server offline. The third form uses UDP directly. Query results depend on the remote server's query settings and network access.

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

The [example consumer](examples/QueryConsumer) shows plugin dependency and command wiring. Its `/qtest [host] [port]` command accepts a separate host and UDP port for local testing; without arguments, it uses the public test server. A callback is delivered once after success or the final failure. Keep the callback lightweight because it runs when the asynchronous task completes on the server thread.

## Build and verify

The [build workflow](.github/workflows/build.yml) runs PHPStan at maximum level, PHP lint, callback and loopback UDP fixtures, and DevTools PHAR builds for both the plugin and its example consumer. It verifies PHAR contents and SHA-256 digests before uploading the plugin artifact. The UDP fixture checks ping parsing and malformed replies against a local responder; it does not establish reachability of public servers. A CI artifact is a test result, not a stable release.

The repository also contains historical `virion.yml` and Poggit metadata. The public API currently depends on a loaded plugin instance, so the source should not be presented as a standalone virion. No current standalone virion release is offered.

## License and upstream

The project is distributed under the root [GPL-3.0-only license](LICENSE). The bundled [libpmquery](https://github.com/jasonw4331/libpmquery) code retains its LGPL-3.0-or-later notice and separate [license text](libpmquery-LICENSE.txt), which is included in the PHAR. Composer and virion metadata describe the GPL-3.0-only project as a whole.

The [root GPL text was added in 2021](https://github.com/NhanAZ-Libraries/QueryServer/commit/66d5565), before the [2023 contributor changes](https://github.com/NhanAZ-Libraries/QueryServer/commit/7bba7be). The [bundled libpmquery code and notice](https://github.com/NhanAZ-Libraries/QueryServer/commit/bf78f5f) were added in 2026. The later [virion metadata change](https://github.com/NhanAZ-Libraries/QueryServer/commit/e117469) did not change the root license. This history is the basis for aligning the metadata, while preserving the contributor and bundled library notices.

Report issues through [GitHub Issues](https://github.com/NhanAZ-Libraries/QueryServer/issues) or [NhanAZ Discord](https://discord.gg/j2X83ujT6c).
