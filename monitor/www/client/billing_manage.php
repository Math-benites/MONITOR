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

function filter_invoice_history(array $records, string $status_filter, string $month_filter, string $search_query, DateTimeZone $timezone): array {
    $status_filter_normalized = strtolower($status_filter);
    $search_filter = mb_strtolower(trim($search_query));
    $month_filter = trim($month_filter);
    return array_values(array_filter($records, function($entry) use ($status_filter_normalized, $month_filter, $search_filter, $timezone){
        $entry_status = strtolower($entry['status'] ?? '');
        if($status_filter_normalized && $status_filter_normalized !== 'all' && $entry_status !== strtolower($status_filter_normalized)){
            return false;
        }
        if($month_filter !== ''){
            try {
                $due = new DateTimeImmutable($entry['due_date'] ?? 'now', $timezone);
            } catch(Exception $e){
                return false;
            }
            if($due->format('Y-m') !== $month_filter){
                return false;
            }
        }
        if($search_filter !== ''){
            $haystack = mb_strtolower(($entry['description'] ?? '') . ' ' . ($entry['id'] ?? ''));
            if(strpos($haystack, $search_filter) === false){
                return false;
            }
        }
        return true;
    }));
}

$server_code = $server_info['server_account'];
$current_history = array_values(array_filter(
    $invoice_history,
    fn($entry) => ($entry['server_account'] ?? '') === $server_code && ($entry['group_id'] ?? '') === $group_detail['usrgrpid']
));
usort($current_history, fn($a, $b)=>strcmp($b['issued_at'] ?? '', $a['issued_at'] ?? ''));

$allowed_status_filters = ['all','Pendente','Pago','Cancelado'];
$status_filter = $_GET['status_filter'] ?? 'all';
if(!in_array($status_filter, $allowed_status_filters, true)){
    $status_filter = 'all';
}
$month_filter = $_GET['month_filter'] ?? '';
$search_query = trim($_GET['search_query'] ?? '');

$filtered_history = filter_invoice_history($current_history, $status_filter, $month_filter, $search_query, $timezone);

