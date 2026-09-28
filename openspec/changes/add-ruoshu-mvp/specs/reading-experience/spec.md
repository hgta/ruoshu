## ADDED Requirements

### Requirement: Work detail page
The work detail page SHALL show cover, title, author entry, category, tags, aggregate stats, license badge, chain-evidence badge (when confirmed) with verification link, latest chapters, review area, and donation entry.

#### Scenario: Visitor views work with evidence
- **WHEN** a visitor opens a work whose latest chapter evidence is confirmed
- **THEN** the evidence badge and a verification link are displayed

### Requirement: Chapter reading page
The reading page SHALL render paragraphs as individual anchors supporting paragraph comments; provide three themes (light/paper/dark), adjustable font size and line height; be mobile-first (375px baseline). End-of-chapter SHALL show next chapter, table of contents, favorite, donation entry and gift selector.

#### Scenario: Read free chapter anonymously
- **WHEN** an anonymous visitor opens a free chapter
- **THEN** the first 3 chapters per work are readable without login, and paragraph anchors render

### Requirement: VIP gating
VIP chapters SHALL require purchase (per-chapter price default 0.2 CNY) or an active monthly membership. Paid content MUST be served per-user (no shared CDN cache for watermarked bodies); free chapters MAY use CDN.

#### Scenario: Member reads VIP chapter
- **WHEN** a monthly member opens a VIP chapter
- **THEN** access is granted with membership covering the price and per-user watermarking is applied

### Requirement: Bookshelf and reading progress
Logged-in readers SHALL have a bookshelf with continue-reading positions. Progress MUST be reported periodically from the client and restored on any device after login.

#### Scenario: Resume reading on another device
- **WHEN** a reader logs in on a new device and opens the bookshelf
- **THEN** the last reading position is restored

### Requirement: Reading performance
Chapter body requests (cache hit) SHALL return within 200ms p95. The reading page MUST NOT contain third-party ad slots.

#### Scenario: Hot chapter under load
- **WHEN** a popular free chapter is requested at baseline load
- **THEN** p95 response time stays within 200ms served from Redis cache
