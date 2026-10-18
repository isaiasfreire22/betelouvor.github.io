<?php
// Portal do Grupo de Louvor integrado ao MySQL.
session_start();
require_once __DIR__ . '/conexao.php';

$message = '';

// Login consulta a tabela usuarios e valida o hash da senha.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $login = strtolower(trim($_POST['user_login'] ?? ''));
        $pass = $_POST['user_password'] ?? '';
        $stmt = $pdo->prepare('SELECT id, nome, usuario, email, senha_hash, perfil, ativo FROM usuarios WHERE (LOWER(usuario) = :login_usuario OR LOWER(email) = :login_email) AND ativo = 1 LIMIT 1');
        $stmt->execute(['login_usuario' => $login, 'login_email' => $login]);
        $user = $stmt->fetch();
        if ($user && password_verify($pass, $user['senha_hash'])) {
            session_regenerate_id(true);
            $_SESSION['logged_user'] = [
                'id' => (int)$user['id'],
                'name' => $user['nome'],
                'username' => $user['usuario'],
                'email' => $user['email'],
                'isAdmin' => $user['perfil'] === 'admin'
            ];
            header('Location: index.php');
            exit;
        }
        $message = 'Usuário/e-mail ou senha incorretos.';
    } elseif ($action === 'logout') {
        unset($_SESSION['logged_user']);
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }
}
$currentUser = $_SESSION['logged_user'] ?? null;

