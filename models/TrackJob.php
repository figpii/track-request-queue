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

    private function sendAnalyticsRequest($analytics_version, $payload, $id_site, $test_id, $user_id): void {
        $redis = Yii::$app->redis;
        $guzzle_client = new Client([
            'timeout' => 10.0,
        ]);

        try {
            $response = $guzzle_client->request('GET', env('ANALYTICS_ENDPOINT_' . strtoupper($analytics_version)), $payload);
            // Save request for debugging purposes
            $response_code = $response->getStatusCode();
            $key = $analytics_version . ':' . $response_code . ':' . $id_site . ':' . $test_id . ':' . $user_id;
            $redis->set($key, json_encode($payload['query']));
        } catch (GuzzleException $e) {
            // Log the failed request to Redis
            $key = 'failed:' . $analytics_version . ':' . $id_site . ':' . $test_id . ':' . $user_id;
            $redis->set($key, json_encode([
                'error' => $e->getMessage(),
                'payload' => $payload
            ]));
            // Notify Bugsnag
            Yii::$app->bugsnag->notifyException($e);
        }
    }

    private function getTestInfo($test_id, $id_site): array|object {
        $test_info = [];
        $redis = Yii::$app->redis;
        $guzzle_client = new Client([
            'timeout' => 10.0
        ]);
        $figpii_client_auth = [
            'headers' => [
                'X-Token' => env('FIGPII_X_TOKEN'),
                'Cookie' => 'SFSESSIDCSMT=' . env('FIGPII_CSRF') . ';'
            ]
        ];
        $test_redis_key = $test_id . ':' . $id_site;
        if ($redis->exists($test_redis_key)) {
            $test_info = json_decode($redis->get($test_redis_key), true);
        } else {
            try {
                $test_info_request = $guzzle_client->request('GET', env('TOOLS_ENDPOINT') . $test_id, $figpii_client_auth);
                if ($test_info_request->getStatusCode() == 200) {
                    $test_info_json = json_decode($test_info_request->getBody(), true);
                    $test_info = $test_info_json['result'];
                    if ($test_info) {
                        $redis->set($test_redis_key, json_encode($test_info));
                        $ttl = 3 * 24 * 60 * 60;
                        $redis->expire($test_redis_key, $ttl);
                    }
                }
            } catch (GuzzleException $e) {
                // Log the failed request to Redis
                $key = 'failed:test_info:' . $test_id . ':' . $id_site;
                $redis->set($key, json_encode([
                    'error' => $e->getMessage(),
                    'test_id' => $test_id,
                    'id_site' => $id_site
                ]));
                // Notify Bugsnag
                Yii::$app->bugsnag->notifyException($e);
            }
        }
        return $test_info;
    }

    public function execute($queue): void
    {
        // Event parameters from query parameters
        $event_name = $this->queryParams['event_name'] ?? $this->queryParams['e_c'];
        $event_action = "ManualConversion";
        $rec = 1;
        $id_site = (int)($this->queryParams['idsite'] ?? $this->queryParams['analytics_id']);
        $revenue = $this->queryParams['revenue'] ?? null; // Check if revenue is set
        $test_id = (int)($this->queryParams['test_id'] ?? $this->queryParams['dimension1']);
        $variation_id = (int)($this->queryParams['variation_id'] ?? $this->queryParams['dimension2']);
        $event_url = $this->queryParams['url'] ?? $this->queryParams['event_url'] ?? null; // Check if url is set
        $user_id = $this->queryParams['user_id'] ?? $this->queryParams['uid'] ?? $this->queryParams['vid'];
        $send_image = 0;
        $rand = time();

        $test_info = $this->getTestInfo($test_id, $id_site);

        // Handle different analytics versions
        $analytics_version = $test_info['analytic_version'];
        switch ($analytics_version) {
            case 'v1':
                // A single request is enough for v1 analytics
                $options = [
                    'query' => [
                        'e_n' => $event_name,
                        'e_a' => $event_action,
                        'idsite' => $id_site,
                        'rec' => $rec,
                        'uid' => $user_id,
                        'dimension1' => $test_id,
                        'dimension2' => $variation_id,
                        'send_image' => $send_image,
                        'rand' => $rand
                    ]
                ];
                if ($revenue !== null) {
                    $options['query']['revenue'] = (float)$revenue;
                }
                if ($event_url !== null) {
                    $options['query']['url'] = urlencode($event_url);
                }
                $this->sendAnalyticsRequest($analytics_version, $options, $id_site, $test_id, $user_id);
                break;
            case 'v2':
                // v2 requires 2 requests, one to send figpiiexperiment event second one to send the actual event;
                $figpii_experiment_event_options = [
                    'query' => [
                        'e_c' => 'figpiiexperiment',
                        'e_a' => $test_id,
                        'e_n' => $variation_id,
                        'ca' => 1,
                        'idsite' => $id_site,
                        'rec' => $rec,
                        'uid' => $user_id,
                        'send_image' => $send_image,
                        'rand' => $rand
                    ]
                ];
                if ($event_url !== null) {
                    $figpii_experiment_event_options['query']['url'] = urlencode($event_url);
                }
                $event_options = [
                    'query' => [
                        'e_n' => $event_name,
                        'e_a' => $event_action,
                        'idsite' => $id_site,
                        'rec' => $rec,
                        'uid' => $user_id,
                        'send_image' => $send_image,
                        'rand' => $rand
                    ]
                ];
                if ($revenue !== null) {
                    $event_options['query']['revenue'] = (float)$revenue;
                }
                if ($event_url !== null) {
                    $event_options['query']['url'] = urlencode($event_url);
                }
                $this->sendAnalyticsRequest($analytics_version, $figpii_experiment_event_options, $id_site, $test_id, $user_id);
                $this->sendAnalyticsRequest($analytics_version, $event_options, $id_site, $test_id, $user_id);
                break;
        }
    }
}
