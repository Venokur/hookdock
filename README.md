# Hookdock — приём и маршрутизация вебхуков

Самодостаточный сервис-«ловушка» для входящих вебхуков с визуальным конструктором маршрутизации
в духе Zapier/Albato. Каждый запрос сохраняется с методом, путём, заголовками, сырым телом и
разобранным JSON, после чего сценарии могут отфильтровать его по полям и переслать, залогировать
или трансформировать данные.

## Возможности

- Приём **любых** методов (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`) по адресам
  `/api/webhook/{endpoint}` и `/hook/{endpoint}`.
- Хранение заголовков, query-параметров, сырого тела и JSON в PostgreSQL (`jsonb`).
- Автообновляемый лог запросов: фильтры по методу/эндпоинту/тексту, пагинация, детальная карточка
  с подсветкой JSON и копированием запроса как cURL.
- Конструктор сценариев: триггер → условия → действия, включение/пауза, история запусков
  (`success` / `failed` / `skipped`).
- Действия: пересылка HTTP-запроса, запись в лог, трансформация полей; шаблоны `{{ path }}`.
- Русский интерфейс, светлая тема по умолчанию и тёмная по переключателю.

## Стек

- **Frontend:** Vue 3 (`<script setup>`), Vite, Pinia, Vue Router, Tailwind CSS v4
- **Backend:** нативный PHP 8.3 без фреймворка и Composer, PDO/pgsql, JWT
- **База:** PostgreSQL 16
- **Окружение:** Docker Compose — nginx (статика + прокси), php-fpm, Postgres

## Быстрый старт

```bash
docker compose up --build
```

Откройте <http://localhost:8080> и войдите:

| Email | Пароль |
| --- | --- |
| `admin@l.loc` | `123456` |

Схема БД, миграции и администратор создаются автоматически при первом запуске
(`backend/migrate.php` вызывается из entrypoint контейнера `api`). Ручной SQL не нужен.

Параметры можно переопределить, скопировав `.env.example` в `.env` (`APP_PORT`, `DB_PASSWORD`,
`JWT_SECRET` и т.д.). Значения по умолчанию работают сразу.

## Тестовый вебхук

```bash
curl -X POST http://localhost:8080/api/webhook/stripe \
  -H "Content-Type: application/json" \
  -d '{"event":{"type":"payment.succeeded"},"customer":{"email":"ada@example.com"}}'
```

В PowerShell используйте `curl.exe` (обычный `curl` — алиас `Invoke-WebRequest`) либо готовый
скрипт:

```powershell
scripts/smoke.ps1 -BaseUrl http://localhost:8080
```

Адреса приёма (публичные, без авторизации):

- `/api/webhook/{endpoint}` — основной адрес из интерфейса;
- `/hook/{endpoint}` — короткий алиас;
- любой другой путь, дошедший до PHP, также считается вебхуком.

Slug эндпоинта сохраняется вместе с запросом — по нему удобно строить сценарии для конкретного
источника.

## Сценарии

Сценарий состоит из **триггера → условий → действий**:

1. **Триггер** — slug эндпоинта (`*` — все эндпоинты).
2. **Условия** — должны совпасть все. Пути читаются из контекста запроса:
   `body.customer.email`, `query.source`, `headers.x-github-event`, `meta.method`, `transformed.*`.
   Операторы: `equals`, `not_equals`, `contains`, `not_contains`, `exists`, `not_exists`,
   `gt`, `gte`, `lt`, `lte`, `regex`.
3. **Действия** — выполняются по порядку:
   - `forward` — HTTP-запрос на внешний URL (POST/PUT/PATCH/GET/DELETE), тело: исходное,
     трансформированное или сырое;
   - `transform` — сопоставление ключей результата с путями контекста
     (`{"email": "body.customer.email"}`);
   - `log` — запись сообщения с подстановкой `{{ body.field }}`.

Каждый сценарий, подошедший по эндпоинту, создаёт запуск со статусом `success`, `failed` или
`skipped` — он виден в карточке запроса. Ошибки действий не мешают приёму запроса (ответ 202).

Пример через API:

```bash
TOKEN=$(curl -s -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@l.loc","password":"123456"}' | jq -r .token)

curl -X POST http://localhost:8080/api/workflows \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "name": "Уведомить CRM",
    "endpoint": "stripe",
    "enabled": true,
    "conditions": [{"path": "body.event.type", "operator": "equals", "value": "payment.succeeded"}],
    "actions": [{"type": "forward", "url": "https://example.com/hooks/crm", "method": "POST", "payload": "body"}]
  }'
```

## API

| Метод | Путь | Авторизация | Назначение |
| --- | --- | --- | --- |
| GET | `/api/health` | — | Проверка живости |
| POST | `/api/auth/login` | — | Email/пароль → JWT |
| GET | `/api/auth/me` | JWT | Текущий пользователь |
| POST | `/api/auth/logout` | JWT | Заглушка, токен удаляет клиент |
| GET | `/api/stats` | JWT | Счётчики и известные эндпоинты |
| GET | `/api/requests` | JWT | Список (`page`, `per_page`, `method`, `endpoint`, `q`, `from`, `to`) |
| GET | `/api/requests/{id}` | JWT | Запрос + запуски сценариев |
| DELETE | `/api/requests/{id}` | JWT | Удалить запрос и его запуски |
| GET/POST | `/api/workflows` | JWT | Список / создание |
| GET/PUT/DELETE | `/api/workflows/{id}` | JWT | Чтение / изменение / удаление |
| POST | `/api/workflows/{id}/toggle` | JWT | Включить/приостановить |
| ANY | `/api/webhook/{endpoint}` | — | Приём и сохранение |
| ANY | `/hook/{endpoint}` | — | Приём и сохранение |

Ошибки: `{"error":{"message":"…"}}`. JWT — HS256, TTL 12 часов, передаётся как
`Authorization: Bearer <token>`.

## Структура проекта

```
backend/
  public/index.php        фронт-контроллер и таблица маршрутов
  src/
    Auth/                 выпуск/проверка JWT, guard запросов
    Controllers/          авторизация, запросы, сценарии, приём вебхуков
    Http/                 Request/Response/Router
    Services/             WorkflowEngine (условия и действия)
    Support/              JsonPath, Template, HttpClient, JsonColumns
  migrations/001_init.sql схема + администратор
  migrate.php             идемпотентный раннер миграций
docker/
  nginx/                  сборка SPA, статика, прокси /api и /hook
  php/                    php-fpm + pdo_pgsql
frontend/
  src/views/              Вход, Входящие, Сценарии
  src/components/         AppShell, RequestDetail, JsonViewer, WorkflowEditor
  src/components/ui/      AppInput, AppSelect, AppCheckbox, AppToggle, AppTextarea
  src/composables/        тема (светлая/тёмная)
  src/stores/auth.js      токен и пользователь
  src/api/client.js       fetch-обёртка с обработкой 401
scripts/smoke.ps1         сквозная проверка запущенного стека
```

## Интерфейс и темы

- Интерфейс полностью на русском; даты и числа форматируются под `ru-RU`.
- **Светлая тема включена по умолчанию.** Переключатель (солнце/луна) есть на странице входа и в
  боковой панели; выбор сохраняется в `localStorage` (`wr_theme`) и применяется до первой отрисовки.
- Тема реализована CSS-переменными в `frontend/src/assets/main.css` и классом `.dark` на `<html>`
  (`@custom-variant dark` в Tailwind v4).
- Нативные контролы не используются напрямую: чекбоксы, селекты, поля, переключатели и textarea
  обёрнуты в компоненты `frontend/src/components/ui/*` — они держат единые размеры, фокус,
  disabled-состояния и цвета обеих тем. Для новых форм подключайте их, а не «сырые» `<input>`
  и `<select>`.
- `AppSelect` — полностью собственный выпадающий список: панель рисует компонент (через `Teleport`
  в `body`), а не браузер. Поддерживаются клавиатура (стрелки, Home/End, Enter, Esc, поиск по
  первой букве), закрытие по клику вне, переворот вверх у нижнего края экрана и пересчёт позиции
  при скролле — поэтому список не обрезается модалками и `overflow: hidden`.
- Автозаполнение браузера, стрелка селекта, кнопка очистки поиска, цвета выпадающих списков и
  скроллбары также приведены к стилю сайта.

## Разработка

PHP и база рассчитаны на запуск в контейнерах. Глобального middleware авторизации нет: каждый
маршрут API сам вызывает `Auth::requireUser($request)`, а маршруты вебхуков намеренно публичны.
HTML формирует только nginx; `/` отдаёт `index.html`, поэтому history-mode роутер работает при
перезагрузке.

Горячая перезагрузка фронтенда:

```bash
# стек уже запущен на :8080
cd frontend
npm.cmd install
npm.cmd run dev        # http://localhost:5173, прокси /api и /hook на :8080
```

Проверка production-сборки: `npm.cmd run build` (Tailwind v4 подключён через `@tailwindcss/vite`,
файла `tailwind.config.js` нет).

Миграции: добавьте `backend/migrations/00N_name.sql` — файл применится при следующем старте `api`
и запишется в `schema_migrations`. Уже применённые файлы не редактируйте.

Полезные команды:

```bash
docker compose logs -f api
docker compose exec api php migrate.php
docker compose exec db psql -U webhooks -d webhooks
docker compose down -v          # полный сброс, удаляет том pgdata
```

## Проверка

`scripts/smoke.ps1` проверяет вход, отказ без токена, приём вебхука, совпадение и пропуск условий,
сохранение не-JSON тела, forward-действие, пагинацию, статистику, а также что JSONB-поля приходят
массивами/объектами и что сценарий можно создать на паузе. Скрипт убирает за собой созданные данные.

## Примечания

- Пароль администратора хранится как bcrypt-хеш; смените его после первого входа в реальном
  окружении.
- Перед публикацией наружу задайте надёжный `JWT_SECRET` и смените `DB_PASSWORD`.
- nginx и PHP ограничивают тело вебхука 16 МБ.
- Forward-действия выполняются из контейнера `api`: во внутренних URL используйте DNS сервисов
  (`http://web/...`), а не `localhost:8080`.
