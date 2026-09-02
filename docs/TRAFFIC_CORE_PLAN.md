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

Итого Фазы 9: 2 из ~6 оставшихся крупных кластеров закрыты. Осталось на
момент конца Фазы 9: hit-limit/cost/payout, постбеки, альтернативные
входные точки, периферийные стадии, `processMacros()`, асинхронная
запись клика, FCGI/php-fpm пул для `local_file`.

## traffic-core — Фаза 10 (периферийные стадии + полный BuildRawClickStage) — 2026-09-02

3 из 4 периферийных стадий реализованы: `DomainRedirectStage` (форсит
схему по `domains.redirect` — значение колонки как строка "not"/"http"/
"https", подтверждено косвенно: `DomainsController.php`'s API-маппинг
`ssl_redirect = (redirect === "https")`, прямого чтения легаси-геттера
`getSSLRedirect()` не нашлось, задокументировано как вывод, не факт),
`CheckPrefetchStage` (буквальный порт — 3 заголовка + `version`+
`prefetch`-параметры, настройка `ingore_prefetch` — легаси-опечатка в
ключе сохранена намеренно), `CheckDefaultCampaignStage` (3 ветки —
редирект на кампанию через уже существующий `forcedCampaignId`+
`PipelineRunner`-механизм, редирект на фиксированный URL, честный 404 —
все три живьём проверены). Для этого `FindCampaignStage` перестал сам
404'ить при отсутствии кампании — теперь просто оставляет
`payload->campaign = null` и передаёт решение `CheckDefaultCampaignStage`
(1-в-1 как в легаси — тот тоже не 404'ит сам, `return $payload;` на
промахе).

**`CheckParamAliasesStage` — 4-й, реализован ПОЛНОСТЬЮ, а не отложен**:
глобальные алиасы через настройку `<param>_aliases` (список через
запятую) и алиасы/плейсхолдеры per-кампания через `campaigns.parameters`
(JSON), плюс `site` → `source`. Архитектурная адаптация (не урезание):
легаси мутирует уже частично собранный `RawClick` на месте, здесь
`BuildRawClickStage` строится ЗА ОДИН ПРОХОД — вместо этого стадия
пишет в новое `payload->resolvedParams`, которое `BuildRawClickStage`
проверяет ПЕРЕД параметрами запроса для каждого алиасируемого поля.

**Это разблокировало полный `BuildRawClickStage`** (было: только 8
базовых полей + визитор/гео из Фазы 9). Теперь резолвятся:
referrer/source/se_referrer/search_engine/x_requested_with/keyword/
cost/ad_campaign_id/creative_id/external_id/landing_id-через-`lp_id`,
15×sub_id, 10×extra_param — через уже существующие `ref_*`-словари
(источники field-словарей были частично созданы ещё в
`2025_01_01_000017_...` миграции для admin-CRUD; добавлена одна новая —
`ref_sub_ids`, миграция `000030`, была пропущена в исходном батче).
Переиспользован `Pipeline\Visitor\DictionaryRepository` (написан
GeoDb-агентом Фазы 9) — просто расширен whitelist таблиц, не
задублирован класс. НЕ портировано: `language`/`currency` — у `clicks`
в этой схеме вообще нет таких колонок (подтверждено `DESCRIBE`, не
пробел порта — сам источник данных отсутствует); поиск ключевого слова
из referrer через паттерны поисковиков (`ReferrerParserService`) — нужна
отдельная база паттернов, вне скоупа; бот/прокси-детекция — отдельные
уже отложенные кластеры.

**Реальный баг, найден живым тестом, не гипотетический**: `clicks.
source_id`/`referrer_id` — единственные из всех новых полей `NOT NULL`
колонки (все остальные новые FK — nullable). Клик без реферера ->
`PDOException: SQLSTATE[23000]... Column 'referrer_id' cannot be null`.
Исправлено — `?? 0` fallback именно для этих двух полей (0 — сентинел
"не резолвлено", словарные id всегда начинаются с 1, коллизий нет).

`StoreRawClickStage` переписан на динамический список колонок из
`array_keys($payload->rawClick)` вместо ручного перечисления — теперь
35 полей, дальше расти будет само.

