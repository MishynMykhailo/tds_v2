# Запланированные доработки

Список важных доработок, которые обсудили. "Сделано (временно)" значит:
внешний вызов отключён заглушкой прямо сейчас, но нормальная полноценная
замена ещё не реализована — не забыть довести до ума.

---

## [СДЕЛАНО ВРЕМЕННО] Курсы валют — exrates.tds.io

**Файл:** `application/Core/Currency/DataSource/ExratesTds.php`

**Было:** метод `convert()` ходил на `https://exrates.tds.io/latest?...` с
вашим лицензионным ключом в заголовке, чтобы получить курс обмена валют для
отчётов.

**Сделано сейчас:** `convert()` больше никуда не стучится — сразу выбрасывает
`CurrencyRequestError`. Это безопасно: `CurrencyService::rate()` и так уже
ловит эту ошибку и откатывается на курс `1.0` (`DEFAULT_RATE`), логируя
предупреждение — то есть поведение при "сервис недоступен" не поменялось, оно
просто теперь всегда такое.

**ЧТО НУЖНО ДОДЕЛАТЬ ПОЗЖЕ:** подключить свой источник курсов валют — либо
завести собственный, обновляемый вручную/по cron список курсов в настройках,
либо взять независимый бесплатный API валютных курсов (например,
exchangerate-api.com, ЦБ РФ и т.п.) вместо exrates.tds.io. Пока это не
сделано — все конвертации валют в отчётах считаются по курсу 1:1, если
валюта оффера отличается от валюты отчёта.

---

## [РЕШЕНО] GeoDB — боты/операторы связи (tds_bot_db2, tds_carrier)

**Файлы:** `application/Component/GeoDb/Tds/TdsBotDb2.php`,
`TdsCarrierDb.php`

**Было:** при обновлении базы качали `.dat`-файл с `tds.io/files/botsdb` и
`tds.io/files/carrierdb` (с вашим лицензионным ключом и IP в самом URL).

**Сделано:** обе базы переключены на `NullDownloadManager` — приложение
больше никогда не пытается скачать или перезаписать эти файлы само. Вы
сказали, что зальёте базы сами — кладите файлы вручную сюда:
- `var/bots/botsV2.dat` (база ботов)
- `var/geoip/carriers.dat` (база операторов связи/типов соединения)

Если файла нет — соответствующий детект (антибот по этой базе / определение
оператора) просто не будет давать данные, ничего не сломается.

---

## [РЕШЕНИЕ ОТЛОЖЕНО] api.hideapi.xyz / HideClickDetect.php

**Файл:** `application/Component/StreamFilters/Filter/HideClickDetect.php`

Логика фильтра уже сейчас выглядит нерабочей (артефакты декомпиляции — `exit`
вместо реальной отправки запроса, `_sendRequest()` нигде не вызывается).
Варианты на будущее: (a) починить и оставить внешний сервис, (b) заменить на
другую IP-репутационную базу (IPQualityScore, IP2Proxy) с локальным
обновлением, (c) выключить фильтр совсем. Решение не принято.

---

## [НЕ СДЕЛАНО] Свой сервис скриншотов лендингов — screenshot.tds24.ru

**Файл:** `application/Component/Landings/LocalFile/PreviewImageService.php`

**Сделано сейчас:** метод `createPreview()` уже отключён (сразу `return`,
ничего никуда не отправляет — превью-картинки лендингов просто не создаются и
не обновляются).

**ЧТО НУЖНО ДОДЕЛАТЬ:** заменить на собственный локальный рендеринг скриншота
— headless-браузер (Chromium/Puppeteer/Playwright либо `wkhtmltoimage`),
поднятый в отдельном Docker-контейнере: взять `$lpUrl`, отрендерить страницу
локально, сохранить PNG в `previewImagePath()`. Ждём отдельного запроса на
реализацию.

---

## [ИСПРАВЛЕНО ПОЛНОСТЬЮ] Массовый баг деобфускации: "final class ...Interface" вместо "interface"

При правке GeoDB/валют обнаружилось, что как минимум 32 файла во всём
проекте были объявлены неправильно — вместо `interface Foo { public
function bar(); }` стояло `final class Foo { public abstract function
bar(); }`. Это гарантированно фатальная ошибка PHP (final-класс не может
содержать abstract-методы) — подтверждено через реальную загрузку класса в
Docker, не только линтером.

Похоже на артефакт инструмента, которым кто-то (вероятно, ещё до
фрилансера) декомпилировал/деобфусцировал исходный ionCube-код обратно в
читаемый PHP — он не смог правильно восстановить объявления `interface` и
превратил их все в этот битый паттерн.

**Статус: исправлены ВСЕ 32 файла** (простое и безопасное исправление —
возвращён корректный синтаксис `interface`, ни один публичный метод не
удалён и не переименован). Список:

Ранее (по ходу задач с валютами/GeoDB):
- `application/Core/Currency/DataSource/DataSourceInterface.php`
- `application/Component/GeoDb/DownloadManager/DownloadManagerInterface.php`

Финальным проходом (30 файлов):
- `application/Component/Cron/CronTaskInterface.php` — критично: от него
  зависят все 19 cron-задач.
- `application/Core/Context/ContextInterface.php` — критично: используется
  на каждом HTTP-запросе через Bootstrap/Kernel.
