<?php

namespace app\models;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;

class TrackJob extends BaseObject implements JobInterface
{
    public array $queryParams;

    private function sendAnalyticsRequest($analyticsVersion, $payload, $idSite, $testId, $userId): void {
        $redis = Yii::$app->redis;
        $guzzle_client = new Client([
            'timeout' => 10.0,
        ]);

        try {
            $response = $guzzle_client->request('GET', env('ANALYTICS_ENDPOINT_' . strtoupper($analyticsVersion)), $payload);
            // Save request for debugging purposes
            $responseCode = $response->getStatusCode();
            $key = $analyticsVersion . ':' . $responseCode . ':' . $idSite . ':' . $testId . ':' . $userId;
            $redis->set($key, json_encode($payload['query']));
        } catch (GuzzleException $e) {
            // Log the failed request to Redis
            $key = 'failed:' . $analyticsVersion . ':' . $idSite . ':' . $testId . ':' . $userId;
            $redis->set($key, json_encode([
                'error' => $e->getMessage(),
                'payload' => $payload
            ]));
            // Notify Bugsnag
            Yii::$app->bugsnag->notifyException($e);
        }
    }

    private function getTestInfo($testId, $idSite): array|object {
        $testInfo = [];
        $redis = Yii::$app->redis;
        $guzzleClient = new Client([
            'timeout' => 10.0
        ]);
        $figpiiClientAuth = [
            'headers' => [
                'X-Token' => env('FIGPII_X_TOKEN'),
                'Cookie' => 'SFSESSIDCSMT=' . env('FIGPII_CSRF') . ';'
            ]
        ];
        $testRedisKey = $testId . ':' . $idSite;
        if ($redis->exists($testRedisKey)) {
            $testInfo = json_decode($redis->get($testRedisKey), true);
        } else {
            try {
                $testInfoRequest = $guzzleClient->request('GET', env('TOOLS_ENDPOINT') . $testId, $figpiiClientAuth);
                if ($testInfoRequest->getStatusCode() == 200) {
                    $testInfoJson = json_decode($testInfoRequest->getBody(), true);
                    $testInfo = $testInfoJson['result'];
                    if ($testInfo) {
                        $redis->set($testRedisKey, json_encode($testInfo));
                        $ttl = 3 * 24 * 60 * 60;
                        $redis->expire($testRedisKey, $ttl);
                    }
                }
            } catch (GuzzleException $e) {
                // Log the failed request to Redis
                $key = 'failed:test_info:' . $testId . ':' . $idSite;
                $redis->set($key, json_encode([
                    'error' => $e->getMessage(),
                    'test_id' => $testId,
                    'id_site' => $idSite
                ]));
                // Notify Bugsnag
                Yii::$app->bugsnag->notifyException($e);
            }
        }
        return $testInfo;
    }

    public function execute($queue): void {
        // Event parameters from query parameters
        $eventName = $this->queryParams['event_name'] ?? $this->queryParams['e_c'];
        $eventAction = "ManualConversion";
        $rec = 1;
        $idSite = (int)($this->queryParams['idsite'] ?? $this->queryParams['analytics_id']);
        $revenue = $this->queryParams['revenue'] ?? null; // Check if revenue is set
        $testId = (int)($this->queryParams['test_id'] ?? $this->queryParams['dimension1']);
        $variationId = (int)($this->queryParams['variation_id'] ?? $this->queryParams['dimension2']);
        $eventUrl = $this->queryParams['url'] ?? $this->queryParams['event_url'] ?? null; // Check if url is set
        $userId = $this->queryParams['user_id'] ?? $this->queryParams['uid'] ?? $this->queryParams['vid'];
        $sendImage = 0;
        $rand = time();

        $testInfo = $this->getTestInfo($testId, $idSite);

        // Handle different analytics versions
        $analyticsVersion = $testInfo['analytic_version'];
        switch ($analyticsVersion) {
            case 'v1':
                // A single request is enough for v1 analytics
                $options = [
                    'query' => [
                        'e_n' => $eventName,
                        'e_a' => $eventAction,
                        'idsite' => $idSite,
                        'rec' => $rec,
                        'uid' => $userId,
                        'dimension1' => $testId,
                        'dimension2' => $variationId,
                        'send_image' => $sendImage,
                        'rand' => $rand
                    ]
                ];
                if ($revenue !== null) {
                    $options['query']['revenue'] = (float)$revenue;
                }
                if ($eventUrl !== null) {
                    $options['query']['url'] = urlencode($eventUrl);
                }
                $this->sendAnalyticsRequest($analyticsVersion, $options, $idSite, $testId, $userId);
                break;
            case 'v2':
                // v2 requires 2 requests, one to send figpiiexperiment event second one to send the actual event;
                $figpiiExperimentEventOptions = [
                    'query' => [
                        'e_c' => 'figpiiexperiment',
                        'e_a' => $testId,
                        'e_n' => $variationId,
                        'ca' => 1,
                        'idsite' => $idSite,
                        'rec' => $rec,
                        'uid' => $userId,
                        'send_image' => $sendImage,
                        'rand' => $rand
                    ]
                ];
                if ($eventUrl !== null) {
                    $figpiiExperimentEventOptions['query']['url'] = urlencode($eventUrl);
                }
                $eventOptions = [
                    'query' => [
                        'e_n' => $eventName,
                        'e_a' => $eventAction,
                        'idsite' => $idSite,
                        'rec' => $rec,
                        'uid' => $userId,
                        'send_image' => $sendImage,
                        'rand' => $rand
                    ]
                ];
                if ($revenue !== null) {
                    $eventOptions['query']['revenue'] = (float)$revenue;
                }
                if ($eventUrl !== null) {
                    $eventOptions['query']['url'] = urlencode($eventUrl);
                }
                $this->sendAnalyticsRequest($analyticsVersion, $figpiiExperimentEventOptions, $idSite, $testId, $userId);
                $this->sendAnalyticsRequest($analyticsVersion, $eventOptions, $idSite, $testId, $userId);
                break;
        }
    }
}
