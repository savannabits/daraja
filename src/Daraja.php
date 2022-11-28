<?php

namespace Savannabits\Daraja;

use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;

class Daraja
{
    private string $consumer_key, $consumer_secret, $access_token, $environment = 'sandbox';

    const CANCELLED = 'Cancelled';

    const SAND_BASE_URL = 'https://sandbox.safaricom.co.ke';
    const LIVE_BASE_URL = 'https://api.safaricom.co.ke';

    const TOKEN_URL = '/oauth/v1/generate';
    const C2B_V2_REGISTER_URL = '/mpesa/c2b/v2/registerurl';
    const C2B_V1_REGISTER_URL = '/mpesa/c2b/v1/registerurl';
    const C2B_V1_SIMULATE_URL = '/mpesa/c2b/v1/simulate';
    const C2B_V2_SIMULATE_URL = '/mpesa/c2b/v2/simulate';
    const LNM_PROCESS_URL = '/mpesa/stkpush/v1/processrequest';
    const LNM_QUERY_URL = '/mpesa/stkpushquery/v1/query';

    const ERROR_INVALID_MSISDN          = 'C2B00011';
    const ERROR_INVALID_ACCOUNT_NUMBER  = 'C2B00012';
    const ERROR_INVALID_AMOUNT          = 'C2B00013';
    const ERROR_INVALID_KYC_DETAILS     = 'C2B00014';
    const ERROR_OTHER                   = 'C2B00016';

    public static function getInstance(): Daraja
    {
        return new self();
    }
    protected function getUrl($endpoint): string
    {
        $base = $this->isLive() ? self::LIVE_BASE_URL : self::SAND_BASE_URL;
        return "$base{$endpoint}";
    }

    protected function isSandbox(): bool
    {
        return !$this->isLive();
    }

    protected function isLive(): bool
    {
        return $this->environment === 'live';
    }

    /**
     * Generate instance's member credentials during runtime which will be used to generate tokens and make other calls
     * @param string $consumer_key
     * @param string $consumer_secret
     * @param bool|null $live
     * @return Daraja
     * @throws RequestException
     */
    public function setCredentials(string $consumer_key, string $consumer_secret, ?bool $live = false): static
    {
        abort_unless($consumer_key && $consumer_secret, 500, "You must pass both the consumer key and consumer secret");
        $this->consumer_key = $consumer_key;
        $this->consumer_secret = $consumer_secret;
        $this->environment = $live ? 'live' : 'sandbox';
        return $this->generateToken();
    }

    /**
     * This is used to generate tokens for the live or sandbox environment
     * @return Daraja
     */
    private function generateToken(): static
    {
        $consumer_key = $this->consumer_key;
        $consumer_secret = $this->consumer_secret;
        $url = $this->getUrl(self::TOKEN_URL);
        try {
            $response = Http::withBasicAuth($consumer_key, $consumer_secret)
                ->get($url, ['grant_type' => 'client_credentials'])
                ->throw()->collect();
        } catch (RequestException $e) {
            Log::info($e);
            abort($e->response?->status() ?? 400, $e->response->reason());
        }
        /*
         * Response Structure
         *   {
         *       "access_token": "orjGJVfE3WTivTpPaDEBAGdMaPTM",
         *       "expires_in": "3599"
         *   }
        */
        $this->access_token = $response->get('access_token');
        abort_unless($this->access_token, 500, "Access token could not be generated");
        return $this;
    }

    public function getAccessToken(): string
    {
        return $this->access_token;
    }

    /**
     * Register Validation and Confirmation Callbacks
     * @param string $shortCode | The MPESA Short Code
     * @param string $confirmationURL | Confirmation Callback URL
     * @param string|null $validationURL | Validation Callback URL
     * @param string|null $responseType | Default Response type in case the validation URL is unreachable or is undefined. Either Cancelled or Completed as per the safaricom documentation
     * @param string|null $darajaVersion
     * @return Collection
     * @throws RequestException
     */
    public function registerCallbacks(string $shortCode, string $confirmationURL, ?string $validationURL = null, ?string $responseType = self::CANCELLED, ?string $darajaVersion='v2'): Collection
    {
        $url = strtolower($darajaVersion) ==='v1' ? $this->getUrl(self::C2B_V1_REGISTER_URL) : $this->getUrl(self::C2B_V2_REGISTER_URL);
        $token = $this->getAccessToken();
        $payload = array(
            "ValidationURL" => $validationURL,
            "ConfirmationURL" => $confirmationURL,
            "ResponseType" => $responseType,
            "ShortCode" => $shortCode
        );
        return Http::withToken($token)->withoutVerifying()->acceptJson()->post($url, $payload)->throw()->collect();
    }

