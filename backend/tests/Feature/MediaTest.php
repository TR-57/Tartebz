<?php

namespace Tests\Feature;

use App\Support\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Media library: originals untouched (except GPS), resized copies, replace keeps links, delete guards. */
class MediaTest extends TestCase
{
    /** A real JPEG with an EXIF block holding a GPS position. */
    private function jpegWithGps(int $w = 1000, int $h = 600): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 220, 30, 40));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = ob_get_clean();

        // TIFF (little endian): IFD0 with one entry (GPS pointer) -> GPS IFD with LatitudeRef "N" and Latitude (3 rationals).
        $tiff = 'II'.pack('v', 42).pack('V', 8);
        $ifd0 = pack('v', 1).pack('vvVV', 0x8825, 4, 1, 26).pack('V', 0);       // 8 + 2 + 12 + 4 = 26
        $gps = pack('v', 2)
            .pack('vvV', 1, 2, 2)."N\0\0\0"
            .pack('vvVV', 2, 5, 3, 26 + 2 + 24 + 4)
            .pack('V', 0);
        $rationals = pack('VVVVVV', 26, 1, 13, 1, 5, 1);                        // 26° 13' 5"
        $exif = "Exif\0\0".$tiff.$ifd0.$gps.$rationals;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    public function test_upload_keeps_the_original_but_removes_gps_and_makes_resized_copies(): void
    {
        $this->actingAsAdmin($this->makeAdmin('staff'));
        $bytes = $this->jpegWithGps();
        $this->assertTrue(\App\Support\ExifScrub::hasGps($bytes));
        $file = UploadedFile::fake()->createWithContent('photo.jpg', $bytes);
        $m = $this->post('/api/admin/media', ['files' => [$file]])->assertOk()->json('media.0');

        $stored = file_get_contents(Media::abs($m['path']));
        $this->assertSame(strlen($bytes), strlen($stored), 'same size: nothing re-encoded');
        $this->assertFalse(\App\Support\ExifScrub::hasGps($stored));
        $this->assertSame(substr($bytes, -2000), substr($stored, -2000), 'pixels untouched');
        $this->assertSame([1000, 600], [$m['width'], $m['height']]);

        // Resized copy: WebP, never wider than the original.
        $r = $this->get('/img/400/uploads/'.$m['path'])->assertOk();
        $this->assertStringContainsString('image/webp', $r->headers->get('Content-Type'));
        [$w] = getimagesize(config('store.thumb_dir').'/400/uploads/'.$m['path'].'.webp');
        $this->assertSame(400, $w);
        $this->get('/img/2400/uploads/'.$m['path'])->assertOk();
        [$w] = getimagesize(config('store.thumb_dir').'/2400/uploads/'.$m['path'].'.webp');
        $this->assertSame(1000, $w, 'no upscaling');
        $this->get('/img/400/uploads/nope.jpg')->assertRedirect('/assets/img/favicon.svg'); // no original: a placeholder, never a broken image
    }

    public function test_svg_and_fake_images_are_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post('/api/admin/uploads?kind=product', ['file' => $svg])->assertStatus(422);
        $fake = UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;');
        $this->post('/api/admin/uploads?kind=product', ['file' => $fake])->assertStatus(422)->assertJsonPath('error.fields.file', 'That is not a JPG, PNG or WebP photo.');
    }

    public function test_replace_keeps_the_link_and_delete_is_guarded_while_used(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $up = $this->post('/api/admin/uploads?kind=product', ['file' => UploadedFile::fake()->createWithContent('a.jpg', $this->jpegWithGps(800, 800))])->assertOk();
        $url = $up->json('url');
        $id = $up->json('media.id');
        $cat = DB::table('categories')->insertGetId(['slug' => 'c', 'name' => 'C', 'name_ar' => 'ج']);
        $pid = $this->postJson('/api/admin/products', ['name' => 'Pack', 'price' => 1, 'images' => [$url], 'category_id' => $cat])->assertStatus(201)->json('product.id');

        $this->getJson("/api/admin/media/$id")->assertOk()->assertJsonPath('usage.0.where', 'product');
        $this->getJson('/api/admin/media?filter=unused')->assertJsonPath('counts.unused', 0);

        // Replacing with another type would break links.
        $png = UploadedFile::fake()->image('b.png', 50, 50);
        $this->post("/api/admin/media/$id/replace", ['file' => $png])->assertStatus(422);
        $r = $this->post("/api/admin/media/$id/replace", ['file' => UploadedFile::fake()->createWithContent('b.jpg', $this->jpegWithGps(500, 500))])->assertOk();
        $this->assertSame(1, $r->json('linksUpdated'));
        $images = json_decode(DB::table('products')->find($pid)->images, true);
        $this->assertMatchesRegularExpression('#^'.preg_quote($url, '#').'\?v=\d+$#', $images[0]);
        $this->assertSame(500, getimagesize(Media::abs($up->json('media.path')))[0]);

        $this->deleteJson("/api/admin/media/$id")->assertStatus(409)->assertJsonPath('error.code', 'in_use');
        $this->postJson('/api/admin/media/bulk-delete', ['ids' => [$id]])->assertOk()->assertJsonPath('kept', 1);
        $this->deleteJson("/api/admin/media/$id", ['force' => true])->assertOk();
        $this->assertFileDoesNotExist(Media::abs($up->json('media.path')));
    }

    public function test_old_shop_import_keeps_photos_byte_for_byte_and_product_numbers(): void
    {
        $this->artisan('store:import-old-site')->assertSuccessful();
        $this->assertSame(12, DB::table('products')->count());
        $this->assertSame(10, DB::table('products')->where('status', 'active')->count());
        $this->assertSame('tartebz-fruits', DB::table('products')->find(9448)->slug);
        foreach (DB::table('media')->get() as $m) {
            $this->assertSame(md5_file(config('store.root').'/media-seed/'.$m->path), md5_file(Media::abs($m->path)), $m->path);
        }
        // Running it again changes nothing.
        $this->artisan('store:import-old-site')->assertSuccessful();
        $this->assertSame(12, DB::table('products')->count());
        $this->assertSame(18, DB::table('media')->count());
    }
}
