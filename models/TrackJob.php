<?php

namespace app\models;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
    public function execute($queue)
    {
        $event_name = $this->queryParams['event_name'] || $this->queryParams['e_c'];
        $event_action = "ManualConversion";
        $rec = 1;
        $id_site = $this->queryParams['idsite'] || $this->queryParams['analytics_id'];
        $revenue = $this->queryParams['revenue'];
        $test_id = $this->queryParams['test_id'] || $this->queryParams['dimension1'];
        $variation_id = $this->queryParams['variation_id'] || $this->queryParams['dimension2'];
        $event_url = $this->queryParams['event_url'] || $this->queryParams['url'];
        $user_id = $this->queryParams['user_id'] || $this->queryParams['uid'] || $this->queryParams['vid'];
        $send_image = 0;
        $rand = time();

        $guzzle_client = new Client([
            'base_uri' => env('ANALYTICS_ENDPOINT'),
            'timeout'  => 10.0,
        ]);

        $response = $guzzle_client->get(`?e_n=$event_name&e_a=$event_action&revenue=$revenue&idsite=$id_site&rec=$rec&uid=$user_id&dimension1=$test_id&dimension2=$variation_id&url=$event_url&send_image=$send_image&rand=$rand`);

        return $response->getStatusCode();

        // TODO
        // Somehow track somewhere that the request was successful or failed along with the event parameters.
    }
}
