<?php

namespace app\models;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Yii;
use yii\base\BaseObject;

//1. e_c=purchase
//2. e_a=ManualConversion
//3. revenue=%revenue_amount% — for example revenue=120 or revenue=120.00
//4. rec=1
//5. idsite=%check_below%
//6. uid=FIGPII.clientInfo.getFingerPrint()
//7. dimension1=%check_below%
//8. dimension2=%check_below%
//9. (optional but recommended) url=encodeURIComponent(window.location.href)
//10. send_image=0
class TrackJob extends BaseObject implements \yii\queue\JobInterface
{
    public array $queryParams;

    /**
     * @throws GuzzleException
     */
    private function analyticsRequest($analytics_version, $payload, $id_side, $test_id, $user_id): void {
        file_put_contents('/Users/reza/Documents/FigPii Projects/track-request-queue/dump/logfile.log', print_r([
            'analytics_version' => $analytics_version,
            'payload' => $payload,
            'id_side' => $id_side,
            'test_id' => $test_id,
            'user_id' => $user_id,
        ], true), FILE_APPEND);
        $redis = Yii::$app->redis;
        $guzzle_client = new Client([
            'timeout'  => 10.0,
        ]);
        $response = $guzzle_client->request('GET', env('ANALYTICS_ENDPOINT_' . strtoupper($analytics_version)), $payload);
        // Save request for debugging purposes
        $response_code = $response->getStatusCode();
        $key = $analytics_version . ':' . $response_code . ':' . $id_side . ':' . $test_id . ':' . $user_id;
        $redis->set($key, json_encode($payload['query']));
    }

    /**
     * @throws GuzzleException
     */
    public function execute($queue): void
    {
        file_put_contents('/Users/reza/Documents/FigPii Projects/track-request-queue/dump/logfile.log', print_r([
            'job_executed_at' => time()
        ], true), FILE_APPEND);

        $redis = Yii::$app->redis;
        $guzzle_client = new Client([
            'timeout'  => 10.0,
        ]);
        $figpii_client_auth = [
            'headers' => [
                'X-Token' => env('FIGPII_X_TOKEN'),
                'Cookie' => env('FIGPII_CSRF')
            ]
        ];
        // Event parameters from query parameters
        $event_name = $this->queryParams['event_name'] ?? $this->queryParams['e_c'];
        $event_action = "ManualConversion";
        $rec = 1;
        $id_site = (int)($this->queryParams['idsite'] ?? $this->queryParams['analytics_id']);
        $revenue = (float)($this->queryParams['revenue']);
        $test_id = (int)($this->queryParams['test_id'] ?? $this->queryParams['dimension1']);
        $variation_id = (int)($this->queryParams['variation_id'] ?? $this->queryParams['dimension2']);
        $event_url = urlencode($this->queryParams['event_url'] ?? $this->queryParams['url']);
        $user_id = $this->queryParams['user_id'] ?? $this->queryParams['uid'] ?? $this->queryParams['vid'];
        $send_image = 0;
        $rand = time();

        if (empty($event_name) || empty($id_site) || empty($test_id) || empty($variation_id) || empty($user_id)) {
            file_put_contents('/Users/reza/Documents/FigPii Projects/track-request-queue/dump/logfile.log', print_r([
                'return_at' => time()
            ], true), FILE_APPEND);
            return;
        }

        // Get test info
        $test_info = null;
        $test_redis_key = $test_id . ':' . $id_site;
        if ($redis->exists($test_redis_key)) {
            $test_info = json_decode($redis->get($test_redis_key));
        } else {
            $test_info_request = $guzzle_client->request('GET', env('TOOLS_ENDPOINT') . $test_id, $figpii_client_auth);
            if ($test_info_request->getStatusCode() == 200) {
                $test_info_json = json_decode($test_info_request->getBody());
                $test_info = $test_info_json['result'];
                if ($test_info) {
                    $redis->set($test_redis_key, json_encode($test_info));
                    $ttl = 3 * 24 * 60 * 60;
                    $redis->expire($test_redis_key, $ttl);
                }
            }
        }

        // Handle different analytics versions
        $analytics_version = $test_info['analytic_version'];
        switch ($analytics_version) {
            case 'v1':
                // A single request is enough for v1 analytics
                $options = [
                    'query' => [
                        'e_n'  =>  $event_name,
                        'e_a' => $event_action,
                        $revenue && 'revenue' => $revenue,
                        'idsite' => $id_site,
                        'rec' => $rec,
                        'uid' => $user_id,
                        'dimension1' => $test_id,
                        'dimension2' => $variation_id,
                        'url' => $event_url,
                        'send_image' => $send_image,
                        'rand' => $rand
                    ]
                ];
                $this->analyticsRequest($analytics_version, $options, $id_site, $test_id, $user_id);
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
                        'url' => $event_url,
                        'send_image' => $send_image,
                        'rand' => $rand
                    ]
                ];
                $event_options = [
                    'query' => [
                        'e_n'  =>  $event_name,
                        'e_a' => $event_action,
                        $revenue && 'revenue' => $revenue,
                        'idsite' => $id_site,
                        'rec' => $rec,
                        'uid' => $user_id,
                        'url' => $event_url,
                        'send_image' => $send_image,
                        'rand' => $rand
                    ]
                ];
                $this->analyticsRequest($analytics_version, $figpii_experiment_event_options, $id_site, $test_id, $user_id);
                $this->analyticsRequest($analytics_version, $event_options, $id_site, $test_id, $user_id);
                break;
        }
        file_put_contents('/Users/reza/Documents/FigPii Projects/track-request-queue/dump/logfile.log', print_r([
            'job_finished_at' => time()
        ], true), FILE_APPEND);
    }
}
