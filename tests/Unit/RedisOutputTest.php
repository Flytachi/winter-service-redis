<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Redis\Tests\Unit;

use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Service\Redis\RedisOutput;
use PHPUnit\Framework\TestCase;

/** Redis's lines and the start script's, as the application's log reads them. */
final class RedisOutputTest extends TestCase
{
    private function read(string $line): OutputLine
    {
        return (new RedisOutput())->line($line);
    }

    public function test_a_mark_is_a_level_and_the_pid_is_who_wrote_it(): void
    {
        foreach (['.' => 'debug', '-' => 'debug', '*' => 'info', '#' => 'warning'] as $mark => $level) {
            $line = $this->read("22:M 15 Nov 2026 11:00:28.576 {$mark} Server initialized");
            self::assertSame([$level, 'Server initialized', 22], [$line->level, $line->message, $line->pid], $mark);
        }
        $child = $this->read('31:C 5 Nov 2026 11:00:30.166 * DB saved on disk');
        self::assertSame([31, 'DB saved on disk'], [$child->pid, $child->message], 'a child saving, its own PID');
    }

    public function test_the_last_words_of_a_good_stop_are_not_a_warning(): void
    {
        self::assertSame('info', $this->read('22:M 15 Nov 2026 11:00:30.169 # Redis is now ready to exit, bye bye...')->level);
        self::assertSame('warning', $this->read('22:M 15 Nov 2026 11:00:28.576 # WARNING Memory overcommit must be enabled!')->level);
    }

    public function test_the_signal_handlers_line(): void
    {
        $line = $this->read('22:signal-handler (1794740430) Received SIGTERM scheduling shutdown...');

        self::assertSame(['info', 'Received SIGTERM scheduling shutdown...', 22], [$line->level, $line->message, $line->pid]);
    }

    public function test_the_start_scripts_lines_and_the_rest(): void
    {
        $error = $this->read('winter-redis: ERROR: Redis is not installed: no redis-server.');

        self::assertSame(['error', 'Redis is not installed: no redis-server.'], [$error->level, $error->message]);
        self::assertSame(['info', 'anything else'], [$this->read('anything else')->level, $this->read('anything else')->message]);
        self::assertTrue($this->read('')->skip);
    }
}
