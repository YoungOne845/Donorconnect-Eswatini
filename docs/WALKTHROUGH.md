# DonorConnect: unzip-to-live walkthrough

This guide assumes Windows, XAMPP, phpMyAdmin and VS Code. Follow it in order.

---

## Part 1 — Fresh local installation

### 1. Back up the old experimental database

The new schema is not the earlier prototype schema. In phpMyAdmin:

1. Select the old `donorconnect` database.
2. Choose **Export**.
3. Use **Quick** and **SQL**.
4. Save the backup somewhere outside XAMPP.

The new `database/schema.sql` deliberately drops and recreates `donorconnect`.

### 2. Extract the project

Extract the ZIP and rename the extracted folder to exactly:

```text
C:\xampp\htdocs\donorconnect
```

The expected path is:

```text
C:\xampp\htdocs\donorconnect\api
C:\xampp\htdocs\donorconnect\database
C:\xampp\htdocs\donorconnect\frontend
```

Do not nest it as `donorconnect/DonorConnect/...`.

### 3. Start XAMPP

Open XAMPP Control Panel and start:

- Apache
- MySQL

Both should become green. If Apache cannot start, another program may already be using ports 80 or 443.

### 4. Generate local configuration

Open PowerShell inside `C:\xampp\htdocs\donorconnect` and run:

```powershell
powershell -ExecutionPolicy Bypass -File .\setup-local.ps1
```

If your MySQL root account has a password:

```powershell
powershell -ExecutionPolicy Bypass -File .\setup-local.ps1 -DbPassword "YOUR_MYSQL_PASSWORD"
```

The script:

- creates `api/.env`;
- generates three independent random security secrets;
- creates `frontend/.env`;
- installs frontend packages.

Never upload `api/.env` to GitHub.

### 5. Create the database

Open:

```text
http://localhost/phpmyadmin
```

Open the **SQL** tab, copy the whole contents of:

```text
database/schema.sql
```

Paste it and click **Go**.

Then run:

```text
database/reference_data.sql
```

The reference file creates a starting blood-service institution that can be edited or supplemented from the administrator portal.

### 6. Confirm the API

Open:

```text
http://localhost/donorconnect/api/health
```

Expected shape:

```json
{
  "success": true,
  "message": "DonorConnect API is healthy.",
  "data": {
    "database": "connected"
  }
}
```

A 404 usually means Apache rewrite rules are disabled. In XAMPP, confirm `mod_rewrite` is enabled and that the Apache directory allows `.htaccess` overrides.

### 7. Run the environment self-check

From the project folder:

```powershell
C:\xampp\php\php.exe api\scripts\self_check.php
```

Resolve every failed item before continuing. The check verifies PHP extensions, security keys, database access, required tables and log permissions.

### 8. Create the first administrator

Open PowerShell in the project folder and run one line:

```powershell
C:\xampp\php\php.exe api\scripts\create_admin.php --name="Siyabonga Mabuza" --phone=76123456 --email=admin@example.com --password="StrongAdmin123"
```

Use a password that is not reused anywhere else.

### 9. Start the React application

In the VS Code terminal:

```powershell
cd frontend
npm run dev
```

Open:

```text
http://localhost:5173
```

The frontend sends API requests to `http://localhost/donorconnect/api` using `frontend/.env`.

---

## Part 2 — Configure the operational network

### 10. Sign in as administrator

Use the administrator phone, email or national ID login identifier. Administrator accounts do not need a national ID, so use the phone or email created by the CLI command.

### 11. Add institutions

Open **Institutions** and add the organisations that will participate:

- blood-service facilities;
- hospitals;
- schools and universities;
- churches;
- workplaces;
- community organisations.

This makes recruitment source analytics meaningful instead of treating every donor as a random web signup.

### 12. Create staff and hospital accounts

Open **User accounts** and create:

- at least one `staff` account linked to a blood-service institution;
- at least one `hospital` account linked to a hospital;
- another `admin` account only when necessary.

Public registration creates donors only. Operational roles must be created by an administrator.

### 13. Register donors

Sign out and use **Join the donor pool**.

Registration captures:

- full name;
- national ID;
- phone and optional email;
- birth date and gender;
- current region and town;
- known or unknown blood type;
- recruitment source and institution;
- preferred contact method;
- consent and emergency contact details.

