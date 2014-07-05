<?php namespace Milkyway\SendThis\Transports;

/**
 * Milkyway Multimedia
 * SendThis_Mandrill.php
 *
 * @package milkyway-multimedia/silverstripe-send-this
 * @author Mellisa Hankins <mell@milkywaymultimedia.com.au>
 */
class Mandrill extends Mail {
    protected $endpoint = 'https://mandrillapp.com/api/1.0';

    protected $async = true;
    protected $sendAt;
    protected $returnPathDomain;

    function __construct(\PHPMailer $messenger) {
        parent::__construct($messenger);

        if(\SendThis::config()->endpoint)
            $this->endpoint = \SendThis::config()->endpoint;
    }

    function send(\PHPMailer $messenger, \ViewableData $log = null)
    {
        if(($key = \SendThis::config()->key)) {
            if(!$this->PreSend())
                return false;

            $response = $this->http()->post($this->endpoint('messages/send-raw'), [
                    'body' => [
                        'key' => $key,
                        'raw_message' => $messenger->GetSentMIMEMessage(),
                        'async' => true,
                    ],
                ]
            );

            return $this->handleResponse($response, $messenger, $log);
        }

        throw new \SendThis_Exception('Invalid API Key. Could not connect to Mandrill.');
    }

    public function handleResponse(\GuzzleHttp\Message\ResponseInterface $response, $messenger = null, $log = null) {
        $body = $response->getBody();
        $message = '';

        if(!$body)
            $message = 'Empty response received from Mandrill' . "\n";

        $results = $response->json();

        if($log && isset($results['_id']))
            $log->MessageID = $results['_id'];

        $status = isset($results['status']) ? $results['status'] : 'failed';

        if((($statusCode = $response->getStatusCode()) && ($statusCode < 200 || $this->statusCode > 399))
           || !in_array($status, ['sent', 'queued', 'scheduled'])) {
            $message = 'Problem sending via Mandrill' . "\n";
            $message .= urldecode(http_build_query($results, '', "\n"));
        }

        if($message) {
            if($log)
                $log->Success = false;
            
            $message .= 'Status Code: ' . $response->getStatusCode() . "\n";
            $message .= 'Message: ' . $response->getReasonPhrase();
            throw new \SendThis_Exception($message);
        }

        if($log) {
            $log->Success = true;
            $log->Sent = date('Y-m-d H:i:s');
        }

        return true;
    }

    /**
     * Get a new HTTP client instance.
     *
     * @return \Guzzle\Http\Client
     */
    protected function http()
    {
        return new \GuzzleHttp\Client;
    }

    protected function endpoint($action = '')
    {
        return Controller::join_links($this->endpoint, $action . '.json');
    }

    public function applyHeaders(array &$headers) {
        if(isset($headers['X-SendAt'])) {
            $this->sendAt = $headers['X-SendAt'];
            unset($headers['X-SendAt']);
        }

        if(array_key_exists($headers, 'X-Async')) {
            $this->async = $headers['X-Async'];
            unset($headers['X-Async']);
        }

        if(array_key_exists($headers, 'X-ReturnPathDomain')) {
            $this->returnPathDomain = $headers['X-ReturnPathDomain'];
            unset($headers['X-ReturnPathDomain']);
        }

        if(!isset($headers['X-MC-Track'])) {
            if(\SendThis::config()->tracking || \SendThis::config()->api_tracking) {
                $headers['X-MC-Track'] = 'opens,clicks_htmlonly';
            }
        }

        $mandrill = \SendThis::config()->mandrill;

        if($mandrill && count($mandrill)) {
            foreach($mandrill as $setting => $value) {
                $header = 'X-MC-' . $setting;

                if(!isset($headers[$header]))
                    $headers[$header] = $value;
            }
        }

        if(\SendThis::config()->sub_account)
            $headers['X-MC-Subaccount'] = \SendThis::config()->sub_account;
    }
}