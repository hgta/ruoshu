## ADDED Requirements

### Requirement: Gift donations
Readers SHALL be able to donate to authors via a gift system (defined gift list with icons and prices) or free-amount tips, paid via WeChat Pay / Alipay H5. Platform commission on donations SHALL be 5%. Donation feedback (danmu broadcast) MUST appear within 3 seconds of payment success.

#### Scenario: Successful donation broadcast
- **WHEN** a reader pays for a ¥1 gift on a chapter
- **THEN** a danmu thanks message appears in that chapter within 3 seconds

### Requirement: Chapter purchase and monthly membership
The system SHALL support per-chapter purchase (author-configurable, default 0.2 CNY, platform commission 15%) and monthly membership (platform commission 10%) covering VIP chapters. Purchases MUST be idempotent per (user, chapter).

#### Scenario: Duplicate purchase attempt
- **WHEN** a user retries payment for an already-purchased chapter
- **THEN** no second charge occurs and access is granted

### Requirement: Ledger as single source of truth
Every money movement (donation/purchase/subscription) SHALL write a ledger entry in the same database transaction as its source record, with gross, platform fee rate, fee, author net (all in cents) and a chained entry_hash including the previous entry's hash. Author-facing dashboards and statements MUST read from ledger only.

#### Scenario: Ledger reconciliation
- **WHEN** an auditor sums ledger entries for a work
- **THEN** the totals match the sum of donation/purchase/subscription source records exactly

### Requirement: Transparent earning dashboard
Authors SHALL see per-transaction details (gross, commission, net, source, timestamp) in real time, and monthly statement PDFs stamped with TSA trusted timestamp.

#### Scenario: Author checks a donation split
- **WHEN** an author opens earnings dashboard after a ¥10 gift
- **THEN** a row shows gross 1000 cents, fee 50 cents (5%), net 950 cents with chain-verify button

### Requirement: Payout compliance
Payouts to authors MUST use official WeChat Pay / Alipay profit-sharing interfaces (no secondary clearing). The donation/purchase feature SHALL be feature-flagged and MUST NOT launch before legal review of author agreements and individual-merchant onboarding flow is complete.

#### Scenario: Feature flag off
- **WHEN** payment features are disabled by flag
- **THEN** donation and purchase entries are hidden and their APIs return not-available

### Requirement: Ledger tamper evidence (MVP scope)
The ledger chain of hashes SHALL be anchored monthly via TSA trusted timestamp on the monthly statement. Per-batch on-chain anchoring is out of MVP scope but the batch table design MUST reserve for it.

#### Scenario: Detect modified historical entry
- **WHEN** any historical ledger row is modified directly in DB
- **THEN** its entry_hash breaks the chain and the mismatch is detectable via the verification tool
