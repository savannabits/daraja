<?php

namespace Savannabits\Daraja;

use Symfony\Component\Dotenv\Dotenv;

class Daraja
{

    private  $consumer_key, $consumer_secret, $access_token, $environment;
    /**
     * Define env method similar to laravel's
     *
     * @param String $env_param | Environment Param Name
     *
     * @return array|false|string
     */
    public static function env($env_param){

        $dotenv = new Dotenv();

        $dotenv->load('../.env');

        $env = getenv($env_param);

        return $env;
    }

    /**
     * This is used to return an instance of the class especially when it is being used statically.
     * @return Daraja
     */
    public static function getInstance() {
        return new self;
    }

    /**
     * Generate instance's member credentials during runtime which will be used to generate tokens and make other calls
     * @param $mpesa_consumer_key
     * @param $mpesa_consumer_secret
     * @return Daraja
     */
    public function setCredentials($mpesa_consumer_key, $mpesa_consumer_secret, $mpesa_env='sandbox') {
        if (!strlen($mpesa_consumer_key)|| !strlen($mpesa_consumer_secret)){
            die("Please provide a valid consumer key and secret as provided by safaricom");
        }
        if ($mpesa_env!=='sandbox' && $mpesa_env !=='live') {
            die('mpesa_env MUST be either sandbox or live');
        }
        $this->consumer_key = $mpesa_consumer_key;
        $this->consumer_secret = $mpesa_consumer_secret;
        $this->environment = $mpesa_env;
        return $this;
    }

    /**
     * This is used to generate tokens for the live environment
     * @return mixed
     */
    public function generateLiveToken(){
        if(!isset($this->consumer_key)||!isset($this->consumer_secret)){
            die("please set the consumer key and consumer secret as defined in the documentation. You can use setCredentials() before calling this function.");
        }
        $consumer_key = $this->consumer_key;
        $consumer_secret = $this->consumer_secret;
        $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        $credentials = base64_encode($consumer_key.':'.$consumer_secret);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic '.$credentials)); //setting a custom header
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $curl_response = curl_exec($curl);
        $this->access_token = optional(json_decode($curl_response))->access_token;
        if (!$this->access_token) {
            abort(500, "Access token could not be generated.");
        }
        return $this;
    }

    public function getAccessToken() {
        return $this->access_token;
    }
    /**
     * use this function to generate a sandbox token
     * @return mixed
     */
    public function generateSandBoxToken(){

        if(!isset($this->consumer_key)||!isset($this->consumer_secret)){
            die("please set the consumer key and consumer secret as defined in the documentation. You can use setCredentials() before calling this function.");
        }
        $consumer_key = $this->consumer_key;
        $consumer_secret = $this->consumer_secret;
        $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        $credentials = base64_encode($consumer_key.':'.$consumer_secret);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic '.$credentials)); //setting a custom header
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $curl_response = curl_exec($curl);

        $this->access_token = optional(json_decode($curl_response))->access_token;
        if (!$this->access_token) {
            abort(500, "Access token could not be generated.");
        }
        return $this;
    }

