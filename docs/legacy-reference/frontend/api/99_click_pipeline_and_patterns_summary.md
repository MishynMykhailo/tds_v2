## 11. Click-processing pipeline (справочно, НЕ admin API)

`Traffic\Pipeline\Pipeline` (`application/Traffic/Pipeline/Pipeline.php`) —
конвейер обработки трафика, запускается из
`Traffic\Dispatcher\ClickDispatcher` / `ClickApiDispatcher` /
`LandingOfferDispatcher` (обычный клик по трекинг-ссылке, Click API,
и запрос "второго уровня" при клике по кнопке лендинга/выборе оффера).
**Не имеет отношения к `Admin\*` и не вызывается из admin-панели.**

Основная цепочка стадий (`firstLevelStages()`, порядок важен —
каждая стадия получает и возвращает общий `Payload`):
`DomainRedirectStage` → `CheckPrefetchStage` → `BuildRawClickStage` →
`FindCampaignStage` → `CheckDefaultCampaignStage` → `UpdateRawClickStage` →
`CheckParamAliasesStage` → `UpdateCampaignUniquenessSessionStage` →
**`ChooseStreamStage`** → `UpdateStreamUniquenessSessionStage` →
**`ChooseLandingStage`** → **`ChooseOfferStage`** → `GenerateTokenStage` →
`FindAffiliateNetworkStage` → `UpdateHitLimitStage` → `UpdateCostsStage` →
`UpdatePayoutStage` → `SaveUniquenessSessionStage` → `SetCookieStage` →
`ExecuteActionStage` → `PrepareRawClickToStoreStage` →
`CheckSendingToAnotherCampaign` → `StoreRawClicksStage`.

- `ChooseStreamStage` — выбирает стрим кампании: сначала форсированный
  (`forced_stream_id` из query, если задан), затем `TYPE_FORCED`-стримы по
  позиции, затем `TYPE_REGULAR` (по позиции для кампаний типа `POSITION`,
  либо по весу-ротации для остальных, с опциональной sticky-привязкой
  визитора через Redis), и в конце — `TYPE_DEFAULT` (запасной стрим, если
  ничего не подошло). Если схема выбранного стрима — не `LANDINGS`/`OFFERS`
  (то есть `ACTION`) — прямое действие стрима (`action_type`/
  `action_payload`/`action_options`) сразу копируется в `Payload`.
- `ChooseLandingStage` — только для схем `LANDINGS`/`OFFERS`: если лендинг
  ещё не предопределён (`forced`), выбирает случайный/по весу лендинг из
  привязанных к стриму (`LandingOfferRotator`), с опциональным sticky-биндингом.
- `ChooseOfferStage` — аналогично, но офферы; пропускается, если лендинг
  уже выбран (кроме случая `isForceChooseOffer()` — например лендинг
  явно запрашивает следующий оффер через клиентский JS-виджет).
- При зацикливании "отправки в другую кампанию" (`CheckSendingToAnotherCampaign`
  / `abort()+forcedCampaignId`) пайплайн перезапускается с нуля до 10 раз
  (`Pipeline::LIMIT`), после чего кидает исключение о бесконечной рекурсии.

**Симуляция клика ("Simulate traffic", проверено 2026-08-27 через анализ app.js + Playwright).**
Первоначальная гипотеза была неверной. Специального admin-API эндпоинта симуляции
действительно нет (`Component\Simulation` пуст), но не потому что функциональность
отсутствует — она просто **не идёт через admin API вообще**. Фронтенд (`simulationService`,
`components.simulation.services`) делает серию настоящих `POST` запросов напрямую на
`<root>/api.php` — это тот же самый публичный Click API, что и для сторонних систем
(см. точку входа `api.php` / `Traffic\Context\ClickApiContext` в §1), с флагами
`token, log:true, version:2, always_empty_cookies:true, save_to_stats:<bool>` и случайными
гео/device-характеристиками. `log:true` заставляет Click API вернуть подробный лог обработки
клика в теле ответа, который фронтенд стримит в модалку. То есть "симуляция" — это реальный
клик через реальный пайплайн, просто с флагом отключения куки и опционально без записи в
статистику. Доступно только Trial/Pro+. Подробности и точный список параметров запроса — см.
`docs/frontend/architecture_plan.md` §1.3.

---

## 12. Паттерны и особенности API (сводка)

