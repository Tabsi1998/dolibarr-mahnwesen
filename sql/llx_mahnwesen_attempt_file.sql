CREATE TABLE llx_mahnwesen_attempt_file (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_attempt INTEGER NOT NULL,
    file_role VARCHAR(32) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    snapshot_path TEXT NOT NULL,
    sha256 VARCHAR(64) NOT NULL,
    mime_type VARCHAR(128),
    size_bytes INTEGER DEFAULT 0 NOT NULL,
    date_creation DATETIME NOT NULL
) ENGINE=innodb;
