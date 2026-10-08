#!/usr/bin/env bash
# gateway conf·compose를 저장소에서 GATEWAY_DIR(데이터 폴더)로 배포한다.
# nginx.conf는 단일 파일 bind mount라 같은 inode에 덮어써야 한다(cp·git은 inode가 바뀌어 컨테이너가 옛 파일을 계속 봄).
#
# 사용법: sudo GATEWAY_DIR=<경로> bash scripts/deploy-gateway.sh
set -euo pipefail

if [[ -z "${GATEWAY_DIR:-}" ]]; then
    echo "에러: GATEWAY_DIR 환경변수를 지정하세요. 예) sudo GATEWAY_DIR=/home/gateway bash scripts/deploy-gateway.sh" >&2
    exit 1
fi
if [[ ! -d "$GATEWAY_DIR" ]]; then
    echo "에러: GATEWAY_DIR이 디렉터리가 아닙니다: $GATEWAY_DIR" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

SRC_NGINX_CONF="$REPO_ROOT/gateway/nginx/nginx.conf"
SRC_COMPOSE="$REPO_ROOT/gateway/docker-compose.yml"
DST_NGINX_CONF="$GATEWAY_DIR/nginx/nginx.conf"
DST_COMPOSE="$GATEWAY_DIR/docker-compose.yml"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

CONF_CHANGED="no"
INODE_KEPT="n/a"
RELOAD_DONE="no"
COMPOSE_CHANGED="no"

# --- nginx.conf ---
if cmp -s "$SRC_NGINX_CONF" "$DST_NGINX_CONF"; then
    echo "nginx.conf: 변경 없음"
else
    CONF_CHANGED="yes"
    BACKUP="$DST_NGINX_CONF.bak-$TIMESTAMP"
    cp -p "$DST_NGINX_CONF" "$BACKUP"
    echo "nginx.conf: 백업 생성 $BACKUP"

    INODE_BEFORE="$(stat -c %i "$DST_NGINX_CONF")"
    cat "$SRC_NGINX_CONF" > "$DST_NGINX_CONF"
    INODE_AFTER="$(stat -c %i "$DST_NGINX_CONF")"

    if [[ "$INODE_BEFORE" != "$INODE_AFTER" ]]; then
        echo "에러: nginx.conf inode가 바뀌었습니다 (전: $INODE_BEFORE, 후: $INODE_AFTER). bind mount가 끊길 수 있습니다." >&2
        exit 1
    fi
    INODE_KEPT="yes"

    if docker exec gateway_nginx nginx -t; then
        docker exec gateway_nginx nginx -s reload
        RELOAD_DONE="yes"
        echo "nginx.conf: 적용 및 reload 완료"
    else
        echo "에러: nginx -t 실패 — 백업으로 원복합니다." >&2
        cat "$BACKUP" > "$DST_NGINX_CONF"
        if ! docker exec gateway_nginx nginx -t; then
            echo "에러: 원복 후에도 nginx -t 실패. 수동 확인 필요." >&2
            exit 1
        fi
        echo "에러: 원복 완료, 배포 중단." >&2
        exit 1
    fi
fi

# --- docker-compose.yml ---
if cmp -s "$SRC_COMPOSE" "$DST_COMPOSE"; then
    echo "docker-compose.yml: 변경 없음"
else
    COMPOSE_CHANGED="yes"
    cp -p "$DST_COMPOSE" "$DST_COMPOSE.bak-$TIMESTAMP"
    cp "$SRC_COMPOSE" "$DST_COMPOSE"
    echo "docker-compose.yml: 변경됨 — GATEWAY_DIR에서 docker compose up -d 필요"
fi

echo "---"
echo "요약: conf 변경=$CONF_CHANGED, inode 유지=$INODE_KEPT, reload=$RELOAD_DONE, compose 변경=$COMPOSE_CHANGED"
