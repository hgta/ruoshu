## ADDED Requirements

### Requirement: Category and tag browsing
The system SHALL provide 6 first-level categories as navigation and a free-combining tag cloud (theme/character/emotion/plot/style/length/redeal). Explore pages SHALL support filtering by category, tags, status (serializing/completed) and sorting (recent/popular/word count).

#### Scenario: Browse by tag combination
- **WHEN** a user selects tags "重生" and "女主强"
- **THEN** works carrying both tags are listed with chosen sort order

### Requirement: Site search
The system SHALL provide search over work titles, author names, and tags via Meilisearch with typo tolerance. Search results SHALL be limited to work/chapter metadata (no full-text body search in MVP).

#### Scenario: Fuzzy search with typo
- **WHEN** a user searches a slightly mistyped author pen name
- **THEN** relevant results are still returned

### Requirement: Homepage modules
The homepage SHALL render: editor-picks banner, new-arrivals shelf, completed/popular ranking tabs, category grid, trending tags, donation activity feed, and selected paragraph-comment cards. All modules MUST be operable by editors without deploy.

#### Scenario: Editor curates homepage
- **WHEN** an editor changes the featured works via admin panel
- **THEN** the homepage updates within cache TTL without redeployment

### Requirement: Meilisearch index sync
Work/chapter metadata changes SHALL be synced to Meilisearch via queued jobs; index lag MUST NOT exceed 30 seconds after a work becomes visible.

#### Scenario: New work searchable
- **WHEN** a work is published
- **THEN** it appears in search results within 30 seconds
