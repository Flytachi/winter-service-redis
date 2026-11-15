<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Redis;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\App\Probe;
use Flytachi\Winter\Kernel\App\Service;
use Flytachi\Winter\Kernel\App\ServiceImage;

/**
 * Redis inside the application's container: started before the rest (High), ready once it
 * answers on its socket, checked while it runs, restarted when it crashes or hangs, stopped
 * last — saving its data first, unless it keeps none.
 *
 * ```php
 * protected static function components(Components $components): void
 * {
 *     $components->service(new Redis());
 * }
 * ```
 *
 * By default it listens on its Unix socket only: nothing outside the container reaches
 * it. Its data is saved to disk (RDB snapshots, and with `$aof` an append-only file); a
 * cache that need not outlive a restart takes `persist: false`.
 *
 * @link https://winterframe.net/docs/services Services inside the application
 */
final readonly class Redis implements Service
{
    /** The variable the password reaches the start script in — never the command line. */
    public const string PASSWORD_VARIABLE = 'WINTER_REDIS_PASSWORD';

    /** The name of the socket file in the socket directory. */
    public const string SOCKET_FILE = 'redis.sock';

    /** What `maxmemory-policy` takes: what goes once the memory is full. */
    public const array POLICIES = [
        'noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random',
        'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl',
    ];

    /** The settings the service sets itself: changed through its parameters. */
    private const array OWN_SETTINGS = [
        'daemonize', 'logfile', 'dir', 'unixsocket', 'unixsocketperm', 'bind', 'port',
        'requirepass', 'appendonly', 'maxmemory', 'maxmemory-policy', 'include',
    ];

    /**
     * @param string $data The data directory, when it keeps data — keep it in a volume.
     * @param string $socket The directory of the Unix socket ({@see SOCKET_FILE}).
     * @param string|null $host Null: the socket only. `127.0.0.1`: TCP inside the container
     *     too. `0.0.0.0`: other containers as well. A host needs a password.
     * @param int $port The TCP port.
     * @param string|null $password Asked for on every connection — through the socket too
     *     (`requirepass`); null — none.
     * @param bool $persist Whether its data is kept on disk: RDB snapshots, saved on a stop.
     *     False — a cache: nothing is written, and no data directory or volume is needed.
     * @param bool $aof Also an append-only file (`appendonly yes`): every write kept, not
     *     the last snapshot's. Only with `$persist`.
     * @param string|null $maxmemory How much memory it may use: `256mb`, `1gb`; null — no limit.
     * @param string|null $maxmemoryPolicy What goes once that memory is full ({@see POLICIES});
     *     null — Redis's own (`noeviction`: writes are refused).
     * @param array<string, scalar> $settings Further configuration, each passed as
     *     `--key value`: `['databases' => 4, 'timeout' => 300]`.
     * @param list<string> $args Further `redis-server` arguments, as they are.
     * @param bool $logs Whether Redis's log goes into the application's ({@see RedisOutput});
     *     false — it is written as Redis writes it.
     * @param string $name How logs, `app status` and the volume name it.
     * @param Priority $priority When it starts and stops relative to the other components.
     * @param bool $critical Whether the application stops when it cannot run.
     * @param int $retries Restarts after a crash within 30 minutes.
     * @param float $grace Seconds it has to stop — to save its data — before SIGKILL.
     * @param int $startTimeout Seconds it has to become ready.
     * @param Probe|bool|null $alive How it is checked once ready: true (the default) pings it
     *     every 10 s (5 s for an answer, 6 misses in a row mean it hangs); a Probe of your
     *     own; null or false — not checked.
     * @throws \InvalidArgumentException A value Redis or the image cannot take.
     */
    public function __construct(
        public string $data = '/var/lib/redis',
        public string $socket = '/run/redis',
        public ?string $host = null,
        public int $port = 6379,
        #[\SensitiveParameter]
        public ?string $password = null,
        public bool $persist = true,
        public bool $aof = false,
        public ?string $maxmemory = null,
        public ?string $maxmemoryPolicy = null,
        public array $settings = [],
        public array $args = [],
        public bool $logs = true,
        public string $name = 'redis',
        public Priority $priority = Priority::High,
        public bool $critical = true,
        public int $retries = 3,
        public float $grace = 30.0,
        public int $startTimeout = 60,
        public Probe|bool|null $alive = true,
    ) {
        foreach (['data' => $data, 'socket' => $socket] as $what => $path) {
            if (preg_match('#^/[A-Za-z0-9._/-]+$#', $path) !== 1 || str_contains($path, '..')) {
                throw new \InvalidArgumentException("{$what} must be an absolute path, \"{$path}\" given.");
            }
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("port must be 1–65535, {$port} given.");
        }
        if ($host !== null && preg_match('/^[A-Za-z0-9.:_-]+$/', $host) !== 1) {
            throw new \InvalidArgumentException("host must be an address, \"{$host}\" given.");
        }
        if ($host !== null && ($password === null || $password === '')) {
            throw new \InvalidArgumentException(
                'a host needs a password: over TCP anyone could read and write it.'
            );
        }
        if ($password !== null && (str_contains($password, "\n") || str_contains($password, "\0"))) {
            throw new \InvalidArgumentException('the password is one line.');
        }
        if ($aof && !$persist) {
            throw new \InvalidArgumentException('aof keeps data on disk: it needs persist.');
        }
        if ($maxmemory !== null && preg_match('/^\d+(b|k|kb|m|mb|g|gb)?$/i', $maxmemory) !== 1) {
            throw new \InvalidArgumentException("maxmemory must be a size, as 256mb or 1gb, \"{$maxmemory}\" given.");
        }
        if ($maxmemoryPolicy !== null && !in_array($maxmemoryPolicy, self::POLICIES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'maxmemoryPolicy "%s" is not one of %s.',
                $maxmemoryPolicy,
                implode(', ', self::POLICIES),
            ));
        }
        foreach ($settings as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', (string) $key) !== 1) {
                throw new \InvalidArgumentException("not a setting name: \"{$key}\".");
            }
            if (in_array((string) $key, self::OWN_SETTINGS, true)) {
                throw new \InvalidArgumentException("setting {$key} is the service's own: set it with its parameter.");
            }
            if (!is_scalar($value) || str_contains((string) $value, "\n")) {
                throw new \InvalidArgumentException("setting {$key}: a value is one line.");
            }
        }
        if (!array_is_list($args) || array_filter($args, static fn($arg): bool => !is_string($arg)) !== []) {
            throw new \InvalidArgumentException('args are a list of strings.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("name must be lowercase letters, digits, _ . -, \"{$name}\" given.");
        }
    }

    public function component(): Component
    {
        $script = ['/bin/sh', dirname(__DIR__) . '/bin/winter-redis'];
        $options = [
            '--data=' . $this->data,
            '--socket=' . $this->socket,
            '--port=' . $this->port,
            '--persist=' . ($this->persist ? '1' : '0'),
            '--aof=' . ($this->aof ? '1' : '0'),
        ];
        if ($this->host !== null) {
            $options[] = '--host=' . $this->host;
        }
        if ($this->maxmemory !== null) {
            $options[] = '--maxmemory=' . $this->maxmemory;
        }
        if ($this->maxmemoryPolicy !== null) {
            $options[] = '--policy=' . $this->maxmemoryPolicy;
        }
        $server = [];
        foreach ($this->settings as $key => $value) {
            array_push($server, '--' . $key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }
        $command = [...$script, ...$options, '--', ...$server, ...$this->args];
        if ($this->password !== null && $this->password !== '') {
            $command = ['/usr/bin/env', self::PASSWORD_VARIABLE . '=' . $this->password, ...$command];
        }

        return Component::command(
            $command,
            $this->name,
            critical: $this->critical,
            grace: $this->grace,
            priority: $this->priority,
            ready: $this->isReady(),
            alive: $this->aliveProbe(),
            stopSignal: SIGTERM,
            retries: $this->retries,
            startTimeout: $this->startTimeout,
            output: $this->logs ? new RedisOutput() : null,
        );
    }

    public function image(): ServiceImage
    {
        return new ServiceImage(
            packages: ['redis'],
            directories: $this->persist ? [$this->data => 0700, $this->socket => 0755] : [$this->socket => 0755],
            volumes: $this->persist ? ["{$this->name}-data" => $this->data] : [],
        );
    }

    /** The socket file: the host winter-redis and phpredis connect to (`connect($path)`). */
    public function socketPath(): string
    {
        return $this->socket . '/' . self::SOCKET_FILE;
    }

    /**
     * A `PING` through the socket: 0 once it answers — while it loads its data from disk
     * it answers `LOADING`, which is not ready yet. With a password the answer is
     * `NOAUTH`, ready or loading alike: it counts as ready once the server answers.
     *
     * @return list<string>
     */
    public function isReady(): array
    {
        return [
            '/bin/sh',
            '-c',
            'r=$(redis-cli -s "$1" ping 2>&1) || exit 1; case "$r" in *LOADING*) exit 1 ;; esac',
            'ping',
            $this->socketPath(),
        ];
    }

    private function aliveProbe(): ?Probe
    {
        return match (true) {
            $this->alive instanceof Probe => $this->alive,
            $this->alive === true => Probe::command($this->isReady()),
            default => null,
        };
    }
}
