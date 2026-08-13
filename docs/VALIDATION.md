# Build validation

The generated handoff was checked before packaging:

- All PHP files passed `php -l` syntax validation under PHP 8.4.
- The React application completed a Vite 8 production build.
- Route-level code splitting produced separate page bundles.
- `npm audit --omit=dev --audit-level=high` reported zero known vulnerabilities at packaging time.
- No real `.env` file or production secret is included in the ZIP.
- `node_modules` is excluded; restore dependencies with `npm install` or `npm ci`.

A live MySQL integration test was not run inside the packaging container because it did not provide a MySQL/MariaDB server. Run `api/scripts/self_check.php` after importing the schema into XAMPP; it verifies the actual local database and required PHP extensions.
