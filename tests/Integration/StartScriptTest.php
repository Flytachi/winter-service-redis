<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Redis\Tests\Integration;

/**
 * The start script against a real server: its data is kept across a stop, or not kept at
 * all for a cache; the password is asked for through the socket and over TCP, and is on no
 * command line; the limits and the settings reach the server.
 */
final class StartScriptTest extends ServerCase
{
    public function test_its_data_is_saved_on_a_stop_and_back_after_a_start(): void
    {
        $db = $this->redis();
        self::assertTrue($this->start($db), $this->serverOutput());
        $this->client($db)->set('greeting', 'kept');

        self::assertSame(0, $this->stop(), 'a stop that saved exits 0');
        self::assertStringContainsString('DB saved on disk', $this->serverOutput());
        self::assertFileExists($this->dir . '/data/dump.rdb');

        self::assertTrue($this->start($db), $this->serverOutput());
        self::assertSame('kept', $this->client($db)->get('greeting'));
    }

    public function test_a_cache_keeps_nothing(): void
    {
        $db = $this->redis(persist: false);
        self::assertTrue($this->start($db), $this->serverOutput());
        $this->client($db)->set('greeting', 'gone');
        $this->stop();

        self::assertDirectoryDoesNotExist($this->dir . '/data');
        self::assertTrue($this->start($db), $this->serverOutput());
        self::assertFalse($this->client($db)->get('greeting'));
    }

    public function test_an_append_only_file_keeps_every_write(): void
    {
        $db = $this->redis(aof: true);
        self::assertTrue($this->start($db), $this->serverOutput());
        $this->client($db)->set('greeting', 'logged');

        self::assertSame('yes', $this->client($db)->config('GET', 'appendonly')['appendonly']);
        self::assertDirectoryExists($this->dir . '/data/appendonlydir');
    }

    public function test_the_password_is_asked_for_through_the_socket(): void
    {
        $db = $this->redis(password: 'hidden-pw');
        self::assertTrue($this->start($db), $this->serverOutput());

        $anonymous = new \Redis();
        $anonymous->connect($db->socketPath());
        try {
            $anonymous->get('x');
            self::fail('no password, yet it answered');
        } catch (\RedisException $e) {
            self::assertStringContainsString('NOAUTH', $e->getMessage());
        }
        self::assertTrue($this->client($db)->set('x', '1'));

        exec('ps -o args', $lines);
        self::assertSame([], preg_grep('/hidden-pw/', $lines), 'no process shows it');
    }

    public function test_over_tcp_with_its_password_only(): void
    {
        $port = $this->freePort();
        $db = $this->redis(host: '127.0.0.1', port: $port, password: 'pw');
        self::assertTrue($this->start($db), $this->serverOutput());

        $tcp = new \Redis();
        $tcp->connect('127.0.0.1', $port);
        try {
            $tcp->auth('wrong');
            self::fail('a wrong password got in');
        } catch (\RedisException $e) {
            self::assertStringContainsString('WRONGPASS', $e->getMessage());
        }
        self::assertTrue($tcp->auth('pw'));
        self::assertTrue($tcp->ping());
    }

    public function test_the_limits_and_settings_reach_the_server(): void
    {
        $db = $this->redis(maxmemory: '64mb', maxmemoryPolicy: 'allkeys-lru', settings: ['databases' => 4]);
        self::assertTrue($this->start($db), $this->serverOutput());

        $client = $this->client($db);
        self::assertSame((string) (64 * 1024 * 1024), $client->config('GET', 'maxmemory')['maxmemory']);
        self::assertSame('allkeys-lru', $client->config('GET', 'maxmemory-policy')['maxmemory-policy']);
        self::assertSame('4', $client->config('GET', 'databases')['databases']);
    }

    public function test_without_a_host_nothing_listens_on_tcp(): void
    {
        $db = $this->redis();
        self::assertTrue($this->start($db), $this->serverOutput());

        self::assertSame('0', $this->client($db)->config('GET', 'port')['port']);
    }
}
