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

### 10.1 Campaigns (`object=campaigns`)

Файл: `application/Component/Campaigns/Controller/CampaignsController.php`.

| Экшен | Вход | Выход / логика |
|---|---|---|
| `gridDefinition` | — | `CampaignGridDefinition::getGridDefinition()` (§9.1) |
| `listAsOptions` | `add_blank`, `include_disabled` (bool), `key` | `[{id,name,group_id,group,value}]`, отфильтровано ACL |
| `index` | `active` (bool) | все активные (`allActive`) либо все неудалённые (`allNotDeleted`); `extended`-параметр не читается тут напрямую, но передаётся как `$this->getParam("extended")` в `CampaignSerializer` |
| `withStats` | POST body = `QueryParams` (§9.2) | `EntityGridFactory` (§9.3) |
| `show` | `id`, `withStreams` (bool) | `CampaignSerializer(true, withStreams)`; `withStreams` дополнительно принудительно гасится в `false`, если у юзера нет доступа к ресурсу `streams` |
| `create`/`update` | body сущности + опционально `streams: [...]` (вложенный массив стримов) | создаёт/обновляет кампанию, затем **отдельно** `StreamService::updateStreams(campaign, streams)` — то есть стримы кампании можно создавать/обновлять "одним запросом" вместе с кампанией; в trial-режиме есть доп. лимиты на число кампаний/стримов |
| `restore`/`enable`/`disable`/`archive` | `id` или `ids` | масс-операции |
| `clone` | `id`/`ids` | клонирует кампанию (и, судя по `CampaignService`, связанные стримы через `cloneResource`) |
| `savePositions` | `ids`, `group_id` | сохраняет порядок кампаний внутри группы (drag&drop в UI) |
| `updateCosts` | `id`, POST: `start_date`,`end_date`,`cost`,`filters`,`currency`,`timezone`, `only_campaign_uniques` | асинхронная задача через `UpdateCostsBulkCommand::enqueue()` (см. `Component\DelayedCommands`) — массовый пересчёт стоимости кликов за период |
| `costTypes` | — | справочник типов стоимости |
| `token` | `id` | `{"token": "..."}` — токен кампании (используется в трекинг-ссылке) |
| `getBindVisitorTypes` | — | справочник режимов "привязки визитора" (sticky routing) |
| `saveNote` | `id`, `note` | |
| `updateStreams` | `campaign_id`, `streams` | то же обновление стримов, что и внутри `update`, но отдельным вызовом |
| `cleanArchive` | — | физическая очистка корзины кампаний |

**Сериализация** (`CampaignSerializer`, `$_fields = true` — то есть в JSON
попадают ВСЕ сырые поля модели `campaigns`, плюс):
`domain` (резолвится из `domain_id` в реальный URL через `DomainService`),
при базовом вызове (`$extended=false`) — этим и ограничивается; при
`extended=true` (используется в `show`/`create`/`update`/`clone`/...)
добавляются: `group` (имя группы или "Default"), `streams_count`, `ts`
(имя источника трафика), `postbacks` (массив, сериализован
`CampaignPostbackSerializer`); при `withStreams=true` (только `show`)
добавляется `streams` — полный вложенный список стримов кампании
(`StreamSerializer(true, true)` — с событиями и capacity офферов). Также:
`cost_type=CPV` подменяется на `CPC` "на лету" (легаси-совместимость),
`cost_value` приводится к `int`, если у кампании cost-модель revshare,
и удаляется служебное поле `mode`.

### 10.2 Streams (`object=streams`) + связанные компоненты

Файл: `application/Component/Streams/Controller/StreamsController.php`.
Стримы **всегда** привязаны к кампании — доступ проверяется через ACL
родительской кампании (`isEditAllowed($campaign)`/`isViewAllowed($campaign)`),
не напрямую по стриму.

