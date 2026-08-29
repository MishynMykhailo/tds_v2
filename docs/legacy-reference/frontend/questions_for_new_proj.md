# Вопросы и чек-лист перед переписыванием фронтенда

Свод того, что нужно проверить вживую в реальной админке, каких контрактных особенностей нельзя терять, и какие архитектурные решения нужно осознанно принять ДО старта переписывания фронтенда. Составлено по итогам исследования в `docs/frontend/backend_api_reference.md`, `docs/frontend/frontend_analysis.md` и `docs/frontend/architecture_plan.md`.

---

⚠ **Актуальность стека (проверено 2026-08-27):** AngularJS 1.x — EOL с 31.12.2021 (Google
официально прекратил поддержку), `react2angular` — последний коммит в октябре 2019 (заброшен),
`angular-formly` (AngularJS-версия) — фактически заброшен, вся жизнь ушла в несовместимый
`ngx-formly`. Это значит, что рекомендация в `architecture_plan.md` §4 ("Вариант A — сохранить гибрид Angular+React")
имеет смысл ТОЛЬКО как временная мера для быстрого старта, а не как целевая архитектура — см. раздел
"Архитектурные решения" ниже, подпункт про выбор итогового стека.

---

## Чек-лист

### A. Проверить вживую в реальной админке (Network tab), прежде чем закладывать в новый фронтенд

1. ~~**Схема стрима**~~ — ✅ ПРОВЕРЕНО ЖИВЬЁМ (Playwright, 2026-08-27): ровно 3 варианта —
   `landings` ("Landing pages & offers", показывает ОБА пикера Landing Pages + Offers сразу),
   `redirect` ("Direct URL", поля Redirect Type + URL), `action` ("Action", один select с
   действием). Расхождение в `architecture_plan.md` §1.1 закрыто, 4-го варианта не существует.
   ОТВЕТ: вот есть инпуты в стримах

Ответ: Реально 3, вот

<div ng-class="{'col-lg-9': !to.hideLabel, 'col-12': to.hideLabel}" class="col-lg-9"><div ng-class="options.className"><!-- ngRepeat: (key, option) in to.options --><div class="form-check form-check-inline ng-scope" ng-repeat="(key, option) in to.options"><input class="form-check-input ng-valid ng-not-empty ng-dirty ng-touched ng-valid-parse" type="radio" id="formly_7_vRadio_schema_0_0" ng-disabled="option.disabled" ng-value="::(option[to.valueProp || 'value'])" ng-model="model[options.key]" name="formly_7_vRadio_schema_0" formly-custom-validation="" value="landings" style=""><label class="form-check-label ng-binding" for="formly_7_vRadio_schema_0_0">Landing pages &amp; offers</label></div><!-- end ngRepeat: (key, option) in to.options --><div class="form-check form-check-inline ng-scope" ng-repeat="(key, option) in to.options"><input class="form-check-input ng-valid ng-not-empty ng-dirty ng-touched" type="radio" id="formly_7_vRadio_schema_0_1" ng-disabled="option.disabled" ng-value="::(option[to.valueProp || 'value'])" ng-model="model[options.key]" name="formly_7_vRadio_schema_0" formly-custom-validation="" value="redirect" style=""><label class="form-check-label ng-binding" for="formly_7_vRadio_schema_0_1">Direct URL</label></div><!-- end ngRepeat: (key, option) in to.options --><div class="form-check form-check-inline ng-scope" ng-repeat="(key, option) in to.options"><input class="form-check-input ng-valid ng-not-empty ng-dirty ng-touched" type="radio" id="formly_7_vRadio_schema_0_2" ng-disabled="option.disabled" ng-value="::(option[to.valueProp || 'value'])" ng-model="model[options.key]" name="formly_7_vRadio_schema_0" formly-custom-validation="" value="action" style=""><label class="form-check-label ng-binding" for="formly_7_vRadio_schema_0_2">Action</label></div><!-- end ngRepeat: (key, option) in to.options --></div></div>

А ты видишь какие у них опции открывается при выбори одного из этого?

Что у value="landings"

