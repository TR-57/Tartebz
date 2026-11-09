<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * SMSA Express (ecomapis.smsaexpress.com) – the same integration as Sniper Safety.
 *
 *   - A paid order whose delivery method goes through SMSA (Admin › SMSA shipping) gets its waybill (AWB) by itself,
 *     from the order: address, phone, weight, value, contents. `store:smsa` runs every minute; a refused waybill is
 *     tried again (3 times at most, 10 minutes apart). Any paid order can also be sent by hand from the order.
 *   - Its label (a PDF from SMSA) is kept and prints from the order – with the declared value printed as a share of the
 *     order value if wanted – or, for many orders, as one PDF.
 *   - SMSA's tracking is read every hour: the scans go on the order's history, and the order becomes Shipped when SMSA
 *     has the parcel and Delivered when it is delivered (the customer is emailed).
 *   - Service: inside the sender's country EDDL; abroad the delivery method's service – by air EIDL or by road EIED.
 *   - Import VAT and duty on a shipment abroad: the customer pays SMSA on delivery ('consignee') or SMSA bills our
 *     account ('shipper'). Each waybill keeps who pays; SMSA's own record is compared with what was sent.
 *
 * Sandbox and live servers have their own key (sealed in the settings); a waybill remembers which one made it.
 */
class Smsa
{
    public const TRACK_URL = 'https://smsaexpress.com/trackingdetails?tracknumbers=';

    private const TRACK_EVERY_MINUTES = 60;

    private const MAX_AUTO_ATTEMPTS = 3;

    public const SERVICES = ['EIDL' => 'By air', 'EIED' => 'By road'];

    public const DOMESTIC = 'EDDL';

    public const DUTIES = ['consignee', 'shipper'];

    public const DUTIES_LABEL = ['consignee' => 'the customer (paid to SMSA on delivery)', 'shipper' => 'us (billed to our SMSA account)'];

    public static function cfg(): array
    {
        return Settings::get('smsa');
    }

    public static function mode(): string
    {
        return self::cfg()['mode'] === 'live' ? 'live' : 'test';
    }

    public static function key(?string $mode = null): string
    {
        $mode ??= self::mode();

        return Secret::open(self::cfg()[$mode === 'live' ? 'liveKey' : 'testKey'] ?? '');
    }

    public static function dutiesBy(): string
    {
        $v = (string) (self::cfg()['dutiesBy'] ?? 'consignee');

        return in_array($v, self::DUTIES, true) ? $v : 'consignee';
    }

    /** A method with no choice saved yet: by road when its name says so (road, land, truck; بري, البر, شاحنة), else by air. */
    public static function guessService(mixed $name): string
    {
        $text = mb_strtolower(is_array($name) ? implode(' ', array_map('strval', array_filter($name, 'is_scalar'))) : (string) $name);
        $word = '(?<![\p{L}\p{M}])(?:%s)(?![\p{L}\p{M}])';
        $road = sprintf($word, 'road|land|overland|truck|lorry|by car').'|'.sprintf($word, 'بر[ّ]?ي|براً?|البر|بالبر|البري|شاحنة|بالشاحنة');

        return preg_match('/'.$road.'/u', $text) ? 'EIED' : 'EIDL';
    }

    /** The service for a delivery method going abroad: the one chosen in the admin, else guessed from its name. */
    public static function serviceOf(?array $method): string
    {
        $chosen = (string) ((self::cfg()['services'] ?? [])[(string) ($method['id'] ?? '')] ?? '');

        return isset(self::SERVICES[$chosen]) ? $chosen : self::guessService($method['name'] ?? '');
    }

    /** What still stops automatic sending, in words for the team: the key, the sender, at least one method; [] when ready. */
    public static function missing(): array
    {
        $out = self::missingToSend();
        if (! array_filter((array) (self::cfg()['methods'] ?? []))) {
            $out[] = 'at least one delivery method sent with SMSA';
        }

        return $out;
    }

    /** What stops sending one order by hand: the key and the sender. */
    public static function missingToSend(): array
    {
        $s = self::cfg();
        $sh = $s['shipper'] ?? [];
        $out = [];
        if (self::key() === '') {
            $out[] = 'the SMSA API key for '.(self::mode() === 'live' ? 'live' : 'sandbox').' mode';
        }
        foreach (['name' => 'the sender name', 'phone' => 'the sender phone', 'city' => 'the sender city', 'address1' => 'the sender address'] as $f => $label) {
            if (trim((string) ($sh[$f] ?? '')) === '') {
                $out[] = $label;
            }
        }

        return $out;
    }

