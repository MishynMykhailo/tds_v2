# Porting Log — расхождения, находки, осознанные решения

Журнал по образцу `docs/legacy-reference/FIXES_LOG.md`/`RISKY_FIXES.md` из
старого проекта. Сюда записывается всё, что может повлиять на дальнейшую
работу: расхождения между документацией и реальным поведением старого
бэкенда, осознанные отклонения нового кода от легаси, найденные баги нового
кода, и сознательно отложенные модули/функциональность.

---

## Расхождения "имя объекта/метода в доке" vs "реальное поведение"

- **`streams.delete` vs `streams.deleteAction`** — таблица в доке называет
  метод по имени PHP-метода (`deleteAction`), а реальный route-параметр —
  `delete` (диспетчер сам добавляет `Action`). Подтверждено живым запросом.
- **`affiliateNetworks` vs `affiliate_networks`** — реальный `?object=`-ключ
  из `Initializer.php` — `affiliateNetworks` (camelCase), а не
  `affiliate_networks` (это на самом деле ACL-ключ, не dispatch-ключ, я сам
  их перепутал в промпте агенту). Зарегистрированы ОБА варианта в
  `ObjectDispatchController` для подстраховки.
- **`streamEvents` (API-объект) → таблица `monitoring_history`, не
  `stream_events`** — таблицы `tds_stream_events` не существует вообще,
  легаси-модель `StreamEvent::$_tableName = "monitoring_history"`.
- **`users.listAsOptions` не существует в легаси** — контроллер в новом
  проекте его всё равно реализовал (лишний, но безвредный эндпоинт сверх
  контракта) — оставлено как есть, не удалялось ради экономии.

## Осознанные отклонения нового кода от легаси-поведения (не баги, решения)

- **`domains.show` с несуществующим/отсутствующим id — теперь настоящий
  404.** Легаси в этом случае возвращает 200 с наполовину пустым объектом
  (`findActive()` тихо возвращает null, сериализатор всё равно его
  обрабатывает, `campaigns_count` даже приходит ненулевым по случайному
  совпадению словарного поиска). Это явный баг легаси, не намеренное
  поведение — решил не воспроизводить, задокументировано прямо в коде
  `DomainsController::showAction()`.

## Найденные и исправленные баги НОВОГО кода (портируемость/фреймворк)

- MySQL-only `FIELD()` в сортировке стримов — не работает на SQLite
  (тестовая БД) — заменено на портируемый `CASE WHEN`.
- MySQL-only `MD5(CONCAT())` в проверке JWT-логина (`AuthService::
  verifyFromCookie()`) — то же самое, перенесено сравнение в PHP.
- Laravel по умолчанию шифрует ВСЕ куки (`EncryptCookies`) — ломало формат
  легаси-JWT-куки `states` и её JS-читаемость (`httpOnly=false` теряет
  смысл на зашифрованном значении) — добавлено исключение.
- Laravel CSRF-защита блокировала все POST на `/admin/index.php` (легаси
  API никогда не использовал CSRF-токены) — добавлено исключение.
- `ObjectDispatchController` — `Illuminate\Http\JsonResponse` не наследуется
  от `Illuminate\Http\Response` в этой версии Laravel (сиблинги, общий
  предок `Symfony\Component\HttpFoundation\Response`) — тайп-хинты поправлены
  на общего предка.
- `streamEvents.index` — поле `total` отдавалось числом, легаси отдаёт
  строкой (тот же паттерн "числа как строки", что и у Triggers) — поправлено.
- `response()->json(null)` в этой версии Laravel/Symfony превращает `null` в
  `{}` (пустой объект), а не отдаёт буквальный `null` — обойдено вручную в
  `UserPreferencesController::getAction()` через прямой `response()`.
- `AclService::ACL_KEYS` изначально содержал только `Campaign` — не-админ
  на Offers/Landings/TrafficSources/Domains ловил бы фатальную ошибку.
  Расширено на все актуальные сущности по мере переноса модулей.

- **`Component\Dics` как отдельного модуля не существует** — `DicsController`
  физически лежит в папке `Component/Settings/`, регистрируется тем же
  `Initializer.php`. У него РОВНО один экшен — `dics.currencies` — никакого
  `dics.index` нет, это не общий справочник, а конкретно валюты.
- **`settings.update` требует именно POST** — не-POST запрос у легаси не
  отдаёт JSON-ошибку, а падает в общий `Exception\Error` → HTTP 500 HTML.
