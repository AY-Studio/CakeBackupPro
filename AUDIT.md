# CakeBackupPro Audit Checklist

This checklist is for release readiness before publishing/tagging.

## 1. Packaging

- [x] Plugin has its own `composer.json` with `type: cakephp-plugin`
- [x] PSR-4 namespace maps `CakeBackupPro\\` -> `src/`
- [x] License file exists (`MIT`)
- [x] README includes install/config/usage/troubleshooting

## 2. Naming / Portability

- [x] App-specific plugin name removed (`AYBackup` -> `CakeBackupPro`)
- [x] App-specific hardcoded tag removed (`warranty` -> `cake-backup-pro`)
- [x] Cron marker is generic (`# cake-backup-pro-runner`)
- [x] Plugin routes are self-contained in `config/routes.php`

## 3. Restore Safety

- [x] Existing local paths archived as `old-*` before restore replace
- [x] URL rewrite can map source domain -> target domain
- [x] One-click full restore keeps component fallback path support
- [x] Legacy backup path formats still restorable

## 4. Operational Robustness

- [x] DB credential probing supports fallbacks (`DB_*_FALLBACKS`)
- [x] Post-restore health checks emit clear PASS/FAIL lines
- [x] Backup progress/status visible in admin
- [x] Logs written to predictable locations

## 5. Security / Secrets

- [x] Example env files use placeholders
- [x] README warns against committing secrets
- [ ] Optional future: signed backup manifests
- [ ] Optional future: encryption-at-rest for file archives beyond B2 transport/storage defaults

## 6. Testing / CI (Recommended Next)

- [ ] Add unit tests for settings normalization and command arg construction
- [ ] Add integration smoke tests for backup script invocation
- [ ] Add GitHub Actions (`php -l`, `composer validate`, tests)
- [ ] Add static analysis (`phpstan`) and coding standard checks (`phpcs`)

## 7. Release Process

- [ ] `composer validate` passes in plugin repo root
- [ ] Tag release with semantic versioning (`v1.0.0`)
- [ ] Publish and sync on Packagist
- [ ] Add CHANGELOG entry

