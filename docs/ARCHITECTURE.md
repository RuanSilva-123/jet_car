# JetCar — Arquitetura

Painel administrativo **privado**: nenhuma página além do login pode ser acessada sem estar autenticado. Não existe cadastro público — o **usuário master** (criado pelo seeder) é quem cria as demais contas.

## Stack

| Camada        | Tecnologia                                   |
|---------------|----------------------------------------------|
| Backend (API) | Laravel 13 / PHP 8.4 (API-only, sem Blade)   |
| Autenticação  | Laravel Sanctum — modo SPA (cookie de sessão HttpOnly + CSRF) |
| Frontend      | Angular 22 (SPA, standalone, zoneless)       |
| Banco         | PostgreSQL 18                                |
| Cache / Filas / Sessões | Redis 8                            |
| Orquestração  | Docker + Docker Compose                      |

> **Por que Sanctum em modo SPA (cookie) e não JWT/token no localStorage?**
> Cookie HttpOnly não fica acessível a JavaScript (mitiga roubo via XSS), a sessão pode ser revogada no servidor e o Laravel já entrega proteção CSRF. Funciona porque o Angular e a API são servidos pelo **mesmo domínio** via Nginx (`/` → Angular, `/api` → Laravel).

---

## Estrutura da raiz

```
JetCar/
├── backend/                 # Laravel (API)
├── frontend/                # Angular (SPA)
├── docker/
│   ├── nginx/
│   │   ├── Dockerfile       # dev: só configs | prod: build do Angular + estáticos
│   │   ├── conf.d/          # dev.conf (proxy p/ ng serve) e prod.conf (SPA estática)
│   │   └── snippets/        # headers de segurança + proxy /api, /sanctum, /up → php-fpm
│   ├── php/
│   │   ├── Dockerfile       # base → dev (código montado) | prod (código na imagem)
│   │   └── conf.d/          # app.ini, dev.ini, prod.ini
│   ├── node/                # imagem do ng serve (dev) + entrypoint que instala deps
│   └── postgres/
│       └── init/            # scripts executados na 1ª subida do banco
├── docs/
│   ├── ARCHITECTURE.md      # este documento
│   └── adr/                 # Architecture Decision Records
├── scripts/                 # scripts utilitários (setup, deploy, backup)
├── docker-compose.yml       # ambiente de desenvolvimento
├── docker-compose.prod.yml  # ambiente de produção (independente)
├── .env.example             # variáveis do Compose (portas, senha do banco)
└── README.md                # como rodar
```

Em dev, `vendor/` (PHP) e `node_modules/` (Angular) ficam em **volumes Docker**, não na pasta do projeto: são milhares de arquivos, o bind mount do Windows é lento demais para eles e os binários do Node precisam ser de Linux.

---

## Containers

```
                 ┌──────────────┐
  navegador ───► │    nginx     │ :80        
                 └──────┬───────┘
     / (Angular)        │        /api, /sanctum, /up (FastCGI)
        ┌───────────────┴──────────────┐
        ▼                              ▼
  dev: node (ng serve)          ┌──────────────┐
  prod: estáticos no nginx      │   app (php)  │──► postgres
                                └──────┬───────┘──► redis
                         ┌─────────────┴─────────────┐
                         ▼                           ▼
                  queue (worker)              scheduler (cron)
```

| Serviço     | Função                                                   | Ambiente   |
|-------------|----------------------------------------------------------|------------|
| `nginx`     | Porta de entrada única; serve SPA e faz proxy da API     | dev + prod |
| `app`       | PHP-FPM rodando Laravel                                  | dev + prod |
| `queue`     | Worker de filas (mesma imagem do `app`)                  | dev + prod |
| `scheduler` | `php artisan schedule:work` (mesma imagem do `app`)      | dev + prod |
| `postgres`  | Banco de dados (volume persistente)                      | dev + prod |
| `redis`     | Cache, sessões e filas                                   | dev + prod |
| `backup`    | `pg_dump` diário + cópia das fotos em `./backups` (imagem `postgres:18-alpine`) | dev + prod |
| `node`      | `ng serve` com hot reload                                | só dev     |
| `mailpit`   | Captura e-mails enviados                                 | só dev     |

Em produção o Angular é compilado num estágio de build (multi-stage) e só o resultado vai para a imagem do Nginx; não existe container Node rodando.

---

## Backend — `backend/` (Laravel)

```
backend/
├── app/
│   ├── Enums/
│   │   └── UserRole.php                 # master | admin
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   │   ├── Auth/AuthController.php  # login, me, logout
│   │   │   └── UserController.php       # CRUD de contas (UserPolicy: só master)
│   │   ├── Middleware/
│   │   │   └── EnsureUserIsActive.php   # alias "active"
│   │   ├── Requests/Auth/
│   │   │   └── LoginRequest.php         # validação + rate limit por e-mail/IP
│   │   └── Resources/
│   │       └── UserResource.php         # JSON do usuário (sem senha)
│   ├── Models/User.php                  # role, is_active, last_login_at
│   └── Providers/AppServiceProvider.php # rate limiter "login" por IP
├── config/jetcar.php                    # dados do usuário master (via .env)
├── database/
│   ├── migrations/
│   └── seeders/MasterUserSeeder.php     # cria o master a partir de MASTER_EMAIL/MASTER_PASSWORD
├── routes/api.php                       # tudo sob /api/v1
└── tests/Feature/                       # autenticação e seeder
```

