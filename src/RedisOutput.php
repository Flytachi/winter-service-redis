<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Redis;

use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Kernel\App\ProgramOutput;

/**
 * Redis's log, read into the application's: each entry at its level, with the PID of the
 * process that wrote it — the server, or a child saving a snapshot.
 *
 * Reads the server's lines (`22:M 15 Nov 2026 11:00:28.576 * Server initialized`), its
 * signal handler's (`22:signal-handler (…) Received SIGTERM…`) and the start script's
 * (`winter-redis: …`). A line it does not know is an entry of its own, at info, as it is.
 *
 * | Redis | the log |
 * | --- | --- |
 * | `.` debug, `-` verbose | debug |
 * | `*` notice | info |
 * | `#` warning | warning |
 *
 * Redis says its last words at warning — `Redis is now ready to exit, bye bye...` — a
 * stop that went well: that line is info.
 */
final readonly class RedisOutput implements ProgramOutput
{
    /** `PID:role day month year time mark message` — the time is the log's own. */
    private const string LINE = '/^(\d+):[A-Z] \d{1,2} [A-Z][a-z]{2} \d{4} \d\d:\d\d:\d\d(?:\.\d+)? ([.*#-]) (.*)$/s';

    private const string SIGNAL = '/^(\d+):signal-handler \(\d+\) (.*)$/s';

    private const string SCRIPT = 'winter-redis: ';

    private const array LEVELS = ['.' => 'debug', '-' => 'debug', '*' => 'info', '#' => 'warning'];

    /** Lines Redis writes at warning that say nothing is wrong. */
    private const array CALM = ['Redis is now ready to exit, bye bye...'];

    public function line(string $line): OutputLine
    {
        if (str_starts_with($line, self::SCRIPT)) {
            $said = substr($line, strlen(self::SCRIPT));
            foreach (['WARNING' => 'warning', 'ERROR' => 'error'] as $tag => $level) {
                if (str_starts_with($said, "{$tag}: ")) {
                    return OutputLine::entry($level, substr($said, strlen($tag) + 2));
                }
            }
            return OutputLine::entry('info', $said);
        }
        if (preg_match(self::LINE, $line, $m) === 1) {
            [, $pid, $mark, $text] = $m;
            $level = in_array($text, self::CALM, true) ? 'info' : self::LEVELS[$mark];
            return OutputLine::entry($level, $text, pid: (int) $pid);
        }
        if (preg_match(self::SIGNAL, $line, $m) === 1) {
            return OutputLine::entry('info', $m[2], pid: (int) $m[1]);
        }
        if (trim($line) === '') {
            return OutputLine::skip();
        }
        return OutputLine::entry('info', $line);
    }
}
