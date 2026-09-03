# Backend (Laravel admin) — что осталось, кроме frontend/

Составлено 2026-09-02 по итогам полного аудита `backend/` двумя фоновыми
агентами (один — "что не сделано", другой — "что сделано иначе, чем в
legacy"), после того как `traffic-core/` был закрыт полностью (Phase 17,
см. `docs/TRAFFIC_CORE_PLAN.md`/`docs/PORTING_LOG.md`).

**traffic-core/ и frontend/ здесь не рассматриваются** — первый готов
полностью, второй осознанно отложен отдельно и не входит в эту задачу.

Работать по тому же принципу, что весь проект до сих пор: живая
верификация через Docker (`docker compose` в `deploy/`, `tds2-mysql`/
`tds2-redis` уже подняты постоянно), фикстуры через прямой SQL и
удаление после теста, `php -l`/Pest перед коммитом, коммиты с
атрибуцией:
```
Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: <ссылка на текущую сессию>
```
Легаси-эталон — `/Users/mykhailomishyn/Downloads/tds_source-main — without KT`
(ВНИМАНИЕ: в имени папки скрытый неразрывный пробел перед "without" —
использовать относительные Bash-пути из этой директории, не перепечатывать
путь руками). При любых вопросах "как это делает легаси" — читать
реальный код там, не гадать.

---

## 1. Кросс-каттинг, узкие (быстро закрываются, высокий приоритет)

### 1.1 ACL не подключён к withStatsAction — ЗАКРЫТО, аудит был неверен (2026-09-03)
Первоначальный аудит ошибся: пять контроллеров (`CampaignsController`,
`OffersController`, `LandingsController`, `StreamsController`,
`TrafficSourcesController`) действительно несли устаревший докблок
"TODO: ACL not wired here yet", но реальная фильтрация УЖЕ реализована
и работает — каждый `withStatsAction` передаёт `user:
$this->currentUserService->get()` в `EntityGridBuilder`, а тот
применяет её в приватном `applyAcl()` (вызывается из `loadEntities()`,
см. `backend/app/Services/Grid/EntityGridBuilder.php:263,293-316`).
Живое покрытие уже существует: `backend/tests/Feature/GridAclTest.php`
(10 тестов, все проходят: admin видит всё, `to_groups_and_selected`
видит только разрешённое, отсутствие acl_rules = ALLOW_NONE = пустой
результат, `full_access` видит всё — для campaigns/streams/offers, плюс
`reports.build`/`conversions.log` тем же ACL-путём).

Сделано в эту сессию: только докблоки очищены (устаревший
"TODO: ACL not wired here yet" заменён на ссылку на реальную
реализацию + тест), кода не менялось. Полный `./vendor/bin/pest`
(350/350) и `php -l` на все 5 контроллеров — чисто.

### 1.2 Groups — реальные имена вместо null-стаба — ЗАКРЫТО (2026-09-03)

Заменены все null/хардкод-стабы на реальный lookup через `Group`-модель
(нет отдельного репозиторного метода в `GroupsController`, простой
`Group::find()`/batch `whereIn()->pluck('name','id')` для списков —
избегает N+1 на `listAsOptionsAction`):
- `CampaignsController.php` — `serializeCampaign()` (extended) +
  `listAsOptionsAction()`. Легаси `CampaignSerializer` — единственный из
  трёх с "empty(group_id) -> group_id=0 + group='Default'" фоллбэком
  (переведённая строка "groups.default" в легаси, хардкод "Default"
  здесь — тот же прецедент, что везде без i18n).
- `OffersController.php` — `serializeOffer()`'s `withGroupName` ветка +
  `listAsOptionsAction()`. Легаси `OfferRepository`/`Builder::build()` —
  **другой контракт**: простой LEFT JOIN, `group_id=0`/удалённая группа
  -> `group=null`, БЕЗ "Default"-фоллбэка (проверено чтением обоих
  реальных источников, не предположено "как у Campaigns").
- `LandingsController.php` — `serializeLanding()`'s `withGroupName`
  ветка + `listAsOptionsAction()`. Тот же контракт, что у Offers (простой
  LEFT JOIN, null без фоллбэка).

Тесты: по 2-3 новых в `CampaignsTest.php`/`OffersTest.php`/
`LandingsTest.php` (реальное имя группы, ungrouped -> `Default`/`null`
по контракту каждого контроллера). Живая проверка на реальном MySQL
(`tds2-mysql`) через `php artisan tinker` — фикстуры удалены. Полный
`./vendor/bin/pest` — 373/373, `php -l` чисто.

