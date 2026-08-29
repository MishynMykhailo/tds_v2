# Анализ фронтенда админки (AngularJS SPA)

Документ описывает функционал единого webpack-бандла `admin/assets/app.js` (~7 МБ, beautified,
без sourcemap и без исходного webpack-конфига). Анализ построен на:

- прямом grep/чтении `admin/assets/app.js` (переменные после минификации однобуквенные —
  `a`, `e`, `t`, `r`, `Gg` и т.п., поэтому идентификация по строковым литералам: `.state(`,
  `.component(`, `?object=...`, `.setType(`, `react2angular`);
- де-бандленном выводе webcrack (`webcrack_out/`, 1342 файла-модуля с числовыми именами +
  `deobfuscated.js` ~201k строк, полностью unminified — использовался для точечного чтения
  конкретных модулей, например `264.js` для `serverValidationErrorCatcher`);
- `admin/assets/app.js.LICENSE.txt` (список библиотек, но, как выяснилось, неполный — см. ниже).

Все ссылки на "строка N" относятся к текущему `admin/assets/app.js` — при пересборке бандла
номера строк уедут, поэтому рядом всегда указано, что искать через grep (уникальные строки типа
`?object=...` или имена сервисов/компонентов).

---

## 1. Технологический стек

### Ядро
- **AngularJS v1.8.2** — вся админка (кроме логин-страницы и страницы лицензии, которые собраны
  отдельными бандлами `login.js`/`license.js`) — это classic Angular 1 SPA с `ui-router`
  (`$stateProvider.state(...)`, найдено 73 определения состояний, полный список — см. раздел 3).
  Компонентный стиль (`.component()`), почти нигде не используется `$scope`-контроллеры старого
  образца, кроме легаси-мест (sidebar, some modals).
- **ui-router** (не отдельно упомянут в LICENSE.txt, но использован везде: `$stateProvider`,
  `$state.go`, `$transitions.onStart` для guard несохранённых форм).

### Формы: angular-formly v8.4.1 + angular-formly-templates-bootstrap v6.5.1 + api-check v7.5.5
Ядро системы динамических форм всего приложения — офферы, лендинги, стримы, кампании, трафик-сорсы,
домены, настройки и т.д. строятся почти без hardcoded HTML-форм, а через конфиги полей (`fields:
[...]`), которые скармливаются директиве `<formly-form model="..." fields="...">`. Подробности —
раздел 2.

### Графики: angular-chart.js v1.1.1 (обёртка Chart.js v2.9.4)
Используется в `Reports`/`Dashboard`: `<canvas chart-data chart-labels chart-series chart-options
chart-dataset-override>` внутри `report-chart`/`app-dashboard` компонентов (шаблон найден на
строке ~85372 app.js). Директивы `chartLine/chartBar/chartHorizontalBar/chartRadar/
chartDoughnut/chartPie/chartPolarArea/chartBubble` зарегистрированы через `ChartJsFactory`
(строка ~55210) — то есть теоретически поддерживаются все типы графиков библиотеки, но по факту
в отчётах/дашборде используется в первую очередь линейный график (`chart-line`) с легендой
(`<chart-legend>`).

### angular-hotkeys v1.7.0
Реальное использование — глобальные и постраничные шорткаты:
- `alt+shift+n` — создать кампанию (кнопка в сайдбаре, `components.layout.sidebar.create_campaign_button`, ~111583);
- `alt+s` — свернуть/развернуть боковое меню (сайдбар построен на **snap.js** — `snapRemote`
  сервис, обёртка над `Snap.js` slide-menu библиотекой; сам snap.js не упомянут в LICENSE.txt,
  см. раздел 4);
- `alt+n` на страницах списков (Offers, Traffic Sources, Landings, Affiliate Networks, Logs) —
  открыть форму создания новой записи (найдено в контроллерах `appTrafficSources`, `appOffers`,
  `appLandings`, `appAffiliateNetworks`, `logsNewPage` — grep `hotkeys.add` даёт 10 мест).

### angular-dialog-service v5.3.0 (поверх ui-bootstrap)
Обёртка `dialogService` над `$uibModal` — используется буквально для каждого модального окна:
формы редактирования сущностей (`dialogService.create(Template, Controller, data, options)`),
подтверждения (`dialogService.confirm(title, text)`), окно логов, окно triggers/webhook и т.д.

### ng-file-upload v12.2.13
Два реальных сценария:
1. Формли-тип `vFileField` (drag&drop зона + кнопка выбора файла + превью картинки + очистка) —
   используется в офферах/лендингах (загрузка изображения оффера) и в брендинге (логотип/favicon).
2. Модалка импорта стримов (`components.campaigns.streams.import`) — `ngf-select`/`ngf-drop` для
   загрузки TDS-экспорт JSON-файла с прогресс-баром, шаблон на строке ~85756.

### jQuery v3.5.1 (+Sizzle) и jQuery UI (:data/Mouse/Widget/Sortable/TouchPunch/ScrollParent) v1.12.1
jQuery как классическая зависимость Bootstrap 3/4-эры (даже несмотря на Angular). jQuery UI
Sortable используется через директиву `ui-sortable="::$ctrl.SORTABLE_OPTIONS"` — найдено ОДНО
явное место применения в бандле: drag&drop сортировка кампаний/групп в боковом меню
(`components.layout.sidebar`, controller на ~111500), сохранение нового порядка —
`campaignService.savePositions()` → `POST ?object=campaigns.savePositions`.
Touch Punch нужен, чтобы то же drag&drop работало на touch-устройствах.

### lodash, moment.js + moment-timezone v0.5.32, accounting.js v0.4.1, classnames, object-assign, cookie, tether v1.4.7
- lodash — общая утилитарная библиотека (везде: `_.includes`, `_.debounce` для автосейва и т.п.).
- moment + moment-timezone — вся работа с датами отчётов/логов, конвертация в таймзону
  пользователя (`userPreferenceService.get('timezone')` передаётся почти в каждый grid `range`).
- accounting.js — форматирование денег/чисел в отчётах и виджетах costs/profit/roi.
- tether — позиционирование дропдаунов/поповеров (используется библиотеками типа
  `ui-select`/tooltip, напрямую в коде проекта не встречается).
- cookie, classnames, object-assign — служебные зависимости самого React/react-select стека (см. ниже).

### React v16.14.0 + react-dom + react-is + scheduler — НЕ изолированный виджет, а полноценный второй UI-слой
Важнейшая находка: React в этом проекте — **не** какой-то один "экзотический виджет". Библиотека
`react2angular` (`t.react2angular = ...`, строка ~4530) используется **34+ раза** для регистрации
Angular-компонентов, чей рендер полностью отдан React-дереву. Ключевые примеры (grep
`react2angular` по всему файлу):

