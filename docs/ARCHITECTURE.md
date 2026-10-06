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

- **Públicas**: `GET /sanctum/csrf-cookie` e `POST /api/v1/auth/login`. O login tem rate limit: 5 tentativas por e-mail+IP e 20 por minuto por IP.
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
│   │   ├── dashboard/           # vazio por enquanto (só a saudação)
│   │   ├── service-orders/      # ordens de serviço + histórico do veículo
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
| `master` | criado pelo `MasterUserSeeder`  | tudo, inclusive criar e gerenciar contas |
| `admin`  | criado pelo master no painel    | usar o painel                          |

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
- **PDFs:** dompdf (`barryvdh/laravel-dompdf`) com views Blade em `resources/views/pdf/` (só tabelas, sem flex/grid). Fonte DejaVu Sans com subset (≈30 KB por arquivo). Cabeçalho/rodapé com logo e os dados da oficina (`App\Support\ShopSettings`, tabela `settings`). Sem campos de assinatura: o fechamento (como aprovar, condições, garantia) fica ao lado dos totais.
- **Envio ao cliente:** o detalhe da OS abre/baixa o PDF e tem um atalho de WhatsApp com o resumo do orçamento; ao usar o atalho a OS é marcada como enviada.

## Próximos passos

1. Dashboard com indicadores reais (OS em aberto, atrasadas, entregues no mês, faturamento).
2. Pagamentos da OS (forma de pagamento, parcial/quitado).

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
