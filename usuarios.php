<?php
session_start();
require_once __DIR__ . '/conexao.php';
$user = $_SESSION['logged_user'] ?? null;
if (!$user || empty($user['isAdmin'])) { http_response_code(403); exit('Acesso restrito à administração.'); }
$mensagem = '';
$erro = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $usuario = strtolower(trim($_POST['usuario'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $senha = $_POST['senha'] ?? '';
    $perfil = ($_POST['perfil'] ?? 'integrante') === 'admin' ? 'admin' : 'integrante';
    if ($nome === '' || !preg_match('/^[a-z0-9._-]{3,60}$/', $usuario) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 10) {
        $mensagem = 'Confira os dados: usuário de 3 a 60 caracteres, e-mail válido e senha com no mínimo 10 caracteres.'; $erro = true;
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome, usuario, email, senha_hash, perfil) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$nome, $usuario, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
            $mensagem = 'Usuário cadastrado com sucesso.';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') $mensagem = 'Esse nome de usuário ou e-mail já está cadastrado.';
            else { error_log($e->getMessage()); $mensagem = 'Não foi possível cadastrar o usuário.'; }
            $erro = true;
        }
    }
}
$usuarios = $pdo->query('SELECT id, nome, usuario, email, perfil, ativo, criado_em FROM usuarios ORDER BY nome')->fetchAll();
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Gerenciar usuários</title>
<style>body{font-family:Segoe UI,Arial,sans-serif;background:#030712;color:#e2e8f0;max-width:1000px;margin:30px auto;padding:20px}a{color:#00f0ff}.painel{background:#10182a;border:1px solid #164e63;border-radius:12px;padding:22px;margin-bottom:20px}label{display:block;margin:12px 0 5px;color:#00f0ff}input,select{width:100%;padding:10px;box-sizing:border-box;background:#0f172a;color:white;border:1px solid #164e63;border-radius:6px}button{margin-top:18px;padding:12px 18px;background:#00bcd4;color:#001018;border:0;border-radius:6px;font-weight:bold;cursor:pointer}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid #243247}th{color:#00f0ff}.msg{padding:12px;background:#123329;border-radius:6px}.err{background:#4a1825}@media(max-width:600px){.grid{grid-template-columns:1fr}table{font-size:12px}}</style></head><body>
<p><a href="index.php">← Voltar ao Portal</a></p><h1>Gerenciar usuários</h1><p>Cadastre os integrantes e defina quem terá acesso administrativo. As senhas são armazenadas como hash, não em texto puro.</p>
<?php if ($mensagem): ?><p class="msg <?= $erro ? 'err' : '' ?>"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>
<div class="painel"><h2>Novo usuário</h2><form method="post"><div class="grid"><div><label>Nome completo</label><input name="nome" required></div><div><label>Nome de usuário (login)</label><input name="usuario" pattern="[a-zA-Z0-9._-]{3,60}" minlength="3" maxlength="60" required></div><div><label>E-mail</label><input name="email" type="email" required></div><div><label>Senha inicial (mínimo 10 caracteres)</label><input name="senha" type="password" minlength="10" required></div><div><label>Perfil</label><select name="perfil"><option value="integrante">Integrante</option><option value="admin">Administrador</option></select></div></div><button type="submit">Cadastrar usuário</button></form></div>
<div class="painel"><h2>Usuários cadastrados</h2><div style="overflow-x:auto"><table><thead><tr><th>Nome</th><th>Usuário</th><th>E-mail</th><th>Perfil</th><th>Status</th></tr></thead><tbody><?php foreach ($usuarios as $u): ?><tr><td><?= htmlspecialchars($u['nome']) ?></td><td><?= htmlspecialchars($u['usuario']) ?></td><td><?= htmlspecialchars($u['email']) ?></td><td><?= htmlspecialchars($u['perfil']) ?></td><td><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></td></tr><?php endforeach; ?></tbody></table></div></div></body></html>
# betelouvor.github.io
