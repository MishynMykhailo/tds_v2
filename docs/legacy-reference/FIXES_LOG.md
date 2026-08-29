# FIXES LOG — полный список всех правок этой сессии, файл за файлом

Дата: 2026-08-27. Этот файл — исчерпывающий список ВСЕХ багов, найденных и
исправленных в текущей сессии отладки, с указанием файла, что было не так
и почему это исправлено именно так. Категории/паттерны багов (общее
описание с примерами) — в `BUG_PATTERNS.md`, этот файл — построчный лог
"файл → что починено".

---

## Инфраструктура и окружение

- **`application/Core/Db/Db.php`** — `Db::instance()` проверял
  `if (!isset($instance))` — `$instance` НИКОГДА не присваивается в этой
  функции (локальная переменная, а не `self::$_masterInstance`), значит
  условие всегда истинно, и **каждый вызов создавал новый объект БД** с
  нуля (новое MySQL-соединение, обнулённый счётчик транзакций). Самый
  критичный фикс сессии — объяснял обрывы транзакций, случайные
  "Cannot assign requested address" под нагрузкой. Исправлено на
  `!isset(self::$_masterInstance)`.
- **`application/Core/Db/TransactionManager.php`** — добавлена защитная
  проверка в `commit()` (аналогично уже существующей в `rollback()`),
  чтобы явно кидать понятную ошибку вместо невалидного
  `RELEASE SAVEPOINT level-1`, если `commit()` вызван без открытой
  транзакции — стало возможным диагностировать баг №1 (Db::instance()).
- **`Dockerfile.dev`** — добавлено PHP-расширение `redis`
  (`pecl install redis && docker-php-ext-enable redis`) — по вашему
  запросу, чтобы Redis-функционал (draft_data_storage, кэш) заработал.
- **`application/config/config.ini.php`** —
  1) убрана пустая, но присутствующая секция `[db_slave]` — из-за неё
     `Db::slaveInstance()` пытался подключиться с пустыми кредами и ронял
     ВСЕ гриды (offers/campaigns/trafficSources/... withStats);
  2) `[redis] uri` переключён с `127.0.0.1:6379/1` на `tds-redis:6379/1`
     (реальный контейнер Redis в сети `tds-net`).
- **Права на `var/`** — выставлены `777` рекурсивно, чтобы PHP внутри
  контейнера мог создавать/удалять файлы (кэш, логи, аплоады).
- **GeoDB `ip2location_lite`** — скачана и положена настоящая бесплатная
  база IP2Location LITE DB3 (`var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN`),
  подтверждено рабочим lookup'ом.

---

## Логи (Maintenance → Log)

- **`application/Core/Logging/Service/LogParserService.php`** — три
  отдельных бага в одном файле:
  1. `parse()` для `SYSTEM_FORMAT` не имел `return $result;` в конце —
     функция ВСЕГДА возвращала `NULL`, поэтому `getRows()` отбрасывал
     каждую строку лога — страница Log была гарантированно пустой всегда.
  2. `getRows()` имел `return $result;` ВНУТРИ цикла по файлам — читался
     только первый файл из списка, а не все ротированные.
  3. `getLogList()` сортировал файлы лога по имени (`sort()`/`rsort()`)
     вместо реальной даты — из-за ASCII-порядка (`'-' < '.'`) старый
     файл `PRODUCTION.log` (без даты) считался "новее" датированного,
     что ломало и чтение, и очистку старых логов. Заменено на сортировку
     по `mtime`.
  4. Регулярка поиска файлов лога была регистрозависимой — не находила
     файлы с другим регистром имени. Добавлен модификатор `/i`.
- **`application/Traffic/Logger/Service/BaseLoggerService.php`** —
  `checkSize()` использовал тот же наивный `rsort()` по имени вместо
  сортировки по реальной дате — синхронизировано с фиксом выше
  (`array_reverse()` вместо `rsort()`, раз `getLogList()` уже сортирует
  правильно).

---

## Гриды и фильтры (вся админка: Campaigns/Offers/Landings/TrafficSources/...)

- **`application/Component/Grid/QueryParams/QueryParams.php`** — три
  свойства (`$_params`, `$_array`, `$_multiDimensional`) были объявлены
  `NULL` и никогда не заполнялись константами класса — из-за этого
  параметры от фронтенда (sort/filters/limit/columns/grouping/metrics)
  ПОЛНОСТЬЮ игнорировались каждым гридом в панели. Восстановлены списки
  по константам того же класса.
- **`application/Component/Grid/Query/FilterItem.php`** —
  1. `$_validOperators` был `NULL` — ЛЮБОЙ фильтр в гриде (EQUALS, IN_LIST
     и т.д.) считался "неверным оператором". Восстановлен полный список
     констант класса.
  2. В `_buildBetween()` в SQL был буквально текст `"self::BETWEEN "`
     вместо ключевого слова `BETWEEN` — фильтр с диапазоном ломал SQL.
- **`application/Component/Grid/Query/SortItem.php`** — `$_validOrders`
  был `NULL` — сортировка по ЛЮБОМУ полю (`ASC`/`DESC`) считалась
  "неверным порядком".
