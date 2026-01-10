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

function server_is_online($url) {
    if (!$url) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    $error = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) {
        return false;
    }
    return $status > 0;
}

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

$server_statuses = [];
foreach ($servers as $srv) {
    $server_statuses[$srv['name']] = server_is_online($srv['zabbix_url'] ?? '');
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
$client_profiles = [];
$client_profiles_file = "$data_dir/client_profiles.json";

function update_client_profiles($file, $server_account, $server_name, array $user_groups) {
    if (!$server_account) {
        return;
    }
    $profiles = [];
    if (file_exists($file)) {
        $profiles = json_decode(file_get_contents($file), true);
        if (!is_array($profiles)) {
            $profiles = [];
        }
    }
    $updated = false;
    foreach ($user_groups as $group) {
        $group_id = isset($group['usrgrpid']) ? (int) $group['usrgrpid'] : 0;
        if (!$group_id) {
            continue;
        }
        $key = $server_account . '_' . $group_id;
        $name = $group['name'] ?? '';
        if (!isset($profiles[$key]) || !is_array($profiles[$key])) {
            $profiles[$key] = [];
            $updated = true;
        }
        if (($profiles[$key]['group_id'] ?? null) !== $group_id) {
            $profiles[$key]['group_id'] = $group_id;
            $updated = true;
        }
        if ($name && ($profiles[$key]['name'] ?? '') !== $name) {
            $profiles[$key]['name'] = $name;
            $updated = true;
        }
    }
    if ($server_name) {
        try {
            $hostgroups = get_hostgroups($server_name);
            $hostgroups_indexed = [];
            foreach ($hostgroups as $hg) {
                $hostgroups_indexed[$hg['groupid']] = [
                    'name' => $hg['name'],
                    'hosts_count' => (int) ($hg['hosts'] ?? 0)
                ];
            }

            foreach ($user_groups as $group) {
                $group_id = isset($group['usrgrpid']) ? (int) $group['usrgrpid'] : 0;
                if (!$group_id) {
                    continue;
                }
                $detail = get_usergroup_detail($server_name, $group_id);
                if (!$detail) {
                    continue;
                }
                $rights = [];
                $total_hosts = 0;
                foreach ($detail['hostgroup_rights'] ?? [] as $right) {
                    $hg_id = $right['id'] ?? $right['hostgroupid'] ?? null;
                    if (!$hg_id) {
                        continue;
                    }
                    $permission = [0 => "Nenhum", 2 => "Leitura", 3 => "Leitura-Escrita"][$right['permission']] ?? $right['permission'];
                    $hosts_count = (int) ($hostgroups_indexed[$hg_id]['hosts_count'] ?? 0);
                    $rights[] = [
                        'id' => (string) $hg_id,
                        'name' => $hostgroups_indexed[$hg_id]['name'] ?? ($right['name'] ?? 'Desconhecido'),
                        'permission' => $permission,
                        'hosts_count' => $hosts_count
                    ];
                    $total_hosts += $hosts_count;
                }
                $key = $server_account . '_' . $group_id;
                if (!isset($profiles[$key]) || !is_array($profiles[$key])) {
                    $profiles[$key] = [];
                }
                $profiles[$key]['last_snapshot'] = [
                    'captured_at' => date('c'),
                    'hostgroups' => $rights,
                    'summary' => [
                        'total_hostgroups' => count($rights),
                        'total_hosts' => $total_hosts
                    ]
                ];
                $updated = true;
            }
        } catch (Exception $ex) {
        }
    }

    if ($updated) {
        file_put_contents($file, json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

if ($server_info) {
    try {
        $user_groups_raw = list_usergroups($server_name);
        $user_groups = array_values(array_filter(
            $user_groups_raw,
            fn($group) => !in_array((int)($group['usrgrpid'] ?? 0), $ignore_groups, true)
                && in_array((int)($group['usrgrpid'] ?? 0), $allowed_group_ids, true)
        ));
        $groups_count = count($user_groups);
        update_client_profiles($client_profiles_file, $server_account, $server_name, $user_groups);

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

if ($api_error && $server_account && file_exists($client_profiles_file)) {
    $client_profiles = json_decode(file_get_contents($client_profiles_file), true);
    if (is_array($client_profiles)) {
        $fallback_groups = [];
        foreach ($client_profiles as $key => $profile) {
            if (strpos($key, $server_account . '_') !== 0) {
                continue;
            }
            $parts = explode('_', $key);
            $group_id = isset($parts[1]) ? (int) $parts[1] : 0;
            if (!$group_id || !in_array($group_id, $allowed_group_ids, true)) {
                continue;
            }
            if (!empty($profile['group_id'])) {
                $group_id = (int) $profile['group_id'];
            }
            $fallback_groups[] = [
                'usrgrpid' => (string) $group_id,
                'name' => $profile['name'] ?? $profile['company'] ?? ("Grupo " . $group_id),
                'users_status' => 0
            ];
        }
        if ($fallback_groups) {
            $user_groups = $fallback_groups;
            $groups_count = count($fallback_groups);
        }
    }
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
        <div class="topbar-user">
            <span><?= htmlspecialchars($auth_user['name'] ?? $auth_user['username'] ?? 'Usuario') ?></span>
            <a href="/logout.php" class="topbar-logout">Logout</a>
        </div>
    </div>

<div class="access-shell" data-loading="true">
    <section class="access-card">
        <header class="access-card__header">
            <h1><?= htmlspecialchars($current_org['name'] ?? '') ?></h1>
            <p>Servicos, clientes e produtos</p>
        </header>

        <div class="access-skeleton" aria-hidden="true">
            <div class="skeleton-line"></div>
            <div class="skeleton-row"></div>
            <div class="skeleton-row"></div>
            <div class="skeleton-row"></div>
        </div>


        <div class="access-layout">
            <div class="server-list">
                <?php if(!empty($servers)): ?>
                    <?php foreach ($servers as $srv): ?>
                        <?php $server_url = $srv['url_user'] ?? ''; ?>
                        <?php $is_online = $server_statuses[$srv['name']] ?? false; ?>
                        <div class="server-item <?= $srv['name']===$server_name?'server-item--active':'' ?>">
                            <a class="server-item__link" href="/index.php?server=<?= urlencode($srv['name']) ?>">
                                <div class="server-item__title">
                                    <span class="server-status <?= $is_online ? 'server-status--online' : 'server-status--offline' ?>">
                                        <?= $is_online ? 'Online' : 'Offline' ?>
                                    </span>
                                    <span><?= htmlspecialchars($srv['name']) ?></span>
                                </div>
                                <small><?= htmlspecialchars($srv['server_account'] ?? '') ?></small>
                            </a>
                            <?php if(!empty($server_url)): ?>
                                <a class="server-item__action" href="<?= htmlspecialchars($server_url) ?>" target="_blank" rel="noopener">
                                    Ir
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="empty-state">Nenhum servidor autorizado.</p>
                <?php endif; ?>
            </div>

            <div class="group-list-panel">
                <div class="group-list-panel__header">
                    <h2>Grupos de empresas</h2>
                    <span>Mostrando os 10 primeiros</span>
                </div>
                <?php if(!empty($user_groups)): ?>
                    <ul class="group-list">
                        <?php foreach(array_slice($user_groups, 0, 10) as $group): ?>
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
        </div>
    </section>
</div>

<div class="canvas" id="canvas"></div>

<script>
const accessShell = document.querySelector('.access-shell');
window.addEventListener('load', () => {
    if (accessShell) {
        accessShell.removeAttribute('data-loading');
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
