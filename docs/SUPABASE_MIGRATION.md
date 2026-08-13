# Later migration from MySQL to Supabase

Do this only after the MySQL version is stable and backed up. Supabase uses PostgreSQL, not MySQL, so this is a database migration rather than changing one connection string.

## Recommended migration sequence

1. Freeze schema changes temporarily.
2. Back up MySQL and test restoration.
3. Create a Supabase project and separate staging project.
4. Convert MySQL schema types:
   - `AUTO_INCREMENT` to PostgreSQL identity columns;
   - MySQL `ENUM` values to PostgreSQL enums or checked text columns;
   - `TINYINT(1)` to `boolean`;
   - `ON UPDATE CURRENT_TIMESTAMP` to triggers or application updates;
   - MySQL `ON DUPLICATE KEY UPDATE` queries to PostgreSQL `ON CONFLICT`.
5. Migrate data using the official Supabase MySQL migration guide.
6. Recreate views, indexes and foreign keys.
7. Decide whether PHP remains the only trusted API or whether selected tables will use Supabase APIs.
8. If the browser queries Supabase directly, enable Row Level Security before exposing tables.
9. Update repository queries and run the full workflow test suite.
10. Compare counts, hashes, donation history, campaign metrics and audit records before cutover.

Official references:

- https://supabase.com/docs/guides/platform/migrating-to-supabase/mysql
- https://supabase.com/docs/guides/database/overview
- https://supabase.com/docs/guides/deployment/database-migrations

Do not expose the national-ID encryption key to React or Supabase client-side code. Identity encryption and decryption must remain in a trusted server environment.
