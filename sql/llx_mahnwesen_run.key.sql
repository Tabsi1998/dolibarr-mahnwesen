ALTER TABLE llx_mahnwesen_run ADD INDEX idx_mahnwesen_run_date (entity, started_at);
ALTER TABLE llx_mahnwesen_run ADD INDEX idx_mahnwesen_run_status (entity, status);
ALTER TABLE llx_mahnwesen_run ADD UNIQUE INDEX uk_mahnwesen_run_lock (entity, run_lock);
