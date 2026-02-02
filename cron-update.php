<?php
/**
 * Script de Cron para Atualização Automática de Plugins
 *
 * @version 3.2
 */

// Define o caminho para o wp-load.php.
$wp_load_path = __DIR__ . '/../../../wp-load.php';

if (!file_exists($wp_load_path)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    die('ERRO CRÍTICO: O arquivo wp-load.php não foi encontrado. Verifique o caminho.');
}

// Carrega o ambiente do WordPress
require_once $wp_load_path;

// Define um limite de tempo maior para o script
@set_time_limit(300);

// Cabeçalho para saída de texto simples
header('Content-Type: text/plain; charset=utf-8');

echo "--------------------------------------------------\n";
echo "Iniciando Script de Atualização Automática\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n";
echo "--------------------------------------------------\n\n";

// Verifica se o arquivo principal do plugin existe antes de prosseguir
$plugin_main_file = __DIR__ . '/meu-repositorio-plugin.php';
if (!file_exists($plugin_main_file)) {
    http_response_code(500);
    die('ERRO CRÍTICO: Arquivo principal do plugin (meu-repositorio-plugin.php) não encontrado.');
}
require_once $plugin_main_file;


// Garante que a classe principal exista
if (!class_exists('MeuRepositorioPlugin')) {
    http_response_code(500);
    die('ERRO CRÍTICO: A classe MeuRepositorioPlugin não está disponível.');
}

// Verifica se a atualização automática está habilitada nas configurações
$is_auto_update_enabled = get_option('meu_repositorio_auto_update_enabled', 0);

if (!$is_auto_update_enabled) {
    echo "STATUS: Atualizações automáticas via cron estão DESABILITADAS nas configurações.\n";
    echo "Encerrando o script.\n";
    exit;
}

echo "STATUS: Atualizações automáticas via cron estão HABILITADAS.\n\n";

// Acessa a instância do plugin usando o método estático da classe.
$plugin_instance = MeuRepositorioPlugin::get_instance();

// Executa a lógica de atualização
$plugin_instance->mrp_run_automatic_updates();

echo "\n--------------------------------------------------\n";
echo "Verificação de atualizações concluída.\n";
echo "--------------------------------------------------\n";

exit;