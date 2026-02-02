/**
 * assets/js/admin-scripts.js
 * Lógica Vanilla JS com sistema de Feedback Visual.
 * @version 2.11
 */
document.addEventListener('DOMContentLoaded', () => {

    // --- Sistema de Feedback (Popup) ---
    const showFeedback = (message, type = 'success') => {
        const container = document.getElementById('mrs-feedback-container');
        if (!container) return;

        const messageEl = container.querySelector(`.mrs-feedback-${type}`);
        if (!messageEl) return;

        // Reset state for re-triggering animation
        messageEl.classList.remove('show');
        messageEl.style.display = 'block';

        // Use requestAnimationFrame or a small timeout to allow display:block to take effect before adding 'show' class
        setTimeout(() => {
            messageEl.textContent = message;
            messageEl.classList.add('show');
            messageEl.style.opacity = '1';
        }, 10);

        setTimeout(() => {
            messageEl.classList.remove('show');
            setTimeout(() => {
                if (!messageEl.classList.contains('show')) {
                    messageEl.style.display = 'none';
                    messageEl.style.opacity = '0';
                }
            }, 500); // Wait for transition
        }, 5000);
    };

    // --- Manipulação de Botões de Ação (Instalar/Atualizar/Ativar) ---
    document.querySelectorAll('.plugin-action-button').forEach(button => {
        button.addEventListener('click', e => {
            e.preventDefault();

            // Verificações iniciais
            const state = button.dataset.state;
            if (button.disabled || !['update', 'install', 'activate'].includes(state)) return;

            const originalText = button.innerHTML;
            const originalWidth = button.offsetWidth; // Mantém largura para não pular layout

            // Estado de Loading
            button.disabled = true;
            button.style.width = `${originalWidth}px`;

            let actionName = 'meu_repositorio_update_plugin';
            if (state === 'install') {
                button.textContent = mrp_ajax.text.installing || 'Instalando...'; // Fallback text?
                // Add specific class for Green button
                button.classList.add('mrp-installing');
            } else if (state === 'activate') {
                button.textContent = mrp_ajax.text.activating;
                actionName = 'meu_repositorio_activate_plugin';
            } else {
                button.textContent = mrp_ajax.text.updating;
            }

            const formData = new URLSearchParams({
                action: actionName,
                plugin_url: button.dataset.pluginUrl || '',
                plugin_file: button.dataset.pluginFile || '',
                security: mrp_ajax.nonce
            });

            fetch(mrp_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showFeedback(data.data.message || mrp_ajax.text.success, 'success');
                        // Recarrega após breve delay para o usuário ver o sucesso
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showFeedback(`${mrp_ajax.text.error} ${data.data.message || ''}`, 'error');
                        button.disabled = false;
                        button.innerHTML = originalText;
                        button.style.width = '';
                    }
                })
                .catch(() => {
                    showFeedback(mrp_ajax.text.fatal_error, 'error');
                    button.disabled = false;
                    button.innerHTML = originalText;
                    button.style.width = '';
                });
        });
    });

    // --- Botão de Forçar Verificação ---
    const forceUpdateButton = document.getElementById('mrp-force-update-button');
    if (forceUpdateButton) {
        forceUpdateButton.addEventListener('click', e => {
            e.preventDefault();
            const button = e.target.closest('button');
            const originalHTML = button.innerHTML;

            button.disabled = true;
            button.innerHTML = `<span class="dashicons dashicons-update spin" style="animation: rotation 1s infinite linear;"></span> ${mrp_ajax.text.force_update_running}`;

            const formData = new URLSearchParams({
                action: 'meu_repositorio_force_update',
                security: mrp_ajax.nonce
            });

            fetch(mrp_ajax.ajax_url, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showFeedback(data.data.message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showFeedback(mrp_ajax.text.error, 'error');
                        button.disabled = false;
                        button.innerHTML = originalHTML;
                    }
                })
                .catch(() => {
                    location.reload();
                });
        });
    }

    // --- Lógica do Repeater de Repositórios (Configurações) ---
    const repeaterContainer = document.getElementById('mrp-repos-repeater');
    if (repeaterContainer) {
        const list = repeaterContainer.querySelector('.mrp-repos-list');
        const addButton = document.getElementById('mrp-add-repo');
        const hiddenInput = document.getElementById('mrp-repos-hidden-input');

        const updateHiddenInput = () => {
            const repos = [];
            repeaterContainer.querySelectorAll('.mrp-repo-row').forEach(row => {
                const url = row.querySelector('.mrp-repo-url').value.trim();
                const token = row.querySelector('.mrp-repo-token').value.trim();
                if (url) {
                    repos.push({ url, token });
                }
            });
            hiddenInput.value = JSON.stringify(repos);
        };

        addButton.addEventListener('click', () => {
            // Remover mensagem de vazio se existir
            const emptyMsg = list.querySelector('.mrp-repo-row-empty');
            if (emptyMsg) emptyMsg.remove();

            const row = document.createElement('div');
            row.className = 'mrp-repo-row';
            row.style = 'background: #ffffff; border: 1px solid #e2e8f0; padding: 20px; border-radius: 8px; margin-bottom: 15px; display: flex; gap: 20px; position: relative;';
            row.innerHTML = `
                <div class="mrp-field-group" style="flex: 2;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 13px;">URL do Repositório</label>
                    <input type="url" class="mrp-repo-url regular-text" placeholder="https://..." style="width: 100%;">
                </div>
                <div class="mrp-field-group" style="flex: 1;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 13px;">Token de Acesso</label>
                    <input type="password" class="mrp-repo-token regular-text" placeholder="Token Opcional" style="width: 100%;">
                </div>
                <button type="button" class="mrp-remove-repo" title="Remover" style="background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; border-radius: 6px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; align-self: flex-end; margin-bottom: 5px;">&times;</button>
            `;
            list.appendChild(row);
        });

        repeaterContainer.addEventListener('click', (e) => {
            if (e.target.classList.contains('mrp-remove-repo')) {
                e.target.closest('.mrp-repo-row').remove();
                if (list.querySelectorAll('.mrp-repo-row').length === 0) {
                    list.innerHTML = `
                        <div class="mrp-repo-row mrp-repo-row-empty" style="background: #f8fafc; border: 1px dashed #cbd5e1; padding: 20px; text-align: center; border-radius: 8px; margin-bottom: 15px;">
                            <p style="color: #64748b; margin: 0;">Nenhum repositório conectado. Clique no botão abaixo para adicionar.</p>
                        </div>
                    `;
                }
            }
        });
    }

    // --- Feedback ao Salvar Configurações (Via AJAX) ---
    const settingsForm = document.querySelector('form.mrp-form');
    if (settingsForm) {
        settingsForm.addEventListener('submit', (e) => {
            e.preventDefault();

            // Sincronizar repeater antes de enviar
            if (repeaterContainer) {
                const repos = [];
                repeaterContainer.querySelectorAll('.mrp-repo-row').forEach(row => {
                    const url = row.querySelector('.mrp-repo-url')?.value.trim();
                    const token = row.querySelector('.mrp-repo-token')?.value.trim();
                    if (url) repos.push({ url, token });
                });
                document.getElementById('mrp-repos-hidden-input').value = JSON.stringify(repos);
            }

            const submitButton = settingsForm.querySelector('#submit');
            const originalValue = submitButton.value;

            submitButton.value = mrp_ajax.text.saving || 'Salvando...';
            submitButton.disabled = true;

            const formData = new FormData(settingsForm);
            formData.append('action', 'mrp_save_settings');
            formData.append('security', mrp_ajax.nonce);

            fetch(mrp_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showFeedback(data.data.message || 'Configurações salvas!', 'success');
                        // Forçar recarga após salvar para aplicar as novas conexões
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showFeedback(data.data.message || 'Erro ao salvar.', 'error');
                    }
                })
                .catch(() => {
                    showFeedback('Erro de conexão ao salvar.', 'error');
                })
                .finally(() => {
                    submitButton.value = originalValue;
                    submitButton.disabled = false;
                });
        });
    }

    // --- Lógica de Rollback / Backups ---
    const renderBackupModal = (slug, backups) => {
        // Remover modal existente se houver
        const existing = document.querySelector('.mrp-modal-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'mrp-modal-overlay';

        let listHTML = '';
        if (backups.length === 0) {
            listHTML = `<div class="mrp-no-backups">Nenhum backup encontrado para este plugin.</div>`;
        } else {
            listHTML = `<ul class="mrp-backup-list">`;
            backups.forEach(backup => {
                listHTML += `
                    <li class="mrp-backup-item">
                        <div class="mrp-backup-info">
                            <span class="mrp-backup-version"><span class="dashicons dashicons-clock" style="font-size:16px; width:16px; height:16px; margin-right:4px;"></span> Versão ${backup.version}</span>
                            <span class="mrp-backup-date">${backup.date} &bull; ${backup.size}</span>
                        </div>
                        <button class="mrp-button mrp-button-secondary mrp-button-sm mrp-restore-btn" data-file="${backup.file}">
                            Restaurar
                        </button>
                    </li>
                `;
            });
            listHTML += `</ul>`;
        }

        overlay.innerHTML = `
            <div class="mrp-modal">
                <div class="mrp-modal-header">
                    <h3>Backups: ${slug}</h3>
                    <button class="mrp-modal-close">&times;</button>
                </div>
                <div class="mrp-modal-body">
                    ${listHTML}
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        // Eventos do Modal
        const closeBtn = overlay.querySelector('.mrp-modal-close');
        closeBtn.addEventListener('click', () => overlay.remove());

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.remove();
        });

        overlay.querySelectorAll('.mrp-restore-btn').forEach(rBtn => {
            rBtn.addEventListener('click', () => {
                if (!confirm('Tem certeza que deseja restaurar esta versão? A versão atual será substituída.')) return;

                const backupFile = rBtn.dataset.file;
                // Loading state no botão do modal
                rBtn.disabled = true;
                rBtn.textContent = 'Restaurando...';

                const restoreData = new URLSearchParams({
                    action: 'meu_repositorio_rollback_plugin',
                    slug: slug,
                    backup_file: backupFile,
                    security: mrp_ajax.nonce
                });

                fetch(mrp_ajax.ajax_url, { method: 'POST', body: restoreData })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            showFeedback(res.data.message, 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showFeedback(res.data.message, 'error');
                            rBtn.disabled = false;
                            rBtn.textContent = 'Restaurar';
                        }
                    })
                    .catch(() => {
                        showFeedback('Erro fatal ao restaurar.', 'error');
                        rBtn.disabled = false;
                        rBtn.textContent = 'Restaurar';
                    });
            });
        });
    };

    document.querySelectorAll('.mrp-rollback-button').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            const slug = btn.dataset.slug;

            // UI Feedback
            const originalIcon = btn.innerHTML;
            btn.innerHTML = '<span class="dashicons dashicons-update spin" style="animation: rotation 1s infinite linear; margin:0;"></span>';
            btn.disabled = true;

            const formData = new URLSearchParams({
                action: 'meu_repositorio_get_backups',
                slug: slug,
                security: mrp_ajax.nonce
            });

            fetch(mrp_ajax.ajax_url, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    btn.innerHTML = originalIcon;
                    btn.disabled = false;

                    if (res.success) {
                        renderBackupModal(slug, res.data.backups);
                    } else {
                        showFeedback(res.data.message || mrp_ajax.text.error, 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    btn.innerHTML = originalIcon;
                    btn.disabled = false;
                    showFeedback(mrp_ajax.text.fatal_error, 'error');
                });
        });
    });

    // --- Search Filter Logic ---
    const searchInput = document.getElementById('mrp-plugin-search');
    if (searchInput) {
        searchInput.addEventListener('keyup', (e) => {
            const term = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('.mrp-table tbody tr');

            rows.forEach(row => {
                const nameEl = row.querySelector('.mrp-plugin-name');
                if (nameEl) {
                    const text = nameEl.textContent.toLowerCase();
                    row.style.display = text.includes(term) ? '' : 'none';
                }
            });
        });
    }

});