<div formly-field="" ng-repeat="field in fields " ng-if="!field.hide" class="formly-field ng-scope ng-isolate-scope" options="field" model="field.model || model" original-model="model" fields="fields" form="theFormlyForm" form-id="formly_8" form-state="options.formState" form-options="options" index="$index"><!-- ngIf: model.schema == 'landings' || model.schema == 'offers' --><app-stream-landings ng-if="model.schema == 'landings' || model.schema == 'offers'" model="model" landings="to.landings" set-landings="to.setLandings" class="ng-scope ng-isolate-scope" style=""><div class="row"><div class="col-lg-3 text-lg-right"><label class="form-control-label ng-binding">Landing Pages</label></div><div class="col-lg-9"><app-splitter field="landing_id" resource-name="landing" available-items="$ctrl.availableItems" model="$ctrl.model.landings" on-collection-change="$ctrl.setLandings(items)" class="ng-isolate-scope"><!-- ngIf: $ctrl.model.length --><div class="form-group"><div class="col-lg-8"><div class="btn-group" role="group"><button class="btn btn-secondary btn-sm ng-binding" type="button" ng-click="$ctrl.add()">Add Landing Pages</button><uib-dropdown class="btn-group dropdown"><button class="btn btn-secondary btn-sm dropdown-toggle" type="button" uib-dropdown-toggle="" aria-haspopup="true" aria-expanded="false"></button><ul class="dropdown-menu dropdown-menu-right" uib-dropdown-menu=""><li><a ng-click="$ctrl.createNew()" class="ng-binding">Create Landing Page</a></li></ul></uib-dropdown></div></div><!-- ngIf: $ctrl.existsActiveLandings() --></div></app-splitter></div></div></app-stream-landings><!-- end ngIf: model.schema == 'landings' || model.schema == 'offers' --></div><div formly-field="" ng-repeat="field in fields " ng-if="!field.hide" class="formly-field ng-scope ng-isolate-scope" options="field" model="field.model || model" original-model="model" fields="fields" form="theFormlyForm" form-id="formly_8" form-state="options.formState" form-options="options" index="$index"><!-- ngIf: model.schema == 'landings' || model.schema == 'offers' --><app-stream-offers ng-if="model.schema == 'landings' || model.schema == 'offers'" model="model" offers="to.offers" set-offers="to.setOffers" class="ng-scope ng-isolate-scope" style=""><div class="row"><div class="col-lg-3 text-lg-right"><label class="form-control-label ng-binding">Offers</label></div><div class="col-lg-9"><app-splitter field="offer_id" resource-name="offer" available-items="$ctrl.availableItems" model="$ctrl.model.offers" on-collection-change="$ctrl.setOffers(items)" class="ng-isolate-scope"><!-- ngIf: $ctrl.model.length --><div class="splitter margin-bottom-10 ng-scope" ng-if="$ctrl.model.length"><!-- ngRepeat: subModel in $ctrl.model --><app-splitter-row class="splitter-item ng-scope ng-isolate-scope splitter-item-single" ng-repeat="subModel in $ctrl.model" ng-class="{'splitter-item-single': $ctrl.model.length == 1}" model="::subModel" recalculate-total-share="$ctrl.recalculateTotalShare()" remove="$ctrl.remove($index)" resource="$ctrl.findItem(subModel[$ctrl.field])" field="::$ctrl.field" index="::$index" on-edit="$ctrl.edit(subModel[$ctrl.field])" on-replace="$ctrl.replace(subModel)"><a class="splitter-item-remove-btn btn btn-additional" ng-click="$ctrl.remove()"><i class="ion ion-android-close"></i></a><!-- ngIf: ::$ctrl.resource --><div class="splitter-item-name ng-scope" ng-if="::$ctrl.resource"><button class="splitter-item-name-text btn btn-link btn-link-clear ng-binding splitter-item-active" type="button" ng-class="'splitter-item-' + $ctrl.model.state" ng-click="$ctrl.onReplace()">test 344</button><button class="btn btn-additional btn-sm margin-left-5" type="button" ng-click="$ctrl.onEdit()"><i class="ion ion-edit"></i></button><!-- ngIf: $ctrl.field == 'offer_id' --><span class="splitter-item-info ng-scope" ng-if="$ctrl.field == 'offer_id'"><!-- ngIf: $ctrl.getCountries() --><!-- ngIf: $ctrl.getPayout() --><!-- ngIf: $ctrl.getPayoutType() --><!-- ngIf: $ctrl.getConversionCap() --></span><!-- end ngIf: $ctrl.field == 'offer_id' --></div><!-- end ngIf: ::$ctrl.resource --><div class="splitter-item-share"><!-- ngIf: $ctrl.model[$ctrl.field] --><div class="input-group input-group-sm ng-scope" ng-if="$ctrl.model[$ctrl.field]"><input class="form-control text-right ng-pristine ng-untouched ng-valid ng-not-empty" tabindex="0" ng-model="$ctrl.model.share" ng-change="$ctrl.recalculateTotalShare()"><div class="input-group-append"><div class="input-group-text">%</div></div></div><!-- end ngIf: $ctrl.model[$ctrl.field] --></div><div class="splitter-item-actions text-nowrap text-right"><!-- ngIf: $ctrl.model[$ctrl.field] --><span class="margin-right-10 switch-xs switch margin-right-10 switch-xs ng-scope ng-isolate-scope ng-not-empty ng-valid checked" ng-click="undefined ? null : toggle()" ng-class="{ checked: ngModel == onValue, disabled:undefined }" ng-if="$ctrl.model[$ctrl.field]" ng-model="$ctrl.model.state" on-value="'active'" off-value="'disabled'" ng-change="$ctrl.onStateChange()"><small></small><input type="checkbox" ng-model="$ctrl.model.state" style="display:none" class="ng-pristine ng-untouched ng-valid ng-empty"><span class="switch-text"> </span></span><!-- end ngIf: $ctrl.model[$ctrl.field] --></div><div class="splitter-item-id ng-binding">#3</div></app-splitter-row><!-- end ngRepeat: subModel in $ctrl.model --></div><!-- end ngIf: $ctrl.model.length --><div class="form-group"><div class="col-lg-8"><div class="btn-group" role="group"><button class="btn btn-secondary btn-sm ng-binding" type="button" ng-click="$ctrl.add()">Add Offers</button><uib-dropdown class="btn-group dropdown"><button class="btn btn-secondary btn-sm dropdown-toggle" type="button" uib-dropdown-toggle="" aria-haspopup="true" aria-expanded="false"></button><ul class="dropdown-menu dropdown-menu-right" uib-dropdown-menu=""><li><a ng-click="$ctrl.createNew()" class="ng-binding">Create Offer</a></li></ul></uib-dropdown></div></div><!-- ngIf: $ctrl.existsActiveLandings() --><div class="col-lg-4 hide-fast ng-scope" ng-if="$ctrl.existsActiveLandings()"><!-- ngIf: $ctrl.getTotalShare() != 100 --><!-- ngIf: !$ctrl.hasEqualShare() || $ctrl.getTotalShare() != 100 --></div><!-- end ngIf: $ctrl.existsActiveLandings() --></div></app-splitter></div></div></app-stream-offers><!-- end ngIf: model.schema == 'landings' || model.schema == 'offers' --></div>

