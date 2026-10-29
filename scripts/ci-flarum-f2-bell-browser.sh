#!/usr/bin/env bash
# Disposable Flarum 2.0.2 + Messaging UI Chromium proof for DM notification bell routing.
# PREPARATION ONLY — no production mutation.
set -euo pipefail

export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

ROOT="$(cd "${DM_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}" && pwd)"
MSG_ROOT="${COMBINED_MESSAGING_UI_ROOT:-/home/ilove/dev/flatrate-flarum-messaging-ui-f530fb43}"
EVIDENCE_DIR="${BELL_EVIDENCE_DIR:-/home/ilove/dev/_ops-evidence/forum-f2-dm-bell-route-001/chromium}"
SMOKE_ROOT="${FLARUM_SMOKE_ROOT:-${TMPDIR:-/tmp}/flarum-dm-f2-bell-$$}"
FLARUM_SKELETON_VERSION="${FLARUM_SKELETON_VERSION:-2.0.0}"
EXPECTED_CORE="${FLARUM_CORE_VERSION:-2.0.2}"
BASE="http://127.0.0.1:8768"
PLAYWRIGHT_ROOT="${PLAYWRIGHT_ROOT:-/home/ilove/dev/_labs/forum008-r4-messaging-browser}"

mkdir -p "${EVIDENCE_DIR}"

cleanup() {
  if [[ -n "${HTTP_PID:-}" ]]; then
    kill "${HTTP_PID}" >/dev/null 2>&1 || true
  fi
  if [[ "${KEEP_SMOKE_ROOT:-0}" != "1" ]]; then
    rm -rf "${SMOKE_ROOT}"
  fi
}
trap cleanup EXIT

if [[ ! -f "${ROOT}/composer.json" ]]; then
  echo "BELL_BROWSER=FAIL missing_dm_composer root=${ROOT}" >&2
  exit 1
fi
if [[ ! -f "${MSG_ROOT}/composer.json" ]]; then
  echo "BELL_BROWSER=FAIL missing_messaging_ui root=${MSG_ROOT}" >&2
  exit 1
fi

DM_SHA="$(git -C "${ROOT}" rev-parse HEAD)"
MSG_SHA="$(git -C "${MSG_ROOT}" rev-parse HEAD)"
echo "DM_SHA=${DM_SHA}" | tee "${EVIDENCE_DIR}/pins.txt"
echo "MSG_SHA=${MSG_SHA}" | tee -a "${EVIDENCE_DIR}/pins.txt"

rm -rf "${SMOKE_ROOT}"
mkdir -p "${SMOKE_ROOT}"

composer create-project "flarum/flarum:${FLARUM_SKELETON_VERSION}" "${SMOKE_ROOT}" --no-interaction --no-install
cd "${SMOKE_ROOT}"
rm -f composer.lock
composer require "flarum/core:${EXPECTED_CORE}" --no-update --no-interaction
composer update --no-interaction --prefer-dist --no-audit

composer config repositories.flatrate-private-messages path "${ROOT}"
composer config repositories.flatrate-messaging-ui path "${MSG_ROOT}"
composer require "flatrate/flarum-private-messages-bridge:*@dev" "flatrate/flarum-messaging-ui:*@dev" --no-interaction --prefer-dist --no-audit

cat > "${SMOKE_ROOT}/install.json" <<EOF
{
  "debug": true,
  "baseUrl": "${BASE}",
  "databaseConfiguration": {
    "driver": "sqlite",
    "database": "storage/f2-bell.sqlite"
  },
  "adminUser": {
    "username": "dmadmin",
    "password": "dm-admin-pass",
    "email": "dm-admin@example.test"
  },
  "settings": {
    "forum_title": "DM Bell Smoke"
  },
  "extensions": "flarum-suspend,flatrate-private-messages-bridge,flatrate-messaging-ui"
}
EOF

php flarum install --file "${SMOKE_ROOT}/install.json"
rm -f "${SMOKE_ROOT}/install.json"

