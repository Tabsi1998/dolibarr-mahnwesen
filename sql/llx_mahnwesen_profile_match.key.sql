ALTER TABLE llx_mahnwesen_profile_match ADD UNIQUE INDEX uk_mahnwesen_profile_match (entity, kind, fk_categorie, customer_type);
ALTER TABLE llx_mahnwesen_profile_match ADD INDEX idx_mahnwesen_profile_match_profile (fk_profile);
