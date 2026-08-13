-- Add optional secondary phone column to users (used by UI and controllers)
ALTER TABLE users
  ADD COLUMN phone_secondary VARCHAR(20) NULL AFTER phone;