Pastas previstas conforme o projeto crescer: `Actions/` (casos de uso), `Policies/` (autorização por recurso), `Services/` (regras de negócio e integrações).

**Regras de acesso na API**

- **Públicas**: `GET /sanctum/csrf-cookie`, `POST /api/v1/auth/login` e o orçamento por link assinado (`/api/v1/public/budgets/{token}`, ver abaixo). O login tem rate limit: 5 tentativas por e-mail+IP e 20 por minuto por IP; o orçamento público, 30 por minuto por IP.
- **Todo o resto** fica num único grupo com `auth:sanctum` + `active`. Nenhuma rota protegida fica fora desse grupo.
- Não existe rota de registro.
- Contas inativas (`is_active = false`) não conseguem entrar e recebem a mesma mensagem de credencial inválida, para não revelar quais e-mails existem. Se uma conta for desativada durante a sessão, ela é deslogada na próxima requisição.

---

## Frontend — `frontend/` (Angular)

```
frontend/src/
├── app/
│   ├── core/
│   │   ├── auth/
│   │   │   ├── guards/          # authGuard (exige login) e guestGuard (só deslogado)
│   │   │   ├── interceptors/    # headers JSON; em 401/419 volta para o login
│   │   │   ├── services/        # AuthService (signals: user, isAuthenticated, isMaster)
│   │   │   └── models/
│   │   ├── http/api.ts          # API_URL e envelope das API Resources
│   │   └── title-strategy.ts    # títulos "Página · JetCar"
│   ├── shared/components/       # icon, logo
│   ├── layouts/
│   │   ├── auth-layout/         # painel da marca + formulário
│   │   └── admin-layout/        # sidebar + header + <router-outlet>
│   ├── features/                # um diretório por módulo, carregado sob demanda (lazy)
│   │   ├── auth/                # login
│   │   ├── customers/           # clientes + veículos (FIPE, CEP, placa)
│   │   ├── services/            # catálogo de mão de obra
│   │   ├── dashboard/           # indicadores da oficina
│   │   ├── agenda/              # horários marcados + check-in que vira OS
│   │   ├── service-orders/      # ordens de serviço, vistoria, pagamentos + histórico do veículo
│   │   ├── parts/               # estoque de peças
│   │   ├── reminders/           # lembretes de revisão
│   │   ├── finance/             # contas a receber, contas a pagar, fluxo de caixa (sem mecânico)
│   │   ├── reports/             # relatórios + CSV (sem mecânico)
│   │   ├── public-budget/       # orçamento aberto pelo cliente, com Pix (sem login)
│   │   ├── public-survey/       # pesquisa de satisfação do cliente (sem login)
│   │   └── users/               # lista + cadastro/edição (só master)
│   ├── app.routes.ts
│   └── app.config.ts
└── styles.scss                  # design tokens e estilos globais
```

Cada feature segue o mesmo padrão interno:

```
features/<modulo>/
├── pages/          # componentes roteáveis (list, form, detail)
├── components/     # componentes específicos do módulo
├── services/       # chamadas HTTP do módulo
├── models/         # interfaces/tipos
└── <modulo>.routes.ts
```

**Regras de acesso no frontend**

```
/login        → AuthLayout  + guestGuard
/** (o resto) → AdminLayout + authGuard (canMatch)
```

- O `authGuard` usa `canMatch`, então quem não está logado nem baixa o código dos módulos protegidos.
- O guard confirma a sessão com `GET /api/v1/auth/me` e guarda a página pedida em `?returnUrl=`. Esse parâmetro só aceita caminhos internos.
- O guard serve à experiência do usuário: **quem garante a segurança é a API** (`auth:sanctum`).

---

## Fluxo de autenticação

1. O Angular chama `GET /sanctum/csrf-cookie` e recebe o cookie `XSRF-TOKEN`.
2. Faz `POST /api/v1/auth/login` com e-mail e senha. O HttpClient envia o header `X-XSRF-TOKEN` automaticamente.
3. O Laravel regenera a sessão (guardada no Redis) e devolve o cookie de sessão **HttpOnly**.
4. As requisições seguintes usam esse cookie. Qualquer `401` ou `419` faz o interceptor limpar o estado e voltar para `/login`.
5. `POST /api/v1/auth/logout` invalida a sessão no servidor.

---

## Papéis