    /** Sending works (key and sender address saved) – by hand from an order. */
    public static function configured(): bool
    {
        return ! self::missingToSend();
    }

    /** Switched on: paid orders with SMSA delivery methods get their waybill by themselves. */
    public static function enabled(): bool
    {
        return ! empty(self::cfg()['enabled']) && ! self::missing();
    }

    /** The order's delivery method, when it is one that goes through SMSA (else null). */
    public static function methodOf(object $o): ?array
    {
        $id = (string) ($o->shipping_method ?? '');

        return $id !== '' && in_array($id, (array) (self::cfg()['methods'] ?? []), true) ? Shipping::findMethod($id) : null;
    }

    public static function shipment(int $orderId): ?object
    {
        return DB::table('shipments')->where('order_id', $orderId)->first();
    }

    public static function home(): string
    {
        return strtoupper((string) (self::cfg()['shipper']['country'] ?? 'BH')) ?: 'BH';
    }

    /* ============================================================ the run */

    /** One pass of `store:smsa`: waybills for paid orders that need one, then tracking not checked in the last hour. */
    public static function run(int $seconds = 50): array
    {
        $out = ['created' => 0, 'failed' => 0, 'tracked' => 0];
        if (! self::enabled()) {
            return $out;
        }
        $until = microtime(true) + $seconds;
        $s = self::cfg();
        $methods = array_values((array) ($s['methods'] ?? []));
        if ($methods) {
            $rows = DB::table('orders as o')->leftJoin('shipments as sh', 'sh.order_id', '=', 'o.id')
                ->where('o.payment_status', 'paid')->whereIn('o.status', ['paid', 'processing'])
                ->where('o.created_at', '>=', $s['since'] ?: now())->whereIn('o.shipping_method', $methods)
                ->where(fn ($q) => $q->whereNull('sh.id')->orWhere(fn ($q) => $q->where('sh.status', 'failed')->where('sh.attempts', '<', self::MAX_AUTO_ATTEMPTS)
                    ->where('sh.updated_at', '<', now()->subMinutes(10))))
                ->orderBy('o.id')->limit(50)->select('o.id')->pluck('id');
            foreach ($rows as $id) {
                if (microtime(true) >= $until) {
                    return $out;
                }
                try {
                    self::create((int) $id, null);
                    $out['created']++;
                } catch (ApiError) {
                    $out['failed']++;
                }
            }
        }
        foreach (DB::table('shipments')->where('carrier', 'smsa')->whereIn('status', ['created', 'moving'])->where('awb', '<>', '')
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(self::TRACK_EVERY_MINUTES)))
            ->orderBy('checked_at')->limit(100)->get() as $sh) {
            if (microtime(true) >= $until) {
                break;
            }
            try {
                self::track($sh);
                $out['tracked']++;
            } catch (ApiError $e) {
                DB::table('shipments')->where('id', $sh->id)->update(['checked_at' => now()]);
                Log::warning('smsa: tracking failed', ['awb' => $sh->awb, 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /* ============================================================ waybill */

    /**
     * Creates the SMSA waybill for an order. Returns the shipment; on a refusal the shipment is kept as failed with
     * SMSA's reason (the order's history says it) and an ApiError says why.
     */
    public static function create(int $orderId, ?object $admin, int $parcels = 1, ?float $kg = null): object
    {
        $o = DB::table('orders')->find($orderId) ?? throw ApiError::notFound('That order does not exist.');
        $have = self::shipment($orderId);
        if ($have && ! in_array($have->status, ['failed', 'cancelled'], true)) {
            throw new ApiError('has_awb', 'This order already has SMSA waybill '.$have->awb.'.', 409);
        }
        if (! $o->paid_at || in_array($o->status, ['cancelled', 'refunded'], true)) {
            throw new ApiError('unpaid', 'Only paid orders are sent to SMSA.', 409);
        }
        if ($missing = self::missingToSend()) {
            throw new ApiError('smsa_not_set', 'SMSA is not set up yet: enter '.implode(', ', $missing).' in Settings › SMSA shipping.', 409);
        }
        $mode = self::mode();
        try {
            $payload = self::payload($o, $parcels, $kg);
            $res = (array) self::call('POST', '/api/shipment/b2c/new', $payload, $mode);
        } catch (ApiError $e) {
            self::fail($o, $have, $e->getMessage(), $admin);
            throw $e;
        }
        $wb = (array) (($res['waybills'] ?? [])[0] ?? []);
        $awb = (string) (($res['sawb'] ?? '') ?: ($wb['awb'] ?? ''));
        if ($awb === '') {
            self::fail($o, $have, 'SMSA did not return a waybill number.', $admin);
            throw new ApiError('smsa_failed', 'SMSA did not return a waybill number.', 502);
        }
        $intl = strtoupper((string) $payload['ConsigneeAddress']['Country']) !== strtoupper((string) $payload['ShipperAddress']['Country']);
        $dutiesBy = $intl ? ($payload['VatPaid'] && $payload['DutyPaid'] ? 'shipper' : 'consignee') : null;
        $row = ['carrier' => 'smsa', 'mode' => $mode, 'awb' => mb_substr($awb, 0, 40), 'service' => (string) $payload['ServiceCode'], 'duties_by' => $dutiesBy,
            'status' => 'created', 'error' => '', 'attempts' => (int) ($have->attempts ?? 0) + 1, 'scans' => null, 'last_scan' => '', 'checked_at' => null, 'updated_at' => now()];
        if ($have) {
            DB::table('shipments')->where('id', $have->id)->update($row);
        } else {
            DB::table('shipments')->insert($row + ['order_id' => $orderId, 'created_at' => now()]);
        }
        $label = self::keepLabels($awb, $res['waybills'] ?? []) ? self::labelRel($awb) : '';
        DB::table('orders')->where('id', $orderId)->update(['awb' => mb_substr($awb, 0, 40), 'carrier' => 'smsa:'.$mode, 'label_path' => $label,
            'tracking_status' => '', 'updated_at' => now()]);
        $svc = (string) $payload['ServiceCode'];
        Orders::event($orderId, 'shipment', 'SMSA waybill created'.($mode === 'test' ? ' (sandbox)' : '').': '.$awb.' – '
            .(isset(self::SERVICES[$svc]) ? strtolower(self::SERVICES[$svc]).' ('.$svc.') – ' : 'inside '.$payload['ShipperAddress']['Country'].' ('.$svc.') – ')
            .($dutiesBy === null ? 'no import VAT or duty.' : 'import VAT and duty paid by '.self::DUTIES_LABEL[$dutiesBy].'.'), ['awb' => $awb], $admin);
        if ($intl) {
            self::confirmDuties($orderId, $awb, $payload, $res, $mode);
        }
        Orders::emailTracking(DB::table('orders')->find($orderId));

        return self::shipment($orderId);
    }

    /** What SMSA is told about the order. Throws when the address cannot pass SMSA's rules. */
    public static function payload(object $o, int $parcels = 1, ?float $kg = null): array
    {
        $s = self::cfg();
        [$consignee, $problems] = self::consignee($o);
        if ($problems) {
            throw new ApiError('fix_address', 'Not sent to SMSA: '.implode(', ', $problems).'. Fix the order’s address, then try again.', 422);
        }
        $names = DB::table('order_items')->where('order_id', $o->id)->pluck('name')->all();
        $contents = trim((string) ($s['contents'] ?? '')) ?: implode(', ', $names);
        $home = self::home();
        $country = strtoupper((string) $o->country);
        $wePay = $country !== $home && self::dutiesBy() === 'shipper';
        $method = $o->shipping_method ? Shipping::findMethod($o->shipping_method) : null;

        return [
            'ConsigneeAddress' => $consignee,
            'ShipperAddress' => self::shipper(),
            'OrderNumber' => (string) $o->number,
            'DeclaredValue' => round(max(0.0, (float) $o->subtotal - (float) $o->discount), 3),
            'CODAmount' => 0,
            'Parcels' => max(1, min(25, $parcels)),
            'ShipDate' => now()->format('Y-m-d\TH:i:s'),
            'ShipmentCurrency' => 'BHD',
            'SMSARetailID' => (string) (($s['retailId'] ?? '') ?: '0'),
            'WaybillType' => 'PDF',
            'Weight' => round(max(0.1, $kg ?? ($o->weight_grams / 1000) ?: 0.5), 3),
            'WeightUnit' => 'KG',
            'ContentDescription' => mb_substr($contents, 0, 100),
            // import VAT and duty: only a shipment out of the sender's country has them
            'VatPaid' => $wePay,
            'DutyPaid' => $wePay,
            // inside the sender's country EDDL; abroad the method's service: by air EIDL, by road EIED
            'ServiceCode' => $country === $home ? self::DOMESTIC : self::serviceOf($method),
        ];
    }

    /** The receiver. Returns [address, problems]: the address line, city and phone must not be empty. */
    public static function consignee(object $o): array
    {
        $problems = [];
        foreach (['address1' => 'address', 'city' => 'city', 'phone' => 'phone'] as $f => $label) {
            if (trim((string) $o->{$f}) === '') {
                $problems[] = 'the customer’s '.$label.' is empty';
            }
        }
        [$cc, $num] = Shipping::splitPhone((string) $o->phone, (string) $o->country);
        $line2 = trim((string) $o->address2);

        return [[
            'ContactName' => mb_substr(trim($o->first_name.' '.$o->last_name), 0, 150), 'ContactPhoneNumber' => $num !== '' ? $cc.$num : '',
            'ContactPhoneNumber2' => '', 'Coordinates' => '', 'Country' => strtoupper((string) $o->country),
            'District' => mb_substr($line2 !== '' ? $line2 : trim((string) $o->city), 0, 100), 'PostalCode' => '',
            'City' => mb_substr(trim((string) $o->city), 0, 50), 'AddressLine1' => mb_substr(trim((string) $o->address1), 0, 100),
            'AddressLine2' => mb_substr($line2, 0, 100), 'ConsigneeID' => '', 'ShortCode' => '',
        ], $problems];
    }

    public static function shipper(): array
    {
        $s = self::cfg()['shipper'];
        [$cc, $num] = Shipping::splitPhone((string) $s['phone'], (string) $s['country']);

        return [
            'ContactName' => (string) $s['name'], 'ContactPhoneNumber' => $cc.$num, 'Coordinates' => '', 'Country' => self::home(),
            'District' => (string) (($s['district'] ?? '') ?: $s['city']), 'PostalCode' => (string) ($s['postalCode'] ?? ''), 'City' => (string) $s['city'],
            'AddressLine1' => (string) $s['address1'], 'AddressLine2' => (string) ($s['address2'] ?? ''),
        ];
    }

    /** The sender fields still empty (Admin › SMSA shipping). */
    public static function shipperProblems(): array
    {
        $sh = self::cfg()['shipper'] ?? [];
        $p = [];
        foreach (['name' => 'the sender name', 'phone' => 'the sender phone', 'city' => 'the sender city', 'address1' => 'the sender address'] as $f => $label) {
            if (trim((string) ($sh[$f] ?? '')) === '') {
                $p[] = 'SMSA shipping: enter '.$label.'.';
            }
        }

        return $p;
    }

    /** SMSA's record of who pays import VAT and duty, compared with what was sent; a difference warns on the order. */
    private static function confirmDuties(int $orderId, string $awb, array $sent, array $res, string $mode): void
    {
        $got = self::dutyFlags($res);
        if (! $got) {
            try {
                $got = self::dutyFlags(self::call('GET', '/api/shipment/b2c/query/'.rawurlencode($awb), null, $mode));
            } catch (\Throwable) {
                return;
            }
        }
        if (! $got) {
            return;
        }
        $who = fn (bool $paid) => $paid ? 'Shipper (us)' : 'Consignee (customer)';
        $diff = [];
        foreach (['VatPaid' => 'VAT billing', 'DutyPaid' => 'Duty billing'] as $k => $label) {
            if (array_key_exists($k, $got) && $got[$k] !== (bool) $sent[$k]) {
                $diff[] = $label.': SMSA has '.$who($got[$k]).', we sent '.$who((bool) $sent[$k]);
            }
        }
        if ($diff) {
            Orders::event($orderId, 'warning', 'SMSA waybill '.$awb.' does not match the import VAT and duty setting – '.implode('; ', $diff).'. Check this waybill in the SMSA portal before it ships.');
            self::backToStaff($orderId);
            Log::warning('smsa duties mismatch', ['order' => $orderId, 'awb' => $awb, 'got' => $got]);

            return;
        }
        Orders::event($orderId, 'shipment', 'SMSA confirmed waybill '.$awb.': '.implode(', ', array_map(
            fn ($k) => ($k === 'VatPaid' ? 'VAT billing ' : 'Duty billing ').$who($got[$k]), array_keys($got))).'.');
    }

    /** VatPaid / DutyPaid wherever they are in an SMSA reply (any letter case), as true/false. */
    private static function dutyFlags(mixed $v): array
    {
        $out = [];
        if (! is_array($v)) {
            return $out;
        }
        foreach ($v as $k => $x) {
            $name = is_string($k) ? strtolower(str_replace(['_', ' '], '', $k)) : '';
            if ($name === 'vatpaid' || $name === 'dutypaid') {
                $b = self::flag($x);
                if ($b !== null) {
                    $out[$name === 'vatpaid' ? 'VatPaid' : 'DutyPaid'] ??= $b;
                }
            } elseif (is_array($x)) {
                $out += self::dutyFlags($x);
            }
        }

        return $out;
    }

    private static function flag(mixed $x): ?bool
    {
        if (is_bool($x)) {
            return $x;
        }
        if (is_int($x) || is_float($x)) {
            return $x != 0;
        }
        $t = strtolower(trim((string) $x));

        return match (true) {
            in_array($t, ['true', '1', 'yes', 'y', 'shipper'], true) => true,
            in_array($t, ['false', '0', 'no', 'n', 'consignee'], true) => false,
            default => null,
        };
    }

    private static function fail(object $o, ?object $have, string $why, ?object $admin): void
    {
        $row = ['carrier' => 'smsa', 'mode' => self::mode(), 'status' => 'failed', 'error' => mb_substr($why, 0, 500), 'attempts' => (int) ($have->attempts ?? 0) + 1, 'updated_at' => now()];
        if ($have) {
            DB::table('shipments')->where('id', $have->id)->update($row);
        } else {
            DB::table('shipments')->insert($row + ['order_id' => $o->id, 'created_at' => now()]);
        }
        Orders::event($o->id, 'warning', 'SMSA waybill not created: '.$why, [], $admin);
        self::backToStaff($o->id);
    }

    /** The order goes back to the team's "to prepare" list (the tick on the orders board is taken off). */
    private static function backToStaff(int $orderId): void
    {
        DB::table('orders')->where('id', $orderId)->update(['prepared_at' => null, 'prepared_by' => '', 'updated_at' => now()]);
    }

    /** Cancels the waybill at SMSA (managers; before SMSA has the parcel). */
    public static function cancel(int $orderId, ?object $admin): void
    {
        $sh = self::shipment($orderId);
        if (! $sh || $sh->awb === '' || $sh->status === 'cancelled') {
            throw new ApiError('no_awb', 'This order has no SMSA waybill.', 409);
        }
        if (in_array($sh->status, ['moving', 'delivered'], true)) {
            throw new ApiError('shipped', 'SMSA already has this parcel – it cannot be cancelled here. Call SMSA.', 409);
        }
        self::call('PATCH', '/api/Shipment/b2c/'.rawurlencode($sh->awb).'/cancel', null, $sh->mode);
        DB::table('shipments')->where('id', $sh->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('orders')->where('id', $orderId)->update(['awb' => '', 'label_path' => '', 'tracking_status' => '', 'updated_at' => now()]);
        Orders::event($orderId, 'shipment', 'SMSA waybill '.$sh->awb.' cancelled.', [], $admin);
    }

    /* ============================================================ labels */

    /** The label PDF of one order (all its parcels). */
    public static function label(int $orderId): string
    {
        $sh = self::shipment($orderId);
        if (! $sh || $sh->awb === '' || $sh->status === 'cancelled') {
            throw new ApiError('no_awb', 'This order has no SMSA waybill yet.', 422);
        }

        return self::labelFor($sh->awb, $sh->mode);
    }

    /** A share of the order's goods value (0–100 %), printed on a copy of the label only. */
    public static function printedDeclaredValue(object $o, mixed $percentage = 100): float
    {
        if ((! is_string($percentage) && ! is_int($percentage) && ! is_float($percentage)) || ! is_numeric($percentage)
            || ! is_finite((float) $percentage) || (float) $percentage < 0 || (float) $percentage > 100) {
            throw ApiError::validation(['percentage' => 'Enter a percentage from 0 to 100.']);
        }

        return round(max(0.0, (float) $o->subtotal - (float) $o->discount) * (float) $percentage / 100, 3);
    }

    /**
     * Writes the declared value (DV) on SMSA's standard 288 × 432 pt label, for this print only – never saved, never
     * sent to SMSA. Other layouts are refused rather than painting over an unknown part of a shipping label.
     */
    public static function withPrintedValue(string $original, float $value): string
    {
        if (! is_finite($value) || $value < 0) {
            throw ApiError::validation(['percentage' => 'The printed value must be 0 or more.']);
        }
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pages = $pdf->setSourceFile(StreamReader::createByString($original));
        for ($page = 1; $page <= $pages; $page++) {
            $tpl = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($tpl);
            if (abs($size['width'] - 288) > 0.5 || abs($size['height'] - 432) > 0.5) {
                throw new ApiError('smsa_label_layout', 'This SMSA label layout does not support printing another value.', 422);
            }
            $pdf->AddPage('P', [288, 432]);
            $pdf->useTemplate($tpl);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect(189, 65, 96, 10, 'F');
            $pdf->SetTextColor(0, 0, 0);
            $text = 'DV:BHD '.number_format($value, 3, '.', '');
            $pdf->SetFont('Times', '', 8);
            if ($pdf->GetStringWidth($text) > 94) {
                $pdf->SetFontSize(8 * 94 / $pdf->GetStringWidth($text));
            }
            $pdf->Text(189, 72, $text);
        }

        return $pdf->Output('S');
    }

    /** The labels of many orders in one PDF, newest order first. Returns [pdf, orders without a waybill]. */
    public static function labels(array $orderIds): array
    {
        rsort($orderIds);
        $files = [];
        $skipped = 0;
        try {
            foreach ($orderIds as $id) {
                $sh = self::shipment((int) $id);
                if (! $sh || $sh->awb === '' || $sh->status === 'cancelled') {
                    $skipped++;

                    continue;
                }
                $files[] = $f = (string) tempnam(sys_get_temp_dir(), 'smsa');
                file_put_contents($f, self::labelFor($sh->awb, $sh->mode));
            }
            if (! $files) {
                throw new ApiError('no_awb', 'None of these orders has an SMSA waybill yet.', 422);
            }

            return [self::merge($files), $skipped];
        } finally {
            array_map('unlink', $files);
        }
    }

    /** A waybill's label: the copy kept here, or SMSA's (then kept for next time). */
    public static function labelFor(string $awb, string $mode = 'live'): string
    {
        $path = storage_path('app/'.self::labelRel($awb));
        $pdf = is_readable($path) ? (string) file_get_contents($path) : '';
        if ($pdf !== '') {
            return $pdf;
        }
        $res = (array) self::call('GET', '/api/shipment/b2c/query/'.rawurlencode($awb), null, $mode);
        $pdf = self::joinLabels($res['waybills'] ?? []);
        if ($pdf === '') {
            throw new ApiError('smsa_no_label', 'SMSA has no label for waybill '.$awb.' yet. Try again in a minute.', 502);
        }
        self::keep($path, $pdf);

        return $pdf;
    }

    private static function keepLabels(string $awb, mixed $waybills): bool
    {
        $pdf = self::joinLabels($waybills);
        if ($pdf === '') {
            return false;
        }
        self::keep(storage_path('app/'.self::labelRel($awb)), $pdf);

        return true;
    }

    private static function keep(string $path, string $pdf): void
    {
        @mkdir(dirname($path), 0770, true);
        if (@file_put_contents($path, $pdf) === false) {
            Log::warning('smsa: could not keep a label (it is fetched from SMSA each time)', ['path' => $path]);
        }
    }

    /** The PDFs in SMSA's "waybills" list (one per parcel) as one PDF, or ''. */
    private static function joinLabels(mixed $waybills): string
    {
        $parts = [];
        foreach (is_array($waybills) ? $waybills : [] as $w) {
            $pdf = base64_decode((string) (((array) $w)['awbFile'] ?? ''), true);
            if ($pdf !== false && str_starts_with($pdf, '%PDF')) {
                $parts[] = $pdf;
            }
        }
        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }
        $tmp = [];
        try {
            foreach ($parts as $pdf) {
                $tmp[] = $f = (string) tempnam(sys_get_temp_dir(), 'smsa');
                file_put_contents($f, $pdf);
            }

            return self::merge($tmp);
        } finally {
            array_map('unlink', $tmp);
        }
    }

    public static function labelRel(string $awb): string
    {
        return 'labels/smsa-'.preg_replace('/[^A-Za-z0-9_-]/', '', $awb).'.pdf';
    }

    /** PDF files joined, every page at its own size. */
    private static function merge(array $files): string
    {
        $pdf = new Fpdi;
        foreach ($files as $f) {
            $pages = $pdf->setSourceFile($f);
            for ($i = 1; $i <= $pages; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        }

        return $pdf->Output('S');
    }

    /* ============================================================ tracking */

    /** Reads SMSA's tracking for a shipment; records new scans and moves the order on. Returns the fresh shipment. */
    public static function track(object $sh): object
    {
        $res = (array) self::call('GET', '/api/track/single/'.rawurlencode($sh->awb), null, $sh->mode);
        $scans = [];
        foreach (is_array($res['Scans'] ?? null) ? $res['Scans'] : [] as $sc) {
            if (is_array($sc)) {
                $scans[] = ['at' => (string) ($sc['ScanDateTime'] ?? ''), 'what' => trim((string) ($sc['ScanDescription'] ?? '')), 'city' => trim((string) ($sc['City'] ?? ''))];
            }
        }
        usort($scans, fn ($a, $b) => strcmp($a['at'], $b['at']));
        $old = json_decode((string) $sh->scans, true) ?: [];
        $seen = array_flip(array_map(fn ($x) => $x['at'].'|'.$x['what'], $old));
        foreach ($scans as $sc) {
            if (! isset($seen[$sc['at'].'|'.$sc['what']]) && $sc['what'] !== '') {
                Orders::event((int) $sh->order_id, 'tracking', 'SMSA: '.$sc['what'].($sc['city'] !== '' ? ' ('.$sc['city'].')' : ''));
            }
        }
        $status = $sh->status;
        if (($res['isDelivered'] ?? false) === true || self::delivered($scans)) {
            $status = 'delivered';
        } elseif (self::moving($scans)) {
            $status = 'moving';
        }
        $last = $scans ? end($scans) : null;
        $lastText = $last ? mb_substr($last['what'].($last['city'] !== '' ? ' – '.$last['city'] : ''), 0, 255) : $sh->last_scan;
        DB::table('shipments')->where('id', $sh->id)->update(['scans' => json_encode($scans, JSON_UNESCAPED_UNICODE), 'status' => $status, 'checked_at' => now(),
            'last_scan' => $lastText, 'last_scan_at' => $last && strtotime($last['at']) ? date('Y-m-d H:i:s', strtotime($last['at'])) : $sh->last_scan_at, 'updated_at' => now()]);
        DB::table('orders')->where('id', $sh->order_id)->update(['tracking_status' => mb_substr((string) $lastText, 0, 200), 'tracking_at' => now()]);

        if (! empty(self::cfg()['autoStatus']) && $status !== $sh->status) {
            $o = DB::table('orders')->find($sh->order_id);
            if ($o && $status === 'delivered' && in_array($o->status, ['paid', 'processing', 'shipped'], true)) {
                DB::table('orders')->where('id', $o->id)->update(['status' => 'delivered', 'delivered_at' => now(), 'shipped_at' => $o->shipped_at ?? now(), 'updated_at' => now()]);
                Orders::event($o->id, 'status', 'Delivered (SMSA tracking).');
                Orders::emailDelivered(DB::table('orders')->find($o->id));
            } elseif ($o && $status === 'moving' && in_array($o->status, ['paid', 'processing'], true)) {
                DB::table('orders')->where('id', $o->id)->update(['status' => 'shipped', 'shipped_at' => now(), 'updated_at' => now()]);
                Orders::event($o->id, 'status', 'Shipped (SMSA has the parcel).');
                Orders::emailShipped(DB::table('orders')->find($o->id));
            }
        }

        return self::shipment((int) $sh->order_id);
    }

    /** SMSA says it was handed to the customer. */
    public static function delivered(array $scans): bool
    {
        foreach ($scans as $sc) {
            $w = strtolower($sc['what']);
            if (preg_match('/\bdelivered\b|proof of delivery/', $w) && ! preg_match('/out for|un-?delivered|not delivered|attempt|fail|return|refus/', $w)) {
                return true;
            }
        }

        return false;
    }

    /** SMSA has the parcel: any scan beyond the waybill's own data being received. */
    public static function moving(array $scans): bool
    {
        foreach ($scans as $sc) {
            $w = strtolower($sc['what']);
            if ($w !== '' && ! preg_match('/data received|information received|shipment created|awb created|booked|label (created|printed)|pending pick/', $w)) {
                return true;
            }
        }

        return false;
    }

    /** What the order page shows about its shipment. */
    public static function view(?object $sh): ?array
    {
        if (! $sh) {
            return null;
        }

        return [
            'awb' => $sh->awb, 'status' => $sh->status, 'mode' => $sh->mode, 'service' => $sh->service, 'error' => $sh->error, 'attempts' => (int) $sh->attempts,
            'dutiesBy' => $sh->duties_by, 'lastScan' => $sh->last_scan, 'lastScanAt' => $sh->last_scan_at, 'checkedAt' => $sh->checked_at,
            'scans' => array_reverse(json_decode((string) ($sh->scans ?? '[]'), true) ?: []),
            'trackUrl' => $sh->awb !== '' ? self::TRACK_URL.rawurlencode($sh->awb) : '',
        ];
    }

    /* ============================================================ the API */

    /** Is the key right? Asks SMSA about a waybill that does not exist: a wrong key is refused, a right one gets "not found". */
    public static function check(string $mode): ?string
    {
        try {
            self::call('GET', '/api/track/single/0000000000', null, $mode);

            return null;
        } catch (ApiError $e) {
            return $e->errCode === 'smsa_not_found' ? null : $e->getMessage();
        }
    }

    /** For the admin's "Test connection": ['connected' => bool, 'message' => …]. */
    public static function test(string $mode): array
    {
        $err = self::check($mode);

        return ['connected' => $err === null, 'message' => $err ?? 'Connected – SMSA accepted the '.($mode === 'live' ? 'live' : 'sandbox').' API key.'];
    }

    public static function call(string $method, string $path, ?array $body = null, ?string $mode = null): mixed
    {
        $mode ??= self::mode();
        $key = self::key($mode);
        if ($key === '') {
            throw new ApiError('smsa_not_set', 'Enter the SMSA API key for '.($mode === 'live' ? 'live' : 'sandbox').' mode in Settings › SMSA shipping first.', 409);
        }
        $base = rtrim((string) self::cfg()[$mode === 'live' ? 'liveUrl' : 'testUrl'], '/');
        $http = Http::withHeaders(['apikey' => $key])->acceptJson()->connectTimeout(10)->timeout(30);
        if ($body !== null) {
            $http = $http->withBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json');
        }
        try {
            $res = $http->send($method, $base.$path);
        } catch (ConnectionException $e) {
            Log::error('smsa: network error', ['path' => $path, 'error' => $e->getMessage()]);
            throw new ApiError('smsa_unreachable', 'SMSA could not be reached. It will be tried again.', 502);
        }
        $data = $res->json();
        if ($res->successful()) {
            return is_array($data) ? $data : [];
        }
        if ($res->status() === 401 || $res->status() === 403) {
            throw new ApiError('smsa_key', 'SMSA refused the API key. Check it in Settings › SMSA shipping (a sandbox key does not work on live, and the other way round).', 422);
        }
        if ($res->status() === 404) {
            throw new ApiError('smsa_not_found', 'SMSA does not know this waybill.', 404);
        }
        $msg = '';
        if (is_array($data['errors'] ?? null)) {
            foreach ($data['errors'] as $field => $list) {
                $msg .= ($msg ? '; ' : '').(is_string($field) ? $field.': ' : '').(is_array($list) ? implode(' ', array_map('strval', $list)) : (string) $list);
            }
        }
        $msg = $msg ?: (string) ($data['message'] ?? $data['Message'] ?? $data['title'] ?? mb_substr(trim(strip_tags($res->body())), 0, 300));
        Log::warning('smsa: refused', ['path' => $path, 'status' => $res->status(), 'message' => $msg]);
        throw new ApiError('smsa_refused', 'SMSA said: '.($msg !== '' ? $msg : 'error '.$res->status()), 422);
    }
}
