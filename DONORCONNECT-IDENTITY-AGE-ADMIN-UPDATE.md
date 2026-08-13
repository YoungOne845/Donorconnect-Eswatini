# DonorConnect Identity, Minimum-Age and Administration Update

This update changes DonorConnect so that:

- a 13-digit national ID is the main login identifier for every account;
- the first six ID digits are interpreted as `YYMMDD`;
- donor date of birth is calculated by the backend instead of being typed manually;
- people younger than 16 are blocked with the exact date on which they can register;
- administrators can delete unused user accounts;
- administrators can delete institutions that no longer have linked users;
- towns and localities use region-based dropdowns;
- sensitive national IDs remain encrypted, searchable only by a keyed hash, and masked in the interface.

## Important database design decision

The raw national ID is **not** used as the physical MySQL primary key. The existing numeric `users.id` remains the internal relational key because it is safe for foreign keys and does not expose personal identity information throughout the database.

The national ID is still the main user identity because:

- `national_id_hash` is unique and required;
- login now accepts only national ID and password;
- duplicate national IDs are rejected;
- all new donor, hospital, staff and administrator accounts require a national ID;
- the encrypted national ID can be recovered only by authorised backend code.

## Files included in the update

- `api/src/Core/Identity.php`
- `api/src/Controllers/AuthController.php`
- `api/src/Controllers/AdminController.php`
- `api/src/Controllers/SetupController.php`
- `api/routes/api.php`
- `api/scripts/create_admin.php`
- `api/scripts/assign_national_id.php`
- `database/schema.sql`
- `database/migrations/2026_06_12_national_id_age_and_admin_deletion.sql`
- `frontend/src/data/eswatini.js`
- `frontend/src/utils/identity.js`
- `frontend/src/pages/RegisterPage.jsx`
- `frontend/src/pages/LoginPage.jsx`
- `frontend/src/pages/UsersPage.jsx`
- `frontend/src/pages/InstitutionsPage.jsx`
- `frontend/src/styles/identity-admin.css`
- `frontend/src/main.jsx`

---

# Installation walkthrough

## 1. Back up the current project and database

Stop the Vite development server with `Ctrl + C`.

Copy:

```text
C:\xampp\htdocs\donorconnect
```

to:

```text
C:\xampp\htdocs\donorconnect-before-identity-update
```

In phpMyAdmin:

1. Select the `donorconnect` database.
2. Select **Export**.
3. Select **Quick** and **SQL**.
4. Download the backup.

## 2. Extract the update

Extract the update ZIP directly into:

```text
C:\xampp\htdocs\donorconnect
```

Choose **Replace the files in the destination** when Windows asks.

Do not create a nested path such as:

```text
C:\xampp\htdocs\donorconnect\DonorConnect-Identity-Age-Admin-Update
```

## 3. Find accounts that do not yet have a national ID

Open phpMyAdmin, select `donorconnect`, open the SQL tab, and run:

```sql
SELECT
    id,
    full_name,
    role,
    email,
    phone,
    national_id_last_four
FROM users
WHERE national_id_encrypted IS NULL
   OR national_id_hash IS NULL
   OR national_id_last_four IS NULL;
```

Your first administrator will normally appear because it was created before national-ID-only login was introduced.

## 4. Assign a national ID to the existing administrator

From the DonorConnect project root, run:

```powershell
C:\xampp\php\php.exe api\scripts\assign_national_id.php --user-id=1 --national-id=YOUR_REAL_13_DIGIT_ID
```

Replace `1` with the internal user ID returned by the SQL query and replace the national ID with that account holder's real ID.

Repeat the command for every existing account returned by the query.

The script encrypts the ID, creates its keyed search hash and stores only the final four digits for display.

## 5. Run the migration

Run the missing-ID query again. It must return zero rows.

Then open:

```text
database/migrations/2026_06_12_national_id_age_and_admin_deletion.sql
```

Copy the complete SQL file into the phpMyAdmin SQL tab and run it.

