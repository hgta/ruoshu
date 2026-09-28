# services/ai-python — 文本处理边车

**负责**：文本规范化、段落 SHA-256 指纹、Merkle root 计算、零宽水印方案规划/提取、版权溯源比对
**不负责**：任何 HTTP 业务、WebSocket、用户认证

## 启动（本地）

```bash
python -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 9000 --reload
```

API 文档：<http://127.0.0.1:9000/docs>

## 接口

| 端点 | 方法 | 说明 |
|------|------|------|
| `/healthz` | GET | 健康检查 |
| `/v1/normalize` | POST | 文本规范化（去空白、统一标点） |
| `/v1/fingerprint/chapter` | POST | 分段 + SHA-256 + Merkle root，返回每段哈希与 root |
| `/v1/watermark/plan` | POST | 输入章节与 user_id，输出嵌入锚点方案 |
| `/v1/watermark/embed` | POST | 输入章节与方案，输出嵌入水印的文本 |
| `/v1/watermark/extract` | POST | 输入疑似盗版文本 + 元数据，提取水印 payload |
| `/v1/trace/match` | POST | 输入盗版文本，返回疑似匹配章节与相似度 |

## 配置（环境变量）

| 变量 | 必填 | 说明 |
|------|------|------|
| `AI_BIND_HOST` | 否 | 绑定地址，默认 `127.0.0.1`（**内网绑定，不暴露公网**） |
| `AI_PORT` | 否 | 默认 9000 |
| `LOG_LEVEL` | 否 | 默认 `info` |

## 安全

- 仅内网绑定，拒绝公网连接
- 上游（仅 `apps/web`）通过 HTTP 调用，无 token（依赖网络隔离）
- 任何水印提取接口均返回置信度，低于阈值不返回账号 ID（避免误伤）

## 文档

- 盲水印能力规格：`openspec/changes/add-ruoshu-mvp/specs/blind-watermark/spec.md`
- 存证能力规格：`openspec/changes/add-ruoshu-mvp/specs/copyright-evidence/spec.md`