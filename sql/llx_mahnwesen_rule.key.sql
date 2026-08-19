ALTER TABLE llx_mahnwesen_rule ADD UNIQUE INDEX uk_mahnwesen_rule_code (entity, code);
ALTER TABLE llx_mahnwesen_rule ADD INDEX idx_mahnwesen_rule_level (entity, level, enabled);
