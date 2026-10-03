# Releasing Linkado Laravel

`linkado-ru/laravel` versions come from Git tags; keep `composer.json` versionless. This procedure separates **preparation**, **publication**, and **post-release verification**. For **v2.0.0**, the live/feature defaults require a major upgrade even though existing public signatures remain compatible. The PHP SDK remains v1.0.0; this release does not require a separate SDK release.

## Authorization and SemVer

Read `git status --short`, staged and unstaged diffs, and the user's request before starting. Preserve existing candidate work. Continue through steps already authorized without asking again. Preparation does not authorize staging/committing, pushing, tagging, Packagist mutation or deployment; obtain missing authorization only when the concrete reviewed result is ready for that next action.

- Patch: compatible fixes or documentation/internal changes.
- Minor: additive compatible public capability.
- Major: incompatible requirements, API or behavior, including defaults.

Never rewrite an already published tag or force-push a release reference. Fix a published error with a new version, normally a patch for a compatible correction. If a tag already exists with unexpected annotation/commit, stop and report the conflict instead of moving it. Do not retry an uncertain publication blindly: inspect remote state first.

## 1. Preparation

### Public contract and local checks

Reconcile [README](README.md), [CHANGELOG](CHANGELOG.md), [compatibility](docs/compatibility.md) and the [bundled Boost skill](resources/boost/skills/linkado-development/SKILL.md) against the final code/tests. Keep the changelog's unreleased candidate status until preparing the actual release commit; then replace it with the chosen version and actual release date.

For v2, check live defaults, enabled tracking/customer/billing/refund, disabled SSO, via/60 days, origin versus legacy full API URL, no invalid-setting production fallback, readiness before policy, no HTTP without live credentials, local-only shadow, and pending delivery preservation. Preserve all four migrations, existing event IDs/payloads/hashes/source keys, same-connection transactions, after-commit dispatch and consume-before-claim even with recording disabled. Preserve the two approved nullable tracking getter suppressions and public signatures.

Check tool versions and ensure Node 24 is on PATH for the hosted fixture contracts. Run the existing checks from the package root:

```bash
composer validate --strict
composer test
composer lint:check
git diff --check
git status --short
```

`composer test` already runs PHPStan, Pint check, Rector dry-run, 100% type coverage and parallel Pest. After PHP edits, use `vendor/bin/pint --dirty --format agent`, then recheck the affected tests/full suite. There is no `composer pint` script here. Ordinary tests use synthetic settings, isolated config cache and HTTP fakes; never source real credentials or use production endpoints. `composer build` rebuilds a disposable workbench and is not a deployment command; do not run it against host application data.

### Skills and distribution

Validate all three skills with the skill-creator `quick_validate.py` available in the executing environment. That helper needs PyYAML; if missing, use a temporary venv outside the repo. Check local `agents/openai.yaml` names/descriptions/prompts, required frontmatter, references, and the two relative `.agents/skills` symlinks. Do not add helper-runtime dependencies to Composer or assume global tooling exists.

Inspect an archive of the **actual candidate**, not merely HEAD. For an uncommitted candidate, a temporary index/tree can capture tracked changes and intended new files without touching the real index or creating a commit. Review the new-file list before adding it. For example, in Bash from the package root:

```bash
release_scratch=$(mktemp -d)
release_index="$release_scratch/index"
GIT_INDEX_FILE="$release_index" git read-tree HEAD
GIT_INDEX_FILE="$release_index" git add -u -- .
git ls-files --others --exclude-standard
# Add only reviewed, intended new files to the temporary index, for example:
GIT_INDEX_FILE="$release_index" git add -- .ai/skills .agents/skills AGENTS.md RELEASING.md
# Include other reviewed candidate additions (such as new tests) explicitly.
release_tree=$(GIT_INDEX_FILE="$release_index" git write-tree)
git archive --format=zip --output="$release_scratch/candidate.zip" "$release_tree"
unzip -l "$release_scratch/candidate.zip"
GIT_INDEX_FILE="$release_index" git ls-files -s .agents/skills
```

Require absent `.ai/`, `.agents/`, `AGENTS.md`, tests, workbench, secrets and build/editor artifacts; require present `composer.json`, runtime code/config/routes/views/lang, all four migrations, README, CHANGELOG, `docs/compatibility.md`, RELEASING.md, and `resources/boost/skills/linkado-development/SKILL.md`. Keep the Boost path and frontmatter name `linkado-development`. Local symlink entries must have Git mode `120000`; check they resolve in a disposable source snapshot/checkout. Export exclusions concern dist archives; source installs still contain tracked maintainer files.

Before publication, repeat `git archive` using the exact release commit SHA. Git-hosted dist exclusions are controlled by `.gitattributes`; the actual installed published dist is verified again after publication.

### Candidate consumer and Boost discovery

Use a disposable Laravel 13 application with a disposable database and isolated Composer configuration. A local path/artifact candidate install can prove package discovery, upgrade behavior and archive/Boost contents, but **cannot prove publication through Packagist**. Keep any synthetic candidate version or repository override confined to that temporary consumer/artifact; never add `version` to this package's composer.json.