- `application/Core/Dispatcher/DispatcherInterface.php`
- `application/Core/Component/InitializerInterface.php`
- `application/Core/EntityEventManager/EventHandler/EventHandlerInterface.php`
- `application/Core/Json/SerializerInterface.php`
- `application/Core/Sandbox/SandboxInterface.php`
- `application/Core/Sandbox/CgiExecutor/CgiExecutorInterface.php`
- `application/Core/Entity/Model/EntityModelInterface.php`
- `application/Admin/Controller/EntityControllerInterface.php`
- `application/Component/GeoDb/Adapter/GeoDbAdapterInterface.php`
- `application/Component/PruneTask/PruneTaskInterface.php`
- `application/Component/Av/AvInterface.php`
- `application/Component/DelayedCommands/DelayedCommandInterface.php`
- `application/Component/BotDetection/UserBotsServiceInterface.php`
- `application/Component/BotDetection/Repository/UserBotsRepositoryInterface.php`
- `application/Component/BotDetection/BotsStorage/StorageInterface.php`
- `application/Component/Grid/Renderer/RendererInterface.php`
- `application/Component/Grid/Builder/BuilderInterface.php`
- `application/Component/Clicks/ClickProcessing/StageInterface.php`
- `application/Component/Conversions/ConversionCapacity/Storage/StorageInterface.php`
- `application/Component/Postback/ProcessPostback/Stages/StageInterface.php`
- `application/Traffic/RawClickInterface.php`
- `application/Traffic/CachedData/DataGetter/DataGetterInterface.php`
- `application/Traffic/CachedData/Storage/StorageInterface.php`
- `application/Traffic/Pipeline/Stage/StageInterface.php`
- `application/Traffic/HitLimit/Storage/StorageInterface.php`
- `application/Traffic/LpToken/Storage/StorageInterface.php`
- `application/Traffic/CommandQueue/QueueStorage/StorageInterface.php`
- `application/Traffic/Session/Storage/StorageInterface.php`

**Проверка:** все 30 файлов прогнаны через `php -l` в Docker (чисто), плюс
отдельно каждый класс реально загружен через настоящий автозагрузчик
приложения (`interface_exists()` внутри `php -r ...` в контейнере
`php:7.4-cli`) — ни одной фатальной ошибки. Раньше это было не проверить
без такого прогона: обычный `php -l` одного файла эту проблему тоже ловит,
но только если реально запустить именно этот файл, а не просто "посмотреть
глазами".

**Важно:** сервер вы ещё не проверяли (ваши слова) — то есть неизвестно,
было ли это причиной реальных сбоев на боевом окружении, или там крутится
другая (например, ещё закодированная ionCube) копия. В любом случае теперь
и исходники, которые у вас на руках, тоже корректны и готовы к запуску с
этой стороны.

---

## [НЕ СДЕЛАНО] Превью оффера/лендинга прямо из админки

**Идея:** добавить `?object=landings.preview&id=X` и `?object=offers.preview&id=X` —
эндпоинты, которые открывают локальный лендинг/оффер (`action_type=local_file`)
напрямую в браузере, в обход всего пайплайна подбора кампании/стрима (см.
BUG_PATTERNS.md — кнопка "Preview" с иконкой глаза уже готова во фронтенде,
привязана к полю `local_path`, но бэкенд никогда его не заполняет; есть даже
неиспользуемый метод-заготовка `LocalFileService::buildUrl($domain, $localPath)`,
который нигде не вызывается).

**Требование от пользователя:** должно работать **только если запрос авторизован
в админке** (текущая сессия админа) — иначе (анонимный доступ) должен идти
обычный редирект на оффер/лендинг (штатное поведение клика), а не прямая отдача
файлов превью.

**Что нужно сделать (когда возьмёмся):**
1. Новый экшен в `LandingsController`/`OffersController` (или общий
   `EditorController`) — `previewAction()`.
2. Проверка авторизации (`isViewAllowed`/наличие текущего админ-пользователя) —
   если нет доступа, `redirect()` на обычный публичный URL оффера/лендинга
   вместо прямой отдачи файлов.
3. При наличии доступа — взять `action_options.folder`, отрендерить через тот
   же `PageWrapper`, что использует `Traffic\Actions\Predefined\LocalFile::_execute()`,
   но без полного пайплайна подбора кампании/стрима.
4. Подключить итоговый URL к полю `local_path` в сериализаторах
   (`OfferSerializer`/аналогичный для Landing), чтобы кнопка "Preview" во
   фронтенде наконец заработала.

---

## [НЕ СДЕЛАНО] Фронтенд: защититься от `undefined` в сравнении версий на странице Domains

**Источник:** найдено Playwright-тестированием, 2026-08-27 (см. `docs/FIXES_LOG.md`, раздел
"Живое тестирование через Playwright").

На странице Domains фронтенд вызывает `statusService.getVersionInstall(installation_method)`,
который парсит регэкспом строку вида `"Approved (v1.2.3)"`. Если бэкенд возвращает `"Custom"`
(нестандартный путь установки — например наш dev-докер, не входящий в
`StatusService::$_approvedTdsInstallations`), функция возвращает `undefined`, а следующий вызов
`Bo.a.compare(undefined, ..., ">=")` (библиотека сравнения версий) кидает необработанный
`TypeError: Invalid argument expected string` в консоль. Страница при этом продолжает
рендериться нормально (баг не влияет на функционал, только на консоль/скрытый банер апгрейда),
поэтому не критично — но при пересборке фронтенда стоит защититься: не вызывать `compare()`,
если `getVersionInstall()` вернул не строку.

**Не бэкенд-баг** — `getInstallationMethod()` работает штатно (осознанно возвращает `"Custom"`
для неопознанных путей установки).
