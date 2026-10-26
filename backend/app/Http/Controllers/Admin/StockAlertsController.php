<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\StockAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Who is waiting for a product (staff); mark a WhatsApp message as sent or remove a request (managers). */
class StockAlertsController extends Controller
{
    public function index(int $id)
    {
        $p = DB::table('products')->find($id) ?? throw ApiError::notFound('That product does not exist any more.');
        $rows = DB::table('stock_alerts')->where('product_id', $id)->orderByRaw('notified_at IS NOT NULL')->orderByDesc('created_at')->limit(500)->get();

        return $this->ok(['alerts' => $rows->map(fn ($a) => [
            'id' => $a->id, 'email' => $a->email, 'phone' => $a->phone, 'lang' => $a->lang, 'country' => $a->country,
            'createdAt' => $a->created_at, 'notifiedAt' => $a->notified_at, 'notifiedBy' => $a->notified_by,
            'whatsapp' => $a->phone ? 'https://wa.me/'.$a->phone.'?text='.rawurlencode(StockAlerts::whatsappText($p, $a->lang)) : null,
        ])->values()]);
    }

    public function done(Request $r, int $id)
    {
        $a = DB::table('stock_alerts')->find($id) ?? throw ApiError::notFound('That request is not there any more.');
        DB::table('stock_alerts')->where('id', $id)->update(['notified_at' => now(), 'notified_by' => 'whatsapp']);
        Audit::log($r, 'stock_alert_done', 'product', $a->product_id, ['alert' => $id]);

        return $this->ok();
    }

    public function destroy(Request $r, int $id)
    {
        $a = DB::table('stock_alerts')->find($id) ?? throw ApiError::notFound('That request is not there any more.');
        DB::table('stock_alerts')->where('id', $id)->delete();
        Audit::log($r, 'stock_alert_deleted', 'product', $a->product_id, ['alert' => $id]);

        return $this->ok();
    }
}
