# CakeBackupPro Release Playbook

## 1. Prepare standalone repository

Create a dedicated repository containing only plugin files:

- `composer.json`
- `src/`
- `config/`
- `templates/`
- `scripts/`
- `README.md`
- `LICENSE`
- `AUDIT.md`

If splitting from a monorepo:

1. Copy `plugins/CakeBackupPro` into a clean repo root.
2. Ensure paths in README/commands are adjusted from `plugins/CakeBackupPro/...` to package-root paths where applicable.

## 2. Pre-release checks

```bash
composer validate composer.json
find src config templates -name "*.php" -print0 | xargs -0 -n1 php -l
bash -n scripts/backup-manager.sh
```

## 3. Version and tag

```bash
git add .
git commit -m "Release v1.0.0"
git tag v1.0.0
git push origin main --tags
```

## 4. Publish on Packagist

1. Open `https://packagist.org/packages/submit`
2. Submit repository URL
3. Verify package name: `cakebackuppro/cake-backup-pro`
4. Enable auto-update webhook from repository settings

## 5. Consumer install smoke test

In a fresh CakePHP app:

```bash
composer require cakebackuppro/cake-backup-pro
bin/cake migrations migrate -p CakeBackupPro
```

In `src/Application.php`:

```php
$this->addPlugin('CakeBackupPro', ['routes' => true]);
```

Open `/admin/backups` and run:

1. Validate connection
2. DB backup
3. Full backup
4. Restore from latest set in a non-production environment