    /**
     * Use this function to initiate a reversal request. This is an abstracted function that takes care of SecurityCredential Generation
     * @param $Initiator | The name of Initiator - Username of user initiating the transaction
     * @param $InitiatorPassword |    Encrypted Initiator Password / security credential
     * @param $TransactionID | Unique ID received with every transaction response.
     * @param $Amount | Amount
     * @param $ReceiverParty | Organization /MSISDN sending the transaction
     * @param $ResultURL | The path that stores information of transaction
     * @param $QueueTimeOutURL | The path that stores information of time out transaction
     * @param $Remarks | Comments that are sent along with the transaction.
     * @param $Occasion |    Optional Parameter
     * @return mixed|string
     * @throws Exception
     */
    public function reverseTransaction(
        $Initiator,
        $InitiatorPassword,
        $TransactionID,
        $Amount,
        $ReceiverParty,
        $ResultURL,
        $QueueTimeOutURL,
        $Remarks,
        $Occasion,
        $ReceiverIdentifierType = 11
    )
    {
//        $SecurityCredential = $this->encryptInitiatorPassword($InitiatorPassword,$this->environment);
        $CommandID = 'TransactionReversal';
        return $this->reversal($CommandID, $Initiator, $InitiatorPassword, $TransactionID, $Amount, $ReceiverParty, $ReceiverIdentifierType, $ResultURL, $QueueTimeOutURL, $Remarks, $Occasion);
    }

    /**
     * Use this function to initiate a transaciton status query
     * @param $Initiator | The username of the user initiating the transaction. This is the credential/username used to authenticate the transaction request
     * @param $InitiatorPassword | Encrypted Security Credential. see daraja docs on how to encrypt
     * @param $TransactionID | Mpesa confirmation code of the trasaction whose query we are checking
     * @param $PartyA | The shortcode or msisdn of the organization that is receiving the transaction
     * @param $ResultURL | Where will the result be sent to
     * @param $QueueTimeOutURL | In case of a timeout, this url will be called.
     * @param $Remarks |Comments sent along with the transaction
     * @param $Occasion | Optional Parameter.
     * @param int $IdentifierType | The type of organization receiving the transaction: 1 = MSISDN, 2 = TILL, 4 = Org shortcode
     * @return mixed|string
     * @throws Exception
     */
    public function checkTransactionStatus($Initiator, $InitiatorPassword, $TransactionID, $PartyA, $ResultURL, $QueueTimeOutURL, $Remarks, $Occasion, $IdentifierType = 4)
    {
//        $SecurityCredential = $this->encryptInitiatorPassword($InitiatorPassword,$this->environment);
        $CommandID = 'TransactionStatusQuery';
        return $this->transactionStatus($Initiator, $InitiatorPassword, $CommandID, $TransactionID, $PartyA, $IdentifierType, $ResultURL, $QueueTimeOutURL, $Remarks, $Occasion);
    }