php -r '
$pdo = new PDO("sqlite:storage/f2-bell.sqlite");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (["startConversation", "neoncube-private-messages.allowUsersToReceiveEmailNotifications"] as $permission) {
  $pdo->exec("INSERT INTO group_permission (group_id, permission) SELECT 3, \"".$permission."\" WHERE NOT EXISTS (SELECT 1 FROM group_permission WHERE group_id = 3 AND permission = \"".$permission."\")");
}
'

(
  cd "${SMOKE_ROOT}/public"
  php -S 127.0.0.1:8768 index.php > "${SMOKE_ROOT}/http.log" 2>&1
) &
HTTP_PID=$!

ready=0
for _ in $(seq 1 40); do
  if curl -s -o /dev/null "${BASE}/api"; then
    ready=1
    break
  fi
  sleep 0.25
done
if [[ "${ready}" != "1" ]]; then
  echo "BELL_BROWSER=FAIL server_not_ready" >&2
  cat "${SMOKE_ROOT}/http.log" >&2 || true
  exit 1
fi

token_for() {
  local user="$1" pass="$2" out code token
  out="$(mktemp)"
  code="$(curl -sS -o "${out}" -w '%{http_code}' -H 'Content-Type: application/json' \
    -d "{\"identification\":\"${user}\",\"password\":\"${pass}\"}" \
    "${BASE}/api/token")"
  token="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["token"] ?? "";' "${out}")"
  rm -f "${out}"
  if [[ -z "${token}" ]]; then
    echo "BELL_BROWSER=FAIL token user=${user} status=${code}" >&2
    exit 1
  fi
  printf '%s' "${token}"
}

create_user() {
  php -r '
    $site = require "site.php";
    $app = $site->bootApp();
    $user = new Flarum\User\User();
    $user->username = $argv[1];
    $user->email = $argv[2];
    $user->password = $argv[3];
    $user->is_email_confirmed = true;
    $user->joined_at = Carbon\Carbon::now();
    $user->save();
    $user->groups()->attach(Flarum\Group\Group::MEMBER_ID);
    echo $user->id;
  ' "$1" "$2" "$3"
}

ALICE_ID="$(create_user alice alice@example.test alice-pass-1)"
BOB_ID="$(create_user bob bob@example.test bob-pass-1)"
CAROL_ID="$(create_user carol carol@example.test carol-pass-1)"
ALICE_TOKEN="$(token_for alice alice-pass-1)"
BOB_TOKEN="$(token_for bob bob-pass-1)"
CAROL_TOKEN="$(token_for carol carol-pass-1)"

CREATED="$(mktemp)"
CREATED_CODE="$(curl -sS -o "${CREATED}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"recipient\":\"${BOB_ID}\",\"messageContents\":\"bell-route-qualification ping\"}}}" \
  "${BASE}/api/neoncube-private-messages/conversations")"
if [[ "${CREATED_CODE}" != "201" ]]; then
  echo "DM_CREATE=FAIL status=${CREATED_CODE}" >&2
  cat "${CREATED}" >&2
  exit 1
fi
CONV_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["data"]["id"] ?? "";' "${CREATED}")"
echo "DM_CREATE=PASS conversation=${CONV_ID}" | tee "${EVIDENCE_DIR}/api.txt"

REPLY_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${BOB_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"conversationId\":\"${CONV_ID}\",\"messageContents\":\"bob reply for unread transition\"}}}" \
  "${BASE}/api/neoncube-private-messages/messages")"
echo "DM_REPLY=status_${REPLY_CODE}" | tee -a "${EVIDENCE_DIR}/api.txt"

# Second message from Alice so Bob has a fresh unread PM notification.
PING2="$(mktemp)"
curl -sS -o "${PING2}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"conversationId\":\"${CONV_ID}\",\"messageContents\":\"bell acceptance ping two\"}}}" \
  "${BASE}/api/neoncube-private-messages/messages" >/dev/null

SUBJ="$(mktemp)"
SUBJ_CODE="$(curl -sS -o "${SUBJ}" -w '%{http_code}' -H "Authorization: Token ${BOB_TOKEN}" \
  "${BASE}/api/notifications?include=subject")"
