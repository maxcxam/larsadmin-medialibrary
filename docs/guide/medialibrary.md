# Медіатека: поля `Media` і `MediaGallery`
🇬🇧 [English](en/medialibrary.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/uk/docs/medialibrary)

Доповнення `maxcxam/larsadmin-medialibrary` додає в панель Larsadmin два поля для колекцій
[spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary):

- `Maxcxam\LarsadminMedialibrary\Fields\Media`: один файл чи зображення колекції (обкладинка, аватар, договір).
  Нове завантаження замінює поточне.
- `Maxcxam\LarsadminMedialibrary\Fields\MediaGallery`: кілька медіа колекції в порядку колекції (`order_column`);
  порядок змінюється у формі й на сторінці запису.

Поля завантажують файли разом з формою, показують прев'ю з вибраного перетворення, віддають приватні медіа через
підписані чи тимчасові посилання, перевіряють права користувача при кожному завантаженні й узгоджують файли з
транзакцією збереження. Опційний `stripMetadata()` прибирає EXIF/GPS із фото перед збереженням.

Доповнення побудоване лише на публічному API розширень ядра. Ядро від медіатеки не залежить: застосунки без
доповнення нічого нового не отримують. Файли в звичайних колонках моделі (без медіатеки) обслуговують поля ядра
`File` і `Image` (посібник 15 ядра, `vendor/maxcxam/larsadmin/docs/guide/15-files.md`).

## Вимоги

| Що | Версія |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 12, 13 |
| `maxcxam/larsadmin` | `^1.6` |
| `spatie/laravel-medialibrary` | `^11.23` (потребує `ext-exif` і `ext-fileinfo`) |

Перетворення робить `spatie/image`, тож для них потрібен GD або Imagick. `stripMetadata()` розширень не потребує.
Хосту не потрібні Node.js чи npm: доповнення постачає зібрані Vue-файли.

## Встановлення

```bash
composer require maxcxam/larsadmin-medialibrary
```

Доповнення реєструється само через package discovery: без `vendor:publish`, власного конфігу й збірки npm.

Якщо медіатека у вашому застосунку нова, опублікуйте й виконайте її міграцію (таблиця `media`):

```bash
php artisan vendor:publish --tag=medialibrary-migrations
php artisan migrate
```

Власний конфіг медіатеки необов'язковий (`php artisan vendor:publish --tag=medialibrary-config`). Що перевірити:

