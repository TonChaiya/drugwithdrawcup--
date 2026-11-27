CREATE TABLE IF NOT EXISTS withdrawal_save_receipts (
    token_hash BINARY(32) NOT NULL PRIMARY KEY,
    attempt BINARY(16) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    account_host VARBINARY(32) NOT NULL,
    form_context VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    KEY withdrawal_save_receipts_expiration (expires_at)
) ENGINE=InnoDB;
