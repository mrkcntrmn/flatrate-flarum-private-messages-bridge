#!/usr/bin/env bash
# Disposable Flarum 2.0.2 HTTP proof for the private-message bridge.
# No production data, credentials, or installation.
set -euo pipefail

export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
git config --global --add safe.directory "${ROOT}" || true
if ! git -C "${ROOT}" symbolic-ref -q HEAD >/dev/null; then
  git -C "${ROOT}" checkout -B ci-f2-private-messages-http
fi

SMOKE_ROOT="${FLARUM_SMOKE_ROOT:-${TMPDIR:-/tmp}/flarum-dm-f2-http-$$}"
FLARUM_SKELETON_VERSION="${FLARUM_SKELETON_VERSION:-2.0.0}"
EXPECTED_CORE="${FLARUM_CORE_VERSION:-2.0.2}"
BASE="http://127.0.0.1:8766"

cleanup() {
  if [[ -n "${HTTP_PID:-}" ]]; then
    kill "${HTTP_PID}" >/dev/null 2>&1 || true
  fi
  if [[ "${KEEP_SMOKE_ROOT:-0}" != "1" ]]; then
    rm -rf "${SMOKE_ROOT}"
  fi
}
trap cleanup EXIT

rm -rf "${SMOKE_ROOT}"
mkdir -p "${SMOKE_ROOT}"

composer create-project "flarum/flarum:${FLARUM_SKELETON_VERSION}" "${SMOKE_ROOT}" --no-interaction --no-install
cd "${SMOKE_ROOT}"
rm -f composer.lock
composer require "flarum/core:${EXPECTED_CORE}" --no-update --no-interaction
composer update --no-interaction --prefer-dist --no-audit

INSTALLED_CORE="$(
  composer show flarum/core --format=json \
    | php -r '$j=json_decode(stream_get_contents(STDIN), true); $v=$j["versions"][0] ?? ""; echo preg_replace("/^v/", "", (string)$v);'
)"
if [[ "${INSTALLED_CORE}" != "${EXPECTED_CORE}" ]]; then
  echo "DM_F2_HTTP=FAIL expected_core=${EXPECTED_CORE} got=${INSTALLED_CORE}" >&2
  exit 1
fi

composer config repositories.flatrate-private-messages path "${ROOT}"
composer require "flatrate/flarum-private-messages-bridge:*@dev" --no-interaction --prefer-dist --no-audit

cat > "${SMOKE_ROOT}/install.json" <<EOF
{
  "debug": true,
  "baseUrl": "${BASE}",
  "databaseConfiguration": {
    "driver": "sqlite",
    "database": "storage/f2-dm.sqlite"
  },
  "adminUser": {
    "username": "dmadmin",
    "password": "dm-admin-pass",
    "email": "dm-admin@example.test"
  },
  "settings": {
    "forum_title": "Direct Message Smoke"
  },
  "extensions": "flarum-suspend,flatrate-private-messages-bridge"
}
EOF

php flarum install --file "${SMOKE_ROOT}/install.json"
rm -f "${SMOKE_ROOT}/install.json"

php -r '
$pdo = new PDO("sqlite:storage/f2-dm.sqlite");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (["startConversation", "neoncube-private-messages.allowUsersToReceiveEmailNotifications"] as $permission) {
  $pdo->exec("INSERT INTO group_permission (group_id, permission) SELECT 3, \"".$permission."\" WHERE NOT EXISTS (SELECT 1 FROM group_permission WHERE group_id = 3 AND permission = \"".$permission."\")");
}
'

(
  cd "${SMOKE_ROOT}/public"
  php -S 127.0.0.1:8766 index.php > "${SMOKE_ROOT}/http.log" 2>&1
) &
HTTP_PID=$!

ready=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  if curl -s -o /dev/null "${BASE}/api"; then
    ready=1
    break
  fi
  sleep 0.25
done
if [[ "${ready}" != "1" ]]; then
  echo "DM_F2_HTTP=FAIL server_not_ready" >&2
  cat "${SMOKE_ROOT}/http.log" >&2 || true
  exit 1
fi

token_for() {
  local user="$1"
  local pass="$2"
  local out
  out="$(mktemp)"
  local code
  code="$(curl -sS -o "${out}" -w '%{http_code}' -H 'Content-Type: application/json' \
    -d "{\"identification\":\"${user}\",\"password\":\"${pass}\"}" \
    "${BASE}/api/token")"
  local token
  token="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["token"] ?? "";' "${out}")"
  if [[ -z "${token}" ]]; then
    echo "DM_F2_HTTP=FAIL token user=${user} status=${code}" >&2
    cat "${out}" >&2 || true
    exit 1
  fi
  rm -f "${out}"
  printf '%s' "${token}"
}