| Экшен | Вход | Логика |
|---|---|---|
| `index` | `campaign_id` | все стримы кампании, в порядке `allOrderedStreamsForCampaign` |
| `listAsOptions` | `exclude` | список активных стримов по всем разрешённым (ACL) кампаниям |
| `deleted` | — | архивные стримы, тоже с фильтром по разрешённым campaign id |
| `show`/`create`/`update`/`disable`/`enable` | `id`/`campaign_id`+body | стандартно; `disable`/`enable` при одном id возвращают объект напрямую (не массив), при нескольких — массив |
| `deleteAction` | `id`/`ids` | на самом деле **архивирует** (`archiveStream`), физического удаления тут нет (см. `cleanArchive`) |
| `replace` | `ids`, `from`, `to` | точечная замена значения (например замена одного оффера на другой во всех выбранных стримах — см. `StreamService::replace`) |
| `createInCampaign` | `campaign_id`, `streams: [...]` | массовое создание нескольких стримов сразу + `resortStreams()` |
| `search` | `query` | полнотекстовый поиск стримов (`Component\Streams\SearchStreams`) в разрешённых кампаниях |
| `currentLimitValues` | `campaign_id` | текущее состояние Hit Limit (капы визитов) на каждый стрим кампании |
| `import` | multipart-файл `file`, `campaign_id`, `save` (bool) | загрузка JSON-экспорта стримов; если `save=false` — только валидация/предпросмотр без сохранения (судя по сигнатуре `ImportStreams::import(campaign, content, save)`); в trial — доп. лимиты по числу фильтров/стримов |
| `export` | `campaign_id` (POST) | генерирует файл экспорта, возвращает `{"url": "<полный URL до файла>"}` |
| `archive` | — | **пустой метод-заглушка** (нужен только для соответствия `EntityControllerInterface`, реальное архивирование — через `delete`) |
| `cleanArchive` | — | физическая чистка корзины стримов |

**StreamSerializer** (`$_fields = true`): убирает legacy-поля из
`TdsMigrator7::getTds6Fields()`, добавляет (если `withEvents`)
`unread_events_count`, всегда убирает `landing_id`/`offer_id`/`status`/
`updated_at`, и всегда добавляет через `_addAssociation()` (⚠ починенный баг —
раньше терялось при чтении, см. FIXES_LOG): `filters` (StreamFilter[]),
`triggers` (StreamTrigger[]), `landings` (StreamLandingAssociation[]),
`offers` (StreamOfferAssociation[], с capacity-инфой если `withOffersCapacity`).
`prepare()` — батч-прелоад этих associations для всего списка стримов разом
(`PreloadedResourceRepository`) — важно для фронтенда: `index`/`withStats`
по стримам НЕ делает N+1, все связанные сущности приходят вместе с гридом.

**StreamSchemaRepository** (`object=streamSchemas.listAsOptions`) — три
доступные "схемы" стрима (`Traffic\Model\BaseStream`): `LANDINGS`
(стрим показывает лендинг → лендинг может показать оффер), `REDIRECT`
(алиас в некоторых местах называется `OFFERS`/`redirect` — прямой редирект
на оффер без лендинга) и `ACTION` (произвольное прямое действие: curl,
show_text, 404, local_file и т.п., без сущностей "оффер"/"лендинг" вообще).
Именно от схемы зависит, какие action-поля стрима значимы — при схеме
`ACTION` заполняются `action_type`/`action_payload`/`action_options`
напрямую на стриме (см. `ChooseStreamStage` в клик-пайплайне, §11).

**StreamFilters** (`object=streamFilters`, единственный экшен
`filters` → `FilterRepository::getFiltersAsOptions()` — справочник ~24 типов
фильтров таргетинга для UI-конструктора условий стрима: country/region/
city/language/browser/os/ip/isp/connection_type/device и т.д., каждый со
своим набором полей формы; сама валидация/payload каждого типа фильтра
живёт в `Traffic\Model\StreamFilter::getPayload()` — JSON-конфиг конкретного
фильтра).

**Triggers** (`object=triggers`): `update` — назначает список триггеров
стриму (`{stream_id via getParam("id")}`, `triggers` — массив конфигов),
`targets`/`conditions`/`actions` — справочники для конструктора триггера
(на какое событие реагировать / какое условие проверять / что сделать —
например автоматически выключить стрим при падении конверсии).

**StreamActions** (`object=streamActions.index`) — справочник типов прямых
действий (curl/show_text/show_html/local_file/404/to_campaign/frame/iframe/
sub_id/do_nothing), используется когда схема стрима = `ACTION`.

**StreamTypes** (`object=streamTypes.listAsOptions`) — справочник типов
стрима (`forced`/`regular`/`default` по весу/позиции — см. `Traffic\Model\Stream::TYPE_*`).

**Collections** (`object=collections`) — общий справочник-хаб для форм
таргетинга стрима, используется и StreamFilters, и напрямую формами:
`browsers`, `countries` (`only=`CSV кодов, `addBlank`, `exclude_unknown`),
`cities` (`query`+`limit`, автокомплит), `isp` (`q`+`limit`), `regions`
(`query`/`only`+`limit`), `languages` (`only`), `os`, `deviceModels`
(`query`+`limit`), `deviceTypes` (`only`/`addBlank`), `operators`
(мобильные операторы, `query`/`addBlank`/`only`), `connectionTypes`.

**FavouriteStreams** (`object=favouriteStreams`) — избранные стримы
пользователя: `index`/`add`(`stream_id`)/`remove`(`stream_id`), доступ по
ACL родительской кампании стрима.