Verification: живые тесты — алиас через `campaigns.parameters`
(`?kw=` → `keyword`), алиас через глобальную настройку
(`?utm_source=` → `source` при `source_aliases=utm_source`), прямые
параметры (`sub_id_1`/`extra_param_1`/`cost`/`ad_campaign_id`) — все
резолвлены в правильные `ref_*`-строки; регрессия — обычный клик без
доп. параметров по-прежнему работает (`0`/`NULL` на своих местах, не
падает); `CheckDefaultCampaignStage` все 3 ветки; `CheckPrefetchStage`
on/off; `DomainRedirectStage` реальный 301 на https. Фикстуры удалены.
`php -l` чисто.

## traffic-core — Фаза 11 (постбеки, hit-limit/cost/payout) — 2026-09-02

Два независимых куска, снова параллельно 2 агентами, снова сведены
координатором одним заходом (без конфликтов файлов — оговорено заранее:
hit-limit-агент единолично владел `public/index.php`/`FilterEngine.php`/
`CheckFilters.php`, постбек-агент — только новыми файлами).

### Постбеки (входящие + исходящие S2S)

Новый вход `public/postback.php` — валидация секретного ключа
(`settings.postback_key`, без легаси-фолбэка на `md5(SALT)` — своего
глобального SALT в traffic-core нет; если ключ не задан, эндпоинт
считается выключенным, не подделывает секрет), парсинг постбека
(`Postback`-класс — sub_id/tid/status/revenue/cost/datetime по спискам
альтернативных имён параметров, буквальный порт `Component\Postback\
Postback`), поиск клика по `sub_id`, find-or-update конверсии (дедуп
упрощён до одной конверсии на `sub_id` — `clicks.sub_id` уникален, что
реализует запрошенный "тот же tid → апдейт на месте" как основной
случай), апдейт `clicks.is_lead/is_sale/is_rejected`+revenue, затем
best-effort исходящий постбек (traffic source + новая таблица
`campaign_postbacks`, миграция `000031`) с минимальной макро-заменой
(`{sub_id}`/`{status}`/`{tid}`/`{cost}`/`{revenue}` — не полный
`MacrosProcessor`) через `curl` напрямую (тот же паттерн, что уже
использует `Remote.php`-экшен, без Guzzle).

**Реальный баг легаси найден и НЕ воспроизведён**: в оригинальном
`PostbackDispatcher::dispatch()` success/`PostbackError`-ветки никогда
не вызывают `_updateBody()` (падают с конца метода без return) — то
есть `?return=jsonp`/`?return=gif` физически недостижимы в реальном
легаси-коде. Порт всегда строит ответ из `$message`/`?return=`, поэтому
все три формата (`jsonp`/`gif`-пиксель/текст) у нас реально работают.

`postback_statuses`/`campaign_postbacks.statuses` — формат подтверждён
чтением реального `TrafficSourcesController::decodeJsonField()` (не
угадан): JSON-массив строк (`["sale","lead","rejected"]`), с
толерантным fallback на comma-separated для руками написанных строк.

Verification: sale→rejected той же связки sub_id+tid обновил ОДНУ и ту
же строку `conversions` (не создал вторую); неверный ключ → 403, без
конверсии; несуществующий sub_id → явная ошибка; `?return=gif`/`jsonp`
оба отработали; исходящий S2S на `httpbin.org` подтверждён — макро-замена
видна в эхо-ответе. Фикстуры удалены.

### Hit-limit / cost / payout

`UpdateHitLimitStage`+`HitLimitService` (Redis sorted set `rate:
<stream_id>`, `ZCOUNT` по диапазонам timestamp — буквальный порт
`RedisStorage`, `prune()` и мёртвый `rate_collection` SET сознательно не
портированы) — теперь пишет реальные хиты. Тип фильтра `limit` в
`FilterEngine`/`CheckFilters` стал РЕАЛЬНЫМ (был fail-open с Фазы 4) —
`evaluate()` получил новый обязательный параметр `$streamId` (единственный
call-site обновлён, подтверждено `grep`), буквальный порт `Filter\Limit`
включая странный, но легаси-точный edge-case: все три порога заданы, но
пустые → блокирует всё.

