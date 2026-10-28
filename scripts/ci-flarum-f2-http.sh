#!/usr/bin/env bash
# Disposable Flarum 2.0.2 HTTP proof for the private-message bridge.
# No production data, credentials, or installation.
set -euo pipefail

export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

ROOT="$(cd "${DM_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}" && pwd)"
if [[ ! -f "${ROOT}/composer.json" ]]; then
  echo "DM_F2_HTTP=FAIL missing_composer_json root=${ROOT}" >&2
  exit 1
fi
git config --global --add safe.directory "${ROOT}" || true
# Detached/CI checkouts need a branch name for composer path metadata; skip when git is unavailable.
if [[ "${SKIP_GIT_CHECKOUT:-0}" != "1" ]] && command -v git >/dev/null 2>&1; then
  if ! git -C "${ROOT}" symbolic-ref -q HEAD >/dev/null 2>&1; then
    git -C "${ROOT}" checkout -B ci-f2-private-messages-http || true
  fi
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
$pdo->exec("INSERT INTO settings (key, value) VALUES (\"mail_driver\", \"log\") ON CONFLICT(key) DO UPDATE SET value=excluded.value");
'

# Confirm disposable install uses the sync queue (production-equivalent for this failure).
QUEUE_DRIVER="$(php -r '$c=include "config.php"; echo $c["queue"]["driver"] ?? "";')"
if [[ "${QUEUE_DRIVER}" != "sync" ]]; then
  echo "F2_QUEUE_SYNC=FAIL driver=${QUEUE_DRIVER}" >&2
  exit 1
fi
echo "F2_QUEUE_SYNC=PASS"
echo "MAIL_TRANSPORT=log"
echo "EXTERNAL_MAIL_SEND=false"

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

# Force recipient email preference ON so SendEmailNotificationJob runs under
# queue=sync. Required to reproduce EXEC-003 against old SHA (alert-only default)
# and to qualify the fixed SHA with the real mail-render path.
php -r '
$site = require "site.php";
$app = $site->bootApp();
$user = Flarum\User\User::find((int) $argv[1]);
if (!$user) { fwrite(STDERR, "missing bob\n"); exit(1); }
$key = Flarum\User\User::getNotificationPreferenceKey("newPrivateMessage", "email");
$user->setPreference($key, true);
$user->save();
if (!$user->shouldEmail("newPrivateMessage")) {
  fwrite(STDERR, "EMAIL_NOTIFICATION_ENABLED=FAIL\n");
  exit(1);
}
echo "EMAIL_NOTIFICATION_ENABLED=true\n";
' "${BOB_ID}"

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

# Synchronous email path: bob is a new member with email driver default-on for
# newPrivateMessage. Create must have rendered both HTML and text views via
# SendEmailNotificationJob (queue=sync) without the missing-route fatal.
MAIL_LOG="$(ls -1t "${SMOKE_ROOT}/storage/logs/"*.log 2>/dev/null | head -1 || true)"
if [[ -z "${MAIL_LOG}" ]]; then
  echo "F2_SYNC_EMAIL=FAIL missing_flarum_log" >&2
  exit 1
fi
if grep -Fq "Route neoncube-private-messages.messages not found" "${MAIL_LOG}"; then
  echo "F2_SYNC_EMAIL=FAIL missing_named_route_in_log" >&2
  exit 1
fi
if ! grep -Eq "messages/direct/${NEW_ID}|/messages/direct/${NEW_ID}" "${MAIL_LOG}"; then
  echo "F2_EMAIL_LINK=FAIL expected_messages_direct_${NEW_ID}" >&2
  tail -n 80 "${MAIL_LOG}" >&2 || true
  exit 1
fi
if ! grep -Fq "newPrivateMessageHtml" "${MAIL_LOG}" && ! grep -Fq "View Direct Message" "${MAIL_LOG}" && ! grep -Fiq "<a href=" "${MAIL_LOG}"; then
  # Log transport dumps the rendered MIME; require HTML body markers.
  if ! grep -Fiq "Content-Type: text/html" "${MAIL_LOG}"; then
    echo "F2_HTML_EMAIL=FAIL no_html_body_in_log" >&2
    tail -n 120 "${MAIL_LOG}" >&2 || true
    exit 1
  fi
fi
if ! grep -Fiq "Content-Type: text/plain" "${MAIL_LOG}"; then
  echo "F2_TEXT_EMAIL=FAIL no_text_body_in_log" >&2
  tail -n 120 "${MAIL_LOG}" >&2 || true
  exit 1
fi
echo "F2_SYNC_EMAIL=PASS"
echo "F2_HTML_EMAIL=PASS"
echo "F2_TEXT_EMAIL=PASS"
echo "F2_EMAIL_LINK=PASS"
echo "F2_EMAIL_ENABLED_CASE=PASS"
echo "EMAIL_URL_PATH=messages/direct/${NEW_ID}"

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

# Reply notification should also render without the missing-route fatal.
if grep -Fq "Route neoncube-private-messages.messages not found" "${MAIL_LOG}"; then
  echo "F2_REPLY_NOTIFICATION=FAIL missing_named_route_after_reply" >&2
  exit 1
