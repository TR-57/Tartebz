<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SMSA pickup bookings: "come and collect these waybills from the shop on <day> between <from> and <until>".
 *
 * SMSA's eCom API has no pickup endpoint (only waybills, tracking and customer returns), so the booking is sent to
 * SMSA's pickup desk or account manager by email (Settings › SMSA shipping › Pickups), or written out for WhatsApp.
 * The window is inside the shop's pickup hours (09:00–17:00 by default), on the same day or a later one; a booking can
 * carry several waybills, so one courier visit collects them all. Sandbox waybills are booked here but never sent.
 * A booking is done by itself once SMSA has scanned every parcel in it (tracking), and can be cancelled before that.
 */
class SmsaPickups
{
    public const STEP_MINUTES = 30;

    public const DAYS_AHEAD = 6;

    public static function cfg(): array
    {
        $p = (array) (Smsa::cfg()['pickup'] ?? []);

        return [
            'email' => (string) ($p['email'] ?? ''), 'whatsapp' => (string) ($p['whatsapp'] ?? ''),
            'from' => self::hhmm($p['from'] ?? '09:00') ?? '09:00', 'until' => self::hhmm($p['until'] ?? '17:00') ?? '17:00',
            'minHours' => max(0.5, min(8, (float) ($p['minHours'] ?? 1))),
        ];
    }

