<?php

namespace Tests\Feature;

use App\Support\I18nCatalog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TranslationsTest extends TestCase
{
    public function test_arabic_site_reports_missing_text_and_it_appears_in_the_missing_list(): void
    {
        $this->call('POST', '/api/i18n/missing', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'http://localhost'],
            json_encode(['page' => '/shop', 'strings' => ['A brand new button', 'ok', 'http://x.com link', 'مرحبا بالعالم']]))->assertNoContent();
        $this->assertSame(1, DB::table('translations')->where('reported', 1)->count());

        $m = $this->makeAdmin('manager');
        $this->actingAsAdmin($m);
        $rows = $this->getJson('/api/admin/translations?scope=site&filter=missing')->assertOk()->json('rows');
        $this->assertSame('A brand new button', $rows[0]['source']);
        $this->assertTrue($rows[0]['reported']);

        // Typing the Arabic fixes it and it reaches the storefront overrides.
        $this->putJson('/api/admin/translations', ['scope' => 'site', 'source' => 'A brand new button', 'ar' => 'زر جديد <script>x()</script><b>تماماً</b>'])->assertOk();
        $ov = $this->getJson('/api/i18n/overrides')->assertOk()->json('phrases');
        $this->assertSame('زر جديد <b>تماماً</b>', $ov['A brand new button']['ar']);
        $this->assertSame(0, $this->getJson('/api/admin/translations?scope=site&filter=missing')->json('counts.missing'));
    }

    public function test_editing_a_built_in_key_and_back_to_original(): void
    {
        $key = array_key_first(I18nCatalog::load()['site']['keys']);
        $this->assertNotNull($key, 'catalogue must be built (node tools/i18n-build.mjs)');
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->putJson('/api/admin/translations', ['scope' => 'site', 'source' => $key, 'ar' => 'نص معدل'])->assertOk()->assertJsonPath('row.changed', true);
        $this->assertSame('نص معدل', $this->getJson('/api/i18n/overrides')->json("keys.$key.ar"));
        $this->postJson('/api/admin/translations/reset', ['scope' => 'site', 'source' => $key])->assertOk();
        $this->assertSame(0, DB::table('translations')->count());
    }

    public function test_staff_cannot_save_translations_and_default_language_is_saved(): void
    {
        $this->actingAsAdmin($this->makeAdmin('staff'));
        $this->putJson('/api/admin/translations', ['scope' => 'site', 'source' => 'x', 'ar' => 'y'])->assertStatus(403);
        $this->signOut()->actingAsAdmin($this->makeAdmin('manager'));
        $this->putJson('/api/admin/translations/settings', ['defaultLang' => 'xx'])->assertStatus(422);
        $this->putJson('/api/admin/translations/settings', ['defaultLang' => 'en'])->assertOk();
        $this->assertSame('en', $this->getJson('/api/i18n/overrides')->json('defaultLang'));
    }
}