**StreamEvents** (`object=streamEvents`) — лог событий стрима (например
срабатывание триггера): `index` (`stream_id`,`limit`,`page` — постраничный
список, **побочный эффект**: при чтении непрочитанные события помечаются
прочитанными `state: UNREAD → READ`!) и `clear` (полная очистка лога
конкретного стрима).

### 10.3 Offers (`object=offers`)

Файл: `application/Component/Offers/Controller/OffersController.php`.
Паттерн полностью аналогичен Campaigns/Landings (Grid+CRUD+archive/clone/
restore/saveNote). Специфика:
- `create`/`update`: если `offer->isLocal()` (загруженный локальный оффер,
  архив с файлами — та же LocalFile-инфраструктура, что и у лендингов, §10.4)
  — после сохранения синхронно генерируется превью-скриншот
  (`PreviewImageService::createPreview()`);
- `download` (`id`) — скачивание ZIP-архива файлов локального оффера
  (`LandingDownloaderService::getPackedFile($id, "offer")`), заголовки
  ответа (`Content-Disposition` и т.п.) прокидываются из
  `getHeadersDownload()`;
- `getCostTypes` — справочник типов стоимости оффера (CPA/RevShare/...).

**OfferSerializer** (`$_fields = true`): если `withGroupName=false` —
убирает `group`/`affiliate_network` из ответа; `affiliate_network_id = null`
нормализуется в `0`; если `action_type == "local_file"` — добавляет
`preview` (путь к скриншоту, через общий трейт `addPreviewData`); если у
оффера включён `conversionCapEnabled` — добавляет `conversion_cap` (текущее
значение суточного/др. капа конверсий, `ConversionCapacityRepository`).
`StreamOfferAssociationSerializer` (вложенный оффер внутри стрима, см.
StreamSerializer §10.2) при `withOfferCapacity=true` добавляет
`daily_cap`/`conversions` для отображения "исчерпания" оффера в UI стрима.

### 10.4 Landings (`object=landings`) + LocalFile/Sandbox

Файл: `application/Component/Landings/Controller/LandingsController.php`,
CRUD аналогичен Offers. `create`/`update` дополнительно проверяют demo-режим
(`ConfigService::isDemo()` запрещает грузить архивы в демо), `download` — как
у Offers, но `type = "landing"`.

**LocalFile — как загружается ZIP лендинга/оффера** (общий трейт
`ActionableResourceTrait`, `application/Component/Landings/Mixin/ActionableResourceTrait.php`,
используется и Landings, и Offers через сервисы):
- Тело `create`/`update` может содержать поле **`archive`** — data-URI
  base64 ZIP-архива (`data:application/zip;base64,...` или
  `data:application/x-zip-compressed;base64,...`);
- если тип сущности `local` — генерируется уникальное имя папки (slug от
  `name` + случайный суффикс при коллизии), архив распаковывается во
  временную папку `<folder>/_tmp`, ищется "главная" директория с
  `index.php`/`index.html` (`findMainFolder`), валидируется
  (`Validator` — проверка на недопустимые файлы/PHP если PHP запрещён
  настройками `LP_ALLOW_PHP`/demo-режимом), затем копируется в целевую папку
  хранилища лендингов; на модели остаётся `action_options = {"folder": "..."}`;
  поле `archive` в БД **не хранится** (`unset` перед сохранением) — это
  чисто транспортное поле запроса;
- Клонирование (`clone`) физически копирует директорию файлов
  (`_cloneContentFor`).
- Хранилище — `LocalFileService::getStoragePath()` (настраиваемый корень),
  `absoluteToLocalPath()`/`buildPath()` — резолвинг путей.

**Sandbox — как реально отдаётся локальный лендинг по клику** (важно для
понимания, но это НЕ admin API — часть click-пайплайна,
`Traffic\Actions\Predefined\LocalFile::_execute()`, §11): страница
оборачивается в `Component\Landings\LocalFile\PageWrapper::wrap()`:
- если PHP запрещён (demo/настройка `LP_ALLOW_PHP=0`) или файл `.html`/`.htm`
  — читается как обычный текст, без исполнения;
- иначе исполняется через `Core\Sandbox\SandboxFactory` (выбор движка —
  `CgiExecutor` (php-cgi) или `FcgiExecutor` (php-fpm) по конфигу
  `system.sandbox_engine`) — то есть `.php`-файлы локального лендинга
  реально исполняются отдельным PHP-процессом/FPM, а не через `eval`;
