<?php
/**
 * Classe para gerenciar a instalação e atualização de plugins.
 * @version 2.0 - Garante o envio de home_url() para rastreamento preciso.
 */
class PluginUpdater {

    /**
     * @var string A URL do pacote do plugin a ser baixado.
     */
    private $package_url;

    /**
     * Atualiza um plugin manualmente: baixa, descompacta, substitui e reativa.
     *
     * @param string $plugin_file_path O caminho do arquivo principal do plugin (ex: meu-plugin/meu-plugin.php).
     * @param string $package_url A URL para o arquivo .zip do plugin.
     * @return bool|WP_Error Retorna true em sucesso, ou um objeto WP_Error em caso de falha.
     */
    public function update_plugin($plugin_file_path, $package_url) {
        error_log('[MRP Updater V5] Iniciando processo de atualização MANUAL.');
        error_log('[MRP Updater V5] Plugin alvo: ' . $plugin_file_path);
        error_log('[MRP Updater V5] URL do pacote: ' . $package_url);

        // Tenta aumentar o limite de memória para evitar erro no PclZip
        @ini_set('memory_limit', '1024M');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        // É necessário garantir que as funções de cópia de diretório estejam disponíveis
        if (!function_exists('copy_dir')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // Inclui funções auxiliares como copy_dir
        }

        WP_Filesystem();
        global $wp_filesystem;

        $plugins_dir = $wp_filesystem->wp_plugins_dir();
        if (!$plugins_dir) {
            return new WP_Error('no_plugins_dir', 'Não foi possível determinar o diretório de plugins.');
        }

        // $plugin_path = dirname($plugins_dir . $plugin_file_path); // REMOVIDO: Calculado dinamicamente agora.
        
        $is_active = is_plugin_active($plugin_file_path);
        if ($is_active) {
            deactivate_plugins($plugin_file_path, true);
            error_log('[MRP Updater V5] Plugin desativado com sucesso.');
        }

        // --- Create Backup Before Update (apenas se for atualização, não instalação) ---
        if (!empty($plugin_file_path)) {
            $this->create_backup($plugin_file_path);
        } else {
            error_log('[MRP Updater V5] Pulando backup (instalação nova).');
        }

        // --- Etapa de Download Melhorada ---
        error_log('[MRP Updater V5] Baixando pacote com wp_remote_get e sslverify=false.');
        
        // Os parâmetros 'token', 'client_token' e 'requesting_site_url' já vêm embutidos na URL pelo servidor V3.
        // Adicionar novamente aqui pode sobrescrever tokens corretos por tokens globais antigos.
        // Apenas garantimos que o home_url() seja enviado se não existir, mas o ideal é confiar na URL do servidor.

        $response = wp_remote_get($package_url, [
            'timeout'    => 300,
            'sslverify'  => false,
            'user-agent' => 'MeuRepositorioClient/' . MRP_VERSION . '; ' . get_bloginfo('url')
        ]);

        if (is_wp_error($response)) {
            error_log('[MRP Updater V5] ERRO em wp_remote_get: ' . $response->get_error_message());
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('[MRP Updater V5] ERRO: O servidor retornou o código de status HTTP ' . $response_code);
            
            // Log headers to debug errors
            $headers = wp_remote_retrieve_headers($response);
            error_log('[MRP Updater V5] Headers da resposta de erro: ' . print_r($headers, true));
            
            // Tratamento especial para erro 429 (Rate Limit)
            if ($response_code === 429) {
                $block_reason = isset($headers['x-mrs-block-reason']) ? $headers['x-mrs-block-reason'] : 'Limite de downloads atingido';
                $error_message = 'Limite de downloads atingido. Razão: ' . $block_reason;
                
                if (strpos($block_reason, 'Plugin/Site') !== false) {
                    $error_message = 'Este site já atingiu o limite de downloads para este plugin. Entre em contato com o administrador do repositório.';
                } elseif (strpos($block_reason, 'Token') !== false) {
                    $error_message = 'O token atingiu o limite de downloads. Entre em contato com o administrador do repositório.';
                }
                
                return new WP_Error('download_limit_exceeded', $error_message, ['status' => 429]);
            }
            
            return new WP_Error('download_failed_http', 'Falha no download. O servidor respondeu com o código: ' . $response_code);
        }

        // Salva o conteúdo em um arquivo temporário
        $package_data = wp_remote_retrieve_body($response);
        
        error_log('[MRP Updater V5] Tamanho do pacote baixado: ' . strlen($package_data) . ' bytes');
        
        // Gera nome de arquivo limpo e único
        // Extrai plugin_id da URL se possível, senão usa hash
        $plugin_id = '';
        if (preg_match('/plugin_id=(\d+)/', $package_url, $matches)) {
            $plugin_id = $matches[1];
        }
        $unique_suffix = $plugin_id ? 'plugin-' . $plugin_id : md5($package_url);
        $temp_filename = 'mrp-download-' . $unique_suffix . '-' . time() . '.zip';
        $temp_file = trailingslashit(get_temp_dir()) . $temp_filename;
        
        error_log('[MRP Updater V5] Nome do arquivo temp: ' . $temp_filename);
        error_log('[MRP Updater V5] Caminho completo temp: ' . $temp_file);
        error_log('[MRP Updater V5] Diretório temp: ' . get_temp_dir());
        
        // Garante que o diretório temp existe e é gravável
        $temp_dir = get_temp_dir();
        if (!is_dir($temp_dir)) {
            error_log('[MRP Updater V5] ERRO: Diretório temporário não existe: ' . $temp_dir);
            return new WP_Error('temp_dir_failed', 'Diretório temporário não existe.');
        }
        if (!is_writable($temp_dir)) {
            error_log('[MRP Updater V5] ERRO: Diretório temporário não é gravável: ' . $temp_dir);
            return new WP_Error('temp_dir_failed', 'Diretório temporário não é gravável.');
        }
        
        // Verifica se já existe algo com esse nome
        if (file_exists($temp_file)) {
            error_log('[MRP Updater V5] AVISO: Arquivo temp já existe, removendo: ' . $temp_file);
            if (is_dir($temp_file)) {
                error_log('[MRP Updater V5] ERRO CRÍTICO: O caminho temp é um DIRETÓRIO!');
                // Tenta remover o diretório
                $wp_filesystem->delete($temp_file, true);
            } else {
                @unlink($temp_file);
            }
        }
        
        // Tenta salvar usando WP_Filesystem primeiro
        error_log('[MRP Updater V5] Tentando salvar com WP_Filesystem...');
        $write_result = $wp_filesystem->put_contents($temp_file, $package_data);
        
        if (!$write_result) {
            error_log('[MRP Updater V5] WP_Filesystem falhou, tentando file_put_contents nativo...');
            // Fallback para file_put_contents nativo
            $write_result = @file_put_contents($temp_file, $package_data);
            if (!$write_result) {
                error_log('[MRP Updater V5] ERRO: Ambos os métodos falharam ao salvar o arquivo.');
                return new WP_Error('write_temp_failed', 'Falha ao salvar o pacote.');
            }
            error_log('[MRP Updater V5] file_put_contents nativo funcionou!');
        } else {
            error_log('[MRP Updater V5] WP_Filesystem funcionou!');
        }
        
        // Verifica se o arquivo foi realmente criado
        clearstatcache(true, $temp_file);
        
        if (!file_exists($temp_file)) {
            error_log('[MRP Updater V5] ERRO: Arquivo não existe após salvar!');
            return new WP_Error('temp_file_not_created', 'Arquivo temporário não foi criado.');
        }
        
        if (is_dir($temp_file)) {
            error_log('[MRP Updater V5] ERRO: O caminho é um DIRETÓRIO após salvar!');
            error_log('[MRP Updater V5] Listando conteúdo do diretório:');
            $files = scandir($temp_file);
            error_log('[MRP Updater V5] Conteúdo: ' . print_r($files, true));
            return new WP_Error('temp_file_is_dir', 'Caminho temporário é um diretório.');
        }
        
        if (!is_file($temp_file)) {
            error_log('[MRP Updater V5] ERRO: Não é um arquivo válido!');
            return new WP_Error('temp_file_invalid', 'Caminho temporário inválido.');
        }
        
        $file_size = filesize($temp_file);
        error_log('[MRP Updater V5] Arquivo criado com sucesso! Tamanho: ' . $file_size . ' bytes');
        error_log('[MRP Updater V5] Pacote salvo em: ' . $temp_file);
        
        // DEBUG: Verificar cabeçalho do arquivo
        $file_header = @file_get_contents($temp_file, false, null, 0, 50);
        if ($file_header === false) {
            error_log('[MRP Updater V5] ERRO: Não foi possível ler o arquivo temporário.');
            return new WP_Error('read_temp_failed', 'Não foi possível ler o arquivo baixado.');
        }
        error_log('[MRP Updater V5] Primeiros 50 bytes do arquivo: ' . bin2hex($file_header));
        error_log('[MRP Updater V5] Conteúdo texto (parcial): ' . substr($file_header, 0, 50));
        
        if (strpos($file_header, 'PK') !== 0) {
            error_log('[MRP Updater V5] AVISO: O arquivo baixado não parece ser um ZIP válido (não começa com PK).');
        }
        // --- Fim da Etapa de Download Melhorada ---

        $temp_unzip_dir = get_temp_dir() . 'mrp_unzip_' . time();
        error_log('[MRP Updater V5] Descompactando para: ' . $temp_unzip_dir);
        $unzip_result = unzip_file($temp_file, $temp_unzip_dir);
        $wp_filesystem->delete($temp_file);

        if (is_wp_error($unzip_result)) {
            error_log('[MRP Updater V5] ERRO ao descompactar: ' . $unzip_result->get_error_message());
            return $unzip_result;
        }

        $unzipped_folders = $wp_filesystem->dirlist($temp_unzip_dir);
        if (!$unzipped_folders || !is_array($unzipped_folders)) {
             return new WP_Error('unzip_failed', 'Falha ao listar o conteúdo do diretório descompactado.');
        }
        $source_dir_name = key($unzipped_folders);
        $source_path = trailingslashit($temp_unzip_dir) . $source_dir_name;
        error_log('[MRP Updater V5] Caminho de origem descompactado: ' . $source_path);

        // --- MODIFICAÇÃO: Determinar o caminho de destino baseado no zip ---
        // Isso permite instalação (quando não temos plugin_path) e atualização segura.
        $target_plugin_path = trailingslashit($plugins_dir) . $source_dir_name;
        error_log('[MRP Updater V5] Caminho de destino final: ' . $target_plugin_path);

        // Se o diretório de destino já existe, removemos (Atualização/Sobrescrita)
        if ($wp_filesystem->exists($target_plugin_path)) {
            error_log('[MRP Updater V5] O diretório de destino já existe. Removendo para substituir...');
            $delete_result = $wp_filesystem->delete($target_plugin_path, true);
            if (!$delete_result) {
                error_log('[MRP Updater V5] ERRO: Falha ao excluir a pasta antiga. Verifique as permissões.');
                $wp_filesystem->delete($temp_unzip_dir, true);
                return new WP_Error('delete_failed', 'Não foi possível remover a versão antiga do plugin.');
            }
        }
        
        // Copia a nova versão
        error_log('[MRP Updater V5] Copiando arquivos para: ' . $target_plugin_path);
        $copy_result = copy_dir($source_path, $target_plugin_path);

        // Limpa o diretório descompactado temporário
        $wp_filesystem->delete($temp_unzip_dir, true);
        
        if (is_wp_error($copy_result) || !$copy_result) {
            error_log('[MRP Updater V5] ERRO: Falha ao copiar a nova versão. ' . (is_wp_error($copy_result) ? $copy_result->get_error_message() : ''));
            return new WP_Error('copy_failed', 'Não foi possível instalar a nova versão do plugin. Erro de cópia.');
        }
        error_log('[MRP Updater V5] Instalação/Atualização concluída com sucesso.');
        
        // Lista arquivos instalados para debug
        if ($wp_filesystem->exists($target_plugin_path)) {
            $installed_files = $wp_filesystem->dirlist($target_plugin_path);
            if ($installed_files) {
                $file_names = array_keys($installed_files);
                error_log('[MRP Updater V5] Arquivos instalados: ' . implode(', ', $file_names));
            }
        }
        // --- FIM DA MODIFICAÇÃO ---


        if ($is_active) {
            activate_plugin($plugin_file_path);
            error_log('[MRP Updater V5] Plugin reativado.');
        }
        
        clearstatcache();
        wp_cache_delete('plugins', 'plugins');
        wp_clean_plugins_cache(); // Força atualização da lista de plugins
        
        if (!empty($plugin_file_path)) {
            $new_plugin_data = get_plugin_data($plugins_dir . $plugin_file_path);
            error_log('[MRP Updater V5] VERIFICAÇÃO FINAL: Versão encontrada no disco: ' . $new_plugin_data['Version']);
        } else {
             error_log('[MRP Updater V5] Instalação concluída. Arquivo principal não verificado (modo instalação).');
        }

        return true;
    }
    /**
     * Verifica se existe backup disponível para o plugin.
     * @param string $plugin_slug
     * @return bool
     */
    public function has_backup($plugin_slug) {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/meu-repositorio-backups/' . $plugin_slug . '/';
        
        if (!file_exists($backup_dir)) {
            return false;
        }

        $files = glob($backup_dir . '*.zip');
        return !empty($files);
    }

