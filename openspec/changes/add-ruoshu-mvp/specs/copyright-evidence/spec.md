## ADDED Requirements

### Requirement: Chapter fingerprints on publish
On publishing, the system SHALL normalize text, split into paragraphs, compute SHA-256 per paragraph, and build a Merkle tree with root stored locally (MySQL + OSS). Fingerprints are immutable snapshots per published version.

#### Scenario: Fingerprint generation
- **WHEN** author publishes a 3124-word chapter
- **THEN** paragraph fingerprint rows and a Merkle root are persisted before any chain submission

### Requirement: Chain evidence submission
The system SHALL submit evidence payloads (chapter/book/author identifiers, title hash, Merkle root, word count, version, license, published_at, prev_chapter_root) to Zhixin Chain asynchronously via a driver abstraction. Payloads MUST NOT contain original text or plaintext titles. Free works use daily batch anchoring (one per day); paid works anchor per chapter.

#### Scenario: Paid chapter anchoring
- **WHEN** a VIP chapter is published
- **THEN** a single evidence record is submitted and tx_id written back within 60 seconds

### Requirement: Local-first evidence retention
Hashes and payloads MUST be persisted locally before chain submission. Chain service outages MUST NOT lose evidence data; submissions retry until confirmed and dead-letter after threshold.

#### Scenario: Chain API outage
- **WHEN** Zhixin Chain API is unavailable for an hour
- **THEN** evidence records stay in pending/retrying states and no data is lost

### Requirement: Evidence badge and public verification
Confirmed works SHALL display an evidence badge with certificate number. A public verification page SHALL accept any pasted original paragraph, compute SHA-256 live, and match it against stored fingerprints to demonstrate the published-version claim.

#### Scenario: Public verification
- **WHEN** anyone pastes a paragraph from the originally published version
- **THEN** the page shows a match with publish time and evidence details

### Requirement: Chained per-chapter evidence
Each chapter's evidence payload SHALL reference the previous chapter's root (prev_chapter_root), forming an unbroken per-work chain so partial-copy claims are refutable.

#### Scenario: Chapter chain integrity
- **WHEN** verification examines chapters 5 and 6 of a work
- **THEN** chapter 6's payload references chapter 5's root correctly
