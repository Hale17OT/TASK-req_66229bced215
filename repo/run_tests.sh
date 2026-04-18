#!/usr/bin/env bash
# Validator-facing entry point. Delegates to the dockerized runner under
# scripts/ so both `./run_tests.sh` (bundle-root convention expected by the
# repo validator) and `./scripts/run_tests.sh` (historical path referenced
# from README.md) execute the same pipeline.
#
# All arguments pass through, e.g.:
#   ./run_tests.sh --testsuite=api
#   ./run_tests.sh --filter=AuthApi
set -euo pipefail
cd "$(dirname "$0")"
exec bash scripts/run_tests.sh "$@"
