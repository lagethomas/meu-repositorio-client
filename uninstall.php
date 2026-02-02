<?php
// /uninstall.php

// Se o uninstall não for chamado pelo WordPress, saia.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove as opções do banco de dados.
delete_option('meu_repositorio_api_url');
delete_option('meu_repositorio_auto_update_enabled');

// Limpa qualquer transiente de atualização que possa ter sido deixado para trás.
delete_site_transient('update_plugins');
delete_transient('mrp_update_count');