<?php
require_once __DIR__ . '/../functions.php';

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
$invoice_slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $group_detail['name'] ?? 'cliente');
$pdf_filename = sprintf('fatura_%s_%s.pdf', $invoice_slug, (new DateTime())->format('Ymd'));

$hostgroups = get_hostgroups($server_name);
$hostgroup_index = [];
foreach($hostgroups as $hg){
    $hostgroup_index[$hg['groupid']] = [
        'name' => $hg['name'],
        'hosts_count' => intval($hg['hosts'] ?? 0)
    ];
}

$rights = [];
foreach($group_detail['hostgroup_rights'] ?? [] as $right){
    $hg_id = $right['id'] ?? $right['hostgroupid'] ?? null;
    if(!$hg_id) continue;
    $rights[] = [
        'hostgroup_id' => $hg_id,
        'name' => $hostgroup_index[$hg_id]['name'] ?? ($right['name'] ?? 'Desconhecido'),
        'hosts_count' => $hostgroup_index[$hg_id]['hosts_count'] ?? 0,
        'permission' => [0=>'Nenhum',2=>'Leitura',3=>'Leitura-Escrita'][$right['permission']] ?? "Permissão {$right['permission']}"
    ];
}

$total_hostgroups = count($rights);
$total_hosts_access = array_sum(array_column($rights, 'hosts_count'));

$data_root = (isset($data_dir) && $data_dir ? $data_dir : (__DIR__ . '/../../data'));
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
$extra_host_rate = 9.5;
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
$billing_extra_rate = floatval($stored_billing['extra_host_rate'] ?? $extra_host_rate);
$display_revenue = 0;
$selected_plan_name = $stored_billing['selected_plan_name'] ?? ($selected_plan ? ($selected_plan['name'] ?? 'Plano n?o definido') : 'Plano n?o definido');
if($display_plan_value <= 0 && $selected_plan){
    $display_plan_value = floatval($selected_plan['valor'] ?? 0);
}
if($display_per_host_plan <= 0 && $selected_plan && intval($selected_plan['host_limit'] ?? 0) > 0){
    $display_per_host_plan = floatval($selected_plan['valor']) / intval($selected_plan['host_limit'] ?? 0);
}
$history_dir = "{$data_root}/history/hosts";
$history_file = "{$history_dir}/{$server_info['server_account']}_{$group_detail['usrgrpid']}.json";
$history_entries = [];
if(file_exists($history_file)){
    $history_entries = json_decode(file_get_contents($history_file), true) ?? [];
    usort($history_entries, fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));
}
$billing_history = $stored_billing['invoice_history'] ?? [];
$history_entry_id = $_GET['history_id'] ?? null;
$selected_history_entry = null;
$history_entry_status = null;
$history_entry_description = '';
$history_entry_due = '';
if($history_entry_id){
    foreach($billing_history as $record){
        if(($record['id'] ?? '') === $history_entry_id
            && ($record['server_account'] ?? '') === $server_info['server_account']
            && ($record['group_id'] ?? '') === $group_detail['usrgrpid']){
            $selected_history_entry = $record;
            $history_entry_status = $record['status'] ?? null;
            $history_entry_description = $record['description'] ?? '';
            $history_entry_due = isset($record['due_date']) ? (new DateTimeImmutable($record['due_date']))->format('d/m/Y') : '';
            break;
        }
    }
}
$billing_date = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i');

