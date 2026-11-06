-- Bitcoin 200-week moving average — schema
--
-- One row per calendar day. The moving average is derived, not stored, so a
-- change to the window length never requires a backfill.

CREATE DATABASE IF NOT EXISTS btc_200wma
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE btc_200wma;

CREATE TABLE IF NOT EXISTS prices (
    price_date DATE           NOT NULL,
    usd        DECIMAL(20, 8) NOT NULL,
    source     VARCHAR(40)    NOT NULL DEFAULT 'blockchain.info',
    updated_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (price_date),
    KEY idx_usd (usd)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- Audit trail for imports: when, how many rows, and whether it worked.
CREATE TABLE IF NOT EXISTS import_runs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at  DATETIME     NOT NULL,
    finished_at DATETIME     NULL,
    rows_seen   INT UNSIGNED NOT NULL DEFAULT 0,
    rows_stored INT UNSIGNED NOT NULL DEFAULT 0,
    status      VARCHAR(20)  NOT NULL DEFAULT 'running',
    message     TEXT         NULL,
    PRIMARY KEY (id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
