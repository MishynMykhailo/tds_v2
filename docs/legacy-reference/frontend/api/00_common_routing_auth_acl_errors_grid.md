# Справочник backend admin API (для пересборки фронтенда)

Документ описывает **бэкенд** admin-панели (не сам фронтенд, исходников
которого нет — есть только собранный `admin/assets/app.js`). Составлен
чтением исходников `application/Admin/*`, `application/Component/*/Controller/*`
и связанных Service/Repository/Serializer классов. Все пути ниже — от корня
репозитория. Стиль и известные баги перекликаются с `docs/BUG_PATTERNS.md`
и `docs/FIXES_LOG.md` — там же общий контекст декомпиляции ionCube→PHP.

Важно: это не готовый OpenAPI-спек, а карта поведения кода "как есть",
включая нетривиальные и местами странные (для декомпилированного кода)
детали, которые фронтенду нужно будет повторить 1-в-1.

---

## 1. Точки входа приложения

| Файл | Контекст (`Core\Context\ContextInterface`) | Назначение |
|---|---|---|
| `admin/index.php` | `Admin\Context\AdminContext` напрямую | **Вся admin-панель.** Единственная точка входа для UI: `/admin/index.php?object=campaigns.update&...` |
| `index.php` (корень) | резолвится через `Core\Router\TrafficRouter` по домену/пути/параметрам | Обработка трафика (клики), а также `/admin_api/vN/...` (см. ниже) — роутер матчит путь `/admin_api\/(v[0-9]+)/` на `Admin\Context\AdminApiContext` |
| `api.php` | `Traffic\Context\ClickApiContext` | Публичный **Click API** (программная генерация кликов третьими системами) — не имеет отношения к admin API |
| `gateway.php` | `Traffic\Context\GatewayRedirectContext` | Технический редирект |

Все контексты запускаются через `Core\Kernel\Kernel::run($serverRequest, $context)`
(`application/Core/Kernel/Kernel.php`), который вызывает по порядку:
`bootstrap()` → `modifyRequest()` → `dispatcher()->dispatch()` → `shutdown()`,
а любое исключение уходит в `context->handleException()`.

---

## 2. Роутинг admin-панели: `?object=controller.action`

### 2.1 Разбор URL → контроллер/экшен

`Admin\AdminRequest\AdminRequestFactory::build()` (`application/Admin/AdminRequest/AdminRequestFactory.php`):

1. Если есть параметр `object` (GET или POST) — строка режется по первой точке:
   `object=campaigns.withStats` → controller = `campaigns`, action = `withStats`.
   Если точки нет — action = `index` (`AdminRequest::INDEX_ACTION`).
2. Иначе читаются отдельные параметры `controller` и `action` (легаси-путь).
3. Если контроллер не задан вовсе — подставляется `home.index`.
4. `checkAuthorization()`:
   - контроллер `auth` и "guest"-экшены (`system.addLicenseKey`,
     `system.loadLanguage`, `system.licenseInfo`) пропускаются без проверки;
   - если текущий пользователь (см. §5) не залогинен, ИЛИ (не админ И лицензия
     basic) → **подмена** на `auth.index` (то есть вместо ошибки просто
     отдаётся форма логина как HTML/JSON, HTTP 200);
   - иначе проверяется `AclService::isResourceAllowed($user, controller)` —
     если ресурс (контроллер целиком) не разрешён пользователю — бросается
     `Core\Exceptions\DenyError` (см. §7 — уходит в HTTP 403).

### 2.2 Диспетчеризация экшена

`Admin\Dispatcher\AdminDispatcher::_dispatchControllerAction()`
(`application/Admin/Dispatcher/AdminDispatcher.php`):

1. `Admin\Controller\ControllerRepository::instance()->getController($name)` —
   контроллер ищется по **зарегистрированному имени** (нижний регистр), см. §2.3.
   Если не найден — `NotFoundError` → HTTP 404.
