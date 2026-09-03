# Porting Log — АРХИВ (traffic-core Фазы 1-17, 2026-08-29 — 2026-09-02)

Вынесено из `docs/PORTING_LOG.md` 2026-09-03 при архивации (проект начал
расти неограниченно — 2238 строк, дальше только хуже). Это ЗАВЕРШЁННАЯ,
закрытая история: полный порт `traffic-core/` (Фазы 1-17) плюс несколько
первых backend-находок с 2026-08-29. Ничего из этого не требует действий —
читать только если реально нужен архивный контекст по конкретной фазе
(например, "почему BuildRawClickStage так упорядочен"). Для текущей
работы читать `docs/PORTING_LOG.md` (актуальные записи) +
`docs/BACKEND_REMAINING_WORK.md`.

---

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

## traffic-core — Фаза 6 (campaign/group-экшен) — 2026-09-02

Портирован `campaign`/`group`-экшен (последний из трёх ранее 501, и
единственный из них, что не был реально заблокирован инфраструктурой —
`double_meta`/`local_file` остаются 501 по своим отдельным причинам).
Полная техническая детализация — в `docs/TRAFFIC_CORE_PLAN.md`, раздел
"Фаза 6". Кратко: `Traffic\Pipeline\Stage\CheckSendingToAnotherCampaign`
+ `Traffic\Pipeline\Pipeline::_run()`/`_preparePayloadForCampaign()`
прочитаны буквально и портированы как `CheckSendingToAnotherCampaign`
(новая стадия) + `PipelineRunner` (новый класс, заменил плоский
`foreach` в `public/index.php` на цикл с `LIMIT=10`, буквально как
легаси). `group` подтверждена как чистый алиас `campaign`
(`StreamActionRepository::alias("group","campaign")`), не отдельный тип.

Единственное сознательное отклонение: превышение лимита рекурсии в
легаси бросает исключение до ответа клиенту; здесь — `508 Loop
Detected` (нет легаси HTTP-эквивалента, т.к. легаси падает раньше).
`clicks.parent_campaign_id` (существующая, ранее неиспользуемая
колонка) теперь реально пишется; `parent_sub_id` не перенесён — такой
колонки нет в схеме `clicks` tds_v2.

Verification: живые curl-тесты (Docker, порт 8099, три фикстурные
кампании — прямая рекурсия A→B с подтверждением `campaign_id`/
`parent_campaign_id` в `clicks`, само-луп C→C корректно завершается
`508` за ~47мс без зависания, регрессия — прямой хит без рекурсии
по-прежнему работает и не проставляет `parent_campaign_id`). Фикстуры
удалены из dev-БД после проверки. `php -l` чисто на всех новых/
изменённых файлах.

## traffic-core — Фаза 7 (double_meta-экшен) — 2026-09-02

