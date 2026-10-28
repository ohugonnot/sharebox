#!/usr/bin/env bash
# Smoke test for the ShareBox Docker image. Usage: SHAREBOX_IMAGE=sharebox:smoke bash tests/docker/smoke.sh
set -euo pipefail

IMAGE="${SHAREBOX_IMAGE:-sharebox:smoke}"
SUFFIX="$$-$(date +%s)"
CONTAINERS=()
VOLUMES=()
TMPDIRS=()

fail() { echo "FAIL: $*" >&2; exit 1; }
ok()   { echo "ok: $*"; }

cleanup() {
    local c v
    for c in "${CONTAINERS[@]:-}"; do [ -n "$c" ] && docker rm -f "$c" >/dev/null 2>&1 || true; done
    for v in "${VOLUMES[@]:-}"; do [ -n "$v" ] && docker volume rm -f "$v" >/dev/null 2>&1 || true; done
    for v in "${TMPDIRS[@]:-}"; do [ -n "$v" ] && rm -rf "$v" || true; done
}
trap cleanup EXIT

# Echo a free TCP port on 127.0.0.1.
free_port() {
    python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])'
}

# start NAME [docker run args...] -> sets PORT; fresh data volume each time,
# unless DATA_VOL names an existing one to reuse.
start() {
    local name="sbsmoke-$1-$SUFFIX"; shift
    local vol="${DATA_VOL:-$name-data}"
    PORT="$(free_port)"
    CNAME="$name"
    CONTAINERS+=("$name"); VOLUMES+=("$vol")
    docker run -d --name "$name" -p "127.0.0.1:$PORT:80" -v "$vol:/data" "$@" "$IMAGE" >/dev/null \
        || fail "cannot start container $name from image $IMAGE"
}

http_code() { curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@" || true; }

wait_healthy() {
    local name="$1" limit="$2" i status
    for ((i = 0; i < limit; i++)); do
        status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}nohealthcheck{{end}}' "$name" 2>/dev/null || echo gone)"
        [ "$status" = healthy ] && return 0
        [ "$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null || echo false)" = true ] || return 1
        sleep 1
    done
    return 1
}

# --- 1-4: default start, no password given ---
start default
if ! wait_healthy "$CNAME" 90; then
    docker logs "$CNAME" 2>&1 | tail -30 >&2
    fail "container not healthy within 90 s (check HEALTHCHECK and entrypoint)"
fi
ok "container healthy"

code="$(http_code "http://127.0.0.1:$PORT/dl/browse")"
[ "$code" = 404 ] || fail "/dl/browse returned $code without demo data, expected 404"
ok "/dl/browse = 404 (demo off by default)"

headers="$(curl -s -o /dev/null -D - --max-time 10 "http://127.0.0.1:$PORT/" || true)"
echo "$headers" | head -1 | grep -q ' 302' || fail "GET / is not a 302: $(echo "$headers" | head -1)"
echo "$headers" | grep -qi '^location:.*/share/' || fail "GET / does not redirect to /share/"
ok "GET / = 302 to /share/"

DEFAULT_CNAME="$CNAME"; DEFAULT_PORT="$PORT"
pass="$(docker logs "$CNAME" 2>&1 | sed -nE 's/^ *Password +: ([0-9a-f]{16}) *$/\1/p' | head -1 || true)"
[ -n "$pass" ] || fail "no 'Password : <16 hex>' banner line found in docker logs"
ok "generated password found in the banner"

saved="$(docker exec "$CNAME" cat /data/initial-admin-password 2>/dev/null || true)"
[ "$saved" = "$pass" ] || fail "/data/initial-admin-password does not contain the generated password"
perm="$(docker exec "$CNAME" stat -c '%a %U' /data/initial-admin-password 2>/dev/null || true)"
[ "$perm" = "600 root" ] || fail "/data/initial-admin-password is '$perm', expected '600 root'"
ok "password also saved in /data/initial-admin-password (0600 root)"

# Successful login answers 302 to /share/; a failed one re-renders the form (200).
login_headers="$(curl -s -o /dev/null -D - --max-time 10 \
    --data-urlencode 'username=admin' --data-urlencode "password=$pass" \
    "http://127.0.0.1:$PORT/share/login.php" || true)"
