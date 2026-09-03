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

## Полная история traffic-core (Фазы 1-17, 2026-08-29 — 2026-09-02) — АРХИВИРОВАНА

Вынесена в `docs/PORTING_LOG_ARCHIVE.md` 2026-09-03 (была 908 строк
завершённой, закрытой истории — полный порт traffic-core от нуля до
Phase 17, плюс первые backend-находки). Читать архив только если нужен
конкретный исторический контекст; для текущей работы он не нужен.

---

## backend — раздел 1 backlog'а (BACKEND_REMAINING_WORK.md), пункты 1.1/1.3 — 2026-09-03

**1.1 (ACL на withStatsAction) — аудит был неверен, уже реализовано.**
Пять контроллеров (Campaigns/Offers/Landings/Streams/TrafficSources)
несли устаревший докблок "TODO: ACL not wired here yet", но
`EntityGridBuilder::applyAcl()` уже реально фильтрует по ACL (вызывается
из `loadEntities()`), фидится `user:`-параметром, который каждый
`withStatsAction` уже передавал. `tests/Feature/GridAclTest.php` (10
тестов) уже покрывал это до моей правки. Изменено только 5 докблоков
(ссылка на реальную реализацию вместо устаревшего TODO), кода не
трогал. Полный `./vendor/bin/pest` — 350/350 до и после.

**1.3 (ReportsController geo/device/isp измерения) — сделано, аудит
тоже был частично неверен.** Премиса "паттерн джойна на ref_sources/
ref_referrers/ref_keywords уже есть, просто не расширен" была ложной —
`grep` подтвердил: `App\Services\Grid\GridBuilder` был честно
single-table-only, ни один вызывающий код (ReportsController,
ConversionsController) никогда не джойнил ни на одну `ref_*` таблицу.

Реализация: `GridBuilder` получил опциональный 4-й конструкторный
параметр `array $joins = []` — список `[table, first, operator,
second]`-кортежей, применяется как `leftJoin()` в `baseQuery()` (значит
— на select/total-count/summary запросы разом). Пустой по умолчанию, не
ломает единственный другой вызов (`ConversionsController::logAction`,
таблица `conversions`, без geo/device).
`ReportsController::GEO_DEVICE_JOINS` — 13 LEFT JOIN-ов
(`clicks.visitor_id -> visitors -> ref_countries/ref_regions/ref_cities/
ref_browsers/ref_browser_versions/ref_os/ref_os_versions/
ref_device_types/ref_device_models/ref_isp/ref_operators/
ref_connection_types`, все LEFT — FK-и на `visitors` nullable, клик без
визитора не должен пропадать из отчёта). 12 новых логических колонок в
`BUILD_COLUMNS_BASE` (`ref_x.value` напрямую, без под-запросов —
под-запрос как строка сломал бы `FilterOperator::apply()`'s `where()`,
который использует `columnExpressions[$name]` как ИМЯ колонки, не raw
SQL) + соответствующие записи в `definitionAction()`.

Попутно исправлена вторая устаревшая докстрока в этом же файле:
`buildAction()` утверждал "ACL NOT applied — GridBuilder has no ACL
hook" — тоже неверно, `GridBuilder::baseQuery()` уже применяет
`campaign_id IN (...)`-рестрикцию через `AclService::
getAllowedCampaignIds()`, покрыто тем же `GridAclTest.php`
(`reports.build` тест).

Тесты: 3 новых в `tests/Feature/ReportsTest.php` (реальный join
возвращает имя измерения; LEFT JOIN не роняет клик без визитора/ref-
строки; group+filter по `country` с двумя визиторами). Живая проверка —
не только SQLite Pest-сьют, но и реальный MySQL (`tds2-mysql` в Docker):
фикстура (`ref_countries`/`ref_ips`/`ref_user_agents`/`visitors`/
`clicks` через `DB::table()`, нет ещё Eloquent-моделей для этих таблиц)
+ вызов `GridBuilder` напрямую через `php artisan tinker` — джойн
`clicks -> visitors -> ref_countries` вернул реальное `country=FR`,
фикстуры удалены. Полный `./vendor/bin/pest` — 353/353, `php -l` чисто
на `GridBuilder.php`/`ReportsController.php`/`ReportsTest.php`.

Не в скоупе (см. BACKEND_REMAINING_WORK.md 1.3 для деталей):
referrer/search_engine/keyword/source и другие NAME-колонки,
`language`/`ip`/`user_agent` (MySQL-only `INET_NTOA`, SQLite-сьют не
потянет).

---

## traffic-core — бот-детекция (`_checkIfBot`) — 2026-09-03

По прямому запросу пользователя (не из исходного аудита backend/), вне
предыдущего "traffic-core полностью готов, не трогать" — реализована
недостающая часть легаси `BuildRawClickStage::_checkIfBot()`
(`application/Traffic/Pipeline/Stage/BuildRawClickStage.php`), ранее
честно помеченная как NOT ported.

Легаси-механизм (прочитан реальный код, не только доклист из плана):
1. GeoDb `IpInfoType::BOT_TYPE` — первая проверка в `_checkIfBot()`.
   Подтверждено недостижимо в этом проекте: требует платного IP2Location
   PX-тира, тут только бесплатный LITE DB3 (см. `GeoDbResolver`'s
   докблок про ISP — тот же принцип). Не портировано, задокументировано.
2. `DeviceInfoService::info()`'s `$this->_detector->isBot()`
   (`matomo/device-detector`, гораздо больше сигнатур, чем
   `UserBotListService`'s ~50) — но ТОЛЬКО когда включена настройка
   `check_bot_ua` (`$detector->skipBotDetection(!$toCheckBot)`), иначе
   detector вообще не проверяет бот-сигнатуры. Если true — authoritative,
   пропускает шаг 3.
3. `UserBotListService::isBot()` — `check_bot_empty_ua` (пустой UA),
   `check_bot_ua` (тот же хардкод-список ~50 сигнатур + кастомный список
   администратора, `Setting` `bots.additional.signature`,
   `App\Http\Controllers\Admin\BotlistController`), `check_bot_ip`
   (диапазон `user_bot_ips.min_ip <= ip2long($ip) <= max_ip`).
   `check_bot_referer` читается, но нигде не используется в реальном
   `isBot()` — честный мёртвый легаси-option, не портирован.

**Найдено при чтении, критично для архитектуры**: легаси мигрировал в
2016 (`application/migrations2/20161007163321_migrate_bot_actions_to_
forced_actions.php`) с мёртвых `campaigns.action_for_bots`/
`bot_redirect_url`/`bot_text` колонок на нормальный `StreamFilter`
(`name='bot'`, `Filter\IsBot::isPass()`) на `type='forced'` потоке —
это и есть РЕАЛЬНЫЙ, текущий механизм маршрутизации бот-трафика
(`mode=accept` — поток принимает ТОЛЬКО ботов, `mode=reject` — только
не-ботов). Просто прописать `clicks.is_bot` было бы половинчатым
решением — без этого фильтра бот-трафик так и не маршрутизировался бы
никуда отдельно, только помечался бы в статистике постфактум.

Это потребовало пересмотра порядка стадий: в этом проекте (в отличие от
легаси, где `BuildRawClickStage` бежит ДО `ChooseStreamStage`) is_bot
раньше вычислялся бы в `BuildRawClickStage`, который тут работает ПОСЛЕ
`ChooseStreamStage` (см. `public/index.php`'s "ordering deviation" note,
Phase 4) — фильтр `bot` не успел бы получить значение вовремя. Решение:
`payload->isBot` теперь резолвится в `ResolveVisitorStage` (бежит рано,
сразу после `CaptureSignalStage`, до `ChooseStreamStage`), не в
`BuildRawClickStage` (тот теперь просто читает уже готовое значение).

Новое: `TrafficCore\BotDetection\BotDetectionService` (шаги 2+3 выше,
метод `resolve(?bool $deviceIsBot, $ua, $ip)`). `DeviceInfoResolver`
теперь читает `check_bot_ua` и делает `skipBotDetection()`
условным вместо всегда-true, возвращает `is_bot` (null когда настройка
выключена). `FilterEngine::evaluate()` — новый `bot`-кейс (`$isBot` —
новый trailing-параметр, тем же способом, что `$streamId` для `limit`),
`CheckFilters::isPass()`/`StreamRotator` прокидывают его насквозь.
`ClickMacroValues`'s `is_bot`-макрос теперь реальный (раньше хардкод
`'0'`).