### 1.3 ReportsController не использует уже готовые в traffic-core словари — ЗАКРЫТО (2026-09-03)
`backend/app/Http/Controllers/Admin/ReportsController.php` исключал
geo/device/isp-измерения. **Аудит был отчасти неверен**: не было (не "не
расширен", а вообще не существовало) никакого готового join-паттерна на
`ref_sources`/`ref_referrers`/`ref_keywords` в `GridBuilder` до этой
сессии — проверено `grep`, `App\Services\Grid\GridBuilder` был честно
single-table-only (см. его собственный докблок до правки).

Сделано: `App\Services\Grid\GridBuilder` получил опциональный
конструкторный параметр `$joins` (LEFT JOIN-ы, применяются в
`baseQuery()` ко всем трём запросам — select/total/summary), не ломает
существующего вызова из `ConversionsController` (параметр по умолчанию
`[]`). `ReportsController::GEO_DEVICE_JOINS` — 13 LEFT JOIN-ов
(`clicks.visitor_id -> visitors -> ref_countries/ref_regions/ref_cities/
ref_browsers/ref_browser_versions/ref_os/ref_os_versions/
ref_device_types/ref_device_models/ref_isp/ref_operators/
ref_connection_types`), 12 новых измерений в
`BUILD_COLUMNS_BASE`/`definitionAction()` (`country`/`region`/`city`/
`browser`/`browser_version`/`os`/`os_version`/`device_type`/
`device_model`/`isp`/`operator`/`connection_type`).

НЕ включено в этот раунд (вне скоупа задачи, не забыто):
referrer/search_engine/keyword/source/ad_campaign_id/external_id/
creative_id/x_requested_with/destination NAME-колонки,
`language`/`ip`/`user_agent` (последние два требуют MySQL-only
`INET_NTOA`, недоступной под SQLite, на котором крутится Pest-сьют —
тот же принцип, что уже был у calendar-колонок).

Тесты: `tests/Feature/ReportsTest.php` — 3 новых теста (реальный джойн
возвращает имя, LEFT JOIN не роняет клик без визитора, group+filter по
`country`). Живая проверка на реальном MySQL (Docker `tds2-mysql`, не
только SQLite) через `php artisan tinker` — джойн `clicks -> visitors ->
ref_countries` подтверждён на реальных данных, фикстуры удалены. Полный
`./vendor/bin/pest` — 353/353 зелёный, `php -l` чисто.

---

## 2. Console/Cron/DelayedCommands/PruneTask — ЧАСТИЧНО ЗАКРЫТО (2026-09-03)

Полный триаж легаси: 18 конкретных `CronTaskInterface`-задач (`grep -rl
"implements.*CronTaskInterface"`) + 9 конкретных `PruneTaskInterface`-задач
+ 7 `BaseArchivePruneTask`-наследников (`ARCHIVE_TYPE`, по одному на
campaigns/streams/offers/landings/traffic_sources/affiliate_networks/
domains) — итого ~26 задач, полный список с обоснованием "порт/скип" в
`docs/PORTING_LOG.md`. Портированы 4 команды в
`backend/app/Console/Commands/` + `Schedule::command()` в
`backend/routes/console.php`:

- **`app:prune-archived-entities`** (`->daily()`) — hard-delete
  `state='deleted'` строк старше `archive_ttl` дней, по всем 7
  ARCHIVE_TYPE-сущностям (порт `Pruner`/`BaseArchivePruneTask`).
- **`app:prune-click-stats`** (`->daily()`) — диспатчит уже готовый
  `DeleteStatsJob` с `endDate = now()-stats_ttl дней` (порт
  `Clicks\CronTask\PruneClicks`).
- **`app:prune-orphaned-data`** (`->daily()->at('03:30')`) — visitors без
  кликов / conversions без кликов / click_links без кликов (порт
  `CleanerService::pruneVisitors/pruneConversions/pruneClickLinks`).
- **`app:prune-expired-password-hashes`** (`->daily()`) — просроченные
  `user_password_hashes` (порт `Users\PruneTask\PruneUserPasswordHash`).

**Ещё 2 команды дозакрыты (2026-09-03, "добей хвосты" раунд)** — обе
раньше числились заблокированными на непортированной инфре, живьём
перепроверено, что это устарело:
- **`app:prune-stream-events`** (`->daily()`) — `DELETE FROM
  monitoring_history WHERE date < now()-30 дней` (порт
  `Streams\PruneTask\PruneStreamEvents`/`StreamEventService::prune()`,
  дословно по легаси-исходнику). Таблица `monitoring_history` давно
  реальна (StreamEvents-модуль портирован ещё в начале проекта) —
  зависимости никогда не было, просто команду не написали.
- **`app:prune-hit-limits`** (`->daily()`) — `ZREMRANGEBYSCORE` на
  Redis-сетах `rate:<stream_id>` старше 1 дня, с исключением для
  стримов, у которых `limit`-фильтр имеет `total`-кап (порт
  `StreamFilters\PruneTask\PruneHitLimits`/`RedisStorage::prune()`).
  Механизм `rate:<stream_id>` реален с traffic-core Phase 11
  (hit-limit/cost/payout) — просто не было пруnера.

  **Побочная находка при реализации**: `backend/`'s `REDIS_CLIENT=phpredis`
  был прописан, но PHP-расширение phpredis физически не установлено ни
  в одном Docker-образе проекта (`deploy/Dockerfile.dev-php`) — баг был
  тихим, потому что Redis до этого вообще не использовался в backend/
  (очередь/кэш/сессии — всё на `database`). Исправлено переключением на
  `predis/predis` (тот же, уже провалидированный пакет, что и
  traffic-core использует по той же причине — повторная security-проверка
  не нужна, тот же пакет/версия). Добавлено новое Redis-подключение
  `traffic` (`config/database.php`) без Laravel-префикса — специально
  для чтения/записи в тот же keyspace, что traffic-core использует
  напрямую (`default`/`cache`-подключения имеют свой префикс и никогда
  не видят реальные ключи traffic-core).

Оба живьём проверены на реальных `tds2-mysql`/`tds2-redis` (фикстуры
удалены). `tests/Feature/PruneCommandsTest.php` — +1 тест на
`prune-stream-events` (чистый SQL, безопасен на SQLite);
`prune-hit-limits` осознанно без автотеста — требует реального Redis, а
Pest-сьют специально изолирован на SQLite без внешних сервисов. Полный
`./vendor/bin/pest` — 402/402.

**ИСПРАВЛЕНИЕ (2026-09-03, стресс-проверка):** утверждение "no-op по
умолчанию на чистой установке" выше — УСТАРЕЛО/НЕВЕРНО, написано до того
как `SettingsSeeder` был добавлен в этой же сессии позже (см. запись
"Разбор оставшихся контрактных падений" в PORTING_LOG.md). Реальный
легаси fresh-install (`application/data/data.sql`) сеет `archive_ttl=60`
И `stats_ttl=256` — НЕ пусто/0 — то есть pruning в легаси АКТИВЕН по
умолчанию, а не выключен (`Pruner::isCleanDisabled()`/`PruneClicks::run()`
трактуют только `empty()`/`== 0` как "выключено", легаси-дефолт — ни то,
ни другое). `SettingsSeeder` сеет те же значения — значит поведение порта
УЖЕ корректно совпадает с легаси (pruning активен из коробки), просто эта
строка документации не была обновлена после добавления сидера. Живьём
подтверждено: `app:prune-click-stats` на текущей dev-БД реально
диспатчит `DeleteStatsJob` с cutoff `-256 дней`, `app:prune-orphaned-data`
реально удалил осиротевшего visitor'а — оба ожидаемо активны, не no-op.
Тесты: `tests/Feature/PruneCommandsTest.php` (6 тестов). Живая проверка
на реальном MySQL (Docker `tds2-mysql`) через `php artisan tinker` +
`php artisan queue:work --once --stop-when-empty` (для `prune-click-stats`,
т.к. `QUEUE_CONNECTION=database` в реальном `.env`, не `sync` как в
тестах) — все 4 подтверждены, фикстуры удалены. Полный
`./vendor/bin/pest` — 367/367, `php -l` чисто.

**Найдено и НЕ портировано (реальная находка, не пропуск)**: легаси
`Triggers\CronTask\DeleteOldTriggers` (удаление triggers-записей с
несуществующим `stream_id`) — в этом проекте `triggers.stream_id` имеет
РЕАЛЬНЫЙ `->constrained()->cascadeOnDelete()` (в отличие от легаси-схемы),
поэтому осиротевший trigger структурно невозможен — команда была бы
гарантированным no-op, не портировал специально (доказано живым тестом,
FK не даёт создать такую строку даже в фикстуре).

**Осознанно НЕ портировано в этом раунде** (см. полный список в
`docs/PORTING_LOG.md`, кратко):
- `SyncCostsWithFacebook`/`SyncConversionAppsFlyer` — реальные вызовы
  внешних API (Facebook/AppsFlyer), отдельная задача с credentials, не
  "довести cron".
- `RunTriggersTask`/`CheckDomains`/`EnableSSLTask`/`UpdateTemplatesTask` —
  зависят от непортированной инфры (AV-checker, DomainChecker, certbot,
  template-downloader) — либо раздел 5 (прод-деплой), либо отдельная
  задача.
- `WarmupCacheTask`/`FlushOldCacheTask`/`PruneMysqlSessions`/`CheckTsTask` —
  не применимы к этой архитектуре (легаси-кэш-namespace'ы, MySQL-сессии
  вместо Redis TTL, пустой no-op).
- `PruneDailyCap` — реально зависит от непортированного
  ConversionCapacity-модуля (дневные капы на конверсии), которого в
  проекте физически нет — не низкий приоритет, а архитектурная задача.
- `PruneUserBotDBCA` — реально зависит от DBCA bot-signature бинарников
  (`UserBotDBCARepository`), которых в проекте физически нет — бот
-детекция в этом порте использует хардкод-список + кастомные сигнатуры
  (`BotDetectionService`), не DBCA-файлы.
- `PruneLandingOfferCache` — файловый lp-offer кэш-слой не существует в
  архитектуре этого проекта вообще (traffic-core использует
  DB/Redis-подходы вместо файлового кэша) — структурно нечего чистить,
  не пропуск.
- `RefresherTask` (принудительный HTTPS через 31 день) — низкий
  приоритет, завязан на прод-деплой-специфичный "первый запуск"
  трекинг (раздел 5, только по явному запросу).
- `pruneReferences()` (ref_*-словари) — зависит от непортированного
  `ClicksDefinition::getRelations()` (см. `ConversionsController::
  updateCostDefinitionAction`'s докблок, та же причина).
- `PruneStreamEvents`/`PruneHitLimits` — **ЗАКРЫТО**, см. выше.

---

## 3. Явные 501-стабы (уже видны в коде, конкретная задача)

### 3.1 GeoDb — скачивание/обновление баз — ЧАСТИЧНО ЗАКРЫТО (2026-09-03)
`backend/app/Http/Controllers/Admin/GeoDbsController.php::updateAction` —
был явный 501 без условий вообще. Реализовано: новый `uploadAction`
(`?object=geoDbs.upload`, реальный multipart-загрузчик файла на путь из
`DB_TYPES[].path`, тот же `isAdmin()`-гейт, что `updateAction`) — админ,
у которого уже есть купленный/скачанный файл базы, реально ставит его на
диск; `serializeDbType()`'s `time` теперь настоящий `filemtime()`
(верифицировано против легаси `DownloadManager::timestamp()` +
`Core\Model\AbstractModel::DATETIME_FORMAT`), а не всегда `null`.

**НЕ сделано, осознанно**: `updateAction` (`?object=geoDbs.update`)
по-прежнему 501 для реального сценария "скачать саму версию с сайта
провайдера" (tds.io/maxmind/ip2location/sypex/proip) — это требует
настоящих оплаченных лицензионных ключей у каждого провайдера, которых у
этой сессии нет, и живого сетевого запроса наружу (вне Docker-окружения
проекта). Не додумано и не сделано наугад — тот же принцип "verify,
don't just please".

Тесты: `tests/Feature/GeoDbsTest.php` — 5 новых (upload реально ставит
файл, `exists`/`installed`/`time` реально флипаются на настоящий
`filemtime()`, 403 не-админу, 422 неизвестный id/internal-тип без path,
422 без файла). Фикстурный файл каждый раз удаляется в `afterEach`
(путь совпадает с тем, что traffic-core's `GeoDbResolver` читает в
рантайме — важно не оставить мусор там). Полный `./vendor/bin/pest` —
358/358, `php -l` чисто.

### 3.2 Conversions — import и updateCostDefinition

**`importAction` — ЗАКРЫТО (2026-09-03).** Реализован
`App\Services\ConversionImportService` — порт легаси
`ConversionsService::processEntries()`/`import()`/`importArray()`, с той
же семантикой find-or-update-by-sub_id + синк click-тоталов, что
`traffic-core/src/Postback/PostbackProcessor.php` использует для живых
постбеков (backend/ и traffic-core/ — раздельные Composer-проекты, общий
код невозможен, поэтому логика продублирована нативно на Eloquent, не
вызовом в другой проект). Осознанно НЕ портирована конвертация валют
(`CurrencyService::exchange()` бьёт во внешний exchange-rate API,
инфраструктуры для этого нет нигде в проекте — тот же прецедент, что
`TrafficCore\Postback\Postback` уже задокументировал для живых
постбеков); `currency`-параметр остаётся обязательным (406-валидация),
но не влияет на сохранённый revenue. Тесты: `tests/Feature/
ConversionsTest.php` (4 новых: успешный импорт с дефолтным статусом
sale/синком click-тоталов, распознанные/нераспознанные статус-варианты,
sub_id не найден — с префиксом в error, "мусорная" строка без запятой
молча дропается и не считается в total — литерально по легаси). Живая
проверка на реальном MySQL (Docker `tds2-mysql`, не только SQLite) через
`php artisan tinker` — фикстуры удалены. Полный `./vendor/bin/pest` —
361/361, `php -l` чисто.

**`updateCostDefinitionAction` — ЗАКРЫТО (2026-09-03), премиса "нужна
полноценная grid-entity" была неверна.** Перепроверено чтением реального
легаси: `updateCostDefinitionAction()` — это `new ClicksDefinition()->
getGridDefinition()` и НИЧЕГО больше. В отличие от `reports.build`/
`conversions.log`, он НЕ выполняет никакого запроса — чистая метадата
(`{url, details, range_intervals, columns}`), подтверждено байт-в-байт
живым curl против порта 8090 (`url: null, details: null,
range_intervals: []`). Значит никакой рабочей query-инфраструктуры для
Clicks не требовалось — только список колонок, тем же стилем, что уже
использует `logDefinitionAction()`/`ReportsController::definitionAction()`
(name/type/category/filter/groupable/sortable/hidden/metric/summary, без
`inner_select`/`title`/decораторов — уже устоявшееся упрощение везде в
порте). Портированы 77 из 104 легаси-колонок — исключены только
`<x>_id -> <x>` разыменованные name-колонки (campaign/offer/landing/...),
для которых в этом порте нигде нет join'а (тот же, уже принятый
прецедент, что `ReportsController::BUILD_COLUMNS_BASE`). Живая сверка
имён колонок против легаси (`python3` diff по обоим JSON) подтвердила
покрытие. Тест обновлён (был "returns 501", теперь проверяет реальную
форму). Полный `./vendor/bin/pest` — 387/387, `php -l` чисто.

`log`/`logDefinition`/`statuses`/`import`/`updateCostDefinition` — все 6
легаси action'ов этого контроллера теперь реальны и работают.

---

## 4. Preview-изображения, trial-режим, i18n

**Решение пользователя (2026-09-03): в этом раунде делать только
preview. Trial-режим не портировать вообще (не осознанное отложение
"навсегда", а просто не в скоупе сейчас). i18n — вынесено в отдельную
задачу "на доработку", начинать не раньше отдельного запроса.**

- **Preview-изображения лендингов/офферов — ЗАКРЫТО (2026-09-03), обе
  половины.** Уточнение у пользователя (`AskUserQuestion`) вскрыло, что
  "preview" в легаси-доке (`docs/default/TODO_IMPROVEMENTS.md`) — это
  ДВЕ разные, никогда не реализованные в легаси фичи, обе сделаны:

  1. **Кнопка Preview в админке** (открыть local_file лендинг/оффер
     напрямую в браузере, в обход подбора кампании/стрима). В легаси
     это была только ИДЕЯ (`docs/default/TODO_IMPROVEMENTS.md`,
     "[НЕ СДЕЛАНО] Превью оффера/лендинга прямо из админки"), никогда не
     реализованная. Новый `traffic-core/public/preview.php` (новый
     entry point, переиспользует существующий `TrafficCore\Pipeline\
     Actions\LocalFile`-хендлер без изменений) + `previewAction()` в
     `LandingsController`/`OffersController` (backend), связаны
     HMAC-подписанной короткоживущей ссылкой (`App\Services\
     PreviewUrlBuilder`, новый env `PREVIEW_SECRET`/`TRAFFIC_CORE_URL` —
     тот же принцип, что `JWT_SALT`, т.к. `backend/`/`traffic-core/` —
     раздельные Composer-проекты без общего кода).
  2. **Скриншот-превью для карточек в гриде** (`_preview.png`). В самом
     легаси `PreviewImageService::createPreview()` уже был отключённым
     no-op (внешний `screenshot.tds24.ru` намеренно выключен, с
     докблоком "own local headless-browser implementation is planned") —
     переносить было нечего, реализовано с нуля: `chromedp/headless-
     shell` (новый Docker-сервис `deploy/docker-compose.yml`, профиль
     `screenshot`) + `chrome-php/chrome` composer-пакет (5.7M+ скачиваний,
     MIT, проверен на репутацию перед установкой — чисто) в
     `App\Services\PreviewImageService`. Скриншотит ТОТ ЖЕ URL, что
     кнопка Preview выше — один путь рендеринга на обе фичи.
     `App\Jobs\GenerateLocalFilePreviewJob` — порт легаси
     `CreatePreviewImageCommand::enqueue()`, диспатчится из
     `EditorController::saveFileDataAction()`/`removeFileAction()` (те
     же две точки, что в легаси, `createFileAction` НЕ диспатчит — тоже
     как в легаси). `preview` поле в сериализаторах — порт
     `ActionableResourceTrait::addPreviewData()` (`{folder}/_preview.png`,
     всегда, вне зависимости от того, сгенерирован ли файл фактически —
     буквальное легаси-поведение).

  Живая проверка (Docker, headless-shell + traffic-core `php -S` +
  реальный MySQL): полный pipeline — save-триггер → job → сигнатура URL →
  `preview.php` рендерит local_file → headless Chrome реально
  скриншотит → валидный PNG 800×600 сохранён по правильному пути.
  Фикстуры удалены. Тесты: 6 новых (`EditorTest`/`LandingsTest`/
  `OffersTest`) + уже существующие `previewAction`-тесты. Полный
  `./vendor/bin/pest` — 384/384, `php -l` чисто на всех изменённых/новых
  файлах (включая `traffic-core/public/preview.php`).
- ~~Trial-режим лимиты~~ — не портировать в этом раунде
  (`CampaignsController.php`/`StreamsController.php` TODO остаются как
  есть). Пересмотреть только по явному запросу пользователя.
- ~~i18n/переводы~~ — вынесено в отдельную задачу "на доработку, но
  позже" (не в этом раунде вообще). Затронутые места остаются
  задокументированы для той будущей задачи: `ResourceController.php`
  (translated string TODO), `GeoProfiles`' `decorated_countries` (только
  английские названия), `SystemController` (язык). Объём — вероятно,
  отдельная многосессионная задача (перевод всех строк UI).