А вот при value=redirect
<stream-actions ng-if="model.schema != 'landings'" category="model.schema" actions="to.streamActions" action-type="model.action_type" action-payload="model.action_payload" action-options="model.action_options" exclude-actions="['local_file']" class="ng-scope ng-isolate-scope" style=""><!-- ngIf: $ctrl.shouldShowActionSelect() --><div class="form-group ng-scope" ng-if="$ctrl.shouldShowActionSelect()"><label class="form-control-label col-lg-3"><!-- ngIf: $ctrl.isRedirectAction() --><span class="hide-fast ng-binding ng-scope" ng-if="$ctrl.isRedirectAction()">Redirect Type</span><!-- end ngIf: $ctrl.isRedirectAction() --><!-- ngIf: $ctrl.isOtherAction() --></label><div class="col-lg-9"><action-select value="$ctrl.actionType" options="$ctrl.getActionList()" on-change="$ctrl.onChange" class="ng-isolate-scope"><div class=" css-2b097c-container"><div class="k-select__control css-yk16xz-control"><div class="k-select__value-container k-select__value-container--has-value css-1hwfws3"><div class="k-select__single-value css-1uccc91-singleValue">HTTP redirect</div><div class="css-1g6gooi"><div class="k-select__input" style="display: inline-block;"><input autocapitalize="none" autocomplete="off" autocorrect="off" id="react-select-12-input" spellcheck="false" tabindex="0" type="text" aria-autocomplete="list" value="" style="box-sizing: content-box; width: 2px; background: 0px center; border: 0px; font-size: inherit; opacity: 1; outline: 0px; padding: 0px; color: inherit;"><div style="position: absolute; top: 0px; left: 0px; visibility: hidden; height: 0px; overflow: scroll; white-space: pre; font-size: 16px; font-family: Roboto, &quot;Helvetica Neue&quot;, &quot;Helvetica sans-serif&quot;; font-weight: 400; font-style: normal; letter-spacing: normal; text-transform: none;"></div></div></div></div><div class="k-select__indicators css-1wy0on6"><span class="k-select__indicator-separator css-1okebmr-indicatorSeparator"></span><div aria-hidden="true" class="k-select__indicator k-select__dropdown-indicator css-tlfecz-indicatorContainer"><svg height="20" width="20" viewBox="0 0 20 20" aria-hidden="true" focusable="false" class="css-19bqh2r"><path d="M4.516 7.548c0.436-0.446 1.043-0.481 1.576 0l3.908 3.747 3.908-3.747c0.533-0.481 1.141-0.446 1.574 0 0.436 0.445 0.408 1.197 0 1.615-0.406 0.418-4.695 4.502-4.695 4.502-0.217 0.223-0.502 0.335-0.787 0.335s-0.57-0.112-0.789-0.335c0 0-4.287-4.084-4.695-4.502s-0.436-1.17 0-1.615z"></path></svg></div></div></div></div></action-select></div></div><!-- end ngIf: $ctrl.shouldShowActionSelect() --><!-- ngIf: $ctrl.getFieldType() == 'campaigns' --><!-- ngIf: $ctrl.getFieldType() == 'text' --><!-- ngIf: $ctrl.getFieldType() == 'url' --><div class="form-group ng-if-fade ng-scope" ng-if="$ctrl.getFieldType() == 'url'"><label class="form-control-label col-lg-3 ng-binding">URL</label><div class="col-lg-9"><div class="input-group"><input class="form-control ng-pristine ng-untouched ng-valid ng-empty" ng-model="$ctrl.actionPayload" type="text" placeholder="http://domain.com/"><div class="input-group-append ng-isolate-scope ng-empty ng-valid" ng-model="$ctrl.actionPayload" type="click"><button class="btn btn-outline-secondary" ng-click="$ctrl.openWindow()" type="button"><span class="ion ion-compose"></span></button></div></div><!-- ngIf: $ctrl.urlDescription --><!-- ngIf: !$ctrl.containsProtocol() --></div></div><!-- end ngIf: $ctrl.getFieldType() == 'url' --><!-- ngIf: $ctrl.getFieldType() == 'upload' --></stream-actions>

