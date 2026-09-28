#!/usr/bin/env bash
# 若书小说平台 - 云资源 provision 脚本（任务 1.7）
#
# 依赖：腾讯云 CLI（tccli），需先 `tccli configure` 配置密钥
# 使用：bash infra/provision.sh <env>
#   env: dev | prod
#
# 警告：本脚本为 MVP 起步模板，需根据实际账号/区域修改 ID 与名称。

set -euo pipefail

ENV_NAME="${1:-dev}"
REGION="ap-shanghai"
PROJECT="ruoshu"

echo "==> Provisioning ruoshu-${ENV_NAME} in ${REGION}"

# ===== 1. CVM 应用节点 =====
echo "==> Creating CVM..."
CVM_ID=$(tccli cvm RunInstances \
    --Region "${REGION}" \
    --InstanceType SA5.SMALL2 \
    --ImageId img-ira1w1mp12 \
    --InstanceName "${PROJECT}-${ENV_NAME}-web" \
    --InstanceChargeType PREPAID \
    --InstanceChargePrepaid '{"Period":1,"RenewFlag":"NOTIFY_AND_MANUAL_RENEW"}' \
    --Placement '{"Zone":"ap-shanghai-1"}' \
    --SystemDisk '{"DiskType":"CLOUD_SSD","DiskSize":50}' \
    --InternetAccessible '{"InternetChargeType":"TRAFFIC_POSTPAID_BY_HOUR","InternetMaxBandwidthOut":5,"PublicIpAssigned":true}' \
    --SecurityGroupIds '["sg-xxxxxxxx"]', \
    --VirtualPrivateCloud '{"VpcId":"vpc-xxxxxxxx","SubnetId":"subnet-xxxxxxxx"}' \
    --query 'InstanceIdSet[0]' \
    --output json)
echo "    CVM ID: ${CVM_ID}"

# ===== 2. 云数据库 MySQL =====
echo "==> Creating MySQL instance..."
MYSQL_ID=$(tcapi cdb CreateDBInstance \
    --Region "${REGION}" \
    --EngineVersion "8.0" \
    --InstanceType "cdb.SMALL2" \
    --InstanceName "${PROJECT}-${ENV_NAME}-mysql" \
    --Volume '{"VolumeType":"CLOUD_SSD","Size":100}' \
    --VpcId "vpc-xxxxxxxx" \
    --SubnetId "subnet-xxxxxxxx" \
    --query 'InstanceId' \
    --output json)
echo "    MySQL ID: ${MYSQL_ID}"

# ===== 3. Redis =====
echo "==> Creating Redis..."
REDIS_ID=$(tcapi redis CreateInstances \
    --Region "${REGION}" \
    --TypeId "redis.SMALL2" \
    --InstanceName "${PROJECT}-${ENV_NAME}-redis" \
    --query 'InstanceId' \
    --output json)
echo "    Redis ID: ${REDIS_ID}"

# ===== 4. OSS Bucket =====
echo "==> Creating OSS bucket..."
OSS_BUCKET="${PROJECT}-${ENV_NAME}-$(date +%s)"
tcapi cos CreateBucket \
    --Bucket "${OSS_BUCKET}" \
    --Region "${REGION}" \
    --ACL "private" || true  # 失败时继续（Bucket 名唯一）

# ===== 6. 域名解析 + CDN =====
echo "==> Configuring CDN..."
# 此处省略：实际需要先注册域名、申请备案、配置 CDN 加速
# 详见 docs/research/01-技术选型全面对比.md 与 infra/README.md

# ===== 7. 至信链 / TSA 账号 =====
echo "==> External services (MANUAL STEPS):"
echo "    1. 至信链:  登录腾讯云控制台 → 至信链 → 版权存证 API 申请"
echo "    2. TSA:     联合信任时间戳 https://www.tsa.cn 注册开发者账号"
echo "    3. 微信支付: https://pay.weixin.qq.com 申请商户号 + 公众号 / 小程序 AppID"
echo "    4. 支付宝:   https://b.alipay.com 申请商户号 + 应用"

echo "==> Done. 请将各资源 ID 填入 apps/web/.env。"