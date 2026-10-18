<?php
/**
 * Conexão PDO com o banco portal_louvor.
 *
 * Ajuste as configurações para o seu ambiente local.
 * Não publique este arquivo com credenciais reais em repositório público.
 */

$host = 'localhost';
$banco = 'portal_louvor';
$usuario = 'root';
$senha = ''; // No XAMPP, geralmente fica vazio por padrão; confirme no seu ambiente.

$dsn = "mysql:host={$host};dbname={$banco};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $usuario, $senha, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // Em produção, registre o erro no log; não mostre detalhes técnicos ao visitante.
    error_log('Falha na conexão com o banco de dados: ' . $e->getMessage());
    http_response_code(500);
    exit('Não foi possível conectar ao banco de dados.');
}
