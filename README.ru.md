# GalaxyResult

[English](README.md)

Самостоятельная библиотека PHP 8.4+ для накопления результата операции. Пакет `denetorus/galaxy-result` использует PSR-4 и пространство имён `Galaxy\Result`. Для работы библиотеки достаточно PHP.

`GResult` хранит имя, данные и упорядоченные коллекции сообщений, предупреждений и ошибок. Записи можно явно копировать между результатами. Код результата берётся из первой текущей ошибки либо равен `0`, если ошибок нет. Предупреждения не меняют итог операции. Оформление и доставка принадлежат вызывающему приложению или внешним компонентам.

Полный контракт описан в [ADR-001](docs/adr/ADR-001-GalaxyResult.ru.md).

## Getting Started

Из каталога приложения установите пакет из Packagist с PHP 8.4+:

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

## Тесты

После установки dev-зависимостей командой `composer install` выполните из корня пакета:

```text
php vendor/bin/phpunit --configuration phpunit.xml
```

## Лицензирование

Библиотека распространяется по лицензии MIT. См. [LICENSE](LICENSE).