fi
REPLY_MAIL_HITS="$(grep -c "messages/direct/${NEW_ID}" "${MAIL_LOG}" || true)"
if [[ "${REPLY_MAIL_HITS}" -lt 1 ]]; then
  echo "F2_REPLY_NOTIFICATION=FAIL missing_email_link_after_reply" >&2
  exit 1
fi
echo "F2_REPLY_NOTIFICATION=PASS"

# Email-disabled recipient: conversation must still succeed without requiring a new mail.
EVE_ID="$(create_user eve eve@example.test eve-pass-1)"
php -r '
$site = require "site.php";
$app = $site->bootApp();
$user = Flarum\User\User::find((int) $argv[1]);
if (!$user) { fwrite(STDERR, "missing eve\n"); exit(1); }
$user->setPreference(Flarum\User\User::getNotificationPreferenceKey("newPrivateMessage", "email"), false);
$user->save();
' "${EVE_ID}"
BEFORE_MAIL_BYTES="$(wc -c < "${MAIL_LOG}")"
EVE_CREATED="$(mktemp)"
EVE_CODE="$(curl -sS -o "${EVE_CREATED}" -w '%{http_code}' -H 'Content-Type: application/json' -H "Authorization: Token ${ALICE_TOKEN}" \
  -d "{\"data\":{\"attributes\":{\"recipient\":\"${EVE_ID}\",\"messageContents\":\"hello eve no-email\"}}}" \
  "${BASE}/api/neoncube-private-messages/conversations")"
if [[ "${EVE_CODE}" != "201" ]]; then
  echo "F2_EMAIL_DISABLED_CASE=FAIL status=${EVE_CODE}" >&2
  cat "${EVE_CREATED}" >&2 || true
  exit 1
fi
EVE_CONV="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["data"]["id"] ?? "";' "${EVE_CREATED}")"
rm -f "${EVE_CREATED}"
php -r '
$pdo = new PDO("sqlite:storage/f2-dm.sqlite");
$n = (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE conversation_id = ".(int)$argv[1])->fetchColumn();
if ($n < 1) { fwrite(STDERR, "F2_EMAIL_DISABLED_CASE=FAIL no_message\n"); exit(1); }
' "${EVE_CONV}"
echo "F2_EMAIL_DISABLED_CASE=PASS"

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

# --- Notification subject resource regressions (EXEC-005 / notifications-500) ---
# Empty-collection include=subject must not 500: schema resolves Message::class
# via MessageResource. A real DM notification must serialize a valid subject.
GUEST_NOTIF_CODE="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/notifications")"
if [[ "${GUEST_NOTIF_CODE}" != "401" && "${GUEST_NOTIF_CODE}" != "403" ]]; then
  echo "DM_NOTIFICATIONS_GUEST=FAIL status=${GUEST_NOTIF_CODE}" >&2
  exit 1
fi
echo "DM_NOTIFICATIONS_GUEST=DENY status=${GUEST_NOTIF_CODE}"

FRANK_ID="$(create_user frank frank@example.test frank-pass-1)"
FRANK_TOKEN="$(token_for frank frank-pass-1)"
# Index defaultInclude already contains subject+fromUser; bare GET must still
# succeed once Message::class maps to a registered ApiResource type.
FRANK_ZERO="$(mktemp)"
FRANK_ZERO_CODE="$(curl -sS -o "${FRANK_ZERO}" -w '%{http_code}' -H "Authorization: Token ${FRANK_TOKEN}" \
  "${BASE}/api/notifications")"
expect_status DM_NOTIFICATIONS_EMPTY 200 "${FRANK_ZERO_CODE}"
php -r '
$body = json_decode(file_get_contents($argv[1]), true);
$rows = $body["data"] ?? null;
if (!is_array($rows) || count($rows) !== 0) {
  fwrite(STDERR, "DM_NOTIFICATIONS_EMPTY=FAIL unexpected_rows\n");
  exit(1);
}
' "${FRANK_ZERO}"
rm -f "${FRANK_ZERO}"

FRANK_SUBJECT="$(mktemp)"
FRANK_SUBJECT_CODE="$(curl -sS -o "${FRANK_SUBJECT}" -w '%{http_code}' -H "Authorization: Token ${FRANK_TOKEN}" \
  "${BASE}/api/notifications?include=subject")"
expect_status DM_NOTIFICATIONS_INCLUDE_SUBJECT_EMPTY 200 "${FRANK_SUBJECT_CODE}"
rm -f "${FRANK_SUBJECT}"

BOB_FROM="$(mktemp)"
BOB_FROM_CODE="$(curl -sS -o "${BOB_FROM}" -w '%{http_code}' -H "Authorization: Token ${BOB_TOKEN}" \
  "${BASE}/api/notifications?include=fromUser")"
expect_status DM_NOTIFICATIONS_INCLUDE_FROM_USER 200 "${BOB_FROM_CODE}"
rm -f "${BOB_FROM}"

