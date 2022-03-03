<?php


namespace App\Services;


use App\Helper;
use App\Models\BookingStatusHistory;
use App\Models\Wallet;
use App\Traits\GeneralTrait;

class VerifyService extends BaseService
{
    use GeneralTrait;
    public $booking;
    private $notification_subject;
    private $notification_message;


    public function setBooking($booking){
        $this->booking=$booking;
        return $this;
    }

    public function checkPaymentIsMade(){
        if ($this->booking->booking_payment_status!='paid'){
            throw new \Exception('Payment not made');
        }
        return $this;
    }

    public function checkHasParkPermission(){
            $this->checkTruckStatusChangePermission(
                ['In-Park','Left-Park'],
                ['in_park_own','in_park_all'],
                ['left_park_own','left_park_all']
            );
         return $this;
    }

    public function checkHasPregatePermission(){
             $this->checkTruckStatusChangePermission(
                 ['In-Pregate','Left-Pregate'],
                 ['in_pregate_own','in_pregate_all'],
                 ['left_pregate_own','left_pregate_all']
             );
                return $this;
    }

    public function checkIfHasPermissionToChangeTruckStatusForSelectedParkOrTerminal(){
        if(Helper::hasPermission(['in_pregate_own','in_park_own','in_terminal_own'])){

            if(in_array($this->request->next_status,['In-Park','Left-Park'])){
                if(!in_array($this->booking->park_id,auth()->user()->allowed_park)){
                   throw  new \Exception('You are not allowed to check in truck from this facility '.$this->booking->park->name);
                }
            }

            if(in_array($this->request->next_status,['In-Pregate','Left-Pregate'])){
                if(!in_array($this->booking->matching->park_id,auth()->user()->allowed_park)){
                    throw  new \Exception('You are not allowed to check in truck from this facility.'.$this->booking->matching->park->name);
                }
            }

            if(in_array($this->request->next_status,['In-Terminal','Left-Terminal'])){
                if(!in_array($this->booking->matching->terminal_id,auth()->user()->allowed_terminal)){
                    throw  new \Exception('You are not allowed to check in truck from this terminal '.$this->booking->matching->terminal->name);
                }
            }
        }

        return $this;
    }



    private function checkTruckStatusChangePermission(array $status ,array $perm_in , array $perm_out){

        $has_perm=0;
        if (in_array($this->request->next_status,$status)){

            if($this->request->next_status==$status[0] && Helper::hasPermission($perm_in)){
                $has_perm=1;
            }
            if($this->request->next_status==$status[1] && Helper::hasPermission($perm_out)){
                $has_perm=1;
            }
            if($has_perm==0){
                throw new \Exception('You do not have permission to update truck status to '.$this->request->next_status);
            }
        }
        return $this;
    }

    public function checkHasTerminalPermission(){
        if (in_array($this->request->next_status,['In-Terminal','Left-Terminal'])){
            $this->checkTruckStatusChangePermission(
                ['In-Terminal','Left-Terminal'],
                ['in_terminal_own','in_terminal_all'],
                ['left_terminal_own','left_terminal_all']
            );
        }
        return $this;
    }


    public function checkTicketValidForNextStatus(){
        if (in_array($this->request->next_status,['In-Pregate','Left-Pregate'])){
            if(is_null($this->booking->matching->id)){
                throw new \Exception('Please Tell Customer to match booking to a container first');
            }
        }

        return $this;
    }


    public function preventBackwardUpdate(){
        if($this->next_stages()[$this->booking->truck_status] !=$this->request->next_status){
            throw new \Exception('You cannot '.$this->request->next_status.' this truck because it is not in its right status');
        }
        return $this;
    }

    public function recongniseRevenue(){
        if(Helper::hasPermission(['in_pregate_own','in_park_own'])) {
            Wallet::where('booking_id', $this->booking->id)->update(['auto_recognized' => 1]);
        }
        return $this;
    }

    public function logToTable(){
        BookingStatusHistory::create([
            'booking_id'=>$this->booking->id,
            'booking_status'=>$this->request->next_status,
            'updated_by_id'=>auth()->user()->real_id
        ]);
        return $this;
    }



    private function sendSmsToDriver(){
        $phone=$this->booking->driver->phone_number;
        $message=$this->notification_message;
        (new SMSService())->sendSMS($phone,$message);
        return $this;
    }

    public function setNotificationSubject(){
        $this->notification_subject='Truck Status change';
        return $this;
    }

    public function setNotificationMessage(){
        $this->notification_message='Your Truck with PlateNumber :'.$this->booking->truck->plate_number.' have Just .'.$this->booking->truck_status.' , Time :'.$this->booking->updated_at;
        return $this;
    }


    public function notifyTruckOwnerOfStatusChange()
    {
        $this->sendSmsToDriver();
        (new Notification())
            ->setUrlToView(route('booking.index', ['booking_id' => $this->booking->id]))
            ->setNotificationSubject($this->notification_subject)
            ->setUserDetail($this->booking->owner)
            ->setUserMessage($this->notification_message)
            ->notifyUser();
        return $this;

    }

    public function chargeDemurrage(){
        (new DemurrageService())->setDefaultEntryExitDate($this->booking)
                                ->setBookingStatuses()
                                ->setDemmurrageCostDetail()
                                ->setDemmurrageApplies()
                                ->getBookingEntryDate()
                                ->getBookingExitDate()
                                ->calculateNumberofDaysStayed()
                                ->calibrateNumberOfDaysSpent()
                                ->setParkDetail()
                                ->setTrukDetail()
                                ->checkCustomerWallet()
                                ->chargeDemurrageFromCustomerWallet()
                                ->remmitDemurrageToParkOwnersAccount();
        return $this;
    }

    public function updateToNewStatus(){

        $this->booking->update(['truck_status'=>$this->request->next_status]);

        return $this;
    }

    public function updateCapacity(){
        $num=$park_id=0;
        if(in_array($this->request->next_status,['In-Park','In-Pregate'])){
           $num=-1;
           $park_id=$this->request->next_status=='In-Park' ? $this->booking->park_id : $this->booking->matching->park_id;
        }
        if(in_array($this->request->next_status,['Left-Park','Left-Pregate'])){
           $num=1;
           $park_id=$this->request->next_status=='Left-Park' ? $this->booking->park_id : $this->booking->matching->park_id;
        }
        if(in_array($this->request->next_status,['In-Park','In-Pregate','Left-Park','Left-Pregate'])) {
            $this->updateParkCapacity($num, $park_id);
        }
        return $this;
    }

    public function successfulResponse(){
        $this->response_message='Successfully Updated Ticket Status';
        $this->response_code=200;
        return $this->getResponse();
    }


}
