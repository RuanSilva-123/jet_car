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

## Comandos do dia a dia

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