$profile_fields = ['company','cnpj','phone','email','responsavel','address','notes'];
$profiles_file = "{$data_root}/client_profiles.json";
$profiles_data = [];
if(file_exists($profiles_file)){
    $profiles_data = json_decode(file_get_contents($profiles_file), true) ?? [];
}
$profile_key = "{$server_info['server_account']}_{$group_detail['usrgrpid']}";
$default_profile = array_fill_keys($profile_fields, '');
$client_profile = array_merge($default_profile, $profiles_data[$profile_key] ?? []);
if(($client_profile['billing_cycle_start_day'] ?? '') === ''){
    $client_profile['billing_cycle_start_day'] = '1';
}
    $billing_cycle_day = max(1, min(31, intval($client_profile['billing_cycle_start_day'] ?? 1)));
    $timezone = new DateTimeZone('America/Sao_Paulo');
    if($selected_history_entry && isset($selected_history_entry['cycle_start'], $selected_history_entry['cycle_end'])){
        $cycle_start = (new DateTimeImmutable($selected_history_entry['cycle_start'], $timezone))->setTime(0, 0);
        $cycle_end = (new DateTimeImmutable($selected_history_entry['cycle_end'], $timezone))->setTime(23, 59, 59);
        $cycle_day_label = str_pad((string)$billing_cycle_day, 2, '0', STR_PAD_LEFT);
    } else {
        $cycle_now = new DateTimeImmutable('now', $timezone);
        $cycle_year = intval($cycle_now->format('Y'));
        $cycle_month = intval($cycle_now->format('n'));
        if(intval($cycle_now->format('j')) < $billing_cycle_day){
            $cycle_month--;
            if($cycle_month < 1){
                $cycle_month = 12;
                $cycle_year--;
            }
        }
        $cycle_month_ref = new DateTimeImmutable(sprintf('%04d-%02d-01', $cycle_year, $cycle_month), $timezone);
        $cycle_day_valid = min($billing_cycle_day, (int)$cycle_month_ref->format('t'));
        $cycle_start = (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $cycle_year, $cycle_month, $cycle_day_valid), $timezone))->setTime(0, 0);
        $next_cycle_start = $cycle_start->modify('+1 month');
        $cycle_end = $next_cycle_start->modify('-1 day');
        $cycle_day_label = str_pad((string)$billing_cycle_day, 2, '0', STR_PAD_LEFT);
    }
    $cycle_start_label = $cycle_start->format('d/m/Y');
    $cycle_end_label = $cycle_end->format('d/m/Y');

    $cycle_start_ts = $cycle_start->getTimestamp();
    $cycle_end_ts = $cycle_end->getTimestamp();
    $history_display = array_values(array_filter(
        $history_entries,
        fn($entry) => ($entry['timestamp'] ?? 0) >= $cycle_start_ts && ($entry['timestamp'] ?? 0) <= $cycle_end_ts
    ));
    $chart_labels = [];
    $chart_values = [];
    $tz = new DateTimeZone('America/Sao_Paulo');
    foreach($history_display as $entry){
        $timestamp = intval($entry['timestamp'] ?? 0);
        if($timestamp > 0){
            $dt = (new DateTime('@' . $timestamp))->setTimezone($tz);
            $chart_labels[] = $dt->format('d/m');
        } else {
            $chart_labels[] = $entry['date'] ?? '';
        }
        $chart_values[] = intval($entry['hosts'] ?? 0);
    }

    $cycle_peak_hosts = !empty($chart_values) ? max($chart_values) : 0;
    if($selected_history_entry){
        $entry_hosts_peak = intval($selected_history_entry['hosts_peak'] ?? 0);
        if($entry_hosts_peak > 0){
            $cycle_peak_hosts = $entry_hosts_peak;
        }
    }
    $limit_percent = $selected_limit > 0 ? min(100, ($cycle_peak_hosts / $selected_limit) * 100) : 0;
    $limit_remaining = $selected_limit > 0 ? max(0, $selected_limit - $cycle_peak_hosts) : 0;
    $display_over_hosts = $selected_limit > 0 ? max(0, $cycle_peak_hosts - $selected_limit) : 0;
    $display_over_value = $display_over_hosts * $billing_extra_rate;
    if($selected_history_entry && isset($selected_history_entry['amount'])){
        $display_revenue = floatval($selected_history_entry['amount']);
    } else {
        $display_revenue = $display_plan_value + $display_over_value;
    }

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>InfraStack – <?= htmlspecialchars($group_detail['name']) ?> · Fatura</title>
<link rel="icon" type="image/svg+xml" href="/img/infrastack.svg">
<link rel="stylesheet" href="/css/style.css">
<link rel="stylesheet" href="/css/cards.css">
</head>
<body class="invoice-page">
    <div class="topbar-brand invoice-top-brand">
        <a href="/">
            <img src="/img/infrastack.svg" alt="InfraStack">
            <span>InfraStack</span>
        </a>
    </div>
    <div class="invoice-shell">
    <header class="invoice-header">
        <div>
            <p class="invoice-label">Fatura digital</p>
            <h1><?= htmlspecialchars($group_detail['name']) ?></h1>
            <p class="invoice-subtitle"><?= htmlspecialchars($server_info['name']) ?> — Grupo <?= htmlspecialchars($group_detail['usrgrpid']) ?></p>
        </div>
        <div class="invoice-meta">
            <div>
                <span>Data de emissão</span>
                <strong><?= $billing_date ?></strong>
            </div>
            <div>
                <span>Plano atual</span>
                <strong><?= htmlspecialchars($selected_plan_name) ?></strong>
            </div>
            </div>
        </header>
        <?php if($selected_history_entry): ?>
            <div class="invoice-status-banner">
                <span>Status: <?= htmlspecialchars($history_entry_status ?? 'Pendente') ?></span>
                <?php if($history_entry_due): ?>
                    <span>Vencimento: <?= htmlspecialchars($history_entry_due) ?></span>
                <?php endif; ?>
                <?php if($history_entry_description): ?>
                    <span><?= htmlspecialchars($history_entry_description) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <div class="invoice-amount-banner invoice-amount-banner--compact">
        <div class="invoice-amount-primary">
            <p class="invoice-label">Total</p>
            <h2>R$ <?= format_money($display_revenue) ?></h2>
        </div>
        <div class="invoice-amount-highlight">
            <span><?= $display_over_hosts ?> host<?= $display_over_hosts === 1 ? '' : 's' ?> extras</span>
        </div>
    </div>
    <div class="print-actions">
        <button type="button" class="print-button print-button--outline" id="downloadPdf">Gerar PDF</button>
    </div>
    <section class="invoice-section">
        <header class="section-header">
            <h2>Dados do cliente</h2>
        </header>
        <div class="client-profile-grid">
            <?php foreach(['company'=>'Razão social','cnpj'=>'CNPJ','phone'=>'Telefone','email'=>'E-mail','responsavel'=>'Responsável','address'=>'Endereço'] as $key => $label): ?>
                <div class="client-profile-grid-item">
                    <span><?= $label ?></span>
                    <strong><?= profile_field_display($client_profile[$key] ?? '') ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if(trim($client_profile['notes'] ?? '') !== ''): ?>
            <p class="client-profile-note"><?= profile_field_display($client_profile['notes']) ?></p>
        <?php endif; ?>
    </section>
    <section class="invoice-section invoice-summary">
        <header class="section-header">
            <h2>Resumo financeiro</h2>
        </header>
        <div class="summary-grid">
            <div>
                <span>Hosts monitorados (pico do ciclo)</span>
                <strong><?= $cycle_peak_hosts ?></strong>
            </div>
            <div>
                <span>Limite do plano</span>
                <strong><?= $selected_limit > 0 ? "{$selected_limit} hosts" : 'Sem limite definido' ?></strong>
            </div>
            <div>
                <span>Valor do plano</span>
                <strong>R$ <?= format_money($display_plan_value) ?></strong>
            </div>
            <div>
                <span>Hosts extras</span>
                <strong><?= $display_over_hosts ?> (R$ <?= format_money($display_over_value) ?>)</strong>
            </div>
            <div>
                <span>Valor por host</span>
                <strong>R$ <?= format_money($display_per_host_plan) ?></strong>
            </div>
        </div>
        <div class="invoice-cycle-panel">
            <div class="invoice-cycle-card">
                <span>Dia do ciclo</span>
                <strong><?= $cycle_day_label ?> / mês</strong>
                <small>Ciclo vigente de <?= $cycle_start_label ?> até <?= $cycle_end_label ?>.</small>
            </div>
            <div class="invoice-cycle-card">
                <span>Capacidade utilizada</span>
                <strong><?= number_format($limit_percent, 1, ',', '.') ?>%</strong>
                <div class="invoice-cycle-progress">
                    <div class="invoice-cycle-progress-fill" style="width: <?= min(100, max(0, $limit_percent)) ?>%;"></div>
                </div>
                <small><?= $selected_limit > 0 ? "{$limit_remaining} hosts restantes para o limite." : 'Sem limite definido no momento.' ?></small>
            </div>
        </div>
    </section>
    <section class="invoice-section">
        <header class="section-header">
            <h2>Histórico diário de hosts (30 dias)</h2>
        </header>
        <?php if(!empty($chart_values)): ?>
            <div class="invoice-chart-card">
                <canvas id="invoiceHistoryChart" role="img" aria-label="Histórico diário de hosts"></canvas>
            </div>
        <?php else: ?>
            <p class="empty-state">Ainda não há registros históricos. Execute o coletor diário para ver a evolução.</p>
        <?php endif; ?>
    </section>
    <section class="invoice-section">
        <header class="section-header">
            <h2>Hostgroups acessíveis</h2>
        </header>
        <?php if(!empty($rights)): ?>
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Hostgroup</th>
                        <th>Hosts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($rights as $right): ?>
                        <tr>
                            <td><?= htmlspecialchars($right['name']) ?></td>
                            <td><?= htmlspecialchars($right['hosts_count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="empty-state">Nenhum hostgroup associado a este grupo.</p>
        <?php endif; ?>
    </section>
    <footer class="invoice-footer">
        <p>Este documento é uma representação digital da cobrança. Para gerar o boleto oficial, use o plano selecionado acima e gere o PDF via impressão.</p>
    </footer>
</div>
<?php if(!empty($chart_values)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script>
    (() => {
        const ctx = document.getElementById('invoiceHistoryChart');
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
                        grid: { display: false },
                        ticks: { maxRotation: 0, autoSkip: true }
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
<script>
(() => {
    const downloadBtn = document.getElementById('downloadPdf');
    if(!downloadBtn) return;
    downloadBtn.addEventListener('click', () => {
        const invoice = document.querySelector('.invoice-shell');
        if(!invoice) return;
        const filename = <?= json_encode($pdf_filename) ?>;
        const options = {
            margin: [10, 10, 10, 10],
            filename,
            html2canvas: {
                scale: 1,
                backgroundColor: '#ffffff'
            },
            jsPDF: {
                unit: 'mm',
                format: 'a4',
                orientation: 'portrait'
            }
        };
        const originalWidth = invoice.style.width;
        const originalMaxWidth = invoice.style.maxWidth;
        invoice.style.width = '190mm';
        invoice.style.maxWidth = '190mm';
        downloadBtn.disabled = true;
        html2pdf().set(options).from(invoice).save().finally(() => {
            invoice.style.width = originalWidth;
            invoice.style.maxWidth = originalMaxWidth;
            downloadBtn.disabled = false;
        });
    });
})();
</script>
</body>
</html>