// Os louvores e cultos agora vêm do banco de dados, não de arrays de sessão.
$songsData = [];
$escalasData = [];
$usersData = [];
if ($currentUser) {
    $stmtUsers = $pdo->query("SELECT nome AS name, perfil AS perfil FROM usuarios WHERE ativo = 1 ORDER BY nome");
    $usersData = $stmtUsers->fetchAll();
    foreach ($usersData as &$userRow) {
        $userRow['isAdmin'] = $userRow['perfil'] === 'admin';
        unset($userRow['perfil']);
    }
    unset($userRow);
    $sqlSongs = "SELECT l.id, DATE_FORMAT(l.criado_em, '%d/%m/%Y') AS date, COALESCE(u.nome, 'Sistema') AS user, l.titulo AS title, l.artista AS artist, l.tema AS theme, l.ritmo AS rhythm, 0 AS repetitions, COALESCE(l.link_youtube, '#') AS url FROM louvores l LEFT JOIN usuarios u ON u.id = l.cadastrado_por WHERE l.ativo = 1 ORDER BY l.criado_em DESC";
    $songsData = $pdo->query($sqlSongs)->fetchAll();
    foreach ($songsData as &$song) {
        $song['id'] = (int)$song['id'];
        $song['repetitions'] = (int)$song['repetitions'];
    }
    unset($song);

    $cultos = $pdo->query("SELECT c.id, c.data_culto, DATE_FORMAT(c.data_culto, '%d/%m/%Y') AS data_formatada, DATE_FORMAT(c.data_culto, '%M / %Y') AS mes_ano, c.tipo, c.status, c.tema FROM cultos c WHERE c.status <> 'Cancelada' ORDER BY c.data_culto")->fetchAll();
    $nomesMeses = [1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'];
    $diasSemana = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
    foreach ($cultos as $culto) {
        $cultoId = (int)$culto['id'];
        $dt = new DateTime($culto['data_culto']);
        $stmt = $pdo->prepare('SELECT u.nome FROM culto_integrantes ci JOIN usuarios u ON u.id = ci.usuario_id WHERE ci.culto_id = ? ORDER BY u.nome');
        $stmt->execute([$cultoId]);
        $membros = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare('SELECT louvor_id FROM culto_louvores WHERE culto_id = ? ORDER BY ordem');
        $stmt->execute([$cultoId]);
        $louvoresIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $escalasData[] = [
            'id' => $cultoId,
            'rawDate' => $culto['data_culto'],
            'data' => $dt->format('d/m/Y'),
            'mesAno' => ucfirst($nomesMeses[(int)$dt->format('n')]) . ' / ' . $dt->format('Y'),
            'dia' => $diasSemana[(int)$dt->format('w')],
            'membros' => $membros,
            'status' => $culto['status'],
            'isCeia' => strtolower((string)$culto['tema']) === 'ceia',
            'louvoresIds' => $louvoresIds
        ];
    }
}
?>
<!-- A partir daqui começa a estrutura visual HTML da página. -->
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal do Grupo de Louvor - Sci-Fi Edition (PHP)</title>
  
  <!-- Bibliotecas externas usadas para montar e exportar o relatório em PDF. -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

  <style>
    /* Variáveis globais de cores: facilitam mudar a aparência do sistema em um só lugar. */
    :root {
      --neon-blue: #00f0ff;
      --neon-cyan: #00ffd2;
      --neon-purple: #9d4edd;
      --neon-red: #ff0055;
      --dark-bg: #030712;
      --card-bg: rgba(10, 15, 30, 0.7);
      --glass-border: rgba(0, 240, 255, 0.25);
      --text: #e2e8f0;
      --text-dim: #94a3b8;
    }

    /* Estilo básico aplicado a todos os elementos para padronizar medidas e espaçamentos. */
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
    
    /* Fundo geral da página, cor do texto, espaçamento e efeitos de luz decorativos. */
    body { 
      background-color: var(--dark-bg); 
      color: var(--text); 
      padding: 20px; 
      min-height: 100vh;
      background-image: 
        radial-gradient(circle at 10% 20%, rgba(0, 240, 255, 0.05) 0%, transparent 40%),
        radial-gradient(circle at 90% 80%, rgba(157, 78, 221, 0.05) 0%, transparent 40%);
    }

    /* Animação suave de brilho usada nos cartões do sistema. */
    @keyframes glow {
      0%, 100% { box-shadow: 0 0 10px rgba(0, 240, 255, 0.2), inset 0 0 5px rgba(0, 240, 255, 0.1); }
      50% { box-shadow: 0 0 20px rgba(0, 240, 255, 0.4), inset 0 0 10px rgba(0, 240, 255, 0.2); }
    }

    /* Mantém o conteúdo centralizado e limita a largura máxima da tela. */
    .container { max-width: 1000px; margin: 0 auto; }
    
    /* Aparência compartilhada pelos painéis: fundo translúcido, borda e brilho. */
    .card {
      background: var(--card-bg);
      border-radius: 16px;
      padding: 30px;
      margin-bottom: 24px;
      border: 1px solid var(--glass-border);
      backdrop-filter: blur(12px);
      animation: glow 6s infinite ease-in-out;
      box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
      position: relative;
      overflow: hidden;
    }

    .card::before, .card::after {
      content: '';
      position: absolute;
      width: 10px; height: 10px;
      border: 2px solid var(--neon-blue);
    }
    .card::before { top: 0; left: 0; border-right: 0; border-bottom: 0; border-top-left-radius: 8px; }
    .card::after { bottom: 0; right: 0; border-left: 0; border-top: 0; border-bottom-right-radius: 8px; }

    /* Padroniza títulos e aplica o efeito visual neon. */
    h1, h2, h3 { 
      color: #fff; text-transform: uppercase; letter-spacing: 1.5px;
      margin-bottom: 20px; text-shadow: 0 0 10px rgba(0, 240, 255, 0.5);
    }

    /* Espaçamento entre campos de formulários. */
    .form-group { margin-bottom: 20px; }
    
    label { 
      display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.85rem; 
      color: var(--neon-blue); text-transform: uppercase; letter-spacing: 1px;
    }

    /* Aparência dos campos de texto e listas de seleção. */
    input, select {
      width: 100%; padding: 12px 16px; background: rgba(15, 23, 42, 0.6);
      border: 1px solid var(--glass-border); border-radius: 8px; font-size: 0.95rem;
      color: #fff; outline: none; transition: all 0.3s;
    }

    input:focus, select:focus { 
      border-color: var(--neon-cyan); box-shadow: 0 0 15px rgba(0, 255, 210, 0.4);
      background: rgba(15, 23, 42, 0.8);
    }

    option { background: var(--dark-bg); color: #fff; }

    /* Estilo padrão dos botões principais. */
    .btn {
      background: linear-gradient(135deg, var(--neon-blue), #00ffd2); color: #030712;
      border: none; padding: 14px 24px; border-radius: 8px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 1.5px; cursor: pointer; width: 100%;
      font-size: 1rem; transition: all 0.3s; box-shadow: 0 0 15px rgba(0, 240, 255, 0.4);
    }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 0 25px rgba(0, 255, 210, 0.7); }

    .btn-secondary {
      background: rgba(15, 23, 42, 0.8); color: var(--neon-blue);
      border: 1px solid var(--neon-blue); box-shadow: 0 0 10px rgba(0, 240, 255, 0.2);
    }

    .btn-repertorio {
      background: rgba(157, 78, 221, 0.15); color: var(--neon-purple);
      border: 1px solid var(--neon-purple); padding: 8px; border-radius: 8px;
      font-weight: 700; font-size: 0.8rem; cursor: pointer; width: 100%; margin-top: 8px;
      transition: all 0.3s; text-transform: uppercase;
    }
    .btn-repertorio:hover { background: rgba(157, 78, 221, 0.3); color: #fff; }

    .btn-pdf {
      background: linear-gradient(135deg, var(--neon-purple), #7b2cbf); color: #fff;
      border: 1px solid var(--neon-purple); box-shadow: 0 0 12px rgba(157, 78, 221, 0.4);
    }

    .btn-logout {
      background: linear-gradient(135deg, var(--neon-red), #990022); color: #fff;
      font-size: 0.8rem; padding: 8px 16px; width: auto; border: none; border-radius: 6px; cursor: pointer;
    }

    .btn-success {
      background: linear-gradient(135deg, #00ff88, #00cc66); color: #030712;
      padding: 8px 16px; font-size: 0.8rem; width: 100%; margin-top: 10px; border: none; border-radius: 6px; cursor: pointer;
    }

    /* Classe utilitária: esconde abas, modais e elementos até serem necessários. */
    .hidden { display: none !important; }

    /* Estilos das abas principais e dos estados ativo/inativo. */
    .nav-tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
    .nav-btn {
        background: rgba(0, 240, 255, 0.05); color: var(--text-dim);
        border: 1px solid var(--glass-border); padding: 10px 20px; border-radius: 8px;
        cursor: pointer; text-transform: uppercase; font-weight: 700; font-size: 0.85rem;
    }
    .nav-btn.active {
        background: rgba(0, 240, 255, 0.2); color: var(--neon-cyan);
        border-color: var(--neon-cyan); box-shadow: 0 0 15px rgba(0, 255, 210, 0.3);
    }

    .subnav-tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid rgba(0, 240, 255, 0.15); padding-bottom: 12px; }
    .subnav-btn {
        background: rgba(15, 23, 42, 0.6); color: var(--text-dim); border: 1px solid rgba(0, 240, 255, 0.2);
        padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.8rem;
    }
    .subnav-btn.active {
        background: rgba(157, 78, 221, 0.25); color: var(--neon-purple); border-color: var(--neon-purple);
    }

    /* Tabelas com rolagem horizontal em telas menores. */
    .table-responsive { overflow-x: auto; margin-top: 15px; border: 1px solid rgba(0, 240, 255, 0.15); border-radius: 8px; }
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem; }
    th, td { padding: 14px 12px; border-bottom: 1px solid rgba(0, 240, 255, 0.1); }
    th { background: rgba(0, 240, 255, 0.05); font-weight: 700; color: var(--neon-blue); text-transform: uppercase; }

    /* Etiquetas pequenas para identificar perfil, ritmo e situação do culto. */
    .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: rgba(255, 255, 255, 0.1); }
    .badge-admin { background: rgba(157, 78, 221, 0.2); color: var(--neon-purple); border: 1px solid var(--neon-purple); }
    .badge-fast { background: rgba(0, 255, 210, 0.15); color: #00ffd2; border: 1px solid #00ffd2; }
    .badge-slow { background: rgba(0, 240, 255, 0.15); color: var(--neon-blue); border: 1px solid var(--neon-blue); }
    .badge-ceia { background: rgba(157, 78, 221, 0.25); color: var(--neon-purple); border: 1px solid var(--neon-purple); font-size: 0.7rem; }
    .badge-pendente { background: rgba(255, 159, 10, 0.15); color: #ff9f0a; border: 1px solid #ff9f0a; }
    .badge-confirmada { background: rgba(52, 199, 89, 0.15); color: #34c759; border: 1px solid #34c759; }

    /* Barra superior com nome do usuário e botão para sair. */
    .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .song-link { color: var(--neon-cyan); text-decoration: none; font-weight: 600; }

    /* Organização visual das escalas por mês e em cartões de culto. */
    .mes-secao { margin-bottom: 35px; }
    .mes-titulo { font-size: 1.1rem; color: var(--neon-cyan); border-bottom: 1px solid var(--glass-border); padding-bottom: 8px; margin-bottom: 16px; }
    .cultos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 18px; }
    .culto-card { background: rgba(15, 23, 42, 0.6); border: 1px solid var(--glass-border); border-radius: 14px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between; }
    .culto-card.selecionado { border-color: var(--neon-cyan); background: rgba(0, 255, 210, 0.08); }
    .culto-card-header { display: flex; justify-content: space-between; margin-bottom: 12px; }
    .culto-data { font-size: 1.1rem; font-weight: 800; color: #fff; }
    .culto-dia { font-size: 0.85rem; color: var(--neon-blue); text-transform: uppercase; font-weight: 700; }

    .btn-presenca {
        display: flex; align-items: center; justify-content: center; width: 100%; padding: 10px;
        margin-top: 14px; border-radius: 8px; border: 1px solid var(--glass-border);
        background: rgba(255, 255, 255, 0.05); color: var(--text-dim); font-weight: 700; font-size: 0.85rem; cursor: pointer;
    }
    .btn-presenca.ativo { background: linear-gradient(135deg, rgba(0, 255, 210, 0.3), rgba(0, 240, 255, 0.3)); border-color: var(--neon-cyan); color: #fff; }

    .progress-bar-bg { width: 100%; height: 8px; background: rgba(255, 255, 255, 0.1); border-radius: 4px; overflow: hidden; margin-top: 6px; }
    .progress-bar-fill { height: 100%; border-radius: 4px; }

    /* Cartões com indicadores resumidos do relatório de frequência. */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
    .kpi-card { background: rgba(15, 23, 42, 0.6); border: 1px solid var(--glass-border); border-radius: 12px; padding: 16px; text-align: center; }
    .kpi-val { font-size: 1.8rem; font-weight: 800; color: var(--neon-cyan); margin-top: 4px; }
    .kpi-lbl { font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase; }

    /* Camada sobreposta que escurece o fundo quando o modal de repertório abre. */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(3, 7, 18, 0.85); backdrop-filter: blur(8px); display: flex; justify-content: center; align-items: center; z-index: 1000; padding: 20px; }
    .modal-content { background: var(--card-bg); border: 1px solid var(--neon-purple); border-radius: 16px; width: 100%; max-width: 600px; max-height: 85vh; overflow-y: auto; padding: 25px; }
    .song-select-item { display: flex; align-items: center; justify-content: space-between; padding: 12px; border: 1px solid rgba(0, 240, 255, 0.15); border-radius: 8px; margin-bottom: 10px; background: rgba(15, 23, 42, 0.6); }
  </style>
</head>
<body>

  <!-- Contêiner principal que centraliza todo o conteúdo visível. -->
  <div class="container">
    
    <!-- Se não houver usuário autenticado, mostra a tela de login. -->
    <?php if (!$currentUser): ?>
    <!-- TELA DE LOGIN PHP -->
    <div id="login-screen" class="card" style="max-width: 420px; margin: 80px auto;">
      <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="font-size: 1.8rem; text-shadow: 0 0 15px rgba(0,240,255,0.6);">⚡ LOUVOR PORTAL</h1>
        <p style="color: var(--text-dim); font-size: 0.9rem;">IDENTIFICAÇÃO DE INTEGRANTE (PHP)</p>
      </div>

      <?php if (!empty($message)): ?>
        <p style="color: var(--neon-red); text-align: center; margin-bottom: 15px; font-size: 0.85rem;"><?= htmlspecialchars($message) ?></p>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="action" value="login">
        <div class="form-group">
          <label>Usuário:</label>
          <input type="text" name="user_login" placeholder="Seu usuário..." required>
        </div>
        <div class="form-group">
          <label>Chave de Acesso:</label>
          <input type="password" name="user_password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn">Autenticar</button>
      </form>
    </div>

    <?php else: ?>
    <!-- Se houver login, mostra o painel principal com as funcionalidades do sistema. -->
    <!-- PAINEL PRINCIPAL PHP/JS -->
    <div id="app-screen">
      
      <div class="top-bar card" style="padding: 16px 24px; margin-bottom: 15px;">
        <div>
          <span style="color: var(--text-dim);">SISTEMA INICIADO:</span> <strong style="color:#fff;"><?= htmlspecialchars($currentUser['name']) ?></strong>
          <?php if ($currentUser['isAdmin']): ?>
            <span class="badge badge-admin" style="margin-left: 10px;">LÍDER ACESSO MASTER</span>
            <a href="usuarios.php" style="margin-left: 12px; color: var(--neon-cyan); font-size: 0.85rem;">Gerenciar usuários</a>
          <?php endif; ?>
        </div>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn-logout">Desconectar</button>
        </form>
      </div>

      <!-- Botões que alternam entre sugestão de louvor, escalas e relatório. -->
      <div class="nav-tabs">
          <button class="nav-btn active" onclick="showTab('sugestao')">Sugerir Louvor</button>
          <button class="nav-btn" onclick="showTab('escalas')">Escalas Oficiais</button>
          <?php if ($currentUser['isAdmin']): ?>
            <button class="nav-btn" id="nav-relatorio" onclick="showTab('relatorio')">Relatório Master (Líderes)</button>
          <?php endif; ?>
      </div>

      <!-- Aba para cadastrar uma sugestão de música. -->
      <div id="tab-sugestao" class="card tab-content">
        <h2>➕ Cadastrar Sugestão de Louvor</h2>
        <form id="song-form">
          <div class="form-group">
            <label for="song-name">Nome da Música *</label>
            <input type="text" id="song-name" placeholder="Ex: Bondade de Deus" required>
          </div>

          <div class="form-group">
            <label for="artist">Ministério / Cantor / Banda *</label>
            <input type="text" id="artist" placeholder="Ex: Isaías Saad" required>
          </div>

          <div class="form-group">
            <label for="youtube-link">Link do YouTube</label>
            <input type="url" id="youtube-link" placeholder="https://www.youtube.com/watch?v=...">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
              <label for="theme">Tema da Música *</label>
              <select id="theme" required>
                <option value="">Selecione o tema...</option>
                <option value="Gratidão">Gratidão</option>
                <option value="Ceia">Ceia (Sacrifício/Ressurreição)</option>
                <option value="Fé">Fé</option>
                <option value="Salvação">Salvação</option>
                <option value="Espírito Santo">Espírito Santo</option>
                <option value="Adoração">Adoração Geral</option>
                <option value="Outros">Outros</option>
              </select>
            </div>

            <div class="form-group">
              <label for="rhythm">Dinâmica / Ritmo *</label>
              <select id="rhythm" required>
                <option value="">Selecione o ritmo...</option>
                <option value="Lenta (Adoração)">Lenta (Adoração)</option>
                <option value="Rápida (Celebração)">Rápida (Celebração)</option>
                <option value="Moderada">Moderada</option>
              </select>
            </div>
          </div>

          <button type="submit" class="btn">Enviar para Base de Dados</button>
        </form>
      </div>

      <!-- Aba com os cultos organizados por mês e controle de presença. -->
      <div id="tab-escalas" class="card tab-content hidden">
          <h2>🗓️ Painel de Escalas Mês a Mês</h2>
          <p style="color: var(--text-dim); margin-bottom: 24px; font-size: 0.85rem;">
            Selecione nos quadrados abaixo os cultos em que você estará presente para registrar sua disponibilidade e veja os louvores programados.
          </p>

          <?php if ($currentUser['isAdmin']): ?>
          <!-- Formulário Admin de Escala -->
          <div id="admin-escala-form" style="margin-bottom: 30px; border-bottom: 1px solid var(--glass-border); padding-bottom: 20px;">
              <h3 style="color: var(--neon-cyan); font-size: 1rem;">⚙️ Gestão de Cultos do Mês (Painel Líder)</h3>
              
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                  <div class="form-group" style="margin-bottom:0;">
                      <label>Data Específica</label>
                      <input type="date" id="data-culto">
                  </div>
                  <div class="form-group" style="margin-bottom:0;">
                      <label>Tipo de Culto / Evento</label>
                      <select id="tipo-culto">
                          <option value="Terça-feira">Culto de Terça-feira</option>
                          <option value="Quinta-feira">Culto de Quinta-feira</option>
                          <option value="Sábado">Evento de Sábado</option>
                          <option value="Domingo">Culto de Domingo</option>
                      </select>
                  </div>
              </div>
              <button class="btn btn-secondary" onclick="criarCulto()">+ Adicionar Culto Individual</button>
          </div>
          <?php endif; ?>

          <div id="escalas-container">
              <!-- Renderizado via JS em Quadrados por Mês -->
          </div>
      </div>

      <!-- Aba gerencial exibida apenas para usuários com perfil de líder. -->
      <?php if ($currentUser['isAdmin']): ?>
      <div id="tab-relatorio" class="card tab-content hidden">
        <h2 style="color: var(--neon-cyan); text-shadow: 0 0 10px rgba(0, 255, 210, 0.3);">📊 Painel Gerencial da Liderança</h2>
        
        <div class="subnav-tabs">
            <button class="subnav-btn active" id="subbtn-repertorio" onclick="showRelatorioSubtab('repertorio')">🎵 Repertório Master</button>
            <button class="subnav-btn" id="subbtn-frequencia" onclick="showRelatorioSubtab('frequencia')">📈 Frequência dos Membros</button>
        </div>

        <!-- SUB-ABA 1: REPERTÓRIO MASTER -->
        <div id="subtab-repertorio">
          <p style="color: var(--text-dim); margin-bottom: 15px; font-size: 0.85rem;">
            Músicas enviadas por todos os membros. Filtre abaixo por assunto, ritmo, autor ou música.
          </p>

          <div style="background: rgba(0, 240, 255, 0.03); border: 1px solid var(--glass-border); padding: 18px; border-radius: 12px; margin-bottom: 20px;">
            <h3 style="font-size: 0.9rem; color: var(--neon-blue); margin-bottom: 14px;">🔍 Filtros do Relatório</h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px;">
              <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 0.75rem;">Música / Cantor</label>
                <input type="text" id="filter-search" placeholder="Buscar por nome..." oninput="filtrarRelatorio()" style="padding: 8px 12px; font-size: 0.85rem;">
              </div>

              <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 0.75rem;">Tema</label>
                <select id="filter-theme" onchange="filtrarRelatorio()" style="padding: 8px 12px; font-size: 0.85rem;">
                  <option value="">Todos os Temas</option>
                  <option value="Gratidão">Gratidão</option>
                  <option value="Ceia">Ceia</option>
                  <option value="Fé">Fé</option>
                  <option value="Salvação">Salvação</option>
                  <option value="Espírito Santo">Espírito Santo</option>
                  <option value="Adoração">Adoração Geral</option>
                  <option value="Outros">Outros</option>
                </select>
              </div>

              <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 0.75rem;">Ritmo</label>
                <select id="filter-rhythm" onchange="filtrarRelatorio()" style="padding: 8px 12px; font-size: 0.85rem;">
                  <option value="">Todos os Ritmos</option>
                  <option value="Lenta">Lenta (Adoração)</option>
                  <option value="Rápida">Rápida (Celebração)</option>
                  <option value="Moderada">Moderada</option>
                </select>
              </div>

              <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 0.75rem;">Enviado Por</label>
                <input type="text" id="filter-user" placeholder="Nome do integrante..." oninput="filtrarRelatorio()" style="padding: 8px 12px; font-size: 0.85rem;">
              </div>

              <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 0.75rem;">Data</label>
                <input type="text" id="filter-date" placeholder="DD/MM/AAAA" oninput="filtrarRelatorio()" style="padding: 8px 12px; font-size: 0.85rem;">
              </div>
            </div>

            <div style="text-align: right; margin-top: 14px;">
              <button class="btn btn-secondary" onclick="limparFiltros()" style="width: auto; padding: 6px 16px; font-size: 0.8rem;">Limpar Filtros</button>
            </div>
          </div>

          <div class="table-responsive">
            <table>
              <thead>
                <tr>
                  <th>Carimbo / Autor</th>
                  <th>Música</th>
                  <th>Ministério</th>
                  <th>Tema</th>
                  <th>Ritmo</th>
                  <th style="text-align: center;">Repetições</th>
                  <th>Mídia</th>
                </tr>
              </thead>
              <tbody id="report-table-body">
              </tbody>
            </table>
          </div>
        </div>

        <!-- SUB-ABA 2: FREQUÊNCIA DOS MEMBROS -->
        <div id="subtab-frequencia" class="hidden">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                <p style="color: var(--text-dim); font-size: 0.85rem;">
                  Acompanhe a assiduidade e presenças registradas de cada integrante do ministério.
                </p>
                
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <select id="freq-month-filter" onchange="renderFrequenciaTable()" style="padding: 8px 12px; font-size: 0.85rem; width: auto;">
                        <option value="TODOS">📅 Histórico Acumulado (Todos os Meses)</option>
                        <option value="Outubro / 2026">Outubro / 2026</option>
                        <option value="Novembro / 2026">Novembro / 2026</option>
                    </select>

                    <button class="btn btn-pdf" onclick="gerarPDFFrequencia()" style="width: auto; padding: 9px 18px; font-size: 0.85rem;">
                        📄 Exportar Relatório em PDF
                    </button>
                </div>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-lbl">Total de Cultos</div>
                    <div class="kpi-val" id="kpi-total-cultos">0</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-lbl">Cultos Confirmados</div>
                    <div class="kpi-val" id="kpi-cultos-confirmados" style="color: #34c759;">0</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-lbl">Média de Integrantes / Culto</div>
                    <div class="kpi-val" id="kpi-media-integrantes" style="color: var(--neon-blue);">0</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-lbl">Mais Frequente</div>
                    <div class="kpi-val" id="kpi-mais-frequente" style="font-size: 1.1rem; padding-top: 8px; color: var(--neon-purple);">-</div>
                </div>
            </div>

            <div class="table-responsive">
              <table>
                <thead>
                  <tr>
                    <th>Integrante</th>
                    <th>Perfil</th>
                    <th style="text-align: center;">Cultos Marcados</th>
                    <th style="text-align: center;">Presenças Confirmadas</th>
                    <th style="text-align: center; min-width: 160px;">Taxa de Frequência</th>
                    <th style="text-align: center;">Status</th>
                  </tr>
                </thead>
                <tbody id="freq-table-body">
                </tbody>
              </table>
            </div>
        </div>

      </div>
      <?php endif; ?>

    </div>
    <?php endif; ?>

  </div>

  <!-- Janela modal para visualizar ou escolher as músicas de um culto. -->
  <div id="modal-repertorio" class="modal-overlay hidden">
    <div class="modal-content">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--glass-border); padding-bottom: 10px;">
        <h3 style="color: var(--neon-purple); margin: 0; font-size: 1.1rem;" id="modal-title">🎵 PROGRAMAÇÃO DO CULTO</h3>
        <button onclick="fecharModalRepertorio()" style="background: none; border: none; color: var(--neon-red); font-size: 1.5rem; cursor: pointer;">✕</button>
      </div>

      <p style="font-size: 0.85rem; color: var(--text-dim); margin-bottom: 15px;" id="modal-desc">
        Marque os louvores que farão parte da ministração deste dia:
      </p>

      <div id="modal-songs-list" style="margin-bottom: 20px;">
      </div>

      <div style="display: flex; gap: 10px;" id="modal-actions">
        <button class="btn btn-secondary" onclick="fecharModalRepertorio()">Fechar</button>
        <button class="btn btn-pdf" id="btn-salvar-repertorio" onclick="salvarProgramacaoCulto()">💾 Salvar Programação</button>
      </div>
    </div>
  </div>

  <script>
    // Dados carregados pelo PHP a partir do MySQL. Alterações são enviadas ao api.php.
    let songsData = <?= json_encode($songsData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    let escalasData = <?= json_encode($escalasData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const usersData = <?= json_encode($usersData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const currentUser = <?= json_encode($currentUser, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    async function enviarApi(action, dados = {}) {
        const resposta = await fetch('api.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action, ...dados}) });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.ok) throw new Error(resultado.message || 'Não foi possível salvar no banco de dados.');
        return resultado;
    }

    let dadosFrequenciaCalculados = [];
    let cultoAtualModalId = null;

    // Inicializa as tabelas e cartões quando existe um usuário conectado.
    if (currentUser) {
        showTab('sugestao');
        renderReportTable();
        renderEscalas();
        renderFrequenciaTable();
    }

    // Alterna a aba principal visível e atualiza o destaque do botão selecionado.
    function showTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.add('hidden'));
        const targetTab = document.getElementById(`tab-${tabName}`);
        if(targetTab) targetTab.classList.remove('hidden');

        document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
        if (event && event.currentTarget) event.currentTarget.classList.add('active');
    }

    // Alterna entre as duas áreas do relatório: repertório e frequência.
    function showRelatorioSubtab(subName) {
        document.getElementById('subtab-repertorio').classList.add('hidden');
        document.getElementById('subtab-frequencia').classList.add('hidden');
        
        document.getElementById('subbtn-repertorio').classList.remove('active');
        document.getElementById('subbtn-frequencia').classList.remove('active');

        document.getElementById(`subtab-${subName}`).classList.remove('hidden');
        document.getElementById(`subbtn-${subName}`).classList.add('active');

        if (subName === 'frequencia') {
            renderFrequenciaTable();
        }
    }

    // CADASTRO DE MÚSICA: impede o recarregamento e adiciona a música à lista em memória.
    const songForm = document.getElementById('song-form');
    if (songForm) {
        songForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const resultado = await enviarApi('criar_louvor', {
                    title: document.getElementById('song-name').value.trim(),
                    artist: document.getElementById('artist').value.trim(),
                    url: document.getElementById('youtube-link').value.trim(),
                    theme: document.getElementById('theme').value,
                    rhythm: document.getElementById('rhythm').value
                });
                songsData.push(resultado.song);
                alert('⚡ Louvor salvo no banco de dados!');
                songForm.reset();
                filtrarRelatorio();
            } catch (erro) { alert(erro.message); }
        });
    }

    // Atualiza a quantidade de vezes que uma música foi repetida no repertório.
    function atualizarRepeticoes(songId, novoValor) {
        const song = songsData.find(s => s.id === songId);
        if (song) song.repetitions = parseInt(novoValor) || 0;
    }

    // Aplica os filtros preenchidos pelo líder e mantém somente músicas correspondentes.
    function filtrarRelatorio() {
        const searchVal = document.getElementById('filter-search').value.toLowerCase().trim();
        const themeVal = document.getElementById('filter-theme').value.toLowerCase().trim();
        const rhythmVal = document.getElementById('filter-rhythm').value.toLowerCase().trim();
        const userVal = document.getElementById('filter-user').value.toLowerCase().trim();
        const dateVal = document.getElementById('filter-date').value.trim();

        // filter() cria uma nova lista contendo apenas músicas que passam por todos os filtros.
        const resultado = songsData.filter(song => {
            const matchSearch = !searchVal || song.title.toLowerCase().includes(searchVal) || song.artist.toLowerCase().includes(searchVal);
            const matchTheme = !themeVal || song.theme.toLowerCase().includes(themeVal);
            const matchRhythm = !rhythmVal || song.rhythm.toLowerCase().includes(rhythmVal);
            const matchUser = !userVal || song.user.toLowerCase().includes(userVal);
            const matchDate = !dateVal || song.date.includes(dateVal);
            return matchSearch && matchTheme && matchRhythm && matchUser && matchDate;
        });

        renderReportTable(resultado);
    }

    // Apaga os campos de filtro e mostra novamente todas as músicas cadastradas.
    function limparFiltros() {
        document.getElementById('filter-search').value = '';
        document.getElementById('filter-theme').value = '';
        document.getElementById('filter-rhythm').value = '';
        document.getElementById('filter-user').value = '';
        document.getElementById('filter-date').value = '';
        renderReportTable(songsData);
    }

    // Desenha as linhas da tabela de repertório usando a lista recebida.
    // Se nenhum argumento for informado, usa todas as músicas.
    function renderReportTable(lista = songsData) {
      const reportTableBody = document.getElementById('report-table-body');
      if(!reportTableBody) return;
      reportTableBody.innerHTML = '';

      if (lista.length === 0) {
        reportTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-dim); padding: 24px;">⚠️ Nenhum louvor encontrado.</td></tr>`;
        return;
      }

      lista.forEach(song => {
        const tr = document.createElement('tr');
        const isFast = song.rhythm.includes('Rápida');
        tr.innerHTML = `
          <td><small style="color:var(--text-dim)">${song.date}</small><br><strong>${song.user}</strong></td>
          <td style="color:#fff;">${song.title}</td>
          <td>${song.artist}</td>
          <td>${song.theme}</td>
          <td><span class="badge ${isFast ? 'badge-fast' : 'badge-slow'}">${song.rhythm}</span></td>
          <td style="text-align: center;">
            <input type="number" min="0" value="${song.repetitions}" onchange="atualizarRepeticoes(${song.id}, this.value)" style="width: 70px; padding: 6px; text-align: center; background: rgba(15, 23, 42, 0.8); border: 1px solid var(--glass-border); border-radius: 6px; color: #fff;">
          </td>
          <td>${song.url !== '#' ? `<a href="${song.url}" target="_blank" class="song-link">▶ Play</a>` : '-'}</td>
        `;
        reportTableBody.appendChild(tr);
      });
    }

    // Cria um novo culto com base na data e no tipo escolhidos pelo líder.
    async function criarCulto() {
        const dataStr = document.getElementById('data-culto').value;
        const dia = document.getElementById('tipo-culto').value;
        if(!dataStr) return alert("Por favor, preencha a data do culto.");

        const dataParts = dataStr.split('-');
        const dataFormatada = `${dataParts[2]}/${dataParts[1]}/${dataParts[0]}`;
        const dataObj = new Date(dataStr + 'T00:00:00');
        const mesExtenso = dataObj.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
        const mesAnoFormatado = mesExtenso.charAt(0).toUpperCase() + mesExtenso.slice(1);
        const isSegundoDomingo = (dataObj.getDay() === 0 && dataObj.getDate() >= 8 && dataObj.getDate() <= 14);

        try {
            const resultado = await enviarApi('criar_culto', {date: dataStr, type: dia, theme: isSegundoDomingo ? 'Ceia' : ''});
            escalasData.push(resultado.culto);
            document.getElementById('data-culto').value = '';
            alert('Novo culto salvo no banco de dados!');
            renderEscalas();
            renderFrequenciaTable();
        } catch (erro) { alert(erro.message); }
    }

    // Marca ou desmarca a presença do usuário atual em uma escala.
    async function togglePresenca(id) {
        const escala = escalasData.find(e => e.id === id);
        if (!escala) return;

        try {
            const resultado = await enviarApi('alternar_presenca', {culto_id: id});
            escala.membros = resultado.membros;
            renderEscalas();
            renderFrequenciaTable();
        } catch (erro) { alert(erro.message); }
    }

    // Permite à liderança confirmar uma escala, desde que exista ao menos um membro inscrito.
    async function aprovarEscala(id) {
        const escala = escalasData.find(e => e.id === id);
        if (escala) {
            if(escala.membros.length === 0) return alert("Não é possível aprovar uma escala vazia.");
            enviarApi('confirmar_culto', {culto_id: id}).then(() => {
                escala.status = 'Confirmada';
                renderEscalas();
                renderFrequenciaTable();
            }).catch(erro => alert(erro.message));
        }
    }

    // Abre o modal do culto. Membros apenas visualizam; líderes podem selecionar músicas.
    function abrirModalRepertorio(cultoId) {
        cultoAtualModalId = cultoId;
        const escala = escalasData.find(e => e.id === cultoId);
        if (!escala) return;

        document.getElementById('modal-title').textContent = `🎵 REPERTÓRIO: ${escala.data} (${escala.dia})`;
        const songsListDiv = document.getElementById('modal-songs-list');
        const btnSalvar = document.getElementById('btn-salvar-repertorio');
        songsListDiv.innerHTML = '';

        if (!currentUser.isAdmin) {
            btnSalvar.classList.add('hidden');
            document.getElementById('modal-desc').textContent = "Louvores selecionados pela liderança para este culto:";
            const louvoresProgramados = songsData.filter(s => escala.louvoresIds && escala.louvoresIds.includes(s.id));

            if (louvoresProgramados.length === 0) {
                songsListDiv.innerHTML = `<p style="color: var(--text-dim); text-align: center; padding: 15px;">Nenhum louvor definido ainda para este culto.</p>`;
            } else {
                louvoresProgramados.forEach(song => {
                    songsListDiv.innerHTML += `
                        <div class="song-select-item">
                            <div>
                                <strong style="color: #fff;">${song.title}</strong><br>
                                <small style="color: var(--neon-blue);">${song.artist}</small> | <small style="color: var(--text-dim);">${song.theme}</small>
                            </div>
                            <div>${song.url !== '#' ? `<a href="${song.url}" target="_blank" class="song-link">▶ Ver Mídia</a>` : ''}</div>
                        </div>
                    `;
                });
            }
        } else {
            btnSalvar.classList.remove('hidden');
            document.getElementById('modal-desc').textContent = "Marque abaixo as músicas que farão parte deste culto:";
            songsData.forEach(song => {
                const isChecked = escala.louvoresIds && escala.louvoresIds.includes(song.id) ? 'checked' : '';
                songsListDiv.innerHTML += `
                    <label class="song-select-item" style="cursor: pointer;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <input type="checkbox" class="song-checkbox" value="${song.id}" ${isChecked} style="width: 18px; height: 18px;">
                            <div>
                                <strong style="color: #fff;">${song.title}</strong><br>
                                <small style="color: var(--neon-blue);">${song.artist}</small> (${song.theme})
                            </div>
                        </div>
                        <span class="badge ${song.rhythm.includes('Rápida') ? 'badge-fast' : 'badge-slow'}">${song.rhythm}</span>
                    </label>
                `;
            });
        }

        document.getElementById('modal-repertorio').classList.remove('hidden');
    }

    // Fecha a janela de repertório e limpa o identificador do culto selecionado.
    function fecharModalRepertorio() {
        document.getElementById('modal-repertorio').classList.add('hidden');
        cultoAtualModalId = null;
    }

    // Lê as caixas marcadas no modal e salva os IDs das músicas na escala em memória.
    async function salvarProgramacaoCulto() {
        if (!cultoAtualModalId) return;
        const escala = escalasData.find(e => e.id === cultoAtualModalId);
        if (!escala) return;

        const checkboxes = document.querySelectorAll('.song-checkbox:checked');
        const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
        try {
            await enviarApi('salvar_repertorio', {culto_id: cultoAtualModalId, louvores_ids: ids});
            escala.louvoresIds = ids;
            alert('Programação salva no banco de dados!');
            fecharModalRepertorio();
            renderEscalas();
        } catch (erro) { alert(erro.message); }
    }

    // Recria visualmente os cartões dos cultos, agrupando-os por mês.
    function renderEscalas() {
        const escalasContainer = document.getElementById('escalas-container');
        if(!escalasContainer) return;
        escalasContainer.innerHTML = '';

        // Objeto usado para agrupar os cultos pela propriedade mês/ano.
        const gruposMes = {};
        escalasData.forEach(escala => {
            if (!gruposMes[escala.mesAno]) gruposMes[escala.mesAno] = [];
            gruposMes[escala.mesAno].push(escala);
        });

        Object.keys(gruposMes).forEach(mesAno => {
            gruposMes[mesAno].sort((a, b) => a.rawDate.localeCompare(b.rawDate));
            const mesDiv = document.createElement('div');
            mesDiv.className = 'mes-secao';

            let gridCardsHTML = '';
            gruposMes[mesAno].forEach(escala => {
                const isEuPresente = escala.membros.includes(currentUser.name);
                const isConfirmada = escala.status === 'Confirmada';
                const badgeStatus = `<span class="badge ${isConfirmada ? 'badge-confirmada' : 'badge-pendente'}">${escala.status}</span>`;
                const badgeCeia = escala.isCeia ? `<span class="badge badge-ceia">🍷 SANTA CEIA</span>` : '';
                const listaMembrosText = escala.membros.length > 0 ? escala.membros.join(', ') : 'Nenhum membro inscrito';

                const qtdLouvores = escala.louvoresIds ? escala.louvoresIds.length : 0;
                let louvoresResumoHTML = '';
                if (qtdLouvores > 0) {
                    const titulos = songsData.filter(s => escala.louvoresIds.includes(s.id)).map(s => s.title).join(', ');
                    louvoresResumoHTML = `<div style="font-size: 0.75rem; color: var(--neon-purple); margin-top: 8px;"><strong>🎵 REPERTÓRIO (${qtdLouvores}):</strong> <span style="color:var(--text-dim);">${titulos}</span></div>`;
                } else {
                    louvoresResumoHTML = `<div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 8px;"><em>Nenhum louvor selecionado</em></div>`;
                }

                let btnAprovacaoAdmin = '';
                if (currentUser.isAdmin && !isConfirmada) {
                    btnAprovacaoAdmin = `<button class="btn btn-success" onclick="aprovarEscala(${escala.id})">✅ Aprovar Escala</button>`;
                }

                gridCardsHTML += `
                    <div class="culto-card ${isEuPresente ? 'selecionado' : ''}">
                        <div>
                            <div class="culto-card-header">
                                <div>
                                    <div class="culto-data">${escala.data}</div>
                                    <div class="culto-dia">${escala.dia}</div>
                                </div>
                                <div style="text-align: right;">${badgeStatus}</div>
                            </div>
                            ${badgeCeia ? `<div style="margin-bottom: 10px;">${badgeCeia}</div>` : ''}
                            <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 6px;">
                                <strong style="color: var(--neon-cyan);">EQUIPE:</strong><br>
                                <span style="color: #fff;">${listaMembrosText}</span>
                            </div>
                            ${louvoresResumoHTML}
                        </div>
                        <div>
                            <button class="btn-presenca ${isEuPresente ? 'ativo' : ''}" onclick="togglePresenca(${escala.id})">
                                ${isEuPresente ? '☑ Estarei Neste Culto' : '☐ Marcar Presença'}
                            </button>
                            <button class="btn-repertorio" onclick="abrirModalRepertorio(${escala.id})">
                                🎵 ${currentUser.isAdmin ? 'Definir Repertório' : 'Ver Louvores'} (${qtdLouvores})
                            </button>
                            ${btnAprovacaoAdmin}
                        </div>
                    </div>
                `;
            });

            mesDiv.innerHTML = `<div class="mes-titulo">📅 CULTOS DE ${mesAno.toUpperCase()}</div><div class="cultos-grid">${gridCardsHTML}</div>`;
            escalasContainer.appendChild(mesDiv);
        });
    }

    // Calcula indicadores de frequência e monta a tabela de presença de cada integrante.
    function renderFrequenciaTable() {
        const freqTableBody = document.getElementById('freq-table-body');
        if (!freqTableBody) return;
        const monthFilter = document.getElementById('freq-month-filter').value;

        let cultosFiltrados = escalasData;
        if (monthFilter !== 'TODOS') cultosFiltrados = escalasData.filter(e => e.mesAno === monthFilter);

        const totalCultos = cultosFiltrados.length;
        const cultosConfirmados = cultosFiltrados.filter(e => e.status === 'Confirmada').length;

        document.getElementById('kpi-total-cultos').textContent = totalCultos;
        document.getElementById('kpi-cultos-confirmados').textContent = cultosConfirmados;

        let totalInscricoes = 0;
        cultosFiltrados.forEach(c => totalInscricoes += c.membros.length);
        const mediaInscritos = totalCultos > 0 ? (totalInscricoes / totalCultos).toFixed(1) : '0';
        document.getElementById('kpi-media-integrantes').textContent = mediaInscritos;

        freqTableBody.innerHTML = '';
        let maiorPresenca = -1;
        let nomeMaisFrequente = '-';

        // Calcula os dados de frequência de cada usuário cadastrado.
        dadosFrequenciaCalculados = usersData.map(userObj => {
            const nomeExibicao = userObj.name;
            const marcados = cultosFiltrados.filter(c => c.membros.includes(nomeExibicao)).length;
            const confirmados = cultosFiltrados.filter(c => c.status === 'Confirmada' && c.membros.includes(nomeExibicao)).length;
            const perc = totalCultos > 0 ? Math.round((marcados / totalCultos) * 100) : 0;

            if (marcados > maiorPresenca && marcados > 0) {
                maiorPresenca = marcados;
                nomeMaisFrequente = nomeExibicao;
            }

            return { nome: nomeExibicao, isAdmin: userObj.isAdmin, marcados, confirmados, totalCultos, porcentagem: perc };
        });

        document.getElementById('kpi-mais-frequente').textContent = nomeMaisFrequente;
        dadosFrequenciaCalculados.sort((a, b) => b.marcados - a.marcados);

        dadosFrequenciaCalculados.forEach(item => {
            const tr = document.createElement('tr');
            let badgeClass = 'badge-slow';
            let statusText = 'Atenção';
            let barColor = 'var(--neon-red)';

            if (item.porcentagem >= 75) { badgeClass = 'badge-fast'; statusText = 'Excelente'; barColor = 'var(--neon-cyan)'; }
            else if (item.porcentagem >= 40) { badgeClass = 'badge-pendente'; statusText = 'Regular'; barColor = '#ff9f0a'; }

            tr.innerHTML = `
                <td style="color:#fff; font-weight: 600;">${item.nome}</td>
                <td><span class="badge ${item.isAdmin ? 'badge-admin' : ''}">${item.isAdmin ? 'Líder' : 'Membro'}</span></td>
                <td style="text-align: center; font-weight: 700; color: var(--neon-blue);">${item.marcados} / ${totalCultos}</td>
                <td style="text-align: center; font-weight: 700; color: #34c759;">${item.confirmados}</td>
                <td style="text-align: center;">
                    <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:var(--text-dim);"><span>${item.porcentagem}%</span></div>
                    <div class="progress-bar-bg"><div class="progress-bar-fill" style="width: ${item.porcentagem}%; background: ${barColor};"></div></div>
                </td>
                <td style="text-align: center;"><span class="badge ${badgeClass}">${statusText}</span></td>
            `;
            freqTableBody.appendChild(tr);
        });
    }

    // Gera um PDF com os indicadores e a tabela de frequência usando a biblioteca jsPDF.
    function gerarPDFFrequencia() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();

        const monthFilter = document.getElementById('freq-month-filter').value;
        const periodoStr = monthFilter === 'TODOS' ? 'Histórico Acumulado (Geral)' : monthFilter;

        const totalCultos = document.getElementById('kpi-total-cultos').textContent;
        const cultosConfirmados = document.getElementById('kpi-cultos-confirmados').textContent;
        const mediaIntegrantes = document.getElementById('kpi-media-integrantes').textContent;
        const maisFrequente = document.getElementById('kpi-mais-frequente').textContent;

        doc.setFillColor(3, 7, 18);
        doc.rect(0, 0, 210, 40, 'F');

        doc.setFont("helvetica", "bold");
        doc.setFontSize(18);
        doc.setTextColor(0, 240, 255);
        doc.text("PORTAL DO MINISTÉRIO DE LOUVOR", 14, 20);

        doc.setFontSize(11);
        doc.setTextColor(226, 232, 240);
        doc.text("RELATÓRIO DE FREQUÊNCIA E ASSIDUIDADE DOS MEMBROS", 14, 28);

        doc.setFontSize(9);
        doc.setTextColor(148, 163, 184);
        doc.text(`Período do Relatório: ${periodoStr} | Emissão: ${new Date().toLocaleString('pt-BR')}`, 14, 35);

        doc.setFillColor(15, 23, 42);
        doc.roundedRect(14, 46, 182, 24, 3, 3, 'F');

        doc.setFontSize(9);
        doc.setFont("helvetica", "bold");
        doc.setTextColor(0, 240, 255);
        
        doc.text(`Cultos Programados: ${totalCultos}`, 20, 56);
        doc.text(`Cultos Confirmados: ${cultosConfirmados}`, 75, 56);
        doc.text(`Média Integrantes/Culto: ${mediaIntegrantes}`, 135, 56);
        
        doc.setTextColor(157, 78, 221);
        doc.text(`Destaque de Presença: ${maisFrequente}`, 20, 64);

        // Transforma os dados calculados em linhas que a biblioteca jsPDF colocará na tabela.
        const tableRows = dadosFrequenciaCalculados.map(item => {
            let status = 'Atenção';
            if (item.porcentagem >= 75) status = 'Excelente';
            else if (item.porcentagem >= 40) status = 'Regular';

            return [
                item.nome,
                item.isAdmin ? 'Líder' : 'Membro',
                `${item.marcados} / ${item.totalCultos}`,
                item.confirmados,
                `${item.porcentagem}%`,
                status
            ];
        });

        doc.autoTable({
            startY: 76,
            head: [['Integrante', 'Perfil', 'Cultos Marcados', 'Presenças Confirmadas', '% Frequência', 'Status']],
            body: tableRows,
            theme: 'grid',
            headStyles: { fillColor: [10, 15, 30], textColor: [0, 240, 255], fontStyle: 'bold', halign: 'center' },
            columnStyles: { 0: { halign: 'left', fontStyle: 'bold' }, 1: { halign: 'center' }, 2: { halign: 'center' }, 3: { halign: 'center' }, 4: { halign: 'center', fontStyle: 'bold' }, 5: { halign: 'center' } },
            alternateRowStyles: { fillColor: [240, 245, 250] },
            styles: { fontSize: 9, cellPadding: 4 }
        });

        const nomeArquivo = `Relatorio_Frequencia_Louvor_${periodoStr.replace(/[^a-zA-Z0-9]/g, '_')}.pdf`;
        doc.save(nomeArquivo);
    }
  </script>
</body>
</html>
