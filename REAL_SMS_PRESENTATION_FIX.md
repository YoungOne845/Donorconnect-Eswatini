# DonorConnect Real SMS Presentation Fix

This version is rebuilt from the original DonorConnect ZIP, not from the broken realtime/push demo.

## What was restored

- The original PHP/XAMPP backend remains the backend.
- The original React frontend remains the frontend.
- The home/landing page is restored at `/`.
- The Socket.io/PWA/SMS receiver demo mode is removed from this version.
- Donor registration is left in its original structure and should no longer show unrelated realtime-demo errors.

## What was added

### 1. Real emergency SMS from the blood request form

Go to:

```text
Requests -> New request
```

There is a checkbox:

```text
Send a real emergency SMS to the demo donor phone now
```

When checked, enter a real Eswatini phone number such as:

```text
76123456
```

or:

```text
+26876123456
```

When the request is created, the PHP backend sends the SMS through the configured provider.

### 2. Real retention SMS wording

The donor engagement section now says real SMS instead of SMS simulation. It uses the existing `/donors/messages` backend endpoint and the existing `SmsService`.

### 3. Real SMS test endpoint

A backend endpoint was added:

```http
POST /sms/test
```

It is available to logged-in hospital, staff, and admin users.

## Configure real SMS (host-only)

Do NOT commit SMS provider credentials to the repository. To enable real SMS delivery, set the following environment variables on your hosting server or in your deployment platform's secret manager:

```env
# SMS_DRIVER=twilio
# TWILIO_ACCOUNT_SID=
# TWILIO_AUTH_TOKEN=
# TWILIO_FROM_NUMBER=
```

After setting these on the host, restart Apache (or your PHP process) so the new environment variables are picked up.

## 2-hour presentation demo flow

1. Start XAMPP.
2. Start Apache and MySQL.
3. Open the frontend:

```bash
cd frontend
npm run dev -- --host 0.0.0.0
```

4. Log in as hospital/admin.
5. Go to `Requests`.
6. Click `New request`.
7. Fill:

```text
Blood type: O-
Units: 2
Urgency: critical
Hospital: Mbabane Government Hospital
Region: Hhohho
Town: Mbabane
```

8. Tick:

```text
Send a real emergency SMS to the demo donor phone now
```

9. Enter the lecturer/demo phone number.
10. Click `Create request`.
11. The phone should receive the real SMS if Twilio is configured and the recipient is allowed by the Twilio account.

## If SMS fails

Check:

```text
api/storage/logs/sms.log
api/storage/logs/app.log
```

Common causes:

- `SMS_DRIVER` is still set to `log` instead of `twilio`.
- Twilio credentials are missing or wrong.
- The `TWILIO_FROM_NUMBER` is not SMS-enabled.
- On a Twilio trial account, the recipient phone number must be verified first.
- The phone number format must be `76123456` or `+26876123456`.
- XAMPP/PHP cURL extension is disabled.

## Files changed

```text
frontend/src/App.jsx
frontend/src/pages/RequestsPage.jsx
frontend/src/pages/DonorsPage.jsx
api/routes/api.php
api/src/Controllers/NotificationController.php
api/src/Controllers/RequestController.php
REAL_SMS_PRESENTATION_FIX.md
```

