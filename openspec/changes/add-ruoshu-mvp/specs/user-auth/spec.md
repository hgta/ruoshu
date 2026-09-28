## ADDED Requirements

### Requirement: WeChat scan registration and login
The system SHALL support WeChat scan-code registration/login as the primary authentication method, and account+password as fallback. After scan, the system MUST create the account with an auto-generated nickname and default avatar without interrupting the user's reading flow.

#### Scenario: New user scans code mid-reading
- **WHEN** an unauthenticated visitor hits a VIP chapter and scans the WeChat code
- **THEN** the account is created within 15 seconds total flow and the user returns to the same reading position

### Requirement: Role system
The system SHALL support three roles: reader, author, admin. Role flags SHALL be a bitmap on the user record; a user MAY hold multiple roles. Reader is the default role on registration.

#### Scenario: Reader becomes author
- **WHEN** a user completes real-name verification in author center
- **THEN** the author flag is granted and author workspace routes become accessible

### Requirement: Author real-name verification
The system SHALL require real-name verification (name + ID number) before a user can publish works or receive payouts. The ID number MUST be stored hashed and masked; plaintext SHALL NOT be stored.

#### Scenario: Unverified user attempts to publish
- **WHEN** a user without author real-name verification submits a chapter for publishing
- **THEN** the system rejects with a guidance message linking to the verification flow

### Requirement: Session and security baseline
All traffic SHALL use HTTPS with HSTS. Admin routes SHALL reside on a separate subdomain requiring IP allowlist and TOTP two-factor authentication. Admin actions MUST be written to immutable audit logs.

#### Scenario: Admin login without TOTP
- **WHEN** an admin provides correct credentials but no valid TOTP code
- **THEN** access is denied and the attempt is logged
