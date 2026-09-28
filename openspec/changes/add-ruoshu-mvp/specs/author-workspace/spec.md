## ADDED Requirements

### Requirement: Work creation and metadata
The system SHALL allow verified authors to create works with title, one of six first-level categories (古风言情/现言都市/玄幻仙侠/奇幻冒险/科幻悬疑/轻小说), at least 3 tags, cover image, and a license selection. License SHALL be selected at creation from 5 options (CC BY-NC-ND default / CC BY-NC / CC BY-ND / all-rights-reserved / CC0) and its choice record SHALL be hash-anchored.

#### Scenario: Create work with default license
- **WHEN** author creates a work and accepts the recommended CC BY-NC-ND license
- **THEN** the work is created and the license choice record with document hash is stored

### Requirement: Chapter editor with autosave
The author chapter editor SHALL autosave drafts every 10 seconds, show live word count, and run sensitive-word precheck before publishing. Single chapter size MUST be limited to 100,000 characters.

#### Scenario: Draft preserved after session loss
- **WHEN** author closes browser without saving and reopens the chapter later
- **THEN** the latest autosaved draft (within 10s) is restored

### Requirement: Publishing pipeline
Publishing a chapter SHALL trigger, asynchronously and non-blockingly: (1) paragraph normalization, SHA-256 fingerprints and Merkle root computation; (2) chain evidence submission per fee-tier policy; (3) watermark anchor planning for paid chapters; (4) RSS feed update; (5) reader update notifications. The publish action itself MUST return success within 3 seconds.

#### Scenario: Publish paid chapter
- **WHEN** author publishes a VIP chapter
- **THEN** the chapter is visible immediately, and evidence record reaches confirmed status within 60 seconds without blocking the author

### Requirement: Version history on revision
The system SHALL support chapter revision: each published revision creates a new immutable paragraph fingerprint snapshot version, so the previously published version remains verifiable.

#### Scenario: Edit published chapter
- **WHEN** author edits and republishes a chapter
- **THEN** a new fingerprint version is stored and the prior version's evidence remains verifiable

### Requirement: Work settings and lifecycle
Authors SHALL be able to modify work settings (pricing, tags, license) and to unpublish/archive works at any time without penalty. Unpublishing MUST NOT delete evidence records.

#### Scenario: Author takes work offline
- **WHEN** author unpublishes a work
- **THEN** the work becomes inaccessible to readers but evidence records are retained
