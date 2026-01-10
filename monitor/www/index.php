<?php
require_once __DIR__ . '/auth.php';
auth_require_login();
$auth_user = auth_current_user();
include __DIR__ . '/functions.php';

$data_dir = '/data';
$history_dir = '/data/history';
$settings_file = "$data_dir/settings.json";
$planos_file  = "$data_dir/planos.json";

// ====== Carrega configurações ======
if(!file_exists($settings_file) || !file_exists($planos_file)){
    die("Arquivos de configuração não encontrados.");
}

$settings = json_decode(file_get_contents($settings_file), true);
$servers  = $settings['servers'] ?? [];
$planos   = json_decode(file_get_contents($planos_file), true);

// ====== Controle de acesso por usuario/org ======
$access_error = null;
$allowed_server_groups = [];
$users_file = "$data_dir/user/user.json";
$orgs_file = "$data_dir/user/org.json";
$auth_email = $auth_user['username'] ?? '';

if (!file_exists($users_file) || !file_exists($orgs_file)) {
    $access_error = 'Arquivos de acesso nao encontrados.';
    $servers = [];
} else {
    $users_data = json_decode(file_get_contents($users_file), true);
    $orgs_data = json_decode(file_get_contents($orgs_file), true);
    $users_list = $users_data['users'] ?? [];
    $orgs_list = $orgs_data['orgs'] ?? [];

    $current_user = null;
    foreach ($users_list as $user) {
        if (($user['email'] ?? '') === $auth_email) {
            $current_user = $user;
            break;
        }
    }

    if (!$current_user) {
        $access_error = 'Usuario sem permissao.';
        $servers = [];
    } else {
        $org_id = $current_user['org_id'] ?? null;
        $current_org = null;
        foreach ($orgs_list as $org) {
            if (($org['id'] ?? null) === $org_id) {
                $current_org = $org;
                break;
            }
        }

        if (!$current_org) {
            $access_error = 'Organizacao nao encontrada.';
            $servers = [];
        } else {
            foreach ($current_org['servers'] ?? [] as $org_server) {
                $server_id = $org_server['server_id'] ?? null;
                if (!$server_id) {
                    continue;
                }
                $allowed_server_groups[$server_id] = array_map(
                    'intval',
                    $org_server['groups'] ?? []
                );
            }
            $servers = array_values(array_filter(
                $servers,
                fn($srv) => isset($allowed_server_groups[$srv['server_account'] ?? ''])
            ));
            if (!$servers) {
                $access_error = 'Nenhum servidor autorizado.';
            }
        }
    }
}

// ====== Seleciona servidor ======
$server_name = $_GET['server'] ?? ($servers[0]['name'] ?? null);
$server_info = null;
foreach($servers as $srv){
    if($srv['name'] === $server_name){
        $server_info = $srv;
        break;
    }
}
if(!$server_info && $servers){
    $server_info = $servers[0];
    $server_name = $server_info['name'];
}

// ====== Filtra grupos deste servidor ======
$server_account = $server_info['server_account'] ?? null;
$typeday = $server_info['typeday'] ?? 'N/A';
$allowed_group_ids = $server_account ? ($allowed_server_groups[$server_account] ?? []) : [];

$ignore_groups = array_map('intval', $server_info['groupuser_ignore'] ?? []);
$api_error = null;
$user_groups = [];
$groups_count = 0;
$hosts_from_api = [];
$total_hosts_api = 0;
$total_items_api = 0;

