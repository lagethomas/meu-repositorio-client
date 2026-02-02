<?php
/**
 * Sistema de Cache para Respostas da API
 * @version 1.0
 */
class MRP_API_Cache {
    
    private $cache_duration = 3600; // 1 hora em segundos
    private $cache_prefix = 'mrp_api_cache_';

    public function __construct() {
        $this->cache_duration = (int) get_option('meu_repositorio_cache_duration', 3600);
    }
    
    /**
     * Busca dados do cache ou da API
     * @param string $cache_key Chave única para o cache
     * @param callable $api_callback Função que faz a chamada à API
     * @param bool $force_refresh Forçar atualização ignorando cache
     * @return mixed Dados da API ou cache
     */
    public function get_or_fetch($cache_key, $api_callback, $force_refresh = false) {
        $full_cache_key = $this->cache_prefix . md5($cache_key);
        
        // Verifica se deve usar cache
        if (!$force_refresh) {
            $cached_data = get_transient($full_cache_key);
            
            if ($cached_data !== false) {
                error_log("[MRP Cache] Cache HIT for key: {$cache_key}");
                return $cached_data;
            }
        }
        
        // Cache miss ou refresh forçado - busca da API
        error_log("[MRP Cache] Cache MISS for key: {$cache_key}");
        $fresh_data = call_user_func($api_callback);
        
        // Armazena no cache apenas se não for erro
        if (!is_wp_error($fresh_data)) {
            set_transient($full_cache_key, $fresh_data, $this->cache_duration);
            
            // Armazena timestamp da última atualização
            update_option($this->cache_prefix . 'last_update_' . md5($cache_key), time());
        }
        
        return $fresh_data;
    }
    
    /**
     * Invalida cache específico
     */
    public function invalidate($cache_key) {
        $full_cache_key = $this->cache_prefix . md5($cache_key);
        delete_transient($full_cache_key);
        error_log("[MRP Cache] Cache invalidated for key: {$cache_key}");
    }
    
    /**
     * Limpa todo o cache da API
     */
    public function clear_all() {
        global $wpdb;
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . $this->cache_prefix) . '%',
                $wpdb->esc_like('_transient_timeout_' . $this->cache_prefix) . '%'
            )
        );
        
        error_log("[MRP Cache] All cache cleared");
    }
    
    /**
     * Retorna informações sobre o cache
     */
    public function get_cache_info($cache_key) {
        $full_cache_key = $this->cache_prefix . md5($cache_key);
        $cached_data = get_transient($full_cache_key);
        $last_update = get_option($this->cache_prefix . 'last_update_' . md5($cache_key), 0);
        
        return [
            'exists' => $cached_data !== false,
            'last_update' => $last_update,
            'age_seconds' => $last_update ? (time() - $last_update) : null,
            'expires_in' => $cached_data !== false ? $this->get_expiration_time($full_cache_key) : null
        ];
    }
    
    /**
     * Calcula tempo restante até expiração do cache
     */
    private function get_expiration_time($transient_key) {
        global $wpdb;
        
        $timeout = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            '_transient_timeout_' . $transient_key
        ));
        
        if ($timeout) {
            return max(0, $timeout - time());
        }
        
        return 0;
    }
    
    /**
     * Define duração customizada do cache
     */
    public function set_cache_duration($seconds) {
        $this->cache_duration = absint($seconds);
    }
}
