#!/usr/bin/env php
<?php
if(!defined('DATA_DIR')){
    if(is_dir('/data')){
        define('DATA_DIR', '/data');
    } elseif(is_dir(__DIR__ . '/../data')){
        define('DATA_DIR', realpath(__DIR__ . '/../data'));
    } else {
        define('DATA_DIR', realpath(__DIR__ . '/../www/data'));
    }
}
require __DIR__ . '/../www/functions.php';

$timezone = new DateTimeZone('America/Sao_Paulo');
$data_root = $data_dir ?? (__DIR__ . '/../data');
$profiles_file = $data_root . '/client_profiles.json';
$billing_file = $data_root . '/group_billing.json';
$invoice_history_file = $data_root . '/billing/history/invoices.json';
$history_dir = $data_root . '/history/hosts';

$profiles = file_exists($profiles_file) ? (json_decode(file_get_contents($profiles_file), true) ?? []) : [];
$billing_data = file_exists($billing_file) ? (json_decode(file_get_contents($billing_file), true) ?? []) : [];
$invoice_history = file_exists($invoice_history_file) ? (json_decode(file_get_contents($invoice_history_file), true) ?? []) : [];

$now = new DateTimeImmutable('now', $timezone);
$current_day = (int)$now->format('j');
$current_month_days = (int)$now->format('t');
$results = [
    'emitted' => 0,
    'skipped' => 0,
];

$server_map = [];
foreach($servers as $srv){
    $server_map[$srv['server_account'] ?? ''] = $srv;
}

foreach($billing_data as $key => $billing){
    if(!preg_match('/^([^_]+)_(\d+)$/', $key, $matches)){
        $results['skipped']++;
        continue;
    }
    $server_account = $matches[1];
    $group_id = $matches[2];
    $server_info = $server_map[$server_account] ?? null;
    if(!$server_info){
        $results['skipped']++;
        continue;
    }
    $profile = $profiles[$key] ?? [];
    $cycle_day = (int)($profile['billing_cycle_start_day'] ?? 1);
    $cycle_day = max(1, min(31, $cycle_day));
    $cycle_day_current = min($cycle_day, $current_month_days);
    if($current_day !== $cycle_day_current){
        $results['skipped']++;
        continue;
    }

    $cycle_start = (new DateTimeImmutable(sprintf('%04d-%02d-%02d', (int)$now->format('Y'), (int)$now->format('n'), $cycle_day_current), $timezone))->setTime(0, 0);
    $next_cycle_start = $cycle_start->modify('+1 month');
    $cycle_end = $next_cycle_start->modify('-1 day');

    $existing = array_filter($invoice_history, function($entry) use ($server_account, $group_id, $cycle_start){
        return ($entry['server_account'] ?? '') === $server_account
            && (string)($entry['group_id'] ?? '') === (string)$group_id
            && ($entry['cycle_start'] ?? '') === $cycle_start->format('c');
    });
    if(!empty($existing)){
        $results['skipped']++;
        continue;
    }

    $amount = (float)($billing['revenue'] ?? 0);
    if($amount <= 0){
        $amount = (float)($billing['plan_value'] ?? 0);
    }
    if($amount <= 0){
        $results['skipped']++;
        continue;
    }

    $history_file = $history_dir . '/' . $server_account . '_' . $group_id . '.json';
    $cycle_peak_hosts = 0;
    if(file_exists($history_file)){
        $entries = json_decode(file_get_contents($history_file), true) ?? [];
        foreach($entries as $entry){
            $timestamp = (int)($entry['timestamp'] ?? strtotime($entry['date'] ?? ''));
            if($timestamp >= $cycle_start->getTimestamp() && $timestamp <= $cycle_end->getTimestamp()){
                $cycle_peak_hosts = max($cycle_peak_hosts, (int)($entry['hosts'] ?? 0));
            }
        }
    }

    $description = 'Cobrança automática';
    if(!empty($billing['selected_plan_name'])){
        $description .= ' - ' . $billing['selected_plan_name'];
    }

    $invoice_entry = [
        'id' => bin2hex(random_bytes(6)),
        'server_account' => $server_account,
        'group_id' => $group_id,
        'amount' => $amount,
        'status' => 'Pendente',
        'issued_at' => $now->format('c'),
        'due_date' => $cycle_end->format('c'),
        'description' => $description,
        'hosts_peak' => $cycle_peak_hosts,
        'plan_name' => $billing['selected_plan_name'] ?? '',
        'cycle_start' => $cycle_start->format('c'),
        'cycle_end' => $cycle_end->format('c'),
        'auto_generated' => true
    ];

    $invoice_history[] = $invoice_entry;
    $results['emitted']++;
    echo "[auto-invoice] Emitido boleto para {$server_account} grupo {$group_id} no valor de R$ {$amount}\n";
}

if($results['emitted'] > 0){
    if(!is_dir(dirname($invoice_history_file))){
        mkdir(dirname($invoice_history_file), 0755, true);
    }
    file_put_contents($invoice_history_file, json_encode($invoice_history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

if($results['emitted'] === 0){
    echo "[auto-invoice] Nenhum boleto emitido neste ciclo.\n";
}
