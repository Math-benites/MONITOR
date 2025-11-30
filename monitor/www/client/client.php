<?php
include __DIR__ . '/../functions.php';
global $data_dir;

function format_money($value) {
    return number_format(floatval($value), 2, ',', '.');
}

function calculate_plan_revenue($plan, $hosts, $extra_rate) {
    $limit = intval($plan['host_limit'] ?? 0);
    $base = floatval($plan['valor'] ?? 0);
    if($limit <= 0) return $base;
    $over = max(0, $hosts - $limit);
    return $base + ($over * $extra_rate);
}

function profile_field_display($value) {
    $clean = trim((string)($value ?? ''));
    if($clean === '') {
        return '<span class="profile-empty">Não informado</span>';
    }
    return htmlspecialchars($clean);
}

$server_name = $_GET['server'] ?? $servers[0]['name'] ?? null;
$server_info = get_server($server_name);
if(!$server_info) die("Servidor '{$server_name}' não encontrado.");

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

$permission_map = [
    0 => 'Nenhum',
    2 => 'Leitura',
    3 => 'Leitura-Escrita'
];

$rights = [];
foreach($group_detail['hostgroup_rights'] ?? [] as $right){
    $hg_id = $right['id'] ?? $right['hostgroupid'] ?? null;
    if(!$hg_id) continue;
    $rights[] = [
        'hostgroup_id' => $hg_id,
        'name' => $hostgroup_index[$hg_id]['name'] ?? ($right['name'] ?? 'Desconhecido'),
        'hosts_count' => $hostgroup_index[$hg_id]['hosts_count'] ?? 0,
        'permission' => $permission_map[$right['permission']] ?? "Permissão {$right['permission']}"
    ];
}

$total_hostgroups = count($rights);
$total_hosts_access = array_sum(array_column($rights, 'hosts_count'));
$read_count = count(array_filter($rights, fn($r)=> $r['permission'] === 'Leitura'));
$write_count = count(array_filter($rights, fn($r)=> $r['permission'] === 'Leitura-Escrita'));

$history_dir = (isset($data_dir) && $data_dir ? $data_dir : (__DIR__ . '/../../data')) . '/history/hosts';
$history_file = "{$history_dir}/{$server_info['server_account']}_{$group_detail['usrgrpid']}.json";
$history_entries = [];
if(file_exists($history_file)){
    $history_entries = json_decode(file_get_contents($history_file), true) ?? [];
    $history_entries = array_map(function($entry){
        $timestamp = intval($entry['timestamp'] ?? 0);
        if($timestamp <= 0){
            $timestamp = strtotime($entry['date'] ?? '');
        }
        return [
            'date' => $entry['date'] ?? '',
            'hosts' => intval($entry['hosts'] ?? 0),
            'hostgroups' => intval($entry['hostgroups'] ?? 0),
            'timestamp' => $timestamp
        ];
    }, $history_entries);
    $history_entries = array_filter($history_entries, fn($entry)=> intval($entry['timestamp'] ?? 0) > 0);
    usort($history_entries, fn($a, $b) => intval($a['timestamp']) <=> intval($b['timestamp']));
}

$data_root = (isset($data_dir) && $data_dir ? $data_dir : (__DIR__ . '/../../data'));
$extra_host_rate = 9.5;
$planos_file = "{$data_root}/planos.json";
$planos_data = [];
if(file_exists($planos_file)){
    $planos_data = json_decode(file_get_contents($planos_file), true) ?? [];
}
$selected_typeday = $server_info['typeday'] ?? '';
$plan_entry = null;
foreach($planos_data as $entry){
    if(($entry['typeday'] ?? '') === $selected_typeday){
        $plan_entry = $entry;
        break;
    }
}
$plan_options = array_values($plan_entry['plans'] ?? []);
$recommended_plan = null;
foreach($plan_options as $plan){
    if($total_hosts_access <= intval($plan['host_limit'] ?? 0)){
        $recommended_plan = $plan;
        break;
    }
}
if(!$recommended_plan && !empty($plan_options)){
    $recommended_plan = end($plan_options);
}