| Angular-компонент | React-функционал |
|---|---|
| `gridReact` (~107098) | **Само тело таблицы (grid) для всех списков** — Campaigns/Offers/Landings/TrafficSources/AffiliateNetworks. Т.е. рендер строк, ячеек, форматирование колонок — React, а обвязка (фильтры, пагинация, тулбар) — Angular. |
| `filterSelect`, `filterOperatorSelect`, `labelSelect`, `gridColumnSelect` (~106135, ~106254, ~106413, ~109519) | Виджеты выбора колонок/операторов фильтра в гриде. |
| `kSelectWrapper` / `kSelectSortableWrapper` (~110561) | Обёртка над **react-select** (подтверждено: внутренний код react-select с `instancePrefix = "react-select-" + ...` найден на строке ~14665 app.js). Это основной "красивый" select во всём приложении (used by `vUiSelect` formly-тип и множеством фильтров). |
| `kTagsWrapper`, `kLogoWrapper` (~110749, ~110787) | Теги/логотип. |
| `usersTable` (~116118) | Таблица пользователей на экране Users. |
| `streams` (~135802) | **Весь виджет управления стримами кампании** — это полноценное React-приложение (с собственным React Context/Provider, `ve.a.createElement(IT, {$injector}, ...)`), которому явно прокидывается Angular `$injector`, чтобы React мог дергать Angular-сервисы (`campaignService`, `streamService`...). |
| `editorView` (~121465) | Экран локального редактора кода лендингов (`/editor/:type/:landingID`) — целиком React, внутри использует **Ace Editor** (ajax.org, `ace.define("ace/ace", ...)`, строка ~35939) как движок редактирования кода (не CodeMirror/Monaco). |
| `integration`, `integrationAVScan`, `integrationIMKlo`, `integrationHideClick`, `integrationFacebook`, `integrationAppsFlyer` (~126292) | Экран Integrations целиком на React. |
| `actionSelect`, `versionFilterOperatorSelect`, `regionFilterSelect`, `operatorFilterSelect`, `streamLanguageFilterSelect` | Виджеты выбора значений для фильтров таргетинга стримов и для выбора action (redirect/показать оффер/лендинг/404 и т.д.). |
| `logsReactTable` (~136201) | Таблица на новом экране логов (`logsNewPage`). |
| `kclientJsIntegration` (~117434) | Виджет генерации/показа кода "kClient JS" интеграции кампании. |
| `detailsTableReact` (~106030) | Таблица деталей строки грида (row details popup). |
| `geoDbSelect`, `updateLicense`, `integrationPresetSelect` | Более мелкие select/модальные виджеты. |

**Вывод по React:** он используется как замена "тяжёлых" интерактивных частей UI (таблицы с
кастомным рендерингом ячеек, кастомные select'ы на базе react-select, drag&drop/сложная логика
редактора стримов, редактор кода) — то есть архитектурно это гибридное Angular+React приложение,
где Angular отвечает за роутинг/состояние страницы/формы (formly), а React — за самые сложные с точки
зрения UX виджеты. Мост — `react2angular`, прокидывающий пропсы как Angular-bindings и (в случае
`streams`) сам `$injector`, чтобы React-код мог получать доступ к Angular DI.

### Dan Grossman date range picker (bootstrap-daterangepicker) v2.1.24 — подтверждено
Прямо найден оригинальный код библиотеки (строка ~89328 app.js): API `singleDatePicker`,
`timePicker`, `ranges`, `locale.applyLabel/cancelLabel`, `autoUpdateInput` и т.д. — это 1-в-1
`www.improvely.com` / `dangrossman/bootstrap-daterangepicker`. Используется для выбора диапазона
дат в отчётах/дашборде/логах (`range: {interval, timezone}` — параметр почти каждого grid).

### Скрытые/недокументированные зависимости (отсутствуют в `app.js.LICENSE.txt`, но реально в бандле)
- **i18next** — полноценно забандлен (найдены комментарии `i18next:`, `i18next.init`,
  `i18next.loadNamespace`, `hasLoadedNamespace` и т.п., строки ~15662–16912). Именно он, судя по
  всему, реализует `translationService.translate/t()` и Angular-фильтр `t` (`.filter("t", q)`,
  строка ~24908). Локали хранятся, видимо, в json-ресурсах (не смотрели отдельно, не входило в
  список выше, но факт использования i18next вместо какого-то самодельного решения — важная деталь
  для пересборки).
- **react-i18next** — тоже забандлен (`react-i18next:: ...`, `initReactI18next`, строка ~133441+),
  то есть React-часть приложения (см. таблицу выше) локализуется отдельно через react-i18next, а
  не через Angular-фильтр `t`.
- **Ace Editor** (ajax.org) — используется в `editorView` (см. выше), в LICENSE.txt не упомянут.
- **Snap.js** (или аналог) — под именем `snapRemote`/`getSnapper()` реализует slide-out сайдбар
  (`snapRemote.toggle('left')`, `snapRemote.open('left')`) — по паттерну API 1-в-1 совпадает с
  `Snap.js` (jakiestfu/Snap.js), но явного `@license Snap.js` комментария grep не нашёл — стоит
  перепроверить руками, если будет доступ к исходной carte de visite библиотеки внутри бандла.

---

## 2. Angular-Formly: как устроены динамические формы

**Главный факт: все конфиги полей — статические, зашиты в JS-бандле, НЕ приходят с бэкенда.**
Ни одного места, где `fields` для formly строился бы из ответа сервера (JSON schema с бэка), не
найдено. Вместо этого на каждую сущность заведён отдельный Angular-factory-сервис
`"xxxForm"` с методом `getFields(...)`, который руками собирает массив полей:

```
campaignForm      → components.campaigns.edit_campaign      (~116898)
offerForm         → components.offers.edit_offer            (~130169)
landingForm       → components.landings.edit_landing        (~130738)
streamForm        → components.streams.edit_stream          (~127454)
trafficSourceForm → components.traffic_sources.edit_traffic_source (~129598)
domainForm        → components.domains.edit_domain          (~111191)
userForm          → components.users.edit_user              (~116191)
affiliateNetworkForm → components.affiliate_networks.edit_affiliate_network (~136326)
brandingForm      → components.branding                      (~111300)
settingsMainForm / settingsSystemForm / settingsBotsForm / settingsIntegrationForm
                  → components.settings.settings              (~126946)
geoProfileForm    → components.geo_profiles.edit_geo_profile  (~127183 область)
```

Такие сервисы получают через DI другие данные-сервисы (например, `offerForm` тянет
`AffiliateNetwork.listAsOptions()`, `Offer.getCostTypes()`, `Group.listAsOptions()`) и строят
поля с условной видимостью через `hideExpression`/`expressionProperties` (Angular-выражения над
`model`, вычисляются реактивно).

### Кастомные типы полей (`formlyConfigProvider.setType`)
Базовые типы форк-нуты из `angular-formly-templates-bootstrap` (`input`, `select`, `checkbox`,
`radio`, `textarea`, `multiCheckbox` — дословно тот же код библиотеки, строки ~55400–55650), но
проект **не использует их напрямую** — вместо этого поверх заведена собственная обёртка с двумя
кастомными wrapper'ами `labelWrapper`/`fieldWrapper` (строка ~24922) и набором `v`-префиксных
типов:

