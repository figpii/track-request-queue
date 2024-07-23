<?php

namespace app\components;

use Bugsnag\Client;
use yii\base\Component;

class BugsnagComponent extends Component
{
    public $apiKey;
    public $notifyReleaseStages;

    private $client;

    public function init()
    {
        parent::init();

        if ($this->apiKey) {
            $this->client = Client::make($this->apiKey);
            $this->client->setReleaseStage(YII_ENV);
            $this->client->setAppVersion(Yii::$app->version);
            $this->client->setNotifyReleaseStages($this->notifyReleaseStages);
        }
    }

    public function notifyException(\Throwable $exception)
    {
        if ($this->client) {
            $this->client->notifyException($exception);
        }
    }
}