if ($server_info) {
    try {
        $user_groups_raw = list_usergroups($server_name);
        $user_groups = array_values(array_filter(
            $user_groups_raw,
            fn($group) => !in_array((int)($group['usrgrpid'] ?? 0), $ignore_groups, true)
                && in_array((int)($group['usrgrpid'] ?? 0), $allowed_group_ids, true)
        ));
        $groups_count = count($user_groups);

        $hosts_from_api = get_hosts($server_name);
        $total_hosts_api = count($hosts_from_api);
        $host_ids = array_filter(array_map(fn($host) => $host['hostid'] ?? null, $hosts_from_api));
        $total_items_api = $host_ids ? count_items($server_name, $host_ids) : 0;
    } catch (Exception $ex) {
        $api_error = $ex->getMessage();
        $user_groups = [];
        $groups_count = 0;
        $hosts_from_api = [];
        $total_hosts_api = 0;
        $total_items_api = 0;
    }
} else {
    $api_error = $access_error ?: 'Servidor nao autorizado.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>InfraStack Dashboard</title>
<link rel="icon" type="image/svg+xml" href="/img/infrastack.svg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/css/style.css">
<link rel="stylesheet" href="/css/cards.css">
</head>
<body>
    <div class="topbar-shell">
        <div class="topbar-brand">
            <a href="/">
                <img src="/img/infrastack.svg" alt="InfraStack">
                <span>InfraStack</span>
            </a>
        </div>
        <a href="/logout.php" class="topbar-logout">Logout</a>
    </div>

<div class="central-menu-wrapper">

    <!-- Select servidor -->
    <div class="card select-card">
        <label for="server">Escolha o servidor:</label>
        <select id="server" name="server" <?= empty($servers) ? 'disabled' : '' ?>>
            <?php foreach ($servers as $srv): ?>
                <option value="<?= htmlspecialchars($srv['name']) ?>"
                    <?= $srv['name']===$server_name?'selected':'' ?>>
                    <?= htmlspecialchars($srv['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button id="accessServer" <?= empty($servers) ? 'disabled' : '' ?>>Atualizar</button>
    </div>

    <!-- Grupos e contador -->
    <div class="groups-board">
        <?php if($api_error): ?>
            <div class="api-alert">
                <strong>Sem conectividade</strong>
                <p>Nao foi possivel carregar os dados do servidor <?= htmlspecialchars($server_info['name'] ?? 'N/A') ?>: <?= htmlspecialchars($api_error) ?>.</p>
            </div>
        <?php endif; ?>
        <div class="groups-board-header">
            <h2>Grupos de usuários</h2>
            <p>Listagem dos grupos vinculados ao servidor selecionado.</p>
        </div>
        <div class="groups-grid">
            <div class="group-list-panel">
                <?php if(!empty($user_groups)): ?>
                    <ul class="group-list">
                        <?php foreach($user_groups as $group): ?>
                            <?php $isInactive = ((int)($group['users_status'] ?? 0)) !== 0; ?>
                            <li class="group-item <?= $isInactive ? 'group-item--inactive' : '' ?>" data-groupid="<?= htmlspecialchars($group['usrgrpid']) ?>">
                                <button type="button" class="group-link">
                                    <div class="group-link__info">
                                        <span class="group-name"><?= htmlspecialchars($group['name']) ?></span>
                                        <?php if($isInactive): ?>
                                            <span class="group-status">Inativo</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="group-id">ID <?= htmlspecialchars($group['usrgrpid']) ?></span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="empty-state">Nenhum grupo encontrado para este servidor.</p>
                <?php endif; ?>
            </div>
            <div class="group-sidebar-panel">
                <div class="group-counter-panel">
                    <span class="group-counter-label">Clientes</span>
                    <strong><?= $groups_count ?></strong>
                    <span class="group-counter-sub">Grupos ativos</span>
                    <small><?= htmlspecialchars($server_info['name'] ?? 'N/A') ?></small>
                </div>
                <div class="group-info-card">
                    <p class="info-card-title">Informações rápidas</p>
                    <div class="info-card-row">
                        <span>Server Day</span>
                        <strong><?= htmlspecialchars($typeday) ?></strong>
                    </div>
                    <div class="info-card-row">
                        <span>Total Hosts</span>
                        <strong><?= $total_hosts_api ?></strong>
                    </div>
                    <div class="info-card-row">
                        <span>Total Items</span>
                        <strong><?= $total_items_api ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="canvas" id="canvas"></div>

<script>
document.getElementById('accessServer').addEventListener('click', () => {
    const serverName = document.getElementById('server').value;
    if(serverName){
        window.location.href = `/index.php?server=${encodeURIComponent(serverName)}`;
    }
});
const groupServer = <?= json_encode($server_name) ?>;
document.querySelectorAll('.group-link').forEach(link => {
    link.addEventListener('click', () => {
        const item = link.closest('.group-item');
        const groupId = item?.dataset.groupid;
        if(!groupId) return;
        const url = new URL('/client/client.php', window.location.origin);
        url.searchParams.set('server', groupServer);
        url.searchParams.set('group', groupId);
        window.location.href = url.toString();
    });
});
</script>


</body>
</html>
