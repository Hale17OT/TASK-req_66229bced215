set -euo pipefail
cd "$(dirname "$0")"
exec bash scripts/run_tests.sh "$@"
