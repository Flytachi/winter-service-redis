# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-11-15

### Added

- `Redis` — Redis as a service of a Winter application (`Components::service()`): High
  priority, ready once a `PING` through its socket is answered (not while it is still
  loading), checked while it runs (`alive`), stopped with SIGTERM — saving its data
  first —, restarted after a crash. Server parameters (`data`, `socket`, `host`, `port`,
  `password`, `persist`, `aof`, `maxmemory`, `maxmemoryPolicy`, `settings`, `args`,
  `logs`) and component parameters (`name`, `priority`, `critical`, `retries`, `grace`,
  `startTimeout`, `alive`), each checked when the object is made.
- `bin/winter-redis` — writes the configuration to the server's standard input (the
  password on no command line and never on disk) and becomes the server.
- `RedisOutput` — Redis's log read into the application's (kernel's `ProgramOutput`):
  `.`/`-` → debug, `*` → info, `#` → warning, with the PID of the process that wrote the
  line; the calm last words of a stop at info.
- `image()` for `call service docker`: `redis`; the socket directory, and with `persist`
  the data directory and its volume, owned by winter.
- `socketPath()` and `isReady()` for connecting and checking.