The result named `users_missing_national_id` must be `0`.

The migration makes all protected national-ID fields mandatory for every account.

Do **not** import `database/schema.sql` into the current database. That file is for a new installation and begins by recreating the database.

## 6. Restart the services

In XAMPP:

1. Stop Apache.
2. Start Apache.
3. Keep MySQL running.

Then restart Vite:

```powershell
cd C:\xampp\htdocs\donorconnect\frontend
npm run dev
```

Open:

```text
http://localhost:5173
```

Hard refresh with `Ctrl + F5`.

## 7. Sign in with national ID

Email and phone login have been removed.

Use:

```text
National ID: the ID assigned to the administrator
Password: the administrator password already created
```

## 8. Test the age calculation

### Adult test

Use a valid-looking ID beginning with:

```text
041222
```

The interface should calculate:

```text
22 December 2004
```

and allow the user to continue because the person is older than 16.

### Under-age test

Use:

```text
1501017100041
```

The birth date should be calculated as:

```text
1 January 2015
```

The interface and API should refuse registration and state that registration opens on:

```text
1 January 2031
```

### Exactly-16 test

Use:

```text
1001017100041
```

The birth date should be calculated as:

```text
1 January 2010
```

During 2026, this user is 16 and registration should be allowed.

These IDs are testing values only. Do not use them as real accounts in a live database.

## 9. Confirm that birth date cannot be manipulated

The public registration page no longer contains a date-of-birth input.

The backend always calculates the date from the first six national-ID digits. Even if someone manually sends a different `date_of_birth` through a developer tool, it is ignored.

## 10. Test operational account creation

Sign in as administrator and open **User accounts**.

Create a blood-service staff, hospital or administrator account.

The form now requires:

- full name;
- national ID;
- role;
- phone;
- institution where required;
- temporary password.

Hospital accounts can only be attached to institutions of type `hospital`.

Blood-service staff can only be attached to institutions of type `blood_service`.

The new user signs in with national ID and password.

## 11. Test user deletion

Create a temporary unused account.

In **User accounts**, select the red delete icon and confirm deletion.

Deletion succeeds only when the account has no protected operational history.

Accounts with any of the following are protected:

- donations;
- eligibility assessments;
- donor deferrals;
- blood-request history;
- request matches;
- campaign participation;
- campaigns created;
- staff-recorded activity.

Protected accounts must be set to **Inactive** instead. This preserves audit and blood-service history.

The currently signed-in administrator cannot delete their own account, and the last active administrator cannot be deleted.

## 12. Test institution deletion

Open **Institutions**.

Create a temporary institution with no linked users, then select its delete icon.

The delete should succeed.

An institution with linked user accounts cannot be deleted until those accounts are reassigned or deleted. Campaign, donation and blood-request history remains preserved if an unused institution is removed.

## 13. Test dropdown locations

The registration and institution forms now use:

- region dropdown;
- town/locality dropdown based on the selected region;
- an **Other / rural locality** option that reveals a manual locality field.

Existing dropdowns remain in place for:

- gender;
- blood type;
- availability;
- recruitment source;
- preferred contact method;
- account role;
- institution type;
- account status.

## 14. Run final checks

Run PHP syntax checks through the existing self-check:

```powershell
C:\xampp\php\php.exe api\scripts\self_check.php
```

Build the React frontend:

```powershell
cd C:\xampp\htdocs\donorconnect\frontend
npm run build
```

Both commands should complete without errors.

---

# Expected final behaviour

- Donor enters a 13-digit national ID.
- The application extracts `YYMMDD` from its first six digits.
- The interface displays the calculated birth date and age.
- The backend independently repeats the calculation.
- Under-16 registration is rejected with the exact sixteenth birthday.
- At 16 or older, registration proceeds.
- The calculated date is stored in `donor_profiles.date_of_birth`.
- The national ID becomes the only login identifier.
- Duplicate IDs cannot create multiple accounts.
- Administrators can delete unused accounts and institutions safely.
- Operational history cannot be casually destroyed.