create_user() {
  local name="$1"
  local email="$2"
  local pass="$3"
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
  ' "${name}" "${email}" "${pass}"
}

expect_status() {
  local label="$1"
  local expected="$2"
  local actual="$3"
  if [[ "${actual}" != "${expected}" ]]; then
    echo "${label}=FAIL expected=${expected} actual=${actual}" >&2
    exit 1
  fi
  echo "${label}=PASS"
}

ADMIN_TOKEN="$(token_for dmadmin dm-admin-pass)"
ALICE_ID="$(create_user alice alice@example.test alice-pass-1)"
BOB_ID="$(create_user bob bob@example.test bob-pass-1)"
CAROL_ID="$(create_user carol carol@example.test carol-pass-1)"
DAVE_ID="$(create_user dave dave@example.test dave-pass-1)"
DAVE_TOKEN="$(token_for dave dave-pass-1)"

php -r '
$pdo = new PDO("sqlite:storage/f2-dm.sqlite");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dave = $argv[1];
$pdo->prepare("UPDATE users SET suspended_until = \"2099-01-01 00:00:00\" WHERE id = ?")->execute([$dave]);
$pdo->exec("INSERT INTO conversations (total_messages, created_at, updated_at) VALUES (2, \"2020-01-01 00:00:00\", \"2020-01-02 00:00:00\")");
$conversation = (int) $pdo->lastInsertId();
$bob = $argv[2];
$carol = $argv[3];
$insertUser = $pdo->prepare("INSERT INTO conversation_user (conversation_id, user_id, last_read_message_number, last_read_at) VALUES (?, ?, 0, NULL)");
$insertUser->execute([$conversation, $bob]);
$insertUser->execute([$conversation, $carol]);
$insertMessage = $pdo->prepare("INSERT INTO messages (message, is_seen, is_hidden, user_id, number, conversation_id, created_at, updated_at) VALUES (?, 0, 0, ?, ?, ?, \"2020-01-01 00:00:00\", \"2020-01-01 00:00:00\")");
$insertMessage->execute(["historical-one", $bob, 1, $conversation]);
$insertMessage->execute(["historical-two", $carol, 2, $conversation]);
file_put_contents("storage/historical-conversation-id", (string) $conversation);
' "${DAVE_ID}" "${BOB_ID}" "${CAROL_ID}"

HISTORICAL_ID="$(cat storage/historical-conversation-id)"
BEFORE_MESSAGES="$(php -r '$pdo=new PDO("sqlite:storage/f2-dm.sqlite"); echo $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();')"
BEFORE_CONVERSATIONS="$(php -r '$pdo=new PDO("sqlite:storage/f2-dm.sqlite"); echo $pdo->query("SELECT COUNT(*) FROM conversations")->fetchColumn();')"

ALICE_TOKEN="$(token_for alice alice-pass-1)"
BOB_TOKEN="$(token_for bob bob-pass-1)"
CAROL_TOKEN="$(token_for carol carol-pass-1)"

GUEST_CODE="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/neoncube-private-messages/conversations")"
if [[ "${GUEST_CODE}" != "401" && "${GUEST_CODE}" != "403" ]]; then
  echo "DM_GUEST_READ=FAIL status=${GUEST_CODE}" >&2
  exit 1
fi
echo "DM_GUEST_READ=DENY status=${GUEST_CODE}"

DAVE_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${DAVE_TOKEN}" "${BASE}/api/neoncube-private-messages/conversations")"
if [[ "${DAVE_CODE}" != "401" && "${DAVE_CODE}" != "403" ]]; then
  echo "DM_SUSPENDED_USER=FAIL status=${DAVE_CODE}" >&2
  exit 1
fi
echo "DM_SUSPENDED_USER=DENY status=${DAVE_CODE}"

ALICE_LIST="$(mktemp)"
ALICE_LIST_CODE="$(curl -sS -o "${ALICE_LIST}" -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/neoncube-private-messages/conversations")"
expect_status DM_AUTHORIZED_PARTICIPANT_READ_EMPTY "${ALICE_LIST_CODE}" 200
if grep -Fq "\"id\":\"${HISTORICAL_ID}\"" "${ALICE_LIST}"; then
  echo "DM_WRONG_USER_READ=FAIL historical_visible_to_alice" >&2
  exit 1
