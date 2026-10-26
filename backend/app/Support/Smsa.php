<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SMSA Express eCommerce API. One "apikey" header per call; the sandbox and live servers have different keys
 * (a live key is refused by the sandbox). Creating a shipment in live mode books a real, billable parcel.
 * Paths are written exactly as SMSA publishes them (their capitals are inconsistent on purpose).
 */
class Smsa
{
    public const CREATE = '/api/shipment/b2c/new';

    public const CANCEL = '/api/Shipment/b2c/%s/cancel';

    public const TRACK = '/api/track/single/%s';

    public const SERVICES = '/api/lookUp/ServiceTypes';

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

    public static function enabled(): bool
    {
        return (bool) self::cfg()['enabled'] && self::key() !== '';
    }

    public static function request(string $method, string $path, ?array $body = null, ?string $mode = null): mixed
    {
        $mode ??= self::mode();
        $key = self::key($mode);
        if ($key === '') {
            throw new ApiError('smsa_not_set', 'SMSA is not set up: save the API key for '.($mode === 'live' ? 'live' : 'test (sandbox)').' mode in Shipping.', 409);
        }
        $base = rtrim(self::cfg()[$mode === 'live' ? 'liveUrl' : 'testUrl'], '/');
        try {
            $req = Http::withHeaders(['apikey' => $key])->acceptJson()->timeout(45)->connectTimeout(10);
            $res = $body === null ? $req->send($method, $base.$path) : $req->send($method, $base.$path, ['json' => $body]);
        } catch (ConnectionException) {
            throw new ApiError('smsa_unreachable', 'Could not reach SMSA. Try again in a moment.', 502);
        }
        if ($res->status() === 401 || $res->status() === 403) {
            throw new ApiError('smsa_auth', 'SMSA refused the API key (wrong key, or a live key on the sandbox / sandbox key on live).', 502);
        }
        if (! $res->successful()) {
            $j = $res->json();
            $msg = is_array($j) ? ($j['message'] ?? $j['Message'] ?? $j['title'] ?? json_encode($j['errors'] ?? $j, JSON_UNESCAPED_UNICODE)) : trim(strip_tags((string) $res->body()));
            throw new ApiError('smsa_error', 'SMSA: '.mb_substr((string) $msg, 0, 400), 502);
        }

        return $res->json() ?? $res->body();
    }

    /** Checks the key by reading the account's service types. Returns them so the right service code can be picked. */
    public static function test(string $mode): array
    {
        try {
            $list = self::request('GET', self::SERVICES, null, $mode);
            $codes = [];
            foreach ((array) $list as $s) {
                $s = (array) $s;
                $code = $s['serviceCode'] ?? $s['ServiceCode'] ?? $s['code'] ?? null;
                if ($code) {
                    $codes[] = ['code' => (string) $code, 'name' => (string) ($s['serviceName'] ?? $s['ServiceName'] ?? $s['name'] ?? $s['description'] ?? '')];
                }
            }

            return ['connected' => true, 'message' => 'Connected to SMSA ('.($mode === 'live' ? 'live' : 'sandbox').').', 'services' => $codes];
        } catch (ApiError $e) {
            return ['connected' => false, 'message' => $e->getMessage(), 'services' => []];
        }
    }

    /** What SMSA needs about the receiver, checked against SMSA's own length rules. Returns [address, problems]. */
    public static function consignee(object $o): array
    {
        $name = trim($o->first_name.' '.$o->last_name);
        $line1 = trim($o->address1);
        if (mb_strlen($line1) < 10) {
            $line1 = trim(implode(', ', array_filter([$o->address1, $o->state, $o->city])));
        }
        $problems = [];
        if (mb_strlen($name) < 5) {
            $problems[] = 'The customer’s full name must be at least 5 letters for SMSA.';
        }
        if (mb_strlen($line1) < 10) {
            $problems[] = 'The address must be at least 10 characters for SMSA.';
        }
        if (mb_strlen(trim($o->city)) < 3) {
            $problems[] = 'The city must be at least 3 letters for SMSA.';
        }
        [$cc, $num] = Shipping::splitPhone($o->phone, $o->country);

        return [[
            'ContactName' => mb_substr($name, 0, 150),
            'ContactPhoneNumber' => $cc.$num,
            'Country' => $o->country,
            'City' => mb_substr(trim($o->city), 0, 50),
            'AddressLine1' => mb_substr($line1, 0, 100),
            'AddressLine2' => mb_substr(trim($o->address2), 0, 100),
            'District' => mb_substr(trim($o->state), 0, 100),
            'PostalCode' => '',
        ], $problems];
    }