| Papel    | Quem                            | Pode                                   |
|----------|---------------------------------|----------------------------------------|
| `master` | criado pelo `MasterUserSeeder`  | tudo, inclusive criar e gerenciar contas e estornar pagamentos |
| `admin`  | criado pelo master no painel    | usar o painel, inclusive o financeiro   |
| `mechanic` | criado pelo master no painel  | OS, checklist, vistoria, agenda e estoque; **não** acessa pagamentos, contas a receber, relatórios nem os números financeiros do dashboard (gate `manage-finance`) |

## Ordens de serviço (`/service-orders`) e histórico do veículo (`/vehicles/{id}/history`)

A OS acompanha o carro da entrada à entrega, em etapas: **entrada** (veículo, km e relato do cliente) → **diagnóstico** (serviços do catálogo e peças, ainda sem valores) → **orçamento** (valores, em `/service-orders/{id}/budget`) → **aprovação** do cliente → **execução** (checklist) → **entrega**. As regras ficam em `App\Services\ServiceOrders\ServiceOrderManager`.

| Endpoint | Ação |
|---|---|
| `GET /api/v1/service-orders` | lista; `status` = `active` (não entregues/canceladas), um status específico ou omitido; `search` (nº, cliente, placa, modelo); `vehicle_id`, `customer_id` |
| `POST /api/v1/service-orders` | entrada do veículo: cliente, veículo, km, relato, previsão, observações. `items`/`parts` opcionais; `status=in_progress` só com orçamento completo |
| `GET /api/v1/service-orders/{id}` | detalhe com itens e linha do tempo |
| `PUT /api/v1/service-orders/{id}` | altera só o que vier: dados de entrada e/ou `items`, `parts`, `discount_cents` (com `id` ficam, sem `id` entram, ausentes saem; campo de linha ausente mantém o valor) |
| `POST /api/v1/service-orders/{id}/items` · `DELETE …/items/{item}` | diagnóstico: adiciona serviço do catálogo (sem valor) / remove (serviço já feito não sai) |
| `POST /api/v1/service-orders/{id}/parts` · `DELETE …/parts/{part}` | diagnóstico: adiciona peça (nome, código, quantidade) / remove |
| `POST /api/v1/service-orders/{id}/budget/send` | marca o orçamento como enviado → `waiting_approval` |
| `POST /api/v1/service-orders/{id}/budget/approve` | registra a aprovação do cliente (valor aprovado + data) e inicia o serviço |
| `POST /api/v1/service-orders/{id}/budget/reject` | recusa: `cancel=true` cancela a OS; `false` volta para revisão (`open`) |
| `GET /api/v1/service-orders/{id}/pdf/budget` | PDF do orçamento (itens sem valor aparecem como "a definir"); `?download=1` baixa |
| `GET /api/v1/service-orders/{id}/pdf/report` | PDF da OS: serviços realizados/não realizados, peças, totais, aprovação e garantia |
| `GET/PUT /api/v1/settings/shop` | dados da oficina usados nos PDFs (PUT só master) |
| `POST /api/v1/service-orders/{id}/status` | muda o status (com observação opcional) |
| `PATCH /api/v1/service-orders/{id}/items/{item}` | marca/desmarca serviço como feito (guarda quem e quando) |
| `POST /api/v1/service-orders/{id}/notes` | comentário na linha do tempo |
| `GET /api/v1/vehicles/{id}/history` | veículo, cliente e todas as OS com os serviços feitos |

- **Status:** `open` (Aberta: entrada, diagnóstico e montagem do orçamento) → `waiting_approval` (orçamento enviado) → aprovação → `in_progress` / `waiting_parts` → `completed` (pronta para retirada) → `delivered`. Qualquer etapa aberta pode ir para `canceled`.
  - **Valor "a definir":** `price_cents`/`unit_price_cents` nulos. Zero é um valor ("sem custo"). Enviar ou aprovar exige ao menos um serviço/peça e nenhum item sem valor (`unpriced_count` no detalhe).
  - **Sem aprovação não há serviço:** `in_progress`, `waiting_parts`, `completed` e `delivered` e o checklist exigem `budget_approved_at`.
  - Se o orçamento mudar depois de aprovado (outro total ou item novo sem valor), a OS avisa (`budget_changed_after_approval`) e o novo valor deve ser reenviado/reaprovado.
  - Entregue e Cancelada **encerram** a OS: ela não aceita edição nem checklist até ser reaberta.
  - As datas de início, conclusão, entrega e cancelamento acompanham o status.
