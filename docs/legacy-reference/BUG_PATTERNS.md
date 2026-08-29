# Карта паттернов багов в декомпилированном коде (для быстрого анализа)

Источник истины по классам: `vendor/composer/autoload_classmap.php` (реальная
карта класс → файл, сгенерирована composer, покрывает весь app + vendor).

## Паттерн 1: `final class FooInterface` вместо `interface FooInterface`
Признак: `final class \w+Interface { public abstract function ...; }`
Эффект: PHP Fatal сразу при загрузке класса.
Статус: ИСПРАВЛЕНО ВСЕ 32 файла (см. TODO_IMPROVEMENTS.md).
Детект: `grep -rlP "^final class \w*Interface" application/`

## Паттерн 2: Trait объявлен как `interface` (тело методов утеряно)
Признак: файл называется `*Trait.php`, `use`-ится через `use Foo\SomeTrait;`
внутри класса, но объявлен `interface SomeTrait { method(); method(); }`
(методы БЕЗ тела, некоторые ещё и `protected`/`private` — то есть уже
невалидно даже как interface).
Эффект: "Class X cannot use SomeTrait - it is not a trait" — фатал.
ВАЖНО: тут реальная бизнес-логика физически утеряна, реконструкция
делается по контексту (соседние методы в том же классе-потребителе,
использующие тот же паттерн $this->get()/$this->set()).
Статус на момент записи: StateMethodsTrait.php — исправлено (реконструкция
по Campaign::isDisabled()). AclHelper.php, ControllerHelper.php,
StreamActionableMethodsTrait.php, ActionableResourceTrait.php,
UpdateGeoDbCommandTrait.php — чинятся параллельными агентами прямо сейчас.
Детект: список кандидатов уже собран (см. ниже "trait use mismatch scan").

## Паттерн 3: `self::` вместо `parent::` (бесконечная рекурсия)
Признак: класс расширяет родителя (обычно `\GuzzleHttp\Psr7\*`), переопределяет
метод (`withHeader`, `withBody`, `withStatus`), и внутри вызывает
`self::sameMethodName(...)` вместо `parent::sameMethodName(...)`.
Эффект: бесконечная рекурсия → "Allowed memory size exhausted" на каждом
ответе/запросе.
Статус: исправлено в Response.php, ServerRequest.php, OfferService.php
(archive/delete), LandingService.php (archive/delete). Ещё ~21
неподтверждённых кандидата из общего скана (много ложных срабатываний —
Grid-дефиниции с initColumns и т.п. требуют ручной проверки).
Детект (грубый, даёт много false positive, нужна ручная проверка):
```python
import re
# внутри function NAME(...) { ... } ищем self::NAME( или $this->NAME(
```

## Паттерн 4: Голая константа вместо `self::CONST` / `ClassName::CONST`
Признак: `const FOO = "bar";` объявлена в классе, но используется в том же
файле как голое слово `FOO` без `self::`/`static::`/`ClassName::` перед ним.
Эффект: PHP просто интерпретирует `FOO` как строку `"FOO"` (E_WARNING
"Use of undefined constant"), что БЕЗОБИДНО в большинстве мест (переменная
получает неправильное, но не проверяемое значение), НО ЛОМАЕТ логику там,
где значение реально сравнивается (switch/case, точное равенство,
array-key-lookup) — именно так сломались Cache.php (switch по типу
хранилища кэша) и CacheService.php (доступ к массиву кэшей по неверному
ключу → null → "Call to a member function on null").
Масштаб: ~228 файлов затронуты (см. scan_bugs.py). Массово чинится третьим
агентом прямо сейчас.
Детект: `grep -rn "\bCONST_NAME\b" file.php` где CONST_NAME объявлена как
`const CONST_NAME = ...;` в том же файле, и вхождение НЕ предваряется `::`.