fi

CAROL_READ_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${CAROL_TOKEN}" "${BASE}/api/neoncube-private-messages/messages/${HISTORICAL_ID}")"
expect_status DM_CAROL_HISTORY_READ "${CAROL_READ_CODE}" 200
ALICE_HISTORY_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/neoncube-private-messages/messages/${HISTORICAL_ID}")"
expect_status DM_WRONG_USER_READ "${ALICE_HISTORY_CODE}" 403

HISTORY_BODY="$(mktemp)"
curl -fsS -o "${HISTORY_BODY}" -H "Authorization: Token ${BOB_TOKEN}" "${BASE}/api/neoncube-private-messages/messages/${HISTORICAL_ID}"
php -r '
$body = json_decode(file_get_contents($argv[1]), true);
$rows = $body["data"] ?? [];
$contents = array_map(fn ($row) => $row["attributes"]["message"] ?? "", $rows);
$numbers = array_map(fn ($row) => $row["attributes"]["number"] ?? null, $rows);
if ($contents !== ["historical-one", "historical-two"] || $numbers !== [1, 2]) {
    fwrite(STDERR, "DM_HISTORY_PRESERVED=FAIL ".json_encode($rows)."\n");
    exit(1);
}
' "${HISTORY_BODY}"
echo "DM_HISTORY_PRESERVED=PASS"
rm -f "${HISTORY_BODY}"

ALICE_WRITE_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"conversationId\":\"${HISTORICAL_ID}\",\"messageContents\":\"alice-should-not-post\"}}}" \
  "${BASE}/api/neoncube-private-messages/messages")"
expect_status DM_WRONG_USER_WRITE "${ALICE_WRITE_CODE}" 403

CREATED="$(mktemp)"
CREATED_CODE="$(curl -sS -o "${CREATED}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"recipient\":\"${BOB_ID}\",\"messageContents\":\"hello bob\"}}}" \
  "${BASE}/api/neoncube-private-messages/conversations")"
if [[ "${CREATED_CODE}" != "201" ]]; then
  echo "DM_AUTHORIZED_PARTICIPANT_SEND=FAIL status=${CREATED_CODE}" >&2
  cat "${CREATED}" >&2 || true
  tail -n 120 "${SMOKE_ROOT}/http.log" >&2 || true
  exit 1
fi
echo "F2_HTTP_CREATE_CONVERSATION=PASS"
echo "F2_INITIAL_MESSAGE_PERSISTED=PASS"
echo "DM_AUTHORIZED_PARTICIPANT_SEND=PASS"
NEW_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["data"]["id"] ?? "";' "${CREATED}")"
rm -f "${CREATED}"
if [[ -z "${NEW_ID}" || "${NEW_ID}" == "${HISTORICAL_ID}" ]]; then
  echo "DM_F2_HTTP=FAIL duplicate_or_missing_conversation" >&2
  exit 1
fi

BOB_NEW="$(mktemp)"
curl -fsS -o "${BOB_NEW}" -H "Authorization: Token ${BOB_TOKEN}" "${BASE}/api/neoncube-private-messages/messages/${NEW_ID}"
php -r '
$body = json_decode(file_get_contents($argv[1]), true);
$row = $body["data"][0] ?? [];
if (($row["attributes"]["message"] ?? "") !== "hello bob" || (int) ($row["attributes"]["userId"] ?? 0) !== (int) $argv[2]) {
    fwrite(STDERR, "DM_AUTHORIZED_PARTICIPANT_READ=FAIL\n");
    exit(1);
}
' "${BOB_NEW}" "${ALICE_ID}"
echo "DM_AUTHORIZED_PARTICIPANT_READ=PASS"
rm -f "${BOB_NEW}" "${ALICE_LIST}"

# Reply flow: Bob responds; Alice must see the reply in order.
REPLY="$(mktemp)"
REPLY_CODE="$(curl -sS -o "${REPLY}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${BOB_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"conversationId\":\"${NEW_ID}\",\"messageContents\":\"hello alice reply\"}}}" \
  "${BASE}/api/neoncube-private-messages/messages")"
if [[ "${REPLY_CODE}" != "201" && "${REPLY_CODE}" != "200" ]]; then
  echo "DM_AUTHORIZED_PARTICIPANT_REPLY=FAIL status=${REPLY_CODE}" >&2
  cat "${REPLY}" >&2 || true
  tail -n 80 "${SMOKE_ROOT}/http.log" >&2 || true
  exit 1