- `vInput`, `vSelect`, `vTextarea`, `vCheckbox`, `vRadio`, `vMultiCheckbox` — базовые (~24922–24957);
- `vSwitch` — toggle-переключатель (built on `vCheckbox`) (~24964);
- `panel` — вложенная `<formly-form>` внутри аккордеон-панели (для группировки полей, например
  "Aliases" в Settings) (~24964);
- `vUiSelect` — обёртка над `kSelectWrapper`/react-select (контроллер слушает `$scope.model` и
  `to.options.$promise`, чтобы поддержать асинхронную загрузку опций) (~25009);
- `vCheckList`, `vFieldGroup` (вложенная группа полей), `vFileField` (аплоад файла),
  `vCountriesField` (выбор стран через `Collection.countries()`), `vButtonGroup`, `vUrlInput`
  (~25020–25100);
- доменные типы: `vTrafficSource` (~117218), `vGroup` (~118534, поле выбора/создания
  группы/папки "на лету"), `vAffiliateNetwork` (~130191) — все три расширяют `vInput` и
  подключают свой select-контроллер + возможность создать новую сущность прямо из выпадающего
  списка (например, `appAffiliateNetworkButton` создаёт новую affiliate-сеть без выхода из формы
  оффера).

### Валидация
Два независимых слоя:
1. **Клиентская** — стандартный Angular/formly `required`, кастомные `validators: {noPath:
   {expression: fn}}` (например, у домена — regex-проверка "это домен, а не URL с путём"; у
   лендинга `lp_dir` — "не пустая строка"). Ошибки показываются формли-обёрткой
   `fieldWrapper`/`bootstrapHasError` (класс `has-error` + текст под полем).
2. **Серверная** — обрабатывается сервисом **`serverValidationErrorCatcher`**
   (`components.users.services`, файл модуля `264.js` в webcrack-выводе). Механизм:
   - Бэкенд обязан отвечать **HTTP 406** с телом-объектом `{ "имя_поля.любой_суффикс": ["текст
     ошибки", ...], ... }` (ключ режется по первой точке: `n.split(".")[0]`);
   - фронт ищет в DOM ближайший элемент `[field-name="имя_поля"]:last:visible` внутри
     `form:first` и вставляет туда `<div class="server-error">текст</div>` + вешает класс
     `has-error` на обёртку поля;
   - если элемент с таким `field-name` не найден на странице — ошибка вместо этого улетает в
     toast через `notificationService.error("поле: текст")`;
   - при любом статусе, отличном от 406, `.catch` в контроллерах (`submit()` у Offer/Landing и
     т.д.) просто показывает `notificationService.error(response.data.error)`.
   Это значит, что **каждое** формли-поле, которое должно поддерживать серверную валидацию,
   обязано иметь HTML-атрибут `field-name="..."`, совпадающий с ключом, который отдаёт бэкенд.
   Для пересборки бэкенда/фронтенда с нуля это критично: если бэкенд переименует поле или
   перестанет отдавать 406 — фронт молча проглотит ошибку в общий toast без привязки к полю.

---

## 3. Экраны / фичи

Ниже для каждого экрана — состояние(я) `ui-router`, применяемый компонент/контроллер, реальные
admin API вызовы (`?object=...`), и клиентские фичи. Полная карта состояний (`grep '\.state("'
admin/assets/app.js`) — 73 состояния, все перечислены по ходу разделов.

### 3.1 Campaigns (`/campaigns`)
Состояния (~118059): `campaigns` (abstract) → `campaigns.index` (`appCampaigns`, грид),
`campaigns.delete` (модалка подтверждения архивации), `campaigns.create`/`campaigns.edit`
(`editCampaign`), `campaigns.report` (`campaignReport`), `campaigns.go_to_report` (headless
контроллер-редирект — строит параметры отчёта и переходит на `reports.index`).

Ресурс `Campaign` — `?object=campaigns.:action` (~113029): `index` (grid query, GET, isArray),
`listAsOptions` (кэшируется), `gridDefinition`, `clone`, `archive`, `enable`, `disable`,
`savePositions` (сохранение ручной сортировки в сайдбаре), плюс отдельно (не в общем ресурсе, а
инлайново вызывается через общий HTTP-клиент `Gg.a` — обёртка над `$http`, судя по паттерну
`Gg.a.get/post`): `campaigns.index` ещё раз используется внутри `streams`-виджета (React) для
подгрузки списка кампаний в selector'е.

Форма кампании (`campaignForm`, ~116898) — вкладки: **Settings** (main fields из
`getMainSettings`: alias/token генерируются `slugGeneratorService.generate()` — алфавит без
неоднозначных символов `123456789bcdfghjkmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ`, длина по умолчанию 6
для alias / 32 для token; type=position; cookies_ttl=24ч; uniqueness_method=ip_ua;
cost_type=CPC; domain_id; group_id и т.д. — см. `campaignService.newCampaign()`, ~117968),
**Integration** (`campaign-integration` — пресеты трекинг-кода/пикселя из `CodePresets` —
`?object=CodePresets.:action` с action=`show&id=tracking_script|tracking_pixel|banner_async|banner_sync`,
плюс `kclientJsIntegration` — React-виджет генерации "kClient JS" сниппета через
`?object=kClientJSPreset.show`), **Traffic Sources parameters** (`parameters-table`, маппинг
макросов трафик-сорса на параметры кампании), **Postbacks** (`app-campaign-postbacks`), **Notes**
(textarea, авто-высота через `msd-elastic`).

Клиентские фичи:
- **Автосейв**: `form-on-change="$ctrl.autosave()"` на теге `<form>` — debounce 3000мс
  (lodash `_.debounce`), срабатывает только если `autosaveEnabled` (управляется настройкой
  `campaign_autosave` в Settings → Main) **и** кампания уже существует (`campaign.id` задан —
  при создании автосейва нет).
- **unsavedFormService** — не даёт уйти со страницы/закрыть модалку с несохранённой формой,
  подписывается на `$transitions.onStart` (ui-router hook) + `$scope.$on('modal.closing')`,
  показывает нативный `window.confirm()`.
- Правая колонка формы — виджет `<streams>` — это встроенный React-виджет (см. раздел 1) со
  списком стримов кампании и drag&drop управлением их порядком/весами прямо на странице кампании
  (без выхода в отдельный экран).
- Экспорт/импорт стримов кампании в TDS JSON-формате (модалки `streams.import` — POST
  `?object=streams.import` через `ngf`, и `streams.export` — вероятно, через отдельный `Stream`-ресурс,
  не найден отдельным action в общем `?object=streams.*` списке — стоит перепроверить бэкенд
  вручную, см. раздел 4).
