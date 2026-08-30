<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$db   = 'ftth_network';
$user = 'root';
$pass = 'Edivar591'; // <-- COLOQUE A SUA SENHA DO MYSQL AQUI
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     echo "Conexão com o banco de dados realizada com sucesso!<br>";

     // Criar tabela de usuários
     $sql = "CREATE TABLE IF NOT EXISTS `usuarios` (
         `id` INT AUTO_INCREMENT PRIMARY KEY,
         `nome` VARCHAR(100) NOT NULL,
         `email` VARCHAR(100) NOT NULL UNIQUE,
         `senha` VARCHAR(255) NOT NULL,
         `perfil` ENUM('admin', 'tecnico', 'visualizador') DEFAULT 'visualizador',
         `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
     
     $pdo->exec($sql);
     echo "Tabela 'usuarios' verificada/criada com sucesso!<br>";

     // Inserir usuário administrador padrão
     $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
     $total = $stmt->fetchColumn();

     if ($total == 0) {
         $senhaHash = password_hash('admin', PASSWORD_BCRYPT);
         $insert = "INSERT INTO usuarios (nome, email, senha, perfil) VALUES ('Administrador', 'admin', '$senhaHash', 'admin')";
         $pdo->exec($insert);
         echo "Usuário administrador padrão criado!<br>";
         echo "<strong>Login:</strong> admin | <strong>Senha:</strong> admin<br>";
     } else {
         echo "A tabela de usuários já possui registros.<br>";
     }

} catch (\PDOException $e) {
     echo "Erro crítico na operação: " . $e->getMessage();
}