fi
echo "DM_AUTHORIZED_PARTICIPANT_REPLY=PASS status=${REPLY_CODE}"
rm -f "${REPLY}"

ALICE_THREAD="$(mktemp)"
curl -fsS -o "${ALICE_THREAD}" -H "Authorization: Token ${ALICE_TOKEN}" \
  "${BASE}/api/neoncube-private-messages/messages/${NEW_ID}"
php -r '
$body = json_decode(file_get_contents($argv[1]), true);
$rows = $body["data"] ?? [];
$contents = array_map(fn ($row) => $row["attributes"]["message"] ?? "", $rows);
$senders = array_map(fn ($row) => (int) ($row["attributes"]["userId"] ?? 0), $rows);
if ($contents !== ["hello bob", "hello alice reply"]) {
    fwrite(STDERR, "DM_MESSAGE_ORDER=FAIL ".json_encode($contents)."\n");
    exit(1);
}
if ($senders !== [(int) $argv[2], (int) $argv[3]]) {
    fwrite(STDERR, "DM_REPLY_SENDER=FAIL ".json_encode($senders)."\n");
    exit(1);
}
' "${ALICE_THREAD}" "${ALICE_ID}" "${BOB_ID}"
echo "DM_MESSAGE_ORDER=PASS"
echo "DM_REPLY_SENDER=PASS"
rm -f "${ALICE_THREAD}"

# Guest and non-participant denial on the new conversation write path.
BEFORE_GUEST_MESSAGES="$(php -r '$pdo=new PDO("sqlite:storage/f2-dm.sqlite"); echo $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();')"
GUEST_WRITE_BODY="$(mktemp)"
GUEST_WRITE_CODE="$(curl -sS -o "${GUEST_WRITE_BODY}" -w '%{http_code}' -H 'Content-Type: application/json' \
  -d "{\"data\":{\"attributes\":{\"conversationId\":\"${NEW_ID}\",\"messageContents\":\"guest-should-not-post\"}}}" \
  "${BASE}/api/neoncube-private-messages/messages")"
AFTER_GUEST_MESSAGES="$(php -r '$pdo=new PDO("sqlite:storage/f2-dm.sqlite"); echo $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();')"
if [[ "${AFTER_GUEST_MESSAGES}" != "${BEFORE_GUEST_MESSAGES}" ]]; then
  echo "DM_GUEST_WRITE=FAIL message_persisted status=${GUEST_WRITE_CODE}" >&2
  cat "${GUEST_WRITE_BODY}" >&2 || true
  exit 1
fi
if [[ "${GUEST_WRITE_CODE}" != "401" && "${GUEST_WRITE_CODE}" != "403" && "${GUEST_WRITE_CODE}" != "400" ]]; then
  echo "DM_GUEST_WRITE=FAIL status=${GUEST_WRITE_CODE}" >&2
  cat "${GUEST_WRITE_BODY}" >&2 || true
  exit 1
fi
echo "DM_GUEST_WRITE=DENY status=${GUEST_WRITE_CODE}"
rm -f "${GUEST_WRITE_BODY}"

# Notification blueprint path: email-notification permission is granted above so
# NewPrivateMessageBlueprint is constructed during create/reply. A PHP fatal here
# would have already failed the HTTP create/reply assertions.
php -r '
require "vendor/autoload.php";
if (!class_exists("Neoncube\\FlarumPrivateMessages\\Notifications\\NewPrivateMessageBlueprint")) {
    fwrite(STDERR, "DM_BLUEPRINT_LOAD=FAIL\n");
    exit(1);
}
$ref = new ReflectionClass("Neoncube\\FlarumPrivateMessages\\Notifications\\NewPrivateMessageBlueprint");
$type = $ref->getMethod("getFromUser")->getReturnType();
if (!$type || (string) $type !== "?Flarum\\User\\User") {
    fwrite(STDERR, "DM_BLUEPRINT_INTERFACE=FAIL return=".($type ? (string) $type : "NONE")."\n");
    exit(1);
}
echo "DM_BLUEPRINT_LOAD=PASS\n";
echo "DM_BLUEPRINT_INTERFACE=PASS\n";
'
# Require at least one alert-driver notification for the create/reply traffic.
NOTIF_COUNT="$(php -r '$pdo=new PDO("sqlite:storage/f2-dm.sqlite"); echo $pdo->query("SELECT COUNT(*) FROM notifications WHERE type = \"newPrivateMessage\"")->fetchColumn();')"
if [[ "${NOTIF_COUNT}" -lt 1 ]]; then
  echo "DM_NOTIFICATION_GENERATION=FAIL count=${NOTIF_COUNT}" >&2
  php -r '$pdo=new PDO("sqlite:storage/f2-dm.sqlite"); foreach ($pdo->query("SELECT id,user_id,type,subject_id,data FROM notifications") as $row) { echo json_encode($row), "\n"; }' >&2 || true
  exit 1
