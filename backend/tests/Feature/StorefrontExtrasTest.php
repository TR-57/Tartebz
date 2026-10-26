<?php

namespace Tests\Feature;

use App\Support\ClientIp;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Back-in-stock alerts, website settings, server-built product pages, sitemap, Cloudflare addresses, the deploy build. */
class StorefrontExtrasTest extends TestCase
{
    private function product(array $over = []): int
    {
        $cat = DB::table('categories')->insertGetId(['slug' => 'c'.uniqid(), 'name' => 'Sweet', 'name_ar' => 'حلو']);

        return \App\Support\Catalog::save($over + [
            'name' => 'Electric Strips', 'name_ar' => 'شرايط كهرباء', 'price' => 5, 'images' => ['/uploads/products/a.jpg'],
            'category_id' => $cat, 'status' => 'active', 'manage_stock' => true, 'stock_qty' => 0,
            'description' => 'Very sour.', 'description_ar' => 'حامض جدًا.',
        ]);
    }

    public function test_back_in_stock_requests_and_emails_when_stock_returns(): void
    {
        $id = $this->product();
        $this->postJson('/api/stock-alerts', ['product' => $id])->assertStatus(422)->assertJsonPath('error.fields.contact', 'Enter your email or your WhatsApp number.');
        $this->postJson('/api/stock-alerts', ['product' => $id, 'email' => 'nope'])->assertStatus(422);
        $this->postJson('/api/stock-alerts', ['product' => $id, 'email' => 'Sara@Example.com', 'lang' => 'en', 'country' => 'SA'])->assertOk();
        $this->postJson('/api/stock-alerts', ['product' => $id, 'email' => 'sara@example.com', 'lang' => 'en'])->assertOk(); // asked twice -> kept once, latest language
        $this->postJson('/api/stock-alerts', ['product' => $id, 'phone' => '3333 4444'])->assertOk();
        $this->assertSame(2, DB::table('stock_alerts')->count());
        $this->assertDatabaseHas('stock_alerts', ['phone' => '97333334444', 'lang' => 'ar']);

        // Admin sees who waits; the phone has a ready WhatsApp message.
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->getJson('/api/admin/products')->assertOk()->assertJsonPath('products.0.waiting', 2);
        $alerts = $this->getJson("/api/admin/products/$id/stock-alerts")->assertOk()->json('alerts');
        $phone = collect($alerts)->firstWhere('phone', '97333334444');
        $this->assertStringStartsWith('https://wa.me/97333334444?text=', $phone['whatsapp']);

        // Stock back -> the email goes out (once), the phone stays for staff.
        $r = $this->postJson("/api/admin/products/$id/stock", ['stock_qty' => 10])->assertOk();
        $this->assertSame(1, $r->json('backInStockEmails'));
        $mail = DB::table('mail_outbox')->where('to_email', 'sara@example.com')->first();
        $this->assertNotNull($mail);
        $this->assertStringContainsString('Electric Strips is back in stock', $mail->subject);
        $this->assertNotNull(DB::table('stock_alerts')->where('email', 'sara@example.com')->value('notified_at'));
        $this->assertNull(DB::table('stock_alerts')->where('phone', '97333334444')->value('notified_at'));
        $this->postJson("/api/admin/products/$id/stock", ['stock_qty' => 12])->assertJsonPath('backInStockEmails', 0);

        $this->postJson('/api/admin/stock-alerts/'.$phone['id'].'/done')->assertOk();
        $this->assertSame('whatsapp', DB::table('stock_alerts')->where('id', $phone['id'])->value('notified_by'));

        // In stock now: no new requests.
        $this->signOut()->postJson('/api/stock-alerts', ['product' => $id, 'email' => 'x@example.com'])->assertStatus(409);
    }

