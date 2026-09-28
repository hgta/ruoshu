## ADDED Requirements

### Requirement: Admin console isolation
The admin console SHALL run on a separate subdomain with IP allowlist and TOTP 2FA, implemented via Filament inside apps/web. Admin actions MUST be recorded in append-only audit logs with actor signature.

#### Scenario: Audit trail completeness
- **WHEN** an admin hides a comment
- **THEN** an audit row records actor, action, target and timestamp, not deletable

### Requirement: Content review queue
The admin console SHALL provide review queues for: new works, reported comments, publishing with sensitive-word hits, and originality check reports. Reviewers SHALL act (approve/flag/reject/return) with mandatory notes.

#### Scenario: Handle a reported comment
- **WHEN** an admin processes a reported comment and chooses hide
- **THEN** the comment becomes hidden for all viewers and the report is marked resolved

### Requirement: Report handling
Users SHALL report works/chapters/comments. Reports SHALL enter a triage queue with SLA display. DMCA/infringement requests SHALL load the author's evidence package and verify the chain certificate via Zhixin Chain check API before takedown or legal escalation.

#### Scenario: Takedown verified via chain
- **WHEN** an admin processes an infringement takedown with valid chain certificate
- **THEN** the infringing content is taken down and the case transitions to legal follow-up

### Requirement: Finance review console
Admin finance module SHALL show ledger reconciliation status, payout approvals, and monthly statement generation with TSA anchoring status.

#### Scenario: Monthly statement generation
- **WHEN** an admin triggers monthly statement generation
- **THEN** statements with TSA timestamps are produced for all authors with activity

### Requirement: Homepage curation
Editors SHALL manage homepage modules (banners, picks, ranking tabs, trending tags) via the admin console without deployment; changes take effect within cache TTL.

#### Scenario: Update editor picks
- **WHEN** an editor saves new featured works
- **THEN** the homepage reflects changes within cache TTL