`UpdateCostsStage`/`UpdatePayoutStage` — новые стадии, вставлены после
`GenerateTokenStage`, до `ExecuteActionStage` (совпадает с реальным
относительным порядком в легаси). Payout (CPC-офферы, не auto) отработал
верно и подтверждён живьём.

**Крупная находка по cost, не гипотеза — воспроизведена и
изолирована**: в РЕАЛЬНОМ легаси-коде `UpdateCostsStage` cost
применяется, только если `isCostPerAcquisition()||isCostPerSale()||
isCostRevShare()` **И** `rawClick->isUniqueCampaign()` — а
per-unique/CPM/CPC-подветка внутри этого же условия физически
недостижима (`cost_type` — один скаляр, не может одновременно быть и
CPA/CPS/RevShare, и CPUC/CPUV). В traffic-core `isUniqueCampaign()`
пока всегда `false` (per-campaign uniqueness ещё не портирована — Фаза
9 сделала только сам визитор) — значит **cost прямо сейчас не
применяется вообще, ни для одного `cost_type`** — задокументированное,
временное, ожидаемое ограничение, не баг порта. Агент независимо
подтвердил, что арифметика (traffic_loss, megapush-патч) верна —
временно переключил `isUniqueCampaign()` на `true`, прогнал тесты
(traffic_loss корректно даёт `2.5/(1-0.2)=3.125`), затем откатил и
перепроверил `php -l`.

Verification: hit-limit off-by-one — 2 клика проходят, 3-й блокируется,
`ZCARD rate:<id>` остаётся `2` (не 3) — счётчик 3-го клика НЕ
инкрементируется; `per_hour`/`per_day` пороги проверены независимо;
payout подтверждён (`is_sale=1`, `sale_revenue` из `payout_value`).
Фикстуры (БД + Redis-ключи) удалены.

### Совместный тест координатора (оба куска + Фазы 9/10 разом)

Один клик через полный пайплайн: реальный `visitor_id`, оффер выбран,
payout применился (`is_sale=1`, `sale_revenue=3.00` от CPC-оффера) →
302 на цель. Постбек по тому же `sub_id` с `revenue=15.50` создал
`conversions`-строку и переписал `clicks.sale_revenue` на 15.50
(постбек — источник истины поверх payout-оценки, ожидаемо). Фикстуры
удалены.

**Итого Фазы 11: постбеки и hit-limit/payout закрыты. Cost временно
неактивен (см. находку выше) до портирования per-campaign uniqueness.**

## traffic-core — Фаза 12 (визитор-уникальность, разблокировала cost) — 2026-09-02

Портированы `UpdateCampaignUniquenessSessionStage`+
`UpdateStreamUniquenessSessionStage`+`SaveUniquenessSessionStage`
(слиты в одну стадию `UpdateUniquenessStage` — тот же паттерн адаптации,
что и `CheckParamAliasesStage`: traffic-core строит `rawClick` за один
проход, а не мутирует общий объект по многим стадиям).

Архитектурное упрощение (не урезание корректности): легаси хранит один
JSON-блоб на uniqueness-id (`campaigns[id]=ts`/`streams[id]=ts`/`time=ts`)
в куках И/ИЛИ Redis/MySQL (`_getSessions()` требует согласия ОБОИХ
хранилищ). Порт использует ТОЛЬКО server-side Redis — `EXISTS`+`SETEX`
на отдельный ключ на каждое измерение (`uniq:campaign:<id>:<hash>` и
т.д.), TTL = `campaign.cookies_ttl` часов, тот же идиоматичный паттерн,
что уже даёт `HitLimitService`/`LpTokenService`. Uniqueness-id —
буквальный порт `getUniquenessId()`: `md5(ip . (uniqueness_method !==
'ip' ? ua : ''))`. НЕ портировано: cookie-хранилище (нет established
инфраструктуры записи response-куки в traffic-core), "deprecated"
murmurhash3-fallback id (не с чем быть обратно совместимым).

