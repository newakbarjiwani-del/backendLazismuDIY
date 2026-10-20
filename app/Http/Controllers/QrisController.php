<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Helpers\JWT;
use App\Helpers\Logger;
use Exception;
use Illuminate\Support\Str;

class QrisController extends Controller
{
    public function generate(Request $request)
    {
        $createdTime = $request->query('createdTime');
    
        if (is_null($createdTime) || $createdTime <= 0) {
            return response()->json(['success' => false, 'message' => 'createdTime tidak valid']);
        }
    
        $row = DB::table('billings')->where('created_time', $createdTime)->first();
    
        if ($row) {
            $data = [
                "accountNo" => "5320017203",
                "amount" => strval($row->billing_amount),
                "mitraCustomerId" => "LAZIZMU DIY274029",
                "transactionId" => strval($row->created_time),
                "tipeTransaksi" => "MTR-GENERATE-QRIS-DYNAMIC",
                "vano" => strval($row->va_number)
            ];
    
            $secretKey = 'TokenJWT_BMI_ICT';
            $jwtToken = JWT::encode($data, $secretKey);
    
            $url = 'http://10.99.23.111/qris/lazizmu_diy/server.php?token=' . urlencode($jwtToken);

            //http://10.99.23.111/qris/SEMARANG_WALISONGO/server.php?token=
    
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);

            $response = curl_exec($ch);
    
            if (curl_errno($ch)) {
                $error = curl_error($ch);
                curl_close($ch);
                Logger::log('Generate QRIS', $data, null, $error); // Log error
                return response()->json(['success' => false, 'message' => 'cURL Error: ' . $error]);
            }
    
            curl_close($ch);
    
            $responseData = json_decode($response, true);
            Logger::log('Generate QRIS', $data, $responseData, null); // Log sukses
    
            if (isset($responseData['transactionDetail']['transactionQrId'])) {
                $transactionQrId = $responseData['transactionDetail']['transactionQrId'];
                DB::table('billings')->where('created_time', $createdTime)->update(['transaction_qr_id' => $transactionQrId]);
    
                $responseData['transactionQrId'] = $transactionQrId;
            } else {
                return response()->json(['success' => false, 'message' => 'Transaction QR ID tidak ditemukan dalam response']);
            }
    
            return response()->json($responseData);
        }
    