    public function test_website_settings_reach_the_storefront(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->putJson('/api/admin/website', ['whatsappNumber' => '12', 'delivery' => [['country' => 'SA', 'min' => 4, 'max' => 2]]])
            ->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['whatsappNumber'], $f['delivery.0.max']));
        $this->putJson('/api/admin/website', [
            'whatsappOrders' => false, 'backInStock' => true, 'whatsappNumber' => '+973 3753 3757',
            'paymentLogos' => ['visa', 'applepay', 'bogus'], 'delivery' => [['country' => 'bh', 'min' => 0, 'max' => 0]],
        ])->assertOk();
        $shop = $this->signOut()->getJson('/api/storefront')->assertOk()->json('shop');
        $this->assertFalse($shop['whatsappOrders']);
        $this->assertSame('97337533757', $shop['whatsapp']);
        $this->assertSame(['visa', 'applepay'], $shop['paymentLogos']);
        $this->assertSame([['country' => 'BH', 'min' => 0, 'max' => 0]], $shop['delivery']);

        // Alerts switched off: the form is refused.
        Settings::set('storefront', ['backInStock' => false] + Settings::get('storefront'));
        $this->postJson('/api/stock-alerts', ['product' => $this->product(), 'email' => 'a@b.co'])->assertStatus(403);
    }

    public function test_product_pages_carry_link_preview_and_google_tags(): void
    {
        $id = $this->product(['stock_qty' => 4]);
        DB::table('products')->where('id', $id)->update(['slug' => 'electric-strips', 'permalink' => '/product/شرايط-كهرباء/']);

        $html = $this->get('/product/electric-strips')->assertOk()->getContent();
        $this->assertStringContainsString('<title>شرايط كهرباء | ترتيبز</title>', $html);
        $this->assertStringContainsString('<meta property="og:image" content="http://localhost/img/og/uploads/products/a.jpg">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/product/electric-strips">', $html);
        $this->assertStringContainsString('"availability":"https://schema.org/InStock"', $html);
        $this->assertStringContainsString('"price":"5.000"', $html);
        $this->assertSame(1, substr_count($html, 'property="og:title"'), 'the generic tags of product.html are replaced, not doubled');

        $en = $this->get('/product/electric-strips?lang=en')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Electric Strips | Tartebz</title>', $en);
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $en);

        // Old WordPress address -> permanent redirect; unknown or hidden -> 404 that search engines skip.
        $this->get('/product/'.rawurlencode('شرايط-كهرباء').'/')->assertRedirect('/product/electric-strips')->assertStatus(301);
        $this->get('/product/nothing-here')->assertStatus(404)->assertSee('noindex', false);
        DB::table('products')->where('id', $id)->update(['status' => 'hidden']);
        $this->get('/product/electric-strips')->assertStatus(404);
    }

    public function test_sitemap_lists_live_products_and_robots_points_to_it(): void
    {
        $id = $this->product();
        DB::table('products')->where('id', $id)->update(['slug' => 'electric-strips']);
        $this->product(['name' => 'Secret', 'status' => 'hidden']);
        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')->getContent();
        $this->assertStringContainsString('<loc>http://localhost/product/electric-strips</loc>', $xml);
        $this->assertStringContainsString('hreflang="en" href="http://localhost/product/electric-strips?lang=en"', $xml);
        $this->assertStringNotContainsString('secret', $xml);
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: http://localhost/sitemap.xml')->assertSee('Disallow: /admin/');
    }

    public function test_link_preview_picture_is_a_1200x630_jpeg(): void
    {
        $img = imagecreatetruecolor(900, 900);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 200, 30));
        @mkdir(config('store.upload_dir').'/products', 0775, true);
        imagepng($img, config('store.upload_dir').'/products/p.png');
        $this->get('/img/og/uploads/products/p.png')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        [$w, $h, $type] = getimagesize(config('store.thumb_dir').'/og/uploads/products/p.png.jpg');
        $this->assertSame([1200, 630, IMAGETYPE_JPEG], [$w, $h, $type]);
    }

    public function test_cloudflare_mode_believes_only_cloudflare(): void
    {
        $req = fn (string $peer, string $cf) => Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $peer, 'HTTP_CF_CONNECTING_IP' => $cf]);
        config(['store.trust_proxy' => 'cloudflare']);
        $this->assertSame('5.6.7.8', ClientIp::of($req('172.70.1.2', '5.6.7.8')));       // from Cloudflare
        $this->assertSame('9.9.9.9', ClientIp::of($req('9.9.9.9', '5.6.7.8')));           // someone faking the header
        $this->assertSame('5.6.7.8', ClientIp::of($req('2606:4700::1', '5.6.7.8')));
        config(['store.trust_proxy' => false]);
        $this->assertSame('172.70.1.2', ClientIp::of($req('172.70.1.2', '5.6.7.8')));
    }

    public function test_deploy_build_minifies_and_versions_links(): void
    {
        $dest = $this->filesDir.'/public';
        mkdir($dest, 0775, true);
        mkdir($dest.'/assets/js', 0775, true);
        file_put_contents($dest.'/assets/js/removed.js', '// gone from the repo');
        $this->artisan('store:build-public', ['dest' => $dest, '--build' => 'abc123'])->assertSuccessful();
        $html = file_get_contents($dest.'/index.html');
        $this->assertStringContainsString('src="/assets/js/app.js?v=abc123"', $html);
        $this->assertStringContainsString('href="/assets/css/style.css?v=abc123"', $html);
        $this->assertStringNotContainsString('__SITE__', $html);
        $this->assertLessThan(filesize(config('store.root').'/assets/css/style.css'), filesize($dest.'/assets/css/style.css'));
        $this->assertStringContainsString('/assets/js/fallback.js?v=abc123', file_get_contents($dest.'/assets/js/live.js'));
        $this->assertFileExists($dest.'/assets/fonts/alexandria-arabic.woff2');
        $this->assertFileDoesNotExist($dest.'/assets/js/removed.js');
    }
}
