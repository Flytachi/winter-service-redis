# Winter Service · Redis

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-service-redis.svg)](https://packagist.org/packages/flytachi/winter-service-redis)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-service-redis.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-service-redis)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Tests](https://github.com/flytachi/winter-service-redis/actions/workflows/tests.yml/badge.svg)](https://github.com/flytachi/winter-service-redis/actions/workflows/tests.yml)
[![Docs](https://img.shields.io/badge/docs-winterframe.net-blue.svg)](https://winterframe.net/docs/services)

Redis inside a Winter application's container, declared in one line. The application
starts it before everything else, waits until it answers, keeps checking it, restarts it
when it crashes or hangs, and stops it last — saving its data first.

```php
$components->service(new Redis());
```

It runs the server; to **connect** to it, use winter-redis or phpredis — see *Connecting*.

📖 **[Services in the documentation](https://winterframe.net/docs/services)**

---

## Installation

```bash
composer require flytachi/winter-service-redis
```

Requires PHP **8.4+**, `flytachi/winter-kernel` **4.5+** and the `flytachi/winter` image
(Alpine): the server comes from `apk` — Redis 8.8 on Alpine 3.24 — and
`call service docker` puts it there.

---

## Quick start

**1. Declare it** in the application:

```php
use Flytachi\Winter\Service\Redis\Redis;

final class Application extends WinterApplication
{
    protected static function components(Components $components): void
    {
        $components->service(new Redis(maxmemory: '256mb'));
    }
}
```

**2. Put it in the image:**

```bash
php call service docker          # Dockerfile block; prints what compose needs
```

The Dockerfile gets `apk add redis` and its directories, owned by `winter`. Compose is
yours: the command prints what to add to `docker-compose.yml`:

```yaml
services:
  server:
    stop_grace_period: 70s
    volumes:
      - redis-data:/var/lib/redis
volumes:
  redis-data:
```

- **`stop_grace_period`** — always: HTTP 30 s + Redis 30 s (saving its data) + 5 + 5.
- **The volume** — if the data must outlive a recreated container. A cache that need
  not (`persist: false`) needs neither the volume nor a data directory.

**3. Start it.** The log says:

```
[2026-11-15 16:12:37] [INFO ] -sys- [1]: Application starting High: redis
[2026-11-15 16:12:37] [INFO ] -redis- [11]: Server initialized
[2026-11-15 16:12:37] [INFO ] -redis- [11]: Ready to accept connections unix
[2026-11-15 16:12:37] [INFO ] -sys- [1]: redis is up.
```

---

## Parameters

### The server

| Parameter | Default | What it sets |
| --- | --- | --- |
| `data` | `/var/lib/redis` | The data directory, when it keeps data — keep it in a volume |
| `socket` | `/run/redis` | The directory of the Unix socket (`redis.sock`) |
| `host` | `null` | `null`: the socket only, nothing outside the container reaches it. `127.0.0.1`: TCP inside the container too. `0.0.0.0`: other containers as well. **A host needs a password** |
| `port` | `6379` | The TCP port |
| `password` | `null` | Asked for on every connection — through the socket too (`requirepass`) |
| `persist` | `true` | Its data kept on disk: RDB snapshots, saved on a stop. `false` — a cache: nothing written |
| `aof` | `false` | Also an append-only file: every write kept, not the last snapshot's. Needs `persist` |
| `maxmemory` | `null` | How much memory it may use: `256mb`, `1gb`; `null` — no limit |
| `maxmemoryPolicy` | `null` | What goes once that memory is full: `allkeys-lru`, `volatile-ttl`…; `null` — Redis's own, `noeviction` (writes are refused) |
| `settings` | `[]` | Further configuration, each passed as `--key value`: `['databases' => 4, 'timeout' => 300]`; `true`/`false` become `yes`/`no` |
| `args` | `[]` | Further `redis-server` arguments, as they are |
| `logs` | `true` | Whether Redis's log goes into the application's — see *Log* |

> [!WARNING]
> **Without `maxmemory` Redis has no limit.** It grows until the container's memory runs
> out, and the OOM killer ends the whole application with it: Redis lives in its
> container, the application is PID 1. Set `maxmemory` below the container's memory,
> leaving room for the application. With the default `noeviction`, a full Redis refuses
> writes (`OOM command not allowed`) and the application lives on; a cache that may lose
> keys takes an eviction policy instead.

For a cache, the usual pair is `persist: false, maxmemory: '256mb', maxmemoryPolicy: 'allkeys-lru'`.

### The component

| Parameter | Default | What it sets |
| --- | --- | --- |
| `name` | `'redis'` | How logs and `app status` name it; the volume in the compose hint is `{name}-data` |
| `priority` | `Priority::High` | Starts before the rest, stops after them |
| `critical` | `true` | When it cannot run, the application stops |
| `retries` | `3` | Restarts after a crash within 30 minutes |
| `grace` | `30.0` | Seconds to stop — to save its data — before SIGKILL |
| `startTimeout` | `60` | Seconds to become ready |
| `alive` | `true` | `true`: a `PING` every 10 s, 5 s for an answer, 6 misses in a row mean it hangs. A `Probe` of your own. `null` or `false`: not checked |

Every value is checked when the object is made — a relative path, a port out of range, a
host without a password, `aof` without `persist`, a size or a policy Redis does not take,
a setting the service sets itself (`requirepass`, `port`, `dir`…), a value on two lines:
each is an `InvalidArgumentException` saying what is wrong.

---

## What a start does

1. Refuses to start without `redis-server` in the image: *"Add it to the image: call service docker"*.
2. Writes the configuration to the server's standard input — the socket, the host and port
   or none, the password, the persistence, the memory limit — and becomes the server
   (`exec`), so the stop signal reaches Redis itself.

The password is on no command line and never on disk.

It is ready once a `PING` through the socket is answered — not while Redis is still
loading its data from disk (`LOADING`). With a password, a `PING` without it is answered
`NOAUTH` loaded or not: it is ready once the server answers.

Without a password Redis says, at warning, that it accepts any local client: through the
socket, inside the container, that is so.

---

## Connecting

```php
$redis = new Redis();

$client = new \Redis();
$client->connect($redis->socketPath());      // /run/redis/redis.sock
```

### With winter-redis

```php
namespace Main\Configs;

use Flytachi\Winter\Redis\Config\RedisConfig;
use Flytachi\Winter\Service\Redis\Redis;

class MainRedisConfig extends RedisConfig
{
    public static function service(): Redis
    {
        return new Redis(maxmemory: '256mb');
    }

    public function setUp(): void
    {
        $redis = self::service();
        $this->host = $redis->socketPath();       // a socket path is a host for phpredis…
        $this->port = 0;                          // …when the port is not one
        $this->password = $redis->password ?? '';
    }
}
```

```php
// bootstrap.php
$components->service(MainRedisConfig::service());
```

`port = 0` is needed: phpredis takes a path for a socket only when the port is less than
1. With winter-redis's default, 6379, it looks the path up as a host name and fails with
`DNS Lookup resolve failed`.

---

## Log

Redis's log goes into the application's: the same format, the `-redis-` channel (blue in
colour), the PID of the process that wrote the line — the server, or a child saving a
snapshot — in the brackets.

| Redis | Log |
| --- | --- |
| `.` debug, `-` verbose | `DEBUG` |
| `*` notice | `INFO` |
| `#` warning | `WARN` |

- Its last words, `Redis is now ready to exit, bye bye...`, which Redis writes at
  warning, are `INFO`: a stop that went well.
- `LOG_LEVEL` applies. Without `LOG_LEVEL` (the log off) Redis's lines are not seen
  either. `logs: false` leaves its output as Redis writes it.

---

## Stopping

SIGTERM: Redis saves its snapshot (with `persist`), syncs its append-only file (with
`aof`) and exits 0. It stops last, after HTTP, so the container needs
`stop_grace_period: 70s` (30 for HTTP, 30 for Redis, 5 + 5 margins). A large dataset takes
longer to save: raise `grace`, and the stop period with it.

---

## Testing

```bash
composer test                     # unit tests
composer test-integration         # against a real Redis, in Docker
```

## License

MIT — see [LICENSE](LICENSE).
