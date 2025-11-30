<?php
/**
 * Coleta diariamente o total de hosts por grupo de usuários e salva em histórico JSON (cinco semanas).
 * Deve ser executado via CLI (cron, task scheduler ou container) por exemplo:
 * php scripts/collect_host_history.php
 */

date_default_timezone_set('America/Sao_Paulo');

define('DATA_DIR', __DIR__ . '/../data');
require __DIR__ . '/../www/functions.php';

$history_root = DATA_DIR . '/history/hosts';
if(!is_dir($history_root)){
    if(!mkdir($history_root, 0755, true)){
        die("Não foi possível criar diretório de histórico: $history_root");
    }
}

$today = (new DateTimeImmutable())->format('Y-m-d');
$timestamp = time();

foreach($servers as $server){
    $server_name = $server['name'];
    $server_account = $server['server_account'];
    $ignore_groups = array_map('intval', $server['groupuser_ignore'] ?? []);

    echo "Processando servidor {$server_name}...\n";

    $hostgroups = get_hostgroups($server_name);
    $hostgroup_index = [];
    foreach($hostgroups as $hg){
        $host_id = $hg['groupid'] ?? null;
        if(!$host_id) continue;
        $hostgroup_index[(string)$host_id] = intval($hg['hosts'] ?? 0);
    }

    $usergroups = list_usergroups($server_name);
    foreach($usergroups as $group){
        $group_id = intval($group['usrgrpid'] ?? 0);
        if($group_id === 0 || in_array($group_id, $ignore_groups, true)){
            continue;
        }

        $group_detail = get_usergroup_detail($server_name, $group_id);
        if(!$group_detail) continue;

        $rights = $group_detail['hostgroup_rights'] ?? [];
        $seen_hostgroups = [];
        $total_hosts = 0;
        $total_hostgroups = 0;
        foreach($rights as $right){
            $hg_id = $right['id'] ?? $right['hostgroupid'] ?? null;
            if(!$hg_id) continue;
            $hg_id = (string)$hg_id;
            if(isset($seen_hostgroups[$hg_id])) continue;
            $seen_hostgroups[$hg_id] = true;
            $total_hostgroups++;
            $total_hosts += $hostgroup_index[$hg_id] ?? 0;
        }

        $history_file = "{$history_root}/{$server_account}_{$group_id}.json";
        $history = [];
        if(file_exists($history_file)){
            $history = json_decode(file_get_contents($history_file), true) ?? [];
        }

        $entry = [
            'date' => $today,
            'timestamp' => $timestamp,
            'hosts' => $total_hosts,
            'hostgroups' => $total_hostgroups
        ];

        $history[] = $entry;

        if(count($history) > 500){
            $history = array_slice($history, -500);
        }

        file_put_contents($history_file, json_encode($history, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        printf("  Grupo %s (%d): %d hosts / %d hostgroups\n", $group_detail['name'], $group_id, $total_hosts, $total_hostgroups);
    }
}