- **Histórico de movimentação:** cada ação gera um registro em `service_order_events` (abertura, status, serviço/peça adicionado ou removido, serviço concluído, orçamento, dados alterados, comentário), com usuário e horário, na mesma transação da alteração.
- **Nome dos serviços:** é copiado para a OS. Renomear ou excluir um serviço do catálogo não altera OS antigas.
- **Sem exclusão:** OS que não vai acontecer é cancelada e fica no histórico. Cliente ou veículo excluídos continuam aparecendo nas OS e no histórico do veículo.
- **Km de entrada:** se for maior que o do cadastro, atualiza a quilometragem do veículo.
- **Valores:** sempre em centavos (inteiros). `labor_total_cents`, `parts_total_cents`, `discount_cents` e `total_cents` ficam gravados na OS e são recalculados a cada alteração (desconto não pode passar do subtotal). Quantidade de peça aceita fração (ex.: 4,5 L de óleo).
- **PDFs:** dompdf (`barryvdh/laravel-dompdf`) com views Blade em `resources/views/pdf/` (só tabelas, sem flex/grid). Fonte DejaVu Sans com subset (≈30 KB por arquivo). Cabeçalho/rodapé com logo e os dados da oficina (`App\Support\ShopSettings`, tabela `settings`). O comprovante (`report`) mostra os pagamentos e o saldo; a vistoria (`inspection`) traz as fotos. A imagem PHP inclui a extensão `gd`, que o dompdf usa para embutir imagens.
- **Envio ao cliente:** o detalhe da OS abre/baixa o PDF e tem um atalho de WhatsApp com o resumo do orçamento e o **link público** para o cliente aprovar; ao usar o atalho a OS é marcada como enviada.
- **Mecânico responsável:** `PUT /api/v1/service-orders/{id}/items/{item}/mechanic` (`mechanic_id` ou `null`). `GET /api/v1/mechanics` lista os mecânicos ativos. A lista de OS aceita `mechanic_id` (um id ou `me`).
- **Peça do estoque:** `parts.*.part_id` (orçamento) ou `part_id` (diagnóstico) liga a linha ao catálogo. Ver "Estoque de peças".

## Orçamento por link (`/orcamento/{token}`)

O detalhe da OS traz `public_budget_url` quando o orçamento está completo. O cliente abre o link pelo WhatsApp, vê os itens e aprova ou recusa sem login.

| Endpoint | Ação |
|---|---|
| `GET /api/v1/public/budgets/{token}` | oficina, veículo, itens, totais e `state` (`awaiting`, `approved`, `rejected`, `unavailable`) |
| `POST …/approve` | `total_cents` (o valor que o cliente viu) e `name` opcional → registra a aprovação e inicia o serviço |
| `POST …/reject` | `total_cents` e `reason` opcional → orçamento volta para revisão (`open`); quem cancela é a oficina |

- **Token assinado** (`App\Support\BudgetLink`): `{id}.{expira}.{assinatura}`, com HMAC-SHA256 da chave da aplicação sobre id + validade. Trocar o número da OS ou estender o prazo invalida o link (404); link vencido responde 410. A validade é a do orçamento (`budget_validity_days` nos dados da oficina).
- **Sem dados pessoais** na resposta: só o primeiro nome do cliente, o veículo e os itens.
- **Valor conferido:** se a oficina mudou o orçamento depois do envio, `total_cents` não bate e a API pede para o cliente conferir de novo (422).
- Quem recusou só decide de novo depois de um novo envio. A linha do tempo registra "pelo link", sem usuário.
- **Pix:** com o orçamento aprovado e saldo em aberto, a resposta traz `pix` (QR Code + copia-e-cola do saldo) e a página mostra "Pague com Pix". Serviços refeitos na garantia vêm com `warranty: true` e aparecem como "Garantia".

## Pagamentos e contas a receber (`/finance`)

| Endpoint | Ação |
|---|---|
| `POST /api/v1/service-orders/{id}/payments` | `method` (`pix`, `cash`, `credit_card`, `debit_card`, `bank_transfer`, `bank_slip`, `other`), `amount_cents`, `installments` (só cartão de crédito), `paid_at`, `notes` |
| `DELETE /api/v1/service-orders/{id}/payments/{payment}` | estorno/lançamento errado — **só master** |
| `GET /api/v1/receivables` | OS aprovadas, não canceladas, com saldo; `scope` = `all`, `delivered` (carro saiu sem quitar) ou `in_service`; resumo por escopo |
| `GET /api/v1/payments` | recebimentos do período (`from`, `to`, `method`) com total por forma de pagamento |

- O total pago fica em `service_orders.paid_cents`. A OS informa `balance_cents` e `payment_status` (`none`, `pending`, `partial`, `paid`).
- O valor não pode passar do saldo; o registro trava a linha da OS (`lockForUpdate`) para dois recebimentos simultâneos não estourarem o saldo.
- Cada recebimento/estorno vira evento na linha do tempo.

## Pix copia-e-cola e QR Code

Sem integração com banco: o painel gera um **Pix estático com valor** a partir da chave da oficina (Dados da oficina → Pix: tipo, chave, nome do recebedor e cidade). O dinheiro cai direto na conta; quem confere o extrato registra o pagamento na OS.

| Endpoint | Ação |
|---|---|
| `GET /api/v1/service-orders/{id}/pix?amount_cents=` | cobrança do saldo em aberto (ou de parte dele) — financeiro; 404 sem chave ou sem saldo |

