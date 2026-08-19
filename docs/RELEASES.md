# Release and branch strategy

The repository separates stable source from test builds.

## Branches

- `main`: stable/release-ready source only
- `feature/*`: focused development work and pull requests
- optional `develop`: integration branch when several features run in parallel

## Version tags

- Stable release examples: `v0.5.1`, later `v0.5.2` after runtime approval
- Test/pre-release examples: `v0.5.2-rc.1`, `v0.5.2-beta.1`

The Dolibarr installer package itself keeps the installer-compatible name `mahnwesen-x.y.z.zip`. A pre-release tag may therefore contain an RC suffix while the attached installable ZIP uses the semantic module version from `modMahnwesen.class.php`.

## GitHub Actions

- Every push/PR runs PHP lint, language-key checks, invoice-write guard and Dolibarr 21/22/23 API compatibility checks.
- Every push/PR also builds an installable module ZIP as a CI artifact.
- Tags matching `v*` build and publish a GitHub Release. Tags containing `-` are marked as pre-releases.

## Runtime verification

A release is only described as runtime-verified for a Dolibarr major after an actual installation/smoke test. Current runtime reference is Dolibarr 22.
