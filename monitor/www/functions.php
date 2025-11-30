<?php
$data_dir = defined('DATA_DIR') ? DATA_DIR : '/data';
$settings_file = "$data_dir/settings.json";

// Ler configurações do Zabbix
if(!file_exists($settings_file)) die("Arquivo de configurações não encontrado: $settings_file");

$settings = json_decode(file_get_contents($settings_file), true);
$servers = $settings['servers'] ?? [];
if(empty($servers)) die("Nenhum servidor configurado em settings.json.");

/**
 * Retorna o servidor pelo nome
 */
function get_server($name) {
    global $servers;
    foreach($servers as $srv) {
        if($srv['name'] === $name) return $srv;
    }
    return null;
}

/**
 * Função genérica para chamar a API Zabbix
 */
function zabbix_request($server_name, $method, $params) {
    $server = get_server($server_name);
    if(!$server) {
        throw new InvalidArgumentException("Servidor '$server_name' nao encontrado.");
    }

    $zabbix_url = $server['zabbix_url'] ?? '';
    $token = $server['token'] ?? '';
    if(!$zabbix_url || !$token) {
        throw new RuntimeException("Configuracoes Zabbix invalidas para o servidor '$server_name'.");
    }

    $payload = [
        "jsonrpc" => "2.0",
        "method" => $method,
        "params" => $params,
        "id" => 1
    ];

    $headers = [
        "Content-Type: application/json-rpc",
        "Authorization: Bearer $token"
    ];

    $ch = curl_init($zabbix_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    if($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Erro CURL: $error");
    }

    curl_close($ch);
    $result = json_decode($response, true);
    if(json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Resposta invalida da API Zabbix: " . json_last_error_msg());
    }

    if(isset($result["error"])) {
        $details = json_encode($result["error"], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        throw new RuntimeException("Erro API Zabbix: $details");
    }

    return $result["result"] ?? [];
}


/**
 * Retorna hostgroups com contagem de hosts
 */
function get_hostgroups($server_name) {
    return zabbix_request($server_name, "hostgroup.get", [
        "output" => ["groupid","name"],
        "selectHosts" => "count"
    ]);
}

/**
 * Retorna hosts, opcionalmente filtrando por groupid
 */
function get_hosts($server_name, $groupid = null) {
    $params = ["output" => ["hostid","host","name"]];
    if($groupid) $params["groupids"] = $groupid;
    return zabbix_request($server_name, "host.get", $params);
}

/**
 * Retorna detalhes de um grupo de usuários pelo ID
 */
function get_usergroup_detail($server_name, $usrgrpid) {
    $params = [
        "usrgrpids" => [$usrgrpid],
        "output" => "extend",
        "selectUsers" => ["userid","username","name","surname"],
        "selectHostGroupRights" => "extend",
        "selectTemplateGroupRights" => "extend"
    ];

    $groups = zabbix_request($server_name, "usergroup.get", $params);
    return $groups[0] ?? null;
}

/**
 * Lista todos os grupos de usuários
 */
function list_usergroups($server_name) {
    return zabbix_request($server_name, "usergroup.get", [
        "output" => ["usrgrpid","name","users_status"]
    ]);
}

/**
 * Conta itens, opcionalmente limitando aos hostids fornecidos
 */
function count_items($server_name, array $hostids = []) {
    $params = [
        "output" => [],
        "countOutput" => true
    ];
    if(!empty($hostids)){
        $params["hostids"] = array_values(array_map('strval', $hostids));
    }
    return intval(zabbix_request($server_name, "item.get", $params));
}

/**
 * Carrega todos os dados necessários: hostgroups, hosts e usergroups detalhados
 */
function load_data($server_name) {
    // Hostgroups
    $hostgroups = get_hostgroups($server_name);
    $hostgroups_indexed = [];
    foreach ($hostgroups as $hg) {
        $hostgroups_indexed[$hg['groupid']] = [
            'name' => $hg['name'],
            'hosts_count' => $hg['hosts'] ?? 0
        ];
    }

    // Hosts
    $hosts = get_hosts($server_name);

    // Usergroups detalhados
    $usergroups = list_usergroups($server_name);
    $usergroups_detailed = [];

    foreach ($usergroups as $ug) {
        $detail = get_usergroup_detail($server_name, $ug['usrgrpid']);
        if (!$detail) continue;

        $detail['hostgroup_rights'] = $detail['hostgroup_rights'] ?? [];
        $detail['users'] = $detail['users'] ?? [];
        $detail['templategroup_rights'] = $detail['templategroup_rights'] ?? [];

        $rights = [];
        foreach ($detail['hostgroup_rights'] as $r) {
            $hg_id = $r['id'] ?? $r['hostgroupid'] ?? null;
            $level = [0 => "Nenhum", 2 => "Leitura", 3 => "Leitura-Escrita"][$r['permission']] ?? $r['permission'];
            $rights[] = [
                'hostgroup_id' => $hg_id,
                'hostgroup_name' => $hostgroups_indexed[$hg_id]['name'] ?? ($r['name'] ?? 'Desconhecido'),
                'hosts_count' => $hostgroups_indexed[$hg_id]['hosts_count'] ?? 0,
                'permission' => $level
            ];
        }

        $usergroups_detailed[] = [
            'usrgrpid' => $detail['usrgrpid'],
            'name' => $detail['name'],
            'users' => array_map(function($u) {
                return [
                    'alias' => $u['alias'] ?? 'N/A',
                    'name' => $u['name'] ?? '',
                    'surname' => $u['surname'] ?? ''
                ];
            }, $detail['users']),
            'hostgroup_rights' => $rights,
            'template_rights' => $detail['templategroup_rights']
        ];
    }

    return [
        'hostgroups' => $hostgroups,
        'hosts' => $hosts,
        'usergroups_detailed' => $usergroups_detailed
    ];
}

/**
 * Topbar padrão
 */
function topbar() { ?>
    <div id="topbar">
        <div class="topbar-brand">
            <a href="/index.php">
                <img src="/img/infrastack.svg" alt="InfraStack" />
                <span>InfraStack</span>
            </a>
        </div>
        <div class="menu">
            <a href="/index.php" class="menu-item"><i class="fas fa-house"></i><span>Home</span></a>
            <a href="/admin.php" class="menu-item"><i class="fas fa-server"></i><span>Perfis</span></a>
            <a href="/logs.php" class="menu-item"><i class="fas fa-clipboard-list"></i><span>Logs</span></a>
            <div class="menu-item user-status">
                <i class="fas fa-user-circle"></i>
                <span><?= htmlspecialchars(auth_current_user()['name'] ?? 'guest') ?></span>
            </div>
            <a href="/logout.php" class="menu-item logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>
<?php }

/**
 * Card dashboard
 */
function card_dashboard($title, $value, $icon = null) {
    echo "<div class='card'>";
    if($icon) echo "<i class='{$icon}'></i>";
    echo "<strong>{$value}</strong>";
    echo "<span>{$title}</span>";
    echo "</div>";
}
?>
