<div class="grid-container admin">
    <div class="grid-x grid-margin-x">
        <div class="cell small-12">
            <div class="d-flex justify-content-between align-items-start flex-wrap pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1 class="h2 mb-1">Backup Dashboard</h1>
                    <p class="text-muted mb-0">Configure once, run backups, restore safely, and monitor progress from one place.</p>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-3 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <div class="small text-muted">Schema</div>
                            <div class="font-weight-bold <?= $schemaReady ? 'text-success' : 'text-danger' ?>">
                                <?= $schemaReady ? 'Ready' : 'Missing' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <div class="small text-muted">Configuration</div>
                            <div class="font-weight-bold <?= $isConfigured ? 'text-success' : 'text-warning' ?>">
                                <?= $isConfigured ? 'Configured' : 'Needs setup' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <div class="small text-muted">Schedule</div>
                            <div class="font-weight-bold <?= trim((string)$scheduleOutput) !== '' ? 'text-success' : 'text-warning' ?>">
                                <?= trim((string)$scheduleOutput) !== '' ? 'Installed' : 'Not installed' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <div class="small text-muted">Snapshot check</div>
                            <div class="font-weight-bold <?= $snapshotsExitCode === 0 ? 'text-success' : 'text-warning' ?>">
                                <?= $snapshotsExitCode === 0 ? 'Reachable' : 'Review output' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="backup-progress-card" class="card mb-3" style="display:none;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="card-title mb-0">Backup Progress</h5>
                        <span id="backup-progress-badge" class="badge badge-secondary">Idle</span>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <div id="backup-progress-spinner" class="spinner-border spinner-border-sm text-primary mr-2" role="status"></div>
                        <strong id="backup-progress-status">Starting...</strong>
                    </div>
                    <div id="backup-progress-hint" class="text-muted mb-2">Preparing backup job...</div>
                    <pre id="backup-progress-log" class="mb-0" style="max-height:260px; overflow:auto;"></pre>
                </div>
            </div>

            <ul class="nav nav-tabs mb-3" id="backup-flow-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="tab-config-link" data-toggle="tab" href="#section-config" role="tab" aria-controls="section-config" aria-selected="true">1. Configure</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-backup-link" data-toggle="tab" href="#section-backup" role="tab" aria-controls="section-backup" aria-selected="false">2. Backup</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-restore-link" data-toggle="tab" href="#section-restore" role="tab" aria-controls="section-restore" aria-selected="false">3. Restore</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-monitor-link" data-toggle="tab" href="#section-monitor" role="tab" aria-controls="section-monitor" aria-selected="false">4. Monitor</a>
                </li>
            </ul>

            <div class="tab-content">
            <div id="section-config" class="card mb-3 tab-pane fade show active" role="tabpanel" aria-labelledby="tab-config-link">
                <div class="card-body">
                    <h5 class="card-title mb-2">1. Configure Backup Destination and Policy</h5>
                    <?php if (!$schemaReady): ?>
                        <p class="text-danger mb-0">Backup settings table is missing. Run <code>bin/cake migrations migrate -p CakeBackupPro</code> and refresh.</p>
                    <?php else: ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                            <p class="text-muted mb-2 mb-md-0">Set destination credentials, schedule, retention, and default component selection.</p>
                            <a
                                href="https://secure.backblaze.com/user_signin.htm"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-outline-primary btn-sm"
                            >
                                Open Backblaze Login
                            </a>
                        </div>

                        <?= $this->Form->create($settings, ['url' => ['action' => 'saveSettings']]) ?>
                        <div class="row">
                            <div class="col-md-6">
                                <?= $this->Form->control('b2_key_id', [
                                    'label' => 'Backblaze Key ID',
                                    'type' => 'text',
                                    'class' => 'form-control',
                                ]) ?>
                            </div>
                            <div class="col-md-6">
                                <?= $this->Form->control('b2_application_key', [
                                    'label' => 'Backblaze Application Key',
                                    'type' => 'password',
                                    'value' => '',
                                    'autocomplete' => 'new-password',
                                    'class' => 'form-control',
                                    'help' => $settings->id ? 'Leave blank to keep existing key.' : null,
                                ]) ?>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-8">
                                <?= $this->Form->control('backup_path', [
                                    'label' => 'Backup Path (bucket/prefix)',
                                    'placeholder' => 'ay-website-backups/projectname',
                                    'class' => 'form-control',
                                ]) ?>
                            </div>
                            <div class="col-md-4">
                                <?= $this->Form->control('b2_region', [
                                    'label' => 'Region',
                                    'class' => 'form-control',
                                    'placeholder' => 'us-west-004',
                                ]) ?>
                            </div>
                        </div>

                        <hr>

                        <div class="row mb-2">
                            <div class="col-12">
                                <div class="form-check">
                                    <?= $this->Form->checkbox('schedule_enabled', [
                                        'class' => 'form-check-input',
                                        'id' => 'schedule-enabled',
                                        'hiddenField' => false,
                                    ]) ?>
                                    <label class="form-check-label" for="schedule-enabled">Enable scheduled backup runs</label>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <?= $this->Form->control('schedule_frequency', [
                                    'label' => 'Frequency',
                                    'type' => 'select',
                                    'options' => $scheduleFrequencyOptions,
                                    'class' => 'form-control',
                                ]) ?>
                            </div>
                            <div class="col-md-4">
                                <?= $this->Form->control('schedule_time', [
                                    'label' => 'Time',
                                    'type' => 'time',
                                    'class' => 'form-control',
                                ]) ?>
                            </div>
                            <div class="col-md-4">
                                <?= $this->Form->control('schedule_weekday', [
                                    'label' => 'Weekly Day',
                                    'type' => 'select',
                                    'options' => $weekdayOptions,
                                    'class' => 'form-control',
                                ]) ?>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <?= $this->Form->control('retention_days', [
                                    'label' => 'Retention Days',
                                    'type' => 'number',
                                    'min' => 1,
                                    'class' => 'form-control',
                                ]) ?>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="font-weight-bold d-block mb-2">Default file components to include in backups</label>
                            <div class="row">
                                <?php foreach ($fileComponentOptions as $componentKey => $componentLabel): ?>
                                    <div class="col-md-4">
                                        <div class="form-check mb-2">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="backup_components[]"
                                                id="settings-component-<?= h($componentKey) ?>"
                                                value="<?= h($componentKey) ?>"
                                                <?= in_array($componentKey, $selectedFileComponents, true) ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label" for="settings-component-<?= h($componentKey) ?>"><?= h($componentLabel) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="d-flex align-items-center mt-3">
                            <?= $this->Form->button('Save Settings', ['class' => 'btn btn-success']) ?>
                            <?= $this->Form->end() ?>

                            <?= $this->Form->create(null, ['url' => ['action' => 'validateSettings'], 'class' => 'ml-2 mb-0']) ?>
                            <?= $this->Form->button('Validate Connection', ['class' => 'btn btn-outline-primary']) ?>
                            <?= $this->Form->end() ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="section-backup" class="card mb-3 tab-pane fade" role="tabpanel" aria-labelledby="tab-backup-link">
                <div class="card-body">
                    <h5 class="card-title mb-2">2. Run Backup</h5>
                    <?php if (!$isConfigured): ?>
                        <p class="text-muted mb-0">Configure settings first to run manual backups.</p>
                    <?php else: ?>
                        <p class="text-muted">Pick a backup type and (for file/full backups) choose components.</p>

                        <?= $this->Form->create(null, ['url' => ['action' => 'runBackup'], 'class' => 'backup-form', 'id' => 'backup-form-type']) ?>
                        <div class="row align-items-end">
                            <div class="col-md-4">
                                <?= $this->Form->control('backup_type', [
                                    'label' => 'Backup Type',
                                    'type' => 'select',
                                    'options' => [
                                        'full' => 'Full (Database + Selected Files)',
                                        'db' => 'Database Only',
                                        'files' => 'Selected Files Only',
                                    ],
                                    'id' => 'backup-type-select',
                                    'class' => 'form-control',
                                ]) ?>
                            </div>
                            <div class="col-md-8">
                                <label class="font-weight-bold d-block mb-2">File components for this run</label>
                                <div class="row">
                                    <?php foreach ($fileComponentOptions as $componentKey => $componentLabel): ?>
                                        <div class="col-md-6">
                                            <div class="form-check mb-1">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    name="backup_components[]"
                                                    id="run-component-<?= h($componentKey) ?>"
                                                    value="<?= h($componentKey) ?>"
                                                    <?= in_array($componentKey, $selectedFileComponents, true) ? 'checked' : '' ?>
                                                >
                                                <label class="form-check-label" for="run-component-<?= h($componentKey) ?>"><?= h($componentLabel) ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <?= $this->Form->button('Start Backup', ['class' => 'btn btn-primary', 'type' => 'submit']) ?>
                        </div>
                        <?= $this->Form->end() ?>
                    <?php endif; ?>
                </div>
            </div>

            <div id="section-restore" class="card mb-3 tab-pane fade" role="tabpanel" aria-labelledby="tab-restore-link">
                <div class="card-body">
                    <h5 class="card-title mb-2">3. Restore</h5>
                    <p class="text-muted">Use backup sets for full environment sync. Upload restore remains available for one-off files.</p>

                    <?php if (!$isConfigured): ?>
                        <p class="text-muted mb-3">Configure settings first to enable restore from remote backup sets.</p>
                    <?php elseif (empty($backupSets)): ?>
                        <p class="text-muted mb-3">No backup sets detected yet. Run a backup first.</p>
                    <?php else: ?>
                        <div class="border rounded p-3 mb-3 bg-light">
                            <h6 class="mb-2">One-Click Full Site Restore</h6>
                            <p class="small text-muted mb-2">Restores full app stack (DB + core components), rewrites domain URLs to this environment, runs composer install, runs migrations, and clears cache.</p>
                            <?= $this->Form->create(null, ['url' => ['action' => 'restoreFullSite']]) ?>
                            <div class="row align-items-end">
                                <div class="col-md-6">
                                    <?= $this->Form->control('snapshot_set', [
                                        'label' => 'Backup Set',
                                        'type' => 'select',
                                        'options' => ['latest' => 'latest'] + $backupSets,
                                        'class' => 'form-control',
                                    ]) ?>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="include_env_files" id="include-env-files">
                                        <label class="form-check-label" for="include-env-files">Also restore env files</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <?= $this->Form->button('Run One-Click Restore', ['class' => 'btn btn-danger btn-block']) ?>
                                </div>
                            </div>
                            <?= $this->Form->end() ?>
                        </div>

                        <div class="border rounded p-3 mb-3">
                            <h6 class="mb-2">Restore from Backup Set (recommended)</h6>
                            <?= $this->Form->create(null, ['url' => ['action' => 'restoreSnapshot']]) ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <?= $this->Form->control('snapshot_set', [
                                        'label' => 'Backup Set',
                                        'type' => 'select',
                                        'options' => ['latest' => 'latest'] + $backupSets,
                                        'class' => 'form-control',
                                    ]) ?>
                                </div>
                            </div>

                            <label class="font-weight-bold d-block mt-2 mb-2">Components to restore</label>
                            <div class="row">
                                <?php foreach ($restoreComponentOptions as $componentKey => $componentLabel): ?>
                                    <div class="col-md-4">
                                        <div class="form-check mb-2">
                                            <input
                                                class="form-check-input"
                                            type="checkbox"
                                            name="restore_components[]"
                                            id="restore-component-<?= h($componentKey) ?>"
                                            value="<?= h($componentKey) ?>"
                                            <?= in_array($componentKey, $restoreDefaultComponents, true) ? 'checked' : '' ?>
                                        >
                                            <label class="form-check-label" for="restore-component-<?= h($componentKey) ?>"><?= h($componentLabel) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted d-block mb-2">Existing local paths are renamed to <code>old-*</code> before restore extraction.</small>
                            <?= $this->Form->button('Start Restore from Set', ['class' => 'btn btn-danger']) ?>
                            <?= $this->Form->end() ?>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="border rounded p-3 h-100">
                                <h6 class="mb-2">Restore Database from Uploaded File</h6>
                                <?= $this->Form->create(null, ['url' => ['action' => 'restoreDb'], 'type' => 'file']) ?>
                                <?= $this->Form->control('backup_file', [
                                    'label' => 'DB Backup File',
                                    'type' => 'file',
                                    'class' => 'form-control-file',
                                    'accept' => '.sql,.gz,.sql.gz',
                                    'help' => 'Upload .sql or .sql.gz',
                                ]) ?>
                                <?= $this->Form->button('Start DB Restore', ['class' => 'btn btn-warning']) ?>
                                <?= $this->Form->end() ?>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="border rounded p-3 h-100">
                                <h6 class="mb-2">Restore Files from Uploaded Archive</h6>
                                <?= $this->Form->create(null, ['url' => ['action' => 'restoreFiles'], 'type' => 'file']) ?>
                                <?= $this->Form->control('backup_file', [
                                    'label' => 'Files Archive',
                                    'type' => 'file',
                                    'class' => 'form-control-file',
                                    'accept' => '.tar.gz,.tgz,.zip,.gz,.tar',
                                    'help' => 'Upload .tar.gz, .tgz, or .zip',
                                ]) ?>
                                <?= $this->Form->control('target_dir', [
                                    'label' => 'Restore Target Directory',
                                    'placeholder' => '/absolute/path/to/restore-output',
                                    'class' => 'form-control',
                                ]) ?>
                                <?= $this->Form->button('Start File Restore', ['class' => 'btn btn-warning']) ?>
                                <?= $this->Form->end() ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="section-monitor" class="card mb-3 tab-pane fade" role="tabpanel" aria-labelledby="tab-monitor-link">
                <div class="card-body">
                    <h5 class="card-title mb-2">4. Monitor and Verify</h5>
                    <?php
                        $scheduleText = trim((string)$scheduleOutput);
                        $snapshotText = trim((string)$snapshotsOutput);
                        $scheduleLines = $scheduleText === '' ? [] : preg_split('/\r\n|\r|\n/', $scheduleText);
                        $snapshotLines = $snapshotText === '' ? [] : preg_split('/\r\n|\r|\n/', $snapshotText);
                    ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 24%;">Check</th>
                                    <th>Current Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th scope="row">Installed Schedule</th>
                                    <td>
                                        <?php if (!empty($scheduleLines)): ?>
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <?php foreach ($scheduleLines as $line): ?>
                                                        <?php if (trim((string)$line) === '') {
                                                            continue;
                                                        } ?>
                                                        <tr>
                                                            <td><code><?= h((string)$line) ?></code></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php else: ?>
                                            <span class="text-muted">No schedule found in crontab.</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Snapshots / Remote Files</th>
                                    <td>
                                        <?php if (!empty($snapshotLines)): ?>
                                            <div style="max-height: 220px; overflow:auto;">
                                                <table class="table table-sm table-striped mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th style="width: 70px;">#</th>
                                                            <th>Remote File</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php $rowNumber = 0; ?>
                                                        <?php foreach ($snapshotLines as $line): ?>
                                                            <?php $line = trim((string)$line); ?>
                                                            <?php if ($line === '') {
                                                                continue;
                                                            } ?>
                                                            <?php $rowNumber++; ?>
                                                            <tr>
                                                                <td><?= $rowNumber ?></td>
                                                                <td><code><?= h($line) ?></code></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">No remote snapshots found.</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Recent Backup Log</th>
                                    <td>
                                        <pre id="recent-backup-log" class="mb-0" style="max-height: 260px; overflow:auto;"><?= h((string)$recentLog) ?></pre>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const forms = document.querySelectorAll('.backup-form');
    const progressCard = document.getElementById('backup-progress-card');
    const statusEl = document.getElementById('backup-progress-status');
    const hintEl = document.getElementById('backup-progress-hint');
    const badgeEl = document.getElementById('backup-progress-badge');
    const spinnerEl = document.getElementById('backup-progress-spinner');
    const logEl = document.getElementById('backup-progress-log');
    const recentLogEl = document.getElementById('recent-backup-log');
    if (!forms.length || !progressCard || !statusEl || !logEl || !badgeEl || !spinnerEl) return;

    let polling = false;

    const setUiState = (state, statusText, hintText = '') => {
        statusEl.textContent = statusText;
        if (hintEl) hintEl.textContent = hintText;

        if (state === 'running') {
            badgeEl.textContent = 'In Progress';
            badgeEl.className = 'badge badge-primary';
            spinnerEl.style.display = '';
        } else if (state === 'done') {
            badgeEl.textContent = 'Completed';
            badgeEl.className = 'badge badge-success';
            spinnerEl.style.display = 'none';
        } else if (state === 'error') {
            badgeEl.textContent = 'Error';
            badgeEl.className = 'badge badge-danger';
            spinnerEl.style.display = 'none';
        } else {
            badgeEl.textContent = 'Idle';
            badgeEl.className = 'badge badge-secondary';
            spinnerEl.style.display = 'none';
        }
    };

    const pollStatus = async () => {
        try {
            const res = await fetch('<?= $this->Url->build(['action' => 'status']) ?>', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const data = await res.json();
            logEl.textContent = data.currentRunLog || data.recentLog || '';
            logEl.scrollTop = logEl.scrollHeight;
            if (recentLogEl) {
                recentLogEl.textContent = data.recentLog || '';
            }

            if (data.running) {
                setUiState('running', 'Backup in progress...', 'Do not close this page if you want live updates.');
                setTimeout(pollStatus, 3000);
            } else {
                polling = false;
                setUiState('done', 'Backup completed.', 'Latest log captured below.');
            }
        } catch (e) {
            polling = false;
            setUiState('error', 'Could not poll backup status.', 'Check server/network and try again.');
        }
    };

    forms.forEach((form) => {
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const formData = new FormData(form);
            progressCard.style.display = 'block';
            setUiState('running', 'Submitting backup request...', 'Starting job...');
            logEl.textContent = '';

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!data.started) {
                    setUiState('error', data.message || 'Failed to start backup.', 'Fix settings and retry.');
                    return;
                }
                setUiState('running', data.message || 'Backup started.', 'Collecting progress output...');
                if (!polling) {
                    polling = true;
                    setTimeout(pollStatus, 1000);
                }
            } catch (e) {
                setUiState('error', 'Failed to start backup request.', 'Check browser/network and retry.');
            }
        });
    });

    (async () => {
        try {
            const res = await fetch('<?= $this->Url->build(['action' => 'status']) ?>', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.running) {
                progressCard.style.display = 'block';
                setUiState('running', 'Backup in progress...', 'Attached to active backup process.');
                logEl.textContent = data.currentRunLog || data.recentLog || '';
                if (!polling) {
                    polling = true;
                    setTimeout(pollStatus, 1500);
                }
            }
        } catch (e) {}
    })();
})();
</script>
