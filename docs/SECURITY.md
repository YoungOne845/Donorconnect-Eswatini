# Security and data-governance notes

DonorConnect handles identity and health-adjacent operational data. Deployment must be reviewed by the responsible institution before real donor data is collected.

## Implemented controls

- National ID encrypted using AES-256-GCM.
- Keyed HMAC hash used for duplicate national-ID detection.
- Only the final four national-ID characters are retained separately for masking.
- Passwords stored with PHP `password_hash()` and checked with `password_verify()`.
- Prepared PDO queries for all user input.
- HTTP-only session cookies with configurable Secure and SameSite attributes.
- CSRF token required for authenticated state-changing requests.
- Role-based route enforcement.
- Login throttling, failed-attempt tracking and temporary account lockout.
- Audit trail for sensitive operational actions.
- Security response headers and restricted API directories.
- Sensitive server errors logged rather than exposed in production.

## Operational obligations

Code controls are not a replacement for governance. Before real deployment, define:

- who is authorised to view donor identity;
- who may verify blood type and eligibility;
- how long records and audit logs are retained;
- how donors request correction, export or deletion where applicable;
- how compromised accounts are revoked;
- how encryption keys are stored and rotated;
- who receives breach and incident alerts;
- approved SMS/email wording and consent rules;
- backup encryption and restoration procedures.

## Key management

Losing `NATIONAL_ID_ENCRYPTION_KEY` makes encrypted national IDs unrecoverable. Leaking it exposes all encrypted national IDs if the database is also obtained. Store the production key in the hosting secret manager or another access-controlled system, and keep a protected recovery copy.

Changing `NATIONAL_ID_HASH_KEY` changes all duplicate-detection hashes. Key rotation therefore requires a controlled data migration.
