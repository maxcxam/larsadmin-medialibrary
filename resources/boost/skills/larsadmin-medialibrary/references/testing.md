# Testing media fields in the host app

Detect the runner first and write in the app's style:

- Pest: `vendor/bin/pest` and `tests/Pest.php` exist, tests use `it()` / `test()`. `tests/Pest.php` adds
  `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` and an `admin()` helper (skill `larsadmin-testing`).
- PHPUnit: classes extending `Tests\TestCase` with `public function test_...(): void`; use the trait in the class.
  Do not install Pest into a PHPUnit app.

## The form payload

The field is sent as an array under its attribute, as multipart (exactly what the panel's form sends):

| Key | Meaning |
| --- | --- |
| `{attribute}[present]` | `1`: the field was submitted; always send it |
| `{attribute}[upload][]` | new files (`UploadedFile`); for `Media` the first one replaces the current media |
| `{attribute}[known][]` | ids of the media the form loaded; only these can be removed |
| `{attribute}[keep][]` | ids to keep, in display order; a known id missing here is removed |

Errors: per file on `{attribute}.upload.N`; `required` and too many media on `{attribute}`. Create is
`POST /admin/entities/{entity}`; update is a multipart `POST /admin/entities/{entity}/{id}` with `_method=PUT`.

## Pest

```php
use App\Larsadmin\Post as PostEntity;
use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\LarsadminMedialibrary\Fields\Media;

it('rejects a file of the wrong type', function () {
    Storage::fake('public');

    $this->actingAs(admin())
        ->post('/admin/entities/posts', [
            'title' => 'Hello',
            'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->create('cover.exe', 1)]],
        ])
        ->assertSessionHasErrors('cover.upload.0');

    expect(Post::count())->toBe(0);
});

it('registers the add-on', function () {
    $this->assertExtensionRegistered('maxcxam/larsadmin-medialibrary');
});

it('matches the model collections and conversions', function () {
    // A gallery over singleFile() or an unregistered conversion throws LogicException here.
    $field = Media::make('Cover', 'cover')->images()->conversion('thumb');

    expect(fn () => $this->serializeField($field, Post::factory()->create(), Field::DETAIL, PostEntity::class, ['view' => true]))
        ->not->toThrow(LogicException::class);
});

it('stores phone photos without GPS', function () {
    Storage::fake('public');
    $post = Post::factory()->create();
    $photo = UploadedFile::fake()->createWithContent('phone.jpg', file_get_contents(base_path('tests/Fixtures/phone-photo-gps.jpg')));
    $field = Media::make('Cover', 'cover')->images()->stripMetadata(); // 'cover' is a singleFile() collection

    $this->fillField($field, $post, ['cover' => ['present' => 1]], ['cover' => ['upload' => [$photo]]]);

    $exif = @exif_read_data($post->getFirstMedia('cover')->getPath()) ?: [];
    expect($exif)->not->toHaveKey('GPSLatitude');
});
```

## PHPUnit

```php
namespace Tests\Feature\Larsadmin;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use Tests\TestCase;

class PostMediaTest extends TestCase
{
    use InteractsWithLarsadmin;
    use RefreshDatabase;

    // A user your viewLarsadmin gate (or Larsadmin::auth() closure) allows.
    private function admin(): User
    {
        return User::factory()->create(['email' => 'admin@example.com']);
    }

    public function test_it_creates_a_post_with_a_cover(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post('/admin/entities/posts', [
                'title' => 'Hello',
                'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->image('cover.jpg', 800, 600)]],
            ])
            ->assertSessionHasNoErrors();

        $media = Post::sole()->getFirstMedia('cover');

        $this->assertNotNull($media);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_the_gallery_limit_is_an_error_on_the_attribute(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->fillField(MediaGallery::make('Gallery', 'gallery')->images()->limit(1), Post::factory()->create(),
            ['gallery' => ['present' => 1]],
            ['gallery' => ['upload' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]]]);
    }
}
```

Notes:

- `UploadedFile::fake()->image()` needs GD and has no metadata; for a `stripMetadata()` test commit a real phone
  photo with GPS as a fixture.
- `fillField()` validates with the field's rules and saves through a file journal, as the panel does;
  `serializeField()` with `abilities` answers `Field::authorizedTo()` (skill `larsadmin-testing`).
- Queued conversions run in the request with `QUEUE_CONNECTION=sync` (the Laravel default for tests).
