DonorConnect ENBTS Rebuild

Start with ENBTS-REBUILD-WALKTHROUGH.md.

Important setup shortcut:
1. Import database/schema.sql in phpMyAdmin.
2. Copy api/.env.example to api/.env and adjust DB_PASSWORD if needed.
3. Run: php api/scripts/seed_enbts_demo.php
4. Run frontend: cd frontend && npm install && npm run dev

Login accounts:
--------------------------------------------------------------
| Role                              | National ID     | Password       |
| Mbabane Central Admin             | 9001011234567   | Mbabane@2026   |
| Manzini Branch Operator           | 9102021234567   | Manzini@2026   |
| Hlathikhulu Branch Operator       | 9203031234567   | Hlathi@2026    |
| Nhlangano Hospital Blood Desk     | 9304041234567   | Hospital@2026  |
| Mbabane Government Hospital Desk  | 8005051234567   | Mbabane@2026   |
--------------------------------------------------------------

FINAL POLISH NOTE
-----------------
For the latest donor OTP/password/chatbot/messaging/appointment/dispatch fixes, read:
FINAL-POLISH-WALKTHROUGH.md

If updating an existing database, run:
database/migrations/2026_06_13_final_donor_experience_and_dispatch.sql