$redirect_params = [
    'server' => $server_name,
    'group' => $group_id
];
if($status_filter && $status_filter !== 'all'){
    $redirect_params['status_filter'] = $status_filter;
}
if($month_filter){
    $redirect_params['month_filter'] = $month_filter;
}
if($search_query){
    $redirect_params['search_query'] = $search_query;
}
$redirect_url = "/client/billing_manage.php?" . http_build_query($redirect_params);

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
            'cycle_end' => $cycle_end->format('c'),
            'paid_at' => null,
            'paid_amount' => 0
        ];
        $invoice_history[] = $entry;
        $current_history = array_values(array_filter($invoice_history, fn($entry) => ($entry['server_account'] ?? '') === $server_code && ($entry['group_id'] ?? '') === $group_detail['usrgrpid']));
        usort($current_history, fn($a,$b)=>strcmp($b['issued_at'] ?? '', $a['issued_at'] ?? ''));
        if(!is_dir(dirname($history_file_path))){
            mkdir(dirname($history_file_path), 0755, true);
        }
        file_put_contents($history_file_path, json_encode($invoice_history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $invoice_link = "/client/invoice.php?server=" . urlencode($server_name) . "&group=" . urlencode($group_id) . "&history_id=" . urlencode($entry['id']);
        add_toast('Boleto emitido com sucesso. <a href="' . htmlspecialchars($invoice_link, ENT_QUOTES) . '" target="_blank" rel="noopener">Abrir boleto</a>.', 'success');
    } elseif($action === 'update_status'){
        $id = $_POST['record_id'] ?? '';
        $newStatus = $_POST['status'] ?? '';
        foreach($invoice_history as &$record){
            if($record['id'] === $id){
                $record['status'] = $newStatus;
                if(strcasecmp($newStatus, 'Pago') === 0){
                    $record['paid_at'] = (new DateTimeImmutable('now', $timezone))->format('c');
                    $record['paid_amount'] = $record['amount'] ?? 0;
                } else {
                    $record['paid_at'] = null;
                    $record['paid_amount'] = 0;
                }
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
        add_toast('Status atualizado para ' . htmlspecialchars($newStatus), 'success');
    } elseif($action === 'duplicate_invoice'){
        $id = $_POST['record_id'] ?? '';
        $record = null;
        foreach($current_history as $entryRecord){
            if(($entryRecord['id'] ?? '') === $id){
                $record = $entryRecord;
                break;
            }
        }
        if($record){
            $_SESSION['billing_prefill'] = [
                'amount' => number_format($record['amount'] ?? 0, 2, '.', ''),
                'due_date' => (new DateTimeImmutable($record['due_date'] ?? 'now', $timezone))->format('Y-m-d'),
                'description' => $record['description'] ?? ''
            ];
            add_toast('Cobrança duplicada. Ajuste os campos e clique em Emitir.', 'info');
        } else {
            add_toast('Não foi possível localizar a cobrança para duplicar.', 'error');
        }
    } elseif($action === 'mark_all_paid'){
        $bulk_status = $_POST['status_filter'] ?? 'all';
        $bulk_month = $_POST['month_filter'] ?? '';
        $bulk_search = trim($_POST['search_query'] ?? '');
        $subset = filter_invoice_history($current_history, $bulk_status, $bulk_month, $bulk_search, $timezone);
        $subset_ids = array_column($subset, 'id');
        $id_map = array_fill_keys($subset_ids, true);
        $updated = 0;
        $nowIso = (new DateTimeImmutable('now', $timezone))->format('c');
        foreach($invoice_history as &$record){
            if(isset($id_map[$record['id']])){
                $record['status'] = 'Pago';
                $record['paid_at'] = $nowIso;
                $record['paid_amount'] = $record['amount'] ?? 0;
                $updated++;
            }
        }
        unset($record);
        if($updated > 0){
            if(!is_dir(dirname($history_file_path))){
                mkdir(dirname($history_file_path), 0755, true);
            }
            file_put_contents($history_file_path, json_encode($invoice_history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            add_toast("{$updated} cobranç" . ($updated === 1 ? 'a' : 'as') . " marcad" . ($updated === 1 ? 'a' : 'as') . " como paga.", 'success');
        } else {
            add_toast('Nenhuma cobrança foi atualizada com os filtros atuais.', 'info');
        }
    } elseif($action === 'export_csv'){
        $export_status = $_POST['status_filter'] ?? 'all';
        $export_month = $_POST['month_filter'] ?? '';
        $export_search = trim($_POST['search_query'] ?? '');
        $export_data = filter_invoice_history($current_history, $export_status, $export_month, $export_search, $timezone);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="billing_' . $group_detail['usrgrpid'] . '_' . date('Ymd_His') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID','Emissão','Vencimento','Valor','Status','Descrição','Hosts pico']);
        foreach($export_data as $row){
            $issued = new DateTimeImmutable($row['issued_at'] ?? 'now', $timezone);
            $due = new DateTimeImmutable($row['due_date'] ?? 'now', $timezone);
            fputcsv($output, [
                $row['id'] ?? '',
                $issued->format('d/m/Y H:i'),
                $due->format('d/m/Y'),
                number_format($row['amount'] ?? 0, 2, ',', '.'),
                $row['status'] ?? '',
                $row['description'] ?? '',
                $row['hosts_peak'] ?? ''
            ]);
        }
        fclose($output);
        exit;
    }
    header("Location: $redirect_url");
    exit;
}

$prefill_state = $_SESSION['billing_prefill'] ?? [];
unset($_SESSION['billing_prefill']);
$amount_input_value = $prefill_state['amount'] ?? number_format($display_revenue, 2, '.', '');
$due_input_value = $prefill_state['due_date'] ?? $cycle_end->format('Y-m-d');
$description_input_value = $prefill_state['description'] ?? "Cobrança referente ao ciclo {$billing_cycle_day}";
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
    <script defer src="/js/toasts.js"></script>
</head>
<body>
    <?php render_toasts(); ?>
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
                            <input type="number" step="0.01" name="amount" value="<?= htmlspecialchars($amount_input_value) ?>" required>
                        </label>
                    </div>
                    <div class="billing-grid-item">
                        <label>
                            <span>Data de vencimento</span>
                            <input type="date" name="due_date" value="<?= htmlspecialchars($due_input_value) ?>">
                        </label>
                    </div>
                    <div class="billing-grid-item">
                        <label>
                            <span>Descrição</span>
                            <input type="text" name="description" value="<?= htmlspecialchars($description_input_value) ?>">
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
            <form method="get" class="billing-filter-form">
                <input type="hidden" name="server" value="<?= htmlspecialchars($server_name) ?>">
                <input type="hidden" name="group" value="<?= htmlspecialchars($group_id) ?>">
                <label>
                    <span>Status</span>
                    <select name="status_filter">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Todas</option>
                        <?php foreach(['Pendente','Pago','Cancelado'] as $statusOption): ?>
                            <option value="<?= $statusOption ?>" <?= $status_filter === $statusOption ? 'selected' : '' ?>><?= $statusOption ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Mês de vencimento</span>
                    <input type="month" name="month_filter" value="<?= htmlspecialchars($month_filter) ?>">
                </label>
                <label class="billing-filter-search">
                    <span>Buscar</span>
                    <input type="text" name="search_query" placeholder="Descrição ou ID" value="<?= htmlspecialchars($search_query) ?>">
                </label>
                <div class="billing-filter-buttons">
                    <button type="submit" class="print-button print-button--outline">Filtrar</button>
                    <button type="button" class="print-button print-button--outline filter-reset-link" onclick="window.location.href='/client/billing_manage.php?server=<?= urlencode($server_name) ?>&group=<?= urlencode($group_id) ?>'">Limpar</button>
                </div>
            </form>
            <div class="billing-filter-actions">
                <form method="post" class="billing-inline-form">
                    <input type="hidden" name="action" value="mark_all_paid">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status_filter) ?>">
                    <input type="hidden" name="month_filter" value="<?= htmlspecialchars($month_filter) ?>">
                    <input type="hidden" name="search_query" value="<?= htmlspecialchars($search_query) ?>">
                    <button type="submit" class="print-button print-button--secondary">Marcar exibidos como pagos</button>
                </form>
                <form method="post" class="billing-inline-form">
                    <input type="hidden" name="action" value="export_csv">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status_filter) ?>">
                    <input type="hidden" name="month_filter" value="<?= htmlspecialchars($month_filter) ?>">
                    <input type="hidden" name="search_query" value="<?= htmlspecialchars($search_query) ?>">
                    <button type="submit" class="print-button print-button--outline">Exportar CSV</button>
                </form>
            </div>
            <?php if(!empty($filtered_history)): ?>
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Emissão</th>
                            <th>Vencimento</th>
                            <th>Valor</th>
                            <th>Valor recebido</th>
                            <th>Status</th>
                            <th>Atraso (dias)</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($filtered_history as $entry): ?>
                            <?php
                                $entry_status = $entry['status'] ?? 'Pendente';
                                $status_class = 'invoice-status-badge--pending';
                                if(strcasecmp($entry_status, 'Pago') === 0){
                                    $status_class = 'invoice-status-badge--paid';
                                } elseif(strcasecmp($entry_status, 'Cancelado') === 0){
                                    $status_class = 'invoice-status-badge--cancelled';
                                }
                                $amount_fmt = number_format($entry['amount'] ?? 0, 2, ',', '.');
                                $received_fmt = null;
                                if(strcasecmp($entry_status, 'Pago') === 0){
                                    $received_fmt = number_format($entry['paid_amount'] ?? ($entry['amount'] ?? 0), 2, ',', '.');
                                }
                                $issued_at = new DateTimeImmutable($entry['issued_at'] ?? 'now', $timezone);
                                $due_at = new DateTimeImmutable($entry['due_date'] ?? 'now', $timezone);
                                $now_ref = new DateTimeImmutable('now', $timezone);
                                $overdue_days = 0;
                                if(strcasecmp($entry_status, 'Pago') === 0 && !empty($entry['paid_at'])){
                                    $paid_at = new DateTimeImmutable($entry['paid_at'], $timezone);
                                    if($paid_at > $due_at){
                                        $overdue_days = $paid_at->diff($due_at)->days;
                                    }
                                } elseif(strcasecmp($entry_status, 'Pago') !== 0 && $now_ref > $due_at){
                                    $overdue_days = $now_ref->diff($due_at)->days;
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($issued_at->format('d/m/Y H:i')) ?></td>
                                <td><?= htmlspecialchars($due_at->format('d/m/Y')) ?></td>
                                <td>R$ <?= $amount_fmt ?></td>
                                <td><?= $received_fmt !== null ? 'R$ ' . $received_fmt : '—' ?></td>
                                <td><span class="invoice-status-badge <?= $status_class ?>"><?= htmlspecialchars($entry_status) ?></span></td>
                                <td><?= $overdue_days ?></td>
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
                                        <button type="submit" class="print-button">Atualizar</button>
                                    </form>
                                    <form method="post" class="invoice-status-form">
                                        <input type="hidden" name="action" value="duplicate_invoice">
                                        <input type="hidden" name="record_id" value="<?= htmlspecialchars($entry['id']) ?>">
                                        <button type="submit" class="print-button">Duplicar</button>
                                    </form>
                                    <a href="/client/invoice.php?server=<?= urlencode($server_name) ?>&group=<?= urlencode($group_id) ?>&history_id=<?= urlencode($entry['id']) ?>"
                                        class="print-button print-button--link">Boleto</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty-state">Nenhuma cobrança encontrada com os filtros atuais.</p>
            <?php endif; ?>
        </section>
</div>
</div>
</body>
</html>