Портирован `double_meta` — последний из двух оставшихся 501-экшенов,
кроме `local_file`. Полная детализация — `docs/TRAFFIC_CORE_PLAN.md`
Фаза 7. Кратко: предыдущая оценка ("заблокирован тем же кластером, что и
`campaign`" — `GenerateTokenStage`/`LpTokenService`) была ОШИБОЧНОЙ,
исправлена при повторном чтении реального кода в эту сессию —
`GenerateTokenStage` принадлежит несвязанному токен-флоу (двухшаговая
атрибуция офферов), `double_meta` использует из `LpTokenService` только
`generateUserKey()`, самодостаточный статический метод. Реально нужно
было: JWT-библиотека (`firebase/php-jwt` `^7.1`, проверен на Packagist
перед установкой — легаси уже использует этот же пакет, без advisories)
+ маленький принимающий endpoint (`public/gateway.php`, порт
`GatewayRedirectDispatcher`).

Новое: `TrafficCore\Pipeline\Actions\DoubleMeta` (extends
`AbstractAction`, как `Meta`), `TrafficCore\LpToken\LpTokenKey`
(`generateUserKey()`, SALT — новый `JWT_SALT` env-секрет для tds_v2, не
обязан совпадать с легаси), `traffic-core/public/gateway.php` (второй
HTTP-entry-point).

**Операционная находка**: для двух entry-point'ов в `public/` дев-сервер
нужно поднимать БЕЗ явного router-скрипта (`php -S host:port -t public`,
не `... -t public public/index.php` как в Фазах 1-6) — иначе весь трафик
(включая `/gateway.php`) шёл бы через `index.php`.

**Буквальный перенос легаси-поведения (не баг)**: gateway-URL строится
без порта (`stripHostWww()` тоже его отбрасывает) — верно для
продакшена (порт подразумевается схемой 80/443), но требует ручной
подстановки порта при dev-верификации на нестандартном порту.

Verification: живые curl-тесты (Docker, порт 8100, кампания `tc7-dm` +
один стрим, `JWT_SALT` задан явно) — обычный клик → meta-refresh на
gateway-URL с JWT; переход по gateway-URL с тем же UA → редирект на
реальный финальный URL; тот же токен с ДРУГИМ UA → `400` (ключ
привязан к UA); без `token` → `500`; `frm=script`/`frm=frame` — верные
(неперепутанные) ветки; регрессия — переключение на `do_nothing`
по-прежнему работает. Фикстуры удалены. `php -l` чисто.

Итого: 18 из 19 реальных ключей `action_type` портировано. Осталось
только `local_file` — подтверждено повторным чтением
`PageWrapper.php`/`LocalFileService.php` в эту сессию, что оценка
"большой отдельный кластер" верна (CGI/FastCGI PHP-sandbox executor +
`MacrosProcessor` + HTML-rewriting — ни один компонент не портирован).

## traffic-core — Фаза 8 (local_file-экшен) — 2026-09-02

Портирован `local_file` — последний из 19 реальных `action_type`-ключей.
Полная детализация — `docs/TRAFFIC_CORE_PLAN.md` Фаза 8. Storage-путь
переиспользует уже готовый `backend/`'s `App\Services\LocalFileService`
(та же физическая директория `backend/<lp_dir>/<folder>`, override через
`LANDINGS_STORAGE_PATH` env) — файлы, загруженные через уже портированную
Editor/Cleaner админку, обслуживаются без доп. синхронизации.

Инфраструктурная замена (не урезание): `php8.4-cgi` не встал в
`tds2-php-dev` (Debian trixie, неудовлетворённая `phpapi-*`-зависимость —
базовый образ собирает PHP из исходников, не через apt); вместо
`php-cgi`+CGI-протокола — `proc_open` обычного CLI SAPI
(`bin/execute_local_file.php`, JSON на stdin/stdout). Добавлено
security-хардение СВЕРХ легаси: `disable_functions`
(exec/system/proc_open/etc.) и `open_basedir` (папка лендинга + `/tmp`)
на каждый запуск — легаси вообще не ограничивает рантайм исполняемого
файла, только upload-time сканирование.

Новое: `LocalFile` (экшен), `LocalFileSandbox` (settings-lookup,
path-resolve с traversal-защитой, proc_open+timeout), `HtmlPathAdapter`
(3 из 4 HTML-rewrite методов `CurlService` — `addBasePath()` не
портирован, нет path-based роутинга лендингов в traffic-core, см. её
докблок), `bin/execute_local_file.php` (воркер песочницы, вне `public/`).

Verification: живые curl-тесты (Docker, порт 8101, `LANDINGS_STORAGE_PATH`
на смонтированную `backend/lander/`) — реальный PHP-лендинг выполнился,
`$_SERVER`/`$rawClick` дошли, HTML-rewriting применился; `lp_allow_php=0`
→ raw-текст без исполнения; пустая папка → `502` с легаси-текстом
ошибки; `folder=../../etc` (traversal) → отклонено; `lp_php_timeout=1` +
`sleep(5)`-лендинг → `504` за ~1с, не завис; `system('id')` внутри
лендинга → недоступен, `open_basedir` корректно ограничен. Фикстуры
(БД + файлы) удалены после. `php -l` чисто.

**Итого: все 19 реальных `action_type`-ключей репозитория портированы в
traffic-core.** Остаются нетронутыми периферийные кластеры, не
относящиеся к самим экшенам — GeoDb/device/bot-резолвинг, визитор/
уникальность, JWT/cookie-биндинг офферов, hit-limit/cost/payout,
постбеки, альтернативные входные точки (см. "Осознанно отложено" в
`TRAFFIC_CORE_PLAN.md`).

## GeoDb — находка по факту, не отдельная фаза — 2026-09-02

Пользователь спросил, почему не портирован GeoDb-резолвинг, ссылаясь на
реальный файл `var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN`
(единственный реально существующий бинарник — все остальные провайдеры
в `Component/GeoDb/{Maxmind,Sypex,ProIP,Tds}` есть только как код,
без единого реального `.BIN`/`.dat`-файла на диске — заявление "у всех
остальных так же прописаны правила" не подтвердилось).

Прочитаны буквально `BuildRawClickStage::_findIpInfo()`/
`_findDeviceInfo()`, `RawClick::serialize()`, `Component\Clicks\
ClickProcessing\SaveClicks`. **Ключевая находка**: резолвленные
гео/device/ISP-данные (`country`/`region`/`city`/`browser`/`os`/
`isp`/`connection_type`/`operator`/...) НЕ пишутся в `tds_clicks`
вообще — подтверждено `DESCRIBE tds_clicks` на живой легаси dev-БД,
там только `is_bot`/`is_using_proxy` (булевы флаги). Все эти поля
нормализованы в ОТДЕЛЬНУЮ таблицу `tds_visitors`
(`country_id`/`region_id`/`city_id`/`device_type_id`/`browser_id`/
`os_id`/`connection_type_id`/`operator_id`/`isp_id`/`ip_id`/
`user_agent_id`/`language_id`/`screen_id` — все FK на словари),
подтверждено `DESCRIBE tds_visitors` там же. `clicks.visitor_id`
ссылается на эту таблицу.

**Вывод**: "GeoDb-резолвинг" неотделим от "визитор/уникальность"
(`Component\Clicks\Model\Visitor`) — уже отдельный пункт в списке
отложенного в `TRAFFIC_CORE_PLAN.md`. В tds_v2 схеме сейчас НЕТ таблицы
`visitors` и её словарей вообще (`clicks.visitor_id` — просто
`random_int()`). Полноценный GeoDb требует: таблицу `visitors` + ~9
словарных таблиц, реальный find-or-create по ip+ua, IP2Location DB3
Lite reader (даёт только country/region/city — ISP/carrier/connection_type
физически недоступны, в LITE-тарифе этих данных нет), UA-parsing для
device/browser/os. Не начато в эту сессию — оценено как отдельная
полноценная сессия, сопоставимая по объёму с уже сделанными фазами, не
пристройка к уже сделанному. Ждёт явного решения пользователя начинать.

## traffic-core — Фаза 8 update: реальный `php-cgi`, не замена — 2026-09-02

По прямому запросу пользователя ("хочу полное тех-соответствие")
пересобрано: `deploy/Dockerfile.dev-php` теперь собирает реальный
`php-cgi`-бинарник из того же исходного дерева PHP, что и основной
`php` CLI SAPI (`docker-php-source extract` + `./configure --enable-cgi
--disable-cli --disable-phpdbg` + `make cgi`), кладёт его в
`/usr/local/bin/php-cgi`. Debian trixie apt-путь (`php8.4-cgi`) остаётся
недоступен по той же причине, что и раньше (не переисследовалось
заново — уже подтверждено ранее), но это больше не имеет значения.

`LocalFileSandbox`/`bin/execute_local_file.php` переписаны на настоящий
CGI-протокол вместо временного JSON-обмена: `proc_open` с реальными CGI
env-переменными (`REDIRECT_STATUS`/`SCRIPT_FILENAME`/`REQUEST_METHOD`/
`REMOTE_ADDR`/`CONTENT_LENGTH`, тот же набор что и легаси
`Sandbox::execute()`), урлкодированный `params=<json>` в stdin, сырой
CGI-ответ парсится буквально как в легаси `_parseOutputToResponse()`.

Две находки в процессе: (1) `proc_open()` с явным `$env` ПОЛНОСТЬЮ
заменяет окружение процесса (Symfony Process у легаси — мержит) —
пришлось явно смержить с `getenv()`; (2) `php-cgi` отказывается принимать
`SCRIPT_FILENAME` с `..`-сегментами ("No input file specified.") — путь
нормализуется `realpath()`.

Полный набор из 6 живых тестов Фазы 8 (реальный PHP, plain-fallback,
missing-index, traversal, timeout, disable_functions/open_basedir)
повторён с реальным `php-cgi` — идентичный результат, плюс
дополнительно подтверждено `pdo_mysql` доступен внутри песочницы (общий
ABI с основным PHP). Фикстуры удалены после.

## traffic-core — Фаза 9 (GeoDb+визитор, токен-биндинг офферов) — 2026-09-02

Два куска сделаны параллельно двумя фоновыми агентами (координатор
заранее написал общую миграцию и разграничил файлы, чтобы агенты не
конфликтовали друг с другом), сведены в единый пайплайн координатором
одним заходом после обоих. Полная детализация — `docs/
TRAFFIC_CORE_PLAN.md`, раздел "Фаза 9".

**GeoDb + визитор**: реальный find-or-create вместо `random_int()`.
Новая таблица `visitors` + 15 `ref_*`-словарей (миграция
`2025_01_01_000029_...`, схема подтверждена `DESCRIBE` на живой
легаси-БД). `GeoDbResolver` (IP2Location LITE DB3 — единственный
реальный geo-бинарник в проекте, не в git из-за размера — см.
`traffic-core/var/geoip/README.md`), `DeviceInfoResolver`
(`matomo/device-detector`, та же библиотека, что у легаси),
`VisitorResolver` (`visitor_code` через `murmurhash3`, буквальный порт
`VisitorService::generateCode()`), новая стадия `ResolveVisitorStage`.
`isp_id`/`operator_id`/`connection_type_id` всегда `NULL` — LITE-тариф
физически не содержит эти данные.

**Токен-биндинг офферов**: порт `GenerateTokenStage`/
`LpTokenService::storeRawClick()` — Redis-хранилище клика по токену с
TTL для будущих постбеков (НЕ связано с уже сделанным `double_meta`'s
JWT). Условие сработки адаптировано на `$payload->offerId !== null`
(без правки `ChooseOfferStage`, чтобы не пересекаться с параллельным
агентом). **Реальная находка, не догадка**: `shouldAddTokenToURL()`
проверен чтением обоих легаси-файлов (`Payload.php`,
`ClickDispatcher.php`, `ChooseLandingStage.php`) и оказался
недостижимым в смоделированном traffic-core флоу — единственный вызов
`setAddTokenToUrl()` во всём легаси находится на ветке "выбран лендинг",
не "выбран оффер напрямую". URL-мутация не реализована, задокументирована
как no-op, не пробел. Новая зависимость `predis/predis`.

**Совместная живая проверка** (один клик, оба механизма разом,
фикстура кампания→стрим(schema=offers)→оффер): `visitor_id` реальный
(не random), `country`/`browser`/`os` резолвлены по живому публичному
IP хоста, повторный клик тем же IP+UA переиспользует того же визитора,
Redis-ключ создан с верным TTL. Фикстуры (БД + Redis) удалены после.
`php -l` чисто на всех новых/изменённых файлах обоих агентов и
координаторских правок слияния.

**Follow-up, замечен агентом, не сделан (вне его скоупа)**: тип фильтра
`country` в `FilterEngine` теперь можно подключить по-настоящему —
реальные гео-данные есть, раньше он был fail-open за неимением данных.

## traffic-core — Фаза 10 (периферийные стадии + полный BuildRawClickStage) — 2026-09-02

`DomainRedirectStage`/`CheckPrefetchStage`/`CheckDefaultCampaignStage`/
`CheckParamAliasesStage` — все 4 портированы (не 3, `CheckParamAliasesStage`
не отложен: без него `BuildRawClickStage`'s расширение было бы
бессмысленным). `FindCampaignStage` больше не 404'ит сам на промахе —
делегирует `CheckDefaultCampaignStage`, 1-в-1 как в легаси.

`CheckParamAliasesStage` архитектурно адаптирован: пишет в новое
`payload->resolvedParams` вместо мутации частично собранного `RawClick`
(которого у нас как отдельного мутабельного объекта нет —
`BuildRawClickStage` строит всё за один проход). `BuildRawClickStage`
проверяет `resolvedParams` раньше сырых параметров запроса для каждого
алиасируемого поля.

Полный `BuildRawClickStage`: referrer/source/se_referrer/search_engine/
x_requested_with/keyword/cost/ad_campaign_id/creative_id/external_id/
landing_id-через-`lp_id`/15×sub_id/10×extra_param — через `ref_*`-словари
(переиспользован `DictionaryRepository` из Фазы 9, whitelist расширен,
не задублирован). Добавлена одна пропущенная миграция — `ref_sub_ids`
(была упущена в исходном батче `2025_01_01_000017_...`).

**Реальный баг, найден живым тестом**: `clicks.source_id`/`referrer_id`
— единственные `NOT NULL` среди всех новых FK-полей. Клик без реферера
→ `PDOException: Column 'referrer_id' cannot be null`. Исправлено `?? 0`
fallback для этих двух конкретных полей.

`StoreRawClickStage` теперь строит INSERT динамически из
`array_keys($payload->rawClick)` (35 полей) вместо ручного списка.

Verification: campaign-alias (`?kw=`→`keyword` через
`campaigns.parameters`), settings-alias (`?utm_source=`→`source` через
`source_aliases`), прямые параметры, регрессия на голом клике,
`CheckDefaultCampaignStage` все 3 ветки (кампания-фолбэк через
recursion, redirect, честный 404), `CheckPrefetchStage` on/off,
`DomainRedirectStage` реальный 301. Фикстуры удалены. `php -l` чисто.

НЕ портировано, с причиной: `language`/`currency` (нет колонок на
`clicks` в этой схеме вообще), keyword-из-referrer через
`ReferrerParserService` (нужна база паттернов поисковиков), bot/proxy-
детекция (отдельные кластеры).

## traffic-core — Фаза 11 (постбеки, hit-limit/cost/payout) — 2026-09-02

Два куска параллельно двумя фоновыми агентами, сведены координатором
без конфликтов (заранее разграничены файлы). Полная детализация —
`docs/TRAFFIC_CORE_PLAN.md` Фаза 11.

**Постбеки**: новый вход `public/postback.php`, `Postback`-класс
(буквальный порт field-extraction), find-or-update конверсии по
`sub_id` (упрощённый дедуп — `clicks.sub_id` уникален), апдейт
`clicks.is_*`+revenue, best-effort исходящий S2S (traffic source + новая
таблица `campaign_postbacks`, миграция `000031`) с минимальной макро-
заменой через `curl`. Найден и НЕ воспроизведён реальный баг легаси —
`PostbackDispatcher::dispatch()` никогда не вызывает `_updateBody()` в
success/error ветках, `?return=jsonp/gif` физически недостижимы в
реальном легаси. `postback_statuses` формат — JSON-массив строк,
подтверждено чтением `TrafficSourcesController`, не угадано.

**Hit-limit/cost/payout**: `HitLimitService` (Redis sorted set,
буквальный порт `RedisStorage`), тип фильтра `limit` в `FilterEngine`
стал реальным (был fail-open с Фазы 4) — `evaluate()` получил новый
параметр `$streamId`. Payout (CPC-офферы) работает, подтверждено
живьём. **Cost — крупная находка**: в реальном легаси cost применяется
только когда `cost_type` в {CPA,CPS,RevShare} И
`rawClick->isUniqueCampaign()` — вторая часть условия в traffic-core
пока всегда `false` (per-campaign uniqueness не портирована), значит
**cost сейчас не применяется вообще** — временное, задокументированное
ограничение, не баг; арифметика (traffic_loss и т.д.) независимо
подтверждена верной через временный override с последующим откатом.

**Совместная проверка координатора**: один клик — реальный visitor,
оффер, payout (is_sale=1, sale_revenue из payout_value) → 302; постбек
по тому же sub_id с revenue=15.50 создал conversions-строку И
перезаписал `clicks.sale_revenue` на 15.50 (постбек как источник
истины поверх payout-оценки). Фикстуры (БД + Redis) удалены после всех
тестов, обоими агентами и координатором.

Итого: постбеки и hit-limit/payout реально работают. Cost портирован,
но неактивен до per-campaign uniqueness. Осталось из крупных кластеров:
визитор-уникальность (per-stream/per-campaign/global флаги + Redis
entity-биндинг), альтернативные входные точки, `processMacros()`
(полная версия), асинхронная запись клика, FCGI/php-fpm для
`local_file` (не критично).

## traffic-core — Фаза 12 (визитор-уникальность, разблокировала cost) — 2026-09-02

Портирован `UpdateCampaignUniquenessSessionStage`/
`UpdateStreamUniquenessSessionStage`/`SaveUniquenessSessionStage` —
слиты в одну `UpdateUniquenessStage` (та же адаптация, что и
`CheckParamAliasesStage`: один проход вместо мутации общего объекта).
Только Redis (не куки, как в легаси, — `EXISTS`+`SETEX` на ключ, TTL =
`campaign.cookies_ttl` часов), тот же идиоматичный паттерн, что
`HitLimitService`/`LpTokenService`.

Разблокировала Finding #2 из Фазы 11: `UpdateCostsStage`'s
`isUniqueCampaign()`-заглушка заменена на чтение реального
`payload->rawClick['is_unique_campaign']`. Cost теперь реально
применяется для CPA/CPS/RevShare на первом хите визитора в TTL-окне.

Verification: первый клик (IP+UA) → unique=1 по всем трём измерениям,
cost применился; повторный тот же IP+UA → unique=0, cost=0; другой UA,
тот же IP (`uniqueness_method=ip_ua`) → снова unique=1, cost применился.
Фикстуры (БД + Redis) удалены.

Осталось из визитор/уникальность кластера: Redis-биндинг сущностей
(sticky лендинг/оффер/стрим), cookie-хранилище уникальности (сознательно
не портировано — см. Фазу 12 в TRAFFIC_CORE_PLAN.md).

## traffic-core — Фаза 13 (Redis-биндинг сущностей) — 2026-09-02

Последний кусок визитор/уникальность. Порт `EntityBindingService`
(только Redis, без "deprecated" id/кук — та же адаптация, что в Фазе
12). Uniqueness-id переиспользован из `UniquenessService::
uniquenessId()` (сделан `public static`). Вписано напрямую в
`StreamRotator`/`LandingOfferRotator` (не в вызывающие стадии) — они
сами решают, включён ли биндинг, по `Campaign::isBindVisitorsEnabled()`/
`isBindVisitorsLandingEnabled()`/`isBindVisitorsOfferEnabled()`
(кумулятивный gate по длине `bind_visitors`: 1+ символ — стрим, 2+ —
ещё и лендинг, 3+ — ещё и оффер).

Перенесён буквально легаси-квирк: биндинг стрима НЕ перепроверяет
`CheckFilters` (голый id-матч по списку кандидатов), биндинг
лендинга/оффера — перепроверяет `state=active` через реальный lookup
(разное поведение двух ротаторов в самом легаси, не унификация задним
числом).

Verification: кампания с `bind_visitors`, 2 стрима 50/50 — один визитор,
5 кликов, все 5 → один и тот же стрим; другой визитор — независимо
получил свой (возможно другой) стрим, подтверждая реальную случайность
первого выбора. Аналогично для оффера. Регрессия — кампания без
`bind_visitors` работает как раньше. Фикстуры (БД + Redis) удалены.

**Кластер визитор/уникальность полностью закрыт** (Фазы 9, 12, 13).

## traffic-core — Фаза 14 (processMacros — реальная подстановка) — 2026-09-02

Реальная находка: легаси применяет макросы не по одному разу на 15+
классов экшенов, а централизованно через `AbstractAction::
getActionPayload()` (application/Traffic/Actions/AbstractAction.php:55).
Порт делает то же самое — `ExecuteActionStage` подставляет один раз
перед диспетчеризацией, плюс 3 отдельные точки для контента, который не
идёт через `actionPayload` (`Curl.php` — тело фетча, `LocalFile.php` —
контент страницы, `OutboundPostbackService` — S2S URL, апгрейд с
временной 5-строчной замены).

`campaign`/`group` осознанно исключены — `ToCampaign::_execute()`
читает `getRawActionPayload()` (сырой, без подстановки), иначе сломался
бы `(int)`-каст в `CheckSendingToAnotherCampaign`.

Новое: `TrafficCore\Macros\MacrosProcessor` (движок парсинга/замены,
источник данных не знает) + `ClickMacroValues` (строит карту для
клик-контекста). Попутно закрыт реальный пробел: `BuildRawClickStage`
теперь параллельно с `rawClick` (словарные FK) пишет
`payload->clickFields` (сырые строки) — без этого `{sub_id_1}`
разворачивался бы в opaque словарный id вместо реального значения.

Портировано ~35 макросов с реальными данными (sub_id/extra_param×N,
source/referrer/keyword/search_engine, cost/revenue/profit,
campaign/stream/landing/offer id+name, GeoDb/device поля, ip/ua/language,
date/random/token/currency/debug). НЕ портировано: языковой перевод
country/region/city, isp/operator/connection_type (нет данных),
is_bot/is_using_proxy (нет детекции), `from_file`/кастомные PHP-макросы
(риск как у `local_file`), конверсионные макросы за пределами уже
поддержанных 7 в постбеках.

Verification: URL с `{sub_id}`/`{campaign_name}`/`{country}`/
`$campaign_id`/`{source}` — все подставились верно (`country=US` —
реальный живой GeoDb-лукап); raw-режим (`{_name}`) не urlencode'ит,
обычный — encode'ит; регрессия — `campaign`-рекурсия по-прежнему
работает (числовой id не пострадал). Фикстуры удалены. `php -l` чисто.

## traffic-core — Фаза 15 (асинхронная запись клика) — 2026-09-02

`StoreRawClickStage` теперь только `RPUSH`ит в Redis-очередь
(`ClickQueue`, буквальный порт легаси `RedisStorage` — `RPUSH` +
атомарный `LRANGE`+`LTRIM`-pipeline, `RANGE_SIZE`=1000). Реальный
INSERT — в новом воркере `bin/process_click_queue.php` (порт
`ProcessCommandQueue`+`AddClickCommand::process()`, сужен до одного
типа команды). Группировка батча при разных наборах ключей — буквальный
порт `Db::multiInsert()`'s алгоритма (не должно случаться в этом
проекте, но не падает и не молча теряет колонки, если случится).

Новый `traffic-core-worker` сервис в `deploy/docker-compose.yml`
(profile `worker`, не в дефолтном `up`).

Verification: 3 клика → очередь=3, `clicks`=0 (подтверждена реальная
асинхронность); запуск воркера → батч-вставка всех 3, очередь=0; новый
клик ПОКА воркер уже работает — подхвачен на следующем poll-цикле.
Фикстуры удалены. `php -l` чисто.

Также в эту сессию: `docs/MACROS.md` — полная таблица легаси-макросов
vs. что реально портировано в `ClickMacroValues`/`OutboundPostbackService`
(вскрыла несколько макросов, не упомянутых в докблоках Фазы 14:
`ts_id`/`destination`/`device_brand`/`offer`/`traffic_source_name`/
`visitor_code`/`keyword_cp1251`/`visitor_id` — все честно отмечены как
не портированные).

## traffic-core — Фаза 16 (FCGI/php-fpm пул для local_file) — 2026-09-02

Порт `SandboxFactory::create()`'s FCGI-первым-CGI-fallback логики.
`php-fpm` собран из исходников тем же приёмом, что `php-cgi` (Фаза 8) —
один `./configure --enable-cgi --enable-fpm` + `make cgi fpm` даёт оба
бинарника разом. `cgi-fcgi`-мост — пакет `libfcgi-bin` (обычная
C-библиотека, apt ставит без проблем, в отличие от `php8.4-*` пакетов).
`LocalFileSandbox::execute()` проверяет `PHP_FPM_HOST`/`PHP_FPM_PORT`
живым `fsockopen`-пробником (не просто наличием env-переменной) и
падает на уже существующий CGI-путь, если пул недоступен.

Новый `deploy/php-fpm-local-file-pool.conf` + `traffic-core-php-fpm`
compose-сервис (профиль `fpm`).

**Два реальных, живьём найденных нюанса, оба исправлены**:
(1) `open_basedir` пула статичен на весь landings root (не на
конкретную папку лендинга, как в CGI-пути) — per-request `-d`-оверрайды
через `cgi-fcgi` невозможны технически; (2) клиентский таймаут убивает
только `cgi-fcgi`-мост, не воркер пула — подтверждено живьём
(`sleep(5)`+`lp_php_timeout=1` → визитор получает "Timed out" за ~1с,
но воркер сам по себе продолжал бы работать все 5с). Исправлено
`request_terminate_timeout` (статичный потолок на уровне пула,
30s в конфиге) — подтверждено логом `php-fpm.log` (не stdout контейнера
— отдельный файл) с временным `2s`: `"execution timed out ... SIGTERM"`.

Verification: полный путь `LocalFile`→`LocalFileSandbox`→`cgi-fcgi`→пул
подтверждён не-заглушкой (реальный `open_basedir`/`disable_functions`
из статичного конфига); fallback на CGI при остановленном пуле —
подтверждён по РАЗНОЙ форме `open_basedir` (per-folder vs whole-root);
кросс-контейнерный FastCGI по TCP — подтверждён (вызывающий контейнер
без смонтированных `/landings` получил корректный ответ). Все
контейнеры остановлены, фикстуры удалены. `php -l` чисто.

Итого: все 6 пунктов из исходного списка "что осталось" закрыты
(GeoDb+визитор, hit-limit/cost/payout, постбеки, processMacros,
асинхронная запись, FCGI-пул). Осталось из крупного: альтернативные
входные точки (отдельная многосессионная задача), Redis-биндинг
сущностей уже закрыт Фазой 13.

## traffic-core — Фаза 17 (альтернативные входные точки) — 2026-09-02

Мелкие: `public/robots.php` (`domains.allow_indexing`, default=allow при
отсутствии домена — как в легаси), `public/ping.php` (`TrafficCore\
Domain\DomainService::getTrackerCode()`, формула как в легаси, но на
`JWT_SALT` вместо легаси-SALT — те же основания, что у `LpTokenKey`),
`public/update-tokens.php` (пост-фактум обновление клика по `sub_id` —
`sub_id_N`/`extra_param_N`/`offer_id`/`is_bot`, требует `sub_id`, 400
если пуст).

Новая `TrafficCore\Queue\ClickUpdateQueue` (отдельный Redis-список
`click_update_queue`, НЕ тот же список что `ClickQueue` — см. её
докблок про порядок гарантий) + `bin/process_click_queue.php` теперь
дренирует оба списка каждую итерацию. `sub_id_N` резолвится через тот
же `ref_sub_ids`-словарь, что `BuildRawClickStage`; `extra_param_N` —
голые строки. `sub_id` без совпадающей строки в `clicks` — лог + drop,
без ретрая (тот же принцип, что `ClickQueue`).

**ClickApi (`public/click-api.php`) — центральная вещь фазы.**
Прогоняет ТОТ ЖЕ пайплайн, что обычный клик (переиспользованы все
существующие стадии), просто не редиректит браузер, а возвращает JSON
(`ClickApiResponseBuilder`, порт легаси `_forVersion2()`). Авторизация:
`?token=<campaigns.token>` (прямо в `forcedCampaignId`, использует уже
существующий механизм `FindCampaignStage`) ИЛИ `?api_key=` против
`settings.api_key`. IP/UA/referrer можно переопределить явными
параметрами (`ClickApiSignalStage`, заменяет `CaptureSignalStage` для
этой точки входа — `payload->signal` оказался единственным источником
истины для всех стадий, проверено grep'ом).

**Найден и задокументирован реальный баг легаси, не гипотеза**:
`ClickApiDispatcher::dispatch()`'s `switch` для версий 1 и 2 не имеет
`return` после `break` — метод возвращает `null` для ОБЕИХ, включая
дефолтную версию (`DEFAULT_VERSION=1`). Работает только `?v=3`, который
требует непостроенного тут JWT/cookie-редиректа офера. Порт возвращает
v2-JSON безусловно (единственная работающая форма) вместо повторения
пустого ответа, на который никто не мог полагаться.

`Ktrk` (`public/ktrk.php`) — обёртка `KTracking.response({sub_id,
token});` вокруг того же пайплайна (без токен/api_key авторизации —
только через домен/alias, как в легаси). `KtrkDispatcher extends
ClickApiDispatcher` с хардкодной версией 2 в легаси — из-за того же
missing-return бага КАЖДЫЙ вызов `/ktrk` там кидает необработанное
исключение. Тоже задокументировано, не воспроизведено.

**KClientJS НЕ портирован — реальная, проверенная находка, не
решение "показалось сложным".** `KClientJSDispatcher`'s ОБЕ ветки
(`_getCodeWithSubId`/`_getCodeWithOutSubId`) вызывают
`CodeGenerator::generateClientCode()`, которая делает
`file_get_contents(self::CLIENT_LOCATION_DEFAULT)`, а
`CLIENT_LOCATION_DEFAULT = NULL` буквально в исходнике легаси. Портировать
здесь нечего — сам легаси-эталон нефункционален как есть (или файл
собирается отдельным billing-only build'ом, которого нет в переданном
исходнике). Не выдумывал замену.

**LandingOfferDispatcher (`public/landing-offer.php`)** — офер
запрашивается ПОСЛЕ того, как лендинг уже показан. Восстановление
клика по `_token` (`LpTokenService::getRawClickByToken()`, новый метод +
`subIdFromToken()`) — Redis-первый, `clicks`-фоллбек. Требует
`payload->forcedStreamId`/`forcedOfferId` (новые поля, добавлены в
`ChooseStreamStage`/`ChooseOfferStage` — тот же паттерн, что
`forcedCampaignId` в `FindCampaignStage`: резолвим по id, обнуляем
поле, скип ротации).

**Реальная находка по ходу тестирования, не с потолка**: пайплайн
`GenerateTokenStage` до этой фазы генерировал токен ТОЛЬКО когда
`ChooseOfferStage` реально выбирал офер (`payload->offerId !== null`) —
т.е. НИКОГДА для лендинг-стрима (там офер решается позже,
отдельно, самим `landing-offer.php`). Получалось: `LandingOfferDispatcher`
был построен, но токен, по которому он восстанавливает клик, было
физически неоткуда взять. Закрыл, портировав легаси-триггер, который
раньше был явно отмечен как "NOT ported" в докблоке `ChooseLandingStage`:
`ChooseLandingStage` теперь ставит `payload->needToken=true`, когда у
выбранного лендинга стрим содержит `stream_offer_associations`.
`GenerateTokenStage`'s условие расширено на `offerId !== null ||
needToken`.

Второй найденный вживую баг (не гипотеза, поймано первым же тестовым
прогоном): нельзя было просто выставлять `payload->landingId` из
восстановленного клика перед прогоном второго пайплайна —
`ChooseOfferStage`'s guard `if ($payload->landingId !== null) return`
существует, чтобы скипать выбор офера, когда `ChooseLandingStage`
ТОЛЬКО ЧТО выбрал лендинг В ЭТОМ ЖЕ прогоне — а не как "у клика
исторически есть лендинг". Раз `landing-offer.php` не гоняет
`ChooseLandingStage` вообще, `landingId` там оставлен `null`
намеренно — иначе офер никогда бы не выбирался. Задокументированный
побочный эффект: макросы `{landing_id}`/`{tds_landing_id}` в
`action_payload` офера тут не резолвятся (пусто), поскольку `BuildRawClickStage`
(единственный источник `payload->clickFields`) в этом втором проходе
не запускается вовсе.

`StoreRawClicksStage`-эквивалент НЕ вызывается в этом проходе
(создал бы дубликат строки с тем же `sub_id`, `ClickQueue` — чисто
INSERT-очередь, упал бы весь батч на UNIQUE-констрейнте) — вместо
этого явный push в `ClickUpdateQueue` (`offer_id`,
`affiliate_network_id` резолвится воркером из `offers`,
`landing_clicked`/`landing_clicked_datetime`, плюс
`sub_id_N`/`extra_param_N` из текущего запроса) — порт легаси
`UpdateClickCommand::saveLpClick()`.

Verification (полная цепочка, живьём): `click-api.php` по токену
кампании → создал клик с лендинг-стримом (стрим имеет офер позади) →
подтверждено появление `lookupToken` в JSON (раньше — не было);
`landing-offer.php` по этому токену → офер реально выбран
(`LandingOfferRotator`), `{sub_id}`-макрос в `action_payload` офера
подставился реальным значением, `clicks.offer_id`/`landing_clicked`
обновлены воркером через `ClickUpdateQueue`. Ошибочные токены: не
`uuid_`-префикс → 441, пустой → 400, синтаксически верный но
несуществующий → 422 — все проверены curl'ом. `update-tokens.php`
проверен на реальном `sub_id`: `extra_param_1`/новый `sub_id_2`
(через `ref_sub_ids`) реально обновились в `clicks`. `robots.php`/
`ping.php` проверены. Все контейнеры и фикстуры (кампания, стримы,
лендинг, офер, ассоциации, `settings.api_key`, клики) удалены после
теста. `php -l` чисто на всех новых/изменённых файлах.

Не портировано (см. докблоки, не тихие пропуски): `language`/
`search_engine`/`landing_id`/`datetime`/`always_empty_cookies`
оверрайды в ClickApi; cookie-фоллбек токена в `landing-offer.php`;
`hasClickApiFeature()` PRO-лицензионный гейт (в проекте нет системы
лицензий вообще, не новое решение).

Осталось из крупного по алгоритму трафика: ничего не идентифицировано
дальше — все входные точки из `application/Traffic/Context/*` и
`Dispatcher/*` разобраны (см. список в начале Фазы 17 в истории сессии).
Дальше — фронтенд (осознанно отложен).

