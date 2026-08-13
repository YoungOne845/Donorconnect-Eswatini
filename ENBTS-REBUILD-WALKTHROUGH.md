# DonorConnect ENBTS Rebuild Walkthrough

This rebuild keeps the original React/Vite + PHP/MySQL design, but changes the system logic to match the ENBTS operating model:

- **Mbabane Blood Bank** is the only **Central ENBTS Admin**.
- **Manzini Blood Bank** and **Hlathikhulu Blood Bank** are branch operators/staff.
- Hospitals submit blood requests into the system.
- Mbabane Central reviews requests, checks stock, assigns dispatches, and controls donor messaging.
- Branches update local inventory, submit campaign requests, and handle assigned dispatches.

---

## 1. Unzip the project

Extract the ZIP into your XAMPP `htdocs` folder:

```text
C:\xampp\htdocs\donorconnect
```

Your folder should look like this:

```text
donorconnect/
├── api/
├── database/
├── frontend/
├── docs/
└── ENBTS-REBUILD-WALKTHROUGH.md
```

---

## 2. Create a fresh database

Open **phpMyAdmin** and import:

```text
database/schema.sql
```

This creates the database named:

```text
donorconnect
```

The schema now includes ENBTS-specific tables for:

```text
blood_inventory
dispatch_assignments
branch_campaign_requests
appointment_requests
system_settings
```

The `system_settings` table is included, so the previous missing-table error is gone.

---

## 3. Check your API environment file

Open:

```text
api/.env
```

For normal XAMPP setup, keep this:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=donorconnect
DB_USER=root
DB_PASSWORD=
FRONTEND_URL=http://localhost:5173
API_BASE_PATH=/donorconnect/api
```

If your MySQL root user has a password, put it in `DB_PASSWORD`.

---

## 4. Seed the official ENBTS demo accounts

Open PowerShell or CMD and run:

```powershell
cd C:\xampp\htdocs\donorconnect
php api\scripts\seed_enbts_demo.php
```

This creates the three ENBTS blood banks, a demo hospital, starter blood stock, and the login accounts.

---

## 5. Login credentials

Use the login page with **National ID + Password**.

| User | Role | National ID | Password |
|---|---|---:|---|
| Mbabane Central Admin | Central admin | `9001011234567` | `Mbabane@2026` |
| Manzini Branch Operator | Branch staff | `9102021234567` | `Manzini@2026` |
| Hlathikhulu Branch Operator | Branch staff | `9203031234567` | `Hlathi@2026` |
| Nhlangano Hospital Blood Desk | Hospital staff | `9304041234567` | `Hospital@2026` |

Important: the seed script intentionally converts any old admin accounts into staff accounts first, then creates exactly one central admin: **Mbabane Central Admin**.

---

## 6. Start the frontend

Open a terminal:

```powershell
cd C:\xampp\htdocs\donorconnect\frontend
npm install
npm run dev
```

Open:

```text
http://localhost:5173
```

---

## 7. System test flow

### A. Hospital creates a blood request

Login as:

```text
Nhlangano Hospital Blood Desk
```

Go to:

```text
Blood requests → New request
```

Create a request, for example:

```text
Blood type: O+
Units: 4
Urgency: high
Hospital: Nhlangano Hospital
Region: Shiselweni
Town: Nhlangano
```

### B. Mbabane Central assigns dispatch

Login as:

```text
Mbabane Central Admin
```

Go to:

```text
Inventory
```

Check stock across Mbabane, Manzini, and Hlathikhulu.

Then go to:

```text
Dispatches → Assign dispatch
```

Select the hospital request and assign it to the nearest suitable blood bank with enough stock, for example:

```text
Hlathikhulu Blood Bank
```

### C. Branch handles dispatch

Login as:

```text
Hlathikhulu Branch Operator
```

Go to:

```text
Dispatches
```

Update the dispatch status through:

```text
assigned → accepted → packed → in_transit → delivered
```

When status becomes `delivered`, the system automatically:

- reduces that branch’s available blood inventory;
- updates the hospital request fulfilment count;
- marks the hospital request as partially fulfilled or fulfilled.

### D. Branch requests campaign approval

Login as Manzini or Hlathikhulu branch operator.

Go to:

```text
Campaign requests
```

Submit a campaign request.

Then login as Mbabane Central Admin and approve it. Approval converts the branch request into a scheduled campaign controlled by Mbabane.

---

## 8. What each user can do

### Mbabane Central Admin

- View national donor pool.
- View all hospital blood requests.
- View inventory across all three ENBTS blood banks.
- Assign dispatches to Mbabane, Manzini, or Hlathikhulu.
- Approve/reject branch campaign requests.
- Create and invite donors to approved campaigns.
- Manage users and institutions.
- View reports and analytics.

### Manzini and Hlathikhulu Branch Operators

- Add and manage donors.
- Update their own blood bank inventory.
- View assigned dispatches.
- Update dispatch status.
- Submit campaign requests to Mbabane Central.
- View local operational reports.

They cannot:

- act as central admin;
- approve national hospital requests;
- assign another blood bank to dispatch;
- blast donor SMS/campaign invites directly.

### Hospital Staff

- Create hospital blood requests.
- Track request status.
- View dispatch progress related to their requests.

### Donors

- Register using national ID.
- Log in using national ID and password in this demo build.
- View profile, activity, campaigns, and notifications.

---

## 9. Files added or changed

Key files changed:

```text
api/src/Controllers/ENBTSController.php
api/routes/api.php
api/scripts/seed_enbts_demo.php
database/schema.sql
frontend/src/App.jsx
frontend/src/components/AppLayout.jsx
frontend/src/pages/InventoryPage.jsx
frontend/src/pages/DispatchesPage.jsx
frontend/src/pages/CampaignRequestsPage.jsx
frontend/src/pages/CampaignsPage.jsx
frontend/src/pages/RequestDetailPage.jsx
frontend/src/styles/index.css
```

---

## 10. Troubleshooting

### `Could not open input file`

Make sure you are inside the correct project folder before running PHP scripts:

```powershell
cd C:\xampp\htdocs\donorconnect
php api\scripts\seed_enbts_demo.php
```

### Database connection failed

Check:

```text
api/.env
```

Make sure `DB_NAME`, `DB_USER`, and `DB_PASSWORD` match your XAMPP/MySQL setup.

### Frontend cannot reach API

Check:

```text
frontend/.env
```

It should point to:

```env
VITE_API_BASE_URL=http://localhost/donorconnect/api
```

Also make sure Apache and MySQL are running in XAMPP.
