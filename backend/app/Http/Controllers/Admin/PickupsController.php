<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Smsa;
use App\Support\SmsaPickups;
use Illuminate\Http\Request;

/** SMSA pickups for the whole shop: one button at the top of the orders list (admin) and the orders board (/staff). */
class PickupsController extends Controller
{
    /** GET – days and times on offer, the waybills waiting for the courier, open bookings, how requests reach SMSA. */
    public function form()
    {
        $waiting = SmsaPickups::waiting();
        $c = SmsaPickups::cfg();

        return $this->ok(SmsaPickups::options() + [
            'waiting' => $waiting, 'open' => SmsaPickups::open(), 'mode' => Smsa::mode(), 'configured' => Smsa::configured(),
            'email' => SmsaPickups::emails(), 'whatsapp' => $c['whatsapp'],
        ]);
    }

    /** GET – open bookings (for the banner above the list). */
    public function index()
    {
        return $this->ok(['open' => SmsaPickups::open(), 'waiting' => count(SmsaPickups::waiting())]);
    }

    /** {date, from, until, orderIds[] (empty = all waiting), parcels?, note, via} */
    public function store(Request $r)
    {
        $res = SmsaPickups::book((array) $r->input('orderIds', []), $this->str($r, 'date', 10), $this->str($r, 'from', 5), $this->str($r, 'until', 5),
            $this->str($r, 'note', 300), $this->str($r, 'via', 10), $this->admin($r), max(0, (int) $r->input('parcels', 0)));
        Audit::log($r, 'smsa_pickup_booked', 'smsa_pickup', $res['pickup']->id, ['date' => $res['pickup']->pickup_date, 'via' => $res['pickup']->sent_via]);

        return $this->ok(['pickup' => SmsaPickups::present($res['pickup']), 'text' => $res['text'], 'whatsappUrl' => $res['whatsappUrl'], 'emailed' => $res['emailed']]);
    }

    public function cancel(Request $r, int $id)
    {
        $p = SmsaPickups::cancel($id, $this->admin($r));
        Audit::log($r, 'smsa_pickup_cancelled', 'smsa_pickup', $id);

        return $this->ok(['pickup' => SmsaPickups::present($p)]);
    }
}
