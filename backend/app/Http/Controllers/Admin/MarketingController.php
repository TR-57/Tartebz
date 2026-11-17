<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Campaigns;
use App\Support\Carts;
use App\Support\Coupons;
use App\Support\EmailPreview;
use App\Support\EmailSender;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Managers: Discounts & coupons, Abandoned carts, Campaigns, Reviews. */
class MarketingController extends Controller
{
    // ---------- coupons ----------

    public function coupons(Request $r)
    {
        $q = DB::table('coupons');
        if (($s = strtoupper($this->str($r, 'q', 40))) !== '') {
            $q->where('code', 'like', '%'.$s.'%');
        }
        if (in_array($r->query('source'), ['manual', 'cart', 'campaign'], true)) {
            $q->where('source', $r->query('source'));
        }

        return $this->ok(['coupons' => $q->orderByDesc('id')->limit(200)->get()->map(fn ($c) => $this->couponOut($c))]);
    }

    public function saveCoupon(Request $r, ?int $id = null)
    {
        $f = [];
        $code = Coupons::normalise($this->str($r, 'code', 40));
        if (! preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            $f['code'] = 'Use 3–40 letters or numbers (no spaces).';
        } elseif (DB::table('coupons')->where('code', $code)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            $f['code'] = 'This code is already used.';
        }
        $type = in_array($r->input('type'), ['percent', 'fixed', 'free_shipping'], true) ? (string) $r->input('type') : 'percent';
        $value = (float) $r->input('value', 0);
        if ($type === 'percent' && ($value <= 0 || $value > 100)) {
            $f['value'] = 'Enter a percentage from 1 to 100.';
        }
        if ($type === 'fixed' && $value <= 0) {
            $f['value'] = 'Enter the amount off (BHD).';
        }
        $num = fn ($k) => $r->input($k) === null || $r->input($k) === '' ? null : max(0, (float) $r->input($k));
        $int = fn ($k) => $r->input($k) === null || $r->input($k) === '' ? null : max(1, (int) $r->input($k));
        $date = function ($k) use ($r, &$f) {
            $v = trim((string) $r->input($k, ''));
            if ($v === '') {
                return null;
            }
            $t = strtotime($v);
            if (! $t) {
                $f[$k] = 'Enter a date.';

                return null;
            }

            return date('Y-m-d H:i:s', $t);
        };
        $starts = $date('startsAt');
        $ends = $date('endsAt');
        if ($starts && $ends && $ends < $starts) {
            $f['endsAt'] = 'The end must be after the start.';
        }
        $emails = [];
        foreach (preg_split('/[\s,;]+/', (string) $r->input('emails', '')) as $e) {
            $e = strtolower(trim($e));
            if ($e !== '') {
                if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $f['emails'] = 'Not an email: '.$e;
                }
                $emails[] = $e;
            }
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        $vals = [
            'code' => $code, 'title' => $this->str($r, 'title', 120), 'title_ar' => $this->str($r, 'titleAr', 120), 'type' => $type,
            'value' => $type === 'free_shipping' ? 0 : $value, 'min_subtotal' => $num('minSubtotal'), 'max_discount' => $num('maxDiscount'),
            'starts_at' => $starts, 'ends_at' => $ends, 'enabled' => $this->bool($r, 'enabled', true),
            'usage_limit' => $int('usageLimit'), 'per_customer_limit' => $int('perCustomerLimit'),
            'emails' => $emails ? json_encode(array_values(array_unique($emails))) : null,
            'product_ids' => ($p = array_values(array_filter(array_map('intval', (array) $r->input('productIds', []))))) ? json_encode($p) : null,
            'category_ids' => ($c = array_values(array_filter(array_map('intval', (array) $r->input('categoryIds', []))))) ? json_encode($c) : null,
            'combinable' => $this->bool($r, 'combinable'), 'show_in_account' => $this->bool($r, 'showInAccount'),
            'updated_at' => now(),
        ];
        if ($id) {
            DB::table('coupons')->where('id', $id)->update($vals);
        } else {
            $id = DB::table('coupons')->insertGetId($vals + ['source' => in_array($r->input('source'), ['manual', 'campaign'], true) ? $r->input('source') : 'manual',
                'created_by' => $this->admin($r)->id, 'created_at' => now()]);
        }
        Audit::log($r, 'coupon_saved', 'coupon', $id, ['code' => $code]);

        return $this->ok(['coupon' => $this->couponOut(DB::table('coupons')->find($id))]);
    }