- после рендера тело прогоняется через `_adaptBody()`: правка относительных
  ссылок/ресурсов/`<form action>` под текущий домен показа, и **обработка
  макросов** (`Traffic\Macros\MacrosProcessor::process()` — плейсхолдеры
  вида `{macro}` в HTML, подставляющие данные клика/кампании).

**LandingSerializer** (`$_fields = true`): убирает `group`, если
`!withGroupName`; для `local_file` добавляет `preview` так же, как у
Offers.

### 10.5 Editor — редактор кода лендингов/офферов (`object=editor`)

Файл: `application/Component/Editor/Controller/EditorController.php`.
Применим только к сущностям с `action_type == "local_file"` (иначе
`EditorRepository::checkLocalType()` бросает `ValidationError` с текстом
"Only local landing available").

| Экшен | Вход | Выход |
|---|---|---|
| `loadFiles` | `id`, `type` (`landing`\|`offer`) | дерево файлов/папок (`{name, toggled, type:"root", children:[{name,type:"folder"/"file",path,ext,children}]}`), строится через `Symfony\Finder` по папке `action_options.folder` |
| `loadFileData` | `id`, `path`, `type` | `{"data": "<содержимое файла>"}` (переносы `\r` вырезаются) — **`path` не санитизируется от `../`** на этом уровне (обход каталога потенциально возможен, если фронтенд/итоговый API не валидирует путь дополнительно — стоит перепроверить при пересборке) |
| `saveFileData` | `id`, `path`, `data`, `type` | сохраняет файл, затем асинхронно ставит в очередь пересоздание превью-скриншота (`CreatePreviewImageCommand::enqueue`) |
| `createFile` | `id`, `path`, `type` | создаёт новый файл/папку |
| `removeFile` | `id`, `path`, `type` | удаляет + пересоздание превью |
| `infoLanding` | `id`, `type` | сериализованная сущность (Offer- или LandingSerializer, в зависимости от `type`) — метаданные для шапки редактора |

Доступ везде проверяется как `isEditAllowed($model)` (для `infoLanding` —
дополнительно ещё раз `isViewAllowed`).

### 10.6 TrafficSources (`object=trafficSources`) + шаблоны

Файл: `application/Component/TrafficSources/Controller/TrafficSourcesController.php`.
CRUD-паттерн идентичен Campaigns/Offers. Специфика:
- `postbackStatuses` — справочник статусов постбека для UI настройки
  постбека источника;
- `availableParameters` — справочник плейсхолдеров URL-параметров, которые
  можно прокидывать в трекинг-ссылку источника (`ParameterRepository`);
- `parameterAliases` (`ts_id`) — алиасы этих параметров, заданные конкретно
  для источника (используется в клик-пайплайне, `CheckParamAliasesStage`).

`TrafficSourceTemplatesController` (`object=trafficSourceTemplates`) —
`index` (весь каталог преднастроенных шаблонов интеграции с популярными
рекламными сетями) / `find` (`name` — конкретный шаблон, содержит преднастроенные
макросы параметров именно под эту сеть).

**TrafficSourceSerializer** — тривиальный, `$_fields = true` без `extra()`.

### 10.7 Domains (`object=domains`) + DomainChecker

Файл: `application/Component/Domains/Controller/DomainsController.php`.
- `create`: доп. проверки — фича мультидоменности (`hasDomainsFeature()`,
  если её нет — можно завести только один домен, `name` режется по запятой
  и берётся первый сегмент), после создания `network_status = validating`;
- `update`: нельзя сменить `name` у уже активного домена (тихо
  вырезается из данных), `network_status`/`is_ssl` тоже не редактируются
  напрямую этим экшеном; есть трансляция легаси-полей `redirect` →
  `ssl_redirect` (`"https"` → true) и `is_robots_allowed` → `allow_indexing`;
- `updateStatus` (`id`/`ids`): при **одном** домене — синхронная проверка
  (`DomainCheckerService::updateDomainsStatus()` — реальный HTTP-запрос на
  `?_ping=domain`, см. `PING_DOMAIN_PATH`, к самому домену через
  `_prepareDomainsPromises`/Guzzle, результат — `network_status`
  `active`/`error` + `error_description`); при **нескольких** —
  `prepareMassCheck()` просто переводит все в `validating` (асинхронная
  проверка предположительно фоновым кроном, синхронного ответа со статусом
  не будет — фронту нужно поллить `domains.index`/`show`);
- `listAsOptions`: `add_default` (bool) — включать ли "дефолтный" пустой
  вариант в список.

