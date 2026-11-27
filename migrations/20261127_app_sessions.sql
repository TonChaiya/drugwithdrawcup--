CREATE TABLE IF NOT EXISTS app_sessions (
    id_hash BINARY(32) NOT NULL PRIMARY KEY,
    payload MEDIUMBLOB NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    KEY app_sessions_expiration (expires_at)
) ENGINE=InnoDB;
