#!/usr/bin/php
<?php
/**
 * Webhook Nubank - Extrator de dados de emails de transferência PIX
 * 
 * Este script processa emails do Nubank (via pipe ou encaminhamento)
 * e extrai informações de pagamento: nome do pagador e valor recebido.
 * 
 * Funciona tanto como pipe quanto processando arquivos .eml
 */

// ===== CONFIGURAÇÕES DE SEGURANÇA =====
// Validação de remetentes - CRÍTICO PARA SEGURANÇA
define('VALIDACAO_REMETENTES_ATIVA', true);
define('REMETENTES_AUTORIZADOS', [
    'todomundo@nubank.com.br',  // Email oficial do Nubank
    'contato@dominio.com'       // Email autorizado para encaminhamento
]);

// Verificação de permissões de arquivos
define('VERIFICACAO_PERMISSOES_ATIVA', true);

// ===== CONFIGURAÇÕES OPERACIONAIS =====
define('DEBUG_MODE', false);  // DESATIVADO EM PRODUÇÃO POR SEGURANÇA
define('LOG_FILE', dirname(__FILE__) . '/webhook_nubank.log');
define('CLIENT_MAP_FILE', dirname(__FILE__) . '/clientes_nubank.txt');
define('ADMIN_EMAIL', 'admin@dominio.com');

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
        
        // Padrão 3: NOVO - Sem espaço após "de": "pelo Pix deNOME e o valor"
        $padraoColgado = '/pelo\s+Pix\s+de([A-Z\s\.\-]+?)\s+e\s+o\s+valor/i';
        if (preg_match($padraoColgado, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (colado): " . $dados['nome']);
            return;
        }
        
        // Padrão 4: NOVO - Versão mais flexível "transferência pelo Pix de NOME e"
        $padraoFlexivel = '/transfer[êe]ncia\s+pelo\s+Pix\s+de\s*([A-Z\s\.\-]+?)\s+e/i';
        if (preg_match($padraoFlexivel, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (flexível): " . $dados['nome']);
            return;
        }
        
        // Padrão 5: Texto junto sem espaços
        $padraoTextoJunto = '/transfer[êe]nciade([A-Z\s\.\-]+?)\.\s*eo\s*valor/i';
        if (preg_match($padraoTextoJunto, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (texto junto): " . $dados['nome']);
            return;
        }
        
        // Padrão 6: LTDA específico
        $padraoLTDA = '/de\s*([A-Z\s\.\-]+?LTDA\.)\s*e\s*o\s*valor/i';
        if (preg_match($padraoLTDA, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (LTDA): " . $dados['nome']);
            return;
        }
        
        // Padrão 7: Formato atual "transferência de Nome e o valor"
        $padraoAtual = '/transferência\s+de\s+(.+?)\s+e\s+o\s+valor/i';
        if (preg_match($padraoAtual, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (formato atual): " . $dados['nome']);
            return;
        }
        
        // Padrão 8: Genérico antigo
        $padraoGenerico = '/transfer[êe]ncia\s*de\s*([^.]+?)\s*\.\s*e\s*o\s*valor/i';
        if (preg_match($padraoGenerico, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (genérico antigo): " . $dados['nome']);
            return;
        }
        
        // Padrão 9: EMERGENCIAL - Captura qualquer nome entre "de " e " e"
        $padraoEmergencial = '/\bde\s+([A-Z][A-Z\s\.\-]{2,})\s+e\b/i';
        if (preg_match($padraoEmergencial, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (emergencial): " . $dados['nome']);
            return;
        }
        
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
        
        // Padrão 2: Email encaminhado com <b> (formato HTML)
        $padraoEncaminhado2 = '/transfer[êe]ncia\s+pelo\s+Pix\s+de\s+<b>([^<]+)<\/b>/i';
        if (preg_match($padraoEncaminhado2, $decodedContent, $matches)) {
            $dados['nome'] = $this->sanitizarTexto(trim($matches[1]));
            $this->log("Nome extraído (encaminhado HTML): " . $dados['nome']);
            return;
        }
        
        // Padrão 3: Quebra de linha no nome - versão melhorada
        $padraoQuebra = '/transfer[êe]ncia\s+pelo\s+Pix\s+de\s+\*([A-Z\s]+)\s*[\r\n\s]+([A-Z\s]+)\s*\*/i';
        if (preg_match($padraoQuebra, $decodedContent, $matches)) {
            $dados['nome'] = trim($matches[1] . ' ' . $matches[2]);
            $this->log("Nome extraído (quebra linha): " . $dados['nome']);
            return;
        }
        
        // Padrão 4: Nome com quebra específica para o padrão observado
        $padraoQuebraEspecifica = '/\*([A-Z]+\s+[A-Z]+\s+[A-Z]+)\s*[\r\n\s]*([A-Z]+)\s*\*/i';
        if (preg_match($padraoQuebraEspecifica, $decodedContent, $matches)) {
            $dados['nome'] = trim($matches[1] . ' ' . $matches[2]);
            $this->log("Nome extraído (quebra específica): " . $dados['nome']);
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
            $dados['valor'] = 'R$ ' . trim($matches[1]);
            $this->log("Valor extraído (direto): " . $dados['valor']);
            return;
        }
        
        // Padrão 2: Qualquer R$ com formato correto
        $padraoValorSimples = '/R\$\s*([0-9]+,\d{2})(?![0-9])/';
        if (preg_match($padraoValorSimples, $decodedContent, $matches)) {
            $dados['valor'] = 'R$ ' . trim($matches[1]);
            $this->log("Valor extraído (direto fallback): " . $dados['valor']);
            return;
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
            $dados['valor'] = 'R$ ' . trim($matches[1]);
            $this->log("Valor extraído (encaminhado asterisco): " . $dados['valor']);
            return;
        }
        
        // Padrão 2: HTML strong em email encaminhado
        $padraoEncaminhado2 = '/<strong>R\$\s*([0-9]+,\d{2})<\/strong>/i';
        if (preg_match($padraoEncaminhado2, $decodedContent, $matches)) {
            $dados['valor'] = 'R$ ' . trim($matches[1]);
            $this->log("Valor extraído (encaminhado HTML): " . $dados['valor']);
            return;
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
     * Extrai ID do cliente de email encaminhado
     */
    private function extrairIdClienteEncaminhado($emailContent) {
        $this->log("Extraindo ID de cliente de email encaminhado...");
        
        // Divide o email em linhas
        $linhas = explode("\n", $emailContent);
        
        // Procura por "-------- Mensagem encaminhada --------"
        foreach ($linhas as $index => $linha) {
            if (strpos($linha, '-------- Mensagem encaminhada --------') !== false) {
                $this->log("Marcador 'Mensagem encaminhada' encontrado na linha: " . ($index + 1));
                
                // Verifica as linhas anteriores buscando um ID numérico isolado
                for ($i = $index - 1; $i >= 0; $i--) {
                    $linhaTrimmed = trim($linhas[$i]);
                    
                    // Ignora linhas vazias, HTML tags e headers
                    if (empty($linhaTrimmed) || 
                        strpos($linhaTrimmed, '<') !== false || 
                        strpos($linhaTrimmed, ':') !== false ||
                        strpos($linhaTrimmed, '→') !== false) {
                        continue;
                    }
                    
                    // Verifica se é um número puro (ID do cliente)
                    if (is_numeric($linhaTrimmed) && strlen($linhaTrimmed) >= 3) {
                        $this->log("ID encontrado: {$linhaTrimmed} (linha " . ($i + 1) . ")");
                        return intval($linhaTrimmed);
                    }
                }
                break;
            }
        }
        
        $this->log("ID não encontrado no email encaminhado");
        return null;
    }
    
    /**
     * Processa cliente: busca no arquivo ou adiciona se novo
     * Agora suporta atualização de ID via email encaminhado
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
        $linhaEncontrada = null;
        $indexLinha = -1;
        
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
                    $linhaEncontrada = $linha;
                    $indexLinha = $index;
                    
                    // Se cliente encontrado SEM ID e temos ID do encaminhamento
                    if (empty($id) && $idClienteEncaminhado) {
                        $this->log("Cliente encontrado sem ID - atualizando com ID: {$idClienteEncaminhado}");
                        return $this->atualizarIdCliente($nomeCliente, $idClienteEncaminhado, $arquivoClientes, $indexLinha, $linhas);
                    }
                    
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
     * Atualiza ID do cliente no arquivo e envia notificação ao admin
     */
    private function atualizarIdCliente($nomeCliente, $novoId, $arquivoClientes, $indexLinha, $linhas) {
        $this->log("=== ATUALIZANDO ID DO CLIENTE ===");
        $this->log("Cliente: {$nomeCliente}");
        $this->log("Novo ID: {$novoId}");
        
        // Verifica se o ID já existe no arquivo para outro cliente
        if ($this->verificarIdJaExiste($novoId, $linhas)) {
            $this->log("ERRO: ID {$novoId} já existe para outro cliente");
            return [
                'status' => 'id_duplicado',
                'nome' => $nomeCliente,
                'id_blesta' => null,
                'mensagem' => "ID {$novoId} já está em uso por outro cliente"
            ];
        }
        
        // Backup da linha original
        $linhaOriginal = $linhas[$indexLinha];
        
        // Atualiza a linha com o novo ID
        $linhas[$indexLinha] = $nomeCliente . '|' . $novoId;
        
        // Salva o arquivo atualizado
        $novoConteudo = implode("\n", $linhas) . "\n";
        
        if (file_put_contents($arquivoClientes, $novoConteudo, LOCK_EX) !== false) {
            $this->log("✓ ID atualizado com sucesso no arquivo");
            $this->log("Linha original: {$linhaOriginal}");
            $this->log("Nova linha: {$linhas[$indexLinha]}");
            
            // Envia email para o admin informando a mudança
            $emailEnviado = $this->enviarEmailAtualizacaoId($nomeCliente, $novoId, $linhaOriginal, $linhas[$indexLinha]);
            
            return [
                'status' => 'id_atualizado',
                'nome' => $nomeCliente,
                'id_blesta' => $novoId,
                'mensagem' => 'ID do cliente atualizado com sucesso. Admin notificado.',
                'linha_original' => trim($linhaOriginal),
                'linha_nova' => trim($linhas[$indexLinha]),
                'email_enviado' => $emailEnviado
            ];
        } else {
            $this->log("ERRO: Não foi possível salvar o arquivo atualizado");
            return [
                'status' => 'erro',
                'mensagem' => 'Erro ao salvar arquivo com novo ID'
            ];
        }
    }
    
    /**
     * Verifica se um ID já existe no arquivo para outro cliente
     */
    private function verificarIdJaExiste($idProcurado, $linhas) {
        foreach ($linhas as $linha) {
            if (strpos(trim($linha), '#') === 0 || strpos($linha, '|') === false) {
                continue;
            }
            
            list($nome, $id) = explode('|', $linha, 2);
            $id = trim($id);
            
            if (!empty($id) && $id == $idProcurado) {
                $this->log("ID {$idProcurado} já existe para cliente: " . trim($nome));
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Envia email para admin sobre atualização de ID
     */
    private function enviarEmailAtualizacaoId($nomeCliente, $novoId, $linhaOriginal, $linhaNova) {
        $this->log("Enviando email para admin sobre atualização de ID...");
        
        $para = ADMIN_EMAIL;
        $assunto = 'ID de Cliente Atualizado - Webhook Nubank';
        $corpo = "Olá Admin,\n\n";
        $corpo .= "O ID de um cliente foi atualizado automaticamente via webhook Nubank:\n\n";
        $corpo .= "Cliente: {$nomeCliente}\n";
        $corpo .= "Novo ID: {$novoId}\n";
        $corpo .= "Data: " . date('d/m/Y H:i:s') . "\n\n";
        $corpo .= "MODIFICAÇÃO NO ARQUIVO:\n";
        $corpo .= "Linha original: " . trim($linhaOriginal) . "\n";
        $corpo .= "Nova linha: " . trim($linhaNova) . "\n\n";
        $corpo .= "O pagamento deste cliente já foi processado automaticamente.\n\n";
        $corpo .= "Webhook Nubank - Sistema Automático";
        
        $cabecalhos = "From: webhook@dominio.com\r\n";
        $cabecalhos .= "Reply-To: webhook@dominio.com\r\n";
        $cabecalhos .= "X-Mailer: PHP/" . phpversion();
        
        $resultado = mail($para, $assunto, $corpo, $cabecalhos);
        
        if ($resultado) {
            $this->log("Email de atualização enviado com sucesso para: {$para}");
        } else {
            $this->log("ERRO: Falha ao enviar email de atualização para: {$para}");
        }
        
        return $resultado;
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
        $assunto = 'Novo Cliente Nubank - ID Necessário';
        $corpo = "Olá Admin,\n\n";
        $corpo .= "Um novo cliente foi detectado no webhook do Nubank:\n\n";
        $corpo .= "Nome: {$nomeCliente}\n";
        $corpo .= "Data: " . date('d/m/Y H:i:s') . "\n\n";
        $corpo .= "Por favor, adicione o ID do Blesta para este cliente no arquivo:\n";
        $corpo .= "clientes_nubank.txt\n\n";
        $corpo .= "Formato: {$nomeCliente}|ID_BLESTA\n\n";
        $corpo .= "Webhook Nubank - Sistema Automático";
        
        $cabecalhos = "From: webhook@dominio.com\r\n";
        $cabecalhos .= "Reply-To: webhook@dominio.com\r\n";
        $cabecalhos .= "X-Mailer: PHP/" . phpversion();
        
        // Usa mail() do PHP que funciona com exim no DirectAdmin
        $resultado = mail($para, $assunto, $corpo, $cabecalhos);
        
        if ($resultado) {
            $this->log("Email enviado com sucesso para: {$para}");
        } else {
            $this->log("ERRO: Falha ao enviar email para: {$para}");
        }
        
        return $resultado;
    }
    
    /**
     * Consulta faturas em aberto para um cliente específico
     */
    private function consultarFaturasEmAberto($clienteId) {
        $this->log("=== CONSULTANDO FATURAS EM ABERTO ===");
        $this->log("Cliente ID: {$clienteId}");
        
        if (!$this->blestaBaseUrl || !$this->blestaApiUser || !$this->blestaApiKey) {
            $this->log("ERRO: Configurações da API do Blesta não disponíveis");
            return false;
        }
        
        $url = rtrim($this->blestaBaseUrl, '/') . '/api/invoices/getlist.json';
        
        $postData = array(
            'client_id' => $clienteId,
            'status' => 'open'
        );
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Basic ' . base64_encode($this->blestaApiUser . ':' . $this->blestaApiKey),
                'Content-Type: application/x-www-form-urlencoded'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            $this->log("ERRO cURL: {$error}");
            return false;
        }
        
        curl_close($curl);
        
        $this->log("Resposta HTTP: {$httpCode}");
        $this->log("Resposta API: " . substr($response, 0, 500) . "...");
        
        if ($httpCode !== 200) {
            $this->log("ERRO: HTTP {$httpCode} - Falha na consulta");
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("ERRO: Resposta JSON inválida");
            return false;
        }
        
        if (isset($data['response']) && is_array($data['response'])) {
            $this->log("Faturas encontradas: " . count($data['response']));
            return $data['response'];
        }
        
        $this->log("Nenhuma fatura encontrada ou erro na resposta");
        return array();
    }
    
    /**
     * Encontra fatura com valor correspondente
     * Agora suporta regras flexíveis: soma múltipla e tolerância de arredondamento
     */
    public function encontrarFaturaCorreta($faturasAbertas, $valorPago) {
        $this->log("=== LOCALIZANDO FATURA CORRETA ===");
        $this->log("Valor pago: {$valorPago}");
        $this->log("Faturas para verificar: " . count($faturasAbertas));
        $this->log("Regras flexíveis: " . (REGRAS_FLEXIVEIS_PAGAMENTO ? 'ATIVADAS' : 'DESATIVADAS'));
        
        $faturasComValorCorreto = array();
        
        // ETAPA 1: Busca exata (tolerância de 1 centavo)
        foreach ($faturasAbertas as $fatura) {
            $valorFatura = floatval($fatura['total']);
            $this->log("Fatura #{$fatura['id']}: R$ {$valorFatura}");
            
            // Tolerância de 1 centavo
            if (abs($valorFatura - $valorPago) < 0.01) {
                $faturasComValorCorreto[] = $fatura;
                $this->log("✓ Fatura #{$fatura['id']} corresponde ao valor");
            }
        }
        
        $totalFaturasCorretas = count($faturasComValorCorreto);
        $this->log("Faturas com valor exato: {$totalFaturasCorretas}");
        
        // RESULTADO EXATO ENCONTRADO
        if ($totalFaturasCorretas === 1) {
            $fatura = $faturasComValorCorreto[0];
            $this->log("✓ FATURA SELECIONADA (valor exato): #{$fatura['id']} - R$ {$fatura['total']}");
            return ['tipo' => 'individual', 'faturas' => [$fatura]];
        } elseif ($totalFaturasCorretas > 1) {
            $this->log("✗ Múltiplas faturas com mesmo valor - não é possível determinar qual");
            return null;
        }
        
        // ETAPA 2: Regras flexíveis (se ativadas)
        if (!REGRAS_FLEXIVEIS_PAGAMENTO) {
            $this->log("✗ Nenhuma fatura com valor correspondente (regras flexíveis desativadas)");
            return null;
        }
        
        $this->log("=== APLICANDO REGRAS FLEXÍVEIS ===");
        
        // REGRA 1: Tolerância de 15% para pagamento MAIOR (apenas 1 fatura)
        // CONDIÇÃO ESPECIAL: Só aplica se valor pago termina em ,00 e fatura NÃO termina em ,00
        if (count($faturasAbertas) === 1) {
            $fatura = $faturasAbertas[0];
            $valorFatura = floatval($fatura['total']);
            $diferenca = $valorPago - $valorFatura;
            
            if ($diferenca > 0) { // Pagou mais que o devido
                // Verifica condição especial: pago ,00 e fatura diferente de ,00
                $valorPagoTerminaEm00 = (fmod($valorPago * 100, 100) == 0); // Termina em ,00
                $valorFaturaTerminaEm00 = (fmod($valorFatura * 100, 100) == 0); // Termina em ,00
                
                $this->log("Tolerância: Valor fatura R$ {$valorFatura}, pago R$ {$valorPago}");
                $this->log("Verificação de formato: Pago termina em ,00? " . ($valorPagoTerminaEm00 ? 'SIM' : 'NÃO'));
                $this->log("Verificação de formato: Fatura termina em ,00? " . ($valorFaturaTerminaEm00 ? 'SIM' : 'NÃO'));
                
                if ($valorPagoTerminaEm00 && !$valorFaturaTerminaEm00) {
                    $percentualDiferenca = ($diferenca / $valorFatura) * 100;
                    $this->log("Diferença: +R$ {$diferenca} ({$percentualDiferenca}%)");
                    
                    if ($percentualDiferenca <= 15) {
                        $this->log("✓ FATURA SELECIONADA (tolerância 15%): #{$fatura['id']} - R$ {$valorFatura}");
                        $this->log("✓ Aplicando transação com valor pago: R$ {$valorPago}");
                        return ['tipo' => 'tolerancia', 'faturas' => [$fatura], 'valor_aplicado' => $valorPago];
                    } else {
                        $this->log("✗ Diferença ({$percentualDiferenca}%) excede tolerância de 15%");
                    }
                } else {
                    $this->log("✗ Condição de formato não atendida para tolerância:");
                    if (!$valorPagoTerminaEm00) {
                        $this->log("  - Valor pago não termina em ,00");
                    }
                    if ($valorFaturaTerminaEm00) {
                        $this->log("  - Valor da fatura já termina em ,00");
                    }
                }
            }
        }
        
        // REGRA 2: Soma de múltiplas faturas
        $this->log("Tentando soma de múltiplas faturas...");
        $resultadoSoma = $this->tentarSomaMultiplasFaturas($faturasAbertas, $valorPago);
        
        if ($resultadoSoma) {
            return $resultadoSoma;
        }
        
        $this->log("✗ Nenhuma combinação válida encontrada");
        return null;
    }
    
    /**
     * Tenta encontrar combinação de faturas que somem o valor pago
     */
    private function tentarSomaMultiplasFaturas($faturasAbertas, $valorPago) {
        $this->log("=== SOMA DE MÚTIPLAS FATURAS ===");
        
        $totalFaturas = count($faturasAbertas);
        
        // Calcula soma total de todas as faturas
        $somaTotal = 0;
        $valoresFaturas = array();
        
        foreach ($faturasAbertas as $fatura) {
            $valor = floatval($fatura['total']);
            $somaTotal += $valor;
            $valoresFaturas[] = $valor;
            $this->log("Fatura #{$fatura['id']}: R$ {$valor}");
        }
        
        $this->log("Soma total de todas as faturas: R$ {$somaTotal}");
        
        // Verifica se a soma total corresponde ao valor pago (tolerância de 1 centavo)
        if (abs($somaTotal - $valorPago) < 0.01) {
            $this->log("✓ SOMA TOTAL CORRESPONDE! Dando baixa em todas as {$totalFaturas} faturas");
            return ['tipo' => 'soma_total', 'faturas' => $faturasAbertas];
        }
        
        $this->log("Soma total não corresponde. Tentando combinações parciais...");
        
        // Tenta combinações parciais (para casos complexos)
        for ($i = 2; $i <= $totalFaturas - 1; $i++) {
            $combinacoes = $this->gerarCombinacoes($faturasAbertas, $i);
            
            foreach ($combinacoes as $combinacao) {
                $somaCombinacao = 0;
                $idsFaturas = array();
                
                foreach ($combinacao as $fatura) {
                    $somaCombinacao += floatval($fatura['total']);
                    $idsFaturas[] = $fatura['id'];
                }
                
                if (abs($somaCombinacao - $valorPago) < 0.01) {
                    $this->log("✓ COMBINAÇÃO ENCONTRADA! Faturas: [" . implode(', ', $idsFaturas) . "] = R$ {$somaCombinacao}");
                    return ['tipo' => 'soma_parcial', 'faturas' => $combinacao];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Gera combinações de faturas para teste de soma
     */
    private function gerarCombinacoes($faturas, $tamanho) {
        $combinacoes = array();
        $total = count($faturas);
        
        // Algoritmo simples para combinações pequenas
        if ($tamanho == 2) {
            for ($i = 0; $i < $total - 1; $i++) {
                for ($j = $i + 1; $j < $total; $j++) {
                    $combinacoes[] = array($faturas[$i], $faturas[$j]);
                }
            }
        } elseif ($tamanho == 3 && $total >= 3) {
            for ($i = 0; $i < $total - 2; $i++) {
                for ($j = $i + 1; $j < $total - 1; $j++) {
                    for ($k = $j + 1; $k < $total; $k++) {
                        $combinacoes[] = array($faturas[$i], $faturas[$j], $faturas[$k]);
                    }
                }
            }
        }
        // Para mais de 3, usamos todas as faturas (caso já testado acima)
        
        return $combinacoes;
    }
    
    /**
     * Adiciona transação no Blesta
     * Agora suporta múltiplas faturas
     */
    private function adicionarTransacaoNoBlesta($clienteId, $valor, $faturas) {
        $this->log("=== ADICIONANDO TRANSAÇÃO NO BLESTA ===");
        $this->log("Cliente: #{$clienteId}");
        $this->log("Valor: R$ {$valor}");
        
        // Prepara array de faturas para a transação
        $invoicesArray = array();
        $totalFaturas = count($faturas);
        
        // Gera ID da transação baseado nas faturas
        if ($totalFaturas === 1) {
            // Fatura individual: Nubank_123
            $fatura = $faturas[0];
            $transactionId = 'Nubank_' . $fatura['id'];
            $valorFatura = floatval($fatura['total']);
            $this->log("Fatura individual: #{$fatura['id']} - R$ {$valorFatura}");
            $invoicesArray[] = array(
                'invoice_id' => $fatura['id'],
                'amount' => $valorFatura  // ✅ VALOR DA FATURA, NÃO VALOR TOTAL
            );
        } else {
            // Múltiplas faturas: Nubank_123_456_789
            $idsFaturas = array_map(function($f) { return $f['id']; }, $faturas);
            $transactionId = 'Nubank_' . implode('_', $idsFaturas);
            $this->log("Múltiplas faturas ({$totalFaturas}):");
            foreach ($faturas as $fatura) {
                $valorFatura = floatval($fatura['total']);
                $this->log("  - Fatura #{$fatura['id']}: R$ {$valorFatura}");
                $invoicesArray[] = array(
                    'invoice_id' => $fatura['id'],
                    'amount' => $valorFatura  // ✅ VALOR ESPECÍFICO DE CADA FATURA
                );
            }
        }
        
        $this->log("ID da transação: {$transactionId}");
        
        if (!$this->blestaBaseUrl || !$this->blestaApiUser || !$this->blestaApiKey) {
            $this->log("ERRO: Configurações da API do Blesta não disponíveis");
            return false;
        }
        
        $url = rtrim($this->blestaBaseUrl, '/') . '/api/transactions/add.json';
        
        $dateFormatted = date('Y-m-d H:i:s');
        
        $postData = array(
            'vars' => array(
                'client_id' => $clienteId,
                'amount' => $valor,
                'currency' => 'BRL',
                'type' => 'other',
                'transaction_id' => $transactionId,
                'status' => 'approved',
                'gateway_id' => 0, // Gateway padrão
                'date_added' => $dateFormatted,
                'invoices' => $invoicesArray
            )
        );
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Basic ' . base64_encode($this->blestaApiUser . ':' . $this->blestaApiKey),
                'Content-Type: application/x-www-form-urlencoded'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            $this->log("ERRO cURL: {$error}");
            return false;
        }
        
        curl_close($curl);
        
        $this->log("Resposta HTTP: {$httpCode}");
        $this->log("Resposta API: " . substr($response, 0, 500));
        
        if ($httpCode !== 200) {
            $this->log("ERRO: HTTP {$httpCode} - Falha ao adicionar transação");
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("ERRO: Resposta JSON inválida");
            return false;
        }
        
        // Verifica se a transação foi criada com sucesso
        // A API do Blesta pode retornar o ID da transação de diferentes formas:
        // 1. Como array: {"response": {"id": "123"}}
        // 2. Como string numérica: {"response": "173"}
        // 3. Como número: {"response": 173}
        
        $blestaTransactionId = null;
        $transactionSuccess = false;
        
        if (isset($data['response'])) {
            if (is_array($data['response']) && isset($data['response']['id'])) {
                // Resposta em formato de array com ID
                $blestaTransactionId = $data['response']['id'];
                $transactionSuccess = true;
                $this->log("✓ TRANSAÇÃO CRIADA E APLICADA COM SUCESSO (formato array)!");
                $this->log("ID da transação no Blesta: " . $blestaTransactionId);
                $this->log("Faturas processadas automaticamente: " . count($faturas));
            } elseif ((is_string($data['response']) && is_numeric($data['response'])) || is_numeric($data['response'])) {
                // API retornou ID direto como string numérica ou número
                $blestaTransactionId = $data['response'];
                $transactionSuccess = true;
                $this->log("✓ TRANSAÇÃO CRIADA E APLICADA COM SUCESSO (formato ID direto)!");
                $this->log("ID da transação no Blesta: " . $blestaTransactionId);
                $this->log("Faturas processadas automaticamente: " . count($faturas));
            } elseif (is_array($data['response'])) {
                // Resposta é array mas não tem 'id', pode ser sucesso mesmo assim
                $transactionSuccess = true;
                $this->log("✓ TRANSAÇÃO CRIADA E APLICADA COM SUCESSO (formato array sem ID específico)!");
                $this->log("Resposta: " . json_encode($data['response']));
                $this->log("Faturas processadas automaticamente: " . count($faturas));
            }
        }
        
        if ($transactionSuccess) {
            return array(
                'status' => 'success',
                'transaction_id' => $transactionId,
                'blesta_transaction_id' => $blestaTransactionId,
                'response' => $data['response']
            );
        } else {
            $this->log("ERRO: Falha ao criar transação - " . json_encode($data));
            return false;
        }
    }
    
    /**
     * Processa pagamento completo quando cliente tem ID
     */
    private function processarPagamento($clienteInfo, $valor) {
        $this->log("=== INICIANDO PROCESSAMENTO DE PAGAMENTO ===");
        
        $clienteId = $clienteInfo['id_blesta'];
        $nomeCliente = $clienteInfo['nome'];
        
        $this->log("Cliente: {$nomeCliente} (ID: {$clienteId})");
        $this->log("Valor: {$valor}");
        
        // 1. Consultar faturas em aberto
        $faturas = $this->consultarFaturasEmAberto($clienteId);
        
        if ($faturas === false) {
            return array(
                'status' => 'erro',
                'mensagem' => 'Erro ao consultar faturas na API do Blesta'
            );
        }
        
        if (empty($faturas)) {
            $this->log("AVISO: Cliente não possui faturas em aberto");
            return array(
                'status' => 'sem_faturas',
                'mensagem' => 'Cliente não possui faturas em aberto'
            );
        }
        
        // 2. Encontrar fatura com valor correspondente
        $valorLimpo = $this->limparValor($valor);
        $faturaCorreta = $this->encontrarFaturaCorreta($faturas, $valorLimpo);
        
        if (!$faturaCorreta) {
            return array(
                'status' => 'valor_incompativel',
                'mensagem' => 'Nenhuma fatura com valor correspondente encontrada',
                'faturas_abertas' => count($faturas)
            );
        }
        
        // 3. Adicionar transação no Blesta
        $this->log("DEBUG: Iniciando criação de transação...");
        
        if ($faturaCorreta['tipo'] === 'individual') {
            $fatura = $faturaCorreta['faturas'][0];
            $this->log("DEBUG: Transação individual - ClienteId: {$clienteId}, Valor: {$valorLimpo}, FaturaId: {$fatura['id']}");
            $resultadoTransacao = $this->adicionarTransacaoNoBlesta($clienteId, $valorLimpo, [$fatura]);
        } elseif ($faturaCorreta['tipo'] === 'tolerancia') {
            $fatura = $faturaCorreta['faturas'][0];
            $valorAplicado = $faturaCorreta['valor_aplicado'];
            $this->log("DEBUG: Transação com tolerância - ClienteId: {$clienteId}, Valor: {$valorAplicado}, FaturaId: {$fatura['id']}");
            $resultadoTransacao = $this->adicionarTransacaoNoBlesta($clienteId, $valorAplicado, [$fatura]);
        } else { // soma_total ou soma_parcial
            $faturas = $faturaCorreta['faturas'];
            $totalFaturas = count($faturas);
            $idsFaturas = array_map(function($f) { return $f['id']; }, $faturas);
            $this->log("DEBUG: Transação múltipla - ClienteId: {$clienteId}, Valor: {$valorLimpo}, Faturas: [" . implode(', ', $idsFaturas) . "]");
            $resultadoTransacao = $this->adicionarTransacaoNoBlesta($clienteId, $valorLimpo, $faturas);
        }
        
        if (!$resultadoTransacao) {
            return array(
                'status' => 'erro_transacao',
                'mensagem' => 'Erro ao criar transação no Blesta'
            );
        }
        
        // 4. CORREÇÃO CRÍTICA: Aplicar transação às faturas (mesmo processo do index_production.php)
        if (isset($resultadoTransacao['blesta_transaction_id']) && $resultadoTransacao['blesta_transaction_id']) {
            $transactionId = $resultadoTransacao['blesta_transaction_id'];
            $this->log("ETAPA APLICAÇÃO: Aplicando transação #{$transactionId} às faturas");
            
            // Aplicar para cada fatura (seguindo lógica do index_production.php)
            $faturasParaAplicar = [];
            if ($faturaCorreta['tipo'] === 'individual' || $faturaCorreta['tipo'] === 'tolerancia') {
                $faturasParaAplicar = [$faturaCorreta['faturas'][0]];
            } else {
                $faturasParaAplicar = $faturaCorreta['faturas'];
            }
            
            foreach ($faturasParaAplicar as $fatura) {
                $valorFatura = floatval($fatura['total']);
                $this->log("Aplicando R$ {$valorFatura} à fatura #{$fatura['id']}");
                $resultadoAplicacao = $this->aplicarTransacaoAFatura($transactionId, $fatura['id'], $valorFatura);
                
                if ($resultadoAplicacao['status'] === 'success') {
                    $this->log("✓ Transação aplicada com sucesso à fatura #{$fatura['id']}");
                } else {
                    $this->log("✗ FALHA ao aplicar transação à fatura #{$fatura['id']}: " . $resultadoAplicacao['message']);
                }
            }
        } else {
            $this->log("AVISO: ID da transação não retornado - pulando etapa de aplicação");
        }
        
        $this->log("✓ PAGAMENTO PROCESSADO COM SUCESSO!");
        
        // Prepara resposta baseada no tipo de transação
        $resposta = array(
            'status' => 'processado',
            'mensagem' => 'Pagamento processado com sucesso',
            'tipo_transacao' => $faturaCorreta['tipo'],
            'transacao_id' => $resultadoTransacao['transaction_id'],
            'blesta_transacao_id' => $resultadoTransacao['blesta_transaction_id']
        );
        
        if ($faturaCorreta['tipo'] === 'individual') {
            $fatura = $faturaCorreta['faturas'][0];
            $resposta['fatura_id'] = $fatura['id'];
            $resposta['fatura_total'] = $fatura['total'];
        } elseif ($faturaCorreta['tipo'] === 'tolerancia') {
            $fatura = $faturaCorreta['faturas'][0];
            $resposta['fatura_id'] = $fatura['id'];
            $resposta['fatura_total'] = $fatura['total'];
            $resposta['valor_aplicado'] = $faturaCorreta['valor_aplicado'];
            $resposta['observacao'] = 'Aplicada tolerância de arredondamento (15%)';
        } else {
            $faturas = $faturaCorreta['faturas'];
            $resposta['faturas_ids'] = array_map(function($f) { return $f['id']; }, $faturas);
            $resposta['faturas_totais'] = array_map(function($f) { return floatval($f['total']); }, $faturas);
            $resposta['total_faturas'] = count($faturas);
            $resposta['observacao'] = 'Baixa aplicada em múltiplas faturas';
        }
        
        return $resposta;
    }
    
    /**
     * Aplica uma transação a uma fatura específica no Blesta
     * Baseado na implementação funcional do index_production.php
     */
    private function aplicarTransacaoAFatura($transaction_id, $invoice_id, $amount) {
        try {
            $this->log("=== APLICANDO TRANSAÇÃO À FATURA ===");
            $this->log("→ Transação ID: #{$transaction_id}");
            $this->log("→ Fatura ID: #{$invoice_id}");
            $this->log("→ Valor: R$ {$amount}");
            
            if (!$this->blestaBaseUrl || !$this->blestaApiUser || !$this->blestaApiKey) {
                $this->log("ERRO: Configurações da API do Blesta incompletas para aplicação");
                throw new Exception("Configurações da API do Blesta incompletas");
            }
            
            $url = rtrim($this->blestaBaseUrl, '/') . '/api/transactions/apply.json';
            
            $postData = array(
                'transaction_id' => $transaction_id,
                'vars' => array(
                    'date' => date('Y-m-d H:i:s'),
                    'amounts' => array(
                        array(
                            'invoice_id' => $invoice_id,
                            'amount' => $amount
                        )
                    )
                )
            );
            
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => http_build_query($postData),
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Basic ' . base64_encode($this->blestaApiUser . ':' . $this->blestaApiKey),
                    'Content-Type: application/x-www-form-urlencoded'
                ),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ));
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            
            if (curl_errno($curl)) {
                $error = curl_error($curl);
                curl_close($curl);
                $this->log("ERRO cURL: {$error}");
                throw new Exception("Erro cURL ao aplicar transação: " . $error);
            }
            
            curl_close($curl);
            
            $this->log("Resposta HTTP aplicação: {$httpCode}");
            $this->log("Resposta API aplicação: " . substr($response, 0, 500));
            
            if ($httpCode !== 200) {
                $this->log("ERRO: HTTP {$httpCode} - Falha ao aplicar transação");
                throw new Exception("Erro HTTP {$httpCode} ao aplicar transação");
            }
            
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log("ERRO: Resposta JSON inválida na aplicação");
                throw new Exception("Erro ao decodificar resposta JSON da aplicação");
            }
            
            $this->log("✓ Transação #{$transaction_id} aplicada com sucesso à fatura #{$invoice_id}");
            
            return array(
                'status' => 'success',
                'message' => "Transação aplicada com sucesso à fatura #{$invoice_id}",
                'transaction_id' => $transaction_id,
                'invoice_id' => $invoice_id,
                'amount' => $amount,
                'response' => $data
            );
            
        } catch (Exception $e) {
            $this->log("✗ FALHA ao aplicar transação à fatura: " . $e->getMessage());
            return array(
                'status' => 'error',
                'message' => $e->getMessage(),
                'transaction_id' => $transaction_id,
                'invoice_id' => $invoice_id
            );
        }
    }
    
    /**
     * Limpa e converte valor para float
     */
    private function limparValor($valor) {
        // Remove "R$", espaços e converte vírgula para ponto
        $valorLimpo = str_replace(array('R$', ' ', ','), array('', '', '.'), $valor);
        $valorFloat = floatval($valorLimpo);
        
        $this->log("Valor original: '{$valor}' -> Valor limpo: {$valorFloat}");
        
        return $valorFloat;
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
                    $this->log("✓ Remetente autorizado: {$remetente}");
                    return true;
                }
            }
            
            $this->log("✗ Remetente NÃO autorizado: {$remetente}");
            return false;
        }
        
        // Tenta extrair de outras formas (Sender, Return-Path)
        if (preg_match('/^Sender:\s*.*?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/m', $emailContent, $matches)) {
            $remetente = strtolower(trim($matches[1]));
            $this->log("Remetente (Sender) detectado: {$remetente}");
            
            foreach ($remetentesAutorizados as $autorizado) {
                if (strtolower($autorizado) === $remetente) {
                    $this->log("✓ Remetente autorizado via Sender: {$remetente}");
                    return true;
                }
            }
        }
        
        $this->log("✗ ERRO: Não foi possível identificar remetente no email");
        return false;
    }
    
    /**
     * Verifica permissões dos arquivos críticos
     */
    private function verificarPermissoesArquivos() {
        $this->log("=== VERIFICAÇÃO DE PERMISSÕES ===");
        
        $arquivosCriticos = [
            dirname(__FILE__) . '/webhook_nubank.php' => ['recomendado' => '700', 'desc' => 'Script principal'],
            dirname(__FILE__) . '/.env' => ['recomendado' => '600', 'desc' => 'Arquivo de configuração'],
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
                $this->log("AVISO: Arquivo não encontrado: {$arquivo}");
            }
        }
        
        if (!empty($problemasEncontrados)) {
            $this->enviarRelatorioSeguranca($problemasEncontrados);
        } else {
            $this->log("✓ Todas as permissões estão corretas");
        }
    }
    
    /**
     * Envia relatório de segurança por email quando há problemas de permissão
     */
    private function enviarRelatorioSeguranca($problemas) {
        $this->log("Enviando relatório de segurança - problemas encontrados: " . count($problemas));
        
        $para = ADMIN_EMAIL;
        $assunto = 'ALERTA DE SEGURANCA - Webhook Nubank - Permissoes Incorretas';
        
        $corpo = "RELATORIO DE SEGURANCA - WEBHOOK NUBANK\n\n";
        $corpo .= "Data/Hora: " . date('d/m/Y H:i:s') . "\n\n";
        $corpo .= "PROBLEMAS DE PERMISSAO DETECTADOS:\n\n";
        
        foreach ($problemas as $problema) {
            $corpo .= "Arquivo: {$problema['desc']} ({$problema['arquivo']})\n";
            $corpo .= "Permissão atual: {$problema['atual']}\n";
            $corpo .= "Permissão recomendada: {$problema['recomendada']}\n";
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
        $corpo .= "Sistema de Segurança - Webhook Nubank";
        
        $cabecalhos = "From: security@dominio.com\r\n";
        $cabecalhos .= "Reply-To: admin@dominio.com\r\n";
        $cabecalhos .= "X-Priority: 1\r\n";
        $cabecalhos .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $cabecalhos .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $cabecalhos .= "Content-Transfer-Encoding: 8bit";
        
        $resultado = mail($para, $assunto, $corpo, $cabecalhos);
        
        if ($resultado) {
            $this->log("Relatorio de seguranca enviado para: {$para}");
        } else {
            $this->log("ERRO: Falha ao enviar relatorio de seguranca - Verifique configuracao do servidor de email");
            // Salva o relatório em arquivo como backup
            $this->salvarRelatorioSegurancaArquivo($problemas, $corpo);
        }
        
        return $resultado;
    }
    
    /**
     * Envia alerta de segurança para tentativas de acesso não autorizado
     */
    private function enviarAlertaSeguranca($tipoAlerta, $emailContent) {
        $this->log("Enviando alerta de segurança: {$tipoAlerta}");
        
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
            if (preg_match('/^Sender:\s*(.+)$/m', $emailContent, $matches)) {
                $corpo .= "Sender: " . trim($matches[1]) . "\n";
            }
            if (preg_match('/^Return-Path:\s*(.+)$/m', $emailContent, $matches)) {
                $corpo .= "Return-Path: " . trim($matches[1]) . "\n";
            }
            
            $corpo .= "\nRemetentes autorizados: " . implode(', ', REMETENTES_AUTORIZADOS) . "\n\n";
        }
        
        $corpo .= "Esta tentativa foi BLOQUEADA automaticamente.\n";
        $corpo .= "Nenhum processamento foi realizado.\n\n";
        $corpo .= "ACAO RECOMENDADA:\n";
        $corpo .= "1. Verifique se esta tentativa e legitima\n";
        $corpo .= "2. Se legitima, adicione o remetente a lista de autorizados\n";
        $corpo .= "3. Se suspeita, investigue possivel tentativa de ataque\n\n";
        $corpo .= "Sistema de Segurança - Webhook Nubank";
        
        $cabecalhos = "From: security@dominio.com\r\n";
        $cabecalhos .= "Reply-To: admin@dominio.com\r\n";
        $cabecalhos .= "X-Priority: 1\r\n";
        $cabecalhos .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $cabecalhos .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $cabecalhos .= "Content-Transfer-Encoding: 8bit";
        
        $resultado = mail($para, $assunto, $corpo, $cabecalhos);
        
        if ($resultado) {
            $this->log("Alerta de seguranca enviado para: {$para}");
        } else {
            $this->log("ERRO: Falha ao enviar alerta de seguranca - Verifique configuracao do servidor de email");
            // Salva o alerta em arquivo como backup
            $this->salvarAlertaSegurancaArquivo($tipoAlerta, $corpo);
        }
        
        return $resultado;
    }
    
    /**
     * Salva alerta de segurança em arquivo quando email falha
     */
    private function salvarAlertaSegurancaArquivo($tipoAlerta, $corpoEmail) {
        $nomeArquivo = dirname(__FILE__) . '/alerta_seguranca_' . date('Y-m-d_H-i-s') . '.txt';
        
        $conteudo = "ALERTA CRITICO DE SEGURANCA - WEBHOOK NUBANK\n";
        $conteudo .= "Data/Hora: " . date('d/m/Y H:i:s') . "\n";
        $conteudo .= "Tipo: {$tipoAlerta}\n";
        $conteudo .= "AVISO: Este alerta foi salvo em arquivo pois o envio por email falhou.\n\n";
        $conteudo .= $corpoEmail . "\n\n";
        $conteudo .= "ACAO URGENTE NECESSARIA:\n";
        $conteudo .= "1. Verifique este arquivo imediatamente\n";
        $conteudo .= "2. Configure um servidor de email no sistema\n";
        $conteudo .= "3. Investigue a tentativa de acesso bloqueada\n";
        
        if (file_put_contents($nomeArquivo, $conteudo, LOCK_EX) !== false) {
            $this->log("Alerta critico salvo em arquivo: " . basename($nomeArquivo));
            chmod($nomeArquivo, 0600); // Permissão segura
        } else {
            $this->log("ERRO: Nao foi possivel salvar alerta em arquivo");
        }
    }
    
    /**
     * Salva relatório de segurança em arquivo quando email falha
     */
    private function salvarRelatorioSegurancaArquivo($problemas, $corpoEmail) {
        $nomeArquivo = dirname(__FILE__) . '/relatorio_seguranca_' . date('Y-m-d_H-i-s') . '.txt';
        
        $conteudo = "RELATORIO DE SEGURANCA - WEBHOOK NUBANK\n";
        $conteudo .= "Data/Hora: " . date('d/m/Y H:i:s') . "\n";
        $conteudo .= "AVISO: Este relatorio foi salvo em arquivo pois o envio por email falhou.\n\n";
        $conteudo .= $corpoEmail . "\n\n";
        $conteudo .= "ACAO NECESSARIA:\n";
        $conteudo .= "1. Configure um servidor de email no sistema\n";
        $conteudo .= "2. Ou monitore manualmente os arquivos de relatorio\n";
        $conteudo .= "3. Execute as correcoes de permissao listadas acima\n";
        
        if (file_put_contents($nomeArquivo, $conteudo, LOCK_EX) !== false) {
            $this->log("Relatorio salvo em arquivo: " . basename($nomeArquivo));
            chmod($nomeArquivo, 0600); // Permissão segura
        } else {
            $this->log("ERRO: Nao foi possivel salvar relatorio em arquivo");
        }
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
        echo "DADOS EXTRAÍDOS:\n";
        echo "Nome: " . $resultado['nome'] . "\n";
        echo "Valor: " . $resultado['valor'] . "\n";
        echo "Data: " . $resultado['data'] . "\n";
        echo "Tipo: " . $resultado['tipo'] . "\n";
        echo "\nINFORMAÇÕES DO CLIENTE:\n";
        echo "Status: " . $resultado['cliente_info']['status'] . "\n";
        echo "Mensagem: " . $resultado['cliente_info']['mensagem'] . "\n";
        if (isset($resultado['cliente_info']['id_blesta']) && $resultado['cliente_info']['id_blesta']) {
            echo "ID Blesta: " . $resultado['cliente_info']['id_blesta'] . "\n";
        }
        
        if (isset($resultado['pagamento_info'])) {
            echo "\nINFORMAÇÕES DO PAGAMENTO:\n";
            echo "Status: " . $resultado['pagamento_info']['status'] . "\n";
            echo "Mensagem: " . $resultado['pagamento_info']['mensagem'] . "\n";
            if (isset($resultado['pagamento_info']['fatura_id'])) {
                echo "Fatura ID: " . $resultado['pagamento_info']['fatura_id'] . "\n";
                echo "Valor da Fatura: R$ " . $resultado['pagamento_info']['fatura_total'] . "\n";
                echo "Transação ID: " . $resultado['pagamento_info']['transacao_id'] . "\n";
            }
            if (isset($resultado['pagamento_info']['faturas_abertas'])) {
                echo "Faturas em aberto: " . $resultado['pagamento_info']['faturas_abertas'] . "\n";
            }
        }
        exit(0);
    } else {
        echo "ERRO: Não foi possível processar o email\n";
        exit(1);
    }
} else {
    // Se chamado via web, retorna JSON
    header('Content-Type: application/json');
    $processor = new NubankEmailProcessor();
    $resultado = $processor->processEmail();
    echo json_encode($resultado ?: ['erro' => 'Não foi possível processar email']);
}
?>
