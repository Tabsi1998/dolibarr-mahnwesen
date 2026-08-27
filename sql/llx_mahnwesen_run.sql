CREATE TABLE llx_mahnwesen_run (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    mode VARCHAR(32) DEFAULT 'cron' NOT NULL,
    status VARCHAR(32) DEFAULT 'running' NOT NULL,
    run_lock VARCHAR(64),
    started_at DATETIME NOT NULL,
    finished_at DATETIME,
    scanned INTEGER DEFAULT 0 NOT NULL,
    synchronized INTEGER DEFAULT 0 NOT NULL,
    attempted INTEGER DEFAULT 0 NOT NULL,
    sent INTEGER DEFAULT 0 NOT NULL,
    skipped INTEGER DEFAULT 0 NOT NULL,
    failed INTEGER DEFAULT 0 NOT NULL,
    summary TEXT,
    fk_user INTEGER
) ENGINE=innodb;
