<?php
require_once __DIR__ . '/auth.php';

define('GOOGLE_CONFIG_FILE', '/data/google.json');
define('GOOGLE_ALLOWED_USERS_FILE', __DIR__ . '/../data/user/user.json');

function google_load_config() {
    if (!file_exists(GOOGLE_CONFIG_FILE)) {
        return [];
    }
    $config = json_decode(file_get_contents(GOOGLE_CONFIG_FILE), true);
    return is_array($config) ? $config : [];
}

function google_config_valid(array $config) {
    return !empty($config['client_id']) && !empty($config['client_secret']) && !empty($config['redirect_uri']);
}

function google_build_auth_url(array $config, $state) {
    $params = [
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'prompt' => 'select_account',
        'state' => $state,
    ];
    if (!empty($config['hosted_domain'])) {
        $params['hd'] = $config['hosted_domain'];
    }
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function google_exchange_code(array $config, $code) {
    $post_fields = http_build_query([
        'code' => $code,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function google_fetch_userinfo($access_token) {
    $ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function google_email_allowed(array $config, $email) {
    if (empty($email)) {
        return false;
    }
    if (file_exists(GOOGLE_ALLOWED_USERS_FILE)) {
        $allowed_users = json_decode(file_get_contents(GOOGLE_ALLOWED_USERS_FILE), true);
        if (is_array($allowed_users) && !empty($allowed_users)) {
            return in_array($email, $allowed_users, true);
        }
    }
    if (!empty($config['allowed_emails']) && is_array($config['allowed_emails'])) {
        return in_array($email, $config['allowed_emails'], true);
    }
    if (!empty($config['allowed_domains']) && is_array($config['allowed_domains'])) {
        $domain = substr(strrchr($email, '@') ?: '', 1);
        return in_array($domain, $config['allowed_domains'], true);
    }
    return true;
}

$errors = [];
$return_to = $_POST['return'] ?? ($_GET['return'] ?? '/');
$google_config = google_load_config();
$google_enabled = google_config_valid($google_config);

if ($google_enabled && isset($_GET['error'])) {
    $errors[] = 'Falha ao autenticar com o Google.';
}

if ($google_enabled && isset($_GET['code'])) {
    $expected_state = $_SESSION['google_oauth_state'] ?? '';
    $state = $_GET['state'] ?? '';
    if ($expected_state === '' || !hash_equals($expected_state, $state)) {
        $errors[] = 'Estado inválido no login do Google.';
    } else {
        $token = google_exchange_code($google_config, $_GET['code']);
        if (!$token || empty($token['access_token'])) {
            $errors[] = 'Não foi possível obter o token do Google.';
        } else {
            $userinfo = google_fetch_userinfo($token['access_token']);
            if (!$userinfo || empty($userinfo['email'])) {
                $errors[] = 'Não foi possível obter o usuário do Google.';
            } elseif (!google_email_allowed($google_config, $userinfo['email'])) {
                $errors[] = 'Seu e-mail não tem permissão para acessar.';
            } else {
                $_SESSION['user'] = [
                    'name' => $userinfo['name'] ?? $userinfo['email'],
                    'username' => $userinfo['email'],
                    'role' => 'google'
                ];
                $return_to = $_SESSION['google_oauth_return'] ?? $return_to;
                unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_return']);
                header("Location: {$return_to}");
                exit;
            }
        }
    }
}

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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
    <link rel="icon" type="image/svg+xml" href="/img/infrastack.svg">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/cards.css">
</head>
<body class="login-body">
    <div class="login-shell">
        <div class="login-hero">
            <div class="login-brand">
                <img class="login-brand__logo" src="/img/infrastack.svg" alt="InfraStack">
                <div class="login-brand__text">
                    <span class="login-brand__name">InfraStack</span>
                    <span class="login-brand__tag">Monitor</span>
                </div>
            </div>
            <h1>Controle total do seu ambiente em tempo real.</h1>
        </div>
        <div class="login-panel">
            <div class="login-card card">
                <div class="login-card__header">
                    <h2>Entrar no Monitor</h2>
                    <p>Use sua conta Google para continuar.</p>
                </div>
                <?php if($errors): ?>
                    <div class="api-alert">
                        <ul>
                            <?php foreach($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if($google_enabled): ?>
                    <?php
                        $oauth_state = bin2hex(random_bytes(16));
                        $_SESSION['google_oauth_state'] = $oauth_state;
                        $_SESSION['google_oauth_return'] = $return_to;
                        $google_auth_url = google_build_auth_url($google_config, $oauth_state);
                    ?>
                    <a class="login-google-btn" href="<?= htmlspecialchars($google_auth_url) ?>">
                        <span class="login-google-btn__icon" aria-hidden="true">
                            <i class="fa-brands fa-google" aria-hidden="true"></i>
                        </span>
                        Entrar com Google
                    </a>
                <?php else: ?>
                    <div class="api-alert">
                        Nao foi possivel carregar as credenciais do Google.
                    </div>
                <?php endif; ?>
                <p class="login-footnote">
                    Ao continuar, voce concorda com o uso seguro da sua conta para autenticar o acesso.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