## Паттерн 5: `self::$_prop[]` — пустые скобки в контексте ЧТЕНИЯ
Признак: `if (!isset(self::$_prop[])) { self::$_prop[] = ...; } return
self::$_prop[];` — пустые `[]` валидны только для ЗАПИСИ (append), не для
чтения → PHP Fatal "Cannot use [] for reading".
Похоже, изначально был индекс по ключу (например `self::$_prop[static::class]`
или `self::$_prop[self::getClassName()]`), который потерялся при декомпиляции.
Статус: найден и исправлен 1 раз (AbstractModel::definition() — общий для
ВСЕХ моделей кэш EntityDefinition, критичный фикс). Пока это единственный
подтверждённый случай, но стоит перепроверять при появлении новых фаталов
такого типа.
Детект: `grep -rn "\[\]\s*)\|\[\]\s*;" application/**/*.php` (грубо, нужно
руками смотреть на isset()/return контекст).

## Паттерн 6: Отсутствующий ведущий `\` перед вызовом функции из глобального неймспейса
Признак: внутри `namespace Foo\Bar;` вызывается `GuzzleHttp\Psr7\stream_for(...)`
БЕЗ ведущего `\`, из-за чего PHP пытается резолвить это как
`Foo\Bar\GuzzleHttp\Psr7\stream_for` (относительное имя с точками — не
попадает под авто-фоллбэк в глобальный неймспейс, который работает только
для простых однословных имён функций).
Эффект: "Call to undefined function Foo\Bar\GuzzleHttp\Psr7\stream_for()".
Статус: исправлено 3 подтверждённых случая (ResponseFactory.php,
HttpService.php, ServerRequestFactory.php) — все три были единственными
найденными через `grep -rn "[^\\\\]GuzzleHttp\\\\"`. Стоит перепроверить
другие часто-используемые namespaced функции (`\Symfony\...`,
`\Traffic\...` и т.п.) тем же способом, если всплывёт похожий фатал.

## Как проверять исправление (важно!)
`php -l <file>` (линт) НЕ ловит паттерны 2-3-4 полностью надёжно — нужно
реально ЗАГРУЗИТЬ класс через настоящий автозагрузчик внутри Docker:
```
docker exec tds-app php -r '
require "/app/vendor/autoload.php";
define("ROOT", "/app");
class_exists("Some\\Class\\Name");
echo "OK\n";
'
```
(контейнер `tds-app` уже поднят, live bind-mount `/app`, сеть `tds-net`,
рядом `tds-mysql` с уже залитой схемой tds_/data.sql, креды tds/secret).

Для проверки самого рендеринга страницы (не просто загрузки класса) —
полный прогон через роутер+kernel с `Application::instance()->setDebug(true)`
чтобы увидеть реальную ошибку вместо GENERAL_ERROR_MESSAGE — см. рабочий
сниппет, использованный весь этот сеанс (сохранён в отчётах агентов).

## Паттерн 7: 'Задвоенный' параметр — метод и первый аргумент называются одинаково
Признак: `function methodName($methodName, ...)` — первый параметр называется
так же, как сам метод (иногда с добавлением типа: `function name(string $name)`).
Похоже, оригинальный первый параметр стёрся при декомпиляции и на его место
подставилось имя метода. Эффект разный: несовместимость с интерфейсом
(strict_types + wrong signature), лишний обязательный параметр ломает вызовы
с меньшим числом аргументов, и т.п.
Масштаб: **80 подтверждённых мест в 39 файлах** (найдено сканом
`function (\w+)\(\s*\$?\w*\1\b` — проверять вручную по месту,
не блайнд-фиксить). Исправлено 8 из 80 (там где блокировали загрузку
страницы). Ещё 72 — предстоит.

## Паттерн 8: Перепутанные местами if/else или while/if
Признак: логика ветвления работает наоборот — либо `if`/`else` тела
поменяны местами (продакшен-конфиг грузит тестовый файл вместо боевого —
`ConfigService::findConfigPath()`), либо `while (!condition)` там, где
нужен `if (condition)` (код никогда не заходит в нужную ветку —
`CachedDataRepository::get()`).
Статус: найдено и исправлено 2 подтверждённых случая. Нет автоматического
скана — надо ловить по месту при тестировании (логика шиворот-навыворот
не детектируется одной regex-командой, нужен реальный прогон кода).

## ВАЖНО: self:: вместо static:: (отдельный от паттерна 3!)
AbstractModel.php использовал `self::$_property` вместо `static::$_property`
для 6 полей ($_aclKey, $_entityName, $_cacheKey, $_fields, $_tableName,
$_primaryKey) — из-за чего entityName()/getTableName()/aclKey()/cacheKey()
для ВСЕХ моделей в приложении возвращали NULL/дефолт AbstractModel вместо
значения конкретной модели. Это ломало систему подписки на события
("entityName must be set") и, скорее всего, много другого. ИСПРАВЛЕНО.


## Паттерн 9: `(int)` вместо `(string)`/`(array)` — массовая порча кастов
Признак: явное приведение объекта/потока к `(int)` там, где по смыслу нужен
`(string)` (тело HTTP-ответа, тело потока) или `(array)` (объект → хэш для
extract()). Каст объекта к `(int)` в PHP всегда даёт `1` (плюс warning) — то
есть эффект скрытый и не сразу заметный.
Подтверждённые места (все исправлены):
- `Traffic\Request\ServerRequestFactory::parseBody()` — `(int) $body` → `(string) $body`.
- `Core\Sandbox\SandboxContext::asHash()` — `(int) $this` → `(array) $this`
  (из-за этого `extract()` не распаковывал вообще ничего — все переменные
  шаблонов типа `$translations`, `$title` были не определены).
- `Admin\Controller\BaseController::renderView()` — `(int)` → `(string)`
  (тело отрендеренной HTML-страницы превращалось в `1`).
- `Core\ServerRenderer\ServerRenderer::_sendBody()` — та же история, самый
  последний шаг перед реальным HTTP-выводом (главная причина, почему тело
  ЛЮБОЙ страницы через настоящий веб-сервер выводилось как голая "1").
Рекомендация: искать `(int) \$` рядом с `getBody()`/`asHash()`/объектами
без явной числовой природы — весьма вероятно тот же баг.

## Паттерн 10: Пропавшее тело цикла (осиротевший код внутри условия)
Признак: кусок кода, который явно должен проходить по КАЖДОМУ элементу
массива (проверяет/квотит/экранирует значение), стоит как одинокий
`if/else` БЕЗ окружающего `foreach`, и переменные цикла (`$value`, `$num`)
используются, но нигде не объявлены/не итерируются.
Подтверждённый случай (серьёзный, с оттенком безопасности!):
`Core\Db\Db::insert()` — блок квотирования/экранирования значений
(`Db::quote($value)`) существовал как мёртвый код ВНЕ цикла, из-за чего
**INSERT одиночной записи вставлял вообще НЕ экранированные значения**
(значения без кавычек, что и ломало запись первой Settings-записи —
`INSERT ... VALUES (api_key, hash...)` без кавычек вызывало ошибку
"Unknown column"). Восстановлен `foreach ($values as $num => $value) {...}`
вокруг существующего тела. `update()`/`multiInsert()` в том же файле такого
бага не имеют — там циклы целые.

## Паттерн 11: Инвертированное условие array-vs-object (частный случай паттерна 8)
`DataConverterService::convertDateForMysql($value)` проверял
`$value instanceof \DateTime`, а внутри пытался обратиться к `$value["date"]`
(как к массиву) — ровно наоборот тому, что нужно. Правильно: `is_array($value)`
→ восстановить DateTime из `$value["date"]`; иначе (реальный объект DateTime)
→ `setTimezone()`+`format()`. Приводило к "Cannot use object of type DateTime
as array" при сохранении любой модели с датой (например, при логине —
создание `UserPasswordHash`).

## ВАЖНЫЙ УРОК (не баг оригинала, а грабли собственного фикса):
При добавлении собственного "заполнителя" вместо отсутствующих метаданных
(например, "здесь есть поле, но не знаем тип") **никогда не используйте
`true`/`false`/числа как заглушку типа**, если это значение затем попадает
в `switch ($type) { case "some_string": ... }` — PHP сравнивает `switch`
через `==` (нестрогое сравнение), и `true == "любая непустая строка"` даёт
`true`! Это привело к тому, что временная заглушка `$cols[$name] = true;`
в `AbstractModel::_realColumns()` заставляла ВСЕ поля таблицы проходить
через ветку `Type::BOOLEAN` (первый `case`), превращая каждое строковое
значение (`"admin"`, `"en"` и т.д.) в `1`. Заменено на безопасную строку-
заглушку `"__auto__"`, гарантированно не совпадающую нестрого ни с одной
реальной константой `Core\Type\Type::*`.

## Паттерн 12: `$_fields` никогда не заполняется ни у одной модели → каскад багов
Корневая причина сразу нескольких симптомов в этом сеансе: КАЖДАЯ модель
(`Campaign`, `Setting`, `User`, `UserPreference` и т.д.) объявляет
`protected static $_fields = NULL;` и никогда не переопределяет его реальным
списком полей. Это ломало (все исправлены):
- `EntityDefinition::hasField()` — всегда `false` → `EntityService::build()`
  не мог создать НИ ОДНУЙ новой записи ("Empty data").
- `AbstractModel::hasField()`/`get()`/`set()`/`setData()` — всегда `false` →
  ЛЮБОЕ обращение к полю модели кидало ValidationError.
- `AbstractModel::serialize()` — использовал сырой (пустой) `$_fields`
  напрямую вместо `definition()->fields()`.
Решение: `AbstractModel::_realColumns()` — новый метод, лениво вычисляющий
реальный список колонок через `SHOW COLUMNS FROM <table>` (с кэшем на
таблицу), используется как fallback everywhere, где раньше слепо читали
`static::$_fields`. НЕ даёт настоящих ТИПОВ полей (только имена) — см.
Паттерн 9 (заглушка типа) и его нюанс про `switch`.

## Найдено при живом тестировании логина (после отчёта агентов)
AuthService.php::_tryToLoadFromToken() — та же history с неверным кастом
типа (`(int)` вместо `(array)`), что уже дважды встречалась в сессии, но
в другом месте: `$decodedData = (int) \Firebase\JWT\JWT::decode(...)`
— JWT::decode() возвращает объект, приведение к (int) превращало его в
мусорное число, из-за чего `$decodedData[self::LOGIN_KEY]` всегда было
NULL, и сервер НИКОГДА не распознавал валидный cookie сессии — при этом
сам логин (POST) отвечал success:true и куку ставил правильно, но
следующий же запрос снова показывал форму входа (бесконечный цикл).
Исправлено на `(array) ...`. Проверено вживую: логин -> GET /admin/ с той
же кукой теперь отдаёт настоящий дашборд с kData.user.login="admin".

## Найдено после фикса логина — 3 новых бага, блокировавших саму админ-панель

1. AdminContext::handleException() — тот же класс 'неправильно вложенных if'
   (паттерн 8), но на уровне ОБРАБОТЧИКА ОШИБОК всей админки: было 6 уровней
   вложенных if(instanceof X), из-за чего почти любое исключение (кроме
   специально сконструированного, одновременно являющегося 5 разными типами
   исключений сразу — то есть практически никогда) проваливалось в
   недоделанный handleLicenseError() и возвращало null вместо ответа —
   отсюда 'Argument 1 ... must be Response, null given' на КАЖДОЙ ошибке в
   любом контроллере админки. Исправлено: превращено в плоскую if/elseif
   цепочку, каждый response привязан к своему настоящему условию, финальный
   fallback — рабочий CommonErrorHandler::handleAny().

2. MigrationsRepository::mapFileNameToClassName() — 'list($date, $fileName)
   = $matches;' не пропускал нулевой элемент $matches[0] (полное совпадение
   regex), из-за чего дата миграции дублировалась в имени класса
   ('Migration_20190125105401_remove_antibot_20190125105401' вместо
   '..._remove_antibot'), ломая diagnostics.index (проверка миграций).
   Исправлено на 'list(, $date, $fileName) = $matches;'.

3. Column.php (application/Component/Grid/Definition/Column.php) — три поля
   $_validOptions/$_validTypes/$_gridDefinitionOptions объявлены, но НИГДЕ
   не заполнялись (NULL) — блокировало создание ЛЮБОЙ колонки грида
   ('Option X is unknown' на первой же опции). Это фундаментальный класс,
   используется вообще всеми отчётами/гридами в панели. Восстановлено в
   конструкторе по уже существующим константам того же класса (список типов
   колонок BOOLEAN/INTEGER/... и список имён опций TYPE/SORTABLE/...).

## Ещё 7 файлов с той же self:: recursion (initColumns), не пойманных
исходным сканом (были в списке 'кандидатов' ещё в начале сессии, но не
подтверждены как реальный баг тогда):
OfferGridDefinition, AffiliateNetworkGridDefinition, LandingGridDefinition,
ConversionsLogDefinition, TrafficSourceGridDefinition, CampaignGridDefinition,
ReportDefinition — все вызывали self::initColumns() вместо
parent::initColumns(). Исправлено во всех 7.

## Логин: (int) вместо (array) при декодировании JWT
AuthService::_tryToLoadFromToken() — $decodedData = (int) JWT::decode(...)
превращал объект в мусорное число, из-за чего кука сессии никогда не
распознавалась обратно (логин отвечал success, но следующий запрос снова
показывал форму входа). Исправлено на (array). Проверено вживую полным
циклом логин -> дашборд.

## Ещё 3 находки в процессе клика по панели

1. **BatchAdminDispatcher** — строка, собирающая результат для фронтенда
   (`$result[] = [...]`), стояла ТОЛЬКО внутри catch-блока — при успешном
   под-запросе в пачке ('bulk') в результат вообще ничего не добавлялось.
   Фронтенд ждёт один результат на каждый под-запрос по порядку — получал
   меньше, чем ожидал, отсюда 'Cannot read properties of undefined
   (reading statusCode)'. Исправлено: строка вынесена из try/catch, чтобы
   выполняться всегда.

2. **AbstractModel::definition()** — внутри самих ЗАМЫКАНИЙ (closures) для
   serializer/repository/service/report_definition/validator стояло
   `self::` вместо `static::`. В отличие от обычных вызовов методов, `self::`
   внутри closure ВСЕГДА резолвится к классу, где closure текстуально
   написан (AbstractModel), а не к реальному вызывающему классу (Campaign/
   Offer/...) — даже если сам closure вызван позже через late static
   binding. Из-за этого ЛЮБАЯ модель получала репозиторий/сервис/etc.
   базового AbstractModel (пустые заглушки) вместо своих настоящих —
   'repository is not set for Traffic\Model\Offer' и т.п. Исправлено на
   static:: во всех 5 замыканиях.

3. **Db.php: полностью отсутствующий метод `slaveInstance()`** — САМОЕ
   интересное открытие сессии: на его месте в файле буквально остался
   **лог краша самого декомпилятора** (комментарий вида 'ERROR in
   processing the function: Object reference not set... at
   a4c0de.PHP.Ioncube.ZNode.SameAs... at a4c0de.PHP.Parsers.OpcodeParser').
   То есть инструмент декомпиляции ionCube→PHP в этом месте сам упал и
   просто не смог восстановить метод — оставил свой краш-лог вместо кода.
   Это первое и единственное найденное 'надгробие' такого рода в проекте
   (проверено сканом по всему application/ — больше таких нет). Метод
   реализован заново по образцу соседнего instance() (с грациозным откатом
   на master, если db_slave не настроен).

## Как это использовать дальше
Если наткнётесь на СТРАННЫЙ комментарий в духе стектрейса/ошибки внутри
.php файла (не структурированный docblock, а именно похожий на вывод
консоли/лог исключения) — это стопроцентный признак того, что декомпилятор
не справился с этим конкретным местом и метод/кусок кода нужно
восстанавливать с нуля по контексту (как slaveInstance() выше), а не
искать 'опечатку' — там просто ничего нет.


## Паттерн 13: `Db::instance()` — проверка несуществующей локальной переменной вместо static-свойства (САМЫЙ КРИТИЧНЫЙ БАГ СЕССИИ)
`Db::instance()` содержал `if (!isset($instance)) { ... self::$_masterInstance = new Db($cnf); }`
— `$instance` НИКОГДА не присваивается в этой функции, значит `isset($instance)`
всегда `false`, и **условие всегда истинно**: каждый вызов `Db::instance()`
(а их за один HTTP-запрос десятки) создавал НОВЫЙ объект `Db` с нуля —
новое MySQL-соединение, обнулённый счётчик транзакций, обнулённый
`_transactionManager`. Эффект каскадный:
- Транзакции ломались с "Attempt to commit without an open transaction" /
  `RELEASE SAVEPOINT level-1` (SQL-синтаксис-ошибка), если между `begin()`
  и `commit()` где-то в цепочке вызывался `Db::instance()` заново (а он
  вызывается практически в каждом запросе/репозитории) — подловлено на
  `CampaignsController::updateAction()` → `StreamService::updateStreams()`.
- МАССОВЫЙ churn MySQL-соединений: НИ ОДНО соединение не переиспользуется
  нигде в приложении, каждая операция с БД открывает новый TCP/PDO коннект
  — это и есть причина периодических `SQLSTATE[HY000] [2002] Cannot assign
  requested address` под нагрузкой (исчерпание портов), а не только моё
  активное тестирование через docker exec/curl.
Исправлено: `if (!isset($instance))` → `if (!isset(self::$_masterInstance))`.
Одна строка, но самый высокоприоритетный фикс сессии по влиянию.
Детект: искать `if (!isset($X))` в static-методах-синглтонах, где `$X` —
локальное имя переменной, СОВПАДАЮЩЕЕ по смыслу с существующим
static-свойством класса (`self::$_masterInstance`, `self::$_instance` и
т.п.), но написанное БЕЗ префикса `self::`/`static::`.

## Паттерн 14: скрытая лицензионная "мина" — CampaignsController::withStatsAction()
Отдельная (не декомпиляционная, а намеренная) находка: в начале метода
стояла проверка `if (@file_get_contents(ROOT."/var/license/key.lic") ===
"1111-1111-1111-1111" || substr_count(@file_get_contents(ROOT.
"/application/Core/Application/TsService.php"), "return true", 0) === 4)
{ return []; }` — если ключ лицензии равен плейсхолдеру "взломанного" ключа
ИЛИ в `TsService.php` ровно 4 вхождения "return true" (как раз получилось
из-за уже пропатченного до нас файла, см. PROJECT_AS_DELIVERED.md п.4) —
вкладка Campaigns молча пустая. Похоже на анти-пиратскую ловушку от
вендора/фрилансера, наказывающую за признаки взлома лицензии. Убрано
полностью (та же категория, что уже вырезанные лицензия/самообновление).
Проверено сканом по всему application/ — больше таких мин с этой сигнатурой
(`1111-1111-1111-1111`, `substr_count`+`file_get_contents` себя же) нет.

## Ещё 13 новых подтверждённых случаев Паттерна 3 (self:: вместо parent::)
Найдено системным сканом всех методов на `self::SAME_METHOD_NAME(...)`
внутри тела метода с этим же именем (после отсева 3 ложных срабатываний —
легитимной рекурсии в `Tools::getFolderSize()`, `Tools::utf8ize()`,
`LocaleService::_find()`, где рекурсия идёт по данным, а не по себе):
- `KtrkDispatcher::dispatch()`, `AdminApiContext::handleException()`,
  `DomainService::update()`, `StreamSearchResultSerializer::extra()`,
  `StreamService::delete()/create()/update()` (все 3!),
  `DecoratedUserSerializer::extra()`, `MetricsService::instance()` (сам
  синглтон-геттер рекурсил в себя — гарантированный infinite loop при
  ЛЮБОМ обращении к сервису), `StreamFilterValidator::validate()`,
  `Isp::getTemplate()`, `ImkloDetect::getTemplate()`,
  `HideClickDetect::getTemplate()`. Все переведены на `parent::`.
`StreamService::create()/update()` — вероятная причина падений создания/
редактирования кампаний со стримами (campaigns.create/update тянут за
собой StreamService).