ну и при value="action", там выпадающий список "Send to campaign", "404 NotFound" и т.д
ГЛАВНОЕ ОТВЕТЬ МНЕ - ТЫ ВИДИШЬ ТАКОЙ СПИСОК? НИЧЕГО НЕ МЕНЯЙ СТРУКТУРУ, ПО СУТИ ТЫ УВИДЕЛ ТО ЧТО ВИДЕЛ?

---

2. ~~**Кнопка "Simulate"**~~ — ✅ НАЙДЕНО В КОДЕ (2026-08-27): это НЕ кнопка в форме кампании,
   а пункт "Simulate traffic" в общем меню Maintenance (и отдельно в контекстном меню строки
   грида кампаний) → `simulationService.open()`. Реализация делает СЕРИЮ настоящих `POST` на
   `/api.php` (публичный Click API, НЕ admin `?object=`) со случайными характеристиками
   (гео/устройство/etc из пулов параметров кампании) и флагами `log:true, version:2,
   always_empty_cookies:true, save_to_stats:<bool>`, стримит ответы (`data.log`) в
   live-консоль в модалке. Прогоняет реальный клик-пайплайн (не dry-run). Требует Pro/Trial+
   лицензию (`isTrial()||isProAndHigher()`, иначе `showProError()`). Полностью переписать раздел
   §11 `backend_api_reference.md` и §1.3 `architecture_plan.md` с этой информацией (обновлено).
