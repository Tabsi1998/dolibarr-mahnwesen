CREATE TABLE llx_mahnwesen_fee (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_case INTEGER NOT NULL,
    fk_facture INTEGER NOT NULL,
    fk_attempt INTEGER NOT NULL,
    level INTEGER NOT NULL,
    kind VARCHAR(16) DEFAULT 'fee' NOT NULL,
    amount DOUBLE(24,8) DEFAULT 0 NOT NULL,
    currency_code VARCHAR(3) NOT NULL,
    status VARCHAR(32) DEFAULT 'open' NOT NULL,
    date_creation DATETIME NOT NULL,
    date_settlement DATETIME,
    settlement_reason TEXT,
    fk_user_create INTEGER,
    fk_user_settlement INTEGER,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
