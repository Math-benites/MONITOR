<?php
require_once __DIR__ . '/auth.php';

$errors = [];
$return_to = $_GET['return'] ?? '/';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    if($user === '' || $pass === ''){
        $errors[] = 'Informe usuário e senha.';
    } else if(auth_attempt_login($user, $pass)){
        header("Location: {$return_to}");
        exit;
    } else {
        $errors[] = 'Usuário ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login InfraStack</title>
    <link rel="icon" type="image/svg+xml" href="/img/infrastack.svg">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/cards.css">
</head>
<body>
    <div class="billing-page-shell">
        <div class="login-card card">
            <div class="topbar-brand">
                <a href="/">
                    <img src="/img/infrastack.svg" alt="InfraStack">
                    <span>InfraStack</span>
                </a>
            </div>
            <h2>Autenticação</h2>
            <?php if($errors): ?>
                <div class="api-alert">
                    <ul>
                        <?php foreach($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form method="post" class="billing-form">
                <label>
                    <span>Usuário</span>
                    <input type="text" name="username" autocomplete="username">
                </label>
                <label>
                    <span>Senha</span>
                    <input type="password" name="password" autocomplete="current-password">
                </label>
                <div class="print-actions">
                    <button type="submit" class="print-button">Entrar</button>
                </div>
                <input type="hidden" name="return" value="<?= htmlspecialchars($return_to) ?>">
            </form>
        </div>
    </div>
</body>
</html>
