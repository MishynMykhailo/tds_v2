# Design Reference — TDS Admin (Keitaro-like)

Дизайн-документация текущей админки, собранная **эмпирически** через Playwright
(`getComputedStyle` на живом рендере, залогинившись как `admin`), а не грепом по
минифицированному `admin/assets/app.css` (26 строк на 1.3 МБ — статический анализ
бесполезен). Все hex-коды в этом документе — реальные вычисленные значения браузера
на момент снятия (Chromium headless, viewport 1600×1200/1300), не предположения.

Скриншоты лежат в `docs/frontend/design/screenshots/` (пути ниже — относительно
`docs/frontend/`). Дополняет `docs/frontend/frontend_analysis.md` (поведение) и
`docs/frontend/backend_api_reference.md` (API) — этот файл только про внешний вид.

---

## 1. Цветовая палитра

### 1.1 Базовые/нейтральные

| Назначение | Hex | rgb() (как вычислено) | Где используется |
|---|---|---|---|
| Фон страницы/body | `#FFFFFF` | rgb(255,255,255) | Весь фон контента |
| Основной текст | `#212529` | rgb(33,37,41) | Body text, заголовки h1 |
| Вторичный текст (хелп-тексты под полями) | `#848484` | rgb(132,132,132) | Settings → System, подсказки под полями форм |
| Приглушённый текст (счётчики типа "Metrics 11") | `#999999` | rgb(153,153,153) | Счётчик рядом с кнопкой "Metrics" в тулбаре грида |
| Инпут-текст по умолчанию | `#555555` | rgb(85,85,85) | Текст внутри `.form-control` |
| Верхний тёмный бар (логотип/хамбургер/поиск/admin-меню) | `#1B2C37` | rgb(27,44,55) | `.top-navbar.navbar-inverse`, скриншот `screenshots/01_dashboard.png` |
| Второй уровень навигации (Dashboard/Campaigns/...) фон | `#F8F8F8` | rgb(248,248,248) | Меню сразу под тёмным баром |
| Ссылка второго уровня навигации (неактивная) | `#444444` | rgb(68,68,68) | Пункты меню Dashboard/Campaigns/... |

### 1.2 Брендовые/акцентные

| Назначение | Hex | rgb() | Где используется |
|---|---|---|---|
| Логотип "Keitaro" (SVG fill) | `#76B46C` | rgb(118,180,108) | `<k-logo-wrapper>` SVG, левый верхний угол |
| **Основной акцент (зелёный)** — кнопки Create/Save, активный статус, подчёркивание активной вкладки верхнего меню | `#6EB371` | rgb(110,179,113) | Кнопка "Create" (campaigns/offers/...), точка статуса "active" в гриде, `border-bottom: 2px solid` у активного пункта верхнего меню, бейдж "Pro" в Integrations |
| Акцент hover (кнопка при наведении) | `#56A55A` | rgb(86,165,90) | Hover-состояние `.btn-success`; также базовый цвет кнопок "Yes"/"Apply"/"Save" внутри модалок (немного темнее, чем кнопки на полной странице) |
| Акцент hover — border | `#529C55` | rgb(82,156,85) | Border кнопки в hover/в модалках |
| Ссылки (в гриде, "more detailed information" и т.п.) | `#3889C9` | rgb(56,137,201) | Название кампании в гриде, синие текстовые ссылки |
| Фокус-обводка инпута (glow) | `rgba(56,137,201,0.25)` | — | `box-shadow: 0 0 0 3.2px rgba(56,137,201,.25)` на focus |
| Bootstrap-синий (бейдж "Beta") | `#007BFF` | rgb(0,123,255) | Бейдж "Beta" в Integrations (Facebook/HideClick) |

### 1.3 Статусы/семантика

