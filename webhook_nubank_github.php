#!/usr/bin/php
<?php
/**
 * Webhook Nubank - Blesta Integration
 * 
 * Este script processa emails do Nubank (via pipe ou encaminhamento)
 * e extrai informações de pagamento PIX para integração automática com Blesta.
 * 
 * Funciona tanto como pipe quanto processando arquivos .eml
 * 
 * @author Sistema de Webhook Nubank-Blesta
 * @version 2.0
 * @license MIT
 */

// ===== CONFIGURAÇÕES DE SEGURANÇA =====
// Validação de remetentes - CRÍTICO PARA SEGURANÇA
define('VALIDACAO_REMETENTES_ATIVA', true);
define('REMETENTES_AUTORIZADOS', [
    'todomundo@nubank.com.br',  // Email oficial do Nubank
    'seu-email@dominio.com'     // Email autorizado para encaminhamento
]);

// Verificação de permissões de arquivos
define('VERIFICACAO_PERMISSOES_ATIVA', true);

// ===== CONFIGURAÇÕES OPERACIONAIS =====
define('DEBUG_MODE', false);  // DESATIVADO EM PRODUÇÃO POR SEGURANÇA
define('LOG_FILE', dirname(__FILE__) . '/webhook_nubank.log');
define('CLIENT_MAP_FILE', dirname(__FILE__) . '/clientes_nubank.txt');
define('ADMIN_EMAIL', 'admin@seudominio.com');

// Modo de teste para emails encaminhados - ATIVAR APENAS PARA TESTES
define('MODO_TESTE_ENCAMINHADO', true);

// Modo para atualização de ID de cliente via email encaminhado
define('MODO_ATUALIZACAO_ID_CLIENTE', true);

// Regras flexíveis de pagamento - ATIVAR PARA PERMITIR:
// 1. Soma automática de múltiplas faturas
// 2. Tolerância de 15% para pagamento maior que fatura individual
//    CONDIÇÃO: Só se valor pago termina em ,00 e fatura NÃO termina em ,00
define('REGRAS_FLEXIVEIS_PAGAMENTO', true);

class NubankEmailProcessor {
    
    private $logFile;
    private $config = array();
    private $blestaBaseUrl;
    private $blestaApiUser;
    private $blestaApiKey;
    
    public function __construct() {
        $this->logFile = LOG_FILE;
        $this->carregarConfiguracao();
        $this->configurarBlesta();
        
        // Verificação de segurança inicial
        if (VERIFICACAO_PERMISSOES_ATIVA) {
            $this->verificarPermissoesArquivos();
        }
    }
    