        return response()->json(['success' => false, 'message' => 'Data tidak ditemukan']);
    }

    public function checkStatus(Request $request)
    {
        $createdTime = $request->query('createdTime');
    
        if (is_null($createdTime) || $createdTime <= 0) {
            return response()->json(['success' => false, 'message' => 'createdTime tidak valid']);
        }
    
        $row = DB::table('billings')->where('created_time', $createdTime)->first();
    
        if ($row) {
            $dataCheckStatus = [
                "accountNo" => "5320017203",
                "amount" => strval($row->billing_amount),
                "merchantId" => "839853200172032",
                "mitraCustomerId" => "LAZIZMU DIY274029",
                "transactionId" => strval($row->created_time),
                "transactionQrId" => $row->transaction_qr_id,
                "tipeTransaksi" => "MTR-CHECK-STATUS"
            ];
    
            $secretKey = 'TokenJWT_BMI_ICT';
            $jwtTokenCheckStatus = JWT::encode($dataCheckStatus, $secretKey);
    
            $url = 'http://10.99.23.111/qris/lazizmu_diy/server.php?token=' . urlencode($jwtTokenCheckStatus);
    
            $chCheckStatus = curl_init($url);
            curl_setopt($chCheckStatus, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chCheckStatus, CURLOPT_POST, true);
            curl_setopt($chCheckStatus, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);

            $responseCheckStatus = curl_exec($chCheckStatus);

            if (curl_errno($chCheckStatus)) {
                $error = curl_error($chCheckStatus);
                curl_close($chCheckStatus);
                Logger::log('Check Status Qris', $dataCheckStatus, null, $error); // Log sukses
                return response()->json(['success' => false, 'message' => 'cURL Error: ' . $error]);
            }
    
            curl_close($chCheckStatus);
    
            // Decode respons
            $responseDataCheckStatus = json_decode($responseCheckStatus, true);
            Logger::log('Check Status QRIS', $dataCheckStatus, $responseDataCheckStatus, 'success'); // Log sukses
    
            return response()->json($responseDataCheckStatus);
        }
    
        return response()->json(['success' => false, 'message' => 'Data tidak ditemukan']);
    }

    public function pushNotification(Request $request)
{
    $token = $request->input('token');

    if (empty($token)) {
        Logger::log('Push Notification', $request->all(), null, 'Token tidak ditemukan');
        return response()->json([
            'responseCode' => '01',
            'responseMessage' => 'Token tidak ditemukan',
            'responseTimestamp' => now()
        ]);
    }

    $secretKey = 'TokenJWT_BMI_ICT';

    try {
        $decoded = JWT::decode($token, $secretKey, ['HS256']);

        $responseCode = $decoded->responseCode;
        $responseMessage = $decoded->responseMessage;
        $responseTimestamp = $decoded->responseTimestamp;
        $transactionId = $decoded->transactionId;
        $data = $decoded->data;

        if ($responseCode === '00') {
            $vano = $data->vano1;
            $amount = $data->amount;
            $accountNo = $data->accountNo;
            $transactionQrId = $data->transactionQrId;
            $description = $data->description;

            $billing = DB::table('billings')->where('transaction_qr_id', $transactionQrId)->first();

            if ($billing) {
                $campaignId = $billing->campaign_id;
                $wakafId = $billing->wakaf_id;
                $zakatId = $billing->zakat_id;
                $infakId = $billing->infak_id;

                DB::table('billings')->where('transaction_qr_id', $transactionQrId)->update(['success' => 1]);

                do {
                    $invoiceId = 'INV-' . now()->format('ymd') . strtoupper(Str::random(5));
                } while (DB::table('transactions')->where('invoice_id', $invoiceId)->exists());

                $for_ict = ($amount < 100000) ? $amount * 0.025 : 3000;

                DB::table('transactions')->insert([
                    'invoice_id' => $invoiceId,
                    'donatur' => $billing->username,
                    'phone_number' => $billing->phone_number,
                    'email' => null,
                    'transaction_amount' => $amount,
                    'for_ict' => $for_ict,
                    'message' => $billing->message,
                    'transaction_date' => now(),
                    'channel' => 'ONLINE',
                    'va_number' => $vano,
                    'method' => 'QRIS',
                    'transaction_qr_id' => $transactionQrId,
                    'created_time' => $billing->created_time,
                    'category'=> $billing->category,
                    'success' => 1,
                    'user_id' => $billing->user_id,
                    'campaign_id' => $campaignId ?? null,
                    'fundraiser_id' => ($billing->category === 'campaign') ? ($billing->fundraiser_id ?? null) : null,
                    'wakaf_id' => $wakafId ?? null,
                    'zakat_id' => $zakatId ?? null,
                    'infak_id' => $infakId ?? null,
                    'asal_transaksi' => 'QRIS ICT'
                ]);

                $campaignName = null;
                if ($campaignId) {
                    $campaign = DB::table('campaigns')->where('id', $campaignId)->first();
                    $campaignName = $campaign->campaign_name;
                    DB::table('campaigns')->where('id', $campaignId)
                        ->increment('current_amount', $billing->billing_amount);
                }

                if ($wakafId) {
                    $wakaf = DB::table('wakafs')->where('id', $wakafId)->first();
                    $campaignName = $wakaf->category_name;
                    DB::table('wakafs')->where('id', $wakafId)
                        ->increment('amount', $billing->billing_amount);
                }

                if ($zakatId) {
                    $zakat = DB::table('zakats')->where('id', $zakatId)->first();
                    $campaignName = $zakat->category_name;
                    DB::table('zakats')->where('id', $zakatId)
                        ->increment('amount', $billing->billing_amount);
                }

                if ($infakId) {
                    $infak = DB::table('infaks')->where('id', $infakId)->first();
                    $campaignName = $infak->category_name;
                    DB::table('infaks')->where('id', $infakId)
                        ->increment('amount', $billing->billing_amount);
                }

                // log traffic
                $servernamelog = '10.99.23.20';
                $usernamelog = 'root';
                $passwordlog = 'Smartpay1ct';
                $databaselog = 'farrelep_broadcaster';
                
                $dbTraffic = mysqli_connect($servernamelog, $usernamelog, $passwordlog, $databaselog);
                    if ($dbTraffic->connect_errno) {
                        echo json_encode("Failed to connect to MySQL: " . $dbTraffic->connect_error);
                        exit();
                    }
                
                $CUSTNM = 'DIY_LAZISMU_QRIS_BMI';
                $NOMINALFee1 = (int) round($amount * 0.007, 0, PHP_ROUND_HALF_UP); // Biaya QRIS
                $NOMINALFee2 = (int) $for_ict; // Biaya Admin ICT
                $GetValue = (string) ($amount + $NOMINALFee1 + $NOMINALFee2); // Nominal Gabungan
                $accountNoLog = '5320017203'; // Account No QRIS
                $mitraCustomerId = 'LAZIZMU DIY274029'; // Mitra ID
                $vanoLog = $vano ?? '-';
                $transactionIdLog = $data->transactionId;
                $transactionQrIdLog = $transactionQrId;
                
                $queryLog = "CALL LogPaymentQR ('" . $CUSTNM . "', '" . $mitraCustomerId . "' , '" . $accountNoLog . "' , '" . $transactionIdLog . "', '" . $transactionId . "' , '" . $transactionQrIdLog . "' , '" . $vanoLog . "' , '" . $GetValue . "' ,'" . $amount . "' ,'" . $NOMINALFee1 . "' ,'" . $NOMINALFee2 . "' ,'" . $token . "','-')";
                $dbTraffic->query($queryLog);


                $latestTransaction = DB::table('transactions')
                ->where('transaction_qr_id', $transactionQrId)
                ->orderBy('transaction_id', 'desc') 
                ->first();
            
                $invoiceIds = $latestTransaction->invoice_id;

                // Format pesan WhatsApp
                $message = "*ZAKAT/INFAK ANDA BERHASIL*\n" .
                    "*$invoiceIds*\n" .
                    "Telah terima dari Bpk/Ibu *{$billing->username}*\n" .
                    "Sebesar *Rp " . number_format($amount, 0, ',', '.') . "*\n" .
                    "Untuk Pembayaran *$campaignName*\n\n" .
                    "Yogyakarta, " . now()->format('d F Y - H:i') . "\n" .
                    "TTD\n" .
                    "Admin Jalan Kebaikan by Lazismu Daerah Istimewa Yogyakarta\n\n" .
                    "Terimakasih \n" .
                    "Kepada Yth Bpk/Ibu {$billing->username} atas kepercayaan anda.\n\n" .
                    "أَجَرَكَ اللهُ فِيْمَا أَعْطَيْتَ, وَبَارَكَ لَكَ فِيْمَا أَبْقَيْتَ, وَاجْعَلْهُ لَكَ طَهُوْرًا\n\n" .
                    "\"Mudah-mudahan Allah memberi pahala atas apa yang engkau berikan, memberikan berkah atas apa yang masih ada di tanganmu dan menjadikannya sebagai pembersih bagimu.”";

                // Kirim pesan WhatsApp
                $this->sendWhatsApp($billing->phone_number, $message);

                Logger::log('Push Notification', $request->all(), [
                    'responseCode' => '00',
                    'responseMessage' => 'TRANSACTION SUCCESS',
                    'transactionId' => $transactionId
                ]);

                return response()->json([
                    'responseCode' => '00',
                    'responseMessage' => 'TRANSACTION SUCCESS',
                    'responseTimestamp' => now(),
                    'transactionId' => $transactionId
                ]);
            }

            Logger::log('Push Notification', $request->all(), null, 'Billing data not found');
            return response()->json([
                'responseCode' => '01',
                'responseMessage' => 'Billing data not found',
                'responseTimestamp' => now()
            ]);
        } else {
            Logger::log('Push Notification', $request->all(), null, $responseMessage);
            return response()->json([
                'responseCode' => '01',
                'responseMessage' => $responseMessage,
                'responseTimestamp' => now()
            ]);
        }
    } catch (Exception $e) {
        Logger::log('Push Notification', $request->all(), null, $e->getMessage());
        return response()->json([
            'responseCode' => '01',
            'responseMessage' => 'Invalid token or data: ' . $e->getMessage(),
            'responseTimestamp' => now()
        ]);
    }
}