BOB_SUBJECT="$(mktemp)"
BOB_SUBJECT_CODE="$(curl -sS -o "${BOB_SUBJECT}" -w '%{http_code}' -H "Authorization: Token ${BOB_TOKEN}" \
  "${BASE}/api/notifications?include=subject")"
if [[ "${BOB_SUBJECT_CODE}" != "200" ]]; then
  echo "DM_NOTIFICATIONS_INCLUDE_SUBJECT_DM=FAIL status=${BOB_SUBJECT_CODE}" >&2
  cat "${BOB_SUBJECT}" >&2 || true
  tail -n 120 "${SMOKE_ROOT}/http.log" >&2 || true
  exit 1
fi
php -r '
$body = json_decode(file_get_contents($argv[1]), true);
$rows = $body["data"] ?? [];
$dm = null;
foreach ($rows as $row) {
  if (($row["attributes"]["contentType"] ?? "") === "newPrivateMessage") {
    $dm = $row;
    break;
  }
}
if ($dm === null) {
  fwrite(STDERR, "DM_NOTIFICATIONS_INCLUDE_SUBJECT_DM=FAIL no_newPrivateMessage_row\n");
  exit(1);
}
$subjectRel = $dm["relationships"]["subject"]["data"] ?? null;
if (!is_array($subjectRel) || ($subjectRel["type"] ?? "") !== "neoncube-private-messages-messages") {
  fwrite(STDERR, "DM_NOTIFICATIONS_INCLUDE_SUBJECT_DM=FAIL bad_subject_rel=".json_encode($subjectRel)."\n");
  exit(1);
}
$subjectId = (string) ($subjectRel["id"] ?? "");
$included = $body["included"] ?? [];
$found = false;
foreach ($included as $row) {
  if (($row["type"] ?? "") === "neoncube-private-messages-messages" && (string) ($row["id"] ?? "") === $subjectId) {
    $found = true;
    if (($row["attributes"]["message"] ?? null) === null) {
      fwrite(STDERR, "DM_NOTIFICATIONS_INCLUDE_SUBJECT_DM=FAIL missing_message_attr\n");
      exit(1);
    }
    break;
  }
}
if (!$found) {
  fwrite(STDERR, "DM_NOTIFICATIONS_INCLUDE_SUBJECT_DM=FAIL subject_not_included\n");
  exit(1);
}
' "${BOB_SUBJECT}"
echo "DM_NOTIFICATIONS_INCLUDE_SUBJECT_DM=PASS"
rm -f "${BOB_SUBJECT}"

# Resource registration must not expose a public private-message API.
RESOURCE_TYPE="neoncube-private-messages-messages"
for path in \
  "/api/${RESOURCE_TYPE}" \
  "/api/${RESOURCE_TYPE}/1" \
  "/api/${RESOURCE_TYPE}?page%5Blimit%5D=1"
do
  CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" "${BASE}${path}")"
  if [[ "${CODE}" == "200" || "${CODE}" == "201" || "${CODE}" == "204" ]]; then
    echo "DM_MESSAGE_RESOURCE_NO_PUBLIC_ROUTE=FAIL path=${path} status=${CODE}" >&2
    exit 1
  fi
  if [[ "${CODE}" != "404" && "${CODE}" != "405" && "${CODE}" != "400" ]]; then
    # 401/403 would also mean an endpoint exists; reject those for this type.
    if [[ "${CODE}" == "401" || "${CODE}" == "403" ]]; then
      echo "DM_MESSAGE_RESOURCE_NO_PUBLIC_ROUTE=FAIL path=${path} status=${CODE}_implies_route" >&2
      exit 1
    fi
  fi
  echo "DM_MESSAGE_RESOURCE_NO_PUBLIC_ROUTE=PASS path=${path} status=${CODE}"
done

# Non-participant must not gain a Show endpoint for the notification subject type.
# Alice is not a participant of the Bob/Carol historical conversation.
HIST_MSG_ID="$(php -r '
$pdo = new PDO("sqlite:storage/f2-dm.sqlite");
$id = $pdo->query("SELECT id FROM messages WHERE conversation_id = ".(int)$argv[1]." ORDER BY number ASC LIMIT 1")->fetchColumn();
echo $id !== false ? (string) $id : "";
' "${HISTORICAL_ID}")"
if [[ -z "${HIST_MSG_ID}" ]]; then
  echo "DM_NONPARTICIPANT_SUBJECT=FAIL missing_historical_message_id" >&2
  exit 1
fi
ALICE_DIRECT_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${ALICE_TOKEN}" \
  "${BASE}/api/${RESOURCE_TYPE}/${HIST_MSG_ID}")"
if [[ "${ALICE_DIRECT_CODE}" == "200" ]]; then
  echo "DM_NONPARTICIPANT_SUBJECT=FAIL direct_resource_show_ok status=${ALICE_DIRECT_CODE}" >&2
  exit 1
fi
echo "DM_NONPARTICIPANT_SUBJECT=DENY status=${ALICE_DIRECT_CODE}"

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
echo "F2_CONVERSATION_CREATE=PASS"
echo "F2_INITIAL_MESSAGE_PERSISTENCE=PASS"
echo "F2_REPLY=PASS"
echo "PRODUCTION_INSTALL=false"