- "Clean stats" / "Update costs" (кнопки в `appCleaner`, `cleanerService`) — модалки очистки
  статистики и пересчёта costs кампании; ACL-проверка `aclService.canEdit('campaigns', ...)`
  перед открытием.
- Копирование ссылки кампании (`campaignService.copyLink`) — собирается через
  `codeConstructorService.create().setAlias/setDomain/setParameters().rebuild().getUrl()` и
  копируется в буфер через `clipboard.copyText()` (сервис-обёртка, вероятно на нативном Clipboard
  API/execCommand, не отдельная npm-либа).

### 3.2 Streams
Streams не имеют собственного самостоятельного списочного экрана верхнего уровня (нет пункта
меню "Streams" отдельно от кампании) — состояние `streams` (~135811) абстрактное и используется
только как контейнер маршрутов; реальный UI стримов — виджет `<streams>` внутри карточки
кампании, **целиком реализованный на React** (см. раздел 1, компонент `wR`/`streams`,
~135802): `ve.a.createElement(IT, {$injector}, ...QT...VT...m$...sD...bR...)` — вложенные React
Context/Provider'ы плюс отдельные под-компоненты (вероятно: список, тулбар, фильтры, drag&drop).

Ресурс `Stream` — `?object=streams.:action` (~129119): `index` (`prepare: true` параметр —
видимо, бэкенд что-то досчитывает/готовит перед выдачей), `favourites`, `listAsOptions`,
`perform` (без явного `action` — вызов какого-то дефолтного POST-экшена, возможно "тестовый
прогон" стрима), `search`, `deleted` (архив), `replace` (замена одного стрима на другой везде,
где он используется — offer/landing внутри стрима), `update` (POST), `createInCampaign` (POST),
`delete` (POST, `{ids: [...]}`, батч).

**Схема стрима** (`streamForm.getSchemaFields`, ~127347–127450) — три логических блока формы:
1. **main** — `name`, `type` (radio: `weight` / `regular` / `forced` / `default` — опции
   приходят с бэка через `?object=streamTypes.listAsOptions`), `weight` (только если
   `type=weight`), `position` (скрыт для `type=default`, для `weight`-кампаний виден только
   при `type=forced`), `collect_clicks` (switch), `state` (active/disabled switch).
2. **schema** — радио `schema` с опциями из `?object=streamSchemas.listAsOptions` (в коде видно
   как минимум значения `landings` и `offers`, т.е. схема "куда лить трафик": на страницу-лендинг
   (с последующим офером) или прямо на оффер). При выборе `landings`/`offers` показываются
   `<app-stream-landings>` и `<app-stream-offers>` — таблицы выбора конкретных лендингов/офферов
   (мульти-select + веса/сплит-тест внутри стрима). Компонент `<stream-actions
   category="model.schema" ... exclude-actions="['local_file']">` показывается, когда
   `schema != 'landings'` — то есть третий вариант схемы — это "action" (произвольное действие:
   редирект на URL, показ 404, вызов внешнего URL и т.п., ровно так же, как action у
   офферов/лендингов, см. ниже). Список доступных экшенов приходит с бэка через
   `?object=streamActions.index`.
3. **triggers** — `<app-stream-triggers>` (скрыт для `type=default`). Триггер — объект
   `{target, condition, action, interval}` (дефолт при добавлении: `target:"stream",
   condition:"not_respond", action:"disable", interval:60`секунд). Списки допустимых
   target/condition/action приходят с бэка (`?object=Triggers.targets|conditions|actions`,
   кэшируются). Сохранение — `POST ?object=Triggers.update` (isArray) — т.е. сохраняются все
   триггеры разом списком. **Триггеры доступны только в Pro/Business/Trial редакциях** —
   `licenseService.isProEdition()||isBusinessEdition()||isTrialEdition()`; в Basic-редакции блок
   скрыт/задизейблен.

**Фильтры таргетинга стрима** (`streamFilters`, ~128390 область, модуль
`components.streams.filters`) — список активных фильтров хранится как массив
`streamFilters` + флаг `filtersOr` (переключение семантики AND/OR между фильтрами всего стрима).
Доступные типы фильтров приходят с бэка (`?object=streamFilters.filters`), опции для конкретных
значений — через общий справочник `Collection` (`?object=collections.:action&limit=:limit`,
~113152): `browsers`, `countries`, `regions`, `languages`, `os`, `cities`, `isp` (лимит 10 по
умолчанию), `deviceModels` и т.д. — все с `cache: true`. Известные вариации фильтров-виджетов
(React-select обёртки): version, region, operator, language, а также специальный
**`hide_click_detect_filter`** (~128386) с полями `paranoia`/`allowvpn` — это фильтр, завязанный
на интеграцию "HideClick" (anti-fraud/click-cloaking сервис, см. Integrations ниже).
**Trial-редакция ограничена максимум 2 фильтрами на стрим** (`streamFilters.length >= 2` →
`notificationService.error('exceptions.not_available_in_trial')`).

### 3.3 Offers (`/offers`)
Состояния (~130301): `offers` → `offers.index` (`appOffers`, грид).
Ресурс `Offer` — `?object=offers.:action` (~113237): `index`, `listAsOptions`, `create`,
`update`, `clone`, `archive`, `getCostTypes`, `deleted` (архив). Форма (`offerForm`, ~130169):
`name`, `affiliate_network_id` (`vAffiliateNetwork`, с опцией "0 = default" + кнопка создать
новую партнёрку не выходя из формы, `appAffiliateNetworkButton`), `offer_type` — button-group из
`streamActionService.getActionCategories()`, дальше тот же переиспользуемый компонент
`<stream-actions category="model.offer_type" ... exclude-actions="['curl']" archive=...
local-path=... preview-path=... type="'offer'">`, что и в стримах/лендингах — т.е. у оффера
точно так же настраивается "экшен" (redirect на внешний URL / залитый архив локального
лендинга-заглушки конверсии / и т.п.), плюс поля `group_id` (`vGroup`), `country`
(`vCountriesField`), `payout_type` (радио из `Offer.getCostTypes()`), `payout_value` +
`payout_currency` (группа полей, `payout_value` дизейблится, если `model.payout_auto`).
После сохранения формы — если включена опция "добавить ещё одну" (`addAnotherOne`), поля
`name/action_payload/archive/local_path/action_options/preview_path` сбрасываются и форма
переиспользуется для следующего оффера без закрытия модалки.

### 3.4 Landings (`/landings`)
Состояния (~130741): `landings.index` (`appLandings`), `landings.instruction`
(`appLandingInstruction` — вероятно, страница-подсказка "как самостоятельно залить лендинг").
Ресурс `Landing` — `?object=landings.:action` (~113322): `index`, `listAsOptions`, `create`,
`update`, `clone`, `archive`, `deleted`, `restore`, плюс отдельный upload:
`?object=landings.uploadArchive` (POST, загрузка ZIP-архива лендинга — вероятно через
`ng-file-upload`, аналогично Streams import).
Форма (`landingForm`, ~130738) переиспользует ту же связку `<stream-actions type="'landing'">`
для описания того, что происходит при заходе на лендинг (свой архив / внешний action).

