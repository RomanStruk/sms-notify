<?php

namespace RomanStruk\SmsNotify\Clients\TurboSms;

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface as GuzzleResponseInterface;
use RomanStruk\SmsNotify\Response\FailDeliveryReport;
use RomanStruk\SmsNotify\Response\Response;
use RomanStruk\SmsNotify\Response\SuccessDeliveryReport;

class TurboSmsClient
{
    protected $urlMessageSend = 'https://api.turbosms.ua/message/send.json';
    protected $urlMessageSendPing = 'https://api.turbosms.ua/message/ping.json';
    protected $urlUserBalance = 'https://api.turbosms.ua/user/balance.json';
    /**
     * @var string[]
     */

    private $headers;

    /**
     * @var GuzzleHttpClient
     */
    private $guzzleClient;

    private $sender;

    public function __construct(string $token, string $sender)
    {
        $this->sender = $sender;

        $this->guzzleClient = new GuzzleHttpClient();

        $this->headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept-Charset' => 'utf-8',
        ];
    }

    /**
     * @param string $sender
     * @param array $recipients
     * @param string $text
     * @return array
     */
    private function prepareSmsJson(string $sender, array $recipients, string $text): array
    {
        return [
            'recipients' => $recipients,
            'sms' => [
                'sender' => $sender,
                'text' => $text
            ]
        ];
    }

    /**
     * @throws JsonException
     */
    private function decodeContent(string $content): array
    {
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function sendSms(array $recipients, string $text): Response
    {
        $response = new Response();
        try {
            $guzzleResponse = $this->request($this->urlMessageSend, $this->headers, $this->prepareSmsJson($this->sender, $recipients, $text));
            $content = $this->decodeContent($guzzleResponse->getBody()->getContents());


            $code = $content['response_code'] ?? null;

            if ($code === 0 || $code === 800 || $code === 801 || $code === 802 || $code === 803) {
                foreach ($content['response_result'] as $responseResult) {
                    $response->setDeliveryReport(
                        $responseResult['response_code'] === 0 ?
                            new SuccessDeliveryReport($responseResult['phone'], $responseResult['message_id'], $responseResult['response_code'], $responseResult['response_status']) :
                            new FailDeliveryReport($responseResult['phone'], $responseResult['response_code'], $responseResult['response_status'])
                    );
                }
            }
            if ($code === 301) {
                $response->setDeliveryReport(FailDeliveryReport::unauthenticated($recipients));
            }
            $response->setDebugInformation('guzzleResponse', $content);
        } catch (\Exception $exception) {
            $response->setDeliveryReport(new FailDeliveryReport(implode(',', $recipients), $exception->getMessage(), $exception->getCode()));
        }
        return $response;
    }

    /**
     * @throws GuzzleException
     * @throws JsonException
     */
    public function sendSmsPing(array $recipients, string $text): Response
    {
        $guzzleResponse = $this->request($this->urlMessageSendPing, $this->headers, $this->prepareSmsJson($this->sender, $recipients, $text));
        $content = $this->decodeContent($guzzleResponse->getBody()->getContents());

        if ($content['response_code'] === 301) {
            $report = FailDeliveryReport::unauthenticated($recipients);
        }else{
            $report = new SuccessDeliveryReport(implode(',', $recipients), '', $content['response_status'], $content['response_code']);
        }
        return new Response($report);
    }

    /**
     * @param $url
     * @param $headers
     * @param $json
     * @return GuzzleResponseInterface
     * @throws GuzzleException
     */
    protected function request($url, $headers, $json): GuzzleResponseInterface
    {
        return $this->guzzleClient->post($url, ['headers' => $headers, 'json' => $json]);
    }
}