-- Separate database for the PHPUnit suite so tests never touch development data.
CREATE DATABASE IF NOT EXISTS digital_wallet_test;