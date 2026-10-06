# JetCar

Painel administrativo privado — Laravel 13 (API) + Angular 22 (SPA), orquestrado com Docker.

Acesso somente para usuários autenticados. Não existe cadastro público: o **usuário master** (criado pelo seeder) é quem cria as demais contas.

Arquitetura completa em [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Requisitos

- Docker Desktop (com WSL2 no Windows)

PHP, Composer e Node **não** precisam estar instalados na máquina — tudo roda nos containers.

## Primeira execução (desenvolvimento)

```bash
cp .env.example .env                    # portas e senha do Postgres
cp backend/.env.example backend/.env    # defina MASTER_EMAIL e MASTER_PASSWORD
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

| Serviço           | URL                    |
|-------------------|------------------------|
| Painel            | http://localhost       |
| Mailpit (e-mails) | http://localhost:8025  |
| Adminer (banco)   | http://localhost:8081  |
| Postgres (host)   | `localhost:5433`       |

Entre com o `MASTER_EMAIL` / `MASTER_PASSWORD` definidos em `backend/.env`.

## Atualizando para esta versão

Esta versão adicionou um pacote PHP (QR Code do Pix), um container de backup e tabelas novas. Depois do `git pull`:

```bash
docker compose up -d --build                    # sobe o novo container "backup"
docker compose exec app composer install        # instala o pacote do QR Code
docker compose exec app php artisan migrate
```

Depois, em **Dados da oficina**, preencha a chave Pix e a cidade (para o QR Code) e confira o prazo de garantia. Em **Fluxo de caixa**, o master informa o saldo inicial do caixa.

## Backup

O container `backup` faz uma cópia do banco e das fotos todo dia às 3h (e na primeira subida) na pasta `backups/` do projeto, guardando 14 dias. Ajuste no `.env` da raiz: `BACKUP_TIME`, `BACKUP_TZ`, `BACKUP_KEEP_DAYS`. A situação aparece em **Dados da oficina**.

Copie a pasta `backups/` para um HD externo ou para a nuvem de vez em quando: backup no mesmo disco não protege contra perda do disco.

```bash
docker compose exec backup sh /scripts/backup.sh          # backup agora
ls backups/db                                             # cópias do banco
```

Para restaurar um backup:

```bash
docker compose stop app queue scheduler
docker compose exec backup sh /scripts/restore.sh jetcar_AAAA-MM-DD_HHMMSS.dump --confirmar
docker compose start app queue scheduler
docker compose exec app tar -xzf /backups/files/storage_AAAA-MM-DD_HHMMSS.tar.gz -C storage/app   # fotos (opcional)
```

O `restore.sh` faz antes um backup de segurança do estado atual (`…_antes-restore.dump`).

## Comandos do dia a dia

Depois de atualizar o código, rode `docker compose up -d --build` (a imagem do PHP ganhou a extensão `gd`) e `docker compose exec app php artisan migrate`. O container `scheduler` gera todo dia, às 6h, a lista de lembretes de revisão (`php artisan jetcar:service-reminders`) e, às 5h30, as contas do mês das despesas fixas (`php artisan jetcar:recurring-bills`).

```bash
docker compose up -d                            # sobe o ambiente
docker compose down                             # derruba (dados persistem nos volumes)
docker compose logs -f app node                 # logs
docker compose exec app php artisan migrate     # migrations
docker compose exec app php artisan test        # testes do backend
docker compose exec node npx ng test --watch=false   # testes do frontend
docker compose exec app composer require <pacote>
docker compose exec node npm install <pacote>
```

## Produção

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec app php artisan migrate --seed --force
```

No servidor, `backend/.env` deve ter `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` com o domínio real, `SANCTUM_STATEFUL_DOMAINS` com o domínio e `SESSION_SECURE_COOKIE=true` (HTTPS).

## Desempenho no Windows

O código fica num bind mount, e no Windows isso deixa o PHP lento em dev (~1s por requisição). Para velocidade próxima da de produção, clone o projeto **dentro do WSL2** (ex.: `\\wsl$\Ubuntu\home\<usuario>\jetcar`) e rode o Docker de lá.