---

## 5. Продакшн-деплой — только план, ничего не реализовано

`docs/ARCHITECTURE_PLAN.md`, раздел "Установка и защита прод-билда",
описывает целевой пайплайн: ionCube-кодирование `app/`, bash-инсталлятор
в духе легаси `kctl`, certbot-автоматизация SSL, systemd-юниты для
Octane/traffic-worker/queue/scheduler. Реально в `deploy/` есть только
dev-докер (`Dockerfile.dev-php`, `docker-compose.yml` для
mysql/redis/worker/fpm, `php-fpm-local-file-pool.conf`). `deploy/systemd/`
— пустая директория.

Это отдельная, немаленькая по объёму задача — браться только когда
проект реально готовится к боевому деплою, не раньше (нет смысла делать
production install script для системы, которая ещё меняется каждый
день). Явно спросить пользователя о приоритете этого пункта относительно
остальных, если дойдёт очередь.

---

## 6. Контрактные тесты — ЗАКРЫТО, покрытие 43 из 43 контроллеров (2026-09-03)

**КРИТИЧЕСКАЯ НАХОДКА (2026-09-03): весь `tests-contract/` сьют был
СЛОМАН против нового бэкенда с самого начала, "покрытие ~24 модулей"
никогда реально не проверялось живьём против Laravel-порта.**
`tests/Support/ApiClient.php` строил `base_uri` как `{target}/admin/`
(без `index.php`) — легаси (реальный Apache/аналог, DirectoryIndex)
это прощал, но новый бэкенд (`Route::match(..., '/admin/index.php', ...)`
— только этот один точный путь, без directory-index фолбэка) отвечал
голым 404 на КАЖДЫЙ запрос, включая `auth.login` в `beforeEach` —
т.е. вообще ни один тест ни разу не доходил до реальной проверки
контракта против нового бэкенда. Исправлено (один файл,
`ApiClient.php`) + подтверждено живьём: `GroupsTest`/`BotlistTest`
теперь проходят на 100% против ОБОИХ таргетов.

