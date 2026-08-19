CREATE TABLE llx_mahnwesen_rule (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(255) NOT NULL,
    level INTEGER NOT NULL,
    days_after_due INTEGER NOT NULL,
    minimum_amount DOUBLE(24,8) DEFAULT 0 NOT NULL,
    fee_amount DOUBLE(24,8) DEFAULT 0 NOT NULL,
    interest_rate DOUBLE(24,8) DEFAULT 0 NOT NULL,
    send_email INTEGER DEFAULT 0 NOT NULL,
    generate_pdf INTEGER DEFAULT 0 NOT NULL,
    email_template VARCHAR(128),
    enabled INTEGER DEFAULT 1 NOT NULL,
    date_creation DATETIME,
    tms TIMESTAMP,
    fk_user_create INTEGER,
    fk_user_modif INTEGER
) ENGINE=innodb;
