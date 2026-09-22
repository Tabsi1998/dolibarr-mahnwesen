CREATE TABLE llx_mahnwesen_profile_match (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_profile INTEGER NOT NULL,
    kind VARCHAR(32) NOT NULL,
    fk_categorie INTEGER DEFAULT 0 NOT NULL,
    customer_type VARCHAR(16) DEFAULT '' NOT NULL,
    date_creation DATETIME,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_create INTEGER
) ENGINE=innodb;
