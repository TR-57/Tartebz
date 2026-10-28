<?php

namespace Tests\Feature;

use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Mobile numbers: country code chosen + the rest typed; checked per country, saved as "+code number". */
class PhoneTest extends TestCase
{
    public function test_numbers_are_checked_per_country(): void
    {
        $ok = [
            ['+973 3600 1234', '', '+973 36001234'], ['36001234', 'BH', '+973 36001234'], ['+966 050 123 4567', '', '+966 501234567'],
            ['0501234567', 'SA', '+966 501234567'], ['+971 50 123 4567', '', '+971 501234567'], ['00974 3312 3456', '', '+974 33123456'],
            ['+965 5012 3456', '', '+965 50123456'], ['+968 9123 4567', '', '+968 91234567'],
        ];
        foreach ($ok as [$in, $cc, $out]) {
            $this->assertSame([$out, null], Phone::check($in, $cc), $in);
        }
        foreach ([['+973 1712 3456', ''], ['+966 5012345', ''], ['+971 40 123 4567', ''], ['3600 123', 'BH'], ['', 'BH']] as [$in, $cc]) {
            $this->assertNull(Phone::check($in, $cc)[0], $in);
        }
    }

    public function test_checkout_rejects_a_wrong_number_and_email_is_optional_on_whatsapp(): void
    {
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $id = \App\Support\Catalog::save(['name' => 'Mango', 'name_ar' => 'مانجو', 'price' => 3, 'images' => ['/uploads/p/a.jpg'], 'category_id' => $cat,
            'status' => 'active', 'manage_stock' => true, 'stock_qty' => 5, 'weight_grams' => 500]);
        $body = ['items' => [['id' => $id, 'qty' => 1]], 'country' => 'BH', 'method' => 'whatsapp', 'lang' => 'ar', 'first_name' => 'Sara', 'last_name' => 'Ali',
            'email' => '', 'city' => 'Riffa', 'state' => '', 'address1' => 'House 12, Road 34', 'address2' => ''];
        $this->postJson('/api/checkout', $body + ['phone' => '+973 1712 3456'])->assertStatus(422)->assertJsonPath('error.fields.phone', fn ($m) => str_contains($m, '+973 3600 1234'));
        $r = $this->postJson('/api/checkout', $body + ['phone' => '+973 3600 1234'])->assertOk();
        $this->assertSame('+973 36001234', DB::table('orders')->where('token', $r->json('order.token'))->value('phone'));
    }
}