**Разблокировала Finding #2 из Фазы 11**: `UpdateCostsStage`'s
`isUniqueCampaign()`-заглушка (`return false`) заменена на чтение
реального `payload->rawClick['is_unique_campaign']`, которое теперь
пишет `UpdateUniquenessStage` (вставлена в пайплайн раньше). Cost
реально применяется для CPA/CPS/RevShare-кампаний на первом хите
визитора в окне `cookies_ttl`.

Verification: campaign `cost_type=CPA`, `cost_value=2.50`,
`cookies_ttl=24` — первый клик (IP+UA) → `is_unique_campaign/stream/
global=1`, `cost=2.50`; второй клик тем же IP+UA → все три `0`,
`cost=0.000000` (совпадает с ожиданием — cost реально не применяется
повторно); третий клик с ДРУГИМ UA, тем же IP (`uniqueness_method=
ip_ua`) → снова unique=1, cost=2.50. Фикстуры (БД + Redis-ключи)
удалены.

## traffic-core — Фаза 13 (Redis-биндинг сущностей, sticky стрим/лендинг/оффер) — 2026-09-02

Последний кусок кластера визитор/уникальность. Порт легаси
`EntityBindingService` (`application/Traffic/Pipeline/Service/
EntityBindingService.php`) — упрощение до только-Redis (легаси
дополнительно проверяет "deprecated" murmurhash3-id и подписанную куку,
и параллельно ПИШЕТ и в Redis, и в куку через ещё не портированный
`SetCookieStage` — та же логика упрощения, что и в Фазе 12). Uniqueness-id
переиспользован из `UniquenessService::uniquenessId()` (сделан
`public static` для этого) — тот же самый id, что и у самой
уникальности, ровно как в легаси (`EntityBindingService` тоже вызывает
`UniquenessSessionService::getUniquenessId()`).

Вписано в существующие ротаторы, не в вызывающие стадии — `StreamRotator`
и `LandingOfferRotator` теперь принимают опциональные `campaign`/`signal`
и сами решают, когда биндинг включён: `Campaign::isBindVisitorsEnabled()`
(`type='weight'` И непустой `bind_visitors`) для стрима,
`isBindVisitorsLandingEnabled()`/`isBindVisitorsOfferEnabled()`
(кумулятивный gate по длине строки `bind_visitors` — 2+ символа для
лендинга, 3+ для оффера) для лендинга/оффера. `ChooseStreamStage`/
`ChooseLandingStage`/`ChooseOfferStage` не знают о биндинге вообще —
просто передают `$payload->campaign`/`$payload->signal` дальше.

**Буквальный легаси-квирк, перенесён как есть, не исправлен**:
`StreamRotator::_findBoundStream()` в легаси НЕ перепроверяет
`CheckFilters` на забинденном стриме — просто ищет по id в списке
кандидатов. Значит биндинг визитора переживёт даже смену фильтров
стрима. `LandingOfferRotator`, в отличие от этого, ВСЕГДА
перепроверяет `state=active` через реальный lookup сущности (легаси:
`_getEntityFromAssociation()` тоже идёт через репозиторий, не
голый id-матч) — разное поведение двух ротаторов у легаси, не унификация
задним числом.

Verification: кампания `bind_visitors='1'` + 2 стрима с равным весом
(50/50) — 5 кликов ОДНОГО визитора (тот же IP+UA) → все 5 попали в один
и тот же стрим (не перебрасывало); другой визитор (другой UA) —
независимо получил свой (возможно другой) стрим, подтверждая, что
первый выбор действительно случайный, а не захардкожен. Аналогично для
оффера (`bind_visitors='123'`, 2 оффера 50/50) — 5/5 кликов одного
визитора попали в один и тот же оффер. Регрессия — кампания БЕЗ
`bind_visitors` по-прежнему работает без биндинга. Фикстуры (БД +
Redis) удалены.

**Кластер визитор/уникальность полностью закрыт этой Фазой** (визитор
find-or-create — Фаза 9; per-stream/campaign/global уникальность —
Фаза 12; sticky-биндинг — Фаза 13). Cookie-хранилище (параллельно
Redis, как резервный источник в легаси) сознательно не портировано ни
в одной из трёх фаз — нет established инфраструктуры записи
response-куки в traffic-core.

## traffic-core — Фаза 14 (processMacros — реальная подстановка макросов) — 2026-09-02