**Локальный редактор кода** — отдельный экран, НЕ часть формы лендинга:
`state("editor", {url: "/editor/:type/:landingID", component: "editorView"})` (~120446).
`editorView` — полноценное **React-приложение** (см. раздел 1), под капотом использующее
**Ace Editor** для редактирования файлов (иконки для php/html/css и т.д. заведены прямо в коде,
~120450). Ресурс `Editor` — `?object=editor.:action` (~121393): `loadFiles` (дерево файлов),
`loadFileData`, `infoLanding`, `saveFileData`, `createFile`, `removeFile`. Ссылка на редактор,
судя по всему, открывается из грида лендингов/офферов (`type` параметр различает
`landing`/`offer`, т.к. и у офферов есть "локальный путь"/архив). Скачивание архива лендинга —
прямая ссылка `?object=<type>s.download&id=<id>` (не через Angular `$resource`, ~128710).

### 3.5 TrafficSources (`/traffic_sources`)
Состояния (~129648): `traffic_sources.index` (`appTrafficSources`).
Ресурс `TrafficSource` — `?object=trafficSources.:action` (~113412): `index`, `listAsOptions`,
`getAvailableParameters` (макросы трекинга, которые трафик-сорс подставляет в URL — используется
в `parameters-table` на странице кампании), `getPostbackStatuses`, `create`, `update` (не в
дампе явно, но по паттерну остальных сущностей есть), `clone`, `archive`.
Есть отдельный `TrafficSourceTemplate` ресурс (`?object=trafficSourceTemplates.:action`, ~129628)
— готовые шаблоны популярных трафик-сорсов (имя/параметры/URL макросов уже преднастроены,
компонент `trafficSourceTemplateSelector`), чтобы не заполнять форму с нуля.
Постбеки (`app-campaign-postbacks` на странице кампании) настраиваются в привязке к
трафик-сорсу — конкретный сервис "postbackBuilderService" инжектится в `campaignForm`, но его
код не разбирался подробно (см. раздел 4 — не критично для общей картины, т.к. схема постбеков
уже задокументирована в `docs/BUG_PATTERNS.md`/`docs/FIXES_LOG.md` со стороны бэкенда).

### 3.6 Domains (`/domains`)
Состояния (~111196): `domains.index` (`appDomains`).
Ресурс `Domain` — `?object=domains.:action` (~110861): `index`, `listAsOptions`, `updateStatus`,
`create`, `clone`, `archive`, `update`, `delete`.
Форма (`domainForm`, ~111079): `name` (задизейблено для уже созданных доменов, если
`network_status !== 'active'` — похоже на флаг успешной проверки DNS/сертификата на бэке),
`ssl_redirect` (switch), `wildcard` (switch, помечен как deprecated с предупреждением про SSL),
`allow_indexing` (radio allow/disallow, показывается только для некоторых типов доменов —
параметр `i` в `getFields(r, i)`), `default_campaign_id` (select кампании — "поймать" весь трафик
без alias на этот домен), `catch_not_found` (switch, виден только если задан
`default_campaign_id` — переключает 404 на редирект на дефолтную кампанию).

### 3.7 Users / Groups / ACL (`/users`)
Состояния (~116343): `users.test`, `users.index` (`appUsers`).
Ресурсы: `User` — `?object=users.:action` (~114454): `changeCurrentUserPassword`,
`setAccessData`, `show`; `Profile` — `?object=profile.:action` (~114518): `getCurrentAccess`,
`getLanguages`, `getTimezones`; `UserPreference` — `?object=userPreferences.:action`: `get/set`;
`Resource` — `?object=resources.:action`: `getMandatory` (обязательные ресурсы, которые нельзя
запретить), `getComplementaryAsOptions` (дополнительные ресурсы, которые можно включить в ACL);
`ApiKey` — `?object=apiKeys.:action`: `getAll`, `add`, `delete`.

**Модель ACL** (`userAccessService`, ~114630 область): сущности, к которым применяется
разграничение доступа — `campaigns`, `offers`, `landings`, `traffic_sources`,
`affiliate_networks`, `domains`. Уровни доступа (`access_type`, 4 варианта в UI):
`full_access`, `read_only`, `to_groups_and_selected`, `created_by_user_groups_and_selected`
(плюс в коде `isCreateAllowedType` встречаются ещё старые/скрытые значения `created_by_user` и
`created_by_user_and_groups`, которые больше не предлагаются в выпадающем списке настроек, но
логика их всё ещё понимает — вероятно легаси с более старой версии ACL). Для типов, работающих
"по группам/выбранным" (`to_groups_and_selected`, `created_by_user_groups_and_selected`),
дополнительно выбираются конкретные группы (`<entity>_selected_groups`) и/или конкретные
элементы (`<entity>_selected_entities`) через switch-массивы (`toSwitchArray`/`fromSwitchArray`
— массив id ⇄ объект `{id: true}` для чекбоксов). Отдельно — доступ к отчётам (`reports`, набор
колонок из `Reports.columnsAsOptions()`) и к прочим "ресурсам" (пункты меню/фичи —
`resource_names`, приходит из `configService.get('resource_names')`, т.е. бэкенд диктует список
доступных модулей всего приложения). Сохранение — `POST ?object=users.setAccessData`
`{user_id, access_data: {...}}` одним запросом.
`aclService`/`currentUserService`/`userAccessService` кэшируют вычисленные решения (`canCreate`,
`canEdit`, `isResourceAllowed`) в объекте-словаре в памяти (`resetCache()` сбрасывает).

Групповая сущность (`Group`) — `?object=groups.:action` (~113001): `index`, `cachedQuery`,
`listAsOptions` — используется как "папки"/теги для Campaigns/Offers/Landings (поле `group_id`,
формли-тип `vGroup` с возможностью создать группу прямо из выпадающего списка).

### 3.8 GeoDb (`/geo_dbs`) и GeoProfiles (`/geo_profiles`)
`geo_dbs.index` (`geoDbs`) — управление подключёнными GeoIP-базами. Ресурс `GeoDb` —
`?object=geoDbs.:action` (~112372): `index`, `update`, `settings`, `saveSettings`. Отдельный
`IpInfoDataTypes` (`?object=ipInfoDataTypes.index`) — какие типы данных (страна/регион/город/
ISP/...) вообще доступны — используется для UI выбора, какая база за какой тип данных отвечает.

`geo_profiles.index/create/edit` (`appGeoProfiles`/`appEditGeoProfile`) — именованные наборы
стран (`GeoProfile`, `?object=geoProfiles.listAsOptions`), которые потом можно one-click
подставлять в поле `vCountriesField` (стримы/офферы) вместо ручного выбора списка стран каждый
раз.