private function sendWhatsApp($phone, $message)
{
    $url = 'https://api.wanotif.id/v1/send';
    $apikey = 'IwfhE7DJ4jNnyQFXqC1MDHpDwrMfbWl2';

    $phone = preg_replace('/^(\+62|62|0)/', '62', $phone);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, [
        'Apikey' => $apikey,
        'Phone' => $phone,
        'Message' => $message,
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    Logger::log('WhatsApp Notification', ['Apikey' => $apikey, 'phone' => $phone, 'message' => $message], $response);
}

    
//     public function pushNotificationVaBCA(Request $request)
// {
//     $secretKey = 'TokenJWT_BMI_ICT';

//     try {
//         $token = JWT::encode($request->all(), $secretKey, 'HS256');


//         $responseCode = $request->input('responseCode');
//         $responseMessage = $request->input('responseMessage');
//         // $responseTimestamp = $request->responseTimestamp;
//         $transactionId = $request->input('transactionId');
//         $data = $request->input('data');

//         if ($responseCode === '00') {
//             $vano = $data['vano'];
//             $amount = $data['amount'];
//             // $accountNo = $data['accountNo'];
//             $transactionQrId = $data['transactionQrId'];
//             // $description = $data['description'];

//             $billing = DB::table('billings')->where('va_number', $vano)->first();

//             if ($billing) {
//                 $campaignId = $billing->campaign_id;
//                 $wakafId = $billing->wakaf_id;
//                 $zakatId = $billing->zakat_id;
//                 $infakId = $billing->infak_id;

//                 DB::table('billings')->where('va_number', $vano)->update(['success' => 1]);

//                 do {
//                     $invoiceId = 'INV-' . now()->format('ymd') . strtoupper(Str::random(5));
//                 } while (DB::table('transactions')->where('invoice_id', $invoiceId)->exists());

//                 $for_ict = ($amount < 100000) ? $amount * 0.025 : 3000;

//                 DB::table('transactions')->insert([
//                     'invoice_id' => $invoiceId,
//                     'donatur' => $billing->username,
//                     'phone_number' => $billing->phone_number,
//                     'email' => null,
//                     'transaction_amount' => $amount,
//                     'for_ict' => $for_ict,
//                     'message' => $billing->message,
//                     'transaction_date' => now(),
//                     'channel' => 'ONLINE',
//                     'va_number' => $vano,
//                     'method' => 'BCA SYARIAH',
//                     'transaction_qr_id' => null,
//                     'created_time' => null,
//                     'category'=> $billing->category,
//                     'success' => 1,
//                     'user_id' => $billing->user_id,
//                     'campaign_id' => $campaignId ?? null,
//                     'wakaf_id' => $wakafId ?? null,
//                     'zakat_id' => $zakatId ?? null,
//                     'infak_id' => $infakId ?? null,
//                     'asal_transaksi' => 'BCA SYARIAH'
//                 ]);

//                 $campaignName = null;
//                 if ($campaignId) {
//                     $campaign = DB::table('campaigns')->where('id', $campaignId)->first();
//                     $campaignName = $campaign->campaign_name;
//                     DB::table('campaigns')->where('id', $campaignId)
//                         ->increment('current_amount', $amount);
//                 }

//                 if ($wakafId) {
//                     $wakaf = DB::table('wakafs')->where('id', $wakafId)->first();
//                     $campaignName = $wakaf->category_name;
//                     DB::table('wakafs')->where('id', $wakafId)
//                         ->increment('amount', $amount);
//                 }

//                 if ($zakatId) {
//                     $zakat = DB::table('zakats')->where('id', $zakatId)->first();
//                     $campaignName = $zakat->category_name;
//                     DB::table('zakats')->where('id', $zakatId)
//                         ->increment('amount', $amount);
//                 }

//                 if ($infakId) {
//                     $infak = DB::table('infaks')->where('id', $infakId)->first();
//                     $campaignName = $infak->category_name;
//                     DB::table('infaks')->where('id', $infakId)
//                         ->increment('amount', $amount);
//                 }

//                 // log traffic (dbtrafic)
//                 $servernamelog = '10.99.23.20';
//                 $usernamelog = 'root';
//                 $passwordlog = 'Smartpay1ct';
//                 $databaselog = 'farrelep_broadcaster';
                
//                 $dbTraffic = mysqli_connect($servernamelog, $usernamelog, $passwordlog, $databaselog);
//                     if ($dbTraffic->connect_errno) {
//                         echo json_encode("Failed to connect to MySQL: " . $dbTraffic->connect_error);
//                         exit();
//                     }
                
//                 $CUSTNM = 'DIY_LAZISMU_BCAS';
//                 $NOMINALFee1 = (int) round($amount * 0.007, 0, PHP_ROUND_HALF_UP); // Biaya QRIS
//                 $NOMINALFee2 = (int) $for_ict; // Biaya Admin ICT
//                 $GetValue = (string) ($amount + $NOMINALFee1 + $NOMINALFee2); // Nominal Gabungan
//                 $accountNoLog = '5320017203'; // Account No QRIS
//                 $mitraCustomerId = 'LAZIZMU DIY274029'; // Mitra ID
//                 $vanoLog = $vano ?? '-';
//                 // $transactionIdLog = $data->transactionId;
//                 $transactionIdLog = $data['transactionId'] ?? null;
//                 $transactionQrIdLog = $transactionQrId;
                
//                 $queryLog = "CALL LogPaymentQR ('" . $CUSTNM . "', '" . $mitraCustomerId . "' , '" . $accountNoLog . "' , '" . $transactionIdLog . "', '" . $transactionId . "' , '" . $transactionQrIdLog . "' , '" . $vanoLog . "' , '" . $GetValue . "' ,'" . $amount . "' ,'" . $NOMINALFee1 . "' ,'" . $NOMINALFee2 . "' ,'" . $token . "','-')";
//                 $dbTraffic->query($queryLog);


//                 $latestTransaction = DB::table('transactions')
//                 ->where('va_number', $vano)
//                 ->orderBy('transaction_id', 'desc') 
//                 ->first();
            
//                 $invoiceIds = $latestTransaction->invoice_id;

//                 // Format pesan WhatsApp
//                 $message = "*ZAKAT/INFAK ANDA BERHASIL*\n" .
//                     "*$invoiceIds*\n" .
//                     "Telah terima dari Bpk/Ibu *{$billing->username}*\n" .
//                     "Sebesar *Rp " . number_format($amount, 0, ',', '.') . "*\n" .
//                     "Untuk Pembayaran *$campaignName*\n\n" .
//                     "Yogyakarta, " . now()->format('d F Y - H:i') . "\n" .
//                     "TTD\n" .
//                     "Admin Jalan Kebaikan by Lazismu Daerah Istimewa Yogyakarta\n\n" .
//                     "Terimakasih \n" .
//                     "Kepada Yth Bpk/Ibu {$billing->username} atas kepercayaan anda.\n\n" .
//                     "أَجَرَكَ اللهُ فِيْمَا أَعْطَيْتَ, وَبَارَكَ لَكَ فِيْمَا أَبْقَيْتَ, وَاجْعَلْهُ لَكَ طَهُوْرًا\n\n" .
//                     "\"Mudah-mudahan Allah memberi pahala atas apa yang engkau berikan, memberikan berkah atas apa yang masih ada di tanganmu dan menjadikannya sebagai pembersih bagimu.”";

//                 // Kirim pesan WhatsApp
//                 $this->sendWhatsApp($billing->phone_number, $message);

//                 Logger::log('Push Notification', $request->all(), [
//                     'responseCode' => '00',
//                     'responseMessage' => 'TRANSACTION SUCCESS',
//                     'transactionId' => $transactionId
//                 ]);

//                 return response()->json([
//                     'responseCode' => '00',
//                     'responseMessage' => 'TRANSACTION SUCCESS',
//                     'responseTimestamp' => now(),
//                     'transactionId' => $transactionId
//                 ]);
//             }

//             Logger::log('Push Notification', $request->all(), null, 'Billing data not found');
//             return response()->json([
//                 'responseCode' => '01',
//                 'responseMessage' => 'Billing data not found',
//                 'responseTimestamp' => now()
//             ]);
//         } else {
//             Logger::log('Push Notification', $request->all(), null, $responseMessage);
//             return response()->json([
//                 'responseCode' => '01',
//                 'responseMessage' => $responseMessage,
//                 'responseTimestamp' => now()
//             ]);
//         }
//     } catch (Exception $e) {
//         Logger::log('Push Notification', $request->all(), null, $e->getMessage());
//         return response()->json([
//             'responseCode' => '01',
//             'responseMessage' => 'Invalid token or data: ' . $e->getMessage(),
//             'responseTimestamp' => now()
//         ]);
//     }
// }


public function pushNotificationVaBCA(Request $request)
{
    $secretKey = 'TokenJWT_BMI_ICT';

    try {
        $token = JWT::encode($request->all(), $secretKey, 'HS256');

        $responseCode = $request->input('responseCode');
        $responseMessage = $request->input('responseMessage');
        $transactionId = $request->input('transactionId');
        $data = $request->input('data');

        if ($responseCode !== '00') {

            Logger::log('Push Notification', $request->all(), null, $responseMessage);

            return response()->json([
                'responseCode' => '01',
                'responseMessage' => $responseMessage,
                'responseTimestamp' => now()
            ]);
        }

        $vano = $data['vano'] ?? null;
        $amount = $data['amount'] ?? 0;
        $transactionQrId = $data['transactionQrId'] ?? null;
        $transactionIdLog = $data['transactionId'] ?? null;

        $billing = DB::table('billings')->where('va_number', $vano)->first();

        if (!$billing) {

            Logger::log('Push Notification', $request->all(), null, 'Billing data not found');

            return response()->json([
                'responseCode' => '01',
                'responseMessage' => 'Billing data not found',
                'responseTimestamp' => now()
            ]);
        }

        DB::table('billings')->where('va_number', $vano)->update(['success' => 1]);


        do {
            $invoiceId = 'INV-' . now()->format('ymd') . strtoupper(Str::random(5));
        } while (DB::table('transactions')->where('invoice_id', $invoiceId)->exists());


        $for_ict = ($amount < 100000) ? $amount * 0.025 : 3000;


        DB::table('transactions')->insert([
            'invoice_id' => $invoiceId,
            'donatur' => $billing->username,
            'phone_number' => $billing->phone_number,
            'email' => null,
            'transaction_amount' => $amount,
            'for_ict' => $for_ict,
            'message' => $billing->message,
            'transaction_date' => now(),
            'channel' => 'ONLINE',
            'va_number' => $vano,
            'method' => 'BCA SYARIAH',
            'transaction_qr_id' => null,
            'created_time' => null,
            'category' => $billing->category,
            'success' => 1,
            'user_id' => $billing->user_id,
            'campaign_id' => $billing->campaign_id,
            'fundraiser_id' => ($billing->category === 'campaign') ? ($billing->fundraiser_id ?? null) : null,
            'wakaf_id' => $billing->wakaf_id,
            'zakat_id' => $billing->zakat_id,
            'infak_id' => $billing->infak_id,
            'asal_transaksi' => 'BCA SYARIAH'
        ]);


        $campaignName = null;

        if ($billing->campaign_id) {
            $campaign = DB::table('campaigns')->where('id', $billing->campaign_id)->first();
            DB::table('campaigns')->where('id', $billing->campaign_id)->increment('current_amount', $amount);
            $campaignName = $campaign->campaign_name;
        }

        if ($billing->wakaf_id) {
            $wakaf = DB::table('wakafs')->where('id', $billing->wakaf_id)->first();
            DB::table('wakafs')->where('id', $billing->wakaf_id)->increment('amount', $amount);
            $campaignName = $wakaf->category_name;
        }

        if ($billing->zakat_id) {
            $zakat = DB::table('zakats')->where('id', $billing->zakat_id)->first();
            DB::table('zakats')->where('id', $billing->zakat_id)->increment('amount', $amount);
            $campaignName = $zakat->category_name;
        }

        if ($billing->infak_id) {
            $infak = DB::table('infaks')->where('id', $billing->infak_id)->first();
            DB::table('infaks')->where('id', $billing->infak_id)->increment('amount', $amount);
            $campaignName = $infak->category_name;
        }


// insert log to broadcaster database
        $dbTraffic = mysqli_connect(
            '10.99.23.20',
            'root',
            'Smartpay1ct',
            'farrelep_broadcaster'
        );

        if ($dbTraffic->connect_errno) {
            return response()->json("Failed to connect to MySQL: " . $dbTraffic->connect_error);
        }

        $NOMINALFee1 = (int) round($amount * 0.007, 0, PHP_ROUND_HALF_UP);
        $NOMINALFee2 = (int) $for_ict;
        $GetValue = (string) ($amount + $NOMINALFee1 + $NOMINALFee2);

        $queryLog = "
            CALL LogPaymentQR (
                'DIY_LAZISMU_BCAS',
                'LAZIZMU DIY274029',
                '5320017203',
                '$transactionIdLog',
                '$transactionId',
                '$transactionQrId',
                '$vano',
                '$GetValue',
                '$amount',
                '$NOMINALFee1',
                '$NOMINALFee2',
                '$token',
                '-'
            )
        ";

        $dbTraffic->query($queryLog);


        // ----------- GET INVOICE TERBARU ------------ //
        $latestTransaction = DB::table('transactions')
            ->where('va_number', $vano)
            ->orderBy('transaction_id', 'desc')
            ->first();

        $invoiceIds = $latestTransaction->invoice_id;



        $message =
            "*ZAKAT/INFAK ANDA BERHASIL*\n" .
            "*{$invoiceIds}*\n" .
            "Telah terima dari Bpk/Ibu *{$billing->username}*\n" .
            "Sebesar *Rp " . number_format($amount, 0, ',', '.') . "*\n" .
            "Untuk Pembayaran *{$campaignName}*\n\n" .
            "Yogyakarta, " . now()->format('d F Y - H:i') . "\n" .
            "TTD\nAdmin Jalan Kebaikan Lazismu DIY\n\n" .
            "Terimakasih atas kepercayaan Anda.\n\n" .
            "أَجَرَكَ اللهُ فِيْمَا أَعْطَيْتَ وَبَارَكَ لَكَ فِيْمَا أَبْقَيْتَ\n\n" .
            "\"Semoga Allah memberi pahala atas apa yang engkau berikan dan menjadikannya sebagai pembersih bagi dirimu.\"";

        $this->sendWhatsApp($billing->phone_number, $message);


        // ----------- RESPON BERHASIL ------------ //
        Logger::log('Push Notification', $request->all(), [
            'responseCode' => '00',
            'responseMessage' => 'TRANSACTION SUCCESS',
            'transactionId' => $transactionId
        ]);

        return response()->json([
            'responseCode' => '00',
            'responseMessage' => 'TRANSACTION SUCCESS',
            'responseTimestamp' => now(),
            'transactionId' => $transactionId
        ]);


    } catch (Exception $e) {

        Logger::log('Push Notification', $request->all(), null, $e->getMessage());

        return response()->json([
            'responseCode' => '01',
            'responseMessage' => 'Invalid token or data: ' . $e->getMessage(),
            'responseTimestamp' => now()
        ]);
    }
}

public function pushNotificationVaBCA2(Request $request)
{
    $secretKey = 'TokenJWT_BMI_ICT';

    try {
        $token = JWT::encode($request->all(), $secretKey, 'HS256');

        $responseCode = $request->input('responseCode');
        $responseMessage = $request->input('responseMessage');
        $transactionId = $request->input('transactionId');
        $data = $request->input('data');

        if ($responseCode === '00') {

            $vano = $data['vano'];
            $amount = $data['amount'];
            $transactionQrId = $data['transactionQrId'] ?? null;

            // Ambil billing berdasarkan VA
            $billing = DB::table('billings')->where('va_number', $vano)->first();

            if (!$billing) {
                return response()->json([
                    'responseCode' => '01',
                    'responseMessage' => 'Billing data not found',
                    'responseTimestamp' => now()
                ]);
            }

            // Update status sukses billing
            DB::table('billings')->where('va_number', $vano)->update(['success' => 1]);

            /**
             * ========================
             * LOG KE DB BROADCASTER
             * ========================
             */
            $servernamelog = '10.99.23.20';
            $usernamelog = 'root';
            $passwordlog = 'Smartpay1ct';
            $databaselog = 'farrelep_broadcaster';

            $dbTraffic = mysqli_connect($servernamelog, $usernamelog, $passwordlog, $databaselog);
            if ($dbTraffic->connect_errno) {
                return response()->json([
                    'responseCode' => '01',
                    'responseMessage' => 'Failed connect to Log DB'
                ]);
            }

            // Fee QRIS (standar dari awal)
            $NOMINALFee1 = (int) round($amount * 0.007, 0, PHP_ROUND_HALF_UP);
            $NOMINALFee2 = 0; // Tidak ada fee ICT karena tidak insert transaksi
            $GetValue = (string) ($amount + $NOMINALFee1 + $NOMINALFee2);

            $queryLog = "CALL LogPaymentQR (
                'DIY_LAZISMU_BCAS',
                'LAZIZMU DIY274029',
                '5320017203',
                '$transactionId',
                '$transactionId',
                '$transactionQrId',
                '$vano',
                '$GetValue',
                '$amount',
                '$NOMINALFee1',
                '$NOMINALFee2',
                '$token',
                '-'
            )";

            $dbTraffic->query($queryLog);

            /**
             * ========================
             * KIRIM WHATSAPP
             * ========================
             */

            // Ambil nama atau kategori untuk WA
            $campaignName = $billing->category ?? 'Zakat/Infak';

            $message = "*PEMBAYARAN ANDA BERHASIL*\n" .
                "Telah diterima dari Bpk/Ibu *{$billing->username}*\n" .
                "Sebesar *Rp " . number_format($amount, 0, ',', '.') . "*\n" .
                "Untuk Pembayaran *{$campaignName}*\n\n" .
                "Yogyakarta, " . now()->format('d F Y - H:i') . "\n" .
                "TTD\n" .
                "Admin Jalan Kebaikan by Lazismu DIY\n\n" .
                "Terimakasih atas kepercayaannya.\n\n" .
                "أَجَرَكَ اللهُ فِيْمَا أَعْطَيْتَ، وَبَارَكَ لَكَ فِيْمَا أَبْقَيْتَ، وَجَعَلَهُ لَكَ طَهُورًا";

            // Kirim WA
            $this->sendWhatsApp($billing->phone_number, $message);

            return response()->json([
                'responseCode' => '00',
                'responseMessage' => 'SUCCESS (Log & WA sent)',
                'responseTimestamp' => now(),
                'transactionId' => $transactionId
            ]);
        }

        return response()->json([
            'responseCode' => '01',
            'responseMessage' => $responseMessage,
            'responseTimestamp' => now()
        ]);

    } catch (Exception $e) {

        return response()->json([
            'responseCode' => '01',
            'responseMessage' => 'Invalid data: ' . $e->getMessage(),
            'responseTimestamp' => now()
        ]);
    }
}


    
}
