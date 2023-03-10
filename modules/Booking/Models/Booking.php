<?php
namespace Modules\Booking\Models;

use App\BaseModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery\Exception;
use Modules\Booking\Emails\NewBookingEmail;
use Modules\Booking\Emails\StatusUpdatedEmail;
use Modules\Tour\Models\Tour;
use App\User;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends BaseModel
{
    use SoftDeletes;
    protected $table      = 'bravo_bookings';
    protected $cachedMeta = [];
    //protected $cachedMetaArr = [];
    const DRAFT      = 'draft'; // New booking, before payment processing
    const UNPAID     = 'unpaid'; // Require payment
    const PROCESSING = 'processing'; // like offline - payment
    const CONFIRMED  = 'confirmed'; // after processing -> confirmed (for offline payment)
    const COMPLETED  = 'completed'; //
    const CANCELLED  = 'cancelled';
    const PAID       = 'paid'; //

    public function getGatewayObjAttribute()
    {
        return $this->gateway ? get_payment_gateway_obj($this->gateway) : false;
    }

    public function getStatusNameAttribute()
    {
        return ucfirst($this->status ?? '');
    }

    public function getStatusClassAttribute()
    {
        switch ($this->status) {
            case "processing":
                return "primary";
                break;
            case "completed":
                return "success";
                break;
            case "confirmed":
                return "info";
                break;
            case "cancelled":
                return "danger";
                break;
            case "paid":
                return "info";
                break;
        }
    }

    public function service()
    {
        $all = config('booking.services');
        if ($this->object_model and !empty($all[$this->object_model])) {
            return $this->hasOne($all[$this->object_model], 'id', 'object_id');
        }
        return null;
    }

    public function payment()
    {
        return $this->hasOne(Payment::class, 'id', 'payment_id');
    }

    public function getCheckoutUrl()
    {
        return url(config('booking.booking_route_prefix') . '/' . $this->code . '/checkout');
    }

    public function getDetailUrl($full = true)
    {
        if (!$full) {
            return config('booking.booking_route_prefix') . '/' . $this->code;
        }
        return url(config('booking.booking_route_prefix') . '/' . $this->code);
    }

    public function getMeta($key, $default = '')
    {
        //if(isset($this->cachedMeta[$key])) return $this->cachedMeta[$key];
        $val = DB::table('bravo_booking_meta')->where([
            'booking_id' => $this->id,
            'name'       => $key
        ])->first();
        if (!empty($val)) {
            //$this->cachedMeta[$key]  = $val->val;
            return $val->val;
        }
        return $default;
    }

    public function getJsonMeta($key, $default = [])
    {
        $meta = $this->getMeta($key, $default);
        return json_decode($meta, true);
    }

    public function addMeta($key, $val, $multiple = false)
    {

        if (is_object($val) or is_array($val))
            $val = json_encode($val);
        if ($multiple) {
            return DB::table('bravo_booking_meta')->insert([
                'name'       => $key,
                'val'        => $val,
                'booking_id' => $this->id
            ]);
        } else {
            $old = DB::table('bravo_booking_meta')->where([
                'booking_id' => $this->id,
                'name'       => $key
            ])->first();
            if ($old) {

                return DB::table('bravo_booking_meta')->where('id', $old['id'])->insert([
                    'val' => $val
                ]);
            } else {
                return DB::table('bravo_booking_meta')->insert([
                    'name'       => $key,
                    'val'        => $val,
                    'booking_id' => $this->id
                ]);
            }
        }
    }

    public function batchInsertMeta($metaArrs = [])
    {
        if (!empty($metaArrs)) {
            foreach ($metaArrs as $key => $val) {
                $this->addMeta($key, $val, true);
            }
        }
    }

    public function generateCode()
    {
        return md5(uniqid() . rand(0, 99999));
    }

    public function save(array $options = [])
    {
        if (empty($this->code))
            $this->code = $this->generateCode();
        return parent::save($options); // TODO: Change the autogenerated stub
    }

    public function markAsProcessing($payment, $service)
    {

        $this->status = static::PROCESSING;
        $this->save();
    }

    public function markAsPaid()
    {

        $this->sendStatusUpdatedEmails();

        $this->status = static::PAID;
        $this->save();
    }

    public function markAsPaymentFailed(){

        $this->sendStatusUpdatedEmails();

        $this->status = static::UNPAID;
        $this->save();

    }

    public function sendNewBookingEmails()
    {
        try {
            // To Admin
            Mail::to(setting_item('admin_email'))->send(new NewBookingEmail($this, 'admin'));

            // to Vendor
            Mail::to(User::find($this->vendor_id))->send(new NewBookingEmail($this, 'vendor'));

            // To Customer
            Mail::to($this->email)->send(new NewBookingEmail($this, 'customer'));

        }catch (\Exception | \Swift_TransportException $exception){

            Log::warning('sendNewBookingEmails: '.$exception->getMessage());
        }
    }

    protected function sendStatusUpdatedEmails(){
        try{
            // To Admin
            Mail::to(setting_item('admin_email'))->send(new StatusUpdatedEmail($this,'admin'));

            // to Vendor
            Mail::to(User::find($this->vendor_id))->send(new StatusUpdatedEmail($this,'vendor'));

            // To Customer
            Mail::to($this->email)->send(new StatusUpdatedEmail($this,'customer'));

        } catch(\Exception $e){

            Log::warning('sendStatusUpdatedEmails: '.$e->getMessage());

        }
    }

    /**
     * Get Location
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function vendor()
    {
        return $this->hasOne("App\User", "id", 'vendor_id');
    }

    public static function getRecentBookings($limit = 10)
    {

        $q = parent::where('status', '!=', 'draft');
        return $q->orderBy('id', 'desc')->limit($limit)->get();
    }

    public static function getEarningChartData($from, $to)
    {

        $data = [
            'labels'   => [],
            'datasets' => [
                [
                    'label'           => __("Earning"),
                    'data'            => [],
                    'backgroundColor' => '#45bbe0'
                ]
            ]
        ];
        if (($to - $from) / DAY_IN_SECONDS > 90) {
            $year = date("Y", $from);
            // Report By Month
            for ($month = 1; $month <= 12; $month++) {
                $day_last_month = date("t", strtotime($year . "-" . $month . "-01"));
                $data['labels'][] = date("F", strtotime($year . "-" . $month . "-01"));
                $data['datasets'][0]['data'][] = parent::whereBetween('created_at', [
                    $year . '-' . $month . '-01 00:00:00',
                    $year . '-' . $month . '-' . $day_last_month . ' 23:59:59'
                ])->where('status', '!=', 'draft')->sum('total');
            }
        } elseif (($to - $from) <= DAY_IN_SECONDS) {
            // Report By Hours
            for ($i = strtotime(date('Y-m-d', $from)); $i <= strtotime(date('Y-m-d 23:59:59', $to)); $i += HOUR_IN_SECONDS) {
                $data['labels'][] = date('H:i', $i);
                $data['datasets'][0]['data'][] = parent::whereBetween('created_at', [
                    date('Y-m-d H:i:s', $i),
                    date('Y-m-d H:i:s', $i + HOUR_IN_SECONDS - 1),
                ])->where('status', '!=', 'draft')->sum('total');
            }
        } else {
            // Report By Day
            for ($i = strtotime(date('Y-m-d', $from)); $i <= strtotime(date('Y-m-d 23:59:59', $to)); $i += DAY_IN_SECONDS) {
                $data['labels'][] = display_date($i);
                $data['datasets'][0]['data'][] = parent::whereBetween('created_at', [
                    date('Y-m-d 00:00:00', $i),
                    date('Y-m-d 23:59:59', $i),
                ])->where('status', '!=', 'draft')->sum('total');
            }
        }
        return $data;
    }

    public static function getTopCardsReport()
    {

        $res = [];
        $total_money = parent::where('status', '!=', 'draft')->sum('total');
        $total_booking = parent::where('status', '!=', 'draft')->count('id');
        $total_user = \App\User::where('status', '!=', 'blocked')->count('id');
        $total_service = Tour::where('status', 'publish')->count('id');
        $res[] = [
            'size'   => 6,
            'size_md'=>3,
            'title'  => __("Earnings"),
            'amount' => format_money($total_money),
            'desc'   => __("Total earnings"),
            'class'  => 'purple',
            'icon'   => 'icon ion-ios-cart'
        ];
        $res[] = [

            'size'   => 6,
            'size_md'=>3,
            'title'  => __("Bookings"),
            'amount' => $total_booking,
            'desc'   => __("Total bookings"),
            'class'  => 'info',
            'icon'   => 'icon ion-ios-pricetags'
        ];
        $res[] = [

            'size'   => 6,
            'size_md'=>3,
            'title'  => __("Users"),
            'amount' => $total_user,
            'desc'   => __("Total users"),
            'class'  => 'pink',
            'icon'   => 'icon ion-ios-contacts'
        ];
        $res[] = [

            'size'   => 6,
            'size_md'=>3,
            'title'  => __("Services"),
            'amount' => $total_service,
            'desc'   => __("Total bookable services"),
            'class'  => 'success',
            'icon'   => 'icon ion-ios-flash'
        ];
        return $res;
    }

    public static function getBookingHistory($booking_status = false, $user_id = false)
    {
        $list_booking = parent::orderBy('id', 'desc');
        if (!empty($booking_status)) {
            $list_booking->where("status", $booking_status);
        }
        if (!empty($user_id)) {
            $list_booking->where("customer_id", $user_id);
        }
        return $list_booking->paginate(10);
    }

    public static function getTopCardsReportForVendor($user_id)
    {

        $res = [];
        $total_money = parent::where('status', 'completed')->where("vendor_id", $user_id)->sum('total');
        $total_booking = parent::where('status', 'completed')->where("vendor_id", $user_id)->count('id');
        $total_service = Tour::where('status', 'publish')->where("create_user", $user_id)->count('id');
        $res[] = [
            'title'  => __("Earnings"),
            'amount' => format_money($total_money),
            'desc'   => __("Total earnings"),
        ];
        $res[] = [
            'title'  => __("Bookings"),
            'amount' => $total_booking,
            'desc'   => __("Total bookings"),
        ];
        $res[] = [
            'title'  => __("Services"),
            'amount' => $total_service,
            'desc'   => __("Total bookable services"),
        ];
        return $res;
    }

    public static function getEarningChartDataForVendor($from, $to, $user_id)
    {

        $data = [
            'labels'   => [],
            'datasets' => [
                [
                    'label'           => __("Earning"),
                    'data'            => [],
                    'backgroundColor' => '#45bbe0'
                ]
            ]
        ];
        if (($to - $from) / DAY_IN_SECONDS > 90) {
            $year = date("Y", $from);
            // Report By Month
            for ($month = 1; $month <= 12; $month++) {
                $day_last_month = date("t", strtotime($year . "-" . $month . "-01"));
                $data['labels'][] = date("F", strtotime($year . "-" . $month . "-01"));
                $data['datasets'][0]['data'][] = parent::where("vendor_id", $user_id)->whereBetween('created_at', [
                    $year . '-' . $month . '-01 00:00:00',
                    $year . '-' . $month . '-' . $day_last_month . ' 23:59:59'
                ])->where('status', '!=', 'draft')->sum('total');
            }
        } elseif (($to - $from) <= DAY_IN_SECONDS) {
            // Report By Hours
            for ($i = strtotime(date('Y-m-d', $from)); $i <= strtotime(date('Y-m-d 23:59:59', $to)); $i += HOUR_IN_SECONDS) {
                $data['labels'][] = date('H:i', $i);
                $data['datasets'][0]['data'][] = parent::where("vendor_id", $user_id)->whereBetween('created_at', [
                    date('Y-m-d H:i:s', $i),
                    date('Y-m-d H:i:s', $i + HOUR_IN_SECONDS - 1),
                ])->where('status', '!=', 'draft')->sum('total');
            }
        } else {
            // Report By Day
            for ($i = strtotime(date('Y-m-d', $from)); $i <= strtotime(date('Y-m-d 23:59:59', $to)); $i += DAY_IN_SECONDS) {
                $data['labels'][] = display_date($i);
                $data['datasets'][0]['data'][] = parent::where("vendor_id", $user_id)->whereBetween('created_at', [
                    date('Y-m-d 00:00:00', $i),
                    date('Y-m-d 23:59:59', $i),
                ])->where('status', '!=', 'draft')->sum('total');
            }
        }
        return $data;
    }

    public static function countBookingByServiceID($service_id = false, $user_id = false, $status = false)
    {
        if (empty($service_id))
            return false;
        $count = parent::where("object_id", $service_id);
        if (!empty($status)) {
            $count->where("status", $status);
        }
        if (!empty($user_id)) {
            $count->where("customer_id", $user_id);
        }
        return $count->count("id");
    }

    public static function getStatisticChartData($from, $to, $statuses, $customer_id = false, $vendor_id = false)
    {
        $data = [
            "chart"  => [
                'labels'   => [],
                'datasets' => [
                    [
                        'label'           => __("Total Price"),
                        'data'            => [],
                        'backgroundColor' => '#45bbe0'
                    ]
                ]
            ],
            "detail" => [
                "total_earning" => [
                    "title" => __("Total Price"),
                    "val"   => 0,
                ],
                "total_booking" => [
                    "title" => __("Total Booking"),
                    "val"   => 0,
                ]
            ]
        ];
        $sql_raw[] = 'sum(`total`) as total_earning';
        if ($statuses) {
            $sql_raw[] = "count( CASE WHEN `status` != 'draft' THEN id ELSE NULL END ) AS total_booking";
            foreach ($statuses as $status) {
                $sql_raw[] = "count( CASE WHEN `status` = '{$status}' THEN id ELSE NULL END ) AS {$status}";
            }
        }
        if (($to - $from) / DAY_IN_SECONDS > 90) {
            $year = date("Y", $from);
            // Report By Month
            for ($month = 1; $month <= 12; $month++) {
                $day_last_month = date("t", strtotime($year . "-" . $month . "-01"));
                $dataBooking = parent::selectRaw(implode(",", $sql_raw))->whereBetween('created_at', [
                    $year . '-' . $month . '-01 00:00:00',
                    $year . '-' . $month . '-' . $day_last_month . ' 23:59:59'
                ])->where('status', '!=', 'draft');
                if (!empty($customer_id)) {
                    $dataBooking = $dataBooking->where('customer_id', $customer_id);
                }
                if (!empty($vendor_id)) {
                    $dataBooking = $dataBooking->where('vendor_id', $vendor_id);
                }
                $dataBooking = $dataBooking->first();
                $data['chart']['labels'][] = date("F", strtotime($year . "-" . $month . "-01"));
                $data['chart']['datasets'][0]['data'][] = $dataBooking->total_earning ?? 0;
                $data['detail']['total_earning']['val'] = $data['detail']['total_earning']['val'] + ($dataBooking->total_earning ?? 0);
                $data['detail']['total_booking']['val'] = $data['detail']['total_booking']['val'] + $dataBooking->total_booking;
                if ($statuses) {
                    foreach ($statuses as $status) {
                        $data['detail'][$status]['title'] = ucfirst($status);
                        $data['detail'][$status]['val'] = ($data['detail'][$status]['val'] ?? 0) + $dataBooking->$status ?? 0;
                    }
                }
            }
        } elseif (($to - $from) <= DAY_IN_SECONDS) {
            // Report By Hours
            for ($i = strtotime(date('Y-m-d', $from)); $i <= strtotime(date('Y-m-d 23:59:59', $to)); $i += HOUR_IN_SECONDS) {
                $dataBooking = parent::selectRaw(implode(",", $sql_raw))->whereBetween('created_at', [
                    date('Y-m-d H:i:s', $i),
                    date('Y-m-d H:i:s', $i + HOUR_IN_SECONDS - 1),
                ])->where('status', '!=', 'draft');
                if (!empty($customer_id)) {
                    $dataBooking = $dataBooking->where('customer_id', $customer_id);
                }
                if (!empty($vendor_id)) {
                    $dataBooking = $dataBooking->where('vendor_id', $vendor_id);
                }
                $dataBooking = $dataBooking->first();
                $data['chart']['labels'][] = date('H:i', $i);
                $data['chart']['datasets'][0]['data'][] = $dataBooking->total_earning ?? 0;
                $data['detail']['total_earning']['val'] = $data['detail']['total_earning']['val'] + ($dataBooking->total_earning ?? 0);
                $data['detail']['total_booking']['val'] = $data['detail']['total_booking']['val'] + $dataBooking->total_booking;
                if ($statuses) {
                    foreach ($statuses as $status) {
                        $data['detail'][$status]['title'] = ucfirst($status);
                        $data['detail'][$status]['val'] = ($data['detail'][$status]['val'] ?? 0) + $dataBooking->$status ?? 0;
                    }
                }
            }
        } else {
            // Report By Day
            for ($i = strtotime(date('Y-m-d', $from)); $i <= strtotime(date('Y-m-d 23:59:59', $to)); $i += DAY_IN_SECONDS) {
                $dataBooking = parent::selectRaw(implode(",", $sql_raw))->whereBetween('created_at', [
                    date('Y-m-d 00:00:00', $i),
                    date('Y-m-d 23:59:59', $i),
                ])->where('status', '!=', 'draft');
                if (!empty($customer_id)) {
                    $dataBooking = $dataBooking->where('customer_id', $customer_id);
                }
                if (!empty($vendor_id)) {
                    $dataBooking = $dataBooking->where('vendor_id', $vendor_id);
                }
                $dataBooking = $dataBooking->first();
                $data['chart']['labels'][] = display_date($i);
                $data['chart']['datasets'][0]['data'][] = $dataBooking->total_earning ?? 0;
                $data['detail']['total_earning']['val'] = $data['detail']['total_earning']['val'] + ($dataBooking->total_earning ?? 0);
                $data['detail']['total_booking']['val'] = $data['detail']['total_booking']['val'] + $dataBooking->total_booking;
                if ($statuses) {
                    foreach ($statuses as $status) {
                        $data['detail'][$status]['title'] = ucfirst($status);
                        $data['detail'][$status]['val'] = ($data['detail'][$status]['val'] ?? 0) + $dataBooking->$status ?? 0;
                    }
                }
            }
        }
        $data['detail']['total_earning']['val'] = format_money($data['detail']['total_earning']['val']);
        return $data;
    }
}