- `App\Support\Pix\PixPayload` monta o BR Code (padrão EMV do Banco Central: campos ID + tamanho + valor, `txid` = número da OS, CRC16-CCITT no fim). `PixKey` valida e normaliza a chave (CPF/CNPJ só dígitos, celular `+55…`, e-mail minúsculo, chave aleatória em UUID). `PixCharge` gera o QR em PNG (`chillerlan/php-qrcode`, data URI).
- Aparece em três lugares: diálogo "Cobrar com Pix" na OS (com envio pelo WhatsApp), link público do orçamento aprovado e comprovante em PDF (OS com saldo).

## Contas a pagar e fluxo de caixa (`/finance/bills`, `/finance/cash-flow`)

| Endpoint | Ação |
|---|---|
| `GET /api/v1/bills` | `status` = `open` (padrão), `overdue`, `paid`, `all`; `month` (vencimento, ou pagamento para pagas), `category`, `supplier_id`, `search`; resumo (vencidas, vencem hoje, em aberto e pago no mês) |
| `POST /api/v1/bills` | descrição, categoria, fornecedor, valor, vencimento; `installments` divide em parcelas mensais (centavos que sobram na 1ª); `paid_at` + `payment_method` lança já paga (só à vista) |
| `PUT /api/v1/bills/{id}` · `DELETE …` | conta paga não muda valor/vencimento nem é excluída |
| `POST …/{id}/pay` · `POST …/{id}/unpay` | baixa com data, forma e valor efetivamente pago (juros/desconto); estorno **só master** |
| `GET/POST/PUT/DELETE /api/v1/recurring-bills` | despesas fixas (aluguel, internet, contador...): valor e dia do vencimento (31 vira o último dia em meses curtos), início, fim opcional, pausa |
| `GET/POST/PUT/DELETE /api/v1/suppliers` | fornecedores com total em aberto; excluir **só master** (as contas ficam no histórico) |
| `GET /api/v1/cash-flow?month=YYYY-MM` | dias do mês com entradas, saídas, contas a vencer e saldo (realizado e previsto), saídas por categoria e resumo; `&format=csv` exporta |
| `GET/PUT /api/v1/settings/finance` | saldo inicial do caixa numa data (`PUT` só master) |

- **Despesas fixas:** `php artisan jetcar:recurring-bills` roda todo dia às 5h30 no `scheduler` e lança a conta do mês de cada despesa ativa; a chave única (`recurring_bill_id`, `due_date`) impede duplicar. Cadastrar ou editar uma despesa já lança a do mês.
- **Saldo:** saldo inicial + pagamentos das OS + outras entradas recebidas − contas pagas desde a data do saldo inicial. A previsão do mês desconta as contas em aberto (as vencidas entram como "hoje") e mostra também o valor recebendo as OS com saldo.
- **Outras entradas** (`/finance`, aba "Outras entradas"): dinheiro que não vem de OS — aporte do dono, empréstimo, venda de um bem, prêmio. `GET/POST/PUT/DELETE /api/v1/incomes` (`status` = `pending`, `received`, `all`), `POST …/{id}/receive` (data, forma e valor que entrou) e `POST …/{id}/unreceive` (**só master**). Já recebida entra no saldo no dia; prevista (`expected_on`) entra na previsão do mês (atrasada conta "hoje"). Excluir uma recebida é só para o master.
- **Compra de peças:** a entrada no estoque pode lançar a conta a pagar (quantidade × custo, com fornecedor, vencimento e parcelas) na mesma transação.
- Todas as telas exigem o perfil financeiro (todos menos o mecânico).

## Estoque de peças (`/parts`)

| Endpoint | Ação |
|---|---|
| `GET /api/v1/parts` | `search` (nome, código, marca), `status` = `active`, `inactive` ou `low` (estoque baixo); `summary.low_stock` |
| `POST /api/v1/parts` · `PUT …/{id}` | nome, código (único), marca, unidade, custo, preço de venda, estoque mínimo; `initial_stock` só no cadastro |
| `DELETE /api/v1/parts/{id}` | exclusão lógica — **só master** |
| `POST /api/v1/parts/{id}/stock` | `type` = `entry` (compra, com custo opcional que atualiza o custo da peça) ou `adjustment` (quantidade contada) |
| `GET /api/v1/parts/{id}/movements` | histórico de entradas e saídas com o saldo após cada uma |

- A quantidade só muda por `App\Services\Inventory\StockManager`, que grava cada movimentação em `stock_movements`.
- **Baixa automática:** peça do estoque que entra na OS sai do estoque; mudar a quantidade movimenta só a diferença; remover a peça ou **cancelar a OS** devolve; reabrir uma OS cancelada dá baixa de novo.
- O estoque pode ficar negativo (peça usada antes de lançar a compra) e então aparece como **estoque baixo**, junto com as peças no mínimo.

## Vistoria de entrada (`/service-orders/{id}/inspection`)