    /**
     * Carrega configurações do arquivo .env
     */
    private function carregarConfiguracao() {
        $envFile = dirname(__FILE__) . '/.env';
        
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            $lines = explode("\n", $envContent);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $this->config[trim($key)] = trim($value);
                }
            }
            $this->log("Configurações carregadas do arquivo .env");
        } else {
            $this->log("AVISO: Arquivo .env não encontrado em: " . $envFile);
        }
    }
    
    /**
     * Configura credenciais da API do Blesta
     */
    private function configurarBlesta() {
        $this->log("Configurando credenciais do Blesta");
        $this->blestaBaseUrl = isset($this->config['BLESTA_BASE_URL']) ? $this->config['BLESTA_BASE_URL'] : null;
        $this->blestaApiUser = isset($this->config['BLESTA_API_USER']) ? $this->config['BLESTA_API_USER'] : null;
        $this->blestaApiKey = isset($this->config['BLESTA_API_KEY']) ? $this->config['BLESTA_API_KEY'] : null;
        
        if (!$this->blestaBaseUrl || !$this->blestaApiUser || !$this->blestaApiKey) {
            $this->log("AVISO: Configurações da API do Blesta não encontradas ou incompletas");
        } else {
            $this->log("API do Blesta configurada: " . $this->blestaBaseUrl);
        }
    }
    
    /**
     * Processa email do Nubank - funciona tanto via pipe quanto arquivo
     */
    public function processEmail($input = null) {
        $this->log("=== PROCESSAMENTO INICIADO ===");
        
        // Se não foi fornecido input, lê do STDIN (pipe)
        if ($input === null) {
            $input = file_get_contents('php://stdin');
        }
        
        if (empty($input)) {
            $this->log("ERRO: Nenhum conteúdo de email recebido");
            return false;
        }
        
        $this->log("Tamanho do email recebido: " . strlen($input) . " bytes");
        
        // ===== VALIDAÇÃO DE SEGURANÇA DO REMETENTE =====
        if (VALIDACAO_REMETENTES_ATIVA) {
            if (!$this->validarRemetente($input)) {
                $this->log("ERRO DE SEGURANÇA: Email de remetente não autorizado rejeitado");
                $this->enviarAlertaSeguranca('remetente_nao_autorizado', $input);
                return false;
            }
        }
        
        // Verifica se é um email encaminhado com ID de cliente para atualização
        if (MODO_ATUALIZACAO_ID_CLIENTE && $this->detectarEmailEncaminhado($input)) {
            $idCliente = $this->extrairIdClienteEncaminhado($input);
            if ($idCliente) {
                $this->log("ID detectado em email encaminhado: {$idCliente}");
            }
        }
        
        // Extrai dados do email
        $dadosExtraidos = $this->extrairDadosEmail($input);
        
        if ($dadosExtraidos) {
            $this->log("SUCESSO: Dados extraídos com sucesso");
            $this->log("Nome: " . $dadosExtraidos['nome']);
            $this->log("Valor: " . $dadosExtraidos['valor']);
            $this->log("Data: " . $dadosExtraidos['data']);
            
            // Processa cliente no arquivo de mapeamento
            $idClienteEncaminhado = null;
            if (MODO_ATUALIZACAO_ID_CLIENTE && $dadosExtraidos['tipo'] === 'encaminhado') {
                $idClienteEncaminhado = $this->extrairIdClienteEncaminhado($input);
            }
            
            $resultadoCliente = $this->processarCliente($dadosExtraidos['nome'], $idClienteEncaminhado);
            $dadosExtraidos['cliente_info'] = $resultadoCliente;
            
            // Se cliente tem ID, processa pagamento no Blesta
            if ($resultadoCliente['status'] === 'encontrado' || $resultadoCliente['status'] === 'id_atualizado') {
                $this->log("Cliente tem ID - processando pagamento no Blesta...");
                $resultadoPagamento = $this->processarPagamento($resultadoCliente, $dadosExtraidos['valor']);
                $dadosExtraidos['pagamento_info'] = $resultadoPagamento;
            }
            
            return $dadosExtraidos;
        } else {
            $this->log("ERRO: Não foi possível extrair dados do email");
            return false;
        }
    }
    
    /**
     * Extrai dados específicos do email do Nubank
     */
    private function extrairDadosEmail($emailContent) {
        $this->log("Iniciando extração de dados...");
        
        // Decodifica HTML quoted-printable se necessário
        $decodedContent = $this->decodificarEmail($emailContent);
        
        // Detecta se é um email encaminhado
        $isEncaminhado = $this->detectarEmailEncaminhado($emailContent);
        $this->log("Tipo de email: " . ($isEncaminhado ? 'ENCAMINHADO' : 'DIRETO'));
        
        $dados = [
            'nome' => null,
            'valor' => null,
            'data' => null,
            'tipo' => $isEncaminhado ? 'encaminhado' : 'direto'
        ];
        
        // Extrai nome baseado no tipo de email
        if ($isEncaminhado) {
            $this->extrairNomeEmailEncaminhado($decodedContent, $dados);
        } else {
            $this->extrairNomeEmailDireto($decodedContent, $dados);
        }
        
        // Extrai valor baseado no tipo de email
        if ($isEncaminhado) {
            $this->extrairValorEmailEncaminhado($decodedContent, $dados);
        } else {
            $this->extrairValorEmailDireto($decodedContent, $dados);
        }
        
        // Padrão 3: Extrai data/hora
        // Procura por "DD MMM às HH:MM"
        $padraoData = '/(\d{1,2}\s+[A-Z]{3})\s+[àa]s\s+(\d{2}:\d{2})/i';
        if (preg_match($padraoData, $decodedContent, $matches)) {
            $dados['data'] = trim($matches[1]) . ' às ' . trim($matches[2]);
            $this->log("Data extraída: " . $dados['data']);
        }
        
        // Verifica se conseguiu extrair ao menos nome e valor
        if ($dados['nome'] && $dados['valor']) {
            $this->log("Extração bem-sucedida!");
            return $dados;
        } else {
            $this->log("ERRO: Dados incompletos - Nome: " . ($dados['nome'] ?: 'não encontrado') . ", Valor: " . ($dados['valor'] ?: 'não encontrado'));
            
            // Debug: salva conteúdo para análise
            if (DEBUG_MODE) {
                $debugFile = dirname(__FILE__) . '/email_debug_' . date('Y-m-d_H-i-s') . '.txt';
                file_put_contents($debugFile, $decodedContent);
                $this->log("Conteúdo salvo para debug em: " . $debugFile);
            }
            
            return null;
        }
    }
    
    /**
     * Detecta se o email é encaminhado
     */
    private function detectarEmailEncaminhado($emailContent) {
        // Verifica indicadores de email encaminhado
        $indicadores = [
            '/Subject:.*Fwd:/i',
            '/-------- Mensagem encaminhada --------/i',
            '/Mensagem encaminhada/i',
            '/X-Forwarded-Message-Id:/i',
            '/From:.*<.*>.*Nubank/i'
        ];
        
        foreach ($indicadores as $padrao) {
            if (preg_match($padrao, $emailContent)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extrai nome de email direto do Nubank
     */
    private function extrairNomeEmailDireto($decodedContent, &$dados) {
        $this->log("Extraindo nome de email direto...");
        
        // Padrão 1: HTML com <b>
        $padraoNome = '/Voc[êe]\s+recebeu\s+uma\s+transfer[êe]ncia\s+de\s+<b>([^<]+)<\/b>/i';
        if (preg_match($padraoNome, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (padrão HTML): " . $dados['nome']);
            return;
        }
        
        // Padrão 2: NOVO - Email minificado formato 2025 "pelo Pix de NOME e o valor"
        $padraoMinificado2025 = '/pelo\s+Pix\s+de\s*([A-Z\s\.\-]+?)\s+e\s+o\s+valor/i';
        if (preg_match($padraoMinificado2025, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (minificado 2025): " . $dados['nome']);
            return;
        }
        
        // Adicione mais padrões conforme necessário...
        
        $this->log("Nome NÃO encontrado em email direto");
    }
    
    /**
     * Extrai nome de email encaminhado
     */
    private function extrairNomeEmailEncaminhado($decodedContent, &$dados) {
        $this->log("Extraindo nome de email encaminhado...");
        
        // Padrão 1: Email encaminhado com asterisco (formato texto simples)
        $padraoEncaminhado1 = '/transfer[êe]ncia\s+pelo\s+Pix\s+de\s+\*([^*]+)\*/i';
        if (preg_match($padraoEncaminhado1, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (encaminhado asterisco): " . $dados['nome']);
            return;
        }
        
        // Fallback: Tenta padrões do email direto
        $this->log("Tentando padrões de email direto como fallback...");
        $this->extrairNomeEmailDireto($decodedContent, $dados);
    }
    
    /**
     * Extrai valor de email direto
     */
    private function extrairValorEmailDireto($decodedContent, &$dados) {
        $this->log("Extraindo valor de email direto...");
        
        // Padrão 1: "Valor recebido" seguido de R$
        $padraoValor = '/Valor\s+recebido[^R]*R\$\s*([0-9]+,\d{2})/i';
        if (preg_match($padraoValor, $decodedContent, $matches)) {
            $valorExtraido = 'R$ ' . trim($matches[1]);
            if ($this->validarValorMonetario($valorExtraido)) {
                $dados['valor'] = $valorExtraido;
                $this->log("Valor extraído (direto): " . $dados['valor']);
                return;
            } else {
                $this->log("AVISO: Valor inválido detectado: " . $valorExtraido);
            }
        }
        
        $this->log("Valor NÃO encontrado em email direto");
    }
    
    /**
     * Extrai valor de email encaminhado
     */
    private function extrairValorEmailEncaminhado($decodedContent, &$dados) {
        $this->log("Extraindo valor de email encaminhado...");
        
        // Padrão 1: Email encaminhado com asterisco *R$ X,XX*
        $padraoEncaminhado1 = '/\*R\$\s*([0-9]+,\d{2})\*/i';
        if (preg_match($padraoEncaminhado1, $decodedContent, $matches)) {
            $valorExtraido = 'R$ ' . trim($matches[1]);
            if ($this->validarValorMonetario($valorExtraido)) {
                $dados['valor'] = $valorExtraido;
                $this->log("Valor extraído (encaminhado asterisco): " . $dados['valor']);
                return;
            } else {
                $this->log("AVISO: Valor inválido detectado: " . $valorExtraido);
            }
        }
        
        // Fallback: Tenta padrões do email direto
        $this->log("Tentando padrões de email direto para valor...");
        $this->extrairValorEmailDireto($decodedContent, $dados);
    }
    
    /**
     * Decodifica email HTML quoted-printable
     */
    private function decodificarEmail($content) {
        // Remove quebras de linha desnecessárias do quoted-printable
        $content = str_replace("=\r\n", "", $content);
        $content = str_replace("=\n", "", $content);
        
        // Decodifica quoted-printable
        $decoded = quoted_printable_decode($content);
        
        // Remove HTML tags para facilitar extração
        $textOnly = strip_tags($decoded);
        
        // Decodifica entidades HTML que possam ter sobrado
        $textOnly = html_entity_decode($textOnly, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        
        return $textOnly;
    }
    
    /**
     * Processa cliente: busca no arquivo ou adiciona se novo
     */
    public function processarCliente($nomeCliente, $idClienteEncaminhado = null) {
        $this->log("=== PROCESSANDO CLIENTE: {$nomeCliente} ===");
        
        $arquivoClientes = CLIENT_MAP_FILE;
        
        if (!file_exists($arquivoClientes)) {
            $this->log("ERRO: Arquivo de clientes não encontrado: {$arquivoClientes}");
            return ['status' => 'erro', 'mensagem' => 'Arquivo de clientes não encontrado'];
        }
        
        // Lê o arquivo de clientes
        $linhas = file($arquivoClientes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        // Procura o cliente no arquivo
        foreach ($linhas as $index => $linha) {
            // Ignora comentários
            if (strpos(trim($linha), '#') === 0) {
                continue;
            }
            
            // Verifica se a linha tem o formato correto
            if (strpos($linha, '|') !== false) {
                list($nome, $id) = explode('|', $linha, 2);
                $nome = trim($nome);
                $id = trim($id);
                
                // Compara nomes (case-insensitive)
                if (strcasecmp($nome, $nomeCliente) === 0) {
                    if (!empty($id)) {
                        $this->log("Cliente encontrado com ID: {$nome} -> {$id}");
                        return [
                            'status' => 'encontrado',
                            'nome' => $nome,
                            'id_blesta' => $id,
                            'mensagem' => 'Cliente encontrado com ID'
                        ];
                    } else {
                        $this->log("Cliente encontrado mas SEM ID: {$nome}");
                        return [
                            'status' => 'sem_id',
                            'nome' => $nome,
                            'id_blesta' => null,
                            'mensagem' => 'Cliente encontrado mas aguardando ID'
                        ];
                    }
                }
            }
        }
        
        // Cliente não encontrado - adiciona ao arquivo
        $this->log("Cliente NÃO encontrado. Adicionando ao arquivo...");
        return $this->adicionarNovoCliente($nomeCliente, $arquivoClientes);
    }
    
    /**
     * Adiciona novo cliente ao arquivo e envia email para admin
     */
    private function adicionarNovoCliente($nomeCliente, $arquivoClientes) {
        // Adiciona o cliente ao arquivo (sem ID)
        $novaLinha = $nomeCliente . '|' . PHP_EOL;
        
        if (file_put_contents($arquivoClientes, $novaLinha, FILE_APPEND | LOCK_EX) !== false) {
            $this->log("Cliente adicionado ao arquivo: {$nomeCliente}");
            
            // Envia email para o admin
            $emailEnviado = $this->enviarEmailAdmin($nomeCliente);
            
            return [
                'status' => 'adicionado',
                'nome' => $nomeCliente,
                'id_blesta' => null,
                'mensagem' => 'Novo cliente adicionado. Admin notificado.',
                'email_enviado' => $emailEnviado
            ];
        } else {
            $this->log("ERRO: Não foi possível adicionar cliente ao arquivo");
            return [
                'status' => 'erro',
                'mensagem' => 'Erro ao adicionar cliente ao arquivo'
            ];
        }
    }
    
    /**
     * Envia email para admin sobre novo cliente
     */
    private function enviarEmailAdmin($nomeCliente) {
        $this->log("Enviando email para admin sobre novo cliente...");
        
        $para = ADMIN_EMAIL;
        $assunto = 'Novo Cliente Nubank - ID Necessario';
        $corpo = "Ola Admin,\n\n";
        $corpo .= "Um novo cliente foi detectado no webhook do Nubank:\n\n";
        $corpo .= "Nome: {$nomeCliente}\n";
        $corpo .= "Data: " . date('d/m/Y H:i:s') . "\n\n";
        $corpo .= "Por favor, adicione o ID do Blesta para este cliente no arquivo:\n";
        $corpo .= "clientes_nubank.txt\n\n";
        $corpo .= "Formato: {$nomeCliente}|ID_BLESTA\n\n";
        $corpo .= "Webhook Nubank - Sistema Automatico";
        
        $cabecalhos = "From: webhook@seudominio.com\r\n";
        $cabecalhos .= "Reply-To: webhook@seudominio.com\r\n";
        $cabecalhos .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $cabecalhos .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $cabecalhos .= "Content-Transfer-Encoding: 8bit";
        
        $resultado = mail($para, $assunto, $corpo, $cabecalhos);
        
        if ($resultado) {
            $this->log("Email enviado com sucesso para: {$para}");
        } else {
            $this->log("ERRO: Falha ao enviar email para: {$para}");
        }
        
        return $resultado;
    }
    
    /**
     * Valida se o remetente do email está autorizado
     */
    private function validarRemetente($emailContent) {
        $this->log("=== VALIDAÇÃO DE REMETENTE ===");
        
        $remetentesAutorizados = REMETENTES_AUTORIZADOS;
        $this->log("Remetentes autorizados: " . implode(', ', $remetentesAutorizados));
        
        // Extrai o remetente do cabeçalho From
        if (preg_match('/^From:\s*.*?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/m', $emailContent, $matches)) {
            $remetente = strtolower(trim($matches[1]));
            $this->log("Remetente detectado: {$remetente}");
            
            foreach ($remetentesAutorizados as $autorizado) {
                if (strtolower($autorizado) === $remetente) {
                    $this->log("Remetente autorizado: {$remetente}");
                    return true;
                }
            }
            
            $this->log("Remetente NAO autorizado: {$remetente}");
            return false;
        }
        
        $this->log("ERRO: Nao foi possivel identificar remetente no email");
        return false;
    }
    
    /**
     * Verifica permissões dos arquivos críticos
     */
    private function verificarPermissoesArquivos() {
        $this->log("=== VERIFICACAO DE PERMISSOES ===");
        
        $arquivosCriticos = [
            dirname(__FILE__) . '/webhook_nubank.php' => ['recomendado' => '700', 'desc' => 'Script principal'],
            dirname(__FILE__) . '/.env' => ['recomendado' => '600', 'desc' => 'Arquivo de configuracao'],
            dirname(__FILE__) . '/clientes_nubank.txt' => ['recomendado' => '600', 'desc' => 'Mapeamento de clientes']
        ];
        
        $problemasEncontrados = [];
        
        foreach ($arquivosCriticos as $arquivo => $config) {
            if (file_exists($arquivo)) {
                $permissaoAtual = substr(sprintf('%o', fileperms($arquivo)), -3);
                $permissaoRecomendada = $config['recomendado'];
                
                $this->log("Arquivo: {$config['desc']} - Atual: {$permissaoAtual}, Recomendada: {$permissaoRecomendada}");
                
                if ($permissaoAtual !== $permissaoRecomendada) {
                    $problemasEncontrados[] = [
                        'arquivo' => basename($arquivo),
                        'desc' => $config['desc'],
                        'atual' => $permissaoAtual,
                        'recomendada' => $permissaoRecomendada,
                        'caminho' => $arquivo
                    ];
                }
            } else {
                $this->log("AVISO: Arquivo nao encontrado: {$arquivo}");
            }
        }
        
        if (!empty($problemasEncontrados)) {
            $this->enviarRelatorioSeguranca($problemasEncontrados);
        } else {
            $this->log("Todas as permissoes estao corretas");
        }
    }
    
    /**
     * Envia relatório de segurança por email quando há problemas de permissão
     */
    private function enviarRelatorioSeguranca($problemas) {
        $this->log("Enviando relatorio de seguranca - problemas encontrados: " . count($problemas));
        
        $para = ADMIN_EMAIL;
        $assunto = 'ALERTA DE SEGURANCA - Webhook Nubank - Permissoes Incorretas';
        
        $corpo = "RELATORIO DE SEGURANCA - WEBHOOK NUBANK\n\n";
        $corpo .= "Data/Hora: " . date('d/m/Y H:i:s') . "\n\n";
        $corpo .= "PROBLEMAS DE PERMISSAO DETECTADOS:\n\n";
        
        foreach ($problemas as $problema) {
            $corpo .= "Arquivo: {$problema['desc']} ({$problema['arquivo']})\n";
            $corpo .= "Permissao atual: {$problema['atual']}\n";
            $corpo .= "Permissao recomendada: {$problema['recomendada']}\n";
            $corpo .= "Caminho completo: {$problema['caminho']}\n\n";
        }
        
        $corpo .= "RECOMENDACOES DE SEGURANCA:\n\n";
        $corpo .= "1. Execute os seguintes comandos para corrigir as permissoes:\n\n";
        
        foreach ($problemas as $problema) {
            $corpo .= "chmod {$problema['recomendada']} '{$problema['caminho']}'\n";
        }
        
        $corpo .= "\n2. Verifique se apenas o usuario correto tem acesso aos arquivos\n";
        $corpo .= "3. Considere mover arquivos sensiveis para fora do web root\n\n";
        $corpo .= "ATENCAO: Este email so e enviado quando ha divergencias de seguranca.\n\n";
        $corpo .= "Sistema de Seguranca - Webhook Nubank";
        
        $cabecalhos = "From: security@seudominio.com\r\n";
        $cabecalhos .= "Reply-To: admin@seudominio.com\r\n";
        $cabecalhos .= "X-Priority: 1\r\n";
        $cabecalhos .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $cabecalhos .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $cabecalhos .= "Content-Transfer-Encoding: 8bit";
        
        $resultado = mail($para, $assunto, $corpo, $cabecalhos);
        
        if ($resultado) {
            $this->log("Relatorio de seguranca enviado para: {$para}");
        } else {
            $this->log("ERRO: Falha ao enviar relatorio de seguranca - Verifique configuracao do servidor de email");
        }
        
        return $resultado;
    }
    
    /**
     * Envia alerta de segurança para tentativas de acesso não autorizado
     */
    private function enviarAlertaSeguranca($tipoAlerta, $emailContent) {
        $this->log("Enviando alerta de seguranca: {$tipoAlerta}");
        
        $para = ADMIN_EMAIL;
        $assunto = 'ALERTA CRITICO DE SEGURANCA - Webhook Nubank - Acesso Negado';
        
        $corpo = "ALERTA CRITICO DE SEGURANCA\n\n";
        $corpo .= "Data/Hora: " . date('d/m/Y H:i:s') . "\n";
        $corpo .= "Tipo de alerta: {$tipoAlerta}\n\n";
        
        if ($tipoAlerta === 'remetente_nao_autorizado') {
            $corpo .= "TENTATIVA DE ACESSO COM REMETENTE NAO AUTORIZADO\n\n";
            
            // Extrai informações do remetente para análise
            if (preg_match('/^From:\s*(.+)$/m', $emailContent, $matches)) {
                $corpo .= "Remetente detectado: " . trim($matches[1]) . "\n";
            }
            
            $corpo .= "\nRemetentes autorizados: " . implode(', ', REMETENTES_AUTORIZADOS) . "\n\n";
        }
        
        $corpo .= "Esta tentativa foi BLOQUEADA automaticamente.\n";
        $corpo .= "Nenhum processamento foi realizado.\n\n";
        $corpo .= "ACAO RECOMENDADA:\n";
        $corpo .= "1. Verifique se esta tentativa e legitima\n";
        $corpo .= "2. Se legitima, adicione o remetente a lista de autorizados\n";
        $corpo .= "3. Se suspeita, investigue possivel tentativa de ataque\n\n";
        $corpo .= "Sistema de Seguranca - Webhook Nubank";
        
        $cabecalhos = "From: security@seudominio.com\r\n";
        $cabecalhos .= "Reply-To: admin@seudominio.com\r\n";
        $cabecalhos .= "X-Priority: 1\r\n";
        $cabecalhos .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $cabecalhos .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $cabecalhos .= "Content-Transfer-Encoding: 8bit";
        
        $resultado = mail($para, $assunto, $corpo, $cabecalhos);
        
        if ($resultado) {
            $this->log("Alerta de seguranca enviado para: {$para}");
        } else {
            $this->log("ERRO: Falha ao enviar alerta de seguranca - Verifique configuracao do servidor de email");
        }
        
        return $resultado;
    }
    
    /**
     * Sanitiza dados de entrada para prevenir injeção
     */
    private function sanitizarTexto($texto) {
        // Remove caracteres potencialmente perigosos
        $texto = htmlspecialchars($texto, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        // Remove caracteres de controle (exceto quebras de linha)
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $texto);
        return trim($texto);
    }
    
    /**
     * Valida formato de valor monetário
     */
    private function validarValorMonetario($valor) {
        // Remove prefixos comuns
        $valorLimpo = preg_replace('/^R\$\s*/', '', $valor);
        // Verifica formato brasileiro (123,45 ou 1.123,45)
        return preg_match('/^\d{1,3}(?:\.\d{3})*,\d{2}$|^\d+,\d{2}$/', $valorLimpo);
    }
    
    /**
     * Registra logs para debug
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        // Sanitiza a mensagem do log para prevenir log injection
        $message = $this->sanitizarTexto($message);
        $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
        
        // Escreve no arquivo de log
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Se DEBUG_MODE ativo, também mostra na tela
        if (DEBUG_MODE && php_sapi_name() === 'cli') {
            echo $logEntry;
        }
    }
}

// Execução principal
if (php_sapi_name() === 'cli') {
    $processor = new NubankEmailProcessor();
    
    // Verifica se foi passado um arquivo como argumento
    if ($argc > 1 && file_exists($argv[1])) {
        // Modo teste: processa arquivo específico
        echo "Processando arquivo: {$argv[1]}\n";
        $content = file_get_contents($argv[1]);
        $resultado = $processor->processEmail($content);
    } else {
        // Modo pipe: lê do STDIN
        $resultado = $processor->processEmail();
    }
    
    if ($resultado) {
        echo "DADOS EXTRAIDOS:\n";
        echo "Nome: " . $resultado['nome'] . "\n";
        echo "Valor: " . $resultado['valor'] . "\n";
        echo "Data: " . $resultado['data'] . "\n";
        echo "Tipo: " . $resultado['tipo'] . "\n";
        echo "\nINFORMACOES DO CLIENTE:\n";
        echo "Status: " . $resultado['cliente_info']['status'] . "\n";
        echo "Mensagem: " . $resultado['cliente_info']['mensagem'] . "\n";
        if (isset($resultado['cliente_info']['id_blesta']) && $resultado['cliente_info']['id_blesta']) {
            echo "ID Blesta: " . $resultado['cliente_info']['id_blesta'] . "\n";
        }
        exit(0);
    } else {
        echo "ERRO: Nao foi possivel processar o email\n";
        exit(1);
    }
} else {
    // Se chamado via web, retorna JSON
    header('Content-Type: application/json');
    $processor = new NubankEmailProcessor();
    $resultado = $processor->processEmail();
    echo json_encode($resultado ?: ['erro' => 'Nao foi possivel processar email']);
}
?>