**Побочная находка и фикс (не бот-детекция, вскрыто при живом тесте
`check_bot_empty_ua`)**: `VisitorResolver`'s NOT-NULL fallback для
пустого User-Agent (`$userAgentId ??= findOrCreateByValue('ref_user_agents',
'')`) был мёртвым кодом — `DictionaryRepository::findOrCreateByValue()`
трактует пустую строку ТАК ЖЕ, как null (`return null`), так что
фоллбэк вызывал тот же метод с тем же аргументом и тоже получал null.
Любой запрос с пустым User-Agent падал с необработанным
`PDOException: Column 'user_agent_id' cannot be null` (500,
`VisitorResolver.php:90`) — то есть именно тот сценарий, который
`check_bot_empty_ua` призван обрабатывать, вообще не доходил до
бот-проверки. Исправлено новым параметром `bool $allowEmptyString`
(default false — поведение для всех остальных вызовов не меняется).

Verification (живой, Docker `tds2-php-dev` + `php -S`, `tds2-mysql`/
`tds2-redis`, `deploy_default` сеть): кампания + `forced`-поток (фильтр
`bot`/`accept`, action `status404`) + `regular`-поток (`do_nothing`,
fallback). Обычный Chrome UA → 200, `is_bot=0`, `stream_id`=regular.
`Googlebot` UA → 404, `is_bot=1`, `stream_id`=forced. Пустой UA (с
`check_bot_empty_ua=1`) → 404, `is_bot=1` (до фикса — 500). IP из
`user_bot_ips` с обычным UA → 404, `is_bot=1` (изолированная проверка
`check_bot_ip`, не зависит от UA-сигнатур). Кастомная UA-сигнатура
(`Setting` `bots.additional.signature`) с UA, которого нет ни в
хардкод-списке, ни у `matomo/device-detector` → 404, `is_bot=1`
(изолированная проверка кастомного списка). Инверсия: тот же
`bot`-фильтр в `mode=reject` пропустил обычный (не-бот) UA на forced-
поток вместо обычного (валидация REJECT-ветки `FilterEngine::bot()`).
Все фикстуры (campaign/streams/stream_filters/clicks/user_bot_ips/
settings) удалены после проверки. `php -l` чисто на все 11 изменённых +
1 новый файл.

Не в скоупе (осознанно, не забыто): geo/device/uniqueness/imklo/
hide_click остаются fail-open в `FilterEngine` (только `bot` реализован
в этом раунде — это всё, что было запрошено); `_checkIfProxy()`
(`is_using_proxy`) на тот момент не был портирован — **портирован позже,
см. запись "Proxy-детекция" ниже**.

---

## conversions.import (backlog 3.2) — 2026-09-03

Реализован `App\Services\ConversionImportService` — порт легаси
`ConversionsService::processEntries()`/`import()`/`importArray()`.
Аудит был отчасти неверен: причиной 501 в доке значилось "зависит от
`Component\Clicks\Grid\ClicksDefinition`" — на самом деле `import()` НЕ
зависит от ClicksDefinition вообще (та зависимость — только у
`updateCostDefinitionAction`, соседнего метода того же контроллера,
который так и остаётся 501). Реальная зависимость `import()` —
`Component\Postback\ProcessPostback\Pipeline` (та же логика, что живые
постбеки), которая уже портирована в `traffic-core/src/Postback/
PostbackProcessor.php`. Поскольку `backend/` и `traffic-core/` —
раздельные Composer-проекты (ionCube-план из ARCHITECTURE_PLAN.md), код
продублирован нативно на Eloquent, той же семантикой find-or-update-by-
sub_id + синк click-тоталов.

Осознанно НЕ портирована конвертация валют (`CurrencyService::exchange()`
бьёт во внешний exchange-rate API — тот же прецедент, что
`TrafficCore\Postback\Postback` уже задокументировал для живых
постбеков). `currency`-параметр остаётся обязательным (406), но не
влияет на сохранённый revenue.

Тесты: `tests/Feature/ConversionsTest.php` (4 новых). Живая проверка на
реальном MySQL (`tds2-mysql`) через `php artisan tinker` — фикстуры
удалены. Полный `./vendor/bin/pest` — 361/361, `php -l` чисто.

---

## Console/Cron/PruneTask (backlog 2) — частично закрыто — 2026-09-03

Полный триаж легаси (не по памяти, реальный `grep -rl` по
`application/`):

**18 `CronTaskInterface`-задачи:**
`Domains\EnableSSLTask`, `Domains\CheckDomains`, `Triggers\RunTriggersTask`,
`Archive\PruneArchive`, `Triggers\DeleteOldTriggers`,
`DelayedCommands\ExecuteDelayedCommand`,
`ThirdPartyIntegration\SyncCostsWithFacebook`,
`ThirdPartyIntegration\SyncConversionAppsFlyer`,
`System\WarmupCacheTask`, `Logs\LogCleaner`, `System\RefresherTask`,
`System\CheckTsTask` (пустой `run(){}` — мёртвый код в самом легаси),
`System\FlushOldCacheTask`, `Templates\UpdateTemplatesTask`,
`Cleaner\PruneData`, `Reports\PruneOldFiles`,
`Stats\PruneMysqlSessions`, `Grid\PruneReferences`, `Clicks\PruneClicks`.

**9 `PruneTaskInterface`-задачи (GENERAL_TYPE/REFERENCE_TYPE) + 7
`BaseArchivePruneTask`-наследников (ARCHIVE_TYPE, по одному на
campaigns/streams/offers/landings/traffic_sources/affiliate_networks/
domains).**

**Портировано (4 команды, см. BACKEND_REMAINING_WORK.md раздел 2 для
деталей реализации/тестов)**: `app:prune-archived-entities` (7
ARCHIVE_TYPE), `app:prune-click-stats` (`Clicks\PruneClicks`, реюз
`DeleteStatsJob`), `app:prune-orphaned-data` (`pruneVisitors`/
`pruneConversions`/`pruneClickLinks`), `app:prune-expired-password-hashes`
(`PruneUserPasswordHash`).

