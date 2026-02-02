<?php
/**
 * Classe para escanear um repositório de plugins via JSON API.
 * * @version 2.1 - Adaptado para novo formato de resposta (Object -> plugins)
 */

class MRP_FolderScanner {
    
    private $api_url;
    private $token;
    
    public function __construct($url, $token = '') {
        $this->api_url = $url;
        $this->token = $token;
    }
    
    public function scan_for_plugins($force_refresh = false) {
        if (empty($this->api_url)) {
            return new WP_Error('api_not_configured', 'A URL da API do repositório não está configurada.');
        }

        $cache = new MRP_API_Cache();
        $cache_key = 'plugins_list_' . md5($this->api_url . $this->token);
        
        return $cache->get_or_fetch($cache_key, function() {
            return $this->fetch_from_api();
        }, $force_refresh);
    }
    
    private function fetch_from_api() {
        $plugins = [];
        $args = ['requesting_site_url' => home_url()];
        
        if (!empty($this->token)) {
            $args['client_token'] = $this->token;
        }

        $api_url_with_tracking = add_query_arg($args, $this->api_url);
        $response = wp_remote_get($api_url_with_tracking, ['timeout' => 25, 'sslverify' => false]);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Erro ao acessar o repositório (' . $this->api_url . '): ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Erro ao decodificar o JSON: ' . json_last_error_msg());
        }

        $plugin_source = [];
        $token_name = '';
        
        if (is_object($data) && isset($data->plugins) && is_array($data->plugins)) {
            $plugin_source = $data->plugins;
            $token_name = $data->token_name ?? '';
        } elseif (is_array($data)) {
            $plugin_source = $data;
        } else {
             return new WP_Error('data_format_error', 'Formato JSON inválido.');
        }

        $expires_at_header = wp_remote_retrieve_header($response, 'X-MRP-Token-Expires');
        $expires_at_body = null;

        foreach ($plugin_source as $plugin_item) {
            if (empty($expires_at_body) && isset($plugin_item->token_expires_at)) {
                $expires_at_body = $plugin_item->token_expires_at;
            }
            
            $has_title = isset($plugin_item->title->rendered);
            $has_slug = isset($plugin_item->slug);
            $has_version = isset($plugin_item->meta->mtf_versao);
            $has_url = !empty($plugin_item->meta->mtf_url);
            $user_has_access = isset($plugin_item->user_has_access) ? (bool) $plugin_item->user_has_access : true;
            
            if ($has_title && $has_slug && $has_version) {
                $plugin_slug = $plugin_item->slug;
                $plugins[$plugin_slug] = [
                    'name'    => $plugin_item->title->rendered,
                    'version' => $plugin_item->meta->mtf_versao,
                    'path'    => $has_url ? $plugin_item->meta->mtf_url : '',
                    'slug'    => $plugin_slug,
                    'download_limits' => isset($plugin_item->download_limits) ? (array) $plugin_item->download_limits : null,
                    'visibility' => isset($plugin_item->visibility) ? $plugin_item->visibility : 'privado',
                    'user_has_access' => $user_has_access,
                    'author_name' => $plugin_item->meta->author_name ?? '',
                    'author_url' => $plugin_item->meta->author_url ?? '',
                    'repo_url' => $this->api_url // Store which repo it belongs to
                ];
            }
        }
        
        return [
            'plugins' => $plugins,
            'token_name' => $token_name,
            'expires_at' => $expires_at_body ? $expires_at_body : $expires_at_header
        ];
    }
}