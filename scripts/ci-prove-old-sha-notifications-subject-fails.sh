#!/usr/bin/env bash
# Prove deployed DM SHA e5fe810 fails F2 /api/notifications?include=subject.
# Uses the CURRENT HTTP harness against a worktree of the frozen production SHA.
# Expectation: the suite fails (non-zero) once notification subject assertions run.
set -euo pipefail

export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
OLD_DM_SHA="${OLD_DM_SHA:-e5fe8107af55207cf40be6845f7502a92a7385f4}"
EXPECTED_TREE="${EXPECTED_OLD_DM_TREE:-d77bbd9a39a6a1df2f9dbafc13809e6095ee6bdb}"

git config --global --add safe.directory "${REPO_ROOT}" || true
git -C "${REPO_ROOT}" cat-file -t "${OLD_DM_SHA}" >/dev/null
ACTUAL_TREE="$(git -C "${REPO_ROOT}" rev-parse "${OLD_DM_SHA}^{tree}")"
if [[ "${ACTUAL_TREE}" != "${EXPECTED_TREE}" ]]; then
  echo "OLD_SHA_TREE=FAIL expected=${EXPECTED_TREE} actual=${ACTUAL_TREE}" >&2
  exit 1
fi
echo "OLD_DM_SHA=${OLD_DM_SHA}"
echo "OLD_DM_TREE=${ACTUAL_TREE}"

WORKTREE="${TMPDIR:-/tmp}/dm-old-sha-notif-fail-$$"
cleanup() {
  git -C "${REPO_ROOT}" worktree remove --force "${WORKTREE}" >/dev/null 2>&1 || rm -rf "${WORKTREE}"
}
trap cleanup EXIT

git -C "${REPO_ROOT}" worktree add --detach "${WORKTREE}" "${OLD_DM_SHA}"

# Confirm old source lacks MessageResource registration (control).
if grep -q 'MessageResource' "${WORKTREE}/extend-f2.php" 2>/dev/null; then
  echo "OLD_SHA_CONTROL=FAIL unexpected_MessageResource_in_e5fe810" >&2
  exit 1
fi
echo "OLD_SHA_CONTROL=PASS no_MessageResource"

set +e
DM_ROOT="${WORKTREE}" \
  FLARUM_SMOKE_ROOT="${TMPDIR:-/tmp}/flarum-dm-f2-old-notif-$$" \
  bash "${SCRIPT_DIR}/ci-flarum-f2-http.sh"
STATUS=$?
set -e

if [[ "${STATUS}" -eq 0 ]]; then
  echo "REGRESSION_TEST_OLD_SHA=UNEXPECTED_PASS" >&2
  echo "Old source must fail authenticated /api/notifications?include=subject." >&2
  exit 1
fi

echo "REGRESSION_TEST_OLD_SHA=FAIL_EXPECTED"
echo "OLD_SHA_REGRESSION_FAILS_EXPECTED=PASS"
echo "FAILURE_CLASS=NOTIFICATION_SUBJECT_TYPE_RESOLUTION"
exit 0
