<?php
/**
 * Plugin Name: Meu Repositório Client
 * Description: Cliente para buscar e atualizar plugins de um repositório privado via REST API com suporte a cache, rate limiting e estatísticas.
 * Version: 2.18.2
 * TAG: true
 * Author: Thomas Marcelino
 * Author URI: https://wpmasters.com.br
 * License: GPL2
 */

defined('ABSPATH') or die('Acesso direto não permitido!');

// --- Constantes e Inclusões ---
define('MRP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MRP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Versão do Plugin (Centralizada)
define('MRP_VERSION', '2.18.2');

// Versionamento Único para Cache Busting
define('MRP_CLIENT_VERSION_J8K3', MRP_VERSION . '.' . time());

require_once MRP_PLUGIN_PATH . 'includes/class-folder-scanner.php';
require_once MRP_PLUGIN_PATH . 'includes/admin-settings-page.php';
require_once MRP_PLUGIN_PATH . 'includes/class-plugin-updater.php';
require_once MRP_PLUGIN_PATH . 'includes/class-api-cache.php';

/**
 * Classe principal e final do plugin.
 * @version 2.12
 */
final class MeuRepositorioPlugin {

    private static $instance;
    private $api_url;
    private $plugin_version;
    private $settings_page_hook;

    private function __construct() {
        add_action('init', [$this, 'init']);
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        load_plugin_textdomain('meu-repositorio-client', false, dirname(plugin_basename(__FILE__)) . '/languages/');

        // Migração de formato antigo para novo (Múltiplos repositórios)
        $this->mrp_migrate_settings_if_needed();

        $this->plugin_version = MRP_VERSION;
        
        // Hooks para limpar cache ao salvar configurações
        add_action('update_option_meu_repositorio_repos', [$this, 'mrp_clear_cache_on_settings_save']);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'mrp_add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'mrp_enqueue_admin_assets']);
            add_action('wp_ajax_meu_repositorio_update_plugin', [$this, 'mrp_handle_update_plugin']);
            add_action('wp_ajax_meu_repositorio_activate_plugin', [$this, 'mrp_handle_activate_plugin']);
            add_action('wp_ajax_meu_repositorio_force_update', [$this, 'mrp_force_update_plugins_ajax_handler']);
            add_action('wp_ajax_meu_repositorio_rollback_plugin', [$this, 'mrp_handle_rollback_plugin']);
            add_action('wp_ajax_meu_repositorio_get_backups', [$this, 'mrp_handle_get_backups']);
            add_action('wp_ajax_mrp_save_settings', [$this, 'mrp_handle_save_settings_ajax']);
            add_action('admin_notices', [$this, 'mrp_render_feedback_container']);
        }

        $repos = get_option('meu_repositorio_repos', []);
        if (!empty($repos)) {
            add_filter('pre_set_site_transient_update_plugins', [$this, 'mrp_check_for_updates']);
        }
    }

    private function mrp_migrate_settings_if_needed() {
        $old_url = get_option('meu_repositorio_api_url');
        $old_token = get_option('meu_repositorio_license_token');
        $new_repos = get_option('meu_repositorio_repos');

        if (!empty($old_url) && $new_repos === false) {
            $repos = [
                ['url' => $old_url, 'token' => $old_token]
            ];
            update_option('meu_repositorio_repos', $repos);
            // Mantemos as antigas para evitar quebra em versões muito antigas, 
            // mas o plugin passará a usar prioritizedly o novo array.
        }
    }

    public function mrp_add_admin_menu() {
        $update_count = $this->mrp_get_update_count();
        $menu_title = __('Repositório', 'meu-repositorio-client');

        if ($update_count > 0) {
            $bubble = " <span class='update-plugins count-{$update_count}'><span class='plugin-count'>{$update_count}</span></span>";
            $menu_title .= $bubble;
        }

        add_menu_page(__('Repositório', 'meu-repositorio-client'), $menu_title, 'manage_options', 'meu-repositorio-client', [$this, 'mrp_render_repo_page'], 'dashicons-cloud-saved', 81);
        add_submenu_page('meu-repositorio-client', __('Plugins Disponíveis', 'meu-repositorio-client'), __('Plugins Disponíveis', 'meu-repositorio-client'), 'manage_options', 'meu-repositorio-client', [$this, 'mrp_render_repo_page']);
        $this->settings_page_hook = add_submenu_page('meu-repositorio-client', __('Configurações', 'meu-repositorio-client'), __('Configurações', 'meu-repositorio-client'), 'manage_options', 'meu-repositorio-settings', 'mrp_render_settings_page');
    }

    private function mrp_get_update_count() {
        $update_count = get_transient('mrp_update_count');
        if (false !== $update_count) {
            return (int) $update_count;
        }

        $count = 0;
        $repos = get_option('meu_repositorio_repos', []);
        
        if (!empty($repos)) {
            $installed_plugins = get_plugins();
            foreach ($repos as $repo) {
                if (empty($repo['url'])) continue;
                
                $folder_scanner = new MRP_FolderScanner($repo['url'], $repo['token'] ?? '');
                $response = $folder_scanner->scan_for_plugins();
                $available_plugins = !is_wp_error($response) ? $response['plugins'] : [];

                if (!empty($available_plugins)) {
                    foreach ($available_plugins as $plugin_slug => $plugin_data) {
                        foreach ($installed_plugins as $path => $details) {
                            if (strpos($path, $plugin_slug . '/') === 0 || $details['Name'] === $plugin_data['name']) {
                                if (version_compare($plugin_data['version'], $details['Version'], '>')) {
                                    $count++;
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        set_transient('mrp_update_count', $count, HOUR_IN_SECONDS);
        return $count;
    }

    public function mrp_enqueue_admin_assets($hook) {
        $pages = ['toplevel_page_meu-repositorio-client', 'repositorio_page_meu-repositorio-settings'];
        if (strpos($hook, 'meu-repositorio') === false) {
           return;
        }

        wp_enqueue_style('meu-repositorio-admin-style', MRP_PLUGIN_URL . 'assets/css/admin-style.css', [], MRP_CLIENT_VERSION_J8K3);
        wp_enqueue_script('meu-repositorio-admin-script', MRP_PLUGIN_URL . 'assets/js/admin-scripts.js', [], MRP_CLIENT_VERSION_J8K3, true);

        wp_localize_script('meu-repositorio-admin-script', 'mrp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('meu-repositorio-nonce'),
            'text' => [
                'updating' => __('Atualizando...', 'meu-repositorio-client'),
                'installing' => __('Instalando...', 'meu-repositorio-client'),
                'success' => __('Operação realizada com sucesso!', 'meu-repositorio-client'),
                'error' => __('Ocorreu um erro:', 'meu-repositorio-client'),
                'fatal_error' => __('Erro fatal de conexão.', 'meu-repositorio-client'),
                'force_update_running' => __('Verificando...', 'meu-repositorio-client'),
                'saving' => __('Salvando...', 'meu-repositorio-client'),
                'activating' => __('Ativando...', 'meu-repositorio-client'),
            ]
        ]);
    }

    public function mrp_render_feedback_container() {
        ?>
        <div id="mrs-feedback-container">
            <p class="mrs-feedback-message mrs-feedback-success"></p>
            <p class="mrs-feedback-message mrs-feedback-error"></p>
        </div>
        <?php
    }

    public function mrp_render_repo_page() {
        delete_transient('mrp_update_count');
        ?>
        <div class="wrap mrp-wrap">
            <header class="mrp-header-bar">
                <div class="mrp-title-group">
                    <h1 class="mrp-page-title">
                        <?php esc_html_e('Plugins Disponíveis', 'meu-repositorio-client'); ?>
                    </h1>
                    <span class="mrp-version-badge" style="background: var(--mrp-primary); color: #fff; border: none; padding: 4px 10px; box-shadow: 0 2px 4px rgba(34, 113, 177, 0.2);">v<?php echo esc_html($this->plugin_version); ?></span>
                </div>
                <div class="mrp-actions-group">
                    <div class="mrp-search-box">
                        <span class="dashicons dashicons-search"></span>
                        <input type="text" id="mrp-plugin-search" placeholder="<?php esc_attr_e('Buscar plugins...', 'meu-repositorio-client'); ?>">
                    </div>
                    <button id="mrp-force-update-button" class="mrp-button mrp-button-secondary">
                        <span class="dashicons dashicons-update"></span> 
                        <?php _e('Verificar Atualizações', 'meu-repositorio-client'); ?>
                    </button>
                </div>
            </header>
            
            <div class="mrp-content-card">
                <?php 
                $repos = get_option('meu_repositorio_repos', []);
                if (empty($repos)): ?>
                    <div class="mrp-notice mrp-notice-warning">
                        <p><?php printf(__('Por favor, %sconfigure a URL do repositório%s para visualizar os plugins.', 'meu-repositorio-client'), '<a href="' . esc_url(admin_url('admin.php?page=meu-repositorio-settings')) . '">', '</a>'); ?></p>
                    </div>
                <?php else: ?>
                    <?php 
                    $all_available_plugins = [];
                    $repo_metadata = [];

                    foreach ($repos as $repo) {
                        if (empty($repo['url'])) continue;
                        $folder_scanner = new MRP_FolderScanner($repo['url'], $repo['token'] ?? '');
                        $force_refresh = isset($_GET['force-check']);
                        $response = $folder_scanner->scan_for_plugins($force_refresh);
                        
                        if (!is_wp_error($response)) {
                            // Merge plugins, ensuring we don't overwrite if multiple repos have same plugin?
                            // Actually, maybe we should keep them separate or just take the latest?
                            // For simplicity, we merge.
                            foreach ($response['plugins'] as $slug => $p) {
                                if (!isset($all_available_plugins[$slug]) || version_compare($p['version'], $all_available_plugins[$slug]['version'], '>')) {
                                    $all_available_plugins[$slug] = $p;
                                }
                            }
                            $repo_metadata[] = [
                                'url' => $repo['url'],
                                'token_name' => $response['token_name'],
                                'expires_at' => $response['expires_at']
                            ];
                        }
                    }

                    foreach ($repo_metadata as $meta) {
                        $expires_at = $meta['expires_at'];
                        if (!empty($expires_at)) {
                            $expiration_timestamp = strtotime($expires_at);
                            $formatted_date = $expiration_timestamp ? date_i18n(get_option('date_format'), $expiration_timestamp) : esc_html($expires_at);
                            
                            $days_remaining = $expiration_timestamp ? ceil(($expiration_timestamp - time()) / DAY_IN_SECONDS) : 99;
                            $class_expire = $days_remaining <= 7 ? 'mrp-expire-warning' : '';
                            
                            echo '<div class="mrp-subscription-info ' . $class_expire . '" style="margin-bottom: 10px;">';
                            printf(__('Repositório %s: Licença válida até <strong>%s</strong>.', 'meu-repositorio-client'), '<strong>'.parse_url($meta['url'], PHP_URL_HOST).'</strong>', $formatted_date);
                            if ($days_remaining <= 7) {
                                 echo ' <a href="https://wpmasters.com.br/produto/plano-mensal-wp-masters-atualizacoes-automaticas/" target="_blank" class="mrp-renew-link">' . __('Renovar Agora', 'meu-repositorio-client') . '</a>';
                            }
                            echo '</div>';
                        }
                    }
                    
                    wp_update_plugins();
                    wp_clean_plugins_cache(); 
                    $installed_plugins = get_plugins();
                    $available_plugins = $all_available_plugins;

                    if (empty($available_plugins)) {
                        $error_msg = is_wp_error($available_plugins) ? $available_plugins->get_error_message() : '';
                        if (empty($available_plugins) && !is_wp_error($available_plugins)) {
                             echo '<div class="mrp-empty-state">
                                    <span class="dashicons dashicons-info"></span>
                                    <h3>' . __('Nenhum plugin disponível', 'meu-repositorio-client') . '</h3>
                                    <p>' . sprintf(__('Não encontramos plugins para o domínio %s. Verifique seu plano.', 'meu-repositorio-client'), parse_url(home_url(), PHP_URL_HOST)) . '</p>
                                    <a href="https://api.whatsapp.com/send?phone=5512992106218&text=Ol%C3%A1!%20Tenho%20uma%20d%C3%BAvida,%20consegue%20me%20ajudar?" target="_blank" class="mrp-button mrp-button-primary">' . __('Falar com Suporte', 'meu-repositorio-client') . '</a>
                                   </div>';
                        } else {
                             echo '<div class="mrp-notice mrp-notice-error"><p>' . esc_html($error_msg) . '</p></div>';
                        }
                    } else {
                        // Filtra apenas plugins acessíveis
                        $accessible_plugins = [];
                        foreach ($available_plugins as $slug => $data) {
                            if (!isset($data['user_has_access']) || $data['user_has_access'] !== false) {
                                $accessible_plugins[$slug] = $data;
                            }
                        }
                        ?>

                        <div class="mrp-tabs">
                            <div class="mrp-tab active" style="cursor: default; border-bottom-color: var(--mrp-primary);">
                                <?php _e('Plugins Disponíveis', 'meu-repositorio-client'); ?> 
                                <span class="mrp-version-badge" style="margin-left:5px; font-size:10px;"><?php echo count($accessible_plugins); ?></span>
                            </div>
                            <div class="mrp-repo-badges-container" style="display: flex; gap: 10px; flex-wrap: wrap; margin-left: auto; align-items: center; padding: 0 15px;">
                                <?php foreach ($repo_metadata as $meta): 
                                    $badge_text = !empty($meta['token_name']) ? $meta['token_name'] : parse_url($meta['url'], PHP_URL_HOST);
                                    if ($badge_text): ?>
                                        <div class="mrp-repo-badge" style="margin: 0; padding: 5px 12px; border: 1px solid var(--mrp-border); border-radius: 20px; background: #f8fafc; height: auto;" title="<?php printf(esc_attr__('Conectado ao repositório: %s', 'meu-repositorio-client'), $meta['url']); ?>">
                                            <span class="mrp-dot" style="margin: 0;"></span>
                                            <?php echo esc_html($badge_text); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mrp-tab-content active" style="border-top-left-radius: 0;">
                        <?php if (empty($accessible_plugins)): ?>
                             <div class="mrp-empty-state">
                                <span class="dashicons dashicons-yes"></span>
                                <p><?php _e('Você já possui todos os plugins ou não há plugins disponíveis.', 'meu-repositorio-client'); ?></p>
                            </div>
                        <?php else: ?>
                        <div class="mrp-table-scroll">
                            <table class="mrp-table">
                                <thead>
                                    <tr>
                                        <th style="width: 20%;"><?php _e('Plugin', 'meu-repositorio-client'); ?></th>
                                    <th style="width: 15%;"><?php _e('Versão Instalada', 'meu-repositorio-client'); ?></th>
                                    <th style="width: 10%;"><?php _e('Disponível', 'meu-repositorio-client'); ?></th>
                                    <th style="width: 15%; text-align:center;"><?php _e('Autor', 'meu-repositorio-client'); ?></th>
                                    <th style="width: 10%; text-align:center;"><?php _e('Tipo', 'meu-repositorio-client'); ?></th>
                                    <th style="width: 10%; text-align:center;"><?php _e('Limites', 'meu-repositorio-client'); ?></th>
                                    <th style="width: 20%; text-align:right;"><?php _e('Ação', 'meu-repositorio-client'); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($accessible_plugins as $plugin_slug => $plugin_data) {
                                    $installed_version = '—';
                                    $plugin_file_path = '';
                                    $plugin_slug_path = ''; // Slug da pasta
                                    $plugin_installed = false;
                                    $is_active = false;

                                    // LÓGICA DE DETECÇÃO REFORÇADA (Baseada na original)
                                    foreach ($installed_plugins as $path => $details) {
                                        $matches = false;
                                        $folder_name = dirname($path);
                                        
                                        // 1. Nome exato da pasta
                                        if ($folder_name === $plugin_slug) {
                                            $matches = true;
                                        }
                                        // 2. Nome exato do Plugin (definido no arquivo principal)
                                        elseif ($details['Name'] === $plugin_data['name']) {
                                            $matches = true;
                                        }
                                        // 3. TextDomain corresponde ao slug
                                        elseif (isset($details['TextDomain']) && $details['TextDomain'] === $plugin_slug) {
                                            $matches = true;
                                        }
                                        // 4. Caminho contém o slug (fallback para pastas renomeadas ou github)
                                        elseif (strpos($path, $plugin_slug . '/') !== false || strpos($path, '/' . $plugin_slug . '.php') !== false) {
                                            $matches = true;
                                        }

                                        if ($matches) {
                                            $installed_version = $details['Version'];
                                            $plugin_file_path = $path;
                                            $plugin_slug_path = $folder_name;
                                            $plugin_installed = true;
                                            $is_active = is_plugin_active($path);
                                            break;
                                        }
                                    }
                                    
                                    // Verifica backup usando instância temporária
                                    $has_backup = false;
                                    if ($plugin_installed && !empty($plugin_slug_path)) {
                                        $temp_updater = new PluginUpdater();
                                        $has_backup = $temp_updater->has_backup($plugin_slug_path);
                                    }
                                    
                                    $is_update_available = $plugin_installed && version_compare($plugin_data['version'], $installed_version, '>');
                                    
                                    // Definição da Classe do Badge
                                    $badge_class = 'status-neutral'; // Padrão (não instalado ou desconhecido)
                                    if ($plugin_installed) {
                                        if ($is_update_available) {
                                            $badge_class = 'status-outdated'; // VERMELHO (Desatualizado)
                                        } else {
                                            $badge_class = 'status-success'; // VERDE (Atualizado)
                                        }
                                    }

                                    // Limites
                                    $limits = isset($plugin_data['download_limits']) ? $plugin_data['download_limits'] : null;
                                    $can_download = $limits && isset($limits['can_download']) ? $limits['can_download'] : true;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="mrp-plugin-name"><?php echo esc_html($plugin_data['name']); ?></span>
                                            <?php if (isset($plugin_data['repo_url'])): ?>
                                                <div class="small text-muted" style="font-size: 9px; opacity: 0.7; margin-top: 2px; display: flex; align-items: center; gap: 4px;">
                                                    <span class="dashicons dashicons-database" style="font-size: 11px; width: 11px; height: 11px;"></span>
                                                    <?php echo parse_url($plugin_data['repo_url'], PHP_URL_HOST); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="mrp-status-pill <?php echo $badge_class; ?>" style="min-width: 60px; justify-content: center;">
                                                <i class="dashicons dashicons-admin-plugins" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></i>
                                                <?php echo esc_html($installed_version); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mrp-status-pill status-info" style="min-width: 60px; justify-content: center;">
                                                <i class="dashicons dashicons-cloud" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></i>
                                                <?php echo esc_html($plugin_data['version']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if (!empty($plugin_data['author_name'])): ?>
                                                <?php if (!empty($plugin_data['author_url'])): ?>
                                                    <a href="<?php echo esc_url($plugin_data['author_url']); ?>" target="_blank" class="mrp-author-badge" style="text-decoration: none; display: inline-block; background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                                        <?php echo esc_html($plugin_data['author_name']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="mrp-author-badge" style="display: inline-block; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                                        <?php echo esc_html($plugin_data['author_name']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="mrp-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php
                                            $visibility = isset($plugin_data['visibility']) ? $plugin_data['visibility'] : 'privado';
                                            $badge_label = 'Privado';
                                            $badge_style = 'background-color: #fcf9e8; color: #996800; border: 1px solid #996800; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600;';
                                            
                                            if ($visibility === 'publico') {
                                                $badge_label = 'Público';
                                                $badge_style = 'background-color: #f0f6fc; color: #00a32a; border: 1px solid #00a32a; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600;';
                                            } elseif ($visibility === 'restrito') {
                                                $badge_label = 'Restrito';
                                                $badge_style = 'background-color: #fcf0f1; color: #d63638; border: 1px solid #d63638; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600;';
                                            }
                                            
                                            if ($visibility !== 'restrito') {
                                                echo '<span style="' . esc_attr($badge_style) . '">' . esc_html($badge_label) . '</span>';
                                            }
                                            ?>
                                        </td>
                                        <td class="col-center">
                                            <?php if ($limits && isset($limits['plugin_site_used'])): ?>
                                                <div class="mrp-limits-box">
                                                    <span title="Downloads deste plugin neste site (diário)" style="display: inline-block; margin-right: 8px;">
                                                        <i class="dashicons dashicons-admin-site-alt3" style="color: <?php echo $can_download ? '#4ade80' : '#ef4444'; ?>"></i> 
                                                        <?php echo $limits['plugin_site_used']; ?>/<?php echo $limits['plugin_site_limit']; ?>
                                                    </span>
                                                    <span title="Downloads totais do token (diário)" style="display: inline-block; color: #64748b;">
                                                        <i class="dashicons dashicons-admin-network" style="color: <?php echo ($limits['token_used'] < $limits['token_limit']) ? '#4ade80' : '#ef4444'; ?>"></i> 
                                                        <?php echo $limits['token_used']; ?>/<?php echo $limits['token_limit']; ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span class="mrp-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-right">
                                            <?php if ($plugin_installed): ?>
                                                <?php if ($has_backup): ?>
                                                <button class="mrp-button mrp-button-secondary mrp-button-sm mrp-rollback-button" 
                                                        data-slug="<?php echo esc_attr($plugin_slug_path); ?>" 
                                                        title="<?php _e('Rollback / Backups', 'meu-repositorio-client'); ?>">
                                                    <span class="dashicons dashicons-backup"></span>
                                                </button>
                                                <?php endif; ?>
                                                <?php if ($is_update_available): ?>
                                                    <button class="mrp-button mrp-button-primary mrp-button-sm plugin-action-button" 
                                                            data-state="update" 
                                                            data-plugin-file="<?php echo esc_attr($plugin_file_path); ?>" 
                                                            data-plugin-url="<?php echo esc_url($plugin_data['path']); ?>"
                                                            <?php echo !$can_download ? 'disabled' : ''; ?>>
                                                        <?php _e('Atualizar', 'meu-repositorio-client'); ?>
                                                    </button>
                                                <?php elseif (!$is_active): ?>
                                                    <button class="mrp-button mrp-button-secondary mrp-button-sm plugin-action-button" data-state="activate" data-plugin-file="<?php echo esc_attr($plugin_file_path); ?>"><?php _e('Ativar', 'meu-repositorio-client'); ?></button>
                                                <?php else: ?>
                                                    <span class="mrp-active-check"><span class="dashicons dashicons-yes"></span> <?php _e('Ativo', 'meu-repositorio-client'); ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <button class="mrp-button mrp-button-primary mrp-button-sm plugin-action-button" 
                                                        data-state="install" 
                                                        data-plugin-file="" 
                                                        data-plugin-url="<?php echo esc_url($plugin_data['path']); ?>"
                                                        <?php echo !$can_download ? 'disabled' : ''; ?>>
                                                    <?php _e('Instalar', 'meu-repositorio-client'); ?>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if (!$can_download): ?>
                                                <div class="mrp-limit-reached"><?php _e('Limite excedido', 'meu-repositorio-client'); ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                        </div>
                    <?php } ?>
                <?php endif; ?>
            </div>
            
            <footer class="mrp-footer">
                Desenvolvido por <a href="https://wpmasters.com.br" target="_blank">WP Masters</a>
            </footer>
        </div>
        <?php
    }

    public function mrp_check_for_updates($transient) {
        $repos = get_option('meu_repositorio_repos', []);
        if (empty($transient->checked) || empty($repos)) {
            return $transient;
        }
        delete_transient('mrp_update_count');
        
        $all_available_plugins = [];
        foreach ($repos as $repo) {
            if (empty($repo['url'])) continue;
            $folder_scanner = new MRP_FolderScanner($repo['url'], $repo['token'] ?? '');
            $response = $folder_scanner->scan_for_plugins();
            if (!is_wp_error($response)) {
                foreach ($response['plugins'] as $slug => $p) {
                    if (!isset($all_available_plugins[$slug]) || version_compare($p['version'], $all_available_plugins[$slug]['version'], '>')) {
                        $all_available_plugins[$slug] = $p;
                    }
                }
            }
        }

        if (empty($all_available_plugins)) {
            return $transient;
        }

        $installed_plugins = get_plugins();
        foreach ($all_available_plugins as $plugin_slug => $plugin_data) {
            $plugin_file_path = false;
            foreach ($installed_plugins as $path => $details) {
                if (strpos($path, $plugin_slug . '/') === 0 || $details['Name'] === $plugin_data['name']) {
                    $plugin_file_path = $path;
                    break;
                }
            }
            if ($plugin_file_path && isset($installed_plugins[$plugin_file_path]) && version_compare($plugin_data['version'], $installed_plugins[$plugin_file_path]['Version'], '>')) {
                $update_info = new \stdClass();
                $update_info->slug = $plugin_slug;
                $update_info->plugin = $plugin_file_path;
                $update_info->new_version = $plugin_data['version'];
                $update_info->package = $plugin_data['path'];
                $transient->response[$plugin_file_path] = $update_info;
            }
        }
        return $transient;
    }

    public function mrp_handle_activate_plugin() {
        check_ajax_referer('meu-repositorio-nonce', 'security');
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(['message' => __('Permissão insuficiente.', 'meu-repositorio-client')]);
        }
        $plugin_file = isset($_POST['plugin_file']) ? sanitize_text_field($_POST['plugin_file']) : '';
        if (empty($plugin_file)) {
            wp_send_json_error(['message' => __('Arquivo não especificado.', 'meu-repositorio-client')]);
        }
        
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($plugin_file);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => __('Ativado com sucesso!', 'meu-repositorio-client')]);
    }

    public function mrp_handle_update_plugin() {
        check_ajax_referer('meu-repositorio-nonce', 'security');
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => __('Permissão insuficiente.', 'meu-repositorio-client')]);
        }
        $plugin_file = isset($_POST['plugin_file']) ? sanitize_text_field($_POST['plugin_file']) : '';
        $package_url = isset($_POST['plugin_url']) ? esc_url_raw($_POST['plugin_url']) : '';
        
        if (empty($package_url)) {
            wp_send_json_error(['message' => __('URL do pacote ausente.', 'meu-repositorio-client')]);
        }
        $plugin_updater = new PluginUpdater();
        $result = $plugin_updater->update_plugin($plugin_file, $package_url);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        $cache = new MRP_API_Cache();
        $cache->clear_all();
        delete_transient('mrp_update_count');
        
        wp_send_json_success(['message' => __('Atualização concluída!', 'meu-repositorio-client')]);
    }

    public function mrp_force_update_plugins_ajax_handler() {
        check_ajax_referer('meu-repositorio-nonce', 'security');
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => __('Permissão insuficiente.', 'meu-repositorio-client')]);
        }
        delete_site_transient('update_plugins');
        $cache = new MRP_API_Cache();
        $cache->clear_all();
        wp_send_json_success(['message' => __('Cache limpo com sucesso.', 'meu-repositorio-client')]);
    }

    public function mrp_handle_get_backups() {
        check_ajax_referer('meu-repositorio-nonce', 'security');
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => __('Permissão insuficiente.', 'meu-repositorio-client')]);
        }

        $plugin_slug = isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : '';
        if (empty($plugin_slug)) {
            wp_send_json_error(['message' => __('Slug do plugin não fornecido.', 'meu-repositorio-client')]);
        }

        $plugin_updater = new PluginUpdater();
        $backups = $plugin_updater->get_backups($plugin_slug);

        wp_send_json_success(['backups' => $backups]);
    }

    public function mrp_handle_rollback_plugin() {
        check_ajax_referer('meu-repositorio-nonce', 'security');
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => __('Permissão insuficiente.', 'meu-repositorio-client')]);
        }

        $plugin_slug = isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : '';
        $backup_file = isset($_POST['backup_file']) ? sanitize_text_field($_POST['backup_file']) : '';

        if (empty($plugin_slug) || empty($backup_file)) {
            wp_send_json_error(['message' => __('Dados insuficientes para o rollback.', 'meu-repositorio-client')]);
        }

        $plugin_updater = new PluginUpdater();
        $result = $plugin_updater->restore_backup($plugin_slug, $backup_file);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

         delete_transient('mrp_update_count');

        wp_send_json_success(['message' => __('Rollback realizado com sucesso!', 'meu-repositorio-client')]);
    }

    /**
     * Executes automatic updates via Cron.
     * Echoes status for the cron log.
     */
    public function mrp_run_automatic_updates() {
        $repos = get_option('meu_repositorio_repos', []);
        if (empty($repos)) {
            echo "ERRO: Nenhum repositório configurado.\n";
            return;
        }

        $all_available_plugins = [];
        foreach ($repos as $repo) {
            if (empty($repo['url'])) continue;
            echo "Buscando atualizações no repositório: " . $repo['url'] . "\n";
            $folder_scanner = new MRP_FolderScanner($repo['url'], $repo['token'] ?? '');
            $response = $folder_scanner->scan_for_plugins(true);

            if (!is_wp_error($response)) {
                foreach ($response['plugins'] as $slug => $p) {
                    if (!isset($all_available_plugins[$slug]) || version_compare($p['version'], $all_available_plugins[$slug]['version'], '>')) {
                        $all_available_plugins[$slug] = $p;
                    }
                }
            } else {
                echo "ERRO ao buscar plugins em {$repo['url']}: " . $response->get_error_message() . "\n";
            }
        }

        if (empty($all_available_plugins)) {
            echo "Nenhum plugin encontrado nos repositórios.\n";
            return;
        }

        $available_plugins = $all_available_plugins;

        $installed_plugins = get_plugins();
        $plugin_updater = new PluginUpdater();
        $updates_count = 0;

        foreach ($available_plugins as $plugin_slug => $plugin_data) {
            $plugin_file_path = false;
            
            // Tenta encontrar o plugin instalado
            foreach ($installed_plugins as $path => $details) {
                // Mesma lógica de detecção do mrp_render_repo_page
                $folder_name = dirname($path);
                
                if ($folder_name === $plugin_slug || 
                    $details['Name'] === $plugin_data['name'] || 
                    (isset($details['TextDomain']) && $details['TextDomain'] === $plugin_slug) ||
                    (strpos($path, $plugin_slug . '/') !== false)) {
                    
                    $plugin_file_path = $path;
                    break;
                }
            }

            if ($plugin_file_path && isset($installed_plugins[$plugin_file_path])) {
                $current_version = $installed_plugins[$plugin_file_path]['Version'];
                $remote_version = $plugin_data['version'];

                if (version_compare($remote_version, $current_version, '>')) {
                    echo "Atualização encontrada para {$plugin_data['name']}: v{$current_version} -> v{$remote_version}\n";
                    
                    $package_url = $plugin_data['path'];
                    
                    // Verifica limites se houver
                    if (isset($plugin_data['download_limits']) && isset($plugin_data['download_limits']['can_download']) && !$plugin_data['download_limits']['can_download']) {
                        echo "  [PULADO] Limite de download excedido para este plugin.\n";
                        continue;
                    }

                    echo "  Iniciando atualização...\n";
                    
                    // O PluginUpdater já faz backup se implementado
                    $result = $plugin_updater->update_plugin($plugin_file_path, $package_url);

                    if (is_wp_error($result)) {
                        echo "  [ERRO] Falha na atualização: " . $result->get_error_message() . "\n";
                    } else {
                        echo "  [SUCESSO] Plugin atualizado com sucesso.\n";
                        $updates_count++;
                    }

                }
            }
        }

        if ($updates_count === 0) {
            echo "Todos os plugins estão atualizados.\n";
        } else {
            // Limpa caches
            wp_clean_plugins_cache();
            $cache = new MRP_API_Cache();
            $cache->clear_all();
            delete_transient('mrp_update_count');
            echo "\nTotal de plugins atualizados: {$updates_count}\n";
        }
    }

    public function mrp_handle_save_settings_ajax() {
        check_ajax_referer('meu-repositorio-nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissão negada.', 'meu-repositorio-client')]);
        }

        $repos_json = isset($_POST['meu_repositorio_repos']) ? $_POST['meu_repositorio_repos'] : '[]';
        $repos = json_decode(stripslashes($repos_json), true);
        
        if (!is_array($repos)) {
            $repos = [];
        }

        // Sanitização básica
        $sanitized_repos = [];
        foreach ($repos as $repo) {
            if (!empty($repo['url'])) {
                $sanitized_repos[] = [
                    'url' => esc_url_raw(trim($repo['url'])),
                    'token' => sanitize_text_field($repo['token'] ?? '')
                ];
            }
        }

        $auto_update = isset($_POST['meu_repositorio_auto_update_enabled']) ? 1 : 0;
        $cache_duration = isset($_POST['meu_repositorio_cache_duration']) ? absint($_POST['meu_repositorio_cache_duration']) : 3600;

        update_option('meu_repositorio_repos', $sanitized_repos);
        update_option('meu_repositorio_auto_update_enabled', $auto_update);
        update_option('meu_repositorio_cache_duration', $cache_duration);

        // O hook update_option_{option_name} disparará mrp_clear_cache_on_settings_save() automaticamente
        
        wp_send_json_success(['message' => __('Configurações salvas com sucesso!', 'meu-repositorio-client')]);
    }

    public static function mrp_deactivate() {
        delete_site_transient('update_plugins');
        delete_transient('mrp_update_count');
    }
    
    /**
     * Limpa todos os caches e transientes quando as configurações de conexão são alteradas.
     */
    public function mrp_clear_cache_on_settings_save() {
        $cache = new MRP_API_Cache();
        $cache->clear_all();
        delete_site_transient('update_plugins');
        delete_transient('mrp_update_count');
        // Force refresh of available plugins cache by scanning immediately? 
        // Better just clear so next load fetches fresh.
    }
}

function mrp_run_plugin() {
    MeuRepositorioPlugin::get_instance();
}
add_action('plugins_loaded', 'mrp_run_plugin');
register_deactivation_hook(__FILE__, ['MeuRepositorioPlugin', 'mrp_deactivate']);