    public static function shipper(): array
    {
        $s = self::cfg()['shipper'];
        [$cc, $num] = Shipping::splitPhone((string) $s['phone'], (string) $s['country']);

        return [
            'ContactName' => (string) $s['name'], 'ContactPhoneNumber' => $cc.$num, 'Country' => (string) $s['country'],
            'City' => (string) $s['city'], 'AddressLine1' => (string) $s['address1'], 'AddressLine2' => (string) $s['address2'],
            'District' => (string) $s['district'], 'PostalCode' => (string) $s['postalCode'],
        ];
    }

    public static function shipperProblems(): array
    {
        $s = self::cfg()['shipper'];
        $p = [];
        if (mb_strlen(trim((string) $s['name'])) < 5) {
            $p[] = 'Shipping › SMSA: the sender name must be at least 5 letters.';
        }
        if (mb_strlen(trim((string) $s['address1'])) < 10) {
            $p[] = 'Shipping › SMSA: the sender address must be at least 10 characters.';
        }
        if (mb_strlen(trim((string) $s['city'])) < 3) {
            $p[] = 'Shipping › SMSA: enter the sender city.';
        }
        if (strlen(preg_replace('/\D/', '', (string) $s['phone'])) < 8) {
            $p[] = 'Shipping › SMSA: enter the sender phone number.';
        }

        return $p;
    }

    /** Books the shipment. Returns ['awb' => …, 'sawb' => …, 'pdf' => binary|null]. */
    public static function createShipment(object $order, int $parcels = 1, ?float $weightKg = null): array
    {
        $cfg = self::cfg();
        [$consignee] = self::consignee($order);
        $declCur = $cfg['declaredCurrency'] ?: 'BHD';
        $declared = $declCur === 'BHD' ? (float) $order->subtotal : Currency::fromBase((float) $order->subtotal, $declCur);
        $body = [
            'ConsigneeAddress' => $consignee,
            'ShipperAddress' => self::shipper(),
            'OrderNumber' => (string) $order->number,
            'DeclaredValue' => round($declared, 2),
            'CODAmount' => 0,
            'Parcels' => max(1, min(25, $parcels)),
            'ShipDate' => now('Asia/Riyadh')->format('Y-m-d\TH:i:s'),
            'ShipmentCurrency' => $declCur,
            'Weight' => round(max(0.1, $weightKg ?? ($order->weight_grams / 1000)), 2),
            'WeightUnit' => 'KG',
            'ContentDescription' => (string) ($cfg['contentDescription'] ?: 'Sweets'),
            'WaybillType' => 'PDF',
            'VatPaid' => true,
            'DutyPaid' => false,
        ];
        if (($cfg['serviceCode'] ?? '') !== '') {
            $body['ServiceCode'] = $cfg['serviceCode'];
        }
        if (($cfg['retailId'] ?? '') !== '') {
            $body['SMSARetailID'] = $cfg['retailId'];
        }
        $res = (array) self::request('POST', self::CREATE, $body);
        $wb = (array) (($res['waybills'] ?? [])[0] ?? []);
        $awb = (string) ($wb['awb'] ?? $res['sawb'] ?? '');
        if ($awb === '') {
            throw new ApiError('smsa_error', 'SMSA did not return a waybill number.', 502);
        }
        $pdf = ($wb['awbFile'] ?? '') !== '' ? base64_decode((string) $wb['awbFile'], true) : null;

        return ['awb' => $awb, 'sawb' => (string) ($res['sawb'] ?? ''), 'pdf' => $pdf ?: null];
    }

    /** Latest scan: ['status' => text, 'delivered' => bool, 'scans' => [...]]. */
    public static function track(string $awb, ?string $mode = null): array
    {
        $r = (array) self::request('GET', sprintf(self::TRACK, rawurlencode($awb)), null, $mode);
        $scans = array_map(fn ($s) => [
            'code' => (string) (((array) $s)['ScanType'] ?? ''), 'text' => (string) (((array) $s)['ScanDescription'] ?? ''),
            'at' => (string) (((array) $s)['ScanDateTime'] ?? ''), 'city' => (string) (((array) $s)['City'] ?? ''),
        ], (array) ($r['Scans'] ?? []));
        $latest = $scans[0] ?? null; // SMSA lists the newest first

        return ['status' => $latest ? trim($latest['text'].($latest['city'] ? ' – '.$latest['city'] : '')) : 'No scans yet',
            'delivered' => ($r['isDelivered'] ?? false) === true, 'scans' => $scans];
    }

    public static function cancel(string $awb): void
    {
        self::request('PATCH', sprintf(self::CANCEL, rawurlencode($awb)));
    }
}
