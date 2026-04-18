-- Ensure the database is created with the right charset/collation.
-- The MYSQL_DATABASE env var on first boot creates the schema with server
-- defaults; this file just guarantees collation if a custom DB exists.
ALTER DATABASE studio_console CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