| Назначение | Hex | rgb() | Где используется |
|---|---|---|---|
| Danger/Delete (кнопка) | `#E25E63` | rgb(226,94,99) | Кнопка "Delete" в тулбаре массовых операций грида, `border` инпута в состоянии ошибки (`has-error input`) |
| Toast — ошибка (toastr) | `#BD362F` | rgb(189,54,47) | `.toast.toast-error` (всплывающее уведомление сверху справа), текст белый, `box-shadow: 0 0 12px rgba(153,153,153,1)`, `border-radius: 3px`, `padding: 15px 15px 15px 50px` (слева место под иконку) |
| Info-алерт (жёлто-бежевый) фон | `#F3F3DE` | rgb(243,243,222) | Плашки "No domains found", "There is no streams yet." (`.alert.alert-info`) |
| Info-алерт текст | `#414D54` | rgb(65,77,84) | Текст внутри info-алертов |
| Строка грида — hover | `rgba(37,208,137,0.08)` (база `#25D089` на 8%) | — | Наведение на строку в Grid (React `gridReact`) — лёгкий зелёный оттенок поверх каждой `<td>` |
| Строка грида — выбрана чекбоксом | `rgba(233,235,4,0.27)` (база `#E9EB04` на 27%) | — | Отмеченная чекбоксом строка — бледно-жёлто-зелёный оттенок |
| Статус "включено" (точка в гриде кампаний) | `#6EB371` | rgb(110,179,113) | `.grid-state.ion-record.grid-state-active`, размер шрифта иконки 10px |

### 1.4 Dashboard — KPI-виджеты (7 цветных карточек)

Порядок как на дашборде слева направо, цвет — фон карточки `app-dashboard-brief-list-item`,
текст поверх всегда белый, `border-radius: 3px`. Метка (label) — тот же цвет + `rgba(0,0,0,.1)`
поверх (слегка темнее).

| Метрика | Hex | rgb() |
|---|---|---|
| Clicks | `#DCA03B` | rgb(220,160,59) |
| Unique clicks for campaign | `#ED6C44` | rgb(237,108,68) |
| Conversions | `#C3A279` | rgb(195,162,121) |
| Cost | `#3B9963` | rgb(59,153,99) |
| Revenue (confirmed) | `#7D993B` | rgb(125,153,59) |
| Profit/loss (confirmed) | `#D07167` | rgb(208,113,103) |
| ROI (confirmed) | `#949FB1` | rgb(148,159,177) |

Эти же 7 цветов используются как цвета серий на линейном графике (Chart.js) и в легенде
под ним (`screenshots/01_dashboard.png`).

### 1.5 Таблица/грид

| Элемент | Hex/значение | Комментарий |
|---|---|---|
| Header-строка, фон | `#F5F5F5` | rgb(245,245,245) |
| Header-строка, текст | `#515151` | rgb(81,81,81), `font-weight: 400`, без `text-transform` |
| Обычная строка | фон прозрачный (наследует белый) | `.grid-row` |
| Ссылка-название в гриде | `#3889C9` | как в 1.2 |

### 1.6 Бейджи (badges)

| Бейдж | Фон | Текст | border-radius | font-size | padding |
|---|---|---|---|---|---|
| "Pro" (Integrations) | `#6EB371` | `#FFFFFF` | 4px | 12px | ~3px 5px |
| "Beta" (Integrations) | `#007BFF` | `#FFFFFF` | 4px | 12px | ~3px 5px |
| "Applied" (Migrations, статус) | `#6EB371` | `#FFFFFF` | 4px | ~10–12px* | ~2–4px* |

\* значения "Applied" пришли от браузера с дробными px (9.756px/2.439px) — похоже на
артефакт масштабирования рендера в headless Chromium, а не осознанный отдельный размер;
по факту бейдж визуально того же размера, что "Pro"/"Beta" (см. скриншот).

---

## 2. Типографика

- **Основной шрифт:** `Roboto, "Helvetica Neue", "Helvetica sans-serif"` — используется
  везде в Angular-части приложения. Базовый размер `16px` на `<body>`.
- **Моноширинный шрифт (Ace-редактор кода лендингов, `/editor/:type/:landingID`):**
  `Monaco, Menlo, "Ubuntu Mono", Consolas, source-code-pro, monospace`, размер `15px`,
  подтверждённая цветовая тема — светлая (белый фон, чёрный текст, синтаксис-хайлайтинг
  как в стандартной светлой Ace-теме). См. `screenshots/48_landing_code_editor.png`.
- **Заголовок страницы (h1)** — "Dashboard", "Users", "Integrations", "Code editor - test",
  "Migrations" и т.д.: `font-size: 33px`, `font-weight: 300` (light), цвет `#212529`,
  `margin-bottom: ~16.5px`.
