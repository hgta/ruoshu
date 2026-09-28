## ADDED Requirements

### Requirement: Per-user zero-width watermark on paid chapters
VIP chapter bodies served to logged-in users SHALL embed a per-user invisible watermark (zero-width character sequence encoding user id with error-check bits) at punctuation anchor positions. Embedding MUST be deterministic per (user, chapter) so repeated reads yield identical output. Free chapters SHALL NOT be watermarked.

#### Scenario: Two users get distinct watermarks
- **WHEN** two different users fetch the same VIP chapter
- **THEN** each body contains the respective user's watermark and is visually identical

### Requirement: Watermark planning at publish time
At publish time, the system SHALL precompute the anchor plan (which punctuation positions carry which bits) per chapter, so read-time embedding is pure string substitution with no runtime NLP.

#### Scenario: Read-time embedding without NLP
- **WHEN** a user reads a watermarked chapter
- **THEN** body rendering performs only planned substitutions and returns within the reading performance budget

### Requirement: Per-user content caching separation
Watermarked bodies MUST NOT enter shared/public CDN cache. Free chapter bodies MAY use shared cache.

#### Scenario: Paid body not shared-cached
- **WHEN** two users request the same VIP chapter through the CDN
- **THEN** each receives an individually watermarked response, never a cached foreign copy

### Requirement: Traceability workbench
The author traceability workbench SHALL accept pasted pirated text (or a pirated link for manual fetch), run paragraph fingerprint matching against works, extract zero-width watermark payload, and report suspected leaking user accounts with confidence scores. Results below confidence threshold MUST be presented as "manual comparison report" and MUST NOT auto-ban.

#### Scenario: Trace a lazy pirated copy
- **WHEN** author pastes pirated text copied verbatim from a VIP chapter
- **THEN** the workbench reports matching chapter, similarity, and the leaking user account

### Requirement: Evidence package generation
The workbench SHALL generate a downloadable PDF evidence package (chain certificates, TSA certificate, original hash list, comparison report, account linkage) usable directly by a lawyer. Package generation is an async queued job.

#### Scenario: Download evidence package
- **WHEN** author requests evidence package for a confirmed leak
- **THEN** a signed URL for the PDF is delivered via notification within 10 minutes

### Requirement: Watermark disclosure
The platform SHALL disclose watermark mechanics to authors (in author agreement and work settings page), and authors SHALL see a watermarked preview identical to what readers see.

#### Scenario: Author preview
- **WHEN** author previews own VIP chapter
- **THEN** the rendered body matches the watermarked reader version format
