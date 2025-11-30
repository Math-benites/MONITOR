<?php
require_once __DIR__ . '/../functions.php';

$server_name = $_GET['server'] ?? $servers[0]['name'] ?? null;
$server_info = get_server($server_name);
if(!$server_info) die("Servidor '{$server_name}' não encontrado.");

$group_id = $_GET['group'] ?? null;
if(!$group_id) die("Grupo de usuários não especificado.");

$group_detail = get_usergroup_detail($server_name, $group_id);
if(!$group_detail) die("Grupo de usuários não encontrado.");

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
$recommended_plan = $plan_options[0] ?? null;
$selected_plan = $recommended_plan;
$selected_limit = $selected_plan ? intval($selected_plan['host_limit'] ?? 0) : 0;
$extra_host_rate = 9.5;

$profiles_file = "{$data_root}/client_profiles.json";
$profiles_data = [];
if(file_exists($profiles_file)){
    $profiles_data = json_decode(file_get_contents($profiles_file), true) ?? [];
}
$profile_key = "{$server_info['server_account']}_{$group_detail['usrgrpid']}";
$default_profile = array_fill_keys(['company','cnpj','phone','email','responsavel','address','notes','billing_cycle_start_day'], '');
$client_profile = array_merge($default_profile, $profiles_data[$profile_key] ?? []);

$history_dir = "{$data_root}/history/hosts";
$history_file = "{$history_dir}/{$server_info['server_account']}_{$group_detail['usrgrpid']}.json";
$history_entries = [];
if(file_exists($history_file)){
    $history_entries = json_decode(file_get_contents($history_file), true) ?? [];
    usort($history_entries, fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));
}

$billing_cycle_day = max(1, min(31, intval($client_profile['billing_cycle_start_day'] ?? 1)));
$timezone = new DateTimeZone('America/Sao_Paulo');
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
$cycle_start_label = $cycle_start->format('d/m/Y');
$cycle_end_label = $cycle_end->format('d/m/Y');

$cycle_start_ts = $cycle_start->getTimestamp();
$cycle_end_ts = $cycle_end->getTimestamp();
$history_cycle = array_values(array_filter(
    $history_entries,
    fn($entry) => ($entry['timestamp'] ?? 0) >= $cycle_start_ts && ($entry['timestamp'] ?? 0) <= $cycle_end_ts
));
$cycle_peak_hosts = !empty($history_cycle) ? max(array_map(fn($entry)=> intval($entry['hosts'] ?? 0), $history_cycle)) : 0;
$cycle_peak_hosts = max($cycle_peak_hosts, 0);
$limit_percent = $selected_limit > 0 ? min(100, ($cycle_peak_hosts / $selected_limit) * 100) : 0;
$display_over_hosts = $selected_limit > 0 ? max(0, $cycle_peak_hosts - $selected_limit) : 0;
$display_over_value = $display_over_hosts * $extra_host_rate;
$display_revenue = (float)($selected_plan['valor'] ?? 0) + $display_over_value;

$history_file_path = "{$data_root}/billing/history/invoices.json";
$invoice_history = [];
if(file_exists($history_file_path)){
    $invoice_history = json_decode(file_get_contents($history_file_path), true) ?? [];
}
$server_code = $server_info['server_account'];
$current_history = array_values(array_filter(
    $invoice_history,
    fn($entry) => ($entry['server_account'] ?? '') === $server_code && ($entry['group_id'] ?? '') === $group_detail['usrgrpid']
));
usort($current_history, fn($a, $b)=>strcmp($b['issued_at'] ?? '', $a['issued_at'] ?? ''));