### 3.9 Reports (`/reports`) + графики Chart.js
Состояния (~114405): `reports.exported_reports` (`appExportedReports`), `reports.index`
(`appReport`, обычный отчёт), `reports.favourite` (`appReport` с `:id` избранного отчёта).
Ресурс `Reports` — `?object=reports.:action` (~113623): `definition` (кэшируется — описание всех
доступных группировок/метрик/колонок для конструктора отчёта), `build` (POST, собственно
построение отчёта по заданным `grouping`/`metrics`/`range`/фильтрам), `summary` (POST, итоговая
строка), `columnsAsOptions`, `getParameterAliases`. `ExportedReports` — `?object=exportedReports.:action`:
`index`, `delete`, `deleteAll` — список асинхронно сформированных экспортов (см. ниже про Grid
export). `FavouriteReports` — `?object=favouriteReports.index` — сохранённые конфигурации отчёта
(`payload` = сериализованное состояние `favouriteReportsService.getState()`).

Дефолтная конфигурация отчёта (`appReport`, ~114390): `grouping: ["campaign"]`,
`metrics: ["clicks", "campaign_unique_clicks", "conversions", "roi_confirmed"]`, `limit: 100`,
`range: {interval: "today", timezone}`. График — `chart-line` (angular-chart.js/Chart.js) со
своей легендой `<chart-legend>`, переключаемой видимостью серий (`chartVisibilityService`).

### 3.10 Clicks / Conversions logs (`/clicks`, `/conversions`)
`clicks.log`/`clicks.campaign_log` (`clickLog`) и `conversions.log`/`conversions.campaign_log`
(судя по симметричной структуре — компонент, аналогичный `clickLog`) — это по сути тот же общий
`Grid`-компонент, что и списки сущностей, только источник данных — `Clicks`/`Conversion`.
`Clicks` — `?object=clicks.:action` (~118128): `log` (POST, сама выдача построчного лога кликов),
`logDefinition` (кэш, описание колонок). Дефолтные колонки клик-лога (жёстко заданы в коде,
~118203): `sub_id, datetime, ip, campaign_id, stream_id, landing, offer, ts, affiliate_network,
country_flag, region, city, os_icon, browser_icon, connection_type, device_type, device_model,
is_bot, is_unique_campaign`. Grid настроен с `pagination`, `sorting`, `fixed` (закреплённые
колонки), `save_state` (состояние грида — выбранные колонки/сортировка/фильтры — сохраняется,
судя по всему в `userPreferenceService`/localStorage), `export_enabled`, `replace_tokens`
(вероятно, замена макросов кампании в значениях), `reset_selection_on_outside_click`. Клик по
строке (`grid:row_clicked` событие) открывает `rowDetailsService.show(row, grid, 'click_id')` —
попап с деталями клика (тот самый React-компонент `detailsTableReact`).

`Conversion` — `?object=conversions.:action` (~118225): `import` (POST — ручной импорт
конверсий, например из CSV/постбека партнёрки задним числом), `logDefinition`,
`updateCostDefinition`, `log` (POST, `withSummary: true` — лог конверсий отдаёт ещё и summary
одним запросом).

### 3.11 Settings (`/settings`)
Состояние `settings.index` (`appSettings`), 4 вкладки/формы (все через один `?object=settings.:action`
ресурс, ~126294: `get`(index), `save`(POST), `config`, `getAuxiliaryData`, `find`):

- **Main** (`settingsMainForm`, ~126666): `currency` (vUiSelect из `Dics.getCurrencies()`),
  `campaign_autosave` (radio yes/no — включает автосейв кампаний, см. 3.1), `lp_dir` (папка
  лендингов на сервере, с валидатором "не пустая строка"), `lp_allow_php`, `lp_php_timeout`
  (секунды), `cookies_enabled` (**инвертированная** логика: `value:"0"` = "да", `value:"1"` =
  "нет" — обратить внимание при пересборке бэкенда, это легко перепутать), `s2s_timeout`
  (секунды), при `default_action_allowed==='1'` — доп. блок настроек домена по умолчанию
  (`extra_action`: `redirect`/`not_found`/`campaign`, `extra_url`, `extra_campaign` — этот блок
  помечен как **deprecated** в UI, `settings.default_action.action_deprecate`), `ingore_prefetch`
  (⚠ опечатка в реальном ключе поля — "ingore" вместо "ignore", жёстко зашита и на фронте, и,
  видимо, совпадает с бэкенд-ключом — переносить как есть, не "исправляя", иначе сломается
  сохранение), `show_extra_param`, `is_sidebar_enabled`, плюс панель "Aliases" — по одному
  текстовому полю на каждый `ts_parameters` (алиасы макросов трафик-сорса, скрыты, если начинаются
  с `extra_param_` и `show_extra_param !== '1'`).
- **System** (`settingsSystemForm`, ~126849): `stats_ttl` (дней), `lp_offer_token_ttl` (минут,
  клиентски ограничено максимумом 43200 = 30 дней — `onChange` обрезает значение), `cache_storage`
  (select из `auxiliaryData.cache_storages`), `draft_data_storage` (select, есть предупреждение
  "redis не установлен", если `!redis_installed`), `redis_server` (только если фича-флаг
  `configService.get('redis_uri_in_settings')` и выбран `draft_data_storage==='redis'`),
  `disable_stats` (**тоже инвертировано**: `0`="да, собирать статистику", `1`="нет"),
  `force_token_files`, `archive_ttl` (дней).
- **Bots** (`settingsBotsForm`, ~126595): `check_bot_ua`, `check_bot_ip`, `check_bot_empty_ua`,
  `detect_spam_bots` (все — да/нет radio), плюс два кастомных виджета — список забаненных IP
  (`bot_ips`) и список бот-сигнатур User-Agent (`bot_signature`) со своими save/add/exclude/clear
  экшенами через отдельный ресурс `Botlist` (`?object=botlist.:action`, ~126324):
  `getBotListCount`, `getBotList`, `saveBotList`, `addBotList`, `excludeBotList`, `clearBotList`,
  `getBotSignatureCount`, `getBotSignature`, `saveBotSignature`.
- **Integration** (`settingsIntegrationForm`, ~126649): единственное реальное поле — `api_key`
  ("Click API Key" — интеграция с внешним антифрод/кликандер-сервисом Click, судя по названию),
  плюс информационный блок про GeoDB/интеграции.

### 3.12 Migrations (`/migrations`)
`migrations.index` (`appMigrations`). Ресурс `Migration` — `?object=migrations.:action`
(~126972): `index`, `appliedList`, `run` (POST), `moveToTokuDb` (отдельная миграция БД в
TokuDB-движок — специфика конкретного MySQL-форка, использовавшегося в проекте-доноре). Отдельно
`LegacyMigration` — `?object=legacyMigrations.:action` (~127003): `index`, `schemaInfo`, `run` —
похоже на два поколения системы миграций (старая/новая), сосуществующих в одном экране.
UI показывает статус (применена/не применена/выполняется — polling), даёт "выполнить все" либо
по одной.

