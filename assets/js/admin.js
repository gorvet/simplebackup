(function($) {
    const cfg = JSON.parse(document.getElementById('simplebackup-modal-config').textContent);
    const actionButtons = Array.prototype.slice.call(document.querySelectorAll('.simplebackup-ai1wm-action'));
    const backupButton = document.getElementById('simplebackup-ai1wm-run-backup');
    let busy = false;
    let lastFlow = '';
    let lastImportType = '';
    let lastExportType = '';
    let reloadOnModalClose = false;
    let reloadScheduled = false;

    const finalImportTypes = {
        done: true,
        error: true,
        pro: true,
        backup_is_encrypted: true,
        server_cannot_decrypt: true
    };

    const finalExportTypes = {
        done: true,
        error: true,
        download: true
    };

    function applyModalBrandingAndLocale() {
        if (window.ai1wm_locale && cfg.modalLocale) {
            Object.keys(cfg.modalLocale).forEach(function(key) {
                window.ai1wm_locale[key] = cfg.modalLocale[key];
            });
        }

        if (document.getElementById('simplebackup-ai1wm-brand-style')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'simplebackup-ai1wm-brand-style';
        style.textContent = `
            .ai1wm-modal-container {
                border-top: 4px solid #2271b1;
            }
            .ai1wm-modal-container .ai1wm-loader,
            .ai1wm-modal-container section h1 .ai1wm-loader {
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background-image: url("${cfg.modalLogoUrl}") !important;
                background-size: 28px 28px !important;
                background-repeat: no-repeat !important;
                background-position: center !important;
                background-color: #eef6ff !important;
                box-shadow: 0 0 0 0 rgba(34,113,177,.45);
                animation: simplebackupPulse 1.6s ease-in-out infinite;
            }
            @keyframes simplebackupPulse {
                0%   { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34,113,177,.45); }
                70%  { transform: scale(1);    box-shadow: 0 0 0 12px rgba(34,113,177,0); }
                100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34,113,177,0); }
            }
        `;
        document.head.appendChild(style);
    }

    function patchAi1wmModalBehaviour() {
        if (!window.Ai1wm || !Ai1wm.Export || !Ai1wm.Import) {
            return;
        }

        if (!Ai1wm.Export.prototype.__simplebackupPatched) {
            const originalExportSetStatus = Ai1wm.Export.prototype.setStatus;
            Ai1wm.Export.prototype.setStatus = function(status) {
                return originalExportSetStatus.call(this, status);
            };
            Ai1wm.Export.prototype.__simplebackupPatched = true;
        }

        if (!Ai1wm.Import.prototype.__simplebackupPatched) {
            const originalImportSetStatus = Ai1wm.Import.prototype.setStatus;
            Ai1wm.Import.prototype.setStatus = function(status) {
                if (status && String(status.type).toLowerCase() === 'confirm') {
                    status = $.extend({}, status, {
                        title: cfg.restoreConfirmTitle,
                        message: cfg.restoreConfirmMessage
                    });
                }

                return originalImportSetStatus.call(this, status);
            };
            Ai1wm.Import.prototype.__simplebackupPatched = true;
        }
    }

    function setBusy(flag, forcedLabel) {
        busy = !!flag;
        actionButtons.forEach(function(btn) {
            if (!btn.dataset.defaultLabel) {
                btn.dataset.defaultLabel = btn.textContent;
            }
            btn.disabled = busy;
            if (busy) {
                btn.textContent = forcedLabel || btn.dataset.busyLabel || cfg.busyLabel;
            } else {
                btn.textContent = btn.dataset.defaultLabel;
            }
        });
    }

    function bindActionMenus() {
        document.querySelectorAll('.simplebackup-actions-toggle').forEach(function(toggle) {
            toggle.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                const holder = toggle.closest('.simplebackup-actions');
                const willOpen = !holder.classList.contains('is-open');
                document.querySelectorAll('.simplebackup-actions.is-open').forEach(function(openMenu) {
                    openMenu.classList.remove('is-open');
                    const openToggle = openMenu.querySelector('.simplebackup-actions-toggle');
                    if (openToggle) {
                        openToggle.setAttribute('aria-expanded', 'false');
                    }
                });
                holder.classList.toggle('is-open', willOpen);
                toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        });

        document.addEventListener('click', function(event) {
            if (event.target.closest('.simplebackup-actions')) {
                return;
            }
            document.querySelectorAll('.simplebackup-actions.is-open').forEach(function(openMenu) {
                openMenu.classList.remove('is-open');
                const openToggle = openMenu.querySelector('.simplebackup-actions-toggle');
                if (openToggle) {
                    openToggle.setAttribute('aria-expanded', 'false');
                }
            });
        });
    }

    function clearAi1wmStatus() {
        const body = new URLSearchParams();
        body.set('action', 'simplebackup_reset_ai1wm_status');
        body.set('nonce', cfg.ajaxNonce);

        return window.fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        })
            .then(function(response) {
                return response.json();
            })
            .then(function(json) {
                if (!json || !json.success) {
                    const message = json && json.data && json.data.message ? json.data.message : cfg.unknownErrorMessage;
                    throw new Error(message);
                }
                return json.data || {};
            });
    }

    function reloadPage(delayMs) {
        if (reloadScheduled) {
            return;
        }
        reloadScheduled = true;
        window.setTimeout(function() {
            window.location.reload();
        }, Math.max(0, delayMs || 0));
    }

    function canUseAi1wmEngine() {
        return (
            window.Ai1wm &&
            window.Ai1wm.Util &&
            window.Ai1wm.Import &&
            window.Ai1wm.Export &&
            window.ai1wm_import &&
            window.ai1wm_export
        );
    }

    applyModalBrandingAndLocale();
    patchAi1wmModalBehaviour();
    bindActionMenus();

    function randomStorage() {
        return (window.Ai1wm && Ai1wm.Util && Ai1wm.Util.random)
            ? Ai1wm.Util.random(12)
            : String(Date.now());
    }

    function listForm(selector) {
        if (window.Ai1wm && Ai1wm.Util && Ai1wm.Util.form) {
            return Ai1wm.Util.form(selector);
        }
        return [];
    }

    function startExportFlow() {
        applyModalBrandingAndLocale();
        patchAi1wmModalBehaviour();
        reloadOnModalClose = false;
        lastFlow = 'export';
        lastExportType = '';
        const model = new Ai1wm.Export();
        const storage = randomStorage();
        const params = listForm('#ai1wm-export-form')
            .concat({ name: 'storage', value: storage })
            .concat({ name: 'file', value: 1 })
            .concat({ name: 'ai1wm_manual_export', value: 1 });

        model.setParams(params);
        model.start();
    }

    function startImportFlow(archiveName) {
        applyModalBrandingAndLocale();
        patchAi1wmModalBehaviour();
        reloadOnModalClose = false;
        lastFlow = 'import';
        lastImportType = '';
        const model = new Ai1wm.Import();
        const storage = randomStorage();
        const params = listForm('#ai1wm-import-form')
            .concat({ name: 'storage', value: storage })
            .concat({ name: 'archive', value: archiveName })
            .concat({ name: 'ai1wm_manual_restore', value: 1 });

        model.setParams(params);
        model.start();
    }

    function startGdriveRestoreFlow(fileId, fileName) {
        applyModalBrandingAndLocale();
        patchAi1wmModalBehaviour();
        reloadOnModalClose = false;
        lastFlow = 'import';
        lastImportType = '';
        const model = new Ai1wm.Import();
        model.setStatus({ type: 'info', message: cfg.prepareGdriveMessage });

        window.onbeforeunload = function() {
            return (window.ai1wm_locale && ai1wm_locale.stop_importing_your_website) ? ai1wm_locale.stop_importing_your_website : '';
        };

        const body = new URLSearchParams();
        body.set('action', 'simplebackup_prepare_restore_gdrive');
        body.set('nonce', cfg.ajaxNonce);
        body.set('file_id', fileId);
        body.set('file_name', fileName || '');

        window.fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        })
            .then(function(response) {
                return response.json();
            })
            .then(function(json) {
                if (!json || !json.success || !json.data || !json.data.archive) {
                    const message = (json && json.data && json.data.message) ? json.data.message : cfg.prepareErrorMessage;
                    throw new Error(message);
                }

                startImportFlow(json.data.archive);
            })
            .catch(function(err) {
                window.onbeforeunload = null;
                setBusy(false);
                model.setStatus({
                    type: 'error',
                    title: (window.ai1wm_locale && ai1wm_locale.unable_to_import) ? ai1wm_locale.unable_to_import : 'Import failed',
                    message: (err && err.message) ? err.message : cfg.unknownErrorMessage
                });
            });
    }

    function ensureReleasedWhenModalClosed() {
        if (!$('.ai1wm-modal-container:visible').length) {
            if (busy) {
                setBusy(false);
            }
            if (reloadOnModalClose) {
                reloadOnModalClose = false;
                reloadPage(300);
            }
        }
    }

    setInterval(ensureReleasedWhenModalClosed, 1200);

    $(document).on('ai1wm-import-status.simplebackup', function(_event, status) {
        if (!status || !status.type) {
            return;
        }
        const type = String(status.type).toLowerCase();
        lastImportType = type;
        if (finalImportTypes[type]) {
            window.onbeforeunload = null;
            setBusy(false);
            if (type === 'done') {
                reloadOnModalClose = true;
            }
        }
    });

    $(document).on('ai1wm-export-status.simplebackup', function(_event, status) {
        if (!status || !status.type) {
            return;
        }
        const type = String(status.type).toLowerCase();
        lastExportType = type;
        if (finalExportTypes[type]) {
            window.onbeforeunload = null;
            setBusy(false);
            if (type === 'done' || type === 'download') {
                reloadOnModalClose = true;
            }
        }
    });

    $(document).on('click', '.ai1wm-modal-container .ai1wm-button-red, .ai1wm-modal-container .ai1wm-button-gray, .ai1wm-modal-container .ai1wm-button-green', function() {
        if ((lastFlow === 'import' && lastImportType === 'done') || (lastFlow === 'export' && (lastExportType === 'done' || lastExportType === 'download'))) {
            reloadOnModalClose = true;
        }
        setTimeout(ensureReleasedWhenModalClosed, 600);
    });

    if (backupButton) {
        backupButton.addEventListener('click', function() {
            if (busy) {
                return;
            }
            if (!canUseAi1wmEngine()) {
                window.alert(cfg.missingAi1wmMessage);
                return;
            }

            setBusy(true, cfg.backupBusyLabel);
            clearAi1wmStatus()
                .then(function() {
                    startExportFlow();
                })
                .catch(function(err) {
                    setBusy(false);
                    window.alert((err && err.message) ? err.message : cfg.unknownErrorMessage);
                });
        });
    }

    document.querySelectorAll('.simplebackup-ai1wm-restore-local').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (busy) {
                return;
            }
            if (!canUseAi1wmEngine()) {
                window.alert(cfg.missingAi1wmMessage);
                return;
            }

            const archive = btn.getAttribute('data-archive') || '';
            if (!archive) {
                window.alert(cfg.unknownErrorMessage);
                return;
            }

            setBusy(true, cfg.restoreBusyLabel);
            clearAi1wmStatus()
                .then(function() {
                    startImportFlow(archive);
                })
                .catch(function(err) {
                    setBusy(false);
                    window.alert((err && err.message) ? err.message : cfg.unknownErrorMessage);
                });
        });
    });

    document.querySelectorAll('.simplebackup-ai1wm-restore-gdrive').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (busy) {
                return;
            }
            if (!canUseAi1wmEngine()) {
                window.alert(cfg.missingAi1wmMessage);
                return;
            }

            const fileId = btn.getAttribute('data-file-id') || '';
            const fileName = btn.getAttribute('data-file-name') || '';
            if (!fileId) {
                window.alert(cfg.unknownErrorMessage);
                return;
            }

            setBusy(true, cfg.prepareBusyLabel);
            clearAi1wmStatus()
                .then(function() {
                    startGdriveRestoreFlow(fileId, fileName);
                })
                .catch(function(err) {
                    window.onbeforeunload = null;
                    setBusy(false);
                    window.alert((err && err.message) ? err.message : cfg.unknownErrorMessage);
                });
        });
    });
})(jQuery);