**DomainCheckerService** (детали, важные для UI): SSL-статусы
(`Domain::SSL_STATUS_*`), `NETWORK_STATUS_*`
(`validating`/`active`/`error`), лимит попыток выпуска SSL
(`LIMIT_SSL_ATTEMPTS = 25`), экспоненциальный бэкофф следующей проверки
(`NEXT_CHECK_RATE = 1.5`, `setNextCheck()`), у самого пинга есть эндпоинт
`?_ping=domain` (не под `?object=`, отдельный контекст `PingDomainContext`
в `TrafficRouter` — небольшая незащищённая ручка для само-теста домена).

**DomainSerializer**: добавляет `campaigns_count` (сколько кампаний
использует домен, из предзагруженного словаря), punycode-декодирование
`xn--`-имён в человекочитаемый юникод, `default_campaign` (имя кампании по
`default_campaign_id`), `error_solution` (человекочитаемая подсказка по
`error_description`, `DomainErrorsService`).

### 10.8 Users / Groups / ACL (`object=users`, `groups`, `profile`, `apiKeys`, `resource`, `userPreferences`, `auth`)

**`UsersController`** — управление пользователями (только `isAdmin()`,
плюс проверка фичи `hasUsersFeature()` для `index`): `index`
(`DecoratedUserSerializer` — по сути `UserSerializer` без изменений,
"decorated" исторический артефакт), `create`/`update`/`delete`,
`setAccessData` (`user_id`/`id`, `access_data` — сохраняет ACL-правила
через `AclService::saveAcl()`, см. §5).

**`ProfileController`** — свой профиль (без `isAdmin`): `currentAccess` (свои
ACL-права), `show`, `update` (смена пароля с проверкой текущего +
обновление произвольных preferences одним вызовом), `languages`
(статический справочник ru/en), `timezones`.

**`ApiKeysController`** — API-ключи для REST AdminApi (§3): `getAll`
(`userId` — только для админа, смотреть чужие ключи), `add` (генерирует
случайный ключ), `delete` (`keyId`). `ApiKeySerializer`: только `id`,
`key`, `datetime` (форматируется через локаль).

**`UserPreferencesController`** — key-value настройки пользователя (UI:
язык таблиц, часовой пояс, свёрнутые панели и т.п.): `index`, `get`
(`pref_name`), `set` (`pref_name`+`pref_value`).

**`ResourceController`** — см. §5 (`mandatory`, `complementaryAsOptions`).

**`GroupsController`** — группы сущностей (кампании/офферы/лендинги/...,
`type` определяет, к какому типу сущности относится группа): `listAsOptions`,
`index` (`extended` — добавляет `count` элементов в группе),
`create`/`update`/`delete`, доступ через `isEditGroupAllowed`/по
`isCreateAllowed` с типом = `GroupService::getAclEntityType($type)`.

**`AuthController`** (без namespace-проверки авторизации, см. §2.1):
`index` — HTML-форма логина (`login.phtml`, серверный рендер через
`renderView`, не JSON!), `login` (POST `login`,`password` → проверка
brute-force → `AuthService::findUserByLoginAndPassword()` →
`storeSession()` ставит cookie `states` и возвращает `{"success": true}`
либо `{"message": "<локализованная ошибка>"}` с HTTP 200 в обоих случаях
— ошибки логина различаются **только по содержимому**, не по коду
ответа), `logout` (чистит cookie, редиректит на `?return=...`).

### 10.9 GeoDb (`object=geoDbs`, `ipInfoDataTypes`)

`GeoDbsController`: `index` (список подключённых баз/провайдеров гео-данных
с проверкой доступности обновлений, `GeoDbSerializer(checkUpdates=true)`),
`settings`/`saveSettings` (настройки автообновления баз), `update` (`id`,
только админ — принудительно скачать/обновить конкретную базу,
`GeoDbService::update()`). `GeoDbSerializer` — НЕ наследует
`AbstractSerializer`, реализует `SerializerInterface` напрямую, отдаёт:
`id, name, type, exists, path, data_types, status_code, status_text, time,
is_recommended, setting_key, purchase_link, key, update_available` (или
`error`, если проверка обновления упала). `IpInfoDataTypesController.index`
— статический справочник типов IP-данных (`IpInfoType::all()`).

### 10.10 Reports / Grid (`object=reports`, `favouriteReport`, `labels`, `exportedReports`)

`ReportsController` (см. также §9): `definition` (грид-дефиниция отчёта),
`build` (основной билдер отчёта, §9.3), `summary` (отдельная сводка через
`ClickRepository::summary()`, не через грид), `columnsAsOptions`
(компактный список колонок для UI-селектора), `parameterAliases`
(`campaign_id` — алиасы GET-параметров кампании, дублирует то же самое, что
есть в TrafficSources, но в контексте кампании), `statsForCampaign`
(`campaign_id`, `range` — краткая сводка статистики кампании, например для
карточки/дашборда).