### 3.13 Dashboard (`/dashboard`)
`dashboard.index` (`appDashboard`, resolve `dashboardService.preload()`). Дашборд переиспользует
`Reports.definition()` для списка доступных метрик (`dashboardService.getAvailableMetrics()`), у
пользователя настраиваются "включённые" метрики-виджеты (`dashboardPreferences` сервис — судя по
всему, хранится через `userPreferenceService`/localStorage — не 100% прояснено, см. раздел 4) и
отдельно есть виджет "последние клики" (`appDashboardLastClicks`, компонент, дергающий `Clicks`
напрямую). Диапазон дат — тот же daterangepicker с `chart` query-параметром в URL (позволяет
делиться ссылкой на конкретный вид дашборда).

### 3.14 AdminApi / Swagger
Отдельного Angular-экрана **нет**. На странице Users → API Keys есть просто ссылка
`<a href="?object=adminApi" target="_blank">` (~85724) — открывает бэкенд-страницу
документации API (предположительно Swagger UI, рендерится сервером, вне SPA). Т.е. с точки
зрения фронтенд-реверса эта функция вне зоны ответственности Angular-бандла — сама
документация API генерируется бэкендом (искать в `application/Admin` контроллер, отвечающий на
`object=adminApi`).

### 3.15 Прочие экраны, не входившие явно в список задания, но найденные в карте состояний
Для полноты (все получены из общего списка `.state("...")`):
- `self_update` (`selfUpdate`) — проверка/установка обновлений системы (`SelfUpdate` ресурс:
  `getUpdate`, `update`, `isOldPHP`) + бета-канал обновлений.
