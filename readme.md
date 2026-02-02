# Meu Repositório de Plugins Client

**Versão:** 2.6  
**Autor:** Thomas Marcelino  
**Licença:** GPL2

## Descrição

Este plugin conecta-se a um repositório de plugins remoto para listar, instalar e gerenciar atualizações diretamente do painel do WordPress, integrando-se ao sistema nativo de atualizações.

## Funcionalidades

-   **Página de Configurações:** Permite definir a URL do endpoint da API JSON do seu repositório.
-   **Listagem de Plugins:** Exibe uma lista de todos os plugins instalados que também existem no repositório, comparando as versões.
-   **Atualização Simplificada:** Permite atualizar plugins com um único clique e mostra notificações de atualização na página de Plugins e no menu do painel.
-   **Atualização Automática (Opcional):** Suporte a um script de cron externo para automatizar as atualizações.
-   **Interface Moderna:** CSS customizado para uma experiência de usuário limpa e agradável, com feedback visual.

## Como Usar

1.  **Instale e Ative:** Instale o plugin no seu site WordPress e ative-o.
2.  **Configure a URL:** Vá para **Repositório > Configurações** no menu do admin e insira a URL do seu endpoint JSON. Salve as alterações.
3.  **Gerencie os Plugins:** Vá para **Repositório > Plugins do Repositório** para ver a lista de plugins e suas atualizações disponíveis.

## Endpoint JSON

O plugin espera que a URL fornecida retorne um array de objetos JSON com a seguinte estrutura:

```json
[
  {
    "slug": "meu-plugin-slug",
    "title": {
      "rendered": "Nome do Meu Plugin"
    },
    "meta": {
      "mtf_versao": "1.2.0",
      "mtf_url": "https://url/para/o/plugin.zip"
    }
  }
]