- **Заголовок модалки** ("Create Offer", "Confirmation", "Access", "Edit Stream"):
  `font-size: 20px`, `font-weight: 400`.
- **Текст в гриде/таблицах:** `~13px`, `font-weight: 400`.
- **Текст полей форм/лейблы:** `16px`, `font-weight: 400`, цвет `#212529` (лейбл) /
  `#555555` (значение в инпуте).
- **Вторичный/хелп-текст под полем** (например, "For how many days the data is stored in
  DB..."): тот же `16px`, но цвет `#848484` — то есть отличается ТОЛЬКО цветом, не размером
  шрифта (важно: не 12–14px, как часто делают в других системах).
- **Бейджи:** `12px`.

Замечено: многие вычисленные размеры пришли с дробными px (`13.008px`, `16.504px`,
`33.008px`, `15.008px`) — похоже на артефакт нецелого масштаба рендера в тестовом
окружении (headless Chromium), а не сознательное дизайн-решение. При вёрстке с нуля
следует ориентироваться на округлённые "чистые" значения (13px/16.5px/33px/15px), а не
копировать дробные числа буквально.

---

## 3. Компоненты

### 3.1 Кнопки

| Вариант | Фон | Текст | Border | border-radius | padding | Пример |
|---|---|---|---|---|---|---|
| Primary/Success ("Create", "Save") | `#6EB371` | `#FFFFFF` | `1px solid #6EB371` | 2px | `6px 12px` | `screenshots/02_campaigns_index.png` |
| Primary hover | `#56A55A` | `#FFFFFF` | `1px solid #529C55` | 2px | тот же | — |
| Primary disabled (форма невалидна/pristine) | тот же `#6EB371` фон | `#FFFFFF` | тот же | 2px | тот же | `opacity: 0.65`, `cursor: not-allowed` |
| Secondary/Cancel/Groups | `#F3F3F3` | `#333333` | `1px solid #CCCCCC` | 2px | `6px 12px` | Кнопка "Groups", "Cancel" |
| Danger/Delete (bulk toolbar) | `#E25E63` | `#FFFFFF` | `1px solid #E25E63` | 2px | `6px 12px` | Кнопка "Delete" после выбора чекбоксов, `screenshots/30_campaigns_row_context_menu.png` |
| Модальные футер-кнопки (Yes/Apply/Save, на всю половину ширины) | `#56A55A` (не `#6EB371`!) | `#FFFFFF` | `1px solid #529C55` (top) | 0 (плоские, без радиуса — только у контейнера модалки) | `15px` | `screenshots/31_confirm_delete_modal.png` |
| Модальный Cancel (та же футер-зона) | `#F3F3F3` | `#333333` | `1px solid #CCCCCC` (top) | 0 | `15px` | тот же скриншот |

Важная деталь: кнопки-действия внутри модалок (`Yes`/`Apply`/`Save`) на **~2 оттенка
темнее**, чем кнопка "Create" на полной странице (`#56A55A` vs `#6EB371`) — это разные
CSS-классы/контексты, не ошибка измерения (перепроверено дважды).

Сегментированный контрол (button-group для выбора Local/Redirect/Preload/Action у
офферов/лендингов, или Landing pages & offers/Direct URL/Action у стрима):
- Активный сегмент: фон `#C1E8C3` (rgb 193,232,195), текст `#212529`, border `1px solid #CCCCCC`.
- Неактивный сегмент: фон `#F3F3F3`, текст `#333333`, border тот же.
См. `screenshots/32_offer_create_form.png`.

### 3.2 Инпуты

| Состояние | Border | box-shadow | Фон | Прочее |
|---|---|---|---|---|
| Обычное | `1px solid rgba(0,0,0,0.2)` | none | `#FFFFFF` | `border-radius: 2px`, `padding: 6px 12px`, `font-size: 16px`, текст `#555555` |
| Focus | тот же border | `0 0 0 3.2px rgba(56,137,201,0.25)` | `#FFFFFF` | синее свечение вокруг поля (без смены border) |
| Error (после неуспешной валидации, `.has-error`) | `1px solid #E25E63` | none | `#FFFFFF` | Лейбл получает класс `form-control-required`; **в Offer/Landing формах текст ошибки НЕ показывается — только красная рамка** (см. "Замеченные визуальные баги" ниже); в Campaign-форме та же ошибка вместо этого улетает в toast `#BD362F` сверху справа |

Скриншоты: `screenshots/34_landing_create_form.png` (focus, синее свечение видно на поле Name),
`screenshots/33_offer_create_validation.png` (красная рамка без текста).

### 3.3 Переключатели/чекбоксы/радио

- **Toggle-switch (`vSwitch`)**: включено — зелёная заливка (тот же диапазон брендового
  зелёного), белый бегунок справа; выключено — серая заливка, бегунок слева. См.
  `screenshots/35_traffic_source_create_form.png` (переключатель "URL Parameters", включён)
  и `screenshots/37_domain_create_form.png` (переключатели "Redirect to https"/"Include
  Subdomains", выключены).
- **Radio/Checkbox**: стандартные, синий акцент при выборе (Bootstrap-подобный синий,
  тот же диапазон, что ссылки `#3889C9`/бейдж `#007BFF` — точный hex радиокружка отдельно
  не вычислялся, визуально между этими двумя).

### 3.4 Таблицы/грид (Grid/gridReact)

- Header: фон `#F5F5F5`, текст `#515151`, `font-weight: 400`, без аптрейс.
- Обычная строка: фон прозрачный (белый), текст `#212529`, `~13px`.
- Hover строки: оверлей `rgba(37,208,137,0.08)` поверх каждой ячейки (не всей строки одним
  блоком — оверлей применяется отдельно к каждому `<td class="grid-cell">`).
- Выбранная строка (чекбокс): оверлей `rgba(233,235,4,0.27)` тем же образом на уровне ячеек.
- Статус-точка (активна/нет): `.ion-record`, цвет `#6EB371` (активна), размер иконки 10px.
- Ссылка-название: `#3889C9`, без подчёркивания.
- Пагинация (низ грида): текст "1 - 50 of 13" обычным цветом `#212529` `16px`; кнопка
  "Export" — тот же secondary-стиль (`#F3F3F3`/`#333333`/`#CCCCCC`); выбор размера страницы
  — обычный `<select>`. См. `screenshots/49_clicks_row_details.png`.
- При выборе чекбокса тулбар "Create/Groups/Metrics" **заменяется** тулбаром массовых
  операций: `Delete` (danger), `Clone`/`Enable`/`Disable`/`Report` (secondary). См.
  `screenshots/30_campaigns_row_context_menu.png`.

### 3.5 Модалки

- **Overlay** (подложка): `rgba(41,47,51,0.6)` — тёмно-синевато-серый на 60% непрозрачности.
- **Контейнер** (`.modal-content`): `border-radius: 6px`, сам по себе без явного фона
  (фон задают вложенные header/body/footer).
- **Header**: фон `#FAFAFA`, `padding: 15px 30px`, `border-bottom: 1px solid rgba(0,0,0,0.1)`,
  скруглены только верхние углы (~4px), заголовок `20px/400`.
- **Body**: тот же фон `#FAFAFA`, `padding: 30px`.
- **Footer**: тот же фон, кнопки во всю ширину, разделены пополам (см. 3.1), без
  собственного отступа контейнера (padding кнопок — 15px).
- Есть два "вида" модалок:
  1. **Confirmation-модалка** (узкая, ~300px, по центру) — заголовок "Confirmation",
     текст вопроса, кнопки Cancel/Yes. `screenshots/31_confirm_delete_modal.png`.
  2. **Форма-модалка** (широкая, ~900px) — заголовок с иконкой "?" (справка) и "×"
     (закрыть) в правом верхнем углу, тело с формой, футер Cancel/Save|Create (+ опционально
     чекбокс "Add more" слева). `screenshots/32_offer_create_form.png`,
     `screenshots/36_affiliate_network_create_form.png`,
     `screenshots/38_user_create_form.png`.
- Кампания — единственная сущность, у которой форма редактирования НЕ модалка, а
  полноценная страница (`#!/campaigns/:id`) с вкладками слева и React-виджетом стримов
  справа. Создание кампании тоже сразу открывает эту полную страницу, а не модалку.
  GeoProfile — тоже отдельная полная страница ("Create a list"), не модалка.

### 3.6 Табы

Два похожих, но не идентичных варианта:

1. **Верхнеуровневые вкладки формы** (Settings/Integration/Parameters/S2S Postbacks/Notes
   в форме кампании; Main/Additional/Notes в форме оффера; Main/Schema/Filters/
   Monitoring/Notes в форме стрима; Main/Integration/Bots/System в Settings) — активная
   вкладка: текст `#212529`(?)/тёмный, `border-bottom: 2px solid #6EB371` (тот же
   брендовый зелёный, что подчёркивание верхнего меню); неактивная — серый текст, без
   рамки. См. `screenshots/22_campaign_create_settings_tab.png`,
   `screenshots/14_settings_system.png`.
2. **Вертикальные табы слева в модалке ACL** ("Allowed resources"/"Campaigns"/"Reports
   restrictions") — тот же принцип, зелёная линия слева/снизу активного пункта.
   `screenshots/40_user_acl_modal.png`.

### 3.7 Тосты/уведомления (toastr)

Библиотека — классический **AngularJS-toastr** (`.toast-top-right` контейнер,
`.toast.toast-error` конкретное уведомление):
- Фон `#BD362F`, текст белый, `border-radius: 3px`.
- `box-shadow: 0 0 12px rgba(153,153,153,1)` (в вычисленном виде — без альфа-канала,
  видимо задано без `rgba` в исходнике).
- `padding: 15px 15px 15px 50px` — слева зарезервировано место под иконку-щит.
- Появляется в правом верхнем углу поверх верхнего тёмного бара.
- См. `screenshots/27_campaign_create_validation_error.png`.

### 3.8 Инфо-плашки (empty state / info alerts)

`.alert.alert-info` — фон `#F3F3DE`, текст `#414D54`, `border-radius: 5px`,
`padding: 15px`, `text-align: center`. Используется и для "нет данных" ("No domains
found", "There is no streams yet."), и для информационных подсказок (описание фильтров
стрима: "There you can restrict incoming traffic..."). См.
`screenshots/07_domains_index.png`, `screenshots/46_stream_filters.png`.

### 3.9 React-select (`kSelectWrapper`, обёртка react-select)

Подтверждена библиотека react-select (классы `k-select__option`, `css-*-option`,
`instancePrefix = "react-select-..."` — см. `frontend_analysis.md`). Выпадающий список:
- Опция под курсором/выбранная: фон `#E8E8E8` (rgb 232,232,232), текст `#1A1A1A`,
  `padding: 8px 12px`.
- Стрелка/крестик очистки справа от контрола — как на нативном select.
См. `screenshots/47_react_select_open.png` (поле "Domain" в форме кампании).

---

## 4. Сетка/отступы

Чёткой единой 4px/8px-сетки не обнаружено — паттерн скорее **Bootstrap 3/4-эры со
своими надстройками**, отступы смешанные:

- `2px` — border-radius кнопок/инпутов (самый маленький радиус в системе).
- `3px` — border-radius toast-уведомления.
- `4px` — border-radius бейджей ("Pro"/"Beta"/"Applied").
- `5px` — border-radius info-алертов.
- `6px` — border-radius модального окна (внешний контейнер).
- `6px/12px` — padding обычной кнопки (`6px 12px`).
- `15px` — повторяющийся "модальный" юнит: padding кнопок в футере модалки, header/body
  модалки использует кратные ему `15px 30px` (header) и `30px` (body, т.е. 2×15px).
- `30px` — двойной модальный юнит (см. выше).

Рекомендация для нового фронтенда: если нужна единая система, `15px`-базовый юнит
("модальный") плюс отдельная мелкая шкала `2/3/4/5/6px` для border-radius — ближе всего
описывает то, что реально в интерфейсе, но это не строгая изначальная система, а,
похоже, унаследованные дефолты Bootstrap 3 (`padding: 6px 12px`, `border-radius: 4px`
слегка кастомизированный на 2px и т.д.) с точечными правками сверху.

---

## 5. Иконки

Подтверждено: **Ionicons** (классы вида `ion ion-navicon`, `ion-record`, `ion-key`,
`ion-ios-locked-outline`, `ion-edit`, `ion-android-close`, `ion-gear-a`/`ion-android-settings`,
`ion-android-attach`, `ion-android-search`) — используются как для навигации/тулбаров,
так и внутри строк грида (иконки-действия "..." /экспорт/детали) и внутри списков (замок
"Change" у ACL, ключ у API Keys, карандаш у "Edit").

Размеры варьируются по контексту:
- Статус-точка в гриде (`ion-record`): `10px`.
- Остальные функциональные иконки (edit/delete/settings/lock/key) — стандартный
  инлайновый размер шрифта родителя (обычно `14–16px`, отдельно не мерился построчно
  для каждой — не было расхождений, влияющих на вёрстку).

Примеры "живых" иконок в интерфейсе: `screenshots/38_user_create_form.png` (список
пользователей — Edit/Delete), `screenshots/40_user_acl_modal.png` (замок "Change").

---

## 6. Графики (Chart.js через angular-chart.js)

Линейный график на Dashboard/Reports использует те же 7 цветов, что и KPI-карточки
(раздел 1.4), с полупрозрачной заливкой под линией (area chart), сетка светло-серая,
подписи осей `#212529`/серый. Легенда под графиком — цветные квадраты + название серии,
клик по элементу легенды переключает видимость серии (`chartVisibilityService`, см.
`frontend_analysis.md`). См. `screenshots/01_dashboard.png`.

---

## 7. Инвентарь скриншотов

Все пути относительно `docs/frontend/` (то есть `docs/frontend/design/screenshots/...`).

| Файл | Экран/состояние |
|---|---|
| `design/screenshots/00_login.png` | Страница логина (до входа) |
| `design/screenshots/00_after_login.png` | Dashboard сразу после логина |
| `design/screenshots/01_dashboard.png` | Dashboard — KPI-карточки, график, таблицы Campaign/Landing/Offer/Source, Recent Clicks |
| `design/screenshots/02_campaigns_index.png` | Campaigns — список (грид) |
| `design/screenshots/03_offers_index.png` | Offers — список |
| `design/screenshots/04_landings_index.png` | Landing Pages — список |
| `design/screenshots/05_traffic_sources_index.png` | Sources (Traffic Sources) — список |
| `design/screenshots/06_affiliate_networks_index.png` | Affiliate Networks — список |
| `design/screenshots/07_domains_index.png` | Domains — список + FAQ секция |
| `design/screenshots/08_users_index.png` | Users — список |
| `design/screenshots/09_geo_profiles_index.png` | GeoProfiles (Lists of countries) — список |
| `design/screenshots/10_geo_dbs_index.png`, `10b_geo_dbs_detail.png` | GeoDBs — список/настройки |
| `design/screenshots/11_reports_build.png` | Reports — конструктор отчёта (таблица, без чарта на этом экране) |
| `design/screenshots/12_clicks_log.png` | Clicks — лог |
| `design/screenshots/13_conversions_log.png` | Conversions — лог |
| `design/screenshots/14_settings_index.png`, `14_settings_main.png` | Settings → Main |
| `design/screenshots/14_settings_system.png` | Settings → System (inverted-logic радио видно: Collect clicks/Force store user tokens) |
| `design/screenshots/14_settings_bots.png` | Settings → Bots |
| `design/screenshots/14_settings_integration.png` | Settings → Integration |
| `design/screenshots/15_migrations_index.png`, `15b_migrations_detail.png` | Migrations — список с бейджами "Applied" |
| `design/screenshots/16_trends_index.png` | Trends |
| `design/screenshots/17_integrations_index.png` | Integrations — карточки с бейджами Pro/Beta |
| `design/screenshots/17b_integrations_avscan_detail.png` | Integrations → AVScan detail |
| `design/screenshots/18_logs_index.png` | Logs (новый React-табличный экран) |
| `design/screenshots/19_profile_edit.png` | Profile |
| `design/screenshots/20_reports_exported.png` | Reports → Exported Reports |
| `design/screenshots/21_self_update.png` | Self Update (Update Tds) |
| `design/screenshots/22_campaign_create_settings.png`, `22_campaign_create_settings_tab.png`, `22b_campaign_create_gear.png` | Campaign create — вкладка Settings (до/после раскрытия панели настроек шестерёнкой) |
| `design/screenshots/23_campaign_create_integration.png` | Campaign create — вкладка Integration |
| `design/screenshots/24_campaign_create_parameters.png` | Campaign create — вкладка Parameters |
| `design/screenshots/25_campaign_create_s2s_postbacks.png` | Campaign create — вкладка S2S Postbacks |
| `design/screenshots/26_campaign_create_notes.png`, `26b_campaign_create_button_dirty.png` | Campaign create — вкладка Notes; кнопка Create в "dirty"-состоянии |
| `design/screenshots/27_campaign_create_validation_error.png` | Campaign create — toast-ошибка "name: Is required" |
| `design/screenshots/29_campaigns_row_selected.png` | Campaigns — выбранная чекбоксом строка (жёлто-зелёный оттенок) |
| `design/screenshots/30_campaigns_row_context_menu.png` | Campaigns — тулбар массовых операций (Delete/Clone/Enable/Disable/Report) |
| `design/screenshots/31_confirm_delete_modal.png` | Confirmation-модалка ("Archive selected entries?") |
| `design/screenshots/32_offer_create_form.png` | Offer create — форма (Main/Additional/Notes, сегментированный Local/Redirect/Preload/Action) |
| `design/screenshots/33_offer_create_validation.png` | Offer create — ошибка валидации (красная рамка без текста), disabled Create |
| `design/screenshots/34_landing_create_form.png` | Landing create — форма (focus-состояние поля Name) |
| `design/screenshots/35_traffic_source_create_form.png` | Traffic Source create — форма с таблицей параметров, toggle "URL Parameters" включён |
| `design/screenshots/36_affiliate_network_create_form.png` | Affiliate Network create — форма |
| `design/screenshots/37_domain_create_form.png` | Domain create — форма, выключенные toggle'ы |
| `design/screenshots/38_user_create_form.png` | User create — форма (радио Role/Language) |
| `design/screenshots/39_geo_profile_create_form.png` | GeoProfile create — полностраничная форма "Create a list" |
| `design/screenshots/40_user_acl_modal.png` | User → Access (ACL) модалка — вертикальные табы, чекбоксы ресурсов |
| `design/screenshots/40_user_edit_acl.png` | User edit — базовая форма (без ACL, для сравнения) |
| `design/screenshots/41_user_apikeys_modal.png` | User → Admin API keys модалка |
| `design/screenshots/42_campaign_edit_with_stream.png`, `42_campaign_edit_with_streams.png` | Campaign edit — полная страница, вкладка Settings + React-виджет Streams справа (2 стрима) |
| `design/screenshots/43_campaign_create_stream_dropdown.png` | Campaign edit — состояние после клика по сплит-кнопке "Create Stream" |
| `design/screenshots/44_stream_edit_offers_schema.png` | Edit Stream модалка — вкладка Main (toggle'ы Collect clicks/Status) |
| `design/screenshots/45_stream_schema_offers.png` | Edit Stream — вкладка Schema (радио "Landing pages & offers"/"Direct URL"/"Action") |
| `design/screenshots/46_stream_filters.png` | Edit Stream — вкладка Filters (Add Filter, AND/OR, info-алерт) |
| `design/screenshots/47_react_select_open.png` | react-select открыт (поле Domain), подсвеченная опция |
| `design/screenshots/48_landing_code_editor.png` | Локальный редактор кода лендинга (Ace editor, файловое дерево слева) |
| `design/screenshots/49_clicks_row_details.png` | Clicks log — пагинация внизу, выбранная строка |
| `design/screenshots/50_search_results.png` | Search result — глобальный поиск (найдена кампания по стриму) |

---

## 8. Замеченные визуальные баги/несостыковки

Зафиксировано по ходу сбора скриншотов (не чинилось, чисто наблюдение):

1. **Кнопка "Delete" в тулбаре массовых операций Campaigns на самом деле архивирует, а
   не удаляет** — при клике открывается confirmation-модалка с текстом "Archive selected
   entries?", а не "Delete...". Это согласуется с уже задокументированным в
   `frontend_analysis.md` фактом, что бэкенд-экшен называется `archive`, но с точки зрения
   UI-копирайтинга кнопка вводит в заблуждение (красная кнопка "Delete" → мягкая архивация).
   См. `screenshots/30_campaigns_row_context_menu.png` + `31_confirm_delete_modal.png`.
2. **Непоследовательный UX серверной/клиентской валидации между сущностями**: у Campaign
   пустое обязательное поле "name" показывает toast-уведомление сверху справа с текстом
   ошибки; у Offer то же самое (`ng-invalid-required` на пустом "Offer Name") показывает
   ТОЛЬКО красную рамку вокруг инпута — без какого-либо текста ошибки рядом или в toast.
   Пользователь Offer-формы должен сам понять, что не так, глядя только на подсвеченную
   рамку. См. `screenshots/27_campaign_create_validation_error.png` vs
   `33_offer_create_validation.png`.
3. **Кнопки-действия внутри модалок на другой оттенок зелёного, чем кнопки на полной
   странице** (`#56A55A` vs `#6EB371` для, казалось бы, одной и той же роли "подтвердить
   действие") — вероятно, унаследовано из разных версий Bootstrap-темы/разных
   CSS-классов, а не осознанное решение. При переписывании стоит унифицировать в одну
   палитру, если не будет обратных указаний от дизайнера.
4. Многие вычисленные px-размеры (шрифты, отступы) в этом окружении дробные
   (`13.008px`, `16.504px`, `33.008px`) — похоже на артефакт рендера/масштаба тестового
   контейнера, а не осознанный дизайн; см. раздел 2.

---

## 9. Что НЕ было (полностью) исследовано — известные пробелы

- **Три схемы стрима** — задокументированы "Landing pages & offers" и увидено разделение
  на "Direct URL"/"Action" радио (см. `45_stream_schema_offers.png`), но отдельный
  скриншот именно с выбранным "Direct URL" и отдельно "Action" не снят (не хватило
  времени на полный проход всех трёх состояний по отдельности) — визуально они, скорее
  всего, идентичны по стилю сегментированному контролу в Offer/Landing формах (раздел 3.1).
- **Reports — линейный график с легендой** заснят только на Dashboard; экран
  `#!/reports/build` в тестовом окружении не показал график (возможно, разворачивается
  по отдельному клику/группировке, не проверено).
- **Row details popup** (`detailsTableReact`, клик по строке клика) — клик по строке в
  тестовом прогоне просто выделил её чекбоксом, полноценный popup с деталями клика не
  словлен (не идентифицирован правильный элемент-триггер за отведённое время).
- **Tooltips** (наведение на "?" иконки типа "Folder ⓘ") — не пойманы отдельно, эти
  иконки видны на скриншотах форм (например `35_traffic_source_create_form.png`), но
  сам popup с текстом подсказки не заскриншочен.
- **Logs (старый экран) / SystemLog / PostbacksLog вкладки** — заснят только
  `logsNewPage` (React) в общем виде, под-вкладки конкретных логов не открывались по
  отдельности.
- Точный hex цвета radio/checkbox accent (когда отмечены) — визуально синий, отдельно
  `getComputedStyle` не снимался (браузерный native control, стилизация через
  `accent-color` не всегда читается через getComputedStyle надёжно).

---

## 10. Summary

- **Заскриншочено экранов/состояний:** ~48 уникальных PNG (список — раздел 7), покрывающих
  все пункты из `frontend_analysis.md` §3, кроме нескольких под-состояний, перечисленных
  в разделе 9 как пробелы.
- **Извлечено design-токенов через `getComputedStyle`:** ~90+ отдельных измерений
  (цвета фонов/текста/бордеров, размеры шрифтов, padding/border-radius/box-shadow) —
  сведены в разделы 1–6 выше с точными hex/rgb.
- **Не успели:** отдельные скриншоты для "Direct URL"/"Action" схем стрима по отдельности,
  row-details popup клика, tooltip-попапы, старые под-экраны Logs, график на экране
  Reports (только Dashboard-график задокументирован).
- Все found визуальные несостыковки — в разделе 8, они НЕ исправлялись (по заданию — это
  чисто сбор визуальной документации).
