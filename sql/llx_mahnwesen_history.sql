CREATE TABLE llx_mahnwesen_history (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_case INTEGER,
    fk_facture INTEGER NOT NULL,
    action VARCHAR(64) NOT NULL,
    level INTEGER DEFAULT 0 NOT NULL,
    amount_snapshot DOUBLE(24,8) DEFAULT 0 NOT NULL,
    mode VARCHAR(32) DEFAULT 'simulation' NOT NULL,
    result VARCHAR(32),
    recipient VARCHAR(255),
    message TEXT,
    date_creation DATETIME NOT NULL,
    fk_user_create INTEGER
) ENGINE=innodb;