    /**
     * Use this function to initiate a reversal request. This is the lowest level function that can change even the Organization Id Type.
     * @param $CommandID | Takes only 'TransactionReversal' Command id
     * @param $Initiator | The name of Initiator to initiating  the request
     * @param $SecurityCredential |    Encrypted Credential of user getting transaction amount
     * @param $TransactionID | Unique ID received with every transaction response.
     * @param $Amount | Amount
     * @param $ReceiverParty | Organization /MSISDN sending the transaction
     * @param $RecieverIdentifierType | Type of organization receiving the transaction
     * @param $ResultURL | The path that stores information of transaction
     * @param $QueueTimeOutURL | The path that stores information of time out transaction
     * @param $Remarks | Comments that are sent along with the transaction.
     * @param $Occasion |    Optional Parameter
     * @return mixed|string
     * @throws RequestException
     */
    private function reversal($CommandID, $Initiator, $SecurityCredential, $TransactionID, $Amount, $ReceiverParty, $RecieverIdentifierType, $ResultURL, $QueueTimeOutURL, $Remarks, $Occasion)
    {

        $url = $this->getUrl('/mpesa/reversal/v1/request');
        $token = $this->generateToken();
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $token));

        $curl_post_data = array(
            'CommandID' => $CommandID,
            'Initiator' => $Initiator,
            'SecurityCredential' => $SecurityCredential,
            'TransactionID' => $TransactionID,
            'Amount' => $Amount,
            'ReceiverParty' => $ReceiverParty,
            'ReceiverIdentifierType' => $RecieverIdentifierType,
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
     * @param $InitiatorName |    This is the credential/username used to authenticate the transaction request.
     * @param $SecurityCredential | Encrypted password for the initiator to autheticate the transaction request
     * @param $CommandID | Unique command for each transaction type e.g. SalaryPayment, BusinessPayment, PromotionPayment
     * @param $Amount | The amount being transacted
     * @param $PartyA | Organization’s shortcode initiating the transaction.
     * @param $PartyB | Phone number receiving the transaction
     * @param $Remarks | Comments that are sent along with the transaction.
     * @param $QueueTimeOutURL | The timeout end-point that receives a timeout response.
     * @param $ResultURL | The end-point that receives the response of the transaction
     * @param $Occasion |    Optional
     * @return string
     * @throws RequestException
     */
    public function b2c($InitiatorName, $SecurityCredential, $CommandID, $Amount, $PartyA, $PartyB, $Remarks, $QueueTimeOutURL, $ResultURL, $Occasion): string
    {

        $url = $this->getUrl('/mpesa/b2c/v1/paymentrequest');
        $token = $this->generateToken();


        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $token));


        $curl_post_data = array(
            'InitiatorName' => $InitiatorName,
            'SecurityCredential' => $SecurityCredential,
            'CommandID' => $CommandID,
            'Amount' => $Amount,
            'PartyA' => $PartyA,
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
     * Use this function to simulate a C2B transaction
     * @param string $ShortCode | 6 digit M-Pesa Till Number or PayBill Number
     * @param int $Amount | The amount been transacted.
     * @param string $Msisdn | MSISDN (phone number) sending the transaction, start with country code without the plus(+) sign.
     * @param string $BillRefNumber |    Bill Reference Number (Optional).
     * @param bool $isBuyGoods
     * @param string|null $darajaVersion
     * @return Collection
     * @throws RequestException
     */
    public function c2b(string $ShortCode, int $Amount, string $Msisdn, string $BillRefNumber, bool $isBuyGoods = false, ?string $darajaVersion = 'v2'): Collection
    {
        abort_unless($this->isSandbox(), 403, "This functionality can only be used in sandbox mode.");
        $CommandID = $isBuyGoods ? "CustomerBuyGoodsOnline" : "CustomerPayBillOnline";
        $token = $this->getAccessToken();
        $payload = array(
            'ShortCode' => $ShortCode,
            'CommandID' => $CommandID,
            'Amount' => $Amount,
            'Msisdn' => $Msisdn,
            'BillRefNumber' => $BillRefNumber
        );
        $url =  strtolower($darajaVersion) ? $this->getUrl(self::C2B_V1_SIMULATE_URL) : $this->getUrl(self::C2B_V2_SIMULATE_URL);
        return Http::withToken($token)->withoutVerifying()->acceptJson()->post($url, $payload)->throw()->collect();
    }


    /**
     * Use this to initiate a balance inquiry request
     * @param $CommandID | A unique command passed to the M-Pesa system.
     * @param $Initiator |    This is the credential/username used to authenticate the transaction request.
     * @param $SecurityCredential | Encrypted password for the initiator to autheticate the transaction request
     * @param $PartyA | Type of organization receiving the transaction
     * @param $IdentifierType |Type of organization receiving the transaction
     * @param $Remarks | Comments that are sent along with the transaction.
     * @param $QueueTimeOutURL | The path that stores information of time out transaction
     * @param $ResultURL |    The path that stores information of transaction
     * @return string
     * @throws RequestException
     */
    public function accountBalance($CommandID, $Initiator, $SecurityCredential, $PartyA, $IdentifierType, $Remarks, $QueueTimeOutURL, $ResultURL): string
    {

        $url = $this->getUrl('/mpesa/accountbalance/v1/query');
        $token = $this->generateToken();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $token)); //setting custom header


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
        return curl_exec($curl);
    }

    /**
     * Use this function to make a transaction status request
     * @param $Initiator | The name of Initiator to initiating the request.
     * @param $SecurityCredential |    Encrypted password for the initiator to autheticate the transaction request.
     * @param $CommandID | Unique command for each transaction type, possible values are: TransactionStatusQuery.
     * @param $TransactionID | Organization Receiving the funds.
     * @param $PartyA | Organization/MSISDN sending the transaction
     * @param $IdentifierType | Type of organization receiving the transaction
     * @param $ResultURL | The path that stores information of transaction
     * @param $QueueTimeOutURL | The path that stores information of time out transaction
     * @param $Remarks |    Comments that are sent along with the transaction
     * @param $Occasion |    Optional Parameter
     * @return mixed|string
     * @throws RequestException
     */
    private function transactionStatus($Initiator, $SecurityCredential, $CommandID, $TransactionID, $PartyA, $IdentifierType, $ResultURL, $QueueTimeOutURL, $Remarks, $Occasion): mixed
    {

        $url = $this->getUrl('/mpesa/transactionstatus/v1/query');
        $token= $this->generateToken();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $token)); //setting custom header


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
     * @return string
     * @throws RequestException
     */
    public function b2b($Initiator, $SecurityCredential, $Amount, $PartyA, $PartyB, $Remarks, $QueueTimeOutURL, $ResultURL, $AccountReference, $commandID, $SenderIdentifierType, $RecieverIdentifierType): string
    {

        $url = $this->getUrl('/mpesa/b2b/v1/paymentrequest');
        $token= $this->generateToken();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $token)); //setting custom header
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
     * @param string $shortCode
     * @param string $PhoneNumber | The MSISDN sending the funds.
     * @param int $Amount | The amount to be transacted.
     * @param string $shortCode2
     * @param string $passKey
     * @param string $CallBackURL | The url to where responses from M-Pesa will be sent to.
     * @param string $TransactionDesc | A description of the transaction.
     * @param string|null $AccountReference | Used with M-Pesa PayBills.
     * @param bool|null $isBuyGoods
     * @return Collection
     * @throws RequestException
     */
    public function lipaNaMpesa(
        string  $shortCode,
        string  $PhoneNumber,
        int     $Amount,
        string  $shortCode2,
        string  $passKey,
        string  $CallBackURL,
        string  $TransactionDesc,
        ?string $AccountReference = null,
        ?bool   $isBuyGoods = false
    ): Collection
    {

        $TransactionType = $isBuyGoods ? "CustomerBuyGoodsOnline" : "CustomerPayBillOnline";
        $token = $this->getAccessToken();
        $url = $this->getUrl(self::LNM_PROCESS_URL);

        $timestamp = date("Ymdhis");
        $password = base64_encode($shortCode . $passKey . $timestamp);
        $payload = array(
            'BusinessShortCode' => $shortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => $TransactionType,
            'Amount' => "$Amount",
            'PartyA' => $PhoneNumber,
            'PartyB' => $shortCode2,
            'PhoneNumber' => $PhoneNumber,
            'CallBackURL' => $CallBackURL,
            'AccountReference' => $AccountReference,
            'TransactionDesc' => $TransactionDesc
        );
        Log::debug(collect($payload));
        return Http::withToken($token)->acceptJson()->post($url, $payload)->throw()->collect();
    }


    /**
     * Use this function to initiate an STKPush Status Query request.
     * @param $checkoutRequestID | Checkout RequestID
     * @param $shortCode
     * @param $passKey
     * @param $timestamp | Timestamp
     * @return Collection
     * @throws RequestException
     */
    public function lipaNaMpesaQuery($checkoutRequestID, $shortCode, $passKey, $timestamp): Collection
    {
        $password = base64_encode($shortCode . $passKey . $timestamp);
        $url = $this->getUrl(self::LNM_QUERY_URL);
        $token = $this->getAccessToken();
        $payload = array(
            'BusinessShortCode' => $shortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestID
        );
        return Http::withToken($token)->withoutVerifying()->acceptJson()->post($url, $payload)->throw()->collect();
    }

    #[ArrayShape(["ResultCode" => "int", "ResultDesc" => "string"])]
    public function validationAcceptResponse(): array
    {
        return [
            "ResultCode" => 0,
            "ResultDesc" => "Accepted"
        ];
    }
    #[ArrayShape(["ResultCode" => "mixed|string", "ResultDesc" => "string"])]
    public function validationRejectResponse(string $errorCode = self::ERROR_OTHER): array
    {
        return [
            "ResultCode" => $errorCode,
            "ResultDesc" => "Rejected"
        ];
    }
}
