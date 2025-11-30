<?php
session_start();

define('AUTH_USER_FILE', '/data/manager/users.json');

function auth_require_login() {
    if(!isset($_SESSION['user'])){
        $return = urlencode($_SERVER['REQUEST_URI']);
        header("Location: /login.php?return={$return}");
        exit;
    }
}

function auth_current_user() {
    return $_SESSION['user'] ?? ['name' => 'guest'];
}

function auth_attempt_login($username, $password) {
    $users = auth_load_users();
    foreach($users as $user){
        if($user['username'] === $username && $user['password'] === $password){
            $_SESSION['user'] = [
                'name' => $user['name'] ?? $username,
                'username' => $username,
                'role' => $user['role'] ?? 'admin'
            ];
            return true;
        }
    }
    return false;
}

function auth_logout(){
    session_unset();
    session_destroy();
}

function auth_load_users(){
    $default = [
        [
            'username' => 'admin',
            'password' => 'admin',
            'name' => 'InfraStack Admin',
            'role' => 'administrator'
        ]
    ];
    if(!file_exists(AUTH_USER_FILE)){
        file_put_contents(AUTH_USER_FILE, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    $users = json_decode(file_get_contents(AUTH_USER_FILE), true);
    if(!is_array($users)){
        $users = $default;
    }
    return $users;
}
