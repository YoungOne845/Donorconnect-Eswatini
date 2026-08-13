# Update: Admin Donor Registration, OTP Login, and Chatbot

This update adds three requested improvements:

1. **All ENBTS admin/staff accounts can register donors** from the Donor Pool page.
   - Mbabane Central Admin can register donors.
   - Manzini Branch Operator can register donors.
   - Hlathikhulu Branch Operator can register donors.
   - Staff/admin can still view donation history and record completed donations from each donor detail page.

2. **Donors can sign in with either password or OTP.**
   - Password login still works for donors who know their password.
   - OTP login works for donors who forgot their password or were registered by ENBTS staff during school/community campaigns.
   - In local demo mode, the OTP appears on the login page and is also written to `api/storage/logs/sms.log`.

3. **The DonorConnect Assistant chatbot is restored.**
   - It appears as a floating chat button across the frontend.
   - It answers basic FAQ-style questions about registration, eligibility, OTP, campaigns, blood requests, dispatches, and donation history.

## If you are using a fresh database

Import:

```sql
/database/schema.sql
```

The OTP table is already included in the main schema.

## If you already imported the old schema before this update

Open phpMyAdmin, select your `donorconnect` database, then import/run:

```text
database/migrations/2026_06_13_otp_login_and_staff_donor_registration.sql
```

Then restart the frontend:

```powershell
cd C:\xampp\htdocs\donorconnect_enbts_rebuild\frontend
npm run dev
```

## Staff/admin donor registration test

1. Log in as Mbabane Central Admin:

```text
National ID: 9001011234567
Password: Mbabane@2026
```

2. Go to:

```text
Donor pool → Register donor
```

3. Leave password blank to create an OTP-login donor.

## Donor OTP login test

1. Go to login.
2. Select `OTP`.
3. Enter the donor National ID.
4. Click `Send OTP`.
5. Use the demo OTP shown on screen.