- Symfony `InputBag::get()` бросает `BadRequestException`/400 на
  array-значения query-параметров (например `only[]=a&only[]=b`) — если
  параметр может быть массивом (как `settings.index`'s `only`), читать через
  `$request->query->all()`, не через `->get()`.

- **AdminApi** (`object=adminApi`) реализован мной напрямую (без агентов —
  слишком тривиально, 2 экшена, экономия токенов): `indexAction` в легаси
  рендерил статичную HTML-страницу документации — у нас JSON-only API без
  Blade, поэтому отдаёт короткое JSON-сообщение вместо HTML; `specAction`
  редиректит на тот же внешний OpenAPI YAML, что и раньше. Живьём проверено.

- **Resource + TrafficSourceTemplates** реализованы мной напрямую (без
  агентов, статичные списки, тривиально): `resource.mandatory`/
  `complementaryAsOptions` — статичные списки разделов меню для ACL-UI,
  `name` в `complementaryAsOptions` сейчас = сырой ключ ресурса, не
  переведённая строка (i18n-модуль не перенесён — TODO). У
  `TrafficSourceTemplatesController` — легаси `PATH = NULL` без переопределения
  нигде в кодовой базе → `getData()` ВСЕГДА возвращает `[]`, даже у живого
  старого бэкенда (вырезанные вендорские данные интеграций). Перенесено как
  постоянно-пустое, не выдумано иначе.

- **Фундамент Clicks/Conversions заложен (2026-08-29).** Обнаружено: обе
  таблицы (`clicks` 65 колонок, `conversions` 50 колонок, легаси-имя
  `tds_conversions_2` — переименовано в `conversions`, версионный суффикс
  не имеет смысла в свежей схеме) используют 9 одинаковых по форме
  словарных таблиц (`ref_referrers`, `ref_sources`, `ref_keywords`,
  `ref_search_engines`, `ref_creative_ids`, `ref_external_ids`,
  `ref_ad_campaign_ids`, `ref_x_requested_with`, `ref_destinations` — все
  `{id, value}`) для избежания дублирования длинных строк на
  высоконагруженной таблице кликов. Миграции+модели созданы, в таблицы
  пока никто не пишет — это будет делать клик-пайплайн (`traffic-core/`,
  не начат). **Вывод по факту:** `conversions.log` (основной листинг) тоже
  оказался Grid-зависимым (`QueryParams`/`GridBuilder`, как и `withStats`
  у всех сущностей) — то есть все CRUD-модули, не требующие Grid, теперь
  исчерпаны. Дальше — либо Grid-система, либо GeoDb/Editor (файловая
  инфраструктура), либо `traffic-core`/`frontend`.

- **Grid-система: первое подключение (`campaigns.withStats`) готово
  (2026-08-29).** `App\Services\Grid\QueryParams`/`FilterOperator` — общий
  фундамент (мой), `App\Services\Grid\EntityGridBuilder` — порт легаси
  `EntityGridFactory` (агентом, сверено с реальным исходником, не с докой).
  Метрики: `clicks=COUNT(click_id)`, `conversions=SUM(is_sale)+SUM(is_lead)+
  SUM(is_rejected)` (дока ошибочно не упоминала `is_rejected` — в реальном
  SQL он есть), `revenue=SUM(lead_revenue)+SUM(sale_revenue)` (БЕЗ
  `rejected_revenue`), `cost=SUM(cost)`, `profit=revenue-cost`.
  **Осознанные отклонения от легаси-бага** (не воспроизведены, задокументированы
  в коде `EntityGridBuilder`):
  1. Легаси теряет `total` в ответе на обычном пути (только при 0 метрик
     отдаёт заявленный `{rows, meta:{total}}`) — реализовано ВСЕГДА отдавать
     `total`, как и задокументировано в доке/задаче.
  2. Легаси пагинирует ЗАПРОС АГРЕГАЦИИ кликов, а не список сущностей — из-за
     этого сущности за пределами `limit` обнуляются даже при реальной
     статистике. Реализовано пагинировать финальный смёрженный список.
  3. Легаси у сущностей форсирует `=` независимо от заявленного оператора
     фильтра — реализовано использовать реальный `FilterOperator` (строго
     корректнее).
  Таблица `clicks`/`conversions` пока пустая (клик-пайплайн не начат) —
  метрики будут нулевыми в реальном использовании, пока это не изменится;
  тесты сами вставляют строки напрямую через Eloquent для проверки формул.
  **Паттерн раскатан (2026-08-29) на Streams (`stream_id`), Offers
  (`offer_id`), Landings (`landing_id`), TrafficSources (`ts_id` — НЕ
  `traffic_source_id`, реальное имя колонки в `clicks`) — `EntityGridBuilder`
  оказался сразу написан обобщённо (конструктор принимает
  `entityClass`+`statsIdColumn`), копипаста логики не понадобилась.
  Нюанс: `TrafficSourceGridDefinition::_url` в легаси camelCase
  (`trafficSources.withStats`), у Offers/Landings — lowercase; ни один из
  трёх (в отличие от Campaigns) не объявляет виртуальную колонку `state`.**

- **Console / Cron / DelayedCommands — НЕ переносятся как отдельные модули
  (архитектурное решение, 2026-08-29).** Подтверждено: ни один из трёх не
  регистрирует `?object=`-контроллер вообще (`loadControllers()` либо
  отсутствует, либо пустой) — это чистая внутренняя инфраструктура без
  admin API, нечего сохранять "контрактом". Легаси хранит очередь
  отложенных команд как сериализованный PHP-объект в BLOB-колонке
  (`tds_delayed_tasks.data`) и статус крона в отдельной таблице
  (`tds_cron_status`) — у Laravel уже есть штатные, значительно лучше
  документированные аналоги: Queue/Jobs (таблица `jobs` уже создана
  дефолтным скелетом Laravel) вместо `tds_delayed_tasks`, Task Scheduling
  (`routes/console.php`) вместо `tds_cron_status`. Когда конкретной фиче
  реально понадобится фоновая задача (например `CreatePreviewImageCommand`/
  `UpdateCostsBulkCommand`, на которые ссылались уже перенесённые
  Offers/Campaigns) — она будет использовать Laravel Job, не кастомную
  таблицу.

- **Conversions + Reports подключены (2026-08-29)** через новый общий
  `App\Services\Grid\GridBuilder` (не путать с `EntityGridBuilder` —
  этот для "плоских" отчётов без привязки к одной сущности). Подтверждено
  по реальному исходнику: `conversions.log` ВСЕГДА форсирует
  `grouping=['conversion_id']` независимо от запроса (легаси-особенность
  `ConversionRepository::log()`); статусы конверсии — ровно
  `lead/sale/rejected/rebill` (константы `Traffic\Model\Conversion`).
  `reports.build` сознательно НЕ включает гео/девайс/браузер/referrer
  измерения и календарные группировки — они требуют JOIN'ов или
  MySQL-специфичных функций дат, которых текущий `GridBuilder`
  (однотабличный, без JOIN) не умеет и которые сломали бы SQLite в тестах.
  `conversions.import`/`updateCostDefinition` — TODO-заглушки.

- **GeoDbs + IpInfoDataTypes готовы (2026-08-29).** 15 известных типов
  гео-баз (Ip2Location/Sypex/Maxmind/собственные TDS/ProIP) — статус
  списком, реальный `file_exists()`-чек (честно "не установлено" в свежей
  системе, не подделано). **Исправлена ошибка в доке**
  (`10.9_geodb.md`): `geoDbs.settings`/`saveSettings` — это НЕ
  "автообновление", а карта `тип_данных → id_базы` (какая база резолвит
  country/city/isp/...), одна JSON-запись в `settings` под ключом `ipdb`
  (легаси-константа `Setting::IPDB`) — не отдельные `geodb_*`-флаги, как
  предполагалось в задаче. `updateAction` (реальная загрузка файлов) —
  TODO, 501 с понятным сообщением.

- **Dashboard — нечего переносить.** `Component/Dashboard/Initializer.php`
  полностью пустой класс, никакого контроллера не регистрирует вообще.
  Дашборд в легаси — чисто фронтенд-концепция (собирает виджеты из уже
  существующих `withStats`/`reports.build`), не отдельный backend-модуль.
- **PostbackTemplatesController — реализован сам (тривиально), всегда
  пустой** (тот же паттерн `PATH = NULL`, что у TrafficSourceTemplates —
  `NetworkTemplatesRepository`, вырезанные вендорские данные).
- **Labels + FavouriteReport готовы (2026-08-29).** Осознанное архитектурное
  отклонение: новая таблица `labels` хранит `ref_value` строкой напрямую
  вместо легаси-связки `ref_id` + отдельная словарная таблица на каждый
  `ref_name` — наш `GridBuilder` не умеет JOIN'ы, а словарные таблицы под
  `ip`/`sub_id_N` и т.п. никогда и не создавались в этом порте. Метки теперь
  дедуплицируются по `(campaign_id, ref_name, ref_value)` напрямую.
  `favouriteReports.payload` — храним как непрозрачный `text`, не JSON-тип
  (подтверждено `schema.sql` легаси).

- **BotDetection/Botlist + PostbackTemplates готовы (2026-08-29).**
  Важная поправка МОЕЙ же неточной вводной агенту: `raw_value` НЕ хранит
  введённый юзером текст как есть — легаси безусловно пересчитывает его из
  `min_ip`/`max_ip` при каждом слиянии диапазонов (`_fillRawValue()`), т.е.
  CIDR `1.2.3.0/24` реально сохраняется как `1.2.3.0-1.2.3.255`. Агент
  проверил по коду и не согласился с моей неверной инструкцией. Сигнатуры
  ботов — легаси хранит как текстовый файл (`\n`-список), не JSON/таблицу —
  перенесено как один `Setting`-ряд с текстовым blob-значением, с
  сохранением легаси-бага "array_unique() без присваивания = не дедуплицирует".
  DBCA (проприетарный формат) — не переносится, тот же класс вырезанных
  вендорских данных, что GeoDb/ip2location.

- **Macros + Branding — реализованы сами (без агентов, простые).** Macros —
  статичный список имён макросов (сверен с `MacroRepository::loadMacros()`,
  который уже разбирали при починке `MacrosProcessor` ранее в сессии),
  `type`-фильтрация click/conversion пока НЕ реализована (TODO,
  низкий риск — это просто автокомплит в UI). Branding — нашёл и исправил
  СВОЙ баг: Eloquent плюрализует `Branding`→`brandings`, а реальная таблица
  `branding` (без "s") — поймано живой проверкой curl'ом, не тестами
  (которые для этих двух модулей сознательно не писал ради экономии).

- **Trends + Diagnostics — реализованы сами.** Trends: `indexAction` у
  легаси — буквально пустой метод (не декомпиляционный артефакт, я
  перепроверил — так и задумано), `TrendsDefinition` — это `ReportDefinition`
  один в один (только `range_intervals=null`), поэтому просто делегирует в
  уже готовый `ReportsController::definitionAction()`. Diagnostics —
  **осознанно НЕ 1-в-1 порт**: легаси мешает в одну кучу то, что применимо
  к нам (миграции применены, storage писабельна, свободное место), и то,
  что завязано на несуществующие у нас концепции (легаси-кастомный
  ранер миграций, крон-таблица с "last run" — а мы используем нативный
  Laravel Scheduler без такой таблицы, внешние сетевые проверки версии на
  vendor-сервера tds.io — апдейт-сервиса для этого продукта больше нет).
  Второе — не воспроизведено, а не заменено фейком.

- **Status — реализован сам (без агента), сознательно НЕ 1-в-1.**
  Легаси `StatusService::info()` тащит кучу полей, завязанных на
  инфраструктуру, которой у нас нет: `var/stats.json`, который пишет
  легаси-супервизор процессов RoadRunner (CPU/RAM/uptime/engine statuses/
  fcgi/build_info), TokuDB-статус, крон "last run" (см. уже
  задокументированное решение про Diagnostics), очередь DelayedCommand.
  Новый `object=status.getInfo` отдаёт только то, что реально существует:
  clicks/conversions count, free/total disk space, db_size (MySQL-only,
  `information_schema`, на SQLite в тестах возвращает `null` — так же как
  легаси-`Db::instance()->size()` был MySQL-only), installation_method
  (захардкожен как `"Custom"`, т.к. концепция "approved-путь установки"
  завязана на легаси-специфичные абсолютные пути `/var/www/tds` и т.п.),
  php_engine (`PHP_SAPI`, не RoadRunner). `warmupCacheAction` и
  `restartRoadrunnerAction` не перенесены вообще (нет ни легаси-кэш-слоя,
  ни RoadRunner-процесса, который можно перезапустить, в этом проекте —
  Octane/RoadRunner отложен как будущее архитектурное решение). Проверено
  живым curl (`?object=status.getInfo` / `status.getInstall`) через
  `php -S -t public public/index.php` внутри `tds2-php-dev` — оба 200 OK.
  Полный Pest-suite не пострадал: 313/313 (новых тестов не писал — тривиальный
  модуль, экономия по установленному правилу).

- **Полная перепись всех 51 `Component/`-директорий легаси завершена** —
  каждая либо перенесена, либо осознанно отложена с причиной (см. ниже),
  либо не имеет `?object=`-поверхности вообще и переносить нечего:
  `Archive`, `Av`, `Benchmark`, `Common`, `CommonErrorHandler`, `Console`,
  `Cron`, `Dashboard`, `DelayedCommands`, `Device`, `EntityGrid`, `Grid`,
  `PruneTask`, `Simulation`, `Stats`, `Templates` — все это внутренняя
  инфраструктура/cron-задачи/консольные команды без единого `Controller`-
  класса (проверено `find -iname "*Controller*.php"` по каждой директории
  — 0 совпадений), т.е. нет legacy `?object=` ключа, который нужно было бы
  воспроизводить. Часть из них уже потреблена уже перенесёнными модулями
  (`Grid` → наш `Grid/QueryParams|FilterOperator|EntityGridBuilder|
  GridBuilder`, `Device`-словари пока не нужны), часть относится к уже
  задокументированным отложенным кластерам (`Cron`/`DelayedCommands`/
  `Console` — крон/очередь, `Templates` — маркетплейс шаблонов лендингов/
  офферов, завязан на ещё не построенный Editor/LocalFile).

## Известные пробелы, отложенные СОЗНАТЕЛЬНО (не забытые)

- **SelfUpdate** (`object=selfUpdates`) — весь модуль завязан на реальный
  апдейт-сервер вендора (`SystemUpdaterService`, `TdsUpdater`,
  `SelfChecker`) — для этого форка продукта такого сервера больше нет
  (см. уже принятое решение про Diagnostics/`tds.io`). Переносить
  UI проверки версии/обновления нечего проверять.
- **Home** (`object=home.index`) — рендерит легаси PHTML-шаблон SPA-шелла
  (`application/layouts/index.phtml`) с `kData` JS-конфигом для фронтенда,
  которого ещё нет (`frontend/` пуст по явному решению — сначала бэкенд).
  Возвращаться к этому модулю имеет смысл только вместе со стартом
  `frontend/`.
- **Logs** (`object=Logs`) — `systemAction`/`trafficAction`/
  `postbacksAction`/`sentPostbacksAction`/`enableSSLAction` читают из
  файловых логов через `LoggerService`/`TrafficLoggerService`/
  `PostbackLoggerService`/`SentPostbackLoggerService`/`DomainSSLLogsService`
  с кастomным парсером формата строк (`LogParserService::SYSTEM_FORMAT`) и
  пагинацией/поиском по строке запроса — нетривиальный кусок
  инфраструктуры парсинга текстовых логов, при этом 4 из 5 источников
  (traffic/postback/sent-postback/SSL) читают данные, которых в этом
  проекте физически ещё нет (клик-пайплайн, реальная отправка постбеков,
  доменный SSL-чекер — все три в уже отложенных кластерах). Реализация
  дала бы один рабочий экшен (`systemAction` поверх `storage/logs/
  laravel.log`) и четыре бесполезных стаба — не окупает объём работы по
  парсеру сейчас; отложено до момента, когда появятся реальные источники
  данных для остальных четырёх типов логов.

- **`SystemController` (`object=system`) — НЕ перенесён целиком.**
  `refreshLicenseAction`/`addLicenseKeyAction`/`changeLicenseKeyAction`
  зависят от реального обращения к лицензионному серверу вендора
  (`LicenseService`/`EssentialService`) — в этой сборке лицензия уже
  нейтрализована (`FeatureService::isBasic()` жёстко `return false`,
  `isPro()`/`isBusiness()` — жёстко `true`, см. код, читан напрямую в этой
  сессии), так что переносить UI управления несуществующей лицензией
  большого смысла нет. `loadLanguageAction` зависит от ещё не
  перенесённого i18n-модуля (см. уже задокументированный прецедент в
  `ResourceController::complementaryAsOptionsAction`). Если понадобится
  позже — здесь единственное, что могло бы иметь смысл, это
  `licenseInfoAction`/первичная admin-инициализация, но и она сейчас
  ничего не делает без реального flow создания первого админа через этот
  контроллер (в новом коде админ создаётся иначе, через `users.create`).

- ~~ACL не подключён к Grid~~ **ИСПРАВЛЕНО (2026-08-29).**
  `AclService::getAllowedCampaignIds()` добавлен (порт легаси,
  `ALLOW_ANY`/`ALLOW_NONE`/массив id), подключён и в `EntityGridBuilder`
  (Campaigns — по id, Streams — по `campaign_id` родителя, Offers/Landings/
  TrafficSources — через `filterByAcl()` на списке сущностей), и в
  `GridBuilder` (`WHERE campaign_id IN (...)` на ВСЕХ путях запроса —
  основном, count, summary — `ALLOW_NONE` вообще не идёт в БД).

- **Migrations / LegacyMigrations** (`object=migrations`,
  `object=legacyMigrations`) — НЕ переносится вообще. В легаси это веб-API
  для запуска миграций БД через HTTP из админки — в новой архитектуре
  миграции гоняются штатным `php artisan migrate`, дублировать через
  HTTP-эндпоинт не нужно и небезопасно (незачем открывать HTTP-доступ к
  запуску произвольных миграций).

- **Settings**: перенесены только `index`/`update`. НЕ перенесены `config`/
  `find`/`getAuxiliaryData`/`changeLanguage` — зависят от модулей, которых
  ещё нет (`CachedSettingsRepository`, Redis-кэш, AV, param-repository).

- **Editor (редактор кода лендингов/офферов)** — зависит от ещё не
  собранной инфраструктуры LocalFile (загрузка ZIP, файловое хранилище на
  диске). Строить не на чем, пока LocalFile не реализован.
- **GeoDb** — это НЕ обычная таблица, а управление внешними бинарными
  гео-базами (IP→страна/город/оператор, скачивание/обновление файлов) —
  тот же класс сложности, что Editor. Требует отдельного планирования.
- **Clicks / Conversions / Grid / Reports** — таблицы `clicks`/
  `conversions_2` — это звёздная схема на 60+ колонок со словарными
  таблицами (referrer/keyword/creative_id и т.д.), тесно связанная с
  логикой клик-пайплайна (`traffic-core/`, ещё не начат). Требует отдельной
  спланированной сессии, не рядовой батч.
- **`CampaignsController::saveNestedStreams()`** — ошибки валидации
  вложенных стримов при `campaigns.create`/`update` сейчас тихо
  проглатываются (не долетают до ответа). Задокументировано TODO в коде.

- **ThirdPartyIntegration + CampaignIntegration — портированы фоновым
  агентом (упал по `server_error`/сон ноутбука прямо перед регистрацией в
  диспетчере и этим логом; код был полностью написан и качественно
  задокументирован, координатор проверил каждый файл вручную и завершил
  оставшийся шаг).**
  - `ThirdPartyIntegrationController` (`object=thirdpartyintegration`):
    create/update/get/find/getByCampaignId/delete. `update` — MERGE новых
    ключей в существующий `settings` JSON (не замена), как в легаси
    `ThirdPartyIntegrationService::updateValues()`. `getSettingsIntegration`/
    `updateSettingsIntegrationAction` — несмотря на расположение в этом
    контроллере, реально читают/пишут ГЛОБАЛЬНЫЙ settings key/value store
    (`App\Models\Setting`), а не таблицу `third_party_integration` —
    подтверждено чтением легаси-исходника.
  - **Осознанное отклонение**: `find`/`update` на несуществующий id → чистый
    404 (`{"error":...}`) вместо легаси-бага (200 с
    `{"data":{"id":null}}` из-за auto-vivify в сериализаторе) — тот же класс
    решения, что уже задокументирован для Domains.
  - `TpiMandatoryController` (`object=tpimandatory`): listAsOptions
    (default-опция `"Not synchronize"` — взято дословно из реального
    `translations/en.php`, не выдумано), addCampaign/removeCampaign (с
    точным легаси-багом: замена integration на другую создаёт НОВУЮ
    ассоциацию, старая не удаляется автоматически; `integration_id=0` на
    существующей записи = удаление), all (ACL-фильтрация через
    `AclService::filterByAcl`, group захардкожен как `"Default"` — Groups
    ещё не подключены к Campaign, тот же TODO что и в
    `CampaignsController::listAsOptionsAction`).
  - `FacebookIntegrationController`/`AppsFlyerIntegrationController` —
    только `getDescriptionAction`, статичный ru/en HTML-текст скопирован
    дословно byte-for-byte.
  - `CodePresetsController` (`object=codepresets`): index/show/
    downloadClient(V2). **Найден doc/reality mismatch в самом легаси**:
    `is_pro_only` завязан на `FeatureService::getEdition() in
    ["trial","pro"]`, но `getEdition()` в этой сборке жёстко возвращает
    `BUSINESS` — проверка уже мертва в самом легаси, не только "из-за
    нейтрализации лицензии на нашей стороне". `showAction` на
    несуществующий `id` → буквальный `null` (200), как в легаси, НЕ 404
    (в отличие от TPI выше — здесь легаси-контракт воспроизведён 1-в-1, а
    не переопределён, т.к. это не "полу-битый" 200, а просто пустой
    результат поиска). `downloadClientV2Action` отдаёт файл `kclient_v2.php`,
    но с `Content-Disposition: filename=kclient.php` (БЕЗ "_v2") — выглядит
    как copy-paste баг в легаси, воспроизведён как есть.
  - `KClientJsPresetController` (`object=kclientjspreset`): `showAction`
    only, `generateClientCode()` НЕ перенесён (принадлежит click-serving
    рантайму `traffic-core/`). **Найден dead code в легаси**:
    `CodeGenerator::getCode()` считает `$urlPostback` через
    `NetworkTemplatesRepository::getSecret()`, но подставляет его под ключ
    `{postback_url}`, которого нет в самом шаблоне `$code` (проверено
    `grep -c postback_url` по легаси-исходнику) — значение вычисляется, но
    никогда не используется. В новом коде secret-lookup просто пропущен,
    не стали строить новый config-механизм под мёртвый код.
  - Verification: полный Pest suite 313/313 без изменений (для CRUD-частей
    юнит-тесты сознательно не писали — экономия, компенсировано живыми
    curl-проверками ниже); живые curl-смоук-тесты через
    `php -S 0.0.0.0:PORT -t public public/index.php` внутри `tds2-php-dev`
    на сети `deploy_default`: `codepresets.index`/`.show` (200, включая
    `null`-ответ на несуществующий id), `facebookintegration.getDescription`/
    `appsflyerintegration.getDescription` (200), полный CRUD-цикл
    `thirdpartyintegration.create`→`.find`→`.update`(merge)→`.get`→`.find`
    на несуществующем id (404), `tpimandatory.listAsOptions` (с
    integration и без), `kclientjspreset.show` (200, валидный
    `<script>`-код), `codepresets.downloadClient` (200,
    `application/octet-stream`, 26310 байт — совпадает с размером файла на
    диске). Тестовая запись, созданная curl'ом, удалена из dev-БД после
    проверки.

- **GeoProfiles — реализован сам (без агента).** `object=geoprofiles`:
  index/listAsOptions/show/create/update/delete. Таблица `country_profiles`
  (реальное имя, живой DESCRIBE), `countries` хранится как
  space-separated строка ISO-кодов (не JSON) — на выходе всегда массив.
  `decorated_countries` — человекочитаемый список имён стран, словарь
  скопирован дословно из легаси
  (`application/Component/GeoDb/dictionaries/countries.php` →
  `resources/data/countries.php`, ~250 стран, ru+en), но отдаём только `en`
  (i18n ещё не перенесён — тот же прецедент, что и в `ResourceController`).
  `showAction` на несуществующий id → буквальный `null` (200), как в
  легаси (это НЕ полу-битый объект, а честный "не найдено", поэтому здесь
  воспроизведено 1-в-1, в отличие от Domains/ThirdPartyIntegration).
  `create`/`update`/`delete` — admin-only (403 `{"error":"Access denied"}`
  для не-админа/гостя), `isDemo()`-гейт на delete не перенесён (нет
  demo-режима в новом проекте). Verification: полный Pest suite 313/313 без
  изменений; живой curl: `index` (пустой список), `create` без авторизации
  → 403, `show` на несуществующий id → `null` (200) — все три подтверждены.

- **Уточнение по SelfUpdate/System (лицензия) — важная находка, влияет на
  оценку сложности этих модулей.** При детальном чтении легаси-исходника
  подтверждено: `TdsUpdater::update()` в этой конкретной копии кода уже
  жёстко кидает `"Self-update is disabled on this installation"` (не
  реализует реальную загрузку/распаковку пакета), а
  `Core\Security\ServerFinderService::findServer()`/`getServerList()`/
  `getLicenserServer()` всегда возвращают `NULL`/`[]`. Т.е. тот, кто делал
  эту декомпилированную/нейтрализованную сборку, **уже вырезал** сетевой
  self-update механизм на уровне исходника — той же рукой, что
  нейтрализовала лицензию (`FeatureService::isBasic()` etc., см. выше по
  логу). Практический вывод: если когда-нибудь решим портировать
  `SelfUpdate`, реальной "качать с tds.io и распаковывать поверх
  приложения" логики уже нет даже в старом коде — переносить там
  буквально нечего, кроме заглушек. Понижает приоритет когда-либо
  возвращаться к этому модулю.

- **Уточнение по Cleaner** — очередь (`QUEUE_CONNECTION=database`) в
  проекте уже настроена инфраструктурно, просто ни одной `Job` ещё не
  написано. Реализация `CleanerController::cleanAction()` через
  `DeleteStatsJob::dispatch(...)` + `handle()` с простым `Click::
  whereBetween(...)->delete()` — тривиальна и не требует переноса всего
  DelayedCommands-кластера, вопреки более осторожной прежней оценке. Не
  сделано пока просто потому что не было явного запроса, а не из-за
  реальной сложности.

## 2026-08-29 — Cleaner, Editor+LocalFile, старт traffic-core (3 параллельных агента)

- **Cleaner — перенесён (object=cleaner, только `clean`).** `CleanerController::cleanAction()`
  порт 1-в-1: POST-only, обязательные `start_date`/`end_date`, опциональные
  `timezone`/`campaign_id`. С `campaign_id` — ACL `isEditAllowed` на кампанию,
  403 при отказе. Без `campaign_id`: admin → один job без фильтра, не-admin →
  по одному `DeleteStatsJob` на каждый id из `AclService::getAllowedCampaignIds()`
  (плюс явно обработан краевой случай `ALLOW_ANY` для не-admin с
  full_access/read_only правилом на campaigns — планировщик в этом случае
  ставит один глобальный job, как и для admin). `DeleteStatsJob`
  (`app/Jobs/`) — прямой порт `CleanerService::deleteStats()`, удаляет из
  уже существующих таблиц `clicks`/`conversions` по `datetime`/
  `click_datetime` BETWEEN (+ campaign_id). Ошибка валидации дат
  воспроизведена буквально как в legacy: `{"success":false,"error":"Invalid
  format date"}`, 406 — НЕ через общий field-map `validationError()` других
  контроллеров, т.к. сам legacy бросал именно такой плоский payload, а не
  field-keyed. НЕ перенесены: `warmupCacheAction` (нет `CachedDataRepository`),
  `CleanerService::prune*()` (принадлежат Cron/DelayedCommands — без своей
  `?object=`-поверхности), demo-gate (нет demo-модуля, тот же паттерн, что у
  GeoProfilesController). Pest: 8 новых тестов.

- **Editor + LocalFile (ZIP-загрузка лендингов/офферов) — реализовано.**
  `App\Services\LocalFileService` — порт `Component\Landings\LocalFile\
  LocalFileService` + `Validator\Validator` 1-в-1: генерация уникальной
  slug-папки (`generateUniqueFolder`), распаковка base64-ZIP через
  `ZipArchive` с поиском "главной" папки по index.php/index.html, полная
  валидация как в легаси (blacklist `kclient.php`/`kclick_client.php`,
  forbidden extensions `sh`+`php/phtml/php5/php4` при `!isPhpAllowed()`,
  forbidden-function scan (`system(`, `.exec(`), forbidden-charset scan
  (windows-1251 meta)), настройки `lp_dir`/`lp_allow_php` читаются из уже
  портированной таблицы `settings`. Подключено в `LandingsController`/
  `OffersController::create|updateAction` (`landing_type`/`offer_type ===
  'local'` → `action_type=local_file` + `action_options.folder`, `===
  'preloaded'` → `action_type=curl`, как в реальном
  `ActionableResourceTrait`). `EditorController` (`object=editor`):
  loadFiles/loadFileData/saveFileData/createFile/removeFile — 1-в-1 по
  поведению, `infoLandingAction` НЕ портирован (тривиальная
  ре-сериализация, вне скоупа). **Сознательные отклонения**: (1) `loadFiles`
  отдаёт плоский список `{path,type,ext}` вместо легаси-дерева —
  старый формат заточен под конкретный JS tree-widget, которого нет; (2)
  добавлена защита от path traversal (`LocalFileService::
  resolveSafePath()`) — легаси НЕ проверяет `path` вообще, просто
  конкатенирует; это улучшение безопасности, а не баг-фикс; (3) невалидный
  ZIP/forbidden-контент → 406 `{field:[msg]}` (наша общая конвенция), а не
  легаси-специфичный `Application\Exception\Error`; (4) отсутствующий
  `id`/`type` → 404 вместо легаси-креша на `isEditAllowed(false)`.
  `CreatePreviewImageCommand::enqueue()` не вызывается (PreviewImageService
  не портирован, уже задокументировано выше). **Инфраструктурная находка**:
  образ `tds2-php-dev` не имел `ext-zip` — добавлен в
  `deploy/Dockerfile.dev-php` (`libzip-dev` + `docker-php-ext-install zip`),
  образ пересобран. Pest: 22 новых теста (`LocalFileServiceTest` 11,
  `EditorTest` 11).

- **traffic-core — старт (Фаза 0: план, Фаза 1: сквозной proof-of-concept).**
  Начат перенос click-processing pipeline в `traffic-core/` — отдельный
  lean PSR-7-стек (не Laravel, сырой PDO на ту же БД `tds2`, без ORM/
  рефлексии — архитектурное решение). Полный план с реальным порядком
  стадий (прочитан из `Pipeline::firstLevelStages()`, не выдуман) —
  `docs/TRAFFIC_CORE_PLAN.md`. **Найдено при чтении**: исходное
  предположение "`GatewayRedirectContext` — самый простой входной случай"
  оказалось неверным — это второй шаг уже готового двухшагового редиректа,
  потребляющий JWT-токен из основного пайплайна, и ничего не пишет в
  `clicks`. Настоящий простейший реальный кейс — упрощённый
  `ClickContext`/`ClickDispatcher` путь: стрим, у которого `schema` НЕ
  `landings`/`offers` (тогда `action_type`/`action_payload` лежат прямо на
  строке `streams`, без ротации лендинга/оффера). **Реализовано (Фаза 1)**:
  `FindCampaignStage` (по `?campaign=alias` или `Host` →
  `domains.default_campaign_id`) → `ChooseStreamStage` (первый активный
  стрим без ротации/фильтров) → `BuildRawClickStage` (только NOT NULL
  колонки `clicks`, честные заглушки вместо GeoDb/bot/device-резолвинга) →
  `ExecuteActionStage` (только `action_type=http`, 1-в-1 `HttpRedirect`) →
  `StoreRawClickStage` (реальный INSERT в существующую `clicks`, без async
  delayed-command). Таблицы `clicks`/`conversions` уже существовали в
  `backend/` (созданы заранее под этот момент по реальной DESCRIBE-схеме) —
  новых миграций не понадобилось. **Осознанно отложено** (полный список с
  причинами в `docs/TRAFFIC_CORE_PLAN.md`): ротация стримов/лендингов/
  офферов, `StreamFilters`, визитор/уникальность, 17 из 18 типов экшенов
  (кроме `http`), весь GeoDb/bot/device-резолвинг в `BuildRawClickStage`,
  JWT-токены и куки, hit-limit/cost/payout, рекурсивный `campaign`-экшен,
  постбеки, остальные 7 входных контекстов (`ClickApiContext`,
  `KtrkContext` и т.д.). Verification: живой curl-смок-тест (Docker,
  `deploy_default`, порт 8095) с самостоятельно созданными фикстурами —
  успешный редирект + подтверждённая строка в `clicks`, и честный 404 на
  несуществующую кампанию.

- **Инфраструктурная чистка бэкенда (не относится к переносу модулей)** —
  Laravel в этом проекте используется ЧИСТО как API-бэкенд (фронтенд будет
  отдельным React-приложением, `frontend/` по архитектурному решению), так
  что дефолтный Blade/Vite-скаффолд удалён как мёртвый вес: `resources/
  views/` (пустая заглушка), `resources/css/`, `resources/js/`,
  `vite.config.js`, `package.json`, `VITE_APP_NAME` из `.env`/`.env.example`,
  `npm install`/`npm run build` из composer-скрипта `setup`. `resources/
  data/` (словарь стран GeoProfiles) и `resources/kclients/` (файлы
  CodePresets/KClient) НЕ тронуты — это реальные данные уже перенесённых
  модулей, не фронтенд-скаффолд. В `composer.json` изначально не было
  Blade/frontend-зависимостей (`laravel/breeze`/`laravel/ui`) — убирать
  было нечего кроме npm-строк в скрипте.

Итог сессии: полный Pest-suite **343/343** (0 регрессий, было 313 до этой
сессии), плюс независимый живой curl-смок-тест traffic-core.

## traffic-core — Фаза 2 (реальная ротация стримов) — 2026-08-29

Порт `Traffic\Actions\StreamRotator` (`traffic-core/src/Pipeline/StreamRotator.php`)
+ трёхуровневая логика легаси `ChooseStreamStage::process()`: `forced` →
chooseByPosition, `regular` → chooseByPosition (если `campaigns.type=
'position'`) иначе chooseByWeight (честный `_rollDice`: shuffle + взвешенный
`mt_rand`, реролл при провале фильтра), `default` → первый стрим этого типа
БЕЗ вызова CheckFilters вообще (точное поведение легаси, не упрощение).

`CheckFilters.php` (новый) — честная trivial-pass ветка (0 filters у стрима
→ true, реальная ветка легаси, не костыль). Полный движок 28 типов фильтров
(гео/устройство/бот/referrer/schedule и т.д.) НЕ портирован — завязан на
ещё не портированные GeoDb/bot-detection. При наличии фильтров —
**fail-open**, но громко: `error_log()` + заголовок ответа
`X-Filters-Skipped: stream#N:count`. Осознанный видимый пробел.

НЕ портировано: visitor entity binding/sticky-стримы (Redis, отдельный
кластер "Визитор/уникальность"), ветка `schema=landings/offers`.

Verification: живые curl-тесты (Docker, порт 8096, фикстуры через прямой
SQL, удалены после) — forced > regular независимо от position/weight;
position-режим стабильно выбирает наименьший position (5/5); weight-режим
даёт распределение близкое к весам (2/80 vs 78/80 для весов 1:99, оба
встретились); default — рабочий fallback; прикреплённый фильтр не
блокирует трафик (fail-open), видно в логе и заголовке.

## StreamLandingAssociation + StreamOfferAssociation — 2026-08-29

Разблокирована ротационная привязка лендингов/офферов к стриму
(`share`-based) — реализовано в `backend/`, параллельно с traffic-core
Фазой 2 выше (не пересекается файлами). Новые таблицы
`stream_landing_associations`/`stream_offer_associations` (миграции
`000027`/`000028`) — схема 1-в-1 с легаси (`id`, `stream_id`,
`landing_id`/`offer_id`, `state` varchar(10), `share` int, timestamps).
Модели `StreamLandingAssociation`/`StreamOfferAssociation` + relations
`Stream::landings()`/`offers()`. `StreamsController`: заменены
TODO-заглушки (`$data['landings']=[]`/`$data['offers']=[]`) на реальную
сериализацию; добавлены `assignStreamLandings()`/`assignStreamOffers()`,
подключены в `updateStreamAssociations()`.

**Ключевая находка из реального `StreamService::_updateAssociations()`**
(прочитан заново, не выдумано): upsert идёт по естественному ключу
`(stream_id, landing_id/offer_id)`, а НЕ по `id` ассоциации —
принципиально отличается от `assignStreamFilters()` (тот матчит по `id`).
Также перенесено реальное правило очистки: стрим со `schema` =
`action`/`redirect` (`BaseStream::ACTION`/`REDIRECT`) всегда обнуляет
`landings`/`offers` при сохранении — независимо от `type` (это НЕ то же
самое, что уже существующее правило "TYPE_DEFAULT обнуляет
filters/triggers", это отдельное, ортогональное условие в легаси).
Элементы без `landing_id`/`offer_id` молча пропускаются (не ошибка
валидации) — как в легаси `assign()`.

Pest: 7 новых тестов (`StreamLandingOfferAssociationsTest.php`), включая
явный тест на границу "TYPE_DEFAULT + schema=landings НЕ обнуляет
landings" (документирует реальное, не придуманное поведение).

Итог сессии (Фаза 2, оба потока): полный Pest-suite **350/350** (0
регрессий), плюс живая curl-верификация ротации стримов в traffic-core.
Разблокировано для следующей фазы: ротация лендингов/офферов в
traffic-core (`ChooseLandingStage`/`ChooseOfferStage` +
`LandingOfferRotator`) теперь может использовать реальные
`stream_landing_associations`/`stream_offer_associations` вместо
несуществующих таблиц.

## traffic-core — Фаза 3 (ротация лендингов/офферов) — 2026-08-29

Порт `Traffic\Actions\LandingOfferRotator` (`traffic-core/src/Pipeline/
LandingOfferRotator.php`) — один класс на лендинги и офферы (как в
легаси), `getRandom()`/`_rollDice()` скопированы буквально (алгоритм
отличается от `StreamRotator`: для каждого кандидата `mt_rand(0,
totalWeight + share)`, `$selected` обновляется пока `totalWeight <=
rand`), отклонённые по `share=0`/`state=disabled` кандидаты убираются и
бросок повторяется. `_isEntityOk()` проверяет `state=active` у самой
сущности отдельно от состояния ассоциации.

`ChooseLandingStage.php`/`ChooseOfferStage.php` (новые) — для стримов со
`schema IN ('landings','offers')` (Фаза 2 такие стримы пропускала).
Offer-стадия пропускает выбор, если landing уже выбран (легаси
`isForceChooseOffer` дефолтно false).

**ИСПРАВЛЕНИЕ (2026-08-29, координатор перепроверил после вопроса
пользователя)**: изначально этот пункт был описан как "сознательное
отклонение" (порт ставит `actionType`/`actionPayload` из оффера
напрямую, а не через `needToken`+JWT-токен-флоу). Это оказалось НЕ
отклонением. Реальная причина: `isForceRedirectOffer()` — флаг,
выставляемый каждым диспетчером (входной точкой трафика) при создании
`Payload`, а не глобальный дефолт `false`. `Traffic\Dispatcher\
ClickDispatcher` (обычный клик по трекинг-ссылке — ровно та точка, что
переносит traffic-core) создаёт `Payload` с `force_redirect_offer =>
true` БЕЗУСЛОВНО (подтверждено чтением `ClickDispatcher.php`), так что
легаси в этом флоу тоже ставит action из оффера напрямую — порт 1-в-1.
Токен/JWT-флоу (`needToken`, `isForceRedirectOffer=false`) реален, но
принадлежит другим, ещё не тронутым входным точкам:
`ClickApiContext`/`ClickApiDispatcher` (AJAX-клик), `KClientJSContext`,
`LandingOfferDispatcher` (нужен для сценария "лендинг уже показан, его
JS `kclient.js` просит оффер отдельным запросом позже") — отдельная
будущая фаза (JS-based клиентский трекинг), не пробел в сделанном.

`clicks.landing_id`/`offer_id` теперь реально пишутся (раньше не входили
в INSERT). Побочно обнаружено: миграции `stream_landing_associations`/
`stream_offer_associations` не были применены к dev-БД `tds2-mysql`
(только к sqlite test-БД) — применены вручную перед verification
(подтверждено координатором повторно: обе таблицы на месте, полный
Pest-suite 350/350 без регрессий).

НЕ портировано: entity binding/sticky-выбор (Redis), `forcedOfferId`/
`forcedLandingId`, `ConversionCapacityService::findAvailableOffer()`
(дневной cap оффера), `needToken`/куки.

Verification: живые curl-тесты (Docker, порт 8097, фикстуры через прямой
SQL, удалены после) — weighted-ротация лендингов (1:99 → 14:16 на 30
запросах), прямой redirect на оффер для `schema=offers` без лендингов,
неактивный лендинг с share=1000 корректно никогда не выбран против
активного с share=1 (10/10). `clicks.landing_id`/`offer_id` подтверждены
через SELECT.

## traffic-core — Фаза 4 (реальный движок StreamFilters) — 2026-08-29

Реализован реальный движок для 9 из 28 типов фильтров (`FilterEngine.php`,
`src/Pipeline/Filters/`), покрывающих ~39 из 43 реально зарегистрированных
имён: `AnyParam` (один класс на `source`/`x_requested_with`/`keyword`/
`search_engine`/`ad_campaign_id`/`creative_id`/`sub_id_1..15`/
`extra_param_1..10`), `parameter`, `referrer`, `empty_referrer`,
`schedule`, `interval`, `ip`, `ipv_6`, `user_agent`, `language`.
`CheckFilters.php` переписан: fail-open теперь ПОФИЛЬТРОВО (не на весь
стрим), `X-Filters-Skipped` дополнен именем типа
(`stream#N:name1,name2`). Новая инфраструктура: `Signal.php`/
`CaptureSignalStage.php` — реальный IP/Referer/User-Agent/
Accept-Language/GET+POST запроса (XFF/прокси не разбираются, отдельный
нерешённый вопрос).

**Три бага легаси найдены и исправлены** (не архитектурные решения):
(1) `StreamFilterService::findInWithRegexSupport()`/`equalOrEmpty()` —
`(int)`-каст строки до сравнения ломал wildcard/regex для любого
нечислового значения (референс на декомпиляцию, см. уже
задокументированные находки этого рода); (2) `Tools::ipInCIDR()` —
обрезка по символам вместо битовой маски, верно только на границе
октета (`/8`,`/16`,`/24`), неверно для `/12`,`/20` и т.п.; (3) **найдено
дополнительно по ходу переноса**: `Filter\Ip::isPass()` — `strtok()`-цикл
не переходит к следующему токену при совпадении → бесконечный цикл
(100% CPU) на первом же матче. Все три исправлены в новом коде, ходы
задокументированы в `docs/TRAFFIC_CORE_PLAN.md`.

**Подтверждено**: `HideClickDetect::isPass()` — реальный мёртвый код в
легаси (строит `$params`, но не вызывает `_sendRequest()` и не
возвращает значение). Не портирован, как и `imklo_detect` (оба зависят
от платных внешних антифрод-API — вне скоупа в любом случае).

НЕ починено вне скоупа: `AnyParam`/`Referrer`/`UserAgent`/`Language`
возвращают неявный `false` при отсутствии совпадения независимо от
`accept`/`reject` — перенесено как есть (неоднозначно — может быть
осознанным поведением продукта).

НЕ портировано (fail-open, видно в `X-Filters-Skipped`): гео/устройство/
бот/proxy/`limit`/`uniqueness`/`imklo_detect`/`hide_click_detect` — все
завязаны на ещё не портированную инфраструктуру (GeoDb, device-detection,
BotDetection-рантайм, HitLimitService, визитор/уникальность).

Verification: живые curl-тесты (Docker, порт 8098, фикстуры через прямой
SQL, удалены после) — referrer wildcard, IP CIDR /20 на невыровненной
границе (реальный клиентский IP), AnyParam `source`, schedule с
несовпадающим днём — все корректно блокируют/пропускают; отложенный тип
(`country`) — fail-open с видимым заголовком; регрессия — стрим без
фильтров не сломан.

## traffic-core — Фаза 5 (15 из 18 оставшихся типов экшенов) — 2026-08-30

Реализованы `blank_referrer`, `curl`, `do_nothing`, `formsubmit`, `frame`,
`iframe`, `js`, `js_for_iframe`, `js_for_script`, `meta`, `remote`,
`show_html`, `show_text`, `status404`, `sub_id` (`src/Pipeline/Actions/`,
dispatch через `ExecuteActionStage::REGISTRY`). `campaign`/`double_meta`/
`local_file` остаются 501 (каждый — по отдельной, уже задокументированной
причине). Полная детализация в `docs/TRAFFIC_CORE_PLAN.md`, Фаза 5.

**Главная находка — реальный баг легаси, LIVE-подтверждён против
работающего приложения (`tds-app`, порт 8090), не только вычитан
статически**: `AbstractAction::_executeInContext()` (и идентичная копия в
back-compat `Component\StreamActions\AbstractAction`) — механизм
переключения рендер-контекста по `frm`-параметру для 11 типов действий —
сломан двумя способами: (1) `_executeDefault()` — мёртвый код,
недостижим ни при каких условиях; live-тест подтвердил, что `frame`- и
`js`-экшены отдают ПУСТОЕ тело на обычном клике без `frm`-параметра в
реальном легаси; (2) ветки `frm=script`/`frm=frame` перепутаны местами —
live-тест на `js`-экшене подтвердил, что `frm=frame` вызывает
`_executeForScript()`, а `frm=script` вызывает `_executeForFrame()`.
Воспроизведено для верификации через временную фикстуру в legacy dev-БД
(`tds-mysql`, campaign alias `frmtest1`, stream `action_type=frame`/`js`),
кеш легаси-приложения (`/app/cache` внутри `tds-app`, доктрина
file-cache) пришлось сбросить вручную, чтобы подхватить новую кампанию —
фикстура и кеш очищены после проверки.

**Это ИСПРАВЛЕНО в порте, не воспроизведено** — иначе все 11 типов
действий были бы одинаково "рабочими только для админки, но пустыми на
реальном клике" и в traffic-core, что обессмысливало бы саму задачу этой
Фазы. См. `AbstractAction.php`'s докблок в traffic-core для полного
разбора и live-доказательств.

Прочие находки, перенесены как есть (не исправлены, задокументированы в
докблоках соответствующих классов): `Iframe::executeForFrame()` не всегда
форсит 302 (зависит от `kversion`-параметра); `JsForScript::
executeForFrame()` ставит `Content-Type: html/text` (похоже на опечатку
вместо `text/html`); `FormSubmit` не экранирует значения `POST`-параметров
в HTML (как и легаси).

Общий пробел на все 15 (и дальше): `processMacros()`/`MacrosProcessor` не
портирован нигде в traffic-core — payload/контент используется сырым.
`AdsParser` тоже не портирован — `Meta`/`BlankReferrer`/`ShowHtml`
поэтому не реализуют свой `_executeForScript()` (откат на честную
заглушку "incompatible", а не битый вывод).

Verification: Docker (`tds2-php-dev`, `deploy_default`, порт 8099),
кампания `actiontest1` + один стрим в `tds2` dev-БД, `action_type`
переключался `UPDATE` перед каждым тестом. Все 15 типов проверены живыми
curl-запросами (включая GET vs POST для `formsubmit`, plain/jsonp для
`sub_id`, обычный клик vs `frm=frame`/`frm=script` для `js`, реальный
внешний HTTP-фетч через `https://httpbin.org` для `curl` и `remote` —
включая проверку файлового кеша `remote` на диске второй попыткой).
`php -l` чисто на всех новых файлах. Фикстуры (кампания/стрим) удалены
из dev-БД после проверки.

---
*Обновляется по ходу переноса — дописывать сюда, не заводить новый файл.*