fi
echo "DM_NOTIFICATION_GENERATION=PASS count=${NOTIF_COUNT}"
echo "DM_NOTIFICATION_PATH=PASS"

# Production-equivalent forum frontend compile using the packaged F2 stylesheet.
# Do not inject theme stubs into vendor; fail if the install was patched.
VENDOR_DM_LESS="${SMOKE_ROOT}/vendor/flatrate/flarum-private-messages-bridge/resources/less/forum-f2.less"
VENDOR_EXTEND_F2="${SMOKE_ROOT}/vendor/flatrate/flarum-private-messages-bridge/extend-f2.php"
if [[ ! -f "${VENDOR_DM_LESS}" ]]; then
  echo "DM_F2_NATIVE_STYLESHEET=FAIL missing_forum-f2.less" >&2
  exit 1
fi
if ! grep -q "resources/less/forum-f2.less" "${VENDOR_EXTEND_F2}"; then
  echo "DM_F2_NATIVE_STYLESHEET=FAIL extend-f2_not_wired_to_forum-f2.less" >&2
  exit 1
fi
if grep -q "resources/less/extension.less" "${VENDOR_EXTEND_F2}"; then
  echo "DM_F2_NATIVE_STYLESHEET=FAIL extend-f2_still_loads_extension.less" >&2
  exit 1
fi
if grep -q 'F2_BROWSER_THEME_VARS' "${VENDOR_DM_LESS}" \
  || grep -qE '^@(control-bg|body-bg|muted-color|screen-tablet)\s*:' "${VENDOR_DM_LESS}"; then
  echo "F2_COMPAT_DM_LESS_STUB=USED_OR_PATCHED" >&2
  exit 1
fi
echo "F2_COMPAT_DM_LESS_STUB=NOT_USED"
echo "DM_F2_NATIVE_STYLESHEET=PASS"

HOME_BODY="$(mktemp)"
HOME_CODE="$(curl -s -o "${HOME_BODY}" -w '%{http_code}' "${BASE}/")"
if [[ "${HOME_CODE}" != "200" ]]; then
  echo "DM_F2_ASSET_COMPILATION=FAIL home_status=${HOME_CODE}" >&2
  head -c 2000 "${HOME_BODY}" >&2 || true
  tail -n 80 "${SMOKE_ROOT}/http.log" >&2 || true
  exit 1
fi
if grep -qiE 'Less_Exception|undefined variable|variable.*is undefined|ParseError' "${HOME_BODY}" \
  || grep -qiE 'Less_Exception|undefined variable' "${SMOKE_ROOT}/storage/logs/"*.log 2>/dev/null; then
  echo "DM_F2_ASSET_COMPILATION=FAIL less_error" >&2
  exit 1
fi
rm -f "${HOME_BODY}"
echo "DM_F2_ASSET_COMPILATION=PASS"
echo "GET_HOME=200"

php flarum extension:disable flatrate-private-messages-bridge
DISABLED_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}/api/neoncube-private-messages/conversations")"
if [[ "${DISABLED_CODE}" == "200" ]]; then
  echo "DM_DISABLE_FALLBACK=FAIL route_still_open" >&2
  exit 1
fi
AFTER_MESSAGES="$(php -r '$pdo=new PDO("sqlite:storage/f2-dm.sqlite"); echo $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();')"
AFTER_CONVERSATIONS="$(php -r '$pdo=new PDO("sqlite:storage/f2-dm.sqlite"); echo $pdo->query("SELECT COUNT(*) FROM conversations")->fetchColumn();')"
if [[ "${AFTER_MESSAGES}" -lt "${BEFORE_MESSAGES}" || "${AFTER_CONVERSATIONS}" -lt "${BEFORE_CONVERSATIONS}" ]]; then
  echo "DM_DISABLE_FALLBACK=FAIL messages_before=${BEFORE_MESSAGES} after=${AFTER_MESSAGES}" >&2
  exit 1
fi
echo "DM_DISABLE_FALLBACK=PASS status=${DISABLED_CODE}"
echo "DM_F2_HTTP=PASS"
echo "DM_F2_FRONTEND_ASSETS=PASS"
echo "PRODUCTION_INSTALL=false"
