# GUIA DE SEGURANÇA - Webhook Nubank-Blesta

## Melhorias de Segurança Implementadas

### ✅ 1. Validação de Remetentes
**CRÍTICO PARA SEGURANÇA**

O webhook agora valida se o email vem de remetentes autorizados:
- `todomundo@nubank.com.br` (oficial do Nubank)  
- `seu-email@seudominio.com` (para encaminhamentos internos)

**Configuração:**
```php
define('VALIDACAO_REMETENTES_ATIVA', true);
define('REMETENTES_AUTORIZADOS', [
    'todomundo@nubank.com.br',
    'seu-email@seudominio.com'
]);
```

**Proteção:** Bloqueia tentativas de spoofing e ataques de email forjado.

### ✅ 2. Verificação de Permissões de Arquivos

O sistema verifica automaticamente as permissões dos arquivos críticos:

**Permissões Recomendadas:**
- `webhook_nubank.php`: **700** (rwx------)
- `.env`: **600** (rw-------)  
- `clientes_nubank.txt`: **600** (rw-------)

**Comando para corrigir:**
```bash
chmod 700 webhook_nubank.php
chmod 600 .env
chmod 600 clientes_nubank.txt
```

### ✅ 3. Correção SSL/TLS
**Importante para segurança das APIs**

Corrigido as chamadas cURL para o Blesta:
```php
// ANTES (INSEGURO):
CURLOPT_SSL_VERIFYPEER => false,
CURLOPT_SSL_VERIFYHOST => false

// DEPOIS (SEGURO):
CURLOPT_SSL_VERIFYPEER => true,
CURLOPT_SSL_VERIFYHOST => 2
```

### ✅ 4. Sanitização de Dados
- Todos os dados extraídos dos emails são sanitizados
- Prevenção contra log injection
- Validação de formato para valores monetários

### ✅ 5. Sistema de Alertas de Segurança

**Alertas Críticos:** Enviados quando:
- Email de remetente não autorizado é recebido
- Tentativa de acesso suspeita é detectada

**Relatórios de Segurança:** Enviados quando:
- Permissões de arquivos estão incorretas
- Divergências de segurança são detectadas

## Como Ativar/Desativar Recursos de Segurança

### Validação de Remetentes
```php
// Para DESATIVAR (não recomendado):
define('VALIDACAO_REMETENTES_ATIVA', false);

// Para ATIVAR (recomendado):
define('VALIDACAO_REMETENTES_ATIVA', true);
```

### Verificação de Permissões
```php
// Para DESATIVAR:
define('VERIFICACAO_PERMISSOES_ATIVA', false);

// Para ATIVAR (recomendado):
define('VERIFICACAO_PERMISSOES_ATIVA', true);
```

### Modo Debug
```php
// PRODUÇÃO (seguro):
define('DEBUG_MODE', false);

// DESENVOLVIMENTO (apenas para testes):
define('DEBUG_MODE', true);
```

## Adicionando Novos Remetentes Autorizados

Para adicionar um novo remetente autorizado, edite:

```php
define('REMETENTES_AUTORIZADOS', [
    'todomundo@nubank.com.br',
    'seu-email@seudominio.com',
    'novo@email.autorizado.com'  // <- Adicione aqui
]);
```

## Monitoramento de Segurança

### Logs de Segurança
Todos os eventos de segurança são registrados em `webhook_nubank.log`:
- Tentativas de acesso negadas
- Verificações de permissão
- Alertas enviados

### Emails de Alerta
O admin recebe emails imediatos para:
- **Alertas Críticos**: Tentativas de acesso não autorizado
- **Relatórios**: Problemas de permissão detectados

## Checklist de Segurança

### ✅ Configuração Inicial
- [ ] Validação de remetentes ativada
- [ ] Permissões de arquivos corretas (700/600)
- [ ] SSL/TLS habilitado nas chamadas API
- [ ] DEBUG_MODE desativado em produção
- [ ] Verificação de permissões ativada

### ✅ Monitoramento
- [ ] Logs sendo gerados corretamente
- [ ] Emails de alerta funcionando
- [ ] Sistema rejeitando remetentes não autorizados

### ✅ Manutenção
- [ ] Revisar remetentes autorizados periodicamente
- [ ] Verificar permissões de arquivos regularmente
- [ ] Monitorar logs por atividades suspeitas

## Configuração do Arquivo .env

Crie um arquivo `.env` com as configurações:

```bash
# Configurações da API do Blesta
BLESTA_BASE_URL=https://seu.blesta.com
BLESTA_API_USER=seu_usuario_api
BLESTA_API_KEY=sua_chave_api_secreta

# IMPORTANTE: Mantenha este arquivo com permissão 600
# chmod 600 .env
```

## Vulnerabilidades Corrigidas

### 🔒 Spoofing de Email
**ANTES:** Qualquer email era processado
**DEPOIS:** Apenas remetentes autorizados são aceitos

### 🔒 Interceptação de Comunicação  
**ANTES:** SSL desabilitado (man-in-the-middle possível)
**DEPOIS:** SSL obrigatório com verificação de certificado

### 🔒 Exposição de Arquivos
**ANTES:** Arquivos com permissões amplas (755)
**DEPOIS:** Permissões restritivas (700/600)

### 🔒 Log Injection
**ANTES:** Dados não sanitizados nos logs
**DEPOIS:** Sanitização de todos os dados de entrada

### 🔒 Modo Debug em Produção
**ANTES:** DEBUG_MODE ativo (vaza informações)
**DEPOIS:** DEBUG_MODE desativado por padrão

## Suporte e Manutenção

### Sistema de Email Funcionando ✅
**STATUS**: O sistema de email está **funcionando corretamente**
- Relatórios de segurança: ✅ Enviados com sucesso
- Alertas críticos: ✅ Enviados com sucesso
- Logs confirmam: `Relatorio/Alerta enviado para: admin@seudominio.com`
- **Encoding corrigido**: UTF-8 configurado nos cabeçalhos de email
- **Acentuação**: Problemas de codificação resolvidos

### Em Caso de Falha de Email
Se o servidor de email não estiver configurado, o sistema automaticamente:
1. **Salva relatórios em arquivos**:
   - `relatorio_seguranca_YYYY-MM-DD_HH-mm-ss.txt`
   - `alerta_seguranca_YYYY-MM-DD_HH-mm-ss.txt`
2. **Registra no log**: "Falha ao enviar - Verifique configuração do servidor de email"
3. **Mantém permissões seguras**: Arquivos criados com permissão 600

### Em Caso de Alerta de Segurança:
1. **Verifique se é legítimo**: Confirme se o remetente deveria ser autorizado
2. **Se legítimo**: Adicione à lista de remetentes autorizados
3. **Se suspeito**: Investigue possível tentativa de ataque

### Para Problemas de Permissão:
1. Execute os comandos chmod recomendados
2. Verifique se apenas o usuário correto tem acesso
3. Considere mover arquivos sensíveis para fora do web root

---

**⚠️ IMPORTANTE:**
- Nunca desative a validação de remetentes em produção
- Mantenha sempre as permissões de arquivo restritas
- Monitore regularmente os logs de segurança
- Teste alterações em ambiente de desenvolvimento primeiro