- **`application/Component/Grid/Definition/Column.php`** — три поля
  (`$_validOptions`, `$_validTypes`, `$_gridDefinitionOptions`) никогда не
  заполнялись — блокировало создание любой колонки грида. Восстановлено в
  конструкторе по существующим константам того же класса.
- **7 файлов Grid-дефиниций** (`OfferGridDefinition`,
  `AffiliateNetworkGridDefinition`, `LandingGridDefinition`,
  `ConversionsLogDefinition`, `TrafficSourceGridDefinition`,
  `CampaignGridDefinition`, `ReportDefinition`) — `self::initColumns()`
  вместо `parent::initColumns()` (бесконечная рекурсия при инициализации
  колонок). Исправлено на `parent::`.
- **`application/Component/Campaigns/Repository/CampaignRepository.php`**
  — `$_costTypes`/`$_bindVisitorTypes` были `NULL` — `getCostTypes()`/
  `getBindVisitorTypes()` падали при любом обращении. Восстановлены точно
  по значениям из файлов переводов (`campaigns.cost_types`/
  `campaigns.bind_visitor_types`).
- **`application/Component/Offers/Repository/OfferRepository.php`** —
  `$_costTypes` был `NULL` — `offers.getCostTypes` падал. Восстановлен
  как `Offer::getValidPayoutTypes()` (CPA/CPC), сверено с переводами.
- **`application/Component/Users/Repository/AclResourceRepository.php`**
  (косвенно, через `AclResource`/`AclService` ниже) и связанные с ACL —
  см. раздел "ACL" ниже.

---

## Клик-пайплайн (самое критичное — показ реальных лендингов/офферов)

- **`application/Component/Landings/LocalFile/PageWrapper.php`** —
  `while (empty($pageInfo))` вместо `if (!empty($pageInfo))`. `$pageInfo`
  — обязательный типизированный параметр, `empty()` на нём всегда false
  → весь блок показа страницы (sandbox, чтение файла) НИКОГДА не
  выполнялся, сразу `throw "pageInfo is not set"`. **Показ ЛЮБОГО
  локального лендинга/оффера был полностью сломан.**