| Endpoint | Ação |
|---|---|
| `GET/PUT /api/v1/service-orders/{id}/inspection` | combustível (0 = reserva … 4 = cheio), avarias (`area`, `type`, `notes`), itens conferidos, pertences, observações |
| `POST …/inspection/photos` · `DELETE …/photos/{photo}` | fotos (JPG/PNG/WEBP, até 10 MB, 30 por OS) |
| `GET …/inspection/photos/{photo}` | foto servida só para usuários logados (disco privado `local`) |
| `GET /api/v1/service-orders/{id}/pdf/inspection` | PDF com os dados e as fotos |

- As fotos são reduzidas no navegador (≈1600 px, JPEG) antes do upload, já com a rotação do EXIF aplicada.
- Sem assinatura: a vistoria fica sempre editável e a linha do tempo registra quando foi feita. A coluna de assinatura existiu numa versão anterior e foi removida (`2026_10_07_000007`).

## Lembretes de revisão (`/reminders`)

- O serviço do catálogo pode ter intervalo de revisão: `reminder_months` e/ou `reminder_km` ("troca de óleo a cada 6 meses ou 10 mil km", o que vier primeiro). A lista sugerida já traz intervalos comuns.
- **Scheduler:** `php artisan jetcar:service-reminders` roda todo dia às 6h no container `scheduler` (`routes/console.php`). Para cada veículo e serviço periódico, olha a última execução em OS entregue e cria o lembrete quando vence em até `REMINDER_LEAD_DAYS` dias (padrão 15) ou `REMINDER_LEAD_KM` km (padrão 500, pela última km conhecida do veículo). Rodar de novo não duplica; serviço refeito encerra o lembrete anterior.
- `GET /api/v1/service-reminders` (`status` = `open`, `scheduled`, `dismissed`, `all`…), `PATCH …/{id}` (contatado, agendado, descartado), `POST …/refresh` (gera na hora).
- No painel: WhatsApp com a mensagem pronta (marca como contatado) e "Agendar", que abre a agenda já preenchida.

## Agenda (`/agenda`)

| Endpoint | Ação |
|---|---|
| `GET /api/v1/appointments?from&to` | agendamentos do período (até 62 dias), datas ISO 8601 com fuso |
| `POST /api/v1/appointments` · `PUT …/{id}` | cliente, veículo (opcional), horário, duração, motivo; `service_reminder_id` marca o lembrete como agendado |
| `POST …/{id}/status` | `scheduled`, `confirmed`, `no_show`, `canceled` |
| `POST …/{id}/check-in` | o carro chegou: abre a OS com o motivo como relato (pede o veículo se não estava definido; aceita `vehicle` para cadastrar um carro novo) |

- **Sem cadastro:** o agendamento aceita `contact_name` (+ `contact_phone` e `vehicle_description`) no lugar de `customer_id`. No check-in, quem não tem cadastro vira cliente: `customer` (nome e telefone, cadastro rápido) ou `customer_id` (cliente que já existia), com `vehicle_id` ou `vehicle` (tipo, marca, modelo, placa).

- O banco guarda em UTC; o painel mostra no horário do navegador. Textos gerados pelo servidor usam `DISPLAY_TIMEZONE` (padrão `America/Sao_Paulo`).

## Retorno em garantia

O prazo fica em Dados da oficina (`warranty_days`, padrão 90). Na OS em aberto, "Marcar retorno em garantia" lista as OS **entregues do mesmo veículo dentro do prazo** e os serviços delas.

| Endpoint | Ação |
|---|---|
| `GET /api/v1/service-orders/{id}/warranty/candidates` | OS candidatas com os serviços |
| `POST /api/v1/service-orders/{id}/warranty` | `warranty_of_id` + `item_ids`: os serviços entram nesta OS **sem custo**, ligados ao serviço original (`warranty_of_item_id`) |
| `DELETE /api/v1/service-orders/{id}/warranty` | desfaz (bloqueado se algum serviço de garantia já foi feito) |

- As duas OS ganham eventos na linha do tempo e avisos com link uma para a outra; a lista de OS mostra o selo "Garantia"; os PDFs mostram "Garantia" no lugar do valor.
- Relatório `warranty`: quais serviços mais voltam, taxa de retorno sobre as execuções entregues no período e quem tinha feito o serviço original. Serviços de garantia não contam como venda no relatório de serviços.

## Pesquisa de satisfação (`/avaliacao/{token}`)

Depois da entrega, o detalhe da OS traz `survey_url` e o botão "Pedir avaliação pelo WhatsApp". O cliente dá uma nota de 0 a 10 ("o quanto indicaria a oficina") e um comentário opcional, sem login.

| Endpoint | Ação |
|---|---|
| `GET /api/v1/public/surveys/{token}` | `state` = `open`, `answered` ou `unavailable` (OS reaberta/cancelada) |
| `POST /api/v1/public/surveys/{token}` | `score` 0–10 e `comment`; uma resposta por OS (trava a linha) |

- Token assinado como o do orçamento (`App\Support\SignedToken`, finalidade `survey`, válido 30 dias); um token de orçamento não abre a pesquisa.
- **NPS** = % promotores (9–10) − % detratores (0–6). O dashboard mostra o NPS dos últimos 90 dias, as últimas respostas, as entregas recentes sem avaliação e os retornos em garantia do mês. Relatório `satisfaction`: respostas do período, piores notas primeiro.