После починки полный прогон против нового бэкенда впервые дал реальный
сигнал: **40 из 93 тестов падали.** Основная причина (найдена и
исправлена в этой же сессии) — `landings`/`offers`/`domains`/
`traffic_sources`/`affiliate_networks`.`state` не имели DB-уровня
дефолта (в отличие от `campaigns`/`streams`, у которых есть
`->default('active')`) — свежесозданная запись получала `state=NULL`,
что делало её невидимой в `WHERE state='active'`/`!='deleted'`
листингах. Исправлено добавлением `$fill['state'] ??= 'active';` в
`createAction()` всех 5 контроллеров (App-уровень, не миграция —
`ALTER TABLE MODIFY` не портируется на SQLite, которым гоняется
Pest-сьют) + бэкофилл существующих NULL-строк в общей dev-БД. После
фикса: Landings/Offers полностью зелёные, Domains — частично.

**stacktrace-падения — ЗАКРЫТО (2026-09-03, тот же день).** Разобрано:
легаси `AdminContext::handleException()` РЕАЛЬНО кладёт настоящий
`$e->getTraceAsString()` в `NotFoundError`/`ADODB_Exception` JSON-
ответы — не мнимый регресс, а буквальный легаси-контракт. Исправлено
во всех ~19 контроллерах (27 мест, `'stacktrace' => ''` →
`(new \Exception($message))->getTraceAsString()` / `$e->getTraceAsString()`).
Попутно найдены и исправлены ещё 3 реальных бага: (1) `settings`
таблица была пустая — добавлен `SettingsSeeder` (52 легаси-дефолта),
(2) `ProfileController::indexAction()` → переименован в `showAction()`
(легаси не имеет `profile.index` вообще), (3) `SettingsController::
findAction()` отсутствовал — добавлен. Полная история — `docs/
PORTING_LOG.md` ("Разбор оставшихся контрактных падений"). Контрактный
сьют против нового бэкенда: 40 → 15 падений.

