ALTER TABLE llx_mahnwesen_rule ADD UNIQUE INDEX uk_mahnwesen_rule_profile (entity, fk_profile, level);
ALTER TABLE llx_mahnwesen_rule ADD INDEX idx_mahnwesen_rule_level (entity, level, enabled);