## Horário nos PDFs

O banco guarda em UTC; `App\Support\LocalTime` converte para `DISPLAY_TIMEZONE` (padrão `America/Sao_Paulo`) tudo que tem hora nos PDFs (emissão, entrada, conclusão, entrega, serviços feitos). Datas sem hora (previsão, data do pagamento) não são convertidas.

## Backup automático

Container `backup` (imagem `postgres:18-alpine`, mesma versão do servidor) com os scripts de `docker/backup/`:

- `loop.sh`: na subida, faz um backup se não houver um das últimas 24 h; depois, todo dia em `BACKUP_TIME` (padrão `03:00`, fuso `BACKUP_TZ`).
- `backup.sh`: `pg_dump -Fc` conferido com `pg_restore --list` antes de valer (`backups/db/jetcar_AAAA-MM-DD_HHMMSS.dump`), cópia das fotos/anexos (`backups/files/storage_*.tar.gz`), apaga cópias com mais de `BACKUP_KEEP_DAYS` dias (padrão 14) e grava `backups/last-backup.json`.
- `restore.sh <arquivo> --confirmar`: faz um backup de segurança (`…_antes-restore.dump`) e restaura numa transação só.
- O PHP lê `last-backup.json` (pasta montada só leitura): Dados da oficina mostra a situação e o dashboard do master avisa quando o backup falhou, parou (mais de 26 h) ou nunca rodou. `GET /api/v1/settings/backup` (master).

Restaurar (passo a passo no README): pare `app`, `queue` e `scheduler`, rode `docker compose exec backup sh /scripts/restore.sh <arquivo>.dump --confirmar` e suba de novo. As fotos: `docker compose exec app tar -xzf /backups/files/<arquivo>.tar.gz -C storage/app`.

## Dashboard (`/dashboard`)

`GET /api/v1/dashboard?budget_days=3`: OS em aberto por status, atrasadas (previsão vencida e ainda não prontas), orçamentos enviados há mais de X dias sem resposta, veículos prontos esperando retirada, agenda do dia, lembretes pendentes e estoque baixo. Para quem acessa o financeiro: faturamento do mês (OS entregues), ticket médio, comparação com o mesmo período do mês anterior, recebido no mês, total a receber, contas a pagar vencidas e dos próximos 7 dias e saldo do caixa. Para todos: satisfação (NPS) e retornos em garantia do mês. Para o master: aviso de backup. "Hoje" e "mês" seguem `DISPLAY_TIMEZONE`.

## Relatórios (`/reports`)

`GET /api/v1/reports/{revenue|services|customers|mechanics|warranty|satisfaction}?from&to` (padrão: mês atual). `?format=csv` baixa o mesmo relatório para o Excel (`;`, vírgula decimal, UTF-8 com BOM).

- **revenue:** OS entregues por dia ou mês (`group`), com mão de obra, peças, descontos, ticket médio e o que foi recebido.
- **services:** serviços feitos nas OS entregues, por quantidade e faturamento.
- **customers:** clientes atendidos no período, gasto, primeira/última visita e se já tinham vindo antes (`only_returning=1`).
- **mechanics:** serviços concluídos por mecânico no período (produção).
- **warranty:** retornos em garantia por serviço, taxa de retorno e quem fez o serviço original.
- **satisfaction:** avaliações respondidas no período com NPS, média e distribuição.

Agrupamentos feitos em PHP (`App\Services\Reports\ReportBuilder`), para funcionar igual no PostgreSQL e no SQLite dos testes.

## Busca global (Ctrl+K)

`GET /api/v1/search?q=` acha veículo pela placa (com ou sem hífen) ou modelo, cliente por nome, CPF/CNPJ ou telefone e OS pelo número ("OS 42", "#00042"). No painel, `Ctrl+K` / `⌘K` (ou o campo no topo) abre a busca; com a placa, a primeira opção é a OS em aberto do veículo, depois o histórico e o cliente.

## Clientes e veículos (`/customers`)

Cadastro completo em uma tela: cliente (pessoa física ou jurídica) e seus veículos, gravados juntos numa transação (`App\Actions\Customers\SaveCustomer`).

| Endpoint | Ação |
|---|---|
| `GET /api/v1/customers` | lista paginada; `search` busca em nome, nome fantasia, e-mail, CPF/CNPJ, telefones, placa, marca e modelo; `person_type` filtra PF/PJ |
| `POST /api/v1/customers` | cadastra o cliente e os veículos enviados em `vehicles` |
| `GET /api/v1/customers/{id}` | detalhe com os veículos |
| `PUT /api/v1/customers/{id}` | edita e **sincroniza** os veículos: com `id` atualiza, sem `id` cria, ausente remove |
| `DELETE /api/v1/customers/{id}` | exclusão lógica (soft delete) do cliente e dos veículos — **só master** |
| `GET /api/v1/vehicle-catalog/{tipo}/brands[/{marca}/years[/{ano}/models]]` | Tabela FIPE: marca → ano/combustível → modelos daquele ano (`tipo` = `car`, `motorcycle` ou `truck`) |
| `GET /api/v1/address-lookup/{cep}` | endereço pelo CEP (ViaCEP) |

