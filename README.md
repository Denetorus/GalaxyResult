# GalaxyResult

Самостоятельная библиотека PHP 8.4+ для накопления результата операции. Пакет `denetorus/galaxy-result` использует PSR-4 и пространство имён `Galaxy\Result`. Для работы библиотеки достаточно PHP.

`GResult` хранит имя, данные и упорядоченные коллекции сообщений, предупреждений и ошибок. Записи можно явно копировать между результатами. Код предупреждения или ошибки — положительное целое число. Код результата берётся из первой текущей ошибки либо равен `0`, если ошибок нет. Оформление и доставка принадлежат вызывающему приложению или внешним компонентам.

Полный контракт описан в [ADR-001](docs/adr/ADR-001-GalaxyResult.md).

## Пример

В PHP-файле с подключённой автозагрузкой Composer:

```php
use Galaxy\Result\GResult;

$result = new GResult(name: 'Import');
$result->setData('processed', 2);
$result->addWarning(1, 'Одна строка пропущена');

echo $result->getCode(), PHP_EOL; // 0

$result->addError(2, 'Импорт прерван');
echo $result->getCode(), PHP_EOL; // 2
```

## Тесты

После установки dev-зависимостей Composer выполните из корня пакета:

```text
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-record-test-run-history --fail-on-warning --fail-on-phpunit-deprecation tests
```

## Лицензирование

Библиотека распространяется по лицензии MIT. См. [LICENSE](LICENSE).