$redirect_url = "/client/billing_manage.php?server=".urlencode($server_name)."&group=".urlencode($group_id);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';
    if($action === 'emit_invoice'){
        $due_date = trim($_POST['due_date'] ?? '');
        $due_date_obj = $due_date ? new DateTimeImmutable($due_date, $timezone) : $cycle_end;
        $amount = floatval($_POST['amount'] ?? $display_revenue);
        $entry = [
            'id' => bin2hex(random_bytes(6)),
            'server_account' => $server_code,
            'group_id' => $group_detail['usrgrpid'],
            'amount' => $amount,
            'status' => 'Pendente',
            'issued_at' => (new DateTimeImmutable('now', $timezone))->format('c'),
            'due_date' => $due_date_obj->format('c'),
            'description' => trim($_POST['description'] ?? 'Cobrança mensal'),
            'hosts_peak' => $cycle_peak_hosts,
            'plan_name' => $selected_plan['name'] ?? 'Plano',
            'cycle_start' => $cycle_start->format('c'),
            'cycle_end' => $cycle_end->format('c')
        ];
        $invoice_history[] = $entry;
        $current_history = array_values(array_filter($invoice_history, fn($entry) => ($entry['server_account'] ?? '') === $server_code && ($entry['group_id'] ?? '') === $group_detail['usrgrpid']));
        usort($current_history, fn($a,$b)=>strcmp($b['issued_at'] ?? '', $a['issued_at'] ?? ''));
        if(!is_dir(dirname($history_file_path))){
            mkdir(dirname($history_file_path), 0755, true);
        }
        file_put_contents($history_file_path, json_encode($invoice_history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    } elseif($action === 'update_status'){
        $id = $_POST['record_id'] ?? '';
        $newStatus = $_POST['status'] ?? '';
        foreach($invoice_history as &$record){
            if($record['id'] === $id){
                $record['status'] = $newStatus;
                break;
            }
        }
        unset($record);
        if(!is_dir(dirname($history_file_path))){
            mkdir(dirname($history_file_path), 0755, true);
        }
        file_put_contents($history_file_path, json_encode($invoice_history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $current_history = array_values(array_filter($invoice_history, fn($entry) => ($entry['server_account'] ?? '') === $server_code && ($entry['group_id'] ?? '') === $group_detail['usrgrpid']));
        usort($current_history, fn($a,$b)=>strcmp($b['issued_at'] ?? '', $a['issued_at'] ?? ''));
    }
    header("Location: $redirect_url");
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>InfraStack – <?= htmlspecialchars($group_detail['name']) ?> · Gestão de cobrança</title>
    <link rel="icon" type="image/svg+xml" href="/img/infrastack.svg">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/cards.css">
</head>
<body>
    <div class="billing-page-shell">
        <div class="client-back-link-wrapper">
            <a href="/client/client.php?server=<?= urlencode($server_name) ?>&group=<?= urlencode($group_id) ?>" class="client-back-link">← Voltar</a>
        </div>
        <div class="invoice-shell">
        <header class="invoice-header">
            <div>
                <p class="invoice-label">Gestão de cobrança</p>
                <h1><?= htmlspecialchars($group_detail['name']) ?></h1>
                <p class="invoice-subtitle">Emita boletos e controles de status</p>
            </div>
            <div class="invoice-meta">
                <div>
                    <span>Ciclo corrente</span>
                    <strong><?= $cycle_start_label ?> – <?= $cycle_end_label ?></strong>
                </div>
                <div>
                    <span>Pico verificado</span>
                    <strong><?= $cycle_peak_hosts ?> hosts</strong>
                </div>
            </div>
        </header>
        <section class="invoice-section">
            <header class="section-header">
                <h2>Emitir boleto</h2>
            </header>
            <form method="post" class="billing-form">
                <input type="hidden" name="action" value="emit_invoice">
                <div class="billing-grid">
                    <div class="billing-grid-item">
                        <label>
                            <span>Valor (R$)</span>
                            <input type="number" step="0.01" name="amount" value="<?= htmlspecialchars(number_format($display_revenue, 2, '.', '')) ?>" required>
                        </label>
                    </div>
                    <div class="billing-grid-item">
                        <label>
                            <span>Data de vencimento</span>
                            <input type="date" name="due_date" value="<?= htmlspecialchars($cycle_end->format('Y-m-d')) ?>">
                        </label>
                    </div>
                    <div class="billing-grid-item">
                        <label>
                            <span>Descrição</span>
                            <input type="text" name="description" value="Cobrança referente ao ciclo <?= $billing_cycle_day ?>">
                        </label>
                    </div>
                </div>
                <div class="print-actions">
                    <button type="submit" class="print-button">Emitir boleto</button>
                </div>
            </form>
        </section>
        <section class="invoice-section">
            <header class="section-header">
                <h2>Histórico de cobranças</h2>
            </header>
            <?php if(!empty($current_history)): ?>
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Emissão</th>
                            <th>Vencimento</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($current_history as $entry): ?>
                            <tr>
                                <td><?= htmlspecialchars((new DateTimeImmutable($entry['issued_at']))->format('d/m/Y H:i')) ?></td>
                                <td><?= htmlspecialchars((new DateTimeImmutable($entry['due_date']))->format('d/m/Y')) ?></td>
                                <td>R$ <?= number_format($entry['amount'], 2, ',', '.') ?></td>
                                <td><?= htmlspecialchars($entry['status']) ?></td>
                                <td class="invoice-table-actions">
                                    <form method="post" class="invoice-status-form">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="record_id" value="<?= htmlspecialchars($entry['id']) ?>">
                                        <select name="status">
                                            <?php foreach(['Pendente','Pago','Cancelado'] as $statusOption): ?>
                                                <option value="<?= $statusOption ?>" <?= $statusOption === ($entry['status'] ?? '') ? 'selected' : '' ?>>
                                                    <?= $statusOption ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="print-button print-button--outline">Atualizar</button>
                                    </form>
                                    <a href="/client/invoice.php?server=<?= urlencode($server_name) ?>&group=<?= urlencode($group_id) ?>&history_id=<?= urlencode($entry['id']) ?>"
                                        class="print-button print-button--outline">Boleto</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty-state">Nenhuma cobrança emitida para este ciclo.</p>
            <?php endif; ?>
        </section>
</div>
</div>
</body>
</html>
