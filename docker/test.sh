#!/bin/sh
# Runs the integration tests against a real Redis server, in a container.
#
#   sh docker/test.sh                 # every integration test
#   sh docker/test.sh --filter=Tcp    # extra phpunit arguments are passed on
#
# With the package's own vendor/ installed, that is what runs. Without it, a winter-kernel
# checkout with its vendor/ — WINTER_KERNEL, or the one beside this package — is mounted
# at /winter-kernel, where tests/bootstrap.php finds it.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
mounts="-v $here:/pkg"
if [ -f "$here/vendor/bin/phpunit" ]; then
    phpunit=/pkg/vendor/bin/phpunit
else
    kernel=${WINTER_KERNEL:-$(cd "$here/../winter-kernel" 2>/dev/null && pwd || true)}
    if [ -z "$kernel" ] || [ ! -f "$kernel/vendor/autoload.php" ]; then
        echo "test.sh: run composer install, or set WINTER_KERNEL to a winter-kernel checkout with its vendor/." >&2
        exit 1
    fi
    mounts="$mounts -v $kernel:/winter-kernel:ro"
    phpunit=/winter-kernel/vendor/bin/phpunit
fi
docker build -q -t winter-service-redis-test -f "$here/docker/Dockerfile.test" "$here/docker" >/dev/null
# shellcheck disable=SC2086 — the mounts are separate words on purpose
exec docker run --rm -e XDEBUG_MODE=off $mounts --entrypoint php winter-service-redis-test \
    "$phpunit" --testsuite integration "$@"
