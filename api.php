<?php
// API interna do Portal do Grupo de Louvor: grava as alterações no MySQL.
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conexao.php';

function responder(int $status, array $dados): never {
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = $_SESSION['logged_user'] ?? null;
if (!$user) responder(401, ['ok' => false, 'message' => 'Faça login para continuar.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'message' => 'Método não permitido.']);

$dados = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $dados['action'] ?? '';
$isAdmin = !empty($user['isAdmin']);

try {
    switch ($action) {
        case 'criar_louvor':
            $titulo = trim((string)($dados['title'] ?? ''));
            $artista = trim((string)($dados['artist'] ?? ''));
            $url = trim((string)($dados['url'] ?? ''));
            $tema = (string)($dados['theme'] ?? '');
            $ritmo = (string)($dados['rhythm'] ?? '');
            $temas = ['Gratidão', 'Ceia', 'Fé', 'Salvação', 'Espírito Santo', 'Adoração', 'Outros'];
            $ritmos = ['Lenta (Adoração)', 'Rápida (Celebração)', 'Moderada'];
            if ($titulo === '' || $artista === '' || !in_array($tema, $temas, true) || !in_array($ritmo, $ritmos, true)) {
                responder(422, ['ok' => false, 'message' => 'Preencha o título, artista, tema e ritmo corretamente.']);
            }
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) responder(422, ['ok' => false, 'message' => 'O link informado não é válido.']);
            $stmt = $pdo->prepare('INSERT INTO louvores (titulo, artista, link_youtube, tema, ritmo, cadastrado_por) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$titulo, $artista, $url !== '' ? $url : null, $tema, $ritmo, (int)$user['id']]);
            responder(200, ['ok' => true, 'song' => [
                'id' => (int)$pdo->lastInsertId(), 'date' => date('d/m/Y'), 'user' => $user['name'],
                'title' => $titulo, 'artist' => $artista, 'url' => $url !== '' ? $url : '#',
                'theme' => $tema, 'rhythm' => $ritmo, 'repetitions' => 0
            ]]);

        case 'criar_culto':
            if (!$isAdmin) responder(403, ['ok' => false, 'message' => 'Somente administradores podem criar cultos.']);
            $data = (string)($dados['date'] ?? '');
            $tipo = trim((string)($dados['type'] ?? 'Culto'));
            $tema = trim((string)($dados['theme'] ?? ''));
            $dt = DateTime::createFromFormat('!Y-m-d', $data);
            if (!$dt || $dt->format('Y-m-d') !== $data || $tipo === '') responder(422, ['ok' => false, 'message' => 'Informe uma data e tipo de culto válidos.']);
            $stmt = $pdo->prepare("INSERT INTO cultos (data_culto, tipo, tema, criado_por) VALUES (?, ?, ?, ?)");
            $stmt->execute([$data, $tipo, $tema !== '' ? $tema : null, (int)$user['id']]);
            $id = (int)$pdo->lastInsertId();
            $meses = [1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'];
            $dias = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
            responder(200, ['ok' => true, 'culto' => [
                'id'=>$id, 'rawDate'=>$data, 'data'=>$dt->format('d/m/Y'),
                'mesAno'=>ucfirst($meses[(int)$dt->format('n')]).' / '.$dt->format('Y'),
                'dia'=>$dias[(int)$dt->format('w')], 'membros'=>[], 'status'=>'Pendente',
                'isCeia'=>$tema === 'Ceia', 'louvoresIds'=>[]
            ]]);

        case 'alternar_presenca':
            $cultoId = filter_var($dados['culto_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$cultoId) responder(422, ['ok'=>false, 'message'=>'Culto inválido.']);
            $stmt = $pdo->prepare('SELECT id FROM cultos WHERE id = ?');
            $stmt->execute([$cultoId]);
            if (!$stmt->fetch()) responder(404, ['ok'=>false, 'message'=>'Culto não encontrado.']);
            $stmt = $pdo->prepare('SELECT status_presenca FROM culto_integrantes WHERE culto_id = ? AND usuario_id = ?');
            $stmt->execute([$cultoId, (int)$user['id']]);
            if ($stmt->fetch()) {
                $pdo->prepare('DELETE FROM culto_integrantes WHERE culto_id = ? AND usuario_id = ?')->execute([$cultoId, (int)$user['id']]);
            } else {
                $pdo->prepare("INSERT INTO culto_integrantes (culto_id, usuario_id, status_presenca) VALUES (?, ?, 'Confirmada')")->execute([$cultoId, (int)$user['id']]);
            }
            $stmt = $pdo->prepare('SELECT u.nome FROM culto_integrantes ci JOIN usuarios u ON u.id = ci.usuario_id WHERE ci.culto_id = ? ORDER BY u.nome');
            $stmt->execute([$cultoId]);
            responder(200, ['ok'=>true, 'membros'=>$stmt->fetchAll(PDO::FETCH_COLUMN)]);

        case 'confirmar_culto':
            if (!$isAdmin) responder(403, ['ok'=>false, 'message'=>'Somente administradores podem confirmar escalas.']);
            $cultoId = filter_var($dados['culto_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$cultoId) responder(422, ['ok'=>false, 'message'=>'Culto inválido.']);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM culto_integrantes WHERE culto_id = ?');
            $stmt->execute([$cultoId]);
            if ((int)$stmt->fetchColumn() === 0) responder(422, ['ok'=>false, 'message'=>'Adicione ao menos um integrante antes de confirmar a escala.']);
            $pdo->prepare("UPDATE cultos SET status = 'Confirmada' WHERE id = ?")->execute([$cultoId]);
            responder(200, ['ok'=>true]);

        case 'salvar_repertorio':
            if (!$isAdmin) responder(403, ['ok'=>false, 'message'=>'Somente administradores podem editar o repertório.']);
            $cultoId = filter_var($dados['culto_id'] ?? null, FILTER_VALIDATE_INT);
            $ids = $dados['louvores_ids'] ?? [];
            if (!$cultoId || !is_array($ids)) responder(422, ['ok'=>false, 'message'=>'Dados de repertório inválidos.']);
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM culto_louvores WHERE culto_id = ?')->execute([$cultoId]);
            $insert = $pdo->prepare('INSERT INTO culto_louvores (culto_id, louvor_id, ordem) VALUES (?, ?, ?)');
            foreach ($ids as $i => $louvorId) $insert->execute([$cultoId, $louvorId, $i + 1]);
            $pdo->commit();
            responder(200, ['ok'=>true]);

        default:
            responder(400, ['ok'=>false, 'message'=>'Ação desconhecida.']);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Erro no Portal do Grupo de Louvor: ' . $e->getMessage());
    responder(500, ['ok'=>false, 'message'=>'Ocorreu um erro ao salvar os dados. Verifique a configuração do banco de dados.']);
}
