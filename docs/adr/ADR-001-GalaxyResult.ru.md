# ADR-001 — GalaxyResult

[English](ADR-001-GalaxyResult.md)

**Статус: Accepted.**

## Назначение

GalaxyResult — самостоятельная библиотека PHP 8.4+ для накопления структурированного результата операции. Пакет denetorus/galaxy-result использует PSR-4 Galaxy\Result и требует только PHP для исполнения. GResult хранит имя, данные и три коллекции записей. Форматирование и доставка относятся к вызывающему приложению или внешним компонентам.

## Публичный API

Тип self означает GResult. Категория записи определяется коллекцией.

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

Имя неизменяемо. Явное имя сохраняется дословно. При null используется класс ближайшего внешнего вызывающего кадра, полученного без аргументов и объектов, либо GResult. Повторная инициализация, нативное клонирование и сериализация закрыты.

## Данные и лимиты

Допустимы null, bool, int, конечный float, string и массивы этих значений. Типы, ключи и порядок сохраняются. Ссылки изолируются на входе и при выдаче снимков. Циклы и недопустимые значения отклоняются атомарно. hasData различает отсутствующий ключ и явный null. Для отсутствующего ключа getData выбрасывает OutOfBoundsException. dataRevision начинается с нуля и меняется только при фактическом изменении данных, включая порядок массива и знак нуля. Записи не меняют эту ревизию. copyDataFrom объединяет верхний уровень, значения источника заменяют одноимённые значения получателя. Целочисленные ключи не перенумеровываются. Подготовка получателя, очистки источника и обеих ревизий предшествует публикации. Самокопирование отклоняется.

Лимиты принимают известные положительные int. Defaults: maxDepth=32, maxValueNodes=100000, maxStringBytes=16777216, maxEntries=4096. Снимок данных имеет отдельный бюджет от суммарного снимка трёх коллекций. maxEntries применяется суммарно к записям. Глубина начинается с нуля для данных и каждого context. Бюджеты учитывают логические узлы, строковые ключи и значения, включая развёрнутые ссылки и поля записи. Проверка полного кандидата предшествует мутации.

## Записи и итог операции

Messages, warnings, errors упорядочены по добавлению. Снимки индексируются по UID. Запись состоит из uid, originPath, text, code, context. UID — 32 строчных шестнадцатеричных символа из 16 случайных байтов. Коллизия отклоняется атомарно. originPath новой записи равен имени результата. updateEntry принимает text, context и для warnings/errors code. UID, категория, позиция и originPath сохраняются. Неизвестный UID при чтении или обновлении даёт OutOfBoundsException. Удаление отсутствующего или принадлежащего другой коллекции UID возвращает false.

Message имеет code=null. Для добавления warning/error и смены их кода требуется положительный int от 1. Ноль и отрицательные значения выбрасывают InvalidArgumentException до мутации. isSuccess соответствует пустой коллекции errors. getCode возвращает код первой текущей ошибки либо 0 при отсутствии ошибок. Предупреждения не меняют итог. Вызывающий владеет реестром и трактовкой кодов. Исключения GResult не создают записи. Код результата отделён от OS exit и HTTP status.

## Явное копирование записей

Copy дописывает выбранную коллекцию в порядке источника, сохраняет UID и отделяет значения. Каждый originPath получает префикс имени получателя и '->'. Самокопирование, коллизия UID в любой коллекции получателя и отказ валидации или лимитов отклоняют операцию целиком. При clearSource=true удаление выбранной коллекции источника публикуется после успешной подготовки обеих сторон. При false источник сохраняется.