2. Собирается имя метода `<action>Action` (например `withStatsAction`).
   Если такого public-метода нет — `NotFoundError` ("Controller action ... is
   not defined") → HTTP 404.
3. `$controller->init()` (проверяет, что заданы request/response), затем сам
   метод вызывается через `call_user_func`.
4. Возвращаемое методом значение (обычно массив/объект/скаляр) прогоняется
   через `Traffic\Response\ResponseFactory::safeBody()`:
   - `array`/`object` → `json_encode()`;
   - иначе — как есть (например строка HTML для `renderView()`).
5. Итоговый `Response` не всегда имеет `Content-Type: application/json` —
   заголовок это выставляет либо сам dispatcher верхнего уровня для батч-
   запросов (`Response::build(["headers"=>["Content-Type"=>"application/json"]])`),
   либо контроллер вручную через `$this->_sendJson()`. Для одиночных
   `?object=` GET/POST запросов **Content-Type НЕ выставляется автоматически**
   в `AdminDispatcher` — многие экшены явно не вызывают `_sendJson()`, и то,
   что тело — валидный JSON, не гарантирует правильный заголовок. Фронтенду
   стоило ориентироваться по факту (`JSON.parse` тела), а не по заголовку.

### 2.3 Регистрация контроллеров (как имя объекта матчится на класс)

Контроллеры **не** резолвятся по namespace/имени класса — они явно
регистрируются в `Initializer.php` каждого компонента через
`Admin\Controller\ControllerRepository::register($name, $controllerInstance)`,
которое вызывается из `Core\ComponentManager\ComponentManager::instance()->loadControllers($repo)`
(перебирает все компоненты и вызывает `loadControllers()` у каждого).

Пример (`application/Component/Campaigns/Initializer.php`):
```php
$repo->register("campaigns", new Controller\CampaignsController());
```
То есть `object=campaigns.withStats` → имя `"campaigns"` (без учёта регистра,
`strtolower()`) → `CampaignsController::withStatsAction()`.

Список "объектов" (первого сегмента `object=`) для всех задокументированных
ниже компонентов взят из соответствующих `Initializer.php`: `campaigns`,
`streams`, `streamfilters`, `triggers`, `streamactions`, `streamtypes`,
`streamschemas`, `collections`, `favouritestreams`, `streamevents`, `offers`,
`landings`, `editor`, `trafficsources`, `trafficsourcetemplates`, `domains`,
`users`, `groups`, `profile`, `apikeys`, `resource`, `userpreferences`, `auth`,
`geodbs`, `ipinfodatatypes`, `reports`, `favouritereport`, `labels`,
`exportedreports`, `conversions`, `settings`, `dics`, `migrations`,
`legacymigrations`, `adminapi`. Точное имя каждого объекта нужно смотреть в
файле `Initializer.php` соответствующего компонента (`register("имя", ...)`) —
имена регистрации в целом совпадают с "человеческим" множественным числом
модели в camelCase/lowercase (см. таблицу компонентов ниже).

### 2.4 Батч-запросы: `?batch` / `?bulk`

`Admin\Context\AdminContext::_isBatchRequest()` проверяет наличие параметра
`batch` (новое имя) или `bulk` (легаси) — если есть, диспетчером становится
`Admin\Dispatcher\BatchAdminDispatcher` вместо обычного `AdminDispatcher`.

**Формат запроса.** Тело запроса (`getParsedBody()`) — это **массив объектов**,
каждый описывает один под-запрос:
```json
[
  { "params": { "object": "campaigns.index" } },
  { "params": { "object": "streams.update", "id": 5 }, "postData": { "name": "..." } },
  { "params": "..." }
]
```
- `params` — то, что уйдёт в query-параметры под-запроса (если `params`
  пустой, используется весь элемент массива целиком как params — легаси-
  совместимость);
- `postData` — то, что уйдёт в parsed body под-запроса; если это строка —
  предварительно `json_decode($postData, true)`, если уже массив — как есть.

Каждый под-запрос реально прогоняется через **полноценный** `AdminDispatcher`
(со своим `AdminRequestFactory::build()`, то есть ACL/auth проверяются **на
каждый** под-запрос заново, хотя текущий пользователь уже определён один раз
на уровне внешнего HTTP-запроса — см. §5).

**Формат ответа** — JSON-массив, один элемент на под-запрос, в том же
порядке:
```json
[
  { "body": <распарсенный JSON-ответ>, "headers": {...}, "statusCode": 200 },
  { "body": {...}, "headers": {...}, "statusCode": 403 }
]
```
Ошибка в одном под-запросе НЕ прерывает батч — исключение конкретного
под-запроса ловится и прогоняется через `AdminContext::handleException()`,
результат (тоже JSON) попадает в тот же элемент массива со своим
`statusCode`. HTTP-код самого батч-ответа **всегда 200** (сам конверт
не падает), ошибки нужно проверять по `statusCode` внутри каждого элемента.

---

## 3. AdminApi — REST API `/admin_api/vN/...` (это ДРУГОЙ механизм)

Не путать с `?object=adminApi` (см. §12 ниже — это просто страница-заглушка
со ссылкой на внешнюю OpenAPI-спеку). Настоящий REST-роутинг:

- `Admin\Context\AdminApiContext` (`application/Admin/Context/AdminApiContext.php`)
  матчится через `TrafficRouter` по пути `/admin_api/(v[0-9]+)/...`.
- Внутри поднимается `Admin\AdminApi\AdminApiRouter` — обёртка над
  `AltoRouter`, куда каждый компонент регистрирует свои REST-маршруты через
  `Initializer::loadApiRoutes(AdminApiRoutesRepository $repo)`, например
  (`Campaigns/Initializer.php`):
  ```php
  $repo->register(["method"=>"GET","route"=>"/campaigns/[i:id]","desc"=>"...",
      "onMatch"=>function($id){ return ["controller"=>"campaigns","action"=>"show","params"=>["id"=>$id]]; }]);
  ```
  То есть REST-маршрут `GET /admin_api/v1/campaigns/5` **транслируется** в
  тот же самый `object=campaigns.show&id=5` и уходит в тот же
  `AdminDispatcher` → `CampaignsController::showAction()`. REST-слой — это
  чисто алиасинг путей на существующие `object.action`, отдельной бизнес-
  логики в REST-контроллерах нет.
- **Аутентификация REST API — НЕ cookie**, а API-ключ:
  `?api_key=...` или заголовок `Api-Key: ...`. Ключ ищется в таблице
  `ApiKeysRepository` (см. §6 — `Users\Controller\ApiKeysController`), по
  найденному ключу поднимается `user_id` и грузится `CurrentUserService::set($user)`.
  Без валидного ключа — HTTP 401 `{"error": "Invalid API key"}`.
- Доступен только в изданиях с фичей `hasAdminApiFeature()` — иначе HTTP 402
  `{"error": "Admin API is available only in Business editions"}`.
- Если путь не смэтчился ни на один зарегистрированный REST-роут — HTTP 400
  `{"error": "URL '...' does not match any route"}`.
- Формат ошибок REST-слоя **отличается** от обычного admin-контекста:
  `AdminApiContext::handleException()` заворачивает ЛЮБОЕ исключение (кроме
  `LicenseError`) в HTTP 402 `{"error": <message>}` — то есть даже банальная
  `ValidationError` по HTTP-коду выглядит как "требуется оплата". Это
  особенность REST-слоя, для обычных `?object=` запросов коды корректные
  (см. §7).

`Component\AdminApi\Controller\AdminApiController` (объект `adminApi` в
обычном `?object=`, НЕ путать с REST):
- `adminApi.index` → рендерит `views/index.phtml` (просто HTML-страница
  документации/лендинг внутри панели).
- `adminApi.spec` → `redirect("https://admin-api.docs.tds.io/openapi.yaml")`
  (307/302 редирект на внешний YAML со спекой — реальной спеки в коде нет,
  это просто ссылка наружу).

---

## 4. Аутентификация и сессии

### 4.1 Cookie-based (обычная admin-панель)

- Куки-параметр: **`states`** (`Component\Users\Service\AuthService::COOKIE_PARAM`).
- При логине (`auth.login`, см. §6) успешный вход формирует JWT (HS256,
  библиотека `Firebase\JWT`):
  ```
  payload = {
    login:     md5(login . "-tds"),
    password:  urlencode(bcrypt_hash),
    timestamp: unix_time
  }
  token = "v1" . JWT::encode(payload, key, "HS256")
  ```
  (префикс `"v1"` = `AuthService::VERSION_BCRYPT`, ключ —
  `LpTokenService::generateUserKey("_get_for_auth")`, специфичный для
  инсталляции секрет). Кука `states` = этот токен, `maxAge` = 2678400 сек
  (31 день), **`httpOnly = false`** (кука читаема из JS — так сделано
  оригиналом, стоит воспроизвести один-в-один или явно решить исправлять).
- На **каждый** HTTP-запрос к admin-панели (`AdminContext::modifyRequest()`
  → `_switchLocale()`) кука расшифровывается заново
  (`AuthService::loadFromCookieToken()` → `_tryToLoadFromToken()`):
  проверяется `login_hash` + `password_hash` по таблице
  `user_password_hashes` (запись создаётся при каждом логине,
  `expires_at` = момент логина + TTL; если строки `user_password_hashes` нет
  в схеме — легаси-фоллбэк на прямое сравнение `password_hash` в таблице
  `users`), и `timestamp` не старше TTL. Успех → `CurrentUserService::instance()->set($user)`
  на весь остаток текущего PHP-процесса (запроса). **Это НЕ настоящая PHP-
  сессия** ($_SESSION нигде не участвует) — состояние авторизации целиком в
  этом JWT + БД-таблице токенов.
- Logout (`auth.logout`) — `CookiesService::unsetCookie()` + редирект на `?return=...`.

### 4.2 Бан по бруте-форсу

`Component\Users\Service\BruteForceDetectionService` — по IP считает
неудачные попытки логина, при превышении лимита `auth.login` возвращает
`{"message": "..."}` (не ошибка HTTP, а обычный 200 с текстом — фронт должен
сам решать, что это ошибка, по наличию ключа `message`/отсутствию `success`).

### 4.3 Кто есть "текущий пользователь" в контроллере

`Admin\Controller\Helper\AclHelper::getUser()` →
`Component\Users\Service\CurrentUserService::instance()->get()` — просто
геттер, ничего не грузит заново. Устанавливается либо из cookie (§4.1), либо
из API-ключа (§3, REST-слой).

---

## 5. ACL — права доступа

Модели: `Component\Users\Model\AclResource` (`acl_resources`, поле
`resources` — **JSON-массив строк**, доступных ресурсов/разделов меню) и
`Component\Users\Model\AclRule` (`acl`, одна строка на пару
пользователь+тип сущности: `access_type`, `entities` (JSON id'шников),
`groups` (JSON id групп)).

`access_type` — одна из констант `AclRule`:
- `full_access` — доступ ко всем сущностям этого типа (и разрешено
  создавать новые — `createAllowed()` true);
- `created_by_user_groups_and_selected` — доступ к тем, что создал сам
  пользователь + к явно перечисленным группам/сущностям (плюс `createAllowed()` true);
- `to_groups_and_selected` — доступ только к явно перечисленным
  сущностям/группам (без права создавать новые);
- `read_only` — доступ ко всем, но без права редактировать/создавать.

Ключевые методы `Component\Users\Service\AclService` (используются ПОЧТИ в
каждом контроллере):
- `isResourceAllowed($user, $resourceName)` — доступ к разделу меню целиком
  (проверяется в §2.1 при роутинге, а также вручную, например, чтобы решить
  отдавать ли вложенные `streams` внутри `campaigns.show`);
- `filterByAcl($entityList, $forEdit, $user)` — фильтрует массив
  сущностей (кампаний/офферов/...) до тех, что реально разрешены;
- `isViewAllowed($entity)` / `isEditAllowed($entity)` — точечная проверка
  одной сущности (кидают `DenyError` через `$this->throwDeny()`, если false);
- `isCreateAllowed($entityType)` — можно ли создавать сущности этого типа;
- `getAllowedCampaignIds($user)` — либо константа `ALLOW_ANY`/`ALLOW_NONE`,
  либо массив конкретных id — используется в Grid-системе (см. §8) как
  фильтр `campaign_id IN (...)` на уровне SQL;
- `getRestrictedReportColumns($user)` — список названий колонок отчёта,
  которые пользователю скрыты (например деньги);
- `addAuthorPermission($user, $entities, $isGroup)` — при создании сущности
  автоматически даёт автору право на неё (если у него `access_type` типа
  `created_by_user_groups_and_selected`).

`Component\Users\Controller\ResourceController`:
- `resource.mandatory` — список ресурсов, которые доступны всем всегда
  (не фильтруются ACL).
- `resource.complementaryAsOptions` — список опциональных ресурсов для
  формы настройки прав в UI.

`Component\Groups\Controller\GroupsController` — группы кампаний/офферов/
лендингов/etc, используются и для организации, и как объект ACL
(`isEditGroupAllowed`/`isViewGroupAllowed` в `AclHelper`).

---

## 6. Формат ответа и ошибок

Внутри одного `?object=` запроса тело успешного ответа — то, что вернул
метод `<action>Action()` (после `json_encode`, если это массив/объект).
**Единого envelope-формата типа `{success: true, data: ...}` в коде НЕТ** —
разные экшены возвращают разное:
- модель/список моделей после `$this->serialize(...)` — плоский
  массив/объект полей (см. §9);
- некоторые экшены возвращают `["success" => true]` вручную (например
  `campaigns.updateCosts`, `conversions.import` через `["errors"=>...,
  "success"=>...,"total"=>...]`);
- некоторые ничего не возвращают (`NULL` → тело `""`), например
  `streams.archive`, `campaigns.savePositions`.

Ошибки — стандартные PHP-исключения, обрабатываемые единообразно в
`Admin\Context\AdminContext::handleException()`:

| Исключение | HTTP-код | Тело JSON |
|---|---|---|
| `Core\Validator\ValidationError` | 406 (`NOT_ACCEPTABLE`) | `e->getErrors()` (обычно `{field: ["сообщение", ...]}`) |
| `Core\Exceptions\DenyError` | 403 (`FORBIDDEN`) | `{"error": "<сообщение, обычно локализованное>"}` |
| `Core\Application\Exception\EditionError` | 402 (`PAYMENT_REQUIRED`) | `{"error": "<сообщение>"}` (фича недоступна в текущей лицензии) |
| `Core\Exceptions\NotFoundError` | 404 | `{"error": "...", "stacktrace": "..."}` (⚠ стек-трейс отдаётся ВСЕГДА, не только в debug!) |
| `ADODB_Exception` (ошибка БД) | 500, если юзер уже существует в системе; иначе см. ниже | `{"error": "...", "stacktrace": "..."}` либо через `CommonErrorHandler` |
| `Core\Application\Exception\LicenseError` | через `CommonErrorHandler::handleAny()` | HTML, 500, текст либо реального сообщения (debug), либо `"An error occurred. Please check Maintenance > Log"` |
| любое другое `\Exception` | через `CommonErrorHandler::handleAny()` | то же, HTML `Content-Type`, 500 |

Важные нюансы:
- Ветки `NotFoundError`/`ADODB_Exception` отдают JSON, но ветка "все прочие
  исключения" (`CommonErrorHandler::handleAny`) отдаёт **`text/html`**, а не
  JSON — если фронтенд слепо ожидает JSON на любую ошибку, надо быть готовым
  распарсить и HTML-текст (или проверять `Content-Type` перед `JSON.parse`).
- Если БД пуста/юзеров ещё нет (`ADODB_Exception` с текстом про
  `"settings' doesn't exist"` и нет ни одного пользователя) — отдаётся общее
  `"Internal error, please check the log file."` / `"Database is empty."`
  без стектрейса — это осознанная защита от утечки деталей до первой
  настройки системы.
- В `AdminApiContext` (REST, §3) формат другой: **любая** ошибка (кроме
  `LicenseError`) → HTTP 402 `{"error": message}`.

---

## 7. Параметры запроса: как читаются GET/POST/JSON

`Admin\Controller\BaseController`:
- `getParam($name)` — сначала ищет в query-параметрах (`$_GET`
  соответствие), затем в parsed body; **query имеет приоритет**, если ключ
  есть в обоих местах.
- `getPostParam($name)` / `getPostParams()` — **только** parsed body (тело
  запроса), без фоллбэка на query.
- `isPost()` — true, если есть непустое parsed body ИЛИ HTTP-метод POST.

Как парсится тело (`Traffic\Request\ServerRequestFactory::parseBody()`,
вызывается для КАЖДОГО запроса, включая admin-панель):
```php
if ($body[0] in ('{','[')) return json_decode($body, true);       // JSON
if (strstr($body, '&'))     return parse_query($body);            // x-www-form-urlencoded
return NULL;
```
**Content-Type заголовок не учитывается вообще** — тип тела определяется
по первому символу. Это значит фронтенд может слать как классический
`application/x-www-form-urlencoded`, так и голый JSON-body (даже без
правильного `Content-Type: application/json`) — бэкенд отличит их сам по
содержимому. Загруженные файлы (`multipart/form-data`) идут отдельным
путём через `getUploadedFiles()` (например `streams.import`).

Для батч-запросов (`?batch`) — тело **верхнего** запроса всегда JSON-массив
(см. §2.4), а `postData` каждого элемента — либо JSON-строка (будет ещё раз
`json_decode`-нута), либо уже готовый массив.

---

## 8. Сериализация: как модель превращается в JSON-ответ

Все модели (`Core\Model\AbstractModel`) сериализуются через
`Core\Json\SerializerFactory::serialize($payload, $serializer)`
(`application/Core/Json/SerializerFactory.php`) — принимает как одну
модель, так и массив, каждый элемент прогоняется через
`$serializer->serialize($obj)`.

Базовый класс `Core\Json\AbstractSerializer`:
1. `prepare($payload)` — хук до сериализации (например
   `StreamSerializer::prepare()` батчево прелоадит связанные
   фильтры/триггеры/лендинги/офферы для ВСЕХ стримов разом, чтобы не
   делать N+1 запросов).
2. `serialize($obj)`:
   - `$data = $obj->getData()` — сырые поля модели (как в БД);
   - `_onlyFields($data, $this->_fields)` — если `$_fields` это **массив
     имён** — оставляет только эти ключи (белый список); если `$_fields
     === true` — **пропускает без изменений все** поля модели (частый
     паттерн в этом проекте — `CampaignSerializer`, `StreamSerializer`,
     `OfferSerializer`, `LandingSerializer`, `TrafficSourceSerializer`,
     `DomainSerializer`, `GroupSerializer` все используют `$_fields = true`);
   - `extra($obj, $data)` — хук ПОСЛЕ фильтрации полей, добавляет
     вычисляемые/связанные поля (см. таблицы ниже по каждому компоненту) и
     часто **удаляет** служебные поля (`unset($data['mode'])` и т.п.);
   - опциональные `$exclusions` — ключи, которые нужно вырезать из ответа
     (используется редко).
3. `_flatTimestamps($data)` — `created_at`/`updated_at` (если это `DateTime`)
   форматируются в строку через `AbstractModel::DATETIME_FORMAT`.

### ⚠ Паттерн "JSON внутри поля модели" — must-know для фронтенда

В БД у многих моделей есть поля, хранящие **JSON-строку** (не сериализованный
PHP-массив), которую соответствующий геттер модели обязан
`json_decode()`-ить на чтении и `json_encode()`-ить на записи. Это уже стало
источником нескольких серьёзных багов декомпиляции (см.
`docs/FIXES_LOG.md`, раздел "Прочее"/"Пользователи / ACL"), поэтому при
восстановлении фронтенда/контракта важно опираться на **распакованную**
форму, а не на сырое поле БД:

| Модель.поле (БД, сырое) | Геттер (даёт декодированный вид) | Где используется |
|---|---|---|
| `stream_filters.payload` | `StreamFilter::getPayload()` | конфигурация ~24 типов фильтров стрима (страна/регион/город/язык/браузер/ОС/IP и т.д.) |
| `streams.action_options` (для схемы `action`) | `BaseStream::getActionOptions()` | прямые действия стрима (curl/local_file/…) |
| `offers.action_options` / `landings.action_options` | тот же `getActionOptions()` (общий трейт) | `{"folder": "..."}` для local_file — читает `EditorRepository`/`EditorService`/`LandingDownloaderService` |
| `acl_resources.resources` | `AclResource::getResources()` | список разрешённых "объектов" меню для не-админа |
| `campaigns.parameters` | `Campaign::getParameters()` | алиасы GET-параметров кампании (`CheckParamAliasesStage` в клик-пайплайне, а также `reports.parameterAliases`) |
| `acl.entities` / `acl.groups` | `AclRule::getEntities()`/`getGroups()` (хранятся как массив, но модель ожидает JSON на входе через валидатор) | правила доступа |

Общий совет: если в новом фронтенде понадобится читать/писать подобное поле
напрямую (в обход существующего Serializer/Controller) — обязательно
проверить, декодирует ли соответствующий геттер модели JSON, а не полагаться
на "как в БД".

---

## 9. Grid-система (`withStats`, отчёты, фильтры, сортировка, пагинация)

Общая инфраструктура в `application/Component/Grid/*` используется и для
"списков с метриками" сущностей (Campaigns/Streams/Offers/Landings/
TrafficSources — экшен `withStats`), и для чистых отчётов
(`Component\Reports`). Также — `Component\Conversions` (`conversions.log`) и
`Component\Clicks`.

### 9.1 Определение грида (`GridDefinition` + `Column`)

`Component\Grid\Definition\GridDefinition` (абстрактный) — у каждой сущности
свой класс-наследник, например `CampaignGridDefinition`,
`OfferGridDefinition`, `LandingGridDefinition`, `TrafficSourceGridDefinition`,
`ConversionsLogDefinition`. **Важно**: конкретные "withStats"-дефиниции
(`CampaignGridDefinition` и т.п.) **наследуются от**
`Component\Reports\Grid\ReportDefinition` — то есть колонки сущности
(`id`, `name`, `group`, ...) добавляются ПОВЕРХ полного набора отчётных
метрик по кликам (клики/уники/конверсии/деньги/гео и т.д., см. ниже) —
именно поэтому экшен называется `withStats`: сущности приджойнены к
статистике кликов по `<entity>_id`.

Каждая колонка (`Component\Grid\Definition\Column`) описывается опциями:
`type` (`boolean|integer|decimal|string|datetime|date|ip|version|enum|json`),
`title`/`th_title` (i18n-ключи), `sortable`, `filter` (тип для UI-фильтра),
`groupable`, `category` (для группировки в UI: `data`/`calendar`/`metrics`/
`money`/`ip`/...), `sort_by`/`group_by` (SQL override), `inner_select` /
`outer_select` (сырое SQL-выражение колонки — либо на уровне `GROUP BY`-
агрегации, либо пост-обработка над уже посчитанными колонками),
`relation` (JOIN на другую таблицу/дименшн, например IP/гео), `summary`
(участвует в строке итогов), `metric` (это метрика отчёта, а не поле
сущности), `formatter` (как рендерить: `money`, `percentage`, `datetime`,
`hour`, `week`, `weekday`, `list`, `object`, ...), `decorator`
(пост-обработка значения на PHP-стороне после SQL, напр. IP-маски),
`required_columns` (какие ещё колонки нужно достать из SQL, чтобы посчитать
эту), `virtual` (нет прямого SQL-select, значение строится в
Serializer/decorator), `hidden`, `width`, `resizable`.

`GridDefinition::getGridDefinition()` — то, что возвращает экшен
`<entity>.gridDefinition` (например `campaigns.gridDefinition`,
`offers.gridDefinition`, `landings.gridDefinition`,
`trafficSources.gridDefinition`, `reports.definition`,
`conversions.logDefinition`):
```json
{
  "url": "?object=campaigns.withStats",
  "details": null,
  "range_intervals": [...],
  "columns": [ { "name": "...", "type": "...", "title": "...", ... }, ... ]
}
```
Это то, из чего фронтенд строит колонки таблицы/фильтров/список метрик.
`<entity>.columnsAsOptions` / `<entity>.listAsOptions` на грид-дефиниции
(`Reports\Controller\ReportsController::columnsAsOptionsAction` →
`GridDefinition::listAsOptions()`) отдают более компактный список `{category,
name, value}` для UI-селектора колонок.

### 9.2 Запрос данных (`QueryParams`)

Экшены типа `withStats`/`reports.build`/`conversions.log` принимают POST-
тело (`getPostParams()`), которое парсится в
`Component\Grid\QueryParams\QueryParams`
(`application/Component/Grid/QueryParams/QueryParams.php`). Поддерживаемые
ключи запроса:

| Ключ | Тип | Смысл |
|---|---|---|
| `columns` | `string[]` | какие колонки вернуть; если пусто — все колонки дефиниции |
| `grouping` (алиас `dimensions`) | `string[]` | по каким колонкам делать `GROUP BY` (должны входить в `columns`) |
| `metrics` | `string[]` | какие метрики агрегировать (аналогично columns, но для отчётов) |
| `sort` | `[{name, order}]` | `order` = `ASC`/`DESC`, колонка должна быть в `columns` (иначе тихо игнорируется, не ошибка) |
| `filters` | `[{name, operator, expression, case_sensitive?}]` | см. операторы ниже |
| `range` | `{interval\|from\|to}` | временной диапазон (обязателен, если не задан `limit` — см. `_checkRange`) |
| `limit` / `offset` | `int >= 0` | пагинация (хотя бы одно из `range`/`limit` обязательно) |
| `summary` | `bool` | включить блок агрегатов `summary` в ответ |
| `format` | `string` | `array` (JSON, дефолт) или `csv`/др. — см. `RendererFactory` |

Операторы фильтров (`Component\Grid\Query\FilterItem`): `EQUALS`,
`NOT_EQUAL`, `GREATER_THAN`, `EQUALS_OR_GREATER_THAN`, `LESS_THAN`,
`EQUALS_OR_LESS_THAN`, `MATCH_REGEXP`, `NOT_MATCH_REGEXP`, `BEGINS_WITH`,
`IP_BEGINS_WITH`, `ENDS_WITH`, `CONTAINS`, `NOT_CONTAIN`, `IN_LIST`,
`NOT_IN_LIST`, `BETWEEN`, `IS_SET`, `IS_NOT_SET`, `IS_TRUE`, `IS_FALSE`,
`HAS_LABEL`, `HAS_NOT_LABEL` (регистронезависимо, приводится к верхнему
регистру). Фильтр по `ip`/`ip_mask*` с оператором отличным от
`IP_BEGINS_WITH` автоматически конвертирует строковый IP в `ip2long`.

### 9.3 Построение SQL и ответ (`GridBuilder` / `EntityGridFactory`)

Для чистых отчётов: `ReportsController::buildAction()` →
`ReportRepository::get()` → `GridBuilder::factory($queryParams, $userParams)`
→ строит `Select`/`From`/`Filter`/`Grouping`/`Sort`/`Limit`/`Offset`/`Joins`
(`Component\Grid\Query\*`) → `build()` выбирает Renderer по
`queryParams->getFormat()` (`Component\Grid\Renderer\RendererFactory`,
JSON/CSV/HTML) → `JsonRenderer::create()` возвращает:
```json
{
  "rows": [...],
  "total": 1234,
  "summary": { ... },   // если summary=true в запросе
  "meta": { "execution_time": "0.1234", "datetime": "2026-08-27T12:00:00+00:00" }
}
```
`total` — реальный `SQL_CALC_FOUND_ROWS` (`Db::getFoundRowsCount()`), т.е.
общее число строк без `LIMIT` — используется UI-пагинацией.

Для `withStats` сущностей (`CampaignRepository::allWithStats()`,
аналогично Offer/Landing/TrafficSource repositories) — используется
**другой** билдер, `Component\EntityGrid\EntityGridFactory`:
1. Загружает саму сущность (`repository()->all(...)` / `allWithRelations()`)
   с базовыми `filters` (кроме `state`, который обрабатывается отдельно —
   спец-значение фильтра `state = "with_clicks"` отключает merge-заполнение
   нулями для сущностей без кликов);
2. Сериализует сущности обычным Serializer'ом сущности;
3. Отдельно тянет метрики (клики/конверсии/деньги) через
   `ReportRepository::get()` с `grouping: ["<entity>_id"]` и
   `filters: [...,{"name":"<entity>_id","operator":"IN_LIST","expression": [id...]}]`;
4. Мёржит статистику с сущностями по id (`_merge()`), сущностям без кликов
   проставляет нулевые метрики (если не выключено фильтром `with_clicks`).
   Итог — тот же формат `{rows: [...], meta: {total: N}}` (без `summary`).

`AccessRestriction`/`DeletedCampaignRestriction`
(`Component\Clicks\Grid\*`, подключаются в `GridBuilder::factory()`) —
автоматически добавляют в фильтр ограничение по разрешённым
`campaign_id` (из ACL, см. §5) и исключают удалённые кампании — фронтенду
не нужно/нельзя эмулировать это самому, оно всегда применяется на бэкенде.

---

## 10. Компоненты — детальный разбор эндпоинтов

Общий CRUD-паттерн (повторяется почти во всех entity-контроллерах,
реализующих `Admin\Controller\EntityControllerInterface`: `createAction`,
`updateAction`, `archiveAction`, `cleanArchiveAction`):

- `index` / `withStats` / `listAsOptions` / `deleted` / `show` — чтение,
  список фильтруется через `AclService::filterByAcl()`;
- `create` — проверка `isCreateAllowed(EntityClass::aclKey())` → `Service::create($postData)`
  → `AclService::addAuthorPermission()`;
- `update` — `find(id)` → `isEditAllowed()` → `Service::update($entity, $postData)`;
- `archive` / `restore` — принимает `id` **или** `ids` (масс-операция),
  фильтрует через ACL (`forEdit=true`), затем поштучно вызывает
  `Service::archive()`/`makeActive()`;
- `clone` — то же, плюс `addAuthorPermission()` на копии;
- `saveNote` — общий паттерн заметки (`note`) почти у всех сущностей;
- `cleanArchiveAction` — физическое удаление всей "корзины" (`PruneTask\Prune*`).

Ниже — только специфичные детали и нетривиальные вещи по каждому компоненту
(полный список экшенов см. в самом файле контроллера).

