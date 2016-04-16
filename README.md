# php-standalone-sqs
A tiny PHP class to help send Amazon SQS messages using Signature v4

## Basic Usage

```php
require_once 'SQS.php';

$sqs = new SQS($awsKey, $awsSecret);

$queuUrl = 'https://sqs.ap-southeast-1.amazonaws.com/<account-id>/<queue-name>';

$sqs->sendMessage($queueUrl, base64_encode($message), $delay);

```
