#!/usr/bin/env bash
# 若书 · 回滚（软链切回，秒级；注意：已跑的迁移不自动回退，schema 向前兼容由部署约定保证）
set -euo pipefail
TARGET="${1:?用法: rollback.sh <user@host> [版本(缺省=上一版)]}"
RELEASE="${2:-}"
APP_DIR="/srv/ruoshu"
SSH="ssh -o BatchMode=yes ${TARGET}"

if [ -z "$RELEASE" ]; then
    RELEASE=$($SSH "ls -1t ${APP_DIR}/releases | sed -n '2p'")
    [ -n "$RELEASE" ] || { echo "!! 没有可回滚的历史版本"; exit 1; }
fi

echo "==> 回滚 ${TARGET} -> ${RELEASE}"
$SSH "ln -sfn ${APP_DIR}/releases/${RELEASE} ${APP_DIR}/current_test \
    && mv -T ${APP_DIR}/current_test ${APP_DIR}/current \
    && systemctl restart ruoshu-queue ruoshu-realtime 2>/dev/null || true \
    && sleep 2 && curl -fsS -m 5 http://127.0.0.1/healthz >/dev/null && echo OK"
