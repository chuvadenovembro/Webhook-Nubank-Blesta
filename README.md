# Webhook Nubank-Blesta Integration

Sistema de webhook para integração automática entre notificações PIX do Nubank e sistema de faturamento Blesta.

## 📋 Funcionalidades

- **Processamento automático de emails PIX** do Nubank
- **Integração direta com API Blesta** para baixa automática de faturas
- **Sistema de segurança robusto** com validação de remetentes
- **Mapeamento automático de clientes** com notificação de novos registros
- **Suporte a emails encaminhados** e diretos
- **Monitoramento de permissões de arquivos** com alertas
- **Sistema de logs detalhado** para auditoria
- **Regras flexíveis de pagamento** (soma múltiplas faturas, tolerância de arredondamento)

## 🔧 Instalação

### 1. Requisitos

- **PHP 7.4+** com suporte a cURL
- **Servidor de email** configurado (exim, postfix, etc.)
- **Acesso à API do Blesta** com credenciais válidas
- **Servidor Linux/Unix** com suporte a pipes de email

### 2. Download e Configuração

```bash
# Baixe o arquivo principal
wget https://github.com/seuusuario/webhook-nubank-blesta/raw/main/webhook_nubank_github.php

# Renomeie para o nome correto
mv webhook_nubank_github.php webhook_nubank.php

# Configure permissões seguras
chmod 700 webhook_nubank.php
```

### 3. Configuração do Arquivo .env

Crie um arquivo `.env` com suas credenciais do Blesta:

```bash
# Configurações da API do Blesta
BLESTA_BASE_URL=https://seu-blesta.com
BLESTA_API_USER=seu_usuario_api
BLESTA_API_KEY=sua_chave_api_secreta
```

**IMPORTANTE**: Configure permissões seguras:
```bash
chmod 600 .env
```

### 4. Configuração do Arquivo de Clientes

Crie o arquivo `clientes_nubank.txt` para mapeamento:

```bash
touch clientes_nubank.txt
chmod 600 clientes_nubank.txt
```

Formato do arquivo:
```
# Mapeamento de clientes - Nome|ID_Blesta
JOAO DA SILVA|1001
MARIA SANTOS|1002
EMPRESA LTDA|1003
```

### 5. Configuração de Emails

Edite o arquivo `webhook_nubank.php` e ajuste as configurações:

```php
// Seus emails autorizados
define('REMETENTES_AUTORIZADOS', [
    'todomundo@nubank.com.br',     // Email oficial do Nubank
    'seu-email@seudominio.com'     // Seu email para encaminhamentos
]);

// Email do administrador
define('ADMIN_EMAIL', 'admin@seudominio.com');
```

## 🚀 Configuração do Servidor

### Opção 1: Pipe de Email (Recomendado)

Configure seu servidor de email para enviar emails do Nubank para o script:

**Para cPanel/DirectAdmin:**
1. Acesse "Forwarders" ou "Email Routing"
2. Crie um pipe para: `|/caminho/para/webhook_nubank.php`

**Para Postfix/Exim manual:**
```bash
# Adicione ao arquivo de aliases
nubank: "|/caminho/para/webhook_nubank.php"
```

### Opção 2: Processamento de Arquivos .eml

```bash
# Processa um arquivo específico
php webhook_nubank.php arquivo_email.eml
```

## 🔒 Recursos de Segurança

### Validação de Remetentes
- **Bloqueio automático** de emails não autorizados
- **Alertas críticos** por email/arquivo
- **Log detalhado** de tentativas de acesso

### Verificação de Permissões
- **Monitoramento automático** de permissões de arquivos
- **Relatórios de segurança** quando há divergências
- **Permissões recomendadas**:
  - `webhook_nubank.php`: 700
  - `.env`: 600
  - `clientes_nubank.txt`: 600

### Configurações de Segurança

```php
// Ativar/desativar recursos
define('VALIDACAO_REMETENTES_ATIVA', true);    // SEMPRE true em produção
define('VERIFICACAO_PERMISSOES_ATIVA', true);  // Recomendado
define('DEBUG_MODE', false);                   // SEMPRE false em produção
```

