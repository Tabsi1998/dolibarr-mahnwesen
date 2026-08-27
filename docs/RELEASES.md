# Release and branch strategy

The repository separates stable source from test builds.

## Branches

- `main`: stable/release-ready source only
- `feature/*`: focused development work and pull requests
- optional `develop`: integration branch when several features run in parallel

## Version tags

- Stable release: `v1.0.0`
- Test/pre-release examples: `v1.1.0-rc.1`, `v1.1.0-beta.1`

The Dolibarr installer package itself keeps the installer-compatible name `mahnwesen-x.y.z.zip`. A pre-release tag may therefore contain an RC suffix while the attached installable ZIP uses the semantic module version from `modMahnwesen.class.php`.

## GitHub Actions

- Every push/PR runs PHP lint, language-key checks, invoice-write guard and Dolibarr 21/22/23 API compatibility checks.
- Every push/PR also builds an installable module ZIP and SHA-256 checksum as CI artifacts.
- Tags matching `v*` build and publish a GitHub Release with ZIP and checksum. Tags containing `-` are marked as pre-releases.

## Runtime verification

A release is only described as runtime-verified for a Dolibarr major after an actual installation/smoke test. Current runtime reference is Dolibarr 22.