$billing_file = "{$data_root}/group_billing.json";
$billing_data = [];
if(file_exists($billing_file)){
    $billing_data = json_decode(file_get_contents($billing_file), true) ?? [];
}
$group_key = "{$server_info['server_account']}_{$group_detail['usrgrpid']}";

$client_profile_fields = ['company','cnpj','phone','email','responsavel','address','notes','billing_cycle_start_day'];
$profiles_file = "{$data_root}/client_profiles.json";
$profiles_data = [];
if(file_exists($profiles_file)){
    $profiles_data = json_decode(file_get_contents($profiles_file), true) ?? [];
}
$profile_key = "{$server_info['server_account']}_{$group_detail['usrgrpid']}";
$default_profile = array_fill_keys($client_profile_fields, '');
$client_profile = array_merge($default_profile, $profiles_data[$profile_key] ?? []);
if(($client_profile['billing_cycle_start_day'] ?? '') === ''){
    $client_profile['billing_cycle_start_day'] = '1';
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';
    if($action === 'save_profile'){
        foreach($client_profile_fields as $field){
            $profiles_data[$profile_key][$field] = trim((string)($_POST[$field] ?? ''));
        }
        if(!is_dir(dirname($profiles_file))){
            mkdir(dirname($profiles_file), 0755, true);
        }
        file_put_contents($profiles_file, json_encode($profiles_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $client_profile = array_merge($default_profile, $profiles_data[$profile_key]);
    } elseif($action === 'save_plan'){
        $posted_limit = intval($_POST['plan_limit'] ?? 0);
        $plan_for_post = null;
        foreach($plan_options as $plan){
            if(intval($plan['host_limit'] ?? 0) === $posted_limit){
                $plan_for_post = $plan;
                break;
            }
        }
        if($plan_for_post){
            $revenue_value = calculate_plan_revenue($plan_for_post, $total_hosts_access, $extra_host_rate);
            $billing_data[$group_key] = [
                'selected_plan_limit' => intval($plan_for_post['host_limit'] ?? 0),
                'selected_plan_name' => $plan_for_post['name'] ?? '',
                'plan_value' => floatval($plan_for_post['valor'] ?? 0),
                'per_host_value' => (intval($plan_for_post['host_limit'] ?? 0) > 0)
                    ? floatval($plan_for_post['valor']) / intval($plan_for_post['host_limit'])
                    : 0,
                'over_hosts' => max(0, $total_hosts_access - intval($plan_for_post['host_limit'] ?? 0)),
                'extra_host_rate' => $extra_host_rate,
                'revenue' => $revenue_value,
                'updated_at' => (new DateTime())->format('c')
            ];
            file_put_contents($billing_file, json_encode($billing_data, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
}

$stored_billing = $billing_data[$group_key] ?? [];
$selected_plan = null;
if(isset($stored_billing['selected_plan_limit'])){
    foreach($plan_options as $plan){
        if(intval($plan['host_limit'] ?? 0) === intval($stored_billing['selected_plan_limit'] ?? 0)){
            $selected_plan = $plan;
            break;
        }
    }
}
if(!$selected_plan){
    $selected_plan = $recommended_plan;
}

$selected_limit = $selected_plan ? intval($selected_plan['host_limit'] ?? 0) : 0;

$display_plan_value = floatval($stored_billing['plan_value'] ?? 0);
$display_per_host_plan = floatval($stored_billing['per_host_value'] ?? 0);
$display_revenue = floatval($stored_billing['revenue'] ?? 0);
$display_over_hosts = isset($stored_billing['over_hosts'])
    ? intval($stored_billing['over_hosts'])
    : max(0, $selected_plan ? $total_hosts_access - intval($selected_plan['host_limit'] ?? 0) : 0);
$selected_plan_name = $stored_billing['selected_plan_name'] ?? ($selected_plan ? ($selected_plan['name'] ?? 'Plano não definido') : 'Plano não definido');
if($display_plan_value <= 0 && $selected_plan){
    $display_plan_value = floatval($selected_plan['valor'] ?? 0);
}
if($display_per_host_plan <= 0 && $selected_plan && intval($selected_plan['host_limit'] ?? 0) > 0){
    $display_per_host_plan = floatval($selected_plan['valor']) / intval($selected_plan['host_limit'] ?? 0);
}
if($display_revenue <= 0 && $selected_plan){
    $display_revenue = calculate_plan_revenue($selected_plan, $total_hosts_access, $extra_host_rate);
}
$charged_per_host = $total_hosts_access > 0 ? $display_revenue / $total_hosts_access : 0;
$recommended_plan_label = $recommended_plan
    ? sprintf('%s — %d hosts', $recommended_plan['name'] ?? 'Plano', intval($recommended_plan['host_limit'] ?? 0))
    : '';
$plan_limit_display = intval($selected_plan['host_limit'] ?? 0);
$used_hosts = $total_hosts_access;
$limit_percent_raw = $plan_limit_display > 0 ? ($used_hosts / max(1, $plan_limit_display)) * 100 : 0;
$limit_percent = $plan_limit_display > 0 ? min(100, max(0, $limit_percent_raw)) : 0;
$limit_overflow_percent = $plan_limit_display > 0 ? max(0, $limit_percent_raw - 100) : 0;
$selected_limit_label = $selected_limit > 0 ? sprintf('%d hosts', $selected_limit) : 'Sem limite definido';
if($selected_limit > 0){
    $plan_capacity_difference = $selected_limit - $total_hosts_access;
    if($plan_capacity_difference > 0){
        $plan_capacity_note = $plan_capacity_difference . ' host' . ($plan_capacity_difference === 1 ? '' : 's') . ' livre' . ($plan_capacity_difference === 1 ? '' : 's');
    } elseif($plan_capacity_difference === 0){
        $plan_capacity_note = 'No limite contratado';
    } else {
        $plan_capacity_note = 'Excedendo em ' . abs($plan_capacity_difference) . ' host' . (abs($plan_capacity_difference) === 1 ? '' : 's');
    }
} else {
    $plan_capacity_note = 'Selecione um plano para definir o limite.';
}
$recommended_plan_hint = '';
if($recommended_plan){
    $recommended_hint_parts = [];
    $recommended_value = floatval($recommended_plan['valor'] ?? 0);
    $recommended_limit = intval($recommended_plan['host_limit'] ?? 0);
    if($recommended_value > 0){
        $recommended_hint_parts[] = 'R$ ' . format_money($recommended_value);
    }
    if($recommended_limit > 0){
        $recommended_hint_parts[] = "{$recommended_limit} hosts";
    }
    $recommended_plan_hint = implode(' • ', $recommended_hint_parts);
}
$history_peak_percent = null;
$recommended_chip_title = $recommended_plan_label;
$recommended_chip_subtitle = $recommended_plan_hint;
if($recommended_plan_label && $history_peak_percent !== null && $history_peak_percent > 100){
    $overflow_pct = number_format($history_peak_percent - 100, 1, ',', '.');
    $recommended_chip_title = "Pico excedeu {$overflow_pct}%";
    $recommended_chip_subtitle = 'Considere ' . $recommended_plan_label;
    if($recommended_plan_hint){
        $recommended_chip_subtitle .= " ({$recommended_plan_hint})";
    }
} elseif(!$recommended_chip_subtitle && $recommended_plan_label){
    $recommended_chip_subtitle = 'Sugestão automática de upgrade';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>InfraStack – <?= htmlspecialchars($group_detail['name']) ?> · Detalhes</title>
<link rel="icon" type="image/svg+xml" href="/img/infrastack.svg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/css/style.css">
<link rel="stylesheet" href="/css/cards.css">
</head>
<body>
    <div class="topbar-brand invoice-top-brand">
        <a href="/">
            <img src="/img/infrastack.svg" alt="InfraStack">
            <span>InfraStack</span>
        </a>
    </div>
<div class="client-page-wrapper">
    <div class="client-back-link-wrapper">
        <a href="/index.php" class="client-back-link">← Voltar</a>
    </div>
    <div class="client-hero-card">
        <span class="hero-label">Grupo selecionado</span>
        <?php $invoice_url = "/client/invoice.php?server=" . urlencode($server_name) . "&group=" . urlencode($group_id); ?>
        <div class="hero-title-row">
            <h2><?= htmlspecialchars($group_detail['name']) ?></h2>
                <div class="hero-actions">
                    <button type="button" class="hero-action hero-profile-toggle" id="toggleProfilePanel">Detalhes do cliente</button>
                    <a href="<?= $invoice_url ?>" target="_blank" rel="noopener" class="hero-action hero-profile-toggle hero-pdf-toggle">PDF</a>
                    <a href="/client/billing_manage.php?server=<?= urlencode($server_name) ?>&group=<?= urlencode($group_id) ?>"
                        class="hero-action hero-profile-toggle hero-pdf-toggle hero-meg-faturar">Gestão cobrança</a>
                </div>
        </div>
    </div>
    <div class="client-profile-panel" id="clientProfilePanel">
        <div class="client-profile-grid">
            <div class="client-profile-grid-item">
                <span>Razão social</span>
                <strong><?= profile_field_display($client_profile['company']) ?></strong>
            </div>
            <div class="client-profile-grid-item">
                <span>CNPJ</span>
                <strong><?= profile_field_display($client_profile['cnpj']) ?></strong>
            </div>
            <div class="client-profile-grid-item">
                <span>Telefone</span>
                <strong><?= profile_field_display($client_profile['phone']) ?></strong>
            </div>
            <div class="client-profile-grid-item">
                <span>Email</span>
                <strong><?= profile_field_display($client_profile['email']) ?></strong>
            </div>
            <div class="client-profile-grid-item">
                <span>Responsável</span>
                <strong><?= profile_field_display($client_profile['responsavel']) ?></strong>
            </div>
            <div class="client-profile-grid-item">
                <span>Endereço</span>
                <strong><?= profile_field_display($client_profile['address']) ?></strong>
            </div>
            <div class="client-profile-grid-item">
                <span>Dia do ciclo</span>
                <strong><?= htmlspecialchars(str_pad($client_profile['billing_cycle_start_day'] ?? '1', 2, '0', STR_PAD_LEFT)) ?></strong>
            </div>
        </div>
        <div class="client-profile-note">
            <?= profile_field_display($client_profile['notes']) ?>
        </div>
        <div class="client-profile-footer">
            <button type="button" class="profile-edit-button" id="openProfileModal">Editar dados</button>
        </div>
    </div>

    <div class="client-detail-body">
        <div class="hostgroup-list-panel">
            <h3>Hostgroups acessíveis</h3>
            <?php if(!empty($rights)): ?>
                <ul class="hostgroup-list">
                    <?php foreach($rights as $right): ?>
                        <li class="hostgroup-item">
                            <div>
                                <strong><?= htmlspecialchars($right['name']) ?></strong>
                                <span class="hostgroup-permission"><?= $right['permission'] ?></span>
                            </div>
                            <span class="host-count"><?= $right['hosts_count'] ?> hosts</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="empty-state">Este grupo não possui hostgroups associados.</p>
            <?php endif; ?>
        </div>
        <div class="client-summary-card">
            <p class="summary-label">Resumo</p>
            <div class="summary-row">
                <span>Total Hostgroups</span>
                <strong><?= $total_hostgroups ?></strong>
            </div>
            <div class="summary-row">
                <span>Total Hosts</span>
                <strong><?= $total_hosts_access ?></strong>
            </div>
        </div>
    </div>
    <div class="billing-section">
        <div class="billing-head">
            <div>
                <h3>Revisão de cobrança</h3>
                <p class="billing-hosts-count">
                    <?= $total_hosts_access ?> host<?= $total_hosts_access === 1 ? '' : 's' ?> monitorado<?= $total_hosts_access === 1 ? '' : 's' ?> neste grupo.
                </p>
            </div>
        </div>
        <?php if(!empty($plan_options)): ?>
            <form method="post"
                action="?server=<?= urlencode($server_name) ?>&group=<?= urlencode($group_id) ?>"
                class="billing-form">
                <input type="hidden" name="action" value="save_plan">
                <div class="billing-grid">
                    <div class="billing-actions">
                        <div class="billing-actions__header">
                            <div>
                                <span class="billing-actions__eyebrow">Escolha um plano</span>
                                <p class="billing-actions__intro">Ajuste o limite contratado antes de faturar este grupo.</p>
                            </div>
                            <?php if($recommended_plan_label): ?>
                                <div class="billing-actions__chip">
                                    <strong><?= htmlspecialchars($recommended_chip_title) ?></strong>
                                    <?php if($recommended_chip_subtitle): ?>
                                        <small><?= htmlspecialchars($recommended_chip_subtitle) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="billing-actions__stat-grid">
                            <div class="billing-actions__stat">
                                <span>Hosts atuais</span>
                                <strong><?= $total_hosts_access ?></strong>
                                <small><?= $total_hosts_access === 1 ? '1 host monitorado' : "{$total_hosts_access} hosts monitorados" ?></small>
                            </div>
                            <div class="billing-actions__stat">
                                <span>Limite do plano</span>
                                <strong><?= htmlspecialchars($selected_limit_label) ?></strong>
                                <small><?= htmlspecialchars($plan_capacity_note) ?></small>
                            </div>
                            <div class="billing-actions__stat">
                                <span>Valor mensal</span>
                                <strong>R$ <?= format_money($display_plan_value) ?></strong>
                                <small>Extra R$ <?= format_money($extra_host_rate) ?>/host</small>
                            </div>
                        </div>
                        <span class="billing-actions__label">Atualize o plano selecionado</span>
                        <div class="plan-options-grid">
                            <?php foreach($plan_options as $plan): ?>
                                <?php
                                    $plan_limit = max(0, intval($plan['host_limit'] ?? 0));
                                    $plan_value = floatval($plan['valor'] ?? 0);
                                    $discount = intval($plan['desconto'] ?? 0);
                                    $is_selected_plan = $plan_limit === $selected_limit;
                                    $is_recommended_plan = $recommended_plan && intval($recommended_plan['host_limit'] ?? 0) === $plan_limit;
                                    $plan_name = $plan['name'] ?? "Plano {$plan_limit}";
                                    $per_host_label = ($plan_limit > 0 && $plan_value > 0)
                                        ? 'R$ ' . format_money($plan_value / max(1, $plan_limit)) . '/host'
                                        : 'Valor sob consulta';
                                ?>
                                <label class="plan-option-card <?= $is_selected_plan ? 'plan-option-card--selected' : '' ?> <?= $is_recommended_plan ? 'plan-option-card--recommended' : '' ?>">
                                    <input type="radio" name="plan_limit" value="<?= $plan_limit ?>" <?= $is_selected_plan ? 'checked' : '' ?>>
                                    <div class="plan-option-card__header">
                                        <span class="plan-option-card__name"><?= htmlspecialchars($plan_name) ?></span>
                                        <?php if($is_recommended_plan): ?>
                                            <span class="plan-option-card__badge">Recomendado</span>
                                        <?php endif; ?>
                                    </div>
                                    <strong class="plan-option-card__value">R$ <?= format_money($plan_value) ?></strong>
                                    <span class="plan-option-card__hosts"><?= $plan_limit > 0 ? "{$plan_limit} hosts" : 'Sem limite' ?></span>
                                    <span class="plan-option-card__note"><?= $per_host_label ?></span>
                                    <?php if($discount > 0): ?>
                                        <span class="plan-option-card__discount">-<?= $discount ?>% desconto</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="plan-options-actions">
                            <button type="submit" class="billing-button">Salvar plano selecionado</button>
                            <span class="plan-options-actions__help">Selecione um cartão e confirme para registrar.</span>
                        </div>
                        <?php if($display_over_hosts > 0): ?>
                            <p class="billing-note billing-actions__note">
                                Hosts extras: <?= $display_over_hosts ?> cobrado<?= $display_over_hosts === 1 ? '' : 's' ?> a R$ <?= format_money($extra_host_rate) ?> cada.
                            </p>
                        <?php else: ?>
                            <p class="billing-note billing-note--muted billing-actions__note">
                                Sem hosts extras no momento.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <p class="empty-state">Ainda não há planos cadastrados para o tipo de contrato deste servidor.</p>
        <?php endif; ?>
    </div>
    <?php
        $timezone = new DateTimeZone(date_default_timezone_get());
        $now = new DateTimeImmutable('now', $timezone);
        $billing_cycle_start_day = intval($client_profile['billing_cycle_start_day'] ?? 1);
        $billing_cycle_start_day = max(1, min(31, $billing_cycle_start_day));
        $cycle_year = intval($now->format('Y'));
        $cycle_month = intval($now->format('n'));
        if(intval($now->format('j')) < $billing_cycle_start_day){
            $cycle_month--;
            if($cycle_month < 1){
                $cycle_month = 12;
                $cycle_year--;
            }
        }
        $ref = new DateTimeImmutable(sprintf('%04d-%02d-01', $cycle_year, $cycle_month), $timezone);
        $cycle_day = min($billing_cycle_start_day, (int)$ref->format('t'));
        $cycle_start = (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $cycle_year, $cycle_month, $cycle_day), $timezone))->setTime(0, 0);
        $cycle_start_timestamp = $cycle_start->getTimestamp();
        $cutoffTimestamp = max($cycle_start_timestamp, $now->modify('-30 days')->getTimestamp());
    $history_display = array_values(array_filter(
        $history_entries,
        fn($entry) => intval($entry['timestamp'] ?? 0) >= $cutoffTimestamp
    ));
    $chart_values = array_column($history_display, 'hosts');
    $history_max_hosts = !empty($history_display) ? max($chart_values) : 0;
    $history_avg_hosts = !empty($history_display) ? round(array_sum($chart_values) / count($chart_values)) : 0;
    $history_peak_percent = $plan_limit_display > 0
        ? ($history_max_hosts > 0 ? ($history_max_hosts / max(1, $plan_limit_display)) * 100 : 0)
        : null;
    $history_peak_hosts_remaining = $plan_limit_display > 0
        ? max(0, $plan_limit_display - $history_max_hosts)
        : null;
    $history_peak_note = $plan_limit_display > 0
        ? $history_peak_hosts_remaining . ' host' . ($history_peak_hosts_remaining === 1 ? '' : 's') . ' restantes para o limite.'
        : 'Plano sem limite definido no momento.';
    $history_peak_overflow_percent = $history_peak_percent !== null
        ? max(0, $history_peak_percent - 100)
        : null;
    $chart_labels = array_map(function($entry) use ($timezone){
        $dt = (new DateTimeImmutable('@' . intval($entry['timestamp'] ?? 0)))->setTimezone($timezone);
        return $dt->format('d/m H:i');
    }, $history_display);
    ?>
    <div class="history-section">
        <div class="strategy-banner strategy-banner--single">
            <div class="strategy-card strategy-card--focused strategy-card--premium">
                <div class="strategy-card__header">
                    <span class="strategy-card__badge">Plano atual</span>
                    <h4>Fidelização &amp; Upsell</h4>
                </div>
                <p class="strategy-text">
                    Valorize a permanência no plano <?= htmlspecialchars($selected_plan_name) ?> por R$
                    <?= format_money($display_plan_value) ?> e destaque o upgrade antes de gastar R$
                    <?= format_money($extra_host_rate) ?> por host extra.
                </p>
                <div class="strategy-card__stats">
                    <div>
                        <span>Total contratual</span>
                        <strong><?= $plan_limit_display > 0 ? "{$plan_limit_display} hosts" : 'Sem limite definido' ?></strong>
                    </div>
                    <div>
                        <span>Valor extra</span>
                        <strong>R$ <?= format_money($extra_host_rate) ?> / host</strong>
                    </div>
                </div>
            </div>
        </div>
        <h3>Histórico diário de hosts (últimos 30 dias)</h3>
        <?php if(!empty($history_entries) && !empty($chart_labels)): ?>
            <div class="history-grid">
                <div class="history-chart-card">
                    <canvas id="historyChart" role="img" aria-label="Histórico diário de hosts"></canvas>
                </div>
                <div class="history-metrics-column">
                    <div class="history-metric-card">
                        <span class="history-metric-label">Pico máximo (30 dias)</span>
                        <?php if($plan_limit_display > 0): ?>
                            <strong><?= number_format($history_peak_percent, 1, ',', '.') ?>%</strong>
                            <small><?= number_format($history_max_hosts, 0, ',', '.') ?> hosts</small>
                            <div class="history-metric-progress history-metric-progress--overflow">
                                <div class="history-metric-progress-fill" style="width: <?= min(100, max(0, $history_peak_percent)) ?>%;"></div>
                                <?php if($history_peak_overflow_percent > 0): ?>
                                    <div class="history-metric-progress-overflow" style="width: <?= min(100, $history_peak_overflow_percent) ?>%;"></div>
                                <?php endif; ?>
                            </div>
                            <p class="history-metric-note"><?= $history_peak_note ?></p>
                        <?php else: ?>
                            <strong><?= number_format($history_max_hosts, 0, ',', '.') ?> hosts</strong>
                            <small>Maior valor registrado</small>
                        <?php endif; ?>
                    </div>
                    <div class="history-metric-card">
                        <span class="history-metric-label">Média de hosts</span>
                        <strong><?= number_format($history_avg_hosts, 0, ',', '.') ?> hosts</strong>
                        <small>Leituras dos últimos 30 dias</small>
                    </div>
                    <div class="history-metric-card history-metric-card--capacity">
                        <span class="history-metric-label">Capacidade atual</span>
                        <strong><?= number_format($limit_percent, 1, ',', '.') ?>%</strong>
                        <small><?= $plan_limit_display > 0 ? "{$plan_limit_display} hosts" : 'Plano sem limite' ?></small>
                        <div class="history-metric-progress history-metric-progress--overflow">
                            <div class="history-metric-progress-fill" style="width: <?= min(100, max(0, $limit_percent)) ?>%;"></div>
                            <?php if($limit_overflow_percent > 0): ?>
                                <div class="history-metric-progress-overflow" style="width: <?= min(100, $limit_overflow_percent) ?>%;"></div>
                            <?php endif; ?>
                        </div>
                        <p class="history-metric-note">
                            <?= $plan_limit_display > 0
                                ? max(0, $plan_limit_display - $used_hosts) . ' host' . (max(0, $plan_limit_display - $used_hosts) === 1 ? '' : 's') . ' restantes para o limite.'
                                : 'Plano sem limite definido no momento.'
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <p class="empty-state">Ainda não há registros históricos. Execute o coletor diário para começar o acompanhamento.</p>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="clientProfileModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Editar dados do cliente</h3>
            <button type="button" class="modal-close" id="closeProfileModal">&times;</button>
        </div>
        <form method="post" class="profile-form">
            <input type="hidden" name="action" value="save_profile">
            <label>
                <span>Razão social</span>
                <input type="text" name="company" value="<?= htmlspecialchars($client_profile['company']) ?>">
            </label>
            <label>
                <span>CNPJ</span>
                <input type="text" name="cnpj" value="<?= htmlspecialchars($client_profile['cnpj']) ?>">
            </label>
            <label>
                <span>Telefone</span>
                <input type="text" name="phone" value="<?= htmlspecialchars($client_profile['phone']) ?>">
            </label>
            <label>
                <span>Email</span>
                <input type="email" name="email" value="<?= htmlspecialchars($client_profile['email']) ?>">
            </label>
            <label>
                <span>Responsável</span>
                <input type="text" name="responsavel" value="<?= htmlspecialchars($client_profile['responsavel']) ?>">
            </label>
            <label>
                <span>Endereço</span>
                <input type="text" name="address" value="<?= htmlspecialchars($client_profile['address']) ?>">
            </label>
            <label>
                <span>Dia do ciclo de cobrança</span>
                <input type="number" name="billing_cycle_start_day" min="1" max="31"
                    value="<?= htmlspecialchars($client_profile['billing_cycle_start_day']) ?>">
            </label>
            <label>
                <span>Notas (opcional)</span>
                <textarea name="notes"><?= htmlspecialchars($client_profile['notes']) ?></textarea>
            </label>
            <button type="submit" class="modal-submit">Salvar alterações</button>
        </form>
    </div>
</div>
<div class="canvas" id="canvas"></div>

<?php if(!empty($chart_labels)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script>
    (() => {
        const ctx = document.getElementById('historyChart');
        if(!ctx) return;
        const labels = <?= json_encode($chart_labels) ?>;
        const data = <?= json_encode($chart_values) ?>;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Hosts',
                    data,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13,110,253,0.2)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#0d6efd'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#fff',
                        titleColor: '#111',
                        bodyColor: '#111',
                        borderColor: '#ccc',
                        borderWidth: 1
                    }
                }
            }
        });
    })();
    </script>
