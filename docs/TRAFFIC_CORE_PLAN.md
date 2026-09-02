# traffic-core — план переноса click-processing pipeline

Читать вместе с `docs/PORTING_LOG.md` (стиль документирования отложенных
пунктов такой же) и `docs/ARCHITECTURE_PLAN.md` (архитектурное решение:
`traffic-core/` — отдельный lean PSR-7-стек, не Laravel, минимум
рефлексии, прямой PDO к той же БД `tds2`, что использует `backend/`).

## Легаси-архитектура (как есть, прочитано напрямую из исходников)

Legacy-код держит click-processing в `application/Traffic/`. Входных
маршрутов ("контекстов") восемь, каждый — пара `Context`+`Dispatcher`:

| Context (application/Traffic/Context/) | Dispatcher | Назначение |
|---|---|---|
| `ClickContext.php` | `ClickDispatcher.php` | основной обработчик клика — запускает полный `Pipeline::firstLevelStages()` |
| `GatewayRedirectContext.php` | `GatewayRedirectDispatcher.php` | JS/meta-редирект по уже выданному JWT-токену (второй шаг двухшагового редиректа для обхода адблокеров/кук) — НЕ отдельный клик-кейс, зависит от токена, который генерирует `GenerateTokenStage` внутри основного пайплайна |
| `LandingOfferContext.php` | `LandingOfferDispatcher.php` | прямой показ лендинга/оффера (второй уровень, `Pipeline::secondLevelStages()`) |
| `ClickApiContext.php` | `ClickApiDispatcher.php` | API вариант клика (144 строки, самый большой из контекстов) |
| `KtrkContext.php` | `KtrkDispatcher.php` | kTracker-совместимый вход |
| `KClientJSContext.php` | `KClientJSDispatcher.php` | отдаёт JS-клиент (kclient.js) |
| `PostbackContext.php` | `PostbackDispatcher.php` | приём постбеков конверсий (отдельный кластер, не пайплайн кликов) |
| `RobotsContext.php`/`PingDomainContext.php`/`UpdateTokensContext.php` | соотв. Dispatcher | вспомогательные (robots.txt, домен-пинг, обновление токенов) |

**Вывод**: `ClickContext`/`ClickDispatcher` — единственный настоящий
"первый клик" путь. `GatewayRedirectContext`, вопреки первоначальному
предположению координатора (простой размер файла ввёл в заблуждение),
НЕ является более простым самостоятельным кейсом — он лишь потребляет
токен, выданный основным пайплайном, и ничего не пишет в `clicks`. Для
Фазы 1 выбран **упрощённый вариант основного клик-пути**, а не gateway.

### Реальный порядок стадий (прочитано из `Pipeline::firstLevelStages()`, application/Traffic/Pipeline/Pipeline.php — НЕ придумано):

```
DomainRedirectStage
CheckPrefetchStage
BuildRawClickStage
FindCampaignStage
CheckDefaultCampaignStage
UpdateRawClickStage
CheckParamAliasesStage
UpdateCampaignUniquenessSessionStage
ChooseStreamStage
UpdateStreamUniquenessSessionStage
ChooseLandingStage
ChooseOfferStage
GenerateTokenStage
FindAffiliateNetworkStage
UpdateHitLimitStage
UpdateCostsStage
UpdatePayoutStage
SaveUniquenessSessionStage
SetCookieStage
ExecuteActionStage
PrepareRawClickToStoreStage
CheckSendingToAnotherCampaign
StoreRawClicksStage
```

`Pipeline::_run()` умеет ещё и рекурсивно перезапускать `firstLevelStages()`
при `payload->isAborted() && getForcedCampaignId()` (стрим типа `campaign`
= редирект в другую кампанию, `Traffic\Actions\Predefined\ToCampaign`) —
не портировано, см. таблицу отложенного ниже.

Данные о клике собирает `Traffic\RawClick` (объект, ~15 категорий полей:
язык, referrer, search-engine, keyword, sub_id_1..15, extra_param_1..10,
cost/currency, GeoDb IP-инфо, device-инфо, bot/proxy-флаги) — строится в
`BuildRawClickStage` (15 приватных подшагов, application/Traffic/Pipeline/
Stage/BuildRawClickStage.php), пишется в таблицу `clicks` (уже есть в
`backend/`, `2025_01_01_000018_create_clicks_table.php`, ~65 колонок,
1-в-1 с legacy `tds_clicks`).

Выбор стрима (`ChooseStreamStage`) идёт в три уровня: `TYPE_FORCED`
(позиционный), `TYPE_REGULAR` (`StreamRotator::chooseByWeight` — взвешенный
случайный выбор + фильтры `Component\StreamFilters\CheckFilters` + опция
"bind visitors" через Redis), `TYPE_DEFAULT` (первый попавшийся). Если у
стрима `schema` НЕ `landings`/`offers` — экшен (`action_type`/
`action_payload`) лежит прямо на самой строке `streams` (простейший
реальный случай, взят за основу Фазы 1). Если `schema` = `landings`/
`offers` — экшен строится через `ChooseLandingStage`/`ChooseOfferStage`
(ротация лендинга/оффера отдельным `LandingOfferRotator`).

Экшены (`ExecuteActionStage`) резолвятся по строковому ключу через
`Traffic\Actions\Repository\StreamActionRepository` (application/Traffic/
Actions/Repository/StreamActionRepository.php) — 18 зарегистрированных
типов: `http` (`HttpRedirect` — простой 302), `remote` (curl-запрос,
кеширование результата в файл, потом редирект), `local_file`, `curl`,
`frame`/`iframe`, `js`/`js_for_iframe`/`js_for_script`, `meta`/
`double_meta`, `show_html`/`show_text`, `campaign` (`ToCampaign` —
запускает рекурсию `Pipeline::_run()` через `forced_campaign_id`),
`sub_id`, `blank_referrer`, `formsubmit`, `status404`, `do_nothing`.

## Фаза 1 (реализовано в этом заходе)

Минимальный сквозной путь: `FindCampaignStage` → `ChooseStreamStage` →
`BuildRawClickStage` → `ExecuteActionStage` → `StoreRawClickStage`.

- Резолв кампании: по `?campaign=<alias>` ИЛИ по `Host`-заголовку через
  `domains.default_campaign_id` (оба пути реально существуют в legacy
  `FindCampaignStage`, просто упрощены — см. докблоки классов).
- Выбор стрима: только детерминированный случай "первый активный стрим
  без `schema` landings/offers, с action_type/action_payload прямо на
  строке" — тот самый простейший реальный ветвь легаси, не выдумка.
- Запись клика: реальный INSERT в существующую таблицу `clicks` (не
  тестовую, не новую) с NOT NULL колонками (`visitor_id`, `sub_id`,
  `datetime`, `campaign_id`, `source_id`, `referrer_id`) + `stream_id`.
- Экшен: только `http` (`HttpRedirect` 1-в-1, без `kversion`-ветки —
  всегда 302, ветка была нужна только для старых legacy JS-клиентов).

