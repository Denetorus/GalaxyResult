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
    addError(int $code, string $text, array $context = []): string
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

Messages, warnings and errors are ordered by insertion. Snapshots are indexed by UID. Each entry consists of uid, originPath, text, code and context. A UID is 32 lowercase hexadecimal characters generated from 16 random bytes. A collision is rejected atomically. A new entry's originPath equals the result's name. updateEntry accepts text, context and, for warnings/errors, code. UID, category, position and originPath are preserved. Reading or updating an unknown UID throws OutOfBoundsException. Removing a missing UID or a UID belonging to another collection returns false.

A message has code=null. Adding a warning/error or changing its code requires a positive int of at least 1. Zero and negative values throw InvalidArgumentException before mutation. isSuccess corresponds to an empty errors collection. getCode returns the first current error's code, or 0 when there are no errors. Warnings leave the outcome unchanged. The caller owns the code registry and interpretation. GResult exceptions do not create entries. The result code is separate from OS exit and HTTP status.

## Explicit entry copying

Copy appends the selected collection in source order, preserves UIDs and detaches values. Each originPath receives a prefix consisting of the receiver's name and '->'. Self-copy, a UID collision in any receiver collection, and validation or limit failures reject the entire operation. With clearSource=true, removal of the selected source collection is published after both sides have been successfully prepared. With false, the source is preserved.