    public function deleteCoupon(Request $r, int $id)
    {
        $c = DB::table('coupons')->find($id) ?? throw ApiError::notFound('That code does not exist.');
        if (DB::table('coupon_uses')->where('coupon_id', $id)->exists() || DB::table('orders')->where('coupon_id', $id)->exists()) {
            DB::table('coupons')->where('id', $id)->update(['enabled' => false, 'updated_at' => now()]);   // used: keep it for the orders, switch it off

            return $this->ok(['disabled' => true]);
        }
        DB::table('coupons')->where('id', $id)->delete();
        Audit::log($r, 'coupon_deleted', 'coupon', $id, ['code' => $c->code]);

        return $this->ok(['deleted' => true]);
    }

    private function couponOut(object $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'title' => $c->title, 'titleAr' => $c->title_ar, 'type' => $c->type, 'value' => (float) $c->value,
            'minSubtotal' => $c->min_subtotal !== null ? (float) $c->min_subtotal : null, 'maxDiscount' => $c->max_discount !== null ? (float) $c->max_discount : null,
            'startsAt' => $c->starts_at, 'endsAt' => $c->ends_at, 'enabled' => (bool) $c->enabled, 'usageLimit' => $c->usage_limit, 'perCustomerLimit' => $c->per_customer_limit,
            'emails' => implode(', ', (array) json_decode((string) $c->emails, true)), 'productIds' => (array) json_decode((string) $c->product_ids, true),
            'categoryIds' => (array) json_decode((string) $c->category_ids, true), 'combinable' => (bool) $c->combinable, 'showInAccount' => (bool) $c->show_in_account,
            'source' => $c->source, 'used' => (int) $c->used_count, 'offer' => Coupons::offer($c, 'en'), 'createdAt' => $c->created_at];
    }

    // ---------- abandoned carts ----------

    public function carts(Request $r)
    {
        $q = DB::table('carts')->leftJoin('coupons', 'coupons.id', '=', 'carts.coupon_id')->leftJoin('orders as rec', 'rec.id', '=', 'carts.recovered_order_id');
        if (($s = mb_strtolower($this->str($r, 'q', 190))) !== '') {
            $q->where('carts.email', 'like', '%'.$s.'%');
        }
        $f = (string) $r->query('filter', '');
        if ($f === 'active') {
            $q->whereNull('carts.stopped_at')->whereNull('carts.recovered_order_id');
        } elseif ($f === 'recovered') {
            $q->whereNotNull('rec.paid_at')->where('carts.step', '>', 0);
        }
        $rows = $q->orderByDesc('carts.last_activity_at')->limit(200)->get([
            'carts.*', 'coupons.code as coupon_code', 'coupons.used_count as coupon_used', 'rec.number as recovered_number', 'rec.paid_at as recovered_paid',
        ]);
        $names = DB::table('products')->whereIn('id', $rows->flatMap(fn ($c) => array_column((array) json_decode((string) $c->items, true), 'id'))->unique()->all() ?: [0])
            ->pluck('name', 'id');

        return $this->ok([
            'carts' => $rows->map(fn ($c) => [
                'id' => $c->id, 'email' => $c->email, 'name' => $c->name, 'lang' => $c->lang, 'country' => $c->country, 'value' => (float) $c->value,
                'items' => array_map(fn ($i) => ['name' => $names[$i['id']] ?? '#'.$i['id'], 'qty' => $i['qty']], (array) json_decode((string) $c->items, true)),
                'lastSeen' => $c->last_activity_at, 'step' => (int) $c->step, 'sent' => [$c->step1_at, $c->step2_at, $c->step3_at],
                'code' => $c->coupon_code, 'codeUsed' => (int) ($c->coupon_used ?? 0) > 0, 'unpaidOrder' => $c->order_id ? (string) DB::table('orders')->where('id', $c->order_id)->value('number') : null,
                'stopped' => $c->stopped_at, 'stopReason' => $c->stop_reason, 'recovered' => $c->recovered_paid ? $c->recovered_number : null,
                'recoveredOrderId' => $c->recovered_paid ? $c->recovered_order_id : null,
            ]),
            'stats' => Carts::stats(), 'settings' => Settings::get('carts'),
        ]);
    }

    public function saveCartSettings(Request $r)
    {
        $c = Settings::get('carts');
        $was = (bool) $c['enabled'];
        $c['enabled'] = $this->bool($r, 'enabled');
        if ($c['enabled'] && ! $was) {
            $c['since'] = now()->toDateTimeString();   // only carts left from now on
        }
        $steps = [];
        foreach ([0, 1, 2] as $i) {
            $s = (array) ($r->input('steps')[$i] ?? []);
            $steps[] = ['on' => filter_var($s['on'] ?? false, FILTER_VALIDATE_BOOLEAN), 'wait' => max(0, min(999, (int) ($s['wait'] ?? 1))),
                'unit' => ($s['unit'] ?? 'hours') === 'days' ? 'days' : 'hours'];
        }
        $c['steps'] = $steps;
        $cc = (array) $r->input('coupon', []);
        $type = in_array($cc['type'] ?? '', ['percent', 'fixed', 'free_shipping'], true) ? $cc['type'] : 'percent';
        $c['coupon'] = ['on' => filter_var($cc['on'] ?? false, FILTER_VALIDATE_BOOLEAN), 'type' => $type, 'value' => max(0, (float) ($cc['value'] ?? 10)),
            'min' => isset($cc['min']) && $cc['min'] !== '' && $cc['min'] !== null ? max(0, (float) $cc['min']) : null,
            'days' => max(1, min(90, (int) ($cc['days'] ?? 7))), 'cooldownDays' => max(0, min(365, (int) ($cc['cooldownDays'] ?? 30)))];
        if ($type === 'percent' && ($c['coupon']['value'] <= 0 || $c['coupon']['value'] > 100)) {
            throw ApiError::validation(['coupon.value' => 'Enter a percentage from 1 to 100.']);
        }
        $c['unpaidOrders'] = $this->bool($r, 'unpaidOrders', true);
        Settings::set('carts', $c);
        Audit::log($r, 'cart_settings', 'settings', 'carts', ['enabled' => $c['enabled']]);

        return $this->ok(['settings' => $c, 'stats' => Carts::stats()]);
    }

    /** Sends the next email of one cart now (whatever the wait). */
    public function sendCart(Request $r, int $id)
    {
        $c = DB::table('carts')->find($id) ?? throw ApiError::notFound('That cart does not exist.');
        if ($c->stopped_at || $c->recovered_order_id) {
            throw new ApiError('stopped', 'This cart’s emails have stopped ('.($c->stop_reason ?: 'ordered').').', 409);
        }
        $sent = Carts::advance($c, true);
        Outbox::flushPending();
        Audit::log($r, 'cart_sent', 'cart', $id);
        $c = DB::table('carts')->find($id);

        return $this->ok(['sent' => $sent, 'step' => (int) $c->step, 'stopped' => $c->stop_reason]);
    }

    // ---------- campaigns ----------

    public function campaigns()
    {
        return $this->ok(['campaigns' => DB::table('campaigns')->orderByDesc('id')->limit(100)->get()->map(fn ($c) => $this->campaignOut($c))]);
    }

    public function saveCampaign(Request $r, ?int $id = null)
    {
        if ($id && DB::table('campaigns')->where('id', $id)->value('status') !== 'draft') {
            throw new ApiError('started', 'A campaign that has been sent cannot be changed.', 409);
        }
        $f = [];
        $name = $this->str($r, 'name', 120);
        if ($name === '') {
            $f['name'] = 'Give the campaign a name (only you see it).';
        }
        if ($this->str($r, 'subject', 255) === '' && $this->str($r, 'subjectAr', 255) === '') {
            $f['subject'] = 'Write a subject.';
        }
        foreach (['body', 'bodyAr'] as $k) {
            if (preg_match('/<\s*(script|style|iframe|a |img )/i', (string) $r->input($k, ''))) {
                $f[$k] = 'Write plain text – no HTML. Use **bold**, [button: Label | https://…] and [image: https://…].';
            }
        }
        $type = in_array($r->input('audience.type'), ['all', 'countries', 'list'], true) ? $r->input('audience.type') : 'all';
        $aud = ['type' => $type, 'countries' => array_values(array_filter(array_map(fn ($c) => strtoupper(substr((string) $c, 0, 2)), (array) $r->input('audience.countries', [])))),
            'emails' => array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $r->input('audience.emails', '')))))];
        if ($f) {
            throw ApiError::validation($f);
        }
        $vals = ['name' => $name, 'subject' => $this->str($r, 'subject', 255), 'subject_ar' => $this->str($r, 'subjectAr', 255),
            'body' => mb_substr((string) $r->input('body', ''), 0, 20000), 'body_ar' => mb_substr((string) $r->input('bodyAr', ''), 0, 20000),
            'audience' => json_encode($aud), 'updated_at' => now()];
        if ($id) {
            DB::table('campaigns')->where('id', $id)->update($vals);
        } else {
            $id = DB::table('campaigns')->insertGetId($vals + ['created_by' => $this->admin($r)->id, 'created_at' => now()]);
        }

        return $this->ok(['campaign' => $this->campaignOut(DB::table('campaigns')->find($id))]);
    }

    public function deleteCampaign(int $id)
    {
        DB::table('campaigns')->where('id', $id)->where('status', 'draft')->delete();

        return $this->ok();
    }

    public function previewCampaign(Request $r, int $id)
    {
        $c = DB::table('campaigns')->find($id) ?? throw ApiError::notFound('That campaign does not exist.');
        $lang = $r->input('lang') === 'en' ? 'en' : 'ar';
        [$subject, $html] = EmailSender::build('campaign', $lang, ['template' => Campaigns::template($c), 'vars' => ['name' => $lang === 'ar' ? 'سارة' : 'Sara'],
            'url' => config('store.store_url')], 'customer@example.com');

        return $this->ok(['subject' => $subject, 'html' => $html]);
    }

    public function testCampaign(Request $r, int $id)
    {
        $c = DB::table('campaigns')->find($id) ?? throw ApiError::notFound('That campaign does not exist.');
        $me = $this->admin($r);
        $out = Campaigns::sendOne($c, ['email' => $me->email, 'name' => $me->name, 'lang' => $r->input('lang') === 'en' ? 'en' : 'ar'], false, '[Test] ');
        Outbox::flushPending();
        $row = DB::table('mail_outbox')->find($out);

        return $this->ok(['sent' => ($row->status ?? '') === 'sent', 'error' => $row->last_error ?? 'Not sent (that address unsubscribed).', 'to' => $me->email]);
    }

    public function sendCampaign(Request $r, int $id)
    {
        $n = Campaigns::start($id);
        Audit::log($r, 'campaign_sent', 'campaign', $id, ['recipients' => $n]);

        return $this->ok(['campaign' => $this->campaignOut(DB::table('campaigns')->find($id))]);
    }

    public function audienceCount(Request $r)
    {
        $type = in_array($r->input('type'), ['all', 'countries', 'list'], true) ? $r->input('type') : 'all';

        return $this->ok(['count' => count(Campaigns::audience(['type' => $type, 'countries' => (array) $r->input('countries', []),
            'emails' => preg_split('/[\s,;]+/', (string) $r->input('emails', ''))]))]);
    }

    private function campaignOut(object $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'subject' => $c->subject, 'subjectAr' => $c->subject_ar, 'body' => (string) $c->body, 'bodyAr' => (string) $c->body_ar,
            'audience' => json_decode((string) $c->audience, true) ?: ['type' => 'all'], 'status' => $c->status, 'startedAt' => $c->started_at,
            'finishedAt' => $c->finished_at, 'progress' => $c->status === 'draft' ? null : Campaigns::progress($c->id)];
    }

    // ---------- reviews ----------

    public function reviews(Request $r)
    {
        $status = in_array($r->query('status'), ['pending', 'approved', 'rejected'], true) ? $r->query('status') : 'pending';
        $rows = DB::table('reviews')->join('products', 'products.id', '=', 'reviews.product_id')->leftJoin('orders', 'orders.id', '=', 'reviews.order_id')
            ->where('reviews.status', $status)->orderByDesc('reviews.updated_at')->limit(200)
            ->get(['reviews.*', 'products.name as product', 'products.slug', 'orders.number as order_number']);

        return $this->ok(['reviews' => $rows, 'counts' => DB::table('reviews')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')]);
    }

    /** {status: approved|rejected} */
    public function moderate(Request $r, int $id)
    {
        $status = $r->input('status') === 'approved' ? 'approved' : 'rejected';
        DB::table('reviews')->where('id', $id)->update(['status' => $status, 'approved_by' => $this->admin($r)->id, 'approved_at' => now(), 'updated_at' => now()]);
        Audit::log($r, 'review_'.$status, 'review', $id);

        return $this->ok();
    }

    public function deleteReview(Request $r, int $id)
    {
        DB::table('reviews')->where('id', $id)->delete();
        Audit::log($r, 'review_deleted', 'review', $id);

        return $this->ok();
    }

    // ---------- carts preview (any of the 10 cart emails, any language) ----------

    public function cartPreview(Request $r)
    {
        $key = $this->str($r, 'key', 40);
        if (! preg_match('/^(cart|unpaid)_[123](_nocode)?$/', $key) || in_array($key, ['cart_1_nocode', 'unpaid_1_nocode'], true)) {
            throw ApiError::validation(['key' => 'Choose an email.']);
        }
        $lang = $r->input('lang') === 'en' ? 'en' : 'ar';
        [$subject, $html] = EmailSender::build($key, $lang, EmailPreview::context($key, $lang), 'customer@example.com');

        return $this->ok(['subject' => $subject, 'html' => $html]);
    }
}
