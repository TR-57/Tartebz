<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SMSA pickup bookings: "come and collect these waybills from the shop on <day> between <from> and <until>".
 *
 * SMSA's eCom API cannot book a courier collection (its "pickup" endpoints are customer returns); collections are booked
 * in SMSA's e-commerce dashboard under Manage Pickup › Initiate Pickup Request. So a booking here keeps the day, the
 * window (inside 09:00–17:00 by default) and the waybills, opens SMSA's dashboard, gives the exact answers for that form
 * (each with a copy button) and keeps SMSA's booking number once it is pasted back, for the whole team to see.
 * A booking can carry several waybills (one courier visit); it closes by itself once SMSA has scanned every parcel.
 */
class SmsaPickups
{
    public const STEP_MINUTES = 30;

    public const DAYS_AHEAD = 6;

    /** A waybill older than this is not offered for a collection (a parcel does not wait that long). */
    public const WAYBILL_DAYS = 14;

    /**
     * Bookings whose day has passed without SMSA scanning the parcels are closed, so they stop showing above the
     * list and their waybills can be booked again. Runs whenever pickups are listed or booked.
     */
    public static function expire(): void
    {
        $old = DB::table('smsa_pickups')->where('status', 'requested')->where('pickup_date', '<', self::now()->toDateString())->get();
        foreach ($old as $p) {
            $orderIds = DB::table('shipments')->where('pickup_id', $p->id)->where('status', 'created')->pluck('order_id');
            DB::table('smsa_pickups')->where('id', $p->id)->where('status', 'requested')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
            DB::table('shipments')->where('pickup_id', $p->id)->where('status', 'created')->update(['pickup_id' => null]);
            foreach ($orderIds as $oid) {
                Orders::event((int) $oid, 'smsa_pickup', 'SMSA pickup #'.$p->id.' closed – its day passed and SMSA did not collect it. Book a new pickup.');
            }
        }
    }

    public static function cfg(): array
    {
        $p = (array) (Smsa::cfg()['pickup'] ?? []);

        return [
            'dashboardUrl' => (string) (($p['dashboardUrl'] ?? '') ?: 'https://ecom.smsaexpress.com/'),
            'heavyKg' => max(1, (float) ($p['heavyKg'] ?? 30)),
            'from' => self::hhmm($p['from'] ?? '09:00') ?? '09:00', 'until' => self::hhmm($p['until'] ?? '17:00') ?? '17:00',
            'minHours' => max(0.5, min(8, (float) ($p['minHours'] ?? 1))),
        ];
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
        self::expire();

        return DB::table('shipments as sh')->join('orders as o', 'o.id', '=', 'sh.order_id')
            ->where('sh.carrier', 'smsa')->where('sh.status', 'created')->where('sh.awb', '<>', '')->where('sh.created_at', '>=', now()->subDays(self::WAYBILL_DAYS))
            ->where(fn ($q) => $q->whereNull('sh.pickup_id')->orWhereIn('sh.pickup_id', DB::table('smsa_pickups')->where('status', '<>', 'requested')->select('id')))
            ->when($mode, fn ($q) => $q->where('sh.mode', $mode))->when($exceptOrder, fn ($q) => $q->where('o.id', '<>', $exceptOrder))
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->orderBy('sh.created_at')->limit(50)
            ->get(['o.id', 'o.number', 'o.first_name', 'o.last_name', 'o.city', 'o.country', 'o.weight_grams', 'sh.awb', 'sh.mode', 'sh.parcels', 'sh.weight_kg', 'sh.created_at'])
            ->map(fn ($x) => ['orderId' => (int) $x->id, 'number' => $x->number, 'name' => trim($x->first_name.' '.$x->last_name),
                'place' => trim($x->city.', '.$x->country, ', '), 'awb' => $x->awb, 'mode' => $x->mode, 'parcels' => max(1, (int) $x->parcels),
                'kg' => round($x->weight_kg !== null && (float) $x->weight_kg > 0 ? (float) $x->weight_kg : max(0.1, (int) $x->weight_grams / 1000), 2)])->all();
    }