- **Validações brasileiras** (no backend e na tela):
  - CPF e CNPJ conferidos pelo dígito verificador. O CNPJ aceita o **formato alfanumérico** (IN RFB 2.229/2024).
  - Telefone com DDD, celular ou fixo.
  - Placa antiga (`ABC1234`) ou Mercosul (`ABC1D23`).
  - Chassi com 17 caracteres e Renavam com 9 a 11 dígitos.
  - Ano de fabricação igual ao ano do modelo ou um ano antes.
- **Unicidade:** CPF/CNPJ e placa são únicos entre os cadastros ativos. Ao excluir um cliente, o documento e as placas dele ficam livres para um novo cadastro.
- **Dados guardados sem máscara**, só dígitos ou letras maiúsculas. A formatação é feita na exibição (`brFormat` pipe).
- **FIPE** (`fipe.parallelum.com.br`):
  - Sem token, o limite é de 500 consultas por dia. As respostas ficam em cache no Redis (`FIPE_CACHE_DAYS`, padrão 7 dias), e o token gratuito opcional `FIPE_TOKEN` sobe o limite para 1.000 por dia.
  - Se a FIPE falhar, a API responde 503 e a tela passa para o preenchimento manual.
  - Marca e modelo são gravados como texto e os códigos FIPE como referência. O histórico não depende da FIPE continuar no ar.
- **CEP:** fica 30 dias em cache. CEP inexistente retorna 404 e a tela pede o preenchimento manual.
- **Limite de consultas externas:** 60 por minuto por usuário (rate limiter `lookups`).

## Catálogo de serviços / mão de obra (`/services`)

São os serviços que a oficina realiza, como troca de amortecedor ou de correia dentada. **Não há valores** por enquanto.

| Endpoint | Ação |
|---|---|
| `GET /api/v1/labor-services` | lista paginada; filtros `search` (nome/descrição), `category` e `status` (`active` ou `inactive`) |
| `POST /api/v1/labor-services` | cadastra (nome, categoria, descrição opcional, ativo) |
| `PUT /api/v1/labor-services/{id}` | edita, ativa ou desativa |
| `DELETE /api/v1/labor-services/{id}` | exclusão lógica — **só master** (no dia a dia, prefira desativar) |
| `POST /api/v1/labor-services/suggestions` | adiciona a lista de serviços comuns (`App\Support\SuggestedLaborServices`), pulando nomes já existentes |

- O nome é único no catálogo e a comparação ignora maiúsculas/minúsculas. Espaços extras são removidos.
- As categorias ficam em `App\Enums\ServiceCategory`, espelhadas no frontend em `features/services/models/labor-service.ts`.

## Consulta de veículo pela placa

`GET /api/v1/plate-lookup` informa se está habilitada; `GET /api/v1/plate-lookup/{placa}` devolve marca, modelo, anos, combustível, cor e, quando há, os códigos FIPE. Com esses códigos, o painel seleciona marca, ano e modelo nas listas da FIPE; sem eles, preenche os campos em modo manual.

- **Provedores** (`PLATE_LOOKUP_DRIVER`):
  - `apibrasil`: plano gratuito de 100 consultas/dia; usa `APIBRASIL_BEARER_TOKEN` e `APIBRASIL_DEVICE_TOKEN`.
  - `apiplacas`: `APIPLACAS_TOKEN`.
  - `mock`: dados fixos, para homologação.
- **Cache:** cada placa encontrada fica 30 dias em cache e só consome a cota uma vez (`PLATE_LOOKUP_CACHE_DAYS`).
- **Sem provedor configurado:** o cadastro funciona normalmente, só sem o preenchimento automático.
- **Dados do proprietário:** nunca são repassados ao painel (`PlateDataNormalizer`).

## Gestão de usuários (`/users`, só master)

| Endpoint                     | Ação                                                        |
|------------------------------|-------------------------------------------------------------|
| `GET /api/v1/users`          | lista paginada (`search`, `status` = `active` ou `inactive`, `per_page`) |
| `POST /api/v1/users`         | cadastra (senha: mín. 8, letras e números)                   |
| `GET /api/v1/users/{id}`     | detalhe                                                     |
| `PUT /api/v1/users/{id}`     | edita; senha em branco = mantém a atual                     |
| `DELETE /api/v1/users/{id}`  | exclui                                                      |

- Autorização: `UserPolicy` (master) + `masterGuard` no Angular.
- O master não pode excluir, desativar ou rebaixar a própria conta, o que garante que o painel nunca fica sem administrador.
- Desativar ou excluir uma conta derruba a sessão dela na próxima requisição.
