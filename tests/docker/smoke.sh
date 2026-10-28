#!/usr/bin/env bash
# Smoke test for the ShareBox Docker image. Usage: SHAREBOX_IMAGE=sharebox:smoke bash tests/docker/smoke.sh
set -euo pipefail

IMAGE="${SHAREBOX_IMAGE:-sharebox:smoke}"
SUFFIX="$$-$(date +%s)"
CONTAINERS=()
VOLUMES=()

fail() { echo "FAIL: $*" >&2; exit 1; }
ok()   { echo "ok: $*"; }

cleanup() {
    local c v
    for c in "${CONTAINERS[@]:-}"; do [ -n "$c" ] && docker rm -f "$c" >/dev/null 2>&1 || true; done
    for v in "${VOLUMES[@]:-}"; do [ -n "$v" ] && docker volume rm -f "$v" >/dev/null 2>&1 || true; done
}
trap cleanup EXIT

# Echo a free TCP port on 127.0.0.1.
free_port() {
    python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])'
}

# start NAME [docker run args...] -> sets PORT; fresh data volume each time.
start() {
    local name="sbsmoke-$1-$SUFFIX"; shift
    local vol="$name-data"
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

pass="$(docker logs "$CNAME" 2>&1 | grep -i password | grep -oE '[0-9a-f]{16}' | head -1 || true)"
[ -n "$pass" ] || fail "no 16-hex generated password found in docker logs"
ok "generated password found in logs"

# Successful login answers 302 to /share/; a failed one re-renders the form (200).
login_headers="$(curl -s -o /dev/null -D - --max-time 10 \
    --data-urlencode 'username=admin' --data-urlencode "password=$pass" \
    "http://127.0.0.1:$PORT/share/login.php" || true)"
echo "$login_headers" | head -1 | grep -q ' 302' || fail "login with generated password failed: $(echo "$login_headers" | head -1)"
echo "$login_headers" | grep -qi '^location:.*/share/' || fail "login did not redirect to /share/"
bad="$(http_code --data-urlencode 'username=admin' --data-urlencode 'password=wrong-password' "http://127.0.0.1:$PORT/share/login.php")"
[ "$bad" = 200 ] || fail "login with a wrong password returned $bad, expected 200 (form shown again)"
ok "login works with the generated password only"

# --- 5: weak password must stop the container ---
start weak -e SHAREBOX_ADMIN_PASS=changeme
rc="$(timeout 60 docker wait "$CNAME" || echo timeout)"
case "$rc" in
    timeout) fail "container with SHAREBOX_ADMIN_PASS=changeme is still running after 60 s, expected exit" ;;
    0)       fail "container with a weak password exited with code 0, expected non-zero" ;;
esac
ok "weak password refused (exit code $rc)"

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

echo "All smoke checks passed."