Реальная находка при чтении: легаси применяет `processMacros()` НЕ
по одному разу в каждом из 15+ классов экшенов, а ЦЕНТРАЛЬНО — через
универсальный аксессор `AbstractAction::getActionPayload()`
(application/Traffic/Actions/AbstractAction.php:55 — `return
$this->processMacros($this->getRawActionPayload());`), которым
пользуются почти все экшены. Порт делает то же самое одним местом —
`ExecuteActionStage::process()` подставляет макросы в
`payload->actionPayload` ОДИН раз перед диспетчеризацией, а не в
15 отдельных файлах — на деле оказалось не "трогает 15+ файлов", а
"1 центральная точка + 3 точки для содержимого, которое не идёт через
`actionPayload`" (`Curl.php` — тело реального HTTP-фетча, `LocalFile.php`
— контент отданной страницы, `OutboundPostbackService` — исходящий
S2S-URL, апгрейд с временной 5-строчной замены на реальный движок).

**Осознанное исключение**: `campaign`/`group` НЕ подставляются —
подтверждено чтением `ToCampaign::_execute()`, он читает
`getRawActionPayload()` (сырой, БЕЗ макро-подстановки — это числовой id
кампании, не контент), подстановка сломала бы `(int)`-каст в
`CheckSendingToAnotherCampaign`, идущий следом.

Архитектурное разделение (не урезание): движок подстановки
(`TrafficCore\Macros\MacrosProcessor` — парсинг `{name:args}`/`{_name}`/
`$name`/`$_name`, urlencode если не raw-режим) отделён от источника
данных. `ClickMacroValues::forPayload()` строит карту макро-значений
для клик-контекста (стрим/оффер/лендинг/GeoDb/device/rawClick),
`OutboundPostbackService` строит свою — маленькую, конверсионную
(`sub_id`/`status`/`tid`/`cost`/`revenue`/`profit`) — тем же движком.
Легаси вместо этого держит ~30 отдельных классов-макросов в реестре;
здесь один класс-строитель карты вместо 30 почти одинаковых классов
(тот же паттерн упрощения, что и `FilterEngine` для типов фильтров).

**Реальный, не гипотетический пробел, закрытый попутно**: чтобы
`{sub_id_1}` разворачивался в РЕАЛЬНОЕ отправленное значение, а не в
opaque словарный id, `BuildRawClickStage` теперь параллельно с
`rawClick` (словарные FK для INSERT) заполняет новое
`payload->clickFields` (сырые строки) — то же самое разделение
"raw getter vs dictionary-resolving serialize()", что и в легаси
`RawClick`.