echo "$login_headers" | head -1 | grep -q ' 302' || fail "login with generated password failed: $(echo "$login_headers" | head -1)"
echo "$login_headers" | grep -qi '^location:.*/share/' || fail "login did not redirect to /share/"
bad="$(http_code --data-urlencode 'username=admin' --data-urlencode 'password=wrong-password' "http://127.0.0.1:$PORT/share/login.php")"
[ "$bad" = 200 ] || fail "login with a wrong password returned $bad, expected 200 (form shown again)"
ok "login works with the generated password only"

# The password must survive a restart and the banner must not be shown again.
docker restart "$DEFAULT_CNAME" >/dev/null
wait_healthy "$DEFAULT_CNAME" 90 || fail "container not healthy after docker restart"
[ "$(docker logs "$DEFAULT_CNAME" 2>&1 | grep -c 'ShareBox - first start')" = 1 ] \
    || fail "first-start banner shown again after restart"
login_headers="$(curl -s -o /dev/null -D - --max-time 10 \
    --data-urlencode 'username=admin' --data-urlencode "password=$pass" \
    "http://127.0.0.1:$DEFAULT_PORT/share/login.php" || true)"
echo "$login_headers" | head -1 | grep -q ' 302' || fail "same password rejected after restart: $(echo "$login_headers" | head -1)"
ok "password stable across restart, banner shown once"

# --- 5: weak password must stop the container ---
start weak -e SHAREBOX_ADMIN_PASS=changeme
rc="$(timeout 60 docker wait "$CNAME" || echo timeout)"
case "$rc" in
    timeout) fail "container with SHAREBOX_ADMIN_PASS=changeme is still running after 60 s, expected exit" ;;
    0)       fail "container with a weak password exited with code 0, expected non-zero" ;;
esac
docker logs "$CNAME" 2>&1 | grep -q 'too weak' || fail "weak-password container did not log 'too weak'"
ok "weak password refused (exit code $rc, 'too weak' logged)"

# --- 6: demo data on empty media ---
start demo -e SHAREBOX_DEMO_DATA=true -e SHAREBOX_ADMIN_PASS=smoke-test-pass-1
code=000
for _ in $(seq 1 120); do
    code="$(http_code "http://127.0.0.1:$PORT/dl/browse")"
    [ "$code" = 200 ] && break
    sleep 1
done
if [ "$code" != 200 ]; then
    docker logs "$CNAME" 2>&1 | tail -30 >&2
    fail "/dl/browse returned $code with SHAREBOX_DEMO_DATA=true, expected 200 within 120 s"
fi
ok "demo data on empty media: /dl/browse = 200"

# --- 7: demo off on the same data volume must remove the stale browse link ---
DEMO_VOL="$CNAME-data"
docker rm -f "$CNAME" >/dev/null
DATA_VOL="$DEMO_VOL" start nodemo -e SHAREBOX_DEMO_DATA=false -e SHAREBOX_ADMIN_PASS=smoke-test-pass-1
wait_healthy "$CNAME" 90 || fail "container not healthy after switching demo off"
code="$(http_code "http://127.0.0.1:$PORT/dl/browse")"
[ "$code" = 404 ] || fail "/dl/browse returned $code after SHAREBOX_DEMO_DATA=false on a demo volume, expected 404"
ok "demo off removes the stale /dl/browse link"

# --- 8: demo on non-empty media without marker must share nothing ---
REAL_MEDIA="$(mktemp -d)"; TMPDIRS+=("$REAL_MEDIA")
echo "private" > "$REAL_MEDIA/private.txt"
chmod -R a+rX "$REAL_MEDIA"
start realmedia -e SHAREBOX_DEMO_DATA=true -e SHAREBOX_ADMIN_PASS=smoke-test-pass-1 -v "$REAL_MEDIA:/media:ro"
found=0
for _ in $(seq 1 60); do
    if docker logs "$CNAME" 2>&1 | grep -q 'no demo marker'; then found=1; break; fi
    sleep 1
done
[ "$found" = 1 ] || { docker logs "$CNAME" 2>&1 | tail -30 >&2; fail "no 'no demo marker' message with real media and DEMO_DATA=true"; }
wait_healthy "$CNAME" 90 || fail "container not healthy with real media"
code="$(http_code "http://127.0.0.1:$PORT/dl/browse")"
[ "$code" = 404 ] || fail "/dl/browse returned $code with real media and DEMO_DATA=true, expected 404"
ok "demo on real media: nothing shared, /dl/browse = 404"

echo "All smoke checks passed."
