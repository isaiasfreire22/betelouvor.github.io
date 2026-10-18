-- Banco de dados do Portal do Grupo de Louvor
-- Compatível com MySQL 8+ / MariaDB recente.
-- Importe este arquivo no phpMyAdmin ou execute pelo cliente MySQL.

CREATE DATABASE IF NOT EXISTS portal_louvor
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE portal_louvor;

-- 1. Usuários e acesso ao sistema
CREATE TABLE IF NOT EXISTS usuarios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    usuario VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('admin', 'integrante') NOT NULL DEFAULT 'integrante',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Catálogo de louvores
CREATE TABLE IF NOT EXISTS louvores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(180) NOT NULL,
    artista VARCHAR(180) NOT NULL,
    link_youtube VARCHAR(500) NULL,
    tema ENUM(
      'Gratidão', 'Ceia', 'Fé', 'Salvação',
      'Espírito Santo', 'Adoração', 'Outros'
    ) NOT NULL DEFAULT 'Outros',
    ritmo ENUM(
      'Lenta (Adoração)', 'Rápida (Celebração)', 'Moderada'
    ) NOT NULL DEFAULT 'Moderada',
    observacoes TEXT NULL,
    cadastrado_por BIGINT UNSIGNED NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_louvores_usuario
      FOREIGN KEY (cadastrado_por) REFERENCES usuarios(id)
      ON DELETE SET NULL,
    INDEX idx_louvores_titulo (titulo),
    INDEX idx_louvores_tema (tema)
) ENGINE=InnoDB;

-- 3. Cultos / escalas
CREATE TABLE IF NOT EXISTS cultos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data_culto DATE NOT NULL,
    horario TIME NULL,
    tipo VARCHAR(100) NOT NULL DEFAULT 'Culto',
    tema VARCHAR(180) NULL,
    status ENUM('Pendente', 'Confirmada', 'Realizada', 'Cancelada')
      NOT NULL DEFAULT 'Pendente',
    observacoes TEXT NULL,
    criado_por BIGINT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cultos_usuario
      FOREIGN KEY (criado_por) REFERENCES usuarios(id)
      ON DELETE SET NULL,
    INDEX idx_cultos_data (data_culto),
    INDEX idx_cultos_status (status)
) ENGINE=InnoDB;

-- 4. Relação entre cultos e louvores.
-- Um culto pode ter vários louvores e o mesmo louvor pode ser usado
-- em vários cultos. A coluna ordem mantém a sequência do repertório.
CREATE TABLE IF NOT EXISTS culto_louvores (
    culto_id BIGINT UNSIGNED NOT NULL,
    louvor_id BIGINT UNSIGNED NOT NULL,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    tom_musical VARCHAR(20) NULL,
    observacoes VARCHAR(500) NULL,
    PRIMARY KEY (culto_id, louvor_id),
    CONSTRAINT fk_cl_culto
      FOREIGN KEY (culto_id) REFERENCES cultos(id)
      ON DELETE CASCADE,
    CONSTRAINT fk_cl_louvor
      FOREIGN KEY (louvor_id) REFERENCES louvores(id)
      ON DELETE RESTRICT,
    UNIQUE KEY uq_culto_ordem (culto_id, ordem)
) ENGINE=InnoDB;

-- 5. Integrantes escalados / disponibilidade / presença em cada culto
CREATE TABLE IF NOT EXISTS culto_integrantes (
    culto_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    status_presenca ENUM('Pendente', 'Confirmada', 'Ausente')
      NOT NULL DEFAULT 'Pendente',
    observacoes VARCHAR(500) NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (culto_id, usuario_id),
    CONSTRAINT fk_ci_culto
      FOREIGN KEY (culto_id) REFERENCES cultos(id)
      ON DELETE CASCADE,
    CONSTRAINT fk_ci_usuario
      FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
      ON DELETE CASCADE
) ENGINE=InnoDB;

-- Observação de segurança:
-- Não armazene senhas em texto puro. Gere o hash no PHP com password_hash()
-- e valide com password_verify(). Use consultas preparadas (PDO).
-- Após importar, crie o primeiro usuário administrador por um formulário
-- PHP seguro que grave senha_hash usando password_hash().