**Найдено, НЕ портировано (структурная причина, не пропуск)**:
`Triggers\DeleteOldTriggers` — этот проект даёт `triggers.stream_id`
реальный `cascadeOnDelete()` FK (легаси — нет), так что осиротевший
trigger физически невозможен. Подтверждено живым тестом: даже фикстура
с несуществующим `stream_id` не проходит вставку (SQLite FK error в
тестах, тот же constraint в реальном MySQL).

**Осознанно не портировано (зависимости/приоритет — не забыто)**:
- `SyncCostsWithFacebook`/`SyncConversionAppsFlyer` — реальные внешние
  API (Facebook/AppsFlyer), отдельная задача с credentials.
- `RunTriggersTask` (нужен `AVCheckerService`/`CheckTrigger` — не
  портированы), `CheckDomains`/`EnableSSLTask` (DomainChecker/certbot —
  раздел 5, прод-деплой), `UpdateTemplatesTask` (внешний template-CDN,
  Templates-модуль вообще не виден в этом проекте).
- `WarmupCacheTask`/`FlushOldCacheTask`/`System\CheckTsTask` — легаси-
  кэш-namespace'ы или пустой no-op, не применимо к этой архитектуре.
- `PruneMysqlSessions` — легаси MySQL-сессионное хранилище уникальности,
  этот проект использует Redis TTL (`UniquenessService`) — само-
  истекает, prune не нужен в принципе.
- `RefresherTask` (принудительный HTTPS через 31 день) — низкий
  приоритет, тривиально, но не запрошено явно.
- `PruneDailyCap`/`PruneStreamEvents`/`PruneLandingOfferCache`/
  `PruneUserBotDBCA`/`PruneHitLimits` — зависят от непортированной
  инфры (ConversionCapacity, файловый lp-кэш, DBCA-бинарники, Redis
  `rate:*`-очистка для HitLimit — см. `HitLimitService`'s собственный
  докблок "NOT ported: prune()").
