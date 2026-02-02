<?php
/**
 * Funções para a página de configurações do repositório.
 * * @version 2.9 - Estilos modernos e Footer WP Masters
 */

add_action('admin_init', 'mrp_register_settings');

function mrp_register_settings() {
    register_setting('mrp_settings_group', 'meu_repositorio_repos');
    register_setting('mrp_settings_group', 'meu_repositorio_auto_update_enabled', 'mrp_sanitize_checkbox');
    register_setting('mrp_settings_group', 'meu_repositorio_cache_duration', 'absint');

    add_settings_section('mrp_main_settings_section', __('Conexão com Repositórios', 'meu-repositorio-client'), 'mrp_main_section_callback', 'meu-repositorio-settings');
    
    add_settings_section('mrp_auto_update_section', __('Automação e Performance', 'meu-repositorio-client'), 'mrp_auto_update_section_callback', 'meu-repositorio-settings');
    add_settings_field('meu_repositorio_cache_duration_field', __('Tempo de Cache', 'meu-repositorio-client'), 'mrp_cache_duration_callback', 'meu-repositorio-settings', 'mrp_auto_update_section');
    add_settings_field('mrp_auto_update_enabled_field', __('Cron Externo', 'meu-repositorio-client'), 'mrp_auto_update_enabled_callback', 'meu-repositorio-settings', 'mrp_auto_update_section');
}

function mrp_render_settings_page() {
    ?>
    <div class="wrap mrp-wrap">
        <header class="mrp-header-bar">
            <div class="mrp-title-group">
                <h1 class="mrp-page-title"><?php esc_html_e('Configurações do Cliente', 'meu-repositorio-client'); ?></h1>
                <span class="mrp-version-badge" style="background: var(--mrp-primary); color: #fff; border: none; padding: 4px 10px; box-shadow: 0 2px 4px rgba(34, 113, 177, 0.2);">v<?php echo MRP_VERSION; ?></span>
            </div>
        </header>

        <form method="post" action="options.php" class="mrp-form">
            <?php settings_fields('mrp_settings_group'); ?>
            
            <div class="mrp-content-card">
                <?php do_settings_sections('meu-repositorio-settings'); ?>
            </div>
            
            <p class="submit">
                <?php submit_button(__('Salvar Configurações', 'meu-repositorio-client'), 'primary mrp-button mrp-button-primary', 'submit', false); ?>
            </p>
        </form>
        
        <div class="mrp-footer">
            Desenvolvido por <a href="https://wpmasters.com.br" target="_blank">WP Masters</a>
        </div>
    </div>
    <?php
}

function mrp_main_section_callback() {
    $repos = get_option('meu_repositorio_repos', []);
    if (!is_array($repos)) $repos = [];
    ?>
    <p class="mrp-form-helper"><?php esc_html_e('Adicione um ou mais repositórios para buscar plugins de diferentes fontes.', 'meu-repositorio-client'); ?></p>
    
    <div id="mrp-repos-repeater" style="margin-top: 20px;">
        <div class="mrp-repos-list">
            <?php if (empty($repos)): ?>
                <!-- Template inicial se vazio -->
                <div class="mrp-repo-row mrp-repo-row-empty" style="background: #f8fafc; border: 1px dashed #cbd5e1; padding: 20px; text-align: center; border-radius: 8px; margin-bottom: 15px;">
                     <p style="color: #64748b; margin: 0;"><?php _e('Nenhum repositório conectado. Clique no botão abaixo para adicionar.', 'meu-repositorio-client'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($repos as $index => $repo): ?>
                    <div class="mrp-repo-row" style="background: #ffffff; border: 1px solid #e2e8f0; padding: 20px; border-radius: 8px; margin-bottom: 15px; display: flex; gap: 20px; position: relative;">
                        <div class="mrp-field-group" style="flex: 2;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 13px;"><?php _e('URL do Repositório', 'meu-repositorio-client'); ?></label>
                            <input type="url" class="mrp-repo-url regular-text" value="<?php echo esc_url($repo['url']); ?>" placeholder="https://..." style="width: 100%;">
                        </div>
                        <div class="mrp-field-group" style="flex: 1;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 13px;"><?php _e('Token de Acesso', 'meu-repositorio-client'); ?></label>
                            <input type="password" class="mrp-repo-token regular-text" value="<?php echo esc_attr($repo['token']); ?>" placeholder="Token Opcional" style="width: 100%;">
                        </div>
                        <button type="button" class="mrp-remove-repo" title="<?php _e('Remover', 'meu-repositorio-client'); ?>" style="background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; border-radius: 6px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; align-self: flex-end; margin-bottom: 5px;">&times;</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <button type="button" id="mrp-add-repo" class="mrp-button mrp-button-secondary" style="margin-top: 10px;">
            <span class="dashicons dashicons-plus" style="margin-top: 4px; margin-right: 5px;"></span> <?php _e('Adicionar Novo Repositório', 'meu-repositorio-client'); ?>
        </button>
        
        <input type="hidden" name="meu_repositorio_repos" id="mrp-repos-hidden-input">
    </div>
    <?php
}

function mrp_auto_update_section_callback() {
    $cron_url = MRP_PLUGIN_URL . 'cron-update.php';
    echo '<p class="mrp-form-helper">' . sprintf(
        esc_html__('Use este comando no seu servidor para atualizações automáticas via cron: %s', 'meu-repositorio-client'),
        '<code style="background: #f1f5f9; padding: 8px 12px; border-radius: 6px; display:block; margin-top:10px; font-size: 12px; border: 1px solid #e2e8f0;">curl -s ' . esc_url($cron_url) . '</code>'
    ) . '</p>';
}

function mrp_auto_update_enabled_callback() {
    $is_enabled = get_option('meu_repositorio_auto_update_enabled', 0);
    echo '<label><input type="checkbox" name="meu_repositorio_auto_update_enabled" value="1" ' . checked(1, $is_enabled, false) . '> ' . esc_html__('Habilitar suporte a script de cron externo', 'meu-repositorio-client') . '</label>';
}

function mrp_cache_duration_callback() {
    $duration = get_option('meu_repositorio_cache_duration', 3600);
    ?>
    <select name="meu_repositorio_cache_duration">
        <option value="0" <?php selected($duration, 0); ?>>Sem Cache (Sempre buscar)</option>
        <option value="1800" <?php selected($duration, 1800); ?>>30 min</option>
        <option value="3600" <?php selected($duration, 3600); ?>>1 Hora (Recomendado)</option>
        <option value="43200" <?php selected($duration, 43200); ?>>12 Horas</option>
    </select>
    <?php
}

function mrp_sanitize_checkbox($input) { return (isset($input) && $input == 1) ? 1 : 0; }