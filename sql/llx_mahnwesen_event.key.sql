ALTER TABLE llx_mahnwesen_event ADD UNIQUE INDEX uk_mahnwesen_event_id (entity, event_id);
ALTER TABLE llx_mahnwesen_event ADD INDEX idx_mahnwesen_event_status (entity, status, attempts);
