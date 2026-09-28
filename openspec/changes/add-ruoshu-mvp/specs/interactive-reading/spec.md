## ADDED Requirements

### Requirement: Paragraph comments
Users SHALL comment on any paragraph anchor, chapter, or work via a polymorphic comment store (target_type + target_id). Comments support threading (one level), likes, moderation states. Posting MUST appear instantly via optimistic UI plus WebSocket broadcast.

#### Scenario: Post a paragraph comment
- **WHEN** a logged-in user submits a paragraph comment
- **THEN** it appears immediately for the poster and broadcasts to other viewers within 2 seconds

### Requirement: Real-time danmu
Chapters SHALL support real-time danmu bound to paragraph positions, delivered via the Go WebSocket service. Danmu is ephemeral stream data, stored hot in Redis with periodic archival; comments are durable assets. The two systems MUST remain separate stores.

#### Scenario: Danmu during hot chapter
- **WHEN** many users watch a newly published chapter simultaneously
- **THEN** danmu float and fade per client rendering without blocking reading

### Requirement: WebSocket with polling fallback
The Go realtime service SHALL authenticate connections via short-lived JWT issued by Laravel. When WebSocket is unavailable, clients MUST automatically degrade to 5-second polling for comments and danmu without user action.

#### Scenario: Realtime service restart
- **WHEN** the Go service restarts during a user's reading session
- **THEN** the client silently falls back to polling and resumes WebSocket when available

### Requirement: Donation danmu integration
Payment-success donation events SHALL trigger a danmu broadcast (gift name + thanks) to the chapter room within 3 seconds, and a notification to the author dashboard.

#### Scenario: Gift lands in danmu
- **WHEN** a reader's gift payment succeeds
- **THEN** the danmu stream in that chapter shows the gift message within 3 seconds

### Requirement: Comment moderation hooks
All comments and danmu SHALL pass sensitive-word filtering before publish. Hidden/removed states MUST be enforced in both realtime and polling paths.

#### Scenario: Filtered content blocked
- **WHEN** a user posts a comment containing blocked words
- **THEN** the comment is rejected with a notice and never broadcast
