CREATE TABLE llx_mahnwesen_case (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_facture INTEGER NOT NULL,
    current_level INTEGER DEFAULT 0 NOT NULL,
    paused INTEGER DEFAULT 0 NOT NULL,
    status VARCHAR(32) DEFAULT 'open' NOT NULL,
    remaining_amount DOUBLE(24,8) DEFAULT 0 NOT NULL,
    last_notice_at DATETIME,
    next_action_at DATETIME,
    note_private TEXT,
    date_creation DATETIME,
    tms TIMESTAMP,
    fk_user_create INTEGER,
    fk_user_modif INTEGER
) ENGINE=innodb;
