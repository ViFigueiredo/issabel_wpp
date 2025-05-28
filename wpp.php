<?php
// Ativa os erros para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Sao_Paulo');

registrarLog("Acesso recebido - IP: {$_SERVER['REMOTE_ADDR']} - GET: " . json_encode($_GET));

// Função para tratar número (agora com detalhes no log quando inválido)
function tratarNumero($numeroBruto, $registrarLog = true) {
    // Remove tudo que não for número
    $numero = preg_replace('/\D/', '', $numeroBruto);
    $numeroOriginal = $numero; // Guarda para possível log

    // Verifica se é um número simples (sem DDI e sem DDD)
    if (strlen($numero) === 8 || strlen($numero) === 9) {
        // Adiciona 5581 (DDI + DDD 81) para números sem DDD/DDI
        $numeroCompleto = '5581' . $numero;
        
        if ($registrarLog) {
            registrarLog("⚠️ Número simples detectado: $numeroOriginal | Adicionado DDI 55 e DDD 81: $numeroCompleto");
        }
        
        return $numeroCompleto;
    }

    // Remove o DDI 55 se já estiver presente
    if (substr($numero, 0, 2) === '55') {
        $numero = substr($numero, 2);
    }

    // Lista de todos os DDDs válidos no Brasil (2024)
    $dddsValidos = [
        '11', '12', '13', '14', '15', '16', '17', '18', '19', // Região 1 (SP e parte do interior)
        '21', '22', '24', '27', '28', // Região 2 (RJ e ES)
        '31', '32', '33', '34', '35', '37', '38', // Região 3 (MG)
        '41', '42', '43', '44', '45', '46', '47', '48', '49', // Região 4 (PR e SC)
        '51', '53', '54', '55', // Região 5 (RS)
        '61', '62', '63', '64', '65', '66', '67', '68', '69', // Região 6 (DF, GO, TO, MT, MS, RO, AC)
        '71', '73', '74', '75', '77', '79', // Região 7 (BA e SE)
        '81', '82', '83', '84', '85', '86', '87', '88', '89', // Região 8 (PE, AL, PB, RN, CE, PI, MA)
        '91', '92', '93', '94', '95', '96', '97', '98', '99'  // Região 9 (PA, AM, RR, AP)
    ];

    // Pega os 2 primeiros dígitos (DDD)
    $ddd = substr($numero, 0, 2);
    $tamanho = strlen($numero);

    // Verifica a validade do número
    $dddValido = in_array($ddd, $dddsValidos);
    $tamanhoValido = ($tamanho === 10 || $tamanho === 11);
    
    if ($dddValido && $tamanhoValido) {
        return '55' . $numero; // Padrão: 55 + DDD + Número
    } else {
        // Registra detalhes da invalidez se solicitado
        if ($registrarLog) {
            $motivo = [];
            if (!$dddValido) $motivo[] = "DDD $ddd inválido";
            if (!$tamanhoValido) $motivo[] = "tamanho $tamanho dígitos (esperado 10 ou 11)";
            
            registrarLog("❌ Número inválido: $numeroOriginal | Motivo: " . implode(' + ', $motivo) . 
                       " | Número bruto: $numeroBruto");
        }
        return false;
    }
}

// Função para registrar log
function registrarLog($mensagemLog) {
    $pastaLogs = __DIR__ . '/logs_wpp';
    if (!file_exists($pastaLogs)) {
        mkdir($pastaLogs, 0777, true); // Cria pasta recursivamente
    }
    $dataHoje = date('Y-m-d');
    $horaAgora = date('H:i:s');
    $arquivoLog = $pastaLogs . "/log-$dataHoje.log";

    // Escreve no log com timestamp
    file_put_contents($arquivoLog, "[$horaAgora] $mensagemLog\n", FILE_APPEND);
}

// Pega os dados da URL (GET)
$numeroOriginal = isset($_GET['numero']) ? $_GET['numero'] : false;
$mensagem = isset($_GET['mensagem']) ? $_GET['mensagem'] : false;

// Registra os parâmetros recebidos
registrarLog("Parâmetros recebidos - Número: " . ($numeroOriginal ?: 'Não informado') . 
             " | Mensagem: " . ($mensagem ?: 'Não informada'));

echo "<h1>Dados recebidos via GET:</h1>";
echo "<p><strong>Número:</strong> " . htmlspecialchars($numeroOriginal ?: 'Não informado') . "</p>";
echo "<p><strong>Mensagem:</strong> " . htmlspecialchars($mensagem ?: 'Não informada') . "</p>";

// Verifica parâmetros obrigatórios
if (!$numeroOriginal || !$mensagem) {
    $erro = "Parâmetros obrigatórios faltando: ";
    $erro .= !$numeroOriginal ? "'numero' " : "";
    $erro .= !$mensagem ? "'mensagem'" : "";
    
    registrarLog("❌ $erro");
    echo "<p style='color: red;'>$erro</p>";
    exit;
}

// Processa o número
$numeroTratado = tratarNumero($numeroOriginal);

if ($numeroTratado === false) {
    // Já registrado na função tratarNumero
    echo "<p style='color: red;'>Número de telefone inválido</p>";
    exit;
}

// Se chegou aqui, número e mensagem são válidos
registrarLog("🔄 Processando - Número original: $numeroOriginal | Número tratado: $numeroTratado");

// Dados a serem enviados via POST
$data = [
    'numero' => $numeroTratado,
    'mensagem' => $mensagem
];

// Inicializa cURL
$ch = curl_init(''); # URL do webhook

// Configurações do POST
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

// Executa o POST
registrarLog("🔄 Enviando para webhook: " . json_encode($data));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Verifica erro
if (curl_errno($ch)) {
    $erro = curl_error($ch);
    registrarLog("❌ Erro cURL: $erro");
    echo "<p><strong>Erro na requisição:</strong> $erro</p>";
} else {
    registrarLog("✅ Resposta do webhook - HTTP $httpCode | Resposta: $response");
    echo "<p><strong>Resposta do servidor:</strong> HTTP $httpCode</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}

// Fecha conexão
curl_close($ch);
registrarLog("✅ Processo concluído");
registrarLog("----------------------------------------");
registrarLog("\r\n");
?>