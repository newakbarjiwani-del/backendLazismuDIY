<?php

namespace App\Http\Controllers;

use App\Helpers\JWT;
use App\Helpers\Logger;
use App\Models\Billing;
use App\Models\Campaign;
use App\Models\Infak;
use App\Models\Wakaf;
use App\Models\Zakat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Midtrans\Config;
use Midtrans\Snap;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    /**
     * Display a listing of the resource.
     */


    public function index(Request $request)
    {
        $success = $request->query('success');
        $category = $request->query('category');
    
        $query = Billing::with(['campaign', 'zakat', 'infak', 'wakaf']);
    
        if (!is_null($success)) {
            $query->where('success', filter_var($success, FILTER_VALIDATE_BOOLEAN));
        }
    
        if (!is_null($category)) {
            $query->where('category', $category);
        }

        $query->orderBy('billing_date', 'desc');
    
        $billings = $query->paginate(20);
    
        return response()->json($billings);
    }
    
    /**
     * Store a newly created resource in storage.
     */
    public function createBilling(Request $request, $categoryType, $id)
{
        // Config::$serverKey = ;
        Config::$serverKey = config('services.midtrans2.server_key');
        // Config::$clientKey = 'Mid-client-U045eqp4HnH7Vw1o'; // optional
        Config::$clientKey = config('services.midtrans2.client_key'); 
        Config::$isProduction = true;
        Config::$isSanitized = true;
        Config::$is3ds = true;
    
    $request->validate([
        'amount' => 'required|numeric',
        'username' => 'required|string|max:20',
        'phone_number' => 'required|string|max:15',
        'message' => 'nullable|string|max:255',
    ]);

    $billing = new Billing();
    $billing->billing_amount = $request->input('amount');
    $billing->username = $request->input('username');
    $billing->phone_number = $request->input('phone_number');
    $billing->message = $request->input('message');
    $billing->billing_date = now();
    $billing->category = $categoryType;
    $billing->method = 'ONLINE';
    $billing->success = false;
    $billing->created_time = substr((string) intval(microtime(true) * 1000), -8);

    $authHeader = $request->header('Authorization');
    $userId = null;

    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
        $key = env('JWT_SECRET', 'your-secret-key');

        try {
            $decoded = JWT::decode($token, $key, ['HS256']);
            $userId = $decoded->sub; 
        } catch (\Exception $e) {
            $userId = null;
        }
    }

    $billing->user_id = $userId;

    $vaNumber = '797755' . rand(1000000000, 9999999999);
    $billing->va_number = (int) $vaNumber;

    switch ($categoryType) {
        case 'campaign':
            $campaign = Campaign::where('id', $id)->first();
            if (!$campaign) {
                return response()->json(['error' => 'Campaign not found with id: ' . $id], 404);
            }
            $billing->campaign_id = $campaign->id;
            break;

        case 'zakat':
            $zakat = Zakat::where('id', $id)->first();
            if (!$zakat) {
                return response()->json(['error' => 'Zakat not found with id: ' . $id], 404);
            }
            $billing->zakat_id = $zakat->id;
            break;

        case 'infak':
            $infak = Infak::where('id', $id)->first();
            if (!$infak) {
                return response()->json(['error' => 'Infak not found with id: ' . $id], 404);
            }
            $billing->infak_id = $infak->id;
            break;

        case 'wakaf':
            $wakaf = Wakaf::where('id', $id)->first();
            if (!$wakaf) {
                return response()->json(['error' => 'Wakaf not found with id: ' . $id], 404);
            }
            $billing->wakaf_id = $wakaf->id;
            break;

        default:
            return response()->json(['error' => 'Invalid transaction type: ' . $categoryType], 400);
    }

    do {
        $invoiceId = 'INV-' . now()->format('ymd') . strtoupper(Str::random(5));
    } while (DB::table('transactions')->where('invoice_id', $invoiceId)->exists());

    $billing->order_id_midtrans = $invoiceId;
    $billing->save();


    $params = [
        'transaction_details' => [
            'order_id' => $invoiceId,
            'gross_amount' => (int) $billing->billing_amount,
        ],
        'customer_details' => [
            'first_name' => $billing->username,
            'phone' => $billing->phone_number,
        ],
        'item_details' => [[
            'id' => 'donasi',
            'price' => (int) $billing->billing_amount,
            'quantity' => 1,
            'name' => ucfirst($billing->category) . ' Donation'
        ]]
    ];

    $snapToken = \Midtrans\Snap::getSnapToken($params);
    $data = $billing->toArray();
    $data['snap_token'] = $snapToken;

    return response()->json($data);

}