<?php endif; ?>

<script>
(function(){
    const planRadios = document.querySelectorAll('.plan-option-card input[type="radio"]');
    if(!planRadios.length) return;
    const planCards = document.querySelectorAll('.plan-option-card');
    const updateSelection = () => {
        planCards.forEach(card => card.classList.remove('plan-option-card--selected'));
        const checked = document.querySelector('.plan-option-card input[type="radio"]:checked');
        if(checked){
            const selectedCard = checked.closest('.plan-option-card');
            if(selectedCard){
                selectedCard.classList.add('plan-option-card--selected');
            }
        }
    };
    planRadios.forEach(radio => {
        radio.addEventListener('change', updateSelection);
    });
    planCards.forEach(card => {
        const radio = card.querySelector('input[type="radio"]');
        if(!radio) return;
        card.addEventListener('click', (event) => {
            if(event.target === radio) return;
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
    updateSelection();
})();
</script>

<script>
(function(){
    const panel = document.getElementById('clientProfilePanel');
    const toggle = document.getElementById('toggleProfilePanel');
    const modal = document.getElementById('clientProfileModal');
    const openModal = document.getElementById('openProfileModal');
    const closeModal = document.getElementById('closeProfileModal');
    if(toggle && panel){
        toggle.addEventListener('click', () => {
            const isOpen = panel.classList.toggle('client-profile-panel--open');
            toggle.textContent = isOpen ? 'Ocultar dados do cliente' : 'Mostrar dados do cliente';
        });
    }
    const closeOverlay = () => modal?.classList.remove('modal-overlay--visible');
    if(openModal && modal){
        openModal.addEventListener('click', () => modal.classList.add('modal-overlay--visible'));
        if(closeModal){
            closeModal.addEventListener('click', closeOverlay);
        }
        modal.addEventListener('click', (event) => {
            if(event.target === modal){
                closeOverlay();
            }
        });
    }
})();
</script>

</body>
</html>
