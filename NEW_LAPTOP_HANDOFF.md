# DonorConnect ENBTS Rebuild – New Laptop Handoff Guide

> **Read this entire file before touching anything.** This guide documents the current state of the project, what was fixed, how to start it, and what login credentials to use for the presentation.

---

## Project Location

The project folder must be in **two places** on the new laptop:

| Location | Purpose |
|---|---|
| `C:\xampp\htdocs\donorconnect_enbts_rebuild\` | Apache/PHP backend served by XAMPP |
| Anywhere (e.g. Desktop or Documents) | Working source folder for Vite/React frontend |

You can use **the same folder** for both by placing it directly inside `C:\xampp\htdocs\`.

---

## Required Software

Install all of these before starting:

1. **XAMPP** – includes Apache, MySQL, PHP 8.2+  
   Download: https://www.apachefriends.org/download.html
2. **Node.js 20+**  
   Download: https://nodejs.org/en/download
3. **A modern browser** (Chrome or Edge)

---

## Step-by-Step Setup on the New Laptop

### Step 1 – Copy the Project Folder

Copy the entire `donorconnect_enbts_rebuild` folder to:
```
C:\xampp\htdocs\donorconnect_enbts_rebuild\
```

### Step 2 – Start XAMPP

1. Open XAMPP Control Panel
2. Start **Apache**
3. Start **MySQL**

### Step 3 – Import the Database

1. Open your browser and go to: http://localhost/phpmyadmin
2. Click **New** on the left sidebar
3. Create a database named: `donorconnect`
4. Click the new `donorconnect` database
5. Click the **Import** tab
6. Click **Choose File** and select:  
   `C:\xampp\htdocs\donorconnect_enbts_rebuild\database\schema.sql`
7. Click **Import** (or Go)

### Step 4 – Apply the Phone Secondary Migration

After importing the schema, go back to phpMyAdmin, click on `donorconnect`, then click the **SQL** tab and run:

```sql
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_secondary VARCHAR(20) NULL AFTER phone;
```

Click **Go**.

### Step 5 – Configure the `.env` File

The file is at:
```
C:\xampp\htdocs\donorconnect_enbts_rebuild\api\.env
```

Make sure it contains exactly this (the file may already exist — just verify/replace the SMS section):

```env
APP_ENV=local
APP_DEBUG=true
APP_TIMEZONE=Africa/Mbabane
APP_URL=http://localhost/donorconnect_enbts_rebuild
FRONTEND_URL=http://localhost:5173
API_BASE_PATH=/donorconnect_enbts_rebuild/api

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=donorconnect
DB_USER=root
DB_PASSWORD=

SESSION_NAME=donorconnect_session
SESSION_SECURE=false
SESSION_SAMESITE=Lax
SESSION_LIFETIME_MINUTES=120

NATIONAL_ID_ENCRYPTION_KEY=<SET_ON_SERVER>
NATIONAL_ID_HASH_KEY=<SET_ON_SERVER>
APP_KEY=<SET_ON_SERVER>

# SMS configuration: Do NOT commit provider credentials to the repository.
# Default local driver writes SMS to logs. To enable Twilio in production,
# set the following environment variables on your host (do NOT add them to
# committed files):
# SMS_DRIVER=twilio
# TWILIO_ACCOUNT_SID=
# TWILIO_AUTH_TOKEN=
# TWILIO_FROM_NUMBER=

