# Round 3 — Fork B: TrafficSources / AffiliateNetworks / Domains / Users / Groups / ACL / Profile / ApiKeys

Тестирование через Playwright (контейнер `tds-playwright`, рабочая директория `/work/forkB/`),
логин `admin`/`TdsAdmin2026!`, `http://tds-app:8080/admin/`. Каждый найденный баг проверен по
логу `docker exec tds-app tail ... var/log/production-2026-08-27.log` и перепроверен живым
повторным запросом после фикса.

## Найдено и исправлено (5 багов, включая один критичный по безопасности)

### 1. 🔴 КРИТИЧНО — ACL "to_groups_and_selected"/"created_by_user_groups_and_selected" был полностью сломан в ОБЕ стороны

**Файл:** `application/Component/Users/Model/AclRule.php`

**Баг А (fail-open, дыра в безопасности):** `getGroups()`/`getEntities()` были голыми
`return $this->get("groups")`/`return $this->get("entities")` — БЕЗ `json_decode()` (поля в БД
хранятся как JSON-строка). До моего первого фикса это означало полный **fail-closed**:
`checkGroupId()`/`checkEntityId()` защищались через `is_array(...) &&`, и раз строка — не
массив — всегда `false`. То есть access_type `to_groups_and_selected`/
`created_by_user_groups_and_selected` **никогда не давал доступ ни к чему**, даже к явно
разрешённым сущностям — фича была полностью нерабочей.

**Баг Б (fail-open, реальная дыра, вскрылась ПОСЛЕ фикса json_decode):** когда пользователю с
access_type `to_groups_and_selected` не выбрано вообще ничего (ни группы, ни сущности), поле в
БД сохраняется не как `[]`, а как `["0"]` (сентинел-заглушка от фронтенда/сериализации формы).
Одновременно у ВСЕХ "негруппированных" кампаний/офферов/etc. `group_id` в БД реально хранится
как **`0`** (не `NULL`). Оригинальный код делал `in_array($groupId, $this->getGroups())` —
**нестрогое** сравнение — и `0 == "0"` в PHP истинно. Итог: пользователь с "доступ только к
выбранному" и НИЧЕГО не выбранным реально видел **ВСЕ** негруппированные сущности (то есть
практически всё, так как "без группы" — это дефолт для новых кампаний/офферов/лендингов).

**Проверено на реальном пользователе (`qa_acl_...`, роль User, ACL campaigns =
`to_groups_and_selected`, entities/groups пустые):**
- До фикса json_decode: `campaigns.withStats` падал с HTTP 500 (SQL `... WHERE group_id in ()`
  — невалидный синтаксис из `CampaignRepository::findByGroupIds()`, см. баг №2).
- После фикса json_decode (но без strict-сравнения): пользователь видел ВСЕ 3 кампании в
  системе (включая созданные другим агентом в параллельном тесте) — подтверждённый обход ACL.
- После полного фикса (json_decode + strict comparison + фильтрация "0"/пустых ID как
  невалидных): пользователь с пустым выбором видит **"No data"** (0 кампаний) — корректно.
  Пользователь, которому явно выдали доступ к одной конкретной кампании (`entities: ["4"]`),
  видит **ровно её одну** ("test", id=4) и не видит остальные — подтверждено скриншотом/логом.

**Фикс:**
```php
public function checkGroupId($groupId) {
    if (empty($groupId)) return false;
    return in_array((string) $groupId, $this->getGroups(), true); // strict
}
public function checkEntityId($entityId) {
    if (empty($entityId)) return false;
    return in_array((string) $entityId, $this->getEntities(), true); // strict
}
public function getEntities() {
    $entities = $this->get("entities");
    $entities = is_string($entities) ? json_decode($entities, true) : (is_array($entities) ? $entities : []);
    return array_values(array_filter($entities, function ($id) { return !empty($id); }));
}
public function getGroups() { /* аналогично */ }
```
`addEntityPermission`/`addGroupPermission` тоже переведены на вызов геттеров вместо прямого
`->get(...)`.

**Рекомендация (не сделано, вне моего скоупа):** стоит перепроверить, не сохраняет ли
фронтенд `["0"]` для "ничего не выбрано" и в ДРУГИХ похожих JSON-полях по всему проекту — это
может быть не единственное место. Также вручную протестировать `created_by_user_groups_and_selected`
(не проверялось — только `to_groups_and_selected`).

