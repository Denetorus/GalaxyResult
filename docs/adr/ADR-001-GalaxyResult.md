# ADR-001 — GalaxyResult

[Русский](ADR-001-GalaxyResult.ru.md)

**Status Accepted.**

## Purpose

GalaxyResult is a standalone PHP 8.4+ library for accumulating a structured operation result. The denetorus/galaxy-result package uses PSR-4 Galaxy\Result and requires only PHP at runtime. GResult holds a name, data and three entry collections. Formatting and delivery belong to the calling application or external components.

## Public API

The self type means GResult. An entry's category is determined by its collection.

    __construct(array $limits = [], ?string $name = null)
    getName(): string
    isSuccess(): bool
    getCode(): int
    getConsoleCode(): int
    hasData(string $key): bool
    getData(string $key): mixed
    getAllData(): array
    getDataRevision(): int
    setData(string $key, mixed $value): self
    replaceData(array $data): self
    removeData(string $key): bool
    clearData(): self
    copyDataFrom(self $source, bool $clearSource = false): self
    addMessage(string $text, array $context = []): string
    addWarning(int $code, string $text, array $context = []): string
    addError(int $code, string $text, array $context = [], int $consoleCode = 1): string
    getMessages(): array
    getWarnings(): array
    getErrors(): array
    getEntry(string $uid): array
    updateEntry(string $uid, array $changes): self
    removeMessage(string $uid): bool
    removeWarning(string $uid): bool
    removeError(string $uid): bool
    clearMessages(): self
    clearWarnings(): self
    clearErrors(): self
    copyMessagesFrom(self $source, bool $clearSource = false): self
    copyWarningsFrom(self $source, bool $clearSource = false): self
    copyErrorsFrom(self $source, bool $clearSource = false): self

The name is immutable. An explicit name is preserved verbatim. For null, the class of the nearest external caller frame obtained without arguments or objects is used, falling back to GResult. Reinitialization, native cloning and serialization are disabled.

## Data and limits

Allowed values are null, bool, int, finite float, string and arrays of these values. Types, keys and order are preserved. References are isolated on input and when returning snapshots. Cycles and invalid values are rejected atomically. hasData distinguishes a missing key from an explicit null. For a missing key, getData throws OutOfBoundsException. dataRevision starts at zero and changes only when data actually changes, including array order and the sign of zero. Entries do not change this revision. copyDataFrom merges the top level, with source values replacing receiver values under matching keys. Integer keys are preserved without renumbering. Preparation of the receiver, source clearing and both revisions precedes publication. Self-copy is rejected.

Limits accept known positive int values. Defaults are maxDepth=32, maxValueNodes=100000, maxStringBytes=16777216, maxEntries=4096. The data snapshot has a separate budget from the combined snapshot of the three collections. maxEntries applies to the total number of entries. Depth starts at zero for data and for each context. Budgets count logical nodes, string keys and values, including expanded references and entry fields. The complete candidate is validated before mutation.

## Entries and operation outcome

Messages, warnings and errors are ordered by insertion. Snapshots are indexed by UID. Messages and warnings contain uid, originPath, text, code and context. Errors contain these fields followed by consoleCode. A UID is 32 lowercase hexadecimal characters generated from 16 random bytes. A collision is rejected atomically. A new entry's originPath equals the result's name. updateEntry accepts text, context and, for warnings/errors, code. For errors it also accepts consoleCode. UID, category, position and originPath are preserved. Reading or updating an unknown UID throws OutOfBoundsException. Removing a missing UID or a UID belonging to another collection returns false.

A message has code=null. Adding a warning/error or changing its code requires a positive int of at least 1. Zero and negative values throw InvalidArgumentException before mutation. isSuccess corresponds to an empty errors collection. getCode returns the first current error's code, or 0 when there are no errors. Warnings leave the outcome unchanged. The caller owns the code registry and interpretation. GResult exceptions do not create entries. getCode is a business code. The calling CLI adapter explicitly assigns its console status through consoleCode. HTTP mapping belongs to the caller.

## Explicit entry copying

Copy appends the selected collection in source order, preserves UIDs and detaches values. Each originPath receives a prefix consisting of the receiver's name and '->'. Self-copy, a UID collision in any receiver collection, and validation or limit failures reject the entire operation. With clearSource=true, removal of the selected source collection is published after both sides have been successfully prepared. With false, the source is preserved.

## Accepted consoleCode amendment and publication boundary

This section describes the accepted local GF-0013 implementation. The published `0.1.0` contract is preserved by its tag and Release. The new `consoleCode` API becomes available through Packagist after a future release whose version must be approved separately.

```text
addError(int $code, string $text, array $context = [], int $consoleCode = 1): string
getConsoleCode(): int
```

The optional final parameter preserves earlier positional and named `addError` calls. Only error snapshots gain a sixth field, in the order `uid`, `originPath`, `text`, `code`, `context`, `consoleCode`. Messages and warnings retain five fields.

`consoleCode` is an integer from 1 to 255, defaulting to 1. `getCode()` returns the business code of the first current error, and `getConsoleCode()` returns the console code of that same error. Both return 0 when errors are empty. `isSuccess()` depends on the errors collection. Removing or clearing errors updates both aggregates consistently. Updating only `consoleCode` preserves the business code, entry collection, UID, order, originPath and `dataRevision`.

Adding an out-of-range console code throws `InvalidArgumentException` before mutation. The native `int` parameter follows the calling PHP code's strictness rules. `updateEntry()` accepts `consoleCode` only for errors and requires an actual `int` in range, without coercion. Invalid values or composite updates throw `InvalidArgumentException` atomically, preserving the complete previous state.

`copyErrorsFrom()` preserves both codes and UIDs and prefixes `originPath` with the receiver's name. The receiver and any `clearSource` transition are fully prepared and checked against limits before either side is published. The new field consumes a value node and its key consumes string bytes in the shared entry budget, so entries near the previous limits may exceed it. Data and its revision have their independent rules.

The calling CLI adapter explicitly supplies the console status it owns. GRouter integration remains a separate stage. For the current local implementation after installing its development dependencies, save this example under `docs-verification/` in the checkout and run it. It prints `1001`, `3` and `false`, then verifies the copied codes, UID and `Receiver->Source` origin.

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
