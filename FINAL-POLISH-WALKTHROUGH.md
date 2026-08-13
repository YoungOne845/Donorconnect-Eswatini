# DonorConnect ENBTS Final Polish Update

This update adds the final donor-experience and ENBTS operations fixes:

- OTP login now works for every donor, whether self-registered or staff-registered.
- OTP request requires National ID + the phone number linked to the donor account.
- Staff-created donors may be created without passwords.
- Donors who have no password can OTP-login and create a password from **My profile**.
- Donors with passwords can change passwords from **My profile**.
- Donor chatbot now appears only inside the donor portal, not admin, branch staff, or hospital portals.
- Mbabane Central Admin can send donor retention, engagement, impact, birthday, and donation reminder messages.
- Donors can book appointment requests and state when they are free to donate.
- Donors can see Bronze/Silver/Gold recognition stats and estimated lives impacted.
- Mbabane Central can assign dispatches directly to Mbabane Blood Bank, including Mbabane hospital requests.

## Required migration for an existing database

If your database already exists, run this file in phpMyAdmin:

```text
 database/migrations/2026_06_13_final_donor_experience_and_dispatch.sql
```

Run this after the earlier OTP migration:

```text
 database/migrations/2026_06_13_otp_login_and_staff_donor_registration.sql
```

If you create a fresh database and import `database/schema.sql`, the new structure is already included.

## Frontend restart

After replacing the project files, restart Vite:

```powershell
cd C:\xampp\htdocs\donorconnect_enbts_rebuild\frontend
npm install
npm run dev
```

## Donor OTP login flow

On the login page:

1. Choose **OTP**.
2. Enter the donor National ID.
3. Enter the phone number used during registration.
4. Click **Send OTP**.
5. In demo mode, the OTP appears on-screen and is logged in:

```text
api/storage/logs/sms.log
```

## Staff-created donor without password

Admin/staff can register a donor and leave the password blank.

That donor should:

1. Log in using National ID + phone OTP.
2. Open **My profile**.
3. Use **Create your password**.

## Admin donor messages

Login as Mbabane Central Admin and open:

```text
Donor Pool
```

You will see **Mbabane donor engagement**. You can send:

- retention messages
- impact messages such as “your donation helped save a life”
- birthday messages
- donation reminders
- general engagement messages

SMS is simulated when selected.

## Donor appointments

Donors can open:

```text
My profile
```

Then use **Book when you are free** to submit an appointment request to an ENBTS blood bank.

## Donor recognition levels

The demo levels are:

```text
0 donations       → New donor
1-4 donations     → Bronze
5-9 donations     → Silver
10+ donations     → Gold
```

Estimated lives impacted is calculated as:

```text
total donations × 3
```

The family-support note is shown as a recognition/ENBTS review flag, not an automatic medical override. Clinical urgency, compatibility, and availability should always remain first.

## Dispatching Mbabane requests

Mbabane Central can now open a blood request detail page and use **Mbabane Central dispatch** to assign:

```text
Mbabane Blood Bank
Manzini Blood Bank
Hlathikhulu Blood Bank
```

So if a Mbabane hospital request should be fulfilled by Mbabane Blood Bank, choose **Mbabane Blood Bank** as the dispatching bank.