- **`application/Core/Sandbox/Sandbox.php`** — два бага:
  1. `execute()`: `return $this->_parseOutputToResponse(...)` был только
     внутри `catch (ProcessTimedOutException)` — при УСПЕШНОМ выполнении
     (обычный случай) функция ничего не возвращала (`NULL`), что дальше
     ломало `_adaptResponseBody()` ("Call to a member function getBody()
     on null"). Перенесено так, чтобы `return` срабатывал всегда.
  2. `_applyHeaders()`: заголовки `X-Powered-By` и `Status` обрабатывались
     ОДНИМ `case` — оба пытались стать HTTP-статусом через `(int)
     $headerValue`. `X-Powered-By: PHP/7.4.33` даёт `(int)` = 0 →
     "Status code must be an integer value between 1xx and 5xx". Разделены
     кейсы.
- **`application/Traffic/Pipeline/Pipeline.php`** — `_run()` не имел
  `return $payload;` после `foreach` по стадиям — для ЛЮБОГО нормального
  (не прерванного) клика функция возвращала `NULL`, что ронялось в
  `ClickDispatcher.php` ("Call to a member function getResponse() on
  null"). Добавлен `return $payload;` в конец.
- **`application/Traffic/Pipeline/Rotator/LandingOfferRotator.php`** —
  `const _ASSOCIATION_FIELD_DIC = NULL;` — константа никогда не
  заполнялась, из-за чего конструктор ВСЕГДА кидал "Unexptected entity
  type lp/of" — ротация офферов/лендингов в стриме была полностью
  сломана. Восстановлено по `EntityBindingService::TYPE_LANDING_BINDING
  => "landing_id"` / `TYPE_OFFER_BINDING => "offer_id"`.
- **`application/Traffic/Device/Service/DeviceInfoService.php`** — два
  бага:
  1. `info($ua)`: результат (`$data`) собирался и возвращался ТОЛЬКО
     внутри `catch(\Exception)` — для успешного парсинга UA (обычный
     случай) функция ничего не возвращала. Детект браузера/ОС/устройства
     не работал для подавляющего большинства визитов. Код сборки данных
     вынесен из `catch` наружу.
  2. `$_matches` (маппинг наших `DeviceType` на числовые ID библиотеки
     DeviceDetector) был `NULL` — `_convertDeviceType()` падал с
     `array_search() expects parameter 2 to be array`. Восстановлен по
     реальным константам `\DeviceDetector\Parser\Device\AbstractDeviceParser`.
- **`application/Component/Device/Repository/DeviceTypeRepository.php`**
  — `$_deviceTypes` был `NULL` — список типов устройств для фильтра
  "device type" был пуст, `foreach` падал. Восстановлен по константам
  `\Traffic\Device\DeviceType`.
- **`application/Traffic/Model/StreamFilter.php`** — `getPayload()` не
  декодировал JSON (поле хранится как JSON-строка через
  `StreamFilterValidator::json_encode()`), возвращал сырую строку.
  **Использовался в ~24 разных классах фильтров** (Country, Region, City,
  Language, Browser, OS, IP и т.д.) — вся система таргетинга трафика по
  фильтрам была сломана. Добавлен `json_decode()`.
- **`application/Traffic/Model/BaseStream.php`** — `getActionOptions()`
  не декодировал JSON (в отличие от аналогичного метода в
  `StreamActionableMethodsTrait`, который уже был правильным) — влияло на
  прямые действия стрима (не через Offer/Landing). Добавлен `json_decode()`.
- **`application/Component/Triggers/CheckTrigger.php`** — два бага:
  1. `while (empty($this->_stream))` вместо `if (!empty($this->_stream))`
     — `$_stream` задаётся один раз в конструкторе и не меняется в цикле,
     значит проверка триггера НИКОГДА не выполнялась (сразу `delete()`
     без реальной проверки условия).
  2. После восстановления `if`: `TriggerService::instance()->delete(...)`
     выполнялся БЕЗУСЛОВНО, даже после того как `catch` откладывал
     повторную проверку через `setNextRunAt()`+`save()` — отложенный
     повтор сразу же стирался удалением той же записи. Добавлен `return`
     в `catch`, чтобы отложенный триггер не удалялся сразу же.
- **`application/Traffic/Actions/Predefined/{Curl,DoNothing,Frame,Iframe,
  LocalFile,ShowHtml,ShowText,Status404,SubId,ToCampaign}.php`** — голые
  константы (`TYPE_REDIRECT`, `TYPE_OTHER`, `TYPE_HIDDEN`, `NOTHING`,
  `TEXT`, `CAMPAIGNS`, `UPLOAD`) вместо `self::CONST` — PHP превращал их
  в строки, совпадающие с именем идентификатора (капсом), а не с реальным
  (строчным) значением константы в `AbstractAction`. Из-за этого, в
  частности, `LocalFile::getField()` отдавал `"UPLOAD"` вместо `"upload"`
  — фронтенд (`getFieldType() == 'upload'`) никогда не совпадал, и кнопка
  загрузки архива для оффера/лендинга не появлялась.

---

## Кампании / Стримы

- **`application/Component/Campaigns/Service/CampaignService.php`** — три
  случая `self::` вместо `parent::` (бесконечная рекурсия): `create()`,
  `delete()`, `archive()` вызывали сами себя вместо базовой реализации
  `EntityService`. Ломало создание/удаление/архивирование кампаний.
- **`application/Component/Campaigns/Controller/CampaignsController.php`**
  — убрана скрытая лицензионная "мина" в `withStatsAction()`: проверка на
  плейсхолдер-ключ `1111-1111-1111-1111` и подсчёт `"return true"` в
  `TsService.php` (случайно давал ровно 4 — из-за уже пропатченного до вас
  файла) — при совпадении вкладка Campaigns молча была пустой.
- **`application/Component/Streams/Service/StreamService.php`** — три
  случая `self::` вместо `parent::`: `delete()`, `create()`, `update()`
  (та же бесконечная рекурсия) — ломало создание/редактирование/удаление
  стримов, в том числе через `campaigns.create`/`campaigns.update` со
  вложенными стримами.
- **`application/Component/Domains/Service/DomainService.php`** —
  `createMultiple()`/`create()` вызывали друг друга по кругу
  (`self::create()` внутри `createMultiple()`, которая сама вызывается
  из `create()`) — взаимная рекурсия, `domains.create` падал.
- **`application/Component/Domains/Service/DomainCheckerService.php`** —
  два бага:
  1. `setNextCheck(Domain $domain = NULL, DateTime $nextCheckAt)` —
     значение по умолчанию `= NULL` стояло не у того параметра (тело
     метода явно рассчитано на необязательный `$nextCheckAt`) — кнопка
     "Check" в Domains падала с `ArgumentCountError`.
  2. `(int) $response->getBody()` вместо `(string)` в
     `_checkDomainResponse()` — проверка домена ВСЕГДА считала ответ
     "unexpected", даже для полностью рабочих доменов.
- **`application/Core/EntityEventManager/EventHandler/CacheStreams.php`**
  — в `handleUpdates()` кейс `case Campaign::entityName():` проваливался
  в `default` и кидал исключение вместо обновления кэша — ломало
  `campaigns.create`/`campaigns.update` (кэш стримов кампании).
- **`application/Traffic/Settings/Service/SettingsService.php`** —
  `_renameLPDir()`: `while (...)` вместо `if (...)` — при НЕизменённом
  `lp_dir` условие всегда истинно и ничего не меняется внутри цикла →
  **бесконечный цикл**, сохранение ЛЮБЫХ настроек в панели зависало на
  30 секунд и падало 500.

---

## GeoDB (раздел Maintenance → GeoDBs)

- **`application/Component/GeoDb/DownloadManager/DownloadManager.php`** —
  `option()` бросал обычный `\Exception` вместо `\Component\GeoDb\Error\DbError`,
  который ожидал вызывающий код — несовпадение типа исключения роняло
  список баз целиком.
- **`application/Component/GeoDb/Serializer/GeoDbSerializer.php`** —
  `serialize()` не имел `return $item;` в успешном пути (только внутри
  `catch`) — `geoDbs.index` (список баз) падал 500 всегда, даже без
  реальной ошибки.

---

## Редактор кода (Offers/Landings)

- **`application/Component/Editor/Repository/EditorRepository.php`**,
  **`application/Component/Editor/Service/EditorService.php`**,
  **`application/Component/Landings/Service/LandingDownloaderService.php`**
  — все три читали `action_options` напрямую через `$model->get(...)`
  вместо `$model->getActionOptions()` — получали сырую JSON-строку,
  `isset($option["folder"])` был всегда `false` → редактор кода отвечал
  "Only local landing available" даже для полностью корректно настроенных
  локальных офферов/лендингов (файлы были реально загружены, просто не
  открывались).

---

## Пользователи / ACL (права доступа, ограничение по группам)

- **`application/Component/Users/Model/AclResource.php`** —
  `getResources()` не декодировал JSON.
- **`application/Component/Users/Service/AclService.php`** —
  `_getResourcesByUser()` читал `->get("resources")` напрямую в обход
  геттера.
  → Вместе эти два бага означали: у ЛЮБОГО пользователя с ограниченным
  доступом (не админа) `allowedResources` всегда было пустым массивом —
  ограниченным пользователям не показывались даже РАЗРЕШЁННЫЕ разделы
  меню. Подтверждено тестом: до фикса `isResourceAllowed()` не работал
  корректно ни для одного ресурса.
- **`application/Component/Users/Serializer/DecoratedUserSerializer.php`**
  — `self::extra()` вместо `parent::extra()` (рекурсия).

---

## Сериализация (данные сохраняются, но пропадают при перезагрузке)

- **`application/Component/Streams/Serializer/StreamSerializer.php`** —
  `_addAssociation($obj, $data)` принимал `$data` по значению (не по
  ссылке) и не имел `return $data;` — все добавленные поля
  (`landings`/`offers`/`filters`/`triggers`) терялись сразу после вызова.
  Плюс вызывающий код (`extra()`) вызывал метод как отдельную инструкцию,
  не сохраняя результат обратно. Итог: офферы/лендинги/фильтры/триггеры
  стрима реально СОХРАНЯЛИСЬ в БД правильно, но при любом чтении (открытии
  кампании заново, обновлении страницы) пропадали из ответа — выглядело
  так, будто "не сохраняется", хотя проблема была в чтении, не в записи.
  Исправлено: `_addAssociation()` теперь возвращает `$data`, `extra()`
  сохраняет результат обратно; убран лишний повторный вызов того же метода.

## Прочее

- **`application/Component/StreamFilters/Filter/{Isp,ImkloDetect,
  HideClickDetect}.php`** — `self::getTemplate()` вместо
  `parent::getTemplate()` (рекурсия) в трёх похожих условных блоках.
- **`application/Component/Migrations/Repository/MigrationsRepository.php`**
  — `list($date, $fileName) = $matches;` не пропускал нулевой элемент
  regex-совпадения — дата дублировалась в имени класса миграции, ломая
  `diagnostics.index`.
- **`application/Admin/Context/AdminContext.php`** — `handleException()`
  был 6-уровневым неправильно вложенным `if` (артефакт декомпиляции) —
  почти любое исключение проваливалось в недоделанный
  `handleLicenseError()` и возвращало `null` вместо ответа. Превращено в
  плоскую `if/elseif` цепочку.
- **`application/Admin/Dispatcher/BatchAdminDispatcher.php`** — строка,
  собирающая результат бэтч-запроса, стояла только внутри `catch` —
  успешные под-запросы в `?bulk` не попадали в ответ.
- **`application/Core/Model/AbstractModel.php`** — `_realColumns()` не
  учитывал режим `avoid_mysql` (БД временно отключена для click-обработки)
  — любое обращение к полю модели в клик-контексте кидало "Model ... does
  not have field ...". Добавлено временное `enable()`/`disable()` вокруг
  чтения схемы таблицы.
- **`application/Traffic/Model/Campaign.php`** — `getParameters()` не
  декодировал JSON — ломало `CheckParamAliasesStage` (алиасы параметров
  кампании) на каждом клике.
- **`application/Traffic/Redis/Service/RedisStorageService.php`** —
  `newRedisInstance()`: `return $redis;` был только в `catch` — при
  УСПЕШНОМ подключении (обычный случай) метод возвращал `NULL`, отсюда
  "No method Redis#zcount" на ровном месте.
- **`application/Traffic/Dispatcher/KtrkDispatcher.php`** —
  `self::dispatch()` вместо `parent::dispatch()` (рекурсия).

---

## Фронтенд (`admin/assets/app.js`, единственный собранный бандл, исходников нет)

- Убран `'local_file'` из `exclude-actions` в конфиге форм Offer и Landing
  (было `['curl', 'local_file']`, стало `['curl']`) — виджет загрузки
  архива физически не мог появиться, хотя все нужные данные (`archive`,
  `folder`, `local-path`) уже передавались в компонент.

---

## Живое тестирование через Playwright (2026-08-27) — найдено кликами в реальной админке

Поставили Playwright в отдельном Docker-контейнере (`tds-playwright`, подключён к сети
`tds-net`), залогинились под `admin`, прошлись по всем 22 разделам сайдбара + отдельно по
форме кампании/стрима, ловили console-ошибки и HTTP-ответы ≥400.

- **`application/Component/Users/Serializer/ApiKeySerializer.php`** — `extra()` вызывал
  `$data["datetime"]->format(...)` без проверки типа: `datetime` из БД приходит строкой
  (модель `ApiKey` не типизирует поля, `$_fields = NULL`), `format()` на строке кидал
  `Uncaught Error`. Уронило ВЕСЬ батч-запрос страницы Profile — вместе с `apiKeys.getAll`
  падали ещё 3 параллельных под-запроса того же батча (`profile.show`, `profile.languages`,
  `profile.timezones`), вся страница Profile была нерабочей (пустая, 500 в консоли). Исправлено:
  если `datetime` — строка, оборачивать в `new \DateTime($data["datetime"])` перед `format()`.
- **`application/Component/Domains/Serializer/DomainSerializer.php`** — блок вычисления
  `default_campaign` (имя "запаркованной" по умолчанию кампании) имел перевёрнутый try/catch:
  `if (!empty($campaign))` стоял ВНУТРИ `catch (NotFoundError $e)`, а не в `try`. Поскольку
  `$campaign` не устанавливается при выбросе исключения, условие в catch-блоке никогда не
  выполнялось — при УСПЕШНОМ поиске кампании (обычный случай) имя просто терялось, поле
  `default_campaign` всегда оставалось пустой строкой. Исправлено переносом `if` внутрь `try`.
- **`application/Component/Domains/Service/DomainService.php`** — `createMultiple()` не
  устанавливал `ssl_status` при создании домена (только `update()` делал это при смене имени),
  из-за чего у только что созданного домена `ssl_status` оставался сырым дефолтом БД (`0`), а
  фронтенд пытался перевести его как `domains.ssl_status.0` — в UI показывался буквальный,
  непереведённый ключ вместо человекочитаемого статуса. Добавлен дефолт
  `SSL_STATUS_AWAITING_DNS`, если `ssl_status` не передан явно.

- **`application/Component/Reports/Controller/LabelsController.php`** — `indexAction()`
  делал `return (int) $labels;`, хотя `LabelRepository::labelsFor()` возвращает ассоциативный
  массив `{value: label_name}` (реальный список меток для UI). PHP кастует непустой массив в
  `1`, поэтому фронтенд при любом непустом результате получал число `1` вместо списка меток —
  фича "меток" (labels) на логе кликов была полностью нерабочей. Исправлено на `return $labels;`.
  Проверено прямым вызовом `?object=labels.index` через Playwright (валидный `ref_name`
  формата `sub_id_N`/`ip`/`source`/... — не любая строка, см. `LabelService::getRefDefinition()`).

Проверено вживую Playwright-скриптом: страница Profile до фикса — 4 упавших запроса в
батче/пустая страница; после фикса — 0 ошибок, форма полностью рендерится.

**Также найдено, но НЕ баг сам по себе** (фронтенд-ограничение, не критично): на странице
Domains консольный `TypeError` в `Bo.a.compare(...)` (сравнение версий) — происходит только на
нестандартных ("Custom") путях установки (наш dev-докер не входит в список
`_approvedTdsInstallations`), т.к. `getVersionInstall()` не защищается от `undefined`, если
`getInstallationMethod()` вернул `"Custom"` вместо `"Approved (vX.Y.Z)"`. На официальной
("Approved") инсталляции не воспроизводится. Добавлено в `docs/TODO_IMPROVEMENTS.md` как
фронтенд-робастность, не бэкенд-баг.

---

## Живое тестирование через Playwright, раунд 2 (2026-08-27) — закрытие чек-листа

Прошёлся по оставшимся пунктам `docs/frontend/questions_for_new_proj.md`. Новых бэкенд-багов не
найдено, но закрыто ещё 4 открытых вопроса (полные детали — в самом чек-листе):

- **Избранное у стрима** — подтверждено, что реально сохраняется на бэкенде (пережило
  перезагрузку страницы), хотя конкретный XHR не пойман (видимо, уходит через `?batch`).
- **Глобальный поиск** — это ДВЕ разные вещи: typeahead по именам кампаний в шапке (мгновенное
  открытие) и отдельный роут `#!/search/?query=...` при Enter — вероятно бьёт в `streams.search`.
- **i18next** — переводы полностью зашиты в `app.js` при сборке, отдельных `.json`-файлов
  локализации по сети не грузится (важно для пересборки: не нужно городить отдельный
  i18n-CDN/файлы, достаточно перекомпилировать бандл).
- **Snap.js** — подтверждено на 100%: класс `snapjs-left` на `<body>` при открытии сайдбара —
  это буквальный класс настоящей библиотеки Snap.js (jakiestfu), не самописный клон.

Не удалось довести до конца (осталось на будущее): `dashboardPreferences`,
`postbackBuilderService`, точный триггер автосейва (сохранение в итоге происходит, но неясно
через debounce ли или через save-on-navigate-guard), хардкод "Не выбрано" не воспроизведён —
похоже, скрыт фиче-флагом `default_action_allowed` на этом стенде.

---

## КРИТИЧЕСКИЙ БАГ: клики вообще не сохранялись в БД (2026-08-27, заметил пользователь)

Пользователь заметил, что счётчик кликов не работает. Расследование заняло цепочку из **9 последовательных багов** — каждый следующий вскрывался только после починки предыдущего (реальный клик через curl на трекинг-ссылку campaign_id=4 использовался как тестовый сценарий на всех этапах). Порядок обнаружения = порядок в пайплайне:

1. **`application/Traffic/CachedData/DataGetter/GetCampaign.php`** — `fallback($scope) {}` был
   ПУСТОЙ (в отличие от всех соседних `GetStream`/`GetResource`, которые правильно грузят из
   БД при промахе кэша). Любой клик по некэшированной кампании падал с `NoCache: no key 'CMPGN_X'`.
   Исправлено: `return CampaignRepository::instance()->find($scope);`.
2. **`application/Traffic/CachedData/Repository/CachedDataRepository.php`** — даже с фиксом (1),
   `Db::instance()->isEnabled()` возвращает `false` во время клик-обработки (намеренно отключено
   в `initDbStatus()` для производительности), поэтому фоллбэк на БД молча блокировался условием
   `if (isEnabled()) { fallback() } else { throw }`. Исправлено: временный `enable()`/`disable()`
   вокруг вызова `fallback()` — тот же паттерн, что уже использовался для `AbstractModel::_realColumns()`.
3. **`application/Traffic/Pipeline/Stage/ChooseOfferStage.php`** — подбор оффера намеренно
   вызывает `getCachedByStream($stream, false)` (без фоллбэка, ради скорости), но НЕ ловит
   `NoCache` — исключение улетало наружу и валило весь клик с 500 вместо трактовки как "офферов
   нет". Исправлено: `try/catch (NoCache)` → `$offerAssociations = []`.
4. **`application/Component/Cron/Service/CronService.php`** — `getLastRun()` читал
   `$task->get("executed_at")` напрминую вместо готового геттера `$task->getExecutedAt()`
   (который правильно кастует строку в DateTime) — падал виджет статуса крона в шапке админки.
5. **`application/Traffic/Redis/Service/RedisStorageService.php`** — `pconnect()` вместо
   `connect()`: подтверждено экспериментально — `rPush()` возвращал "успех" (`int(1)`), но данные
   физически НЕ записывались в Redis (`lLen` сразу после = 0). Баг конкретно этой связки
   phpredis 6.3.0 + Redis 7.4.9 + PHP built-in dev-server в этом окружении. Заменено на `connect()`.
6. **`application/Traffic/CommandQueue/QueueStorage/RedisStorage.php`** — `pop()` был
   `while (true)` БЕЗ условия выхода и без `return` — реальный клик-краш-луп, из-за которого
   `cron:run` (обрабатывающий очередь) зависал НАВСЕГДА при первом же вызове. Исправлено на
   одноразовый проход с `return $decoded;` (по образцу `FileStorage::pop()`).
7. **`application/Core/Application/Bootstrap.php`** — `initCacheService($rootFolder = false)`:
   `false . "/cache"` превращалось в путь `/cache` (корень ФС, ВНЕ `/app`), а не `/app/cache`.
   Директория создавалась под root (веб-сервер), но `cron:run` обязан идти под `www-data`,
   который не мог туда писать — permission denied на все операции с кэшем/очередью при запуске
   из-под правильного пользователя. Исправлено: дефолт `$rootFolder = ROOT`.
   *(Найдено параллельно фоновым QA-агентом; на диске уже была верная версия.)*
8. **`application/Component/Clicks/ClickProcessing/ResolveClickDevice.php`** — `process($entries)`
   вообще не использовал параметр `$entries` — всё тело метода читало несуществующую переменную
   `$data` (нет `foreach`). De-facto `$data["user_agent"]` всегда был `NULL` →
   `DeviceDetector::setUserAgent(NULL)` → `Uncaught TypeError`, весь клик-процессор падал.
   Исправлено: обёрнуто в `foreach ($entries as &$data) { ... }`, `return $entries;` в конце.
   Заодно поправлен `Component\Clicks\ClickProcessing\Pipeline::process()` — не было `return $entries;`.
9. **`application/Core/Model/AbstractModel.php`** — `getFields()` возвращал сырой
   `static::$_fields` (у ВСЕХ моделей в `Component\Clicks\Model\*` он `NULL` — общая конвенция,
   never populated), хотя `definition()` в этом же классе уже давно ожидает fallback на
   `_realColumns()` (`static::$_fields ?: static::_realColumns()`, live-интроспекция реальных
   колонок таблицы). `VisitorAggregator`/`FilterAttributes` — единственные потребители
   `Model::getFields()` в проекте — оба получали `NULL`, что ломало вставку в `tds_visitors` и
   `tds_clicks` (`INSERT INTO tds_clicks (\`\`) VALUES ()`). Исправлено: `getFields()` теперь
   тоже делает `?: static::_realColumns()`.
10. **`application/Component/Clicks/ClickProcessing/FilterAttributes.php`** — у замыкания
    `array_map(function ($click) { ... foreach ($fields as ...) ... })` отсутствовал `use
    ($fields, $hasColumn, $hasSubId, $hasXRequestedWith)` — классический декомпиляционный баг
    (потерянный `use`-список). Внутри замыкания `$fields` был неопределён (`NULL`), несмотря на
    то что снаружи он был корректно заполнен (проверено трейсом) — отсюда "Invalid argument
    supplied for foreach()" и в итоге пустой `INSERT INTO tds_clicks`.

**После всех 10 фиксов клик реально сохраняется**: `SELECT * FROM tds_clicks` →
`click_id=1, campaign_id=4, stream_id=7, sub_id=u93f3h1r, datetime=2026-08-27 13:37:42`.

Попутно (тот же класс "потерянный `use`"/"потерянный default") пофикшены ещё 2 бага, всплывшие
при полном прогоне `cron:run` (не блокировали клики, но валили cron целиком с ненулевым кодом
выхода):
- **`application/Component/Domains/Service/DomainCommandService.php`** —
  `enableSSLCommand($domains = NULL, Process $process)` — второй параметр без дефолта ПОСЛЕ
  параметра с дефолтом → `ArgumentCountError` при вызове с одним аргументом из
  `EnableSSLTask.php`. Исправлено: `$process = NULL`.
- **`application/Traffic/Http/Service/HttpService.php`** — `settleLimit()`: три вложенных
  замыкания Guzzle promise-хендлера мутировали/читали `$results` без `use (&$results)` —
  каждое видело свою пустую копию. Исправлено: `use (&$results)` во всех трёх.

**Экологические (не кодовые) причины, добавившие путаницы при отладке**:
- На хосте накопилось **7 параллельных зомби-процессов `cron:run`** от предыдущих тестов (форков
  и ручных попыток) — они наперегонки вычитывали друг у друга очередь Redis, из-за чего казалось,
  что данные "пропадают" сразу после записи. Все убиты, дев-стенду для ручных прогонов `cron:run`
  нужен явный `rm var/locks/*.lock` + проверка отсутствия старых процессов перед каждым тестом.
- Директория `/app/var/bots/` отсутствовала физически (нужна для `PruneUserBotDBCA`) — создана
  вручную (`mkdir -p`), это дополняет уже известную историю с отсутствующими GeoDB/bot-базами
  (см. `HAND_FIX.md`).

**Для продакшена (НЕ для кода, для docs/HAND_FIX.md)**: `cron:run` — одноразовая команда,
рассчитанная на вызов РЕАЛЬНЫМ system cron каждую минуту от имени `www-data`
(`sudo -u www-data php bin/cli.php cron:run`), не демон. В этом Docker dev-стенде такого cron
изначально не было настроено вообще — без него весь асинхронный конвейер (клики, SSL,
delayed commands) не работает молча, без единой ошибки в обычном UI-тестировании.

---

## Ещё одна цепочка багов: гео/девайс "Unknown" и полностью нерабочая уникальность (2026-08-27, вторая половина дня)

Пользователь заметил в логе кликов кучу "Unknown" значений и буквальную строку `[self::IPv6]`
вместо IP, плюс — все его повторные клики с одного браузера считались уникальными. Разобрал до
конца, нашёл 5 причин:

1. **`application/Component/Grid/Builder/Decorator.php`** — `_ip($row)` при пустом/нулевом IP
   возвращал буквальную СТРОКУ `"[self::IPv6]"` вместо реального форматирования — классический
   декомпиляционный "bare string instead of real value" баг. Сам по себе он вторичен — реальная
   причина в п.2.

2. **`application/Traffic/Device/Service/RealRemoteIpService.php`** — `find()` применял
   анти-спуфинг фильтр (отбрасывать `192.168.*`/`127.0.*` как подозрительные) КО ВСЕМ источникам
   IP, включая `REMOTE_ADDR` — единственный НЕ подделываемый источник (в отличие от
   X-Forwarded-For/X-Real-IP и т.п., которые действительно может подделать клиент). В
   локальной/докер-сети REMOTE_ADDR законно попадает в приватный диапазон → IP никогда не
   определялся → `ip=0` → каскад: `_ip()` возвращает "[self::IPv6]", вся гео/ISP/девайс
   информация тоже не резолвится ("Unknown" по всем полям, т.к. геолокация делается по IP).
   Исправлено: приватный диапазон фильтруется только для заголовков, `REMOTE_ADDR` принимается
   как есть, если он вообще не пустой.

3. **`application/Traffic/Session/Storage/RedisStorage.php`** — `save()` безусловно вызывал
   `SETEX ключ 0 данные`, если у кампании `Uniqueness TTL` не заполнено (0). Redis считает TTL=0
   невалидным для SETEX и возвращает ошибку, ничего не сохраняя — проверено напрямую
   (`$r->setex($key, 0, $data)` → `false`, `ERR invalid expire time`). Из-за этого запись сессии
   уникальности всегда тихо проваливалась. Исправлено: при TTL≤0 используется обычный `SET` без
   срока жизни (сессия просто не истекает по времени, вместо того чтобы не сохраняться вовсе).

4. **Настоящая причина "всё всегда уникально" — это НЕ баг кода, а пустое поле формы.**
   `Traffic\Session\SessionEntry::_isActive()`: `$now < $lastClickTime + ttlHours*3600`. При
   `ttlHours=0` формула превращается в `$now < $lastClickTime`, что математически ВСЕГДА ложно
   (текущее время не может быть меньше прошлого) → клик никогда не считается "ещё активным" →
   всегда уникален, вне зависимости от реального повторного визита. Это логически корректное
   поведение "окна в 0 часов", просто ни один реальный пользователь не ожидает такого от пустого
   поля. **Требуется действие: заполнить "Uniqueness TTL" реальным значением в настройках каждой
   кампании** (для тестовой кампании "test", id=4, проставлено 24 часа вручную в рамках проверки).

5. **`application/Traffic/Http/Service/HttpService.php`** — `buildDefaultClient()` создавал
   `GuzzleHttp\Client` без `timeout`/`connect_timeout` — реально ловили: `cron:run` намертво
   зависал на проверке недоступного домена (без вообще какого-либо предела ожидания). Добавлены
   разумные дефолты (`connect_timeout: 10, timeout: 20`).

Также по пути исправлен **ещё один "потерянный `use()`"** — `DomainCheckerService.php`:
`array_walk($updatedFields, function ($value, $key) { $currentDomain->set(...); })` без
`use ($currentDomain)` → `Call to a member function set() on null` при каждой проверке домена.

**Важно для тестирования**: при ручном `UPDATE ... campaigns SET cookies_ttl=...` через SQL
напрямую (в обход admin API) кэш кампании (`CachedDataRepository`/файловый кэш) НЕ
инвалидируется автоматически — только запись через реальный `campaigns.update` делает это
(через `EntityEventService`). Обновление напрямую в БД требует ручной очистки/warmup кэша, иначе
клик-пайплайн продолжит читать старое закэшированное значение. Не баг, особенность архитектуры
кэша — но легко потерять полчаса на отладку, если не знать (как и было в этой сессии).

**Итог, проверено живым тестом через Playwright (реальный браузер, не curl — curl определяется
как бот и намеренно ВСЕГДА получает `is_unique=false`, это correct-by-design, не баг):**
- Клик 1 (первый визит) → `is_unique_stream/campaign/global = 1` (верно, первый раз).
- Клики 2 и 3 (тот же браузер, тот же IP+UA, TTL=24ч) → `is_unique_* = 0` (верно, повтор).

## 2026-08-27 (продолжение): инвертированные ветки click/conversion в MacrosProcessor

**`application/Traffic/Macros/MacrosProcessor.php`, `_searchInMacroScripts()`** — ветки
`AbstractConversionMacro`/иначе были перепутаны местами: код требовал `$pageContext->rawClick()`
внутри блока `if ($macro instanceof AbstractConversionMacro)`, а `$pageContext->conversion()` —
в блоке `else` (то есть для click-макросов). На обычном клике (лендинге) `conversion()` всегда
пуст, поэтому для ЛЮБОГО click-макроса (`subid`, `offer`, `ip`, `country`, `device_type` и т.д.,
всё что наследует `AbstractClickMacro`) метод сразу возвращал `"{" . $name . "}"` — то есть
исходный, не заменённый токен. Дальше в `_replace()` это ещё и urlencode'илось (rawMode не был
выставлен), из-за чего на странице вместо значения показывалось `%7Bsubid%7D`.

Найдено живьём: юзер вставил `{subid}`/`{offer}` в свой `index.php` лендинга и через curl к
`http://localhost:8090/qbrtcz` получил `<a href="%7Boffer%7D">%7Bsubid%7D</a>` вместо реальных
значений. Это системный баг — затрагивал ВСЕ click-макросы на ВСЕХ лендингах сайта, просто ни
один прошлый раунд QA не тестировал именно рендер макросов на живом клике.

Исправлено: поменял местами тела блоков (`if` теперь требует `conversion()`+использует
`[stream, conversion]`, `else` — требует `rawClick()`+использует `[stream, rawClick]`).
Проверено живым curl-запросом до и после фикса — `{subid}` теперь корректно резолвится в
реальный `sub_id` из `RawClick` (например `2kiuech1i`), `{offer}` — в реальное значение вместо
литерального токена.
