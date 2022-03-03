<?php


namespace App\Services;


use App\Models\BookingStatusHistory;
use App\Models\Wallet;
use App\Traits\GeneralTrait;

class DemurrageService extends BaseService
{
use GeneralTrait;
    private $booking_status ,$booking;
    private $booking_entry_date;
    private $booking_exit_date;
    /**
     * @var int
     */
    private $number_of_days_spent;
    private $demurrage_applies;
    private $demurrage_cost_details;
    /**
     * @var int
     */
    private $calibrated_number_of_days_spent;
    private $park_details;
    private $truck_details;

    public function setBookingStatuses(){

        $this->booking_status=['Left-Park'=>'In-Park','Left-Pregate'=>'In-Pregate'];
        return $this;
    }


    public function setDefaultEntryExitDate($booking)
    {
        $this->booking=$booking;
        $this->booking_exit_date=date('Y-m-d H:i:s');
        $this->booking_entry_date=date('Y-m-d H:i:s');
        $this->demurrage_applies=0;
        $this->calibrated_number_of_days_spent=0;
        return $this;
    }


    public function getBookingEntryDate(){
        if (!in_array($this->request->next_status,['Left-Park','Left-Pregate']) || $this->demurrage_applies==0){
            return $this;
        }

        $this->booking_entry_date=BookingStatusHistory::selectRaw('min(created_at) as entry_date ')
            ->where(['booking_id'=>$this->booking->id])
            ->where('booking_status',$this->booking_status[$this->request->next_status])
            ->value('entry_date');
        return $this;
    }

    public function getBookingExitDate(){
        if (!in_array($this->request->next_status,['Left-Park','Left-Pregate']) || $this->demurrage_applies==0){
            return $this;
        }
        $this->booking_exit_date=BookingStatusHistory::selectRaw('max(created_at) as exit_date ')
            ->where(['booking_id'=>$this->booking->id])
            ->where('booking_status',$this->request->next_status)
            ->value('exit_date');
        return $this;
    }

    public function calculateNumberofDaysStayed(){

        if($this->demurrage_applies==0){
            return $this;
        }
        $this->number_of_days_spent=\Carbon\Carbon::parse($this->booking_entry_date)->diffInDays(\Carbon\Carbon::parse($this->booking_exit_date));

        return $this;
    }

    public function setDemmurrageApplies(){
//     dd($this->demurrage_cost_details->demurrage);
        if (in_array($this->request->next_status,['Left-Park'])){
            $this->demurrage_applies=$this->demurrage_cost_details->demurrage>0 ? 1 : 0;
        }

        if (in_array($this->request->next_status,['Left-Pregate'])){
            $this->demurrage_applies=$this->demurrage_cost_details->demurrage>0 ? 1 : 0;
        }
        return $this;
    }

    public function setDemmurrageCostDetail(){
        if (in_array($this->request->next_status,['Left-Park'])){
            $this->demurrage_cost_details=$this->booking->park->parkcosts->where('port_id',$this->booking->terminal->port_id)->first();
        }

        if (in_array($this->request->next_status,['Left-Pregate'])){
            $this->demurrage_cost_details=$this->booking->matching->park->parkcosts->where('port_id',$this->booking->matching->terminal->port_id)->first();
        }
        return $this;
    }



    public function calibrateNumberOfDaysSpent(){

        if($this->demurrage_applies==0){

            return $this;
        }

        $this->calibrated_number_of_days_spent=$this->number_of_days_spent-$this->demurrage_cost_details->grace_period;
        $this->calibrated_number_of_days_spent=$this->calibrated_number_of_days_spent <= 0 ? 0 : $this->calibrated_number_of_days_spent;
        return $this;
    }

    public function setParkDetail(){
        if (in_array($this->request->next_status,['Left-Park'])){
            $this->park_details=$this->booking->park;
        }

        if (in_array($this->request->next_status,['Left-Pregate'])){
            $this->park_details=$this->booking->matching->park;
        }
        return $this;
    }

    public function setTrukDetail(){
        if($this->demurrage_applies==0){
            return $this;
        }
        $this->truck_details=$this->booking->truck;
        return $this;
    }

    public function checkCustomerWallet(){
        if($this->demurrage_applies==0){
            return $this;
        }
        $this->checkWalletBalance(($this->demurrage_cost_details->demurrage * $this->calibrated_number_of_days_spent));
        return $this;
    }

    public function chargeDemurrageFromCustomerWallet(){

        if($this->calibrated_number_of_days_spent==0){
            return $this;
        }
        Wallet::updateOrCreate([
            'user_id'=>$this->booking->owner_id,
            'service_description'=>'Demurrage charge for staying for extra '.$this->calibrated_number_of_days_spent.' days stay at Park Name '.$this->park_details->name.' for truck '.$this->truck_details->plate_number,
            'amount'=>$this->demurrage_cost_details->demurrage * $this->calibrated_number_of_days_spent,
            'transaction_status'=>1,
            'transaction_reference'=>$this->generateTransactionReference(),
            'validated'=>1,
            'booking_id'=>$this->booking->id,
            'transaction_direction_user'=>'out',
            'transaction_direction_admin'=>'in',
            'transaction_direction_facility'=>'in'
        ]);

        return $this;
    }

    public function remmitDemurrageToParkOwnersAccount(){

        if($this->calibrated_number_of_days_spent==0){
            return $this;
        }

        Wallet::updateOrCreate([
            'user_id'=>round($this->demurrage_cost_details->parkowner_remmitance_account_id)==0 ? $this->park_details->owner_id : $this->demurrage_cost_details->parkowner_remmitance_account_id ,
            'service_description'=>'Demurrage Remittance charge for staying for extra '.$this->calibrated_number_of_days_spent.' days stay at Park Name '.$this->park_details->name.' for truck '.$this->truck_details->plate_number,
            'amount'=>$this->demurrage_cost_details->demurrage * $this->calibrated_number_of_days_spent,
            'transaction_status'=>1,
            'transaction_reference'=>$this->generateTransactionReference(),
            'validated'=>1,
            'booking_id'=>$this->booking->id,
            'transaction_direction_user'=>'in',
            'transaction_direction_admin'=>'out',
            'transaction_direction_facility'=>'out'
        ]);

        return $this;
    }


}