- `status` (нет отдельного top-level route, но `Status` ресурс — `getInfo`, `getInstall`,
  `warmupCache`, `restartRr` (RoadRunner — PHP application server, рестарт которого доступен
  прямо из UI) — используется в модалке "Диагностика/статус системы".
  `Diagnostic`-directive в топ-баре (~111971) периодически дергает `?object=diagnostics.index`.
- `integrations` (~121468: index/avscan/imklo/hideclick/facebook/appsflyer) — экран интеграций
  с внешними антифрод/трекинг сервисами: **AVScan**, **IMKlo**, **HideClick** (см. фильтр
  `hide_click_detect_filter` в 3.2), **Facebook** (Conversions API/Pixel), **AppsFlyer**. Все —
  React-компоненты (см. раздел 1). Есть отдельная сущность "mandatory" интеграций
  (`tpimandatory` ресурс) — интеграции, которые надо принудительно подключать к кампаниям
  (`addCampaign`/`removeCampaign`/`all`).
- `search` (~119175) — глобальный поиск (`appSearchResults`), с массовыми операциями над
  найденными стримами (`streamsTableMassActions`) — то есть это, похоже, поиск конкретно по
  стримам (где ещё используется этот оффер/лендинг/трафик-сорс).
  Дальше в коде на грепе я явно не увидел явного "поиск по кампаниям" — фактически "search" может
  быть узкоспециализированным ("где ещё используется офер/лендинг").
- `logs` (~136146, `logsNewPage`) и отдельно старые `SystemLog`/`PostbacksLog`/
  `SentPostbacksLog`/`TrafficLog`/`EnableSSLLog` ресурсы (все под одним `?object=logs.:action`,
  разные `action`: `system/deleteSystem`, `postbacks/deletePostbacks`,
  `sentPostbacks/deleteSentPostbacks`, `traffic/deleteTraffic`,
  `enableSSL/deleteEnableSSL/enableSSLLogFile`) — сервисные логи (не путать с click/conversion
  логами из 3.10): системный лог, лог принятых постбеков, лог отправленных постбеков (трафик-сорсу),
  лог обращений к трекеру, лог получения SSL-сертификатов (Let's Encrypt, судя по имени).
  Есть и старый, и новый (`logsNewPage`/`logsReactTable`, React) UI одновременно — похоже на
  незаконченную миграцию экрана логов на React.
- `affiliate_networks` (~136447) — почти зеркало Offers/TrafficSources: `AffiliateNetwork`
  ресурс (~113511: `gridDefinition`, `instructions`, `query`, `listAsOptions`, `create`,
  `update`, `clone`, `archive`), плюс `AffiliateNetworkTemplate` (готовые шаблоны популярных
  партнёрских сетей, аналогично TrafficSourceTemplate) и `pullApiOptions` (компонент настройки
  Pull-API конкретной сети — периодический импорт офферов из партнёрки).
- `trends` (~136685) — отдельный отчёт-грид с возможностью группировки по произвольной колонке
  (`appTrendUnitSelect`), ресурс `Trends` — `?object=trends.definition`.
- `home` (~120436) — простой `HomeController`, вероятно, редиректит на dashboard/campaigns в
  зависимости от прав пользователя (не разбирался детально).
- `profile` (~127314, `profile.edit`) — личный кабинет пользователя: смена пароля
  (`User.changeCurrentUserPassword`), язык/таймзона (`Profile.getLanguages/getTimezones`).
- **Labels** (не отдельный экран, а фича внутри грида кликов/кампаний) — ресурс `Label` —
  `?object=labels.:action` (~106808): `labelVariations`, `refNameVariations`, `query`(index),
  `update`, `replaceList` — вайтлист/блэклист-подобная система меток (использует
  `labelManager`/`filterManager` грида), с настройкой разделителя (запятая/новая строка,
  `useCommas`, хранится в `store` (localStorage)).
- **Branding** (`/branding`, модалка, не полноценный route) — `Branding`-ресурс (~111211,
  `get`/`update`) — кастомный логотип/favicon, доступно, судя по коду, не во всех лицензиях
  (`licenseService`/`configService.get('custom_logo')`).
- **Cleaner** (`appCleaner`) — отдельная фича очистки статистики кампании, `Cleaner`-ресурс
  (~116442, единственный экшен `clean`, POST).

---

## 4. Общая архитектура Grid (используется почти во всех списочных экранах)

Все таблицы списков (Campaigns/Offers/Landings/TrafficSources/AffiliateNetworks/Clicks/
Conversions/TrafficLogs/Reports) построены на одном переиспользуемом `Grid`-сервисе
(`shared.grid`, factory `Grid`/`GridDataManager`/`GridColumnManager`/`GridColumn`/
`GridFilterManager`, ~109100) + React-компоненте `gridReact` для собственно рендера строк.
Общие конфиг-флаги, которые передаются в `new Grid(definition, options)` по месту использования:
`user_columns` (пользователь сам выбирает видимые колонки), `pagination`, `sorting`/
`virtual_sorting`, `fixed`/`fixed_columns` (закреплённые слева колонки), `save_state`
(состояние грида персистится — колонки/сортировка/фильтры/диапазон), `export_enabled`,
`selection_enabled` (чекбоксы строк + массовые операции), `metrics`/`summary` (подмешивание
отчётных метрик прямо в строки грида — например, для TrafficSources и Offers к каждой строке
подтягивается profit/CR/EPC и т.п.), `reset_selection_on_outside_click`, `disable_pagination`
(вместо страниц — вероятно виртуальный скролл, actual для TrafficSources/Offers).

**Экспорт грида** (`gridExport`/`downloadService`, ~106744): поддерживаются форматы `csv` и
`html`; экспорт — асинхронный (сервер генерирует файл и отдаёт `{url}`), после чего `csv`
скачивается через невидимый `<iframe src="...">` (чтобы не потерять текущее состояние SPA), а
`html`-отчёт открывается в новом окне (`window.open`). Список ранее сгенерированных экспортов —
экран Reports → Exported Reports (`ExportedReports` ресурс, см. 3.9).

**Серверные ошибки в гриде-фильтрах / grid-специфичные UI-виджеты** (`groupFilter`,
`affiliateNetworkFilter` и т.п., например ~118543) — специальные компоненты, которые прячут
дефолтный авто-сгенерированный фильтр по полю (`grid.filterManager.hide(fieldName)`) и
подменяют его собственным (например, select группы вместо текстового ввода `group_id`).

---

## 5. Необъяснённые / непонятные моменты (для доразбора руками)

1. **`?object=streams.export`** — в модалке экспорта стримов кампании (`components.campaigns
   .streams.export_modal`, контроллер вызывает `this.Stream.export({campaign_id}, cb)`) явного
   `?object=streams.:action` с `action:"export"` в общем ресурсе (~129119) я не нашёл — возможно,
   у `Stream`-ресурса (не `campaignService`) есть отдельный дополнительный `$resource`-экшен,
   объявленный в другом месте бандла, который я не отследил при построчном разборе. Нужно
   перепроверить конкретный вызов `Stream.export` в контексте (класс объявлен на ~117918,
   `zm = r(1045)` — модуль 1045 в webcrack, стоит прочитать отдельно).
2. **`autosave` для стримов/офферов/лендингов** — точно подтверждён только для Campaigns
   (`form-on-change="$ctrl.autosave()"` в шаблоне кампании). Не проверялось, есть ли аналогичный
   автосейв у формы стрима внутри React-виджета `streams` — учитывая, что стримы теперь React, а
   не formly/Angular-форма, механизм там может быть принципиально другим (не искалось внутри
   React-кода, т.к. он geht глубоко в отдельные вебпак-модули без человекочитаемых имён).
3. **`dashboardPreferences` сервис** — не нашли явного `$resource`/`?object=` вызова для него;
   похоже, что настройки дашборда (включённые метрики-виджеты, их порядок) хранятся чисто
   клиентски (через `userPreferenceService`/`store`/localStorage), без отдельного бэкенд-эндпоинта
   — но это не проверено на 100%, стоит перепроверить, дергается ли `userPreferences.set` при
   изменении дашборда.
4. **Snap.js / slide-sidebar библиотека** — по API (`snapRemote.toggle/open`, события
   `animated/open/close` на "snapper"-объекте) очень похоже на **Snap.js** (jakiestfu), но
   явного `@license`/копирайт-комментария от этой библиотеки в `app.js.LICENSE.txt` нет и в
   самом бандле построчным grep не нашёлся — либо лицензионный комментарий вырезан минификатором
   без сохранения (не должно происходить при стандартной сборке с `terser` + license-plugin), либо
   это самописная мини-обёртка "под" Snap.js API. Разница важна для пересборки — если это внешняя
   либа, её у нас нет в списке зависимостей вообще.
5. **`postbackBuilderService`** (используется в `campaignForm`) — не разбирался подробно (не
   нашёл его определение по прямому имени переменной за разумное время грепа — вероятно, из-за
   переименования переменной в другом чанке бандла). Схема постбеков в целом уже описана со
   стороны бэкенда в `docs/BUG_PATTERNS.md`/`docs/FIXES_LOG.md`, но связка "какие макросы
   показываются в UI-конструкторе постбека" не сверялась 1-в-1 с тем, что реально шлёт бэкенд.
6. **`?object=search` (endpoint для global search)** — не нашли отдельного `$resource`/явного
   HTTP-вызова с именем "search" объекта; `search.index` резолвит просто `$stateParams.query` и
   рендерит `appSearchResults` — сам HTTP-запрос, видимо, прячется внутри
   `streamsTableMassActions`/`appSearchResults` контроллера, который не удалось быстро
   локализовать в общем потоке минифицированного кода (много одно-двух-буквенных идентификаторов
   с одинаковыми именами переиспользуются в разных областях видимости webpack-модулей — grep по
   короткому имени даёт десятки нерелевантных совпадений).
7. **i18next namespaces / переводы** — подтверждено, что используется i18next (плюс react-i18next
   для React-части), но откуда фактически подгружаются JSON-словари переводов (отдельные `.json`
   файлы по языкам, встроенные в бандл ресурсы, или отдельный HTTP-бекенд i18next) — не
   исследовалось; не входило в изначальный список библиотек для проверки, но раз i18next оказался
   "скрытой" зависимостью, вопрос "куда положить файлы локализации при пересборке с нуля" остаётся
   открытым.
8. **`ingore_prefetch`** (см. 3.11, Settings → Main) — сохранён специально с опечаткой "ingore"
   вместо "ignore": это выглядит как исторический баг в имени поля, замороженный в API-контракте
   между фронтом и бэкендом. При пересборке с нуля соблазн "исправить" опечатку — при этом бэкенд
   ожидает именно `ingore_prefetch`, иначе настройка тихо перестанет сохраняться/читаться
   (полная аналогия с уже описанными в `docs/BUG_PATTERNS.md` типами багов "поле называется не
   так, как кажется логичным").
9. **Инвертированная семантика `cookies_enabled`/`disable_stats`** (см. 3.11) — `"0"`/`0` в UI
   означают "включено"/"да", а `"1"`/`1` — "выключено". Это не баг фронтенда (значения
   действительно завязаны на реальные имена бэкенд-полей `cookies_enabled`/`disable_stats`,
   логика инвертирована по смыслу самого имени поля), но при описании контракта бэкенда стоит явно
   пометить эти два поля как "инвертированные", чтобы не перепутать при ручном тестировании через
   Postman/curl.
10. **Жёстко закодированная русская строка** `nullDisplay: "Не выбрано"` в
    `settingsMainForm.getDomainSettingsFields` (~126790) — единственное найденное место, где
    вместо `translationService.translate(...)` в форме используется хардкод на русском в
    формли-конфиге (при том что весь остальной интерфейс полностью локализован через i18next).
    Если понадобится английская/другая локаль в Settings → Main → "Кампания по умолчанию" —
    придётся руками поправить именно это место в бандле/при пересборке.