`FavouriteReportController` — сохранённые пользователем конфигурации
отчёта (набор колонок/фильтров/группировок как "закладка"): `index`
(свои закладки), `create`/`update`/`delete` (POST-тело — вероятно, тот же
формат, что и `QueryParams`, плюс `name` закладки — см.
`FavouriteReportValidator` за точным списком обязательных полей).

`LabelsController` — метки (labels) на значениях рефереров/под-ID в отчёте
(пользовательские теги для группировки трафика): `labelVariations`/
`refNameVariations` (справочники), `index` (`campaign_id`,`ref_name`,
`label_name` — **⚠ похоже на баг/артефакт декомпиляции**: метод приводит
результат `labelsFor()` к `(int)`, хотя судя по имени должен возвращать
список меток — при пересборке фронтенда стоит перепроверить фактическое
поведение в живой системе, а не полагаться только на тип из кода), `update`
(`campaign_id`,`ref_name`,`items` — сохранить метки), `replaceList`
(`campaign_id`,`ref_name`,`ref_values`,`label_name` — массовая замена).

`ExportedReportsController` — файлы экспортированных отчётов (CSV/др.,
генерируются Grid Renderer'ом, §9.3): `index` (список файлов),
`delete`/`deleteAll` (`filename`, запрещено в demo-режиме).

### 10.11 Conversions (`object=conversions`)

Файл: `application/Component/Conversions/Controller/ConversionsController.php`.
- `logDefinition` — грид-дефиниция лога конверсий (`ConversionsLogDefinition`,
  тоже вероятно наследник `ReportDefinition`-подобного класса);
- `updateCostDefinition` — **другая** грид-дефиниция
  (`Component\Clicks\Grid\ClicksDefinition`) — используется в UI для формы
  "обновить стоимость клика" (массовая простановка `cost` по фильтру
  кликов, ср. `campaigns.updateCosts`);
- `log` (POST = QueryParams) → `ConversionRepository::log()` — грид лога
  конверсий с фильтрами/сортировкой/пагинацией как в §9.2;
- `import` (`data`, `currency`) → `ConversionsService::import()`, ответ
  `{"errors":[...], "success": <кол-во успешных>, "total": <всего>}` —
  массовый импорт конверсий (например из внешней CRM/партнёрки);
- `statuses` — справочник статусов конверсии (`sale`/`lead`/`rejected`/...).

### 10.12 Settings (`object=settings`, `dics`)

`SettingsController`: `index` (`only` — фильтр по ключам, только админ) —
все настройки как хэш `{key: value}`; `config` — `JsConfigService::get()`
(похоже, это как раз тот блоб конфигурации, что инжектится в HTML-страницу
панели при первой загрузке, вне `?object=` API — стоит свериться при
восстановлении bootstrap-последовательности фронтенда); `find` (`key`) —
одно значение через `CachedSettingsRepository` (кэширующий репозиторий, а
не прямой `SettingsRepository`); `update` (POST — произвольный хэш
`{key: value}`, только POST-метод, запрещено в demo) →
`SettingsService::updateValues()`, ответ — актуальные значения только
изменённых ключей; `getAuxiliaryData` — сборная солянка справочников для
формы настроек (кэш-хранилища, доступные storage для отложенных команд,
наличие Redis, антивирус-сервисы, варианты формата ссылок, валюты,
доступные TS-параметры); `changeLanguage` (`new` = `ru`/`en`, иначе
дефолт `en`) — меняет язык **глобально для инсталляции** (не per-user!,
это системная настройка, не `userPreferences`), затем редиректит на `?`.

`DicsController.currencies` — просто справочник валют (дублирует часть
`getAuxiliaryData`).

### 10.13 Migrations (`object=migrations`, `legacyMigrations`)

`MigrationsController`: `index` (список миграций нового формата,
`MigrationSerializer` отдаёт только `{name, description}` — описание
берётся на текущем языке локали), `appliedList` (какие уже применены),
`runAction` (только админ, POST; `name` — запустить одну конкретную,
иначе `runAll()`; ошибка `"Duplicate column"` от БД **проглатывается**
молча — идемпотентость по факту дублирующихся ALTER TABLE), `moveToTokuDb`
(конвертация таблиц движка БД в TokuDB, долгая операция — `set_time_limit(0)`).

`LegacyMigrationsController` — тот же паттерн для **старого** (пронумерованного
версиями int, не именованного) формата миграций из легаси-версии продукта:
`index`, `schemaInfo` (`{current_version, last_migration_version}`),
`run` (`version` — конкретная по номеру, либо `runAll()`).

Также замечен в контексте миграций баг, уже исправленный (см.
`docs/BUG_PATTERNS.md`): `MigrationsRepository` парсил имя файла миграции
регэкспом и терял первый элемент `list()`-деструктуризации — дублировал
дату в имени класса. При восстановлении контракта фронтенда стоит опираться
на текущее (исправленное) поведение `getMigrations()`.

### 10.14 AdminApi (`object=adminApi`) — см. §3

Всего два экшена-заглушки: `index` (HTML-страница) и `spec` (редирект на
внешний YAML). Никакой отдельной бизнес-логики или списка функций внутри
самого `?object=adminApi` нет — реальный REST-роутинг живёт в
`Admin\AdminApi\*` и матчится через путь `/admin_api/vN/...`, не через
`?object=`.

---

## 11. Click-processing pipeline (справочно, НЕ admin API)

`Traffic\Pipeline\Pipeline` (`application/Traffic/Pipeline/Pipeline.php`) —
конвейер обработки трафика, запускается из
`Traffic\Dispatcher\ClickDispatcher` / `ClickApiDispatcher` /
`LandingOfferDispatcher` (обычный клик по трекинг-ссылке, Click API,
и запрос "второго уровня" при клике по кнопке лендинга/выборе оффера).
**Не имеет отношения к `Admin\*` и не вызывается из admin-панели.**

Основная цепочка стадий (`firstLevelStages()`, порядок важен —
каждая стадия получает и возвращает общий `Payload`):
`DomainRedirectStage` → `CheckPrefetchStage` → `BuildRawClickStage` →
`FindCampaignStage` → `CheckDefaultCampaignStage` → `UpdateRawClickStage` →
`CheckParamAliasesStage` → `UpdateCampaignUniquenessSessionStage` →
**`ChooseStreamStage`** → `UpdateStreamUniquenessSessionStage` →
**`ChooseLandingStage`** → **`ChooseOfferStage`** → `GenerateTokenStage` →
`FindAffiliateNetworkStage` → `UpdateHitLimitStage` → `UpdateCostsStage` →
`UpdatePayoutStage` → `SaveUniquenessSessionStage` → `SetCookieStage` →
`ExecuteActionStage` → `PrepareRawClickToStoreStage` →
`CheckSendingToAnotherCampaign` → `StoreRawClicksStage`.

- `ChooseStreamStage` — выбирает стрим кампании: сначала форсированный
  (`forced_stream_id` из query, если задан), затем `TYPE_FORCED`-стримы по
  позиции, затем `TYPE_REGULAR` (по позиции для кампаний типа `POSITION`,
  либо по весу-ротации для остальных, с опциональной sticky-привязкой
  визитора через Redis), и в конце — `TYPE_DEFAULT` (запасной стрим, если
  ничего не подошло). Если схема выбранного стрима — не `LANDINGS`/`OFFERS`
  (то есть `ACTION`) — прямое действие стрима (`action_type`/
  `action_payload`/`action_options`) сразу копируется в `Payload`.
- `ChooseLandingStage` — только для схем `LANDINGS`/`OFFERS`: если лендинг
  ещё не предопределён (`forced`), выбирает случайный/по весу лендинг из
  привязанных к стриму (`LandingOfferRotator`), с опциональным sticky-биндингом.
- `ChooseOfferStage` — аналогично, но офферы; пропускается, если лендинг
  уже выбран (кроме случая `isForceChooseOffer()` — например лендинг
  явно запрашивает следующий оффер через клиентский JS-виджет).
- При зацикливании "отправки в другую кампанию" (`CheckSendingToAnotherCampaign`
  / `abort()+forcedCampaignId`) пайплайн перезапускается с нуля до 10 раз
  (`Pipeline::LIMIT`), после чего кидает исключение о бесконечной рекурсии.

**Симуляция клика ("Simulate traffic", проверено 2026-08-27 через анализ app.js + Playwright).**
Первоначальная гипотеза была неверной. Специального admin-API эндпоинта симуляции
действительно нет (`Component\Simulation` пуст), но не потому что функциональность
отсутствует — она просто **не идёт через admin API вообще**. Фронтенд (`simulationService`,
`components.simulation.services`) делает серию настоящих `POST` запросов напрямую на
`<root>/api.php` — это тот же самый публичный Click API, что и для сторонних систем
(см. точку входа `api.php` / `Traffic\Context\ClickApiContext` в §1), с флагами
`token, log:true, version:2, always_empty_cookies:true, save_to_stats:<bool>` и случайными
гео/device-характеристиками. `log:true` заставляет Click API вернуть подробный лог обработки
клика в теле ответа, который фронтенд стримит в модалку. То есть "симуляция" — это реальный
клик через реальный пайплайн, просто с флагом отключения куки и опционально без записи в
статистику. Доступно только Trial/Pro+. Подробности и точный список параметров запроса — см.
`docs/frontend/architecture_plan.md` §1.3.

---

## 12. Паттерны и особенности API (сводка)

1. **Один универсальный роутинг**: `?object=<controller>.<action>` →
   `strtolower(<controller>)` ищется в реестре, зарегистрированном каждым
   компонентом в своём `Initializer::loadControllers()`; экшен —
   `<action>Action()` public-метод. Нет соглашения "REST по HTTP-методу" —
   GET/POST используются произвольно, разделение по смыслу (`isPost()`
   внутри самого экшена), а не по факту роутинга.
2. **Батч-запросы** (`?batch=1` или легаси `?bulk=1`, тело — массив
   `{params, postData}`) прогоняют каждый под-запрос через полноценный
   `AdminDispatcher`, ответ — массив `{body, headers, statusCode}` в том же
   порядке; внешний HTTP-статус батча всегда 200.
3. **Аутентификация** — JWT в cookie `states` (НЕ httpOnly), проверяется
   заново на каждый запрос через таблицу `user_password_hashes`; никакой
   PHP-сессии. REST AdminApi (`/admin_api/vN`) — отдельно, через
   `api_key`/`Api-Key`.
4. **Тело запроса** парсится по содержимому, а не по `Content-Type`:
   начинается с `{`/`[` → JSON, содержит `&` → urlencoded form, иначе —
   пусто. `getParam()` = query, потом body (query приоритетнее);
   `getPostParam()` = только body.
5. **Формат ошибок** не единообразен между "обычным" admin-контекстом (см.
   таблицу в §7 — коды 402/403/404/406/500, тело JSON почти везде **кроме**
   generic-500 через `CommonErrorHandler`, который отдаёт HTML) и REST
   AdminApi (все ошибки → 402 JSON). Фронтенду нужно быть готовым к обоим
   вариантам тела (JSON и голый HTML-текст).
6. **Сериализация** — почти везде `AbstractSerializer` с `$_fields = true`
   (то есть "отдать всё, что есть в модели"), а не явный whitelist; реальный
   контракт полей ответа нужно смотреть по `extra()` каждого конкретного
   Serializer'а (что добавляется/удаляется) — белого списка полей "из
   коробки" в таком случае нет, нужно ориентироваться на структуру таблицы
   БД + `extra()`.
7. **JSON-в-поле** — целый класс полей моделей хранит JSON-строку и
   ожидает, что и модель, и потребитель модели используют геттер (а не
   читают `->get("поле")` напрямую) — см. таблицу в §8. Это уже было
   источником критичных багов при декомпиляции, при написании нового
   фронтенда стоит всегда сверяться с фактическим (текущим, желательно уже
   починенным) поведением геттера, а не полагаться на "как хранится в БД".
8. **Grid/withStats** — универсальный конверт пагинации/фильтрации:
   `{columns, grouping/dimensions, metrics, sort:[{name,order}], filters:
   [{name,operator,expression,case_sensitive}], range:{interval|from|to},
   limit, offset, summary, format}` → ответ `{rows, total, summary?, meta}`
   (§9). Список сущностей "withStats" (Campaigns/Offers/Landings/
   TrafficSources) использует близкий, но не идентичный билдер
   (`EntityGridFactory`, объединяет сущности + агрегированные метрики по
   `<entity>_id`), в отличие от чистых Reports (`GridBuilder`/`ReportRepository`,
   единая таблица кликов без пред-загрузки сущностей).
9. **ACL** проверяется на трёх уровнях: (а) весь контроллер/ресурс целиком
   при роутинге (§2.1); (б) конкретная сущность в каждом
   `show`/`update`/`archive`/... (`isViewAllowed`/`isEditAllowed`); (в) на
   уровне SQL-фильтра в Grid (`AccessRestriction` по разрешённым
   `campaign_id`). Плюс отдельно — какие столбцы отчёта видны
   (`getRestrictedReportColumns`).
10. **Массовые операции** почти везде принимают либо `id` (одиночный),
    либо `ids` (массив) взаимозаменяемо — если `id` присутствует, он
    молча превращается в `ids = [id]` в начале метода.
11. **"Мягкое" и "жёсткое" удаление** — почти у всех сущностей `delete`/
    `archive` это одно и то же (перевод в `state = deleted`, попадает в
    `deletedAction`), а физическое удаление — отдельный `cleanArchive`
    (`PruneTask\Prune<Entity>::deleteAll()`), обычно защищённый той же
    проверкой прав, что и `create`.
