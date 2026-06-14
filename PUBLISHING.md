# Publishing `monoverse/voicebot-laravel-sync`

This package develops in the VoiceBot monorepo at `packages/laravel-sync/` and ships to
**public Packagist** as `monoverse/voicebot-laravel-sync`. Distribution is a read-only
**subtree split** of this directory into a standalone mirror repository; Packagist indexes
the mirror's version tags.

- **Mirror repository:** `Monoverse1/voicebot-laravel-sync` (the value of the
  `LARAVEL_SYNC_SPLIT_REPO` repo variable; override there if it ever moves).
- **Automation:** `.github/workflows/publish-laravel-sync.yml`, triggered by a monorepo
  tag `laravel-sync-v*` (or a manual `workflow_dispatch`).
- **v0.1.0 was seeded manually** (`git subtree split` → pushed `main` → tagged `v0.1.0`)
  and is **already live on the mirror**. Every release from `v0.1.1` onward goes through
  the automated workflow below — do not hand-split again.

The decision and trade-offs are recorded in
[ADR-057](../../docs/shared/adr/ADR-057_laravel_package_publish_pipeline.md).

---

## A. One-time setup (do once, by a maintainer)

### 1. GitHub secrets and variables (monorepo)

Create these in the monorepo's **Settings → Secrets and variables → Actions**:

| Kind | Name | Required | Value |
|------|------|----------|-------|
| Variable | `LARAVEL_SYNC_SPLIT_REPO` | recommended | `Monoverse1/voicebot-laravel-sync` (workflow defaults to this if unset) |
| Secret | `LARAVEL_SYNC_SPLIT_TOKEN` | **yes** | A PAT / fine-grained token with **`contents: write`** scoped to the mirror repo only — the workflow uses it to push the split branch + tag |
| Variable | `PACKAGIST_USER` | optional | Your Packagist username (enables the force-update API step) |
| Secret | `PACKAGIST_TOKEN` | optional | Your Packagist API token (enables the force-update API step) |

Notes:

- `LARAVEL_SYNC_SPLIT_TOKEN` should be **scoped to the mirror repo only** (least
  privilege). A classic PAT needs `repo` (or `public_repo` for a public mirror); a
  fine-grained token needs `Contents: Read and write` on `Monoverse1/voicebot-laravel-sync`.
- `PACKAGIST_TOKEN` / `PACKAGIST_USER` are **optional**. If you install the Packagist
  GitHub App (step 3), auto-update happens via webhook and these are not needed — the
  workflow's force-update step **skips cleanly** when they are absent.

### 2. Submit the mirror to Packagist (claims the `monoverse` vendor)

1. Sign in at <https://packagist.org>.
2. Go to <https://packagist.org/packages/submit>.
3. Submit the **mirror** repo URL: `https://github.com/Monoverse1/voicebot-laravel-sync`.
4. Packagist reads the root `composer.json` (name `monoverse/voicebot-laravel-sync`) and
   creates the package, claiming the `monoverse` vendor namespace for your account.

Because v0.1.0 is already tagged on the mirror, Packagist publishes `0.1.0` immediately on
submission.

### 3. Install the Packagist GitHub App (auto-update)

So new mirror tags publish without manual pings:

1. Install the **Packagist** GitHub App: <https://github.com/apps/packagist>.
2. Grant it access to the **mirror** repo `Monoverse1/voicebot-laravel-sync`.

With the App installed, every tag the workflow pushes to the mirror triggers a Packagist
update via webhook. The optional `PACKAGIST_TOKEN`/`PACKAGIST_USER` force-update step is
then redundant (but harmless).

---

## B. Cut a release (every version after 0.1.0)

Releases are **tag-driven**. Tag the **monorepo**; the workflow mirrors the split + tag.

1. **Bump the version in two places (same PR):**
   - `packages/laravel-sync/CHANGELOG.md` — add a dated section for the new version.
   - `packages/laravel-sync/src/Protocol/Protocol.php` — bump
     `Protocol::CLIENT_VERSION` to match (it ships on the wire as
     `X-VoiceBot-Plugin-Version: laravel-sync/X.Y.Z`).

   Keep all three in sync: `composer.json` has no hardcoded version (Packagist derives it
   from the tag), so the CHANGELOG entry, `CLIENT_VERSION`, and the tag must agree.

2. **Merge the PR to `main`.**

3. **Tag the monorepo and push the tag:**

   ```bash
   git tag laravel-sync-vX.Y.Z
   git push origin laravel-sync-vX.Y.Z
   ```

   Example: a `1.2.0` release →

   ```bash
   git tag laravel-sync-v1.2.0
   git push origin laravel-sync-v1.2.0
   ```

4. **The workflow runs automatically.** It strips the `laravel-sync-` prefix
   (`laravel-sync-v1.2.0` → mirror tag `v1.2.0`), subtree-splits
   `packages/laravel-sync/` into the mirror, and pushes the `v1.2.0` tag there.

5. **Packagist picks it up** within a minute (via the GitHub App webhook, and/or the
   optional force-update step). Confirm at
   <https://packagist.org/packages/monoverse/voicebot-laravel-sync>.

### Manual run (re-publish / recover)

If you need to (re)publish a version without a fresh git tag, run the workflow manually:
**Actions → publish-laravel-sync → Run workflow**, and enter the version as `X.Y.Z`
(no `v`, no `laravel-sync-` prefix). Re-running an existing version is **idempotent** — it
re-pushes the same split and re-points the same mirror tag.

---

## C. Client install line

Merchants install the published package straight from Packagist:

```bash
composer require monoverse/voicebot-laravel-sync
```

Then publish config, migrate, and pair — see this package's `README.md`.

---

## D. How the version mapping works

| Monorepo tag (you push) | Mirror tag (workflow pushes) | Packagist version |
|---|---|---|
| `laravel-sync-v0.1.1` | `v0.1.1` | `0.1.1` |
| `laravel-sync-v1.2.3` | `v1.2.3` | `1.2.3` |

The prefix strip is `${TAG#laravel-sync-}`. The monorepo namespace stays clean (package
tags are clearly prefixed and never collide with any future app/service tags), while the
mirror carries plain SemVer tags that Packagist understands.