- **Диск.** Файли потрапляють на `media-library.disk_name` (`MEDIA_DISK`, за замовчуванням `public`), якщо колекція
  чи поле не задають інший (див. [Модель — джерело істини](#модель--джерело-істини)). Для чутливих файлів беріть
  приватний диск (див. [Диски, посилання й доступ](#диски-посилання-й-доступ)).
- **Диск `public`** віддає вебсервер; йому потрібні посилання й правильний `APP_URL`:

  ```bash
  php artisan storage:link
  ```

  `getUrl()` будує абсолютні адреси з `url` диска (`APP_URL.'/storage'`). Якщо панель відкрито на іншому хості,
  ніж `APP_URL`, прев'ю не завантажуються.
- **Черга.** Перетворення за замовчуванням ставляться в чергу (`media-library.queue_conversions_by_default`).
  Запустіть worker (`php artisan queue:work`) або використайте `QUEUE_CONNECTION=sync`. Див.
  [Перетворення й прев'ю](#перетворення-й-превю).

## Швидкий старт

Модель оголошує колекції й перетворення як завжди:

```php
// app/Models/Post.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Post extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
        $this->addMediaCollection('gallery')->onlyKeepLatest(12);
        $this->addMediaCollection('contracts')->singleFile()->useDisk('local')->acceptsMimeTypes(['application/pdf']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 300, 300)
            ->performOnCollections('cover', 'gallery')
            ->nonQueued();
    }
}
```

Entity використовує поля:

```php
// app/Larsadmin/Post.php
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;

public function fields(Request $request): array
{
    return [
        Text::make('Заголовок', 'title')->rules('required'),
        Media::make('Обкладинка', 'cover')->images()->conversion('thumb')->maxSize(4096)->rules('required'),
        MediaGallery::make('Галерея', 'gallery')->images()->conversion('thumb')->limit(12)->stripMetadata(),
        Media::make('Договір', 'contract', 'contracts')->types(['pdf'])->removable(false),
    ];
}
```

Моделі потрібна Policy з `view` і `update` (посібник 05 ядра): з неї поля беруть право бачити посилання й змінювати
медіа.

Що отримує користувач:

- **Список:** перше прев'ю й лічильник `+N`.
- **Сторінка запису:** прев'ю або назви файлів з посиланнями на завантаження. Для тих, хто може оновлювати запис,
  галерея має кнопки «вгору / вниз», а кожне медіа — кнопку «Видалити»; обидві діють одразу (видалення — з
  підтвердженням) і записуються в журнал аудиту.
- **Форма:** вибір файлів, видалення наявних медіа (з «Скасувати»), зміна порядку галереї. Усе зберігається разом
  з формою, в одній транзакції із записом.

## Модель — джерело істини

Поле читає оголошення своєї колекції в моделі (`registerMediaCollections()`) і перетворень
(`registerMediaConversions()`), а не дублює їх:

| Оголошення | Що робить поле |
| --- | --- |
| `useDisk('s3')` | диск завантаження: `disk()` поля, інакше `useDisk()` колекції, інакше `media-library.disk_name` |
| `singleFile()` / `onlyKeepLatest(1)` | обов'язкове для поля `Media`: над оголошеною колекцією, що може містити більше медіа, `Media` кидає `LogicException` при першому завантаженні сторінки (інакше воно ховало б чи видаляло решту). `MediaGallery` над `singleFile()`-колекцією теж кидає `LogicException` |
| `onlyKeepLatest(n)` | обмежує галерею: ліміт `min(limit(), n)`; більше файлів — 422 до будь-якого запису |
| `acceptsMimeTypes([...])` | правило `mimetypes:` для кожного файлу (422) |
| `acceptsFile(fn)` | файл, який відхилив callback, — 422 біля цього файлу; уже записані в цьому збереженні файли прибираються |
| `registerMediaConversions()` | `conversion('thumb')` має бути зареєстроване для колекції, інакше `LogicException` при першому завантаженні сторінки. Реєстрації, що залежать від медіа (`if ($media?->mime_type === …)`), надійно перевірити не можна, і вони не перевіряються (див. [Обмеження](#обмеження)) |

Помилки конфігурації виявляються під час першого розв'язання поля (на будь-якій сторінці, що його показує), тож
з'являються в розробці й тестах, а не як 500 посеред збереження. Модель має реалізовувати `HasMedia`; інакше поле
теж кидає `LogicException`.

Тримайте обмеження в моделі: тоді й код поза панеллю (імпорти, jobs, API) дотримується тих самих правил.

## Поле `Media`

```php
Media::make('Обкладинка', 'cover')                     // колекція "cover"
Media::make('Договір', 'contract', 'contracts')         // атрибут "contract", колекція "contracts"
```

`Media::make(string $name, ?string $attribute = null, ?string $collection = null)`: атрибут за замовчуванням —
назва в snake_case (для назви з не-ASCII символами атрибут передавайте явно), колекція — атрибут. Поле містить одне
медіа: нове завантаження замінює поточне (старе медіа і його файли видаляються після коміту).

Якщо модель оголошує колекцію, вона має бути `singleFile()` (або `onlyKeepLatest(1)`); інакше поле кидає
`LogicException` при першому завантаженні сторінки, бо поле на один файл над колекцією з кількох медіа ховало б їх
або видаляло при збереженні. Колекцію, якої модель не оголошує, поле приймає: там нове завантаження замінює лише
медіа, які показала форма, а медіа, додані тим часом (іншим користувачем чи вашим кодом), ніколи не видаляються.

`rules()`, `creationRules()` і `updateRules()` працюють як у файлових полях ядра: `required` (а також `nullable`,
`sometimes`) стосується поля, будь-яке інше правило — кожного завантаженого файлу:

```php
Media::make('Обкладинка', 'cover')->images()->rules('required', 'dimensions:min_width=800');
```

`required` означає «після збереження є хоча б одне медіа» і також не дає видалити останнє медіа на сторінці запису
(422).

## Поле `MediaGallery`

```php
MediaGallery::make('Галерея', 'gallery')->images()->conversion('thumb')->limit(12);
```

`MediaGallery` розширює `Media` й має всі його методи плюс `limit(int $maxFiles)`: найбільша кількість медіа в
галереї. За замовчуванням `larsadmin.files.max_files` (10); `onlyKeepLatest(n)` колекції обмежує його. Збереження,
після якого медіа стало б більше, — 422 до будь-якого запису. `limit(0)` кидає `InvalidArgumentException`.

Порядок — `order_column` колекції. У формі нові файли додаються в кінець, а порядок зберігається з формою; на
сторінці запису «вгору / вниз» зберігає новий порядок одразу. Колекція не може бути `singleFile()`.

## Довідник API

Stable для 1.x доповнення:

| Метод | Опис |
| --- | --- |
| `Media::make(string $name, ?string $attribute = null, ?string $collection = null)` | компонент `maxcxam/media`; `MediaGallery::make()` дає `maxcxam/media-gallery` |
| `collection(string $collection)` | колекція моделі; за замовчуванням атрибут |
| `conversion(?string $conversion)` | перетворення для прев'ю, наприклад `'thumb'`; має бути зареєстроване для колекції (реєстрації, що залежать від медіа, не перевіряються). Без нього растрові зображення показуються оригіналом, зменшеним CSS |
| `disk(?string $disk)` | диск для нових файлів; `null` (за замовчуванням) — `useDisk()` колекції, інакше `media-library.disk_name`. Диск, якого немає у `filesystems.disks`, кидає `InvalidArgumentException` |
| `types(array $types)` | дозволені розширення, наприклад `['pdf', 'docx']`: правила `mimes` + `extensions` і `accept` вибору файлів |
| `images()` | лише растрові зображення, перевірка за явним переліком і за типом вмісту (`mimetypes`), і за розширенням. Без `types()` дозволяє jpg, jpeg, png, gif і webp; AVIF і BMP — лише коли їх називає `types()` (`->types(['jpg', 'png', 'avif'])`). SVG не дозволяється ніколи |
| `maxSize(int $kilobytes)` | найбільший розмір одного файлу в КБ. Діє `min(maxSize(), larsadmin.files.max_size, media-library.max_file_size)`, тож метод може лише зменшити ліміт панелі; менше 1 кидає `InvalidArgumentException` |
| `public(bool $public = true)` | вважати медіа публічними (звичайні адреси сховища й суворіший список заблокованих розширень) або, з `false`, як `private()`; за замовчуванням вирішує `visibility` диска |
| `private()` | `public(false)`: підписані чи тимчасові посилання замість звичайних адрес. Змінює **лише посилання, а не місце зберігання файлів**. На диску завантаження з публічною видимістю кидає `LogicException` (файли лишалися б доступні за адресою): чутливим файлам потрібен приватний диск |
| `removable(bool $removable = true)` | дозволити видаляти медіа у формі й на сторінці запису. З `false` кнопки «Видалити» немає, сервер нічого не видаляє (навіть з підробленого запиту), маршрут видалення відповідає 403; нове завантаження в `Media` все одно замінює поточний файл |
| `stripMetadata(bool $strip = true)` | прибирати метадані (EXIF, GPS, XMP, IPTC) з кожного зображення, завантаженого через це поле; вимкнено за замовчуванням (див. [Метадані зображень](#метадані-зображень-stripmetadata)) |
| `MediaGallery::limit(int $maxFiles)` | найбільша кількість медіа в галереї (див. вище) |

Також stable: назва розширення `maxcxam/larsadmin-medialibrary` і ключі компонентів `maxcxam/media` та
`maxcxam/media-gallery`. Усе успадковане від `Field` ядра працює як завжди: `help()`, `readonly()`, `sensitive()`,
`canSee()`, `hideFromIndex()`, `onlyOnForms()` тощо. `sortable()` кидає `LogicException` (у медіаполя немає
колонки); `fillUsing()` повністю замінює збереження.

Внутрішнє (може змінитися в будь-якому релізі): інші публічні методи класів полів, контролер, правила валідації,
очищувач метаданих, сервіс-провайдер, маршрути, формат даних форми й серіалізовані ключі. Vue-частина постачається
разом із PHP-частиною, тож вони завжди сумісні.

## Перетворення й прев'ю

`->conversion('thumb')` вибирає перетворення, яке показується як прев'ю у списку, на сторінці запису й у формі.
Оригінал лишається доступним для завантаження.

- **Перетворення без черги** (`->nonQueued()`) робляться під час збереження, у запиті панелі.
- **Перетворення в черзі** (типова поведінка медіатеки) відправляються **після коміту**
  (`media-library.queue_conversions_after_database_commit`, за замовчуванням `true`; не вимикайте). Job ніколи не
  бачить рядок медіа, який відкочене збереження прибрало, а job для медіа, видаленого тим часом, тихо завершується.
- **Доки перетворення немає**, прев'ю — оригінал (для растрових зображень) або іконка файлу з написом «Генерується
  прев'ю…» (`previewPending`). Перезавантажте сторінку, коли worker відпрацював. Файли, з якими не працює жоден
  генератор зображень (наприклад, ZIP), перетворення не отримують і не позначаються як очікувані.
- Якщо прев'ю довго лишається «Генерується прев'ю…», worker черги не запущено.

Перетворення поля зі `stripMetadata()` робляться з уже очищеного оригіналу.

## Диски, посилання й доступ

**Хто отримує посилання.** Адреси видаються, лише коли поле видиме користувачу й `Field::authorizedTo('view')`
повертає true: панель відповідає з Policy запису, яку вже викликала для рядка, тож додаткових викликів policy
немає. Поза Entity (поле, розв'язане вручну) це `false`: без посилань, лише назви. Рядки обмежують
`indexQuery()` / `detailQuery()` Entity, як завжди.

**Адреси залежно від диска:**

| Диск медіа | Адреса | Що перевіряється при кожному запиті |
| --- | --- | --- |
| публічний (`visibility => 'public'` або поле з `->public()`) | `$media->getUrl()`: постійна публічна адреса, яку віддає вебсервер | нічого: публічний за визначенням |
| приватний S3-сумісний (з тимчасовими адресами) | тимчасова адреса провайдера, час життя `larsadmin.files.url_ttl`; нерастрові файли примусово завантажуються (`attachment`, `application/octet-stream`) | підпис провайдера |
| приватний локальний | відносний підписаний маршрут панелі `larsadmin.ext.medialibrary.show` | див. нижче |

**Публічний чи приватний.** Поле вирішує для кожного медіа за диском, на якому воно лежить:

1. `->public()` / `->private()` поля мають пріоритет для посилань (`->private()` на публічному диску завантаження
   заборонено, див. нижче);
2. інакше медіа публічні, **лише якщо** конфіг цього диска має `'visibility' => 'public'`
   (`config("filesystems.disks.{disk}.visibility") === 'public'`);
3. диск, у конфігу якого **немає ключа `visibility`, приватний**. У типовому `config/filesystems.php` Laravel це
   диск `local` (`storage/app/private`) і `s3`; диск `public` має `'visibility' => 'public'`.

Приватний диск, драйвер якого не `local` і який дає тимчасові адреси (S3 і сумісні), отримує тимчасові адреси
провайдера; будь-який інший приватний диск (`local` або драйвер без тимчасових адрес) — підписаний маршрут панелі.
**`->private()` не робить файли приватними.** Він змінює лише посилання: файл пишеться на той самий диск
завантаження. На публічному диску файл лишився б у веб-корені й був би доступний кожному, хто має чи вгадає адресу,
тож поле відхиляє таке поєднання з `LogicException` при першому завантаженні сторінки. Для чутливих файлів беріть
приватний диск (`useDisk('local')` у колекції або `->disk('local')`). На непублічному диску підписані чи тимчасові
посилання й так типові, тож `->private()` там нічого не додає.

Перевірка заблокованих розширень іде за тим, де файл лежить насправді: завантаження вважається публічним (розмітка й
скрипти відхиляються завжди), коли видимість диска завантаження `public` або поле має `->public()`. `->public()` на
диску, який вебсервер не віддає (наприклад, `local`), дає адреси, що не відкриваються: застосовуйте його лише до
диска з публічним `url`.

Маршрут приватних медіа:

```
GET {path}/ext/medialibrary/{entity}/{id}/{field}/{media}?expires=…&signature=…
```

Він працює за входом і gate панелі, `signed:relative` та обмежувачем `larsadmin-files` (`larsadmin.files.throttle`).
Далі при кожному запиті:

1. запис через `Larsadmin::findRecord(…, 'view')`: Entity існує, `viewAny`, запис є в `detailQuery`, `view` на
   записі (інакше 404 / 403);
2. поле з цим атрибутом видиме на сторінці запису чи у списку або у формі редагування для користувача з `update`
   (інакше 404);
3. медіа належить колекції поля **цього** запису (інакше 404), тож чинне посилання одного запису не можна
   спрямувати на медіа іншого;
4. віддається лише оригінал або власне перетворення прев'ю поля (інакше 404).

Тож посилання перестає працювати, щойно користувач втратив доступ або медіа видалено, ще до завершення терміну.
Підписані й тимчасові посилання діють до «зараз + `url_ttl`», округлено вгору до чверті TTL, тож перезавантаження
сторінки в межах цього вікна дає ті самі адреси й кеш браузера працює.

**Як віддаються файли** (приватний локальний маршрут): через `FileSafety::response()` ядра, потоком, із
`X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`, `Cross-Origin-Resource-Policy: same-origin` і
`Referrer-Policy: no-referrer`. Файл показується inline, **лише якщо це растрове зображення і за збереженим
розширенням, і за збереженим MIME-типом**; усе інше віддається як вкладення `application/octet-stream`.

**SVG і HTML ніколи не показуються inline.** `images()` приймає лише растрові типи свого переліку, за типом
вмісту й розширенням, ніколи SVG. На публічних дисках
розмітка й скрипти (SVG, HTML, XML, JS, PHP) завжди відхиляються `larsadmin.files.blocked_extensions`, хоч би що
казав `types()`. На приватних дисках такі файли можна лише завантажити як вкладення.

**Зміни.** Форма потребує прав `create` / `update` панелі й видимого поля без `readonly()`. Зміна порядку
(`POST {path}/ext/medialibrary/{entity}/{id}/{field}/order`) і видалення
(`DELETE {path}/ext/medialibrary/{entity}/{id}/{field}/{media}`) на сторінці запису потребують `update` на записі
(`Larsadmin::findRecord(…, 'update')`) і поля, яке можна редагувати у формі редагування
(`Larsadmin::findField(…, Field::UPDATE)`); обидві дії йдуть через `Larsadmin::audited()`. Ці маршрути внутрішні:
користуйтеся ними лише через інтерфейс поля.

## Доступ для окремого запису

Хто може бачити й завантажувати медіаполе, вирішують для кожного запису Policy запису й видимість поля; підписаний
маршрут перевіряє обидва при кожному завантаженні (`view` на записі, потім поле має бути видиме користувачу для
цього запису).

`canSee()` не може залежати від запису: його callback отримує лише запит (`fn (Request $request) => …`), тож він
ховає поле для всіх записів або для жодного. Для правила, що залежить від запису, беріть callback-и видимості
`Field` ядра: `hideFromDetail()`, `showOnDetail()`, `hideWhenUpdating()`, `showWhenUpdating()` приймають
`fn (Request $request, ?Model $record): bool`. У списку (`hideFromIndex()`, `showOnIndex()`) callback завжди
отримує `null` замість запису, тож правило для окремого запису там неможливе: сховайте поле зі списку.

Приклад: договір, який бачать і завантажують лише ті, хто може **оновлювати** запис, а решта може переглядати
запис:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

Media::make('Договір', 'contract', 'contracts')
    ->types(['pdf'])
    ->hideFromIndex()
    ->hideFromDetail(fn (Request $request, ?Model $record) => ! $request->user()?->can('update', $record));
```

- Користувачі без `update` не бачать поля на сторінці запису, тож посилання їм не видається, а підписаний маршрут
  відповідає їм 404 навіть на посилання, скопійоване в когось іншого: маршрут приймає поле, лише якщо воно видиме
  на сторінці запису чи у списку або у формі редагування для користувача з `update`.
- Форма редагування й так показує поле лише тим, хто має `update`.
- Тримайте такі файли на приватному диску (колекція `contracts` вище має `useDisk('local')`): на публічному диску
  єдиний захист — сама адреса, і `->private()` цього не змінює.

## Помилки валідації

Будь-яка проблема — 422, і нічого не записується: ні рядків медіа, ні файлів. Ключі помилок:

| Ключ | Помилки |
| --- | --- |
| `{attribute}.upload.N` | усе про один завантажений файл (N — його індекс у запиті): розмір, пікселі, тип, заблоковане розширення, `acceptsMimeTypes()`, `acceptsFile()`, `stripMetadata()` |
| `{attribute}` | поле загалом: `required` і більше медіа, ніж `limit()` / `onlyKeepLatest(n)`, після збереження |
| `{attribute}.upload` | більше файлів в одному запиті, ніж ліміт поля |
| `ids` | зміна порядку на сторінці запису із застарілим списком |

Форма показує кожну помилку біля файлу чи поля, якого вона стосується.

| Випадок | Помилка |
| --- | --- |
| файл більший за `min(maxSize(), larsadmin.files.max_size, media-library.max_file_size)` | стандартне повідомлення `max` |
| тип поза `types()` або не дозволене растрове зображення з `images()` | стандартне повідомлення `mimes` / `mimetypes` / `extensions` |
| зображення більше за `larsadmin.files.max_pixels` пікселів (ширина × висота із заголовка; за замовчуванням 40 Мп) | «Зображення поля … може мати не більше N мегапікселів.» |
| тип поза `acceptsMimeTypes()` колекції | стандартне повідомлення `mimetypes` |
| заблоковане розширення (`larsadmin.files.blocked_extensions`, розмітка й скрипти на публічних дисках) | «Поле … не може містити файл типу …» |
| `acceptsFile()` колекції відхиляє файл | «Файл поля … не прийнято.» (виникає під час збереження; уже записані файли прибираються) |
| після збереження медіа більше, ніж `limit()` / `onlyKeepLatest(n)` | «Поле … може містити не більше N файлів.» |
| порожнє поле з `required` | «Поле … є обов'язковим.» |
| `stripMetadata()`: тип зображення, який не можна очистити (AVIF, HEIC …) | «Зображення в полі … має бути JPEG, PNG, WebP або GIF: метадані інших типів зображень не можна видалити.» (див. [Метадані зображень](#метадані-зображень-stripmetadata)) |
| `stripMetadata()`: пошкоджене чи обрізане зображення | «Не вдалося видалити метадані зображення в полі …; можливо, файл пошкоджено.» |

На сторінці запису:

| Випадок | Відповідь |
| --- | --- |
| видалення останнього медіа поля з `required` | 422 «Поле … є обов'язковим.» (перевіряється із заблокованими рядками, тож два паралельні видалення не спорожнять поле) |
| зміна порядку зі списком, що не збігається з поточними медіа (хтось їх тим часом змінив) | 422 «Медіа змінилися тим часом; перезавантажте сторінку.» |
| видалення з `removable(false)` | 403 |
| медіа іншого запису чи колекції, невідоме перетворення | 404 |

Запит, більший за `post_max_size` PHP, до валідації не доходить: Laravel відповідає 413, а форма показує
повідомлення ядра про завеликі файли. Для великих галерей збільште `upload_max_filesize` / `post_max_size` (і ліміт
вебсервера).

**Застарілі форми.** Форма надсилає id медіа, які вона завантажила. Збереження може видалити лише їх, тож медіа,
додані тим часом іншим користувачем, стара форма ніколи не видалить.

## Метадані зображень: `stripMetadata()`

Фото з телефонів і камер містять метадані: EXIF (GPS-координати, пристрій, час), XMP та IPTC. Медіатека зберігає
оригінал байт у байт, тож на публічному диску будь-хто з адресою може дізнатися, де й коли зроблено фото. **Без
`stripMetadata()` оригінал зберігає все це.**

```php
// Типові для images() типи (jpg, jpeg, png, gif, webp) — саме ті, які stripMetadata() вміє очистити.
Media::make('Фото', 'photo')
    ->images()
    ->types(['jpg', 'jpeg', 'png', 'gif', 'webp'])
    ->conversion('thumb')
    ->stripMetadata();

MediaGallery::make('Фото', 'photos')
    ->images()
    ->types(['jpg', 'jpeg', 'png', 'gif', 'webp'])
    ->conversion('thumb')
    ->limit(20)
    ->stripMetadata();
```

`images()` і `stripMetadata()` перевіряють різне: `images()` вирішує, які растрові типи можна завантажити (за
замовчуванням jpg, jpeg, png, gif і webp; AVIF і BMP — лише коли їх називає `types()`); `stripMetadata()` очищає лише
JPEG, PNG/APNG, WebP і GIF і відхиляє будь-яке інше зображення з 422. Тож не додавайте `avif` чи `bmp` у `types()`
поля зі `stripMetadata()`: такі файли пройдуть перевірку типу, а потім будуть відхилені. Явний перелік типів, як
вище, тримає поле правильним незалежно від типових значень.

`stripMetadata(bool $strip = true)` вимкнено за замовчуванням. Коли увімкнено, кожен файл, завантажений через це
поле, вміст якого — зображення (`image/*`, визначається за вмістом), очищається перед збереженням. Файл **не
перекодовується**: доповнення переписує контейнер зображення й копіює піксельні дані байт у байт, тож якість не
втрачається, колірний профіль (ICC) і анімація лишаються, а PHP-розширення не потрібні.

| Формат | Лишається | Видаляється |
| --- | --- | --- |
| JPEG (baseline і progressive) | дані зображення, JFIF/JFXX, ICC-профіль, сегмент Adobe | EXIF, XMP (зокрема extended), MPF, IPTC/Photoshop, інші сегменти застосунків, коментарі, усе після кінця зображення (MPF-зображення, motion photo) |
| PNG / APNG | дані зображення й палітра, прозорість, гамма, кольоровість, sRGB, ICC-профіль, `sBIT`, `bKGD`, `pHYs`, `hIST`, чанки анімації | `eXIf`, `tEXt`, `zTXt`, `iTXt`, `tIME` і будь-який невідомий чанк; усе після `IEND` |
| WebP | дані зображення, альфа, анімація, ICC-профіль | EXIF, XMP і невідомі чанки (прапорці VP8X і розмір RIFF виправляються) |
| GIF | дані зображення, таблиці кольорів, керування кадрами, зациклення (`NETSCAPE2.0`) | коментарі й інші розширення застосунків (XMP, ICC …); усе після трейлера |
| будь-яке інше зображення (AVIF, HEIC/HEIF, TIFF, BMP, SVG, ICO …) | — | **файл відхиляється з 422** |
| не зображення (PDF, Office …) | файл без змін | — (очищаються лише зображення) |

Переліки того, що лишається, — allow-list-и: метадані невідомого доповненню виду видаляються, а не лишаються.

**Орієнтація зберігається.** Фото з телефона часто записане «боком» із тегом EXIF Orientation. Якби тег просто
зник, фото лягло б на бік, а повертати пікселі означало б перекодовувати. Тому, коли оригінал мав Orientation 2…8,
очищений файл отримує мінімальний EXIF-блок **лише з цим тегом** (без жодних інших даних). Браузери й перетворення
`spatie/image` його застосовують, тож і оригінал, і прев'ю показуються правильно.

**Fail closed.** Непідтримуваний тип зображення, пошкоджений чи обрізаний файл, невідомий сегмент JPEG перед даними
зображення, невдала самоперевірка результату (доповнення перечитує його, порівнює розміри й піксельні дані) або
помилка запису — файл відхиляється з 422 біля нього. **Неочищене зображення ніколи не зберігається.** Повідомлення
не розкриває деталей парсера. Очищення ідемпотентне: повторно очищений файл байт у байт той самий.

Зауваги:

- Зі `stripMetadata()` завантаження AVIF і HEIC відхиляються. Попросіть користувачів завантажувати JPEG, PNG чи
  WebP (телефони вміють їх експортувати).
- Очищаються лише завантаження цього поля через панель. Медіа, які додає ваш код (`$post->addMedia()`, імпорти), і
  медіа, збережені до ввімкнення опції, не змінюються.
- Ім'я на диску й оригінальна назва (`original_name`) такі самі, як без опції.
- Очищена копія пишеться в приватну робочу теку `storage/app/larsadmin-medialibrary-strip`, по одній теці на
  завантаження (права 0700, непередбачувана назва). Вона видаляється після додавання медіа й наприкінці запиту; теки,
  що лишилися після збою, видаляються, коли їм більше години (при наступному завантаженні). Налаштовувати теку не
  треба; не включайте її в резервні копії й не кладіть на публічний диск.
- Метадані PDF і Office-файлів не чіпаються. Тримайте такі файли на приватному диску.

## Життєвий цикл файлів

Диски не транзакційні, тож доповнення прив'язує файли до збереження панелі через журнал файлів ядра
(`FileJournal`):

| Подія | Рядки медіа | Файли |
| --- | --- | --- |
| збереження форми | пишуться й видаляються в транзакції збереження | нові файли (оригінал, перетворення без черги, відповідальні зображення) видаляються, якщо збереження не вдалося, зокрема коли збій стався всередині `addMedia()` уже після запису; файли видалених медіа видаляються **після коміту** |
| перетворення в черзі | — | робляться після коміту; до того прев'ю — оригінал |
| видалення на сторінці запису | видаляються в транзакції з аудитом | видаляються після коміту |
| видалення запису | видаляє медіатека; м'яке видалення зберігає їх до остаточного | медіатека видаляє одразу (див. нижче) |

**Що видаляється разом з медіа:** оригінал, усі згенеровані перетворення й відповідальні зображення (responsive
images) оригіналу та перетворень (шляхи — через path generator медіа). Теки ніколи не видаляються, бо власний path
generator може класти кілька медіа в одну теку, тож порожні теки можуть лишатися.

**Відкочене збереження** (помилка валідації у відкладеному збереженні, `acceptsFile()` відхилив файл, виняток у
хуку вашої моделі) не лишає нових файлів і рядків, а медіа, які воно мало видалити, лишаються.

**Збій усередині `addMedia()`.** Медіатека спершу пише рядок і оригінал, потім робить перетворення без черги й
відповідальні зображення та викликає свої події. Якщо один із цих кроків кидає виняток (зображення, яке не читає
GD чи Imagick, ваш слухач, переповнений диск), поле знаходить напівдодане медіа за збереженим ім'ям і прибирає його
файли: у збереженні панелі — через журнал під час відкату транзакції, поза панеллю — одразу разом із рядком. Файл без
рядка може лишити лише процес, що загинув посеред запиту (fatal error, вбитий worker).

**Видалення запису (обмеження).** У ядрі 1.6 немає хука поля для видалення запису, тож його виконує медіатека: її
observer видаляє рядки медіа та їхні файли одразу під час видалення моделі, до коміту транзакції панелі. Якщо щось
пізніше в цій транзакції не вдасться (далі йде лише запис аудиту), запис і рядки медіа відновляться, а файлів уже
не буде. М'яко видалені записи зберігають медіа. Stable-хук поля для видалення запису заплановано в ядрі; тоді
доповнення в мінорному релізі переведе видалення запису на журнал.

**Поза панеллю.** `fill()` без журналу (ваш код викликає поле) теж видаляє файли видалених медіа після коміту;
записані ним файли не відстежуються.

## Журнал аудиту

Коли журнал аудиту ядра ввімкнено, медіаполе записується як список його медіа, `[{id, name}]` у порядку, без адрес
і шляхів. Записуються збереження форми, зміни порядку й видалення на сторінці запису. `->sensitive()` маскує
значення, як завжди.

## Конфігурація

Власного конфігу в доповнення немає. Воно читає:

| Ключ | Для чого |
| --- | --- |
| `larsadmin.files.max_size` | верхня межа `maxSize()`, КБ |
| `larsadmin.files.max_pixels` | найбільше зображення в пікселях (ширина × висота), за замовчуванням `40000000` (40 Мп). Опублікованого конфігу ядра цей ключ не має: щоб змінити, додайте його в секцію `files` вашого `config/larsadmin.php` |
| `larsadmin.files.max_files` | `limit()` галереї за замовчуванням |
| `larsadmin.files.url_ttl` | час життя підписаних і тимчасових посилань, хвилини |
| `larsadmin.files.throttle` | завантажень за хвилину через приватний маршрут (обмежувач `larsadmin-files`) |
| `larsadmin.files.blocked_extensions` | розширення, які ніколи не приймаються (розмітка й скрипти на публічних дисках заблоковані завжди) |
| `media-library.disk_name` | диск завантаження, коли ні поле, ні колекція його не задають |
| `media-library.max_file_size` | ще одна верхня межа `maxSize()`, байти |
| `media-library.queue_conversions_by_default`, `queue_conversions_after_database_commit` | див. [Перетворення й прев'ю](#перетворення-й-превю) |

## Безпека

- Дайте моделі Policy з `view` і `update` та обмежте рядки в `indexQuery()` / `detailQuery()`. Посилання, зміна
  порядку й видалення підкоряються їм.
- **Медіа на публічному диску може прочитати будь-хто з адресою**, без входу. Договори, документи, рахунки й
  медичні файли тримайте на приватному диску (`useDisk('local')` у колекції або `->disk('local')`). Сам
  `->private()` змінює лише посилання, а на публічному диску поле його відхиляє.
- Фото людей чи місць на публічному диску: `->stripMetadata()` (або приватний диск).
- Завжди задавайте `types()` чи `images()`; `blocked_extensions` — лише страховка. Оголошуйте `acceptsMimeTypes()` у
  колекції, де тип важливий.
- Імена на диску випадкові (40 символів) з розширенням, визначеним за вмістом, а не з браузера; назва клієнта
  зберігається очищеною в custom property `original_name`.
- Публічний диск віддає вебсервер, і панель не може додати йому заголовки. Додайте `nosniff`,
  `Content-Security-Policy: sandbox` і `Content-Disposition: attachment` для не-зображень до `/storage` у конфігурації
  вебсервера, як радить ядро для власних файлових полів.
- Ніколи не будуйте адреси, шляхи чи імена файлів медіа з даних запиту у власному коді.

Як повідомити про вразливість — у `SECURITY.md` пакета.

## AI-асистенти

Доповнення постачає скіл для AI-асистентів, що пишуть код, `larsadmin-medialibrary`
(`resources/boost/skills/larsadmin-medialibrary/SKILL.md`), і короткий guideline (`resources/boost/guidelines/core.md`),
англійською. Скіл містить рецепти (обкладинка, галерея, приватні PDF, фото з телефонів зі `stripMetadata()`,
перетворення в черзі, тести хоста), шпаргалку API й правила безпеки.

**З Laravel Boost (2.x):**

```bash
composer require laravel/boost --dev
php artisan boost:install
```

Виберіть `maxcxam/larsadmin-medialibrary` (і `maxcxam/larsadmin`) серед сторонніх пакетів. Boost бачить лише
**прямі** залежності: обидва пакети мають бути в секції `require` вашого `composer.json`, інакше скіли ядра не
встановляться. Після оновлення доповнення виконайте `php artisan boost:update`.

**Без Boost:** скопіюйте теку скілу в теку скілів асистента, для Claude Code:

```bash
mkdir -p .claude/skills
cp -r vendor/maxcxam/larsadmin-medialibrary/resources/boost/skills/larsadmin-medialibrary .claude/skills/
```

Після оновлення доповнення скопіюйте знову. `php artisan larsadmin:ai --skills` ядра копіює лише скіли ядра.
MCP-інструменти ядра `search-docs` / `read-docs` цей посібник не індексують; скіл посилається на нього як
`vendor/maxcxam/larsadmin-medialibrary/docs/guide/en/medialibrary.md`.

## Тестування в застосунку

Тестуйте поля через HTTP, як і решту панелі (посібник 21 ядра), або викликайте одне поле напряму помічниками
ядра. В обох випадках медіаполем керують **даними форми**: значення поля — масив під його атрибутом, що надсилається
як `multipart/form-data`, точно як його надсилає форма панелі.

| Ключ | Значення | Зміст |
| --- | --- | --- |
| `{attr}[present]` | `1` | поле надіслано. Без атрибута в запиті поле не чіпається: збережені медіа лишаються |
| `{attr}[known][]` | id медіа | медіа, які завантажила форма. Цей запит може видалити лише їх |
| `{attr}[keep][]` | id медіа в порядку | медіа, які лишаються, у новому порядку (галерея зберігається в ньому, нові файли — в кінці) |
| `{attr}[upload][]` | файли | нові файли. У `Media` завантаження замінює поточне медіа |

Тобто: видалити медіа — вказати його в `known` і не вказати в `keep`; змінити порядок — `keep` у новому порядку;
запит лише з `present` і `upload` додає файли й нічого не видаляє. Оновлення надсилаються як `POST` з
`_method=PUT`, як це робить форма. Ці дані призначені лише для тестів і вбудованої форми: не формуйте їх у коді
застосунку.

### Pest

```php
use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('creates a post with a cover', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/admin/entities/posts', [
            'title' => 'Hello',
            'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->image('cover.jpg', 800, 600)]],
        ])
        ->assertSessionHasNoErrors();

    $media = Post::sole()->getFirstMedia('cover');

    expect($media)->not->toBeNull()
        ->and($media->getCustomProperty('original_name'))->toBe('cover.jpg');
    Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
});

it('reorders and removes gallery media', function () {
    Storage::fake('public');
    $post = Post::factory()->create(['title' => 'Hello']);
    $a = $post->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection('gallery');
    $b = $post->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection('gallery');
    $c = $post->addMedia(UploadedFile::fake()->image('c.jpg'))->toMediaCollection('gallery');

    $this->actingAs($this->admin)
        ->post("/admin/entities/posts/{$post->id}", [
            '_method' => 'PUT',
            'title' => 'Hello',
            // b видаляється (у known, але не в keep); c тепер перед a.
            'gallery' => ['present' => 1, 'known' => [$a->id, $b->id, $c->id], 'keep' => [$c->id, $a->id]],
        ])
        ->assertSessionHasNoErrors();

    expect($post->fresh()->getMedia('gallery')->pluck('id')->all())->toBe([$c->id, $a->id]);
});

it('rejects a file of the wrong type', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/admin/entities/posts', [
            'title' => 'Hello',
            'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->create('cover.exe', 1)]],
        ])
        ->assertSessionHasErrors('cover.upload.0');

    expect(Post::count())->toBe(0);
});
```

Помічники ядра `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` працюють і для медіаполів. У Pest додайте
трейт у `tests/Pest.php` (`pest()->extend(Tests\TestCase::class)->use(InteractsWithLarsadmin::class)->in('Feature')`):

```php
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\LarsadminMedialibrary\Fields\Media;

it('registers the add-on', function () {
    $this->assertExtensionRegistered('maxcxam/larsadmin-medialibrary');
});

it('matches the model collections and conversions', function () {
    // Галерея над singleFile() чи незареєстроване перетворення кидає LogicException тут.
    $field = Media::make('Cover', 'cover')->images()->conversion('thumb');

    expect(fn () => $this->serializeField($field, Post::factory()->create(), Field::DETAIL, \App\Larsadmin\Post::class, ['view' => true]))
        ->not->toThrow(LogicException::class);
});

it('stores phone photos without GPS', function () {
    Storage::fake('public');
    $post = Post::factory()->create();
    $photo = UploadedFile::fake()->createWithContent('phone.jpg', file_get_contents(base_path('tests/Fixtures/phone-photo-gps.jpg')));

    // fillField($field, $record, $input, $files): дані без файлів, потім файли під тими самими ключами.
    $this->fillField(Media::make('Cover', 'cover')->images()->stripMetadata(), $post, ['cover' => ['present' => 1]], ['cover' => ['upload' => [$photo]]]);

    $exif = @exif_read_data($post->getFirstMedia('cover')->getPath()) ?: [];
    expect($exif)->not->toHaveKey('GPSLatitude');
});
```

### PHPUnit

Ті самі тести як клас PHPUnit. Помічники дає трейт у класі тесту:

```php
namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Tests\TestCase;

class PostMediaTest extends TestCase
{
    use InteractsWithLarsadmin;
    use RefreshDatabase;

    public function test_the_add_on_is_registered(): void
    {
        $this->assertExtensionRegistered('maxcxam/larsadmin-medialibrary');
    }

    public function test_a_post_is_created_with_a_cover(): void
    {
        Storage::fake('public');

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/entities/posts', [
                'title' => 'Hello',
                'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->image('cover.jpg', 800, 600)]],
            ])
            ->assertSessionHasNoErrors();

        $media = Post::sole()->getFirstMedia('cover');

        $this->assertNotNull($media);
        $this->assertSame('cover.jpg', $media->getCustomProperty('original_name'));
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_a_file_of_the_wrong_type_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/entities/posts', [
                'title' => 'Hello',
                'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->create('cover.exe', 1)]],
            ])
            ->assertSessionHasErrors('cover.upload.0');

        $this->assertSame(0, Post::count());
    }

    public function test_phone_photos_are_stored_without_gps(): void
    {
        Storage::fake('public');
        $post = Post::factory()->create();
        $photo = UploadedFile::fake()->createWithContent('phone.jpg', file_get_contents(base_path('tests/Fixtures/phone-photo-gps.jpg')));

        $this->fillField(Media::make('Cover', 'cover')->images()->stripMetadata(), $post, ['cover' => ['present' => 1]], ['cover' => ['upload' => [$photo]]]);

        $exif = @exif_read_data($post->getFirstMedia('cover')->getPath()) ?: [];
        $this->assertArrayNotHasKey('GPSLatitude', $exif);
    }
}
```

Приклади припускають gate панелі, що пускає користувачів з `is_admin` (про gate у тестах — посібник 21 ядра).

Зауваги:

- `fillField()` валідує правилами поля й зберігає через журнал файлів, як панель; помилка валідації кидає
  `ValidationException` з ключами з розділу [Помилки валідації](#помилки-валідації).
- `UploadedFile::fake()->image()` потребує GD і метаданих не має. Для тесту `stripMetadata()` беріть справжнє фото
  з GPS (фікстуру у вашому репозиторії).
- Перетворення в черзі виконуються в запиті з `QUEUE_CONNECTION=sync` (типове значення Laravel для тестів).

## Обмеження

- **Немає редагування alt-тексту й підписів** (custom properties) в 1.0; заплановано в мінорному релізі доповнення.
- **Файли надсилаються разом з формою**: без попереднього чи порційного завантаження, тож загальний розмір
  обмежує `post_max_size`.
- У прев'ю панелі немає `srcset` / відповідальних зображень (файли відповідальних зображень коректно видаляються).
- Медіаполя не підтримуються в модальних вікнах дій (`LogicException`, як і будь-яке поле, що надсилає файли) і не
  сортуються.
- `stripMetadata()` очищає лише JPEG, PNG/APNG, WebP і GIF; AVIF, HEIC/HEIF та інші типи зображень відхиляються, а
  метадані PDF / Office не чіпаються.
- Видалення запису: медіатека видаляє файли медіа до коміту транзакції панелі (див.
  [Життєвий цикл файлів](#життєвий-цикл-файлів)); після видалень можуть лишатися порожні теки.
- Медіа-моделі з UUID-ключами маршрути сторінки запису не підтримують.
- Немає браузера медіа, завантаження ZIP і переміщення медіа між колекціями.
- Перетворення, зареєстровані умовно (`registerMediaConversions()`, що дивиться на медіа, наприклад на його MIME-тип),
  не звіряються з `conversion()`: доповнення не може знати, які медіа їх отримають. Помилку в назві там не буде
  виявлено; прев'ю тоді лишається оригіналом з написом «Генерується прев'ю…».
- MCP-сервер ядра ще не знає доповнення: `describe-entity` не показує опцій медіаполів (колекція, перетворення,
  диск, типи, `stripMetadata()`), а `search-docs` / `read-docs` не індексують цей посібник. Обидва пункти
  заплановано в ядрі 1.7.
- `spatie/laravel-medialibrary` 12 поки не підтримується (діапазон розширять після виходу й перевірки).
