-- Migration: Add display_name to users and lock verified profile fields
-- Run this against the donorconnect database.

USE donorconnect;

ALTER TABLE users
    ADD COLUMN display_name VARCHAR(100) NULL AFTER full_name;