    /**
     * Cria um backup do plugin antes da atualização.
     */
    private function create_backup($plugin_file_path) {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        // Caminho físico completo do arquivo
        $full_plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file_path;
        
        // Determina o diretório do plugin
        // Se estiver em subpasta: plugins/slug/arquivo.php -> dirname = plugins/slug
        // Se estiver na raiz: plugins/arquivo.php -> dirname = plugins
        $plugin_dir = dirname($full_plugin_path);
        
        // Se for plugin de arquivo único (na raiz de plugins), abortar backup por segurança/complexidade
        if ($plugin_dir === WP_PLUGIN_DIR) {
            return;
        }

        $plugin_slug = basename($plugin_dir);

        if (!file_exists($plugin_dir)) {
            return;
        }

        $plugin_data = get_plugin_data($full_plugin_path);
        $version = $plugin_data['Version'];
        
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/meu-repositorio-backups/' . $plugin_slug . '/';
        
        if (!file_exists($backup_dir)) {
            if (!wp_mkdir_p($backup_dir)) {
                 error_log("[MRP Backup] Erro ao criar diretório: " . $backup_dir);
                 return;
            }
        }

        // Nome do arquivo: slug-version-timestamp.zip
        $backup_file = $backup_dir . $plugin_slug . '-' . $version . '-' . time() . '.zip';
        
        // Usando PclZip para compatibilidade
        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        
        // Verificar se a classe existe mesmo após inclusão (alguns ambientes bloqueiam)
        if (class_exists('PclZip')) {
            $archive = new PclZip($backup_file);
            
            // Remove o caminho absoluto para deixar a estrutura limpa dentro do zip
            $remove_path = dirname($plugin_dir);
            
            $v_list = $archive->create($plugin_dir, PCLZIP_OPT_REMOVE_PATH, $remove_path);
            
            if ($v_list == 0) {
                error_log("[MRP Backup] Falha ao criar backup: " . $archive->errorInfo(true));
            } else {
                error_log("[MRP Backup] Backup criado com sucesso: " . $backup_file);
                
                // Gerenciamento de rotação: manter APENAS O ÚLTIMO (versão anterior)
                // Remove todos os outros arquivos .zip no diretório que não sejam o atual
                $files = glob($backup_dir . '*.zip');
                if ($files) {
                    foreach ($files as $file) {
                        if ($file !== $backup_file) {
                            @unlink($file);
                        }
                    }
                }
            }
        } else {
            error_log("[MRP Backup] Classe PclZip não encontrada.");
        }
    }