    /**
     * Books one SMSA pickup (a day and a window) for the waybills of $orderIds that are waiting for the courier.
     * Empty $orderIds = every waybill waiting and not booked yet. $parcels overrides the box count (0 = from the waybills).
     * $heavy: null = by weight (Settings › SMSA shipping › Pickups). Returns the booking; its form answers are in present().
     */
    public static function book(array $orderIds, string $date, string $from, string $until, string $note, ?object $admin, int $parcels = 0, ?bool $heavy = null): object
    {
        [$date, $from, $until] = self::validWindow($date, $from, $until);
        $note = mb_substr(trim($note), 0, 300);
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), fn ($i) => $i > 0)));

        self::expire();

        return DB::transaction(function () use ($ids, $date, $from, $until, $note, $admin, $parcels, $heavy) {
            $free = fn ($q) => $q->whereNull('pickup_id')->orWhereIn('pickup_id', DB::table('smsa_pickups')->where('status', '<>', 'requested')->select('id'));
            $ships = DB::table('shipments')->where('carrier', 'smsa')->where('status', 'created')->where('awb', '<>', '')->where($free)
                ->where('created_at', '>=', now()->subDays(self::WAYBILL_DAYS))
                ->when($ids, fn ($q) => $q->whereIn('order_id', $ids))
                ->whereIn('order_id', DB::table('orders')->whereNotIn('status', ['cancelled', 'refunded'])->select('id'))
                ->orderBy('id')->lockForUpdate()->get();
            if ($ships->isEmpty()) {
                throw new ApiError('no_waybill', 'No SMSA waybills are waiting for collection. Send the orders to SMSA first (their waybills), then book the pickup.', 409);
            }
            // one server per booking: live waybills go to SMSA; sandbox ones only when there are no live ones
            $mode = $ships->contains(fn ($x) => $x->mode === 'live') ? 'live' : 'test';
            $ships = $ships->where('mode', $mode)->values();

            $orders = DB::table('orders')->whereIn('id', $ships->pluck('order_id'))->get()->keyBy('id');
            $count = 0;
            $kg = 0.0;
            $lines = [];
            foreach ($ships as $sh) {
                $o = $orders[$sh->order_id];
                $n = max(1, (int) ($sh->parcels ?? 1));
                $w = round($sh->weight_kg !== null && (float) $sh->weight_kg > 0 ? (float) $sh->weight_kg : max(0.1, (int) $o->weight_grams / 1000), 2);
                $count += $n;
                $kg += $w;
                $lines[] = ['awb' => $sh->awb, 'number' => $o->number, 'parcels' => $n, 'kg' => $w, 'to' => trim($o->city.', '.$o->country, ', ')];
            }
            $parcels = $parcels > 0 ? min(999, $parcels) : $count;
            $sandbox = $mode !== 'live';
            $id = DB::table('smsa_pickups')->insertGetId([
                'mode' => $mode, 'pickup_date' => $date, 'ready_from' => $from, 'ready_until' => $until, 'status' => 'requested',
                'parcels' => $parcels, 'weight_kg' => round($kg, 2), 'note' => $note, 'heavy' => $heavy ?? ($kg >= self::cfg()['heavyKg']),
                'sent_via' => $sandbox ? 'sandbox' : 'dashboard', 'sent_to' => '', 'created_by' => $admin->name ?? '', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('shipments')->whereIn('id', $ships->pluck('id'))->update(['pickup_id' => $id, 'updated_at' => now()]);
            $p = DB::table('smsa_pickups')->find($id);
            DB::table('smsa_pickups')->where('id', $id)->update(['message' => self::notes($p, $lines)]);
            foreach ($ships as $sh) {
                Orders::event((int) $sh->order_id, 'smsa_pickup', 'SMSA pickup planned for '.self::when($p).' (#'.$id.($sandbox ? ', sandbox' : '').').', [], $admin);
            }

            return DB::table('smsa_pickups')->find($id);
        });
    }

    /** Cancels a booking here (it must also be cancelled in SMSA's dashboard); its waybills can be booked again. */
    public static function cancel(int $pickupId, ?object $admin): object
    {
        $p = DB::table('smsa_pickups')->find($pickupId) ?? throw ApiError::notFound('That pickup does not exist.');
        if ($p->status !== 'requested') {
            throw new ApiError('closed', $p->status === 'done' ? 'SMSA already collected these parcels.' : 'This pickup is already cancelled.', 409);
        }
        $orderIds = DB::table('shipments')->where('pickup_id', $p->id)->pluck('order_id');
        DB::table('smsa_pickups')->where('id', $p->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
        DB::table('shipments')->where('pickup_id', $p->id)->where('status', 'created')->update(['pickup_id' => null]);
        foreach ($orderIds as $oid) {
            Orders::event((int) $oid, 'smsa_pickup', 'SMSA pickup #'.$p->id.' cancelled.', [], $admin);
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

    /** SMSA's booking number (pasted from their dashboard after booking). */
    public static function setRef(int $pickupId, string $ref, ?object $admin): object
    {
        $p = DB::table('smsa_pickups')->find($pickupId) ?? throw ApiError::notFound('That pickup does not exist.');
        $ref = trim($ref);
        if ($ref === '' || ! preg_match('/^[A-Za-z0-9 \/_.-]{2,60}$/', $ref)) {
            throw ApiError::validation(['ref' => 'Paste the booking number SMSA showed (letters and numbers).']);
        }
        DB::table('smsa_pickups')->where('id', $p->id)->update(['smsa_ref' => $ref, 'updated_at' => now()]);
        foreach (DB::table('shipments')->where('pickup_id', $p->id)->pluck('order_id') as $oid) {
            Orders::event((int) $oid, 'smsa_pickup', 'SMSA booking number '.$ref.' (pickup #'.$p->id.').', [], $admin);
        }

        return DB::table('smsa_pickups')->find($p->id);
    }

    /** The "Notes" for SMSA's form: parcels, waybills and the team's note. */
    public static function notes(object $p, array $lines): string
    {
        $out = $p->parcels.' '.((int) $p->parcels === 1 ? 'parcel' : 'parcels').', about '.rtrim(rtrim(number_format((float) $p->weight_kg, 2), '0'), '.').' kg. Waybills: '
            .implode(', ', array_column($lines, 'awb')).'.';

        return $p->note !== '' ? $out.' '.$p->note : $out;
    }

    /** "Road 1, West Riffa" + "West Riffa" → once only. */
    private static function joinParts(array $parts): string
    {
        $out = '';
        foreach (array_filter(array_map(fn ($x) => trim((string) $x), $parts)) as $x) {
            if ($out === '' || mb_stripos($out, $x) === false) {
                $out .= ($out === '' ? '' : ', ').$x;
            }
        }

        return $out;
    }

    /** The answers for SMSA's "Initiate Pickup Request" form, in its order. */
    public static function fields(object $p): array
    {
        $s = (array) (Smsa::cfg()['shipper'] ?? []) + ['name' => '', 'phone' => '', 'address1' => '', 'address2' => '', 'district' => '', 'city' => '', 'postalCode' => ''];
        $biz = Settings::get('business');
        $day = Carbon::createFromFormat('Y-m-d', substr((string) $p->pickup_date, 0, 10), Fmt::tz())->locale('en');

        return [
            ['key' => 'contact', 'label' => 'Contact name', 'value' => (string) ($s['name'] ?: Settings::get('brand')['name'])],
            ['key' => 'phone', 'label' => 'Contact phone', 'value' => (string) ($s['phone'] ?: ($biz['phone'] ?? ''))],
            ['key' => 'address', 'label' => 'Pickup address', 'value' => self::joinParts([$s['address1'], $s['address2'], $s['district']])],
            ['key' => 'postal', 'label' => 'Postal code', 'value' => (string) $s['postalCode']],
            ['key' => 'city', 'label' => 'Origin city', 'value' => (string) $s['city']],
            ['key' => 'heavy', 'label' => 'Heavy shipment', 'value' => ! empty($p->heavy) ? 'Yes' : 'No'],
            ['key' => 'ready', 'label' => 'Shipment ready time', 'value' => $day->translatedFormat('D j M Y').', '.$p->ready_from],
            ['key' => 'closing', 'label' => 'Branch closing time', 'value' => $p->ready_until],
            ['key' => 'notes', 'label' => 'Notes', 'value' => (string) $p->message],
        ];
    }

    /** Bookings still waiting for the courier (orders list, staff board, SMSA settings). */
    public static function open(): array
    {
        self::expire();

        return DB::table('smsa_pickups')->where('status', 'requested')->orderBy('pickup_date')->orderBy('ready_from')->limit(20)->get()
            ->map(fn ($p) => self::present($p))->values()->all();
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
            'id' => (int) $p->id, 'mode' => $p->mode, 'smsaRef' => (string) ($p->smsa_ref ?? ''), 'date' => substr((string) $p->pickup_date, 0, 10), 'from' => $p->ready_from, 'until' => $p->ready_until,
            'when' => self::when($p), 'status' => $p->status, 'parcels' => (int) $p->parcels, 'weightKg' => (float) $p->weight_kg, 'note' => $p->note,
            'sentVia' => $p->sent_via, 'by' => $p->created_by, 'createdAt' => $p->created_at, 'heavy' => ! empty($p->heavy),
            'fields' => self::fields($p), 'dashboardUrl' => self::cfg()['dashboardUrl'],
            'awbs' => DB::table('shipments as sh')->join('orders as o', 'o.id', '=', 'sh.order_id')->where('sh.pickup_id', $p->id)
                ->get(['o.id', 'o.number', 'sh.awb', 'sh.status'])->map(fn ($x) => ['orderId' => (int) $x->id, 'number' => $x->number, 'awb' => $x->awb, 'status' => $x->status])->all(),
        ];
    }
}