Verify Laravel package discovery, `php artisan linkado:install`, its publish-only/idempotent behavior, stable migration filenames, the four migrations and local shadow recording. For upgrades, preserve explicit old flags/mode and manually merge published config without `--force`. V1.0 consumers must check the additive identity migration; v1.1 to v2 introduces no fifth migration and must preserve outbox history.

Install a compatible `laravel/boost` dev dependency in the disposable consumer, record its version, inspect command help, and select Codex/package skills through `php artisan boost:install`. Check `linkado-development` is installed with its current instructions and that neither maintainer skill is installed. Verify refresh with `php artisan boost:update`; use `--discover` for newly installed packages when supported by that installed Boost version. Confirm references resolve to the installed package after copying, including a non-default vendor directory. Do not add Boost to this library's dependencies.

### Remote CI gate

Use [.github/workflows/tests.yml](.github/workflows/tests.yml) as the source of truth. Required jobs are:

- Ubuntu SQLite: PHP 8.3/8.4/8.5 × prefer-lowest/prefer-stable, Laravel 13. Each cell runs PHPStan, Pint, type coverage, Rector and Pest, plus both configured query-separator checks. Hosted fixture contracts use Node 24.
- Ubuntu server databases on PHP 8.5: MySQL 8.4, MariaDB 11.4 and PostgreSQL 17, running the database/outbox/delivery/attribution portability suite and real independent-process concurrency.

All nine jobs must succeed for the intended release SHA. SQLite defaults exclude server concurrency; a local green `composer test` does not prove it. Use only disposable test databases for local server runs. Windows is not in the matrix. This workflow runs on PRs and pushes to `main`/`*.x`, not tags; publication must use a SHA already verified on the release branch. Check remote status through available GitHub tools/CLI/UI, not assumptions about previous runs.

Preparation may be reported as locally complete while remote/publication gates are pending. Do not call the package released or all gates passed in that state.

## 2. Publication (only within explicit authorization)

1. Confirm release version/date/notes, intended files and clean release tree. Commit/stage/push only if authorized; do not blindly include unrelated user work. The normal release branch is `main` on the configured `origin` (`https://github.com/linkado-ru/laravel.git`); verify the actual remote before pushing.
2. Verify the release commit is on remote `main` and all nine CI jobs succeeded for that exact commit. Record the full SHA. An earlier branch run or tag creation is not that evidence.
3. Confirm the version tag is unused locally and remotely. Create an annotated tag pointing at that SHA and push only the intended tag, substituting the verified values:

```bash
git tag -a v2.0.0 VERIFIED_RELEASE_SHA -m 'v2.0.0'
git push origin refs/tags/v2.0.0
git ls-remote origin refs/tags/v2.0.0 'refs/tags/v2.0.0^{}'
```

4. Verify both the remote annotated tag and its peeled commit match the intended objects. Check Packagist exposes `linkado-ru/laravel` version `2.0.0` with that source/dist commit. Establish whether automatic update/webhook is configured; do not assume it. If a manual update is needed, perform it only within authorization. If unavailable or mismatched, report publication as incomplete and stop before consumer rollout; never retag to hide a mismatch.

## 3. Post-release verification

Create a fresh disposable Laravel 13 consumer, with no inherited Composer repository overrides/plugins that substitute this package. Check local/global Composer configuration. Require exact `linkado-ru/laravel:2.0.0` with `--prefer-dist` through Packagist. Do not use path/artifact/custom VCS/package repositories, aliases, or provide/replace substitutions as publication evidence.

Inspect `composer.lock` and `vendor/composer/installed.json`: the package version must be 2.0.0 (Composer may display `v2.0.0`); both `source.reference` and `dist.reference` must match the verified peeled release commit. Confirm the installation source is dist and the downloaded package actually has the expected archive contents, including Boost and four migrations. As an independent runtime check:

```php
require 'vendor/autoload.php';
var_dump(
    Composer\InstalledVersions::getPrettyVersion('linkado-ru/laravel'),
    Composer\InstalledVersions::getReference('linkado-ru/laravel'),
    Composer\InstalledVersions::getInstallPath('linkado-ru/laravel'),
);
```

Repeat package discovery/install/migrations and Boost discovery/refresh in this clean consumer. Verify SDK resolves compatibly within `^1.0`; do not make a separate SDK release part of this procedure. Candidate archive tests are not a replacement for these published-install checks.

## Readiness and report

Keep separate evidence for local package checks, exact-SHA Ubuntu CI, archive/local discovery, candidate consumer/Boost, remote tag/Packagist references, and exact published dist installation. Report command, environment, SHA/version and outcome; mark unrun or unavailable gates as pending.

Hosted DNS/TLS, script bytes/loading/CSP/cookies, API/SSO/CORS, program referral/window/scopes and consumer producers/adapters/transaction paths/workers/scheduler/financial acceptance are independent gates. Offline health or local fixture parity cannot prove them. Shadow proves local capture/recording; controlled live acceptance is needed for hosted tracking and SSO. No real API token goes to browser processes.

Publishing the library does not deploy a hosted service or consumer. Before consumer rollout, complete its own acceptance, preserve old configuration explicitly, then rebuild config cache and restart long-running workers through its normal authorized deployment workflow. Disable the affected integration before rollback; never automatically drop migrations, rewrite history or claim older code retains identity lifecycle safety.
