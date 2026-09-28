## ADDED Requirements

### Requirement: License selector
Authors SHALL choose from 5 license options at work creation (CC BY-NC-ND recommended default, CC BY-NC, CC BY-ND, all rights reserved, CC0). The work page SHALL render the license badge with a link to full license text. License change history SHALL be hash-recorded with timestamps.

#### Scenario: Reader sees license terms
- **WHEN** a reader opens a work licensed CC BY-NC-ND
- **THEN** the badge with link to the CC license text is displayed in the work footer

### Requirement: One-click export
Authors SHALL export their works at any time, unlimited and free of charge, in Markdown and TXT formats. The export MUST include a manifest with Merkle root references matching the chain-evidenced published version. Exports are async jobs delivering signed OSS URLs with 72h expiry.

#### Scenario: Author exports full work
- **WHEN** author requests a full export of a completed work
- **THEN** a ZIP containing chapters in Markdown/TXT plus evidence manifest is delivered

### Requirement: Feed outputs
Each work SHALL provide an RSS feed (title, summary within 200 chars, link) and each author an Atom feed, with paid-content bodies never exposed in feeds. Feeds update within 5 minutes of publishing.

#### Scenario: Reader subscribes via RSS
- **WHEN** a chapter is published
- **THEN** RSS subscribers see title, summary and link (never full paid text)

### Requirement: Update notifications neutrality
Update notifications SHALL be available via in-site message, email, and RSS. The notification subsystem MUST NOT be the only channel authors can reach readers through.

#### Scenario: Reader follows updates without app
- **WHEN** a reader subscribes to a work's RSS feed
- **THEN** the reader receives update notices without installing anything

### Requirement: No lock-in guarantee
The platform MUST NOT require exclusivity for any feature in MVP. Taking a work offline or deleting an author account MUST NOT delete evidence records (retained for defense of past claims) but MUST remove reader-facing content per author request.

#### Scenario: Author leaves platform
- **WHEN** an author deletes their account
- **THEN** their works go offline and historical evidence records are retained