### 2. `CampaignRepository::findByGroupIds()` — невалидный SQL при пустом списке групп

**Файл:** `application/Component/Campaigns/Repository/CampaignRepository.php`

Строил `"group_id in (" . implode(",", $groupIds) . ")"` без проверки на пустоту/не-массив —
при `$groupIds = null` или `[]` получалось `group_id in ()`, невалидный SQL → `ADODB_Exception`
→ HTTP 500. Плюс мёртвая строка `$groupId = Db::quote($groupId)` с необъявленной переменной
(остаток декомпиляции, результат никуда не использовался). Фикс:
```php
public function findByGroupIds($groupIds) {
    if (!is_array($groupIds) || !count($groupIds)) return [];
    $rows = $this->rawRows("id", "group_id in (" . implode(",", \Core\Db\Db::quote($groupIds)) . ")");
    ...
}
```
(Убрана мёртвая строка, добавлено экранирование через `Db::quote()` по аналогии с соседним
`allByIds()`.)

### 3. `ApiKeySerializer` — `format()` на строке вместо DateTime

Уже задокументировано в основном `docs/FIXES_LOG.md` другим раундом тестирования сегодня же
(не мой фикс, я только ПОВТОРНО подтвердил его: страница Profile → Admin API keys рендерится
без ошибок, дата и у старого, и у только что созданного ключа отображается корректно
человекочитаемым форматом — "27 Aug 2026 10:07").

### 4. `ProfileController::updateAction()` — несуществующий класс исключения

**Файл:** `application/Component/Users/Controller/ProfileController.php`

При неверном текущем пароле код делал `throw new \Core\Application\Error(...)` — такого
класса **не существует** в проекте (правильный — `\Core\Application\Exception\Error`, ИЛИ, что
семантически правильнее для поля формы, `\Core\Validator\ValidationError`). Результат — вместо
понятной ошибки валидации пользователь получал HTTP 500 "An error occurred. Please check
Maintenance > Log" при любой попытке сменить пароль с неверным текущим паролем. Фикс —
заменено на `\Core\Validator\ValidationError(["current_password" => [LocaleService::t("users.current_password_incorrect")]])`,
что даёт HTTP 406 и привязывает ошибку к полю "Current password" по стандартному контракту
серверной валидации (см. `docs/frontend/frontend_analysis.md` §2). Проверено: до фикса — 500,
после — 406 с полем `current_password`.

### 5. `Component\Domains\Service\DomainService` / `DomainSerializer` (не мой фикс, только verification)

Оба вчерашних фикса (ssl_status дефолт при создании, default_campaign в сериализаторе)
перепроверены на СВЕЖЕСОЗДАННЫХ данных этим раундом (не на старых записях, которые чинились
раньше):
- Новый домен `qa-domain-....com` → статус сразу "Waiting for DNS" (человекочитаемо, не
  `domains.ssl_status.0`).
- Тот же домен, после простановки `default_campaign_id` = кампания "test" → в колонке "Park to
  Campaign" после перезагрузки страницы показывается "test", не пусто.

## Найдено, но НЕ является багом декомпиляции — обрезанная vendor-инфраструктура

**TrafficSource templates и AffiliateNetwork templates всегда пустые ("No options").**
`TrafficSourceTemplateRepository::PATH` и `NetworkTemplatesRepository::PATH` (и
`Component\Templates\Info\Info::TEMPLATES`, откуда берутся source→destination URL для скачивания)
— все три равны `const ... = NULL`. Есть целый компонент `Component\Templates` с
`TemplateDownloader`/`CronTask\UpdateTemplatesTask`/`ConsoleCommand\DownloadTemplatesCommand`,
рассчитанный на скачивание файлов шаблонов с внешнего (вероятно платного/лицензионного) сервера
вендора при первом запуске/по крону — но сами URL-адреса и целевые пути отсутствуют в поставке.
Это та же категория, что уже задокументирована в `docs/TODO_IMPROVEMENTS.md` для GeoDB-баз ботов
и `exrates.tds.io` — не чинил, гадать чужие приватные URL нельзя. **Стоит добавить отдельную
запись в `docs/TODO_IMPROVEMENTS.md`** (не успел сам — оставляю координатору): при желании можно
завести свои собственные локальные файлы шаблонов и захардкодить в них `PATH`, раз готового
источника нет.

## Найдено, не критично (frontend-only, не чинил — не PHP-баг)