## 📊 Uso e Monitoramento

### Logs do Sistema

O sistema gera logs detalhados em `webhook_nubank.log`:

```bash
# Monitore logs em tempo real
tail -f webhook_nubank.log

# Busque por erros
grep "ERRO" webhook_nubank.log

# Verifique sucessos
grep "SUCESSO" webhook_nubank.log
```

### Teste Manual

```bash
# Teste com arquivo de email
php webhook_nubank.php email_exemplo.eml

# Teste via pipe
cat email_exemplo.eml | php webhook_nubank.php
```

### Alertas por Email

O sistema envia automaticamente:
- **Novos clientes** detectados (necessário ID)
- **Alertas críticos** de segurança
- **Relatórios** de problemas de permissão
- **Atualizações** de ID de cliente

## ⚙️ Configurações Avançadas

### Regras Flexíveis de Pagamento

```php
define('REGRAS_FLEXIVEIS_PAGAMENTO', true);
```

Permite:
- **Soma automática** de múltiplas faturas
- **Tolerância de 15%** para pagamentos maiores (condições específicas)
- **Detecção inteligente** de combinações de faturas

### Modo de Atualização de ID

```php
define('MODO_ATUALIZACAO_ID_CLIENTE', true);
```

Permite atualização automática de IDs via emails encaminhados.

### Modo de Teste

```php
define('MODO_TESTE_ENCAMINHADO', true);
```

**ATENÇÃO**: Desative em produção.

## 🐛 Solução de Problemas

### Email não está sendo processado
1. Verifique logs: `tail -f webhook_nubank.log`
2. Confirme remetente autorizado
3. Teste permissões de arquivos
4. Verifique configuração do pipe

### API Blesta não responde
1. Verifique credenciais no `.env`
2. Teste conectividade: `curl -I https://seu-blesta.com`
3. Confirme usuário API tem permissões necessárias

### Emails de alerta não chegam
1. Teste servidor de email: `echo "teste" | mail admin@seudominio.com`
2. Verifique logs de email do servidor
3. Sistema salva automaticamente em arquivos como backup

### Cliente não encontrado
1. Verifique formato no arquivo `clientes_nubank.txt`
2. Compare nome exato do email com arquivo
3. Sistema adiciona automaticamente novos clientes

## 📁 Estrutura de Arquivos

```
projeto/
├── webhook_nubank.php       # Script principal
├── .env                     # Configurações (não versionar!)
├── clientes_nubank.txt      # Mapeamento de clientes
├── webhook_nubank.log       # Logs do sistema
├── README.md               # Esta documentação
├── SEGURANCA.md            # Guia de segurança detalhado
└── .env.example            # Modelo de configuração
```

## 🔄 Fluxo de Processamento

1. **Recepção**: Email PIX recebido via pipe ou arquivo
2. **Validação**: Verificação de remetente autorizado
3. **Extração**: Dados do pagador e valor
4. **Mapeamento**: Busca ID do cliente no arquivo
5. **API**: Consulta faturas abertas no Blesta
6. **Processamento**: Criação e aplicação de transação
7. **Notificação**: Logs e emails de confirmação

## 📞 Suporte

### Logs e Debug
- Ative `DEBUG_MODE` apenas para testes
- Monitore `webhook_nubank.log` regularmente
- Arquivos de debug salvos automaticamente quando necessário

### Manutenção
- Revise remetentes autorizados periodicamente
- Monitore permissões de arquivos
- Faça backup do arquivo de clientes regularmente
- Atualize credenciais API conforme necessário

## 📄 Licença

MIT License - Veja arquivo LICENSE para detalhes.

## 🔐 Segurança

Este sistema foi desenvolvido com foco em segurança:
- ✅ Validação de remetentes
- ✅ Sanitização de dados
- ✅ Verificação SSL/TLS
- ✅ Permissões de arquivo restritivas
- ✅ Logs de auditoria
- ✅ Sistema de alertas

**NUNCA** desative as validações de segurança em produção.

---

**Developed by**: Sistema Webhook Nubank-Blesta  
**Version**: 2.0  
**Last Update**: 2025-06-13