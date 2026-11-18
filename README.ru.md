# GalaxyResult

[English](README.md)

Самостоятельная библиотека PHP 8.4+ для накопления результата операции. Пакет `denetorus/galaxy-result` использует PSR-4 и пространство имён `Galaxy\Result`. Для работы библиотеки достаточно PHP.

`GResult` хранит имя, данные и упорядоченные коллекции сообщений, предупреждений и ошибок. Записи можно явно копировать между результатами. Код результата берётся из первой текущей ошибки либо равен `0`, если ошибок нет. Предупреждения не меняют итог операции. Оформление и доставка принадлежат вызывающему приложению или внешним компонентам.

Полный контракт описан в [ADR-001](docs/adr/ADR-001-GalaxyResult.ru.md).

## Установка — 0.2.0

Этот README описывает утверждённый API `0.2.0`, включая `consoleCode` и `getConsoleCode()`. Версия `0.2.0` ещё не опубликована. Команда ниже станет доступна после публикации тега `v0.2.0` и индексации версии в Packagist. Выполните её из каталога приложения с PHP 8.4+:

```text
composer require denetorus/galaxy-result:0.2.0
```

`composer.lock` фиксирует выбранную ревизию. Подключите `vendor/autoload.php` в приложении.

## Опубликованная 0.1.0

Версия `0.1.0` сейчас доступна через Packagist. В ней отсутствуют параметр и поле ошибки `consoleCode`, а также метод `getConsoleCode()`. Для её установки из каталога приложения:

```text
composer require denetorus/galaxy-result:0.1.0
```

Требование `0.1.0` выбирает опубликованную версию, а `composer.lock` фиксирует выбранную ревизию. Подключите `vendor/autoload.php` в приложении.

Сохраните следующий PHP-файл рядом с каталогом `vendor` и запустите его:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Galaxy\Result\GResult;

$source = new GResult(name: 'Source');
$source->setData('processed', 2);
if ($source->getData('processed') !== 2) {
    throw new RuntimeException('Unexpected data');
}
echo $source->getCode(), PHP_EOL; // 0

$uid = $source->addError(2, 'Import interrupted');
$receiver = new GResult(name: 'Receiver');
$receiver->copyErrorsFrom($source);
$entry = $receiver->getEntry($uid);
if ($entry['uid'] !== $uid || $entry['originPath'] !== 'Receiver->Source') {
    throw new RuntimeException('Unexpected copied entry');
}
echo $receiver->getCode(), PHP_EOL; // 2
```

`hasData()` различает отсутствующий ключ и существующий `null`. Для существующего `null` метод `getData()` возвращает `null`. Чтение отсутствующего ключа или UID выбрасывает `OutOfBoundsException`.

Данные и context принимают plain values — `null`, bool, int, конечный float, string и массивы этих значений. Ссылки изолируются на входе и при выдаче снимков. Данные имеют отдельный бюджет лимитов от суммарного бюджета трёх коллекций записей. При нарушении типа или лимита операция отклоняется атомарно, сохраняя предыдущее состояние.

Коды предупреждений и ошибок должны быть положительными целыми числами. Код `<= 0` вызывает `InvalidArgumentException`. Самокопирование и коллизия UID при копировании вызывают `LogicException`. Копирование сохраняет UID и добавляет имя получателя к `originPath`.

## API consoleCode — 0.2.0

Следующий API реализован для утверждённой версии `0.2.0`. Его установка зависит от описанных выше публикации и индексации.

```text
addError(int $code, string $text, array $context = [], int $consoleCode = 1): string
getConsoleCode(): int
```

Последний необязательный параметр сохраняет прежние позиционные и именованные вызовы `addError`. Только снимки ошибок получают шестое поле в порядке `uid`, `originPath`, `text`, `code`, `context`, `consoleCode`. Сообщения и предупреждения сохраняют пять полей.

`consoleCode` — целое число от 1 до 255, default 1. `getCode()` возвращает бизнес-код первой текущей ошибки, а `getConsoleCode()` — консольный код той же ошибки. При пустых errors оба возвращают 0. `isSuccess()` зависит от коллекции errors. Удаление и очистка ошибок согласованно меняют оба агрегата. Изменение только `consoleCode` сохраняет бизнес-код, коллекцию записи, UID, порядок, originPath и `dataRevision`.

Добавление консольного кода вне диапазона выбрасывает `InvalidArgumentException` до мутации. Нативный параметр `int` следует правилам strictness вызывающего PHP-кода. `updateEntry()` принимает `consoleCode` только для errors и требует настоящий `int` в диапазоне без преобразования. Неверные значения и составные правки выбрасывают `InvalidArgumentException` атомарно с сохранением полного прежнего состояния.

`copyErrorsFrom()` сохраняет оба кода и UID и добавляет к `originPath` имя получателя. Получатель и переход при `clearSource` полностью подготавливаются и проверяются по лимитам до публикации любой стороны. Новое поле расходует узел значения, а его ключ расходует строковые байты общего бюджета записей, поэтому записи возле прежних границ могут превысить лимит. Данные и их ревизия сохраняют независимые правила.

Вызывающий CLI-адаптер явно задаёт принадлежащий ему консольный статус. Интеграция GRouter остаётся отдельным этапом. После установки `0.2.0`, когда она станет доступна, сохраните этот пример в `examples/` внутри каталога приложения и запустите. Он выводит `1001`, `3` и `false`, затем проверяет скопированные коды, UID и происхождение `Receiver->Source`.

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Galaxy\Result\GResult;

$source = new GResult(name: 'Source');
$uid = $source->addError(code: 1001, text: 'Command failed.', consoleCode: 3);
if ($source->getCode() !== 1001 || $source->getConsoleCode() !== 3 || $source->isSuccess() !== false) {
    throw new RuntimeException('Unexpected source codes');
}
echo $source->getCode(), PHP_EOL;
echo $source->getConsoleCode(), PHP_EOL;
echo json_encode($source->isSuccess(), JSON_THROW_ON_ERROR), PHP_EOL;

$receiver = new GResult(name: 'Receiver');
$receiver->copyErrorsFrom($source);
$entry = $receiver->getEntry($uid);
if ($receiver->getCode() !== 1001 || $receiver->getConsoleCode() !== 3 || $receiver->isSuccess() !== false
    || $entry['uid'] !== $uid || $entry['originPath'] !== 'Receiver->Source') {
    throw new RuntimeException('Unexpected copied error');
}
```

## Тесты

После установки dev-зависимостей командой `composer install` выполните из корня пакета:

```text
php vendor/bin/phpunit --configuration phpunit.xml
```

## Лицензирование

Библиотека распространяется по лицензии MIT. См. [LICENSE](LICENSE).
