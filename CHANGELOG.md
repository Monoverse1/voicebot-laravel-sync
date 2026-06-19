# Changelog

All notable changes to `monoverse/voicebot-laravel-sync` are documented here. This
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.1] - 2026-06-19

### Changed

- **Declare the canonical provider on pairing.** Both `voicebot:pair` paths now send
  `provider_id = "laravel"`, so the backend records the connection as a Laravel
  producer instead of defaulting it to `woocommerce`. Fixes catalog-resolve trying to
  pull a non-existent WooCommerce API for Laravel sites.

## [0.5.0] - 2026-06-19

### Added

- **Pair by publishable key (`pk_...`).** `voicebot:pair` now accepts a publishable key and
  calls the new backend `POST /api/v1/ingest/pair-by-key` (mints the same connection as the
  legacy pair-code path, gated on the key's bound `canonical_domain`). The command resolves
  the pairing credential in order CLI argument → `VOICEBOT_PUBLIC_KEY` → `VOICEBOT_PAIR_CODE`,
  branching to pair-by-key when the credential starts with `pk_`. `IngestClient::pairByKey()`
  mirrors the unsigned, retry-on-5xx `pair()` path and surfaces the backend error codes
  `invalid_key` (401), `domain_mismatch` (403) and `key_has_no_domain` (409) as distinct,
  actionable messages; a domain mismatch prints a hint to align `VOICEBOT_SITE_URL` with the
  key's bound storefront domain. New config knob `VOICEBOT_PUBLIC_KEY` / `voicebot.public_key`
  and protocol constant `Protocol::PATH_PAIR_BY_KEY`.

### Changed

- **`voicebot:pair` argument renamed `code` → `credential`** (accepts a `pk_...` key or a
  legacy `VB-XXXX-XXXX` code); `voicebot:install` `--code` option renamed `--key`.
- `Protocol::CLIENT_VERSION` → `0.3.0` (sent as `X-VoiceBot-Plugin-Version`).

### Retained

- **Legacy pair-code path unchanged.** `POST /api/v1/ingest/pair` (`VB-XXXX-XXXX`) still works
  end to end; catalog-sync HMAC signing is unchanged and already-paired installs keep their
  stored shared secret (no re-pair required).

## [0.4.0] - 2026-06-17

### Added

- **Declarative multilingual catalogs (no custom `EntitySource`).** A `translations` block
  (`locales`, `base_locale`, optional `present`) fans one model row out to a base entity plus one per
  locale — `lang` + `translation_of` are set automatically and each translation gets a
  `{base}:{locale}` external id. Map closures now receive `($model, $locale)`. `EntityMapper::mapRows()`
  is the new fan-out entry point; `map()` still returns the base entity. Soft-delete now emits a delete
  per emitted locale id and respects the configured `external_id`.
- **Declarative variant grouping.** A `variations` block (`items`, `external_id`, `axes`, `fields`)
  builds the canonical `variant_axes` + inline `variations[]` from a relation, so configurable products
  no longer need a custom `EntitySource`. The axis display name is shared between `variant_axes` and each
  variation's `attributes` — the key `select_variant` resolves on.
- **Config map presets.** `Mapping\Presets::product()` / `category()` / `page()` turn a few column names
  into the full canonical map (money → integer minor units, content → plain text, taxonomy → slug lists,
  stock → status) — a common catalog map drops from ~40 lines to ~5. Merge your own closures to extend.
- **`Mapping\ExternalId` helper.** `format()` / `parse()` / `isValid()` for the `producer:kind:id`
  external_id grammar, pinned to the contract (`docs/contracts/tools_contract.json#external_id_grammar`)
  by a drift test. `EntityMapper` now formats ids through it.
- **`php artisan voicebot:install`** — one-command onboarding: publishes the config, runs the package
  migrations, and runs a guided pairing, then prints the next steps. Wires nothing into the host's files.
- **Self-registering schedule.** Set `VOICEBOT_SCHEDULE_ENABLED=true` (config block `voicebot.schedule`)
  and the package registers the incremental delta + nightly full sync itself — no `routes/console.php`
  edit. Cadence configurable via `VOICEBOT_SCHEDULE_DELTA_CRON` / `VOICEBOT_SCHEDULE_FULL_CRON`. Still
  requires the system cron tick (`* * * * * php artisan schedule:run`).
- **`voicebot:doctor` flags decimal money.** Warns when a mapped `*price*` / `*_amount` field looks like
  a decimal instead of integer minor units (the common ×100 mapping mistake).

## [0.2.1] - 2026-06-15

### Fixed

- **Zero-publish `php artisan migrate` now creates the tables.** Migrations ship as
  timestamped `.php` files (`2025_01_01_0000NN_create_voicebot_*_table.php`) and are
  registered directly via `loadMigrationsFrom()` in `packageBooted()`. The previous
  `.php.stub` files (registered through Spatie `hasMigrations()->runsMigrations()`) were
  silently dropped by Laravel 10–13's `Migrator`, which only globs `*.php`, so a plain
  `migrate` created none of `voicebot_connections`, `voicebot_sync_state`,
  `voicebot_dead_letter`. The documented zero-publish flow now works as written.
- **Publishing can no longer double-run a migration.** The `voicebot-migrations` publish
  group now copies each file verbatim to an app path with the **identical basename**, so
  the published copy shares the package migration's name. The `Migrator` collapses the two
  by name (`keyBy(getMigrationName)`), so `vendor:publish` followed by `migrate` creates
  each table exactly once (no "table already exists").

### Changed

- `Protocol::CLIENT_VERSION` → `0.2.1` (sent as `X-VoiceBot-Plugin-Version`).

## [0.2.0] - 2026-06-15

### Added

- **Laravel 13 support.** All five `illuminate/*` constraints now allow `^13.0`
  (`contracts`, `support`, `console`, `http`, `database`), so the package installs
  cleanly on `laravel/framework ^13`. Laravel 10/11/12 stay supported. The CI matrix
  gains a Laravel 13 cell (PHP 8.3 + 8.4 × Testbench 11). The package's runtime API is
  unchanged — no behaviour change beyond compatibility.

### Changed

- `spatie/laravel-package-tools` floor raised to `^1.93` (first 1.x line whose
  `illuminate/contracts` constraint allows `^13.0`).
- Dev tooling bumped for Laravel 13: `orchestra/testbench ^11.0`, `pestphp/pest ^4.0`
  + `pest-plugin-laravel ^4.1`, `larastan/larastan ^3.10`, `laravel/pint ^1.29`. Each
  is added alongside the existing 10/11/12 constraints, so older cells resolve their
  prior versions.
- `Protocol::CLIENT_VERSION` → `0.2.0` (sent as `X-VoiceBot-Plugin-Version`).

## [0.1.0] - 2026-06-14

Initial release — a Laravel producer client for the VoiceBot canonical signed-ingest
contract (protocol v2, ADR-045 / ADR-055).

### Added

- HMAC-SHA256 request signer matching the backend verifier byte-for-byte
  (`METHOD\npath\nts\nnonce\nbody_sha256`, raw-byte secret).
- `IngestClient` for `pair` / `init` / `upload` / `finalize` / `events` / `status` /
  `unpair`. The bytes hashed are the bytes sent — the signature never drifts from the
  body. Retry policy is split by signing: **signed** calls retry on connection errors
  only (the nonce is spent server-side before a 429/5xx, so retrying it would replay);
  the unsigned pair handshake and the no-HMAC snapshot upload retry on 429/5xx with
  backoff honouring `Retry-After`. Plaintext `http://` transport is rejected.
- Streaming gzip-NDJSON snapshot writer; the full catalog is never held in memory.
- `FullSync` (init with exact `expected_counts`, empty-snapshot tombstone guard) and
  `DeltaSync` (per-kind watermarks advanced to run-start, op/byte batching, dead-letter,
  watermark advances only on full success).
- Dead-letter accumulates attempts per op identity and flags `exhausted` at
  `sync.dead_letter_max_attempts` instead of re-parking forever.
- Config-driven Eloquent source + `EntityMapper` (money minor units, slug lists, closure
  mapping, `laravel:{kind}:{id}` external-id wrapping) and a custom-source contract.
- Encrypted `SecretStore`; the shared secret is never logged, printed, or returned.
- Artisan commands: `voicebot:pair`, `voicebot:unpair`, `voicebot:sync` (`--full` /
  `--since` / `--dry-run` / `--queue`), `voicebot:doctor` (pre-flight that fails loudly
  before the first push).
- `SyncCatalogJob` (`ShouldQueue`) sharing one `SyncRunner` path with the command.
- Self-registering, auto-running migrations for `voicebot_connections`,
  `voicebot_sync_state`, `voicebot_dead_letter` (plain `php artisan migrate` creates them).
- Typed `ConfigException` / `TransientException` drive cron-friendly exit-code
  classification (no string matching).
- Pest + Orchestra Testbench suite (matrix PHP 8.2/8.3 × Laravel 10/11/12 in CI),
  including a backend-conformance signer vector, the hashed-equals-sent invariant, the
  signed-no-retry / unsigned-retry policy, and migration table creation.