3. ~~**"Избранное" у стрима**~~ — ✅ ПРОВЕРЕНО (Playwright, 2026-08-27): клик по звезде
   (`.stream-favourite`, класс переключается `stream-is-not-favourite`↔`stream-is-favourite`)
   РЕАЛЬНО сохраняется на бэкенде — подтверждено полной перезагрузкой страницы, состояние
   сохранилось. Конкретный XHR не пойман фильтром по URL (скорее всего уходит через `?batch`,
   где `object=favouriteStreams.add` сидит в теле запроса, а не в URL). `Stream.perform`
   по-прежнему НЕ идентифицирован — не нашли триггер в UI, который бы его вызывал.
4. ~~**`labels.index`**~~ — ✅ БАГ ПОДТВЕРЖДЁН И ПОФИКШЕН (2026-08-27): прямой вызов дал
   HTTP 500 с невалидным `ref_name`, а после подбора валидного (`sub_id_N`/`ip`/`source`/...,
   см. `LabelService::getRefDefinition()`) — код действительно делал `return (int) $labels`
   вместо массива. Исправлено на `return $labels` в `LabelsController.php`.
5. ⚠️ **Автосейв форм** — ЧАСТИЧНО/НЕОДНОЗНАЧНО (Playwright, 2026-08-27): включил
   "Enable campaign autosave" в Settings, поменял поле Notes у кампании, подождал 5 сек —
   прямого автосейв-POST не поймал. Позже, при попытке уйти со страницы, всплыл диалог
   "Changes are not saved" и следом прошёл `?object=campaigns.update` — то есть сохранение
   в итоге произошло, но неясно, сработал ли именно 3-секундный debounce-автосейв, или это
   был save-on-navigate-confirm флоу. Требует отдельной чистой проверки с открытым Network tab
   вручную (без диалогов от других вкладок). Streams/Offers/Landings не проверялись отдельно.
6. ⏳ **`dashboardPreferences`** — НЕ ПРОВЕРЕНО (не дошли из-за нехватки времени в этом
   раунде тестирования).
7. ~~**Глобальный поиск**~~ — ✅ ЧАСТИЧНО РЕШЕНО (Playwright, 2026-08-27): в шапке есть ДВЕ
   разные вещи — (а) typeahead `$ctrl.searchCampaigns($viewValue)` с `typeahead-on-select`,
   мгновенно открывающий кампанию по имени (без перехода на отдельную страницу); (б) Enter в
   этом же поле уходит на отдельный роут `#!/search/?query=<текст>` — это, судя по всему, и есть
   `appSearchResults`/`streamsTableMassActions` из `frontend_analysis.md` §3.15 (поиск по
   стримам, где используется оффер/лендинг/etc). Конкретный `?object=...` вызов НА странице
   результатов не пойман (скрипт не досмотрел до конца) — вероятный кандидат `streams.search`
   (см. `backend_api_reference.md` §10.2).
8. ⏳ **`postbackBuilderService`** — НЕ ПРОВЕРЕНО (не дошли из-за нехватки времени в этом
   раунде тестирования).
9. ~~**i18next namespaces**~~ — ✅ РЕШЕНО (Playwright, 2026-08-27): переключение языка (ru↔en)
   идёт через `?object=profile.update` (это ПЕРСОНАЛЬНАЯ настройка юзера, отдельно от
   `settings.changeLanguage` — общесистемного дефолта для НЕ залогиненных/новых юзеров, см.
   `backend_api_reference.md` §10.12 — оба механизма реально сосуществуют, это не ошибка
   документации). При смене языка НЕ было поймано ни одного отдельного `.json`/`/locales/`
   запроса — переводы зашиты прямо в `app.js` на этапе сборки, отдельно грузить файлы
   локализации при пересборке с нуля не нужно (только перекомпилировать бандл с новыми
   строками).