    /**
     * Register Validation and Confirmation Callbacks
     * @param $shortCode | The MPESA Short Code
     * @param string $confirmationURL | Confirmation Callback URL
     * @param string|null $validationURL | Validation Callback URL
     * @param string $responseType | Default Response type in case the validation URL is unreachable or is undefined. Either Cancelled or Completed as per the safaricom documentation
     * @return false|mixed|string
     */
    public function registerCallbacks($shortCode,$confirmationURL, $validationURL=null, $responseType="Cancelled") {
        $environment = $this->environment;
        if( $environment =="live"){
            $url = "https://api.safaricom.co.ke/mpesa/c2b/v1/registerurl";
            $this->generateLiveToken();
        }elseif ($environment=="sandbox"){
            $url = "https://sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl";
            $this->generateSandBoxToken();
        }else{
            return json_encode(["Message"=>"Invalid App environment. Please set it via setCredentials() to either live or sandbox."]);
        }

        $token = $this->getAccessToken();
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$token));


        $curl_post_data = array(
            "ValidationURL" => $validationURL,
            "ConfirmationURL" => $confirmationURL,
            "ResponseType" => $responseType,
            "ShortCode" => $shortCode
        );

        $data_string = json_encode($curl_post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $curl_response = curl_exec($curl);
        return json_decode($curl_response);
    }

    /**
     * Use this function to initiate a reversal request
     * @param $CommandID | Takes only 'TransactionReversal' Command id
     * @param $Initiator | The name of Initiator to initiating  the request
     * @param $SecurityCredential | 	Encrypted Credential of user getting transaction amount
     * @param $TransactionID | Unique Id received with every transaction response.
     * @param $Amount | Amount
     * @param $ReceiverParty | Organization /MSISDN sending the transaction
     * @param $RecieverIdentifierType | Type of organization receiving the transaction
     * @param $ResultURL | The path that stores information of transaction
     * @param $QueueTimeOutURL | The path that stores information of time out transaction
     * @param $Remarks | Comments that are sent along with the transaction.
     * @param $Occasion | 	Optional Parameter
     * @return mixed|string
     */
    public function reversal($CommandID, $Initiator, $SecurityCredential, $TransactionID, $Amount, $ReceiverParty, $RecieverIdentifierType, $ResultURL, $QueueTimeOutURL, $Remarks, $Occasion){

        $environment = $this->environment;
        if( $environment =="live"){
            $url = 'https://api.safaricom.co.ke/mpesa/reversal/v1/request';
            $this->generateLiveToken();
        }elseif ($environment=="sandbox"){
            $url = 'https://sandbox.safaricom.co.ke/mpesa/reversal/v1/request';
            $this->generateSandBoxToken();
        }else{
            return json_encode(["Message"=>"invalid application status. Please set mpesa_env credential as either live or sandbox."]);
        }

        $token = $this->getAccessToken();

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$token));

        $curl_post_data = array(
            'CommandID' => $CommandID,
            'Initiator' => $Initiator,
            'SecurityCredential' => $SecurityCredential,
            'TransactionID' => $TransactionID,
            'Amount' => $Amount,
            'ReceiverParty' => $ReceiverParty,
            'RecieverIdentifierType' => $RecieverIdentifierType,
            'ResultURL' => $ResultURL,
            'QueueTimeOutURL' => $QueueTimeOutURL,
            'Remarks' => $Remarks,
            'Occasion' => $Occasion
        );

        $data_string = json_encode($curl_post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $curl_response = curl_exec($curl);
        return json_decode($curl_response);

    }

    /**
     * @param $InitiatorName | 	This is the credential/username used to authenticate the transaction request.
     * @param $SecurityCredential | Encrypted password for the initiator to autheticate the transaction request
     * @param $CommandID | Unique command for each transaction type e.g. SalaryPayment, BusinessPayment, PromotionPayment
     * @param $Amount | The amount being transacted
     * @param $PartyA | Organization’s shortcode initiating the transaction.
     * @param $PartyB | Phone number receiving the transaction
     * @param $Remarks | Comments that are sent along with the transaction.
     * @param $QueueTimeOutURL | The timeout end-point that receives a timeout response.
     * @param $ResultURL | The end-point that receives the response of the transaction
     * @param $Occasion | 	Optional
     * @return string
     */
    public function b2c($InitiatorName, $SecurityCredential, $CommandID, $Amount, $PartyA, $PartyB, $Remarks, $QueueTimeOutURL, $ResultURL, $Occasion){

        $environment = $this->environment;

        if( $environment =="live"){
            $url = 'https://api.safaricom.co.ke/mpesa/b2c/v1/paymentrequest';
            $token=self::generateLiveToken();
        } elseif ($environment=="sandbox"){
            $url = 'https://sandbox.safaricom.co.ke/mpesa/b2c/v1/paymentrequest';
            $token=self::generateSandBoxToken();
        }else{
            return json_encode(["Message"=>"invalid application status. Please set mpesa_env to either live or sandbox via setCredentials()"]);
        }


        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$token));


        $curl_post_data = array(
            'InitiatorName' => $InitiatorName,
            'SecurityCredential' => $SecurityCredential,
            'CommandID' => $CommandID ,
            'Amount' => $Amount,
            'PartyA' => $PartyA ,
            'PartyB' => $PartyB,
            'Remarks' => $Remarks,
            'QueueTimeOutURL' => $QueueTimeOutURL,
            'ResultURL' => $ResultURL,
            'Occasion' => $Occasion
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);

        $curl_response = curl_exec($curl);

        return json_encode($curl_response);

    }
    /**
     * Use this function to initiate a C2B transaction
     * @param $ShortCode | 6 digit M-Pesa Till Number or PayBill Number
     * @param $CommandID | Unique command for each transaction type.
     * @param $Amount | The amount been transacted.
     * @param $Msisdn | MSISDN (phone number) sending the transaction, start with country code without the plus(+) sign.
     * @param $BillRefNumber | 	Bill Reference Number (Optional).
     * @return mixed|string
     */
    public function c2b($ShortCode, $CommandID, $Amount, $Msisdn, $BillRefNumber ){
        $environment = $this->environment;
        if( $environment =="live"){
            $url = 'https://api.safaricom.co.ke/mpesa/c2b/v1/simulate';
            $this->generateLiveToken();
        }elseif ($environment=="sandbox"){
            $url = 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/simulate';
            $this->generateSandBoxToken();
        }else{
            return json_encode(["Message"=>"invalid application status. Please set mpesa_env to either sandbox or live"]);
        }

        $token = $this->getAccessToken();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$token));

        $curl_post_data = array(
            'ShortCode' => $ShortCode,
            'CommandID' => $CommandID,
            'Amount' => $Amount,
            'Msisdn' => $Msisdn,
            'BillRefNumber' => $BillRefNumber
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $curl_response = curl_exec($curl);
        return $curl_response;
    }


    /**
     * Use this to initiate a balance inquiry request
     * @param $CommandID | A unique command passed to the M-Pesa system.
     * @param $Initiator | 	This is the credential/username used to authenticate the transaction request.
     * @param $SecurityCredential | Encrypted password for the initiator to autheticate the transaction request
     * @param $PartyA | Type of organization receiving the transaction
     * @param $IdentifierType |Type of organization receiving the transaction
     * @param $Remarks | Comments that are sent along with the transaction.
     * @param $QueueTimeOutURL | The path that stores information of time out transaction
     * @param $ResultURL | 	The path that stores information of transaction
     * @return mixed|string
     */
    public function accountBalance($CommandID, $Initiator, $SecurityCredential, $PartyA, $IdentifierType, $Remarks, $QueueTimeOutURL, $ResultURL){

        $environment = $this->environment;
        if( $environment =="live"){
            $url = 'https://api.safaricom.co.ke/mpesa/accountbalance/v1/query';
            $this->generateLiveToken();
        }elseif ($environment=="sandbox"){
            $url = 'https://sandbox.safaricom.co.ke/mpesa/accountbalance/v1/query';
            $this->generateSandBoxToken();
        }else{
            return json_encode(["Message"=>"invalid application environment"]);
        }

        $token = $this->getAccessToken();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$token)); //setting custom header


        $curl_post_data = array(
            'CommandID' => $CommandID,
            'Initiator' => $Initiator,
            'SecurityCredential' => $SecurityCredential,
            'PartyA' => $PartyA,
            'IdentifierType' => $IdentifierType,
            'Remarks' => $Remarks,
            'QueueTimeOutURL' => $QueueTimeOutURL,
            'ResultURL' => $ResultURL
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $curl_response = curl_exec($curl);
        return $curl_response;
    }

    /**
     * Use this function to make a transaction status request
     * @param $Initiator | The name of Initiator to initiating the request.
     * @param $SecurityCredential | 	Encrypted password for the initiator to autheticate the transaction request.
     * @param $CommandID | Unique command for each transaction type, possible values are: TransactionStatusQuery.
     * @param $TransactionID | Organization Receiving the funds.
     * @param $PartyA | Organization/MSISDN sending the transaction
     * @param $IdentifierType | Type of organization receiving the transaction
     * @param $ResultURL | The path that stores information of transaction
     * @param $QueueTimeOutURL | The path that stores information of time out transaction
     * @param $Remarks | 	Comments that are sent along with the transaction
     * @param $Occasion | 	Optional Parameter
     * @return mixed|string
     */
    public function transactionStatus($Initiator, $SecurityCredential, $CommandID, $TransactionID, $PartyA, $IdentifierType, $ResultURL, $QueueTimeOutURL, $Remarks, $Occasion){

        $environment = $this->environment;

        if( $environment =="live"){
            $url = 'https://api.safaricom.co.ke/mpesa/transactionstatus/v1/query';
            $this->generateLiveToken();
        }elseif ($environment=="sandbox"){
            $url = 'https://sandbox.safaricom.co.ke/mpesa/transactionstatus/v1/query';
            $this->generateSandBoxToken();
        }else{
            return json_encode(["Message"=>"invalid application status"]);
        }

        $token = $this->getAccessToken();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$token)); //setting custom header


        $curl_post_data = array(
            'Initiator' => $Initiator,
            'SecurityCredential' => $SecurityCredential,
            'CommandID' => $CommandID,
            'TransactionID' => $TransactionID,
            'PartyA' => $PartyA,
            'IdentifierType' => $IdentifierType,
            'ResultURL' => $ResultURL,
            'QueueTimeOutURL' => $QueueTimeOutURL,
            'Remarks' => $Remarks,
            'Occasion' => $Occasion
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $curl_response = curl_exec($curl);


        return $curl_response;
    }


    /**
     * Use this function to initiate a B2B request
     * @param $Initiator | This is the credential/username used to authenticate the transaction request.
     * @param $SecurityCredential | Encrypted password for the initiator to autheticate the transaction request.
     * @param $Amount | Base64 encoded string of the B2B short code and password, which is encrypted using M-Pesa public key and validates the transaction on M-Pesa Core system.
     * @param $PartyA | Organization’s short code initiating the transaction.
     * @param $PartyB | Organization’s short code receiving the funds being transacted.
     * @param $Remarks | Comments that are sent along with the transaction.
     * @param $QueueTimeOutURL | The path that stores information of time out transactions.it should be properly validated to make sure that it contains the port, URI and domain name or publicly available IP.
     * @param $ResultURL | The path that receives results from M-Pesa it should be properly validated to make sure that it contains the port, URI and domain name or publicly available IP.
     * @param $AccountReference | Account Reference mandatory for “BusinessPaybill” CommandID.
     * @param $commandID | Unique command for each transaction type, possible values are: BusinessPayBill, MerchantToMerchantTransfer, MerchantTransferFromMerchantToWorking, MerchantServicesMMFAccountTransfer, AgencyFloatAdvance
     * @param $SenderIdentifierType | Type of organization sending the transaction.
     * @param $RecieverIdentifierType | Type of organization receiving the funds being transacted.

     * @return mixed|string
     */
    public function b2b($Initiator, $SecurityCredential, $Amount, $PartyA, $PartyB, $Remarks, $QueueTimeOutURL, $ResultURL, $AccountReference, $commandID, $SenderIdentifierType, $RecieverIdentifierType){

        $environment = $this->environment;

        if( $environment =="live"){
            $url = 'https://api.safaricom.co.ke/mpesa/b2b/v1/paymentrequest';
            $this->generateLiveToken();
        }elseif ($environment=="sandbox"){
            $url = 'https://sandbox.safaricom.co.ke/mpesa/b2b/v1/paymentrequest';
            $this->generateSandBoxToken();
        }else{
            return json_encode(["Message"=>"invalid application status"]);
        }

        $token = $this->getAccessToken();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$token)); //setting custom header
        $curl_post_data = array(
            'Initiator' => $Initiator,
            'SecurityCredential' => $SecurityCredential,
            'CommandID' => $commandID,
            'SenderIdentifierType' => $SenderIdentifierType,
            'RecieverIdentifierType' => $RecieverIdentifierType,
            'Amount' => $Amount,
            'PartyA' => $PartyA,
            'PartyB' => $PartyB,
            'AccountReference' => $AccountReference,
            'Remarks' => $Remarks,
            'QueueTimeOutURL' => $QueueTimeOutURL,
            'ResultURL' => $ResultURL
        );
        $data_string = json_encode($curl_post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        $curl_response = curl_exec($curl);
        return $curl_response;

    }

    /**
     * Use this function to initiate an STKPush Simulation
     * @param $BusinessShortCode | The organization shortcode used to receive the transaction.
     * @param $LipaNaMpesaPasskey | The password for encrypting the request. This is generated by base64 encoding BusinessShortcode, Passkey and Timestamp.
     * @param $TransactionType | The transaction type to be used for this request. Only CustomerPayBillOnline is supported.
     * @param $Amount | The amount to be transacted.
     * @param $PartyA | The MSISDN sending the funds.
     * @param $PartyB | The organization shortcode receiving the funds
     * @param $PhoneNumber | The MSISDN sending the funds.
     * @param $CallBackURL | The url to where responses from M-Pesa will be sent to.
     * @param $AccountReference | Used with M-Pesa PayBills.
     * @param $TransactionDesc | A description of the transaction.
     * @param $Remark | Remarks
     * @return mixed|string
     */
    public function STKPushSimulation($BusinessShortCode, $LipaNaMpesaPasskey, $TransactionType, $Amount, $PartyA, $PartyB, $PhoneNumber, $CallBackURL, $AccountReference, $TransactionDesc, $Remark){

        $environment = $this->environment;

        if( $environment =="live"){
            $url = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
            $this->generateLiveToken();
        }elseif ($environment=="sandbox"){
            $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
            $this->generateSandBoxToken();
        }else{
            return json_encode(["Message"=>"invalid application status"]);
        }

        $token = $this->getAccessToken();

        $timestamp= date(    "Ymdhis");
        $password=base64_encode($BusinessShortCode.$LipaNaMpesaPasskey.$timestamp);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$token));


        $curl_post_data = array(
            'BusinessShortCode' => $BusinessShortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => $TransactionType,
            'Amount' => $Amount,
            'PartyA' => $PartyA,
            'PartyB' => $PartyB,
            'PhoneNumber' => $PhoneNumber,
            'CallBackURL' => $CallBackURL,
            'AccountReference' => $AccountReference,
            'TransactionDesc' => $TransactionDesc
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $curl_response=curl_exec($curl);
        return $curl_response;

    }


    /**
     * Use this function to initiate an STKPush Status Query request.
     * @param $checkoutRequestID | Checkout RequestID
     * @param $businessShortCode | Business Short Code
     * @param $password | Password
     * @param $timestamp | Timestamp
     * @return mixed|string
     */
    public function STKPushQuery($checkoutRequestID, $businessShortCode, $password, $timestamp){

        $environment =$this->environment;

        if( $environment =="live"){
            $url = 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
            $this->generateLiveToken();
        }elseif ($environment=="sandbox"){
            $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';
            $this->generateSandBoxToken();
        }else{
            return json_encode(["Message"=>"invalid application status"]);
        }
        $token = $this->getAccessToken();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$token));

        $curl_post_data = array(
            'BusinessShortCode' => $businessShortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestID
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HEADER, false);

        $curl_response = curl_exec($curl);

        return $curl_response;
    }

    /**
     *Use this function to confirm all transactions in callback routes
     */
    public function finishTransaction($status = true)
    {
        if ($status === true) {
            $resultArray=[
                "ResultDesc"=>"Confirmation Service request accepted successfully",
                "ResultCode"=>"0"
            ];
        } else {
            $resultArray=[
                "ResultDesc"=>"Confirmation Service not accepted",
                "ResultCode"=>"1"
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($resultArray);
    }


    /**
     *Use this function to get callback data posted in callback routes
     */
    public function getDataFromCallback(){
        $callbackJSONData=file_get_contents('php://input');
        return $callbackJSONData;
    }

}
