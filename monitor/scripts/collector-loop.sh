#!/usr/bin/env bash
set -euo pipefail

LOG_PREFIX="[history-collector]"

while true; do
    echo "${LOG_PREFIX} Iniciando coleta de hosts: $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
    if php /var/www/scripts/collect_host_history.php; then
        echo "${LOG_PREFIX} Coleta concluída com sucesso."
    else
        echo "${LOG_PREFIX} Erro ao executar a coleta."
    fi
    echo "${LOG_PREFIX} Aguardando 5 minutos até a próxima coleta."
    sleep 43200
done
