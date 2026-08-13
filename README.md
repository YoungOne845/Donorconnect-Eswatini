# DonorConnect 🩸

DonorConnect is a full-stack blood donor lifecycle, recruitment, retention and mobilisation platform designed around the real goal of building a larger, reliable and active donor pool.

## What is included

- React + Vite responsive frontend
- PHP 8 API with route protection, sessions and CSRF controls
- MySQL/MariaDB schema for XAMPP and phpMyAdmin
- Encrypted national ID storage with keyed duplicate-detection hashes
- Donor registration, verification, eligibility, donation and deferral workflows
- Campaign recruitment, invitations, participation and conversion reporting
- Hospital blood requests, compatibility matching, notifications and donor responses
- Web notifications and optional Twilio SMS delivery
- Donor-pool growth, verification, conversion and repeat-donor analytics
- Role-based portals for donors, hospitals, blood-service staff and administrators
- Audit logs, rate limiting, account lockout and daily maintenance automation
- Local setup and production deployment scripts

## Start here

Read **[docs/WALKTHROUGH.md](docs/WALKTHROUGH.md)**. It starts at unzipping the project and finishes with a production go-live checklist.

## Main folders

```text
DonorConnect/
├── api/                  PHP API and security layer
├── database/             Fresh MySQL schema and reference data
├── frontend/             React + Vite application
├── deployment/           Apache and SPA deployment files
├── docs/                 Setup, deployment, security and migration guides
├── setup-local.ps1       Generates local environment files and installs packages
└── build-production.ps1  Produces a shared-hosting deployment package
```

## Required software

- XAMPP with Apache, MySQL/MariaDB and PHP 8.2 or newer
- Node.js 20.19 or newer
- VS Code
- A modern browser

## Important

`database/schema.sql` is a **fresh installation script**. It drops and recreates the `donorconnect` database. Back up any old test database before running it.
