-- Eigene Datenbank für die Tests (./dev.sh test), damit die Entwicklungsdaten erhalten bleiben
CREATE DATABASE IF NOT EXISTS musicwiz_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON musicwiz_test.* TO 'musicwiz'@'%';