- `pruneReferences()` (ref_*-словари) — зависит от `ClicksDefinition::
  getRelations()`, не портировано (см. `ConversionsController::
  updateCostDefinitionAction`'s докблок, та же причина).

Полный `./vendor/bin/pest` — 367/367, `php -l` чисто, живая проверка на
реальном MySQL для всех 4 команд (включая `queue:work --once` для
`prune-click-stats`, т.к. `.env`'s `QUEUE_CONNECTION=database`, не
`sync`).

---

## Groups — реальные имена (backlog 1.2) — 2026-09-03

Заменены null/хардкод-стабы в Campaigns/Offers/Landings контроллерах на
реальный `Group`-модель lookup. **Найдено при чтении реальных источников
(не предположено)**: Campaigns и Offers/Landings следуют РАЗНЫМ
контрактам — `CampaignSerializer` фоллбэкает пустой `group_id` на
`group_id=0`+`group='Default'`, а `OfferRepository`/`LandingRepository`/
`Core\Entity\ListOptions\Builder::build()` — простой LEFT JOIN без
фоллбэка (`group=null`). Портировано отдельно под каждый контракт, не
унифицировано ошибочно.

Список lookup не имел готового репозиторного метода в
`GroupsController` — простой `Group::find()` (одиночный serialize) /
`whereIn(...)->pluck('name','id')` (списки, избегает N+1). Тесты (по
2-3 на контроллер) + живая проверка на реальном MySQL через `php
artisan tinker`, фикстуры удалены. Полный `./vendor/bin/pest` —
373/373, `php -l` чисто.

---

## Preview-изображения (backlog 4) — 2026-09-03

Уточнение у пользователя вскрыло: легаси-док `docs/default/
TODO_IMPROVEMENTS.md` описывает ДВЕ разные "preview"-идеи, ни одна не
была реализована в легаси. Сделаны обе — детали и живая проверка в
`docs/BACKEND_REMAINING_WORK.md` раздел 4. Кратко по новым файлам:

- `traffic-core/public/preview.php` — новый entry point, HMAC-токен
  (`type:id:expires`, `PREVIEW_SECRET` env, тот же принцип что
  `JWT_SALT`), рендерит local_file напрямую через уже существующий
  `TrafficCore\Pipeline\Actions\LocalFile` (ноль изменений в этом
  хендлере — просто вызван с минимальным `Payload`).
- `backend/app/Services/PreviewUrlBuilder.php` — общий HMAC-URL builder
  (раньше было 2 копии одного и того же в Landings/OffersController,
  вынесено в одно место).
- `backend/app/Services/PreviewImageService.php` — headless-Chrome
  скриншот через `chrome-php/chrome` (проверен на репутацию перед
  установкой: 5.7M+ downloads, MIT, Graham Campbell — чисто) + новый
  Docker-сервис `deploy/docker-compose.yml` `screenshot`
  (`chromedp/headless-shell`, профиль `screenshot`, флаг
  `--remote-allow-origins=*` обязателен — иначе CDP WebSocket
  handshake падает с 403, подтверждено живьём).
- `backend/app/Jobs/GenerateLocalFilePreviewJob.php` — порт
  `CreatePreviewImageCommand::enqueue()`. **Реальный баг, найденный и
  исправленный при живом тесте**: если просто дать
  `PreviewImageService::capture()` бросать исключение наружу, это ломает
  вызывающий HTTP-запрос с 500 при `QUEUE_CONNECTION=sync` (тестовое
  окружение) — джоб выполняется синхронно прямо в том же запросе.
  Исправлено try/catch внутри `handle()` (лог + молчаливый пропуск) —
  сохранение/удаление файла в редакторе никогда не должно падать
  из-за недоступного скриншот-сервиса.

Verification: полный живой pipeline (headless-shell + traffic-core
`php -S` + реальный MySQL в Docker) — save → job → подписанный URL →
`preview.php` → headless Chrome → валидный PNG 800×600 на диске.
Фикстуры удалены. 6 новых Pest-тестов, полный `./vendor/bin/pest` —
384/384, `php -l` чисто.

---

## Контрактные тесты — реальный запуск против нового бэкенда впервые (backlog 6) — 2026-09-03

Добавил `tests-contract/tests/BotlistTest.php` (9 тестов, IP-list +
UA-signature, оба независимых саб-фичи `BotlistController`). При
живом прогоне против нового бэкенда (`php artisan serve`) обнаружил,
что `beforeEach`'s `auth.login` падал 404 — то есть НИ ОДИН тест из
уже существующих ~24 "покрытых" модулей никогда реально не проходил
против нового бэкенда, только против легаси. Причина: `tests/Support/
ApiClient.php`'s `base_uri = "{target}/admin/"` (без `index.php`) —
новый бэкенд (`Route::match(..., '/admin/index.php', ...)`, точный
путь, без directory-index фолбэка, который легаси прощает через
реальный веб-сервер) отвечал 404 на любой запрос. Исправлено (добавлен
`index.php` в base_uri), подтверждено живьём против ОБОИХ таргетов
(`GroupsTest`/`BotlistTest` — 100% на legacy И на новом бэкенде).

Также обнаружил и исправил: тестовый `admin`-юзер в общей dev-БД был
создан через `DatabaseSeeder`'s `User::factory()->admin()` с рандомным
паролем — не совпадал с `ApiClient::DEFAULT_PASSWORD` ("TdsAdmin2026!",
который тест-сьют предполагает как известный fixture-пароль с самого
начала, судя по докблоку константы). Обновил пароль этого dev-only
юзера через `Hash::make()`, чтобы сьют вообще мог логиниться.

После починки полный прогон (93 теста) впервые дал реальный сигнал:
40 падений. Разобрал корневую причину для большинства —
`landings`/`offers`/`domains`/`traffic_sources`/`affiliate_networks`.
`state` объявлены `->nullable()` БЕЗ дефолта в своих миграциях (в
отличие от `campaigns`/`streams`, у которых `->default('active')`)
— свежесозданная запись получала `state=NULL`, что молча исключало её
из любого `WHERE state='active'`/`!='deleted'` листинга (NULL в SQL —
ни true, ни false). Рассматривал миграцию с `ALTER TABLE MODIFY`, но
это MySQL-only синтаксис, ломает SQLite (на котором гоняется Pest) —
вместо этого добавил `$fill['state'] ??= 'active';` в `createAction()`
всех 5 контроллеров (App-уровень, тот же паттерн, что уже был в
`StreamsController.php:805` для `StreamLandingAssociation`), плюс
бэкофилл существующих NULL-строк в общей dev-БД прямым `UPDATE`.

После фикса: Landings/Offers полностью зелёные против нового бэкенда,
Domains частично (падения там — другая причина, id-less/несуществующий
id "не 404, а 200" legacy-quirk, не разобрано). Ещё ~7 падений по
всему сьюту — ожидание непустого `stacktrace` в error-респонсах, где
код реально хардкодит `''` (найдено уже в ConversionsController/
CleanerController ранее в этой же сессии) — реальный ли это регресс
или неверное предположение теста, не выяснено, отдельная задача.

Verification: полный `backend/./vendor/bin/pest` — 384/384 (мой фикс
не сломал ничего). `tests-contract` — `BotlistTest`/`GroupsTest` 100%
на обоих таргетах. Существующие NULL-строки в dev-БД (landings/offers/
domains/traffic_sources/affiliate_networks) бэкофилены на 'active'.
`php -l` чисто на всех изменённых файлах.

---

## Разбор оставшихся контрактных падений: stacktrace + 3 попутных бага — 2026-09-03

Продолжение предыдущей записи (после фикса `ApiClient` base_uri + `state`-
дефолта, 40 падений → упало до 21). По запросу пользователя разобрал
оставшиеся "про stacktrace" падения.

**Корневая причина — реальная, не мнимая.** Проверил легаси
`Admin\Context\AdminContext::handleException()`: для `NotFoundError`
(и `ADODB_Exception`) легаси РЕАЛЬНО кладёт настоящий PHP-стектрейс —
`"stacktrace" => $e->getTraceAsString()`. Это НЕ дебаг-режим-зависимая
случайность, а буквальный, всегда исполняемый код. При этом весь этот
Laravel-порт (19 файлов, ~27 мест) хардкодил `'stacktrace' => ''` в
`notFound()`/`dbError()`-хелперах. Исправлено скриптом (`sed` по всем
файлам сразу, две устойчивые сигнатуры: `'error' => $message, ...` →
`(new \Exception($message))->getTraceAsString()`; `'error' =>
$e->getMessage(), ...` → `$e->getTraceAsString()`) + 3 места вручную
(литеральные строки в `ConversionsController`/`ObjectDispatchController`
x2).

**Попутно найдено и исправлено, копая эту причину (3 отдельных реальных
бага):**
1. `settings` таблица была практически пустая (1 строка) — легаси
   `data.sql` сеет 52 дефолтных настройки, ни одна не была
   портирована в Laravel-сидер. Новый `database/seeders/
   SettingsSeeder.php` (буквальная копия всех 52 пар), подключён в
   `DatabaseSeeder`. Разово прогнан `php artisan db:seed
   --class=SettingsSeeder` на общей dev-БД (НЕ полный `db:seed` —
   тот бы попытался пересоздать admin-юзера и упал на уникальном
   `login`).
2. `ProfileController::indexAction()` — неправильное имя метода.
   Легаси `Component\Users\Controller\ProfileController` имеет
   `showAction()`, НЕ `indexAction()` (`?object=profile.index` 404-ит
   против реального легаси). Переименовано + поправлен свой же
   `tests/Feature/ProfileTest.php` (тоже использовал `'index'`).
3. `SettingsController::findAction()` отсутствовал целиком (легаси
   имеет `find`/`config`/`getAuxiliaryData`/`changeLanguage` кроме
   уже портированных `index`/`update`) — добавлен `findAction`
   (`{"key":.., "value":..}`, `value: null` для неизвестного ключа,
   без isAdmin-гейта, как в легаси).
4. `ObjectDispatchController`'s `method_exists()`-фолбэк на
   неизвестный action/controller отдавал голый текст "Not Found"
   вместо JSON `{error, stacktrace}` — тоже исправлено (тот же коммит,
   что и общий stacktrace-фикс, поскольку легаси даёт ИМЕННО эту
   ошибку через `NotFoundError` с реальным `getTraceAsString()`).

Verification: полный `backend/./vendor/bin/pest` — 384/384 без
изменений. `tests-contract` против нового бэкенда: 40 → 21 → **15**
оставшихся падений (все 7 "про stacktrace" закрыты; Settings/Profile —
100%). `php -l` чисто на всех ~20 изменённых файлах.

**Осталось (НЕ разобрано, отдельная причина от stacktrace, не в
скоупе этого захода)**: `ApiKeysTest` (2), `CampaignsTest`'s legacy-
fixture smoke test (id=4 — не относится к свежей БД), `DomainsTest`
(3 — `domains.create` возвращает массив из 23 элементов вместо 1,
похоже на отдельный баг сериализации создания), `GroupsTest`
(дубликат имени не отклоняется 406), `UserPreferencesTest` (1),
`UsersTest` (3 — фикстура `users.create` падает на "new_password
required", плюс `users.listAsOptions` отсутствует). Следующий шаг —
разобрать каждое так же, как здесь: живой curl + сверка с реальным
легаси-источником.

---

## traffic-core — pretty-URL postback (по запросу пользователя) — 2026-09-03

Новая фича, без легаси-эквивалента (легаси всегда использует
query-параметр — `?key=SECRET&subid=...` либо голый токен
`?SECRET&subid=...`, подтверждено чтением `PostbackDispatcher::
_findKey()`/`NetworkTemplatesRepository::getSecret()`). Пользователь
попросил вариант вида `/{key}/postback?subid=...&status=...&payout=...`
— ключ в пути, а не в query.

Добавлено: `PostbackAuthService::findPathKey()` — парсит `/{key}/postback`
из пути запроса, используется как fallback ПОСЛЕ обоих легаси-
механизмов (`postback.php`: `findKey() ?? findPathKey()`), не заменяет
их. traffic-core не имеет собственного роутинга (каждый entry point —
буквальный файл в `public/`), поэтому реальный запрос с таким путём
нуждается во внешнем rewrite: `deploy/nginx-traffic-core.conf.example`
(референс-конфиг для прода, раздел 5 "прод-деплой" всё ещё план, не
подключено) + `traffic-core/public/router.php` (для локальной
разработки — единственный entry point в проекте, который специально
запускается С router-скриптом, `php -S host:port -t public
public/router.php`; для всех остальных entry-point'ов явный
router-скрипт НЕ используется — см. "Операционная находка" в записи
про Phase 7 выше, тот же принцип: `router.php` для НЕраспознанных
путей возвращает `false`, штатное поведение встроенного PHP-сервера не
меняется).

Verification: живой прогон в Docker (`tds2-mysql` реальные
кампания+клик, `postback_key` setting = "6a05078", `router.php`) —
`GET /6a05078/postback?subid=...&status=sale&payout=50` → 200
"Success", в БД реально создалась конверсия (`sub_id=..., status=sale,
revenue=50.0000`); `GET /wrongkey/postback?...` → 403 "Incorrect
postback code". Фикстуры удалены. `php -l` чисто на всех 3 файлах.

**ИСПРАВЛЕНИЕ (2026-09-03, при повторной стресс-проверке этой же сессии):
докблок выше и в коде был НЕВЕРЕН — "без легаси-эквивалента" не
подтвердилось при чтении реального `Core\Router\TrafficRouter`.**
Легаси-роутер УЖЕ содержит буквально этот же паттерн первым (сразу после
`admin_api`, до вообще всех остальных маршрутов, включая клик-роутинг):
`["pattern" => "/\/([a-z0-9\-_]+)\/postback/i", "context" =>
"Traffic\Context\PostbackContext", "param" => PARAM_KEY]` —
`PostbackDispatcher::_findKey()` читает именно этот `PARAM_KEY` первым
делом. Живьём подтверждено против легаси (порт 8090):
`GET /6a05078/postback?subid=x` реально доходит до `PostbackDispatcher`
(тело `"Incorrect postback code (6a05078 in 1)"` — `1` тут отдельный
легаси-баг, `(int) $request->getUri()` кастует URI-объект в int, не
реальный путь, буквально бесполезное число, не стал воспроизводить
дословно — это диагностическое сообщение, не часть контракта), а не
generic 404. Значит `/{key}/postback` — РЕАЛЬНЫЙ, уже существующий
легаси-механизм, а не "фича без прецедента по просьбе пользователя", как
утверждалось. Функционально ничего не сломано (наша реализация уже
корректно матчит и валидирует ключ), но докблоки в
`PostbackAuthService::findPathKey()`/`public/postback.php` переписаны на
точную формулировку находки, плюс regex сужен с `[^/]+` до
легаси-точного `[a-zA-Z0-9_-]+` (легаси: `[a-z0-9\-_]+` + `/i`-флаг,
покрывающий регистр). `php -l` чисто, живая регрессия (правильный
ключ → 200 "SubID not found" для чужого sub_id; ключ с недопустимым
символом `.` → путь не матчится, 403 "Incorrect postback code") на обоих
файлах после правки, фикстурная `settings.postback_key` строка удалена.

---

## DomainsTest — 3 падения закрыты, важный урок про докблоки — 2026-09-03

1. `domains.create` отдавал один объект вместо массива из 1 —
   live-подтверждено против легаси, что `createMultiple()` всегда
   отдаёт массив. Обёрнуто в `[...]`.
2. `domains.show` без id/с несуществующим id/для archived — прошлая
   сессия "улучшила" это до 404 с докблоком "легаси-баг, не стоит
   сохранять, реальный клиент не пострадает". Перепроверил живьём
   ПРЯМО против легаси (порт 8090, curl) ДО правки — легаси
   действительно отдаёт 200 с квирк-телом (нет id/name, но есть
   campaigns_count/default_campaign/error_solution). Откатил к
   буквальному легаси-поведению.

**Урок на будущее**: докблок "это баг легаси, не стоит реплицировать"
без реальной живой перепроверки — не основание менять поведение.
Контрактный тест (который в момент написания докблока физически не
мог запуститься против нового бэкенда — см. запись про сломанный
`ApiClient` выше) был единственным источником, который РЕАЛЬНО сверялся
с легаси. Всегда перепроверять такие "улучшения" curl'ом против
`tds-app` (порт 8090) перед тем как доверять им.

Verification: `tests-contract` DomainsTest — 6/6 против нового
бэкенда. Полный `backend/./vendor/bin/pest` — 384/384. `tests-contract`
в целом: 15 → 11 оставшихся падений (полный список с первичным
диагнозом — `docs/BACKEND_REMAINING_WORK.md` раздел 6, следующая
сессия).

---

## Все 11 оставшихся контрактных падений разобраны и закрыты — 2026-09-03

Продолжение предыдущей записи. Каждое сверено живым curl/тестом против
реального легаси (порт 8090) перед фиксом, как договорено. Полный
`tests-contract` (не считая `smoke`-группы) теперь **92/92 против ОБОИХ
таргетов** (новый бэкенд и легаси), `backend/./vendor/bin/pest` — 383/383.

1. **`ApiKeysTest` (2) — реальный баг, не мнимый.** `ApiKeysController`
   был переименован в `index/create/removeAction` на докблоке "то же
   отклонение, что у Users/Groups" — ЛОЖЬ, проверено чтением легаси:
   `Component\Users\Controller\UsersController`/`GroupsController` в
   легаси УЖЕ используют `index/create/show/update/delete` (без
   отклонения от порта), тогда как легаси `ApiKeysController` РЕАЛЬНО
   использует `getAll/add/delete` — другие имена. `ObjectDispatchController`
   не имеет alias-таблицы, маппинг строго 1:1
   (`object=apiKeys.add` → `addAction`), так что переименованные методы
   были физически недостижимы (404). Переименовано обратно в
   `getAllAction`/`addAction`/`deleteAction`, докблок исправлен, внутренний
   `tests/Feature/ApiKeysTest.php` обновлён (`apikeys.index/create/remove` →
   `apikeys.getAll/add/delete` — этот сьют молчал именно потому, что
   тестировал имена, которые сам порт придумал, а не легаси-контракт).
   Живая проверка формы `{id,key,datetime}` против легаси (порт 8090) —
   совпадает, единственное отличие — формат `datetime`
   (`03 Sep 2026 08:19` в легаси через i18n-слой vs
   `2026-09-03 08:19:00` в порте) — уже задокументированное, осознанное
   отклонение (нет i18n-слоя в этом порте).

2. **`GroupsTest` — дубликат имени группы реально не отклонялся, легаси
   ДЕЙСТВИТЕЛЬНО валидирует.** Легаси `Component\Groups\Validator\
   GroupValidator` содержит `"uniqueness" => [["name", Group::definition(),
   "type = {type}"]]` (Valitron-правило, `Bootstrap::initValidators()`,
   всегда исключает собственный id — т.е. работает и на update).
   Живьём подтверждено против порта 8090: повторное `groups.create` с тем
   же `name`+`type` → 406 `{"name":["This value has already used"]}`.
   Добавлена `GroupsController::nameTaken()` (name+type, исключая свой id
   на update) в `createAction`/`updateAction` нового бэкенда, с точным
   текстом легаси-сообщения об ошибке.

3. **`UserPreferencesTest` — реальный баг сериализации.**
   `UserPreferencesController::getAction()` оборачивал значение в
   `json_encode()` (кавычки вокруг строки, литерал `"null"` для
   отсутствующего pref) — легаси (`ObjectDispatchController`'s общая
   ветка "не массив/объект → `(string) $result`") отдаёт СЫРУЮ строку без
   кавычек и ПУСТОЕ тело (не `"null"`) для отсутствующего значения.
   Подтверждено живым curl против порта 8090. Исправлено: `getAction()`
   теперь возвращает голый скаляр (`string|null`), диспетчер сам
   приводит к `(string)`. Обновлены 2 теста в собственном
   `tests/Feature/UserPreferencesTest.php`, которые раньше ассертили
   JSON-обёртку (тестировали баг, а не контракт).

4. **`UsersTest` (3) — два независимых реальных бага.**
   - `new_password` требовался всегда на `users.create`, но легаси
     `UserService::createUser()` делает `new_password` полностью
     ОПЦИОНАЛЬНЫМ (`isset()`-guard) — обязательны только
     `login`/`type`/`password_hash` (легаси `UserValidator`), причём
     `password_hash` там — это СЫРОЙ, уже захешированный клиентом литерал,
     который легаси кладёт в столбец без сервер-side хеширования
     (подтверждено чтением `UserValidator`/`UserService::createUser()` +
     живым curl против порта 8090). Принятое решение: **не** портировать
     эту небезопасную легаси-семантику один-в-один (принимать сырой хеш от
     клиента — реальная уязвимость), оставить существующее
     security-осознанное отклонение порта (`new_password`
     required + `Hash::make()`) как есть. Вместо этого исправлена
     `tests-contract/tests/Support/Fixtures.php::createUser()` — теперь
     шлёт ОБА набора полей (`password_hash` И
     `new_password`/`new_password_confirmation`), что живьём подтверждено
     рабочим против ОБОИХ таргетов (легаси: `password_hash` закрывает
     required-валидацию, `new_password` необязательно перезаписывает хеш
     на нормальный; новый бэкенд: `password_hash` игнорируется,
     `new_password` используется).
   - `users.listAsOptions` — легаси `Component\Users\Controller\
     UsersController` НЕ имеет такого action вообще (только
     index/create/show/update/delete/setAccessData, подтверждено чтением
     исходника) — это была намеренная, но неавторизованная задачей
     добавка прошлой сессии ("added per task brief for parity", докблок
     был неточен — в задаче этого не было), уже отмеченная в этом же файле
     ("оставлено как есть, не удалялось ради экономии"). Раз контрактный
     тест теперь реально проверяет это и требует 404 — удалено из
     `UsersController` (метод + 2 внутренних Pest-теста заменены на один,
     ассертящий 404, как настоящий контракт). **Похожая, но НЕ идентичная
     добавка осталась нетронутой**: `GroupsController::showAction()` (тоже
     без легаси-аналога) — у неё пока нет проваливающегося контрактного
     теста, оставлена с обновлённым докблоком-предупреждением "не считать
     безопасной без реальной проверки" на будущее.

5. **`CampaignsTest`'s `smoke`-тест** — не баг, историческая фикстура
   легаси-БД (id=4 "qbrtcz2"), не относящаяся к свежей БД. Уже был помечен
   `->group('smoke')` в коде, но ничего не исключало эту группу из
   дефолтного прогона — добавлен `<groups><exclude><group>smoke</group>
   ...` в `tests-contract/phpunit.xml`, дефолтный `vendor/bin/pest` теперь
   зелёный на обоих таргетах без явного исключения флагом; тест по-прежнему
   доступен через `--group=smoke` для ручной сверки с легаси.

Verification: `backend/./vendor/bin/pest` — 383/383 (было 384: -2 старых
`listAsOptions`-теста Users, +1 новый "404 как в легаси"). `tests-contract`
(без smoke) — **92/92 на новом бэкенде, 92/92 на легаси (порт 8090)**.
`php -l` чисто на всех изменённых файлах. Фикстуры (легаси group id=22,
apiKey id=4, user id=13) удалены после живой сверки.

---

## ReportsController — 4 недостающих action'а + реальный GridBuilder-баг (только на MySQL) — 2026-09-03

Фоновый аудит всех ~43 контроллеров (см. параллельный отчёт) нашёл, что
`ReportsController` реализовывал только `build`/`definition` из 6
легаси action'ов. Портированы `summary`/`columnsAsOptions`/
`parameterAliases`/`statsForCampaign`:
- `summaryAction` — тот же `GridBuilder`-пайплайн, что `buildAction`, но
  только `result['summary']` (форсирует `params->summary = true`,
  легаси `ClickRepository::summary()` не имеет своего флага вообще).
- `columnsAsOptionsAction` — колонки `definitionAction()` вынесены в
  общий `columnDefinitions()`, отфильтрованы нескрытые, замаплены в
  `{category, name, value}` (без i18n — как везде в этом порте).
- `parameterAliasesAction`/`statsForCampaignAction` — прямой перенос
  `CampaignRepository::getParameterAliases()`/
  `ReportRepository::briefCampaignStats()` на Eloquent (`campaigns.
  parameters` — уже готовый JSON-столбец), 404/403 на несуществующую/
  недоступную кампанию по образцу `CampaignsController::showAction()`.

**Реальный баг, найденный при живой проверке summary (не мнимый, не мой
код — уже существовал в `App\Services\Grid\GridBuilder`, используемом
`reports.build` тоже):** вызов без явного `columns` (легаси-дефолт —
"взять все колонки грид-определения", `QueryParams.php:174-175`) мешает
сырые построчные колонки и агрегатные (`clicks` => `COUNT(click_id)`) в
одном SELECT без GROUP BY — невалидный SQL по стандарту, реальный MySQL
(`ONLY_FULL_GROUP_BY`, дефолтный режим MySQL 8) 500-ит: "Expression #1 of
SELECT list is not in GROUP BY clause...". SQLite-Pest-сьют этого никогда
не ловил (SQLite такие смешанные SELECT'ы не проверяет). Подтверждено
живьём: `?object=reports.build` без `columns` на новом бэкенде (порт
8010, реальный MySQL) — 500; тот же запрос на легаси (порт 8090) —
200 с реальными данными.

**Разобрана причина расхождения — не "легаси особенный", а `Core\Db\
Db.php:143` делает `$this->_db->execute("SET sql_mode=''")` НА КАЖДОМ
подключении, безусловно отключая ONLY_FULL_GROUP_BY (и вообще все
strict-режимы) для всего легаси-проекта разом.** Осознанно НЕ
воспроизведено — это не полезная фича, а footgun: пустой sql_mode тихо
возвращает произвольное (не детерминированное) значение для НЕагрегатных
колонок в любом смешанном запросе где угодно в приложении, не только в
этом одном report-эндпоинте. Вместо этого — `GridBuilder::build()`:
когда `columns` не передан И `grouping` пуст, дефолтный набор колонок
исключает агрегатные (SUM(/COUNT() выражения — они всё равно
бессмысленны построчно без GROUP BY, попутно чинит и уже
существовавший `reports.build` для этого редкого (фронтенд всегда шлёт
явный `columns`, поэтому ни разу не всплывало) случая. Также нашёл и
починил тем же заходом: `buildSummary()` при пустом наборе агрегатных
колонок раньше тихо проваливался в Laravel-дефолт `SELECT *` и отдавал
одну случайную сырую строку вместо summary — теперь отдаёт `[]`.

Verification: `backend/./vendor/bin/pest` — 383/383 (было 384 до сессии,
-1 за счёт двух `listAsOptions`-тестов Users в предыдущей записи, здесь
без изменений). `tests-contract` — 92/92 на новом бэкенде И на легаси
(не задело ни одного, Reports вне контрактного покрытия). Живая проверка
на реальном MySQL (`tds2-mysql`): `reports.build` без columns — 200 с
реальными строками; `reports.summary` — реальные агрегаты (`clicks:130`
и т.д.), не сырая строка; `reports.statsForCampaign` — реальная кампания
даёт `{"null":{...}}` (нет кликов под группировкой), несуществующая/
отсутствующая — 404; `reports.parameterAliases` — реальный alias с
префиксом `[S1]`/`[X2]` подтверждён на живой фикстуре (`campaigns.
parameters` JSON), очищено (`UPDATE ... SET parameters=NULL`). `php -l`
чисто на всех изменённых файлах.

---

## Proxy-детекция (`_checkIfProxy`) — 2026-09-03

По прямому запросу пользователя ("доделай бот-детекцию") перепроверил
`_checkIfProxy()` — легаси-докблок в traffic-core утверждал "нет
ProxyService-рантайма", но реальный `Traffic\Device\Service\
ProxyService::usingProxy()` — ЧИСТАЯ проверка HTTP-заголовков
(X-Forwarded-For/X-Real-IP/Forwarded/CF-*), без единого внешнего вызова
или платных данных. GeoDb-половина (`IpInfoType::PROXY_TYPE`) — да,
недостижима (тот же платный IP2Location PX-тир, что и `BOT_TYPE` для
ботов), но заголовочная половина была полностью портируемой и просто не
была замечена/начата раньше.

**Реальный баг легаси, найденный при чтении, не воспроизведён буквально**:
`usingProxy()` содержит дублирующее, логически недостижимое вложенное
условие (`if (isBehindCloudFlare && isXffContainsCfcip) { if
(isBehindCloudFlare && !isXffContainsCfcip) {...} return false; }` —
внутренний `if` требует `!isXffContainsCfcip`, но внешний уже доказал
`isXffContainsCfcip === true`, так что внутренняя ветка математически
недостижима ни при каких входных данных). Порт (`TrafficCore\Pipeline\
Proxy\ProxyDetectionResolver`) реализует упрощённую, поведенчески
идентичную версию без мёртвой ветки (проверено полным перебором обеих
веток буля, не догадкой).

Механизм: `bindClient` за CloudFlare (есть `CF-IPCountry`/
`CF-Connecting-IP`/`CF-Visitor`) И `CF-Connecting-IP` реально входит в
`X-Forwarded-For` -> НЕ прокси (это легитимный CDN-проброс, не
пользовательский прокси). Иначе — 2+ различных IP в `X-Forwarded-For` ->
прокси.

Подключено той же схемой, что уже была для `is_bot`: `Payload::
$isUsingProxy` резолвится в `ResolveVisitorStage` (до `ChooseStreamStage`,
чтобы `proxy`-фильтр на `forced`-потоке успел его увидеть) через новый
`ProxyDetectionResolver::resolve($payload->request)`. `FilterEngine::
evaluate()` — новый `proxy`-кейс (`$isUsingProxy` — новый trailing-
параметр, тем же способом, что `$isBot`), `CheckFilters::isPass()`/
`StreamRotator`/`ChooseStreamStage` прокидывают его насквозь.
`BuildRawClickStage` пишет реальное `clicks.is_using_proxy` (было
всегда 0 — дефолт колонки). `ClickMacroValues`'s `is_using_proxy`-макрос
теперь реальный (был хардкод `'0'`, тот же паттерн, что раньше нашли и
починили для `is_bot`).

Verification (живой, Docker `tds2-mysql`/`tds2-redis`, traffic-core
`php -S`+`router.php`, ручной прогон `bin/process_click_queue.php`
кратким запуском+`pkill` — воркер-контейнер не поднят по умолчанию, а
`StoreRawClickStage` пишет клики асинхронно через Redis-очередь, без
воркера строки в `clicks` не появляются): кампания + `forced`-поток
(фильтр `proxy`/`accept`, action `status404`). Обычный запрос (без
XFF) -> 200, `is_using_proxy=0`. `X-Forwarded-For: 1.2.3.4, 5.6.7.8` (2
разных IP) -> 404 (роутится на forced-поток), `is_using_proxy=1`.
`X-Forwarded-For: 1.2.3.4, 1.2.3.4` (один IP дважды) -> 200,
`is_using_proxy=0` (не прокси). `CF-Connecting-IP: 9.9.9.9` +
`X-Forwarded-For: 9.9.9.9` (легитимный CloudFlare-проброс) -> 200,
`is_using_proxy=0` (правильно НЕ помечен как прокси). Побочно
подтверждено: curl'ов дефолтный User-Agent (`curl/...`) реально ловится
как бот (`is_bot=1`) существующей бот-детекцией — не регрессия, реальный
UA-сигнатурный список так и должен работать; с настоящим браузерным UA
— `is_bot=0`. Все фикстуры (campaign/stream/stream_filters/clicks)
удалены после проверки. `php -l` чисто на всех 9 изменённых/новых
файлов.

Не в скоупе (осознанно, не по забывчивости): GeoDb-половина
`_checkIfProxy()` (`PROXY_TYPE`, платный тир) и geo/device/uniqueness/
imklo/hide_click фильтры (тот же прецедент, что раньше — не запрошены
явно в этот раз).

---

## Labels — контрактный тест + 3 реальных бага — 2026-09-03

Первый модуль из "доделай все контрактные тесты" (Labels выбран первым
как самый маленький). Живая сверка с легаси (порт 8090) при написании
теста нашла 3 реальных бага:
1. `refNameVariations`/`validRefNames()` — `sub_id_N` был захардкожен до
   10, легаси реально отдаёт 15 (этот порт's `clicks` таблица уже имеет
   все 15 `sub_id_N_id`-колонок — тот же схемо-зависимый прецедент, что
   легаси само использует через `hasSubId15()`).
2. `indexAction()` для пустого результата отдавал текст `"null"` (4
   байта) вместо буквально ПУСТОГО тела (0 байт) — тот же класс бага,
   что уже чинили для `userPreferences.get`.
3. `campaign_id`, не резолвящийся в реальную кампанию, отдавал 403
   вместо 404 — легаси `CampaignRepository::find()` реально кидает
   `NotFoundError` (подтверждено живым curl, `campaign_id=0` →
   `"Traffic\Model\Campaign #0 not found"`, 404), а не проваливается в
   ACL-проверку с `null`.

**Осознанно НЕ воспроизведено (проверено живьём, не предположено)**:
`labels.update`/`labels.replaceList`'s `items`/`ref_values` ключи.
Реальный легаси трактует ключ как СЫРОЕ числовое `value` дикт-таблицы
(`ip2long()`/`(int)`-каст) и резолвит настоящий `ref_id` через `WHERE
value = ...` — доменная строка типа `"example.com"` как ключ у легаси
молча резолвится в `(int)"example.com" === 0` и не матчит НИЧЕГО (живой
тест подтвердил: label не создаётся вообще). Контракт этого порта
(`ref_value` как есть, без дикт-джойна) — уже задокументированное,
подтверждённое сейчас живьём как реально более безопасное решение, не
компромисс схемы для сокрытия. Аналогично `ref_name`-валидация: легаси
500-ит на неизвестном `ref_name` (необработанный `Error` в
`getRefDefinition()`), этот порт отдаёт чистый 406 — осознанное
улучшение, не баг.

Новый `tests-contract/tests/LabelsTest.php` (4 теста) — 4/4 на ОБОИХ
таргетах. Внутренний `backend/tests/Feature/LabelsTest.php` обновлён (3
теста поправлены под реальный контракт). Полный `backend/./vendor/bin/pest`
— 389/389. `php -l` чисто.

---

## GeoProfiles — контрактный тест + 3 реальных бага (200-вместо-404) — 2026-09-03

Второй модуль. Внутреннего Pest-теста для этого контроллера не было
вообще (фоновый аудит-агент проверял только имена action'ов живым curl,
без регрессионного теста). Живая сверка нашла 3 бага одного семейства —
докблок утверждал "легаси отдаёт `serialize(null,...)` → буквальный
JSON `null` (200)" для несуществующего id, но `GeoProfile::find($id)`
(легаси `Core\Model\AbstractModel::find()`, статический find, как у
каждой модели в этом проекте) реально КИДАЕТ `NotFoundError` →
настоящий 404 с трейсом (подтверждено живым curl против порта 8090):
- `showAction` — отдавал 200/`null`, теперь 404.
- `deleteAction` — использовал query-builder `->delete()` (тихо не
  находит строк для несуществующего id, не ошибка) вместо
  `GeoProfile::find()`, теперь тоже 404.
- `updateAction` уже был правильным (404), но без `stacktrace`-поля —
  приведён к общему `{error, stacktrace}`-виду.

Побочно подтверждено (не баг, для контрактного теста): `countries[]=X`
form-urlencoded тело НЕ парсится легаси в массив (`create` тихо
сохраняет `countries: null`) — только JSON-тело работает, что как раз
дефолт `ApiClient::post()`. Ключевой порядок полей в JSON-ответах
`create` vs `show` у легаси РАЗНЫЙ (баг легаси не воспроизведён/не
важен — сравнение в тесте через `ksort()`, не через порядок).

Новые `backend/tests/Feature/GeoProfilesTest.php` (8 тестов, этого
файла раньше не было) + `tests-contract/tests/GeoProfilesTest.php` (4
теста) — зелёные на ОБОИХ таргетах. Полный `backend/./vendor/bin/pest`
— 397/397. `php -l` чисто.

---

## GeoDb контрактный тест — НЕЗАВЕРШЁНО, разведка на середине (2026-09-03)

Сессия прервана пользователем на этом месте ("сделай пока /clear") —
работа над `geoDbs`-контрактным тестом реально НАЧАТА (третий модуль
после Labels/GeoProfiles), но НЕ доведена до теста/фикса/коммита. Ниже —
всё, что уже успели найти живым curl, чтобы не передоказывать заново.

**`geoDbs.index` — живое сравнение легаси (порт 8090) vs новый бэкенд
(порт 8010)**:
- Легаси: `{"id","name","type","exists","path","data_types",
  "status_code","status_text","time","is_recommended","setting_key",
  "purchase_link","key","error"}` — ПОСЛЕДНЕЕ поле `error` (например
  `"[ip2location_lite] Error while request db, status: 404"` — это
  реальная попытка живого сетевого запроса на сайт провайдера для
  проверки обновлений, которая тут падает 404, т.к. окружение не имеет
  доступа/лицензии).
- Новый бэкенд: те же поля КРОМЕ `error`, но есть лишние `installed`
  (bool) и `update_available` (null) вместо `error`. `exists=false` у
  ip2location_lite несмотря на реально существующий на диске файл — НЕ
  проверено, баг это или нет (не успели разобраться, файл `var/geoip/
  IP2Location/lite/IP2LOCATION-LITE-DB3.BIN` в момент последнего теста
  был на месте, 48MB, только что восстановлен второй раз в этой сессии
  — см. ниже про "ловушку с файлом").
- **НЕ выяснено**: (а) реальный ли это баг `exists`/`installed` или
  ожидаемое расхождение (сетевой check недостижим в обеих средах,
  просто по-разному сериализован); (б) откуда взялись
  `installed`/`update_available` — новая, недокументированная добавка
  или замена `error`; (в) что должно быть в `time` (null у нас, реальный
  filemtime раньше подтверждался работающим по докам раздела 3.1
  BACKEND_REMAINING_WORK.md — возможно, `exists=false` — причина, из-за
  которой `time` тоже null, замкнутый круг, нужно сначала понять exists).

**`geoDbs.settings`** — оба таргета вернули ИДЕНТИЧНЫЙ JSON
(`{"0":null,...,"8":null,"country":"ip2location_lite",...}"`) — этот
кусок, похоже, уже рабочий 1:1, не нужно чинить, только written contract
test.

**ВАЖНАЯ ЛОВУШКА (для следующей сессии, чуть не наступили дважды)**:
`backend/var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN` — реальный
48MB geoip-файл, НЕ в git (untracked, `var/` в .gitignore), скопирован
вручную кем-то до этой сессии из легаси-репо. Живой ручной upload-тест
`geoDbs.upload` (раздел 3.1 BACKEND_REMAINING_WORK.md) перезаписывает
ЭТОТ ЖЕ путь тестовым мусором — если тестируешь `geoDbs.upload` живым
curl (не через Pest `afterEach`, который сам чистит), **обязательно
восстанови файл после**: `cp "./var/geoip/IP2Location/lite/
IP2LOCATION-LITE-DB3.BIN" "/Users/mykhailomishyn/Documents/trafox/
tds_v2/backend/var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN"`
(легаси-репо — эталонная нетронутая копия, всегда доступна по
относительному пути от дефолтного cwd). В этой сессии файл дважды
оказывался пустой директорией без него (возможно, что-то в
Pest-сьюте/фикстурах его трогает — НЕ выяснено, что именно, стоит
поискать `afterEach`/`tearDown` в `GeoDbsTest.php`, который может
удалять файл по общему пути раньше, чем ожидается, или отдельный
скрипт/тест, запущенный в этой сессии, задел его).

Следующие шаги: (1) разобраться с ловушкой файла — найти, что его
удаляет; (2) разобраться с `exists`/`installed`/`error` vs
`update_available` расхождением в `geoDbs.index` — почитать
`GeoDbSerializer`/`GeoDbRepository::all()` в легаси и сравнить с портом
дословно, не гадая; (3) дописать `backend/tests/Feature/GeoDbsTest.php`
(тесты там уже есть, 18 штук — просто перепроверить, не пропущено ли
что-то) + `tests-contract/tests/GeoDbsTest.php` (ещё не создан); (4)
после GeoDb — Conversions, Reports, Editor, Cleaner,
ThirdPartyIntegration-кластер, CodePresets, KClientJsPreset, Macros,
Branding, IpInfoDataTypes — по списку из BACKEND_REMAINING_WORK.md
раздела 6.

---
*Обновляется по ходу переноса — дописывать сюда, не заводить новый файл.
Завершённая история (traffic-core Фазы 1-17) — в `docs/PORTING_LOG_ARCHIVE.md`,
туда же архивировать записи старше ~2-3 недель/сессий, когда этот файл
снова разрастётся. Формат записи — см. `tds_v2/CLAUDE.md` ("Ведение
PORTING_LOG.md").*
