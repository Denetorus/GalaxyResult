# GalaxyResult

[Русский](README.ru.md)

A standalone PHP 8.4+ library for accumulating an operation result. The `denetorus/galaxy-result` package uses PSR-4 and the `Galaxy\Result` namespace. Only PHP is required at runtime.

`GResult` holds a name, data and ordered collections of messages, warnings and errors. Entries can be explicitly copied between results. The result code is the first current error's code, or `0` when there are no errors. Warnings leave the operation outcome unchanged. Formatting and delivery belong to the calling application or external components.

The full contract is defined in [ADR-001](docs/adr/ADR-001-GalaxyResult.md).

## Installation — 0.2.0

This README describes the approved `0.2.0` API, including `consoleCode` and `getConsoleCode()`. Version `0.2.0` is not yet published. The command below becomes available after the `v0.2.0` tag is published and Packagist indexes the version. From your application directory with PHP 8.4+:

```text
composer require denetorus/galaxy-result:0.2.0
```

`composer.lock` records the selected revision. Load `vendor/autoload.php` in your application.

## Published 0.1.0

Version `0.1.0` is currently available through Packagist. It has no `consoleCode` parameter, error field or `getConsoleCode()` method. To install it from your application directory:

```text
composer require denetorus/galaxy-result:0.1.0
```

The `0.1.0` constraint selects the published version, and `composer.lock` records the selected revision. Load `vendor/autoload.php` in your application.

Save the following PHP file beside the `vendor` directory and run it:

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

`hasData()` distinguishes a missing key from an existing `null`. For an existing `null`, `getData()` returns `null`. Reading a missing key or UID throws `OutOfBoundsException`.

Data and context accept plain values — `null`, bool, int, finite float, string and arrays of these values. References are isolated on input and when returning snapshots. Data has a separate limit budget from the combined budget of the three entry collections. A type or limit violation rejects the operation atomically, preserving the previous state.

Warning and error codes must be positive integers. A code `<= 0` causes `InvalidArgumentException`. Self-copy and a UID collision during copying cause `LogicException`. Copying preserves UIDs and prefixes `originPath` with the receiver's name.

## consoleCode API — 0.2.0

The following API is implemented for the approved `0.2.0` version. Its installation depends on the publication and indexing described above.

```text
addError(int $code, string $text, array $context = [], int $consoleCode = 1): string
getConsoleCode(): int
```

The optional final parameter preserves earlier positional and named `addError` calls. Only error snapshots gain a sixth field, in the order `uid`, `originPath`, `text`, `code`, `context`, `consoleCode`. Messages and warnings retain five fields.

`consoleCode` is an integer from 1 to 255, defaulting to 1. `getCode()` returns the business code of the first current error, and `getConsoleCode()` returns the console code of that same error. Both return 0 when errors are empty. `isSuccess()` depends on the errors collection. Removing or clearing errors updates both aggregates consistently. Updating only `consoleCode` preserves the business code, entry collection, UID, order, originPath and `dataRevision`.

Adding an out-of-range console code throws `InvalidArgumentException` before mutation. The native `int` parameter follows the calling PHP code's strictness rules. `updateEntry()` accepts `consoleCode` only for errors and requires an actual `int` in range, without coercion. Invalid values or composite updates throw `InvalidArgumentException` atomically, preserving the complete previous state.

`copyErrorsFrom()` preserves both codes and UIDs and prefixes `originPath` with the receiver's name. The receiver and any `clearSource` transition are fully prepared and checked against limits before either side is published. The new field consumes a value node and its key consumes string bytes in the shared entry budget, so entries near the previous limits may exceed it. Data and its revision have their independent rules.

The calling CLI adapter explicitly supplies the console status it owns. GRouter integration remains a separate stage. After installing `0.2.0` when it becomes available, save this example under `examples/` in your application directory and run it. It prints `1001`, `3` and `false`, then verifies the copied codes, UID and `Receiver->Source` origin.

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

## Tests

After installing development dependencies with `composer install`, run from the package root:

```text
php vendor/bin/phpunit --configuration phpunit.xml
```

## License

The library is distributed under the MIT license. See [LICENSE](LICENSE).