10. ⚠️ **Хардкод "Не выбрано"** — НЕ ВОСПРОИЗВЕДЕНО на этой инсталляции (Playwright,
    2026-08-27): под English на Settings → Main никакой кириллицы не найдено. Блок с этим полем
    условный (`default_action_allowed==='1'`) и на этом тестовом стенде, судя по всему, скрыт —
    в самой форме Settings → Main нет блока "Кампания по умолчанию" вообще (см. скриншот в
    `docs/FIXES_LOG.md`). Нужно включить соответствующий фиче-флаг и повторить проверку, чтобы
    подтвердить или опровергнуть находку `frontend_analysis.md`.
11. ~~**Snap.js**~~ — ✅ ПОДТВЕРЖДЕНО (Playwright, 2026-08-27): клик по гамбургер-кнопке
    добавляет `document.body` класс `snapjs-left` — это буквальный, дословный CSS-класс из
    реальной библиотеки Snap.js (jakiestfu), не самописный аналог. Можно смело закладывать
    настоящую Snap.js (или её актуальный форк/замену) в план пересборки.

### B. Контрактные "гочи" — НЕ чинить, просто повторить один-в-один (иначе тихо сломается сохранение)

- `ingore_prefetch` — опечатка в имени поля, оставить как есть.
- `cookies_enabled` (`"0"`=да) и `disable_stats` (`0`=собирать статистику) — инвертированная
  семантика имени поля.
- JSON-в-поле (`stream_filters.payload`, `*.action_options`, `acl_resources.resources`,
  `campaigns.parameters`) — всегда через геттер модели, не напрямую из БД.
- Контракт серверной валидации: HTTP 406 + `{field_name: [...]}` ⇄ DOM `[field-name="..."]`.
- Батч (`?batch`/`?bulk`) — внешний HTTP-статус всегда 200, реальный статус — внутри
  `statusCode` каждого элемента ответа.
- Формат ошибок разный: обычный `?object=` — JSON почти везде, кроме generic-500 (HTML);
  REST AdminApi (`/admin_api/vN`) — вообще всё в HTTP 402.
- `id`/`ids` взаимозаменяемы в массовых операциях.
- "Удаление" почти везде = архивирование; физическое удаление — отдельный `cleanArchive`.
- Content-Type тела запроса не учитывается бэкендом — определяется по первому символу.
- Кука `states` не httpOnly (осознанно небезопасно) — решить, воспроизводить ли как есть.

### C. Архитектурные решения, которые нужно осознанно принять (не факты, а выбор)

1. **Итоговый фронтенд-стек.** Учитывая, что AngularJS/react2angular/angular-formly мертвы (см.
   предупреждение выше), стоит решить: переписывать ли сразу на современный единый
   стек (например React 18/19 + TypeScript + tanstack-table/tanstack-query, или Vue 3) вместо
   временного "Варианта A" из `architecture_plan.md` §4. Это меняет весь план §5-§6 этого же файла (структура проекта, фазы).
2. **Grid/таблицы** — при выборе современного стека решить, чем заменить связку
   `gridReact`+react-select (например `@tanstack/react-table` + `react-select`/`radix` или
   аналоги на выбранном фреймворке).
3. **Редактор кода** — Ace Editor устарел морально; рассмотреть Monaco Editor (движок VS Code,
   активно поддерживается) как замену при переписывании экрана Editor.
4. **Графики** — Chart.js 2.9.4 сильно отстаёт от актуального Chart.js 4.x (другой API) —
   решить, обновлять ли прямо на актуальную мажорную версию.
5. **Даты** — `moment.js` официально не рекомендован автором для новых проектов; рассмотреть
   `day.js`/`date-fns`/нативный `Intl`+`Temporal` вместо `moment`+`moment-timezone`.
6. **jQuery/jQuery UI/Snap.js** — если полностью уходим от Angular+jQuery-эры на единый
   современный стек, эти зависимости, скорее всего, не нужны вовсе (drag&drop сайдбара/сортировка
   решается нативными современными библиотеками).
7. **i18next/react-i18next** — актуальны и сейчас, можно оставить без изменений независимо от
   выбора остального стека.

### D. Технические предпосылки перед стартом

- Dev-прокси к бэкенду в Docker (`tds-app`, порт 8090) настроен и куки/CORS работают для
  выбранного dev-origin.
- Зафиксирован список решений из пункта C — они определяют структуру §5/§6 `architecture_plan.md`, менять
  план без этого решения бессмысленно.

---
