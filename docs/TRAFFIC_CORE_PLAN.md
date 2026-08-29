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

## Осознанно отложено (следующие фазы, каждая — отдельная спланированная сессия)

- **`remote`-экшен** (`Traffic\Actions\Predefined\Remote`) и остальные 16
  типов экшенов кроме `http` — каждый мелкий, но их 17 штук.
- **Визитор/уникальность** (`Component\Clicks\Model\Visitor`,
  `SaveUniquenessSessionStage`, `UpdateStreamUniquenessSessionStage`,
  `UpdateCampaignUniquenessSessionStage`, Redis-биндинг визиторов) —
  сейчас `visitor_id` — случайное число, не реальный find-or-create.
  Нужно для anti-fraud/уникальных кликов в отчётах.
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
- **`CheckSendingToAnotherCampaign`/`ToCampaign`-экшен** — рекурсивный
  редирект между кампаниями (`Pipeline::_preparePayloadForCampaign()`).
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
