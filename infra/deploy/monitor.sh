#!/usr/bin/env bash
# 若书 · 线上监控告警（任务 13.5：接口错误率 / 队列积压 / 存证死信）
# 部署：crontab（双机各一份，告警去重由脚本 state 文件 + 时间窗承担）
#   * * * * * /srv/ruoshu/current/infra/deploy/monitor.sh >> /var/log/ruoshu-monitor.log 2>&1
#
# 指标（与 QueueHealthCheckJob 应用层自检互补，此为系统层兜底）：
#   1. healthz/readyz 探针（本机 + 对端）
#   2. 队列积压（redis llen queues:default > 1000）
#   3. 存证死信（evidence_records status=4 增长）
#   4. nginx 5 分钟窗口 5xx 比例 > 1%
set -uo pipefail

APP_DIR="/srv/ruoshu"
STATE="/tmp/ruoshu-monitor.state"
WEBHOOK="${OPS_WEBHOOK:-}"           # 企业微信/钉钉机器人 webhook（环境变量注入）
PEER="${OPS_PEER:-}"                # 对端内网 IP（如 10.0.0.12）
MINUTES_WINDOW=5

send_alert() {  # send_alert "标题" "详情"
    echo "[$(date '+%F %T')] ALERT: $1 | $2"
    if [ -n "$WEBHOOK" ]; then
        curl -fsS -m 5 "$WEBHOOK" -H 'Content-Type: application/json' \
            -d "{\"msgtype\":\"text\",\"text\":{\"content\":\"[若书告警] $1\n$2\"}}" >/dev/null 2>&1 || true
    fi
}

# 简易告警静默：同键 30 分钟内不重复
should_alert() {
    local key="$1" now last
    now=$(date +%s)
    last=$(grep "^${key}=" "$STATE" 2>/dev/null | cut -d= -f2)
    echo "${key}=${now}" > "$STATE.tmp"
    grep -v "^${key}=" "$STATE" 2>/dev/null >> "$STATE.tmp" || true
    mv "$STATE.tmp" "$STATE"
    [ -z "$last" ] || [ $((now - last)) -ge 1800 ]
}

cd "$APP_DIR/current" 2>/dev/null || exit 0

# 1. 本机 + 对端探针
for host in 127.0.0.1 ${PEER}; do
    [ -z "$host" ] && continue
    if ! curl -fsS -m 5 "http://${host}/healthz" >/dev/null 2>&1; then
        should_alert "down-${host}" && send_alert "服务不可用 ${host}" "healthz 探测失败，请 systemctl status / nginx -t 排查"
    fi
    if ! curl -fsS -m 5 "http://${host}/readyz" >/dev/null 2>&1; then
        should_alert "degraded-${host}" && send_alert "依赖降级 ${host}" "readyz 失败（DB 或 Redis 异常）"
    fi
done

# 2. 队列积压（系统层兜底；应用层 QueueHealthCheckJob 5 分钟自检）
PENDING=$(php artisan tinker --execute='echo Illuminate\Support\Facades\Redis::llen("queues:default");' 2>/dev/null || echo 0)
if [ "${PENDING:-0}" -gt 1000 ]; then
    should_alert "queue" && send_alert "队列积压" "default 队列待处理 ${PENDING} 条（阈值 1000），检查 ruoshu-queue 与消费耗时"
fi

# 3. 存证死信（evidence status=4；红线：死信须人工介入重放）
DEAD=$(php artisan tinker --execute='echo App\Models\EvidenceRecord::where("status", 4)->count();' 2>/dev/null || echo 0)
if [ "${DEAD:-0}" -gt 0 ]; then
    should_alert "dead" && send_alert "存证死信" "evidence_records 死信 ${DEAD} 条，须人工重放（保留原始数据，不可删除）"
fi

# 4. 5 分钟窗口 5xx 错误率（nginx access log）
LOG="${NGINX_LOG:-/var/log/nginx/access.log}"
if [ -f "$LOG" ]; then
    TOTAL=$(tail -n 20000 "$LOG" | awk -v w=$((MINUTES_WINDOW*60)) \
        -v cut="$(date -d "-${MINUTES_WINDOW} minutes" '+%d/%b/%Y:%H:%M' 2>/dev/null)" '$4 >= "["cut {n++} END{print n+0}')
    S5XX=$(tail -n 20000 "$LOG" | awk -v w=$((MINUTES_WINDOW*60)) \
        -v cut="$(date -d "-${MINUTES_WINDOW} minutes" '+%d/%b/%Y:%H:%M' 2>/dev/null)" \
        '$4 >= "["cut && $9 ~ /^5/ {n++} END{print n+0}')
    if [ "${TOTAL:-0}" -ge 100 ] && [ $((S5XX * 100)) -gt $((TOTAL)) ]; then
        should_alert "5xx" && send_alert "5xx 错误率过高" "窗口 ${MINUTES_WINDOW} 分钟：5xx ${S5XX}/${TOTAL}（>1%），查 laravel.log 与 php-fpm slow log"
    fi
fi
