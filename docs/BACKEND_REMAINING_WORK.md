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

### 1.1 ACL не подключён к withStatsAction
Пять контроллеров дают полный доступ к Grid/EntityGrid статистике без
ACL-фильтрации, хотя `AclService` (`backend/app/Services/AclService.php`)
уже реально используется в их index/show/create/update:
- `backend/app/Http/Controllers/Admin/CampaignsController.php`
- `backend/app/Http/Controllers/Admin/OffersController.php`
- `backend/app/Http/Controllers/Admin/LandingsController.php`
- `backend/app/Http/Controllers/Admin/StreamsController.php`
- `backend/app/Http/Controllers/Admin/TrafficSourcesController.php`

Задача: найти `withStatsAction`-подобный метод в каждом (искать по TODO
с идентичным текстом про ACL), подключить `AclService::filterByAcl()`/
`getAllowedCampaignIds()` так же, как это уже сделано в соседних методах
того же контроллера — паттерн уже есть в коде, копировать оттуда.

### 1.2 Groups — реальные имена вместо null-стаба
`GroupsController.php` + `Group`-модель уже существуют и подключены в
`AclService` (entity_type = group). Но в нескольких местах остался
старый null-стаб с комментарием "Groups module not ported yet" —
комментарий устарел, сам модуль уже есть:
- `CampaignsController.php` (withGroupName)
- `OffersController.php` (withGroupName)
- `LandingsController.php` (withGroupName)

Задача: заменить null-стаб реальным JOIN/lookup на `groups` через
`Group`-модель (или существующий `GroupsController`'s репозиторный
метод, если такой уже есть — проверить).

### 1.3 ReportsController не использует уже готовые в traffic-core словари
`backend/app/Http/Controllers/Admin/ReportsController.php` явно
исключает geo/device/browser/isp-измерения с комментарием "колонок нет
на этом порту `clicks`". Это устарело: `ref_countries`/`ref_regions`/
`ref_cities`/`ref_browsers`/`ref_browser_versions`/`ref_os`/
`ref_os_versions`/`ref_device_types`/`ref_device_models`/`ref_isp`/
`ref_operators`/`ref_connection_types` — все появились в traffic-core
Phase 9-10 (`backend/database/migrations/2025_01_01_000029_create_visitors_and_geo_device_ref_tables.php`),
и `clicks` таблица их реально использует через FK-колонки visitor'а
(см. `traffic-core/src/Pipeline/Visitor/DictionaryRepository.php` для
списка колонок).

Задача: расширить whitelist измерений в `ReportsController`/
`Component\Reports`-эквивалентном Grid-построителе join'ами на эти
`ref_*` таблицы. Смотреть, как уже сделаны существующие join'ы на
`ref_sources`/`ref_referrers`/`ref_keywords` (те же самые, из Phase 10) —
паттерн уже есть в коде, просто не расширен на geo/device/isp.

---

## 2. Console/Cron/DelayedCommands/PruneTask — джобы не написаны

Архитектурное решение уже принято и задокументировано (в докблоке
`DiagnosticsController.php`): легаси-модули Console/Cron/DelayedCommands
заменяются нативным Laravel Scheduler + Queue, не переносятся как
отдельные "модули". Но `backend/app/Console/Commands/` **реально пуст** —
из всех легаси cron-задач перенесена только одна (Cleaner →
`DeleteStatsJob`, уже сделано и протестировано).

Задача: пройтись по легаси `application/Component/Cron/`,
`application/Component/PruneTask/`, `application/Component/DelayedCommands/`
(смотреть реальный код, не только имена классов) и перенести каждую
реальную периодическую задачу как Laravel `Command`+`Schedule::command()`
запись в `backend/routes/console.php` (уже используется для существующих
задач, смотреть формат там). Приоритет — по частоте использования в
легаси (какие задачи реально что-то делают с боевыми данными: очистка,
агрегация статистики, health-checks), не по алфавиту.

---

## 3. Явные 501-стабы (уже видны в коде, конкретная задача)

### 3.1 GeoDb — скачивание/обновление баз
`backend/app/Http/Controllers/Admin/GeoDbsController.php::updateAction` —
явный 501. Runtime-резолвер (IP2Location LITE) в traffic-core уже
реален (Phase 9) — админского UI управления файлами баз данных всё ещё
нет: скачать новую версию, заменить файл, показать статус.

### 3.2 Conversions — import и updateCostDefinition
`backend/app/Http/Controllers/Admin/ConversionsController.php`:
- `importAction` — явный 501, зависит от непортированного
  `Component\Clicks\Grid\ClicksDefinition`/`ConversionsService`
  (легаси: "сложная логика импорта из CSV/файла").
- `updateCostDefinitionAction` — то же самое, та же зависимость.

`log`/`logDefinition`/`statuses` в этом же контроллере уже реальны и
работают — портировать нужно именно эти два метода.

---

## 4. Preview-изображения, trial-режим, i18n

- **Preview-изображения лендингов/офферов** (`PreviewImageService` в
  легаси) — явно застаблены `null` в `LandingsController.php`/
  `OffersController.php` (`landing->isLocal()`/`offer->isLocal()`
  preview generation). Нужен реальный генератор скриншота/превью для
  локальных (`landing_type=local`/`offer_type=local`) лендингов/офферов.
- **Trial-режим лимиты** — не перенесены в `CampaignsController.php`
  (`checkTrialCampaignLimit`-подобная проверка) и `StreamsController.php`
  (`checkTrialStreamFilters`/`checkTrialStream`). Это НЕ осознанное
  решение (в отличие от лицензирования вообще) — помечено как TODO в
  коде, нужно решить: либо портировать реальные лимиты, либо явно
  задокументировать как "триала в этой сборке больше нет" — решение за
  пользователем, не додумывать самостоятельно.
- **i18n/переводы** — не начато вообще. Затронутые места: `ResourceController.php`
  (translated string TODO), `GeoProfiles`' `decorated_countries` (только
  английские названия), `SystemController` (язык). Нужна отдельная
  оценка объёма прежде, чем браться — возможно, целая задача сама по
  себе (переводы всех строк UI), не просто "починить 3 места".

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