- **Все списочные страницы (грид)** — в консоли браузера на каждой странице со списком
  (TrafficSources, AffiliateNetworks минимум, вероятно и остальные) стабильно
  воспроизводится `TypeError: Cannot read properties of undefined (reading 'setColumns')`
  (`app.js:107137`). Грид при этом рендерится и работает нормально с реальными данными —
  похоже на race condition при инициализации второго grid-инстанса (виджет "Metrics N"), не
  блокирует функционал. Не чинил (минифицированный фронтенд-бандл вне скоупа PHP-фиксов).

## Подтверждённый рабочий функционал (полный CRUD, без багов)

- **TrafficSources**: создание (с S2S postback URL, URL Parameters секцией — 15 sub_id полей +
  keyword/cost/currency/external_id/creative_id/ad_campaign_id/source), список с метриками.
  Шаблоны недоступны (см. выше, не баг проекта).
- **AffiliateNetworks**: создание (name/offer_param/postback_url), список с метриками.
- **Domains**: одиночное создание, редактирование (SSL redirect/Include Subdomains/Park to
  Campaign/Catch 404 переключатели), Check-кнопка. Множественное создание через запятую НЕ
  протестировано отдельно (создавал по одному) — стоит перепроверить отдельно.
- **Users**: создание пользователя (login/password/role/language/timezone), список.
- **ACL ("Access" модалка)**: полноценный UI — "Allowed resources" (чекбоксы разделов меню,
  включая `Domains` корректно ОТСУТСТВУЮЩИЙ по умолчанию у нового юзера), по каждой сущности
  (Campaigns/Offers/Landing pages/Sources/Affiliate networks) — 4 access_type
  (Full access/Read only/To Selected/Created and selected), "Add groups"/"Add campaigns"
  react-select пикеры. Resource-level ACL подтверждён: `?object=users.index` → 403 для не-админа
  с точным текстом "You have no permission to access to this page - Users"; попытка открыть
  `#!/domains/` (ресурс не в Allowed resources) → редирект обратно + `errors.restricted_access`.
- **Profile**: смена пароля с ПРАВИЛЬНЫМ текущим паролем не проверялась отдельно (проверял
  только сценарий с неверным паролем — это и было целью), timezone-селектор визуально работает.
- **ApiKeys**: создание нового ключа (`?object=apiKeys.add`, 200), список с датами, все даты
  отображаются корректно (включая только что созданный ключ). Удаление НЕ доведено до конца
  технически (мой Playwright-селектор для кнопки "Delete" не сработал из-за иконки внутри
  ссылки) — сам backend-эндпоинт `apiKeys.delete` не тестировался в этом раунде, стоит
  перепроверить отдельно.

## Тестовые сущности, оставленные в БД (для справки, не удалял — dev-окружение)

- Traffic Sources: id=2 "QA Source ..." (создан несколько раз, финальный успешный — с S2S
  postback URL и Ad Campaign ID параметром)
- Affiliate Networks: 1 новая "QA AffNetwork ..."
- Domains: id=2 "qa-domain-....com" (default_campaign_id → кампания "test")
- Users: id=4 "qa_acl_...." (роль User, ACL campaigns = to_groups_and_selected, entities=["4"] —
  оставлено в этом состоянии как живая демонстрация корректной работы ACL; пароль
  `QaTest12345!`), "limiteduser" — уже существовал до этого раунда, не мой
- ApiKeys: 1 новый ключ у admin (`9711a6c2...`) — не удалён (см. выше, delete не довёл до конца)

## Не успел проверить (не хватило времени в рамках этого раунда)

- Домены через запятую/точку-с-запятой/`|` в одном поле (множественное создание) — код
  `createMultiple()` проверен ЧТЕНИЕМ и должен работать (`explode(",", ...)` + `array_map`), но
  живого клика с реальной проверкой не было.
- `created_by_user_groups_and_selected` access_type — проверял только `to_groups_and_selected`.
- Смена пароля с ПРАВИЛЬНЫМ текущим паролем (позитивный сценарий) — только негативный проверен.
- ApiKeys — удаление ключа до конца не доведено технически.
- Pull-API опции AffiliateNetworks, инструкции (`instructions`), клонирование/архивация
  Offers/AffiliateNetworks/TrafficSources/Domains, привязка постбека к трафик-сорсу на странице
  кампании, шаблоны трафик-сорсов (сами по себе технически недоступны, см. выше).