    /** SMSA pickup email addresses (one or more, comma separated). */
    public static function emails(): array
    {
        return array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), preg_split('/[\s,;]+/', self::cfg()['email'])),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
    }

    public static function hhmm(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v) ? $v : null;
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    private static function fmt(int $min): string
    {
        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }

    public static function now(): Carbon
    {
        return now()->setTimezone(Fmt::tz());
    }

    /**
     * The choices for the booking form: days, and per day the start / end times on offer.
     * Today starts at the next half hour from now; a day with no room left for the shortest window is left out.
     */
    public static function options(): array
    {
        $c = self::cfg();
        $open = self::minutes($c['from']);
        $close = self::minutes($c['until']);
        $min = (int) round($c['minHours'] * 60);
        $now = self::now();
        $days = [];
        for ($i = 0; $i <= self::DAYS_AHEAD; $i++) {
            $d = $now->copy()->startOfDay()->addDays($i);
            $start = $open;
            if ($i === 0) {
                $nowMin = $now->hour * 60 + $now->minute;
                $start = max($open, (int) (ceil(($nowMin + 1) / self::STEP_MINUTES) * self::STEP_MINUTES));
            }
            if ($start + $min > $close) {
                continue;
            }
            $days[] = ['date' => $d->toDateString(), 'today' => $i === 0, 'label' => ['en' => $d->locale('en')->translatedFormat('D j M'), 'ar' => $d->locale('ar')->translatedFormat('l j F')],
                'from' => array_map([self::class, 'fmt'], range($start, $close - $min, self::STEP_MINUTES)),
                'until' => array_map([self::class, 'fmt'], range($start + $min, $close, self::STEP_MINUTES))];
        }

        return ['days' => $days, 'open' => $c['from'], 'close' => $c['until'], 'minHours' => $c['minHours']];
    }

    /** Checks a requested window. Returns [date, from, until] or throws a validation error naming the field. */
    public static function validWindow(string $date, string $from, string $until): array
    {
        $c = self::cfg();
        $from = self::hhmm($from) ?? throw ApiError::validation(['from' => 'Choose the time from.']);
        $until = self::hhmm($until) ?? throw ApiError::validation(['until' => 'Choose the time until.']);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ApiError::validation(['date' => 'Choose the day.']);
        }
        $day = Carbon::createFromFormat('Y-m-d', $date, Fmt::tz())->startOfDay();
        $today = self::now()->startOfDay();
        if ($day->lt($today) || $day->gt($today->copy()->addDays(self::DAYS_AHEAD))) {
            throw ApiError::validation(['date' => 'Choose today or one of the next '.self::DAYS_AHEAD.' days.']);
        }
        $f = self::minutes($from);
        $u = self::minutes($until);
        if ($f < self::minutes($c['from']) || $u > self::minutes($c['until'])) {
            throw ApiError::validation(['from' => 'SMSA collects between '.$c['from'].' and '.$c['until'].'.']);
        }
        if ($u - $f < (int) round($c['minHours'] * 60)) {
            throw ApiError::validation(['until' => 'Give SMSA at least '.self::hoursText($c['minHours']).' to come.']);
        }
        if ($day->equalTo($today)) {
            $now = self::now();
            if ($f < $now->hour * 60 + $now->minute - 5) {
                throw ApiError::validation(['from' => 'That time has passed. Choose a later time.']);
            }
        }

        return [$day->toDateString(), $from, $until];
    }

    private static function hoursText(float $h): string
    {
        return $h == 1.0 ? '1 hour' : rtrim(rtrim(number_format($h, 1), '0'), '.').' hours';
    }

    /** Waybills that are waiting for the courier and not booked yet (same server as $mode), for "add these too". */
    public static function waiting(?string $mode = null, ?int $exceptOrder = null): array
    {
        return DB::table('shipments as sh')->join('orders as o', 'o.id', '=', 'sh.order_id')
            ->where('sh.carrier', 'smsa')->where('sh.status', 'created')->where('sh.awb', '<>', '')->whereNull('sh.pickup_id')
            ->when($mode, fn ($q) => $q->where('sh.mode', $mode))->when($exceptOrder, fn ($q) => $q->where('o.id', '<>', $exceptOrder))
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->orderBy('sh.created_at')->limit(50)
            ->get(['o.id', 'o.number', 'o.first_name', 'o.last_name', 'o.city', 'o.country', 'sh.awb', 'sh.created_at'])
            ->map(fn ($x) => ['orderId' => (int) $x->id, 'number' => $x->number, 'name' => trim($x->first_name.' '.$x->last_name),
                'place' => trim($x->city.', '.$x->country, ', '), 'awb' => $x->awb])->all();
    }

    /**
     * Books a pickup for $orderId's waybill (+ the waybills of $extraOrderIds that are also waiting).
     * $via: email (to SMSA's pickup address) | whatsapp (returns the link) | copy (returns the text).
     *
     * @return array{pickup: object, text: string, whatsappUrl: ?string, emailed: bool}
     */
    public static function book(int $orderId, string $date, string $from, string $until, array $extraOrderIds, string $note, string $via, ?object $admin): array
    {
        [$date, $from, $until] = self::validWindow($date, $from, $until);
        $via = in_array($via, ['email', 'whatsapp', 'copy'], true) ? $via : 'email';
        $note = mb_substr(trim($note), 0, 300);

        return DB::transaction(function () use ($orderId, $date, $from, $until, $extraOrderIds, $note, $via, $admin) {
            $main = DB::table('shipments')->where('order_id', $orderId)->lockForUpdate()->first();
            if (! $main || $main->awb === '' || $main->status !== 'created') {
                throw new ApiError('no_waybill', $main && $main->status !== 'created' && $main->awb !== ''
                    ? 'SMSA already has this parcel (or the waybill was cancelled) – no pickup needed.'
                    : 'Create the SMSA waybill first (Send to SMSA), then book the pickup.', 409);
            }
            if ($main->pickup_id && DB::table('smsa_pickups')->where('id', $main->pickup_id)->where('status', 'requested')->exists()) {
                throw new ApiError('booked', 'A pickup is already booked for this order. Cancel it first to book another time.', 409);
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $extraOrderIds), fn ($i) => $i > 0 && $i !== $orderId)));
            $extra = $ids ? DB::table('shipments')->whereIn('order_id', $ids)->where('status', 'created')->where('awb', '<>', '')
                ->where('mode', $main->mode)->where(fn ($q) => $q->whereNull('pickup_id')->orWhereIn('pickup_id', DB::table('smsa_pickups')->where('status', '<>', 'requested')->select('id')))
                ->lockForUpdate()->get() : collect();
            $ships = collect([$main])->merge($extra);

            $orders = DB::table('orders')->whereIn('id', $ships->pluck('order_id'))->get()->keyBy('id');
            $parcels = 0;
            $kg = 0.0;
            $lines = [];
            foreach ($ships as $sh) {
                $o = $orders[$sh->order_id];
                $n = max(1, (int) ($sh->parcels ?? 1));
                $w = round($sh->weight_kg !== null && (float) $sh->weight_kg > 0 ? (float) $sh->weight_kg : max(0.1, (int) $o->weight_grams / 1000), 2);
                $parcels += $n;
                $kg += $w;
                $lines[] = ['awb' => $sh->awb, 'number' => $o->number, 'parcels' => $n, 'kg' => $w, 'to' => trim($o->city.', '.$o->country, ', ')];
            }
            $mode = $main->mode;
            $sandbox = $mode !== 'live';
            $id = DB::table('smsa_pickups')->insertGetId([
                'mode' => $mode, 'pickup_date' => $date, 'ready_from' => $from, 'ready_until' => $until, 'status' => 'requested',
                'parcels' => $parcels, 'weight_kg' => round($kg, 2), 'note' => $note,
                'sent_via' => $sandbox ? 'sandbox' : $via, 'sent_to' => '', 'created_by' => $admin->name ?? '', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('shipments')->whereIn('id', $ships->pluck('id'))->update(['pickup_id' => $id, 'updated_at' => now()]);
            $p = DB::table('smsa_pickups')->find($id);
            $text = self::message($p, $lines, $admin);
            $emailed = false;
            $to = '';
            if (! $sandbox && $via === 'email') {
                $to = implode(', ', self::emails());
                if ($to === '') {
                    throw ApiError::validation(['via' => 'Add SMSA’s pickup email in Settings › SMSA shipping first – or send the request on WhatsApp.']);
                }
                $emailed = self::send($p, $text, self::emails(), 'Pickup request');
            } elseif (! $sandbox && $via === 'whatsapp') {
                $to = self::cfg()['whatsapp'];
            }
            DB::table('smsa_pickups')->where('id', $id)->update(['message' => $text, 'sent_to' => mb_substr($to, 0, 255)]);
            $when = self::when($p);
            foreach ($ships as $sh) {
                Orders::event((int) $sh->order_id, 'smsa_pickup', 'SMSA pickup booked for '.$when.' (request #'.$id.', '.($sandbox ? 'sandbox – not sent to SMSA' : ['email' => 'emailed to SMSA', 'whatsapp' => 'sent on WhatsApp', 'copy' => 'copied to send'][$via]).').', [], $admin);
            }
            $wa = self::cfg()['whatsapp'];
            $waUrl = ! $sandbox && $via === 'whatsapp' ? 'https://wa.me/'.preg_replace('/\D/', '', $wa).'?text='.rawurlencode($text) : null;
            if ($via === 'whatsapp' && ! $sandbox && preg_replace('/\D/', '', $wa) === '') {
                $waUrl = 'https://wa.me/?text='.rawurlencode($text);   // no SMSA number saved: WhatsApp asks whom to send it to
            }

            return ['pickup' => DB::table('smsa_pickups')->find($id), 'text' => $text, 'whatsappUrl' => $waUrl, 'emailed' => $emailed];
        });
    }

    /** Cancels a booking (tells SMSA by email when it was emailed); its waybills can be booked again. */
    public static function cancel(int $pickupId, ?object $admin): object
    {
        $p = DB::table('smsa_pickups')->find($pickupId) ?? throw ApiError::notFound('That pickup does not exist.');
        if ($p->status !== 'requested') {
            throw new ApiError('closed', $p->status === 'done' ? 'SMSA already collected these parcels.' : 'This pickup is already cancelled.', 409);
        }
        $orderIds = DB::table('shipments')->where('pickup_id', $p->id)->pluck('order_id');
        DB::table('smsa_pickups')->where('id', $p->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
        DB::table('shipments')->where('pickup_id', $p->id)->where('status', 'created')->update(['pickup_id' => null]);
        if ($p->sent_via === 'email' && self::emails()) {
            $awbs = DB::table('shipments')->whereIn('order_id', $orderIds)->pluck('awb')->implode(', ');
            $shop = Settings::get('brand')['name'];
            $text = "Hello SMSA team,\n\nPlease CANCEL pickup request #{$p->id} for {$shop} on ".self::when($p).".\nWaybills: {$awbs}\n\nThank you.";
            self::send($p, $text, self::emails(), 'CANCEL pickup request', 'cancel');
        }
        foreach ($orderIds as $oid) {
            Orders::event((int) $oid, 'smsa_pickup', 'SMSA pickup #'.$p->id.' cancelled'.($p->sent_via === 'email' ? ' (SMSA emailed).' : ' – tell SMSA if you sent it on WhatsApp.'), [], $admin);
        }

        return DB::table('smsa_pickups')->find($p->id);
    }

    /** Tracking: a booking whose parcels SMSA has all scanned is done. */
    public static function settle(?int $pickupId): void
    {
        if (! $pickupId) {
            return;
        }
        if (! DB::table('shipments')->where('pickup_id', $pickupId)->exists()) {
            // every waybill in it was cancelled: nothing left to collect
            DB::table('smsa_pickups')->where('id', $pickupId)->where('status', 'requested')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);

            return;
        }
        $left = DB::table('shipments')->where('pickup_id', $pickupId)->where('status', 'created')->count();
        if ($left === 0) {
            DB::table('smsa_pickups')->where('id', $pickupId)->where('status', 'requested')->update(['status' => 'done', 'done_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function when(object $p): string
    {
        $d = Carbon::createFromFormat('Y-m-d', substr((string) $p->pickup_date, 0, 10), Fmt::tz())->locale('en');

        return $d->translatedFormat('D j M Y').', '.$p->ready_from.'–'.$p->ready_until;
    }

    /** The request, in English (SMSA's operations team), the same for email, WhatsApp and copy. */
    public static function message(object $p, array $lines, ?object $admin): string
    {
        $s = (array) (Smsa::cfg()['shipper'] ?? []) + ['name' => '', 'phone' => '', 'address1' => '', 'address2' => '', 'district' => '', 'city' => '', 'country' => '', 'postalCode' => ''];
        $cfg = Smsa::cfg();
        $shop = Settings::get('brand')['name'];
        $biz = Settings::get('business');
        $addr = implode(', ', array_filter([$s['address1'] ?? '', $s['address2'] ?? '', $s['district'] ?? '', $s['city'] ?? '', $s['country'] ?? '', $s['postalCode'] ?? '']));
        $out = "Hello SMSA team,\n\nPlease collect our shipments:\n\n";
        $out .= 'Pickup request: #'.$p->id."\n";
        $out .= 'Date and time: '.self::when($p)."\n";
        $out .= 'Shipper: '.($s['name'] ?: $shop).(($cfg['retailId'] ?? '') !== '' ? ' (account '.$cfg['retailId'].')' : '')."\n";
        $out .= 'Pickup address: '.$addr."\n";
        $out .= 'Contact: '.trim(($admin->name ?? '').' '.($s['phone'] ?: ($biz['phone'] ?? '')))."\n";
        $out .= 'Parcels: '.$p->parcels.' · about '.rtrim(rtrim(number_format((float) $p->weight_kg, 2), '0'), '.')." kg\n\n";
        $out .= "Waybills:\n";
        foreach ($lines as $l) {
            $out .= '- '.$l['awb'].' (order '.$l['number'].', '.$l['parcels'].' '.($l['parcels'] === 1 ? 'parcel' : 'parcels').', '.$l['kg'].' kg'.($l['to'] !== '' ? ' → '.$l['to'] : '').")\n";
        }
        if ($p->note !== '') {
            $out .= "\nNote: ".$p->note."\n";
        }
        $out .= "\nPlease confirm. Thank you.\n".($s['name'] ?: $shop);

        return $out;
    }

    /** Emails SMSA (plain words in the shop's email layout), Reply-To the shop, a copy to the store notification addresses. */
    private static function send(object $p, string $text, array $to, string $what, string $tag = 'book'): bool
    {
        $shop = Settings::get('brand')['name'];
        $subject = $what.' #'.$p->id.' – '.$shop.' – '.self::when($p).' – '.$p->parcels.' '.((int) $p->parcels === 1 ? 'parcel' : 'parcels');
        $html = EmailLayout::wrap('en', $subject, '<div style="white-space:pre-line;font-size:15px;line-height:1.6;">'.e($text).'</div>', ['title' => $subject]);
        $reply = (string) (Settings::get('business')['email'] ?? '');
        $sent = false;
        foreach (array_merge($to, array_diff(Mailer::notifyAddresses(), $to)) as $i => $addr) {
            $id = Outbox::queue($addr, ($i >= count($to) ? '[Copy] ' : '').$subject, $html, ['kind' => 'transactional', 'key' => 'smsa_pickup', 'text' => $text,
                'dedupe' => 'smsa_pickup:'.$p->id.':'.$tag.':'.$addr, 'replyTo' => filter_var($reply, FILTER_VALIDATE_EMAIL) ? $reply : null]);
            $sent = $sent || ($i < count($to) && $id > 0);
        }

        return $sent;
    }

    /** For the order page. */
    public static function forOrder(int $orderId): ?array
    {
        $pid = DB::table('shipments')->where('order_id', $orderId)->value('pickup_id');
        $p = $pid ? DB::table('smsa_pickups')->find($pid) : null;

        return $p ? self::present($p) : null;
    }

    public static function present(object $p): array
    {
        return [
            'id' => (int) $p->id, 'mode' => $p->mode, 'date' => substr((string) $p->pickup_date, 0, 10), 'from' => $p->ready_from, 'until' => $p->ready_until,
            'when' => self::when($p), 'status' => $p->status, 'parcels' => (int) $p->parcels, 'weightKg' => (float) $p->weight_kg, 'note' => $p->note,
            'sentVia' => $p->sent_via, 'sentTo' => $p->sent_to, 'by' => $p->created_by, 'createdAt' => $p->created_at, 'message' => (string) $p->message,
            'awbs' => DB::table('shipments as sh')->join('orders as o', 'o.id', '=', 'sh.order_id')->where('sh.pickup_id', $p->id)
                ->get(['o.id', 'o.number', 'sh.awb', 'sh.status'])->map(fn ($x) => ['orderId' => (int) $x->id, 'number' => $x->number, 'awb' => $x->awb, 'status' => $x->status])->all(),
        ];
    }
}