**DomainsTest — ЗАКРЫТО (2026-09-03, тот же день).** Разобрано и
исправлено:
- `domains.create` возвращал ОДИН объект вместо массива из 1 элемента.
  Живьём подтверждено против легаси (`createMultiple()` реально всегда
  отдаёт массив, даже для одного домена) — обёрнуто в `[...]`
  (`DomainsController::createAction`), поправлены оба места в
  `tests/Feature/DomainsTest.php`, использующие `$data['name']`
  напрямую (теперь `$data = $response->json()[0]`).
- `domains.show` без id / с несуществующим id / для archived-домена —
  РАНЬШЕ отдавал 404 (осознанное "улучшение" предыдущей сессии,
  задокументированное как "легаси-баг, не стоит сохранять"). Живьём
  перепроверено против реального легаси (порт 8090) ДО правки:
  подтверждено, что легаси РЕАЛЬНО отдаёт 200 с телом без id/name, но с
  `campaigns_count`/`default_campaign`/`error_solution` (баг легаси, не
  придуманный тестом) — контрактный тест был прав. Откачено обратно к
  буквальному легаси-поведению (`DomainsController::showAction()` +
  новый `serializeMissingDomain()`), поправлены 3 теста в `tests/
  Feature/DomainsTest.php`, ожидавшие 404. **Урок**: не доверять
  докблоку "это баг легаси, реальный клиент не пострадает" без живой
  перепроверки — контрактный тест был единственным источником правды,
  который действительно смотрел на реальный легаси.

