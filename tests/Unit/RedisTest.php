<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Redis\Tests\Unit;

use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\App\Probe;
use Flytachi\Winter\Service\Redis\Redis;
use Flytachi\Winter\Service\Redis\RedisOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What the service declares, from its parameters, and what it refuses. */
final class RedisTest extends TestCase
{
    public function test_by_default_it_starts_first_is_checked_and_stops_saving(): void
    {
        $component = (new Redis())->component();

        self::assertSame(ComponentKind::Command, $component->kind);
        self::assertSame('redis', $component->name());
        self::assertSame(Priority::High, $component->priority);
        self::assertTrue($component->critical);
        self::assertSame(3, $component->retries);
        self::assertSame(SIGTERM, $component->stopSignal, 'Redis saves, then exits');
        self::assertSame('/run/redis/redis.sock', $component->ready[4]);
        self::assertSame($component->ready, $component->alive?->command);
        self::assertInstanceOf(RedisOutput::class, $component->output);
    }

    public function test_the_parameters_reach_the_start_script(): void
    {
        $command = (new Redis(
            data: '/srv/kv',
            socket: '/tmp/kv',
            host: '127.0.0.1',
            port: 6380,
            password: 'pw',
            aof: true,
            maxmemory: '256mb',
            maxmemoryPolicy: 'allkeys-lru',
            settings: ['databases' => 4, 'lazyfree-lazy-eviction' => true],
            args: ['--loglevel', 'verbose'],
        ))->component()->command;

        self::assertSame(['/usr/bin/env', 'WINTER_REDIS_PASSWORD=pw', '/bin/sh'], array_slice($command, 0, 3));
        self::assertStringEndsWith('/bin/winter-redis', $command[3]);
        self::assertSame([
            '--data=/srv/kv',
            '--socket=/tmp/kv',
            '--port=6380',
            '--persist=1',
            '--aof=1',
            '--host=127.0.0.1',
            '--maxmemory=256mb',
            '--policy=allkeys-lru',
            '--',
            '--databases', '4',
            '--lazyfree-lazy-eviction', 'yes',
            '--loglevel', 'verbose',
        ], array_slice($command, 4));
    }

    public function test_a_cache_needs_no_data_directory_nor_volume(): void
    {
        $kept = (new Redis(name: 'kv'))->image();
        $cache = (new Redis(persist: false))->image();

        self::assertSame(['redis'], $kept->packages);
        self::assertSame(['/var/lib/redis' => 0700, '/run/redis' => 0755], $kept->directories);
        self::assertSame(['kv-data' => '/var/lib/redis'], $kept->volumes);
        self::assertSame(['/run/redis' => 0755], $cache->directories);
        self::assertSame([], $cache->volumes);
    }

    public function test_connecting_and_its_options(): void
    {
        self::assertSame('/run/redis/redis.sock', (new Redis())->socketPath());
        self::assertSame([], preg_grep('/WINTER_REDIS_PASSWORD/', (new Redis())->component()->command), 'no password, none passed');

        $probe = Probe::command(['true'], every: 30);
        self::assertSame($probe, (new Redis(alive: $probe))->component()->alive);
        self::assertNull((new Redis(alive: false))->component()->alive);
        self::assertNull((new Redis(logs: false))->component()->output);
    }

    /** @return iterable<string, array{\Closure(): Redis, string}> */
    public static function refusals(): iterable
    {
        yield 'a relative data directory' => [static fn() => new Redis(data: 'data'), 'data must be an absolute path'];
        yield 'a port out of range' => [static fn() => new Redis(port: 0), 'port must be 1–65535'];
        yield 'a host without a password' => [static fn() => new Redis(host: '0.0.0.0'), 'a host needs a password'];
        yield 'a password on two lines' => [static fn() => new Redis(password: "a\nb"), 'the password is one line'];
        yield 'aof without persist' => [static fn() => new Redis(persist: false, aof: true), 'aof keeps data on disk: it needs persist'];
        yield 'not a size' => [static fn() => new Redis(maxmemory: 'lots'), 'maxmemory must be a size'];
        yield 'not a policy' => [static fn() => new Redis(maxmemoryPolicy: 'lru'), 'maxmemoryPolicy "lru" is not one of'];
        yield 'a setting the service sets' => [static fn() => new Redis(settings: ['requirepass' => 'x']), "setting requirepass is the service's own"];
        yield 'a setting on two lines' => [static fn() => new Redis(settings: ['save' => "1\n2"]), 'a value is one line'];
        yield 'args not strings' => [static fn() => new Redis(args: [1]), 'args are a list of strings'];
        yield 'a name of other letters' => [static fn() => new Redis(name: 'Key Value'), 'name must be lowercase'];
    }

    #[DataProvider('refusals')]
    public function test_a_value_it_cannot_take_is_refused(\Closure $make, string $why): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($why);
        $make();
    }
}
