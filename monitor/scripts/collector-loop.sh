#!/usr/bin/env bash
set -euo pipefail

LOG_PREFIX="[history-collector]"
CONFIG_FILE="${CONFIG_FILE:-/var/www/data/settings.json}"
DEFAULT_INTERVAL=43200
MIN_INTERVAL=60

read_interval() {
    local interval
    if [[ -f "$CONFIG_FILE" ]]; then
        interval=$(php -r '
            $file = $argv[1];
            $config = json_decode(@file_get_contents($file), true);
            $value = $config["collector"]["interval_seconds"] ?? null;
            if (is_numeric($value)) {
                echo (int)$value;
            }
        ' "$CONFIG_FILE" 2>/dev/null)
    fi

    if [[ -n "$interval" && "$interval" =~ ^[0-9]+$ && "$interval" -ge $MIN_INTERVAL ]]; then
        echo "$interval"
    else
        echo "$DEFAULT_INTERVAL"
    fi
}

SLEEP_INTERVAL=$(read_interval)
echo "${LOG_PREFIX} Intervalo configurado: ${SLEEP_INTERVAL}s (arquivo: ${CONFIG_FILE})"

while true; do
    echo "${LOG_PREFIX} Iniciando coleta de hosts: $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
    if php /var/www/scripts/collect_host_history.php; then
        echo "${LOG_PREFIX} Coleta concluída com sucesso."
    else
        echo "${LOG_PREFIX} Erro ao executar a coleta."
    fi
    echo "${LOG_PREFIX} Aguardando ${SLEEP_INTERVAL}s até a próxima coleta."
    sleep "$SLEEP_INTERVAL"
    SLEEP_INTERVAL=$(read_interval)
done