Портировано (реальные данные есть): `sub_id`/`subid`, `sub_id_1..15`,
`extra_param_1..10`, `source`, `referrer`, `search_engine`, `keyword`,
`ad_campaign_id`/`creative_id`/`external_id`, `x_requested_with`,
`cost`/`revenue`/`profit`, `campaign_id`/`campaign_name`/`stream_id`/
`landing_id`/`offer_id`/`parent_campaign_id` (+ `tds_`-алиасы),
`country`/`region`/`city`/`device_type`/`device_model`/`browser(_version)`/
`os(_version)` (из Фазы 9's GeoDb/device), `ip`/`user_agent`/`language`,
`current_domain`, `date`, `random`, `token` (Redis lookup-токен из Фазы
9), `currency` (настройка), `debug`.

НЕ портировано, с причиной: языковые варианты `{country:ru}` и т.п. —
аргумент принимается, но игнорируется (нет словарей перевода);
`isp`/`operator`/`carrier`/`connection_type` — всегда `""` (нет данных,
LITE-тариф); `is_bot`/`is_using_proxy` — всегда `"0"` (нет
детекции); `visitor_code`/`destination` — не экспонированы на Payload
ни одной стадией; `from_file`/`sample`/кастомные (admin-определённые
через PHP-код) макросы — тот же класс риска, что `local_file`,
сознательно вне скоупа; конверсионные макросы за пределами уже
поддержанных 7 в постбеках (`original_status`/`conversion_time` и
т.д.) — нет модели `Conversion`; `alwaysRaw()`-переопределение
конкретных макросов — используй `{_name}`-префикс вручную для того же
эффекта; `_addParamsFromCampaign()` — не нужен отдельно,
`CheckParamAliasesStage` уже резолвит `campaigns.parameters`-алиасы в
`payload->resolvedParams` раньше в пайплайне.

Verification: живой клик с URL, содержащим `{sub_id}`/`{campaign_name}`/
`{country}`/`$campaign_id`/`{source}` — все подставились верно
(`country=US` — реальный GeoDb-лукап по публичному IP хоста, не
синтетика); raw-режим (`{_campaign_name}`) сохранил пробелы буквально,
обычный режим (`{campaign_name}`) заменил их на `+`; регрессия —
`campaign`-экшен (рекурсия в другую кампанию) по-прежнему работает
корректно, макро-подстановка не сломала `(int)`-каст числового id.
Фикстуры (БД + Redis) удалены. `php -l` чисто.

## traffic-core — Фаза 15 (асинхронная запись клика) — 2026-09-02

`StoreRawClickStage` больше не пишет в `clicks` синхронно — теперь
только `RPUSH` в Redis-очередь (`TrafficCore\Queue\ClickQueue`,
буквальный порт легаси `Traffic\CommandQueue\QueueStorage\RedisStorage` —
тот же `RPUSH`/атомарный `LRANGE`+`LTRIM`-pipeline вместо `LPOP` в
цикле, тот же `RANGE_SIZE`=1000). Реальный INSERT переехал в отдельный
воркер-процесс — `bin/process_click_queue.php`, порт легаси
`ProcessCommandQueue`+`AddClickCommand::process()`, доведённый до
одного типа команды (`add_click`) вместо универсального
command-dispatch слоя (в traffic-core нет других отложенных команд).

Группировка батча при вставке — буквальный порт алгоритма
`Core\Db\Db::multiInsert()` (прочитан заново в этой сессии): если в
одном попапнутом батче встречаются строки с РАЗНЫМ набором ключей
(в этом проекте такого не бывает — `BuildRawClickStage` всегда
производит одни и те же 35 полей, но легаси на это рассчитан), группа
флашится одним multi-row INSERT и начинается новая — не падает и не
молча теряет колонки.

Новый сервис в `deploy/docker-compose.yml` — `traffic-core-worker`
(profile `worker`, не запускается по умолчанию с обычным `docker
compose up`) — `tds2-php-dev` образ, `php bin/process_click_queue.php`
в вечном цикле (poll раз в секунду при пустой очереди). НЕ портировано:
gzip-компрессия очереди, "дополнительная Redis-очередь"
(multi-tenant-фича легаси), retry/dead-letter (упавший батч в порте
просто логируется и теряется — как и у легаси для `add_click`
конкретно, `CommandAggregator::flushAll()` без своего retry).

Verification: 3 клика подряд → `LLEN click_queue`=3, `clicks`=0 строк
(подтверждена реальная асинхронность, не мгновенная вставка); запуск
воркера → лог "inserted 3 click(s)", очередь опустела, 3 строки
появились в `clicks` с верными `campaign_id`/`stream_id`; клик, отправленный
ПОКА воркер уже работает — подхвачен и вставлен на следующем
poll-цикле без перезапуска воркера. Фикстуры удалены. `php -l` чисто.

## traffic-core — Фаза 16 (FCGI/php-fpm пул для local_file) — 2026-09-02

Полная параллель легаси `SandboxFactory::create()` — тот предпочитает
пул FastCGI-воркеров (`FcgiExecutor`/`cgi-fcgi -bind -connect <сокет>`),
когда он реально доступен, и только иначе падает на per-request
`php-cgi` (`CgiExecutor`, наш уже сделанный путь Фазы 8). Порт делает то
же самое: `LocalFileSandbox::execute()` сначала проверяет
`PHP_FPM_HOST`/`PHP_FPM_PORT` (реальным `fsockopen`-пробником, не просто
наличием переменной окружения — 1-в-1 как легаси `FcgiExecutor::
isAvailable()` реально проверяет файл unix-сокета, а не доверяет
конфигу), и если пул жив — идёт через `cgi-fcgi`, иначе — молча падает
на уже существующий CGI-путь. Публичный интерфейс класса не поменялся.

**`php-fpm` собран из исходников тем же приёмом, что и `php-cgi`
(Фаза 8)** — ОДИН проход `./configure --enable-cgi --enable-fpm` +
`make cgi fpm` производит оба бинарника разом (не два отдельных
билда). `cgi-fcgi`-мост — из пакета `libfcgi-bin`, который, в отличие
от `php8.4-cgi`/`php8.4-fpm` .deb, ставится через apt БЕЗ проблем
(обычная C-библиотека, без привязки к PHP ABI).

Новый `deploy/php-fpm-local-file-pool.conf` (профиль `fpm` в
`docker-compose.yml`, не в дефолтном `up`).

**Хардненинг-компромисс, найден и честно задокументирован, не
скрыт**: CGI-путь передаёт `disable_functions`/`open_basedir` через
`-d`-флаги НА КАЖДЫЙ запрос, сужая `open_basedir` до КОНКРЕТНОЙ папки
текущего лендинга (процесс свежий каждый раз). Общий FPM-пул не может
принять per-request `-d`-оверрайды через `cgi-fcgi` (это просто
протокольный мост, не процесс, который вызывающий код контролирует) —
`open_basedir` пула статичен на ВЕСЬ landings storage root, не на
конкретную папку — лендинг через пул технически МОЖЕТ прочитать файлы
ДРУГОГО лендинга (чего CGI-путь не допускает). Всё равно строго лучше
легаси (тот не хардненит вообще ни один из путей).

**Второй, более важный пробел — найден живым тестом, не гипотеза**:
клиентский таймаут (`proc_terminate` над `cgi-fcgi`-процессом-мостом)
рвёт связь с ВИЗИТОРОМ вовремя, но НЕ останавливает воркер пула,
который продолжает выполнять запрос — PHP не проверяет обрыв
соединения клиента во время блокирующего `sleep()`. Живьём
подтверждено: лендинг с `sleep(5)` и `lp_php_timeout=1` отдаёт
визитору "Timed out" за ~1с, но воркер пула сам по себе продолжал бы
спать все 5с. Исправлено добавлением `request_terminate_timeout` — это
СТАТИЧНЫЙ потолок на уровне пула (php-fpm сам шлёт SIGTERM/SIGKILL
воркеру, превысившему лимит), независимый от динамической, БД-настройки
`lp_php_timeout` (по той же причине, по которой `open_basedir` нельзя
пропихнуть per-request в уже работающий пул). Подтверждено живьём с
временным `request_terminate_timeout=2s`: лог `php-fpm.log`
(`/usr/local/var/log/php-fpm.log` — НЕ stdout/stderr контейнера, лежит
в файле) показал `"execution timed out (2.049242 sec), terminating"` +
`"child N exited on signal 15 (SIGTERM)"`, воркер реально убит, новый
поднят автоматически. В закоммиченном конфиге — `30s` (щедрый
статичный потолок поверх любого реального `lp_php_timeout`).

Verification: (1) полный путь `LocalFile`-экшен → `LocalFileSandbox` →
`cgi-fcgi` → живой пул — подтверждён (не заглушка): `sub_id`
резолвился верно, `open_basedir=/landings:/tmp:/app/bin` (форма пула,
на весь root) и `system_disabled=YES`; (2) fallback на CGI при
остановленном пуле — подтверждён по РАЗНОЙ форме `open_basedir`
(`/landings/tc16-test:/tmp:/app/bin` — форма CGI-пути, на конкретную
папку) — оба пути реально разные, не один и тот же код в двух
обёртках; (3) кросс-контейнерный FastCGI по TCP — подтверждён
(вызывающий контейнер без смонтированных `/landings` получил ответ,
файл читал ИМЕННО контейнер пула); (4) `request_terminate_timeout`
реально убивает зависший воркер — подтверждено логом. Все тестовые
контейнеры остановлены, фикстуры (БД + файлы) удалены. `php -l` чисто.

## Осознанно отложено (следующие фазы, каждая — отдельная спланированная сессия)

- ~~`local_file`-экшен~~ — портирован Фазой 8 (см. выше). Все 19
  `action_type`-ключей теперь реализованы; ничего из этого списка не
  относится к экшенам самим по себе, только к периферийной инфраструктуре
  ниже.
- ~~**Визитор find-or-create + GeoDb/device**~~ — Фаза 9.
  ~~**Уникальные клики per-stream/per-campaign/global**~~ — Фаза 12.
  ~~**Redis-биндинг сущностей (sticky стрим/лендинг/оффер)**~~ — Фаза 13.
  **Кластер визитор/уникальность полностью закрыт.** Всё ещё НЕ
  портировано: cookie-хранилище (легаси пишет и в Redis, и в куку
  параллельно — порт использует только Redis, см. Фазы 12/13), остальная
  часть `SetCookieStage` (generic-куки, не связанные с уникальностью/
  биндингом).
- ~~**`BuildRawClickStage` — остальные подшаги**~~ — портировано Фазой 9
  (GeoDb/device/визитор) + Фазой 10 (referrer/source/se_referrer/
  keyword/search_engine/x_requested_with/cost/sub_ids/extra_params/
  ad_campaign_id/creative_id/external_id). Реально осталось: `language`/
  `currency` (у `clicks` в этой схеме нет таких колонок вообще — не
  пробел порта, негде хранить), поиск ключевого слова из referrer через
  паттерны поисковиков (`ReferrerParserService` — нужна отдельная база
  паттернов), bot/proxy-детекция (`UserBotListService`/`ProxyService` —
  Botlist уже портирован в `backend/` как админ-CRUD, не как рантайм-
  проверка).
- ~~**`GenerateTokenStage`**~~ — портировано Фазой 9 (Redis-хранилище
  клика по токену для офферов). `SetCookieStage` — НЕ портировано:
  generic-куки/entity-биндинг (sticky лендинг/оффер/стрим)/uniqueness-
  session — см. пункт про визитор/уникальность выше.
- ~~**`UpdateHitLimitStage`/`UpdatePayoutStage`**~~ — портированы Фазой
  11, реально работают. `UpdateCostsStage` тоже портирован Фазой 11, но
  **временно неактивен** — зависит от per-campaign uniqueness (см. её
  находку выше), не от отсутствия кода.
- ~~**`DomainRedirectStage`/`CheckPrefetchStage`/`CheckParamAliasesStage`/
  `CheckDefaultCampaignStage`**~~ — все 4 портированы Фазой 10.
- ~~**Асинхронная запись клика**~~ — портировано Фазой 15
  (`ClickQueue` + `bin/process_click_queue.php` + `traffic-core-worker`
  compose-сервис).
- ~~**Постбеки**~~ (`PostbackContext`/`PostbackDispatcher`) — портированы
  Фазой 11 (входящие + исходящие S2S).
- ~~**`processMacros()`**~~ — портировано Фазой 14 (`MacrosProcessor`/
  `ClickMacroValues`), реальная подстановка ~35 макросов. Оставшиеся
  ~вне-скоупа: `from_file`/кастомные PHP-макросы (риск как у
  `local_file`), языковой перевод country/region/city.
- ~~**`ClickApiContext`, `KtrkContext`, `RobotsContext`,
  `PingDomainContext`, `UpdateTokensContext`, `LandingOfferContext`**~~ —
  портированы Фазой 17 (`public/click-api.php`/`ktrk.php`/`robots.php`/
  `ping.php`/`update-tokens.php`/`landing-offer.php`). Живьём проверена
  полная цепочка: клик через ClickApi по токену кампании → лендинг с
  офером позади → `landing-offer.php` реально выбирает офер и обновляет
  `clicks` через новую `ClickUpdateQueue`. `KClientJSContext` — НЕ
  портирован, см. `docs/PORTING_LOG.md` Фаза 17: обе ветки
  `KClientJSDispatcher` зависят от `CodeGenerator::generateClientCode()`,
  которая читает файл по константе `CLIENT_LOCATION_DEFAULT = NULL` —
  нефункционально уже в самом легаси-исходнике, портировать нечего.

---
*Обновляется по ходу переноса, как `docs/PORTING_LOG.md` — дописывать, не переписывать.*
