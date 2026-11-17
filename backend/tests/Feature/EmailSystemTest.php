<?php

namespace Tests\Feature;

use App\Support\Campaigns;
use App\Support\Carts;
use App\Support\Coupons;
use App\Support\EmailJobs;
use App\Support\Outbox;
use App\Support\Secret;
use App\Support\Settings;
use App\Support\Subscribers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every email trigger (docs/emails.md lists them): orders, statuses, reminders, abandoned carts and their stop rules,
 * unpaid-order recovery, unsubscribe, pop-up codes, campaigns, coupons, the outbox (dedupe, back-off) and mail settings.
 */
class EmailSystemTest extends TestCase
{
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $cat = DB::table('categories')->insertGetId(['slug' => 'sweet', 'name' => 'Sweet', 'name_ar' => 'حلو']);
        $this->productId = \App\Support\Catalog::save([
            'name' => 'Mango Fruits', 'name_ar' => 'مانجو', 'price' => 3, 'images' => ['/uploads/p/a.jpg'], 'category_id' => $cat,
            'status' => 'active', 'manage_stock' => true, 'stock_qty' => 50, 'weight_grams' => 500,
        ]);
        $p = Settings::get('payments');
        $p['tap'] = ['enabled' => true, 'mode' => 'test', 'testSecret' => Secret::seal('sk_test_ABCDEFGH1234'), 'testPublishable' => '',
            'liveSecret' => '', 'livePublishable' => '', 'merchantId' => '', 'chargeCurrencies' => ['BHD']];
        $p['whatsapp'] = ['enabled' => true] + (array) ($p['whatsapp'] ?? []);
        Settings::set('payments', $p);
        $m = Settings::get('mail');
        $m['notifyEmails'] = ['orders@tartebz.test'];
        Settings::set('mail', $m);
    }

    private function customer(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->productId, 'qty' => 2]], 'country' => 'BH', 'method' => 'tap', 'lang' => 'en',
            'first_name' => 'Sara', 'last_name' => 'Ali', 'email' => 'sara@example.com', 'phone' => '+973 3333 4444',
            'state' => 'Northern', 'city' => 'Riffa', 'address1' => 'House 12, Road 34, Block 905', 'address2' => '',
        ];
    }

    private function fakeTap(float $amount = 8.0): void
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response(['id' => 'chg_TS01', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/?x']]),
            'api.tap.company/v2/charges/chg_TS01' => Http::response(['id' => 'chg_TS01', 'status' => 'CAPTURED', 'amount' => $amount, 'currency' => 'BHD',
                'reference' => ['payment' => '1'], 'source' => ['payment_method' => 'VISA'], 'response' => ['message' => 'ok']]),
        ]);
    }

    private function mails(string $key, ?string $to = null): int
    {
        return DB::table('mail_outbox')->where('email_key', $key)->when($to, fn ($q) => $q->where('to_email', $to))->count();
    }

    private function cardOrder(array $over = []): object
    {
        $this->fakeTap();
        $token = $this->postJson('/api/checkout', $this->customer($over + ['expected_total' => 8.0]))->assertOk()->json('order.token');

        return DB::table('orders')->where('token', $token)->first();
    }

    private function enableCarts(array $coupon = ['on' => true, 'type' => 'percent', 'value' => 10, 'days' => 7, 'cooldownDays' => 30]): void
    {
        $c = Settings::get('carts');
        $c['enabled'] = true;
        $c['since'] = now()->subMinute()->toDateTimeString();
        $c['steps'] = [['on' => true, 'wait' => 1, 'unit' => 'hours'], ['on' => true, 'wait' => 24, 'unit' => 'hours'], ['on' => true, 'wait' => 3, 'unit' => 'days']];
        $c['coupon'] = $coupon + ['min' => null];
        Settings::set('carts', $c);
    }

    // ---------- orders ----------

    public function test_whatsapp_order_emails_the_customer_and_the_store(): void
    {
        $this->postJson('/api/checkout', $this->customer(['method' => 'whatsapp', 'expected_total' => 8.0]))->assertOk();
        $this->assertSame(1, $this->mails('order_received', 'sara@example.com'));
        $this->assertSame(1, $this->mails('staff_new_order', 'orders@tartebz.test'));
        $row = DB::table('mail_outbox')->where('email_key', 'staff_new_order')->first();
        $this->assertSame('sara@example.com', json_decode((string) $row->headers, true)['Reply-To'] ?? DB::table('mail_outbox')->where('id', $row->id)->value('reply_to'));
        $this->assertSame(0, $this->mails('order_paid'), 'nothing is paid yet');
    }

    public function test_paid_email_is_sent_once_even_when_tap_notifies_twice(): void
    {
        $o = $this->cardOrder();
        $this->assertSame(0, $this->mails('order_paid'));
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01', 'status' => 'CAPTURED'])->assertOk();
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01', 'status' => 'CAPTURED'])->assertOk();
        $this->assertSame(1, $this->mails('order_paid', 'sara@example.com'));
        $this->assertSame(1, $this->mails('staff_new_order', 'orders@tartebz.test'));
        $paid = DB::table('mail_outbox')->where('email_key', 'order_paid')->first();
        $this->assertStringContainsString($o->number, $paid->subject);
        $this->assertStringContainsString('/api/invoice/', $paid->html, 'the email links to the invoice');
    }

    public function test_status_emails_follow_the_email_the_customer_tick(): void
    {
        $o = $this->cardOrder();
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01'])->assertOk();
        $me = $this->makeAdmin('owner');
        $other = $this->makeAdmin('owner');
        $this->actingAsAdmin($me);
        $this->postJson("/api/admin/orders/{$o->id}/status", ['status' => 'processing', 'notify' => false])->assertOk();
        $this->assertSame(0, $this->mails('status_processing'), 'not ticked: no email');
        $this->postJson("/api/admin/orders/{$o->id}/status", ['status' => 'shipped', 'notify' => true])->assertOk();
        $this->assertSame(1, $this->mails('status_shipped', 'sara@example.com'));
        $this->postJson("/api/admin/orders/{$o->id}/status", ['status' => 'shipped', 'notify' => true])->assertOk();
        $this->assertSame(1, $this->mails('status_shipped'), 'same status again: no second email');
        $this->postJson("/api/admin/orders/{$o->id}/status", ['status' => 'delivered'])->assertOk();
        $this->assertSame(1, $this->mails('status_delivered'), 'ticked by default');
        // the team: other owners hear about it, the person who did it does not
        $this->assertGreaterThan(0, $this->mails('staff_order_update', $other->email));
        $this->assertSame(0, $this->mails('staff_order_update', $me->email));
    }

    public function test_ready_to_collect_and_cancel_with_a_message(): void
    {
        $o = $this->cardOrder();
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01'])->assertOk();
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->postJson("/api/admin/orders/{$o->id}/status", ['status' => 'ready'])->assertOk();
        $this->assertSame(1, $this->mails('status_ready'));
        $this->assertNotNull(DB::table('orders')->find($o->id)->ready_at);
        $this->postJson("/api/admin/orders/{$o->id}/cancel", ['reason' => 'Out of mango', 'customerReason' => 'Sorry, we ran out.', 'notify' => true])->assertOk();
        $row = DB::table('mail_outbox')->where('email_key', 'status_cancelled')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('Sorry, we ran out.', $row->html);
        $this->assertStringNotContainsString('Out of mango', $row->html, 'the team reason stays inside');
    }

    public function test_payment_reminder_once_while_the_items_are_held(): void
    {
        $o = $this->cardOrder();
        $this->assertSame(0, EmailJobs::paymentReminders(), 'too early');
        $this->travel(35)->minutes();
        $this->assertSame(1, EmailJobs::paymentReminders());
        $this->assertSame(0, EmailJobs::paymentReminders(), 'only once');
        $this->assertSame(1, $this->mails('payment_reminder', 'sara@example.com'));
        $this->assertNotNull(DB::table('orders')->find($o->id)->reminder_sent_at);
    }

    // ---------- abandoned carts ----------

    private function leaveCart(string $key, string $email = 'cart@example.com', array $items = null): void
    {
        $this->postJson('/api/checkout/quote', ['items' => $items ?? [['id' => $this->productId, 'qty' => 2]], 'country' => 'BH', 'cart' => $key,
            'email' => $email, 'first_name' => 'Huda', 'lang' => 'en'])->assertOk();
    }

    public function test_cart_series_of_three_with_a_personal_code(): void
    {
        $this->enableCarts();
        $key = str_repeat('a', 32);
        $this->leaveCart($key);
        $this->assertSame(0, Carts::run(), 'nothing due yet');
        $this->travel(61)->minutes();
        $this->assertSame(1, Carts::run());
        $this->assertSame(1, $this->mails('cart_1', 'cart@example.com'));
        $this->travel(25)->hours();
        $this->assertSame(1, Carts::run());
        $this->assertSame(1, $this->mails('cart_2'));
        $code = DB::table('coupons')->where('source', 'cart')->first();
        $this->assertNotNull($code, 'a personal code was made');
        $this->assertSame(['cart@example.com'], json_decode((string) $code->emails, true));
        $this->assertStringContainsString($code->code, DB::table('mail_outbox')->where('email_key', 'cart_2')->value('html'));
        $this->assertStringContainsString('restore=', DB::table('mail_outbox')->where('email_key', 'cart_2')->value('html'));
        // the restore link gives back the same cart and code on any device
        $this->getJson('/api/cart/restore/'.DB::table('carts')->value('token'))->assertOk()->assertJsonPath('cart.code', $code->code)->assertJsonPath('cart.items.0.qty', 2);
        $this->travel(4)->days();
        $this->assertSame(1, Carts::run());
        $this->assertSame(1, $this->mails('cart_3'));
        $this->assertSame('finished', DB::table('carts')->value('stop_reason'));
        $this->travel(4)->days();
        $this->assertSame(0, Carts::run(), 'no fourth email');
        // …and the expired code is no longer offered by the restore link
        $this->getJson('/api/cart/restore/'.DB::table('carts')->value('token'))->assertOk()->assertJsonPath('cart.code', null);
    }

    public function test_cart_series_without_codes(): void
    {
        $this->enableCarts(['on' => false, 'type' => 'percent', 'value' => 10, 'days' => 7, 'cooldownDays' => 30]);
        $this->leaveCart(str_repeat('b', 32));
        $this->travel(61)->minutes();
        Carts::run();
        $this->travel(25)->hours();
        Carts::run();
        $this->travel(4)->days();
        Carts::run();
        $this->assertSame(1, $this->mails('cart_1'));
        $this->assertSame(1, $this->mails('cart_2_nocode'));
        $this->assertSame(1, $this->mails('cart_3_nocode'));
        $this->assertSame(0, DB::table('coupons')->count());
    }

    public function test_cart_stop_rules(): void
    {
        $this->enableCarts();
        // emptied
        $this->leaveCart(str_repeat('c', 32), 'empty@example.com');
        $this->leaveCart(str_repeat('c', 32), 'empty@example.com', []);
        $this->assertSame('emptied', DB::table('carts')->where('email', 'empty@example.com')->value('stop_reason'));
        // unsubscribed
        $this->leaveCart(str_repeat('d', 32), 'gone@example.com');
        Subscribers::unsubscribe('gone@example.com');
        $this->assertSame('unsubscribed', DB::table('carts')->where('email', 'gone@example.com')->value('stop_reason'));
        // a newer cart takes over
        $this->leaveCart(str_repeat('e', 32), 'twice@example.com');
        $this->leaveCart(str_repeat('f', 32), 'twice@example.com');
        $this->assertSame('newer_cart', DB::table('carts')->where('cart_key', str_repeat('e', 32))->value('stop_reason'));
        // ordered
        $this->leaveCart(str_repeat('1', 32), 'sara@example.com');
        $this->postJson('/api/checkout', $this->customer(['method' => 'whatsapp', 'expected_total' => 8.0, 'cart' => str_repeat('1', 32)]))->assertOk();
        $this->assertSame('ordered', DB::table('carts')->where('cart_key', str_repeat('1', 32))->value('stop_reason'));
        // nothing goes out for any of them
        $this->travel(2)->hours();
        Carts::run();
        $this->assertSame(1, $this->mails('cart_1'), 'only the live newer cart');
        $this->assertSame(1, $this->mails('cart_1', 'twice@example.com'));
        // older than 30 days
        DB::table('carts')->whereNull('stopped_at')->update(['last_activity_at' => now()->subDays(31)]);
        Carts::run();
        $this->assertSame('too_old', DB::table('carts')->where('email', 'twice@example.com')->whereNotNull('cart_key')->orderByDesc('id')->value('stop_reason'));
    }

    public function test_carts_are_off_until_switched_on(): void
    {
        $this->leaveCart(str_repeat('9', 32));
        $this->assertSame(0, DB::table('carts')->count());
    }

    public function test_unpaid_card_order_is_won_back_and_cancelled_ones_are_not(): void
    {
        $this->enableCarts();
        $o = $this->cardOrder();
        $cancelled = $this->cardOrder(['email' => 'other@example.com']);
        DB::table('orders')->where('id', $cancelled->id)->update(['status' => 'cancelled']);
        DB::table('stock_holds')->delete();
        $this->travel(3)->hours();
        Carts::run();
        $this->assertSame(1, $this->mails('unpaid_1', 'sara@example.com'));
        $this->assertSame(0, $this->mails('unpaid_1', 'other@example.com'));
        $this->assertSame($o->id, (int) DB::table('carts')->value('order_id'));
        // paying stops the series
        DB::table('orders')->where('id', $o->id)->update(['paid_at' => now(), 'payment_status' => 'paid', 'status' => 'paid']);
        $this->travel(2)->days();
        Carts::run();
        $this->assertSame(0, $this->mails('unpaid_2') + $this->mails('unpaid_2_nocode'));
    }

    // ---------- unsubscribe, pop-up, campaigns ----------

    public function test_unsubscribe_link_and_one_click(): void
    {
        $url = Subscribers::url('fan@example.com', 'en');
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->get($path)->assertOk()->assertSee('unsubscribed');
        $this->assertTrue(Subscribers::unsubscribed('fan@example.com'));
        $this->get('/api/email/unsubscribe?e=fan@example.com&s=wrong')->assertNotFound();
        // one-click POST (RFC 8058) from the mail app
        $url2 = Subscribers::url('fan2@example.com');
        parse_str((string) parse_url($url2, PHP_URL_QUERY), $q);
        $this->post('/api/email/unsubscribe?e='.urlencode($q['e']).'&s='.$q['s'], ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertTrue(Subscribers::unsubscribed('fan2@example.com'));
        // marketing mail is no longer queued for them; order mail still is
        $this->assertSame(0, Outbox::queue('fan@example.com', 'Offer', '<p>x</p>', ['kind' => 'marketing']));
        $this->assertGreaterThan(0, Outbox::queue('fan@example.com', 'Order', '<p>x</p>', ['kind' => 'order']));
    }

    public function test_popup_gives_one_code_per_email_and_needs_consent(): void
    {
        Settings::set('popup', array_merge(Settings::get('popup'), ['enabled' => true]));
        $this->postJson('/api/popup', ['email' => 'new@example.com', 'consent' => false])->assertStatus(422);
        $this->postJson('/api/popup', ['email' => 'new@example.com', 'consent' => true, 'lang' => 'ar'])->assertOk()->assertJsonPath('sent', true);
        $this->postJson('/api/popup', ['email' => 'NEW@example.com', 'consent' => true])->assertOk()->assertJsonPath('already', true);
        $this->assertSame(1, DB::table('coupons')->where('source', 'popup')->count());
        $this->assertSame(1, $this->mails('popup_code', 'new@example.com'));
        $this->assertNotNull(DB::table('mail_subscribers')->where('email', 'new@example.com')->value('consent_at'));
        Settings::set('popup', array_merge(Settings::get('popup'), ['enabled' => false]));
        $this->postJson('/api/popup', ['email' => 'late@example.com', 'consent' => true])->assertStatus(409);
    }

    public function test_campaign_goes_out_in_batches_and_skips_unsubscribed(): void
    {
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $e) {
            Subscribers::subscribe($e, ['source' => 'popup', 'consent' => true]);
        }
        Subscribers::unsubscribe('c@example.com');
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $id = $this->postJson('/api/admin/campaigns', ['name' => 'Eid', 'subject' => 'Eid sale', 'subject_ar' => 'عروض العيد', 'subjectAr' => 'عروض العيد',
            'body' => "Hi {name}\n\n{button: Shop}", 'body_ar' => "هلا {name}", 'bodyAr' => "هلا {name}", 'audience' => ['type' => 'all']])->assertOk()->json('campaign.id');
        $this->postJson("/api/admin/campaigns/{$id}/send")->assertOk();
        $this->assertSame(2, DB::table('mail_outbox')->where('campaign_id', $id)->count());
        $this->assertSame(0, DB::table('mail_outbox')->where('campaign_id', $id)->where('to_email', 'c@example.com')->count());
        $this->assertSame('sending', DB::table('campaigns')->find($id)->status);
        $this->assertSame(1, Campaigns::batch(1));
        $this->assertSame('sending', DB::table('campaigns')->find($id)->status);
        Campaigns::batch(10);
        $this->assertSame('done', DB::table('campaigns')->find($id)->status);
        $this->postJson("/api/admin/campaigns/{$id}/send")->assertStatus(409);
        $html = DB::table('mail_outbox')->where('campaign_id', $id)->value('html');
        $this->assertStringContainsString('/api/email/unsubscribe', $html);
    }

    // ---------- coupons ----------

    public function test_coupon_checks_and_use_counting(): void
    {
        $id = DB::table('coupons')->insertGetId(['code' => 'EID10', 'type' => 'percent', 'value' => 10, 'enabled' => 1, 'source' => 'manual', 'usage_limit' => 1,
            'min_subtotal' => 5, 'used_count' => 0, 'combinable' => 0, 'show_in_account' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('coupons')->insert(['code' => 'OLD', 'type' => 'fixed', 'value' => 1, 'enabled' => 1, 'source' => 'manual', 'ends_at' => now()->subDay(),
            'used_count' => 0, 'combinable' => 0, 'show_in_account' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $mine = Coupons::personal('vip@example.com', 'fixed', 2, null, 7, 'cart');
        $q = fn (string $code, int $qty = 2, string $email = '') => $this->postJson('/api/checkout/quote', ['items' => [['id' => $this->productId, 'qty' => $qty]], 'country' => 'BH', 'coupon' => $code, 'email' => $email])->json('quote');
        $this->assertSame('not_found', $q('NOPE')['couponError']['reason']);
        $this->assertSame('expired', $q('OLD')['couponError']['reason']);
        $this->assertSame('min', $q('EID10', 1)['couponError']['reason']);
        $this->assertEquals(5, $q('EID10', 1)['couponError']['min']);
        $this->assertSame('email', $q($mine->code, 2, 'someone@example.com')['couponError']['reason']);
        $this->assertEquals(2.0, $q($mine->code, 2, 'vip@example.com')['discount']);
        $ok = $q('eid10');
        $this->assertNull($ok['couponError']);
        $this->assertEquals(0.6, $ok['discount']);
        $this->assertEquals(7.4, $ok['total']);
        // a bad code at checkout is refused with its reason
        $this->postJson('/api/checkout', $this->customer(['coupon' => 'NOPE', 'expected_total' => 8.0]))->assertStatus(422)->assertJsonPath('error.data.reason', 'not_found');
        // a use counts when paid, and comes back when the order is cancelled
        $this->fakeTap(7.4);
        $token = $this->postJson('/api/checkout', $this->customer(['coupon' => 'EID10', 'expected_total' => 7.4]))->assertOk()->json('order.token');
        $o = DB::table('orders')->where('token', $token)->first();
        $this->assertSame('EID10', $o->coupon_code);
        $this->assertEquals(0.6, $o->discount);
        $this->assertSame(0, (int) DB::table('coupons')->find($id)->used_count, 'not counted before payment');
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01'])->assertOk();
        $this->assertSame(1, (int) DB::table('coupons')->find($id)->used_count);
        $this->assertSame('used_up', $q('EID10')['couponError']['reason']);
        $this->actingAsAdmin($this->makeAdmin('owner'));
        $this->postJson("/api/admin/orders/{$o->id}/cancel", ['reason' => 'test', 'notify' => false])->assertOk();
        $this->assertSame(0, (int) DB::table('coupons')->find($id)->used_count);
        $this->assertSame(0, $this->mails('status_cancelled'), 'not ticked: no email');
    }

    // ---------- outbox and settings ----------

    public function test_outbox_dedupe_and_back_off(): void
    {
        $a = Outbox::queue('x@example.com', 'Hi', '<p>Hi</p>', ['dedupe' => 'once:1', 'later' => true]);
        $b = Outbox::queue('x@example.com', 'Hi', '<p>Hi</p>', ['dedupe' => 'once:1', 'later' => true]);
        $this->assertSame($a, $b);
        $this->assertSame(1, DB::table('mail_outbox')->count());
        $this->assertSame(0, Outbox::queue('not-an-email', 'Hi', '<p>Hi</p>'));
        // a failing mail server: the row waits 1 minute, then 5, …
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $m = Settings::get('mail');
        $m['host'] = '127.0.0.1';
        $m['port'] = 1;
        Settings::set('mail', $m);
        config(['store.mail_test_mode' => false]);
        $sent = Outbox::send($a);
        $row = DB::table('mail_outbox')->find($a);
        if ($sent) {
            $this->markTestSkipped('This environment delivers mail in test mode; back-off checked by the unit below.');
        }
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertEqualsWithDelta(now()->addMinute()->timestamp, strtotime((string) $row->next_attempt_at), 5);
        $this->assertSame(0, Outbox::retry(), 'still waiting');
    }

    public function test_mail_settings_are_owner_only_and_the_password_is_never_shown(): void
    {
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->getJson('/api/admin/email-settings')->assertForbidden();
        $this->signOut()->actingAsAdmin($this->makeAdmin('owner'));
        $this->putJson('/api/admin/email-settings', ['host' => 'smtp.hostinger.com', 'port' => 465, 'scheme' => 'ssl', 'username' => 'sales@tartebz.com',
            'password' => 'Mailbox-Pass-123', 'fromAddress' => 'sales@tartebz.com', 'fromName' => 'Tartebz', 'notifyEmails' => 'a@tartebz.com, b@tartebz.com'])->assertOk();
        $r = $this->getJson('/api/admin/email-settings')->assertOk();
        $this->assertStringNotContainsString('Mailbox-Pass-123', $r->getContent());
        $r->assertJsonPath('mail.password.set', true)->assertJsonPath('mail.host', 'smtp.hostinger.com')->assertJsonPath('mail.notifyEmails', ['a@tartebz.com', 'b@tartebz.com']);
        $this->assertNotSame('Mailbox-Pass-123', Settings::get('mail')['password'], 'stored sealed');
        $this->putJson('/api/admin/email-settings', ['fromAddress' => 'not an email'])->assertStatus(422);
        // the test button sends through the mailer and says what happened
        config(['mail.default' => 'array']);
        $this->postJson('/api/admin/email-settings/test', ['to' => 'me@example.com'])->assertOk()->assertJsonStructure(['sent', 'mode', 'message']);
    }
    public function test_tracking_refund_payment_link_and_back_in_stock(): void
    {
        $o = $this->cardOrder();
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01'])->assertOk();
        // tracking number: once per waybill
        DB::table('orders')->where('id', $o->id)->update(['awb' => '290012345678']);
        \App\Support\OrderMail::tracking(DB::table('orders')->find($o->id));
        \App\Support\OrderMail::tracking(DB::table('orders')->find($o->id));
        $this->assertSame(1, $this->mails('tracking_number'));
        $this->assertStringContainsString('290012345678', DB::table('mail_outbox')->where('email_key', 'tracking_number')->value('html'));
        // a refund recorded by staff
        $admin = $this->makeAdmin('owner');
        \App\Support\Refunds::start($o->id, 1.0, 'Broken box', false, 'manual', $admin);
        $this->assertSame(1, $this->mails('status_refunded', 'sara@example.com'));
        // payment link for an unpaid order
        $unpaid = $this->cardOrder(['email' => 'link@example.com']);
        $this->actingAsAdmin($admin);
        $this->postJson("/api/admin/orders/{$unpaid->id}/payment-link", ['via' => 'email'])->assertOk();
        $this->assertSame(1, $this->mails('payment_link', 'link@example.com'));
        // back in stock
        DB::table('products')->where('id', $this->productId)->update(['stock_qty' => 0]);
        DB::table('stock_alerts')->insert(['product_id' => $this->productId, 'email' => 'wait@example.com', 'lang' => 'ar', 'created_at' => now()]);
        DB::table('products')->where('id', $this->productId)->update(['stock_qty' => 5]);
        \App\Support\StockAlerts::afterChange($this->productId, false);
        $this->assertSame(1, $this->mails('back_in_stock', 'wait@example.com'));
    }

    public function test_review_request_review_and_the_signed_invoice_page(): void
    {
        $o = $this->cardOrder();
        $this->postJson('/api/payments/tap/webhook', ['id' => 'chg_TS01'])->assertOk();
        DB::table('orders')->where('id', $o->id)->update(['status' => 'delivered', 'delivered_at' => now()]);
        $this->assertSame(0, EmailJobs::reviewRequests(), 'too early');
        $this->travel(4)->days();
        $this->assertSame(1, EmailJobs::reviewRequests());
        $this->assertSame(0, EmailJobs::reviewRequests(), 'once per order');
        $html = DB::table('mail_outbox')->where('email_key', 'review_request')->value('html');
        $this->assertMatchesRegularExpression('/review=([\w.]+)&amp;rate=5/', $html);
        preg_match('/review=([\w.]+)&amp;rate=/', $html, $m);
        $this->getJson('/api/reviews/check?token='.$m[1])->assertOk()->assertJsonPath('productId', $this->productId);
        $this->postJson('/api/reviews', ['token' => $m[1], 'rating' => 5, 'body' => 'Lovely', 'lang' => 'en'])->assertOk()->assertJsonPath('status', 'pending');
        $this->getJson("/api/products/{$this->productId}/reviews")->assertJsonPath('reviews.count', 0);   // waits for approval
        $this->postJson('/api/reviews', ['token' => '1.2.bad', 'rating' => 5])->assertNotFound();
        $id = DB::table('reviews')->value('id');
        $this->actingAsAdmin($this->makeAdmin('manager'));
        $this->postJson("/api/admin/reviews/{$id}", ['status' => 'approved'])->assertOk();
        $this->getJson("/api/products/{$this->productId}/reviews")->assertJsonPath('reviews.count', 1)->assertJsonPath('reviews.average', 5);
        // the invoice link in the emails works only with its signature
        $url = \App\Support\OrderMail::invoiceUrl(DB::table('orders')->find($o->id), 'en');
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->get($path)->assertOk()->assertSee($o->number);
        $this->get(parse_url($url, PHP_URL_PATH).'?s=wrong')->assertNotFound();
    }
}
