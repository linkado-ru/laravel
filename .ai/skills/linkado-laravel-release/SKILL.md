---
name: linkado-laravel-release
description: Prepare and verify linkado-ru/laravel Composer releases, including SemVer, release documentation, package checks, distribution archives, authorized Git tags and Packagist publication, and exact published-version verification. Consumer or hosted-server deployment is separate.
---

# Linkado Laravel Release

Read Git status, the current diff, and [RELEASING.md](../../../RELEASING.md) first. That document is the single release procedure; follow its preparation, publication, or post-release verification stage according to the user's request and existing authorization.

## Release contract

- Keep `composer.json` versionless. Versions come from annotated `vX.Y.Z` Git tags. Assess behavior and requirements as well as signatures: the live/feature defaults make the current candidate `v2.0.0` major.
- Preserve existing candidate work, all four migrations, outbox history and official SDK DTO/transport semantics. SDK v1.0.0 needs no separate release.
- Reconcile [README](../../../README.md), [CHANGELOG](../../../CHANGELOG.md), [compatibility](../../../docs/compatibility.md) and the [Boost skill](../../../resources/boost/skills/linkado-development/SKILL.md) with the final public contract.
- Complete the authorized stage without requesting confirmation again for already authorized steps. Preparation alone does not authorize staging/commit, push, tag, Packagist mutation or deployment. If the next required action lies outside authorization, finish the reviewable preparation and identify the specific pending action.
- Never rewrite a published tag or force-push a release reference. Correct an already published release with a new version. Stop on unexpected tag/reference conflicts rather than retrying publication blindly.

## Evidence and handoff

Run the real Composer checks documented in RELEASING.md; do not substitute SDK script names. Verify discovery and archive contents for the actual candidate, then the exact release commit. Local checks or path/artifact installs do not prove the Ubuntu CI matrix or Packagist delivery.

For publication, require the intended release commit on `main` and successful full remote CI for that exact SHA. Confirm remote tag dereferencing and Packagist source/dist references. After publication, verify the exact version in a clean Laravel 13 consumer without repository overrides; do not substitute a local candidate install.

Report local checks, remote CI, archive/discovery, and published installation separately, marking unavailable evidence as pending. Keep package readiness distinct from hosted DNS/TLS/assets/API/SSO/CORS, program settings/scopes, and consumer producers/adapters/workers/browser/financial acceptance. Config-cache rebuild, worker restart and deployment belong to the application's procedure.