1. **Один универсальный роутинг**: `?object=<controller>.<action>` →
   `strtolower(<controller>)` ищется в реестре, зарегистрированном каждым
   компонентом в своём `Initializer::loadControllers()`; экшен —
   `<action>Action()` public-метод. Нет соглашения "REST по HTTP-методу" —
   GET/POST используются произвольно, разделение по смыслу (`isPost()`
   внутри самого экшена), а не по факту роутинга.
2. **Батч-запросы** (`?batch=1` или легаси `?bulk=1`, тело — массив
   `{params, postData}`) прогоняют каждый под-запрос через полноценный
   `AdminDispatcher`, ответ — массив `{body, headers, statusCode}` в том же
   порядке; внешний HTTP-статус батча всегда 200.
3. **Аутентификация** — JWT в cookie `states` (НЕ httpOnly), проверяется
   заново на каждый запрос через таблицу `user_password_hashes`; никакой
   PHP-сессии. REST AdminApi (`/admin_api/vN`) — отдельно, через
   `api_key`/`Api-Key`.
4. **Тело запроса** парсится по содержимому, а не по `Content-Type`:
   начинается с `{`/`[` → JSON, содержит `&` → urlencoded form, иначе —
   пусто. `getParam()` = query, потом body (query приоритетнее);
   `getPostParam()` = только body.
5. **Формат ошибок** не единообразен между "обычным" admin-контекстом (см.
   таблицу в §7 — коды 402/403/404/406/500, тело JSON почти везде **кроме**
   generic-500 через `CommonErrorHandler`, который отдаёт HTML) и REST
   AdminApi (все ошибки → 402 JSON). Фронтенду нужно быть готовым к обоим
   вариантам тела (JSON и голый HTML-текст).
6. **Сериализация** — почти везде `AbstractSerializer` с `$_fields = true`
   (то есть "отдать всё, что есть в модели"), а не явный whitelist; реальный
   контракт полей ответа нужно смотреть по `extra()` каждого конкретного
   Serializer'а (что добавляется/удаляется) — белого списка полей "из
   коробки" в таком случае нет, нужно ориентироваться на структуру таблицы
   БД + `extra()`.
7. **JSON-в-поле** — целый класс полей моделей хранит JSON-строку и
   ожидает, что и модель, и потребитель модели используют геттер (а не
   читают `->get("поле")` напрямую) — см. таблицу в §8. Это уже было
   источником критичных багов при декомпиляции, при написании нового
   фронтенда стоит всегда сверяться с фактическим (текущим, желательно уже
   починенным) поведением геттера, а не полагаться на "как хранится в БД".
8. **Grid/withStats** — универсальный конверт пагинации/фильтрации:
   `{columns, grouping/dimensions, metrics, sort:[{name,order}], filters:
   [{name,operator,expression,case_sensitive}], range:{interval|from|to},
   limit, offset, summary, format}` → ответ `{rows, total, summary?, meta}`
   (§9). Список сущностей "withStats" (Campaigns/Offers/Landings/
   TrafficSources) использует близкий, но не идентичный билдер
   (`EntityGridFactory`, объединяет сущности + агрегированные метрики по
   `<entity>_id`), в отличие от чистых Reports (`GridBuilder`/`ReportRepository`,
   единая таблица кликов без пред-загрузки сущностей).
9. **ACL** проверяется на трёх уровнях: (а) весь контроллер/ресурс целиком
   при роутинге (§2.1); (б) конкретная сущность в каждом
   `show`/`update`/`archive`/... (`isViewAllowed`/`isEditAllowed`); (в) на
   уровне SQL-фильтра в Grid (`AccessRestriction` по разрешённым
   `campaign_id`). Плюс отдельно — какие столбцы отчёта видны
   (`getRestrictedReportColumns`).
10. **Массовые операции** почти везде принимают либо `id` (одиночный),
    либо `ids` (массив) взаимозаменяемо — если `id` присутствует, он
    молча превращается в `ids = [id]` в начале метода.
11. **"Мягкое" и "жёсткое" удаление** — почти у всех сущностей `delete`/
    `archive` это одно и то же (перевод в `state = deleted`, попадает в
    `deletedAction`), а физическое удаление — отдельный `cleanArchive`
    (`PruneTask\Prune<Entity>::deleteAll()`), обычно защищённый той же
    проверкой прав, что и `create`.