Стек: `composer.json` (`nyholm/psr7` + `nyholm/psr7-server`, PHP 8.2+),
`public/index.php` — единственная точка входа, без роутера (не нужен —
один сценарий), `src/Db.php` — сырой PDO singleton на `tds2-mysql:3306`/
БД `tds2` (env `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/
`DB_PASSWORD`, дефолты подходят для `deploy_default` docker-сети),
`src/Pipeline/*.php` — 5 классов-стадий + `Payload.php` (урезанный порт
legacy `Traffic\Pipeline\Payload`).

**Живой smoke-test пройден** (докер, `tds2-php-dev`, сеть
`deploy_default`, порт 8095): создана временная кампания+стрим (alias
`tc-phase1-test`, action_type=`http`), `curl` на `/?campaign=tc-phase1-test`
→ `302 Location: https://example.com/offer?src=tc-test`, реальная строка
появилась в `clicks` (проверено `SELECT`), `curl` на несуществующий алиас
→ честный `404 Campaign not found`. Тестовые данные удалены из dev-БД
после проверки.

## Фаза 2 (реализовано в этом заходе)

**Реальная ротация стримов** — порт `Traffic\Actions\StreamRotator`
(`StreamRotator.php`, новый класс) + воспроизведение трёхуровневой логики
легаси `ChooseStreamStage::process()`:
1. `type='forced'` — `chooseByPosition()` (по `position ASC`, первый
   прошедший `CheckFilters` побеждает).
2. `type='regular'` — `chooseByPosition()` если `campaigns.type ===
   'position'`, иначе (default) `chooseByWeight()` — честный порт
   `_rollDice()`: `shuffle()` + взвешенный `mt_rand`, при провале фильтра
   кандидат убирается и бросок повторяется на остатке.
3. `type='default'` — первый стрим этого типа, **без вызова `CheckFilters`
   вообще** — это не упрощение, легаси делает `$streams[0]` напрямую,
   минуя фильтры.

**`CheckFilters.php`** (новый класс) — честная trivial-pass ветка: 0
прикреплённых `stream_filters` → `isPass()` возвращает true (реальная
ветка легаси `if (empty($filters)) return true`). Если фильтры ЕСТЬ —
движок проверки 28 типов (гео/устройство/бот/referrer/schedule и т.д.) НЕ
портирован (завязан на ещё не портированные GeoDb/bot-detection), поэтому
**fail-open**: пропускает стрим, но громко — `error_log()` +
`X-Filters-Skipped: stream#N:count` заголовок в ответе. Это осознанный
видимый пробел, не баг.

**ОБНОВЛЕНО В ФАЗЕ 4** (см. секцию ниже) — этот пробел частично закрыт: 9
из 28 типов фильтров реализованы по-настоящему, fail-open теперь
пофильтрово (не на весь стрим), формат заголовка дополнен именем типа
(`stream#N:name1,name2`).

**НЕ портировано** (осталось из прежней формулировки, сейчас точнее):
visitor entity binding / sticky-стримы (`EntityBindingService`,
`isBindVisitorsEnabled()`, Redis-биндинг) — часть отдельного кластера
"Визитор/уникальность" ниже; ветка `schema=landings`/`offers` (см.
`ChooseLandingStage`/`ChooseOfferStage` ниже) — не тронута, `streamsByType()`
по-прежнему не устанавливает `actionType` для таких стримов.

**Verification** (Docker, `tds2-php-dev`, `deploy_default`, порт 8096,
фикстуры созданы и удалены вручную через прямой INSERT/DELETE): (a) forced
стрим побеждает regular независимо от position/weight — подтверждено; (b)
`campaigns.type='position'` с двумя regular-стримами — 5/5 запросов подряд
выбрали `position=1`, ни разу `position=2`; (c) `campaigns.type='weight'`
(default) с весами 1 и 99 — 80 запросов дали распределение 2/80 против
78/80 (оба стрима встретились, пропорция близка к ожидаемой); (d)
default-тип используется как fallback, когда forced/regular пусты —
подтверждено; (e) прикреплённый `stream_filters`-фильтр к forced-стриму —
стрим по-прежнему выбран (fail-open), в логе сервера и в
`X-Filters-Skipped`-заголовке видно пропущенную проверку.

## Фаза 3 (реализовано в этом заходе)

**Ротация лендингов/офферов** для стримов со `schema IN ('landings',
'offers')` — Фаза 2 такие стримы пропускала (`actionType` оставался
пустым). Порт `Traffic\Actions\LandingOfferRotator`
(`LandingOfferRotator.php`, новый класс, один код обслуживает и лендинги,
и офферы — параметризован именем целевой таблицы и FK-колонкой, как в
легаси один класс на оба случая через `bindingEntityType`): `getRandom()`
рекурсивно пропускает ассоциации, чей `landing_id`/`offer_id` резолвится
в отсутствующую или не-`active` строку; `_rollDice()` — легаси-алгоритм
дословно (shuffle, затем для каждого элемента `mt_rand(0, totalWeight +
share)`, `$selected` обновляется пока `totalWeight <= rand`, накопление
`totalWeight` по ходу; отклонить результат с `share === 0` или
`association.state === 'disabled'` и повторить на остатке) — это НЕ тот
же алгоритм, что у `StreamRotator`, скопирован как есть, а не
переосмыслен.

`ChooseLandingStage.php`/`ChooseOfferStage.php` (новые) — воспроизводят
реальную логику: обе стадии skip, если `stream.schema` не
`landings`/`offers`; `ChooseLandingStage` берёт ассоциации из
`stream_landing_associations` (только что построенных в `backend/`),
прогоняет через ротатор, при успехе ставит `actionType`/`actionPayload`/
`actionOptions` из лендинга + `payload.landingId`; `ChooseOfferStage`
пропускает выбор оффера, если лендинг уже выбран (легаси:
`isForceChooseOffer` дефолтно false — эквивалентно), иначе берёт
ассоциации из `stream_offer_associations`.

**ИСПРАВЛЕНИЕ (2026-08-29, после доп. проверки координатором) — предыдущая
версия этого пункта ошибочно называла прямой offer-редирект "сознательным
отклонением". Это НЕ отклонение — перепроверено чтением реального
`ClickDispatcher.php`.** `isForceRedirectOffer()` — не глобальный дефолт
`false`, а флаг, который каждый ДИСПЕТЧЕР (входная точка трафика)
выставляет сам при создании `Payload`. `Traffic\Dispatcher\
ClickDispatcher` — это ровно та входная точка (обычный клик по
трекинг-ссылке), которую traffic-core и переносит — конструирует `Payload`
с `["force_redirect_offer" => true]` БЕЗУСЛОВНО (см. код диспетчера). То
есть `ChooseOfferStage::process()` реально доходит до
`if ($payload->isForceRedirectOffer())` с `true` и ставит
`actionType`/`actionPayload`/`actionOptions` из оффера напрямую — именно
то, что порт и делает. Порт здесь 1-в-1, не отклонение.

Токен/JWT/`GatewayRedirectContext`-флоу (`needToken`, гейт
`isForceRedirectOffer` может быть `false`) реален, но принадлежит ДРУГИМ
входным точкам, которые traffic-core вообще не трогал: `ClickApiContext`/
`ClickApiDispatcher` (AJAX-эндпоинт клика — форсит true только если
версия клиента &lt; 3 или явный параметр), `KClientJSContext`,
`LandingOfferDispatcher` (внутренний ре-диспатч для случая, когда лендинг
уже показан и его JS `kclient.js`/`kclient.php` просит оффер отдельным
запросом позже — файлы уже отдаются статикой через CodePresets, но их
серверная AJAX-логика не портирована). Это отдельная, ещё не начатая
фаза (JS-based клиентский трекинг), а не пробел в уже сделанном.

`Payload.php` — добавлены `landingId`/`offerId`/`actionOptions`.
`BuildRawClickStage.php`/`StoreRawClickStage.php` — `clicks.landing_id`/
`offer_id` теперь пишутся реальными значениями (были не в списке колонок
вообще). `public/index.php` — `ChooseLandingStage`/`ChooseOfferStage`
вставлены между `ChooseStreamStage` и `BuildRawClickStage` (осознанный
порядок, не как в легаси — см. обновлённый докблок `index.php`: этому
урезанному `BuildRawClickStage` нужны уже выбранные landing/offer id для
одного INSERT, в легаси `BuildRawClickStage` идёт вообще первым в
конвейере).

**НЕ портировано**: entity binding/sticky-выбор (Redis, кластер
"Визитор/уникальность"), `forcedOfferId`/`forcedLandingId` (query-параметры
принудительного выбора), `ConversionCapacityService::findAvailableOffer()`
(дневной cap оффера — колонки `conversion_cap_enabled`/`daily_cap` есть в
`backend/`, рантайм-проверки нет), `needToken`/куки (весь токен-флоу).

**Verification** (Docker, `tds2-php-dev`, `deploy_default`, порт 8097,
фикстуры через прямой SQL, удалены после): (a) кампания → стрим
`schema=landings` + 2 лендинга (`share` 1 и 99, оба `action_type=http`) —
50 запросов, оба лендинга встретились, редирект соответствовал
`action_payload` выбранного лендинга каждый раз, `clicks.landing_id`
совпадал; (b) кампания → стрим `schema=offers` без лендингов + 1 оффер
(`action_type=http`) — прямой 302 на оффер, `clicks.offer_id` записан,
`clicks.landing_id` остался `NULL`; (c) лендинг с `state != 'active'` —
корректно пропущен ротатором, выбран второй кандидат.

## Фаза 4 (реальный движок StreamFilters — реализовано в этом заходе)

Реализован реальный движок для 9 из 28 типов фильтров (`FilterEngine.php`,
`src/Pipeline/Filters/`) — покрывают ~39 из 43 реально зарегистрированных
имён (`FilterRepository::loadFilters()`): `AnyParam` (один класс на
`source`/`x_requested_with`/`keyword`/`search_engine`/`ad_campaign_id`/
`creative_id`/`sub_id_1..15`/`extra_param_1..10`), `parameter`,
`referrer`, `empty_referrer`, `schedule`, `interval`, `ip`, `ipv_6`,
`user_agent`, `language`. `CheckFilters.php` переписан: раньше — "есть
хоть 1 фильтр → fail-open на ВЕСЬ стрим", теперь — fail-open ТОЛЬКО на
конкретный неподдержанный фильтр (видно в `X-Filters-Skipped:
stream#N:name1,name2`), остальные реально проверяются; AND/OR
комбинирование (`stream.filter_or`) перенесено точно.

**Новая инфраструктура, которой не было**: `Signal.php`/
`CaptureSignalStage.php` — реальное сигнальное сырьё запроса (IP из
`REMOTE_ADDR`, `Referer`, `User-Agent`, первый код языка из
`Accept-Language`, GET+POST параметры с приоритетом GET), которого в
traffic-core не было вообще (числилось как "NOT ported" в докблоке
`BuildRawClickStage` с Фазы 1). Работает только с `REMOTE_ADDR` —
X-Forwarded-For/прокси-цепочки сознательно не разбираются (доверие
прокси — отдельный нерешённый вопрос, тот же, что у `Proxy`-фильтра).

### Найденные и исправленные баги легаси (не архитектурные решения — реальные баги)

1. **`StreamFilterService::findInWithRegexSupport()`/`equalOrEmpty()`** —
   легаси кастит `$string`/`$pattern` в `(int)` ДО любого сравнения, что
   ломает любое нечисловое значение (referrer/UA/произвольный параметр →
   всегда `0`), сводя на нет весь wildcard/regex-матчинг чуть ниже по
   коду. Похоже на артефакт декомпиляции. Исправлено в `FilterMatcher.php`
   — убраны `(int)`-касты, остальная логика (спецзначения `@empty`/
   `Unknown`/`XX`, regex, wildcard `*`, регистронезависимое строгое/
   substring-сравнение) перенесена точно.
2. **`Tools::ipInCIDR()`** — обрезает IP по количеству СИМВОЛОВ вместо
   битовой маски (работает только на границе октета — `/8`,`/16`,`/24`,
   даёт неверный результат для `/12`,`/20`,`/27` и т.п.), плюс мёртвый код
   с неопределённой переменной `$v`. Исправлено в `IpMatcher::ipInCidr()`
   — корректная битовая маска через `ip2long()`. `ipInMask`/`ipInInterval`
   перенесены как есть — багов не найдено.
3. **`Filter\Ip::isPass()` — найдено по ходу переноса (сверх изначально
   указанных двух)**: цикл по токенам IP-маски использует `strtok()`, но
   переходит к следующему токену ТОЛЬКО в ветке "не совпало" — при
   совпадении `$tok` никогда не переприсваивается → бесконечный цикл
   (100% CPU) в момент первого же совпадения IP-фильтра. Исправлено —
   токенизация через `preg_split()`, каждый токен проверяется ровно один
   раз независимо от результата.

**Не фиксили (не входило в скоуп)**: у `AnyParam`/`Referrer`/`UserAgent`/
`Language` легаси возвращает неявный `NULL` (falsy), если ни одно
значение payload не совпало — из-за `return` внутри `foreach` при первом
совпадении. На практике это значит "нет совпадения → фильтр всегда
блокирует", даже в режиме `reject` (где логически должно быть наоборот).
Перенесено как есть (в отличие от `Parameter`/`Ip`, которые считают
`$found` по всему циклу и возвращают корректно) — это менее однозначный
случай (может быть осознанным поведением продукта, а не багом), не
трогали без явного запроса.

**`imklo_detect`/`hide_click_detect`** — при переносе подтверждено:
`HideClickDetect::isPass()` строит `$params`, но НИКОГДА не вызывает
`_sendRequest()` и не возвращает значение вообще — реальный мёртвый код в
легаси, не "функциональность недоступна". Оба фильтра также зависят от
внешних платных антифрод-сервисов (`hideapi.xyz`, IMKLO) — не портированы
ни по одной из двух причин.

**Verification** (Docker, `tds2-php-dev`, `deploy_default`, порт 8098,
фикстуры через прямой SQL, удалены после): (a) `referrer` с wildcard
`*.google.*` — блокирует без совпадающего `Referer`, пропускает с
`https://www.google.com/search`; (b) `ip` с CIDR `/20` на границе, НЕ
выровненной по октету (`169.150.240.0/20` против реального клиентского
IP `169.150.247.38`) — пропускает внутри диапазона, блокирует вне
(`169.150.0.0/20`) — доказывает, что бит-мэтчинг работает, а не старый
байтовый хак; (c) `source` (AnyParam) — блокирует без параметра и с
неверным значением, пропускает с `source=ads`; (d) `schedule` с
интервалом на день, не совпадающий с текущим (легаси-день Monday=0
против фактического Saturday=5) — блокирует; (e) `country` (отложенный
тип) — fail-open, `X-Filters-Skipped: stream#N:country`; регрессия:
стрим без фильтров по-прежнему проходит тривиально (не сломано Фазой 4).

## Фаза 5 (15 из 18 оставшихся типов экшенов — реализовано в этом заходе)

Реализованы реальные обработчики для 15 типов (`src/Pipeline/Actions/`,
общий интерфейс `ActionHandler`, dispatch-таблица в
`ExecuteActionStage::REGISTRY`): `blank_referrer`, `curl`, `do_nothing`,
`formsubmit`, `frame`, `iframe`, `js`, `js_for_iframe`, `js_for_script`,
`meta`, `remote`, `show_html`, `show_text`, `status404`, `sub_id`. `http`
не тронут (Фаза 1, работает как раньше).

**НАЙДЕН И ИСПРАВЛЕН реальный баг легаси, live-подтверждён** (не просто
вычитан статически — curl-тест против ЖИВОГО легаси-приложения, `tds-app`,
порт 8090, временная фикстура `campaign.alias='frmtest1'`, удалена после
проверки): `AbstractAction::_executeInContext()` (application/Traffic/
Actions/AbstractAction.php) — общий механизм переключения контекста
рендеринга (`frm`-параметр, добавляется только code-preset'ами embed-
интеграции, `Component/CampaignIntegration/data/code_presets.php`,
`add_params => "frm=script"`/`"frm=frame"`, НИКОГДА обычным кликом по
трекинг-ссылке) для 11 из типов действий (`blank_referrer`, `double_meta`,
`frame`, `iframe`, `js`, `js_for_iframe`, `js_for_script`, `meta`,
`remote`, `show_html`, `show_text`):

1. `_executeDefault()` — мёртвый код: строка не может одновременно
   начинаться и с `"script"`, и с `"frame"`, а именно это требуется, чтобы
   ветка с `_executeDefault()` выполнилась. **Live-подтверждено**: `frame`-
   и `js`-экшены на обычном клике (без `frm`) отдают ПУСТОЕ тело, статус
   200, в реальном работающем легаси.
2. Ветки `frm=script`/`frm=frame` перепутаны местами относительно имён
   методов. **Live-подтверждено** на `js`-экшене: `frm=frame` вызывает
   `_executeForScript()` (голая JS-функция без `<script>`-обёртки),
   `frm=script` вызывает `_executeForFrame()` (обёрнутый в `<script>`
   `top.location`-сниппет из `RedirectService::frameRedirect()`). Та же
   логика 1-в-1 продублирована в `Component\StreamActions\AbstractAction`
   (application/Traffic/BackCompatibility/classes/AbstractAction.php,
   back-compat база для пользовательских кастомных экшенов) — это
   устойчивое поведение приложения, не разовая опечатка.

**Исправлено в порте** (не воспроизведено как баг): нет `frm*`-параметра →
вызывается `executeDefault()` напрямую (именно то, что нужно обычному
клику по трекинг-ссылке); `frm` есть и начинается с `"script"` →
`executeForScript()`; иначе → `executeForFrame()` (без перестановки).
Поскольку traffic-core пока нигде не генерирует `frm`-параметры сам (embed/
JS-client флоу не портирован), доступна пока только первая ветка —
исправление второй сделано на будущее, when JS-client-флоу когда-нибудь
дойдёт очередь.

**Общий, не повторяемый по каждому классу пробел**: `processMacros()`
(`Traffic\Macros\MacrosProcessor`) нигде в traffic-core не портирован —
`action_payload`/контент отдаются как есть, без подстановки макросов
(`{sub_id_1}`, `{source_id}` и т.п.).

**`AdsParser`** (application/Traffic/Actions/AdsParser.php, `_cid`-
переписывание для async `<script>`-тегов) тоже не портирован — три класса
(`Meta`, `BlankReferrer`, `ShowHtml`) поэтому НЕ переопределяют
`executeForScript()`, честно откатываясь на общую заглушку
"incompatible" вместо непарсенного, вероятно битого вывода.

**Другие находки, перенесены как есть (не исправлены)**:
- `Iframe::executeForFrame()` — легаси ставит заголовок `Location` через
  `addHeader()` (НЕ `redirect()`) и форсит статус 302 только если есть
  `kversion`-параметр `>= "3.4"` — иначе ответ остаётся 200 с
  игнорируемым браузером `Location`-заголовком. Похоже на код для старого
  JS-клиента, читающего заголовки вручную, а не на баг с точки зрения
  этого пути (сам достижим только через `frm`, недостижим из обычного
  клика).
- `JsForScript::executeForFrame()` — `Content-Type: html/text` дословно
  (похоже на опечатку вместо `text/html`), перенесено буквально.
- `FormSubmit` — значения `getParsedBody()` подставляются в
  `value="..."` без экранирования, как и в легаси (сам смысл экшена —
  форвардить параметры как есть).

**`remote`-экшен**: честный порт (сырой PHP `curl_*`, как в легаси, а не
Guzzle из `Curl.php`/`CurlService`) — файловый кеш (`md5(url).link`, TTL
60с) + `_appendParams()`-слияние query-параметров дословно. Кеш-директория
— `CACHE_DIR` env (дефолт `<traffic-core>/var/cache`), не системный
`/var/cache` легаси (у traffic-core нет общей с легаси cache-инфры).

**`curl`-экшен**: честный порт `CurlService::request()`+`Curl::_execute()`
— реальный HTTP-фетч (PHP curl, без Guzzle), UA/Referer форвардинг,
base64 для image/pdf, `utf8ize()` дословно. Подтверждено чтением, что
`CurlService::adaptAnchors()`/`addBasePath()`/`adaptResourcePaths()`/
`adaptFormAction()` вызываются ТОЛЬКО из `Component\Landings\LocalFile\
PageWrapper` (рантайм `local_file`-экшена, см. ниже), а не из
`request()` — корректно не портированы для `curl`, это не пробел.

**Осознанно НЕ включено в эту партию (из исходных "17 мелких")**:
- **`double_meta`** — требует JWT/gateway-токен-флоу
  (`GenerateTokenStage`/`LpTokenService`/`GatewayRedirectContext`),
  отдельный уже отложенный кластер — портировать без него значило бы
  поставить заведомо нерабочий two-step редирект.
- **`local_file`** — требует `Component\Landings\LocalFile\PageWrapper`,
  РАНТАЙМ-движок раздачи файлов лендинга (не тот же самый Editor/Cleaner
  админский CRUD, что уже портирован) — включает выполнение PHP из
  загруженных файлов лендинга, отдельная security-чувствительная
  подсистема, заслуживающая отдельной сессии.

Итого: 15 портировано в этой Фазе + `http` (Фаза 1) = 16 из 19
реальных ключей репозитория (`campaign`/`group`-алиас портирован Фазой 6
ниже; `double_meta`/`local_file` — всё ещё 501, с явной причиной каждого
в докблоке `ExecuteActionStage`).

**Verification** (Docker, `tds2-php-dev`, `deploy_default`, порт 8099,
кампания `actiontest1` + один стрим, `action_type`/`action_payload`
переключались `UPDATE` перед каждым тестом, фикстуры удалены после):
все 15 типов — `do_nothing`/`status404`/`sub_id`(+jsonp)/`formsubmit`
(GET vs POST body) проверены напрямую; `frame`/`iframe`/`js`/
`js_for_iframe`/`js_for_script`/`meta`/`blank_referrer`/`show_html`/
`show_text` — обычный клик (пусто в легаси → реальный контент в порте,
это и есть фикс) плюс `js` дополнительно проверен на `frm=frame`/
`frm=script` (корректно НЕ перепутаны, в отличие от легаси); `curl` —
реальный фетч `https://httpbin.org/get`, тело/content-type совпали;
`remote` — реальный фетч `https://httpbin.org/base64/...` (base64 URL),
302 на раскодированный URL, второй запрос отдан из файлового кеша
(`.link`-файл подтверждён на диске). `php -l` без ошибок на всех новых
файлах.

## traffic-core — Фаза 6 (campaign/group-экшен, рекурсия пайплайна) — 2026-09-02

Портирован последний из трёх заблокированных экшенов, который был
блокирован НЕ инфраструктурой (в отличие от `double_meta`/`local_file`),
а просто отсутствием рекурсивного раннера. Прочитаны буквально
`Traffic\Pipeline\Pipeline::_run()`/`_preparePayloadForCampaign()`,
`Traffic\Pipeline\Stage\CheckSendingToAnotherCampaign`,
`Traffic\Actions\Predefined\ToCampaign` (application/Traffic/...).

Новое: `Payload::$forcedCampaignId`/`$parentCampaignId`;
`CampaignAction` (`src/Pipeline/Actions/`, no-op — мирроринг `ToCampaign::
_execute()`, который тоже ничего не делает с ответом, только лог);
`CheckSendingToAnotherCampaign` (новая стадия, ставит `forcedCampaignId`
и абортит, если `actionType IN ('campaign','group')` — `group` не
отдельный тип, а алиас `campaign` в легаси
`StreamActionRepository::alias("group","campaign")`, подтверждено
чтением); `PipelineRunner` (`src/Pipeline/PipelineRunner.php`) — заменил
плоский `foreach` в `public/index.php`, реализует цикл повторного
прогона всего списка стадий с `LIMIT=10` (буквально как легаси
`Pipeline::LIMIT`), сбрасывает `campaign/stream/landingId/offerId/
actionType/actionPayload/actionOptions/signal` перед каждым повтором.

**Отличие от легаси, документированное, не случайное**: легаси при
превышении лимита рекурсии бросает `StageException` ("makes infinite
recursion") ДО какого-либо ответа клиенту; здесь вместо этого
завершается ответом `508 Loop Detected` с телом-объяснением — легаси не
имеет HTTP-эквивалента (падает раньше), выбор `508` осознан (реальный,
применимый HTTP-статус для этого случая), не буквальный порт "throw".

**Не перенесено**: `parentSubId` (`RawClick::setParentSubId()`) — в
схеме `clicks` таблицы tds_v2 нет колонки `parent_sub_id` (проверено по
миграции), перенесён только `parent_campaign_id` (реальная колонка,
была неиспользуемой до этой Фазы). `forcedStreamId`-сброс тоже
пропущен — в traffic-core нет вообще forced-stream-id функциональности.

`FindCampaignStage` расширен: если `forcedCampaignId` установлен —
резолвит кампанию напрямую по `id` (не по alias/domain), минуя обычный
путь, и потребляет (обнуляет) флаг. `BuildRawClickStage`/
`StoreRawClickStage` теперь пишут `clicks.parent_campaign_id`.

**Verification** (Docker, `tds2-php-dev`, `deploy_default`, порт 8099,
три фикстурные кампании в `tds2` dev-БД — B: финальный `http`-редирект,
A: `campaign`→B, C: `campaign`→сама себя (само-луп), все удалены после):
(1) `?campaign=tc5-campA` → 302 на финальный URL кампании B, `clicks`
запись — `campaign_id=B`, `parent_campaign_id=A`, `stream_id=`B-стрим
(рекурсия реально проходит через `FindCampaignStage`→...→
`ExecuteActionStage` второй раз); (2) `?campaign=tc5-campC` (само-луп)
→ `508` за 47мс (10 итераций, не зависает, не превращается в
бесконечный цикл); (3) регрессия — прямой `?campaign=tc5-campB` (без
рекурсии) по-прежнему 302, `parent_campaign_id=NULL` в `clicks` (не
"утекает" из статики/предыдущего теста). `php -l` чисто на всех новых/
изменённых файлах.

Итого действий: 16 (Фаза 5) + `campaign`/`group` = 17 из 19 реальных
ключей репозитория. Осталось: `double_meta`, `local_file`.

## traffic-core — Фаза 7 (double_meta-экшен) — 2026-09-02

**Исправлена собственная ошибка координатора из Фазы 5/6**: `double_meta`
был записан как заблокированный тем же кластером, что и `campaign`
(`GenerateTokenStage`/`LpTokenService`/`GatewayRedirectContext`). При
реальном чтении обоих классов это оказалось неверно — `GenerateTokenStage`
принадлежит СОВСЕМ другому, несвязанному токен-флоу (двухшаговый
трекинг атрибуции офферов, `isTokenNeeded()`/TTL/UUID-хранилище в
Redis/etc — то, что реально отложено и остаётся отложенным). `double_meta`
использует из `LpTokenService` только один статический метод,
`generateUserKey()` = `hash("sha256", SALT) . $postfix` — не связан с
хранилищем/TTL вообще. Единственное, что реально требовалось —
JWT-библиотека и маленький принимающий endpoint. Урок: при повторной
оценке "заблокировано инфраструктурой X" — перечитать реальный код,
а не доверять прошлой оценке этой же сессии буквально.

Портировано: `Traffic\Actions\Predefined\DoubleMeta` →
`TrafficCore\Pipeline\Actions\DoubleMeta` (extends `AbstractAction`, как
`Meta`/`Frame`/etc — три ветки `executeDefault`/`executeForFrame`/
`executeForScript`, каждая строит один и тот же подписанный gateway-URL,
отличаясь только оберткой `RedirectService`). `LpTokenKey::
generateUserKey()` (`src/LpToken/LpTokenKey.php`) — SALT здесь НОВЫЙ
секрет для tds_v2 (`JWT_SALT` env, dev-фоллбек), не обязан совпадать с
легаси — токены double_meta никогда не покидают traffic-core (кодируются
и декодируются им же). `public/gateway.php` — новый второй
HTTP-entry-point (порт `Traffic\Dispatcher\GatewayRedirectDispatcher` +
`GatewayRedirectContext`), декодирует JWT ключом, зависящим от
User-Agent запроса, и отдаёт то же самое meta-refresh+JS HTML, что и
легаси `_code()` буквально. `firebase/php-jwt` `^7.1` добавлен в
`traffic-core/composer.json` (уже зависимость легаси-приложения;
проверен на Packagist перед установкой — официальный `googleapis/php-jwt`
преемник, BSD-3-Clause, без security advisories).

**Операционная находка про dev-сервер**: `php -S host:port -t public`
БЕЗ явного router-скрипта (в отличие от Фаз 1-6, которые всегда
запускали `... -t public public/index.php`) нужен, когда в `public/`
больше одного entry-point — со явным router-скриптом ВСЕ запросы (включая
`/gateway.php`) шли бы через `index.php`. Без router PHP built-in server
резолвит `/gateway.php` напрямую в файл (и `/`/`index.php` — через
дефолтный document, как обычный веб-сервер) — использовать этот вариант
запуска для любого будущего entry-point'а тоже (легаси-паттерн: несколько
плоских `.php`-файлов в корне, не единый front controller).

**НЕ изменено, буквальный порт легаси-поведения**: `_getGatewayBaseUrl()`
строит URL БЕЗ порта (`scheme://host/gateway.php`, порт отброшен и в
легаси `stripHostWww()`, и здесь) — корректно для продакшена (80/443
подразумеваются схемой), но означает, что построенный gateway-URL в
DEV-окружении на нестандартном порту (сейчас все тесты идут на 8099-8100)
технически указывает не туда — не баг порта, а унаследованное свойство,
подтверждённое чтением легаси-кода; для верификации URL порта
подставлялся вручную в curl.

Verification (Docker, `tds2-php-dev`, `deploy_default`, порт 8100, dev-сервер
БЕЗ router-скрипта, кампания `tc7-dm` + один стрим, `JWT_SALT` задан явно):
(1) обычный клик → `200`, тело — `metaRedirect()` на `/gateway.php?frm=dm&
token=<jwt>`; (2) переход по этому URL (порт подставлен вручную) с ТЕМ ЖЕ
`User-Agent` → `200`, тело — редирект-HTML на реальный финальный URL из
токена; (3) тот же токен с ДРУГИМ `User-Agent` → `400` (ключ зависит от UA
— подделать/переиспользовать чужой токен нельзя); (4) без `token` вовсе →
`500`; (5) `frm=script`/`frm=frame` — правильные (неперепутанные, см. Фазу
5) ветки `executeForScript`/`executeForFrame`, оба со своим gateway-URL;
(6) регрессия — переключение того же стрима на `do_nothing` по-прежнему
`200` без ошибок. Фикстуры удалены после. `php -l` чисто на всех новых/
изменённых файлах.

Итого действий на конец Фазы 7: 18 из 19 реальных ключей.

## traffic-core — Фаза 8 (local_file-экшен) — 2026-09-02

Портирован последний из 19 реальных `action_type`-ключей. Прочитаны
буквально `PageWrapper.php`, `LocalFileService.php`, `PageInfo.php`,
`Core\Sandbox\{Sandbox,SandboxContext,SandboxSubject,CgiExecutor\
CgiExecutor}`, `bin/execute_script.php`, `CurlService::{adaptAnchors,
addBasePath,adaptResourcePaths,adaptFormAction}` (application/Traffic/...,
application/Core/Sandbox/..., application/bin/execute_script.php).

**Storage-путь переиспользован, не заново придуман**: `backend/`'s уже
портированный `App\Services\LocalFileService` (Editor/Cleaner CRUD)
хранит файлы лендингов в `base_path($lpDir)` (`backend/<lp_dir настройка,
дефолт "lander">/<folder>`) и уже применяет полный набор
upload-time-проверок из легаси `Validator` (запрещённые файлы/функции/
charset) плюс собственную защиту от traversal (`resolveSafePath()`,
уже документированное улучшение над легаси). `LocalFileSandbox`
(traffic-core) вычисляет ТОТ ЖЕ физический путь (`<repo-root>/backend/
<lp_dir>`, override через `LANDINGS_STORAGE_PATH` env) — значит файл,
загруженный через уже готовую админку, сразу обслуживается тут без
доп. синхронизации.

**ОБНОВЛЕНО в этой же сессии, по прямому запросу пользователя ("хочу
полное тех-соответствие") — реальный `php-cgi`, не замена**: изначально
apt-путь (`php8.4-cgi` пакет) не работал в `tds2-php-dev` (Debian trixie
— `phpapi-*`-зависимость не удовлетворена, т.к. базовый образ собирает
PHP из исходников, а не через apt), и первая версия порта временно
использовала обычный CLI SAPI + свой JSON-протокол вместо CGI. По
запросу пользователя пересобрано на РЕАЛЬНЫЙ `php-cgi`: тем же исходным
деревом PHP, что уже используется для `pdo_mysql`/`pdo_sqlite`/`zip`
(`docker-php-source extract`, тот же приём, только с `--enable-cgi
--disable-cli --disable-phpdbg`), бинарник кладётся в
`/usr/local/bin/php-cgi` в `deploy/Dockerfile.dev-php` — теперь
постоянная часть образа. Тот же Zend/TSRM ABI, что и у `php` CLI SAPI —
`pdo_mysql`/`pdo_sqlite` и другие расширения грузятся в `php-cgi` без
отдельной пересборки (подтверждено `php-cgi -m`, живым тестом
`extension_loaded('pdo_mysql')` изнутри песочницы). `LocalFileSandbox`/
`bin/execute_local_file.php` переписаны на настоящий CGI-протокол:
`proc_open` c реальными CGI env-переменными (`REDIRECT_STATUS`/
`SCRIPT_FILENAME`/`REQUEST_METHOD`/`REMOTE_ADDR`/`CONTENT_LENGTH` —
точная копия набора из легаси `Sandbox::execute()`), урлкодированный
`params=<json>` в stdin (то же, что и легаси — `_getExecParams()` в
легаси тоже использует JSON, не только `serialize()`), сырой
CGI-ответ (`Status:`/заголовки/`\r\n\r\n`/тело) парсится в
`parseCgiOutput()` — 1-в-1 порт `Sandbox::_parseOutputToResponse()`.

**Две нетривиальные находки при переходе на реальный `php-cgi`**:
(1) `proc_open()`'s явный `$env`-параметр ПОЛНОСТЬЮ заменяет окружение
дочернего процесса (в отличие от Symfony Process у легаси, который
мержит) — пришлось явно смержить `getenv()` с CGI-плейсхолдерами,
иначе `php-cgi` терял `PATH`/остальное окружение; (2) `php-cgi`
отказывается принимать `SCRIPT_FILENAME` с `..`-сегментами пути
("No input file specified.", подтверждено вручную через `docker run
... php-cgi`) — путь нормализуется через `realpath()` перед передачей
в env.

**Security-хардening СВЕРХ легаси (осознанное усиление, не
расхождение)**: легаси у `Sandbox::execute()` вообще не ограничивает
рантайм исполняемого файла — вся защита только на этапе загрузки
(`Validator`). Порт добавляет `-d disable_functions=exec,shell_exec,
system,passthru,proc_open,popen,proc_close,pcntl_exec,pcntl_fork` и
`-d open_basedir=<папка_лендинга>:/tmp:<traffic-core>/bin` при каждом
запуске (третий путь в `open_basedir` — сам `bin/`, нужен потому что
CGI SAPI применяет `open_basedir` и к резолву `SCRIPT_FILENAME` тоже,
не только к файлам, которые открывает код лендинга). `REMOTE_ADDR ===
"127.127.127.127"`-гейт из легаси `execute_script.php` теперь портирован
буквально и реально работает (тот самый плейсхолдер-IP, что
`LocalFileSandbox` передаёт в CGI-окружение) — не оставлен как
неприменимый пережиток, каким казался в версии до перехода на реальный
`php-cgi`.

**НЕ портировано, задокументировано в `HtmlPathAdapter`'s докблоке**:
`addBasePath()` — легаси инжектит `<base href>` на РЕАЛЬНЫЙ маршрутизируемый
URL лендинга (`PageInfo::uri()`); в traffic-core нет path-based роутинга
лендингов вообще (`FindCampaignStage` резолвит только `?campaign=alias`
или домен-дефолт) — подставлять несуществующий URL было бы хуже, чем
честно пропустить. `processMacros()` — тот же общий пробел, что и у всех
остальных экшенов (см. докблок `ExecuteActionStage`), не повторяется.

Verification (Docker, `tds2-php-dev`, `deploy_default`, порт 8101,
`LANDINGS_STORAGE_PATH` указывал на смонтированную `backend/lander/`,
кампания `tc8-lf` + стрим `schema=landings` + лендинг `action_type=
local_file` + association): (1) реальный PHP-лендинг с `<?php ... ?>` —
выполнился в песочнице, `$_SERVER['REMOTE_ADDR']` и `$rawClick['sub_id']`
дошли до кода лендинга корректно, `adaptAnchors`/`adaptFormAction`
отработали на результате (`href="#x"` → onclick-паттерн, `action=""` →
`action="index.php"`); (2) `lp_allow_php=0` → тот же файл отдан как
raw-текст (PHP-теги не исполнены), как и предписывает
`_mustReadAsPlainText()`; (3) пустая папка (нет index-файла) → `502` с
точным легаси-текстом ошибки; (4) `folder` с `../../etc` (path traversal)
→ отклонено, `502`, наружу не вышло; (5) `lp_php_timeout=1` + лендинг с
`sleep(5)` → `504` за ~1с, не зависает на все 5; (6) лендинг с `system(
'id')` → вызов недоступен (`disable_functions` сработал,
`function_exists('system')` = false), `open_basedir` корректно ограничен
папкой лендинга + `/tmp`. Фикстуры (БД + файлы) удалены после. `php -l`
чисто на всех новых файлах.

**Весь набор тестов выше повторён ПОВТОРНО после перехода на реальный
`php-cgi`** (порт 8102, тот же набор фикстур, пересобранный образ
`tds2-php-dev`) — идентичный результат по всем 6 пунктам, плюс
дополнительно проверено `extension_loaded('pdo_mysql')` изнутри
песочницы → `loaded` (подтверждает общий ABI с основным `php` CLI SAPI).

**Итого: все 19 реальных `action_type`-ключей репозитория теперь
портированы в traffic-core, `local_file` — с полным техническим
соответствием легаси-движку исполнения (реальный `php-cgi`, не
замена).**

## traffic-core — Фаза 9 (GeoDb+визитор, токен-биндинг офферов) — 2026-09-02

Два независимых куска сделаны параллельно (2 фоновых агента), сведены в
единый пайплайн координатором одним заходом. Оба ранее были в списке
"осознанно отложено" ниже.

### GeoDb + визитор (реальный find-or-create вместо `random_int()`)

Ключевая находка (уже была зафиксирована в `PORTING_LOG.md` ранее в эту
сессию, здесь — реализована): гео/device/ISP-данные в легаси нормализованы
на ОТДЕЛЬНУЮ таблицу `visitors`, не на `clicks`. Новая миграция
`backend/database/migrations/2025_01_01_000029_...` (написана координатором
до запуска агентов, чтобы избежать конфликта параллельных правок схемы) —
`visitors` + 15 словарных `ref_*`-таблиц (`ref_ips`, `ref_user_agents`,
`ref_countries`, `ref_regions`, `ref_cities`, `ref_device_types`,
`ref_device_models`, `ref_languages`, `ref_browsers`,
`ref_browser_versions`, `ref_os`, `ref_os_versions`,
`ref_connection_types`, `ref_operators`, `ref_isp`) — идентичная легаси
схема, подтверждено `DESCRIBE` на живой легаси-БД.

Новое в traffic-core: `Pipeline\GeoDb\GeoDbResolver` (обёртка над
`IP2Location\Database`, читает `var/geoip/IP2LOCATION-LITE-DB3.BIN` —
единственный реально существующий geo-бинарник в проекте, не
закоммичен в git из-за размера, см. `var/geoip/README.md`);
`Pipeline\Device\DeviceInfoResolver` (обёртка над официальным
`matomo/device-detector`, тем же, что использует легаси, с портированными
правилами нормализации `_convertOs`/`_convertDeviceType`);
`Pipeline\Visitor\DictionaryRepository` (общий find-or-create по `value`
для любой `ref_*`-таблицы через `INSERT ... ON DUPLICATE KEY UPDATE id =
LAST_INSERT_ID(id)`); `Pipeline\Visitor\VisitorResolver` (считает
`visitor_code = hash('murmur3a', ip.ua.connection_type.country.city.
device_model)`, буквальный порт легаси `VisitorService::generateCode()`,
находит-или-создаёт визитора); новая стадия `Pipeline\ResolveVisitorStage`
— вставлена в `public/index.php` сразу после `CaptureSignalStage`, до
`FindCampaignStage`. `BuildRawClickStage` теперь пишет
`$payload->visitorId` вместо `random_int()`.

Зависимости: `ip2location/ip2location-php` (закреплён на `^8.2`, не
последний `^9.x` — тот требует `bcmath`, которого нет в
`tds2-php-dev`/не гарантирован и в легаси; версия 8.x — та же линия,
что и вендоренная в легаси, ноль дополнительных требований, чисто на
Packagist) и `matomo/device-detector` `^6.5`.

Честно задокументированные отклонения: `region` хранит сырое название
региона от IP2Location (`"California"`), не легаси-компактный код
`"US_CA"` — тот требует 247 файлов реверс-словарей по странам
(`application/Traffic/GeoDb/ip2location_reverse/*.php`), не портировано,
не в скоупе. `isp_id`/`operator_id`/`connection_type_id` всегда `NULL` —
LITE DB3 физически не содержит эти данные, других реальных
geo-бинарников в проекте нет вообще (Maxmind/Sypex/ProIP/Tds — только
код, без данных). IPv6/неразбираемые IP — `ref_ips` строка-сентинел `0`,
клик не теряется.

Verification: живой клик (реальный публичный IP хоста, Chrome UA) →
`visitors`-строка с корректными `country=US`/`city=San Francisco`
(живой IP2Location lookup, не синтетика) + `browser`/`os`/`device_type`
резолвлены; повторный клик с ТЕМ ЖЕ IP+UA переиспользует ТОТ ЖЕ
`visitor_id` (не создаёт новую строку) — критично для будущей
уникальности в отчётах; клик с ДРУГИМ UA (тот же IP) создаёт ОТДЕЛЬНОГО
визитора с верным `device_type=mobile`. Отдельно точечно проверен
`GeoDbResolver` на `8.8.8.8` (→ US/California/Mountain View) и graceful
null на приватных/невалидных IP. Фикстуры удалены.

**Follow-up, не сделано намеренно (агент сообщил координатору, а не
внёс сам — `CheckFilters.php`/`Filters/*` вне его скоупа)**: раз реальные
гео-данные теперь есть, тип фильтра `country` в `FilterEngine` можно
подключить по-настоящему вместо fail-open — отдельная небольшая задача
на будущее.

### Токен-биндинг офферов (`GenerateTokenStage` + Redis)

Порт легаси `GenerateTokenStage`/`LpTokenService::storeRawClick()` —
серверный lookup-токен: когда стрим выбирает оффер, клик сохраняется в
Redis по сгенерированному токену с TTL, для будущего (ещё не
портированного) постбек-колбэка. Не путать с уже сделанным JWT
`double_meta` — не связанные механизмы, подтверждено чтением обоих
классов.

**Условие сработки, честно адаптировано, не добавлением нового флага**:
легаси гейтит на `Payload::isTokenNeeded()`, выставляемый
`ChooseOfferStage` безусловно при выборе оффера. Порт условится
напрямую на `$payload->offerId !== null` — функционально идентично,
без правки `ChooseOfferStage`/`ChooseLandingStage` (файлы были вне
скоупа задачи, чтобы не пересекаться с параллельным GeoDb-агентом).

**`shouldAddTokenToURL()` — проверено чтением, не угадано: недостижимо
в смоделированном флоу.** `Payload::$_addTokenToUrl` в легаси по
умолчанию `NULL`; `ClickDispatcher::dispatch()` (флоу, который
моделирует traffic-core) никогда не вызывает `setAddTokenToUrl()` —
единственный вызов во всём легаси находится в
`ChooseLandingStage::_updatePayload()`, только на ветке "выбран
лендинг, а не оффер напрямую". В traffic-core структурно то же самое:
`ChooseOfferStage::process()` выходит рано, если `landingId !== null`
— значит гейт `offerId !== null` этой стадии никогда не пересекается с
веткой лендинга. Вывод: мутация URL (`_subid=`/`_token=` параметры) —
задокументированный no-op в этом порте, не реализована, не пробел.

Новое: `Redis\RedisClient` (синглтон-обёртка над `Predis\Client`, стиль
1-в-1 как `TrafficCore\Db`, `REDIS_HOST`/`REDIS_PORT` env), `LpToken\
LpTokenService` (`storeRawClick()`, TTL из `settings.lp_offer_token_ttl`
— значение в МИНУТАХ, как и в легаси, default 1440 = 24ч), новая стадия
`Pipeline\GenerateTokenStage` — вставлена в `public/index.php` после
`BuildRawClickStage`, перед `ExecuteActionStage` (нужен уже собранный
`$payload->rawClick`). Токен НЕ пишется в `$payload->rawClick` (это
сломало бы позиционный/именованный INSERT в `StoreRawClickStage` —
живьём подтверждено, что PDO бросает `SQLSTATE[HY093]: Invalid
parameter number` на лишнем ключе массива) — хранится в новом
`$payload->lookupToken` (пока ничем не потребляется, задел на будущее).

Зависимость: `predis/predis` `^3.0` (чистый PHP Redis-клиент, без
расширения — избегает пересборки образа).

Verification: фикстура кампания→стрим(`schema=offers`)→оффер, TTL
настройки временно = 2 мин → живой Redis-ключ `uuid_<subid>_...`
существует, TTL близко к 120с; клик БЕЗ оффера (schema=NULL,
action_type=http напрямую) → Redis остаётся пустым (негативный кейс
явно проверен, не пропущен). Совместный тест с Фазой GeoDb (один клик,
оба механизма разом) — `visitor_id` реальный, `offer_id` верный,
Redis-ключ создан с корректным TTL — все три подтверждены одним
запросом. Фикстуры удалены (БД + Redis).

Итого Фазы 9: 2 из ~6 оставшихся крупных кластеров закрыты. Осталось:
hit-limit/cost/payout в реальном времени, постбеки, альтернативные
входные точки (`ClickApiContext`/`KClientJSContext`/etc.), периферийные
стадии пайплайна (`DomainRedirectStage`/`CheckPrefetchStage`/etc.),
`processMacros()`, асинхронная запись клика, FCGI/php-fpm пул для
`local_file` (см. ниже — не критично, даже сам легаси-прод его не
использует).

## Осознанно отложено (следующие фазы, каждая — отдельная спланированная сессия)

- ~~`local_file`-экшен~~ — портирован Фазой 8 (см. выше). Все 19
  `action_type`-ключей теперь реализованы; ничего из этого списка не
  относится к экшенам самим по себе, только к периферийной инфраструктуре
  ниже.
- ~~**Визитор find-or-create + GeoDb/device**~~ — портировано Фазой 9
  (`ResolveVisitorStage`/`VisitorResolver`, реальный `visitors` +
  словари, IP2Location LITE DB3, `matomo/device-detector`). Всё ещё НЕ
  портировано из этого кластера: `SaveUniquenessSessionStage`/
  `UpdateStreamUniquenessSessionStage`/`UpdateCampaignUniquenessSessionStage`
  (уникальные клики per-stream/per-campaign/global для отчётов —
  отдельная механика поверх уже реального `visitor_id`), Redis-биндинг
  сущностей (`EntityBindingService` — sticky-выбор лендинга/оффера/
  стрима для одного визитора, часть `SetCookieStage`, которая целиком
  НЕ портирована — см. её докблок в легаси, там же uniqueness-cookie
  логика).
- **`BuildRawClickStage` — остальные 14 из 15 подшагов**: язык,
  referrer/se_referrer/keyword/search_engine, sub_id_1..15,
  extra_param_1..10, cost/currency, **GeoDb IP-резолвинг** (`source_id`/
  `referrer_id`/`search_engine_id`/`keyword_id` и т.д. — все словарные
  FK, сейчас всегда 0), device-инфо, **bot-детекция**
  (`Component\BotDetection\Service\UserBotListService` — Botlist уже
  портирован в `backend/` как админ-CRUD, а не как рантайм-проверка),
  proxy-детекция. Это отдельный большой кластер сам по себе (GeoDb-файлы
  ещё не портированы вообще, см. `docs/PORTING_LOG.md`).
- **`GenerateTokenStage`/`SetCookieStage`** — JWT-токены и куки для
  привязки визитора/двухшагового редиректа (`LpTokenService`) — нужны,
  чтобы `GatewayRedirectContext` вообще имело смысл портировать.
- **`UpdateHitLimitStage`/`UpdateCostsStage`/`UpdatePayoutStage`** —
  лимиты показов и расчёт cost/payout в реальном времени клика.
- **`DomainRedirectStage`/`CheckPrefetchStage`/`CheckParamAliasesStage`/
  `CheckDefaultCampaignStage`** — периферийные стадии основного пайплайна,
  не влияющие на "происходит ли редирект и пишется ли клик", отложены как
  наименее приоритетные для proof-of-concept.
- **Асинхронная запись клика** — legacy кладёт клик в отложенную команду
  (`Component\Clicks\DelayedCommand\AddClickCommand`) вместо синхронного
  INSERT, ради пропускной способности. Здесь INSERT синхронный — ок для
  Фазы 1, пересмотреть при реальной нагрузке.
- **Постбеки** (`PostbackContext`/`PostbackDispatcher`) — отдельный
  кластер, не часть click pipeline.
- **`ClickApiContext`, `KtrkContext`, `KClientJSContext`, `RobotsContext`,
  `PingDomainContext`, `UpdateTokensContext`** — альтернативные входные
  точки, не тронуты вообще.

---
*Обновляется по ходу переноса, как `docs/PORTING_LOG.md` — дописывать, не переписывать.*
