CREATE TABLE llx_mahnwesen_pause (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_case INTEGER NOT NULL,
    fk_facture INTEGER NOT NULL,
    status VARCHAR(32) DEFAULT 'active' NOT NULL,
    pause_until DATETIME,
    reason TEXT,
    date_creation DATETIME NOT NULL,
    date_end DATETIME,
    fk_user_create INTEGER,
    fk_user_end INTEGER,
    tms TIMESTAMP
) ENGINE=innodb;
