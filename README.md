# Monitor Stack

Aplicação web em PHP/Apache para visualizar perfis de clientes do Zabbix, relatórios de billing e histórico diário de hosts. O projeto sobe dois contêineres:

- **php-monitor**: UI em PHP 8.2 + Apache servida em `http://localhost:8080`.
- **history-collector**: processo em loop que executa `scripts/collect_host_history.php` periodicamente para consolidar dados vindos da API do Zabbix.

## Estrutura

```
MONITOR/
├── docker-compose.yml        # Orquestra os containers php-monitor e history-collector
├── monitor/
│   ├── Dockerfile            # Build único usado por ambos os serviços
│   ├── data/                 # Arquivos JSON (billing, perfis, settings, histórico)
│   ├── scripts/              # Coletor CLI e loop scheduler
│   └── www/                  # Código PHP/HTML/CSS da aplicação
└── README.md
```

> ⚠️ O diretório `monitor/data/` não é versionado. Ele deve conter seus arquivos reais de configuração e billing (`settings.json`, `planos.json`, etc.). Faça backup separado e garanta as permissões corretas ao subir para produção.

## Pré-requisitos

- Docker 20.x+
- Docker Compose Plugin v2+
- Redes externas já criadas (caso utilize a mesma topologia compartilhada do restante do stack):
  ```bash
  docker network create monitoring_frontend
  docker network create --internal monitoring_backend
  ```

## Configuração

1. Crie `monitor/data/` com seus arquivos JSON. No mínimo, `settings.json` deve listar os servidores Zabbix, suas URLs e tokens (veja `www/functions.php` para o formato esperado).
2. (Opcional) Popule `data/billing`, `data/history`, etc., com dados históricos existentes. O coletor atualizará automaticamente os arquivos em `data/history/hosts/*.json`.

## Subindo o projeto

```bash
cd MONITOR
docker compose up -d --build
```

- A interface web fica disponível em `http://localhost:8080`.
- O contêiner `php_history_collector` roda `scripts/collector-loop.sh`, que dispara `collect_host_history.php` a cada 12 horas (ajuste o `sleep` se precisar de outra periodicidade).

### Comandos úteis

- Ver logs do coletor: `docker compose logs -f history-collector`
- Executar coleta manualmente: `docker compose exec history-collector php /var/www/scripts/collect_host_history.php`
- Atualizar dependências PHP (se necessário): editar `monitor/Dockerfile` e reconstruir com `docker compose build`.

## Desenvolvimento

- Arquivos em `monitor/www` ficam montados em `/var/www/html`, então alterações locais são refletidas imediatamente.
- Os scripts podem ser executados diretamente na máquina host usando `php monitor/scripts/collect_host_history.php`, desde que o `monitor/data` exista e contenha as credenciais corretas.

## Boas práticas

- Nunca commitar os arquivos reais de `monitor/data/`; eles frequentemente contêm tokens de API e dados sensíveis.
- Automatize o backup de `monitor/data/` fora do repositório (por exemplo, usando `restic`, `rclone` ou snapshots do servidor).
- Use variáveis de ambiente/secret managers se publicar o projeto em ambientes compartilhados.