SETUP_TOKEN=setup_token_secure_123_abc_xyz
```

> **CRITICAL:** The three encryption keys (NATIONAL_ID_ENCRYPTION_KEY, NATIONAL_ID_HASH_KEY, APP_KEY) must stay exactly the same as above. If you change them, all existing donor national IDs in the database will fail to decrypt.

### Step 6 – Seed the Demo Accounts

Open **Command Prompt** and run:

```cmd
C:\xampp\php\php.exe C:\xampp\htdocs\donorconnect_enbts_rebuild\api\scripts\seed_enbts_demo.php
```

You should see output listing four login accounts. This step is REQUIRED — without it, no admin/staff/hospital logins will work.

### Step 7 – Install Frontend Dependencies

Open **Command Prompt** or PowerShell and run:

```cmd
cd C:\xampp\htdocs\donorconnect_enbts_rebuild\frontend
npm install
```

Wait for it to finish.

### Step 8 – Start the Frontend Dev Server

```cmd
npm run dev -- --host 0.0.0.0
```

Open your browser and go to: http://localhost:5173

---

## Login Credentials

> All accounts are active. Use these for the demo.

| Role | National ID | Password | Notes |
|------|------------|---------|-------|
| **Admin** – Mbabane Central | `9001011234567` | `Mbabane@2026` | Full admin portal |
| **Staff** – Manzini Branch | `9102021234567` | `Manzini@2026` | Staff portal |
| **Staff** – Hlathikhulu Branch | `9203031234567` | `Hlathi@2026` | Staff portal |
| **Hospital** – Nhlangano Blood Desk | `9304041234567` | `Hospital@2026` | Hospital portal |

### Registered Donor (OTP Login)

| Field | Value |
|-------|-------|
| **Name** | Vuyo Mncina |
| **Phone** | +26876261223 |
| **Login method** | SMS OTP (code sent to the phone) |

---

## What Was Fixed (Summary of Changes Made on 2026-06-23)

### 1. Database Fix – `phone_secondary` column missing
- `Auth.php` line 29 queries `u.phone_secondary` which didn't exist in the live database.
- **Fixed:** `ALTER TABLE users ADD COLUMN phone_secondary VARCHAR(20) NULL AFTER phone;`

### 2. Demo accounts were missing from the database
- The database only had 1 user so all admin/staff/hospital logins failed with "incorrect password or ID".
- **Fixed:** Ran `api/scripts/seed_enbts_demo.php` to insert all four portal accounts.

### 3. Real Twilio SMS now working
- XAMPP `.env` had `SMS_DRIVER=log` and empty Twilio credentials.
- Twilio trial account had no phone numbers (the old `+26876261223` was incorrectly set as the From number — you can't send to/from the same number).
-- **Fixed:** Provisioned an SMS-capable Twilio number and configured credentials on the server for testing. (Credentials removed from repository.)

### 4. Demo mode text removed from OTP login page
- `LoginPage.jsx` said "in local demo mode, the code also appears here" — removed.

### 5. USSD Portal API Route Fix & Cleaned Offline SMS Response
- **Problem:** USSD page loaded but showed "API route not found" on `/admin/ussd/requests` because the live Apache backend files on XAMPP were out of sync and lacked the admin USSD dashboard routes and updated controller logic.
- **Fixed:** Synced all files from the workspace `api` folder to the live XAMPP Apache path. The USSD dashboard/simulator now loads and communicates correctly.
- **Realistic Offline Flow:** Updated all emergency SMS and match notification texts to be highly realistic, removing technical fields and showing: *"DonorConnect: There is an emergency blood request at {hospital_name} in {town}. You are a match for the needed blood type. Please confirm availability by logging into the portal or dialing *256# Option 2."*
- **Hospitals and Towns Dropdowns:** Replaced the free-text "Hospital name" and "Town" inputs on the request form with dynamic, validated selects. It now loads active hospitals from the database (auto-filling region and town), offers an "Other" option for custom entries, and maps Eswatini towns to their respective regions automatically. All developer/demo jargon has been cleaned from user-facing screens.

---

## Presentation Demo Flow

### Demo 1: Real OTP SMS Login (Donor)
1. Click **SMS OTP** tab on the login page
2. Enter a donor's National ID and phone number
3. Click **Send SMS OTP**
4. Wait for the real SMS to arrive on the phone
5. Enter the 6-digit code and click **Sign In**

### Demo 2: Emergency Blood Request SMS
1. Log in as **Nhlangano Hospital Blood Desk** (`9304041234567` / `Hospital@2026`)
2. Go to **Requests** → **New Request**
3. Select an existing hospital from the **Hospital** dropdown (which auto-fills its Region and Town), or choose **Other (Specify)** to input custom hospital and town fields.
4. Tick: **"Send real SMS alert to matching donors now"**
5. Enter the recipient phone (e.g. `76261223` or `+26876261223`) in the recipient number field.
6. Click **Create request** → Phone receives the realistic emergency SMS within seconds.

---

## Architecture Overview

```
donorconnect_enbts_rebuild/
├── api/                  ← PHP 8 backend (served by XAMPP Apache)
│   ├── .env              ← Environment config (DB, Twilio, keys)
│   ├── bootstrap.php     ← App bootstrap + env loading
│   ├── routes/api.php    ← All API routes
│   ├── src/
│   │   ├── Controllers/  ← AuthController, RequestController, etc.
│   │   ├── Core/         ← Auth, Database, Identity, Env, etc.
│   │   └── Services/     ← SmsService (Twilio), NotificationService
│   ├── scripts/          ← CLI seed/migration scripts
│   └── storage/logs/     ← app.log, sms.log
├── database/
│   ├── schema.sql        ← Full fresh DB schema (import in phpMyAdmin)
│   └── migrations/       ← Individual ALTER TABLE migration SQL files
└── frontend/             ← React + Vite frontend
    ├── src/
    │   ├── pages/        ← LoginPage, DashboardPage, RequestsPage, etc.
    │   ├── context/      ← AuthContext (session management)
    │   └── styles/       ← index.css (all CSS)
    └── vite.config.js    ← Proxy: /api → http://localhost/donorconnect_enbts_rebuild/api
```

---

## Common Issues & Fixes

| Problem | Fix |
|---------|-----|
| `SQLSTATE[42S22]: Column not found: phone_secondary` | Run the SQL in phpMyAdmin: `ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_secondary VARCHAR(20) NULL AFTER phone;` |
| "Incorrect national ID or password" | Run `seed_enbts_demo.php` – the demo accounts are missing from DB |
| OTP SMS not arriving | Verify `SMS_DRIVER` and that SMS provider credentials are configured on your host environment (do NOT commit them to the repo). Restart Apache after changing server env. |
| SMS error: "From is not a Twilio number" | Ensure the `TWILIO_FROM_NUMBER` (if used) is an SMS-enabled Twilio number. Do NOT commit phone numbers or provider credentials. |
| SMS fails to unverified numbers | Verify recipient at https://www.twilio.com/console/phone-numbers/verified |
| Frontend shows "API error" / can't connect | Make sure Apache is running in XAMPP and project is at `C:\xampp\htdocs\donorconnect_enbts_rebuild\` |
| Session / login cookie not working | Frontend must run on `localhost:5173`. Do not use `127.0.0.1:5173` |

---

## Twilio (SMS provider)

- **Provisioning:** If you want real SMS delivery in production, provision a Twilio account and an SMS-capable number via https://www.twilio.com/console. Do NOT store credentials in the repository.
- **Set on host:** Configure `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, and `TWILIO_FROM_NUMBER` as environment variables on your server or hosting platform. Verify recipient numbers in the Twilio console if on a trial account.

---

*This handoff document was generated on 2026-06-23.*