echo "NOTIFICATIONS_INCLUDE_SUBJECT=HTTP_${SUBJ_CODE}" | tee -a "${EVIDENCE_DIR}/api.txt"
php -r '
$j=json_decode(file_get_contents($argv[1]), true);
$types=array_values(array_unique(array_map(fn($r)=>$r["attributes"]["contentType"]??null, $j["data"]??[])));
$incl=array_values(array_unique(array_map(fn($r)=>$r["type"]??null, $j["included"]??[])));
file_put_contents($argv[2], json_encode(["types"=>$types,"included"=>$incl,"total"=>($j["meta"]["page"]["total"]??null)], JSON_PRETTY_PRINT)."\n");
if (!in_array("newPrivateMessage", $types, true)) { fwrite(STDERR, "NOTIFICATIONS_INCLUDE_SUBJECT=FAIL missing newPrivateMessage\n"); exit(1); }
if (!in_array("neoncube-private-messages-messages", $incl, true)) { fwrite(STDERR, "NOTIFICATIONS_INCLUDE_SUBJECT=FAIL missing subject type\n"); exit(1); }
echo "NOTIFICATIONS_INCLUDE_SUBJECT=PASS\n";
' "${SUBJ}" "${EVIDENCE_DIR}/bob-notifications-subject.json"

GUEST_CODE="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/notifications")"
echo "GUEST_NOTIFICATIONS=HTTP_${GUEST_CODE}" | tee -a "${EVIDENCE_DIR}/api.txt"

NONPART="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${CAROL_TOKEN}" \
  "${BASE}/api/neoncube-private-messages/messages/${CONV_ID}")"
echo "NONPARTICIPANT_MESSAGES=HTTP_${NONPART}" | tee -a "${EVIDENCE_DIR}/api.txt"

# Deleted/unavailable subject: remove a message row while leaving its notification.
php -r '
$pdo = new PDO("sqlite:storage/f2-bell.sqlite");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$row = $pdo->query("SELECT subject_id FROM notifications WHERE type = \"newPrivateMessage\" AND subject_id IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row && !empty($row["subject_id"])) {
  $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ?");
  $stmt->execute([$row["subject_id"]]);
  echo "ORPHANED_SUBJECT_ID=".$row["subject_id"]."\n";
} else {
  echo "ORPHANED_SUBJECT_ID=NONE\n";
}
'
ORPHAN_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${BOB_TOKEN}" \
  "${BASE}/api/notifications?include=subject")"
echo "ORPHAN_SUBJECT_INCLUDE=HTTP_${ORPHAN_CODE}" | tee -a "${EVIDENCE_DIR}/api.txt"
if [[ "${ORPHAN_CODE}" != "200" ]]; then
  echo "ORPHAN_SUBJECT_INCLUDE=FAIL" >&2
  exit 1
fi

cat > "${EVIDENCE_DIR}/fixture.json" <<EOF
{
  "baseUrl": "${BASE}",
  "conversationId": "${CONV_ID}",
  "bob": {"username": "bob", "password": "bob-pass-1"},
  "alice": {"username": "alice", "password": "alice-pass-1"},
  "expectedHref": "/messages/direct/${CONV_ID}",
  "dmSha": "${DM_SHA}",
  "msgSha": "${MSG_SHA}"
}
EOF

if [[ ! -d "${PLAYWRIGHT_ROOT}/node_modules/@playwright/test" ]]; then
  (cd "${PLAYWRIGHT_ROOT}" && npm ci)
fi

export BROWSER_FIXTURE_PATH="${EVIDENCE_DIR}/fixture.json"
export BROWSER_BASE_URL="${BASE}"
export PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-${HOME}/.cache/ms-playwright}"
if command -v chromium >/dev/null 2>&1; then
  export PLAYWRIGHT_EXECUTABLE_PATH="$(command -v chromium)"
fi

node "${ROOT}/scripts/ci-flarum-f2-bell-browser.mjs" 2>&1 | tee "${EVIDENCE_DIR}/browser.log"
echo "BELL_BROWSER=PASS" | tee -a "${EVIDENCE_DIR}/summary.txt"
