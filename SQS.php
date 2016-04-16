<?php
/**
* Amazon SQS PHP class
*
* Modified to:
* - Use Signature v4
* - Send messages only
*
* Some parts yanked from: http://sourceforge.net/projects/php-sqs/
* Other parts yanked from: https://github.com/aws/aws-sdk-php
* Remaining parts yanked from the mind of: Akshay Bazad <AkshayBz@yahoo.co.in>
*/
class SQS
{
    const ISO8601_BASIC = 'Ymd\THis\Z';

    protected $accessKey;
    protected $secretKey;

    static protected $cache = array();

    /**
    * Constructor - this class cannot be used statically
    *
    * @param string $accessKey - AWS Access key
    * @param string $secretKey - AWS Secret key
    */
    public function __construct($accessKey, $secretKey)
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
    }

    /**
     * Send a message to a queue
     *
     * @param string $queueUrl - The queue URL which will receive the message
     *                           Format: https://sqs.ap-southeast-1.amazonaws.com/<account-id>/<queue-name>
     * @param string $message  - The body of the message to send
     * @param int    $delay    - Delay in seconds
     *
     * @return integer - Message ID
     */
    public function sendMessage($queueUrl, $message, $delay = 0)
    {
        $parameters = array(
            'Action'       => 'SendMessage',
            'MessageBody'  => $message,
            'DelaySeconds' => $delay,
            'TimeStamp'    => gmdate('Y-m-d\TH:i:s\Z')
        );

        $result = $this->sendRequest($queueUrl, $parameters, 'POST');

        $messageId = $result->SendMessageResult->MessageId;

        if (is_null($messageId)) {
            $errorMessage = sprintf(
                "SQS::%s(): Error %s caused by %s. \n Message: %s",
                __FUNCTION__,
                $result->Code,
                $result->Type,
                $result->Message
            );

            $detail = (string) $result->Error->Detail;

            if (strlen($detail) > 0) {
                $errorMessage .= sprintf("Detail: %s\n", $error['Detail']);
            }

            throw new \Exception($errorMessage);
        }

        return $messageId;
    }

    protected function sendRequest($queueUrl, $parameters, $requestMethod)
    {
        $requestMethod = strtoupper($requestMethod);

        list($scheme, $host, $path, $region) = $this->parseQueueUrl($queueUrl);

        $cleanUrl = $scheme . '://' . $host . $path;

        $parameters += array(
            'Version'  => '2012-11-05',
            'QueueUrl' => $cleanUrl,
        );

        $query = http_build_query($parameters);
        $headers = $this->getRequestHeaders($cleanUrl, $query);

        $curlOpts = array(
            CURLOPT_URL            => $cleanUrl,
            CURLOPT_CUSTOMREQUEST  => $requestMethod,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true
        );
        if ($scheme === 'https') {
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 2;
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = true;
        }
        if ($requestMethod == 'POST') {
            $curlOpts[CURLOPT_POSTFIELDS] = $query;
        }

        // Setup & Execute cURL request
        $curl = curl_init();
        curl_setopt_array($curl, $curlOpts);
        $response = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (curl_errno($curl)) {
            $error = array(
                'code' => curl_errno($curl),
                'message' => curl_error($curl)
            );

            $errorMessage = "SQS::%s(): cURL Request errored \n %s";

            throw new \Exception(sprintf($errorMessage, __FUNCTION__, var_export($error, true)));
        }
        @curl_close($curl);

        // Parse body into XML
        $result = new \SimpleXMLElement($response);

        if ($statusCode !== 200) {
            $error = array(
                'code'    => $statusCode,
                'message' => $result
            );

            $errorMessage = "SQS::%s(): SQS Returned unexpected HTTP Status Code \n %s";

            throw new \Exception(sprintf($errorMessage, __FUNCTION__, var_export($error, true)));
        }

        return $result;
    }

    /**
     * Returns the signed request headers with Authorization
     *
     * @param string $queueUrl
     * @param string $query
     * @param string $requestMethod
     *
     * @return array
     */
    private function getRequestHeaders($queueUrl, $query, $requestMethod = 'POST')
    {
        list($scheme, $host, $path, $region) = $this->parseQueueUrl($queueUrl);

        $longDate = gmdate(self::ISO8601_BASIC);
        $shortDate = substr($longDate, 0, 8);

        $requestHeaders = array(
            'Host'       => $host,
            'X-Amz-Date' => $longDate
        );

        if ($requestMethod == 'POST') {
            $requestHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $parsedRequest = array(
            'method'  => $requestMethod,
            'path'    => $path,
            'headers' => $requestHeaders
        );

        // Payload is the hash of query string
        $payload = hash('sha256', $query);
        // Generate canonical request & headers
        $context = $this->createContext($parsedRequest, $payload);
        $hashedCanon = hash('sha256', $context['creq']);
        $credentialScope = "{$shortDate}/{$region}/sqs/aws4_request";

        $toSign = "AWS4-HMAC-SHA256" .
            "\n{$longDate}" .
            "\n{$credentialScope}" .
            "\n{$hashedCanon}";

        $signingKey = $this->getSigningKey($shortDate, $region);
        $signature = hash_hmac('sha256', $toSign, $signingKey);

        $parsedRequest['headers']['Authorization'] = "AWS4-HMAC-SHA256 " .
            "Credential={$this->accessKey}/{$credentialScope}, " .
            "SignedHeaders={$context['headers']}, " .
            "Signature={$signature}";

        $headers = array();
        foreach ($parsedRequest['headers'] as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        return $headers;
    }

    /**
     * Pulls out Scheme, Host, Path & Region from the queue URL
     *
     * @param string $queueUrl
     *
     * @return array
     */
    private function parseQueueUrl($queueUrl)
    {
        $urlParts = parse_url($queueUrl);
        // Extract parts from the URL Host
        $hostParts = explode('.', $urlParts['host'], 3);
        // Extract region from the queue URL
        $region = @$hostParts[1] ?: 'ap-southeast-1';

        return array($urlParts['scheme'], $urlParts['host'], $urlParts['path'], $region);
    }

    /**
     * METHODS BELOW THIS LINE WERE PICKED FROM AWS PHP SDK.
     *
     * Source file in AWS SDK: src/Signature/SignatureV4.php
     * They are not an exact copy, they have been modified to suit our needs.
     */

     /**
     * @param array  $parsedRequest - Parsed Request Array
     * @param string $payload       - Hash of the request payload
     *
     * @return array - Returns an array of context information
     */
    private function createContext(array $parsedRequest, $payload)
    {
        // The following headers are not signed because signing these headers
        // would potentially cause a signature mismatch when sending a request
        // through a proxy or if modified at the HTTP client level.
        static $blacklist = array(
            'cache-control'       => true,
            'content-type'        => true,
            'content-length'      => true,
            'expect'              => true,
            'max-forwards'        => true,
            'pragma'              => true,
            'range'               => true,
            'te'                  => true,
            'if-match'            => true,
            'if-none-match'       => true,
            'if-modified-since'   => true,
            'if-unmodified-since' => true,
            'if-range'            => true,
            'accept'              => true,
            'authorization'       => true,
            'proxy-authorization' => true,
            'from'                => true,
            'referer'             => true,
            'user-agent'          => true
        );

        // Normalize the path as required by SigV4
        $canon = $parsedRequest['method'] . "\n"
            . $this->createCanonicalizedPath($parsedRequest['path']) . "\n\n";

        // Case-insensitively aggregate all of the headers.
        $aggregate = [];
        foreach ($parsedRequest['headers'] as $key => $values) {
            $key = strtolower($key);
            if (!isset($blacklist[$key])) {
                if (is_array($values)) {
                    foreach ($values as $v) {
                        $aggregate[$key][] = $v;
                    }
                } else {
                    $aggregate[$key][] = $values;
                }
            }
        }

        ksort($aggregate);
        $canonHeaders = [];
        foreach ($aggregate as $k => $v) {
            if (count($v) > 0) {
                sort($v);
            }
            $canonHeaders[] = $k . ':' . preg_replace('/\s+/', ' ', implode(',', $v));
        }

        $signedHeadersString = implode(';', array_keys($aggregate));
        $canon .= implode("\n", $canonHeaders) . "\n\n"
            . $signedHeadersString . "\n"
            . $payload;

        return array('creq' => $canon, 'headers' => $signedHeadersString);
    }

    private function createCanonicalizedPath($path)
    {
        $doubleEncoded = rawurlencode(ltrim($path, '/'));

        return '/' . str_replace('%2F', '/', $doubleEncoded);
    }

    private function getSigningKey($shortDate, $region)
    {
        $k = $shortDate . '_' . $region . '_' . $this->secretKey;

        if (isset(self::$cache[$k])) {
            return self::$cache[$k];
        }

        if (count(self::$cache) > 50) {
            // Clearing the cache
            self::$cache = [];
        }

        $dateKey = hash_hmac('sha256', $shortDate, "AWS4{$this->secretKey}", true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 'sqs', $regionKey, true);

        self::$cache[$k] = hash_hmac('sha256', 'aws4_request', $serviceKey, true);

        return self::$cache[$k];
    }
}