public function sendReminder($id)
{
    $billing = Billing::findOrFail($id);
    $phone = $billing->phone_number;
    $amount = number_format($billing->billing_amount, 0, ',', '.');
    $campaignName = null;

    if ($billing->campaign_id) {
        $campaign = DB::table('campaigns')->where('id', $billing->campaign_id)->first();
        $campaignName = $campaign->campaign_name ?? 'Donasi';
    } elseif ($billing->wakaf_id) {
        $wakaf = DB::table('wakafs')->where('id', $billing->wakaf_id)->first();
        $campaignName = $wakaf->category_name ?? 'Wakaf';
    } elseif ($billing->zakat_id) {
        $zakat = DB::table('zakats')->where('id', $billing->zakat_id)->first();
        $campaignName = $zakat->category_name ?? 'Zakat';
    } elseif ($billing->infak_id) {
        $infak = DB::table('infaks')->where('id', $billing->infak_id)->first();
        $campaignName = $infak->category_name ?? 'Infak';
    }

    $message = "Assalamu’alaikum warahmatullahi wabarakatuh\n\n"
        . "Yth. Bpk/Ibu {$billing->username}\n\n"
        . "Terima kasih telah mempercayakan donasi *$campaignName* melalui platform Jalankebaikan.id by Lazismu DIY dengan total *Rp $amount*.\n\n"
        . "Kami ingin memastikan apakah ada kendala dalam proses pembayaran? Jika ada yang bisa kami bantu, jangan ragu untuk menghubungi kami.\n\n"
        . "Semoga kebaikan ini membawa keberkahan untuk Bapak/Ibu. 🤲🏻\n\n"
        . "Salam,\nAmil Lazismu Daerah Istimewa Yogyakarta";

    $response = $this->sendWhatsApp($phone, $message);

    return response()->json(['message' => 'Success', 'response' => $response]);
}

    /**
     * Helper function to send WhatsApp message.
     */
    private function sendWhatsApp($phone, $message)
    {
        $url = 'https://api.wanotif.id/v1/send';
        $apikey = 'IwfhE7DJ4jNnyQFXqC1MDHpDwrMfbWl2';

        $phone = preg_replace('/^(\+62|62|0)/', '62', $phone);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, [
            'Apikey' => $apikey,
            'Phone' => $phone,
            'Message' => $message,
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        Logger::log('WhatsApp Notification', ['Apikey' => $apikey, 'phone' => $phone, 'message' => $message], $response);

        return $response;
    }

    

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

//     public function createBillingFLIP(Request $request, $categoryType, $id)
// {
//     $request->validate([
//         'amount' => 'required|numeric',
//         'username' => 'required|string|max:20',
//         'phone_number' => 'required|string|max:15',
//         'bank' => 'required|string|max:10',
//         'message' => 'nullable|string|max:255',
//     ]);

//     $billing = new Billing();
//     $billing->billing_amount = $request->input('amount');
//     $billing->username = $request->input('username');
//     $billing->phone_number = $request->input('phone_number');
//     $billing->message = $request->input('message');
//     $billing->billing_date = now();
//     $billing->category = $categoryType;
//     $billing->method = 'ONLINE';
//     $billing->success = false;
//     $billing->created_time = substr((string) intval(microtime(true) * 1000), -8);

//     $authHeader = $request->header('Authorization');
//     $userId = null;
//     if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
//         $token = $matches[1];
//         $key = env('JWT_SECRET', 'your-secret-key');
//         try {
//             $decoded = JWT::decode($token, $key, ['HS256']);
//             $userId = $decoded->sub;
//         } catch (\Exception $e) {
//             $userId = null;
//         }
//     }
//     $billing->user_id = $userId;

//     $vaNumber = '797755' . rand(1000000000, 9999999999);
//     $billing->va_number = (int) $vaNumber;

//     switch ($categoryType) {
//         case 'campaign':
//             $campaign = Campaign::find($id);
//             if (!$campaign) return response()->json(['error' => 'Campaign not found.'], 404);
//             $billing->campaign_id = $campaign->id;
//             break;
//         case 'zakat':
//             $zakat = Zakat::find($id);
//             if (!$zakat) return response()->json(['error' => 'Zakat not found.'], 404);
//             $billing->zakat_id = $zakat->id;
//             break;
//         case 'infak':
//             $infak = Infak::find($id);
//             if (!$infak) return response()->json(['error' => 'Infak not found.'], 404);
//             $billing->infak_id = $infak->id;
//             break;
//         case 'wakaf':
//             $wakaf = Wakaf::find($id);
//             if (!$wakaf) return response()->json(['error' => 'Wakaf not found.'], 404);
//             $billing->wakaf_id = $wakaf->id;
//             break;
//         default:
//             return response()->json(['error' => 'Invalid category type.'], 400);
//     }

//     do {
//         $invoiceId = 'INV-' . now()->format('ymd') . strtoupper(Str::random(5));
//     } while (DB::table('transactions')->where('invoice_id', $invoiceId)->exists());

//     $billing->order_id_midtrans = $invoiceId;
//     $billing->save();

//     $payload = [
//         "id_tagihan" => $billing->id,
//         "nama_tagihan" => strtoupper($categoryType) . " " . now()->format('F'),
//         "tahun_akademik" => "2024/2025 - GENAP",
//         "periode" => "202501",
//         "nominal" => (string) $billing->billing_amount,
//         "bank" => strtolower($request->input('bank')),
//         "vano" => (string) $billing->va_number,
//         "nama" => $billing->username,
//         "token" => md5($billing->id . 'flip-secret')
//     ];

//     try {
//         $client = new \GuzzleHttp\Client();
//         $response = $client->post('http://10.99.23.111/WS_FLIP/wsFlip.php', [
//             'headers' => ['Content-Type' => 'application/json'],
//             'json' => $payload
//         ]);
//         $flipResponse = json_decode($response->getBody(), true);
//     } catch (\Exception $e) {
//         return response()->json([
//             'message' => 'Billing created but failed to send to Flip',
//             'error' => $e->getMessage(),
//             'billing' => $billing
//         ], 500);
//     }

//     return response()->json([
//         'message' => 'Billing created and sent to Flip',
//         'billing' => $billing,
//         'flip_response' => $flipResponse ?? null
//     ]);
// }


   public function createBCABilling(Request $request, $categoryType, $id)
{
        // Config::$serverKey = ;
        // Config::$clientKey = ; // optional
        // Config::$isProduction = true;
        // Config::$isSanitized = true;
        // Config::$is3ds = true;
    
    $request->validate([
        'amount' => 'required|numeric',
        'username' => 'required|string|max:20',
        'phone_number' => 'required|string|max:15',
        'message' => 'nullable|string|max:255',
    ]);

    $billing = new Billing();
    $billing->billing_amount = $request->input('amount');
    $billing->username = $request->input('username');
    $billing->phone_number = $request->input('phone_number');
    $billing->message = $request->input('message');
    $billing->billing_date = now();
    $billing->category = $categoryType;
    $billing->method = 'ONLINE';
    $billing->success = false;
    $billing->created_time = substr((string) intval(microtime(true) * 1000), -8);

    $authHeader = $request->header('Authorization');
    $userId = null;

    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
        $key = env('JWT_SECRET', 'your-secret-key');

        try {
            $decoded = JWT::decode($token, $key, ['HS256']);
            $userId = $decoded->sub; 
        } catch (\Exception $e) {
            $userId = null;
        }
    }

    $billing->user_id = $userId;

    $vaNumber = '01912' . rand(1000000000, 9999999999);
    $billing->va_number = $vaNumber;

    switch ($categoryType) {
        case 'campaign':
            $campaign = Campaign::where('id', $id)->first();
            if (!$campaign) {
                return response()->json(['error' => 'Campaign not found with id: ' . $id], 404);
            }
            $billing->campaign_id = $campaign->id;
            break;

        case 'zakat':
            $zakat = Zakat::where('id', $id)->first();
            if (!$zakat) {
                return response()->json(['error' => 'Zakat not found with id: ' . $id], 404);
            }
            $billing->zakat_id = $zakat->id;
            break;

        case 'infak':
            $infak = Infak::where('id', $id)->first();
            if (!$infak) {
                return response()->json(['error' => 'Infak not found with id: ' . $id], 404);
            }
            $billing->infak_id = $infak->id;
            break;

        case 'wakaf':
            $wakaf = Wakaf::where('id', $id)->first();
            if (!$wakaf) {
                return response()->json(['error' => 'Wakaf not found with id: ' . $id], 404);
            }
            $billing->wakaf_id = $wakaf->id;
            break;

        default:
            return response()->json(['error' => 'Invalid transaction type: ' . $categoryType], 400);
    }

    do {
        $invoiceId = 'INV-' . now()->format('ymd') . strtoupper(Str::random(5));
    } while (DB::table('transactions')->where('invoice_id', $invoiceId)->exists());

    // $billing->order_id_midtrans = $invoiceId;
    $billing->save();


    // $params = [
    //     'transaction_details' => [
    //         'order_id' => $invoiceId,
    //         'gross_amount' => (int) $billing->billing_amount,
    //     ],
    //     'customer_details' => [
    //         'first_name' => $billing->username,
    //         'phone' => $billing->phone_number,
    //     ],
    //     'item_details' => [[
    //         'id' => 'donasi',
    //         'price' => (int) $billing->billing_amount,
    //         'quantity' => 1,
    //         'name' => ucfirst($billing->category) . ' Donation'
    //     ]]
    // ];

    // $snapToken = \Midtrans\Snap::getSnapToken($params);
    $data = $billing->toArray();
    // $data['snap_token'] = $snapToken;

    return response()->json($data);

}

}

