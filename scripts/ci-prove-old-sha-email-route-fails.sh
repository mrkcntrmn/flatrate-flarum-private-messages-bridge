#!/usr/bin/env bash
# Prove the production EXEC-003 email-route failure against the frozen old DM SHA.
# Uses the CURRENT test harness (this script's sibling) against a worktree of dae03bc.
# Expectation: create-with-email-notifications FAILS (non-zero exit from HTTP suite).
set -euo pipefail

export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
OLD_DM_SHA="${OLD_DM_SHA:-dae03bcbea01f6d29a7c72d424870d4013b46e53}"
EXPECTED_TREE="${EXPECTED_OLD_DM_TREE:-61d601b0b1ebe7c941ad0d0f0e48316adc06730b}"

git config --global --add safe.directory "${REPO_ROOT}" || true
git -C "${REPO_ROOT}" cat-file -t "${OLD_DM_SHA}" >/dev/null
ACTUAL_TREE="$(git -C "${REPO_ROOT}" rev-parse "${OLD_DM_SHA}^{tree}")"
if [[ "${ACTUAL_TREE}" != "${EXPECTED_TREE}" ]]; then
  echo "OLD_SHA_TREE=FAIL expected=${EXPECTED_TREE} actual=${ACTUAL_TREE}" >&2
  exit 1
fi
echo "OLD_DM_SHA=${OLD_DM_SHA}"
echo "OLD_DM_TREE=${ACTUAL_TREE}"

WORKTREE="${TMPDIR:-/tmp}/dm-old-sha-email-fail-$$"
cleanup() {
  git -C "${REPO_ROOT}" worktree remove --force "${WORKTREE}" >/dev/null 2>&1 || rm -rf "${WORKTREE}"
}
trap cleanup EXIT

git -C "${REPO_ROOT}" worktree add --detach "${WORKTREE}" "${OLD_DM_SHA}"

# The HTTP suite from HEAD must exercise the sync email path. Point it at the old
# package source so composer path-requires dae03bc while using the new assertions.
set +e
DM_ROOT="${WORKTREE}" \
  FLARUM_SMOKE_ROOT="${TMPDIR:-/tmp}/flarum-dm-f2-old-email-$$" \
  bash "${SCRIPT_DIR}/ci-flarum-f2-http.sh"
STATUS=$?
set -e

if [[ "${STATUS}" -eq 0 ]]; then
  echo "REGRESSION_TEST_OLD_SHA=UNEXPECTED_PASS" >&2
  echo "Old source must fail the sync-email create path (missing named forum route)." >&2
  exit 1
fi

echo "REGRESSION_TEST_OLD_SHA=FAIL_EXPECTED"
echo "OLD_SHA_REGRESSION_FAILS_EXPECTED=PASS"
echo "FAILURE_CLASS=EMAIL_ROUTE_GENERATION"
exit 0