The national ID is encrypted with AES-256-GCM. A keyed HMAC hash detects duplicate registrations without searching the encrypted text. Normal API responses expose only a masked suffix.

### 14. Verify and assess a donor

Sign in as staff or administrator:

1. Open **Donor pool**.
2. Select a donor.
3. Choose **Verify donor** and confirm the blood type.
4. Choose **Assess eligibility**.
5. Set the donor to eligible or record a temporary/permanent deferral.

Registration is never treated as medical clearance.

### 15. Record a completed donation

From the donor record:

1. Select **Record donation**.
2. Enter the donation date and type.
3. Enter the collection location.
4. Enter the next eligible date determined by authorised policy and staff.
5. Save.

The system then:

- updates donation history;
- increments total donations;
- moves the donor into a recovery/temporary-deferral state;
- sends a thank-you notification;
- sends milestone recognition at 1, 5, 10, 20 and 50 donations.

### 16. Create a recruitment or retention campaign

Open **Campaigns** and create a campaign with:

- a campaign type;
- location and venue;
- target region and optional blood type;
- start/end dates;
- capacity;
- description.

Use **Invite matching donors** to create real campaign notifications for suitable donor records. Donors can register or show interest through their own portal.

### 17. Test hospital demand and donor mobilisation

Sign in as a hospital account:

1. Open **Blood requests**.
2. Create a request with blood type, units, hospital, urgency and location.

Then sign in as staff/admin:

1. Open the request.
2. Select **Identify suitable donors**.
3. Review the scores.
4. Select **Notify top matches**.

Matching uses:

- red-cell blood compatibility;
- verified identity and confirmed blood type;
- current eligibility;
- availability;
- town/region proximity;
- previous response behaviour.

A donor can accept or decline from the donor dashboard. The hospital and staff request view then shows the response.

### 18. Review reports

Open **Reports** to review:

- total and newly recruited donors;
- verification rate;
- first-donation conversion rate;
- repeat-donor rate;
- eligibility distribution;
- regional and blood-type distribution;
- recruitment source performance;
- campaign participation and donation conversion.

This is the module that proves DonorConnect is a donor-pool enlargement system rather than only an emergency alert app.

---

## Part 3 — Daily automation

Run the maintenance command manually during local testing:

```powershell
C:\xampp\php\php.exe api\scripts\run_maintenance.php
```

It:

- restores eligible status after expired temporary deferrals;
- reminds donors who will soon become eligible;
- re-engages donors with no recent activity;
- reminds registered campaign participants 24–48 hours before an event.

For production, schedule this command once daily using the hosting control panel's Cron Jobs feature.

---

## Part 4 — Real SMS

The default local driver is:

```env
SMS_DRIVER=log
```

Messages are written to:

```text
api/storage/logs/sms.log
```

For Twilio, configure the following environment variables on your server (do NOT commit credentials to the repository):

```env
# Example (set these on the host only):
# SMS_DRIVER=twilio
# TWILIO_ACCOUNT_SID=
# TWILIO_AUTH_TOKEN=
# TWILIO_FROM_NUMBER=
```

The PHP cURL extension must be enabled. Web notifications work independently of SMS.

---

## Part 5 — Build the production frontend

Before deployment, set `frontend/.env`:

```env
VITE_API_URL=https://your-domain.example/api
```

Then:

```powershell
cd frontend
npm ci
npm run build
```

Vite creates:

```text
frontend/dist
```

The project uses route-level code splitting so the analytics chart bundle is not downloaded on every page.

You can also generate a complete shared-hosting package from the project root:

```powershell
powershell -ExecutionPolicy Bypass -File .\build-production.ps1 -Domain "https://your-domain.example"
```

Output:

```text
release/DonorConnect-public-html.zip
```

---

## Part 6 — Put DonorConnect live on PHP/MySQL hosting

Use hosting that supports:

- a custom domain and HTTPS;
- PHP 8.2+;
- MySQL 8 or a compatible modern MariaDB release;
- Apache rewrite rules or an equivalent SPA fallback;
- PHP PDO MySQL, OpenSSL and cURL extensions;
- cron jobs;
- a database user with only the permissions needed for this application.

### 19. Create the production database

In the hosting control panel:

1. Create a MySQL database.
2. Create a dedicated database user.
3. Grant that user access to only the DonorConnect database.
4. Import `database/schema.sql`.
5. Import `database/reference_data.sql`.

Some hosts prefix database names and usernames. Use the full prefixed names in `api/.env`.

