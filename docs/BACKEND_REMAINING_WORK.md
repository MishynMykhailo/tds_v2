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

Все 4 — no-op по умолчанию на чистой установке (governing setting
`archive_ttl`/`stats_ttl` не задан = очистка выключена, как в легаси).
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
- `RefresherTask` (принудительный HTTPS через 31 день), `PruneDailyCap`/
  `PruneStreamEvents`/`PruneLandingOfferCache`/`PruneUserBotDBCA`/
  `PruneHitLimits`/`pruneReferences()` (ref_*-словари) — низкий приоритет
  или зависят от непортированной инфры (ConversionCapacity,
  file-based lp-cache, DBCA), можно пересмотреть по отдельному запросу.

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

**`updateCostDefinitionAction` — остаётся 501, не в этом раунде.**
Зависит от `Component\Clicks\Grid\ClicksDefinition` (178 строк,
конкретные колонки cost-модели/source/referrer/keyword и т.д.) —
полноценная grid-entity для Clicks-модуля, которой в этом Laravel-порте
всё ещё нет (только `App\Models\Click` + ad-hoc
`GridBuilder`/`EntityGridBuilder` whitelist'ы). Не "доделать стаб", а
отдельная задача — построить Clicks-grid-definition с нуля. Не начинать
без отдельного запроса.

`log`/`logDefinition`/`statuses`/`import` в этом же контроллере уже
реальны и работают.

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

## 6. Контрактные тесты — покрытие ~24 из ~43 контроллеров

`tests-contract/` — реальный dual-target (legacy vs new) contract-suite
на Guzzle. Уже покрыты: Auth, Groups, Campaigns, Streams, Offers,
Domains, TrafficSources, Users, Settings, ApiKeys, Triggers,
FavouriteStreams, StreamFilters/Actions/Events/Types/Schemas, Dics,
Profile, UserPreferences, AffiliateNetworks, Landings (~24).

**НЕ покрыты** (в основном — то, что добавляли поздние фоновые агенты):
GeoDb, GeoProfiles, Conversions, Reports, Editor, Cleaner, весь кластер
ThirdPartyIntegration/CampaignIntegration (TPI, TpiMandatory, Facebook/
AppsFlyer-интеграции, CodePresets, KClientJsPreset), Botlist, Macros,
Branding, IpInfoDataTypes, Labels.

Задача: писать контрактные тесты по образцу уже существующих (смотреть
`tests-contract/tests/` на любой уже покрытый модуль как шаблон) для
каждого из непокрытых — сверяя реальный ответ старого приложения
(`tds-app`, отдельный порт) и нового.

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

1. Раздел 1 (кросс-каттинг, узкие) — быстрые точечные правки, низкий риск.
2. Раздел 3 (501-стабы GeoDb/Conversions) — конкретные, самодостаточные.
3. Раздел 2 (Console/Cron джобы) — нужен обзор реального легаси-кода
   перед портированием, побольше объёма.
4. Раздел 6 (контрактные тесты) — можно делать параллельно с 1-3, не
   блокирует и не блокируется ничем.
5. Раздел 4 (preview/trial/i18n) — уточнить с пользователем объём перед
   стартом (особенно i18n — может оказаться отдельной большой задачей).
6. Раздел 5 (прод-деплой) — только по явному запросу, не раньше.

---
*Обновляется по ходу работы, как `docs/PORTING_LOG.md` — дописывать, не переписывать.*
