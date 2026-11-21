<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Smsa;
use App\Support\SmsaPickups;
use Illuminate\Http\Request;

/**
 * SMSA pickups for the whole shop: one button at the top of the orders list (admin) and the orders board (/staff).
 * SMSA's API cannot book a collection, so the booking is made in SMSA's dashboard with the answers given here.
 */
class PickupsController extends Controller
{
    /** GET – days and times on offer, the waybills waiting for the courier, open bookings. */
    public function form()
    {
        $c = SmsaPickups::cfg();

        return $this->ok(SmsaPickups::options() + [
            'waiting' => SmsaPickups::waiting(), 'open' => SmsaPickups::open(), 'mode' => Smsa::mode(), 'configured' => Smsa::configured(),
            'heavyKg' => $c['heavyKg'], 'dashboardUrl' => $c['dashboardUrl'],
        ]);
    }

    /** GET – open bookings (for the banner above the list). */
    public function index()
    {
        return $this->ok(['open' => SmsaPickups::open(), 'waiting' => count(SmsaPickups::waiting())]);
    }

    /** {date, from (ready time), until (closing time), orderIds[] (empty = all waiting), parcels?, heavy?, note} */
    public function store(Request $r)
    {
        $p = SmsaPickups::book((array) $r->input('orderIds', []), $this->str($r, 'date', 10), $this->str($r, 'from', 5), $this->str($r, 'until', 5),
            $this->str($r, 'note', 300), $this->admin($r), max(0, (int) $r->input('parcels', 0)), $r->has('heavy') ? $this->bool($r, 'heavy') : null);
        Audit::log($r, 'smsa_pickup_booked', 'smsa_pickup', $p->id, ['date' => $p->pickup_date]);

        return $this->ok(['pickup' => SmsaPickups::present($p)]);
    }

    /** {ref} – SMSA's booking number, pasted from their dashboard. */
    public function ref(Request $r, int $id)
    {
        $p = SmsaPickups::setRef($id, $this->str($r, 'ref', 60), $this->admin($r));
        Audit::log($r, 'smsa_pickup_ref', 'smsa_pickup', $id, ['ref' => $p->smsa_ref]);

        return $this->ok(['pickup' => SmsaPickups::present($p)]);
    }

    public function cancel(Request $r, int $id)
    {
        $p = SmsaPickups::cancel($id, $this->admin($r));
        Audit::log($r, 'smsa_pickup_cancelled', 'smsa_pickup', $id);

        return $this->ok(['pickup' => SmsaPickups::present($p)]);
    }
}