### 20. Upload files

Upload the **contents** of `frontend/dist` into `public_html`.

Upload the project `api` folder into:

```text
public_html/api
```

Copy:

```text
deployment/public-root.htaccess
```

to:

```text
public_html/.htaccess
```

The root `.htaccess` sends React routes to `index.html` but leaves `/api` requests untouched.

### 21. Create the production API environment file

Create `public_html/api/.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Africa/Mbabane
APP_URL=https://your-domain.example
FRONTEND_URL=https://your-domain.example
API_BASE_PATH=/api

DB_HOST=your_database_host
DB_PORT=3306
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password

SESSION_NAME=donorconnect_session
SESSION_SECURE=true
SESSION_SAMESITE=Lax
SESSION_LIFETIME_MINUTES=120

NATIONAL_ID_ENCRYPTION_KEY=64_or_more_random_characters
NATIONAL_ID_HASH_KEY=a_different_64_or_more_character_secret
APP_KEY=a_third_independent_random_secret

SMS_DRIVER=log
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_FROM_NUMBER=

SETUP_TOKEN=unused_after_setup
```

Use different random values for all three secrets. Never copy development secrets into production.

### 22. Create the first production administrator

With a cPanel/hosting terminal:

```bash
php public_html/api/scripts/create_admin.php --name="System Administrator" --phone=76123456 --email=admin@your-domain.example --password="A-Unique-Strong-Password123"
```

If the host does not provide a terminal, temporarily set:

```env
APP_ENV=setup
SETUP_TOKEN=a_long_one_time_random_value
```

Then call:

```bash
curl -X POST "https://your-domain.example/api/setup/admin" \
  -H "Content-Type: application/json" \
  -H "X-Setup-Token: a_long_one_time_random_value" \
  -d '{"full_name":"System Administrator","phone":"76123456","email":"admin@your-domain.example","password":"A-Unique-Strong-Password123"}'
```

Immediately change `APP_ENV` back to `production` and replace/remove the setup token.

### 23. Configure the daily cron job

Example:

```text
0 2 * * * /usr/local/bin/php /home/ACCOUNT/public_html/api/scripts/run_maintenance.php
```

The exact PHP path and account path depend on the host.

### 24. Set file permissions

Typical values:

- folders: `755`;
- files: `644`;
- `api/.env`: `600` or the most restrictive value supported;
- `api/storage/logs`: writable by PHP, commonly `775`.

Do not use `777` unless the host gives no safer alternative.

### 25. Verify HTTPS and cookies

Production must use HTTPS because `SESSION_SECURE=true` prevents the browser from sending the session cookie over plain HTTP.

Test:

```text
https://your-domain.example/api/health
https://your-domain.example/login
https://your-domain.example/app/dashboard
```

Also refresh a nested React route such as `/app/reports`. It should load rather than return an Apache 404.

---

## Part 7 — Go-live checklist

Before showing the system publicly:

- [ ] `APP_DEBUG=false`
- [ ] HTTPS is active and forced
- [ ] production security keys are unique and backed up securely
- [ ] no `.env` file is committed to GitHub
- [ ] database uses a dedicated non-root user
- [ ] administrator uses a unique password
- [ ] old test donors and fake institutions are removed
- [ ] national ID is never displayed in full
- [ ] operational users have the correct roles
- [ ] cron maintenance runs successfully
- [ ] database backups are enabled and tested
- [ ] SMS provider consent and message wording are approved
- [ ] healthcare professionals confirm eligibility and deferral rules
- [ ] privacy, retention and access policies are approved before real personal data is collected
- [ ] incident contact and account-revocation procedures exist

---

## Part 8 — Recommended presentation flow

To impress lecturers or stakeholders, demonstrate the story rather than clicking randomly:

1. Show the public mission: donor-pool growth and retention.
2. Register a donor recruited through a university campaign.
3. Show encrypted/masked national identity handling.
4. Verify the donor and assess eligibility as staff.
5. Create a campaign and invite donors.
6. Record a donation and show the automatic thank-you, recovery date and milestone.
7. Show the growth and conversion reports.
8. Create a hospital request.
9. Match and notify suitable donors.
10. Accept from the donor portal and show the response in the request monitor.

The key message is:

> DonorConnect does not wait for a shortage to start looking for donors. It continuously builds, understands and retains the donor pool so the country is better prepared when demand occurs.
