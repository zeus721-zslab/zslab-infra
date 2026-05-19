#!/bin/bash
# zslab-search 패키지를 각 프로젝트 packages/ 복사본에 동기화
set -e
INFRA_PKG="/home/zslab-infra/zslab-search"
LMS_PKG="/home/zslab-lms/backend/packages/zslab-search"
SHOP_PKG="/home/zslab/backend/packages/zslab-search"

echo "=== zslab-search 동기화 시작 ==="

if sudo test -d "$LMS_PKG"; then
    sudo rsync -av --delete "$INFRA_PKG/src/" "$LMS_PKG/src/"
    sudo rsync -av "$INFRA_PKG/composer.json" "$LMS_PKG/composer.json"
    echo "LMS 동기화 완료"
else
    echo "LMS packages 경로 없음: $LMS_PKG"
fi

if sudo test -d "$SHOP_PKG"; then
    sudo rsync -av --delete "$INFRA_PKG/src/" "$SHOP_PKG/src/"
    sudo rsync -av "$INFRA_PKG/composer.json" "$SHOP_PKG/composer.json"
    echo "shop 동기화 완료"
else
    echo "shop packages 경로 없음: $SHOP_PKG"
fi

echo "=== 동기화 완료 ==="
