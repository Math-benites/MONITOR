<?php
include __DIR__ . '/../functions.php';

$server_name = $_GET['server'] ?? $servers[0]['name'] ?? null;
$server_info = get_server($server_name);
if(!$server_info) die("Servidor '$server_name' não encontrado.");

$group_id = $_GET['group'] ?? null;
if(!$group_id) die("Grupo de usuários não especificado.");

$group_detail = get_usergroup_detail($server_name, $group_id);
if(!$group_detail) die("Grupo de usuários não encontrado.");

$hostgroups = get_hostgroups($server_name);
$hostgroup_index = [];
foreach($hostgroups as $hg){
    $hostgroup_index[$hg['groupid']] = [
        'name' => $hg['name'],
        'hosts_count' => $hg['hosts'] ?? 0
    ];
}

$rights = [];
foreach($group_detail['hostgroup_rights'] ?? [] as $right){
    $hg_id = $right['id'] ?? $right['hostgroupid'] ?? null;
    $permission = [0=>'Nenhum',2=>'Leitura',3=>'Leitura-Escrita'][$right['permission']] ?? $right['permission'];
    $rights[] = [
        'hostgroup_id' => $hg_id,
        'name' => $hostgroup_index[$hg_id]['name'] ?? ($right['name'] ?? 'Desconhecido'),
        'hosts_count' => $hostgroup_index[$hg_id]['hosts_count'] ?? 0,
        'permission' => $permission,
        'raw' => $right
    ];
}

$output = [
    'server' => $server_info,
    'group' => [
        'usrgrpid' => $group_detail['usrgrpid'],
        'name' => $group_detail['name'],
        'rights' => $rights
    ]
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
