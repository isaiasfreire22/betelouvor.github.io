<?php
// Página de configuração inicial: permite criar o primeiro administrador apenas.
require_once __DIR__ . '/conexao.php';
$erro = '';
$sucesso = false;
$total = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
if ($total > 0) {
    http_response_code(403);
    exit('Já existe usuário cadastrado. Por segurança, exclua o arquivo criar_admin.php do servidor.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $usuario = strtolower(trim($_POST['usuario'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $senha = $_POST['senha'] ?? '';
    if ($nome === '' || !preg_match('/^[a-z0-9._-]{3,60}$/', $usuario) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 10) {
        $erro = 'Preencha todos os campos. O usuário deve ter 3–60 caracteres e a senha pelo menos 10 caracteres.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO usuarios (nome, usuario, email, senha_hash, perfil) VALUES (?, ?, ?, ?, 'admin')");
        $stmt->execute([$nome, $usuario, $email, password_hash($senha, PASSWORD_DEFAULT)]);
        $sucesso = true;
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Criar administrador</title>
<style>body{font-family:Arial,sans-serif;background:#030712;color:#e2e8f0;max-width:520px;margin:50px auto;padding:20px}form{background:#10182a;padding:25px;border-radius:12px}label{display:block;margin-top:15px}input{width:100%;padding:10px;margin-top:6px;box-sizing:border-box}button{margin-top:20px;padding:12px;width:100%;cursor:pointer}.erro{color:#ff718d}</style></head><body>
<h1>Configuração inicial</h1>
<?php if ($sucesso): ?><p>Administrador criado com sucesso. Entre no <a href="index.php">Portal do Grupo de Louvor</a> e depois remova o arquivo <code>criar_admin.php</code> do servidor.</p>
<?php else: ?>
<p>Crie o primeiro acesso administrativo. Use uma senha forte com pelo menos 10 caracteres.</p>
<?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
<form method="post"><label>Nome completo<input name="nome" required></label><label>Nome de usuário<input name="usuario" minlength="3" maxlength="60" required></label><label>E-mail<input name="email" type="email" required></label><label>Senha<input name="senha" type="password" minlength="10" required></label><button type="submit">Criar administrador</button></form>
<?php endif; ?></body></html>