    /**
     * Retorna a lista de backups disponíveis para um plugin.
     * @param string $plugin_slug Slug da pasta do plugin
     */
    public function get_backups($plugin_slug) {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/meu-repositorio-backups/' . $plugin_slug . '/';
        
        if (!file_exists($backup_dir)) {
            return [];
        }

        $files = glob($backup_dir . '*.zip');
        $backups = [];

        if (!$files) return [];

        foreach ($files as $file) {
            $filename = basename($file);
            // Formato esperado: slug-version-timestamp.zip
            // Ex: elementor-pro-3.0.1-1634567890.zip
            
            if (preg_match('/' . preg_quote($plugin_slug, '/') . '-(.+)-(\d+)\.zip$/', $filename, $matches)) {
                $backups[] = [
                    'file' => $filename,
                    'version' => $matches[1],
                    'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $matches[2]),
                    'timestamp' => $matches[2],
                    'size' => size_format(filesize($file))
                ];
            }
        }

        // Ordenar do mais novo para o mais antigo
        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $backups;
    }

    /**
     * Restaura um backup específico.
     */
    public function restore_backup($plugin_slug, $backup_filename) {
        $upload_dir = wp_upload_dir();
        $backup_file = $upload_dir['basedir'] . '/meu-repositorio-backups/' . $plugin_slug . '/' . $backup_filename;
        
        if (!file_exists($backup_file)) {
            return new WP_Error('backup_not_found', 'Arquivo de backup não encontrado.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;
        
        $plugins_dir = $wp_filesystem->wp_plugins_dir();
        $target_path = trailingslashit($plugins_dir) . $plugin_slug;

        // 1. Remover plugin atual
        if ($wp_filesystem->exists($target_path)) {
            // Backup de segurança antes de apagar? Talvez overkill aqui, pois estamos restaurando.
            $deleted = $wp_filesystem->delete($target_path, true);
            if (!$deleted) {
                return new WP_Error('delete_failed', 'Não foi possível limpar a versão atual para restaurar o backup.');
            }
        }

        // 2. Extrair backup
        $unzip_result = unzip_file($backup_file, $plugins_dir);
        
        if (is_wp_error($unzip_result)) {
            return $unzip_result;
        }

        // Limpar caches
        wp_clean_plugins_cache();
        
        return true;
    }
}