<?php


namespace App\Services;


use App\Models\Booking;
use App\Models\Wallet;
use App\Services\PaymentProcessors\FlutterWaveService;
use App\Services\PaymentProcessors\PayStackService;
use App\Services\PaymentProcessors\VouguePayService;
use App\Traits\GeneralTrait;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentService extends BaseService
{
    use GeneralTrait;
    public $booking_details,$booking_message,$notification_subject,$wallet_details;

//    public function
    private $response;

    public function __construct($wallet_details='')
    {
        $this->wallet_details=$wallet_details;
        parent::__construct();
    }

    public function getResponseFromPayStack(){
        $this->response=(new PayStackService($this->wallet_details))
                                                    ->buildRequest()
                                                    ->sendRequest()
                                                    ->getResponse();

        return $this;
    }


    public function getResponseFromFlutterWave(){
        $this->response=(new FlutterWaveService($this->wallet_details))
            ->buildRequest()
            ->sendRequest()
            ->getResponse();
        return $this;
    }

    public function getResponseFromVouGePay(){
        $this->response=(new VouguePayService($this->wallet_details))
                                            ->buildRequest()
                                            ->sendRequest()
                                            ->getResponse();
        return $this;
    }


    public function redirectToGateWay()
    {

        if($this->wallet_details->payment_processor=='paystack'){
            $this->getResponseFromPayStack();
        }

        if($this->wallet_details->payment_processor=='flutterwave'){
            $this->getResponseFromFlutterWave();
        }

        if($this->wallet_details->payment_processor=='vouguepay'){
            $this->getResponseFromVouGePay();
        }
//        dd($this->response['data']['authorization_url']);
        return redirect($this->response);
    }

    public function getBookingDetails(){
        $this->request=request();
         $this->booking_details=Booking::where('id',$this->request->booking_id)->first();
         return $this;
    }

    public function checkCustomerWalletForSufficentBalance(){
        $this->checkWalletBalance($this->booking_details->total_cost);
        return $this;
    }

    public function payforBooking()
    {

           $wallet= Wallet::updateOrCreate([
                'user_id'=>auth()->user()->real_id,
                'service_description'=>'Payment For Booking Park '.$this->booking_details->park->name.' For Truck With Plate Number '.$this->booking_details->truck->plate_number,
                'amount'=>$this->booking_details->total_cost,
                'transaction_status'=>1,
                'transaction_reference'=>$this->generateTransactionReference(),
                'validated'=>1,
                'booking_id'=>$this->booking_details->id,
                'park_id'=>$this->booking_details->park_id,
                'matching_id'=>0,
                'transaction_direction_user'=>'out',
                'transaction_direction_admin'=>'in',
                'transaction_direction_facility'=>'in'
            ]);

        (new TransactionSPlitService(
            $this->booking_details->park,
            $this->booking_details->total_cost,
            $this->booking_details,$wallet->service_description
        ))
            ->splitNPA()
            ->splitUnion()
            ->splitParkOwner();
        return $this;
    }

    public function  updateBookingPaymentStatus(){

        $this->request->request->add(['update_capacity'=>true]);
        Booking::where('id',$this->booking_details->id)->update([
            'booking_payment_status'=>'paid',
            'truck_status'=>'In-Transit'
        ]);
        $this->updateParkCapacity(-1,$this->booking_details->park_id);
        return $this;
    }

    public function setBookingMessage(){
        $this->booking_message='Your Booking of Truck with Plate Number : '.$this->booking_details->truck->plate_number.' to Park :'.$this->booking_details->park->name.' has been successfully completed. Please proceed to the designated park';
        return $this;
    }

    public function setNotificationSubject(){
        $this->notification_subject='Successful Booking Notification';
        return $this;
    }

    private function sendSmsToDriver(){
        $phone=$this->booking_details->driver->phone_number;
        $message='You have been assigned to Truck with Plate Number '.$this->booking_details->truck->plate_number.' please proceed to '.$this->booking_details->park->name.' at '.$this->booking_details->park->location.' provide this booking number to access park '.$this->booking_details->booking_number;
        (new SMSService())->sendSMS($phone,$message);
        return $this;
    }

    public function notifyUserOfSuccessfulBooking(){

        $this->sendSmsToDriver();
        (new Notification())
                ->setUrlToView(route('booking.index',['booking_id'=>$this->booking_details->id]))
                ->setNotificationSubject($this->notification_subject)
                ->setUserDetail($this->booking_details->owner)
                ->setUserMessage($this->booking_message)
                ->notifyUser();

        $this->response_message='Payment Successfully made';
        $this->response_code=200;
        return $this->getResponse();
    }
}
