#!/usr/bin/env bash
set -eu
cd "$(dirname "$0")"
exec bash scripts/run_tests.sh "$@"