**Все 11 закрыты (2026-09-03) — подробности `docs/PORTING_LOG.md` ("Все 11
оставшихся контрактных падений разобраны и закрыты").** Кратко:
`ApiKeysController` action-имена возвращены к легаси (`getAll/add/delete` —
переименование в `index/create/remove` было реальным багом, не
"тем же отклонением, что у Users/Groups", как утверждал докблок — те два
контроллера и в легаси уже `index/create/...`); `GroupsController` получил
реальную uniqueness-валидацию имени в рамках `type` (легаси `GroupValidator`
её действительно делает); `UserPreferencesController::getAction()`
перестал JSON-оборачивать сырое значение; `UsersTest` — фикстура
`tests-contract` чинена на отправку `password_hash`+`new_password` разом
(легаси требует первое, порт — второе, оба поля не мешают друг другу), а
неавторизованный `listAsOptionsAction` (нет в легаси вообще) — удалён;
`CampaignsTest`'s `smoke`-группа теперь реально исключена из дефолтного
прогона через `tests-contract/phpunit.xml`.

`tests-contract` (без `smoke`) — **92/92 на новом бэкенде И на легаси**.
`backend/./vendor/bin/pest` — 383/383.

Перезапуск: `TDS_TEST_TARGET=http://localhost:PORT vendor/bin/pest` в
`tests-contract/`, бэкенд — `php artisan serve --port=PORT`, легаси для
сверки уже поднят постоянно в Docker (`tds-app`, порт 8090, логин
`admin`/`TdsAdmin2026!`).

Уже покрыты (документные, теперь и реально работающие против нового
бэкенда): Auth, Groups, Campaigns, Streams, Offers, Domains,
TrafficSources, Users, Settings, ApiKeys, Triggers, FavouriteStreams,
StreamFilters/Actions/Events/Types/Schemas, Dics, Profile,
UserPreferences, AffiliateNetworks, Landings, Botlist, Labels,
GeoProfiles, GeoDb, Conversions, Reports, Editor, Cleaner,
ThirdPartyIntegration/TpiMandatory/CodePresets/KClientJsPreset/
FacebookIntegration/AppsFlyerIntegration (весь кластер разом,
2026-09-03 — 18 тестов, у кластера не было НИ ОДНОГО теста до этой
сессии; 5 реальных багов исправлено + подтверждена живьём КРУПНАЯ
data-corruption находка в самом легаси, find/get/update отдают
испорченный JSON), **Macros/Branding/IpInfoDataTypes — последние 3
модуля (2026-09-03 — 6 тестов; Branding отдавал branding-настройки
БЕЗ ACL-гейта на чтение + создавал БД-строку побочным эффектом на
read-only запрос, оба исправлены; подтверждена живьём находка про
собственный битый `array_flatten()`-шим легаси)**.

**Раздел 6 полностью закрыт.** 43/43 контроллера покрыты живыми
контрактными тестами против ОБОИХ таргетов. Итоговая сводка находок и
живых регрессионных прогонов — `docs/PORTING_LOG.md`, записи с
2026-09-03 (GeoDb → Macros/Branding/IpInfoDataTypes).


## 8. Аудит всех ~43 контроллеров (2026-09-03) — найденные и открытые пункты

Систематический живой аудит (admin/non-admin/невалидные параметры,
сверка с легаси) всех контроллеров `backend/app/Http/Controllers/Admin/`.
Подробности и живая верификация — `docs/PORTING_LOG.md`.

**Закрыто в этом раунде:**
- `ReportsController` — не хватало 4 из 6 легаси action'ов
  (`summary`/`columnsAsOptions`/`parameterAliases`/`statsForCampaign`) —
  портированы. Попутно найден и исправлен реальный баг в
  `App\Services\Grid\GridBuilder` (общий с `reports.build`): дефолтный
  набор колонок при отсутствии явного `columns` смешивал агрегатные и
  сырые построчные колонки без GROUP BY — 500 на реальном MySQL
  (`ONLY_FULL_GROUP_BY`), никогда не ловилось SQLite-Pest-сьютом. Легаси
  не падает только потому, что `Core\Db\Db` безусловно шлёт
  `SET sql_mode=''` на каждое подключение — осознанно не воспроизведено
  (реальный footgun, не полезное поведение).
- Pretty-URL postback (`docs/PORTING_LOG.md`, отдельная запись) —
  докблок "нет легаси-эквивалента" был неверен, поправлен.
- `EditorController::infoLandingAction` — портирован. В отличие от всех
  остальных action'ов этого контроллера, НЕ идёт через
  `resolveEditable()` (нет требования local_file/folder вообще, легаси
  этого не проверяет) и требует ОБА `isEditAllowed`+`isViewAllowed` (не
  один). Возвращает то же базовое поле-множество, что `landings.show`/
  `offers.show` (raw attributes + декодированный `action_options`, без
  `group`). 4 новых Pest-теста, живая проверка на реальном MySQL
  (реальный external-лендинг → 200 с полными данными;
  несуществующий id/неверный type → 404).

**`AdminApiController::indexAction` — ЗАКРЫТО (2026-09-03).** Отдавал
JSON-заглушку на премисе "порт JSON-only, Blade-вьюх нет" — премиса не
основание: легаси-страница (`views/index.phtml`) — статический HTML без
какой-либо серверной логики (просто Swagger UI, CDN-ассеты с
`admin-api.docs.tds.io`, `url: "?object=adminApi.spec"`), тривиально
воспроизводится как raw HTML-строка без движка шаблонов. Портирован
буквально (тот же CDN-хост, уже используемый `specAction()` без
изменений), кроме одного: у легаси в реальном файле — блуждающий
литеральный символ `e` между двумя `<script>`-тегами (опечатка при
копипасте, реально видна в браузере) — убран как настоящий баг, не часть
контракта (ничего не потребляет эту страницу программно, чтобы зависеть
от опечатки). Ни один action не гейтится авторизацией в реальном легаси
(нет `isAdmin()`/`isViewAllowed()` в исходнике) — подтверждено живьём
против порта 8090, доступны без логина. `specAction` УЖЕ был настоящим
302 (`RedirectResponse`) — фоновый аудит-агент ошибочно сообщил про
"200 с HTML meta-refresh", живая проверка это не подтвердила; не
доверять такому отчёту без личной перепроверки (тот же принцип, что для
докблоков). 2 новых Pest-теста (`AdminApiTest.php`). Полный
`./vendor/bin/pest` — 389/389.

**Осталось (не в этом раунде, следующая сессия):**
- Остальные ~40 контроллеров проверены живьём (имена action'ов, базовая
  форма ответа) — расхождений не найдено, см. полный список
  "проверено — не найдено" в `docs/PORTING_LOG.md`.

Задача на будущее: (а) разобрать оставшиеся ~7 падений (stacktrace/
domains-quirk) — вероятно, реальные мелкие расхождения контракта; (б)
писать контрактные тесты по образцу `BotlistTest.php`/`GroupsTest.php`
для каждого из непокрытых модулей — сверяя реальный ответ старого
приложения (`tds-app`, отдельный порт) и нового, теперь что инструмент
для этого реально работает.

---

## 7. Осознанно отложено — НЕ трогать без явного запроса пользователя

Эти пункты — не пропуски, а принятые решения, задокументированные в
коде/`docs/PORTING_LOG.md`. Не начинать работу над ними, пока
пользователь явно не попросит:
- **SelfUpdate** — сам легаси-код уже "disabled" (`TdsUpdater::update()`
  жёстко кидает исключение), переносить нечего кроме заглушек.
- **Migrations HTTP-API** — сознательно не переносится, `php artisan
  migrate` вместо HTTP-эндпоинта (security-решение, не пробел).
- **Home** — ждёт frontend, вне скоупа этой задачи.
- **Лицензирование/PRO-фичи** — унаследованный факт: в переданном
  легаси-исходнике уже нейтрализовано кем-то до нас
  (`FeatureService::isBasic()/isPro()/isBusiness()` захардкожены), не
  наше решение и не задача на перенос.
- **Logs-модуль** — был отложен как "нетривиальный парсер, источники
  данных ещё не существуют" — это утверждение больше не полностью верно
  (postback/traffic-логи из traffic-core Phase 11/17 теперь есть), но
  сам модуль всё ещё не начат. Можно пересмотреть приоритет, но не
  автоматически считать высокоприоритетным — спросить пользователя.

---

## Рекомендованный порядок работы

**Разделы 1 и 6 — полностью закрыты (2026-09-03).** Разделы 2/3 —
частично закрыты (см. их собственные заголовки для точного статуса
каждого подпункта). Оставшееся:

1. Раздел 4 (preview/trial/i18n) — уточнить с пользователем объём перед
   стартом (особенно i18n — может оказаться отдельной большой задачей).
2. Раздел 5 (прод-деплой) — только по явному запросу, не раньше.
3. Раздел 2/3 недозакрытые подпункты — см. их заголовки.

---
*Обновляется по ходу работы, как `docs/PORTING_LOG.md` — дописывать, не переписывать.*
